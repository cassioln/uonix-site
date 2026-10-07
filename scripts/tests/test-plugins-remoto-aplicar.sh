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
    v="${4#--version=}"
    [ "${FAKE_VERSAO_ERRADA:-}" = "$3" ] && v="$v.9"
    printf '%s\n' "$v" > "$p/$3/VERSION" ;;
  "maintenance-mode activate") : > "$doc/.maintenance" ;;
  "maintenance-mode deactivate") [ -f "$doc/.maintenance" ] && mv "$doc/.maintenance" "$doc/.maintenance-desligado" ; true ;;
  *) : ;;
esac
PHP
chmod +x "$TMP_DIR/php"

preparar() {
  doc="$TMP_DIR/doc-$1"; bk="$TMP_DIR/bk-$1"
  mkdir -p "$doc/wp-content/plugins/a" "$doc/wp-content/plugins/b" "$doc/wp-content/plugins/c"
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
grep -q 'PARADO EM: b (smoke)' "$bk.log" || fail "smoke: relatório sem o ponto de parada"

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

printf 'OK: job remoto aplica, recusa drift, restaura sem apagar e nunca deixa manutenção ligada\n'
