#!/usr/bin/env bash
# Impede que o ambiente remoto de desenvolvimento retirado volte ao código
# versionado ou à documentação.
#
# POR QUE ESTE TESTE EXISTE
#
# A topologia passou de quatro para três ambientes (produção, QA e local). O
# ambiente remoto de desenvolvimento saiu junto com seu workflow de deploy, sua
# entrada no painel de clone, seus ramos no mapa de ambientes e seu par na matriz
# de clone. Sem guard, qualquer um desses pontos volta em silêncio: o par seria
# aceito na UI, resolveria para variáveis inexistentes e apontaria rsync para um
# docroot vazio.
#
# O QUE É BANIDO — E O QUE NÃO PODE SER
#
# Banidos são os tokens de INFRAESTRUTURA do ambiente retirado: hostname,
# docroot, as Variables de deploy e clone, e o nome de Environment do workflow.
#
# NÃO é banida a palavra "DEV" nem o tipo de ambiente 'development'. Essa
# distinção não é estética, é de segurança: 'development' continua sendo um
# WP_ENVIRONMENT_TYPE válido e permanece no array $allowed de
# mu-plugins/uonix-shared/environment.php de propósito — removê-lo faria o
# fallback devolver 'production' para uma máquina que se reporte como
# desenvolvimento, liberando indexação e analytics. Por consequência, o
# mapeamento 'development' => 'DEV' de 49-email-environment-label.php e as
# asserções de test-email-policy.php e test-analytics-policy.php descrevem
# comportamento VIVO. Um guard que banisse a palavra reprovaria código correto e
# pressionaria alguém a remover uma trava.
#
# DUAS FASES
#
# Fase 1 prova que cada padrão casa o exemplo canônico que promete casar, e que
# nenhum padrão casa os textos legítimos. Sem ela, um padrão corrompido por
# edição ou normalização deixaria de casar, a varredura não acharia nada e o
# teste passaria — reportando ausência sem ter procurado.
#
# ESCOPO
#
# Somente arquivos VERSIONADOS, enumerados por `git ls-files`. Diretórios
# ignorados pelo Git (scripts/maintenance/local/, local/wp-content/, tmp/)
# guardam runtime e scripts operacionais antigos que legitimamente citam o
# ambiente retirado. Delegar ao .gitignore mantém o escopo correto sozinho.
#
# ESTE ARQUIVO se exclui da varredura: ele necessariamente contém os tokens que
# proíbe.
#
# Compatível com Bash 3.2 (macOS): sem mapfile, sem arrays associativos.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SELF_REL="scripts/tests/$(basename "${BASH_SOURCE[0]}")"

cd "$ROOT_DIR"

# Padrões ERE, aplicados case-insensitive.
TERMOS=(
  'test\.uonix\.(ksio\.dev|com\.br)'
  'dev_uonix'
  'HOSTGATOR_DEV_ROOT'
  'DEVELOPMENT_URL'
  'ENABLE_DEPLOY_DEVELOPMENT'
  'development-hostgator'
)

MOTIVOS=(
  'hostname do ambiente remoto de desenvolvimento retirado'
  'docroot do ambiente retirado'
  'Variable de docroot do ambiente retirado'
  'Variable de URL do ambiente retirado'
  'Variable de guard de deploy do ambiente retirado'
  'nome de Environment do workflow de deploy retirado'
)

# Exemplo que cada padrão TEM de casar. É o que torna a fase 1 uma prova.
#
# As aspas simples são deliberadas: estes são textos LITERAIS de fixture, não
# expressões a expandir. Expandir `${DEVELOPMENT_URL:?}` aqui abortaria o teste.
# shellcheck disable=SC2016
EXEMPLOS=(
  'https://test.uonix.ksio.dev'
  '/home2/uonix/dev_uonix'
  'vars.HOSTGATOR_DEV_ROOT'
  '${DEVELOPMENT_URL:?}'
  'vars.ENABLE_DEPLOY_DEVELOPMENT'
  'environment_name: development-hostgator'
)

