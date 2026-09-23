#!/usr/bin/env bash
# Contrato de CÓDIGO DE SAÍDA do passo `Roll back managed code after failure`.
#
# MOTIVAÇÃO (defeito real, run 35784786643 de 2026-09-22): o deploy falhou no passo
# 10 (`Back up the production database`), antes de qualquer publicação. O rollback
# imprimiu as três linhas certas — "nenhuma mutação foi iniciada; nada a restaurar",
# a sondagem de indexação, e "não encontrou marcador de código ou reconfiguração" —
# e então saiu 1. O run mostrou dois jobs vermelhos com produção INTOCADA, e a
# leitura natural foi "o deploy quebrou e o rollback também".
#
# A causa não estava no `if` que imprime "nada a restaurar": aquele `exit 0` encerra
# apenas o script REMOTO. O runner seguia adiante, sondava HTTP e chamava o heredoc
# de limpeza, que exige um marcador desta execução; sem publicação não há marcador,
# e ele reprovava — corretamente, para o caso dele. O que faltava era o desfecho
# chegar ao runner. Hoje o bloco remoto sai 32 (ROLLBACK_NOT_NEEDED) e o runner
# traduz esse — e SOMENTE esse — código em sucesso.
#
# Por que não `|| true`: isso apagaria o quarto desfecho da tabela, que é o único
# que deve reprovar (rollback tentado e falhado). Este teste fixa os quatro:
#
#   | situação                                         | esperado |
#   |--------------------------------------------------|----------|
#   | nada preparado (sem BACKUP_DIR)                   | 0        |
#   | preparado, nenhuma mutação (remoto sai 32)        | 0        |
#   | havia mutação e o rollback a desfez               | 0        |
#   | havia mutação e o rollback NÃO conseguiu desfazer | != 0     |
#
# O teste executa o corpo REAL do step (extraído do YAML) com `sshpass` e `curl`
# falsos, então ele mede o fluxo do runner, não uma reimplementação dele.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
WORKFLOW="$ROOT_DIR/.github/workflows/deploy-production.yml"
TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/uonix-rollback-exit.XXXXXX")"
trap 'rm -rf "$TMP_DIR"' EXIT HUP INT TERM

fail() {
  printf 'FAIL: %s\n' "$*" >&2
  exit 1
}

[ -f "$WORKFLOW" ] || fail 'deploy-production.yml não encontrado.'

STEP_BODY="$TMP_DIR/rollback-step.sh"
python3 - "$WORKFLOW" 'Roll back managed code after failure' "$STEP_BODY" <<'PY'
import re
import sys

workflow, step_name, destination = sys.argv[1:4]
lines = open(workflow, encoding='utf-8').read().splitlines()

name_index = next(
    index for index, line in enumerate(lines)
    if line.strip() == f'- name: {step_name}'
)
for index in range(name_index + 1, len(lines)):
    if lines[index].lstrip().startswith('- name:'):
        raise SystemExit(f'step sem run literal: {step_name}')
    match = re.match(r'^(\s*)run:\s*\|\s*$', lines[index])
    if not match:
        continue
    run_indent = len(match.group(1))
    body = []
    for body_line in lines[index + 1:]:
        indentation = len(body_line) - len(body_line.lstrip())
        if body_line.strip() and indentation <= run_indent:
            break
        body.append(body_line[run_indent + 2:] if body_line.strip() else '')
    with open(destination, 'w', encoding='utf-8') as handle:
        handle.write('\n'.join(body) + '\n')
    break
PY

[ -s "$STEP_BODY" ] || fail 'não foi possível extrair o corpo do step de rollback.'
bash -n "$STEP_BODY" || fail 'corpo extraído do step de rollback tem sintaxe inválida.'

# O corpo precisa ter mesmo os dois heredocs remotos, senão as asserções abaixo
# mediriam um script truncado e passariam por motivo errado.
remote_blocks="$(grep -c "^REMOTE$" "$STEP_BODY" || true)"
[ "$remote_blocks" -eq 2 ] \
  || fail "esperados 2 heredocs REMOTE no corpo extraído, encontrados $remote_blocks"

# --- mocks -------------------------------------------------------------------
BIN_DIR="$TMP_DIR/bin"
mkdir -p "$BIN_DIR"

# sshpass falso: consome o heredoc (como o ssh real faria), conta a invocação e
# devolve o código configurado para ELA. Assim distinguimos "primeiro bloco
# remoto" de "heredoc de limpeza pós-smoke".
cat > "$BIN_DIR/sshpass" <<'MOCK'
#!/usr/bin/env bash
set -u
cat > /dev/null
n=0
[ -f "$MOCK_SSH_COUNT" ] && n="$(cat "$MOCK_SSH_COUNT")"
n=$((n + 1))
printf '%s\n' "$n" > "$MOCK_SSH_COUNT"
name="MOCK_SSH_EXIT_$n"
exit "${!name:-0}"
MOCK

