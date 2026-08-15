#!/usr/bin/env bash
# Executa os blocos Bash remotos do workflow reutilizável contra um document_root
# sintético, em vez de apenas procurar strings no YAML.
#
# Motivação (achado GAP-2 da revisão independente do PR #5): a suíte anterior era
# inteiramente grep/regex sobre o texto do YAML, então defeitos de COMPORTAMENTO
# passavam verdes — rollback incompleto, allowlist vazia abortando sob `set -u` e
# re-parse de argv pelo shell remoto. Este teste extrai os heredocs REMOTE e os
# roda de verdade.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKFLOW="$ROOT_DIR/.github/workflows/_deploy-hostgator.yml"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT
chmod 700 "$TMP_DIR"

fail() {
  printf 'FAIL: %s\n' "$1" >&2
  exit 1
}

# Extrai o corpo de um heredoc REMOTE pertencente a um step nomeado.
# Uso: extract_remote <nome do step> <ocorrência 1-based> <arquivo de saída>
extract_remote() {
  python3 - "$WORKFLOW" "$1" "$2" "$3" <<'PY'
import re
import sys
import textwrap

workflow, step_name, occurrence, destination = sys.argv[1:5]
lines = open(workflow, encoding='utf-8').read().splitlines()

start = next(
    index for index, line in enumerate(lines)
    if line.strip() == f'- name: {step_name}'
)
end = len(lines)
for index in range(start + 1, len(lines)):
    if lines[index].lstrip().startswith('- name:'):
        end = index
        break

body = lines[start:end]
blocks = []
current = None
for line in body:
    if current is None:
        if re.search(r"<<'REMOTE'\s*$", line):
            current = []
        continue
    if line.strip() == 'REMOTE':
        blocks.append(current)
        current = None
        continue
    current.append(line)

wanted = int(occurrence)
if len(blocks) < wanted:
    raise SystemExit(f'heredoc REMOTE #{wanted} não encontrado em "{step_name}"')

with open(destination, 'w', encoding='utf-8') as handle:
    handle.write(textwrap.dedent('\n'.join(blocks[wanted - 1])) + '\n')
PY
}

backup_block="$TMP_DIR/backup.sh"
publish_block="$TMP_DIR/publish.sh"
rollback_block="$TMP_DIR/rollback.sh"
extract_remote 'Back up managed remote paths' 1 "$backup_block"
extract_remote 'Publish only managed paths and verify manifest' 1 "$publish_block"
extract_remote 'Roll back managed code after failure' 1 "$rollback_block"

for block in "$backup_block" "$publish_block" "$rollback_block"; do
  bash -n "$block" || fail "bloco remoto com sintaxe inválida: $block"
done

# Monta um document_root sintético. `extra` fica FORA da allowlist de propósito.
make_root() {
  local root="$1"
  rm -rf "$root"
  mkdir -p "$root/wp-content/themes/kadence-child" "$root/wp-content/mu-plugins"
  printf 'theme\n' > "$root/wp-content/themes/kadence-child/style.css"
  printf 'core\n' > "$root/wp-content/mu-plugins/uonix-core.php"
  local module
  for module in "${@:2}"; do
    mkdir -p "$root/wp-content/mu-plugins/$module"
    printf '%s\n' "$module" > "$root/wp-content/mu-plugins/$module/module.php"
  done
}

# --- Backup: inventaria o delta e nunca remove nada -------------------------
root="$TMP_DIR/root-backup"
make_root "$root" uonix-shared uonix-security uonix-local
home="$TMP_DIR/home-backup"
mkdir -p "$home"
# TG-2 (revisão independente, rodada 2): rodar com umask PERMISSIVO de propósito.
# O teste extrai o heredoc e o executa herdando o umask do shell chamador; sob um
# umask restritivo a asserção de permissão passaria mesmo que o bloco perdesse o
# `umask 077`, medindo o ambiente em vez do código. Com 0022 a proteção só pode
# vir do próprio bloco remoto.
HOME="$home" bash -c 'umask 0022; exec bash "$0" "$@"' "$backup_block" "$root" 'backups/qa/run1' uonix-shared uonix-security \
  > "$TMP_DIR/backup.out" 2>&1 || fail "bloco de backup falhou: $(cat "$TMP_DIR/backup.out")"

