#!/usr/bin/env bash
# Job remoto de aplicação de plugins (#393, entrega 3), sem servidor: um `php`
# falso faz o papel do WP-CLI (versão de cada plugin num arquivo VERSION); tar,
# mv e o trap de status são reais.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
JOB="$ROOT_DIR/scripts/plugins/remoto-aplicar.sh"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT HUP INT TERM

fail() {
  printf 'FAIL: %s\n' "$*" >&2
  exit 1
}

# php falso: "php -d disable_functions= WPCLI --path=DOC <comando wp...>"
cat > "$TMP_DIR/php" <<'PHP'
#!/usr/bin/env bash
shift 3
doc="${1#--path=}"; shift
p="$doc/wp-content/plugins"
[ "$1" = theme ] && p="$doc/wp-content/themes"
case "${1/theme/plugin} $2" in
  "plugin get")
    # Ruído: depois da atualização (o 3º "get" do mesmo plugin, o das versões finais), o PHP imprime um Warning antes da versão.
    n=$(( $(cat "$doc/.gets-$3" 2>/dev/null || echo 0) + 1 )); printf '%s\n' "$n" > "$doc/.gets-$3"
    [ "${FAKE_RUIDO_VERSAO:-}" = "$3" ] && [ "$n" -ge 3 ] && printf 'Warning: Undefined variable $x in /x.php on line 1\n'
    [ -f "$p/$3/VERSION" ] && cat "$p/$3/VERSION" || exit 1 ;;
  "plugin update")
    [ "${FAKE_FALHA_UPDATE:-}" = "$3" ] && exit 1
    [ "${FAKE_DORME:-}" = "$3" ] && sleep 6
    [ "${FAKE_QUEBRA_PERMANENTE:-}" = "$3" ] && : > "$doc/QUEBRADO"
    v="${4#--version=}"
    [ "${FAKE_VERSAO_ERRADA:-}" = "$3" ] && v="$v.9"
    printf '%s\n' "$v" > "$p/$3/VERSION" ;;
  "maintenance-mode activate")
    [ -n "${FAKE_FALHA_MANUTENCAO:-}" ] && exit 1
    # A N-ésima ativação em diante falha (a 1ª é a inicial; a 2ª, a reativação entre etapas).
    n=$(( $(cat "$doc/.ativacoes" 2>/dev/null || echo 0) + 1 )); printf '%s\n' "$n" > "$doc/.ativacoes"
    [ -n "${FAKE_FALHA_MANUTENCAO_A_PARTIR_DE:-}" ] && [ "$n" -ge "$FAKE_FALHA_MANUTENCAO_A_PARTIR_DE" ] && exit 1
    : > "$doc/.maintenance" ;;
  "maintenance-mode deactivate") [ -f "$doc/.maintenance" ] && mv "$doc/.maintenance" "$doc/.maintenance-desligado" ; true ;;
  "wc update") [ -n "${FAKE_FALHA_WC:-}" ] && exit 1 ; true ;;
  "core version") cat "$doc/wp-includes/version.txt" ;;
  "core update")
    printf '%s\n' "$*" >> "$doc/.core-update-args"
    [ -n "${FAKE_FALHA_NUCLEO:-}" ] && exit 1
    v="${3#--version=}"
    printf '%s\n' "$v" > "$doc/wp-includes/version.txt"
    printf 'novo %s\n' "$v" > "$doc/index.php"; : > "$doc/wp-admin/arquivo-novo.php"
    printf 'idioma novo %s\n' "$v" > "$doc/wp-content/languages/pt_BR.po"
    # o WordPress apaga o .maintenance ao fim do core update (medido no container)
    [ -z "${FAKE_NAO_APAGA_MANUTENCAO:-}" ] && rm -f "$doc/.maintenance"
    printf 'idioma de plugin novo\n' > "$doc/wp-content/languages/plugins/a-pt_BR.po"
    [ -n "${FAKE_NUCLEO_QUEBRA:-}" ] && : > "$doc/QUEBRADO" ; true ;;
  "core update-db")
    [ -e "$doc/.maintenance" ] && echo "manutencao-ativa-no-update-db" >> "$doc/.updatedb-log" || echo "manutencao-DESLIGADA-no-update-db" >> "$doc/.updatedb-log"
    [ -n "${FAKE_FALHA_NUCLEO_DB:-}" ] && exit 1 ; true ;;
  "core verify-checksums") [ -n "${FAKE_FALHA_CHECKSUMS:-}" ] && exit 1 ; true ;;
  eval*) printf '%s\n' "$2" >> "$doc/.eval-log"; case "$2" in *get_locale*) printf 'pt_BR' ;; esac ;;
  *) : ;;
esac
PHP
chmod +x "$TMP_DIR/php"

