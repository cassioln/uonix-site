#!/usr/bin/env bash
# Exclusões do clone não podem cortar código de plugin (#406, #415).
#
# Espelho (rsync): `cache/` e `logs/` só são excluídos na RAIZ do diretório
# sincronizado; os aninhados, como `vendor/psr/cache`, são copiados. Os
# diretórios de runtime de plugins (wc-logs, wp-staging, wpmc-trash, backups,
# FLUENT_PDF_TEMPLATES) só saem da raiz de `uploads`: em `plugins`, o plugin
# `wp-staging` é código e é copiado. Os de dados pessoais
# (`curriculos-recebidos/`, `wp-personal-data-exports/`) saem em qualquer
# diretório e profundidade: nem uma cópia aninhada pode atravessar ambientes.
# Backup (tar, de onde o rollback restaura): nenhum `cache` é excluído, e só
# `uploads/wc-logs` e `uploads/wp-staging` saem.
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
  "$origem/cache" "$origem/logs" "$origem/wc-logs" \
  "$origem/wp-staging/Backend" "$origem/wpvivid-backuprestore-x/wpvividbackups" \
  "$origem/um-plugin/curriculos-recebidos" "$origem/outro/wp-personal-data-exports"
printf '<?php interface CacheItemPoolInterface {}\n' > "$origem/fast-indexing-api/vendor/psr/cache/src/CacheItemPoolInterface.php"
printf '<?php\n' > "$origem/google-site-kit/third-party/psr/cache/CacheItemInterface.php"
printf '<?php\n' > "$origem/woocommerce-products-filter/ext/logs/index.php"
printf 'lixo\n' > "$origem/cache/pagina.html"
printf 'lixo\n' > "$origem/logs/erro.txt"
printf 'lixo\n' > "$origem/wc-logs/fatal.txt"
printf '<?php\n' > "$origem/wp-staging/Backend/Main.php"
printf '<?php\n' > "$origem/wpvivid-backuprestore-x/wpvividbackups/index.php"
printf 'pessoal\n' > "$origem/um-plugin/curriculos-recebidos/cv.pdf"
printf 'pessoal\n' > "$origem/outro/wp-personal-data-exports/export.zip"

# 1. Espelho: origem -> payload, como bridge_runtime_directory.
payload="$TMP_DIR/payload"
uonix_rsync_to_runner local "$origem" "$payload" "${EXCLUDED_RSYNC_ARGS[@]}" "${PLUGIN_RSYNC_EXCLUDES[@]}" \
  || fail 'uonix_rsync_to_runner falhou'

for codigo in \
  fast-indexing-api/vendor/psr/cache/src/CacheItemPoolInterface.php \
  google-site-kit/third-party/psr/cache/CacheItemInterface.php \
  woocommerce-products-filter/ext/logs/index.php \
  wp-staging/Backend/Main.php \
  wpvivid-backuprestore-x/wpvividbackups/index.php; do
  [ -f "$payload/$codigo" ] || fail "espelho cortou código de plugin: $codigo"
done
for pessoal in um-plugin/curriculos-recebidos outro/wp-personal-data-exports; do
  [ ! -e "$payload/$pessoal" ] || fail "espelho copiou dado pessoal aninhado: $pessoal"
done

# 1a. uploads: os diretórios de runtime e de dados pessoais da raiz não são copiados.
up="$TMP_DIR/origem-uploads"
nomes_raiz=(wc-logs wp-staging wpmc-trash wp-personal-data-exports curriculos-recebidos FLUENT_PDF_TEMPLATES \
            ai1wm-backups wpvivid_uploads wpvividbackups wpvivid_staging)
for n in "${nomes_raiz[@]}"; do mkdir -p "$up/$n"; printf 'x\n' > "$up/$n/arquivo"; done
mkdir -p "$up/2026/10"; printf 'img\n' > "$up/2026/10/foto.webp"
payload_up="$TMP_DIR/payload-uploads"
mkdir -p "$up/2026/10/wc-logs"; printf 'img\n' > "$up/2026/10/wc-logs/aninhado.webp"
uonix_rsync_to_runner local "$up" "$payload_up" "${EXCLUDED_RSYNC_ARGS[@]}" "${UPLOADS_RSYNC_EXCLUDES[@]}" \
  || fail 'rsync de uploads falhou'
