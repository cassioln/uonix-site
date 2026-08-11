#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

python3 - \
  "$ROOT_DIR/.github/workflows/deploy-production.yml" \
  "$ROOT_DIR/.github/workflows/clone-environment.yml" \
  "$ROOT_DIR/.github/workflows/_deploy-hostgator.yml" \
  "$ROOT_DIR/.github/workflows/validate.yml" \
  "${UONIX_TEST_CLONE_SCRIPT:-$ROOT_DIR/scripts/clone-environment.sh}" <<'PY'
import os
import pathlib
import re
import subprocess
import sys
import tempfile

production = pathlib.Path(sys.argv[1]).read_text(encoding='utf-8')
clone = pathlib.Path(sys.argv[2]).read_text(encoding='utf-8')
hostgator = pathlib.Path(sys.argv[3]).read_text(encoding='utf-8')
validate = pathlib.Path(sys.argv[4]).read_text(encoding='utf-8')
clone_script = pathlib.Path(sys.argv[5]).read_text(encoding='utf-8')


def require(document, pattern, message):
    if not re.search(pattern, document, re.M | re.S):
        raise AssertionError(message)


def forbid(document, pattern, message):
    if re.search(pattern, document, re.M | re.S):
        raise AssertionError(message)


def named_step_run(document, step_name):
    lines = document.splitlines()
    name_index = next(
        index for index, line in enumerate(lines)
        if line.strip() == f'- name: {step_name}'
    )
    for index in range(name_index + 1, len(lines)):
        if lines[index].lstrip().startswith('- name:'):
            break
        match = re.match(r'^(\s*)run:\s*\|\s*$', lines[index])
        if not match:
            continue
        run_indent = len(match.group(1))
        body = []
        for body_line in lines[index + 1:]:
            indentation = len(body_line) - len(body_line.lstrip())
            if body_line.strip() and indentation <= run_indent:
                break
            body.append(body_line[run_indent + 2:] if body_line.strip() else '')
        return '\n'.join(body) + '\n'
    raise AssertionError(f'step sem run literal: {step_name}')


trigger_block = production.split('\npermissions:', 1)[0]
forbid(trigger_block, r'^\s+push:\s*$', 'produção ainda dispara por push')
require(trigger_block, r'^\s+workflow_dispatch:\s*\n\s+inputs:\s*\n\s+confirmation:', 'produção precisa de confirmação manual')
require(trigger_block, r'confirmation:.*?required:\s*true.*?type:\s*string', 'confirmação de produção precisa ser string obrigatória')
require(
    trigger_block,
    r'migrate_variation_technical_sheet:.*?required:\s*true.*?default:\s*false.*?type:\s*boolean',
    'migração da ficha em produção precisa ser input booleano explícito e desligado por padrão',
)
require(production, r'^concurrency:\s*\n\s+group:\s*uonix-environment-prod\s*$', 'produção precisa compartilhar o lock lógico do destino prod')
require(production, r'inputs\.migrate_variation_technical_sheet', 'migração da ficha precisa depender do input explícito')
require(production, r'- name: Migrate legacy variation technical sheets', 'etapa versionada de migração da ficha ausente')
require(production, r'--dry-run.*?--execute', 'produção precisa executar dry-run antes da migração efetiva')
require(production, r'NO-CHANGE: 5 fichas já migradas e verificadas|migrate --execute', 'migração de produção precisa ser idempotente')
if production.index('- name: Back up the production database') > production.index('- name: Migrate legacy variation technical sheets'):
    raise AssertionError('migração da ficha deve ocorrer depois do backup do banco')
require(production, r'cancel-in-progress:\s*false', 'produção não pode cancelar uma operação mutável em andamento')
require(production, r'^\s{2}authorize:\s*$', 'job de autorização local ausente')
require(production, r'environment:\s*production-locaweb', 'Environment protegido de produção ausente')
require(production, r'ENABLE_DEPLOY_PRODUCTION', 'guard persistente de produção ausente')
require(production, r'PUBLICAR \$\{UONIX_REQUEST_SHA\} EM SITE\.UONIX\.COM\.BR', 'confirmação não está vinculada ao SHA')
require(production, r'UONIX_REQUEST_REF.*?refs/heads/master|refs/heads/master.*?UONIX_REQUEST_REF', 'produção não restringe a ref master')

