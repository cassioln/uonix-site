#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOWNLOADS_DIR="${DOWNLOADS_DIR:-$HOME/Downloads}"
WP_CONTENT_DIR="${ROOT_DIR}/local/wp-content"

if [ -d "${DOWNLOADS_DIR}/plugins" ]; then
  rsync -a --delete \
    --exclude='.DS_Store' \
    "${DOWNLOADS_DIR}/plugins/" \
    "${WP_CONTENT_DIR}/plugins/"
fi

if [ -d "${DOWNLOADS_DIR}/uploads" ]; then
  rsync -a --delete \
    --exclude='.DS_Store' \
    --exclude='curriculos-recebidos/' \
    --exclude='gosmtp-attachments/' \
    --exclude='wc-logs/' \
    --exclude='wp-personal-data-exports/' \
    --exclude='wp-staging/' \
    --exclude='wpmc-trash/' \
    --exclude='*.log' \
    "${DOWNLOADS_DIR}/uploads/" \
    "${WP_CONTENT_DIR}/uploads/"
fi

echo "Runtime local sincronizado em ${WP_CONTENT_DIR}"
