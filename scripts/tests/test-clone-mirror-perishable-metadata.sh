#!/usr/bin/env bash
# Espelho do clone: diretório que só existe no destino e contém apenas
# metadados (.DS_Store, ._*, *~, *.log) precisa ser removido (#396), sem que as
# exclusões de diretório deixem de proteger o destino.
#
# Exercita bridge_upload_payload, a mesma função que o clone usa para enviar o
# payload ao destino, com destino `local` (rsync local, sem SSH). No Mac roda
# com openrsync; no CI, com GNU rsync.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLONE_SCRIPT="$ROOT_DIR/scripts/clone-environment.sh"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT HUP INT TERM

fail() {
  printf 'FAIL: %s\n' "$*" >&2
  exit 1
}

export UONIX_CLONE_LIBRARY_ONLY=1
# shellcheck source=scripts/clone-environment.sh
source "$CLONE_SCRIPT"

payload="$TMP_DIR/payload"
target="$TMP_DIR/target"

mkdir -p "$payload/mantido" "$target/mantido"
printf '<?php\n' > "$payload/mantido/plugin.php"
printf 'origem\n' > "$payload/.DS_Store"

# Diretórios fantasmas: só no destino, só com metadados.
# Nomes neutros de propósito: um diretório chamado `x~` ou `x.log` casaria ele
# mesmo com o padrão e ficaria protegido, mascarando o que se quer medir.
n=0
for padrao in .DS_Store ._icone 'nota~' debug.log; do
  n=$((n + 1))
  mkdir -p "$target/fantasma-$n"
  printf 'x\n' > "$target/fantasma-$n/$padrao"
done
mkdir -p "$target/fantasma-aninhado/sub"
printf 'x\n' > "$target/fantasma-aninhado/sub/.DS_Store"

# Metadado do destino em diretório que continua existindo: não é apagado.
printf 'destino\n' > "$target/mantido/.DS_Store"
# Exclusões de diretório e de plugin: só no destino, precisam sobreviver.
mkdir -p "$target/cache" "$target/fluentform"
printf 'x\n' > "$target/cache/pagina.html"
printf '<?php\n' > "$target/fluentform/fluentform.php"
# Arquivo comum só no destino: o espelho remove.
printf 'x\n' > "$target/sobra.php"

bridge_upload_payload local "$payload" "$target" plugins \
  || fail 'bridge_upload_payload falhou'

for fantasma in fantasma-1 fantasma-2 fantasma-3 fantasma-4 fantasma-aninhado; do
  [ ! -e "$target/$fantasma" ] || fail "diretório só com metadados sobreviveu ao espelho: $fantasma"
done

[ -f "$target/mantido/plugin.php" ] || fail 'arquivo da origem não foi copiado'
[ "$(cat "$target/mantido/.DS_Store")" = destino ] \
  || fail '.DS_Store do destino em diretório mantido foi apagado ou sobrescrito'
[ ! -e "$target/.DS_Store" ] || fail '.DS_Store da origem foi copiado'
[ -f "$target/cache/pagina.html" ] || fail 'exclusão de diretório cache/ deixou de proteger o destino'
[ -f "$target/fluentform/fluentform.php" ] || fail 'exclusão de plugin fluentform/ deixou de proteger o destino'
[ ! -e "$target/sobra.php" ] || fail 'arquivo comum só no destino não foi removido'

printf 'OK: espelho remove diretórios só com metadados e preserva as exclusões de diretório\n'
