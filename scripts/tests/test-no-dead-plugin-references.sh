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
#     host, com comando para um diretório inexistente.
#
# Uma chamada guardada por `function_exists` nunca quebra o site: ela silenciosamente
# não faz nada. É exatamente por isso que precisa de guard automatizado — não há
# sintoma para observar.
#
# DUAS FASES, e a primeira existe por um motivo concreto
#
# Fase 1 prova que cada padrão casa o nome que promete casar; fase 2 varre o repo.
#
# Sem a fase 1, este teste tem um modo de falha SILENCIOSO: se um padrão for
# corrompido (por edição, por normalização de espaços em branco, por um `sed` mal
# feito), ele deixa de casar, a varredura não encontra nada e o teste passa —
# reportando ausência quando na verdade não procurou. É a mesma classe de defeito
# que o PR inteiro corrige: verde sem ter feito o trabalho.
#
# Os três arrays são PARALELOS de propósito, em vez de uma string com separador.
# Um separador invisível (TAB) funciona, mas some num diff e vira espaço em qualquer
# formatador — e a corrupção seria justamente do tipo que passa em silêncio.
#
# ESCOPO
#
# Somente arquivos VERSIONADOS, enumerados por `git ls-files`. Isso é deliberado, e
# não apenas conveniente: `scripts/maintenance/local/`, `local/wp-content/`,
# `.worktrees/`, `tmp/` e `docs/superpowers/` são runtime e contexto de trabalho
# ignorados pelo Git, e contêm scripts operacionais antigos que legitimamente falam
# desses plugins — inclusive o script que auditou e removeu o LiteSpeed. Delegar ao
# `.gitignore` mantém o escopo correto sozinho, em vez de depender de uma lista de
# `--exclude-dir` que envelhece.
#
# ESTE ARQUIVO se exclui da varredura: ele necessariamente contém os nomes que
# proíbe. Sem a exclusão, o teste reprovaria em si mesmo.
#
# Compatível com Bash 3.2 (macOS): sem `mapfile`, sem arrays associativos.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SELF_NAME="$(basename "${BASH_SOURCE[0]}")"
SELF_REL="scripts/tests/${SELF_NAME}"

cd "$ROOT_DIR"

# Padrões ERE, aplicados case-insensitive.
#
# `litespeed[-_]` exige o separador de identificador de propósito: busca o nome do
# PLUGIN (`litespeed_purge_all`, `litespeed-cache`) e não o do SERVIDOR WEB. O texto
# "Apache/LiteSpeed" em 33-form-trabalhe-conosco.php é legítimo e está nos negativos.
TERMOS=(
  'wp[-_]rocket'
  'rocket_clean'
  'loginizer'
  'backuply'
  'speedy[-_ ]?cache'
  'litespeed[-_]'
  'litespeed_purge'
)

MOTIVOS=(
  'WP Rocket (removido de produção em 2026-08-15)'
  'WP Rocket (removido de produção em 2026-08-15)'
  'Loginizer (descontinuado)'
  'Backuply (descontinuado)'
  'SpeedyCache (descontinuado)'
  'LiteSpeed Cache (descontinuado)'
  'LiteSpeed Cache (descontinuado)'
)

# Exemplo canônico que cada padrão TEM de casar. É o que torna a fase 1 uma prova, e
# não uma declaração de intenção.
EXEMPLOS=(
  'wp-rocket'
  'rocket_clean_domain'
  'loginizer-admin-shortcut'
  'backuply'
  'SpeedyCache'
  'litespeed-cache'
  'litespeed_purge_all'
)

# Textos legítimos que NENHUM padrão pode casar. Guardam contra um padrão largo
# demais, que transformaria o guard num bloqueio de texto correto.
NEGATIVOS=(
  'Apache/LiteSpeed'
  'O .htaccess bloqueia acesso direto em servidores Apache/LiteSpeed.'
  'skyrocket'
  'rocketship'
)

falhas=0
UONIX_ACHADOS_TERMOS=0
UONIX_VARREDURA_ERRO=0

reprova() {
  printf 'FAIL: %s\n' "$1" >&2
  falhas=$((falhas + 1))
}

