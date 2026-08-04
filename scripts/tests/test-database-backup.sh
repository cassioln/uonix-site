#!/usr/bin/env bash
# Contrato do backup de banco remoto.
#
# O que este teste protege: o passo de backup do deploy salvava somente CÓDIGO,
# então um rollback restaurava arquivos sobre um schema não restaurado. Cada
# assertiva abaixo corresponde a uma forma de o backup passar por bom sem ser.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCRIPT="${ROOT_DIR}/scripts/backup-remote-database.sh"
TMP_DIR="$(mktemp -d)"
CONTROL_DIR="/tmp/uonix-dbbackup-test-$$"
trap 'rm -rf "$TMP_DIR" "$CONTROL_DIR"' EXIT HUP INT TERM

fail() {
  printf 'FAIL: %s\n' "$*" >&2
  exit 1
}

[ -f "$SCRIPT" ] || fail 'scripts/backup-remote-database.sh ainda não existe.'

mkdir -p "$TMP_DIR/bin" "$TMP_DIR/remote"
printf 'known host fixture\n' > "$TMP_DIR/known-hosts"
printf 'private key fixture\n' > "$TMP_DIR/key"
printf 'senha-que-nao-deve-aparecer\n' > "$TMP_DIR/password"
chmod 600 "$TMP_DIR/known-hosts" "$TMP_DIR/key" "$TMP_DIR/password"

# O mock de ssh executa o corpo remoto LOCALMENTE, em bash, com binários falsos
# no PATH. Assim o contrato exercitado é o script remoto de verdade — não uma
# reimplementação dele no teste.
cat > "$TMP_DIR/bin/ssh" <<'MOCK'
#!/usr/bin/env bash
set -u
printf 'ssh' >> "$MOCK_SSH_LOG"
printf ' <%s>' "$@" >> "$MOCK_SSH_LOG"
printf '\n' >> "$MOCK_SSH_LOG"
remote_command="${!#}"
printf '%s' "$remote_command" > "$MOCK_REMOTE_SCRIPT"
bash -c "$remote_command"
MOCK

cat > "$TMP_DIR/bin/sshpass" <<'MOCK'
#!/usr/bin/env bash
set -u
printf 'sshpass' >> "$MOCK_SSH_LOG"
printf ' <%s>' "$@" >> "$MOCK_SSH_LOG"
printf '\n' >> "$MOCK_SSH_LOG"
shift
exec "$@"
MOCK

# wp falso: responde credenciais e registra cada invocação, para provarmos que a
# senha nunca é impressa nem passada em argv.
cat > "$TMP_DIR/bin/wp" <<'MOCK'
#!/usr/bin/env bash
set -u
printf 'wp' >> "$MOCK_WP_LOG"
printf ' <%s>' "$@" >> "$MOCK_WP_LOG"
printf '\n' >> "$MOCK_WP_LOG"
for argument in "$@"; do
  case "$argument" in
    *'echo DB_NAME'*) printf 'uonix_db\tuonix_user\tdb.example.invalid'; exit 0 ;;
    *'echo DB_PASSWORD'*) printf 'senha-que-nao-deve-aparecer'; exit 0 ;;
  esac
done
case "${1:-}" in
  db) [ "${2:-}" = export ] && { [ "${MOCK_WP_DB_EXPORT_OK:-0}" = 1 ] || exit 1; printf -- '-- wp export\n'; exit 0; } ;;
esac
exit 0
MOCK

