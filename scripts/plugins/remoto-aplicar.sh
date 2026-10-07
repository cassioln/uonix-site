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
# Etapas: os itens que não são da camada crítica formam um lote; cada crítico é
# uma etapa sozinho. Depois de cada etapa, o site é conferido. Se uma etapa
# falhar, os itens DELA são restaurados; se o site seguir vermelho, todos os
# itens aplicados nesta execução são restaurados, em ordem reversa.
#
# Saída (também gravada em aplicar.status):
#   0   aplicado e site verde;
#   20  parou numa etapa, restaurou e o site ficou VERDE de novo;
#   30  preflight recusou, nada alterado;
#   50  restauração falhou ou o site seguiu vermelho depois de restaurar tudo:
#       INTERVENÇÃO HUMANA. Pastas em <backup>/plugin-<slug>.tar.gz; banco em
#       <backup>/db-prod-*.sql.gz (as migrações de banco não são desfeitas);
#   143 interrompido por sinal (a manutenção é desligada antes de sair).
# SIGKILL não pode ser tratado: o status fica ausente e o .maintenance pode
# ficar (o WordPress o ignora depois de 10 minutos); ver estado-producao.
#
# Para teste: UONIX_SMOKE_CMD substitui a checagem HTTP do site.
set -uo pipefail

DOCROOT="$1"
PHP="$2"
WPCLI="$3"
BK="$4"
LISTA="$5"
# Pela variável, e não por "$4": uma saída dentro de função (o trap de sinal)
# veria os argumentos DA FUNÇÃO em $4, e o status iria para o lugar errado.
trap 'echo $? > "$BK/aplicar.status"' EXIT
# A pasta existe antes de qualquer saída: sem ela o trap não grava o status, e
# o `estado-producao` não saberia como o job terminou.
mkdir -p "$BK" && chmod 700 "$BK" || exit 30
cd "$DOCROOT" || exit 30

# stdin fechado: os comandos rodam dentro de laços que leem a LISTA.
wp() { "$PHP" -d disable_functions= "$WPCLI" --path="$DOCROOT" "$@" </dev/null; }

em_manutencao=0
# shellcheck disable=SC2329  # chamada pelo trap abaixo
interrompido() {
  echo "INTERROMPIDO por sinal"
  [ "$em_manutencao" = 1 ] && wp maintenance-mode deactivate >/dev/null 2>&1
  exit 143
}
trap interrompido TERM INT HUP

