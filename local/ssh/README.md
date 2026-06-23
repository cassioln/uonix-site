# Remote Explorer (SSH) no container WordPress local

Permite editar os arquivos **dentro** do container `uonix-local-app` usando o
**Remote Explorer** do Antigravity / VS Code (extensão *Open Remote - SSH*),
equivalente ao "Attach to Container".

O servidor SSH é provisionado **automaticamente** toda vez que o container sobe
(`up`, `restart`, recriação), via `entrypoint-ssh.sh` configurado no
`compose.yml`. Não é preciso rodar nada manualmente após reiniciar o ambiente.

## Arquivos

| Arquivo | Versionado? | Função |
|---|---|---|
| `entrypoint-ssh.sh` | ✅ | Wrapper: sobe o sshd e delega ao entrypoint oficial do WordPress |
| `provision-ssh.sh` | ✅ | Instala `openssh-server`, `socat`, `wget`; configura e inicia o sshd (idempotente) |
| `authorized_keys.example` | ✅ | Template/exemplo de chave pública |
| `authorized_keys` | ❌ (gitignore) | Sua chave pública pessoal (cada dev cria a sua) |

## Setup (uma vez por máquina)

1. **Gere um par de chaves** dedicado para o container:
   ```bash
   ssh-keygen -t ed25519 -f ~/.ssh/uonix_container -N "" -C "antigravity-container"
   ```

2. **Registre a chave pública** para o provisionamento usar:
   ```bash
   cp ~/.ssh/uonix_container.pub local/ssh/authorized_keys
   ```

3. **Adicione o host ao `~/.ssh/config`** (ajuste `docker`→`podman` se usar podman):
   ```sshconfig
   Host uonix-container
       HostName localhost
       User root
       IdentityFile ~/.ssh/uonix_container
       StrictHostKeyChecking no
       UserKnownHostsFile /dev/null
       ProxyCommand podman exec -i uonix-local-app socat - TCP:localhost:22
   ```

4. **(Re)suba o container** para aplicar:
   ```bash
   cd local && podman compose up -d --force-recreate wordpress
   ```

5. **Teste a conexão** local:
   ```bash
   ssh -T uonix-container "echo OK && ls /var/www/html"
   ```

## Usando no Antigravity / VS Code

1. Instale a extensão **Open Remote - SSH**.
2. Abra o **Remote Explorer** → **SSH** → conecte em `uonix-container`.
3. Na janela remota: **File → Open Folder → `/var/www/html`**.

> Na 1ª conexão, o IDE baixa o `antigravity-ide-server` dentro do container
> (por isso o `wget` é necessário). Pode levar alguns segundos.

## Observações

- A imagem é a oficial `wordpress:php8.2-apache`; **nada é alterado na imagem**.
  Toda a configuração é montada via volumes — basta clonar o repo e seguir o setup.
- O Apache continua sendo o processo principal (PID 1); o sshd roda ao lado.
- Reiniciar/recriar o container **não** exige mais rodar `service ssh start`.
