#!/usr/bin/env bash
# Verifica o CONTRATO entre o painel de clone do wp-admin, o workflow
# clone-environment.yml e o script clone-environment.sh.
#
# MOTIVAÇÃO (defeito real, 2026-08-01): as frases de confirmação divergiram e o CI
# ficou verde, porque cada teste validava apenas o SEU lado:
#   test-clone-admin.php e test-clone-guards.sh  -> "CLONAR X PARA PROD"      (sem sha)
#   test-production-workflow.sh e test-clone-workflow.sh -> "... @ <sha>"     (com sha)
# Nenhum comparava os dois. Resultado: clone para prod pelo painel dispararia um
# workflow destinado a falhar em validate-request, gastando janela SSH.
#
# Este teste é estático de propósito: não exige PHP instalado, para rodar em
# qualquer runner do CI.

set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PANEL="$ROOT_DIR/mu-plugins/uonix-admin/48-admin-clone-ambientes.php"
WORKFLOW="$ROOT_DIR/.github/workflows/clone-environment.yml"
SCRIPT="$ROOT_DIR/scripts/clone-environment.sh"

for path in "$PANEL" "$WORKFLOW" "$SCRIPT"; do
  [ -f "$path" ] || {
    printf 'FALHA: arquivo não encontrado: %s\n' "$path" >&2
    exit 1
  }
done

failures=0

report() {
  printf '  FALHA %s\n' "$1" >&2
  failures=$((failures + 1))
}

# 1. Os inputs enviados pelo painel precisam existir no workflow. Um input a mais
#    faz o dispatch responder HTTP 422 e a ferramenta para de funcionar.
panel_inputs="$(
  awk "/'inputs' => array\(/,/^\t\t\t\t\t\)/" "$PANEL" |
    grep -oE "'[a-z_]+' +=>" |
    tr -d "' =>" |
    grep -vx 'inputs' |
    sort -u
)"

workflow_inputs="$(
  awk '/^  workflow_dispatch:/,/^jobs:/' "$WORKFLOW" |
    grep -oE '^      [a-z_]+:' |
    tr -d ' :' |
    sort -u
)"

[ -n "$panel_inputs" ] || report 'não foi possível extrair os inputs enviados pelo painel.'
[ -n "$workflow_inputs" ] || report 'não foi possível extrair os inputs declarados pelo workflow.'

while IFS= read -r input; do
  [ -n "$input" ] || continue
  if ! printf '%s\n' "$workflow_inputs" | grep -qx "$input"; then
    report "painel envia input '$input' que o workflow NÃO declara; dispatch daria HTTP 422."
  fi
done <<< "$panel_inputs"

# 2. As flags do comando local montado pelo painel precisam ser aceitas pelo parser
#    do script, senão o comando sugerido ao operador falha.
panel_flags="$(
  awk '/function uox_clone_build_local_command/,/^}/' "$PANEL" |
    grep -oE "'--[a-z-]+='?" |
    tr -d "'" |
    sed 's/=$//' |
    sort -u
)"

script_flags="$(
  awk '/^clone_parse_arguments\(\)/,/^}/' "$SCRIPT" |
    grep -oE '\-\-[a-z-]+' |
    sort -u
)"

while IFS= read -r flag; do
  [ -n "$flag" ] || continue
  if ! printf '%s\n' "$script_flags" | grep -qx -- "$flag"; then
    report "painel monta comando com '$flag', que clone_parse_arguments() NÃO aceita."
  fi
done <<< "$panel_flags"

# 3. Coerência das frases de confirmação. O workflow amarra a autorização de clone
#    para produção ao SHA do commit. O painel não conhece o SHA, então NÃO pode
#    prosseguir com prod+execute — tem de recusar antes de disparar.
# shellcheck disable=SC2016  # o padrão buscado é literal no YAML, não expansão
if grep -q 'PARA PROD @ \${UONIX_REQUEST_SHA}' "$WORKFLOW"; then
  # O workflow exige SHA. Então o painel precisa ter a guarda de recusa.
  if ! grep -q 'uox_clone_production_requires_manual_dispatch' "$PANEL"; then
    report 'workflow exige SHA na confirmação de clone para prod, mas o painel não recusa prod+execute; ele dispararia um clone destinado a falhar.'
  fi

  # E a recusa precisa vir ANTES de qualquer chamada de dispatch.
  guard_line="$(grep -n 'uox_clone_production_requires_manual_dispatch' "$PANEL" | head -1 | cut -d: -f1)"
  dispatch_line="$(grep -n 'uox_clone_dispatch_workflow( \$source' "$PANEL" | tail -1 | cut -d: -f1)"
  if [ -n "$guard_line" ] && [ -n "$dispatch_line" ] && [ "$guard_line" -gt "$dispatch_line" ]; then
    report "a recusa de prod+execute (linha $guard_line) vem DEPOIS do dispatch (linha $dispatch_line); o clone seria disparado antes da verificação."
  fi
fi

# 4. O ramo não-prod do workflow exige confirmação vazia. O painel precisa permitir
#    esse caminho, isto é, não exigir frase quando o destino não é prod.
# shellcheck disable=SC2016  # o padrão buscado é literal no YAML, não expansão
if grep -q '\[ -z "\$UONIX_CLONE_REQUEST_CONFIRMATION" \]' "$WORKFLOW"; then
  if ! grep -qE "isset\( \\\$_POST\['uox_clone_confirmation'\] \)" "$PANEL"; then
    report 'workflow exige confirmação vazia fora de prod, mas o painel não trata a ausência do campo; poderia enviar valor indevido.'
  fi
fi

if [ "$failures" -ne 0 ]; then
  printf 'FALHA: %s divergência(s) de contrato entre painel, workflow e script de clone.\n' "$failures" >&2
  exit 1
fi

echo 'PASS: contrato de clone coerente entre painel, workflow e script.'
