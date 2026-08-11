#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLONE_SCRIPT="${ROOT_DIR}/scripts/clone-environment.sh"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT HUP INT TERM

fail() {
  printf 'FAIL: %s\n' "$*" >&2
  exit 1
}

export PRODUCTION_URL='https://site.uonix.com.br'
export QA_URL='https://uonix.ksio.dev'
export DEVELOPMENT_URL='https://test.uonix.ksio.dev'
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
export UONIX_CLONE_LIBRARY_ONLY=1

# shellcheck source=scripts/clone-environment.sh
source "$CLONE_SCRIPT"

for required_function in \
  clone_pair_allowed \
  clone_execution_mode \
  clone_required_confirmation \
  clone_parse_arguments \
  clone_validate_request \
  run_clone \
  bridge_runtime_directory \
  rollback_target; do
  type "$required_function" >/dev/null 2>&1 || fail "função obrigatória ausente: $required_function"
done

all_environments='prod qa dev local'
for source_environment in $all_environments; do
  for target_environment in $all_environments; do
    if [ "$source_environment" = "$target_environment" ]; then
      if clone_pair_allowed "$source_environment" "$target_environment"; then
        fail "source==target foi aceito: $source_environment"
      fi
    else
      clone_pair_allowed "$source_environment" "$target_environment" || fail "par dirigido foi bloqueado: $source_environment -> $target_environment"
    fi
  done
done

[ "$(clone_execution_mode prod qa)" = github-runner ] || fail 'par remoto não usa GitHub runner'
[ "$(clone_execution_mode qa dev)" = github-runner ] || fail 'par HostGator remoto não usa GitHub runner'
[ "$(clone_execution_mode local qa)" = mac ] || fail 'par com local não usa Mac'
[ "$(clone_execution_mode prod local)" = mac ] || fail 'par com local não usa Mac'
[ "$(clone_required_confirmation qa prod)" = 'CLONAR QA PARA PROD' ] || fail 'frase de produção incorreta'

clone_parse_arguments --source=qa --target=dev --dry-run
[ "$SOURCE" = qa ] || fail 'source não parseado'
[ "$TARGET" = dev ] || fail 'target não parseado'
[ "$CLONE_MODE" = dry-run ] || fail 'modo dry-run não parseado'
[ "$REPLACE_USERS" = 0 ] || fail 'usuários não são preservados por padrão'
clone_validate_request

clone_parse_arguments --source=qa --target=prod --execute --replace-users --confirmation='CLONAR QA PARA PROD'
[ "$CLONE_MODE" = execute ] || fail 'modo execute não parseado'
[ "$REPLACE_USERS" = 1 ] || fail '--replace-users não habilitou substituição'
clone_validate_request

clone_parse_arguments --source=qa --target=prod --execute
if clone_validate_request >/dev/null 2>&1; then
  fail 'execução para produção sem frase foi aceita'
fi
clone_parse_arguments --source=qa --target=prod --execute --confirmation='CLONAR QA PARA DEV'
if clone_validate_request >/dev/null 2>&1; then
  fail 'frase incorreta para produção foi aceita'
fi

# Toda execução real precisa realizar o preflight/dry-run no mesmo processo antes da mutação.
sequence_file="$TMP_DIR/sequence"
dry_run_clone() { printf 'dry-run\n' >> "$sequence_file"; }
execute_clone_mutation() { printf 'mutation\n' >> "$sequence_file"; }
# O lock compartilhado é coberto isoladamente em test-clone-lock.sh; este tracer
# verifica somente a ordem dry-run -> mutação sem iniciar transporte remoto.
acquire_clone_lock() { :; }
release_clone_lock() { :; }
SOURCE=qa
TARGET=dev
CLONE_MODE=execute
CONFIRMATION=''
REPLACE_USERS=0
run_clone
[ "$(cat "$sequence_file")" = "$(printf 'dry-run\nmutation')" ] || fail 'execute não fez dry-run antes da mutação'

