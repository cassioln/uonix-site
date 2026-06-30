#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOWNLOADS_DIR="${DOWNLOADS_DIR:-$HOME/Downloads}"
WP_CONTENT_DIR="${ROOT_DIR}/local/wp-content"

if [ -d "${DOWNLOADS_DIR}/plugins" ]; then
  rsync -a --delete \
    --exclude='.DS_Store' \
    --exclude='*~' \
    --exclude='*.log' \
    --exclude='logs/' \
    --exclude='all-in-one-wp-migration-10GB/' \
    --exclude='backuply/' \
    --exclude='backuply-pro/' \
    --exclude='fluent-smtp/' \
    --exclude='fluentform/' \
    --exclude='gosmtp/' \
    --exclude='gosmtp-pro/' \
    --exclude='loginizer/' \
    --exclude='loginizer-security/' \
    --exclude='speedycache/' \
    --exclude='speedycache-pro/' \
    --exclude='wp-mail-logging/' \
    --exclude='wp-staging/' \
    --exclude='wpvivid-backuprestore/' \
    "${DOWNLOADS_DIR}/plugins/" \
    "${WP_CONTENT_DIR}/plugins/"
fi

if [ -d "${DOWNLOADS_DIR}/uploads" ]; then
  rsync -a --delete \
    --exclude='.DS_Store' \
    --exclude='*~' \
    --exclude='*.log' \
    --exclude='curriculos-recebidos/' \
    --exclude='FLUENT_PDF_TEMPLATES/' \
    --exclude='gosmtp-attachments/' \
    --exclude='loginizer-config/' \
    --exclude='speedycache-binary/' \
    --exclude='wc-logs/' \
    --exclude='wp-personal-data-exports/' \
    --exclude='wp-staging/' \
    --exclude='wpvivid_uploads/' \
    --exclude='wpvividbackups/' \
    --exclude='wpvivid_staging/' \
    --exclude='wpmc-trash/' \
    --exclude='logs/' \
    "${DOWNLOADS_DIR}/uploads/" \
    "${WP_CONTENT_DIR}/uploads/"
fi

echo "Runtime local sincronizado em ${WP_CONTENT_DIR}"
