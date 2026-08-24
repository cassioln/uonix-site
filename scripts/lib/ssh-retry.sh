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
# Uso: uonix_ssh_retry <max_attempts> <delay_seconds> -- comando...
set -euo pipefail

if [ "$#" -lt 4 ] || [ "$3" != '--' ]; then
  printf 'uso: %s <max_attempts> <delay_seconds> -- comando...\n' "$0" >&2
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

attempt=1
status=0
while [ "$attempt" -le "$max_attempts" ]; do
  if "$@"; then
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
