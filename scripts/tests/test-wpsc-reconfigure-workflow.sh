#!/usr/bin/env bash
# Contrato do caminho opt-in para reconfigurar WPSC já instalado.
# Não reinstala plugin; cria checkpoint do config e rollback restaura só esse arquivo.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKFLOW="$ROOT_DIR/.github/workflows/deploy-production.yml"

python3 - "$WORKFLOW" <<'PY'
import pathlib
import sys

import yaml

workflow = pathlib.Path(sys.argv[1])
text = workflow.read_text(encoding='utf-8')
document = yaml.safe_load(text)
inputs = document[True]['workflow_dispatch']['inputs']

def fail(message):
    raise SystemExit(f'FAIL: {message}')

def require(needle, message):
    if needle not in text:
        fail(message)

setting = inputs.get('configure_page_cache')
if not isinstance(setting, dict):
    fail('input configure_page_cache ausente')
if setting.get('type') != 'boolean' or setting.get('required') is not True or setting.get('default') is not False:
    fail('input configure_page_cache precisa ser booleano, obrigatório e desligado por padrão')
if 'reconfigurar' not in str(setting.get('description', '')).lower():
    fail('descrição do input não identifica reconfiguração')

# O caminho deve permanecer explicitamente opt-in e distinto da instalação.
for needle, message in (
    ('UONIX_CONFIGURE_PAGE_CACHE: ${{ inputs.configure_page_cache }}', 'input não chega ao authorize'),
    ('case "$UONIX_CONFIGURE_PAGE_CACHE" in true|false)', 'authorize não valida input'),
    ('if [ "$UONIX_INSTALL_PAGE_CACHE" = true ] && [ "$UONIX_CONFIGURE_PAGE_CACHE" = true ]; then', 'workflow aceita instalar e reconfigurar ao mesmo tempo'),
    ('- name: Configure existing WP Super Cache safely', 'step de reconfiguração ausente'),
    ('if: ${{ inputs.configure_page_cache }}', 'step de reconfiguração não é opt-in'),
    ('plugin is-installed wp-super-cache', 'reconfiguração não exige plugin existente'),
    ('plugin is-active wp-super-cache', 'reconfiguração não exige plugin ativo'),
    ('plugin get wp-super-cache --field=version)" = 3.1.3', 'reconfiguração não fixa versão auditada'),
    ('wpsc-reconfigure', 'checkpoint específico ausente'),
    ('wpsc-reconfigure-started', 'marcador específico ausente'),
    ('cp -p -- "$config_path" "$backup/wp-cache-config.php"', 'checkpoint não preserva config WPSC'),
    ('cli eval-file "$config_script"', 'configurador não roda pelo WP-CLI'),
    ('cp -p -- "$wpsc_reconfigure_backup" "$document_root/wp-content/wp-cache-config.php"', 'rollback não restaura só o config WPSC'),
    ('wpsc_reconfigure_marker="$operation_lock/wpsc-reconfigure-started"', 'rollback não declara o marcador de reconfiguração antes do gate inicial'),
    ('[ ! -e "$wpsc_reconfigure_marker" ] && [ ! -L "$wpsc_reconfigure_marker" ]', 'gate inicial de rollback ignora reconfiguração pendente'),
    ('"$wpsc_reconfigure_marker"', 'loop de validação comum não cobre marcador de reconfiguração'),
    ('test "$(file_mode "$wpsc_reconfigure_marker")" = 600', 'rollback não exige marcador WPSC reconfigurado em modo 0600'),
    ('test "$(cat "$wpsc_reconfigure_marker")" = "$run_id"', 'rollback não exige ownership do marcador WPSC reconfigurado'),
    ('test "$(file_mode "$wpsc_reconfigure_backup")" = 600', 'rollback não exige checkpoint WPSC reconfigurado em modo 0600'),
    ('test "$(file_mode "$wpsc_reconfigure_dir")" = 700', 'rollback não exige diretório privado do checkpoint'),
    ('test ! -e "$lock_path/wpsc-reconfigure-started"', 'release não exige remoção do marcador'),
):
    require(needle, message)

# O rollback de reconfiguração não pode desinstalar o WPSC existente.
rollback_start = text.index('# Reconfiguração de WPSC existente')
rollback_end = text.index('# O dump permanece', rollback_start)
reconfigure = text[rollback_start:rollback_end]
if 'plugin deactivate wp-super-cache' in reconfigure:
    fail('rollback de reconfiguração pode desativar plugin existente')

print('PASS: workflow reconfigura WPSC existente com checkpoint e rollback seletivo.')
PY