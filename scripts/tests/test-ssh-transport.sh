#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
LIBRARY="${ROOT_DIR}/scripts/lib/ssh-transport.sh"
TMP_DIR="$(mktemp -d)"
CONTROL_DIR="/tmp/uonix-ssh-transport-test-$$"
trap 'rm -rf "$TMP_DIR" "$CONTROL_DIR"' EXIT HUP INT TERM

fail() {
  printf 'FAIL: %s\n' "$*" >&2
  exit 1
}

[ -f "$LIBRARY" ] || fail 'scripts/lib/ssh-transport.sh ainda não existe.'

mkdir -p "$TMP_DIR/bin" "$TMP_DIR/runner"
printf 'known host fixture\n' > "$TMP_DIR/known-hosts"
printf 'private key fixture\n' > "$TMP_DIR/key"
printf 'correct-password-not-for-logs\n' > "$TMP_DIR/password"
chmod 600 "$TMP_DIR/known-hosts" "$TMP_DIR/key" "$TMP_DIR/password"

cat > "$TMP_DIR/bin/ssh" <<'MOCK'
#!/usr/bin/env bash
set -u
printf 'ssh' >> "$MOCK_TRANSPORT_LOG"
printf ' <%s>' "$@" >> "$MOCK_TRANSPORT_LOG"
printf '\n' >> "$MOCK_TRANSPORT_LOG"
count=0
if [ -f "$MOCK_TRANSPORT_COUNT" ]; then
  count="$(cat "$MOCK_TRANSPORT_COUNT")"
fi
count=$(( count + 1 ))
printf '%s\n' "$count" > "$MOCK_TRANSPORT_COUNT"
case "${MOCK_SSH_MODE:-success}" in
  success)
    printf 'mock-ok\n'
    exit 0
    ;;
  retry)
    if [ "$count" -lt 3 ]; then
      exit 255
    fi
    printf 'mock-ok-after-retry\n'
    exit 0
    ;;
  import-started)
    printf '__UONIX_REMOTE_IMPORT_STARTED__\n' >&2
    exit 255
    ;;
  *) exit 2 ;;
esac
MOCK

cat > "$TMP_DIR/bin/sshpass" <<'MOCK'
#!/usr/bin/env bash
set -u
printf 'sshpass' >> "$MOCK_TRANSPORT_LOG"
printf ' <%s>' "$@" >> "$MOCK_TRANSPORT_LOG"
printf '\n' >> "$MOCK_TRANSPORT_LOG"
[ "${1:-}" = '-e' ] && shift
exec "$@"
MOCK

cat > "$TMP_DIR/bin/rsync" <<'MOCK'
#!/usr/bin/env bash
set -u
printf 'rsync' >> "$MOCK_TRANSPORT_LOG"
printf ' <%s>' "$@" >> "$MOCK_TRANSPORT_LOG"
printf '\n' >> "$MOCK_TRANSPORT_LOG"
count=0
if [ -s "$MOCK_RSYNC_COUNT" ]; then
  count="$(cat "$MOCK_RSYNC_COUNT")"
fi
count=$(( count + 1 ))
printf '%s\n' "$count" > "$MOCK_RSYNC_COUNT"
if [ "$count" -le "${MOCK_RSYNC_FAILURES_BEFORE_SUCCESS:-0}" ]; then
  exit "${MOCK_RSYNC_FAILURE_STATUS:-255}"
fi
exit 0
MOCK

cat > "$TMP_DIR/bin/podman" <<'MOCK'
#!/usr/bin/env bash
set -u
printf 'podman' >> "$MOCK_TRANSPORT_LOG"
printf ' <%s>' "$@" >> "$MOCK_TRANSPORT_LOG"
printf '\n' >> "$MOCK_TRANSPORT_LOG"
printf 'local-ok\n'
MOCK
chmod 755 "$TMP_DIR/bin/ssh" "$TMP_DIR/bin/sshpass" "$TMP_DIR/bin/rsync" "$TMP_DIR/bin/podman"