canonical_values = (
    'ftp.site.uonix.com.br',
    'siteuonix1',
    '/home/storage/f/34/12/siteuonix1',
    '/home/storage/f/34/12/siteuonix1/public_html',
    '/usr/bin/php85',
    '/home/storage/f/34/12/siteuonix1/bin/wp-cli.phar',
    'https://site.uonix.com.br',
)
for value in canonical_values:
    if value not in production:
        raise AssertionError(f'allowlist de produção não contém {value}')

validation_step = 'Validate production request and canonical target'
validation_index = production.find(f'      - name: {validation_step}')
if validation_index < 0:
    raise AssertionError('step de autorização canônica ausente')
secret_index = production.find('secrets.LOCAWEB_SSH_PASSWORD')
if secret_index < 0 or secret_index < validation_index:
    raise AssertionError('senha Locaweb é materializada antes da allowlist')
forbid(production, r'^\s{6}SSHPASS:\s*\$\{\{\s*secrets\.', 'senha não pode ficar no env do job inteiro')
require(production, r'bash -s -- "\$LOCAWEB_DOCUMENT_ROOT" "\$LOCAWEB_PHP_BIN" "\$LOCAWEB_WP_BIN" <<\x27REMOTE\x27', 'preflight precisa passar Variables como argv a heredoc literal')
forbid(production, r'core is-installed\"; then', 'preflight ainda interpola Variables em string remota')

for token in (
    'DEPLOY_LOCK_ACQUIRED',
    '.uonix-operation.lock',
    'github.run_id',
    'github.run_attempt',
    'option get home',
    'option get siteurl',
    'UONIX_ALLOW_INDEXING',
    'uonix_environment_allows_indexing',
    'X-Robots-Tag',
    'noindex',
    'nofollow',
    'noarchive',
    'url_effective',
    'uonix_analytics_configuration',
):
    if token not in production:
        raise AssertionError(f'controle de produção ausente: {token}')

require(clone, r'^concurrency:\s*\n\s+group:\s*uonix-environment-\$\{\{\s*inputs\.target\s*\}\}', 'clone precisa compartilhar o lock lógico por destino')
require(clone, r'^\s{2}validate-request:\s*$', 'clone precisa validar antes de carregar Environment/Secrets')
require(clone, r"environment_name='production-clone'", 'clone em produção precisa de Environment próprio')
require(clone, r"environment_name='clone-operations'", 'clones não produtivos precisam manter Environment próprio')
require(clone, r'environment:\s*\$\{\{\s*needs\.validate-request\.outputs\.environment_name\s*\}\}', 'Environment precisa vir de output allowlisted')
require(clone, r'ENABLE_CLONE_PRODUCTION', 'clone em produção precisa de guard persistente separado')
require(clone, r'CLONAR .* PARA PROD @ \$\{UONIX_REQUEST_SHA\}', 'confirmação de clone prod não está vinculada ao SHA')
require(clone, r'ref:\s*\$\{\{\s*github\.sha\s*\}\}', 'checkout do clone precisa fixar o SHA aprovado')
require(hostgator, r"group:\s*uonix-environment-\$\{\{\s*inputs\.environment_name\s*==\s*'qa-hostgator'\s*&&\s*'qa'\s*\|\|\s*inputs\.environment_name\s*==\s*'development-hostgator'\s*&&\s*'dev'\s*\|\|\s*'invalid'\s*\}\}", 'deploy HostGator precisa compartilhar o lock lógico qa/dev com clones')

require(clone, r'UONIX_CLONE_RUN_ID:\s*\$\{\{\s*github\.run_id\s*\}\}-\$\{\{\s*github\.run_attempt\s*\}\}', 'clone não encaminha um owner único para o script canônico')
require(clone, r'scripts/clone-environment\.sh\s+\\', 'workflow de clone não invoca o script canônico')
for token in ('.uonix-operation.lock', 'mkdir --', 'owner', 'acquire_clone_lock "$TARGET"'):
    if token not in clone_script:
        raise AssertionError(f'script canônico de clone não mantém lock remoto compartilhado: {token}')
