#!/usr/bin/env bash
# C37 — clear_cache() executada DE VERDADE, não stubada.
#
# POR QUE ESTE TESTE EXISTE
#
# Todos os testes existentes substituem clear_cache por um stub:
#     clear_cache() { :; }
#     clear_cache() { printf 'cache\n' >> "$log"; }
#
# Isso prova que o PIPELINE chama clear_cache no momento certo — útil — mas NÃO prova
# que clear_cache faz o que promete. A função real nunca é executada em teste nenhum.
#
# Aqui ela é executada contra um sistema de arquivos temporário, com o wp-cli
# substituído por um duplo que registra as chamadas.
#
# CONTRATO ATUAL (clone-environment.sh:1515-1560)
#
# `cache_dirs` está VAZIO de propósito: nenhum plugin de cache de PÁGINA está ativo, e a
# única camada restante (pods-alternative-cache, cache de OBJETO) é invalidada por
# `cache flush`, não por remoção de diretório. O comentário no código diz: "Se um plugin
# de cache de página voltar, reintroduzir os diretórios aqui."
#
# Então o contrato que este teste protege é:
#
#   1. chama `transient delete --all` e `cache flush` — é isso que efetivamente limpa
#   2. NÃO apaga nada dentro de wp-content quando a lista está vazia (nem a pasta cache,
#      nem uploads) — um `rm -rf` mal construído aqui destruiria dados do site
#   3. sobrevive a `set -u` com array vazio: no bash 4.2 da Locaweb, `${arr[@]}` com
#      array vazio ABORTA o script. O código tem guarda `[ ${#cache_dirs[@]} -gt 0 ]`
#   4. é best-effort: falha do wp-cli não aborta o clone
#   5. se diretórios voltarem à lista, são removidos de dentro de wp-content/cache
#      (testado injetando a lista, para o dia em que reintroduzirem)
#
# Escopo: caminho LOCAL. O remoto usa remote_run e é coberto por
# test-deploy-remote-blocks.sh / test-clone-safety.sh.

set -euo pipefail

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ALVO="$RAIZ/scripts/clone-environment.sh"

falhas=0
asserts=0

falhou() {
  printf 'FAIL: %s\n' "$1" >&2
  falhas=$((falhas + 1))
}

assert_ausente() {
  asserts=$((asserts + 1))
  if [ -e "$1" ]; then
    falhou "$2 (esperado REMOVIDO, ainda existe: $1)"
  fi
}

assert_presente() {
  asserts=$((asserts + 1))
  if [ ! -e "$1" ]; then
    falhou "$2 (esperado PRESERVADO, foi removido: $1)"
  fi
}

assert_contem() {
  asserts=$((asserts + 1))
  if ! grep -qF "$2" "$1" 2>/dev/null; then
    falhou "$3 (não encontrei '$2' em $1)"
  fi
}

TMP="$(mktemp -d "${TMPDIR:-/tmp}/c37-clearcache.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT

# ---------------------------------------------------------------------------
# Extrai a função REAL, sem executar o resto do script (que exige .env, SSH etc.)
# ---------------------------------------------------------------------------
FONTE_FN="$TMP/clear_cache.sh"
awk '/^clear_cache\(\) \{/,/^\}/' "$ALVO" > "$FONTE_FN"

if [ ! -s "$FONTE_FN" ]; then
  falhou "não consegui extrair clear_cache() de $ALVO — a função foi renomeada?"
  printf 'FALHOU: %d asserções, %d falhas\n' "$asserts" "$falhas" >&2
  exit 1
fi

# a extração precisa ter trazido o corpo, não só a assinatura
asserts=$((asserts + 1))
if ! grep -q 'cache flush' "$FONTE_FN"; then
  falhou "a função extraída não contém 'cache flush' — extração incompleta, teste inválido"
fi

# ---------------------------------------------------------------------------
# Cenário 1: contrato principal — limpa via wp-cli e NÃO apaga arquivos
# ---------------------------------------------------------------------------
WPC="$TMP/wp-content"
mkdir -p "$WPC/cache/pods-alternative-cache"
mkdir -p "$WPC/uploads/2026/08"
printf 'objeto\n' > "$WPC/cache/pods-alternative-cache/dado.php"
printf 'imagem\n' > "$WPC/uploads/2026/08/foto.jpg"
printf 'raiz\n' > "$WPC/cache/index.php"

