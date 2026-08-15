#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKFLOW="${ROOT_DIR}/.github/workflows/clone-environment.yml"
VALIDATE_WORKFLOW="${ROOT_DIR}/.github/workflows/validate.yml"
REUSABLE_DEPLOY_WORKFLOW="${ROOT_DIR}/.github/workflows/_deploy-hostgator.yml"
ADMIN_PANEL="${ROOT_DIR}/mu-plugins/uonix-admin/48-admin-clone-ambientes.php"
ADMIN_TEST="${ROOT_DIR}/scripts/tests/test-clone-admin.php"
CLONE_DOC="${ROOT_DIR}/docs/clone-ambientes.md"

fail() {
  printf 'FAIL: %s\n' "$*" >&2
  exit 1
}

[ -f "$WORKFLOW" ] || fail 'workflow de clone ausente'
[ -f "$VALIDATE_WORKFLOW" ] || fail 'workflow de validação ausente'
[ -f "$REUSABLE_DEPLOY_WORKFLOW" ] || fail 'workflow reutilizável HostGator ausente'
[ -f "$ADMIN_PANEL" ] || fail 'painel administrativo de clone ausente'
[ -f "$ADMIN_TEST" ] || fail 'teste PHP do painel de clone ausente'
[ -f "$CLONE_DOC" ] || fail 'documentação de clone ausente'

python3 - "$WORKFLOW" "$VALIDATE_WORKFLOW" "$REUSABLE_DEPLOY_WORKFLOW" "$ADMIN_PANEL" "$ADMIN_TEST" "$CLONE_DOC" <<'PY'
import os
import pathlib
import re
import subprocess
import sys
import tempfile

text = pathlib.Path(sys.argv[1]).read_text(encoding='utf-8')
validate_text = pathlib.Path(sys.argv[2]).read_text(encoding='utf-8')
deploy_text = pathlib.Path(sys.argv[3]).read_text(encoding='utf-8')
admin_text = pathlib.Path(sys.argv[4]).read_text(encoding='utf-8')
admin_test_text = pathlib.Path(sys.argv[5]).read_text(encoding='utf-8')
clone_doc_text = pathlib.Path(sys.argv[6]).read_text(encoding='utf-8')

def require(pattern, message):
    if not re.search(pattern, text, re.M | re.S):
        raise AssertionError(message)

def forbid(pattern, message):
    if re.search(pattern, text, re.M | re.S):
        raise AssertionError(message)

def literal_run_blocks(document):
    lines = document.splitlines()
    blocks = []
    line_index = 0
    while line_index < len(lines):
        match = re.match(r'^(\s*)run:\s*\|\s*$', lines[line_index])
        if not match:
            line_index += 1
            continue

        run_indent = len(match.group(1))
        body = []
        line_index += 1
        while line_index < len(lines):
            line = lines[line_index]
            indentation = len(line) - len(line.lstrip())
            if line.strip() and indentation <= run_indent:
                break
            body.append(line)
            line_index += 1
        blocks.append('\n'.join(body))
    return blocks

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

for name in ('source', 'target', 'mode', 'replace_users', 'confirmation'):
    require(rf'^      {name}:\s*$', f'input ausente: {name}')

require(r'      source:.*?options:\s*\n\s*- prod\s*\n\s*- qa\s*\n\s*- dev', 'source precisa oferecer prod/qa/dev')
require(r'      target:.*?options:\s*\n\s*- prod\s*\n\s*- qa\s*\n\s*- dev', 'target precisa oferecer prod/qa/dev')
require(r'      mode:.*?options:\s*\n\s*- dry-run\s*\n\s*- execute', 'mode precisa oferecer dry-run/execute')
require(r'      replace_users:.*?type: boolean.*?default: false', 'replace_users precisa iniciar false')
forbid(r'^\s*- local\s*$', 'local não pode aparecer no workflow remoto')