# Varre a lista de caminhos NUL-separados em $1 e reporta cada termo encontrado.
#
# Existe como função para que a fase 3 exercite ESTE código, e não uma reimplementação
# — um autoteste que duplica a lógica prova apenas que a cópia funciona.
#
# Define UONIX_ACHADOS_TERMOS com quantos termos tiveram ocorrência. `$2` opcional
# silencia a saída (usado pela fase 3, onde encontrar é o resultado esperado).
varrer_lista() {
  local lista="$1"
  local silencioso="${2:-}"
  local indice=0
  local termo motivo achados

  UONIX_ACHADOS_TERMOS=0

  while [ "$indice" -lt "${#TERMOS[@]}" ]; do
    termo="${TERMOS[$indice]}"
    motivo="${MOTIVOS[$indice]}"

    # `/dev/null` extra força o grep a prefixar o nome do arquivo mesmo quando o
    # xargs entrega um único caminho. `|| true`: grep sai 1 quando não encontra
    # nada, que é o caso de sucesso na varredura real.
    #
    # O stderr é CAPTURADO em vez de descartado. Com `2>/dev/null`, uma falha real de
    # grep ou xargs (arquivo ilegível, limite de argumentos, binário ausente) chegava
    # aqui indistinguível de "nada encontrado" — a mesma classe de verde-sem-trabalho
    # que este teste existe para impedir. O xargs mascara o código de saída do grep
    # (devolve 123 para qualquer 1..125), então o stderr é o sinal utilizável.
    erros="$(mktemp "${TMPDIR:-/tmp}/uonix-varredura-erros.XXXXXX")"
    achados="$(
      xargs -0 grep -niIE -- "$termo" /dev/null < "$lista" 2>"$erros" \
        | grep -v "^${SELF_REL}:" || true
    )"

    if [ -s "$erros" ]; then
      printf 'FAIL: a varredura do termo <%s> emitiu erro; o resultado não é confiável:\n' "$termo" >&2
      cat "$erros" >&2
      rm -f "$erros"
      UONIX_VARREDURA_ERRO=1
      return 1
    fi
    rm -f "$erros"

    if [ -n "$achados" ]; then
      UONIX_ACHADOS_TERMOS=$((UONIX_ACHADOS_TERMOS + 1))
      if [ -z "$silencioso" ]; then
        printf 'FAIL: referência a %s reapareceu:\n' "$motivo" >&2
        printf '%s\n' "$achados" >&2
      fi
    fi

    indice=$((indice + 1))
  done
}

# ---------------------------------------------------------------------------
# Fase 1 — os padrões realmente procuram o que dizem procurar
# ---------------------------------------------------------------------------
if [ "${#TERMOS[@]}" -ne "${#MOTIVOS[@]}" ] || [ "${#TERMOS[@]}" -ne "${#EXEMPLOS[@]}" ]; then
  reprova "TERMOS, MOTIVOS e EXEMPLOS têm tamanhos diferentes (${#TERMOS[@]}/${#MOTIVOS[@]}/${#EXEMPLOS[@]}); \
os arrays são paralelos e precisam andar juntos"
  printf 'FALHOU\n' >&2
  exit 1
fi

if [ "${#TERMOS[@]}" -eq 0 ]; then
  reprova 'TERMOS está vazio: a varredura passaria sem procurar nada'
fi

indice=0
while [ "$indice" -lt "${#TERMOS[@]}" ]; do
  termo="${TERMOS[$indice]}"
  exemplo="${EXEMPLOS[$indice]}"

  if ! printf '%s\n' "$exemplo" | grep -qiE -- "$termo"; then
    reprova "o padrão <${termo}> não casa o próprio exemplo canônico <${exemplo}>: \
a varredura abaixo reportaria ausência sem ter procurado"
  fi

  indice=$((indice + 1))
done

for negativo in "${NEGATIVOS[@]}"; do
  indice=0
  while [ "$indice" -lt "${#TERMOS[@]}" ]; do
    termo="${TERMOS[$indice]}"
    if printf '%s\n' "$negativo" | grep -qiE -- "$termo"; then
      reprova "o padrão <${termo}> casa o texto legítimo <${negativo}>: largo demais, \
bloquearia conteúdo correto"
    fi
    indice=$((indice + 1))
  done
done

if [ "$falhas" -ne 0 ]; then
  printf '\nFALHOU: os padrões não estão íntegros; a varredura não seria confiável.\n' >&2
  exit 1
fi

# ---------------------------------------------------------------------------
# Fase 2 — varredura dos arquivos versionados
# ---------------------------------------------------------------------------
command -v git >/dev/null 2>&1 || { printf 'FAIL: git não encontrado.\n' >&2; exit 1; }
git rev-parse --is-inside-work-tree >/dev/null 2>&1 \
  || { printf 'FAIL: não é um repositório Git; o escopo do teste depende de git ls-files.\n' >&2; exit 1; }