preparar() {
  doc="$TMP_DIR/doc-$1"; bk="$TMP_DIR/bk-$1"
  mkdir -p "$doc/wp-content/plugins/a" "$doc/wp-content/plugins/b" "$doc/wp-content/plugins/c" "$doc/wp-content/plugins/woocommerce"
  printf '1.0\n' > "$doc/wp-content/plugins/woocommerce/VERSION"
  printf '1.0\n' > "$doc/wp-content/plugins/a/VERSION"
  printf '1.0\n' > "$doc/wp-content/plugins/b/VERSION"
  printf '1.0\n' > "$doc/wp-content/plugins/c/VERSION"
  printf 'original\n' > "$doc/wp-content/plugins/b/codigo.php"
  mkdir -p "$doc/wp-admin" "$doc/wp-includes" "$doc/wp-content/languages/plugins"
  printf 'idioma original\n' > "$doc/wp-content/languages/pt_BR.po"; printf 'idioma de plugin original\n' > "$doc/wp-content/languages/plugins/a-pt_BR.po"
  printf '7.1.3\n' > "$doc/wp-includes/version.txt"; printf 'admin\n' > "$doc/wp-admin/admin.php"
  printf 'index original\n' > "$doc/index.php"; printf 'segredo\n' > "$doc/wp-config.php"; printf 'regras\n' > "$doc/.htaccess"
  mkdir -p "$doc/wp-content/themes/t"
  printf '1.0\n' > "$doc/wp-content/themes/t/VERSION"
  printf 'tema-original\n' > "$doc/wp-content/themes/t/functions.php"
}

rodar() {  # rodar <lista> [env...]
  local lista="$1"; shift
  set +e
  env "$@" bash "$JOB" "$doc" "$TMP_DIR/php" /fake/wp-cli.phar "$bk" "$lista" > "$bk.log" 2>&1
  rc=$?
  set -e
}

versao() { cat "$doc/wp-content/plugins/$1/VERSION"; }
versao_tema() { cat "$doc/wp-content/themes/$1/VERSION"; }
LISTA=$'a 1.0 1.1 acoplada\nb 1.0 2.0 critica'

# 1. verde
preparar verde; rodar "$LISTA" UONIX_SMOKE_CMD=true
[ "$rc" = 0 ] || fail "verde saiu $rc: $(cat "$bk.log")"
[ "$(versao a)" = 1.1 ] && [ "$(versao b)" = 2.0 ] || fail "verde: versões não aplicadas"
[ "$(cat "$bk/aplicar.status")" = 0 ] || fail "verde: aplicar.status não é 0"
[ ! -e "$doc/.maintenance" ] || fail "verde: site ficou em manutenção"
[ "$(versao c)" = 1.0 ] || fail "verde: plugin fora do lock foi tocado"

# 2. drift: nada alterado, nada em manutenção, status 30
preparar drift; rodar $'a 0.9 1.1 acoplada\nb 1.0 2.0 critica' UONIX_SMOKE_CMD=true
[ "$rc" = 30 ] && [ "$(cat "$bk/aplicar.status")" = 30 ] || fail "drift saiu $rc"
[ "$(versao a)" = 1.0 ] && [ "$(versao b)" = 1.0 ] || fail "drift: algo foi alterado"
[ ! -e "$doc/.maintenance" ] && [ ! -e "$doc/.maintenance-desligado" ] || fail "drift: entrou em manutenção"
grep -q 'DRIFT a: produção=1.0 lock=0.9' "$bk.log" || fail "drift: relatório sem o item divergente"

# 3. smoke vermelho depois do crítico b: b restaurado, original preservado no backup
preparar smoke
printf 'quebrado\n' > "$TMP_DIR/novo-b"
rodar "$LISTA" UONIX_SMOKE_CMD="! grep -qx 2.0 $doc/wp-content/plugins/b/VERSION"
[ "$rc" = 20 ] && [ "$(cat "$bk/aplicar.status")" = 20 ] || fail "smoke vermelho saiu $rc: $(cat "$bk.log")"
[ "$(versao b)" = 1.0 ] && [ "$(cat "$doc/wp-content/plugins/b/codigo.php")" = original ] || fail "smoke: b não foi restaurado"
[ "$(cat "$bk/descartado-b/VERSION")" = 2.0 ] || fail "smoke: pasta quebrada não foi guardada em descartado-b"
[ "$(versao a)" = 1.1 ] || fail "smoke: a, aplicado antes, deveria continuar 1.1"
[ ! -e "$doc/.maintenance" ] || fail "smoke: site ficou em manutenção"
grep -q 'PARADO EM: etapa b (smoke); restaurado e site verde' "$bk.log" || fail "smoke: relatório sem o ponto de parada"

