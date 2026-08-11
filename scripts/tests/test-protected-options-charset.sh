#!/usr/bin/env bash
# Regressão: o transporte de opções protegidas do clone precisa ser
# multibyte-safe.
#
# Bug real (2026-08-10): `fluentmail-settings` chegou ao DEV truncado em 283 de
# 1202 bytes, cortado no meio do `Ô` de "SITE UÔNIX" (0xC3 0x94). O
# `unserialize()` do PHP então devolvia `false`, `get_option()` retornava a
# string crua em vez do array, e o painel do Fluent SMTP ficava em loading
# infinito ao salvar.
#
# Causa: `snapshot_options()` invocava o cliente mysql SEM
# `--default-character-set=utf8mb4`. A conexão caía no default do servidor
# (medido: latin1 no MySQL 5.7.44-48 da HostGator), então o valor utf8mb4 era
# convertido/cortado na saída. O caminho de RESTORE já usava utf8mb4 — só o
# DUMP não usava, e a assimetria é exatamente o que quebra o round-trip.
#
# Este teste NÃO acessa banco nem rede: ele afirma sobre os argumentos que o
# script monta, que é onde o defeito vivia.
set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "${script_dir}/../.." && pwd)"
clone_script="${repo_root}/scripts/clone-environment.sh"

test -f "$clone_script" || {
  printf 'FALHA: %s não encontrado\n' "$clone_script" >&2
  exit 1
}

failures=0

fail() {
  printf 'FALHA: %s\n' "$1" >&2
  failures=$((failures + 1))
}

pass() {
  printf 'ok: %s\n' "$1"
}

# Extrai o corpo de uma função do script, para asserção isolada.
extract_function() {
  local name="$1"
  sed -n "/^${name}() {/,/^}/p" "$clone_script"
}

# ---------------------------------------------------------------------------
# 1. O dump REMOTO das opções protegidas carrega charset explícito.
# ---------------------------------------------------------------------------
snapshot_body="$(extract_function snapshot_options)"

if [ -z "$snapshot_body" ]; then
  fail 'não foi possível extrair snapshot_options'
else
  if printf '%s' "$snapshot_body" | grep -q -- '--default-character-set=utf8mb4'; then
    pass 'snapshot_options: dump remoto usa --default-character-set=utf8mb4'
  else
    fail 'snapshot_options: dump remoto SEM --default-character-set=utf8mb4 (trunca multibyte)'
  fi
fi

# ---------------------------------------------------------------------------
# 2. O dump LOCAL das opções protegidas carrega charset explícito.
# ---------------------------------------------------------------------------
local_dump_body="$(extract_function local_db_dump_options)"

if [ -z "$local_dump_body" ]; then
  fail 'não foi possível extrair local_db_dump_options'
else
  if printf '%s' "$local_dump_body" | grep -q -- '--default-character-set=utf8mb4'; then
    pass 'local_db_dump_options: usa --default-character-set=utf8mb4'
  else
    fail 'local_db_dump_options: SEM --default-character-set=utf8mb4 (trunca multibyte)'
  fi
fi

# ---------------------------------------------------------------------------
# 3. O dump completo do banco local também é multibyte-safe.
# ---------------------------------------------------------------------------
local_db_dump_body="$(extract_function local_db_dump)"

if [ -z "$local_db_dump_body" ]; then
  fail 'não foi possível extrair local_db_dump'
else
  if printf '%s' "$local_db_dump_body" | grep -q -- '--default-character-set=utf8mb4'; then
    pass 'local_db_dump: usa --default-character-set=utf8mb4'
  else
    fail 'local_db_dump: SEM --default-character-set=utf8mb4'
  fi
fi

# ---------------------------------------------------------------------------
# 4. Simetria dump/restore. Um lado em utf8mb4 e o outro no default do
#    servidor é precisamente a assimetria que corrompeu o DEV.
# ---------------------------------------------------------------------------
restore_body="$(extract_function restore_options)"

