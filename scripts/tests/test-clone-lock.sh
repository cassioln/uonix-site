#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLONE_SCRIPT="$ROOT_DIR/scripts/clone-environment.sh"
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

LOCAL_BACKUP_ROOT="$TMP_DIR/backups"
CLONE_RUN_ID='local-run-1'
CLONE_LOCK_HELD=0
CLONE_LOCK_PATH=''
is_remote_env() { [ "$1" != local ]; }

acquire_clone_lock local || fail 'lock local canônico foi rejeitado'
[ "$CLONE_LOCK_HELD" = 1 ] || fail 'lock local não marcou ownership'
[ -d "$CLONE_LOCK_PATH" ] || fail 'diretório de lock local ausente'
[ "$(<"$CLONE_LOCK_PATH/owner")" = "$CLONE_RUN_ID" ] || fail 'owner local incorreto'
first_lock_path="$CLONE_LOCK_PATH"

# Simula outro processo: não possui o lock, mas encontra o diretório existente.
CLONE_LOCK_HELD=0
CLONE_LOCK_PATH=''
if acquire_clone_lock local >/dev/null 2>&1; then
  fail 'segundo clone local adquiriu lock concorrente'
fi
[ -d "$first_lock_path" ] || fail 'tentativa concorrente removeu lock alheio'

CLONE_LOCK_HELD=1
CLONE_LOCK_PATH="$first_lock_path"
release_clone_lock || fail 'release local rejeitado'
[ ! -e "$first_lock_path" ] || fail 'lock local não foi removido pelo owner'
[ "$CLONE_LOCK_HELD" = 0 ] || fail 'release local manteve estado held'

remote_log="$TMP_DIR/remote.log"
: > "$remote_log"
wp_path() { printf '/remote/wp\n'; }
REMOTE_STATUS=0
remote_run() {
  printf '%s\n' "$2" >> "$remote_log"
  return "$REMOTE_STATUS"
}

CLONE_RUN_ID='remote-run-2'
CLONE_LOCK_HELD=0
CLONE_LOCK_PATH=''
acquire_clone_lock qa || fail 'lock remoto canônico foi rejeitado'
[ "$CLONE_LOCK_HELD" = 1 ] || fail 'lock remoto não marcou ownership'
[ "$CLONE_LOCK_PATH" = '/remote/wp/.uonix-operation.lock' ] || fail 'clone remoto não usa lock compartilhado do ambiente'
grep -q 'mkdir' "$remote_log" || fail 'lock remoto não usa mkdir atômico'
grep -q 'owner' "$remote_log" || fail 'lock remoto não registra owner'
release_clone_lock || fail 'release remoto rejeitado'
grep -q 'rmdir' "$remote_log" || fail 'release remoto não remove lock vazio'
grep -q 'remote-run-2' "$remote_log" || fail 'release remoto não confere owner'

REMOTE_STATUS=73
CLONE_RUN_ID='remote-run-3'
CLONE_LOCK_HELD=0
CLONE_LOCK_PATH=''
if acquire_clone_lock qa >/dev/null 2>&1; then
  fail 'falha remota de lock foi mascarada'
fi
[ "$CLONE_LOCK_HELD" = 0 ] || fail 'falha remota marcou lock como adquirido'

REMOTE_STATUS=0
CLONE_RUN_ID='inseguro/com/barra'
CLONE_LOCK_HELD=0
CLONE_LOCK_PATH=''
: > "$remote_log"
if acquire_clone_lock qa >/dev/null 2>&1; then
  fail 'RUN_ID inseguro foi aceito'
fi
[ ! -s "$remote_log" ] || fail 'RUN_ID inseguro abriu transporte'

printf 'PASS: clone usa lock atômico por destino e libera apenas o próprio owner.\n'
