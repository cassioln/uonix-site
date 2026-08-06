#!/usr/bin/env bash
# Garante que o loader de mu-plugins não derruba o site quando um arquivo
# obrigatório não chegou ao servidor.
#
# MOTIVAÇÃO (risco real, 2026-08-01): mu-plugins/uonix-core.php fazia
# `require_once UONIX_MU_PATH . 'uonix-shared/environment.php'` sem proteção. O
# deploy publica o loader ANTES dos módulos, então existia uma janela de segundos
# em que o core novo estava no disco e o environment.php ainda não. Como
# mu-plugins carregam em TODA requisição, o resultado seria E_COMPILE_ERROR
# fatal: site inteiro fora do ar, incluindo wp-admin. `require` ausente não é
# capturável por try/catch, então a checagem tem de vir antes.
#
# Este teste é estático de propósito: não depende de PHP instalado no host nem de
# container, para poder rodar em qualquer runner do CI.

set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CORE="$ROOT_DIR/mu-plugins/uonix-core.php"

[ -f "$CORE" ] || {
  echo "FALHA: mu-plugins/uonix-core.php não encontrado." >&2
  exit 1
}

failures=0

report() {
  printf '  FALHA %s\n' "$1" >&2
  failures=$((failures + 1))
}

# 1. Nenhum require/include de caminho do próprio projeto pode estar desprotegido.
#    Protegido = existe um guard is_readable()/file_exists() no mesmo bloco lógico,
#    ainda que separado por `try {` ou linhas em branco. Usamos a indentação para
#    delimitar o bloco em vez de uma janela fixa de linhas.
unguarded="$(
  python3 - "$CORE" <<'PY'
import pathlib
import re
import sys

lines = pathlib.Path(sys.argv[1]).read_text(encoding='utf-8').splitlines()
guard = re.compile(r'\b(is_readable|file_exists)\s*\(')
req = re.compile(r'^\s*(require|include)(_once)?\b')


def indent_of(text):
    stripped = text.expandtabs(4)
    return len(stripped) - len(stripped.lstrip())


for index, raw in enumerate(lines):
    if not req.search(raw):
        continue

    # Sobe até o início do bloco que contém este require, procurando um guard.
    # O bloco termina quando encontramos uma linha com indentação MENOR que a de
    # abertura da função/condicional que envolve o require.
    protected = False
    limit = indent_of(raw)
    for previous in reversed(lines[:index]):
        if not previous.strip():
            continue
        if guard.search(previous):
            protected = True
            break
        current = indent_of(previous)
        if current < limit:
            limit = current
            # Chegou à raiz do arquivo sem encontrar guard.
            if current == 0:
                break

    if not protected:
        print(f"{index + 1}:{raw.strip()}")
PY
)"

if [ -n "$unguarded" ]; then
  report "require/include sem is_readable()/file_exists() em uonix-core.php:"
  printf '%s\n' "$unguarded" | sed 's/^/        /' >&2
fi

# 2. A detecção de ambiente precisa sobreviver à ausência de environment.php,
#    e o fallback tem de ser o ambiente MAIS restritivo (production), para não
#    afrouxar política de indexação nem de e-mail por um arquivo faltando.
if ! grep -q "function_exists( 'uonix_resolve_environment' )" "$CORE"; then
  report "uonix-core.php não verifica function_exists('uonix_resolve_environment') antes de usá-la."
fi

if ! grep -A4 "function_exists( 'uonix_resolve_environment' )" "$CORE" | grep -q "return 'production'"; then
  report "o fallback de ambiente não é 'production' (deve ser o mais restritivo)."
fi

# 3. A ausência precisa ser observável: sem log, o defeito fica invisível.
if ! grep -q "error_log( 'UONIX MU: uonix-shared/environment.php ausente" "$CORE"; then
  report "a ausência de environment.php não é registrada em error_log."
fi

if [ "$failures" -ne 0 ]; then
  printf 'FALHA: %s problema(s) na proteção do loader de mu-plugins.\n' "$failures" >&2
  exit 1
fi

echo 'PASS: loader de mu-plugins degrada sem derrubar o site e usa fallback restritivo.'