require(
    r'environment:\s*\$\{\{\s*needs\.validate-request\.outputs\.environment_name\s*\}\}',
    'job credenciado precisa usar Environment proveniente da validação allowlisted',
)
require(r"environment_name='clone-operations'", 'clones não produtivos precisam usar clone-operations')
require(r"environment_name='production-clone'", 'escrita em produção precisa usar Environment próprio')
if 'clone-operations' not in clone_doc_text:
    raise AssertionError('documentação precisa declarar o Environment clone-operations')
if 'production-clone' not in clone_doc_text:
    raise AssertionError('documentação precisa declarar o Environment production-clone')
if not re.search(r'production-clone.*?execute.*?destino\s+`?prod`?', clone_doc_text, re.I | re.S):
    raise AssertionError('documentação precisa limitar production-clone a execute com destino prod')
require(
    r'uses:\s*actions/checkout@v4.*?with:\s*\n\s*ref:\s*\$\{\{\s*github\.sha\s*\}\}',
    'checkout precisa fixar o SHA validado',
)
for secret in (
    'HOSTGATOR_SSH_PRIVATE_KEY',
    'HOSTGATOR_SSH_KNOWN_HOSTS',
    'LOCAWEB_SSH_PASSWORD',
    'LOCAWEB_SSH_KNOWN_HOSTS',
):
    require(rf'secrets\.{secret}', f'secret ausente: {secret}')

forbid(
    r"printf\s+'::add-mask::%s\\n'\s+\"\$HOSTGATOR_SSH_PRIVATE_KEY\"",
    'chave SSH multilinha não pode ser emitida em comando add-mask',
)

run_shell = '\n'.join(literal_run_blocks(text))
if '${{ inputs.' in run_shell:
    raise AssertionError('inputs do workflow são interpolados diretamente em run')
if '${{ secrets.' in run_shell:
    raise AssertionError('Secrets do workflow são interpolados diretamente em run')

request_environment = {
    'source': 'UONIX_CLONE_REQUEST_SOURCE',
    'target': 'UONIX_CLONE_REQUEST_TARGET',
    'mode': 'UONIX_CLONE_REQUEST_MODE',
    'replace_users': 'UONIX_CLONE_REQUEST_REPLACE_USERS',
}
workflow_lines = set(text.splitlines())
for input_name, environment_name in request_environment.items():
    expected_mapping = f'      {environment_name}: ${{{{ inputs.{input_name} }}}}'
    if expected_mapping not in workflow_lines:
        raise AssertionError(
            f'input {input_name} precisa ser materializado no env do job'
        )
    quoted_reference = re.escape(f'"${{{environment_name}}}"')
    require(
        quoted_reference,
        f'shell não consome {environment_name} como variável entre aspas',
    )

forbid(
    r'^\s+UONIX_CLONE_SUMMARY_FILE:\s*\$\{\{\s*runner\.temp',
    'runner.temp não é válido no env do job',
)
summary_assignment = (
    'export UONIX_CLONE_SUMMARY_FILE="${RUNNER_TEMP}/uonix-clone-summary.txt"'
)
if text.count(summary_assignment) != 3:
    raise AssertionError(
        'dry-run, execute e resumo precisam usar o mesmo arquivo privado em RUNNER_TEMP'
    )
require(
    r'case "\$key" in\s*\n\s*source\|target\|mode\|replace_users\|backup_id\|runtime_file_count\|runtime_directory_count\)',
    'resumo precisa manter allowlist fechada de campos sanitizados',
)
forbid(
    r"printf(?: --)? '[^'\n]*`[^'\n]*'",
    'backticks literais do resumo precisam ser escapados sem command substitution',
)

forbid(r'ssh-keyscan', 'workflow não pode confiar em ssh-keyscan')
forbid(r'StrictHostKeyChecking=(?:no|accept-new)', 'workflow relaxa host key checking')
forbid(r'STAGING_SSH_KEY|STAGING_PATH|QA_PATH|PROD_PATH', 'workflow ainda usa topologia antiga')