# Textos LEGÍTIMOS que nenhum padrão pode casar. Guardam contra um padrão largo
# demais, que transformaria o guard num bloqueio de comportamento correto.
#
# Aspas simples deliberadas, pelo mesmo motivo de EXEMPLOS: são trechos de código
# PHP citados como texto, não expressões de shell.
# shellcheck disable=SC2016
NEGATIVOS=(
  'DEV'
  '[DEV] Mensagem de teste'
  "'development' => 'DEV'"
  'in_array( $wp_environment, $allowed, true )'
  'wp_get_environment_type() === "development"'
  'https://uonix.ksio.dev'
  '/home2/uonix/public_html'
  'uonix.com.br'
)

falhas=0

reprova() {
  printf 'FAIL: %s\n' "$1" >&2
  falhas=$((falhas + 1))
}

# ---------------------------------------------------------------------------
# Fase 1: os padrões casam o que prometem, e só isso.
# ---------------------------------------------------------------------------
indice=0
while [ "$indice" -lt "${#TERMOS[@]}" ]; do
  termo="${TERMOS[$indice]}"
  exemplo="${EXEMPLOS[$indice]}"

  if ! printf '%s\n' "$exemplo" | grep -qiE -- "$termo"; then
    reprova "padrão <${termo}> não casa o próprio exemplo canônico <${exemplo}>; a varredura não provaria nada"
  fi

  negativo_indice=0
  while [ "$negativo_indice" -lt "${#NEGATIVOS[@]}" ]; do
    negativo="${NEGATIVOS[$negativo_indice]}"
    if printf '%s\n' "$negativo" | grep -qiE -- "$termo"; then
      reprova "padrão <${termo}> casa texto legítimo <${negativo}>; largo demais"
    fi
    negativo_indice=$((negativo_indice + 1))
  done

  indice=$((indice + 1))
done

# ---------------------------------------------------------------------------
# Fase 2: varredura dos arquivos versionados.
# ---------------------------------------------------------------------------
LISTA="$(mktemp "${TMPDIR:-/tmp}/uonix-retired-env-files.XXXXXX")"
ERROS="$(mktemp "${TMPDIR:-/tmp}/uonix-retired-env-erros.XXXXXX")"
trap 'rm -f "$LISTA" "$ERROS"' EXIT

git ls-files -z > "$LISTA"

indice=0
while [ "$indice" -lt "${#TERMOS[@]}" ]; do
  termo="${TERMOS[$indice]}"
  motivo="${MOTIVOS[$indice]}"

  # O stderr é CAPTURADO, não descartado: com 2>/dev/null, uma falha real de grep
  # ou xargs (arquivo rastreado ausente, limite de argumentos) chegaria aqui
  # indistinguível de "nada encontrado" — verde sem ter feito o trabalho.
  : > "$ERROS"
  achados="$(
    LC_ALL=C xargs -0 grep -niIE -- "$termo" /dev/null < "$LISTA" 2>"$ERROS" \
      | grep -v "^${SELF_REL}:" || true
  )"

  if [ -s "$ERROS" ]; then
    if ! grep -qvE '^grep: .+: No such file or directory$' "$ERROS"; then
      reprova 'há arquivo rastreado ausente na worktree; a varredura não cobre tudo. Use "git rm" (ou "git checkout --" para restaurar)'
    else
      reprova "a varredura do termo <${termo}> emitiu erro; o resultado não é confiável"
    fi
    sed 's/^/      /' "$ERROS" >&2
    indice=$((indice + 1))
    continue
  fi

  if [ -n "$achados" ]; then
    reprova "referência a ${motivo} reapareceu:"
    printf '%s\n' "$achados" >&2
  fi

  indice=$((indice + 1))
done

if [ "$falhas" -ne 0 ]; then
  exit 1
fi

printf 'PASS: padrões íntegros (%d termos, %d negativos) e nenhuma referência ao ambiente retirado.\n' \
  "${#TERMOS[@]}" "${#NEGATIVOS[@]}"
