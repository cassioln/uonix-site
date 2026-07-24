#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENVIRONMENT=""
OUTPUT=""
TEMP_OUTPUT=""

usage() {
  cat <<'EOF'
Uso: scripts/prepare-deploy-bundle.sh --environment=production|qa|development --output=PATH
EOF
}

fail() {
  printf 'ERRO: %s\n' "$*" >&2
  exit 1
}

cleanup() {
  if [ -n "$TEMP_OUTPUT" ] && [ -e "$TEMP_OUTPUT" ]; then
    rm -rf -- "$TEMP_OUTPUT"
  fi
}
trap cleanup EXIT HUP INT TERM

for argument in "$@"; do
  case "$argument" in
    --environment=*) ENVIRONMENT="${argument#*=}" ;;
    --output=*) OUTPUT="${argument#*=}" ;;
    --help|-h)
      usage
      exit 0
      ;;
    *) fail "argumento desconhecido: ${argument}" ;;
  esac
done

case "$ENVIRONMENT" in
  production|qa|development) ;;
  '') fail '--environment é obrigatório' ;;
  *) fail "ambiente remoto inválido: ${ENVIRONMENT}" ;;
esac

[ -n "$OUTPUT" ] || fail '--output é obrigatório'
OUTPUT="$(python3 -c 'import os, sys; print(os.path.abspath(sys.argv[1]))' "$OUTPUT")"

case "$OUTPUT" in
  /|"$ROOT_DIR") fail "output inseguro: ${OUTPUT}" ;;
esac

[ -d "${ROOT_DIR}/themes/kadence-child" ] || fail 'tema Kadence Child ausente'
[ -f "${ROOT_DIR}/mu-plugins/uonix-core.php" ] || fail 'uonix-core.php ausente'

TEMP_OUTPUT="${OUTPUT}.tmp.$$"
rm -rf -- "$TEMP_OUTPUT"
mkdir -p "$TEMP_OUTPUT/theme" "$TEMP_OUTPUT/mu-plugins"

rsync -a \
  --exclude='.DS_Store' \
  --exclude='._*' \
  "${ROOT_DIR}/themes/kadence-child/" \
  "$TEMP_OUTPUT/theme/"

cp "${ROOT_DIR}/mu-plugins/uonix-core.php" "$TEMP_OUTPUT/mu-plugins/uonix-core.php"

for module_path in "${ROOT_DIR}"/mu-plugins/uonix-*; do
  [ -d "$module_path" ] || continue
  module_name="$(basename "$module_path")"

  if [ 'uonix-local' = "$module_name" ]; then
    continue
  fi

  mkdir -p "$TEMP_OUTPUT/mu-plugins/$module_name"
  rsync -a \
    --exclude='.DS_Store' \
    --exclude='._*' \
    "$module_path/" \
    "$TEMP_OUTPUT/mu-plugins/$module_name/"
done

(
  cd "$TEMP_OUTPUT"
  find theme mu-plugins -type f -print | LC_ALL=C sort | while IFS= read -r relative_path; do
    checksum="$(shasum -a 256 "$relative_path" | cut -d ' ' -f 1)"
    printf '%s  %s\n' "$checksum" "$relative_path"
  done > manifest.sha256
)

[ -s "$TEMP_OUTPUT/manifest.sha256" ] || fail 'manifest vazio'
rm -rf -- "$OUTPUT"
mv "$TEMP_OUTPUT" "$OUTPUT"
TEMP_OUTPUT=""

printf 'Bundle %s criado em %s.\n' "$ENVIRONMENT" "$OUTPUT"
