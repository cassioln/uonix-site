#!/usr/bin/env bash
# Garante que opções cujo valor é um CAMINHO DE DISCO ABSOLUTO sejam preservadas
# ao clonar o banco entre ambientes.
#
# MOTIVAÇÃO (bug real, corrigido no T59C em 2026-07-31): a opção
# `downloaded_font_files` (Kadence, fontes auto-hospedadas) guarda o caminho de
# disco do ambiente, não uma URL:
#
#   localhost         /var/www/html/wp-content//fonts/...
#   site.uonix.com.br /home/storage/f/34/12/siteuonix1/public_html/wp-content//fonts/...
#   QA e DEV          /home2/uonix/{public_html,dev_uonix}/wp-content//fonts/...
#
# O Kadence converte esse caminho em URL com
# str_replace( wp_content_dir(), content_url(), $path ). Se o valor vier de OUTRO
# ambiente, o str_replace não casa, o caminho cru vai para o HTML, a fonte Barlow
# dá 404 e o texto cai em fallback sans-serif (botões quebram de linha).
#
# Como o clone copia wp_options da origem, clonar SEM proteger essa opção
# reintroduz o defeito no destino. Foi assim que ele se espalhou originalmente.

set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLONE="$ROOT_DIR/scripts/clone-environment.sh"

[ -f "$CLONE" ] || {
  echo "FALHA: scripts/clone-environment.sh não encontrado." >&2
  exit 1
}

failures=0

report() {
  printf '  FALHA %s\n' "$1" >&2
  failures=$((failures + 1))
}

# Extrai o predicado SQL de opções protegidas do próprio script, sem executá-lo.
protected_sql="$(
  awk '/^protected_options_where\(\) \{/{flag=1; next} /^SQL$/{flag=0} flag' "$CLONE" |
    grep -v "^  cat <<'SQL'$"
)"

[ -n "$protected_sql" ] || {
  echo 'FALHA: não foi possível extrair protected_options_where() do script.' >&2
  exit 1
}

# Opções que guardam caminho de disco e PRECISAM ser preservadas no destino.
# Ao adicionar uma nova opção dessa natureza, inclua-a aqui.
path_bound_options=(
  'downloaded_font_files'
)

for option in "${path_bound_options[@]}"; do
  if ! printf '%s' "$protected_sql" | grep -q "$option"; then
    report "opção '$option' guarda caminho de disco e NÃO está em protected_options_where(); clonar propagaria o caminho da origem."
  fi
done

# O predicado precisa continuar sendo SQL utilizável: sem linha vazia inicial e
# com pelo menos uma cláusula.
if ! printf '%s' "$protected_sql" | grep -qE '^option_name '; then
  report 'protected_options_where() não começa com uma cláusula option_name; o SQL montado ficaria inválido.'
fi

# A função é consumida em pelo menos um ponto do fluxo de clone.
if [ "$(grep -c 'protected_options_where' "$CLONE")" -lt 2 ]; then
  report 'protected_options_where() é definida mas não é consumida no script.'
fi

if [ "$failures" -ne 0 ]; then
  printf 'FALHA: %s problema(s) na proteção de opções com caminho de disco.\n' "$failures" >&2
  exit 1
fi

printf 'PASS: %s opção(ões) com caminho de disco protegida(s) no clone.\n' "${#path_bound_options[@]}"