inventory="$home/backups/qa/run1/orphan-inventory.txt"
[ -f "$inventory" ] || fail 'orphan-inventory.txt não foi criado'
[ "$(cat "$inventory")" = 'uonix-local' ] \
  || fail "inventário do delta incorreto: $(cat "$inventory")"
grep -q 'orphan_inventory=1' "$TMP_DIR/backup.out" \
  || fail 'contador do inventário não reportado'
[ -d "$root/wp-content/mu-plugins/uonix-local" ] \
  || fail 'backup removeu um módulo do destino'
[ -f "$home/backups/qa/run1/managed/mu-plugins/uonix-local/module.php" ] \
  || fail 'módulo fora da allowlist não foi copiado para o backup'

# O inventário lista órfãos do ambiente e não deve nascer legível para outros
# usuários: o destino é hospedagem compartilhada. Só os bits de owner são aceitos.
mode="$(stat -c '%a' "$inventory" 2>/dev/null || stat -f '%Lp' "$inventory")"
mode="$(printf '%03d' "$mode")"
[ "${mode:1:2}" = '00' ] \
  || fail "orphan-inventory.txt legível ou gravável para grupo/outros (modo $mode)"
dir_mode="$(stat -c '%a' "$home/backups/qa/run1" 2>/dev/null || stat -f '%Lp' "$home/backups/qa/run1")"
dir_mode="$(printf '%03d' "$dir_mode")"
[ "${dir_mode:1:2}" = '00' ] \
  || fail "diretório de backup acessível a grupo/outros (modo $dir_mode)"

# --- Publicação: preserva o que está fora da allowlist ----------------------
root="$TMP_DIR/root-publish"
make_root "$root" uonix-shared uonix-local
bash "$publish_block" "$root" uonix-shared > "$TMP_DIR/publish.out" 2>&1 \
  || fail "bloco de publicação falhou: $(cat "$TMP_DIR/publish.out")"
[ -d "$root/wp-content/mu-plugins/uonix-local" ] \
  || fail 'publicação REMOVEU módulo fora da allowlist'
grep -q 'preserve_orphan_modules=uonix-local' "$TMP_DIR/publish.out" \
  || fail 'módulo preservado não reportado'
grep -q 'UONIX_PRESERVED_MODULES=1' "$TMP_DIR/publish.out" \
  || fail 'contador de preservados incorreto'

# --- Allowlist vazia: deve falhar de forma explícita, não silenciosa --------
# Um bundle sem módulos indica bundle corrompido. Tratar como zero módulos
# esperados faria a política considerar TODOS os módulos remotos como órfãos.
root="$TMP_DIR/root-empty"
make_root "$root" uonix-shared
home="$TMP_DIR/home-empty"
mkdir -p "$home"
if HOME="$home" bash "$backup_block" "$root" 'backups/qa/run2' > "$TMP_DIR/empty-backup.out" 2>&1; then
  fail 'backup aceitou allowlist vazia em vez de falhar fechado'
fi
grep -qi 'allowlist' "$TMP_DIR/empty-backup.out" \
  || fail "falha com allowlist vazia sem mensagem acionável: $(cat "$TMP_DIR/empty-backup.out")"
if bash "$publish_block" "$root" > "$TMP_DIR/empty-publish.out" 2>&1; then
  fail 'publicação aceitou allowlist vazia em vez de falhar fechado'
fi
[ -d "$root/wp-content/mu-plugins/uonix-shared" ] \
  || fail 'falha com allowlist vazia removeu módulo do destino'

# --- Rollback: reverte módulo NOVO publicado por este deploy ----------------
# Regressão LOG-1: iterar apenas sobre o backup deixa módulos recém-criados no
# destino. O rollback precisa considerar a allowlist, não só o que foi salvo.
root="$TMP_DIR/root-rollback"
make_root "$root" uonix-shared uonix-local
home="$TMP_DIR/home-rollback"
mkdir -p "$home"
HOME="$home" bash "$backup_block" "$root" 'backups/qa/run3' uonix-shared uonix-novo \
  > /dev/null 2>&1 || fail 'backup do cenário de rollback falhou'

# Simula a publicação: altera um módulo existente e cria um módulo novo.
printf 'publicado\n' > "$root/wp-content/mu-plugins/uonix-shared/module.php"
mkdir -p "$root/wp-content/mu-plugins/uonix-novo"
printf 'novo\n' > "$root/wp-content/mu-plugins/uonix-novo/module.php"