require(r'--dry-run', 'dry-run obrigatório ausente')
require(r"if:\s*\$\{\{\s*inputs\.mode\s*==\s*'execute'\s*\}\}", 'execute não está condicionado ao input mode')
require(r'--execute', 'execução real ausente')
require(r'--replace-users', 'flag replace_users não é encaminhada')
require(r'--confirmation=', 'confirmation não é encaminhada')
forbid(r'--confirmation=["\x27]?\$\{\{\s*inputs\.confirmation', 'input confirmation é interpolado diretamente no shell')
require(
    r'UONIX_CLONE_REQUEST_CONFIRMATION:\s*\$\{\{\s*inputs\.confirmation\s*\}\}',
    'confirmation precisa entrar no job de validação sem Secrets',
)
require(
    r'UONIX_CLONE_SCRIPT_CONFIRMATION:\s*\$\{\{\s*needs\.validate-request\.outputs\.script_confirmation\s*\}\}',
    'job credenciado precisa receber somente a confirmação canônica validada',
)
require(
    r'--confirmation="\$UONIX_CLONE_SCRIPT_CONFIRMATION"',
    'shell precisa ler a confirmação validada como variável entre aspas',
)
require(r'GITHUB_STEP_SUMMARY', 'resumo do workflow ausente')

clone_validation_script = named_step_run(
    text, 'Validate clone request before credentials'
)

canonical_clone_topology = {
    'PRODUCTION_URL': 'https://uonix.com.br',
    'QA_URL': 'https://uonix.ksio.dev',
    'DEVELOPMENT_URL': 'https://test.uonix.ksio.dev',
    'LOCAWEB_SSH_HOST': 'ftp.uonix.com.br',
    'LOCAWEB_SSH_PORT': '22',
    'LOCAWEB_SSH_USER': 'siteuonix1',
    'LOCAWEB_DOCUMENT_ROOT': '/home/storage/f/34/12/siteuonix1/public_html',
    'LOCAWEB_ACCOUNT_ROOT': '/home/storage/f/34/12/siteuonix1',
    'LOCAWEB_PHP_BIN': '/usr/bin/php85',
    'LOCAWEB_WP_BIN': '/home/storage/f/34/12/siteuonix1/bin/wp-cli.phar',
    'HOSTGATOR_SSH_HOST': '108.179.252.137',
    'HOSTGATOR_SSH_PORT': '22',
    'HOSTGATOR_SSH_USER': 'uonix',
    'HOSTGATOR_QA_ROOT': '/home2/uonix/public_html',
    'HOSTGATOR_DEV_ROOT': '/home2/uonix/dev_uonix',
    'HOSTGATOR_CLONE_BACKUP_ROOT': '/home2/uonix/_uonix-clone-backups',
}

def run_clone_validation(source, target, mode, replace_users, confirmation, enabled='false', ref='refs/heads/master', topology_overrides=None):
    with tempfile.TemporaryDirectory(prefix='uonix-clone-auth-') as tmp:
        output = pathlib.Path(tmp) / 'github.output'
        env = dict(os.environ)
        env.update(canonical_clone_topology)
        if topology_overrides:
            env.update(topology_overrides)
        env.update({
            'GITHUB_OUTPUT': str(output),
            'UONIX_CLONE_REQUEST_SOURCE': source,
            'UONIX_CLONE_REQUEST_TARGET': target,
            'UONIX_CLONE_REQUEST_MODE': mode,
            'UONIX_CLONE_REQUEST_REPLACE_USERS': replace_users,
            'UONIX_CLONE_REQUEST_CONFIRMATION': confirmation,
            'UONIX_ENABLE_CLONE_PRODUCTION': enabled,
            'UONIX_REQUEST_SHA': 'a' * 40,
            'UONIX_REQUEST_REF': ref,
        })
        result = subprocess.run(
            ['/bin/bash', '-c', clone_validation_script],
            env=env,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
        )
        exports = output.read_text(encoding='utf-8') if output.exists() else ''
        return result, dict(
            line.split('=', 1) for line in exports.splitlines() if '=' in line
        )

