#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

STAGING_SSH_HOST="${STAGING_SSH_HOST:?Defina STAGING_SSH_HOST}"
STAGING_SSH_USER="${STAGING_SSH_USER:?Defina STAGING_SSH_USER}"
STAGING_PATH="${STAGING_PATH:-/home2/uonix/qa_uonix/wp-content/themes/kadence-child}"
SSH_KEY="${SSH_KEY:-$HOME/.ssh/id_rsa}"

MODE="${1:---dry-run}"

if [ "${MODE}" != "--dry-run" ] && [ "${MODE}" != "--apply" ]; then
  echo "Uso: $0 [--dry-run|--apply]"
  exit 1
fi

args=(-avz --delete --exclude='.DS_Store' --exclude='._*')

if [ "${MODE}" = "--dry-run" ]; then
  args+=(--dry-run)
fi

rsync "${args[@]}" \
  -e "ssh -i ${SSH_KEY}" \
  "${STAGING_SSH_USER}@${STAGING_SSH_HOST}:${STAGING_PATH}/" \
  "${ROOT_DIR}/themes/kadence-child/"
