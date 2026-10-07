#!/usr/bin/env bash
# Esteira de atualização de plugins (#393).
#
#   atualizar.sh inventario [--entrada=ARQ]
#       Lê de produção, numa única conexão SSH, os plugins e temas com versão,
#       atualização disponível e atualização automática, e classifica pela
#       política. Com --entrada, usa um JSON já salvo e não conecta.
#
#   atualizar.sh contratos [--plugins-dir=DIR] [--temas-dir=DIR] [SLUG ...]
#       Verifica os contratos contra o fonte instalado. O padrão é o ambiente
#       local, que é onde o ensaio acontece.
#
#   atualizar.sh ensaiar [--entrada=ARQ] [--reusar-clone]
#                        [--aceitar-major=a,b] [--dispensar-quarentena=a,b]
#       Lê o inventário de produção, monta o plano (plano.py) e ensaia no local
#       (ensaio.py): clone prod -> local, alinhamento, backup, smoke antes,
#       atualização na ordem do plano com smoke e contratos a cada etapa.
#       Grava o lock só quando tudo fica verde. Saída 0, 20 ou 30.
#       Muta SOMENTE o ambiente local; produção é só lida.
#
# Exige a janela SSH da Locaweb aberta para o inventário. As credenciais vêm do
# .env do checkout principal, como no clone e no backup de banco.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

# O checkout principal guarda o que não é versionado: .env, local/wp-content e
# backups/. Rodando de uma worktree, ROOT_DIR não tem nada disso.
checkout_principal() {
  if [ -n "${UONIX_CHECKOUT_PRINCIPAL:-}" ]; then
    printf '%s\n' "$UONIX_CHECKOUT_PRINCIPAL"
    return
  fi
  local comum
  comum="$(git -C "$ROOT_DIR" rev-parse --path-format=absolute --git-common-dir 2>/dev/null)" || {
    printf '%s\n' "$ROOT_DIR"
    return
  }
  dirname "$comum"
}
CHECKOUT="$(checkout_principal)"
SAIDA_DIR="${UONIX_PLUGINS_SAIDA_DIR:-${CHECKOUT}/tmp/plugins}"

uso() {
  # O bloco de comentário do topo, sem depender de número de linha.
  awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "${BASH_SOURCE[0]}"
}

# Credenciais do checkout principal, para a leitura de produção e para o clone
# do ensaio (o clone roda de ROOT_DIR, que numa worktree não tem .env).
carregar_env() {
  if [ -z "${LOCAWEB_DOCUMENT_ROOT:-}" ] && [ -f "${CHECKOUT}/.env" ]; then
    set -a
    # shellcheck source=/dev/null
    source "${CHECKOUT}/.env"
    set +a
  fi
}

ler_producao() {
  local destino="$1"
  carregar_env
  : "${LOCAWEB_DOCUMENT_ROOT:?Defina LOCAWEB_DOCUMENT_ROOT (rode do checkout principal, que tem o .env)}"
  : "${LOCAWEB_PHP_BIN:?Defina LOCAWEB_PHP_BIN}"
  : "${LOCAWEB_WP_BIN:?Defina LOCAWEB_WP_BIN}"

  local wp campos remoto bruto
  wp="$(printf '%q -d disable_functions= %q --path=%q' "$LOCAWEB_PHP_BIN" "$LOCAWEB_WP_BIN" "$LOCAWEB_DOCUMENT_ROOT")"
  campos='name,status,version,update,update_version,auto_update'
  remoto="cd $(printf '%q' "$LOCAWEB_DOCUMENT_ROOT") || exit 1
echo '@@PLUGINS'; ${wp} plugin list --fields=${campos} --format=json 2>/dev/null || exit 3
printf '\n@@TEMAS\n'; ${wp} theme list --fields=${campos} --format=json 2>/dev/null || exit 3
printf '\n@@FIM\n'"
  # O printf com quebra de linha antes do marcador é necessário: o WP-CLI
  # imprime o JSON sem quebra final, e o marcador grudaria na última linha.

  # Primeiro plano, sem retry: uma leitura, uma conexão (a Locaweb bloqueia
  # rajadas de conexões curtas). Janela fechada pendura em silêncio, e este Mac
  # não tem `timeout`. Um alarme no processo pai não basta: o ssh filho manteria
  # o stdout aberto e o `$(...)` esperaria por ele. O cão de guarda mata os ssh
  # deste projeto, que são os que usam o diretório de sockets do transporte.
  # shellcheck source=scripts/lib/ssh-transport.sh
  source "${ROOT_DIR}/scripts/lib/ssh-transport.sh"
  local sockets="${UONIX_SSH_CONTROL_DIR:-${RUNNER_TEMP:-/tmp/uonix-ssh-${UID:-0}}}"
  local limite="${UONIX_PLUGINS_LIMITE_SSH:-150}" vigia status=0
  ( sleep "$limite"; pkill -9 -f -- "$sockets" ) >/dev/null 2>&1 &
  vigia=$!
  bruto="$(uonix_transport_ssh_once prod "$remoto")" || status=$?
  kill "$vigia" 2>/dev/null || true
  uonix_transport_close_master prod
  if [ "$status" -ne 0 ]; then
    echo "Erro: leitura de produção falhou (exit ${status}; janela SSH aberta? limite ${limite}s)" >&2
    return 1
  fi

  # Separa pelos marcadores; falha de qualquer tipo guarda a saída bruta (#413).
  printf '%s\n' "$bruto" | python3 "${ROOT_DIR}/scripts/plugins/ler_saida.py" "$destino"
}