# O bloco de rollback chama `wp` no host remoto; aqui ele é stubbado para que o
# teste exercite a lógica de arquivos, não o WP-CLI.
stub_bin="$TMP_DIR/stub-bin"
mkdir -p "$stub_bin"
printf '#!/bin/sh\nexit 0\n' > "$stub_bin/wp"
chmod +x "$stub_bin/wp"

HOME="$home" PATH="$stub_bin:$PATH" bash "$rollback_block" "$root" 'backups/qa/run3' uonix-shared uonix-novo \
  > "$TMP_DIR/rollback.out" 2>&1 || fail "bloco de rollback falhou: $(cat "$TMP_DIR/rollback.out")"

[ ! -e "$root/wp-content/mu-plugins/uonix-novo" ] \
  || fail 'rollback NÃO removeu módulo novo publicado por este deploy (reversão incompleta)'
[ "$(cat "$root/wp-content/mu-plugins/uonix-shared/module.php")" = 'uonix-shared' ] \
  || fail 'rollback não restaurou o conteúdo original do módulo'
[ -d "$root/wp-content/mu-plugins/uonix-local" ] \
  || fail 'rollback removeu módulo fora da allowlist'

# --- Nomes degenerados: rejeitados; nomes legítimos com ponto: aceitos ------
# TG-4 (revisão independente, rodada 3): a rejeição de `uonix-.` e `uonix-..`
# (SUG-1) não tinha nenhuma asserção, logo era reversível sem quebrar a suíte.
# Estes nomes alimentam `rm -rf`, então a rejeição precisa ser verificada — e o
# oposto também: nomes legítimos com ponto NÃO podem sofrer falso positivo.
root="$TMP_DIR/root-degenerate"
home="$TMP_DIR/home-degenerate"
run=0
for degenerate in 'uonix-.' 'uonix-..' 'uonix-'; do
  run=$((run + 1))
  make_root "$root" uonix-shared
  rm -rf "$home"
  mkdir -p "$home"
  if HOME="$home" bash "$backup_block" "$root" "backups/qa/deg$run" "$degenerate" \
      > "$TMP_DIR/degenerate.out" 2>&1; then
    fail "backup aceitou nome degenerado: $degenerate"
  fi
  grep -qiE 'inválido|invalido' "$TMP_DIR/degenerate.out" \
    || fail "nome degenerado '$degenerate' rejeitado sem mensagem clara"
done

make_root "$root" uonix-a.b uonix-x..y uonix-v1.2.3
rm -rf "$home"
mkdir -p "$home"
HOME="$home" bash "$backup_block" "$root" 'backups/qa/legit' uonix-a.b uonix-x..y uonix-v1.2.3 \
  > "$TMP_DIR/legit.out" 2>&1 \
  || fail "nomes legítimos com ponto foram rejeitados: $(cat "$TMP_DIR/legit.out")"
legit_inventory="$home/backups/qa/legit/orphan-inventory.txt"
# VAC-1/TG-7: `[ "$(cat X)" = '' ]` PASSA quando X não existe — `set -e` não aborta
# dentro de `$(...)` em `[ ]`. Sem este `-f` a asserção era vacuamente verdadeira.
[ -f "$legit_inventory" ] \
  || fail 'inventário não foi gerado para nomes legítimos (asserção seria vacuosa)'
[ "$(cat "$legit_inventory")" = '' ] \
  || fail 'nomes legítimos foram classificados como órfãos'

# --- Nome de módulo hostil: sem re-parse pelo shell remoto -----------------
# SEC-1: `ssh ... bash -s -- a b c` entrega os argumentos concatenados e o shell
# remoto os re-parseia. Um nome com `;` executaria comando arbitrário.
root="$TMP_DIR/root-hostile"
make_root "$root" uonix-shared
sentinel="$TMP_DIR/INJETADO"
rm -f "$sentinel"
home="$TMP_DIR/home-hostile"
mkdir -p "$home"
if HOME="$home" bash "$backup_block" "$root" 'backups/qa/run4' \
    "uonix-x;touch $sentinel" > "$TMP_DIR/hostile.out" 2>&1; then
  fail 'backup aceitou nome de módulo hostil'