CHAMADAS="$TMP/wp-cli-chamadas.log"
: > "$CHAMADAS"

local_wp() { printf '%s\n' "$*" >> "$CHAMADAS"; }
log() { :; }
is_remote_env() { return 1; }
LOCAL_WP_CONTENT="$WPC"

# shellcheck disable=SC1090
. "$FONTE_FN"

# Blindagem: se a guarda de array vazio for removida, este `clear_cache` aborta sob
# `set -e` e mataria o teste inteiro com status 0 — mutação passando impune. Desligamos o
# errexit só nesta chamada; a asserção estrutural do cenário 2 é quem reporta o problema.
set +e
clear_cache "local"
set -e

assert_contem "$CHAMADAS" "transient delete --all" "clear_cache deve limpar transients"
assert_contem "$CHAMADAS" "cache flush" "clear_cache deve dar flush no object cache"

# Com a lista vazia, NADA pode ser apagado. Um rm -rf mal construído aqui
# (ex.: "${LOCAL_WP_CONTENT}/cache/" sem o item) destruiria o cache de objeto do Pods.
assert_presente "$WPC/cache" "com cache_dirs vazio, a pasta cache NÃO pode ser removida"
assert_presente "$WPC/cache/index.php" "arquivos na raiz do cache devem ser preservados"
assert_presente "$WPC/cache/pods-alternative-cache/dado.php" \
  "o cache de OBJETO do Pods é mantido de propósito e não pode ser apagado"
assert_presente "$WPC/uploads/2026/08/foto.jpg" "clear_cache nunca deve tocar em uploads"

# ---------------------------------------------------------------------------
# Cenário 2: sobrevive a `set -u` com array vazio
#
# `${arr[@]}` com array vazio aborta sob `set -u` no bash 3.2 (macOS) e no 4.2 da
# Locaweb. O código protege com `[ "${#cache_dirs[@]}" -gt 0 ]`.
#
# ATENÇÃO ao método: o `. "$FONTE_FN"` do cenário 1 já rodou com `set -e` ativo neste
# shell. Se a guarda for removida, o próprio `clear_cache` do cenário 1 aborta e MATA o
# teste antes de qualquer asserção ser contada — o script sai com status 0 e a mutação
# passa impune. Foi o que aconteceu na primeira versão.
#
# Por isso a verificação roda em PROCESSO separado e a falha é detectada pela AUSÊNCIA da
# sentinela, não pelo status do teste.
# ---------------------------------------------------------------------------
SENT_U="$TMP/sentinela-set-u.txt"
rm -f "$SENT_U"

bash -c '
  set -euo pipefail
  LOCAL_WP_CONTENT="$1"
  SENT="$2"
  FN="$3"
  log() { :; }
  is_remote_env() { return 1; }
  local_wp() { :; }
  # shellcheck disable=SC1090
  . "$FN"
  clear_cache "local"
  printf "ok\n" > "$SENT"
' _ "$WPC" "$SENT_U" "$FONTE_FN" >/dev/null 2>&1 || true

asserts=$((asserts + 1))
if [ ! -f "$SENT_U" ]; then
  falhou "clear_cache abortou sob 'set -u' com cache_dirs vazio — falta a guarda \
[ \${#cache_dirs[@]} -gt 0 ]? isso quebraria o clone no bash 4.2 da Locaweb"
fi

# Asserção estrutural de reforço: a guarda tem de existir no código-fonte.
#
# Necessária porque o cenário acima roda em subprocesso e, se a guarda cair, este mesmo
# shell pode morrer antes de reportar. Ter as duas garante que a remoção da guarda seja
# vista de alguma forma.
asserts=$((asserts + 1))
if ! grep -qE '\[ *"\$\{#cache_dirs\[@\]\}" *-gt *0 *\]' "$ALVO"; then
  falhou "a guarda [ \${#cache_dirs[@]} -gt 0 ] desapareceu de clone-environment.sh — \
sem ela, cache_dirs vazio + set -u aborta o clone no bash 4.2 da Locaweb"
fi

