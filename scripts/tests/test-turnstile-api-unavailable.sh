#!/usr/bin/env bash
# Garante que o Turnstile nunca deixa um formulário público travado em silêncio.
#
# MOTIVAÇÃO: o listener de submit chama preventDefault() e espera o token. Se o
# api.js da Cloudflare não carregar — adblock, firewall corporativo, DNS filtrado
# — o formulário nunca era enviado e NENHUMA mensagem aparecia: o botão parecia
# morto. Nos formulários públicos (lead, newsletter, trabalhe-conosco) isso é
# perda de lead sem rastro. O `catch` estava vazio, engolindo o erro.
#
# Achado MÉDIO da revisão independente do PR #40, registrado no card t_f9c3e19c.
#
# Este guarda falha se alguém reintroduzir o catch vazio, remover a liberação do
# submit pendente, ou passar a confiar apenas no JavaScript — a validação
# server-side é o que de fato barra request sem token e não pode sair.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MODULE="$ROOT_DIR/mu-plugins/uonix-integrations/34-turnstile-custom-forms.php"

if [[ ! -f "$MODULE" ]]; then
  printf 'FAIL: módulo do Turnstile não encontrado em %s\n' "$MODULE" >&2
  exit 1
fi

failures=0

fail() {
  printf 'FAIL: %s\n' "$1" >&2
  failures=$((failures + 1))
}

# 1. O catch de prepare() não pode ser vazio.
if grep -qE '\.catch\(function\(\)\s*\{\s*\}\)' "$MODULE"; then
  fail 'catch vazio reintroduzido: falha ao carregar o api.js volta a travar o formulário em silêncio'
fi

# 2. Precisa existir a liberação do submit pendente.
if ! grep -q 'releasePendingSubmit' "$MODULE"; then
  fail 'releasePendingSubmit ausente: nada destrava o formulário quando o desafio não carrega'
fi

# 3. O submit pendente marcado pelo listener tem de ser efetivamente reenviado.
if ! grep -qE 'requestSubmit|form\.submit\(\)' "$MODULE"; then
  fail 'nenhum reenvio do formulário: preventDefault sem contrapartida trava o usuário'
fi

# 4. A flag que o listener grava precisa ser a mesma que a liberação limpa.
listener_flag="$(grep -oE "dataset\.uonixTurnstilePendingSubmit" "$MODULE" | wc -l | tr -d ' ')"
if [[ "$listener_flag" -lt 3 ]]; then
  fail "flag uonixTurnstilePendingSubmit aparece $listener_flag vez(es): listener e liberação devem usar a mesma flag"
fi

# 5. A falha do JS não pode virar bypass: a validação server-side é obrigatória.
#    Sem ela, liberar o submit abriria brecha real.
if ! grep -q 'function uonix_turnstile_validate_request' "$MODULE"; then
  fail 'validador server-side ausente: liberar o submit sem ele seria bypass'
fi

if ! grep -q 'uonix_turnstile_send_json_error_if_invalid' "$MODULE"; then
  fail 'helper de recusa server-side ausente: formulários AJAX ficariam sem gate'
fi

# 6. O aviso ao console é o único rastro que sobra para diagnóstico.
if ! grep -q 'console.warn' "$MODULE"; then
  fail 'sem console.warn: indisponibilidade do desafio ficaria sem rastro algum'
fi

if (( failures > 0 )); then
  exit 1
fi

printf 'PASS: indisponibilidade do Turnstile não trava formulário público nem vira bypass.\n'
