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
code="$(printf '%s\n' "$body" | grep -v '^[[:space:]]*#')"

# --- Gates de autorização de produção -------------------------------------
# Precisam estar dentro do ramo de produção, não em qualquer lugar do arquivo.
prod_branch="$(printf '%s\n' "$code" \
  | awk '/= execute \] && \[ "\$UONIX_REQUEST_ENVIRONMENT" = prod \]/,/^          else$/')"
[ -n "$prod_branch" ] || fail 'não encontrei o ramo de autorização de produção'
printf '%s\n' "$prod_branch" | grep -qE 'ENABLE_CLEANUP_PRODUCTION" = true' \
  || fail 'produção não exige o guard ENABLE_CLEANUP_PRODUCTION ligado'
printf '%s\n' "$prod_branch" | grep -q 'LIMPAR PLUGINS EM PROD @' \
  || fail 'produção não exige a frase de confirmação'
printf '%s\n' "$prod_branch" | grep -q 'UONIX_REQUEST_SHA' \
  || fail 'confirmação de produção não é atrelada ao SHA'
printf '%s\n' "$prod_branch" | grep -q 'UONIX_REQUEST_CONFIRMATION' \
  || fail 'a frase informada não é comparada com a esperada'
printf '%s\n' "$code" | grep -q "refs/heads/master" \
  || fail 'workflow não exige master'

# --- O bloco que serve /painel jamais pode ser removido -------------------
if printf '%s\n' "$code" | grep -qE 'sed .*BEGIN Loginizer|rm .*htaccess|BEGIN Loginizer.*d;'; then
  fail 'workflow tenta editar/remover o bloco do .htaccess que serve /painel'
fi
# A validação do /painel tem de estar no step de validação pós-remoção, não
# apenas mencionada num comentário em qualquer lugar do arquivo.
validate_block="$(printf '%s\n' "$code" | awk '/name: Validate the site after removal/,/name: Release exclusive lock/')"
[ -n "$validate_block" ] || fail 'não encontrei o step de validação pós-remoção'
printf '%s\n' "$validate_block" | grep -q "probe painel '/painel'" \
  || fail 'validação pós-remoção não prova que /painel continua respondendo'
printf '%s\n' "$validate_block" | grep -q "probe login '/wp-login.php'" \
  || fail 'validação pós-remoção não prova que o login continua respondendo'

# --- Integridade do backup ------------------------------------------------
printf '%s' "$body" | grep -q 'mysqldump' \
  || fail 'backup não usa mysqldump (wp db export não funciona na Locaweb)'
printf '%s' "$body" | grep -q -- '-- Dump completed' \
  || fail 'backup não exige o marcador final de conclusão'
printf '%s' "$body" | grep -q "tr -d '\\\\r'" \
  || fail 'validação do dump não normaliza CRLF'
if printf '%s' "$body" | grep -qE 'tail -n [2-9] \|'; then
  fail 'validação do dump usa janela fixa de linhas'
fi
printf '%s' "$body" | grep -q 'htaccess.bak' \
  || fail '.htaccess não é copiado antes da escrita'
# Ancorado no STEP de backup: sem isso, a menção em outro lugar bastaria e a
# remoção da cópia real passaria despercebida.
backup_block="$(printf '%s\n' "$code" | awk '/name: Back up .htaccess and the database/,/name: Prove zero content usage/')"
[ -n "$backup_block" ] || fail 'não encontrei o step de backup'
printf '%s\n' "$backup_block" | grep -q 'cp -p .*htaccess.*htaccess\.bak' \
  || fail 'step de backup não copia o .htaccess'
printf '%s\n' "$backup_block" | grep -q 'mysqldump' \
  || fail 'step de backup não gera dump do banco'

# --- Uma única sessão SSH para o lote ------------------------------------
printf '%s' "$body" | grep -q 'ControlMaster auto' \
  || fail 'sem multiplexação SSH; a rajada faz o host recusar a porta'
remove_block="$(printf '%s' "$body" | awk '/name: Remove vendor plugins/,/name: Validate the site/')"
[ "$(printf '%s' "$remove_block" | grep -c 'sshpass -e ssh')" -eq 1 ] \
  || fail 'remoção usa mais de uma sessão SSH; deve ser um laço remoto único'
printf '%s' "$remove_block" | grep -q 'for p in' \
  || fail 'remoção não usa laço no shell remoto'

# --- loginizer sai; Turnstile precisa continuar --------------------------
# Ancorado no LAÇO de remoção: exige o nome na lista real de plugins, não numa
# menção qualquer. Foi decisão explícita de Cassio (Turnstile fica como único gate).
remove_loop="$(printf '%s\n' "$remove_block" | awk '/for p in/,/done/')"
[ -n "$remove_loop" ] || fail 'não encontrei o laço de remoção'
printf '%s\n' "$remove_loop" | grep -qw 'loginizer' \
  || fail 'loginizer não está na lista de remoção (decisão de Cassio)'
printf '%s\n' "$remove_loop" | grep -qw 'socialfeeds' \
  || fail 'socialfeeds não está na lista de remoção'
printf '%s\n' "$validate_block" | grep -qi 'turnstile' \
  || fail 'validação pós-remoção não prova que o Turnstile permanece no login'

# --- Desativar antes de apagar limpa os blocos do plugin ----------------
printf '%s' "$remove_block" | grep -q 'plugin deactivate' \
  || fail 'remoção não desativa antes de apagar'

# --- wp-cli remoto na forma que a Locaweb aceita ------------------------
printf '%s' "$body" | grep -q -- '-d disable_functions=' \
  || fail 'wp-cli remoto não relaxa disable_functions'
# Ignora comentários: um `grep 'wp db query'` solto casa a PROSA que explica por
# que não se usa db query, e o teste reprovaria o código correto.
if printf '%s' "$body" | grep -v '^[[:space:]]*#' | grep -q 'wp db query'; then
  fail 'usa wp db query, que falha na Locaweb (proc_open desabilitado)'
fi

# --- QA/DEV ficam fora deste caminho ------------------------------------
printf '%s' "$body" | grep -q 'Reject non-production environments' \
  || fail 'workflow não rejeita QA/DEV explicitamente'

printf 'PASS: gates do workflow de limpeza são fail-closed e preservam /painel.\n'
