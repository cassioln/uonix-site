#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="${ROOT_DIR}/scripts/prepare-deploy-bundle.sh"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT HUP INT TERM

fail() {
  printf 'FAIL: %s\n' "$*" >&2
  exit 1
}

if [ ! -f "$SCRIPT" ]; then
  fail 'prepare-deploy-bundle.sh ainda não existe.'
fi

OUTPUT="${TMP_DIR}/bundle"
bash "$SCRIPT" --environment=production --output="$OUTPUT"

[ -d "$OUTPUT/theme" ] || fail 'bundle/theme ausente'
[ -d "$OUTPUT/mu-plugins" ] || fail 'bundle/mu-plugins ausente'
[ -f "$OUTPUT/manifest.sha256" ] || fail 'manifest.sha256 ausente'
[ -f "$OUTPUT/mu-plugins/uonix-core.php" ] || fail 'uonix-core.php ausente'
[ ! -e "$OUTPUT/mu-plugins/uonix-local" ] || fail 'uonix-local entrou no bundle remoto'

if find "$OUTPUT" \( -name '.DS_Store' -o -name '._*' \) -print | grep -q .; then
  fail 'metadados macOS entraram no bundle'
fi

while IFS= read -r source_file; do
  relative_path="${source_file#"${ROOT_DIR}/themes/kadence-child/"}"
  target_file="${OUTPUT}/theme/${relative_path}"
  [ -f "$target_file" ] || fail "arquivo do tema ausente: ${relative_path}"
  cmp -s "$source_file" "$target_file" || fail "arquivo do tema divergiu: ${relative_path}"
done < <(find "${ROOT_DIR}/themes/kadence-child" -type f ! -name '.DS_Store' ! -name '._*' | LC_ALL=C sort)

while IFS= read -r bundled_file; do
  relative_path="${bundled_file#"${OUTPUT}/theme/"}"
  source_file="${ROOT_DIR}/themes/kadence-child/${relative_path}"
  [ -f "$source_file" ] || fail "arquivo inesperado no tema: ${relative_path}"
done < <(find "$OUTPUT/theme" -type f | LC_ALL=C sort)

while IFS= read -r source_file; do
  relative_path="${source_file#"${ROOT_DIR}/mu-plugins/"}"
  case "$relative_path" in
    uonix-core.php|uonix-*/*)
      case "$relative_path" in
        uonix-local/*) continue ;;
      esac
      ;;
    *) continue ;;
  esac

  case "$(basename "$source_file")" in
    .DS_Store|._*) continue ;;
  esac

  target_file="${OUTPUT}/mu-plugins/${relative_path}"
  [ -f "$target_file" ] || fail "arquivo MU ausente: ${relative_path}"
  cmp -s "$source_file" "$target_file" || fail "arquivo MU divergiu: ${relative_path}"
done < <(find "${ROOT_DIR}/mu-plugins" -type f | LC_ALL=C sort)

while IFS= read -r bundled_file; do
  relative_path="${bundled_file#"${OUTPUT}/mu-plugins/"}"
  case "$relative_path" in
    uonix-core.php|uonix-*/*) ;;
    *) fail "arquivo fora do escopo no bundle MU: ${relative_path}" ;;
  esac
  case "$relative_path" in
    uonix-local/*) fail 'uonix-local entrou no bundle MU' ;;
  esac
  [ -f "${ROOT_DIR}/mu-plugins/${relative_path}" ] || fail "arquivo MU sem origem: ${relative_path}"
done < <(find "$OUTPUT/mu-plugins" -type f | LC_ALL=C sort)

if grep -Ev '^[0-9a-f]{64}  (theme|mu-plugins)/' "$OUTPUT/manifest.sha256" | grep -q .; then
  fail 'manifest contém path fora do bundle gerenciado'
fi

(
  cd "$OUTPUT"
  shasum -a 256 -c manifest.sha256 >/dev/null
)

cp "$OUTPUT/manifest.sha256" "${TMP_DIR}/manifest.first"
bash "$SCRIPT" --environment=production --output="$OUTPUT"
cmp -s "${TMP_DIR}/manifest.first" "$OUTPUT/manifest.sha256" || fail 'manifest não é determinístico/idempotente'

for environment in qa development; do
  other_output="${TMP_DIR}/bundle-${environment}"
  bash "$SCRIPT" --environment="$environment" --output="$other_output"
  [ ! -e "$other_output/mu-plugins/uonix-local" ] || fail "uonix-local entrou no bundle ${environment}"
  (
    cd "$other_output"
    shasum -a 256 -c manifest.sha256 >/dev/null
  )
done

# Os workflows de deploy passam o tipo de ambiente WordPress (uonix_env_type), não o
# nome canônico curto: qa-hostgator resolve para 'staging'. Todos os aliases do mapa
# declarativo em scripts/lib/environment-map.sh devem ser aceitos, e o bundle não pode
# variar com o alias — o conteúdo publicado é idêntico em todos os ambientes remotos.
for environment in prod production qa staging dev development; do
  alias_output="${TMP_DIR}/bundle-alias-${environment}"
  bash "$SCRIPT" --environment="$environment" --output="$alias_output" \
    || fail "alias de ambiente rejeitado: ${environment}"
  [ ! -e "$alias_output/mu-plugins/uonix-local" ] || fail "uonix-local entrou no bundle ${environment}"
  cmp -s "${TMP_DIR}/manifest.first" "$alias_output/manifest.sha256" \
    || fail "manifest divergiu para o alias ${environment}"
  (
    cd "$alias_output"
    shasum -a 256 -c manifest.sha256 >/dev/null
  )
done

if bash "$SCRIPT" --environment=nao-existe --output="${TMP_DIR}/bundle-invalid" >/dev/null 2>&1; then
  fail 'ambiente inválido foi aceito'
fi
[ ! -e "${TMP_DIR}/bundle-invalid" ] || fail 'ambiente inválido produziu bundle'

printf 'PASS: bundle determinístico contém somente tema e MU-plugins gerenciados.\n'