if [ -z "$restore_body" ]; then
  fail 'não foi possível extrair restore_options'
else
  if printf '%s' "$restore_body" | grep -q -- '--default-character-set=utf8mb4'; then
    pass 'restore_options: usa --default-character-set=utf8mb4'
  else
    fail 'restore_options: SEM --default-character-set=utf8mb4'
  fi
fi

# ---------------------------------------------------------------------------
# 5. Nenhuma invocação de cliente mysql/mariadb com --raw pode ficar sem
#    charset. `--raw` desliga o escaping, então é o modo em que a conversão
#    silenciosa de charset se torna perda de bytes.
# ---------------------------------------------------------------------------
raw_without_charset=0
while IFS= read -r fn; do
  body="$(extract_function "$fn")"
  printf '%s' "$body" | grep -q -- '--raw' || continue
  if ! printf '%s' "$body" | grep -q -- '--default-character-set=utf8mb4'; then
    fail "função ${fn}() usa --raw sem --default-character-set=utf8mb4"
    raw_without_charset=$((raw_without_charset + 1))
  fi
done < <(grep -oE '^[a-z_]+\(\) \{' "$clone_script" | sed 's/() {//')

if [ "$raw_without_charset" -eq 0 ]; then
  pass 'nenhuma função usa --raw sem charset explícito'
fi

# ---------------------------------------------------------------------------
# 6. A opção do Fluent SMTP continua na lista de proteção. A proteção não é
#    suficiente sozinha (era ela que estava sendo preservada pela metade), mas
#    removê-la reintroduziria perda total de configuração.
# ---------------------------------------------------------------------------
protected_body="$(extract_function protected_options_where)"

for pattern in 'fluentmail' 'smtp'; do
  if printf '%s' "$protected_body" | grep -q "$pattern"; then
    pass "protected_options_where cobre '${pattern}'"
  else
    fail "protected_options_where NÃO cobre '${pattern}'"
  fi
done

# ---------------------------------------------------------------------------
# 7. Prova de que o mecanismo do bug é real, sem depender de banco: cortar um
#    serialize PHP no meio de um multibyte produz exatamente o sintoma
#    observado (unserialize falha).
# ---------------------------------------------------------------------------
if command -v php >/dev/null 2>&1; then
  # O código PHP vai deliberadamente entre aspas simples: as variáveis são do
  # PHP, não do shell, e não devem sofrer expansão aqui.
  # shellcheck disable=SC2016
  php_result="$(php -r '
    $valor = ["mappings" => ["site@x.dev" => "abc"], "nome" => "SITE UÔNIX"];
    $ok = serialize($valor);
    // 0xC3 0x94 = Ô. Cortar entre os dois bytes reproduz o corte medido.
    $pos = strpos($ok, "\xC3\x94");
    if ($pos === false) { echo "SEM_MULTIBYTE"; exit; }
    $truncado = substr($ok, 0, $pos + 1);
    $r1 = @unserialize($ok);
    $r2 = @unserialize($truncado);
    printf("intacto=%s truncado=%s",
      is_array($r1) ? "array" : gettype($r1),
      is_array($r2) ? "array" : gettype($r2));
  ' 2>/dev/null)"

  case "$php_result" in
    'intacto=array truncado=boolean')
      pass 'mecanismo confirmado: corte em multibyte faz unserialize devolver false'
      ;;
    'SEM_MULTIBYTE')
      printf 'aviso: PHP não produziu multibyte esperado; asserção pulada\n'
      ;;
    *)
      fail "mecanismo não reproduziu como esperado: ${php_result}"
      ;;
  esac
else
  printf 'aviso: php ausente; asserção de mecanismo pulada\n'
fi

# ---------------------------------------------------------------------------
if [ "$failures" -ne 0 ]; then
  printf '\n%s asserção(ões) falharam\n' "$failures" >&2
  exit 1
fi

printf '\nTodas as asserções passaram.\n'