canonical_clone_requests = (
    ('prod', 'qa', 'dry-run', 'false', '', 'false', 'clone-operations', ''),
    ('qa', 'dev', 'execute', 'true', '', 'false', 'clone-operations', ''),
    (
        'qa',
        'prod',
        'execute',
        'false',
        f"CLONAR QA PARA PROD @ {'a' * 40}",
        'true',
        'production-clone',
        'CLONAR QA PARA PROD',
    ),
)
for source, target, mode, replace_users, confirmation, enabled, expected_environment, expected_confirmation in canonical_clone_requests:
    result, exports = run_clone_validation(
        source, target, mode, replace_users, confirmation, enabled
    )
    if result.returncode != 0:
        raise AssertionError(
            f'requisição canônica {source}:{target}:{mode} rejeitada: {result.stderr.strip()}'
        )
    expected_exports = {
        'environment_name': expected_environment,
        'script_confirmation': expected_confirmation,
    }
    expected_exports.update({
        name.lower(): value for name, value in canonical_clone_topology.items()
    })
    if exports != expected_exports:
        raise AssertionError(
            f'outputs incorretos para {source}:{target}:{mode}: {exports}'
        )

unsafe_clone_requests = (
    ('qa', 'prod', 'execute', 'false', f"CLONAR QA PARA PROD @ {'a' * 40}", 'false', 'refs/heads/master'),
    ('qa', 'prod', 'execute', 'false', 'CLONAR QA PARA PROD', 'true', 'refs/heads/master'),
    ('qa', 'qa', 'dry-run', 'false', '', 'false', 'refs/heads/master'),
    ('qa', 'dev', 'execute', 'false', '', 'false', 'refs/heads/qa'),
    ('qa;touch /tmp/uonix-clone-injection', 'dev', 'dry-run', 'false', '', 'false', 'refs/heads/master'),
)
for request in unsafe_clone_requests:
    result, exports = run_clone_validation(*request)
    if result.returncode == 0:
        raise AssertionError(f'requisição insegura aceita: {request}')
    if exports:
        raise AssertionError(f'requisição insegura publicou outputs: {request}')
if pathlib.Path('/tmp/uonix-clone-injection').exists():
    raise AssertionError('input adversarial executou sentinela')

# FR1.F — a topologia de cada host deve ser allowlisted antes de um Environment
# credenciado materializar Secrets. Alterar qualquer valor operacional precisa
# falhar sem publicar outputs ou abrir transporte.
for variable, canonical_value in canonical_clone_topology.items():
    divergent_value = canonical_value + '-divergent'
    result, exports = run_clone_validation(
        'qa', 'dev', 'dry-run', 'false', '',
        topology_overrides={variable: divergent_value},
    )
    if result.returncode == 0:
        raise AssertionError(f'topologia divergente aceita antes de Secrets: {variable}')
    if exports:
        raise AssertionError(f'topologia divergente publicou outputs: {variable}')

# O dry-run deve aparecer antes do execute no documento.
if text.index('--dry-run') > text.index('--execute'):
    raise AssertionError('execute aparece antes do dry-run obrigatório')

required_c3_shell_tests = (
    'test-environment-map.sh',
    'test-ssh-transport.sh',
    'test-clone-guards.sh',
    'test-clone-workflow.sh',
    'test-clone-author-remap.sh',
    'test-post-clone-core-validation.sh',
    'test-post-clone-policy-validation.sh',
    'test-post-clone-email-validation.sh',
    'test-post-clone-turnstile-compressx-validation.sh',
    'test-clone-safety.sh',
)
for test_name in required_c3_shell_tests:
    command = f'bash scripts/tests/{test_name}'
    if validate_text.count(command) != 1:
        raise AssertionError(
            f'validate.yml precisa executar exatamente uma vez: {command}'
        )

# FR1.D — o reusable deploy valida toda a requisição não confiável antes de
# Secrets/SSH e nunca injeta expressões inputs.* dentro de código shell.
deploy_run_shell = '\n'.join(literal_run_blocks(deploy_text))
if '${{ inputs.' in deploy_run_shell:
    raise AssertionError('reusable deploy interpola inputs diretamente em run')

validation_step_name = 'Validate deployment request before credentials'
validation_index = deploy_text.find(f'      - name: {validation_step_name}')
if validation_index < 0:
    raise AssertionError('primeiro step de validação fail-closed ausente')
