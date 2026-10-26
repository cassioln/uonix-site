#!/usr/bin/env bash
# Job que roda NO SERVIDOR de produção para aplicar um lock de plugins e temas (#393, #445).
#
# Enviado pelo `atualizar.sh aplicar-producao` e executado sob nohup, desacoplado
# da conexão SSH: se ela cair, o job termina sozinho e o resultado fica em
# <backup>/aplicar.log e <backup>/aplicar.status. Em 2026-10-07, um job ligado
# à conexão morreu no meio e deixou o site em manutenção.
#
# Uso: remoto-aplicar.sh DOCROOT PHP WPCLI BACKUP_DIR LISTA
#   LISTA: uma linha por item, "slug de para camada tipo", na ordem do lock. O
#          tipo é "plugin", "tema" ou "nucleo" (o slug do núcleo é "wordpress");
#          sem ele, vale "plugin".
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
#       INTERVENÇÃO HUMANA. Pastas em <backup>/plugin-<slug>.tar.gz (ou
#       tema-<slug>.tar.gz; o núcleo, em nucleo-wordpress.tar.gz); banco em
#       <backup>/db-prod-*.sql.gz (as migrações de banco não são desfeitas);
#   143 interrompido por sinal (a manutenção é desligada antes de sair).
# SIGKILL não pode ser tratado: o status fica ausente e o .maintenance pode
# ficar (o WordPress o ignora depois de 10 minutos); ver estado-producao.
#
# Para teste: UONIX_SMOKE_CMD substitui a checagem HTTP do site; TAR_ESPERA e
# TAR_TENTATIVAS ajustam a repetição do tar de backup.
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

# Tipo do item (campo 5 da LISTA; ausente = plugin). Daí saem o comando do
# WP-CLI, a pasta e o prefixo do tar de backup (#445).
tipo_de() {
  awk -v s="$1" '$1 == s { print ($5 == "tema" || $5 == "nucleo" ? $5 : "plugin"); exit }' <<< "$LISTA"
}

# Núcleo do WordPress (#448). Não é uma pasta: são wp-admin/, wp-includes/ e os arquivos do
# núcleo na raiz. wp-config.php, .htaccess e wp-content/ (temas, plugins, uploads) ficam de
# fora do backup e da restauração.
NUCLEO_RAIZ=(index.php license.txt readme.html wp-activate.php wp-blog-header.php wp-comments-post.php
  wp-config-sample.php wp-cron.php wp-links-opml.php wp-load.php wp-login.php wp-mail.php
  wp-settings.php wp-signup.php wp-trackback.php xmlrpc.php)
itens_nucleo() {
  local f
  printf '%s\n' wp-admin wp-includes
  for f in "${NUCLEO_RAIZ[@]}"; do [ -e "$f" ] && printf '%s\n' "$f"; done
  return 0
}

# Versão instalada e atualização de um item, seja plugin, tema ou núcleo.
versao_item() {
  if [ "$(tipo_de "$1")" = nucleo ]; then wp core version 2>/dev/null; else wp_item "$1" get --field=version 2>/dev/null; fi
}
atualizar_nucleo() {  # atualizar_nucleo <versão>: arquivos, depois banco, depois checksums
  local para="$1" loc
  loc="$(wp eval 'echo get_locale();' 2>/dev/null)"
  wp core update --version="$para" --locale="${loc:-en_US}" --force || return 1
  wp core update-db || return 1
  wp core verify-checksums || return 1
}
pasta_de() { [ "$(tipo_de "$1")" = tema ] && echo "wp-content/themes/$1" || echo "wp-content/plugins/$1"; }
tar_de() { echo "$BK/$(tipo_de "$1")-$1.tar.gz"; }
wp_item() {  # wp_item <slug> <subcomando> [args...]: wp plugin|theme <subcomando> <slug> ...
  local slug="$1" sub="$2"; shift 2
  if [ "$(tipo_de "$slug")" = tema ]; then wp theme "$sub" "$slug" "$@"; else wp plugin "$sub" "$slug" "$@"; fi
}

