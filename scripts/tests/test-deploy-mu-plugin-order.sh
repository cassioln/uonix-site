#!/usr/bin/env bash
# Garante que os workflows de deploy publicam os MÓDULOS de mu-plugins antes do
# LOADER (uonix-core.php).
#
# MOTIVAÇÃO (risco real, 2026-08-01): uonix-core.php requer
# uonix-shared/environment.php. Se o loader chegar ao servidor primeiro, existe uma
# janela de segundos em que o core novo está no disco e a dependência ainda não —
# e mu-plugins carregam em TODA requisição. Antes do hardening isso derrubava o
# site inteiro (E_COMPILE_ERROR, inclusive wp-admin).
#
# O loader hoje tolera a ausência (is_readable + fallback para 'production', ver
# scripts/tests/test-mu-loader-resilience.sh), mas publicar na ordem correta FECHA
# a janela em vez de apenas sobreviver a ela: durante o intervalo, o site rodaria
# com detecção de ambiente degradada.
#
# Este teste é estático: compara a posição das linhas de rsync em cada workflow.

set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

failures=0

report() {
  printf '  FALHA %s\n' "$1" >&2
  failures=$((failures + 1))
}

# Para cada workflow que publica mu-plugins, a linha do rsync do core precisa vir
# DEPOIS da linha do rsync dos módulos.
check_workflow() {
  local file="$1"
  local path="$ROOT_DIR/.github/workflows/$file"

  [ -f "$path" ] || {
    report "workflow não encontrado: $file"
    return
  }

  # Só analisa workflows que realmente publicam o loader.
  grep -q 'uonix-core\.php' "$path" || return

  # Linha do rsync que envia o loader (o argumento de origem do rsync, não o backup).
  local core_line
  core_line="$(grep -n '^\s*\.deploy/mu-plugins/uonix-core\.php' "$path" | head -1 | cut -d: -f1)"

  # Linha do rsync que envia um diretório de módulo. Os dois workflows escrevem a
  # origem de formas diferentes: "$module/" (deploy-production) e
  # ".deploy/mu-plugins/$module_name/" (_deploy-hostgator). Usamos a linha do
  # `for module ... in .deploy/mu-plugins/uonix-*` que CONTÉM um rsync no corpo,
  # o que cobre ambos sem depender da grafia da origem.
  local module_line
  module_line="$(
    awk '
      /^[[:space:]]*for module(_name)? in/ { in_loop = 1; loop_start = NR }
      in_loop && /rsync -az/ { print loop_start; exit }
      /^[[:space:]]*done[[:space:]]*$/ { in_loop = 0 }
    ' "$path"
  )"

  if [ -z "$core_line" ]; then
    report "$file: não foi possível localizar o rsync do loader uonix-core.php."
    return
  fi

  if [ -z "$module_line" ]; then
    report "$file: não foi possível localizar o rsync dos módulos uonix-*."
    return
  fi

  if [ "$core_line" -lt "$module_line" ]; then
    report "$file: o loader é publicado na linha $core_line, ANTES dos módulos (linha $module_line). Inverta: módulos primeiro, loader depois."
  fi
}

check_workflow 'deploy-production.yml'
check_workflow '_deploy-hostgator.yml'

if [ "$failures" -ne 0 ]; then
  printf 'FALHA: %s workflow(s) publicam o loader antes das suas dependências.\n' "$failures" >&2
  exit 1
fi

echo 'PASS: deploys publicam módulos de mu-plugins antes do loader.'
