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

canonical_path() {
  local candidate="$1"
  local directory
  local base

  directory="$(dirname "$candidate")"
  base="$(basename "$candidate")"
  ( cd "$directory" >/dev/null 2>&1 && printf '%s/%s\n' "$(pwd -P)" "$base" )
}

# `wp config path` é entrada de confiança limitada: se apontar para fora da
# instalação operada, as constantes seriam escritas noutro site e a "prova" de
# persistência valeria para o arquivo errado. Um symlink no próprio arquivo tem
# o mesmo efeito, então é recusado; links de diretório do sistema (por exemplo
# /var no macOS) são resolvidos, não rejeitados.
require_managed_config_path() {
  local candidate="$1"
  local reason="$2"
  local resolved
  local resolved_root

  case "$candidate" in
    /*) ;;
    *) fail "$reason" ;;
  esac
  [ -f "$candidate" ] || fail "$reason"
  [ ! -L "$candidate" ] || fail "$reason"

  resolved="$(canonical_path "$candidate")" || fail "$reason"
  resolved_root="$(cd "$WP_ROOT" >/dev/null 2>&1 && pwd -P)" || fail "$reason"
  [ -n "$resolved" ] && [ -n "$resolved_root" ] || fail "$reason"

  # O WordPress aceita wp-config.php na raiz ou um nível acima; qualquer outro
  # local significa que a instalação alvo não é a que estamos operando.
  case "$resolved" in
    "$resolved_root"/wp-config.php) ;;
    "$(dirname "$resolved_root")"/wp-config.php) ;;
    *) fail "$reason" ;;
  esac
}

# A prova de persistência precisa vir da configuração PHP efetiva. Um grep de
# texto aceitaria `trueish`, um define comentado ou um WPCACHEHOME divergente.
assert_wpsc_constants_persisted() {
  local stage="$1"
  local expected_home="$2"
  local wp_cache
  local wpcachehome

  wp_cache="$(cli config get WP_CACHE --type=constant 2>/dev/null)" || fail "wp_cache_nao_persistido_${stage}"
  case "$wp_cache" in
    1|true|TRUE|True) ;;
    *) fail "wp_cache_nao_persistido_${stage}" ;;
  esac

  wpcachehome="$(cli config get WPCACHEHOME --type=constant 2>/dev/null)" || fail "wpcachehome_nao_persistido_${stage}"
  [ "$wpcachehome" = "$expected_home" ] || fail "wpcachehome_divergente_${stage}"
}

if cli plugin is-installed wp-super-cache >/dev/null 2>&1; then
  fail 'plugin_preexistente'
fi

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

expected_wpcachehome="$WP_ROOT/wp-content/plugins/wp-super-cache/"

# O caminho é validado ANTES de qualquer escrita: um wp-config alcançado por
# symlink não pode receber constantes nem ser aceito como prova.
config_path="$(cli config path)"
require_managed_config_path "$config_path" 'wp_config_invalido'

# O hook de ativação do WPSC cria advanced-cache.php e o arquivo de
# configuração apenas quando WP_CACHE já está habilitado. Declarar a constante
# depois da ativação deixaria o drop-in incompleto até uma ação manual.
cli config set WP_CACHE true --raw
cli config set WPCACHEHOME "$expected_wpcachehome" --type=constant

# Fail-closed antes da mutação: se `wp config set` retornar sucesso sem
# persistir, a ativação NÃO deve rodar em estado incorreto.
assert_wpsc_constants_persisted 'pre_ativacao' "$expected_wpcachehome"

cli plugin install "$archive" --activate --force
cli plugin is-active wp-super-cache >/dev/null 2>&1 || fail 'plugin_nao_ativo'
[ "$(cli plugin get wp-super-cache --field=version)" = 3.1.3 ] || fail 'versao_divergente'

# Defesa adicional: a ativação do WPSC reescreve o wp-config em alguns
# caminhos, então o estado é reconfirmado depois dela.
config_path="$(cli config path)"
require_managed_config_path "$config_path" 'wp_config_invalido_pos_ativacao'
assert_wpsc_constants_persisted 'pos_ativacao' "$expected_wpcachehome"

cli eval-file "$CONFIG_SCRIPT"

for path in \
  "$WP_ROOT/wp-content/plugins/wp-super-cache/wp-cache.php" \
  "$WP_ROOT/wp-content/advanced-cache.php" \
  "$WP_ROOT/wp-content/wp-cache-config.php" \
  "$WP_ROOT/wp-content/cache"; do
  [ -e "$path" ] && [ ! -L "$path" ] || fail 'artefato_cache_ausente_ou_inseguro'
done

printf 'WPSC_SIMPLE_INSTALL=PASS version=3.1.3 mode=PHP\n'
