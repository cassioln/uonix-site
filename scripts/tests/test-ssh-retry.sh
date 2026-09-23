#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
LIBRARY="${ROOT_DIR}/scripts/lib/ssh-retry.sh"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT HUP INT TERM

# O spool de --replay-stdin é criado em `${TMPDIR:-/tmp}`. Apontar TMPDIR para o
# diretório deste teste é o que permite a asserção 10 varrer só o próprio
# território: sem isto, o spool cairia no TMPDIR do sistema, e escopar a varredura
# tornaria aquela asserção VACUOSA — ela nunca acharia o vazamento que existe para
# detectar. As duas mudanças andam juntas; uma sem a outra é pior que nenhuma.
export TMPDIR="$TMP_DIR"

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

# --- --replay-stdin ----------------------------------------------------------
#
# Existe por um fail-open MEDIDO: quando o comando retentado lê o script remoto de
# stdin (`ssh ... bash -s -- args <<'REMOTE'`), o heredoc é um arquivo temporário
# cujo offset avança. A primeira tentativa consome tudo; as seguintes recebem
# stdin VAZIO, e `bash -s` sem entrada não executa nada e sai 0. Ou seja: o retry
# declarava SUCESSO sem rodar uma única asserção remota.
#
# Os mocks abaixo registram quantos BYTES cada tentativa recebeu, que é a única
# forma de distinguir "retentou e reenviou" de "retentou e mandou nada".
STDIN_COUNT="$TMP_DIR/stdin-count"
STDIN_LOG="$TMP_DIR/stdin-log"
export STDIN_COUNT STDIN_LOG

cat > "$TMP_DIR/stdin-consumer.sh" <<'CONSUMER'
#!/usr/bin/env bash
set -u
attempt=0
[ -f "$STDIN_COUNT" ] && attempt="$(cat "$STDIN_COUNT")"
attempt=$((attempt + 1))
printf '%s\n' "$attempt" > "$STDIN_COUNT"
payload="$(cat)"
printf 'attempt=%s bytes=%s\n' "$attempt" "${#payload}" >> "$STDIN_LOG"
[ "$attempt" -ge "${STDIN_SUCCEED_AT:-3}" ] || exit "${STDIN_FAIL_STATUS:-255}"
exit 0
CONSUMER

# 6) Com --replay-stdin, TODA tentativa recebe o payload e o retry converge.
rm -f "$STDIN_COUNT"
: > "$STDIN_LOG"
bash "$LIBRARY" --replay-stdin 3 0 -- bash "$TMP_DIR/stdin-consumer.sh" 2>/dev/null <<'PAYLOAD'
echo payload-remoto
PAYLOAD
[ "$(cat "$STDIN_COUNT")" = 3 ] \
  || fail "esperadas 3 tentativas com --replay-stdin, obteve $(cat "$STDIN_COUNT")"
[ "$(grep -c 'bytes=19' "$STDIN_LOG")" = 3 ] \
  || fail "alguma tentativa não recebeu o payload de 19 bytes: $(cat "$STDIN_LOG")"

# 7) Payload vazio é erro de chamador (64), não sucesso silencioso. Sem esta
#    recusa, pedir replay sem payload reproduz exatamente o fail-open original.
set +e
bash "$LIBRARY" --replay-stdin 3 0 -- true < /dev/null >/dev/null 2>&1
status=$?
set -e
[ "$status" -eq 64 ] || fail "--replay-stdin sem payload deveria sair 64, obteve $status"

# 8) A bandeira não altera o critério de retry nem corrompe o status propagado.
#    Uma condição composta mal escrita devolveria o status do TESTE da bandeira
#    (sempre 0 ou 1) em vez do status do comando, e "só 255 retenta" passaria a
#    ler o número errado.
rm -f "$STDIN_COUNT"
: > "$STDIN_LOG"
set +e
STDIN_FAIL_STATUS=7 STDIN_SUCCEED_AT=99 \
  bash "$LIBRARY" --replay-stdin 3 0 -- bash "$TMP_DIR/stdin-consumer.sh" >/dev/null 2>&1 <<'PAYLOAD'