# mysqldump falso: emite um dump com o número de tabelas pedido, ou falha.
# No modo `garbage` produz gzip com stream truncado — o caso perigoso de verdade:
# o dump "termina com sucesso", tem bytes, e só a verificação de integridade pega.
cat > "$TMP_DIR/bin/mysqldump" <<'MOCK'
#!/usr/bin/env bash
set -u
printf 'mysqldump' >> "$MOCK_DUMP_LOG"
printf ' <%s>' "$@" >> "$MOCK_DUMP_LOG"
printf '\n' >> "$MOCK_DUMP_LOG"
printf 'MYSQL_PWD_PRESENTE=%s\n' "${MYSQL_PWD:+sim}" >> "$MOCK_DUMP_LOG"
[ "${MOCK_DUMP_MODE:-ok}" != fail ] || { printf 'erro simulado\n' >&2; exit 2; }
count="${MOCK_DUMP_TABLES:-158}"
i=0
while [ "$i" -lt "$count" ]; do
  printf 'CREATE TABLE `t%s` (id int);\n' "$i"
  i=$((i + 1))
done
MOCK

chmod 755 "$TMP_DIR/bin/ssh" "$TMP_DIR/bin/sshpass" "$TMP_DIR/bin/wp" "$TMP_DIR/bin/mysqldump"

export PATH="$TMP_DIR/bin:$PATH"
export MOCK_SSH_LOG="$TMP_DIR/ssh.log"
export MOCK_WP_LOG="$TMP_DIR/wp.log"
export MOCK_DUMP_LOG="$TMP_DIR/dump.log"
export MOCK_REMOTE_SCRIPT="$TMP_DIR/remote.sh"
export HOSTGATOR_SSH_KEY="$TMP_DIR/key"
export HOSTGATOR_SSH_KNOWN_HOSTS_FILE="$TMP_DIR/known-hosts"
export LOCAWEB_SSH_PASSWORD_FILE="$TMP_DIR/password"
export LOCAWEB_SSH_KNOWN_HOSTS_FILE="$TMP_DIR/known-hosts"
export UONIX_SSH_CONTROL_DIR="$CONTROL_DIR"
export UONIX_TRANSPORT_RETRY_DELAY=0
export UONIX_TRANSPORT_MAX_ATTEMPTS=1

export LOCAWEB_SSH_HOST='ftp.example.invalid'
export LOCAWEB_SSH_PORT='22'
export LOCAWEB_SSH_USER='siteuonix1'
export LOCAWEB_DOCUMENT_ROOT="$TMP_DIR/remote"
export LOCAWEB_ACCOUNT_ROOT="$TMP_DIR/remote"
export LOCAWEB_PHP_BIN="$TMP_DIR/bin/php-fake"
export LOCAWEB_WP_BIN="$TMP_DIR/bin/wp-cli-fake"
export HOSTGATOR_SSH_HOST='hostgator.example.invalid'
export HOSTGATOR_SSH_PORT='22'
export HOSTGATOR_SSH_USER='uonix'
export HOSTGATOR_QA_ROOT="$TMP_DIR/remote"
export HOSTGATOR_DEV_ROOT="$TMP_DIR/remote"
export PRODUCTION_URL='https://prod.example.invalid'
export QA_URL='https://qa.example.invalid'
export DEVELOPMENT_URL='https://dev.example.invalid'

# O PHP/PHAR da Locaweb é substituído por um wrapper que cai no `wp` falso,
# preservando a forma real da invocação (`php -d disable_functions= phar`).
printf '#!/usr/bin/env bash\nshift 2\nexec wp "$@"\n' > "$TMP_DIR/bin/php-fake"
printf 'phar fixture\n' > "$TMP_DIR/bin/wp-cli-fake"
chmod 755 "$TMP_DIR/bin/php-fake"

reset_logs() {
  : > "$MOCK_SSH_LOG"; : > "$MOCK_WP_LOG"; : > "$MOCK_DUMP_LOG"
  rm -rf "$TMP_DIR/out"
}

run_backup() {
  reset_logs
  bash "$SCRIPT" --environment="$1" --output-dir="$TMP_DIR/out" --backup-id=test 2>&1
}

# --- Caso 1: caminho feliz, mysqllump disponível --------------------------------
if ! output="$(MOCK_DUMP_MODE=ok run_backup prod)"; then
  fail "backup válido reprovou: $output"
