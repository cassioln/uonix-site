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
case "$1 $2" in
  "plugin get") [ -f "$p/$3/VERSION" ] && cat "$p/$3/VERSION" || exit 1 ;;
  "plugin update")
    [ "${FAKE_FALHA_UPDATE:-}" = "$3" ] && exit 1
    [ "${FAKE_DORME:-}" = "$3" ] && sleep 6
    [ "${FAKE_QUEBRA_PERMANENTE:-}" = "$3" ] && : > "$doc/QUEBRADO"
    v="${4#--version=}"
    [ "${FAKE_VERSAO_ERRADA:-}" = "$3" ] && v="$v.9"
    printf '%s\n' "$v" > "$p/$3/VERSION" ;;
  "maintenance-mode activate") : > "$doc/.maintenance" ;;
  "maintenance-mode deactivate") [ -f "$doc/.maintenance" ] && mv "$doc/.maintenance" "$doc/.maintenance-desligado" ; true ;;
  "wc update") [ -n "${FAKE_FALHA_WC:-}" ] && exit 1 ; true ;;
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
}

rodar() {  # rodar <lista> [env...]
  local lista="$1"; shift
  set +e
  env "$@" bash "$JOB" "$doc" "$TMP_DIR/php" /fake/wp-cli.phar "$bk" "$lista" > "$bk.log" 2>&1
  rc=$?
  set -e
}

versao() { cat "$doc/wp-content/plugins/$1/VERSION"; }
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

# 5. update falhando
preparar update; rodar "$LISTA" UONIX_SMOKE_CMD=true FAKE_FALHA_UPDATE=b
[ "$rc" = 20 ] && [ "$(versao b)" = 1.0 ] || fail "update falho saiu $rc"

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

# 13. vermelho só na conferência depois da limpeza de cache: nada a restaurar
# na etapa, então vai à cascata; nunca sai 20 com as versões novas no ar.
preparar cache
n="$TMP_DIR/contador-cache"; : > "$n"
rodar "$LISTA" UONIX_SMOKE_CMD="echo x >> $n; [ \$(wc -l < $n) -ne 4 ]"
[ "$rc" = 20 ] && [ "$(versao a)" = 1.0 ] && [ "$(versao b)" = 1.0 ] \
  || fail "vermelho pós-cache deveria restaurar tudo antes de sair 20 (saiu $rc, a=$(versao a) b=$(versao b)): $(cat "$bk.log")"
grep -q 'restaurando TUDO' "$bk.log" || fail "vermelho pós-cache deveria ir à cascata"

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

printf 'OK: job remoto aplica, recusa drift, restaura sem apagar e nunca deixa manutenção ligada\n'