cmd_inventario() {
  local entrada=""
  for arg in "$@"; do
    case "$arg" in
      --entrada=*) entrada="${arg#*=}" ;;
      *) echo "Erro: argumento desconhecido: $arg" >&2; return 2 ;;
    esac
  done
  if [ -z "$entrada" ]; then
    mkdir -p "$SAIDA_DIR"
    entrada="${SAIDA_DIR}/inventario-prod-$(date -u +%Y%m%d-%H%M%S).json"
    ler_producao "$entrada" || return 1
    echo "Inventário salvo em ${entrada#"${ROOT_DIR}"/}"
    echo
  fi
  python3 "${ROOT_DIR}/scripts/plugins/inventario.py" --entrada="$entrada"
}

cmd_contratos() {
  local plugins_dir="${CHECKOUT}/local/wp-content/plugins"
  local temas_dir="${CHECKOUT}/local/wp-content/themes"
  local slugs=()
  for arg in "$@"; do
    case "$arg" in
      --plugins-dir=*) plugins_dir="${arg#*=}" ;;
      --temas-dir=*) temas_dir="${arg#*=}" ;;
      --*) echo "Erro: argumento desconhecido: $arg" >&2; return 2 ;;
      *) slugs+=("$arg") ;;
    esac
  done
  python3 "${ROOT_DIR}/scripts/plugins/contratos.py" verificar \
    --plugins-dir="$plugins_dir" --temas-dir="$temas_dir" ${slugs[@]+"${slugs[@]}"}
}

cmd_ensaiar() {
  local entrada="" reusar="" opcoes=()
  for arg in "$@"; do
    case "$arg" in
      --entrada=*) entrada="${arg#*=}" ;;
      --reusar-clone) reusar="--reusar-clone" ;;
      --aceitar-major=*|--dispensar-quarentena=*) opcoes+=("$arg") ;;
      *) echo "Erro: argumento desconhecido: $arg" >&2; return 2 ;;
    esac
  done
  carregar_env
  local dir
  dir="${SAIDA_DIR}/ensaio-$(date -u +%Y%m%d-%H%M%S)"
  mkdir -p "$dir"
  if [ -n "$entrada" ]; then
    cp "$entrada" "${dir}/inventario-prod.json"
  else
    ler_producao "${dir}/inventario-prod.json" || return 30
  fi
  python3 "${ROOT_DIR}/scripts/plugins/plano.py" --inventario "${dir}/inventario-prod.json" \
    --saida "${dir}/plano.json" ${opcoes[@]+"${opcoes[@]}"} || return 30
  echo
  python3 "${ROOT_DIR}/scripts/plugins/ensaio.py" --inventario "${dir}/inventario-prod.json" \
    --plano "${dir}/plano.json" --checkout "$CHECKOUT" --saida "$dir" ${reusar:+"$reusar"}
}

main() {
  local comando="${1:-}"
  [ "$#" -gt 0 ] && shift
  case "$comando" in
    inventario) cmd_inventario "$@" ;;
    contratos) cmd_contratos "$@" ;;
    ensaiar) cmd_ensaiar "$@" ;;
    -h|--help|'') uso ;;
    *) echo "Erro: subcomando desconhecido: $comando" >&2; uso >&2; return 2 ;;
  esac
}

main "$@"