export PATH="$TMP_DIR/bin:$PATH"
export MOCK_TRANSPORT_LOG="$TMP_DIR/transport.log"
export MOCK_TRANSPORT_COUNT="$TMP_DIR/transport.count"
export MOCK_RSYNC_COUNT="$TMP_DIR/rsync.count"
export HOSTGATOR_SSH_KEY="$TMP_DIR/key"
export HOSTGATOR_SSH_KNOWN_HOSTS_FILE="$TMP_DIR/known-hosts"
export LOCAWEB_SSH_PASSWORD_FILE="$TMP_DIR/password"
export LOCAWEB_SSH_KNOWN_HOSTS_FILE="$TMP_DIR/known-hosts"
export UONIX_TRANSPORT_RETRY_DELAY=0
export UONIX_TRANSPORT_MAX_ATTEMPTS=5
export UONIX_LOCAL_APP_CONTAINER='uonix-local-app'
export UONIX_SSH_CONTROL_DIR="$CONTROL_DIR"

export LOCAWEB_SSH_HOST='ftp.site.uonix.com.br'
export LOCAWEB_SSH_PORT='22'
export LOCAWEB_SSH_USER='siteuonix1'
export LOCAWEB_DOCUMENT_ROOT='/home/storage/f/34/12/siteuonix1/public_html'
export LOCAWEB_ACCOUNT_ROOT='/home/storage/f/34/12/siteuonix1'
export LOCAWEB_PHP_BIN='/usr/bin/php85'
export LOCAWEB_WP_BIN='/home/storage/f/34/12/siteuonix1/bin/wp-cli.phar'
export HOSTGATOR_SSH_HOST='108.179.252.137'
export HOSTGATOR_SSH_PORT='22'
export HOSTGATOR_SSH_USER='uonix'
export HOSTGATOR_QA_ROOT='/home2/uonix/public_html'
export HOSTGATOR_DEV_ROOT='/home2/uonix/dev_uonix'
export PRODUCTION_URL='https://site.uonix.com.br'
export QA_URL='https://uonix.ksio.dev'
export DEVELOPMENT_URL='https://test.uonix.ksio.dev'

# shellcheck source=scripts/lib/ssh-transport.sh
source "$LIBRARY"

test_fr1_stream_mv_failure() (
  local errexit_state="$1"
  local output_file="$TMP_DIR/fr1-stream-mv-${errexit_state}"
  local status

  if [ "$errexit_state" = enabled ]; then
    set -e
  else
    set +e
  fi
  : > "$MOCK_TRANSPORT_COUNT"
  rm -f "$output_file" "${output_file}.partial"
  mv() { return 44; }

  if MOCK_SSH_MODE=success uonix_transport_stream_to_file \
    qa 'printf stream-for-mv-failure' "$output_file"; then
    status=0
  else
    status=$?
  fi
  unset -f mv

  [ "$status" -eq 44 ] || fail "FR1 mv falho retornou ${status}, esperado 44"
  [ ! -e "$output_file" ] || fail 'FR1 mv falho publicou output final'
  [ ! -e "${output_file}.partial" ] || fail 'FR1 mv falho preservou .partial'
  case "$errexit_state:$-" in
    enabled:*e*) ;;
    disabled:*e*) fail 'FR1 mv falho habilitou errexit no chamador' ;;
    disabled:*) ;;
    *) fail 'FR1 mv falho desabilitou errexit no chamador' ;;
  esac
)

if [ -n "${FR1_MV_ERREXIT:-}" ]; then
  test_fr1_stream_mv_failure "$FR1_MV_ERREXIT" || exit $?
  printf 'PASS: mv=44 preserva publicação e errexit (%s).\n' "$FR1_MV_ERREXIT"
  exit 0
fi

test_fr1_stream_mv_failure disabled
test_fr1_stream_mv_failure enabled