# 4. versão instalada diferente do lock
preparar versao; rodar "$LISTA" UONIX_SMOKE_CMD=true FAKE_VERSAO_ERRADA=a
[ "$rc" = 20 ] && [ "$(versao a)" = 1.0 ] && [ "$(versao b)" = 1.0 ] || fail "versão divergente saiu $rc (a=$(versao a) b=$(versao b))"
grep -q 'versão instalada 1.1.9 != lock 1.1' "$bk.log" || fail "versão: relatório sem a divergência"
[ ! -e "$doc/.maintenance" ] || fail "versão divergente: site ficou em manutenção"

# 5. update falhando
preparar update; rodar "$LISTA" UONIX_SMOKE_CMD=true FAKE_FALHA_UPDATE=b
[ "$rc" = 20 ] && [ "$(versao b)" = 1.0 ] || fail "update falho saiu $rc"
[ ! -e "$doc/.maintenance" ] || fail "update falho: site ficou em manutenção"

# 6. lista vazia e slug inválido: 30 sem tocar em nada
preparar vazia; rodar "" UONIX_SMOKE_CMD=true
[ "$rc" = 30 ] || fail "lista vazia saiu $rc"
preparar barra; rodar $'../a 1.0 1.1 comum' UONIX_SMOKE_CMD=true
[ "$rc" = 30 ] && [ "$(versao a)" = 1.0 ] || fail "slug com barra saiu $rc"

# 7. site já falhando antes: 30, nada alterado
preparar antes; rodar "$LISTA" UONIX_SMOKE_CMD=false
[ "$rc" = 30 ] && [ "$(versao a)" = 1.0 ] || fail "site falhando antes saiu $rc"

# 8. culpado no LOTE: o lote reprova no smoke dele; só o lote é restaurado; b nunca é tocado
preparar lote; rodar "$LISTA" UONIX_SMOKE_CMD="! grep -qx 1.1 $doc/wp-content/plugins/a/VERSION"
[ "$rc" = 20 ] && [ "$(versao a)" = 1.0 ] && [ "$(versao b)" = 1.0 ] || fail "culpado no lote saiu $rc (a=$(versao a) b=$(versao b)): $(cat "$bk.log")"
grep -q 'PARADO EM: etapa a (smoke)' "$bk.log" || fail "lote: relatório deveria apontar a etapa do lote"

# 9. restauração falhando (tar corrompido) -> 50, nunca 20
preparar tarruim
rodar "$LISTA" UONIX_SMOKE_CMD="if grep -qx 2.0 $doc/wp-content/plugins/b/VERSION; then : > $bk/plugin-b.tar.gz; false; else true; fi"
[ "$rc" = 50 ] && [ "$(cat "$bk/aplicar.status")" = 50 ] || fail "restauração falha deveria sair 50 (saiu $rc): $(cat "$bk.log")"
grep -q 'INTERVENÇÃO NECESSÁRIA' "$bk.log" || fail "50 sem o aviso de intervenção"
[ ! -e "$doc/.maintenance" ] || fail "50: site ficou em manutenção"

# 10. site segue vermelho mesmo depois de restaurar tudo -> 50
preparar permanente
rodar "$LISTA" UONIX_SMOKE_CMD="[ ! -e $doc/QUEBRADO ]" FAKE_QUEBRA_PERMANENTE=b
[ "$rc" = 50 ] && [ "$(versao a)" = 1.0 ] && [ "$(versao b)" = 1.0 ] || fail "vermelho persistente deveria sair 50 com tudo restaurado (saiu $rc, a=$(versao a) b=$(versao b))"
grep -q 'restaurando TUDO' "$bk.log" || fail "vermelho persistente deveria restaurar tudo o que foi aplicado"

# 11. wp wc update falhando -> restaura o woocommerce, 20
preparar wc; rodar $'woocommerce 1.0 1.1 critica' UONIX_SMOKE_CMD=true FAKE_FALHA_WC=1
[ "$rc" = 20 ] && [ "$(versao woocommerce)" = 1.0 ] || fail "wc update falho saiu $rc (wc=$(versao woocommerce))"
[ ! -e "$doc/.maintenance" ] || fail "wc update falho: site ficou em manutenção"

# 13. vermelho só na conferência depois da limpeza de cache: nada a restaurar
# na etapa, então vai à cascata; nunca sai 20 com as versões novas no ar.
preparar cache
n="$TMP_DIR/contador-cache"; : > "$n"
rodar "$LISTA" UONIX_SMOKE_CMD="echo x >> $n; [ \$(wc -l < $n) -ne 4 ]"
[ "$rc" = 20 ] && [ "$(versao a)" = 1.0 ] && [ "$(versao b)" = 1.0 ] \
  || fail "vermelho pós-cache deveria restaurar tudo antes de sair 20 (saiu $rc, a=$(versao a) b=$(versao b)): $(cat "$bk.log")"
grep -q 'restaurando TUDO' "$bk.log" || fail "vermelho pós-cache deveria ir à cascata"

