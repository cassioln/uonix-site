#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
README="${ROOT_DIR}/local/README.md"

fail() {
  printf 'FAIL: %s\n' "$*" >&2
  exit 1
}

[ -f "$README" ] || fail 'README local ausente'

if grep -Eq 'mariadb[^\n]*[[:space:]]-p[^[:space:]]+' "$README"; then
  fail 'README local ainda passa senha MariaDB em argv'
fi

grep -Fq "read -rsp 'Senha" "$README" || fail 'README precisa ler a senha local sem eco'
grep -Fq 'podman exec -e MYSQL_PWD' "$README" || fail 'README precisa encaminhar somente o nome MYSQL_PWD ao container'
grep -Fq 'unset MYSQL_PWD' "$README" || fail 'README precisa limpar MYSQL_PWD após cada uso'

printf 'PASS: README local não expõe senha MariaDB em argv.\n'