test_d1r_stream_success_preserves_errexit() (
  set +e
  : > "$MOCK_TRANSPORT_COUNT"
  rm -f "$TMP_DIR/stream-success" "$TMP_DIR/stream-success.partial"

  if MOCK_SSH_MODE=success uonix_transport_stream_to_file \
    qa 'printf stream-success' "$TMP_DIR/stream-success"; then
    :
  else
    fail 'D1R stream_to_file sucesso retornou falha'
  fi
  case "$-" in
    *e*) fail 'D1R stream_to_file sucesso habilitou errexit no chamador' ;;
  esac
)

test_d1r_stream_failure_preserves_errexit() (
  local status

  set +e
  : > "$MOCK_TRANSPORT_COUNT"
  rm -f "$TMP_DIR/stream-failure" "$TMP_DIR/stream-failure.partial"

  if MOCK_SSH_MODE=failure uonix_transport_stream_to_file \
    qa 'printf stream-failure' "$TMP_DIR/stream-failure"; then
    fail 'D1R stream_to_file falha retornou sucesso'
  else
    status=$?
  fi
  case "$-" in
    *e*) fail 'D1R stream_to_file falha habilitou errexit no chamador' ;;
  esac
  [ "$status" -eq 2 ] || fail "D1R stream_to_file alterou status de falha: ${status}"
  [ ! -e "$TMP_DIR/stream-failure.partial" ] || fail 'D1R stream_to_file preservou .partial após falha'
)

test_d1r_import_success_preserves_errexit() (
  set +e
  printf 'SELECT 1;\n' | gzip -c > "$TMP_DIR/d1r-success.sql.gz"
  : > "$MOCK_TRANSPORT_COUNT"

  if MOCK_SSH_MODE=success uonix_transport_import_gzip \
    prod "$TMP_DIR/d1r-success.sql.gz" 'consume sql' >/dev/null; then
    :
  else
    fail 'D1R import_gzip sucesso retornou falha'
  fi
  case "$-" in
    *e*) fail 'D1R import_gzip sucesso habilitou errexit no chamador' ;;
  esac
)

test_d1r_import_failure_preserves_errexit() (
  local status

  set +e
  printf 'SELECT 1;\n' | gzip -c > "$TMP_DIR/d1r-failure.sql.gz"
  : > "$MOCK_TRANSPORT_COUNT"

  if MOCK_SSH_MODE=import-started uonix_transport_import_gzip \
    qa "$TMP_DIR/d1r-failure.sql.gz" 'consume sql'; then
    fail 'D1R import_gzip falha retornou sucesso'
  else
    status=$?
  fi
  case "$-" in
    *e*) fail 'D1R import_gzip falha habilitou errexit no chamador' ;;
  esac
  [ "$status" -eq 255 ] || fail "D1R import_gzip alterou status SSH: ${status}"
  [ "$(cat "$MOCK_TRANSPORT_COUNT")" = 1 ] || fail 'D1R import_gzip repetiu após marcador remoto'
)

case "${D1R_ERREXIT_CASE:-}" in
  '') ;;
  stream-success) test_d1r_stream_success_preserves_errexit ;;
  stream-failure) test_d1r_stream_failure_preserves_errexit ;;
  import-success) test_d1r_import_success_preserves_errexit ;;
  import-failure) test_d1r_import_failure_preserves_errexit ;;
  *) fail "caso D1R desconhecido: ${D1R_ERREXIT_CASE}" ;;
esac
if [ -n "${D1R_ERREXIT_CASE:-}" ]; then
  printf 'PASS: caso D1R %s preserva errexit.\n' "$D1R_ERREXIT_CASE"
  exit 0
fi

for required_function in \
  uonix_exec \
  uonix_stream_from \
  uonix_stream_to \
  uonix_rsync_to_runner \
  uonix_rsync_from_runner; do
  type "$required_function" >/dev/null 2>&1 || fail "função obrigatória ausente: $required_function"
