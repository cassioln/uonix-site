#!/usr/bin/env bash
# O workflow de limpeza de plugins escreve em PRODUÇÃO. Os gates que impedem
# escrita acidental precisam ser verificáveis, não confiados.
#
# Contrato coberto aqui:
#   - produção exige guard ligado E frase de confirmação com o SHA;
#   - o backup (arquivo + banco) acontece ANTES de qualquer remoção;
#   - o dump é aceito só com o marcador final, não apenas gzip íntegro;
#   - o bloco do .htaccess que serve /painel NUNCA é removido;
#   - o lock é liberado com if: always();
#   - as remoções ocorrem numa ÚNICA sessão SSH (evita recusa de porta);
#   - QA/DEV não passam por este workflow.
set -uo pipefail

ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
WF="${ROOT_DIR}/.github/workflows/cleanup-vendor-plugins.yml"

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }

[ -f "$WF" ] || fail 'workflow de limpeza não encontrado'

# Estrutura por YAML, não por grep solto: a ordem dos steps é parte do contrato.
python3 - "$WF" <<'PY' || exit 1
import sys, yaml

wf = yaml.safe_load(open(sys.argv[1]))
def die(m):
    print(f"FAIL: {m}", file=sys.stderr); sys.exit(1)

jobs = wf.get('jobs', {})
for j in ('authorize', 'validate', 'cleanup'):
    if j not in jobs:
        die(f'job ausente: {j}')

# A validação da suíte precisa vir antes da limpeza, e a limpeza depende dela.
needs = jobs['cleanup'].get('needs', [])
needs = [needs] if isinstance(needs, str) else needs
for dep in ('authorize', 'validate'):
    if dep not in needs:
        die(f'job cleanup não depende de {dep}')

steps = jobs['cleanup'].get('steps', [])
names = [s.get('name', '') for s in steps]

def idx(fragment):
    for i, n in enumerate(names):
        if fragment.lower() in n.lower():
            return i
    die(f'step ausente: {fragment}')

# ORDEM CRÍTICA: preflight -> lock -> backup -> prova de uso -> remoção -> validação.
i_pre    = idx('Preflight SSH')
i_lock   = idx('Acquire exclusive lock')
i_backup = idx('Back up')
i_usage  = idx('zero content usage')
i_remove = idx('Remove vendor plugins')
i_valid  = idx('Validate the site after removal')
i_rel    = idx('Release exclusive lock')

if not (i_pre < i_lock < i_backup < i_usage < i_remove < i_valid):
    die(f'ordem dos steps não é fail-closed: {names}')
if i_rel < i_remove:
    die('lock é liberado antes da remoção')

# O release do lock precisa rodar mesmo em falha, senão sobra lock órfão.
rel = steps[i_rel]
if 'always()' not in str(rel.get('if', '')):
    die('release do lock não usa if always()')

# Backup, prova de uso e remoção só no modo execute — dry-run não escreve nada.
for i, label in ((i_backup, 'backup'), (i_usage, 'prova de uso'), (i_remove, 'remoção')):
    cond = str(steps[i].get('if', ''))
    if "mode == 'execute'" not in cond:
        die(f'step de {label} não está restrito a mode=execute (if={cond!r})')

# O preflight NÃO pode ser condicional: ele é o que impede escrita às cegas.
if steps[i_pre].get('if'):
    die('preflight está condicional; deve rodar sempre')

print('estrutura OK')
PY

body="$(cat "$WF")"
# Corpo SEM comentários: asserção textual sobre prosa é sem dentes — o comentário
# que explica um gate faz o grep passar mesmo com o gate removido do código.
code="$(grep -v '^[[:space:]]*#' <<<"$body")"

# `printf ... | grep -q` é uma armadilha: grep -q sai no PRIMEIRO match e o printf
# morre com SIGPIPE. Sob `set -o pipefail` (ou o `bash -e` do runner) isso reprova
# um teste correto de forma intermitente — dependente de timing e do tamanho do
# buffer. Passou no macOS e no meu container, e falhou no runner do GitHub com
# "printf: write error: Broken pipe".
# Os helpers abaixo usam here-string: sem pipe, sem SIGPIPE, mesmo veredito.
has() {  # has <texto> <padrao-basico>
  grep -q -e "$2" <<<"$1"
}
has_re() {  # has_re <texto> <padrao-estendido>
  grep -qE -e "$2" <<<"$1"
}
has_word() {
  grep -qw -e "$2" <<<"$1"
}
has_i() {
  grep -qi -e "$2" <<<"$1"
}
count_of() {
  grep -c -e "$2" <<<"$1" || true
}

# --- Gates de autorização de produção -------------------------------------
# Precisam estar dentro do ramo de produção, não em qualquer lugar do arquivo.
prod_branch="$(awk '/= execute \] && \[ "\$UONIX_REQUEST_ENVIRONMENT" = prod \]/,/^          else$/' <<<"$code")"
[ -n "$prod_branch" ] || fail 'não encontrei o ramo de autorização de produção'
has_re "$prod_branch" 'ENABLE_CLEANUP_PRODUCTION" = true' \
  || fail 'produção não exige o guard ENABLE_CLEANUP_PRODUCTION ligado'
