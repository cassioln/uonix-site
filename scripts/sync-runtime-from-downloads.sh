#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOWNLOADS_DIR="${DOWNLOADS_DIR:-$HOME/Downloads}"
WP_CONTENT_DIR="${ROOT_DIR}/local/wp-content"

# As exclusões vêm do clone (#439): uma regra só para os dois caminhos. Antes este script mantinha
# listas próprias e divergia (excluía `wp-staging/` em plugins, que é código, e as de uploads sem âncora).
export UONIX_CLONE_LIBRARY_ONLY=1
# shellcheck source=scripts/clone-environment.sh
source "${ROOT_DIR}/scripts/clone-environment.sh"

if [ -d "${DOWNLOADS_DIR}/plugins" ]; then
  rsync -a --delete \
    "${EXCLUDED_RSYNC_ARGS[@]}" \
    "${PLUGIN_RSYNC_EXCLUDES[@]}" \
    "${DOWNLOADS_DIR}/plugins/" \
    "${WP_CONTENT_DIR}/plugins/"
fi

if [ -d "${DOWNLOADS_DIR}/uploads" ]; then
  rsync -a --delete \
    "${EXCLUDED_RSYNC_ARGS[@]}" \
    "${UPLOADS_RSYNC_EXCLUDES[@]}" \
    "${DOWNLOADS_DIR}/uploads/" \
    "${WP_CONTENT_DIR}/uploads/"
fi

echo "Runtime local sincronizado em ${WP_CONTENT_DIR}"
