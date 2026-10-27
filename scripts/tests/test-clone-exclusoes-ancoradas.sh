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
# `uploads/wc-logs` e `uploads/wp-staging` saem, ANCORADOS no começo do caminho (#439): o mesmo
# nome dentro de um plugin é código e entra.
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

# 2. Backup: o tar com as exclusões reais, como create_backup (#439). A âncora tem sintaxe diferente em
# cada tar: a função escolhe pela primeira linha de `tar --version`, e o resultado é conferido de verdade.
GNU='tar (GNU tar) 1.35'
BSD='bsdtar 3.5.3 - libarchive 3.7.4 zlib/1.2.12 liblzma/5.4.3 bz2lib/1.0.8'
[ "$(backup_tar_exclude_args "$GNU" | tr '\n' ' ')" = "--anchored --exclude=uploads/wc-logs --exclude=uploads/wp-staging " ] \
  || fail "GNU tar: opções inesperadas: $(backup_tar_exclude_args "$GNU" | tr '\n' ' ')"
[ "$(backup_tar_exclude_args "$BSD" | tr '\n' ' ')" = "--exclude=^uploads/wc-logs --exclude=^uploads/wp-staging " ] \
  || fail "bsdtar: opções inesperadas: $(backup_tar_exclude_args "$BSD" | tr '\n' ' ')"
[ "$(backup_tar_exclude_args "BusyBox v1.36 tar" | tr '\n' ' ')" = "--exclude=uploads/wc-logs --exclude=uploads/wp-staging " ] \
  || fail "tar desconhecido deveria cair no padrão sem âncora (exclui a mais, nunca a menos)"
[ "$(backup_tar_exclude_args "" | tr '\n' ' ')" = "--exclude=uploads/wc-logs --exclude=uploads/wp-staging " ] \
  || fail "versão vazia deveria cair no padrão sem âncora"

mkdir -p "$TMP_DIR/origem/uploads/wc-logs" "$TMP_DIR/origem/uploads/wp-staging" "$TMP_DIR/origem/uploads/2026"
printf 'log\n' > "$TMP_DIR/origem/uploads/wc-logs/fatal-uploads.txt"
printf 'stg\n' > "$TMP_DIR/origem/uploads/wp-staging/copia.txt"
printf 'img\n' > "$TMP_DIR/origem/uploads/2026/foto.webp"
mkdir -p "$TMP_DIR/origem/plugins/com-uploads/uploads/wc-logs" "$TMP_DIR/origem/plugins/com-uploads/uploads/wp-staging"
printf 'codigo\n' > "$TMP_DIR/origem/plugins/com-uploads/uploads/wc-logs/x.php"
printf 'codigo\n' > "$TMP_DIR/origem/plugins/com-uploads/uploads/wp-staging/y.php"

# O tar que está instalado aqui (bsdtar no Mac, GNU no CI), com as opções que a função escolhe para ele.
tar_local=()
while IFS= read -r opt; do tar_local+=("$opt"); done < <(backup_tar_exclude_args "$(tar --version 2>&1 | head -n 1)")
arquivo="$TMP_DIR/backup.tar.gz"
tar -czf "$arquivo" -C "$TMP_DIR/origem" "${tar_local[@]}" -- uploads plugins || fail 'tar do backup falhou'
listagem="$(tar -tzf "$arquivo")"
grep -q 'plugins/wp-staging/Backend/Main.php' <<<"$listagem" || fail 'backup do rollback perdeu o plugin wp-staging'
grep -q 'plugins/wc-logs/fatal.txt' <<<"$listagem" || fail 'backup do rollback perdeu plugins/wc-logs (só uploads/wc-logs sai)'
grep -q 'uploads/2026/foto.webp' <<<"$listagem" || fail 'backup do rollback perdeu mídia de uploads'
! grep -q '^uploads/wc-logs/' <<<"$listagem" || fail 'backup incluiu uploads/wc-logs, que deveria ser excluído'
! grep -q '^uploads/wp-staging/' <<<"$listagem" || fail 'backup incluiu uploads/wp-staging, que deveria ser excluído'
# A âncora de verdade (#439): o mesmo caminho DENTRO de um plugin é código e entra no backup.
grep -q 'plugins/com-uploads/uploads/wc-logs/x.php' <<<"$listagem" \
  || fail 'o tar sem âncora tirou plugins/<p>/uploads/wc-logs, que é código de plugin'
