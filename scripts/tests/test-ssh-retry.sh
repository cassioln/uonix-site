#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
LIBRARY="${ROOT_DIR}/scripts/lib/ssh-retry.sh"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT HUP INT TERM

fail() {
  printf 'FAIL: %s\n' "$*" >&2
  exit 1
}

[ -f "$LIBRARY" ] || fail 'scripts/lib/ssh-retry.sh ainda não existe.'

COUNTER_FILE="$TMP_DIR/count"
export COUNTER_FILE

mock() {
  # $1 = quantas falhas simular antes de suceder; $2 = exit code da falha
  local fail_times="$1"
  local fail_status="${2:-255}"
  count=0
  [ -f "$COUNTER_FILE" ] && count="$(cat "$COUNTER_FILE")"
  count=$((count + 1))
  printf '%s\n' "$count" > "$COUNTER_FILE"
  if [ "$count" -le "$fail_times" ]; then
    return "$fail_status"
  fi
  printf 'mock-ok\n'
  return 0
}
export -f mock

# 1) Sucesso na primeira tentativa: nenhum retry, sem delay.
rm -f "$COUNTER_FILE"
output="$(bash "$LIBRARY" 3 0 -- bash -c 'mock 0 255')"
[ "$output" = 'mock-ok' ] || fail "sucesso imediato não retornou mock-ok (obteve: $output)"
[ "$(cat "$COUNTER_FILE")" = 1 ] || fail 'sucesso imediato não deveria retentar'

# 2) Falha com exit 255 (transporte) duas vezes, sucede na terceira: deve retentar e suceder.
rm -f "$COUNTER_FILE"
output="$(bash "$LIBRARY" 3 0 -- bash -c 'mock 2 255')"
[ "$output" = 'mock-ok' ] || fail "retry não convergiu para sucesso (obteve: $output)"
[ "$(cat "$COUNTER_FILE")" = 3 ] || fail "esperava exatamente 3 tentativas, obteve $(cat "$COUNTER_FILE")"

# 3) Exit code diferente de 255 (falha de lógica remota, não de transporte):
#    não deve retentar — propaga imediatamente na primeira falha.
rm -f "$COUNTER_FILE"
set +e
bash "$LIBRARY" 3 0 -- bash -c 'mock 5 1' >/dev/null
status=$?
set -e
[ "$status" -eq 1 ] || fail "esperava propagar exit 1 sem retry, obteve $status"
[ "$(cat "$COUNTER_FILE")" = 1 ] || fail "exit não-255 não deve retentar; tentativas=$(cat "$COUNTER_FILE")"

# 4) Esgotar todas as tentativas com exit 255 persistente: propaga 255 no fim.
rm -f "$COUNTER_FILE"
set +e
bash "$LIBRARY" 2 0 -- bash -c 'mock 99 255' >/dev/null
status=$?
set -e
[ "$status" -eq 255 ] || fail "esperava exit 255 ao esgotar tentativas, obteve $status"
[ "$(cat "$COUNTER_FILE")" = 2 ] || fail "esperava exatamente 2 tentativas esgotadas, obteve $(cat "$COUNTER_FILE")"

# 5) Validação de argumentos: max_attempts/delay inválidos devem falhar com exit 64.
set +e
bash "$LIBRARY" 0 0 -- true >/dev/null 2>&1
status=$?
set -e
[ "$status" -eq 64 ] || fail "max_attempts=0 deveria ser rejeitado com exit 64, obteve $status"

set +e
bash "$LIBRARY" 3 -1 -- true >/dev/null 2>&1
status=$?
set -e
[ "$status" -eq 64 ] || fail "delay negativo deveria ser rejeitado com exit 64, obteve $status"

echo 'PASS: uonix_ssh_retry retenta só em falha de transporte (255), converge, e propaga outros exit codes imediatamente.'