for n in "${nomes_raiz[@]}"; do
  [ ! -e "$payload_up/$n" ] || fail "espelho copiou uploads/$n, que deveria ser excluído"
done
[ -f "$payload_up/2026/10/foto.webp" ] || fail 'espelho não copiou mídia comum de uploads'
[ -f "$payload_up/2026/10/wc-logs/aninhado.webp" ] || fail 'a exclusão de uploads/wc-logs deveria valer só na raiz'
# As exclusões de runtime de uploads são todas ancoradas, e nenhuma entra em plugins.
for exclusao in "${UPLOADS_RSYNC_EXCLUDES[@]}"; do
  case "$exclusao" in --exclude=/*) ;; *) fail "exclusão de uploads sem âncora: $exclusao" ;; esac
done
for exclusao in "${EXCLUDED_RSYNC_ARGS[@]}" "${PLUGIN_RSYNC_EXCLUDES[@]}"; do
  case "$exclusao" in *wp-staging*|*wc-logs*|*wpmc-trash*|*FLUENT_PDF*|*ai1wm*|*wpvivid_*|*wpvividbackups*)
    fail "exclusão de runtime de uploads vazou para os demais diretórios: $exclusao" ;; esac
done

for lixo in cache logs; do
  [ ! -e "$payload/$lixo" ] || fail "espelho copiou o $lixo/ da raiz, que deveria ser excluído"
done
[ -f "$payload/wc-logs/fatal.txt" ] || fail 'em plugins, wc-logs/ é só um nome: deveria ser copiado (o runtime fica em uploads)'

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

# 1c. As funções reais do clone montam as exclusões certas por diretório: a de
# uploads só em uploads, a de plugins só em plugins. O transporte é trocado por um
# falso que só registra os argumentos.
registro="$TMP_DIR/args"
uonix_rsync_from_runner() { shift 3; printf '%s\n' "$@" > "$registro"; }
uonix_rsync_to_runner() { shift 3; printf '%s\n' "$@" > "$registro.download"; }
for dir in uploads plugins languages; do
  bridge_upload_payload local "$TMP_DIR/vazio" "$TMP_DIR/vazio" "$dir" || fail "bridge_upload_payload $dir falhou"
  grep -qx -- "--exclude=/wp-staging/" "$registro" && tem_up=1 || tem_up=0
  grep -qx -- "--exclude=fluent-smtp/" "$registro" && tem_pl=1 || tem_pl=0
  grep -qx -- "--exclude=curriculos-recebidos/" "$registro" || fail "$dir sem a exclusão de dados pessoais"
  case "$dir" in
    uploads) [ "$tem_up$tem_pl" = 10 ] || fail "uploads deveria levar só as exclusões de uploads (up=$tem_up pl=$tem_pl)" ;;
    plugins) [ "$tem_up$tem_pl" = 01 ] || fail "plugins deveria levar só as exclusões de plugins (up=$tem_up pl=$tem_pl)" ;;
    *) [ "$tem_up$tem_pl" = 00 ] || fail "$dir não deveria levar exclusões de uploads nem de plugins (up=$tem_up pl=$tem_pl)" ;;
  esac
done
mkdir -p "$TMP_DIR/fonte-local/wp-content/uploads"
content_path() { printf '%s\n' "$TMP_DIR/fonte-local/wp-content"; }
write_directory_manifest() { :; }
verify_directory_manifest() { :; }
verify_payload_at_target() { :; }
: > "$registro"; : > "$registro.download"
bridge_runtime_directory local local uploads "$TMP_DIR/ponte" >/dev/null 2>&1 || true
# O download (origem -> ponte) e o upload (ponte -> destino) são conferidos separados:
# um não pode mascarar o outro.
grep -qx -- "--exclude=/wc-logs/" "$registro.download" || fail 'download de uploads sem as exclusões de uploads'
grep -qx -- "--exclude=/wc-logs/" "$registro" || fail 'upload de uploads sem as exclusões de uploads'
for dir in plugins languages; do
  : > "$registro.download"; mkdir -p "$TMP_DIR/fonte-local/wp-content/$dir"
  bridge_runtime_directory local local "$dir" "$TMP_DIR/ponte" >/dev/null 2>&1 || true
  ! grep -qx -- "--exclude=/wc-logs/" "$registro.download" || fail "download de $dir não deveria levar as exclusões de uploads"
done
unset -f uonix_rsync_from_runner uonix_rsync_to_runner content_path write_directory_manifest verify_directory_manifest verify_payload_at_target

# 2. Backup: o tar com as exclusões reais, como create_backup.
# O ramo remoto monta as mesmas exclusões como texto, por shell_join.
[ "$(shell_join "${BACKUP_TAR_EXCLUDES[@]}" | xargs)" = "--exclude=uploads/wc-logs --exclude=uploads/wp-staging" ] \
  || fail "exclusões do tar remoto inesperadas: $(shell_join "${BACKUP_TAR_EXCLUDES[@]}")"
arquivo="$TMP_DIR/backup.tar.gz"
mkdir -p "$TMP_DIR/origem/uploads/wc-logs" "$TMP_DIR/origem/uploads/wp-staging" "$TMP_DIR/origem/uploads/2026"
printf 'log\n' > "$TMP_DIR/origem/uploads/wc-logs/fatal-uploads.txt"
printf 'stg\n' > "$TMP_DIR/origem/uploads/wp-staging/copia.txt"
printf 'img\n' > "$TMP_DIR/origem/uploads/2026/foto.webp"
tar -czf "$arquivo" -C "$TMP_DIR/origem" "${BACKUP_TAR_EXCLUDES[@]}" -- uploads plugins || fail 'tar do backup falhou'
listagem="$(tar -tzf "$arquivo")"
grep -q 'plugins/wp-staging/Backend/Main.php' <<<"$listagem" || fail 'backup do rollback perdeu o plugin wp-staging'
grep -q 'plugins/wc-logs/fatal.txt' <<<"$listagem" || fail 'backup do rollback perdeu plugins/wc-logs (só uploads/wc-logs sai)'
grep -q 'uploads/2026/foto.webp' <<<"$listagem" || fail 'backup do rollback perdeu mídia de uploads'
! grep -q '^uploads/wc-logs/' <<<"$listagem" || fail 'backup incluiu uploads/wc-logs, que deveria ser excluído'
! grep -q '^uploads/wp-staging/' <<<"$listagem" || fail 'backup incluiu uploads/wp-staging, que deveria ser excluído'
# Comportamento conhecido, travado aqui para a documentação não mentir de novo:
# sem âncora, o tar também tira `plugins/<p>/uploads/wc-logs` (issue de seguimento).
mkdir -p "$TMP_DIR/origem/plugins/com-uploads/uploads/wc-logs"
printf 'codigo\n' > "$TMP_DIR/origem/plugins/com-uploads/uploads/wc-logs/x.php"
tar -czf "$arquivo" -C "$TMP_DIR/origem" "${BACKUP_TAR_EXCLUDES[@]}" -- uploads plugins || fail 'tar do backup falhou'
! tar -tzf "$arquivo" | grep -q 'plugins/com-uploads/uploads/wc-logs/x.php' \
  || fail 'o tar passou a ancorar: atualize o comentário de BACKUP_TAR_EXCLUDES e docs/clone-ambientes.md'
grep -q 'fast-indexing-api/vendor/psr/cache/src/CacheItemPoolInterface.php' <<<"$listagem" \
  || fail 'backup do rollback perdeu vendor/psr/cache'
grep -q 'google-site-kit/third-party/psr/cache/' <<<"$listagem" \
  || fail 'backup do rollback perdeu third-party/psr/cache'

printf 'OK: exclusões ancoradas preservam código de plugin no espelho e no backup; dados pessoais nunca atravessam\n'
