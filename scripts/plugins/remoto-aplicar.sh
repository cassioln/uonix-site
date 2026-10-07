#!/usr/bin/env bash
# Job que roda NO SERVIDOR de produção para aplicar um lock de plugins (#393).
#
# Enviado pelo `atualizar.sh aplicar-producao` e executado sob nohup, desacoplado
# da conexão SSH: se ela cair, o job termina sozinho e o resultado fica em
# <backup>/aplicar.log e <backup>/aplicar.status. Em 2026-10-07, um job ligado
# à conexão morreu no meio e deixou o site em manutenção.
#
# Uso: remoto-aplicar.sh DOCROOT PHP WPCLI BACKUP_DIR LISTA
#   LISTA: uma linha por item, "slug de para camada", na ordem do lock.
#
# Saída (também gravada em aplicar.status): 0 aplicado; 20 parou numa etapa e
# restaurou a pasta do plugin que falhou; 30 preflight recusou, nada alterado.
#
# Para teste: UONIX_SMOKE_CMD substitui a checagem HTTP do site.
trap 'echo $? > "$4/aplicar.status"' EXIT
set -uo pipefail

DOCROOT="$1"
PHP="$2"
WPCLI="$3"
BK="$4"
LISTA="$5"
# A pasta existe antes de qualquer saída: sem ela o trap não grava o status, e
# o `estado-producao` não saberia como o job terminou.
mkdir -p "$BK" && chmod 700 "$BK" || exit 30
cd "$DOCROOT" || exit 30

wp() { "$PHP" -d disable_functions= "$WPCLI" --path="$DOCROOT" "$@"; }

checar_site() {
  if [ -n "${UONIX_SMOKE_CMD:-}" ]; then
    eval "$UONIX_SMOKE_CMD" && { echo "  site ok"; return 0; }
    echo "  FALHA no smoke"
    return 1
  fi
  local u code corpo
  corpo="$(mktemp)"
  for u in / /cotacao/ /produtos/ /sitemap_index.xml /wp-json/; do
    code=$(curl -s -o "$corpo" -w '%{http_code}' "${UONIX_SITE_URL:-https://uonix.com.br}${u}?nocache=$RANDOM")
    if [ "$code" != 200 ] || grep -qiE 'critical error|Fatal error' "$corpo"; then
      echo "  FALHA em $u (http $code)"
      return 1
    fi
  done
  echo "  site ok"
}

# Restaura sem apagar nada: a pasta atual vai para dentro do backup, e a do tar
# é extraída à parte e movida para o lugar. Slug vazio é recusado, porque
# "wp-content/plugins/" sozinho seria a pasta de TODOS os plugins.
restaurar() {
  local slug="$1"
  case "$slug" in ''|*/*|.|..) echo "  RESTAURAÇÃO RECUSADA: slug inválido '$slug'"; return 1 ;; esac
  [ -s "$BK/plugin-$slug.tar.gz" ] || { echo "  RESTAURAÇÃO RECUSADA: sem backup de $slug"; return 1; }
  echo "  RESTAURANDO pasta de $slug"
  mkdir -p "$BK/restaura-$slug" && tar -xzf "$BK/plugin-$slug.tar.gz" -C "$BK/restaura-$slug" || return 1
  [ -d "$BK/restaura-$slug/$slug" ] || { echo "  RESTAURAÇÃO RECUSADA: tar sem a pasta $slug"; return 1; }
  if [ -e "wp-content/plugins/$slug" ]; then
    mv "wp-content/plugins/$slug" "$BK/descartado-$slug" || return 1
  fi
  mv "$BK/restaura-$slug/$slug" "wp-content/plugins/$slug"
}

[ -n "$LISTA" ] || { echo "ABORTADO: lista vazia"; exit 30; }

echo "== preflight: versões de origem"
drift=0
while read -r slug de para camada; do
  case "$slug" in ''|*/*|.|..) echo "  slug inválido na lista: '$slug'"; drift=1; continue ;; esac
  atual=$(wp plugin get "$slug" --field=version 2>/dev/null)
  [ "$atual" = "$de" ] || { echo "  DRIFT $slug: produção=${atual:-ausente} lock=$de"; drift=1; }
done <<< "$LISTA"
[ "$drift" = 0 ] || { echo "ABORTADO: produção divergiu do ensaio; nada foi alterado"; exit 30; }
echo "  sem drift"
checar_site || { echo "ABORTADO: site já falhava antes; nada foi alterado"; exit 30; }

echo "== backup das pastas de plugin em $BK"
while read -r slug _; do
  tar -czf "$BK/plugin-$slug.tar.gz" -C wp-content/plugins "$slug" \
    || { echo "ABORTADO: tar de $slug falhou; nada foi alterado"; exit 30; }
done <<< "$LISTA"
echo "  pastas salvas: $(find "$BK" -maxdepth 1 -name 'plugin-*.tar.gz' | wc -l | tr -d ' ')"

wp maintenance-mode activate
falhou=""
while read -r slug de para camada; do
  echo "== $slug $de -> $para ($camada)"
  if ! wp plugin update "$slug" --version="$para"; then
    restaurar "$slug"; falhou="$slug (update)"; break
  fi
  instalada=$(wp plugin get "$slug" --field=version 2>/dev/null)
  if [ "$instalada" != "$para" ]; then
    echo "  versão instalada ${instalada:-nenhuma} != lock $para"
    restaurar "$slug"; falhou="$slug (versão)"; break
  fi
  [ "$slug" = woocommerce ] && wp wc update
  if [ "$camada" = critica ]; then
    wp maintenance-mode deactivate >/dev/null
    if ! checar_site; then restaurar "$slug"; falhou="$slug (smoke)"; break; fi
    wp maintenance-mode activate >/dev/null
  fi
done <<< "$LISTA"
wp maintenance-mode deactivate >/dev/null 2>&1 || true

echo "== limpeza de cache"
wp eval 'if ( function_exists( "wp_cache_clear_cache" ) ) { wp_cache_clear_cache(); echo "supercache limpo\n"; }'
wp cache flush
checar_site || falhou="${falhou:-site depois da limpeza de cache}"

echo "== versões finais"
while read -r slug _; do printf '  %s %s\n' "$slug" "$(wp plugin get "$slug" --field=version 2>/dev/null)"; done <<< "$LISTA"
if [ -n "$falhou" ]; then
  echo "PARADO EM: $falhou (backup em $BK)"
  exit 20
fi
echo "CONCLUÍDO (backup em $BK)"
exit 0
