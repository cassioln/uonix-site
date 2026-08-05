#!/usr/bin/env bash
# O smoke pós-clone não pode derrubar um clone inteiro — e disparar rollback — por
# um bloqueio transitório de WAF. O Mod_Security do HostGator responde 406/409 a
# requisições automatizadas em wp-login.php mesmo com o site saudável: foi o que
# fez o clone real qa->dev falhar depois de TODA a mutação ter dado certo.
#
# Mas tolerar não pode virar cegueira: erro real (5xx, 404, conexão recusada)
# continua reprovando, e um WAF que NUNCA libera também reprova.
set -uo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
CLONE_SCRIPT="${ROOT_DIR}/scripts/clone-environment.sh"
export UONIX_CLONE_LIBRARY_ONLY=1

TMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/uonix-smoke.XXXXXX")" || exit 1
trap 'rm -rf -- "$TMP_ROOT"' EXIT

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

# Mock de curl controlado por arquivo: emite os códigos em sequência, um por
# chamada, para simular WAF que libera (ou não) na tentativa seguinte.
run_smoke() {
  local codes="$1"
  printf '%s\n' "$codes" | tr ' ' '\n' > "${TMP_ROOT}/codes"
  : > "${TMP_ROOT}/calls"
  # Estado do caso anterior faria um ABORT parecer sucesso herdado.
  rm -f "${TMP_ROOT}/exit"
  (
    set +e
    # shellcheck disable=SC1090,SC1091
    . "$CLONE_SCRIPT" >/dev/null 2>&1

    # shellcheck disable=SC2329
    curl() {
      local n code
      printf 'x\n' >> "${TMP_ROOT}/calls"
      n="$(wc -l < "${TMP_ROOT}/calls" | tr -d ' ')"
      code="$(sed -n "${n}p" "${TMP_ROOT}/codes")"
      # Sem código para esta tentativa, a sequência acabou: mantém o ÚLTIMO
      # código declarado. Assim '406 406 ...' representa WAF que nunca libera,
      # em vez de virar sucesso silencioso na tentativa seguinte.
      [ -n "$code" ] || code="$(grep -v '^[[:space:]]*$' "${TMP_ROOT}/codes" | tail -n 1)"
      case "$code" in
        ERRO) return 7 ;;
      esac
      printf '%s' "$code"
      return 0
    }
    # Não dormir de verdade dentro do teste.
    # shellcheck disable=SC2329
    sleep() { :; }

    validate_http_endpoint 'wp-login' 'https://test.uonix.ksio.dev/wp-login.php' >/dev/null 2>&1
    printf '%s' "$?" > "${TMP_ROOT}/exit"
  ) >/dev/null 2>&1
  # `die` encerra o subshell, então a linha do printf acima pode nunca rodar.
  # Ausência do arquivo significa aborto — que é justamente o veredito de falha.
  local recorded
  if [ -f "${TMP_ROOT}/exit" ]; then
    recorded="$(cat "${TMP_ROOT}/exit")"
  else
    recorded='ABORT'
  fi
  printf 'EXIT=%s CALLS=%s' \
    "$recorded" \
    "$(wc -l < "${TMP_ROOT}/calls" | tr -d ' ')"
}

# 1) Bloqueio de WAF que libera na segunda tentativa: o clone deve seguir.
result="$(run_smoke '406 200')"
case "$result" in
  'EXIT=0 CALLS=2') ;;
  *) fail "WAF transitório (406 depois 200) deveria passar com retry; obtive: ${result}" ;;
esac

# 2) O 409 observado no clone real recebe o mesmo tratamento.
result="$(run_smoke '409 200')"
case "$result" in
  'EXIT=0 CALLS=2') ;;
  *) fail "409 transitório deveria passar com retry; obtive: ${result}" ;;
esac

# 3) WAF que NUNCA libera precisa reprovar: tolerar sempre esconderia queda real.
result="$(run_smoke '406 406 406 406 406 406')"
case "$result" in
  EXIT=0*) fail "bloqueio permanente de WAF foi aceito: ${result}" ;;
  EXIT=*\ CALLS=1) fail "bloqueio permanente não tentou novamente: ${result}" ;;
esac

# 4) Erro de servidor real também é retentado, mas reprova se persistir.
result="$(run_smoke '500 500 500 500 500 500')"
case "$result" in
  EXIT=0*) fail "HTTP 500 persistente foi aceito: ${result}" ;;
esac

# 5) 404 é veredito de conteúdo, não indisponibilidade: reprova sem gastar retry.
#    Exigir CALLS=1 é essencial: se 404 cair no ramo transitório, o smoke tenta
#    5 vezes e uma sequência com 200 depois acabaria APROVANDO página ausente.
result="$(run_smoke '404 200 200 200')"
case "$result" in
  EXIT=0*) fail "HTTP 404 foi aceito no smoke: ${result}" ;;
  'EXIT=1 CALLS=1'|'EXIT=ABORT CALLS=1') ;;
  *) fail "404 deveria reprovar imediatamente, sem retry; obtive: ${result}" ;;
