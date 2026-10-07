#!/usr/bin/env bash
# Esteira de atualização de plugins (#393). Entrega 1: somente leitura.
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
# Exige a janela SSH da Locaweb aberta para o inventário. As credenciais vêm do
# .env do checkout principal, como no clone e no backup de banco.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SAIDA_DIR="${UONIX_PLUGINS_SAIDA_DIR:-${ROOT_DIR}/tmp/plugins}"

uso() {
  # O bloco de comentário do topo, sem depender de número de linha.
  awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "${BASH_SOURCE[0]}"
}

ler_producao() {
  local destino="$1"
  if [ -z "${LOCAWEB_DOCUMENT_ROOT:-}" ] && [ -f "${ROOT_DIR}/.env" ]; then
    set -a
    # shellcheck source=/dev/null
    source "${ROOT_DIR}/.env"
    set +a
  fi
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
  # rajadas de conexões curtas). Janela fechada pendura, daí o alarme.
  bruto="$(perl -e 'alarm 120; exec @ARGV' bash -c \
    'source "$0/scripts/lib/ssh-transport.sh"; uonix_transport_ssh_once prod "$1"' \
    "$ROOT_DIR" "$remoto")" || { echo "Erro: leitura de produção falhou (janela SSH aberta?)" >&2; return 1; }

  # Separa pelos marcadores: o shell remoto pode imprimir ruído antes deles.
  BRUTO="$bruto" python3 - "$destino" <<'PY'
import json, os, sys
partes = {}
atual = None
for linha in os.environ["BRUTO"].splitlines():
    if linha.startswith("@@"):
        atual = linha[2:]
        partes[atual] = []
    elif atual:
        partes[atual].append(linha)
if "FIM" not in partes:
    bruto = sys.argv[1] + ".bruto.txt"
    with open(bruto, "w") as f:
        f.write(os.environ["BRUTO"])
    sys.exit(f"Erro: saída de produção incompleta (sem @@FIM). Saída bruta em {bruto}")
dados = {"plugins": json.loads("\n".join(partes["PLUGINS"])), "temas": json.loads("\n".join(partes["TEMAS"]))}
with open(sys.argv[1], "w") as f:
    json.dump(dados, f, ensure_ascii=False, indent=1)
PY
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
  local plugins_dir="${ROOT_DIR}/local/wp-content/plugins"
  local temas_dir="${ROOT_DIR}/local/wp-content/themes"
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

main() {
  local comando="${1:-}"
  [ "$#" -gt 0 ] && shift
  case "$comando" in
    inventario) cmd_inventario "$@" ;;
    contratos) cmd_contratos "$@" ;;
    -h|--help|'') uso ;;
    *) echo "Erro: subcomando desconhecido: $comando" >&2; uso >&2; return 2 ;;
  esac
}

main "$@"
