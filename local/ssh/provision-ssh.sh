#!/usr/bin/env bash
#
# provision-ssh.sh
# Provisiona o servidor SSH dentro do container WordPress para permitir
# o uso do Remote Explorer (Open Remote - SSH) do Antigravity/VS Code.
#
# Idempotente: pode rodar a cada start do container sem efeitos colaterais.
# Chamado automaticamente pelo entrypoint-ssh.sh (ver compose.yml).
#
set -euo pipefail

MARKER="/var/lib/uonix-ssh-provisioned"
PUBKEY_SRC="/run/secrets/uonix_authorized_keys"
PUBKEY_EXAMPLE="/run/secrets/uonix_authorized_keys_example"
AUTH_KEYS="/root/.ssh/authorized_keys"


log() { echo "[provision-ssh] $*"; }

# 1) Instala dependências apenas na primeira vez (cacheado pelo marker).
#    openssh-server: o sshd em si
#    socat: usado pelo ProxyCommand do ~/.ssh/config (podman exec ... socat)
#    wget: exigido pelo instalador do antigravity-ide-server
if [ ! -f "$MARKER" ]; then
  log "Instalando openssh-server, socat e wget (primeira execução)..."
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -qq
  apt-get install -y --no-install-recommends openssh-server socat wget
  touch "$MARKER"
else
  log "Dependências já instaladas (marker presente)."
fi

# 2) Diretório de runtime do sshd e permissão de root por chave.
mkdir -p /run/sshd
sed -i 's/^#*PermitRootLogin.*/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config

# 3) Instala a chave pública autorizada (montada via volume read-only).
#    Usa a chave pessoal do dev (authorized_keys) e, se não existir,
#    cai para o template versionado (authorized_keys.example).
KEY_FILE=""
if [ -s "$PUBKEY_SRC" ]; then
  KEY_FILE="$PUBKEY_SRC"
elif [ -s "$PUBKEY_EXAMPLE" ]; then
  KEY_FILE="$PUBKEY_EXAMPLE"
  log "Usando authorized_keys.example (chave pessoal não encontrada)."
fi

if [ -n "$KEY_FILE" ]; then
  mkdir -p /root/.ssh
  chmod 700 /root/.ssh
  cp "$KEY_FILE" "$AUTH_KEYS"
  chmod 600 "$AUTH_KEYS"
  log "authorized_keys atualizado a partir de $KEY_FILE."
else
  log "AVISO: nenhuma chave pública encontrada. Conexão SSH não funcionará até montá-la."
fi


# 4) (Re)inicia o sshd. Mata instância anterior para evitar duplicidade.
pkill sshd 2>/dev/null || true
/usr/sbin/sshd
log "sshd iniciado."