# 14. manutenção que não ativa: aborta antes de qualquer update (#421)
preparar semmanut; rodar "$LISTA" UONIX_SMOKE_CMD=true FAKE_FALHA_MANUTENCAO=1
[ "$rc" = 30 ] && [ "$(versao a)" = 1.0 ] && [ "$(versao b)" = 1.0 ] \
  || fail "manutenção que não ativa deveria sair 30 sem alterar nada (saiu $rc, a=$(versao a) b=$(versao b))"
grep -q 'não foi possível ativar o modo de manutenção' "$bk.log" || fail "relatório sem o motivo do aborto"

# 15. a cascata limpa também o cache de páginas (wp_cache_clear_cache) (#421)
preparar cascatacache
rodar "$LISTA" UONIX_SMOKE_CMD="[ ! -e $doc/QUEBRADO ]" FAKE_QUEBRA_PERMANENTE=b
grep -q 'wp_cache_clear_cache' "$doc/.eval-log" 2>/dev/null || fail "a cascata não chamou a limpeza do WP Super Cache"

# 16. restauração falha, mas o site volta verde: continua sendo 50, porque a
# restauração ficou incompleta (o operador precisa saber), nunca 20.
preparar restaurafalha
n="$TMP_DIR/contador-rf"; : > "$n"
# 1ª e 2ª conferências (preflight e lote) verdes; 3ª (etapa b) vermelha e corrompe o tar de b; depois verde.
rodar "$LISTA" UONIX_SMOKE_CMD="echo x >> $n; if [ \$(wc -l < $n) -eq 3 ]; then : > $bk/plugin-b.tar.gz; false; else true; fi"
[ "$rc" = 50 ] || fail "restauração que falha com o site verde deveria sair 50 (saiu $rc): $(cat "$bk.log")"
grep -q 'RESTAURAÇÃO FALHOU' "$bk.log" || fail "relatório sem a falha da restauração"

# 12. SIGTERM no meio do update: manutenção desligada e status 143 gravado
preparar sinal
( UONIX_SMOKE_CMD=true FAKE_DORME=b bash "$JOB" "$doc" "$TMP_DIR/php" /fake/wp-cli.phar "$bk" "$LISTA" > "$bk.log" 2>&1 ) &
job=$!
for _ in $(seq 1 30); do grep -q '== b 1.0 -> 2.0' "$bk.log" 2>/dev/null && break; sleep 0.2; done
sleep 0.5
pkill -TERM -f "$JOB $doc" || true
wait "$job" || true
[ "$(cat "$bk/aplicar.status" 2>/dev/null)" = 143 ] || fail "SIGTERM deveria gravar status 143 (status: $(cat "$bk/aplicar.status" 2>/dev/null || echo ausente)): $(cat "$bk.log")"
[ ! -e "$doc/.maintenance" ] || fail "SIGTERM deixou o site em manutenção"

# 17. a reativação da manutenção entre etapas falha: segue, avisa e não deixa manutenção (#428)
preparar reativa; rodar "$LISTA" UONIX_SMOKE_CMD=true FAKE_FALHA_MANUTENCAO_A_PARTIR_DE=2
[ "$rc" = 0 ] && [ "$(versao a)" = 1.1 ] && [ "$(versao b)" = 2.0 ] \
  || fail "reativação falha deveria seguir e aplicar tudo (saiu $rc, a=$(versao a) b=$(versao b)): $(cat "$bk.log")"
# Duas etapas (lote "a", depois o crítico "b"): a ativação inicial e uma reativação depois de cada etapa.
[ "$(grep -c 'AVISO: manutenção não reativou; seguindo' "$bk.log")" = 2 ] || fail "reativação falha sem os 2 avisos: $(cat "$bk.log")"
[ "$(cat "$doc/.ativacoes")" = 3 ] || fail "deveria haver 3 ativações (inicial e uma por etapa): $(cat "$doc/.ativacoes")"
[ ! -e "$doc/.maintenance" ] || fail "reativação falha deixou o site em manutenção"

# 18. tema crítico + plugin: o tema entra pelo wp theme, o backup é tema-<slug>.tar.gz e nada vaza para plugins/
LISTA_TEMA=$'a 1.0 1.1 acoplada plugin\nt 1.0 2.0 critica tema'
preparar tema; rodar "$LISTA_TEMA" UONIX_SMOKE_CMD=true
[ "$rc" = 0 ] && [ "$(versao a)" = 1.1 ] && [ "$(versao_tema t)" = 2.0 ] \
  || fail "tema verde saiu $rc (a=$(versao a) t=$(versao_tema t)): $(cat "$bk.log")"
[ -s "$bk/tema-t.tar.gz" ] && [ -s "$bk/plugin-a.tar.gz" ] || fail "tema: backups tema-t e plugin-a deveriam existir"
[ ! -e "$doc/wp-content/plugins/t" ] || fail "tema: pasta do tema apareceu em plugins/"
grep -qx '@@VERSAO t 2.0' "$bk.log" || fail "tema: versões finais sem o tema"
grep -q 'pastas salvas: 2' "$bk.log" || fail "tema: a contagem de pastas salvas deveria incluir o tema (2)"