esac

# 5b) Prova decisiva: 404 seguido de 200 não pode virar sucesso.
result="$(run_smoke '404 200')"
case "$result" in
  EXIT=0*) fail "404 seguido de 200 foi aprovado; página ausente passaria: ${result}" ;;
esac
[ "$result" = 'EXIT=1 CALLS=1' ] || [ "$result" = 'EXIT=ABORT CALLS=1' ] \
  || fail "404 consumiu tentativa extra em vez de reprovar na hora: ${result}"

# 5c) 410 (Gone) tem o mesmo veredito de conteúdo.
result="$(run_smoke '410 200')"
case "$result" in
  EXIT=0*) fail "HTTP 410 seguido de 200 foi aprovado: ${result}" ;;
esac

# 5d) Contrato no CÓDIGO: 404/410 não podem entrar na lista de status retentáveis.
#     Verificado no texto porque, por comportamento, tirar 404 do ramo dedicado o
#     joga no ramo padrão, que também reprova — indistinguível de fora. O risco
#     real é alguém ADICIONAR 404 aos retentáveis, e é isso que travamos aqui.
smoke_body="$(awk '$0 == "validate_http_endpoint() {" { inside = 1 } inside { print } inside && /^}$/ { exit }' "$CLONE_SCRIPT")"
retry_branch="$(printf '%s\n' "$smoke_body" | grep -E '^[[:space:]]*[0-9]{3}\|' | grep -E '40[69]|429|50[0-4]' | head -n 1)"
[ -n "$retry_branch" ] || fail 'não encontrei a lista de status retentáveis no smoke'
case "$retry_branch" in
  *404*) fail 'HTTP 404 foi incluído nos status retentáveis do smoke' ;;
  *410*) fail 'HTTP 410 foi incluído nos status retentáveis do smoke' ;;
esac

# 6) Falha de transporte do curl é retentada e, se ceder, o clone segue.
result="$(run_smoke 'ERRO 200')"
case "$result" in
  'EXIT=0 CALLS=2') ;;
  *) fail "falha de curl transitória deveria ser retentada; obtive: ${result}" ;;
esac

# 7) Sucesso de primeira não deve gastar tentativa extra.
result="$(run_smoke '200')"
case "$result" in
  'EXIT=0 CALLS=1') ;;
  *) fail "200 imediato deveria usar 1 chamada; obtive: ${result}" ;;
esac

# 8) A janela de retry precisa cobrir a DECADÊNCIA MEDIDA do bloqueio: o deny do
#    Mod_Security persistiu em t+75s e só liberou perto de t+180s. Um bloqueio
#    que libera na 6ª tentativa ainda precisa resultar em clone aprovado — com
#    5 tentativas (75s de espera) o clone morreria dentro do bloqueio.
result="$(run_smoke '406 406 406 406 406 200')"
case "$result" in
  'EXIT=0 CALLS=6') ;;
  *) fail "bloqueio que libera perto de 180s deveria ser tolerado; obtive: ${result}" ;;
esac

# 8b) A soma das esperas precisa alcançar ~135s. Verificado no texto porque o
#     teste mocka sleep: o risco é alguém reduzir a janela e reintroduzir o bug.
smoke_body="$(awk '$0 == "validate_http_endpoint() {" { inside = 1 } inside { print } inside && /^}$/ { exit }' "$CLONE_SCRIPT")"
attempts_declared="$(printf '%s\n' "$smoke_body" | sed -n 's/^[[:space:]]*local max_attempts=\([0-9]*\).*/\1/p' | head -n 1)"
[ -n "$attempts_declared" ] || fail 'não encontrei max_attempts no smoke'
[ "$attempts_declared" -ge 6 ] \
  || fail "max_attempts=${attempts_declared} não cobre os ~180s medidos de bloqueio do WAF"

# 9) Sucesso obtido só após retry precisa ser ANOTADO. Um retry silencioso
#    mascararia degradação real da borda.
printf '%s\n' "$smoke_body" | grep -qi 'AVISO' \
  || fail 'sucesso após retry não emite aviso; degradação passaria silenciosa'

# 8) O tratamento tolerante precisa estar no CÓDIGO, não só no comportamento do
#    mock: exige retry real e classificação explícita de status transitório.
body="$(awk '$0 == "validate_http_endpoint() {" { inside = 1 } inside { print } inside && /^}$/ { exit }' "$CLONE_SCRIPT")"
printf '%s\n' "$body" | grep -qE 'sleep' \
  || fail 'validate_http_endpoint não espera entre tentativas'
printf '%s\n' "$body" | grep -qE '40[69]' \
  || fail 'validate_http_endpoint não classifica os bloqueios 406/409 do WAF'

printf 'PASS: smoke tolera bloqueio transitório de WAF e ainda reprova falha real.\n'
