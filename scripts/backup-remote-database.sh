#!/usr/bin/env bash
# Backup de banco de dados de um ambiente WordPress remoto, antes de qualquer
# mutação.
#
# Motivo de existir: o passo de backup do deploy salvava somente CÓDIGO (tema
# filho e módulos mu-plugins). Um rollback restaurava arquivos sobre um schema
# não restaurado. O caso concreto é o search-replace do cutover de domínio.
#
# Por que não usar `wp db export`: a Locaweb desabilita proc_open/proc_close de
# forma compilada, e `-d disable_functions=` NÃO relaxa isso (medido). O wp-cli
# funciona para subcomandos em PHP puro (option, eval, db query, search-replace)
# e falha em todos os que shellam out (db export, db import, db check).
#
# Por isso o mecanismo é escolhido por CAPACIDADE, não por nome de host: tenta
# mysqldump direto e só cai para `wp db export` quando o host permite. Assim o
# mesmo script serve HostGator e Locaweb sem ramificar por ambiente.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=scripts/lib/ssh-transport.sh
source "${ROOT_DIR}/scripts/lib/ssh-transport.sh"

ENVIRONMENT=""
OUTPUT_DIR=""
BACKUP_ID=""
MIN_TABLES="${UONIX_DB_BACKUP_MIN_TABLES:-20}"

usage() {
  cat <<'USAGE'
Uso: backup-remote-database.sh --environment=<prod|qa|dev> --output-dir=<dir remoto> [--backup-id=<id>]

Grava <dir remoto>/db-<ambiente>-<id>.sql.gz no HOST REMOTO e valida a
integridade do artefato antes de retornar. Falha fechado: sem dump válido,
o status de saída é diferente de zero e nada deve prosseguir.
USAGE
}

die() {
  printf 'Erro: %s\n' "$*" >&2
  return 1
}

parse_arguments() {
  local argument
  for argument in "$@"; do
    case "$argument" in
      --environment=*) ENVIRONMENT="${argument#*=}" ;;
      --output-dir=*)  OUTPUT_DIR="${argument#*=}" ;;
      --backup-id=*)   BACKUP_ID="${argument#*=}" ;;
      -h|--help)       usage; exit 0 ;;
      *)               die "argumento desconhecido: $argument" || return 1 ;;
    esac
  done

  [ -n "$ENVIRONMENT" ] || { die 'informe --environment'; return 1; }
  [ -n "$OUTPUT_DIR" ] || { die 'informe --output-dir'; return 1; }

  # O diretório vira parte de comandos remotos e de um rm em caso de falha.
  case "$OUTPUT_DIR" in
    /*) ;;
    *) die 'output-dir deve ser caminho absoluto'; return 1 ;;
  esac
  case "$OUTPUT_DIR" in
    /|//*|*/../*|*/..|*$'\n'*|*$'\r'*)
      die 'output-dir inseguro'
      return 1
      ;;
  esac

  # `local` é Podman: não há transporte SSH nem credencial de host remoto.
  case "$ENVIRONMENT" in
    prod|production|qa|dev|development) ;;
    *) die "ambiente não suportado para backup remoto: $ENVIRONMENT"; return 1 ;;
  esac

  BACKUP_ID="${BACKUP_ID:-$(date -u +%Y%m%d-%H%M%S)}"
  case "$BACKUP_ID" in
    ''|*[!A-Za-z0-9._-]*)
      die 'backup-id aceita apenas letras, números, ponto, hífen e underscore'
      return 1
      ;;
  esac
}