# curl falso: materializa o arquivo de cabeçalhos e ecoa a URL efetiva, que o
# corpo compara com "$TARGET_URL/".
cat > "$BIN_DIR/curl" <<'MOCK'
#!/usr/bin/env bash
set -u
printf 'curl\n' >> "$MOCK_CURL_LOG"
headers=''
url=''
while [ "$#" -gt 0 ]; do
  case "$1" in
    --dump-header) headers="$2"; shift 2 ;;
    http*) url="$1"; shift ;;
    *) shift ;;
  esac
done
[ -n "$headers" ] && printf '%s\n' "${MOCK_RESPONSE_HEADERS:-HTTP/1.1 200 OK}" > "$headers"
printf '%s' "$url"
MOCK

# mktemp com template explícito. NÃO é mock de comportamento: o corpo do step usa
# `mktemp` sem template para o arquivo de cabeçalhos, e o mktemp do BSD/macOS ignora
# TMPDIR nesse caso, usando _CS_DARWIN_USER_TEMP_DIR. Num ambiente de
# desenvolvimento com escrita restrita a TMPDIR isso reprovaria o teste por motivo
# alheio ao que ele mede — o roteamento de código de saída. No CI (Linux/GNU) o
# caminho nativo já funciona e este wrapper apenas delega.
cat > "$BIN_DIR/mktemp" <<'MOCK'
#!/usr/bin/env bash
set -u
real="$(PATH=/usr/bin:/bin command -v mktemp)"
for argument in "$@"; do
  case "$argument" in
    -*) ;;
    *) exec "$real" "$@" ;;
  esac
done
exec "$real" "$@" "${TMPDIR:-/tmp}/uonix-rollback-headers.XXXXXX"
MOCK

chmod 755 "$BIN_DIR/sshpass" "$BIN_DIR/curl" "$BIN_DIR/mktemp"

export LOCAWEB_SSH_PORT='22'
export LOCAWEB_SSH_USER='siteuonix1'
export LOCAWEB_SSH_HOST='ftp.example.invalid'
export LOCAWEB_DOCUMENT_ROOT="$TMP_DIR/document-root"
export LOCAWEB_PHP_BIN="$TMP_DIR/bin/php-fake"
export LOCAWEB_WP_BIN="$TMP_DIR/bin/wp-fake"
export TARGET_URL='https://prod.example.invalid'
export DEPLOY_RUN_ID='runtime-42'
mkdir -p "$LOCAWEB_DOCUMENT_ROOT"

# Uso: run_case <rótulo> <BACKUP_DIR> <exit do 1º ssh> <exit do 2º ssh> [cabeçalhos]
# Exporta: CASE_STATUS, CASE_OUTPUT, CASE_SSH_CALLS, CASE_CURL_CALLS
run_case() {
  local label="$1" backup_dir="$2" first="$3" second="$4"
  local headers="${5:-HTTP/1.1 200 OK}"
  local case_dir="$TMP_DIR/case-$label"
  mkdir -p "$case_dir"
  MOCK_SSH_COUNT="$case_dir/ssh.count"
  MOCK_CURL_LOG="$case_dir/curl.log"
  : > "$MOCK_SSH_COUNT"
  printf '0\n' > "$MOCK_SSH_COUNT"
  : > "$MOCK_CURL_LOG"
  set +e
  CASE_OUTPUT="$(
    PATH="$BIN_DIR:$PATH" \
    BACKUP_DIR="$backup_dir" \
    MOCK_SSH_COUNT="$MOCK_SSH_COUNT" \
    MOCK_CURL_LOG="$MOCK_CURL_LOG" \
    MOCK_SSH_EXIT_1="$first" \
    MOCK_SSH_EXIT_2="$second" \
    MOCK_RESPONSE_HEADERS="$headers" \
    bash "$STEP_BODY" 2>&1
  )"
  CASE_STATUS=$?
  set -e
  CASE_SSH_CALLS="$(cat "$MOCK_SSH_COUNT")"
  CASE_CURL_CALLS="$(wc -l < "$MOCK_CURL_LOG" | tr -d ' ')"
}

# --- Desfecho 1: nada foi preparado (sem BACKUP_DIR) -> sucesso, zero conexões --
run_case 'sem-backup' '' 0 0
[ "$CASE_STATUS" -eq 0 ] \
  || fail "falha antes de qualquer preparação deveria sair 0, saiu $CASE_STATUS"
[ "$CASE_SSH_CALLS" -eq 0 ] \
  || fail "sem backup o rollback não deve abrir conexão; abriu $CASE_SSH_CALLS"
printf '%s' "$CASE_OUTPUT" | grep -q 'Nenhum backup foi criado' \
  || fail "sem backup o rollback não explicou o desfecho: $CASE_OUTPUT"