fi
dump="$TMP_DIR/out/db-prod-test.sql.gz"
[ -f "$dump" ] || fail 'artefato de backup não foi publicado'
gzip -t "$dump" 2>/dev/null || fail 'artefato publicado não é gzip válido'
printf '%s' "$output" | grep -q 'mechanism=mysqldump' || fail 'mecanismo mysqldump não foi reportado'
printf '%s' "$output" | grep -q 'tables=158' || fail 'contagem de tabelas não foi reportada'
printf '%s' "$output" | grep -q "DB_BACKUP_FILE=$dump" || fail 'caminho do backup não foi exportado'
# Modo do arquivo de forma portável. Cuidado: `stat -f` existe nos DOIS mundos
# com significados diferentes — no BSD/macOS é formato, no GNU/Linux é informação
# do filesystem e retorna 0. Um `stat -f ... || stat -c ...` portanto NUNCA cai no
# fallback no Linux e devolve um valor que não é o modo. Decidimos pelo SO.
file_mode() {
  if stat -c '%a' /dev/null >/dev/null 2>&1; then
    stat -c '%a' "$1"
  else
    stat -f '%Lp' "$1"
  fi
}

[ "$(file_mode "$dump")" = 600 ] \
  || fail "artefato de backup não está 0600 (modo: $(file_mode "$dump"))"

# A senha do banco não pode aparecer em NENHUM log: nem em argv do mysqldump,
# nem na saída do script. Ela deve trafegar apenas por MYSQL_PWD.
grep -q 'senha-que-nao-deve-aparecer' "$MOCK_DUMP_LOG" \
  && fail 'senha do banco apareceu na linha de comando do mysqldump'
printf '%s' "$output" | grep -q 'senha-que-nao-deve-aparecer' \
  && fail 'senha do banco apareceu na saída do script'
grep -q 'MYSQL_PWD_PRESENTE=sim' "$MOCK_DUMP_LOG" \
  || fail 'mysqldump não recebeu a senha por MYSQL_PWD'
grep -q -- '--single-transaction' "$MOCK_DUMP_LOG" \
  || fail 'dump sem --single-transaction (inconsistente em site ativo)'
grep -q -- '--no-tablespaces' "$MOCK_DUMP_LOG" \
  || fail 'dump sem --no-tablespaces (exigiria privilégio PROCESS)'

# --- Caso 2: dump falha -> nada é publicado ------------------------------------
if output="$(MOCK_DUMP_MODE=fail run_backup prod)"; then
  fail 'dump falho retornou sucesso'
fi
[ ! -e "$TMP_DIR/out/db-prod-test.sql.gz" ] || fail 'dump falho publicou artefato final'
[ -z "$(find "$TMP_DIR/out" -name '*.partial' 2>/dev/null)" ] || fail 'dump falho deixou .partial'

# --- Caso 3: artefato corrompido é rejeitado ----------------------------------
# O caso perigoso de verdade: o dump termina com sucesso e o arquivo tem bytes,
# mas o stream gzip está truncado — exatamente o que uma conexão interrompida ou
# um disco cheio produzem. Só `gzip -t` pega isso; tamanho > 0 não pega.
# Interceptamos o gzip para truncar a saída, simulando a escrita interrompida.
cat > "$TMP_DIR/bin/gzip" <<'MOCK'
#!/usr/bin/env bash
set -u
real_gzip=/usr/bin/gzip
[ -x "$real_gzip" ] || real_gzip="$(PATH=/bin:/usr/bin command -v gzip)"
if [ "${MOCK_GZIP_TRUNCATE:-0}" = 1 ] && [ "$#" -eq 0 ]; then
  # Gera gzip legítimo e descarta o fim, deixando o stream incompleto.
  "$real_gzip" | head -c 20
  exit 0
fi
exec "$real_gzip" "$@"
MOCK
chmod 755 "$TMP_DIR/bin/gzip"