# Backup de uma pasta. O GNU tar sai com 1 quando um arquivo muda durante a leitura
# ("file changed as we read it"): o site vivo escreve em cache e em metadados enquanto
# o tar lê, e isso aborta o ciclo à toa (#447). Saída 1 repete, até TAR_TENTATIVAS vezes,
# com TAR_ESPERA segundos entre elas; 2 ou mais é erro de verdade e para na hora.
# Um tar que terminou em 0 ainda precisa ser legível: um backup ilegível só apareceria
# na restauração, que é tarde demais.
salvar_pasta() {
  local slug="$1" destino tentativa=1 rc=0 max="${TAR_TENTATIVAS:-3}" f
  destino="$(tar_de "$slug")"
  local base conteudo=()
  if [ "$(tipo_de "$slug")" = nucleo ]; then
    base="$DOCROOT"
    while IFS= read -r f; do conteudo+=("$f"); done < <(itens_nucleo)
  else
    base="$(dirname "$(pasta_de "$slug")")"; conteudo=("$slug")
  fi
  while :; do
    # stdin fechado, como no wp(): o laço que chama esta função lê a LISTA.
    tar -czf "$destino" -C "$base" "${conteudo[@]}" </dev/null; rc=$?
    if [ "$rc" = 0 ]; then
      # Só o stdout vai para o lixo: o motivo de um arquivo ilegível fica no log do job.
      tar -tzf "$destino" </dev/null >/dev/null && return 0
      echo "  tar de $slug ficou ilegível"; return 1
    fi
    if [ "$rc" != 1 ]; then echo "  tar de $slug saiu $rc: erro, sem repetir"; return 1; fi
    if [ "$tentativa" -ge "$max" ]; then echo "  tar de $slug: arquivo mudou durante a leitura em todas as $max tentativas"; return 1; fi
    echo "  tar de $slug: arquivo mudou durante a leitura (tentativa $tentativa de $max); repetindo em ${TAR_ESPERA:-5}s"
    tentativa=$((tentativa + 1))
    sleep "${TAR_ESPERA:-5}"
  done
}

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
# "wp-content/plugins/" sozinho seria a pasta de TODOS os plugins (ou de todos
# os temas).
restaurar() {
  local slug="$1" destino tar pasta
  tar="$(tar_de "$slug")"; pasta="$(pasta_de "$slug")"
  case "$slug" in ''|*/*|.|..) echo "  RESTAURAÇÃO RECUSADA: slug inválido '$slug'"; return 1 ;; esac
  if [ "$(tipo_de "$slug")" = nucleo ]; then restaurar_nucleo "$slug"; return; fi
  [ -s "$tar" ] || { echo "  RESTAURAÇÃO FALHOU: sem backup de $slug"; return 1; }
  echo "  RESTAURANDO pasta de $slug"
  if ! mkdir -p "$BK/restaura-$slug" || ! tar -xzf "$tar" -C "$BK/restaura-$slug"; then
    echo "  RESTAURAÇÃO FALHOU: tar de $slug ilegível"
    return 1
  fi
  [ -d "$BK/restaura-$slug/$slug" ] || { echo "  RESTAURAÇÃO FALHOU: tar sem a pasta $slug"; return 1; }
  if [ -e "$pasta" ]; then
    destino="$BK/descartado-$slug"
    [ -e "$destino" ] && destino="$BK/descartado-$slug-$(date +%s)"
    mv "$pasta" "$destino" || { echo "  RESTAURAÇÃO FALHOU: não moveu a pasta atual de $slug"; return 1; }
  fi
  mv "$BK/restaura-$slug/$slug" "$pasta" \
    || { echo "  RESTAURAÇÃO FALHOU: não pôs a pasta de $slug no lugar"; return 1; }
}

# Núcleo: devolve wp-admin, wp-includes e os arquivos da raiz que estavam no tar. O que
# existe agora vai inteiro para descartado-wordpress, e nada fora dessa lista é tocado.
restaurar_nucleo() {
  local slug="$1" tar destino f
  tar="$(tar_de "$slug")"
  [ -s "$tar" ] || { echo "  RESTAURAÇÃO FALHOU: sem backup do núcleo"; return 1; }
  echo "  RESTAURANDO o núcleo do WordPress"
  if ! mkdir -p "$BK/restaura-$slug" || ! tar -xzf "$tar" -C "$BK/restaura-$slug"; then
    echo "  RESTAURAÇÃO FALHOU: tar do núcleo ilegível"; return 1
  fi
  [ -d "$BK/restaura-$slug/wp-admin" ] && [ -d "$BK/restaura-$slug/wp-includes" ] \
    || { echo "  RESTAURAÇÃO FALHOU: tar do núcleo sem wp-admin ou wp-includes"; return 1; }
  destino="$BK/descartado-$slug"
  [ -e "$destino" ] && destino="$BK/descartado-$slug-$(date +%s)"
  mkdir -p "$destino" || { echo "  RESTAURAÇÃO FALHOU: sem pasta de descarte"; return 1; }
  for f in "$BK/restaura-$slug"/* "$BK/restaura-$slug"/.[!.]*; do
    [ -e "$f" ] || continue
    f="$(basename "$f")"
    if [ -e "$f" ]; then
      mv "$f" "$destino/$f" || { echo "  RESTAURAÇÃO FALHOU: não moveu $f"; return 1; }
    fi
    mv "$BK/restaura-$slug/$f" "$f" || { echo "  RESTAURAÇÃO FALHOU: não pôs $f no lugar"; return 1; }
  done
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
while read -r slug de para camada _; do
  case "$slug" in ''|*/*|.|..) echo "  slug inválido na lista: '$slug'"; drift=1; continue ;; esac
  atual=$(versao_item "$slug")
  [ "$atual" = "$de" ] || { echo "  DRIFT $slug: produção=${atual:-ausente} lock=$de"; drift=1; }