# A ponte remota sempre passa pelo runner e valida manifest/checksum antes do upload.
: > "$sequence_file"
remote_run() { return 0; }
remote_run_idempotent() { return 0; }
uonix_rsync_to_runner() { printf 'download:%s:%s\n' "$1" "$2" >> "$sequence_file"; mkdir -p "$3"; printf 'fixture\n' > "$3/file.txt"; }
uonix_rsync_from_runner() { printf 'upload:%s:%s\n' "$1" "$3" >> "$sequence_file"; }
uonix_stream_to() { cat >/dev/null; }
bridge_runtime_directory prod qa uploads "$TMP_DIR/bridge"
bridge_log="$(cat "$sequence_file")"
printf '%s\n' "$bridge_log" | grep -q '^download:prod:' || fail 'ponte não baixou origem para runner'
printf '%s\n' "$bridge_log" | grep -q '^upload:qa:' || fail 'ponte não enviou runner ao destino'
[ -s "$TMP_DIR/bridge/uploads/manifest.sha256" ] || fail 'ponte não criou manifest'
(
  cd "$TMP_DIR/bridge/uploads/payload"
  shasum -a 256 -c ../manifest.sha256 >/dev/null
) || fail 'manifest da ponte não valida'

# Somente o status semântico 1 significa diretório ausente. Erros operacionais
# de transporte (por exemplo 255) precisam atravessar a ponte sem download.
remote_run_idempotent() { return 1; }
: > "$sequence_file"
bridge_runtime_directory prod qa uploads "$TMP_DIR/bridge-absent" || \
  fail 'diretório remoto legitimamente ausente não foi tratado como no-op'
[ ! -s "$sequence_file" ] || fail 'ponte baixou payload de diretório remoto ausente'

remote_run_idempotent() { return 255; }
: > "$sequence_file"
if bridge_runtime_directory prod qa uploads "$TMP_DIR/bridge-error" >/dev/null 2>&1; then
  bridge_transport_status=0
else
  bridge_transport_status=$?
fi
[ "$bridge_transport_status" -eq 255 ] || \
  fail "ponte mascarou erro operacional remoto (esperado 255, obtido ${bridge_transport_status})"
[ ! -s "$sequence_file" ] || fail 'ponte baixou payload após erro operacional remoto'

# Falha após início de mutação exige rollback automático.
: > "$sequence_file"
rollback_target() { printf 'rollback:%s:%s\n' "$1" "$2" >> "$sequence_file"; }
MUTATION_STARTED=1
TARGET=dev
TARGET_BACKUP_DIR='/safe/backup'
clone_handle_failure 27
[ "$(cat "$sequence_file")" = 'rollback:dev:/safe/backup' ] || fail 'falha pós-mutação não acionou rollback'

runtime_list="$(clone_runtime_directories)"
for directory in uploads plugins languages; do
  printf '%s\n' "$runtime_list" | grep -qx "$directory" || fail "$directory ausente da lista runtime"
done
if printf '%s\n' "$runtime_list" | grep -Eq 'themes|mu-plugins'; then
  fail 'código versionado entrou no clone runtime'
fi

protected_sql="$(protected_options_where)"
for needle in admin_email active_plugins fluent smtp turnstile captcha; do
  printf '%s\n' "$protected_sql" | grep -qi "$needle" || fail "opção protegida ausente: $needle"
done

if grep -q 'StrictHostKeyChecking=accept-new' "$CLONE_SCRIPT"; then
  fail 'clone ainda aceita host key nova'
fi
if grep -Eq 'https://(test\.)?uonix\.ksio\.dev|/home2/uonix/(public_html|qa_uonix|dev_uonix)' "$CLONE_SCRIPT"; then
  fail 'clone contém topologia hardcoded fora do mapa'
fi
for summary_key in runtime_file_count runtime_directory_count backup_id; do
  grep -q "$summary_key" "$CLONE_SCRIPT" || fail "resumo não publica $summary_key"
done

printf 'PASS: 16 combinações, guards, dry-run interno, ponte e rollback estão protegidos.\n'
