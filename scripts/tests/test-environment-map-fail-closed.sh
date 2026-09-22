#!/usr/bin/env bash
# Garante que TODA função do mapa de ambientes falha fechado para ambiente inválido.
#
# POR QUE ESTE TESTE EXISTE
#
# As funções do mapa produzem document root, host, usuário e raiz de backup — valores
# que seguem direto para `rsync --delete`, `tar`, `rm -rf` e `ssh`. Um valor vazio
# interpolado ali não aponta para lugar nenhum, ou aponta para a raiz.
#
# O defeito original (achado por revisão do PR 258): as funções eram escritas como
#
#   case "$(uonix_env_canonical "$1")" in ...
#
# e a command substitution DESCARTA o exit status. Para um ambiente inválido,
# `uonix_env_canonical` falhava corretamente, mas o `case` recebia string vazia,
# nenhum ramo casava, e a função devolvia string vazia com **exit 0**. Ou seja,
# `uonix_env_path ambiente-inexistente` respondia '' e dizia que deu tudo certo.
#
# Na época isso não era bug alcançável, porque todo chamador canonizava antes e
# checava o status com `|| return`. Mas a segurança estava na disciplina de quem
# chama, não na biblioteca — e nada obrigava o próximo chamador a lembrar.
#
# DUAS PROVAS, e a segunda é a que impede a regressão silenciosa
#
# Fase 1 é comportamental: cada função, com ambiente inválido, precisa devolver
# status != 0 E não imprimir nada em stdout.
#
# Fase 2 é estrutural, e existe porque a fase 1 pode passar por acidente. Ela
# proíbe as duas grafias que reintroduzem o defeito:
#   - `case "$(uonix_env_canonical ...)"` — descarta o status;
#   - `local x="$(...)"` numa linha só — no Bash o status observado é o do `local`,
#     então a falha da substituição é mascarada.
#
# Compatível com Bash 3.2 (macOS): sem mapfile, sem arrays associativos.

set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
LIBRARY="${ROOT_DIR}/scripts/lib/environment-map.sh"

falhas=0

reprova() {
  printf 'FAIL: %s\n' "$1" >&2
  falhas=$((falhas + 1))
}

if [ ! -f "$LIBRARY" ]; then
  reprova 'scripts/lib/environment-map.sh não existe'
  exit 1
fi

# Topologia válida no ambiente do teste: as funções de produção/QA usam `:?`, então
# sem estas variáveis o teste não distinguiria "falhou por ambiente inválido" de
# "falhou por variável ausente".
export PRODUCTION_URL='https://exemplo-prod.test'
export QA_URL='https://exemplo-qa.test'
export LOCAWEB_SSH_HOST='prod.test'
export LOCAWEB_SSH_PORT='22'
export LOCAWEB_SSH_USER='prod-user'
export LOCAWEB_DOCUMENT_ROOT='/srv/prod'
export LOCAWEB_ACCOUNT_ROOT='/srv'
export LOCAWEB_PHP_BIN='/usr/bin/php'
export LOCAWEB_WP_BIN='/usr/bin/wp'
export HOSTGATOR_SSH_HOST='qa.test'
export HOSTGATOR_SSH_PORT='22'
export HOSTGATOR_SSH_USER='qa-user'
export HOSTGATOR_QA_ROOT='/srv/qa'

# shellcheck source=scripts/lib/environment-map.sh
. "$LIBRARY"

FUNCOES=(
  uonix_env_canonical
  uonix_env_url
  uonix_env_title
  uonix_env_type
  uonix_env_transport
  uonix_env_host
  uonix_env_port
  uonix_env_user
  uonix_env_path
  uonix_env_backup_root
  uonix_env_php_bin
  uonix_env_wp_bin
  uonix_env_requires_ssh_window
)

# ---------------------------------------------------------------------------
# Fase 1: comportamento. Ambiente inválido -> status != 0 e stdout vazio.
# ---------------------------------------------------------------------------
INVALIDOS=(
  'ambiente-inexistente'
  'dev'
  'development'
  ''
)

