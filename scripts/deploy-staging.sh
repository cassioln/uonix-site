#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

STAGING_SSH_HOST="${STAGING_SSH_HOST:?Defina STAGING_SSH_HOST}"
STAGING_SSH_USER="${STAGING_SSH_USER:?Defina STAGING_SSH_USER}"
STAGING_PATH="${STAGING_PATH:-/home2/uonix/qa_uonix/wp-content/themes/kadence-child}"
SSH_KEY="${SSH_KEY:-$HOME/.ssh/id_rsa}"

rsync -avz --delete \
  --exclude='.DS_Store' \
  --exclude='._*' \
  -e "ssh -i ${SSH_KEY}" \
  "${ROOT_DIR}/themes/kadence-child/" \
  "${STAGING_SSH_USER}@${STAGING_SSH_HOST}:${STAGING_PATH}/"