checar_site() {
  if [ -n "${UONIX_SMOKE_CMD:-}" ]; then
    eval "$UONIX_SMOKE_CMD" && { echo "  site ok"; return 0; }
    echo "  FALHA no smoke"
    return 1
  fi
  local u code corpo
  corpo="$(mktemp)"
  for u in / /cotacao/ /produtos/ /sitemap_index.xml /wp-json/; do
    code=$(curl -s --max-time 60 -o "$corpo" -w '%{http_code}' "${UONIX_SITE_URL:-https://uonix.com.br}${u}?nocache=$RANDOM" </dev/null)
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
  local slug="$1" destino
  case "$slug" in ''|*/*|.|..) echo "  RESTAURAÇÃO RECUSADA: slug inválido '$slug'"; return 1 ;; esac
  [ -s "$BK/plugin-$slug.tar.gz" ] || { echo "  RESTAURAÇÃO FALHOU: sem backup de $slug"; return 1; }
  echo "  RESTAURANDO pasta de $slug"
  if ! mkdir -p "$BK/restaura-$slug" || ! tar -xzf "$BK/plugin-$slug.tar.gz" -C "$BK/restaura-$slug"; then
    echo "  RESTAURAÇÃO FALHOU: tar de $slug ilegível"
    return 1
  fi
  [ -d "$BK/restaura-$slug/$slug" ] || { echo "  RESTAURAÇÃO FALHOU: tar sem a pasta $slug"; return 1; }
  if [ -e "wp-content/plugins/$slug" ]; then
    destino="$BK/descartado-$slug"
    [ -e "$destino" ] && destino="$BK/descartado-$slug-$(date +%s)"
    mv "wp-content/plugins/$slug" "$destino" || { echo "  RESTAURAÇÃO FALHOU: não moveu a pasta atual de $slug"; return 1; }
  fi
  mv "$BK/restaura-$slug/$slug" "wp-content/plugins/$slug" \
    || { echo "  RESTAURAÇÃO FALHOU: não pôs a pasta de $slug no lugar"; return 1; }
}

# Restaura os slugs dados, na ordem dada. Devolve 1 se algum falhar.
restaurar_lista() {
  local ok=0 s
  for s in "$@"; do restaurar "$s" || ok=1; done
  return "$ok"
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

# Etapas: o lote (camadas não críticas) primeiro, depois um crítico por etapa.
etapas=()
lote="$(awk '$4 != "critica"' <<< "$LISTA")"
[ -n "$lote" ] && etapas+=("$lote")
while read -r linha; do etapas+=("$linha"); done < <(awk '$4 == "critica"' <<< "$LISTA")

aplicar_item() {  # aplicar_item slug para -> 0 ok
  local slug="$1" para="$2" instalada
  wp plugin update "$slug" --version="$para" || { echo "  update de $slug falhou"; return 1; }
  instalada=$(wp plugin get "$slug" --field=version 2>/dev/null)
  [ "$instalada" = "$para" ] || { echo "  versão instalada ${instalada:-nenhuma} != lock $para"; return 1; }
  if [ "$slug" = woocommerce ]; then
    wp wc update || { echo "  wp wc update falhou"; return 1; }
  fi
}

wp maintenance-mode activate && em_manutencao=1
aplicados=()   # todos os slugs tocados nesta execução, na ordem
falhou=""
for etapa in "${etapas[@]}"; do
  da_etapa=()
  while read -r slug de para camada; do
    echo "== $slug $de -> $para ($camada)"
    da_etapa+=("$slug")
    aplicados+=("$slug")
    aplicar_item "$slug" "$para" || { falhou="$slug (update)"; break; }
  done <<< "$etapa"
  if [ -z "$falhou" ]; then
    wp maintenance-mode deactivate >/dev/null && em_manutencao=0
    checar_site || falhou="etapa ${da_etapa[*]} (smoke)"
    [ -z "$falhou" ] && { wp maintenance-mode activate >/dev/null && em_manutencao=1; }
  fi
  [ -n "$falhou" ] && break
done

resultado=0
if [ -z "$falhou" ]; then
  wp maintenance-mode deactivate >/dev/null 2>&1; em_manutencao=0
  echo "== limpeza de cache"
  wp eval 'if ( function_exists( "wp_cache_clear_cache" ) ) { wp_cache_clear_cache(); echo "supercache limpo\n"; }'
  wp cache flush
  checar_site || { falhou="site depois da limpeza de cache"; da_etapa=(); }
fi

if [ -n "$falhou" ]; then
  echo "== FALHA: $falhou"
  wp maintenance-mode deactivate >/dev/null 2>&1; em_manutencao=0
  resultado=20
  if [ "${#da_etapa[@]}" -gt 0 ]; then
    echo "== restaurando a etapa que falhou: ${da_etapa[*]}"
    restaurar_lista "${da_etapa[@]}" || resultado=50
  fi
  # Só conta como "restaurado" se alguma etapa foi de fato restaurada: o
  # vermelho depois da limpeza de cache não tem etapa (da_etapa vazio) e vai
  # direto à cascata, senão sairia 20 com as versões novas no ar.
  if [ "$resultado" = 20 ] && [ "${#da_etapa[@]}" -gt 0 ] && checar_site; then
    echo "== site verde depois de restaurar a etapa"
  else
    reverso=()
    for ((n=${#aplicados[@]}-1; n>=0; n--)); do reverso+=("${aplicados[n]}"); done
    echo "== restaurando TUDO o que foi aplicado nesta execução: ${reverso[*]}"
    restaurar_lista "${reverso[@]}" || resultado=50
    wp cache flush >/dev/null 2>&1
    if checar_site; then
      [ "$resultado" = 50 ] || resultado=20
    else
      resultado=50
    fi
  fi
fi

echo "== versões finais"
while read -r slug _; do printf '  %s %s\n' "$slug" "$(wp plugin get "$slug" --field=version 2>/dev/null)"; done <<< "$LISTA"
case "$resultado" in
  0) echo "CONCLUÍDO (backup em $BK)" ;;
  20) echo "PARADO EM: $falhou; restaurado e site verde (backup em $BK)" ;;
  *) echo "INTERVENÇÃO NECESSÁRIA: $falhou; restauração incompleta ou site vermelho (backup em $BK)" ;;
esac
exit "$resultado"
