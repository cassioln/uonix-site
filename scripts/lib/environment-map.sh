#!/usr/bin/env bash
# Mapa declarativo dos quatro ambientes Uonix, compatível com Bash 3.2.
# Source this file; do not execute it directly.

uonix_env_auto_load_dotenv() {
  local lib_dir root_dir env_file line var_name var_value
  lib_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
  root_dir="$(cd "${lib_dir}/../.." && pwd)"
  env_file="${root_dir}/.env"

  if [ -f "$env_file" ]; then
    while IFS= read -r line || [ -n "$line" ]; do
      case "$line" in
        '#'*|'') continue ;;
        *=*)
          var_name="${line%%=*}"
          var_name="$(printf '%s' "$var_name" | tr -d '[:space:]')"
          var_value="${line#*=}"
          case "$var_value" in
            '$HOME'/*) var_value="${HOME}${var_value#\$HOME}" ;;
            '~'/*) var_value="${HOME}${var_value#\~}" ;;
          esac
          case "$var_name" in
            [a-zA-Z_][a-zA-Z0-9_]*)
              eval "if [ -z \"\${$var_name+x}\" ]; then export $var_name=\"\$var_value\"; fi"
              ;;
          esac
          ;;
      esac
    done < "$env_file"
  fi
}
uonix_env_auto_load_dotenv

uonix_env_error() {
  printf 'Erro de ambiente: %s\n' "$*" >&2
}

uonix_env_canonical() {
  case "${1:-}" in
    prod|production) printf 'prod\n' ;;
    qa|staging) printf 'qa\n' ;;
    dev|development) printf 'dev\n' ;;
    local) printf 'local\n' ;;
    *)
      uonix_env_error "ambiente inválido: ${1:-vazio}"
      return 1
      ;;
  esac
}

uonix_env_url() {
  case "$(uonix_env_canonical "$1")" in
    prod) printf '%s\n' "${PRODUCTION_URL:?Defina PRODUCTION_URL}" ;;
    qa) printf '%s\n' "${QA_URL:?Defina QA_URL}" ;;
    dev) printf '%s\n' "${DEVELOPMENT_URL:?Defina DEVELOPMENT_URL}" ;;
    local) printf '%s\n' "${LOCAL_URL:-http://localhost:8080}" ;;
  esac
}

uonix_env_title() {
  case "$(uonix_env_canonical "$1")" in
    prod) printf 'Uônix\n' ;;
    qa) printf 'QA - UONIX\n' ;;
    dev) printf 'DEV - UONIX\n' ;;
    local) printf 'LOCAL - UONIX\n' ;;
  esac
}

uonix_env_type() {
  case "$(uonix_env_canonical "$1")" in
    prod) printf 'production\n' ;;
    qa) printf 'staging\n' ;;
    dev) printf 'development\n' ;;
    local) printf 'local\n' ;;
  esac
}

uonix_env_transport() {
  case "$(uonix_env_canonical "$1")" in
    prod) printf 'locaweb-password\n' ;;
    qa|dev) printf 'hostgator-key\n' ;;
    local) printf 'local-podman\n' ;;
  esac
}

uonix_env_host() {
  case "$(uonix_env_canonical "$1")" in
    prod) printf '%s\n' "${LOCAWEB_SSH_HOST:?Defina LOCAWEB_SSH_HOST}" ;;
    qa|dev) printf '%s\n' "${HOSTGATOR_SSH_HOST:?Defina HOSTGATOR_SSH_HOST}" ;;
    local) printf '\n' ;;
  esac
}

uonix_env_port() {
  case "$(uonix_env_canonical "$1")" in
    prod) printf '%s\n' "${LOCAWEB_SSH_PORT:?Defina LOCAWEB_SSH_PORT}" ;;
    qa|dev) printf '%s\n' "${HOSTGATOR_SSH_PORT:?Defina HOSTGATOR_SSH_PORT}" ;;
    local) printf '\n' ;;
  esac
}

uonix_env_user() {
  case "$(uonix_env_canonical "$1")" in
    prod) printf '%s\n' "${LOCAWEB_SSH_USER:?Defina LOCAWEB_SSH_USER}" ;;
    qa|dev) printf '%s\n' "${HOSTGATOR_SSH_USER:?Defina HOSTGATOR_SSH_USER}" ;;
    local) printf '\n' ;;
  esac
}

uonix_env_path() {
  case "$(uonix_env_canonical "$1")" in
    prod) printf '%s\n' "${LOCAWEB_DOCUMENT_ROOT:?Defina LOCAWEB_DOCUMENT_ROOT}" ;;
    qa) printf '%s\n' "${HOSTGATOR_QA_ROOT:?Defina HOSTGATOR_QA_ROOT}" ;;
    dev) printf '%s\n' "${HOSTGATOR_DEV_ROOT:?Defina HOSTGATOR_DEV_ROOT}" ;;
    local) printf '%s\n' "${LOCAL_DOCUMENT_ROOT:-/var/www/html}" ;;
  esac
}

uonix_env_backup_root() {
  case "$(uonix_env_canonical "$1")" in
    prod)
      printf '%s/_uonix-clone-backups/prod\n' "${LOCAWEB_ACCOUNT_ROOT:?Defina LOCAWEB_ACCOUNT_ROOT}"
      ;;
    qa)
      printf '%s/qa\n' "${HOSTGATOR_CLONE_BACKUP_ROOT:-/home2/uonix/_uonix-clone-backups}"
      ;;
    dev)
      printf '%s/dev\n' "${HOSTGATOR_CLONE_BACKUP_ROOT:-/home2/uonix/_uonix-clone-backups}"
      ;;
    local)
      printf '%s\n' "${LOCAL_CLONE_BACKUP_ROOT:-}"
      ;;
  esac
}

uonix_env_php_bin() {
  case "$(uonix_env_canonical "$1")" in
    prod) printf '%s\n' "${LOCAWEB_PHP_BIN:?Defina LOCAWEB_PHP_BIN}" ;;
    qa|dev) printf '%s\n' "${HOSTGATOR_PHP_BIN:-php}" ;;
    local) printf 'php\n' ;;
  esac
}

uonix_env_wp_bin() {
  case "$(uonix_env_canonical "$1")" in
    prod) printf '%s\n' "${LOCAWEB_WP_BIN:?Defina LOCAWEB_WP_BIN}" ;;
    qa|dev) printf '%s\n' "${HOSTGATOR_WP_BIN:-wp}" ;;
    local) printf 'wp\n' ;;
  esac
}

uonix_env_requires_ssh_window() {
  case "$(uonix_env_canonical "$1")" in
    prod) printf 'true\n' ;;
    qa|dev|local) printf 'false\n' ;;
  esac
}