fi
[ ! -e "$sentinel" ] || fail 'INJEÇÃO DE COMANDO: nome de módulo hostil foi executado'
grep -qiE 'inválido|invalido' "$TMP_DIR/hostile.out" \
  || fail "nome hostil rejeitado sem mensagem clara: $(cat "$TMP_DIR/hostile.out")"

# --- Integridade ESTRUTURAL dos blocos remotos ------------------------------
# STRUCT-1..4: as asserções acima executam os blocos contra fixtures e validam o
# RESULTADO. Isso deixa passar quebras que não alteram o comportamento observável
# naquele fixture específico. Três casos reais, todos comprovados por mutação em
# 2026-08-15 (o teste passava com o workflow quebrado):
#
#   a) `set -euo pipefail` -> `set -u`: só se manifesta quando um comando falha
#      no meio de um bloco. Sem `-e`, um deploy segue após erro parcial.
#   b) guardas `if [ "${#allowlist[@]}" -eq 0 ]` -> `if false`: só se manifesta
#      com allowlist vazia. É proteção contra operação destrutiva sem allowlist.
#   c) duas linhas colapsadas numa só (`var="$2"          comando`): o fixture
#      não exercita a variável perdida, e o YAML continua válido.
#
# O caso (c) não é hipotético: aconteceu ao remover código morto de cache. Sete
# suítes passaram com o bug presente; foi encontrado por leitura do diff.
#
# Estas asserções inspecionam o TEXTO dos blocos extraídos, não o resultado da
# execução, e por isso pegam as três classes.

assert_block_structure() {
  local label="$1" block="$2"

  # STRUCT-1: modo estrito completo. `set -u` sozinho não basta: sem `-e` o bloco
  # continua após falha, e sem `pipefail` um erro no meio de pipe fica invisível.
  grep -qE '^\s*set -euo pipefail\s*$' "$block" \
    || fail "$label: bloco remoto sem 'set -euo pipefail' (modo estrito incompleto)"

  # STRUCT-2: nenhuma linha com dois comandos colapsados. Heurística conservadora:
  # atribuição simples seguida de 2+ espaços e outro token de comando na mesma
  # linha. Não casa com `export A=1 B=2`, `var=$(cmd arg)` nem `A=1 cmd` (prefixo
  # de ambiente com 1 espaço), que são construções legítimas.
  if grep -nE '^\s*[A-Za-z_][A-Za-z0-9_]*="?\$[0-9]"?\s{2,}\S' "$block" > "$TMP_DIR/collapsed.out"; then
    fail "$label: linha com dois comandos colapsados: $(cat "$TMP_DIR/collapsed.out")"
  fi

  # STRUCT-3: o heredoc não pode vir vazio nem truncado por remoção acidental.
  [ "$(wc -l < "$block" | tr -d ' ')" -ge 5 ] \
    || fail "$label: bloco remoto suspeito de truncamento ($(wc -l < "$block" | tr -d ' ') linhas)"
}

assert_block_structure 'backup'   "$backup_block"
assert_block_structure 'publish'  "$publish_block"
assert_block_structure 'rollback' "$rollback_block"

# STRUCT-4: cada bloco deve CONTER a guarda de coleção vazia. O comportamento
# fail-closed em si já é exercitado acima (linhas ~146 e ~151, com backup e
# publish recebendo allowlist vazia). Aqui a asserção é de presença: se alguém
# remover a guarda de um bloco, ou trocá-la por `if false`, o texto do `if`
# permanece — por isso a checagem de presença é complementar, não substituta,
# da execução fail-closed. As assinaturas de argumento diferem entre os blocos
# (publish recebe só o root; backup e rollback recebem root + caminho), então
# não é possível dirigi-los todos com a mesma chamada genérica.
for pair in "backup:$backup_block" "publish:$publish_block" "rollback:$rollback_block"; do
  label="${pair%%:*}"
  block="${pair#*:}"
  grep -qE 'eq 0 \]' "$block" \
    || fail "$label: bloco remoto sem guarda de coleção vazia (allowlist/expected_modules)"
done

printf 'PASS: blocos remotos de backup, publicação e rollback executam com allowlist, preservação, reversão completa, rejeição de nome hostil e integridade estrutural (modo estrito, linhas não colapsadas, guardas fail-closed).\n'