# ---------------------------------------------------------------------------
# Cenário 3: best-effort — falha do wp-cli não aborta o clone
# ---------------------------------------------------------------------------
SENT_ERR="$TMP/sentinela-wpcli.txt"
rm -f "$SENT_ERR"

bash -c '
  set -euo pipefail
  LOCAL_WP_CONTENT="$1"
  SENT="$2"
  FN="$3"
  log() { :; }
  is_remote_env() { return 1; }
  local_wp() { return 1; }          # wp-cli quebrado
  # shellcheck disable=SC1090
  . "$FN"
  clear_cache "local"
  printf "ok\n" > "$SENT"
' _ "$WPC" "$SENT_ERR" "$FONTE_FN" >/dev/null 2>&1 || true

asserts=$((asserts + 1))
if [ ! -f "$SENT_ERR" ]; then
  falhou "falha do wp-cli abortou clear_cache sob 'set -e' — limpar cache é best-effort \
e o clone não pode morrer por causa disso (falta '|| true' nas chamadas?)"
fi

# ---------------------------------------------------------------------------
# Cenário 4: se diretórios VOLTAREM à lista, são removidos corretamente
#
# O código diz "Se um plugin de cache de página voltar, reintroduzir os diretórios aqui".
# Este cenário testa esse caminho hoje, para que a reintrodução não venha com bug: usamos
# uma cópia da função com a lista preenchida.
# ---------------------------------------------------------------------------
FONTE_COM_DIRS="$TMP/clear_cache_com_dirs.sh"
sed 's/local cache_dirs=()/local cache_dirs=( min wp-rocket )/' "$FONTE_FN" > "$FONTE_COM_DIRS"

asserts=$((asserts + 1))
if ! grep -q 'cache_dirs=( min wp-rocket )' "$FONTE_COM_DIRS"; then
  falhou "não consegui injetar a lista de diretórios — a declaração 'local cache_dirs=()' \
mudou de forma? este cenário ficaria sem cobertura"
fi

WPC2="$TMP/wp-content-com-dirs"
mkdir -p "$WPC2/cache"/{min,wp-rocket,pods-alternative-cache}
mkdir -p "$WPC2/uploads"
printf 'x\n' > "$WPC2/cache/min/a.css"
printf 'x\n' > "$WPC2/cache/wp-rocket/b.html"
printf 'objeto\n' > "$WPC2/cache/pods-alternative-cache/dado.php"
printf 'img\n' > "$WPC2/uploads/foto.jpg"

(
  LOCAL_WP_CONTENT="$WPC2"
  # shellcheck disable=SC2329  # chamados indiretamente pela função carregada abaixo
  local_wp() { :; }
  # shellcheck disable=SC2329
  log() { :; }
  # shellcheck disable=SC2329
  is_remote_env() { return 1; }
  # shellcheck disable=SC1090
  . "$FONTE_COM_DIRS"
  clear_cache "local"
) >/dev/null 2>&1

assert_ausente "$WPC2/cache/min" "com a lista preenchida, 'min' deve ser removido"
assert_ausente "$WPC2/cache/wp-rocket" "com a lista preenchida, 'wp-rocket' deve ser removido"
assert_presente "$WPC2/cache" "a pasta cache em si nunca deve ser removida"
assert_presente "$WPC2/cache/pods-alternative-cache/dado.php" \
  "pastas fora da lista devem ser preservadas"
assert_presente "$WPC2/uploads/foto.jpg" "uploads nunca deve ser tocado"

# ---------------------------------------------------------------------------
# Cenário 5: idempotência — rodar de novo não pode falhar
# ---------------------------------------------------------------------------
# shellcheck disable=SC2034  # lida pela função extraída, não por este script
LOCAL_WP_CONTENT="$WPC"
asserts=$((asserts + 1))
if ! clear_cache "local" >/dev/null 2>&1; then
  falhou "clear_cache deve ser idempotente"
fi

