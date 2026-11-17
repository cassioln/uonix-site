#!/usr/bin/env bash
# Sonda autenticada canônica, somente leitura; nunca imprime credenciais.
set -euo pipefail
umask 077
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
[ "${HERMES_HOME:-}" = /Users/cassio/.hermes/profiles/rocky ] || exit 30
export UONIX_ATUALIZAR_BIBLIOTECA=1
# shellcheck source=scripts/plugins/atualizar.sh
source "$ROOT/scripts/plugins/atualizar.sh"
carregar_env
# shellcheck source=scripts/lib/ssh-transport.sh
source "$ROOT/scripts/lib/ssh-transport.sh"
[ "$CHECKOUT" = /Users/cassio/GitHubPessoal/uonix-site ] || exit 30
host="$(uonix_environment_field prod host)"
case "$host" in ftp.uonix.com.br|ftp.siteuonix1.hospedagemdesites.ws|179.188.55.94) ;; *) exit 30;; esac
[ "$(uonix_environment_field prod user)" = siteuonix1 ] || exit 30
[ "$(uonix_environment_field prod port)" = 22 ] || exit 30
for nome in LOCAWEB_SSH_PASSWORD_FILE LOCAWEB_SSH_KNOWN_HOSTS_FILE; do
  uonix_transport_require_private_file "${!nome:-}" "$nome" >/dev/null 2>&1 || exit 30
done
base="$HERMES_HOME/locaweb-ssh"
[ -d "$base" ] && [ ! -L "$base" ] && [ -O "$base" ] || exit 30
pasta="$(mktemp -d "$base/sonda-XXXXXXXX")"
cd "$base"
export UONIX_SSH_CONTROL_DIR="./${pasta##*/}/c"
export UONIX_PLUGINS_LIMITE_SONDA=30
limpar() {
  uonix_transport_close_master prod
  rmdir "$pasta/c" "$pasta" 2>/dev/null || true
}
trap limpar EXIT
trap 'exit 30' TERM INT HUP
uonix_transport_build_ssh_command prod >/dev/null 2>&1 || exit 30
# Sonda única não precisa de master desacoplado: flags anteriores vencem os
# defaults canônicos, mantendo senha/host keys e impedindo órfão ControlPersist.
UONIX_TRANSPORT_SSH=("${UONIX_TRANSPORT_SSH[@]:0:3}" -o ControlMaster=no -o ControlPersist=no -o ControlPath=none "${UONIX_TRANSPORT_SSH[@]:3}")
saida="$(uonix_transport_execute_built_ssh "$UONIX_TRANSPORT_REMOTE" 'echo JANELA-ABERTA' 2>/dev/null)" || exit 30
if [ "$saida" = JANELA-ABERTA ]; then
  printf 'SSH_AUTENTICADO_OK\n'
else
  exit 30
fi
