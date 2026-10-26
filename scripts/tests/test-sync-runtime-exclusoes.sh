#!/usr/bin/env bash
# sync-runtime-from-downloads.sh usa as exclusões do clone, não listas próprias (#439).
# Roda o script de verdade com DOWNLOADS_DIR falso e um ROOT_DIR copiado, para não tocar em local/ do checkout.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT HUP INT TERM
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }

# Raiz de trabalho copiada: o script grava em <raiz>/local/wp-content.
mkdir -p "$TMP_DIR/raiz/scripts/lib" "$TMP_DIR/raiz/local/wp-content/plugins" "$TMP_DIR/raiz/local/wp-content/uploads"
cp "$ROOT_DIR/scripts/sync-runtime-from-downloads.sh" "$ROOT_DIR/scripts/clone-environment.sh" "$TMP_DIR/raiz/scripts/"
cp "$ROOT_DIR/scripts/lib/"*.sh "$TMP_DIR/raiz/scripts/lib/"

d="$TMP_DIR/downloads"
mkdir -p "$d/plugins/wp-staging/Backend" "$d/plugins/um/logs" "$d/plugins/fluent-smtp" "$d/plugins/ok/vendor/psr/cache" \
         "$d/plugins/cache" "$d/uploads/wc-logs" "$d/uploads/wp-staging" "$d/uploads/2026/wc-logs" "$d/uploads/curriculos-recebidos" \
         "$d/uploads/outro/wp-personal-data-exports"
printf '<?php\n' > "$d/plugins/wp-staging/Backend/Main.php"
printf 'x\n' > "$d/plugins/um/logs/a.txt"; printf 'x\n' > "$d/plugins/fluent-smtp/f.php"
printf '<?php\n' > "$d/plugins/ok/vendor/psr/cache/A.php"; printf 'x\n' > "$d/plugins/cache/pagina.html"
printf 'x\n' > "$d/uploads/wc-logs/l"; printf 'x\n' > "$d/uploads/wp-staging/s"; printf 'x\n' > "$d/uploads/2026/wc-logs/aninhado"
printf 'x\n' > "$d/uploads/curriculos-recebidos/cv"; printf 'x\n' > "$d/uploads/outro/wp-personal-data-exports/e"
printf 'img\n' > "$d/uploads/2026/foto.webp"

DOWNLOADS_DIR="$d" bash "$TMP_DIR/raiz/scripts/sync-runtime-from-downloads.sh" >/dev/null || fail "sync-runtime falhou"
wc="$TMP_DIR/raiz/local/wp-content"

# Plugins: wp-staging é CÓDIGO e entra; o cache/ da raiz e os plugins por ambiente não.
[ -f "$wc/plugins/wp-staging/Backend/Main.php" ] || fail "plugins/wp-staging é código e deveria ser sincronizado (regra do clone)"
[ -f "$wc/plugins/ok/vendor/psr/cache/A.php" ] || fail "vendor/psr/cache aninhado é código e deveria entrar"
[ ! -e "$wc/plugins/cache" ] || fail "o cache/ da raiz de plugins não deveria entrar"
[ ! -e "$wc/plugins/fluent-smtp" ] || fail "fluent-smtp tem configuração por ambiente e não deveria entrar"
# O logs/ ANINHADO é código/dado do plugin e entra (a regra do clone só tira o /logs/ da raiz).
[ -f "$wc/plugins/um/logs/a.txt" ] || fail "logs/ aninhado em plugin deveria entrar, como no clone"

# Uploads: os diretórios de runtime da raiz saem; o mesmo nome aninhado e a mídia entram; dados pessoais nunca.
for n in wc-logs wp-staging curriculos-recebidos; do
  [ ! -e "$wc/uploads/$n" ] || fail "uploads/$n deveria ser excluído"
done
[ -f "$wc/uploads/2026/wc-logs/aninhado" ] || fail "a exclusão de uploads/wc-logs vale só na raiz (âncora)"
[ -f "$wc/uploads/2026/foto.webp" ] || fail "mídia comum de uploads deveria entrar"
[ ! -e "$wc/uploads/outro/wp-personal-data-exports" ] || fail "dado pessoal aninhado nunca atravessa (regra do clone)"

# O script não pode voltar a manter lista própria.
! grep -q -- "--exclude=" "$ROOT_DIR/scripts/sync-runtime-from-downloads.sh" \
  || fail "sync-runtime-from-downloads.sh voltou a ter exclusões próprias; use os arrays do clone"
grep -q 'EXCLUDED_RSYNC_ARGS' "$ROOT_DIR/scripts/sync-runtime-from-downloads.sh" || fail "o script deveria usar EXCLUDED_RSYNC_ARGS"

printf 'OK: sync-runtime-from-downloads usa as exclusões do clone (código de plugin entra; runtime e dados pessoais saem)\n'