# ---------------------------------------------------------------------------
# Cenário 6: o RAMO REMOTO — o que roda em PRODUÇÃO (Locaweb)
#
# Sem este cenário, o branch `is_remote_env` verdadeiro fica 100% descoberto: substituir
# todo o corpo remoto por `true` passava com 17/17 asserções verdes, e nenhum outro teste
# do repo exercita esse caminho (todos stubam clear_cache por inteiro). Uma regressão que
# ZERASSE a limpeza de cache em produção passaria impune — achado do revisor do PR #111.
#
# `remote_run` é stubado para CAPTURAR o comando em vez de executar por SSH.
# ---------------------------------------------------------------------------
REMOTO_CMD="$TMP/remote-cmd.txt"
: > "$REMOTO_CMD"

(
  # Os stubs a seguir são invocados INDIRETAMENTE pela função carregada com `. $FONTE_FN`;
  # o shellcheck não enxerga essa indireção (SC2329).
  # shellcheck disable=SC2329
  is_remote_env() { return 0; }              # força o caminho REMOTO
  # shellcheck disable=SC2329
  wp_path() { printf '/home/storage/f/34/12/siteuonix1/public_html\n'; }
  # shellcheck disable=SC2329
  wp_cli_shell() { printf 'php85 wp-cli.phar\n'; }
  # shellcheck disable=SC2329
  remote_run() { printf '%s\n' "$2" >> "$REMOTO_CMD"; }
  # shellcheck disable=SC2329
  shell_join() { printf '%s\n' "$*"; }
  # shellcheck disable=SC2329
  log() { :; }
  # shellcheck disable=SC1090
  . "$FONTE_FN"
  clear_cache "producao"
) >/dev/null 2>&1

assert_contem "$REMOTO_CMD" "transient delete --all" \
  "o ramo REMOTO precisa limpar transients — é o que roda em produção"
assert_contem "$REMOTO_CMD" "cache flush" \
  "o ramo REMOTO precisa dar flush no object cache"
assert_contem "$REMOTO_CMD" "set -euo pipefail" \
  "o comando remoto precisa abortar em erro não tratado (set -euo pipefail)"

# O caminho do WP precisa viajar em CADA chamada do wp-cli: sem --path o wp-cli age no
# diretório de trabalho do SSH, não na instalação. Contar não basta — as duas chamadas
# precisam ter o --path (uma mutação que removeu só de uma escapava).
asserts=$((asserts + 1))
chamadas_wp=$(grep -c 'transient delete --all\|cache flush' "$REMOTO_CMD" 2>/dev/null || echo 0)
com_path=$(grep -c -- '--path=' "$REMOTO_CMD" 2>/dev/null || echo 0)
if [ "$chamadas_wp" -lt 2 ] || [ "$com_path" -lt 2 ]; then
  falhou "cada chamada do wp-cli no remoto precisa de --path: encontrei $chamadas_wp \
chamada(s) e $com_path com --path. Sem --path o wp-cli age no diretório errado do servidor"
fi

# Cada chamada precisa de `|| true`: limpar cache é best-effort e um wp-cli que falhe no
# servidor não pode abortar o clone com `set -euo pipefail` ativo no comando remoto.
asserts=$((asserts + 1))
com_or_true=$(grep -c '|| true' "$REMOTO_CMD" 2>/dev/null || echo 0)
if [ "$com_or_true" -lt 2 ]; then
  falhou "cada chamada do wp-cli no remoto precisa de '|| true' (achei $com_or_true): com \
'set -euo pipefail' no comando remoto, um wp-cli que falhe abortaria o clone"
fi

# Nenhum `rm -rf` deve ser emitido hoje: cache_dirs está vazio. Um rm -rf construído com
# variável vazia em produção seria catastrófico.
asserts=$((asserts + 1))
if grep -q 'rm -rf' "$REMOTO_CMD"; then
  falhou "com cache_dirs vazio, o comando remoto NÃO deve conter 'rm -rf' — um caminho \
mal montado apagaria dados em produção. Comando emitido: $(cat "$REMOTO_CMD")"
fi

# ---------------------------------------------------------------------------
if [ "$falhas" -ne 0 ]; then
  printf 'FALHOU: %d asserções, %d falhas\n' "$asserts" "$falhas" >&2
  exit 1
fi

printf 'PASS: clear_cache() executada de verdade (%d asserções)\n' "$asserts"