steps_index = deploy_text.index('    steps:')
first_step_index = deploy_text.index('      - name:', steps_index)
if validation_index != first_step_index:
    raise AssertionError('validação da requisição não é o primeiro step')
secret_index = deploy_text.find('secrets.HOSTGATOR_SSH_PRIVATE_KEY')
ssh_index = deploy_text.find('\n          ssh \\', validation_index)
if secret_index < 0 or secret_index < validation_index:
    raise AssertionError('Secret é materializado antes da validação')
if ssh_index < 0 or ssh_index < validation_index:
    raise AssertionError('SSH aparece antes da validação')
if 'environment: ${{ inputs.environment_name }}' in deploy_text:
    raise AssertionError('job carrega GitHub Environment antes da validação do input')
if 'environment: ${{ needs.validate-request.outputs.environment_name }}' not in deploy_text:
    raise AssertionError('deploy não usa Environment proveniente do job validado')

for input_name, environment_name in {
    'environment_name': 'DEPLOY_ENVIRONMENT_NAME',
    'target_root': 'DEPLOY_TARGET_ROOT',
    'target_url': 'DEPLOY_TARGET_URL',
    'include_local_module': 'DEPLOY_INCLUDE_LOCAL_MODULE',
}.items():
    expected = f'{environment_name}: ${{{{ inputs.{input_name} }}}}'
    if expected not in deploy_text:
        raise AssertionError(f'input reusable não materializado em env: {input_name}')

validation_script = named_step_run(deploy_text, validation_step_name)
for forbidden in (
    'HOSTGATOR_SSH_PRIVATE_KEY',
    'HOSTGATOR_SSH_KNOWN_HOSTS',
    'ssh ',
    'rsync ',
):
    if forbidden in validation_script:
        raise AssertionError(f'primeiro step toca credencial/transporte: {forbidden}')

canonical_requests = {
    'qa-hostgator': ('/home2/uonix/public_html', 'https://uonix.ksio.dev', 'staging'),
    'development-hostgator': ('/home2/uonix/dev_uonix', 'https://test.uonix.ksio.dev', 'development'),
}

def run_validation(environment_name, target_root, target_url, include_local_module):
    with tempfile.TemporaryDirectory(prefix='uonix-fr1-workflow-') as tmp:
        tmp_path = pathlib.Path(tmp)
        github_output = tmp_path / 'github.output'
        transport_log = tmp_path / 'transport.log'
        fake_bin = tmp_path / 'bin'
        fake_bin.mkdir()
        for command in ('ssh', 'scp', 'sftp', 'rsync'):
            shim = fake_bin / command
            shim.write_text(
                '#!/bin/sh\nprintf "%s\\n" "$0" >> "$FR1_TRANSPORT_LOG"\nexit 97\n',
                encoding='utf-8',
            )
            shim.chmod(0o755)
        env = dict(os.environ)
        env.update({
            'PATH': f'{fake_bin}:{env.get("PATH", "")}',
            'GITHUB_OUTPUT': str(github_output),
            'FR1_TRANSPORT_LOG': str(transport_log),
            'DEPLOY_ENVIRONMENT_NAME': environment_name,
            'DEPLOY_TARGET_ROOT': target_root,
            'DEPLOY_TARGET_URL': target_url,
            'DEPLOY_INCLUDE_LOCAL_MODULE': include_local_module,
        })
        result = subprocess.run(
            ['/bin/bash', '-c', validation_script],
            env=env,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
        )
        exports = github_output.read_text(encoding='utf-8') if github_output.exists() else ''
        transport = transport_log.read_text(encoding='utf-8') if transport_log.exists() else ''
        return result, exports, transport, tmp_path

