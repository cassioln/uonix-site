#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
LIBRARY="${ROOT_DIR}/scripts/lib/environment-map.sh"

fail() {
  printf 'FAIL: %s\n' "$*" >&2
  exit 1
}

if [ ! -f "$LIBRARY" ]; then
  fail 'scripts/lib/environment-map.sh ainda não existe.'
fi

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

# shellcheck source=scripts/lib/environment-map.sh
source "$LIBRARY"

assert_eq() {
  local expected="$1"
  local actual="$2"
  local message="$3"
  [ "$actual" = "$expected" ] || fail "$message: esperado '$expected', obtido '$actual'"
}

for env in prod qa dev local; do
  assert_eq "$env" "$(uonix_env_canonical "$env")" "ambiente canônico $env"
done
assert_eq prod "$(uonix_env_canonical production)" 'alias production'
assert_eq qa "$(uonix_env_canonical staging)" 'alias staging'
assert_eq dev "$(uonix_env_canonical development)" 'alias development'
if uonix_env_canonical unknown >/dev/null 2>&1; then
  fail 'ambiente desconhecido foi aceito'
fi

assert_eq 'https://site.uonix.com.br' "$(uonix_env_url prod)" 'URL produção'
assert_eq 'https://uonix.ksio.dev' "$(uonix_env_url qa)" 'URL QA'
assert_eq 'https://test.uonix.ksio.dev' "$(uonix_env_url dev)" 'URL DEV'
assert_eq 'http://localhost:8080' "$(uonix_env_url local)" 'URL local'

assert_eq 'Uônix' "$(uonix_env_title prod)" 'título produção'
assert_eq 'QA - UONIX' "$(uonix_env_title qa)" 'título QA'
assert_eq 'DEV - UONIX' "$(uonix_env_title dev)" 'título DEV'
assert_eq 'LOCAL - UONIX' "$(uonix_env_title local)" 'título local'

assert_eq production "$(uonix_env_type prod)" 'tipo produção'
assert_eq staging "$(uonix_env_type qa)" 'tipo QA'
assert_eq development "$(uonix_env_type dev)" 'tipo DEV'
assert_eq local "$(uonix_env_type local)" 'tipo local'

assert_eq locaweb-password "$(uonix_env_transport prod)" 'transporte produção'
assert_eq hostgator-key "$(uonix_env_transport qa)" 'transporte QA'
assert_eq hostgator-key "$(uonix_env_transport dev)" 'transporte DEV'
assert_eq local-podman "$(uonix_env_transport local)" 'transporte local'

assert_eq 'ftp.site.uonix.com.br' "$(uonix_env_host prod)" 'host produção'
assert_eq '108.179.252.137' "$(uonix_env_host qa)" 'host QA'
assert_eq '108.179.252.137' "$(uonix_env_host dev)" 'host DEV'
assert_eq '' "$(uonix_env_host local)" 'host local vazio'

assert_eq siteuonix1 "$(uonix_env_user prod)" 'usuário produção'
assert_eq uonix "$(uonix_env_user qa)" 'usuário QA'
assert_eq uonix "$(uonix_env_user dev)" 'usuário DEV'
assert_eq '' "$(uonix_env_user local)" 'usuário local vazio'

assert_eq '/home/storage/f/34/12/siteuonix1/public_html' "$(uonix_env_path prod)" 'path produção'
assert_eq '/home2/uonix/public_html' "$(uonix_env_path qa)" 'path QA'
assert_eq '/home2/uonix/dev_uonix' "$(uonix_env_path dev)" 'path DEV'
assert_eq '/var/www/html' "$(uonix_env_path local)" 'path local'

assert_eq true "$(uonix_env_requires_ssh_window prod)" 'janela SSH produção'
assert_eq false "$(uonix_env_requires_ssh_window qa)" 'janela SSH QA'
assert_eq false "$(uonix_env_requires_ssh_window dev)" 'janela SSH DEV'
assert_eq false "$(uonix_env_requires_ssh_window local)" 'janela SSH local'

assert_eq '/home/storage/f/34/12/siteuonix1/_uonix-clone-backups/prod' "$(uonix_env_backup_root prod)" 'backup produção'
assert_eq '/home2/uonix/_uonix-clone-backups/qa' "$(uonix_env_backup_root qa)" 'backup QA'
assert_eq '/home2/uonix/_uonix-clone-backups/dev' "$(uonix_env_backup_root dev)" 'backup DEV'
assert_eq '/usr/bin/php85' "$(uonix_env_php_bin prod)" 'PHP produção'
assert_eq '/home/storage/f/34/12/siteuonix1/bin/wp-cli.phar' "$(uonix_env_wp_bin prod)" 'WP-CLI produção'

# Produção nunca pode receber defaults silenciosos.
if (
  unset LOCAWEB_DOCUMENT_ROOT
  uonix_env_path prod >/dev/null 2>&1
); then
  fail 'path de produção ganhou default silencioso'
fi

# A biblioteca precisa permanecer compatível com Bash 3.2: sem associative arrays/readarray/mapfile.
if grep -Eq 'declare[[:space:]]+-A|(^|[^[:alnum:]_])(readarray|mapfile)([^[:alnum:]_]|$)' "$LIBRARY"; then
  fail 'biblioteca usa recurso posterior ao Bash 3.2'
fi

printf 'PASS: mapa shell cobre prod, QA, DEV e local sem defaults silenciosos.\n'