done

: > "$MOCK_TRANSPORT_LOG"
: > "$MOCK_TRANSPORT_COUNT"
MOCK_SSH_MODE=success uonix_exec qa -- printf qa >/dev/null
qa_log="$(cat "$MOCK_TRANSPORT_LOG")"
printf '%s' "$qa_log" | grep -q 'StrictHostKeyChecking=yes' || fail 'QA sem host key estrita'
printf '%s' "$qa_log" | grep -q "UserKnownHostsFile=$TMP_DIR/known-hosts" || fail 'QA sem known_hosts fixado'
printf '%s' "$qa_log" | grep -q "<$TMP_DIR/key>" || fail 'QA sem chave dedicada'
printf '%s' "$qa_log" | grep -q 'ControlMaster=auto' || fail 'QA abre conexões independentes'
printf '%s' "$qa_log" | grep -q 'ControlPersist=120' || fail 'QA não mantém o socket entre etapas'
printf '%s' "$qa_log" | grep -Fq "ControlPath=$CONTROL_DIR/uonix-%C" || fail 'QA sem ControlPath curto e hashado'
printf '%s' "$qa_log" | grep -q 'accept-new' && fail 'QA ainda aceita host key nova'

: > "$MOCK_TRANSPORT_LOG"
: > "$MOCK_TRANSPORT_COUNT"
MOCK_SSH_MODE=success uonix_exec prod -- printf production >/dev/null
production_log="$(cat "$MOCK_TRANSPORT_LOG")"
printf '%s' "$production_log" | grep -q 'sshpass <-e>' || fail 'produção não usou sshpass -e'
printf '%s' "$production_log" | grep -q 'StrictHostKeyChecking=yes' || fail 'produção sem host key estrita'
printf '%s' "$production_log" | grep -q 'ControlMaster=auto' || fail 'produção abre conexões independentes'
printf '%s' "$production_log" | grep -q 'ControlPersist=120' || fail 'produção não mantém o socket entre etapas'
printf '%s' "$production_log" | grep -Fq "ControlPath=$CONTROL_DIR/uonix-%C" || fail 'produção sem ControlPath curto e hashado'
if printf '%s' "$production_log" | grep -q 'correct-password-not-for-logs'; then
  fail 'senha apareceu na linha de comando/log'
fi

short_control_dir="$UONIX_SSH_CONTROL_DIR"
UONIX_SSH_CONTROL_DIR="$TMP_DIR/$(printf 'x%.0s' {1..90})"
export UONIX_SSH_CONTROL_DIR
if uonix_transport_build_ssh_command qa >/dev/null 2>&1; then
  fail 'ControlPath longo foi aceito e falharia silenciosamente no OpenSSH real'
fi
UONIX_SSH_CONTROL_DIR="$short_control_dir"
export UONIX_SSH_CONTROL_DIR

: > "$MOCK_TRANSPORT_LOG"
uonix_exec local -- wp option get home >/dev/null
local_log="$(cat "$MOCK_TRANSPORT_LOG")"
printf '%s' "$local_log" | grep -q 'podman <exec>.*<uonix-local-app>' || fail 'local não usou podman exec'

: > "$MOCK_TRANSPORT_LOG"
MOCK_SSH_MODE=success uonix_rsync_to_runner qa '/remote/uploads' "$TMP_DIR/runner/uploads"
runner_in_log="$(cat "$MOCK_TRANSPORT_LOG")"
printf '%s' "$runner_in_log" | grep -q 'rsync' || fail 'download para runner não usou rsync'
printf '%s' "$runner_in_log" | grep -q 'uonix@108.179.252.137:/remote/uploads/' || fail 'origem remota incorreta no runner'