# --- Desfecho 2: preparado, NENHUMA mutação (remoto sai 32) -> sucesso ---------
# O coração da #273. Além do código 0, exigimos que o passo PARE ali: nem sondagem
# HTTP nem heredoc de limpeza. Se ele seguisse, a limpeza reprovaria por não achar
# marcador — que é exatamente o defeito original.
run_case 'preparado-sem-mutacao' "$TMP_DIR/backups/run-1" 32 0
[ "$CASE_STATUS" -eq 0 ] \
  || fail "preparação sem mutação deveria sair 0, saiu $CASE_STATUS (saída: $CASE_OUTPUT)"
[ "$CASE_SSH_CALLS" -eq 1 ] \
  || fail "preparação sem mutação deveria parar após o 1º bloco remoto; houve $CASE_SSH_CALLS conexões"
[ "$CASE_CURL_CALLS" -eq 0 ] \
  || fail 'preparação sem mutação sondou HTTP; não há nada publicado para validar'
printf '%s' "$CASE_OUTPUT" | grep -q 'rollback dispensado' \
  || fail "preparação sem mutação não produziu mensagem explícita: $CASE_OUTPUT"

# --- Desfecho 3: havia mutação e o rollback a desfez -> sucesso ---------------
run_case 'mutacao-revertida' "$TMP_DIR/backups/run-2" 0 0
[ "$CASE_STATUS" -eq 0 ] \
  || fail "rollback completo deveria sair 0, saiu $CASE_STATUS (saída: $CASE_OUTPUT)"
[ "$CASE_SSH_CALLS" -eq 2 ] \
  || fail "rollback completo precisa das duas conexões (reversão e limpeza); houve $CASE_SSH_CALLS"
[ "$CASE_CURL_CALLS" -ge 1 ] \
  || fail 'rollback completo não sondou HTTP; a prova externa faz parte do contrato'
printf '%s' "$CASE_OUTPUT" | grep -q 'Rollback validado' \
  || fail "rollback completo não confirmou validação: $CASE_OUTPUT"

# --- Desfecho 4a: rollback necessário falhou no bloco remoto -> reprova -------
run_case 'reversao-falhou' "$TMP_DIR/backups/run-3" 1 0
[ "$CASE_STATUS" -ne 0 ] \
  || fail 'rollback que falhou na reversão saiu 0; o único desfecho vermelho foi apagado'
[ "$CASE_SSH_CALLS" -eq 1 ] \
  || fail "reversão falha não pode seguir para a limpeza; houve $CASE_SSH_CALLS conexões"
[ "$CASE_CURL_CALLS" -eq 0 ] \
  || fail 'reversão falha sondou HTTP como se houvesse estado válido a validar'

# --- Desfecho 4b: reversão ok, limpeza pós-smoke falhou -> reprova ------------
run_case 'limpeza-falhou' "$TMP_DIR/backups/run-4" 0 1
[ "$CASE_STATUS" -ne 0 ] \
  || fail 'falha na limpeza pós-smoke foi convertida em sucesso; o lock ficaria ambíguo'

# --- Desfecho 4c: X-Robots-Tag restritivo -> reprova -------------------------
# Espelha o smoke: um rollback que valide MENOS declararia sucesso num estado que
# o smoke reprovaria.
run_case 'robots-restritivo' "$TMP_DIR/backups/run-5" 0 0 \
  'X-Robots-Tag: noindex, nofollow'
[ "$CASE_STATUS" -ne 0 ] \
  || fail 'rollback aceitou X-Robots-Tag restritivo após o cutover'

# --- O mapeamento 32 -> 0 precisa ser EXATO ----------------------------------
# Se a tradução fosse `>= 32`, um intervalo, ou `|| true`, um código vizinho
# também viraria sucesso. 31 e 33 devem continuar reprovando.
for adjacent in 31 33; do
  run_case "adjacente-$adjacent" "$TMP_DIR/backups/adj-$adjacent" "$adjacent" 0
  [ "$CASE_STATUS" -ne 0 ] \
    || fail "código $adjacent virou sucesso; a tradução de ROLLBACK_NOT_NEEDED não é exata"
done

# --- E o bloco remoto real precisa de fato emitir 32 nesse estado ------------
# Sem esta asserção o runner poderia tratar um código que ninguém produz, e o
# desfecho 2 continuaria reprovando em produção com a suíte verde.
REMOTE_BLOCK="$TMP_DIR/rollback-remote.sh"
awk '/^REMOTE$/{exit} p; /<<.REMOTE.$/{p=1}' "$STEP_BODY" > "$REMOTE_BLOCK"
[ -s "$REMOTE_BLOCK" ] || fail 'não foi possível extrair o 1º heredoc remoto do rollback.'