# O corpo remoto é literal de propósito: expandir aqui vazaria a senha do banco
# para a linha de comando. As credenciais são lidas no HOST, pelo próprio
# WordPress, e a senha nunca transita por argv — só por MYSQL_PWD.
remote_backup_script() {
  cat <<'REMOTE'
set -euo pipefail
umask 077

document_root="$1"
wp_cli="$2"
output_dir="$3"
dump_file="$4"
min_tables="$5"

test -d "$document_root" || { printf 'raiz remota inexistente\n' >&2; exit 1; }
mkdir -p -- "$output_dir"
chmod 700 "$output_dir"

# Credenciais vêm do wp-config pelo próprio WordPress: nenhum segredo novo é
# introduzido e nada é impresso.
credentials="$($wp_cli --path="$document_root" eval \
  'echo DB_NAME . "\t" . DB_USER . "\t" . DB_HOST;')"
db_name="$(printf '%s' "$credentials" | cut -f1)"
db_user="$(printf '%s' "$credentials" | cut -f2)"
db_host="$(printf '%s' "$credentials" | cut -f3)"

if [ -z "$db_name" ] || [ -z "$db_user" ] || [ -z "$db_host" ]; then
  printf 'não foi possível resolver as credenciais do banco\n' >&2
  exit 1
fi

partial="${dump_file}.partial"
rm -f -- "$partial"

mechanism=''
if command -v mysqldump >/dev/null 2>&1; then
  mechanism='mysqldump'
elif $wp_cli --path="$document_root" db export --help >/dev/null 2>&1; then
  mechanism='wp'
else
  printf 'nenhum mecanismo de dump disponível no host\n' >&2
  exit 1
fi

case "$mechanism" in
  mysqldump)
    # Flags escolhidas a partir de medição no host, não por hábito:
    #   --single-transaction  consistência sem travar site em uso. Seguro aqui:
    #                         as 158 tabelas são InnoDB, zero MyISAM.
    #   --quick               streaming linha a linha em vez de bufferizar tabela.
    #   --no-tablespaces      evita exigir PROCESS, que a conta não tem.
    #   --routines/--triggers/--events  sem eles o dump perde stored procedures,
    #                         triggers e eventos agendados — a restauração fica
    #                         silenciosamente incompleta.
    #   --default-character-set=utf8mb4  preserva acentos e emoji.
    #   --no-defaults         PRIMEIRA flag, por segurança: sem ela o cliente lê
    #                         ~/.my.cnf e /etc/my.cnf antes do nosso argv. Um
    #                         `[mysqldump]\nno-data` ali faz o dump sair rc=0 com
    #                         schema completo e ZERO dados (reproduzido em MariaDB
    #                         10.11). Em hospedagem compartilhada esse arquivo é
    #                         criado por painéis de controle.
    #
    # JAMAIS --flush-logs, --master-data ou --flush-privileges: exigem RELOAD,
    # que o usuário não possui (erro 1227, rc=2), e quebrariam o deploy.
    dump_flags='--no-defaults --single-transaction --quick --no-tablespaces --routines --triggers --events --default-character-set=utf8mb4'

    # Cliente 8.0 contra servidor 5.7 emite "column statistics not supported by
    # the server" em TODO dump. É inofensivo, mas polui o log do deploy e pode
    # ser lido como falha. A flag que silencia isso só existe no cliente 8.0+ e
    # não existe no MariaDB, então é detectada em vez de assumida — caso
    # contrário o backup quebraria em hosts com cliente diferente.
    if mysqldump --help 2>/dev/null | grep -q -- '--column-statistics'; then
      dump_flags="$dump_flags --column-statistics=0"
    fi

    # shellcheck disable=SC2086
    if ! MYSQL_PWD="$($wp_cli --path="$document_root" eval 'echo DB_PASSWORD;')" \
        mysqldump $dump_flags \
          -h "$db_host" -u "$db_user" "$db_name" 2>"${partial}.err" \
        | gzip > "$partial"; then
      printf 'mysqldump falhou\n' >&2
      head -c 400 "${partial}.err" >&2 || true
      rm -f -- "$partial" "${partial}.err"
      exit 1
    fi
    ;;
  wp)
    if ! $wp_cli --path="$document_root" db export - 2>"${partial}.err" | gzip > "$partial"; then
      printf 'wp db export falhou\n' >&2
      head -c 400 "${partial}.err" >&2 || true
      rm -f -- "$partial" "${partial}.err"
      exit 1
    fi
    ;;
esac

# Integridade real, não "arquivo existe". Um dump truncado é pior que nenhum:
# passa por presente e falha exatamente na hora de restaurar.
if ! gzip -t "$partial" 2>/dev/null; then
  printf 'artefato de backup corrompido (gzip inválido)\n' >&2
  rm -f -- "$partial" "${partial}.err"
  exit 1
fi

tables="$(gzip -dc "$partial" | grep -c '^CREATE TABLE' || true)"
if [ "$tables" -lt "$min_tables" ]; then
  printf 'backup com apenas %s tabelas (mínimo %s)\n' "$tables" "$min_tables" >&2
  rm -f -- "$partial" "${partial}.err"
  exit 1
fi

# Publica só depois de validado, para nunca existir um dump inválido com o nome
# final — que um rollback poderia aceitar como bom.
mv -- "$partial" "$dump_file"
chmod 600 "$dump_file"
rm -f -- "${partial}.err"

printf 'mechanism=%s\n' "$mechanism"
printf 'tables=%s\n' "$tables"
printf 'bytes=%s\n' "$(wc -c < "$dump_file" | tr -d ' ')"
REMOTE
}

main() {
  parse_arguments "$@" || return $?

  local canonical wp_root php_bin wp_bin wp_cli dump_file output
  canonical="$(uonix_env_canonical "$ENVIRONMENT")" || return $?
  wp_root="$(uonix_environment_field "$canonical" document_root)" || return $?
  php_bin="$(uonix_environment_field "$canonical" php_bin)" || return $?
  wp_bin="$(uonix_environment_field "$canonical" wp_bin)" || return $?

  [ -n "$wp_root" ] || { die "raiz WordPress ausente para $canonical"; return 1; }
  [ -n "$php_bin" ] || { die "php_bin ausente para $canonical"; return 1; }
  [ -n "$wp_bin" ] || { die "wp_bin ausente para $canonical"; return 1; }

  # Mesma convenção do clone (wp_cli_shell): quando o host expõe `wp` no PATH
  # usamos o binário direto; caso contrário invocamos o PHAR pelo PHP versionado.
  # O `-d disable_functions=` é mantido porque ajuda nos subcomandos em PHP puro,
  # mesmo onde não restaura proc_open.
  if [ "$php_bin" = php ] && [ "$wp_bin" = wp ]; then
    wp_cli='wp'
  else
    wp_cli="$(printf '%q -d disable_functions= %q' "$php_bin" "$wp_bin")"
  fi

  dump_file="${OUTPUT_DIR%/}/db-${canonical}-${BACKUP_ID}.sql.gz"

  printf 'Backup de banco: %s -> %s\n' "$canonical" "$dump_file"

  if ! output="$(uonix_transport_ssh_once "$canonical" \
      "bash -s -- $(printf '%q %q %q %q %q' \
        "$wp_root" "$wp_cli" "$OUTPUT_DIR" "$dump_file" "$MIN_TABLES") <<'UONIX_DB_BACKUP'
$(remote_backup_script)
UONIX_DB_BACKUP")"; then
    die 'backup de banco falhou; nenhuma mutação deve prosseguir'
    return 1
  fi

  printf '%s\n' "$output"
  printf 'DB_BACKUP_FILE=%s\n' "$dump_file"
}

main "$@"