if not re.search(r'run_clone\(\)\s*\{.*?acquire_clone_lock "\$TARGET"\s*\|\|\s*return \$\?.*?execute_clone_with_rollback', clone_script, re.S):
    raise AssertionError('clone não adquire o lock antes da mutação com rollback')
for token in ('.uonix-operation.lock', 'owner'):
    if token not in hostgator:
        raise AssertionError(f'deploy HostGator não implementa lock remoto compartilhado: {token}')
for token in ('DEPLOY_RUN_ID', 'DEPLOY_LOCK_ACQUIRED', 'github.run_id', 'github.run_attempt'):
    if token not in hostgator:
        raise AssertionError(f'deploy HostGator não mantém ownership do lock: {token}')
for document, label in ((production, 'produção'), (clone, 'clone'), (hostgator, 'deploy HostGator')):
    forbid(document, r'_uonix-deploy\.lock|\.uonix-clone\.lock', f'{label} ainda usa lock específico e incompatível')

for command in (
    'bash scripts/tests/test-production-workflow.sh',
    'bash scripts/tests/test-clone-lock.sh',
    'php scripts/tests/test-blog-archive-optional-woocommerce.php',
    'php scripts/tests/test-woocommerce-optional-plugin.php',
):
    if validate.count(command) != 1:
        raise AssertionError(f'validate.yml precisa executar exatamente uma vez: {command}')

script = named_step_run(production, validation_step)
with tempfile.TemporaryDirectory(prefix='uonix-production-auth-') as tmp:
    marker = pathlib.Path(tmp) / 'transport.log'
    fake_bin = pathlib.Path(tmp) / 'bin'
    fake_bin.mkdir()
    for command in ('ssh', 'sshpass', 'rsync', 'curl'):
        shim = fake_bin / command
        shim.write_text(
            '#!/bin/sh\nprintf "%s\\n" "$0" >> "$UONIX_AUTH_TRANSPORT_LOG"\nexit 97\n',
            encoding='utf-8',
        )
        shim.chmod(0o755)

    sha = 'a' * 40
    canonical = {
        'PATH': f'{fake_bin}:{os.environ.get("PATH", "")}',
        'UONIX_AUTH_TRANSPORT_LOG': str(marker),
        'UONIX_ENABLE_DEPLOY_PRODUCTION': 'true',
        'UONIX_PRODUCTION_CONFIRMATION': f'PUBLICAR {sha} EM SITE.UONIX.COM.BR',
        'UONIX_REQUEST_SHA': sha,
        'UONIX_REQUEST_REF': 'refs/heads/master',
        'UONIX_MIGRATE_VARIATION_TECHNICAL_SHEET': 'false',
        'LOCAWEB_SSH_HOST': 'ftp.site.uonix.com.br',
        'LOCAWEB_SSH_PORT': '22',
        'LOCAWEB_SSH_USER': 'siteuonix1',
        'LOCAWEB_ACCOUNT_ROOT': '/home/storage/f/34/12/siteuonix1',
        'LOCAWEB_DOCUMENT_ROOT': '/home/storage/f/34/12/siteuonix1/public_html',
        'LOCAWEB_PHP_BIN': '/usr/bin/php85',
        'LOCAWEB_WP_BIN': '/home/storage/f/34/12/siteuonix1/bin/wp-cli.phar',
        'TARGET_URL': 'https://site.uonix.com.br',
    }

    def run(overrides):
        env = dict(os.environ)
        env.update(canonical)
        env.update(overrides)
        return subprocess.run(['/bin/bash', '-c', script], env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)

    if run({}).returncode != 0:
        raise AssertionError('requisição canônica de produção foi rejeitada')
    probes = (
        {'UONIX_ENABLE_DEPLOY_PRODUCTION': 'false'},
        {'UONIX_PRODUCTION_CONFIRMATION': 'PUBLICAR OUTRO SHA'},
        {'UONIX_REQUEST_REF': 'refs/heads/qa'},
        {'LOCAWEB_DOCUMENT_ROOT': '/outro/site'},
        {'TARGET_URL': 'https://uonix.com.br'},
        {'LOCAWEB_SSH_HOST': 'outro.example.invalid'},
    )
    for override in probes:
        if run(override).returncode == 0:
            raise AssertionError(f'requisição de produção insegura aceita: {override}')
    if marker.exists() and marker.read_text(encoding='utf-8'):
        raise AssertionError('autorização abriu transporte')