# 19. smoke vermelho depois do tema: o tema é restaurado de tema-t.tar.gz e a pasta nova fica em descartado-t
preparar temasmoke
rodar "$LISTA_TEMA" UONIX_SMOKE_CMD="! grep -qx 2.0 $doc/wp-content/themes/t/VERSION"
[ "$rc" = 20 ] && [ "$(versao_tema t)" = 1.0 ] && [ "$(cat "$doc/wp-content/themes/t/functions.php")" = tema-original ] \
  || fail "tema reprovado deveria sair 20 com o tema restaurado (saiu $rc, t=$(versao_tema t)): $(cat "$bk.log")"
[ "$(cat "$bk/descartado-t/VERSION")" = 2.0 ] || fail "tema: pasta quebrada não foi guardada em descartado-t"
[ "$(versao a)" = 1.1 ] || fail "tema: o plugin do lote, aplicado antes, deveria continuar 1.1"
[ ! -e "$doc/.maintenance" ] || fail "tema reprovado: site ficou em manutenção"

# 20. drift do tema: nada alterado
preparar temadrift; rodar $'t 0.9 2.0 critica tema' UONIX_SMOKE_CMD=true
[ "$rc" = 30 ] && [ "$(versao_tema t)" = 1.0 ] || fail "drift de tema saiu $rc"
grep -q 'DRIFT t: produção=1.0 lock=0.9' "$bk.log" || fail "drift de tema sem o item divergente"

# 21. tar do tema corrompido na restauração: 50, nunca 20
preparar temartar
rodar "$LISTA_TEMA" UONIX_SMOKE_CMD="if grep -qx 2.0 $doc/wp-content/themes/t/VERSION; then : > $bk/tema-t.tar.gz; false; else true; fi"
[ "$rc" = 50 ] || fail "restauração do tema que falha deveria sair 50 (saiu $rc): $(cat "$bk.log")"

# 22. linha sem o campo tipo (formato antigo) segue valendo como plugin
preparar semtipo; rodar "$LISTA" UONIX_SMOKE_CMD=true
[ "$rc" = 0 ] && [ "$(versao b)" = 2.0 ] && [ -s "$bk/plugin-b.tar.gz" ] || fail "lista sem tipo deveria valer como plugin (saiu $rc)"

# 23. tar que sai com 1 (arquivo mudou durante a leitura) repete e passa; com 2 aborta na hora (#447)
mkdir -p "$TMP_DIR/fake-tar"
REAL_TAR="$(command -v tar)"
cat > "$TMP_DIR/fake-tar/tar" <<TAR
#!/usr/bin/env bash
# Só o tar de backup (-czf) é falho; o resto (-tzf, -xzf) vai para o tar de verdade.
if [ "\$1" = -czf ] && [ -n "\${FAKE_TAR_FALHAS:-}" ]; then
  n=\$(( \$(cat "\$FAKE_TAR_CONTADOR" 2>/dev/null || echo 0) + 1 )); printf '%s\n' "\$n" > "\$FAKE_TAR_CONTADOR"
  if [ "\$n" -le "\$FAKE_TAR_FALHAS" ]; then "$REAL_TAR" "\$@"; echo "tar: x: file changed as we read it" >&2; exit "\${FAKE_TAR_SAIDA:-1}"; fi
fi
exec "$REAL_TAR" "\$@"
TAR
chmod +x "$TMP_DIR/fake-tar/tar"

preparar tarrepete; ctr="$TMP_DIR/ctr-repete"; : > "$ctr"
rodar $'b 1.0 2.0 critica' PATH="$TMP_DIR/fake-tar:$PATH" FAKE_TAR_FALHAS=2 FAKE_TAR_CONTADOR="$ctr" TAR_ESPERA=0 UONIX_SMOKE_CMD=true
[ "$rc" = 0 ] && [ "$(versao b)" = 2.0 ] || fail "tar que falha 2x com saída 1 deveria passar na 3ª (saiu $rc): $(cat "$bk.log")"
[ "$(grep -c 'arquivo mudou durante a leitura' "$bk.log")" = 2 ] || fail "tar: deveria registrar 2 repetições: $(cat "$bk.log")"
tar -tzf "$bk/plugin-b.tar.gz" >/dev/null || fail "tar repetido: backup ilegível"

preparar tarsempre; ctr="$TMP_DIR/ctr-sempre"; : > "$ctr"
rodar $'b 1.0 2.0 critica' PATH="$TMP_DIR/fake-tar:$PATH" FAKE_TAR_FALHAS=99 FAKE_TAR_CONTADOR="$ctr" TAR_ESPERA=0 UONIX_SMOKE_CMD=true
[ "$rc" = 30 ] && [ "$(versao b)" = 1.0 ] || fail "tar que sempre sai 1 deveria abortar com 30 sem alterar nada (saiu $rc)"
[ "$(cat "$ctr")" = 3 ] || fail "tar: deveria tentar 3 vezes antes de abortar (tentou $(cat "$ctr"))"
grep -q 'ABORTADO: tar de b falhou' "$bk.log" || fail "tar: relatório sem o aborto"