: > "$MOCK_TRANSPORT_LOG"
MOCK_SSH_MODE=success uonix_rsync_from_runner prod "$TMP_DIR/runner/uploads" '/remote/uploads'
runner_out_log="$(cat "$MOCK_TRANSPORT_LOG")"
printf '%s' "$runner_out_log" | grep -q 'sshpass' || fail 'upload Locaweb não usou sshpass'
if printf '%s' "$runner_out_log" | grep -q 'correct-password-not-for-logs'; then
  fail 'senha apareceu no rsync/log'
fi

# Configuração de retry inválida precisa falhar antes de abrir o transporte rsync.
for invalid_attempts in 0 00 000; do
  : > "$MOCK_RSYNC_COUNT"
  UONIX_TRANSPORT_MAX_ATTEMPTS="$invalid_attempts"
  if uonix_rsync_to_runner qa '/remote/uploads' "$TMP_DIR/runner/invalid-retry" >/dev/null 2>&1; then
    fail "rsync aceitou tentativas inválidas como sucesso: ${invalid_attempts}"
  fi
  [ ! -s "$MOCK_RSYNC_COUNT" ] || fail "tentativas inválidas abriram rsync: ${invalid_attempts}"
done
UONIX_TRANSPORT_MAX_ATTEMPTS=5

: > "$MOCK_RSYNC_COUNT"
UONIX_TRANSPORT_RETRY_DELAY=08
if uonix_rsync_to_runner qa '/remote/uploads' "$TMP_DIR/runner/invalid-delay" >/dev/null 2>&1; then
  fail 'rsync aceitou delay decimal ambíguo como sucesso: 08'
fi
[ ! -s "$MOCK_RSYNC_COUNT" ] || fail 'delay decimal ambíguo abriu rsync: 08'
UONIX_TRANSPORT_RETRY_DELAY=0

# Downloads rsync podem repetir somente falhas transitórias conhecidas. Cada
# tentativa precisa reaplicar exatamente a mesma operação declarativa.
export MOCK_RSYNC_FAILURES_BEFORE_SUCCESS=2
for retry_status in 10 12 30 35 255; do
  export MOCK_RSYNC_FAILURE_STATUS="$retry_status"
  : > "$MOCK_TRANSPORT_LOG"
  : > "$MOCK_RSYNC_COUNT"
  if uonix_rsync_to_runner qa '/remote/uploads' "$TMP_DIR/runner/retry-${retry_status}" >/dev/null 2>&1; then
    :
  else
    fail "rsync download não repetiu status transitório ${retry_status}"
  fi
  [ "$(cat "$MOCK_RSYNC_COUNT")" = 3 ] || \
    fail "rsync download não parou após sucesso para status ${retry_status}"
  [ "$(LC_ALL=C sort -u "$MOCK_TRANSPORT_LOG" | wc -l | tr -d ' ')" = 1 ] || \
    fail "rsync download mudou argumentos entre tentativas para status ${retry_status}"
done
unset MOCK_RSYNC_FAILURE_STATUS MOCK_RSYNC_FAILURES_BEFORE_SUCCESS

# Erros de conteúdo/arquivos não são transitórios: repetir pode esconder uma
# origem inconsistente e precisa parar na primeira tentativa.
export MOCK_RSYNC_FAILURE_STATUS=23
export MOCK_RSYNC_FAILURES_BEFORE_SUCCESS=5
: > "$MOCK_RSYNC_COUNT"
if uonix_rsync_to_runner qa '/remote/uploads' "$TMP_DIR/runner/non-retryable" >/dev/null 2>&1; then
  fail 'rsync aceitou status não transitório 23 como sucesso'
else
  rsync_nonretry_status=$?
fi
[ "$rsync_nonretry_status" -eq 23 ] || fail "rsync alterou status não transitório: ${rsync_nonretry_status}"
[ "$(cat "$MOCK_RSYNC_COUNT")" = 1 ] || fail 'rsync repetiu status não transitório 23'
unset MOCK_RSYNC_FAILURE_STATUS MOCK_RSYNC_FAILURES_BEFORE_SUCCESS