grep -q 'plugins/com-uploads/uploads/wp-staging/y.php' <<<"$listagem" \
  || fail 'o tar sem âncora tirou plugins/<p>/uploads/wp-staging, que é código de plugin'
grep -q 'fast-indexing-api/vendor/psr/cache/src/CacheItemPoolInterface.php' <<<"$listagem" \
  || fail 'backup do rollback perdeu vendor/psr/cache'
grep -q 'google-site-kit/third-party/psr/cache/' <<<"$listagem" \
  || fail 'backup do rollback perdeu third-party/psr/cache'

# O ramo remoto: renderiza o script que prepare_target_backup enviaria ao servidor (remote_run vira um stub
# que o grava), confere a sintaxe e EXECUTA o trecho do tar contra um wp-content simulado (#439). O dump do
# banco e as conferências de conteúdo ficam de fora: só a parte do tar importa aqui.
corpo_prepare="$(declare -f prepare_target_backup)"
grep -q 'backup_tar_exclude_args' <<<"$corpo_prepare" || fail 'prepare_target_backup não usa backup_tar_exclude_args'
! grep -q 'BACKUP_TAR_EXCLUDES' <<<"$corpo_prepare" || fail 'prepare_target_backup ainda usa a lista antiga sem âncora'

rc=0
(
  set +e
  wc_remoto="$TMP_DIR/remoto/wp-content"
  mkdir -p "$wc_remoto/uploads/wc-logs" "$wc_remoto/uploads/wp-staging" "$wc_remoto/uploads/2026" \
           "$wc_remoto/plugins/p/uploads/wc-logs" "$wc_remoto/plugins/wp-staging"
  printf 'x\n' > "$wc_remoto/uploads/wc-logs/a"; printf 'x\n' > "$wc_remoto/uploads/wp-staging/s"
  printf 'x\n' > "$wc_remoto/uploads/2026/foto"; printf '<?php\n' > "$wc_remoto/plugins/p/uploads/wc-logs/c.php"
  printf '<?php\n' > "$wc_remoto/plugins/wp-staging/Main.php"
  # Substitutos: tudo que faria rede, banco ou SSH. Sobra o tar.
  is_remote_env() { return 0; }
  wp_path() { printf '%s\n' "$TMP_DIR/remoto"; }
  wp_cli_shell() { printf 'true\n'; }
  resolve_backup_root() { printf '%s\n' "$TMP_DIR/backups"; }
  validate_backup_destination() { return 0; }
  # O dump é falso, mas existe: o script remoto faz chmod nele depois do tar.
  remote_db_dump_snippet() { printf ': > %q\n' "$3"; }
  remote_dump_content_check_snippet() { printf 'true\n'; }
  log() { :; }
  remote_run() { printf '%s\n' "$2" > "$TMP_DIR/script-remoto.sh"; }
  INCLUDE_GIT_FILES=0 STAMP=teste
  prepare_target_backup remota "$TMP_DIR/backups/destino" >/dev/null 2>&1
  [ -s "$TMP_DIR/script-remoto.sh" ] || exit 11
  bash -n "$TMP_DIR/script-remoto.sh" || exit 12
  # O texto leva a lista de caminhos e a função (declare -p / declare -f), e o tar do servidor é lido lá.
  grep -q 'BACKUP_TAR_EXCLUDED_PATHS' "$TMP_DIR/script-remoto.sh" || exit 13
  grep -q 'backup_tar_exclude_args ()' "$TMP_DIR/script-remoto.sh" || exit 14
  grep -q 'tar --version' "$TMP_DIR/script-remoto.sh" || exit 15
  mkdir -p "$TMP_DIR/backups/destino"
  # A rotação de backups do script usa `find -printf`, que só o GNU find tem (o servidor); no Mac um
  # find falso, antes no PATH, devolve vazio. Só essa rotação é trocada: o tar roda de verdade.
  mkdir -p "$TMP_DIR/bin-find"
  printf '#!/bin/sh\nexit 0\n' > "$TMP_DIR/bin-find/find"; chmod +x "$TMP_DIR/bin-find/find"
  PATH="$TMP_DIR/bin-find:$PATH" bash "$TMP_DIR/script-remoto.sh" >"$TMP_DIR/script-remoto.log" 2>&1 || exit 16
  exit 0
) || rc=$?
case "$rc" in
  0) ;;
  11) fail 'remoto: prepare_target_backup não enviou script algum' ;;
  12) fail 'remoto: o script enviado ao servidor tem erro de sintaxe' ;;
  13) fail 'remoto: o script não leva BACKUP_TAR_EXCLUDED_PATHS (declare -p)' ;;
  14) fail 'remoto: o script não leva a função backup_tar_exclude_args (declare -f)' ;;
  15) fail 'remoto: o script não lê a versão do tar do servidor' ;;
  *) fail "remoto: o script enviado ao servidor falhou ao executar (rc=$rc): $(tail -5 "$TMP_DIR/script-remoto.log" 2>/dev/null)" ;;