preparar tarerro; ctr="$TMP_DIR/ctr-erro"; : > "$ctr"
rodar $'b 1.0 2.0 critica' PATH="$TMP_DIR/fake-tar:$PATH" FAKE_TAR_FALHAS=99 FAKE_TAR_SAIDA=2 FAKE_TAR_CONTADOR="$ctr" TAR_ESPERA=0 UONIX_SMOKE_CMD=true
[ "$rc" = 30 ] && [ "$(cat "$ctr")" = 1 ] || fail "tar com saída 2 deveria abortar na 1ª tentativa (saiu $rc, tentou $(cat "$ctr"))"
[ ! -e "$doc/.maintenance" ] && [ "$(versao b)" = 1.0 ] || fail "tar com erro: algo foi alterado"

# Vários itens, repetição só no 1º tar: o 2º é salvo de primeira, e a restauração depois de um
# smoke vermelho usa o backup obtido na repetição (a LISTA não pode ser consumida pelo tar)
preparar tarmulti; ctr="$TMP_DIR/ctr-multi"; : > "$ctr"
rodar "$LISTA" PATH="$TMP_DIR/fake-tar:$PATH" FAKE_TAR_FALHAS=1 FAKE_TAR_CONTADOR="$ctr" TAR_ESPERA=0 \
  UONIX_SMOKE_CMD="! grep -qx 2.0 $doc/wp-content/plugins/b/VERSION"
[ "$rc" = 20 ] && [ "$(versao a)" = 1.1 ] && [ "$(versao b)" = 1.0 ] \
  || fail "vários itens com repetição: smoke vermelho em b deveria restaurar b (saiu $rc, a=$(versao a) b=$(versao b)): $(cat "$bk.log")"
[ -s "$bk/plugin-a.tar.gz" ] && [ -s "$bk/plugin-b.tar.gz" ] || fail "vários itens: os dois backups deveriam existir"
[ "$(grep -c 'arquivo mudou durante a leitura (tentativa' "$bk.log")" = 1 ] || fail "vários itens: deveria haver uma só repetição"

# tar que "termina bem" mas deixa um arquivo ilegível: o backup não pode ser aceito
cat > "$TMP_DIR/fake-tar/tar" <<TAR
#!/usr/bin/env bash
if [ "\$1" = -czf ]; then printf 'lixo' > "\$2"; exit 0; fi
exec "$REAL_TAR" "\$@"
TAR
preparar tarilegivel
rodar $'b 1.0 2.0 critica' PATH="$TMP_DIR/fake-tar:$PATH" TAR_ESPERA=0 UONIX_SMOKE_CMD=true
[ "$rc" = 30 ] && [ "$(versao b)" = 1.0 ] || fail "backup ilegível deveria abortar com 30 (saiu $rc)"
grep -q 'ficou ilegível' "$bk.log" || fail "backup ilegível sem o aviso"

# 24. núcleo (#448): verde. O backup guarda wp-admin, wp-includes e a raiz do núcleo, e NUNCA wp-config.php,
# .htaccess nem wp-content.
LISTA_NUCLEO=$'a 1.0 1.1 acoplada plugin\nwordpress 7.1.3 7.1.4 critica nucleo'
nucleo_ver() { cat "$doc/wp-includes/version.txt"; }
preparar nucleo; rodar "$LISTA_NUCLEO" UONIX_SMOKE_CMD=true
[ "$rc" = 0 ] && [ "$(nucleo_ver)" = 7.1.4 ] && [ "$(versao a)" = 1.1 ] || fail "núcleo verde saiu $rc (núcleo=$(nucleo_ver) a=$(versao a)): $(cat "$bk.log")"
[ -s "$bk/nucleo-wordpress.tar.gz" ] || fail "núcleo: sem nucleo-wordpress.tar.gz"
lista_tar="$(tar -tzf "$bk/nucleo-wordpress.tar.gz")"
grep -q '^wp-includes/version.txt' <<< "$lista_tar" || fail "núcleo: o tar deveria ter wp-includes: $lista_tar"
grep -q '^index.php' <<< "$lista_tar" || fail "núcleo: o tar deveria ter index.php: $lista_tar"
! grep -qE 'wp-config\.php|\.htaccess|wp-content/plugins|wp-content/themes|wp-content/uploads|wp-content/languages/plugins' <<< "$lista_tar" \
  || fail "núcleo: o tar não pode levar wp-config, .htaccess, plugins, temas, uploads nem idiomas de plugin: $lista_tar"
