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
  "plugin get") [ -f "$p/$3/VERSION" ] && cat "$p/$3/VERSION" || exit 1 ;;
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
  eval*) printf '%s\n' "$2" >> "$doc/.eval-log" ;;
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
grep -q '  t 2.0' "$bk.log" || fail "tema: versões finais sem o tema"
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

printf 'OK: job remoto aplica, recusa drift, restaura sem apagar e nunca deixa manutenção ligada\n'