if output="$(MOCK_DUMP_MODE=ok MOCK_GZIP_TRUNCATE=1 run_backup prod)"; then
  fail 'artefato com gzip truncado foi aceito'
fi
printf '%s' "$output" | grep -q 'corrompido' || fail 'corrupção não foi diagnosticada'
[ ! -e "$TMP_DIR/out/db-prod-test.sql.gz" ] || fail 'artefato corrompido foi publicado'
[ -z "$(find "$TMP_DIR/out" -name '*.partial' 2>/dev/null)" ] || fail 'artefato corrompido deixou .partial'
rm -f "$TMP_DIR/bin/gzip"

# --- Caso 4: dump com poucas tabelas é rejeitado ------------------------------
# Um dump de 2 tabelas passa em gzip -t e tem bytes > 0; só a contagem o pega.
if output="$(MOCK_DUMP_MODE=ok MOCK_DUMP_TABLES=2 run_backup prod)"; then
  fail 'dump truncado (2 tabelas) foi aceito'
fi
printf '%s' "$output" | grep -q 'tabelas' || fail 'contagem insuficiente não foi diagnosticada'
[ ! -e "$TMP_DIR/out/db-prod-test.sql.gz" ] || fail 'dump truncado foi publicado'

# --- Caso 5: fallback para wp db export quando não há mysqldump ---------------
# Prova que o mecanismo é escolhido por CAPACIDADE, não por nome de ambiente.
#
# Remover só o mock NÃO basta: o runner do CI tem um mysqldump real em /usr/bin,
# então `command -v mysqldump` continuaria encontrando-o e o fallback nunca seria
# exercitado (era exatamente a diferença entre passar no macOS e falhar no CI).
# Montamos um PATH espelhando os binários reais, exceto mysqldump.
nodump_bin="$TMP_DIR/nodump-bin"
mkdir -p "$nodump_bin"
for real_dir in /bin /usr/bin; do
  [ -d "$real_dir" ] || continue
  for real_binary in "$real_dir"/*; do
    [ -x "$real_binary" ] || continue
    name="${real_binary##*/}"
    [ "$name" = mysqldump ] && continue
    [ -e "$nodump_bin/$name" ] || ln -s "$real_binary" "$nodump_bin/$name" 2>/dev/null || true
  done
done
mv "$TMP_DIR/bin/mysqldump" "$TMP_DIR/mysqldump.disabled"

original_path="$PATH"
PATH="$TMP_DIR/bin:$nodump_bin"
export PATH
command -v mysqldump >/dev/null 2>&1 \
  && fail 'não foi possível simular a ausência de mysqldump'

if ! output="$(MOCK_WP_DB_EXPORT_OK=1 MOCK_DUMP_TABLES=0 UONIX_DB_BACKUP_MIN_TABLES=0 run_backup qa)"; then
  fail "fallback para wp db export reprovou: $output"
fi
printf '%s' "$output" | grep -q 'mechanism=wp' || fail 'fallback não usou wp db export'

PATH="$original_path"
export PATH
mv "$TMP_DIR/mysqldump.disabled" "$TMP_DIR/bin/mysqldump"

# --- Caso 6: argumentos inseguros são recusados antes de qualquer conexão -----
for bad in '--output-dir=relativo' '--output-dir=/' '--output-dir=/a/../b'; do
  reset_logs
  if bash "$SCRIPT" --environment=prod "$bad" --backup-id=test >/dev/null 2>&1; then
    fail "output-dir inseguro aceito: $bad"
  fi
  [ ! -s "$MOCK_SSH_LOG" ] || fail "output-dir inseguro abriu conexão: $bad"
done

reset_logs
if bash "$SCRIPT" --environment=prod --output-dir="$TMP_DIR/out" --backup-id='a;rm -rf /' >/dev/null 2>&1; then
  fail 'backup-id com metacaractere foi aceito'