grep -q '^wp-content/languages/pt_BR.po' <<< "$lista_tar" || fail "núcleo: o tar deveria levar os idiomas do núcleo: $lista_tar"
# O WordPress apaga o .maintenance no fim do core update; o job reativa antes do update-db
[ "$(cat "$doc/.updatedb-log")" = "manutencao-ativa-no-update-db" ] || fail "núcleo: update-db deveria rodar com a manutenção ativa: $(cat "$doc/.updatedb-log")"
grep -qx '@@VERSAO wordpress 7.1.4' "$bk.log" || fail "núcleo: versões finais sem o núcleo"
# O idioma do site vai ao core update: sem --locale, um WordPress pt_BR viraria en_US
grep -q -- '--locale=pt_BR' "$doc/.core-update-args" || fail "núcleo: core update sem --locale=pt_BR: $(cat "$doc/.core-update-args")"
grep -q -- '--version=7.1.4' "$doc/.core-update-args" || fail "núcleo: core update sem a versão exata do lock"
[ "$(find "$bk" -maxdepth 1 -name '*.tar.gz' | wc -l | tr -d ' ')" = 2 ] || fail "núcleo: deveria haver 2 backups (plugin e núcleo)"
grep -q 'pastas salvas: 2' "$bk.log" || fail "núcleo: o job deveria contar 2 pastas salvas"

# 25. núcleo reprovado no smoke: restaura wp-admin, wp-includes e a raiz; não toca em wp-config, .htaccess
# nem wp-content; o estado quebrado fica em descartado-wordpress
preparar nucleosmoke
printf 'mudado depois do backup\n' > "$doc/wp-content/plugins/c/extra.txt"
rodar "$LISTA_NUCLEO" UONIX_SMOKE_CMD="! grep -qx 7.1.4 $doc/wp-includes/version.txt"
[ "$rc" = 20 ] && [ "$(nucleo_ver)" = 7.1.3 ] || fail "núcleo reprovado deveria sair 20 com 7.1.3 (saiu $rc, núcleo=$(nucleo_ver)): $(cat "$bk.log")"
[ "$(cat "$doc/index.php")" = "index original" ] || fail "núcleo: index.php da raiz não foi restaurado"
[ ! -e "$doc/wp-admin/arquivo-novo.php" ] || fail "núcleo: wp-admin restaurado ainda tem o arquivo novo"
[ "$(cat "$doc/wp-config.php")" = segredo ] && [ "$(cat "$doc/.htaccess")" = regras ] || fail "núcleo: wp-config.php ou .htaccess foram tocados"
[ "$(cat "$doc/wp-content/plugins/c/extra.txt")" = "mudado depois do backup" ] || fail "núcleo: wp-content foi tocado pela restauração"
[ "$(cat "$bk/descartado-wordpress/wp-includes/version.txt")" = 7.1.4 ] || fail "núcleo: o estado quebrado deveria ficar em descartado-wordpress"
[ "$(cat "$bk/descartado-wordpress/index.php")" = "novo 7.1.4" ] || fail "núcleo: index.php quebrado deveria ir para o descarte"
[ "$(cat "$doc/wp-content/languages/pt_BR.po")" = "idioma original" ] || fail "núcleo: o idioma do núcleo não foi restaurado: $(cat "$doc/wp-content/languages/pt_BR.po")"
[ "$(cat "$doc/wp-content/languages/plugins/a-pt_BR.po")" = "idioma de plugin novo" ] || fail "núcleo: o idioma de plugin não pode ser tocado pela restauração"
tar -tzf "$bk/descartado-wordpress/idiomas-atuais.tar.gz" | grep -q 'languages/pt_BR.po' || fail "núcleo: os idiomas atuais deveriam ficar guardados em descartado-wordpress"
[ "$(versao a)" = 1.1 ] || fail "núcleo: o plugin aplicado antes deveria continuar 1.1"
[ ! -e "$doc/.maintenance" ] || fail "núcleo reprovado: site ficou em manutenção"

# 26. update-db falha, checksums falham e o próprio update falha: restaura (20)
for var in FAKE_FALHA_NUCLEO_DB FAKE_FALHA_CHECKSUMS FAKE_FALHA_NUCLEO; do
  preparar "nucleo-$var"; rodar $'wordpress 7.1.3 7.1.4 critica nucleo' UONIX_SMOKE_CMD=true "$var=1"
  [ "$rc" = 20 ] && [ "$(nucleo_ver)" = 7.1.3 ] && [ "$(cat "$doc/index.php")" = "index original" ] \
    || fail "núcleo com $var deveria sair 20 restaurado (saiu $rc, núcleo=$(nucleo_ver)): $(cat "$bk.log")"
done

