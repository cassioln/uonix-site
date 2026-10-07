#!/usr/bin/env bash
# Exclusões do clone não podem cortar código de plugin (#406).
#
# Espelho (rsync): `cache/` e `logs/` só são excluídos na RAIZ do diretório
# sincronizado; os aninhados, como `vendor/psr/cache`, são copiados.
# Backup (tar, de onde o rollback restaura): nenhum `cache` é excluído.
#
# Usa as funções e os arrays reais do clone, com origem e destino locais.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLONE_SCRIPT="$ROOT_DIR/scripts/clone-environment.sh"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT HUP INT TERM

fail() {
  printf 'FAIL: %s\n' "$*" >&2
  exit 1
}

export UONIX_CLONE_LIBRARY_ONLY=1
# shellcheck source=scripts/clone-environment.sh
source "$CLONE_SCRIPT"

origem="$TMP_DIR/origem/plugins"
mkdir -p \
  "$origem/fast-indexing-api/vendor/psr/cache/src" \
  "$origem/google-site-kit/third-party/psr/cache" \
  "$origem/woocommerce-products-filter/ext/logs" \
  "$origem/cache" "$origem/logs" "$origem/wc-logs"
printf '<?php interface CacheItemPoolInterface {}\n' > "$origem/fast-indexing-api/vendor/psr/cache/src/CacheItemPoolInterface.php"
printf '<?php\n' > "$origem/google-site-kit/third-party/psr/cache/CacheItemInterface.php"
printf '<?php\n' > "$origem/woocommerce-products-filter/ext/logs/index.php"
printf 'lixo\n' > "$origem/cache/pagina.html"
printf 'lixo\n' > "$origem/logs/erro.txt"
printf 'lixo\n' > "$origem/wc-logs/fatal.txt"

# 1. Espelho: origem -> payload, como bridge_runtime_directory.
payload="$TMP_DIR/payload"
uonix_rsync_to_runner local "$origem" "$payload" "${EXCLUDED_RSYNC_ARGS[@]}" "${PLUGIN_RSYNC_EXCLUDES[@]}" \
  || fail 'uonix_rsync_to_runner falhou'

for codigo in \
  fast-indexing-api/vendor/psr/cache/src/CacheItemPoolInterface.php \
  google-site-kit/third-party/psr/cache/CacheItemInterface.php \
  woocommerce-products-filter/ext/logs/index.php; do
  [ -f "$payload/$codigo" ] || fail "espelho cortou código de plugin: $codigo"
done
for lixo in cache logs wc-logs; do
  [ ! -e "$payload/$lixo" ] || fail "espelho copiou o $lixo/ da raiz, que deveria ser excluído"
done

# 1b. Espelho: payload -> destino com --delete, como bridge_upload_payload.
# O cache/ da raiz do destino segue protegido; o aninhado que só existe no
# destino é apagado, porque agora é espelhado como qualquer código.
destino="$TMP_DIR/destino"
mkdir -p "$destino/cache" "$destino/velho/vendor/psr/cache"
printf 'runtime\n' > "$destino/cache/pagina.html"
printf '<?php\n' > "$destino/velho/vendor/psr/cache/A.php"
uonix_rsync_from_runner local "$payload" "$destino" "${EXCLUDED_RSYNC_ARGS[@]}" "${PLUGIN_RSYNC_EXCLUDES[@]}" \
  || fail 'uonix_rsync_from_runner falhou'
[ -f "$destino/cache/pagina.html" ] || fail 'espelho apagou o cache/ da raiz do destino, que é protegido'
[ ! -e "$destino/velho" ] || fail 'espelho manteve plugin que só existe no destino por causa de um cache/ aninhado'
[ -f "$destino/fast-indexing-api/vendor/psr/cache/src/CacheItemPoolInterface.php" ] \
  || fail 'espelho não entregou vendor/psr/cache ao destino'

# 2. Backup: o tar com as exclusões reais, como create_backup.
# O ramo remoto monta as mesmas exclusões como texto, por shell_join.
[ "$(shell_join "${BACKUP_TAR_EXCLUDES[@]}" | xargs)" = "--exclude=wc-logs --exclude=wp-staging" ] \
  || fail "exclusões do tar remoto inesperadas: $(shell_join "${BACKUP_TAR_EXCLUDES[@]}")"
arquivo="$TMP_DIR/backup.tar.gz"
tar -czf "$arquivo" -C "$TMP_DIR/origem" "${BACKUP_TAR_EXCLUDES[@]}" -- plugins || fail 'tar do backup falhou'
listagem="$(tar -tzf "$arquivo")"
grep -q 'fast-indexing-api/vendor/psr/cache/src/CacheItemPoolInterface.php' <<<"$listagem" \
  || fail 'backup do rollback perdeu vendor/psr/cache'
grep -q 'google-site-kit/third-party/psr/cache/' <<<"$listagem" \
  || fail 'backup do rollback perdeu third-party/psr/cache'
! grep -q 'wc-logs/fatal.txt' <<<"$listagem" || fail 'backup incluiu wc-logs, que deveria ser excluído'

printf 'OK: exclusões ancoradas preservam código de plugin no espelho e no backup\n'