# T59A — política de convergência com allowlist explícita (decisão registrada).
#
# O publish do reusable é destrutivo por construção: rsync --delete no tema e em cada
# módulo, mais remoção de módulos uonix-* órfãos no destino. Isso conflita com a
# preservação de extras remotos que sustentou T56A/T56/T57. A política escolhida é
# convergência COM allowlist: só caminhos declarados podem ser apagados, o que estiver
# fora da allowlist é preservado, e o delta a remover é inventariado e copiado para o
# backup ANTES de qualquer remoção.
#
# Caso concreto que motivou a decisão: DEV serve mu-plugins/uonix-local (Mailpit),
# carregado por uonix-core.php, mas os workflows passam include_local_module=false.
# A remoção de órfãos apagaria esse módulo silenciosamente.
require(
    hostgator,
    r'UONIX_DELETE_POLICY_ALLOWLIST',
    'política de allowlist de remoção ausente no reusable',
)
require(
    hostgator,
    r'preserve_orphan_modules|UONIX_PRESERVED_MODULES',
    'módulos preservados fora da allowlist não declarados',
)
require(
    hostgator,
    r'orphan-inventory|orphan_inventory',
    'inventário do delta de órfãos ausente antes da remoção',
)

orphan_step = named_step_run(hostgator, 'Publish only managed paths and verify manifest')
backup_step = named_step_run(hostgator, 'Back up managed remote paths')
rollback_step = named_step_run(hostgator, 'Roll back managed code after failure')
if 'uonix-local' not in hostgator:
    raise AssertionError('uonix-local não é reconhecido como módulo preservado')
if hostgator.index('- name: Back up managed remote paths') > hostgator.index(
    '- name: Publish only managed paths and verify manifest'
):
    raise AssertionError('backup deve preceder a publicação destrutiva')


def strip_comments(document):
    """Descarta comentários: uma guarda satisfeita por comentário não protege nada."""
    return '\n'.join(
        line for line in document.splitlines()
        if not line.lstrip().startswith('#')
    )


# GAP-1 (revisão independente do PR #5): a asserção anterior era satisfeita pela mera
# presença de UONIX_DELETE_POLICY_ALLOWLIST em um COMENTÁRIO, então reintroduzir
# `rm -rf` de órfãos mantinha o teste verde. Agora o código executável é avaliado.
orphan_code = strip_comments(orphan_step)
if 'rm -rf' in orphan_code:
    raise AssertionError(
        'step de publicação voltou a remover caminhos; órfãos devem ser preservados'
    )
for step_name, step_code in (
    ('backup', strip_comments(backup_step)),
    ('publicação', orphan_code),
    ('rollback', strip_comments(rollback_step)),
):
    if '${#allowlist[@]}" -eq 0' not in step_code and '${#expected_modules[@]}" -eq 0' not in step_code:
        raise AssertionError(f'step de {step_name} sem gate fail-closed para allowlist vazia')
    if 'uonix-*[!A-Za-z0-9._-]*' not in step_code:
        raise AssertionError(f'step de {step_name} sem validação de nome de módulo')
if 'umask 077' not in strip_comments(backup_step):
    raise AssertionError('heredoc de backup sem umask restritivo')
if 'for module_name in "${allowlist[@]}"' not in strip_comments(rollback_step):
    raise AssertionError('rollback não reverte os módulos publicados por este deploy')
forbid(
    orphan_step,
    r'rsync[^\n]*--delete[^\n]*\$TARGET_ROOT/wp-content/?\s',
    '--delete aplicado na raiz do wp-content',
)
del backup_step

print('PASS: produção e clone exigem autorização, allowlist, serialização e smoke fail-closed.')
PY
