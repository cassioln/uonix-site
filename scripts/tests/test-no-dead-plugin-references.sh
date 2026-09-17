#!/usr/bin/env bash
# Impede que plugins descontinuados voltem ao código versionado ou à documentação.
#
# POR QUE ESTE TESTE EXISTE
#
# Cinco plugins saíram do site — WP Rocket, Loginizer, Backuply, SpeedyCache e
# LiteSpeed Cache — e cada um deixou resíduos que sobreviveram meses. Os resíduos
# não são cosméticos:
#
#   - `39-admin-editor-dashboard.php` chamava `rocket_clean_domain()` guardado por
#     `function_exists`, então o botão "Limpar Memória do Site" continuava verde
#     enquanto limpava menos do que o rótulo prometia;
#   - `test-husky-mobile-drawer-geometry.php` chegou a EXIGIR `rocket_clean_domain`
#     no código, transformando o resíduo em contrato e bloqueando a limpeza;
#   - `clone-ambientes.md` descrevia o clone apagando um diretório `wp-rocket` que
#     `clear_cache()` não apaga mais;
#   - `local/README.md` afirmava que o clone preserva options do Loginizer, que já
#     não estão em `protected_options_where()`;
#   - a §7 do runbook Locaweb apresentava o SpeedyCache como peculiaridade VIVA do
#     host, com comando para um diretório inexistente — o mesmo diagnóstico errado
#     que a própria seção alertava.
#
# Uma chamada guardada por `function_exists` nunca quebra o site: ela silenciosamente
# não faz nada. É exatamente por isso que precisa de guard automatizado — não há
# sintoma para observar.
#
# ESCOPO
#
# Somente arquivos VERSIONADOS, enumerados por `git ls-files`. Isso é deliberado, e
# não apenas conveniente: `scripts/maintenance/local/`, `local/wp-content/`,
# `.worktrees/`, `tmp/` e `docs/superpowers/` são runtime e contexto de trabalho
# ignorados pelo Git, e contêm scripts operacionais antigos que legitimamente falam
# desses plugins. Delegar ao `.gitignore` mantém o escopo correto sozinho, em vez de
# depender de uma lista de `--exclude-dir` que envelhece.
#
# ESTE ARQUIVO se exclui do próprio grep: ele necessariamente contém os nomes que
# proíbe. Sem a exclusão, o teste reprovaria em si mesmo.
#
# Compatível com Bash 3.2 (macOS): sem `mapfile`, sem arrays associativos.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SELF_NAME="$(basename "${BASH_SOURCE[0]}")"
SELF_REL="scripts/tests/${SELF_NAME}"

# Alvos versionados. `local/README.md` entra explicitamente porque o resto de
# `local/` é runtime não versionado.
ALVOS=(
  mu-plugins
  themes
  scripts
  docs
  .github
  local/README.md
  README.md
)

# Cada entrada é "termo<TAB>explicação". O termo é um padrão ERE case-insensitive.
#
# `litespeed` é buscado como nome de PLUGIN (`litespeed_purge_all`,
# `LiteSpeed_Cache_API`, `litespeed-cache`), não como servidor web: o texto
# "Apache/LiteSpeed" em 33-form-trabalhe-conosco.php descreve o servidor e é
# legítimo. Por isso o padrão exige o separador de identificador.
TERMOS=(
  'wp[-_]rocket	WP Rocket (removido em 2026-08-15)'
  'rocket_clean	WP Rocket (removido em 2026-08-15)'
  'loginizer	Loginizer (descontinuado)'
  'backuply	Backuply (descontinuado)'
  'speedy[-_ ]?cache	SpeedyCache (descontinuado)'
  'litespeed[-_]	LiteSpeed Cache (descontinuado)'
  'litespeed_purge	LiteSpeed Cache (descontinuado)'
)

falhas=0
verificados=0

cd "$ROOT_DIR"

command -v git >/dev/null 2>&1 || { printf 'FAIL: git não encontrado.\n' >&2; exit 1; }
git rev-parse --is-inside-work-tree >/dev/null 2>&1 \
  || { printf 'FAIL: não é um repositório Git; o escopo do teste depende de git ls-files.\n' >&2; exit 1; }

LISTA="$(mktemp "${TMPDIR:-/tmp}/uonix-dead-plugins.XXXXXX")"
trap 'rm -f "$LISTA"' EXIT
git ls-files -z -- "${ALVOS[@]}" > "$LISTA"

# Sanidade: uma lista vazia faria todos os termos "passarem" sem varrer nada.
[ -s "$LISTA" ] || { printf 'FAIL: git ls-files não retornou arquivo algum nos alvos.\n' >&2; exit 1; }

for entrada in "${TERMOS[@]}"; do
  termo="${entrada%%	*}"
  motivo="${entrada#*	}"
  verificados=$((verificados + 1))

  # `/dev/null` extra força o grep a prefixar o nome do arquivo mesmo quando o xargs
  # entrega um único caminho. `|| true`: grep sai 1 quando não encontra nada, que é
  # o caso de sucesso aqui.
  achados="$(
    xargs -0 grep -niIE "$termo" /dev/null < "$LISTA" 2>/dev/null \
      | grep -v "^${SELF_REL}:" || true
  )"

  if [ -n "$achados" ]; then
    printf 'FAIL: referência a %s reapareceu:\n' "$motivo" >&2
    printf '%s\n' "$achados" >&2
    falhas=$((falhas + 1))
  fi
done

if [ "$falhas" -ne 0 ]; then
  printf '\n%d termo(s) proibido(s) encontrado(s). Se um destes plugins voltar a ser\n' "$falhas" >&2
  printf 'usado de propósito, remova o termo da lista TERMOS deste teste no mesmo\n' >&2
  printf 'commit que reintroduz o plugin — e diga por quê na mensagem.\n' >&2
  exit 1
fi

printf 'PASS: nenhuma referência a plugin descontinuado (%d termos verificados).\n' "$verificados"
