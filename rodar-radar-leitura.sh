#!/usr/bin/env bash
# Radar de Pautas: leitura em produção, SOMENTE LEITURA.
set -u
cd /Users/cassio/GitHubPessoal/uonix-site || exit 1
source scripts/lib/ssh-transport.sh || exit 1
PHP=$(uonix_environment_field prod php_bin)
WP=$(uonix_environment_field prod wp_bin)
DOCROOT=$(uonix_environment_field prod document_root)
CONTA="${LOCAWEB_ACCOUNT_ROOT:?}"
TMP="$CONTA/.uonix-radar-$$.php"
( sleep 200; pkill -9 -f "uonix-ssh-$UID" ) & GUARDA=$!
trap 'uonix_exec prod -- rm -f "$TMP" >/dev/null 2>&1; uonix_transport_close_master prod 2>/dev/null; kill $GUARDA 2>/dev/null' EXIT
uonix_stream_to prod "$TMP" < /tmp/claude/i193/radar-leitura.php || { echo "ABORT: envio falhou (janela SSH fechada?)"; exit 1; }
uonix_exec prod -- "$PHP" -d disable_functions= "$WP" --path="$DOCROOT" eval-file "$TMP" 2>&1