for environment_name, (target_root, target_url, bundle_environment) in canonical_requests.items():
    result, exports, transport, _ = run_validation(
        environment_name, target_root, target_url, 'false'
    )
    if result.returncode != 0:
        raise AssertionError(
            f'combinação canônica {environment_name} rejeitada: {result.stderr.strip()}'
        )
    expected_exports = {
        f'environment_name={environment_name}',
        f'target_root={target_root}',
        f'target_url={target_url}',
        'include_local_module=false',
        f'expected_uonix_env={bundle_environment}',
    }
    if set(exports.splitlines()) != expected_exports:
        raise AssertionError(f'exports não canônicos para {environment_name}')
    if transport:
        raise AssertionError('validação canônica abriu transporte')

with tempfile.TemporaryDirectory(prefix='uonix-fr1-markers-') as marker_tmp:
    marker_root = pathlib.Path(marker_tmp)
    probes = (
        ('environment', "qa-hostgator'; touch '%s'; : '" % (marker_root / 'environment'), '/home2/uonix/public_html', 'https://uonix.ksio.dev', 'false'),
        ('root', 'qa-hostgator', "/home2/uonix/public_html'; touch '%s'; : '" % (marker_root / 'root'), 'https://uonix.ksio.dev', 'false'),
        ('url', 'qa-hostgator', '/home2/uonix/public_html', "https://uonix.ksio.dev'; touch '%s'; : '" % (marker_root / 'url'), 'false'),
        ('boolean', 'qa-hostgator', '/home2/uonix/public_html', 'https://uonix.ksio.dev', "false; touch '%s'" % (marker_root / 'boolean')),
    )
    for label, environment_name, target_root, target_url, include_local_module in probes:
        result, exports, transport, _ = run_validation(
            environment_name, target_root, target_url, include_local_module
        )
        if result.returncode == 0:
            raise AssertionError(f'input adversarial aceito: {label}')
        if (marker_root / label).exists():
            raise AssertionError(f'input adversarial executou sentinela: {label}')
        if exports:
            raise AssertionError(f'input adversarial publicou outputs: {label}')
        if transport:
            raise AssertionError(f'input adversarial abriu transporte: {label}')

if "bash -s -- \"$TARGET_ROOT\" <<'REMOTE'" not in deploy_text:
    raise AssertionError('preflight remoto não passa TARGET_ROOT como argumento posicional')
if "test -d '$TARGET_ROOT'" in deploy_text:
    raise AssertionError('preflight ainda concatena TARGET_ROOT no segundo shell')

for command in (
    'php scripts/tests/test-clone-admin.php',
    'bash scripts/tests/test-deploy-variable-contract.sh',
):
    if validate_text.count(command) != 1:
        raise AssertionError(f'validate.yml precisa executar exatamente uma vez: {command}')

# FR1.E — um override legado não pode redirecionar o workflow_dispatch para
# código não canônico. A suíte PHP exerce o payload real no CI; esta checagem
# local permanece executável mesmo quando não há runtime PHP instalado.
if "define( 'UONIX_GITHUB_WORKFLOW_REF', 'qa' );" not in admin_test_text:
    raise AssertionError('fixture PHP não instala override legado qa')
if "assert_same( 'master', uox_clone_get_workflow_ref()" not in admin_test_text:
    raise AssertionError('fixture PHP não exige ref canônico master')
if "assert_same( 'master', $payload['ref']" not in admin_test_text:
    raise AssertionError('fixture PHP não prova payload final em master')
workflow_ref_match = re.search(
    r'function\s+uox_clone_get_workflow_ref\s*\(\s*\)\s*\{(?P<body>.*?)\n\}',
    admin_text,
    re.S,
)
if not workflow_ref_match:
    raise AssertionError('função uox_clone_get_workflow_ref ausente')
workflow_ref_body = workflow_ref_match.group('body')
if 'UONIX_GITHUB_WORKFLOW_REF' in workflow_ref_body:
    raise AssertionError('painel ainda honra override legado do ref')
if not re.search(r"return\s+'master'\s*;", workflow_ref_body):
    raise AssertionError('painel não fixa o ref canônico master')
if "'ref'    => uox_clone_get_workflow_ref()" not in admin_text:
    raise AssertionError('payload não usa o resolvedor fail-closed do ref')

print('PASS: workflows de clone e validação cobrem guards e testes C3.')
PY