indice=0
while [ "$indice" -lt "${#FUNCOES[@]}" ]; do
  funcao="${FUNCOES[$indice]}"

  if ! type "$funcao" >/dev/null 2>&1; then
    reprova "função ausente do mapa: ${funcao}"
    indice=$((indice + 1))
    continue
  fi

  invalido_indice=0
  while [ "$invalido_indice" -lt "${#INVALIDOS[@]}" ]; do
    invalido="${INVALIDOS[$invalido_indice]}"
    rotulo="${invalido:-<vazio>}"

    saida="$("$funcao" "$invalido" 2>/dev/null)"
    status="$?"

    if [ "$status" -eq 0 ]; then
      reprova "${funcao} aceitou ambiente inválido <${rotulo}> com exit 0"
    fi
    if [ -n "$saida" ]; then
      reprova "${funcao} imprimiu <${saida}> em stdout para ambiente inválido <${rotulo}>"
    fi

    invalido_indice=$((invalido_indice + 1))
  done

  indice=$((indice + 1))
done

# Contraprova: os ambientes VÁLIDOS continuam respondendo com exit 0 e saída não
# vazia. Sem isto, uma função que falhasse sempre passaria na fase 1.
for valido in prod qa local; do
  for funcao in uonix_env_url uonix_env_type uonix_env_path uonix_env_requires_ssh_window; do
    if ! saida="$("$funcao" "$valido" 2>/dev/null)"; then
      reprova "${funcao} rejeitou ambiente válido <${valido}>"
      continue
    fi
    if [ -z "$saida" ]; then
      reprova "${funcao} devolveu vazio para ambiente válido <${valido}>"
    fi
  done
done

# ---------------------------------------------------------------------------
# Fase 2: estrutura. As duas grafias que mascaram a falha ficam proibidas.
# ---------------------------------------------------------------------------
# A varredura estrutural roda sobre o CÓDIGO, sem comentários. Isso não é detalhe:
# o próprio environment-map.sh documenta o anti-padrão em prosa, citando
# `case "$(uonix_env_canonical ...)"` para explicar por que não se deve usá-lo. Sem
# o filtro, o teste reprovaria por causa da documentação que existe justamente para
# impedir o defeito — foi o que aconteceu na primeira versão.
CODIGO="$(mktemp "${TMPDIR:-/tmp}/uonix-envmap-codigo.XXXXXX")"
trap 'rm -f "$CODIGO"' EXIT
grep -vE '^[[:space:]]*#' "$LIBRARY" > "$CODIGO"

# As aspas simples nas linhas abaixo são deliberadas: os padrões e as mensagens
# citam sintaxe de shell como TEXTO, e expandi-los destruiria o que se procura.
# shellcheck disable=SC2016
if grep -nE 'case[[:space:]]+"\$\(uonix_env_canonical' "$CODIGO"; then
  # shellcheck disable=SC2016
  reprova 'o mapa voltou a usar case "$(uonix_env_canonical ...)": a command substitution descarta o exit status'
fi

if grep -nE '^[[:space:]]*local[[:space:]]+[a-zA-Z_][a-zA-Z0-9_]*="\$\(' "$CODIGO"; then
  # shellcheck disable=SC2016
  reprova 'o mapa usa local x="$(...)" numa linha só: o status observado passa a ser o do local e a falha é mascarada'
fi

# Toda função que consome o canônico precisa capturar o status explicitamente.
esperado_capturas=$(( ${#FUNCOES[@]} - 1 ))
# shellcheck disable=SC2016
capturas="$(grep -cE '^[[:space:]]*env="\$\(uonix_env_canonical "\$1"\)"[[:space:]]*\|\|[[:space:]]*return' "$CODIGO" || true)"
if [ "$capturas" -ne "$esperado_capturas" ]; then
  reprova "esperava ${esperado_capturas} capturas de status com || return, encontrei ${capturas}"
fi

if [ "$falhas" -ne 0 ]; then
  exit 1
fi

printf 'PASS: %d funções do mapa falham fechado para ambiente inválido, sem vazar stdout.\n' \
  "${#FUNCOES[@]}"
