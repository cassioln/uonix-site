#!/usr/bin/env bash
# Mapa declarativo dos três ambientes Uonix, compatível com Bash 3.2.
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
          # shellcheck disable=SC2016
          case "$var_value" in
            "\$HOME"/*) var_value="${HOME}${var_value#\$HOME}" ;;
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

# O ambiente remoto de desenvolvimento saiu da topologia. `dev`/`development`
# caem no ramo inválido de propósito: toda a tooling passa por aqui, então um
# nome retirado falha FECHADO no primeiro passo, em vez de seguir adiante com
# variáveis vazias e apontar rsync para um destino indefinido.
uonix_env_canonical() {
  case "${1:-}" in
    prod|production) printf 'prod\n' ;;
    qa|staging) printf 'qa\n' ;;
    local) printf 'local\n' ;;
    *)
      uonix_env_error "ambiente inválido: ${1:-vazio}"
      return 1
      ;;
  esac
}

# TODAS as funções abaixo seguem o MESMO contrato de duas partes, e as duas
# importam:
#
# 1. `local env` é declarado em linha PRÓPRIA, e só depois vem
#    `env="$(uonix_env_canonical "$1")" || return 1`. Escrever
#    `local env="$(...)"` numa linha só NÃO funciona: no Bash o status observado
#    passa a ser o do `local`, e a falha da substituição é mascarada. Escrever
#    `case "$(uonix_env_canonical "$1")" in` também não funciona: a command
#    substitution DESCARTA o status, o `case` recebe string vazia, nenhum ramo
#    casa, e a função devolve vazio com exit 0 — que era o defeito original.
#
# 2. Cada `case` tem um ramo `*)` explícito. Hoje ele é inalcançável, porque
#    uonix_env_canonical só devolve prod/qa/local. Ele existe para o dia em que um
#    nome canônico novo for adicionado: sem o ramo, a função devolveria vazio com
#    exit 0 para o ambiente novo, e o vazio seguiria para rsync/tar/ssh.
#
# O motivo de tudo isso: estas funções produzem docroot, host e backup root. Um
# valor vazio interpolado num `rsync --delete` não aponta para lugar nenhum — ou
# aponta para a raiz.

uonix_env_url() {
  local env
  env="$(uonix_env_canonical "$1")" || return 1
  case "$env" in
    prod) printf '%s\n' "${PRODUCTION_URL:?Defina PRODUCTION_URL}" ;;
    qa) printf '%s\n' "${QA_URL:?Defina QA_URL}" ;;
    local) printf '%s\n' "${LOCAL_URL:-http://localhost:8080}" ;;
    *) uonix_env_error "ambiente sem URL declarada: ${env}"; return 1 ;;
  esac
}

uonix_env_title() {
  local env
  env="$(uonix_env_canonical "$1")" || return 1
  case "$env" in
    prod) printf 'Uônix\n' ;;
    qa) printf 'QA - UONIX\n' ;;
    local) printf 'LOCAL - UONIX\n' ;;
    *) uonix_env_error "ambiente sem título declarado: ${env}"; return 1 ;;
  esac
}

uonix_env_type() {
  local env
  env="$(uonix_env_canonical "$1")" || return 1
  case "$env" in
    prod) printf 'production\n' ;;
    qa) printf 'staging\n' ;;
    local) printf 'local\n' ;;
    *) uonix_env_error "ambiente sem WP_ENVIRONMENT_TYPE declarado: ${env}"; return 1 ;;
  esac
}

uonix_env_transport() {
  local env
  env="$(uonix_env_canonical "$1")" || return 1
  case "$env" in
    prod) printf 'locaweb-password\n' ;;
    qa) printf 'hostgator-key\n' ;;
    local) printf 'local-podman\n' ;;
    *) uonix_env_error "ambiente sem transporte declarado: ${env}"; return 1 ;;
  esac
}

uonix_env_host() {
  local env
  env="$(uonix_env_canonical "$1")" || return 1
  case "$env" in
    prod) printf '%s\n' "${LOCAWEB_SSH_HOST:?Defina LOCAWEB_SSH_HOST}" ;;
    qa) printf '%s\n' "${HOSTGATOR_SSH_HOST:?Defina HOSTGATOR_SSH_HOST}" ;;
    local) printf '\n' ;;
    *) uonix_env_error "ambiente sem host declarado: ${env}"; return 1 ;;
  esac
}

uonix_env_port() {
  local env
  env="$(uonix_env_canonical "$1")" || return 1
  case "$env" in
    prod) printf '%s\n' "${LOCAWEB_SSH_PORT:?Defina LOCAWEB_SSH_PORT}" ;;
    qa) printf '%s\n' "${HOSTGATOR_SSH_PORT:?Defina HOSTGATOR_SSH_PORT}" ;;
    local) printf '\n' ;;
    *) uonix_env_error "ambiente sem porta declarada: ${env}"; return 1 ;;
  esac
}

uonix_env_user() {
  local env
  env="$(uonix_env_canonical "$1")" || return 1
  case "$env" in
    prod) printf '%s\n' "${LOCAWEB_SSH_USER:?Defina LOCAWEB_SSH_USER}" ;;
    qa) printf '%s\n' "${HOSTGATOR_SSH_USER:?Defina HOSTGATOR_SSH_USER}" ;;
    local) printf '\n' ;;
    *) uonix_env_error "ambiente sem usuário declarado: ${env}"; return 1 ;;
  esac
}

uonix_env_path() {
  local env
  env="$(uonix_env_canonical "$1")" || return 1
  case "$env" in
    prod) printf '%s\n' "${LOCAWEB_DOCUMENT_ROOT:?Defina LOCAWEB_DOCUMENT_ROOT}" ;;
    qa) printf '%s\n' "${HOSTGATOR_QA_ROOT:?Defina HOSTGATOR_QA_ROOT}" ;;
    local) printf '%s\n' "${LOCAL_DOCUMENT_ROOT:-/var/www/html}" ;;
    *) uonix_env_error "ambiente sem document root declarado: ${env}"; return 1 ;;
  esac
}

uonix_env_backup_root() {
  local env
  env="$(uonix_env_canonical "$1")" || return 1
  case "$env" in
    prod)
      printf '%s/_uonix-clone-backups/prod\n' "${LOCAWEB_ACCOUNT_ROOT:?Defina LOCAWEB_ACCOUNT_ROOT}"
      ;;
    qa)
      printf '%s/qa\n' "${HOSTGATOR_CLONE_BACKUP_ROOT:-/home2/uonix/_uonix-clone-backups}"
      ;;
    local)
      printf '%s\n' "${LOCAL_CLONE_BACKUP_ROOT:-}"
      ;;
    *)
      uonix_env_error "ambiente sem raiz de backup declarada: ${env}"
      return 1
      ;;
  esac
}

uonix_env_php_bin() {
  local env
  env="$(uonix_env_canonical "$1")" || return 1
  case "$env" in
    prod) printf '%s\n' "${LOCAWEB_PHP_BIN:?Defina LOCAWEB_PHP_BIN}" ;;
    qa) printf '%s\n' "${HOSTGATOR_PHP_BIN:-php}" ;;
    local) printf 'php\n' ;;
    *) uonix_env_error "ambiente sem binário PHP declarado: ${env}"; return 1 ;;
  esac
}

uonix_env_wp_bin() {
  local env
  env="$(uonix_env_canonical "$1")" || return 1
  case "$env" in
    prod) printf '%s\n' "${LOCAWEB_WP_BIN:?Defina LOCAWEB_WP_BIN}" ;;
    qa) printf '%s\n' "${HOSTGATOR_WP_BIN:-wp}" ;;
    local) printf 'wp\n' ;;
    *) uonix_env_error "ambiente sem binário WP-CLI declarado: ${env}"; return 1 ;;
  esac
}

uonix_env_requires_ssh_window() {
  local env
  env="$(uonix_env_canonical "$1")" || return 1
  case "$env" in
    prod) printf 'true\n' ;;
    qa|local) printf 'false\n' ;;
    *) uonix_env_error "ambiente sem política de janela SSH declarada: ${env}"; return 1 ;;
  esac
}