done <<< "$LISTA"
[ "$drift" = 0 ] || { echo "ABORTADO: produção divergiu do ensaio; nada foi alterado"; exit 30; }
echo "  sem drift"
checar_site || { echo "ABORTADO: site já falhava antes; nada foi alterado"; exit 30; }

echo "== backup das pastas de plugin, tema e núcleo em $BK"
while read -r slug _; do
  salvar_pasta "$slug" || { echo "ABORTADO: tar de $slug falhou; nada foi alterado"; exit 30; }
done <<< "$LISTA"
echo "  pastas salvas: $(find "$BK" -maxdepth 1 \( -name 'plugin-*.tar.gz' -o -name 'tema-*.tar.gz' -o -name 'nucleo-*.tar.gz' \) | wc -l | tr -d ' ')"

# Etapas: o lote (camadas não críticas) primeiro, depois um crítico por etapa.
etapas=()
lote="$(awk '$4 != "critica"' <<< "$LISTA")"
[ -n "$lote" ] && etapas+=("$lote")
while read -r linha; do etapas+=("$linha"); done < <(awk '$4 == "critica"' <<< "$LISTA")

limpar_cache() {
  wp eval 'if ( function_exists( "wp_cache_clear_cache" ) ) { wp_cache_clear_cache(); echo "supercache limpo\n"; }'
  wp cache flush
}

aplicar_item() {  # aplicar_item slug para -> 0 ok
  local slug="$1" para="$2" instalada
  if [ "$(tipo_de "$slug")" = nucleo ]; then
    atualizar_nucleo "$para" || { echo "  update do núcleo falhou"; return 1; }
  else
    wp_item "$slug" update --version="$para" || { echo "  update de $slug falhou"; return 1; }
  fi
  instalada=$(versao_item "$slug")
  [ "$instalada" = "$para" ] || { echo "  versão instalada ${instalada:-nenhuma} != lock $para"; return 1; }
  if [ "$slug" = woocommerce ]; then
    wp wc update || { echo "  wp wc update falhou"; return 1; }
  fi
}

# Sem manutenção, os updates rodariam com o site aberto ao público: aborta
# antes de qualquer update (#421).
if ! wp maintenance-mode activate; then
  echo "ABORTADO: não foi possível ativar o modo de manutenção; nada foi alterado"
  exit 30
fi
em_manutencao=1
aplicados=()   # todos os slugs tocados nesta execução, na ordem
falhou=""
for etapa in "${etapas[@]}"; do
  da_etapa=()
  while read -r slug de para camada _; do
    echo "== $slug $de -> $para ($camada)"
    da_etapa+=("$slug")
    aplicados+=("$slug")
    aplicar_item "$slug" "$para" || { falhou="$slug (update)"; break; }
  done <<< "$etapa"
  if [ -z "$falhou" ]; then
    wp maintenance-mode deactivate >/dev/null && em_manutencao=0
    checar_site || falhou="etapa ${da_etapa[*]} (smoke)"
    if [ -z "$falhou" ]; then
      # Entre etapas já há plugins aplicados: parar seria pior do que seguir
      # sem a página de manutenção. Fica registrado.
      if wp maintenance-mode activate >/dev/null; then em_manutencao=1; else echo "  AVISO: manutenção não reativou; seguindo"; fi
    fi
  fi
  [ -n "$falhou" ] && break
done

resultado=0
if [ -z "$falhou" ]; then
  wp maintenance-mode deactivate >/dev/null 2>&1; em_manutencao=0
  echo "== limpeza de cache"
  limpar_cache
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
    # Mesma limpeza da etapa final: sem ela, o WP Super Cache seguiria servindo
    # páginas geradas durante a falha (#421).
    limpar_cache >/dev/null 2>&1
    if checar_site; then
      [ "$resultado" = 50 ] || resultado=20
    else
      resultado=50
    fi
  fi
fi

echo "== versões finais"
while read -r slug _; do printf '  %s %s\n' "$slug" "$(versao_item "$slug")"; done <<< "$LISTA"
case "$resultado" in
  0) echo "CONCLUÍDO (backup em $BK)" ;;
  20) echo "PARADO EM: $falhou; restaurado e site verde (backup em $BK)" ;;
  *) echo "INTERVENÇÃO NECESSÁRIA: $falhou; restauração incompleta ou site vermelho (backup em $BK)" ;;
esac
exit "$resultado"