remote_root="$TMP_DIR/remote/document-root"
remote_backup="$TMP_DIR/remote/backup"
lock="$remote_root/.uonix-operation.lock"
mkdir -p "$lock" "$remote_backup"
printf '%s\n' "$DEPLOY_RUN_ID" > "$lock/owner"
chmod 600 "$lock/owner"

set +e
remote_output="$(
  bash "$REMOTE_BLOCK" \
    "$remote_root" "$remote_backup" \
    "$TMP_DIR/bin/php-fake" "$TMP_DIR/bin/wp-fake" \
    "$TARGET_URL" "$DEPLOY_RUN_ID" 2>&1
)"
remote_status=$?
set -e
[ "$remote_status" -eq 32 ] \
  || fail "bloco remoto com lock e ZERO marcadores deveria sair 32, saiu $remote_status ($remote_output)"
printf '%s' "$remote_output" | grep -q 'nenhuma mutação foi iniciada' \
  || fail "bloco remoto não explicou o desfecho 32: $remote_output"
[ -f "$lock/owner" ] \
  || fail 'bloco remoto removeu o owner do lock no caminho sem mutação'

# --- Precedência: a verificação de DONO vem antes do exit 32 -------------------
# O `exit 32` significa "preparado, nada mutado, nada a restaurar", e o runner o
# traduz em sucesso. Se ele viesse ANTES da verificação de dono, um rollback
# disparado por uma execução que NÃO é dona do lock sairia 32 e seria reportado
# como sucesso — violação de posse convertida em "nada a fazer".
#
# Asserção COMPORTAMENTAL, e não de ordem de linha: mover o `exit 32` para antes
# do `test "$(cat owner)" = "$run_id"` sobrevive a qualquer checagem estática de
# presença, e sobrevivia às duas asserções do #276.
alien_root="$TMP_DIR/remote-root-alheio"
alien_backup="$alien_root/.uonix-deploy-backups/run-1"
alien_lock="$alien_root/.uonix-operation.lock"
mkdir -p "$alien_lock" "$alien_backup"
printf 'outra-execucao-99\n' > "$alien_lock/owner"
chmod 600 "$alien_lock/owner"

set +e
alien_output="$(
  bash "$REMOTE_BLOCK" \
    "$alien_root" "$alien_backup" \
    "$TMP_DIR/bin/php-fake" "$TMP_DIR/bin/wp-fake" \
    "$TARGET_URL" "$DEPLOY_RUN_ID" 2>&1
)"
alien_status=$?
set -e
[ "$alien_status" -ne 32 ] \
  || fail 'bloco remoto saiu 32 com lock de OUTRA execução: violação de posse virou "nada a restaurar"'
[ "$alien_status" -ne 0 ] \
  || fail "bloco remoto aceitou lock de outra execução como sucesso ($alien_output)"

# A asserção acima exige apenas "não 32 e não 0", e isso é subespecificado: uma falha
# futura mais PRECOCE — um `test -d` acrescentado acima, por exemplo — a manteria
# verde destruindo a propriedade. Verde por acidente, deslocado para o futuro.
#
# A contraprova fixa isso. Mesmo fixture, mudando SÓ o conteúdo de `owner` para o
# run_id correto: agora tem de sair 32. Com os dois casos, um status diferente de 32
# no caso alheio só pode vir da comparação de dono.
printf '%s\n' "$DEPLOY_RUN_ID" > "$alien_lock/owner"
set +e
proprio_output="$(
  bash "$REMOTE_BLOCK" \
    "$alien_root" "$alien_backup" \
    "$TMP_DIR/bin/php-fake" "$TMP_DIR/bin/wp-fake" \
    "$TARGET_URL" "$DEPLOY_RUN_ID" 2>&1
)"
proprio_status=$?
set -e
[ "$proprio_status" -eq 32 ] \
  || fail "mesmo fixture com owner correto deveria sair 32, saiu $proprio_status ($proprio_output); o delta entre os dois casos não é a verificação de dono"

# A mensagem também é asserida, e não só o número: o item 1 da #277 pretende trocar
# o encoding do 32 por linha-sentinela, e a mensagem é o que ele vai promover. Esta
# asserção sobrevive à troca; a do número não.
printf '%s' "$alien_output" | grep -q 'nenhuma mutação foi iniciada' \
  && fail 'bloco remoto declarou "nada a restaurar" com lock de outra execução'
printf '%s' "$proprio_output" | grep -q 'nenhuma mutação foi iniciada' \
  || fail "com owner correto o bloco não explicou o desfecho: $proprio_output"

printf 'PASS: o rollback distingue os quatro desfechos — nada preparado, preparado sem mutação (32 -> 0), revertido, e reversão falhada (!= 0) — sem colapsar em || true, e a verificação de dono precede o 32.\n'