esac
arq_remoto=""
for arq_remoto in "$TMP_DIR"/backups/destino/files-*.tar.gz; do break; done
[ -n "$arq_remoto" ] || fail 'remoto: o script não gerou o tar do backup'
lista_remota="$(tar -tzf "$arq_remoto")"
! grep -q '^uploads/wc-logs/' <<<"$lista_remota" || fail 'remoto: o backup incluiu uploads/wc-logs'
! grep -q '^uploads/wp-staging/' <<<"$lista_remota" || fail 'remoto: o backup incluiu uploads/wp-staging'
grep -q 'uploads/2026/foto' <<<"$lista_remota" || fail 'remoto: o backup perdeu mídia de uploads'
grep -q 'plugins/p/uploads/wc-logs/c.php' <<<"$lista_remota" || fail 'remoto: o backup perdeu plugins/<p>/uploads/wc-logs (código)'
grep -q 'plugins/wp-staging/Main.php' <<<"$lista_remota" || fail 'remoto: o backup perdeu o plugin wp-staging'

# 2b. GNU tar de verdade, quando houver um contêiner Linux disponível (o Mac só tem bsdtar). No CI o tar
# local já é GNU e foi exercitado acima; aqui o Mac confere o ramo GNU sem depender do servidor.
if command -v podman >/dev/null 2>&1 && podman image exists public.ecr.aws/docker/library/debian:stable-slim 2>/dev/null; then
  gnu_opts="$(backup_tar_exclude_args "$GNU" | tr '\n' ' ')"
  saida_gnu="$(podman run --rm -v "$TMP_DIR/origem:/o:ro" public.ecr.aws/docker/library/debian:stable-slim sh -c \
    "tar -czf /tmp/g.tgz -C /o $gnu_opts -- uploads plugins && tar -tzf /tmp/g.tgz" 2>&1)" || saida_gnu=""
  if [ -n "$saida_gnu" ]; then
    ! grep -q '^uploads/wc-logs/' <<<"$saida_gnu" || fail 'GNU tar: incluiu uploads/wc-logs'
    grep -q 'plugins/com-uploads/uploads/wc-logs/x.php' <<<"$saida_gnu" || fail 'GNU tar: --anchored não preservou plugins/<p>/uploads/wc-logs'
  else
    printf 'SKIP: o contêiner com GNU tar falhou; o ramo GNU fica para o CI (tar local GNU)\n' >&2
  fi
else
  printf 'SKIP: sem podman ou sem a imagem debian; o ramo GNU fica para o CI (tar local GNU)\n' >&2
fi

printf 'OK: exclusões ancoradas preservam código de plugin no espelho e no backup; dados pessoais nunca atravessam\n'
