#!/usr/bin/env bash
# Instala a versão auditada do WP Super Cache e aplica o perfil Simple Uônix.
set -euo pipefail

WP_ROOT=''
PHP_BIN=''
WP_BIN=''
CONFIG_SCRIPT=''
ARCHIVE=''
SOURCE_URL='https://downloads.wordpress.org/plugin/wp-super-cache.3.1.3.zip'
SOURCE_SHA256='e2773f2146be15c088d5fa4e6280d433b6c08c4d155257be5580b0d69dfcf270'

usage() {
  printf '%s\n' 'Uso: install-wp-super-cache-simple.sh --wp-root=PATH --php-bin=PATH --wp-bin=PATH --config-script=PATH [--archive=ZIP]'
}

fail() {
  printf 'WPSC_SIMPLE_INSTALL=BLOCKED %s\n' "$*" >&2
  exit 1
}

for argument in "$@"; do
  case "$argument" in
    --wp-root=*) WP_ROOT="${argument#*=}" ;;
    --php-bin=*) PHP_BIN="${argument#*=}" ;;
    --wp-bin=*) WP_BIN="${argument#*=}" ;;
    --config-script=*) CONFIG_SCRIPT="${argument#*=}" ;;
    --archive=*) ARCHIVE="${argument#*=}" ;;
    --source-url=*) SOURCE_URL="${argument#*=}" ;;
    --source-sha256=*) SOURCE_SHA256="${argument#*=}" ;;
    --help|-h) usage; exit 0 ;;
    *) fail "argumento desconhecido" ;;
  esac
done

[ -n "$WP_ROOT" ] || fail 'wp_root_ausente'
[ -n "$PHP_BIN" ] || fail 'php_bin_ausente'
[ -n "$WP_BIN" ] || fail 'wp_bin_ausente'
[ -n "$CONFIG_SCRIPT" ] || fail 'config_script_ausente'
[ -d "$WP_ROOT" ] || fail 'wp_root_invalido'
[ -x "$PHP_BIN" ] || fail 'php_bin_invalido'
[ -f "$WP_BIN" ] || fail 'wp_bin_invalido'
[ -f "$CONFIG_SCRIPT" ] || fail 'config_script_invalido'
case "$SOURCE_SHA256" in
  ????????*) ;;
  *) fail 'source_sha256_invalido' ;;
esac
case "$SOURCE_SHA256" in *[!0-9a-f]*) fail 'source_sha256_invalido' ;; esac
[ "${#SOURCE_SHA256}" -eq 64 ] || fail 'source_sha256_invalido'

cli() {
  "$PHP_BIN" -d disable_functions= "$WP_BIN" --path="$WP_ROOT" "$@"
}

plugin_state="$(cli plugin status wp-super-cache --field=status 2>/dev/null || true)"
case "$plugin_state" in
  '') ;;
  *) fail "plugin_preexistente status=${plugin_state}" ;;
esac

for path in \
  "$WP_ROOT/wp-content/advanced-cache.php" \
  "$WP_ROOT/wp-content/wp-cache-config.php" \
  "$WP_ROOT/wp-content/cache"; do
  [ ! -e "$path" ] && [ ! -L "$path" ] || fail 'artefato_cache_preexistente'
done

cleanup_archive=''
if [ -n "$ARCHIVE" ]; then
  [ -f "$ARCHIVE" ] && [ ! -L "$ARCHIVE" ] || fail 'archive_invalido'
  archive="$ARCHIVE"
else
  archive="$(mktemp /tmp/uonix-wpsc.XXXXXX.zip)"
  cleanup_archive="$archive"
  curl --fail --location --silent --show-error --proto '=https' --tlsv1.2 "$SOURCE_URL" --output "$archive"
fi
trap 'rm -f "$cleanup_archive"' EXIT HUP INT TERM
if command -v sha256sum >/dev/null 2>&1; then
  archive_checksum="$(sha256sum "$archive" | cut -d ' ' -f 1)"
else
  archive_checksum="$(shasum -a 256 "$archive" | cut -d ' ' -f 1)"
fi
[ "$archive_checksum" = "$SOURCE_SHA256" ] || fail 'checksum_fonte_divergente'

cli plugin install "$archive" --activate --force
[ "$(cli plugin status wp-super-cache --field=status)" = active ] || fail 'plugin_nao_ativo'
[ "$(cli plugin get wp-super-cache --field=version)" = 3.1.3 ] || fail 'versao_divergente'
cli eval-file "$CONFIG_SCRIPT"

for path in \
  "$WP_ROOT/wp-content/plugins/wp-super-cache/wp-cache.php" \
  "$WP_ROOT/wp-content/advanced-cache.php" \
  "$WP_ROOT/wp-content/wp-cache-config.php" \
  "$WP_ROOT/wp-content/cache"; do
  [ -e "$path" ] && [ ! -L "$path" ] || fail 'artefato_cache_ausente_ou_inseguro'
done

printf 'WPSC_SIMPLE_INSTALL=PASS version=3.1.3 mode=PHP\n'