# 27. drift do núcleo: nada alterado
preparar nucleodrift; rodar $'wordpress 7.1.2 7.1.4 critica nucleo' UONIX_SMOKE_CMD=true
[ "$rc" = 30 ] && [ "$(nucleo_ver)" = 7.1.3 ] || fail "drift do núcleo saiu $rc: $(cat "$bk.log")"
grep -q 'DRIFT wordpress: produção=7.1.3 lock=7.1.2' "$bk.log" || fail "drift do núcleo sem o item divergente"

# 28. smoke que só reprova com o núcleo novo E o plugin do lote já aplicado: cascata devolve os dois
preparar nucleocascata
rodar "$LISTA_NUCLEO" UONIX_SMOKE_CMD="[ ! -e $doc/QUEBRADO ]" FAKE_NUCLEO_QUEBRA=1
[ "$rc" = 50 ] && [ "$(nucleo_ver)" = 7.1.3 ] && [ "$(versao a)" = 1.0 ] \
  || fail "vermelho persistente com núcleo deveria restaurar tudo e sair 50 (saiu $rc, núcleo=$(nucleo_ver) a=$(versao a)): $(cat "$bk.log")"
grep -q 'restaurando TUDO' "$bk.log" || fail "núcleo: vermelho persistente deveria ir à cascata"

# 28b. arquivo órfão na raiz (criado depois do backup) não é apagado; a restauração não toca em nada fora da lista
preparar nucleoorfao
printf 'meu\n' > "$doc/wp-arquivo-do-cliente.php"
rodar $'wordpress 7.1.3 7.1.4 critica nucleo' UONIX_SMOKE_CMD="! grep -qx 7.1.4 $doc/wp-includes/version.txt"
[ "$rc" = 20 ] && [ "$(cat "$doc/wp-arquivo-do-cliente.php")" = meu ] || fail "arquivo do cliente na raiz deveria sobreviver à restauração (saiu $rc)"

# 28c. o `mv` que devolve a raiz falha na restauração do núcleo: 50, nunca 20
mkdir -p "$TMP_DIR/fake-mv"
REAL_MV="$(command -v mv)"
cat > "$TMP_DIR/fake-mv/mv" <<MV
#!/usr/bin/env bash
# falha só ao devolver index.php da pasta restaura-wordpress; o resto vai para o mv de verdade
case "\$1" in */restaura-wordpress/index.php) exit 1 ;; esac
exec "$REAL_MV" "\$@"
MV
chmod +x "$TMP_DIR/fake-mv/mv"
preparar nucleomvfalha
rodar $'wordpress 7.1.3 7.1.4 critica nucleo' PATH="$TMP_DIR/fake-mv:$PATH" UONIX_SMOKE_CMD="! grep -qx 7.1.4 $doc/wp-includes/version.txt"
[ "$rc" = 50 ] || fail "mv de volta que falha deveria sair 50 (saiu $rc): $(cat "$bk.log")"
grep -q 'não pôs index.php no lugar' "$bk.log" || fail "relatório sem a falha ao devolver index.php: $(cat "$bk.log")"

# 29. tar do núcleo corrompido na restauração: 50, nunca 20
preparar nucleotar
rodar $'wordpress 7.1.3 7.1.4 critica nucleo' UONIX_SMOKE_CMD="if grep -qx 7.1.4 $doc/wp-includes/version.txt; then : > $bk/nucleo-wordpress.tar.gz; false; else true; fi"
[ "$rc" = 50 ] || fail "restauração do núcleo que falha deveria sair 50 (saiu $rc): $(cat "$bk.log")"

# 30. versões finais (#430): uma linha @@VERSAO por item, e o ruído do PHP no stdout do WP-CLI não vira "versão"
preparar versoes; rodar $'a 1.0 1.1 acoplada plugin\nb 1.0 2.0 critica plugin' UONIX_SMOKE_CMD=true
[ "$rc" = 0 ] || fail "versões: job verde saiu $rc"
grep -qx '@@VERSAO a 1.1' "$bk.log" || fail "versões: sem @@VERSAO a 1.1: $(grep VERSAO "$bk.log")"
grep -qx '@@VERSAO b 2.0' "$bk.log" || fail "versões: sem @@VERSAO b 2.0: $(grep VERSAO "$bk.log")"
! grep -qE '^  [a-z] [0-9]' "$bk.log" || fail "versões: o formato antigo não deveria mais ser impresso"
preparar versoesruido; rodar $'a 1.0 1.1 acoplada plugin' UONIX_SMOKE_CMD=true FAKE_RUIDO_VERSAO=a
grep -qx '@@VERSAO a ?' "$bk.log" || fail "versões: Warning do PHP no stdout deveria virar '?': $(grep VERSAO "$bk.log")"
[ "$(grep -c '^@@VERSAO' "$bk.log")" = 1 ] || fail "versões: o ruído não pode gerar linha @@VERSAO extra"

printf 'OK: job remoto aplica, recusa drift, restaura sem apagar e nunca deixa manutenção ligada\n'
