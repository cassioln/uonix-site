#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP_DIR="${ROOT_DIR}/backups"
STAMP="$(date +%Y%m%d-%H%M%S)"

mkdir -p "${BACKUP_DIR}"

tar -czf "${BACKUP_DIR}/kadence-child-${STAMP}.tar.gz" \
  -C "${ROOT_DIR}/themes" \
  kadence-child

echo "${BACKUP_DIR}/kadence-child-${STAMP}.tar.gz"
