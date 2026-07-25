#!/usr/bin/env bash
set -euo pipefail

printf '%s\n' \
  'DEPRECADO: sync-from-staging.sh é inerte e não executa operações.' \
  'Use os workflows canônicos aprovados ou revise scripts/clone-environment.sh --dry-run.' >&2
exit 64