fi
[ ! -s "$MOCK_SSH_LOG" ] || fail 'backup-id inseguro abriu conexão'

reset_logs
if bash "$SCRIPT" --environment=local --output-dir="$TMP_DIR/out" >/dev/null 2>&1; then
  fail 'ambiente local (Podman, sem SSH) foi aceito como backup remoto'
fi
[ ! -s "$MOCK_SSH_LOG" ] || fail 'ambiente local abriu conexão SSH'

# --- Caso 7: o deploy de produção precisa exigir este backup -----------------
# Sem esta assertiva, o script poderia existir e nunca ser chamado — a mesma
# classe de falha do módulo registrado mas não carregado.
workflow="${ROOT_DIR}/.github/workflows/deploy-production.yml"
grep -q 'backup-remote-database.sh' "$workflow" \
  || fail 'deploy de produção não invoca o backup de banco'

publish_line="$(grep -n 'Publish only managed paths' "$workflow" | head -1 | cut -d: -f1)"
backup_line="$(grep -n 'backup-remote-database.sh' "$workflow" | head -1 | cut -d: -f1)"
[ -n "$publish_line" ] && [ -n "$backup_line" ] \
  || fail 'não foi possível localizar as etapas de backup e publicação'
[ "$backup_line" -lt "$publish_line" ] \
  || fail 'backup de banco ocorre DEPOIS da publicação (inútil como rollback)'

# --- Caso 8: o rollback tem de restaurar o banco, não apenas arquivos ---------
# Um backup de banco que o rollback ignora é decoração: o deploy falha, o
# rollback declara sucesso e o schema segue divergente.
rollback_body="$(sed -n "/Roll back managed code after failure/,/Retain five code backups/p" "$workflow")"
printf '%s' "$rollback_body" | grep -q "db-\*.sql.gz" \
  || fail 'rollback não localiza o dump de banco'
printf '%s' "$rollback_body" | grep -q 'gzip -t' \
  || fail 'rollback não valida a integridade do dump antes de restaurar'
printf '%s' "$rollback_body" | grep -q 'MYSQL_PWD=' \
  || fail 'rollback não restaura o banco por cliente mysql'

# A restauração do banco precisa vir ANTES da troca de arquivos: restaurar
# arquivos sobre um schema divergente passa no smoke e mente sobre o conteúdo.
restore_offset="$(printf '%s' "$rollback_body" | grep -n 'MYSQL_PWD=' | head -1 | cut -d: -f1)"
# O padrão é literal de propósito: procuramos o texto do workflow, não o valor
# de uma variável local.
# shellcheck disable=SC2016
files_offset="$(printf '%s' "$rollback_body" | grep -n 'rm -rf -- "\$document_root/wp-content/themes/kadence-child"' | head -1 | cut -d: -f1)"
[ -n "$restore_offset" ] && [ -n "$files_offset" ] \
  || fail 'não foi possível ordenar restauração de banco e de arquivos'
[ "$restore_offset" -lt "$files_offset" ] \
  || fail 'rollback restaura arquivos antes do banco'

# Um dump presente mas corrompido tem de ABORTAR, não ser ignorado.
printf '%s' "$rollback_body" | grep -q 'rollback abortado antes de escrever' \
  || fail 'rollback não aborta diante de dump corrompido'

# --- Caso 9: a retenção precisa alcançar o dump ------------------------------
# O dump mora dentro do diretório do backup, então a retenção existente já o
# remove junto. Se alguém mover o dump para fora, esta assertiva quebra.
# O padrão é literal: casa o texto do workflow, não expande $BACKUP_DIR aqui.
# shellcheck disable=SC2016
grep -q 'output-dir="\$BACKUP_DIR"' "$workflow" \
  || fail 'dump não é gravado dentro do diretório de backup coberto pela retenção'

printf 'PASS: backup de banco valida integridade, falha fechado, precede a publicação e o rollback o restaura.\n'