# O upload usa o mesmo payload local imutável e também é replay-safe: repetir
# uma falha transitória deve convergir para o mesmo destino/argumentos.
export MOCK_RSYNC_FAILURE_STATUS=255
export MOCK_RSYNC_FAILURES_BEFORE_SUCCESS=2
: > "$MOCK_TRANSPORT_LOG"
: > "$MOCK_RSYNC_COUNT"
if uonix_rsync_from_runner prod "$TMP_DIR/runner/uploads" '/remote/uploads' >/dev/null 2>&1; then
  :
else
  fail 'rsync upload não repetiu falha transitória 255'
fi
[ "$(cat "$MOCK_RSYNC_COUNT")" = 3 ] || fail 'rsync upload não parou após sucesso'
[ "$(LC_ALL=C sort -u "$MOCK_TRANSPORT_LOG" | wc -l | tr -d ' ')" = 1 ] || \
  fail 'rsync upload mudou argumentos entre tentativas'
unset MOCK_RSYNC_FAILURE_STATUS MOCK_RSYNC_FAILURES_BEFORE_SUCCESS

: > "$MOCK_TRANSPORT_LOG"
: > "$MOCK_TRANSPORT_COUNT"
MOCK_SSH_MODE=retry uonix_transport_ssh_retry qa 'printf retry' >/dev/null
[ "$(cat "$MOCK_TRANSPORT_COUNT")" = 3 ] || fail 'retry idempotente não parou após sucesso'

# A biblioteca é sourceável: helpers não podem alterar `errexit` do chamador.
(
  set +e
  : > "$MOCK_TRANSPORT_COUNT"
  if MOCK_SSH_MODE=success uonix_transport_ssh_retry qa 'printf state' >/dev/null; then
    :
  else
    fail 'retry SSH saudável falhou no teste de errexit'
  fi
  case "$-" in
    *e*) fail 'retry SSH habilitou errexit no chamador' ;;
  esac
)

test_d1r_stream_success_preserves_errexit
test_d1r_stream_failure_preserves_errexit

: > "$MOCK_TRANSPORT_COUNT"
UONIX_TRANSPORT_MAX_ATTEMPTS=0
if MOCK_SSH_MODE=success uonix_transport_ssh_retry qa 'printf skipped' >/dev/null 2>&1; then
  fail 'retry aceitou zero tentativas como sucesso'
fi
[ ! -s "$MOCK_TRANSPORT_COUNT" ] || fail 'configuração inválida abriu SSH'
UONIX_TRANSPORT_MAX_ATTEMPTS=3

printf 'SELECT 1;\n' | gzip -c > "$TMP_DIR/source.sql.gz"
test_d1r_import_success_preserves_errexit
test_d1r_import_failure_preserves_errexit
: > "$MOCK_TRANSPORT_LOG"
: > "$MOCK_TRANSPORT_COUNT"
if MOCK_SSH_MODE=import-started uonix_transport_import_gzip qa "$TMP_DIR/source.sql.gz" 'consume sql'; then
  fail 'importação simulada deveria falhar'
fi
[ "$(cat "$MOCK_TRANSPORT_COUNT")" = 1 ] || fail 'importação foi repetida depois do marcador remoto'

calls_before="$(cat "$MOCK_TRANSPORT_COUNT")"
HOSTGATOR_SSH_KNOWN_HOSTS_FILE="$TMP_DIR/missing-known-hosts"
export HOSTGATOR_SSH_KNOWN_HOSTS_FILE
if uonix_exec qa -- printf unsafe >/dev/null 2>&1; then
  fail 'transporte aceitou known_hosts ausente'
fi
[ "$(cat "$MOCK_TRANSPORT_COUNT")" = "$calls_before" ] || fail 'SSH iniciou sem known_hosts válido'

printf 'PASS: transporte fixa host keys, separa auth e limita retry a operações replay-safe.\n'