# `local/README.md` entra explicitamente porque o resto de `local/` é runtime.
ALVOS=(
  mu-plugins
  themes
  scripts
  docs
  .github
  local/README.md
  README.md
)

LISTA="$(mktemp "${TMPDIR:-/tmp}/uonix-dead-plugins.XXXXXX")"
trap 'rm -f "$LISTA"' EXIT
git ls-files -z -- "${ALVOS[@]}" > "$LISTA"

# Sanidade: lista vazia faria todos os termos "passarem" sem varrer arquivo algum.
[ -s "$LISTA" ] || { printf 'FAIL: git ls-files não retornou arquivo algum nos alvos.\n' >&2; exit 1; }

varrer_lista "$LISTA" || true
falhas=$((falhas + UONIX_ACHADOS_TERMOS))

# Erro de encanamento não é "nada encontrado": aborta em vez de reportar limpo.
if [ "$UONIX_VARREDURA_ERRO" -ne 0 ]; then
  printf '\nFALHOU: a varredura falhou tecnicamente; não é possível afirmar ausência.\n' >&2
  exit 1
fi

if [ "$falhas" -ne 0 ]; then
  printf '\n%d termo(s) proibido(s) encontrado(s). Se um destes plugins voltar a ser\n' "$falhas" >&2
  printf 'usado de propósito, remova a entrada correspondente de TERMOS/MOTIVOS/EXEMPLOS\n' >&2
  printf 'no mesmo commit que reintroduz o plugin — e diga por quê na mensagem.\n' >&2
  exit 1
fi

# ---------------------------------------------------------------------------
# Fase 3 — a varredura REALMENTE reprova quando os termos estão presentes
#
# Fase 1 prova que os padrões casam seus exemplos. Isso não prova que o encanamento
# (git ls-files -> xargs -> grep -> filtro do próprio arquivo) reporta o achado.
#
# Um teste que só verifica ausência é indistinguível de um teste que não procura:
# os dois passam. Aqui a fase 2 acabou de reportar "nada encontrado"; esta fase
# monta um repositório git temporário com TODOS os termos proibidos e exige que a
# MESMA função varrer_lista encontre todos.
# ---------------------------------------------------------------------------
FIXTURE_DIR="$(mktemp -d "${TMPDIR:-/tmp}/uonix-dead-plugins-fixture.XXXXXX")"
trap 'rm -f "$LISTA"; rm -rf "$FIXTURE_DIR"' EXIT

(
  cd "$FIXTURE_DIR"
  git init --quiet .
  {
    printf 'Fixture do autoteste. Cada linha carrega um termo proibido.\n'
    for exemplo in "${EXEMPLOS[@]}"; do
      printf '%s\n' "$exemplo"
    done
  } > residuos.txt
  git add residuos.txt
) >/dev/null 2>&1

FIXTURE_LISTA="${FIXTURE_DIR}/lista"
git -C "$FIXTURE_DIR" ls-files -z -- residuos.txt \
  | tr '\0' '\n' \
  | while IFS= read -r caminho; do
      [ -n "$caminho" ] || continue
      printf '%s\0' "${FIXTURE_DIR}/${caminho}"
    done > "$FIXTURE_LISTA"

if [ ! -s "$FIXTURE_LISTA" ]; then
  reprova 'não consegui montar o fixture do autoteste; a fase 3 ficaria sem cobertura'
else
  varrer_lista "$FIXTURE_LISTA" silencioso || true

  if [ "$UONIX_ACHADOS_TERMOS" -ne "${#TERMOS[@]}" ]; then
    reprova "a varredura encontrou ${UONIX_ACHADOS_TERMOS} de ${#TERMOS[@]} termos num fixture \
que contém TODOS eles — o encanamento da varredura não está reportando achados, então a \
fase 2 passaria por não procurar, não por estar limpa"
  fi
fi

if [ "$falhas" -ne 0 ]; then
  printf '\nFALHOU: o autoteste da varredura não passou.\n' >&2
  exit 1
fi

printf 'PASS: padrões íntegros (%d termos, %d negativos), varredura comprovada em fixture e nenhuma referência a plugin descontinuado.\n' \
  "${#TERMOS[@]}" "${#NEGATIVOS[@]}"
