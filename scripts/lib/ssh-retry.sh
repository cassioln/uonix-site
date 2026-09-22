#!/usr/bin/env bash
# Retry para uma sessão SSH/rsync completa contra a Locaweb.
#
# Motivação (2026-08-24): o firewall da Locaweb trata sessões SSH sequenciais
# em rajada como abuso e corta a conexão no meio (rsync "connection
# unexpectedly closed", exit 255). Isso já aconteceu depois que a
# transferência de código tinha COMPLETADO — o workflow reportou failure com
# o deploy na prática bem-sucedido, e o lock remoto ficou preso porque o
# rollback/release subsequentes também caíram de conexão.
#
# Retry aqui só cobre exit 255 (falha de transporte SSH/rsync). Qualquer outro
# código de saída (ex.: falha de validação, checksum divergente) propaga
# imediatamente — não é um problema de conexão e retentar não ajuda.
#
# --replay-stdin existe por um defeito MEDIDO, não por precaução. Quando o comando
# retentado lê o script remoto de stdin — `ssh ... bash -s -- args <<'REMOTE'` —, o
# heredoc é um arquivo temporário cujo offset avança: a PRIMEIRA tentativa consome
# tudo e as seguintes recebem stdin VAZIO. E `bash -s` sem entrada não executa nada
# e sai 0, então o retry declara sucesso sem ter rodado uma única asserção remota.
# Isto é fail-open, e é pior que não ter retry. Medido em 2026-09-22:
#
#   attempt=1 bytes=19   attempt=2 bytes=0   attempt=3 bytes=0   -> exit 0
#
# Com --replay-stdin o stdin é copiado uma vez para arquivo e CADA tentativa é
# redirecionada de uma abertura nova dele. Como a bandeira só faz sentido quando há
# payload, stdin vazio é recusado (exit 64) em vez de virar sucesso silencioso.
# Não use a bandeira quando o comando não lê stdin (ex.: rsync): ela bloquearia
# esperando EOF de um stdin que ninguém vai fechar.
#
# Uso: uonix_ssh_retry [--replay-stdin] <max_attempts> <delay_seconds> -- comando...
set -euo pipefail

usage() {
  printf 'uso: %s [--replay-stdin] <max_attempts> <delay_seconds> -- comando...\n' "$0" >&2
}

replay_stdin=false
if [ "${1:-}" = '--replay-stdin' ]; then
  replay_stdin=true
  shift
fi

if [ "$#" -lt 4 ] || [ "$3" != '--' ]; then
  usage
  exit 64
fi

max_attempts="$1"
delay="$2"
shift 3

case "$max_attempts" in
  ''|*[!0-9]*|0) printf 'max_attempts deve ser inteiro maior que zero\n' >&2; exit 64 ;;
esac
case "$delay" in
  ''|*[!0-9]*) printf 'delay_seconds deve ser inteiro não negativo\n' >&2; exit 64 ;;
esac

stdin_copy=''
if [ "$replay_stdin" = true ]; then
  stdin_copy="$(mktemp "${TMPDIR:-/tmp}/uonix-ssh-retry-stdin.XXXXXX")" || exit 64
  # Trap em aspas SIMPLES de propósito: expandir o caminho aqui quebraria com
  # qualquer metacaractere no valor. A expansão acontece na hora da limpeza.
  trap 'rm -f -- "$stdin_copy"' EXIT HUP INT TERM
  chmod 600 "$stdin_copy"
  cat > "$stdin_copy"
  if [ ! -s "$stdin_copy" ]; then
    printf '%s\n' '--replay-stdin exige payload em stdin; stdin veio vazio e um retry aprovaria o comando sem executá-lo' >&2
    exit 64
  fi
fi

# Uma única forma de invocar o comando, para que o status propagado seja sempre o
# DELE. Uma condição composta (`{ flag && cmd; } || { ... }`) devolveria em `$?` o
# status do teste da bandeira, não o do comando, e o critério "só 255 retenta"
# passaria a ler o número errado.
run_attempt() {
  if [ "$replay_stdin" = true ]; then
    "$@" < "$stdin_copy"
  else
    "$@"
  fi
}

attempt=1
status=0
while [ "$attempt" -le "$max_attempts" ]; do
  if run_attempt "$@"; then
    exit 0
  else
    status=$?
  fi

  # Só falha de transporte (SSH/rsync exit 255) é retentável. Qualquer outro
  # código é uma falha de lógica/validação remota — propague imediatamente.
  if [ "$status" -ne 255 ] || [ "$attempt" -eq "$max_attempts" ]; then
    exit "$status"
  fi

  printf 'uonix_ssh_retry: tentativa %s/%s falhou com exit 255 (transporte); aguardando %ss\n' \
    "$attempt" "$max_attempts" "$((attempt * delay))" >&2
  if [ "$delay" -gt 0 ]; then
    sleep "$((attempt * delay))"
  fi
  attempt=$((attempt + 1))
done

exit "$status"