has "$prod_branch" 'LIMPAR PLUGINS EM PROD @' \
  || fail 'produção não exige a frase de confirmação'
has "$prod_branch" 'UONIX_REQUEST_SHA' \
  || fail 'confirmação de produção não é atrelada ao SHA'
has "$prod_branch" 'UONIX_REQUEST_CONFIRMATION' \
  || fail 'a frase informada não é comparada com a esperada'
has "$code" 'refs/heads/master' \
  || fail 'workflow não exige master'

# --- O bloco que serve /painel jamais pode ser removido -------------------
if has_re "$code" 'sed .*BEGIN Loginizer|rm .*htaccess|BEGIN Loginizer.*d;'; then
  fail 'workflow tenta editar/remover o bloco do .htaccess que serve /painel'
fi
# A validação do /painel tem de estar no step de validação pós-remoção, não
# apenas mencionada num comentário em qualquer lugar do arquivo.
validate_block="$(awk '/name: Validate the site after removal/,/name: Release exclusive lock/' <<<"$code")"
[ -n "$validate_block" ] || fail 'não encontrei o step de validação pós-remoção'
has "$validate_block" "probe painel '/painel'" \
  || fail 'validação pós-remoção não prova que /painel continua respondendo'
has "$validate_block" "probe login '/wp-login.php'" \
  || fail 'validação pós-remoção não prova que o login continua respondendo'

# --- Integridade do backup ------------------------------------------------
has "$body" 'mysqldump' \
  || fail 'backup não usa mysqldump (wp db export não funciona na Locaweb)'
has "$body" '-- Dump completed' \
  || fail 'backup não exige o marcador final de conclusão'
has "$body" "tr -d '\\\\r'" \
  || fail 'validação do dump não normaliza CRLF'
if has_re "$body" 'tail -n [2-9] \|'; then
  fail 'validação do dump usa janela fixa de linhas'
fi
has "$body" 'htaccess.bak' \
  || fail '.htaccess não é copiado antes da escrita'
# Ancorado no STEP de backup: sem isso, a menção em outro lugar bastaria e a
# remoção da cópia real passaria despercebida.
backup_block="$(awk '/name: Back up .htaccess and the database/,/name: Prove zero content usage/' <<<"$code")"
[ -n "$backup_block" ] || fail 'não encontrei o step de backup'
has_re "$backup_block" 'cp -p .*htaccess.*htaccess\.bak' \
  || fail 'step de backup não copia o .htaccess'
has "$backup_block" 'mysqldump' \
  || fail 'step de backup não gera dump do banco'

# --- Uma única sessão SSH para o lote ------------------------------------
has "$body" 'ControlMaster auto' \
  || fail 'sem multiplexação SSH; a rajada faz o host recusar a porta'
remove_block="$(awk '/name: Remove vendor plugins/,/name: Validate the site/' <<<"$body")"
[ "$(count_of "$remove_block" 'sshpass -e ssh')" -eq 1 ] \
  || fail 'remoção usa mais de uma sessão SSH; deve ser um laço remoto único'
has "$remove_block" 'for p in' \
  || fail 'remoção não usa laço no shell remoto'

# --- loginizer sai; Turnstile precisa continuar --------------------------
# Ancorado no LAÇO de remoção: exige o nome na lista real de plugins, não numa
# menção qualquer. Foi decisão explícita de Cassio (Turnstile fica como único gate).
remove_loop="$(awk '/for p in/,/done/' <<<"$remove_block")"
[ -n "$remove_loop" ] || fail 'não encontrei o laço de remoção'
has_word "$remove_loop" 'loginizer' \
  || fail 'loginizer não está na lista de remoção (decisão de Cassio)'
has_word "$remove_loop" 'socialfeeds' \
  || fail 'socialfeeds não está na lista de remoção'
has_i "$validate_block" 'turnstile' \
  || fail 'validação pós-remoção não prova que o Turnstile permanece no login'

# --- Desativar antes de apagar limpa os blocos do plugin ----------------
has "$remove_block" 'plugin deactivate' \
  || fail 'remoção não desativa antes de apagar'

# --- wp-cli remoto na forma que a Locaweb aceita ------------------------
has "$body" '-d disable_functions=' \
  || fail 'wp-cli remoto não relaxa disable_functions'
# Ignora comentários: um `grep 'wp db query'` solto casa a PROSA que explica por
# que não se usa db query, e o teste reprovaria o código correto.
if has "$code" 'wp db query'; then
  fail 'usa wp db query, que falha na Locaweb (proc_open desabilitado)'
fi

# --- QA/DEV ficam fora deste caminho ------------------------------------
has "$body" 'Reject non-production environments' \
  || fail 'workflow não rejeita QA/DEV explicitamente'

printf 'PASS: gates do workflow de limpeza são fail-closed e preservam /painel.\n'
