#!/usr/bin/env bash
#
# entrypoint-ssh.sh
# Wrapper de entrypoint para o container WordPress local.
#
# Objetivo: provisionar e iniciar o servidor SSH (para o Remote Explorer do
# Antigravity/VS Code) e em seguida delegar para o entrypoint ORIGINAL da
# imagem oficial do WordPress, mantendo o Apache como processo principal (PID 1).
#
# Configurado em local/compose.yml via `entrypoint:`.
#
set -e

# Provisiona o SSH (instala deps na 1a vez, configura chave e sobe o sshd).
# Não deve derrubar o container se algo falhar no SSH; por isso o `|| true`
# protege o boot do WordPress mesmo que o provisionamento tenha problema.
if [ -x /usr/local/bin/provision-ssh.sh ]; then
  /usr/local/bin/provision-ssh.sh || echo "[entrypoint-ssh] provisionamento SSH falhou (seguindo com WordPress)."
fi

# Delega para o entrypoint oficial do WordPress, repassando o CMD original
# (apache2-foreground) e quaisquer argumentos.
exec docker-entrypoint.sh "$@"