echo payload-remoto
PAYLOAD
status=$?
set -e
[ "$status" -eq 7 ] \
  || fail "com --replay-stdin, exit 7 deveria propagar intacto, obteve $status"
[ "$(cat "$STDIN_COUNT")" = 1 ] \
  || fail "exit não-255 não deve retentar com --replay-stdin; tentativas=$(cat "$STDIN_COUNT")"

# 9) A bandeira desloca os argumentos corretamente: a validação de max_attempts e
#    delay continua valendo depois dela.
for bad_args in '0 0' '3 -1'; do
  set +e
  # shellcheck disable=SC2086
  bash "$LIBRARY" --replay-stdin $bad_args -- true >/dev/null 2>&1 <<'PAYLOAD'
payload
PAYLOAD
  status=$?
  set -e
  [ "$status" -eq 64 ] \
    || fail "--replay-stdin $bad_args deveria ser rejeitado com 64, obteve $status"
done

# 10) A cópia de stdin não pode sobreviver ao processo: o script remoto pode
#     conter caminhos e nomes de arquivo do destino.
# Escopado ao TMP_DIR deste teste, e não ao TMPDIR do sistema. Varrendo o diretório
# do sistema, um arquivo vazado por OUTRA execução — uma rodada de mutação anterior,
# por exemplo — fazia este teste reprovar sem nada errado no código sob teste.
# Observado. Em runner efêmero do Actions o TMPDIR é por job e o efeito não aparece;
# localmente tornava o teste dependente de ordem de execução, e FAIL falso em teste
# de guarda é o pior tipo de ruído, porque ensina a ignorar vermelho.
# 10b) SIGTERM tem de ENCERRAR, não só limpar. Um trap de sinal sem `exit` roda o
#      handler e RETOMA: o processo sobrevive, a tentativa seguinte falha ao abrir o
#      spool já removido, e o wrapper sai 1. Fail-closed, mas ignora o cancelamento
#      do Actions até o SIGKILL e converte estado retentável em erro duro.
#
#      Sem espera de tempo fixo: aguarda a mensagem de retry aparecer, que é a prova
#      de que o processo já está no `sleep` da cadência. Assim o teste não depende de
#      velocidade de máquina.
sinal_log="$TMP_DIR/sinal.err"
cat > "$TMP_DIR/sempre-255.sh" <<'MOCK'
#!/usr/bin/env bash
exit 255
MOCK
chmod 755 "$TMP_DIR/sempre-255.sh"

printf 'carga\n' | bash "$LIBRARY" --replay-stdin 3 5 -- "$TMP_DIR/sempre-255.sh" 2> "$sinal_log" &
sinal_pid=$!

sinal_esperou=0
while [ "$sinal_esperou" -lt 100 ]; do
  if grep -q 'aguardando' "$sinal_log" 2>/dev/null; then
    break
  fi
  sleep 0.1
  sinal_esperou=$((sinal_esperou + 1))
done
grep -q 'aguardando' "$sinal_log" 2>/dev/null \
  || fail 'o wrapper não chegou à espera do retry; não há como testar o sinal'

kill -TERM "$sinal_pid" 2>/dev/null || fail 'não consegui enviar SIGTERM ao wrapper'
set +e
wait "$sinal_pid"
sinal_status=$?
set -e
[ "$sinal_status" -eq 143 ] \
  || fail "SIGTERM deveria encerrar com 143 (128+15), obteve $sinal_status"

leaked="$(find "$TMP_DIR" -maxdepth 1 -name 'uonix-ssh-retry-stdin.*' 2>/dev/null | head -1)"
[ -z "$leaked" ] || fail "cópia de stdin vazou em $leaked"

echo 'PASS: uonix_ssh_retry retenta só em falha de transporte (255), converge, propaga outros exit codes imediatamente, e --replay-stdin reenvia o payload em toda tentativa sem afrouxar o critério.'
