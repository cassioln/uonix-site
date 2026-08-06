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

# ORDEM CRÍTICA: preflight -> lock -> backup -> prova de uso -> remoção ->
# renomear APENAS comentários do /painel -> validação.
i_pre    = idx('Preflight SSH')
i_lock   = idx('Acquire exclusive lock')
i_backup = idx('Back up')
i_usage  = idx('zero content usage')
i_remove = idx('Remove vendor plugins')
i_rename = idx('Rename misleading /painel markers')
i_valid  = idx('Validate the site after removal')
i_rel    = idx('Release exclusive lock')

if not (i_pre < i_lock < i_backup < i_usage < i_remove < i_rename < i_valid):
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
# Literal, sem interpretar regex: código PHP com `[$var]`, `??` e `!==` casaria
# como classe de caracteres em BRE e daria falso negativo silencioso.
has_lit() {  # has_lit <texto> <string-literal>
  grep -qF -e "$2" <<<"$1"
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

# --- O bloco que serve /painel só pode ter os MARCADORES renomeados --------
# As diretivas precisam ficar byte-idênticas. O step deve trabalhar numa cópia
# temporária, remover dos dois lados somente os comentários esperados, comparar
# o restante e só então trocar o arquivo original.
rename_block="$(awk '/name: Rename misleading \/painel markers/,/name: Validate the site after removal/' <<<"$code")"
[ -n "$rename_block" ] || fail 'não encontrei o step de renomear os marcadores de /painel'
has "$rename_block" '# BEGIN Loginizer' \
  || fail 'renomeação não exige o marcador BEGIN antigo exato'
has "$rename_block" '# END Loginizer' \
  || fail 'renomeação não exige o marcador END antigo exato'
has_lit "$rename_block" '# BEGIN UonixAdminPath (NAO REMOVER: serve a URL de admin /painel)' \
  || fail 'renomeação não grava o marcador BEGIN autoexplicativo'
has "$rename_block" '# END UonixAdminPath' \
  || fail 'renomeação não grava o marcador END novo'
# Os `$...` abaixo são o TEXTO que precisa existir no script remoto; não devem
# expandir enquanto este teste roda.
# shellcheck disable=SC2016
has_lit "$rename_block" 'cp -p "$htaccess" "$tmp"' \
  || fail 'renomeação não trabalha numa cópia que preserva metadados'
# shellcheck disable=SC2016
has_lit "$rename_block" 'grep -Fc "$old_begin"' \
  || fail 'renomeação não exige exatamente um marcador BEGIN antigo'
# shellcheck disable=SC2016
has_lit "$rename_block" 'grep -Fc "$old_end"' \
  || fail 'renomeação não exige exatamente um marcador END antigo'
# shellcheck disable=SC2016
has_lit "$rename_block" 'grep -Fc "$new_begin"' \
  || fail 'renomeação não prova exatamente um marcador BEGIN novo'
# shellcheck disable=SC2016
has_lit "$rename_block" '! grep -Fq "$old_begin"' \
  || fail 'renomeação não prova que o marcador BEGIN antigo sumiu da cópia'
# shellcheck disable=SC2016
has_lit "$rename_block" '($hits[$old] ?? 0) !== 1' \
  || fail 'substituição PHP não exige ocorrência única de cada marcador'
# shellcheck disable=SC2016
has_lit "$rename_block" '$count !== 2' \
  || fail 'substituição PHP não confirma que trocou exatamente os dois marcadores'
# As DUAS extrações (original e cópia) precisam de -Fvx. Trocar só uma por
# `grep -v` desancorado descartaria linhas legítimas e o cmp compararia lixo.
[ "$(count_of "$rename_block" 'grep -Fvx')" -ge 2 ] \
  || fail 'comparação usa grep desancorado; descartaria linhas legítimas do .htaccess'
# O .htaccess vivo só pode ser ESCRITO pelo mv final. Edição in-place ou
# redirecionamento sobre ele burla a prova byte a byte inteira.
# shellcheck disable=SC2016
if has_re "$rename_block" 'sed -i.*htaccess|tee .*\$htaccess|> *"\$htaccess"|>> *"\$htaccess"'; then
  fail 'renomeação escreve direto no .htaccess vivo em vez de trocar a cópia validada'
fi
# ORDEM REAL, não só presença: copiar -> comparar -> trocar. Um `mv` antes do
# `cmp` trocaria o arquivo e só depois verificaria — exatamente o inverso.
# shellcheck disable=SC2016
rename_cp_line="$(grep -n 'cp -p "\$htaccess"' <<<"$rename_block" | head -1 | cut -d: -f1)"
rename_cmp_line="$(grep -n 'cmp -s' <<<"$rename_block" | head -1 | cut -d: -f1)"
rename_mv_line="$(grep -n 'mv -f' <<<"$rename_block" | head -1 | cut -d: -f1)"
[ -n "$rename_cp_line" ] && [ -n "$rename_cmp_line" ] && [ -n "$rename_mv_line" ] \
  || fail 'não localizei cp/cmp/mv no step de renomeação'
[ "$rename_cp_line" -lt "$rename_cmp_line" ] \
  || fail "cópia (linha $rename_cp_line) não precede a comparação (linha $rename_cmp_line)"
[ "$rename_cmp_line" -lt "$rename_mv_line" ] \
  || fail "comparação (linha $rename_cmp_line) não precede a troca (linha $rename_mv_line)"
# O step precisa mesmo executar a renomeação: reduzi-lo a echos mantendo as
# strings satisfaria qualquer asserção puramente textual.
has "$rename_block" 'file_put_contents' \
  || fail 'step de renomeação não escreve a cópia; seria apenas eco de texto'
# Medido no host: o .htaccess real NÃO termina em newline. Casar o marcador com
# "\n" colado quebraria se o bloco fosse a última linha. A comparação precisa ser
# por LINHA, tolerando \r e ausência de terminador final.
# shellcheck disable=SC2016
has_lit "$rename_block" 'rtrim($line, "\r")' \
  || fail 'substituição não compara linha a linha tolerando CR e ausência de newline final'
# Se os terminadores mudarem, o Apache pode reinterpretar o arquivo. Exige as
# duas medições E a comparação entre elas: só o printf do CR_ANTES passaria sem
# nunca comparar nada.
has "$rename_block" 'CR_ANTES' \
  || fail 'renomeação não mede os terminadores antes da troca'
# shellcheck disable=SC2016
has_lit "$rename_block" 'cr_depois="$(tr -dc' \
  || fail 'renomeação não mede os terminadores depois da troca'
# shellcheck disable=SC2016
has_lit "$rename_block" '[ "$cr_antes" = "$cr_depois" ]' \
  || fail 'renomeação não compara os terminadores antes/depois; conversão CRLF passaria'
has "$rename_block" 'cmp -s' \
  || fail 'renomeação não prova que as diretivas ficaram byte-idênticas'
has "$rename_block" 'mv -f' \
  || fail 'renomeação não troca o arquivo apenas após validar a cópia temporária'
if has_re "$rename_block" 'BEGIN Loginizer.*d;|END Loginizer.*d;|rm .*htaccess'; then
  fail 'workflow tenta apagar o bloco que serve /painel'
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
# O preflight aceita mysqldump OU mariadb-dump. Se o produtor chamar mysqldump
# fixo, um host só-MariaDB passa o preflight e falha DEPOIS de adquirir o lock.
has "$backup_block" 'mariadb-dump' \
  || fail 'produtor não detecta mariadb-dump; divergiria do preflight e falharia pós-lock'
# shellcheck disable=SC2016
has_lit "$backup_block" '"$dump_bin"' \
  || fail 'produtor não invoca o binário detectado; a detecção seria decorativa'
has "$backup_block" '--no-tablespaces' \
  || fail 'mysqldump não usa --no-tablespaces; usuário compartilhado sem PROCESS aborta o dump'
for dump_flag in --routines --triggers --events; do
  has "$backup_block" "$dump_flag" \
    || fail "mysqldump omite $dump_flag; backup não cobre o schema completo"
done
for footer_flag in --comments --dump-date; do
  has "$backup_block" "$footer_flag" \
    || fail "mysqldump não força $footer_flag; my.cnf pode suprimir o marcador final"
done

# --- Uma única sessão SSH para o lote ------------------------------------
has "$body" 'ControlMaster auto' \
  || fail 'sem multiplexação SSH; a rajada faz o host recusar a porta'
remove_block="$(awk '/name: Remove vendor plugins/,/name: Rename misleading \/painel markers/' <<<"$body")"
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
has "$code" 'Reject non-production environments' \
  || fail 'workflow não rejeita QA/DEV explicitamente'

# --- Correções vindas da auditoria adversarial ---------------------------
# Qualquer toque em prod, inclusive dry-run (que só lê), passa pelo environment
# com gate de aprovação — senão o dry-run seria o único caminho a abrir SSH em
# produção sem revisor.
authorize_block="$(awk '/environment_name=.clone-operations./,/printf .environment_name/' <<<"$code")"
[ -n "$authorize_block" ] || fail 'não encontrei a resolução do environment'
has "$authorize_block" "UONIX_REQUEST_ENVIRONMENT\" = prod" \
  || fail 'environment de produção não é escolhido por ambiente'
has "$authorize_block" "environment_name='production-locaweb'" \
  || fail 'prod não resolve para o environment com aprovação'
# A linha que resolve production-locaweb tem de estar FORA do ramo que exige a
# frase, senão o dry-run volta a escapar do gate.
if has_re "$authorize_block" "= execute \\].*\n.*production-locaweb"; then
  fail 'production-locaweb só é aplicado no modo execute'
fi

# O lock é confirmado pelo HOST, não pelo status do transporte: mkdir+owner podem
# completar e o ssh ainda retornar ≠0, deixando diretório órfão.
lock_block="$(awk '/name: Acquire exclusive lock/,/name: Back up/' <<<"$code")"
[ -n "$lock_block" ] || fail 'não encontrei o step de aquisição do lock'
# Duas metades do contrato: o REMOTO imprime o marcador e o RUNNER decide por ele.
# Exigir só a menção deixaria passar a remoção do printf remoto.
[ "$(count_of "$lock_block" 'UONIX_LOCK_ACQUIRED')" -ge 2 ] \
  || fail 'lock não é confirmado pelo host e verificado pelo runner'
has "$lock_block" "grep -q 'UONIX_LOCK_ACQUIRED'" \
  || fail 'runner não verifica o marcador impresso pelo host'
has "$lock_block" "printf 'UONIX_LOCK_ACQUIRED" \
  || fail 'host não imprime o marcador de lock adquirido'

# O release não pode abortar deixando diretório órfão quando o owner falta.
release_block="$(awk '/name: Release exclusive lock/,/name: Publish sanitized summary/' <<<"$code")"
[ -n "$release_block" ] || fail 'não encontrei o step de release'
has "$release_block" 'removendo diretório órfão' \
  || fail 'release não trata lock sem owner; deixaria diretório órfão'
# O `set -e` do RUNNER é correto e deve ficar. O que não pode ter `-e` é o script
# REMOTO: lá um `test` falho abortaria antes do rmdir, deixando o diretório.
remote_release="$(awk "/bash -s -- .\\\$lock_path/,/^          REMOTE\$/" <<<"$release_block")"
[ -n "$remote_release" ] || fail 'não encontrei o script remoto do release'
if has "$remote_release" 'set -euo pipefail'; then
  fail 'script remoto do release usa set -e; um test falho deixaria o lock órfão'
fi
has "$remote_release" 'set -uo pipefail' \
  || fail 'script remoto do release não define set -uo pipefail'

# BACKUP_DIR só é publicado depois de o dump passar pelas provas. Regex multilinha
# não funciona em grep linha-a-linha, então comparo as POSIÇÕES das duas linhas.
has "$backup_block" 'BACKUP_VALIDO' \
  || fail 'step de backup não valida o dump'
# O footer do mysqldump NÃO é confiável neste host: dois runs reais (31034098987 e
# 31037659803) terminaram rc=0 sem `-- Dump completed`, inclusive já com
# --comments --dump-date. A prova de conclusão passa a ser um marcador NOSSO,
# acrescentado ao fluxo somente quando o produtor sai com status zero.
# Exige as DUAS pontas: quem grava o marcador e quem o compara. Só mencionar o
# nome num comentário, ou gravá-lo sem comparar, não vale.
#
# Os dois caminhos em stream foram REFUTADOS pelo host:
# - run 31046533827: marcador como segundo membro gzip não apareceu;
# - run 31050726743: marcador no mesmo pipeline também não apareceu.
# A prova deixa de depender do comportamento de pipe/gzip do host: o produtor
# grava um SQL físico, o marcador é anexado a ESSE arquivo depois de rc=0, e só
# então um único gzip é criado. O temporário precisa ser limpo até em falha.
# shellcheck disable=SC2016
has_lit "$backup_block" 'sql_tmp="$backup_dir/db.sql.partial"' \
  || fail 'backup não usa arquivo SQL temporário físico para atestar conclusão'
# O trap precisa limpar E abortar. Um handler HUP/TERM que só executa `rm` pode
# retornar e deixar o script continuar; o `exit` dispara a limpeza centralizada
# do trap EXIT e preserva o status convencional do sinal.
# shellcheck disable=SC2016
has_lit "$backup_block" 'cleanup_sql_tmp() { rm -f -- "$sql_tmp"; }' \
  || fail 'backup não centraliza a limpeza do SQL temporário'
has_lit "$backup_block" 'trap cleanup_sql_tmp EXIT' \
  || fail 'falha normal não limpa o SQL temporário no EXIT'
has_lit "$backup_block" "trap 'exit 129' HUP" \
  || fail 'HUP não aborta; queda do SSH poderia deixar o script continuar'
has_lit "$backup_block" "trap 'exit 130' INT" \
  || fail 'INT não aborta o backup após limpar o SQL temporário'
has_lit "$backup_block" "trap 'exit 143' TERM" \
  || fail 'TERM não aborta; cancelamento poderia deixar o script continuar'
has_lit "$backup_block" 'trap - EXIT HUP INT TERM' \
  || fail 'traps do SQL temporário não são todos desarmados no sucesso'

# SIGKILL não pode ser interceptado. Como o lock remoto já foi adquirido, o
# próximo run deve varrer qualquer db.sql.partial deixado por execução anterior
# dentro do diretório dedicado — nunca procurar fora dele.
has_lit "$backup_block" 'cleanup_stale_sql() {' \
  || fail 'backup não define limpeza dos SQL plaintext órfãos de SIGKILL'
# shellcheck disable=SC2016
has_lit "$backup_block" 'for stale_sql in "$backup_root"/*/db.sql.partial' \
  || fail 'limpeza de órfãos não está limitada ao backup_root dedicado'
# shellcheck disable=SC2016
has_lit "$backup_block" 'rm -f -- "$stale_sql"' \
  || fail 'próximo run não remove SQL plaintext órfão'

# O SQL físico e o gzip coexistem. Antes do dump, exigir espaço livre suficiente
# no filesystem e dimensionado pelo banco; piso fixo sozinho ficaria obsoleto.
# shellcheck disable=SC2016
has_lit "$backup_block" 'df -Pk "$backup_dir"' \
  || fail 'backup não consulta espaço livre antes de gerar SQL+gzip'
has_lit "$backup_block" 'BACKUP_INVALIDO: espaço livre insuficiente' \
  || fail 'preflight de espaço não aborta com diagnóstico sanitizado'
# shellcheck disable=SC2016
has_lit "$backup_block" 'required_space_kb() {' \
  || fail 'backup não encapsula o dimensionamento de espaço pelo banco'
has_lit "$backup_block" 'INFORMATION_SCHEMA.TABLES' \
  || fail 'preflight não mede o tamanho real das tabelas do banco'
has_lit "$backup_block" 'TABLE_SCHEMA = DATABASE()' \
  || fail 'medição de espaço não está limitada ao banco WordPress atual'
# shellcheck disable=SC2016
has_lit "$backup_block" '$wpdb->last_error' \
  || fail 'erro ao medir INFORMATION_SCHEMA vira tamanho zero e passa pelo piso'
has_lit "$backup_block" 'exit(24);' \
  || fail 'wp eval não aborta quando a medição do banco falha'
has_lit "$backup_block" 'db_kb * 3 + 200000' \
  || fail 'espaço exigido não cobre SQL+gzip com margem proporcional'
has_lit "$backup_block" 'required_kb=500000' \
  || fail 'dimensionamento perdeu o piso absoluto de 500 MB'
# shellcheck disable=SC2016
has_lit "$backup_block" '[ "$livre_kb" -ge "$required_kb" ]' \
  || fail 'espaço livre não é comparado ao requisito derivado do banco'
# shellcheck disable=SC2016
has_lit "$backup_block" '"$(wp config get DB_NAME)" > "$sql_tmp"' \
  || fail 'mysqldump não grava diretamente no arquivo SQL temporário'
# Garante linha autônoma mesmo se o cliente terminar sem newline.
# shellcheck disable=SC2016
has_lit "$backup_block" "printf -- '\\n-- UONIX_DUMP_COMPLETO\\n' >> \"\$sql_tmp\"" \
  || fail 'marcador não é anexado como linha autônoma ao SQL físico'
# shellcheck disable=SC2016
has_lit "$backup_block" 'gzip -c "$sql_tmp" > "$dump"' \
  || fail 'gzip não é gerado em um único membro a partir do SQL atestado'
# Rejeita as duas implementações já refutadas em produção.
# shellcheck disable=SC2016
if has_lit "$backup_block" 'gzip -c >> "$dump"' \
  || has_lit "$backup_block" '; } | gzip -c > "$dump"'; then
  fail 'backup reutiliza stream gzip refutado pelo host; precisa comprimir SQL físico'
fi
# Ordem concreta: produtor -> marcador -> gzip. Uma menção decorativa não basta.
# shellcheck disable=SC2016
producer_line="$(grep -nF '"$(wp config get DB_NAME)" > "$sql_tmp"' <<<"$backup_block" | head -1 | cut -d: -f1)"
marker_line="$(grep -nF "printf -- '\\n-- UONIX_DUMP_COMPLETO\\n' >> \"\$sql_tmp\"" <<<"$backup_block" | head -1 | cut -d: -f1)"
# shellcheck disable=SC2016
gzip_line="$(grep -nF 'gzip -c "$sql_tmp" > "$dump"' <<<"$backup_block" | head -1 | cut -d: -f1)"
[ -n "$producer_line" ] && [ -n "$marker_line" ] && [ -n "$gzip_line" ] \
  && [ "$producer_line" -lt "$marker_line" ] && [ "$marker_line" -lt "$gzip_line" ] \
  || fail 'ordem do atestado físico não é produtor -> marcador -> gzip'
# shellcheck disable=SC2016
has_lit "$backup_block" '[ "$last" != '"'"'-- UONIX_DUMP_COMPLETO'"'"' ]' \
  || fail 'prova não compara a última linha com o marcador próprio'
# A prova NÃO pode voltar a depender do footer do cliente.
if has "$backup_block" 'Dump completed'; then
  # shellcheck disable=SC2016
  fail 'prova volta a depender do footer `-- Dump completed`, que este host não emite'
fi
# O marcador só vale se o script remoto abortar imediatamente quando o produtor
# retornar rc != 0; sem `-e`, o append poderia rodar depois de um dump parcial.
remote_backup="$(awk "/<<'REMOTE'/,/^          REMOTE$/" <<<"$backup_block")"
[ -n "$remote_backup" ] || fail 'não encontrei o script remoto do backup'
has_lit "$remote_backup" 'set -euo pipefail' \
  || fail 'backup remoto não usa set -euo pipefail; marcador poderia seguir falha do produtor'
has_lit "$backup_block" "tail -n 1" \
  || fail 'prova não exige o marcador como última linha não vazia'
# Mínimo de tabelas: gzip íntegro + marcador ainda passariam num dump que perdeu
# tabelas no meio. O CREATE TABLE é a evidência de cobertura.
has "$backup_block" 'CREATE TABLE' \
  || fail 'prova não conta tabelas; dump parcial com marcador passaria'
# A âncora precisa da crase de abertura: sem ela `^CREATE TABLE` casa o corpo de
# uma ROUTINE (emitido verbatim por --routines) e a contagem é inflável — a
# auditoria mediu 121 "CREATE TABLE" num dump de 1 tabela real.
# shellcheck disable=SC2016
has_lit "$backup_block" 'grep -c '"'"'^CREATE TABLE `'"'" \
  || fail 'contagem de tabelas sem âncora de crase; corpo de routine infla o total'
# O piso precisa ser REAL: produção tem 158 tabelas, então `-ge 0` ou `-ge 1`
# tornaria o gate decorativo. Extrai o número e exige um piso significativo.
# shellcheck disable=SC2016
tabelas_min="$(sed -n 's/.*"\$tabelas" -ge \([0-9]\{1,\}\).*/\1/p' <<<"$backup_block" | head -1)"
[ -n "$tabelas_min" ] \
  || fail 'não encontrei o piso de tabelas do gate de cobertura'
[ "$tabelas_min" -ge 100 ] \
  || fail "piso de tabelas é $tabelas_min; baixo demais para produção (158 tabelas)"
# --no-defaults é OBRIGATÓRIO e precisa vir ANTES das outras opções. Auditoria
# provou em MariaDB 10.11 real que um `~/.my.cnf` com `[mysqldump] no-data` (ou
# `where=1=0`) faz o produtor sair rc=0 com ZERO dados: marcador presente e 160
# linhas CREATE TABLE. Em hospedagem compartilhada o painel cria esse arquivo, o
# que dispensa invasor. Restaurar esse backup entregaria um site vazio.
has_lit "$backup_block" '--no-defaults' \
  || fail 'produtor sem --no-defaults; ~/.my.cnf poderia injetar no-data e zerar os dados'
nodefaults_line="$(grep -nF -- '--no-defaults' <<<"$backup_block" | head -1 | cut -d: -f1)"
firstopt_line="$(grep -nF -- '--single-transaction' <<<"$backup_block" | head -1 | cut -d: -f1)"
[ -n "$nodefaults_line" ] && [ -n "$firstopt_line" ] && [ "$nodefaults_line" -lt "$firstopt_line" ] \
  || fail '--no-defaults não precede as demais opções do produtor'
# Cobertura por CREATE TABLE é falsificável: auditoria provou que o corpo de uma
# ROUTINE é emitido verbatim por --routines, e um corpo com linhas literais
# `CREATE TABLE ...` fez um dump de 1 tabela contar 121. A prova precisa medir
# VOLUME DE DADOS, não só estrutura.
has_lit "$backup_block" "grep -c '^INSERT INTO'" \
  || fail 'prova não conta INSERT; dump só-estrutura (no-data) passaria'
# shellcheck disable=SC2016
inserts_min="$(sed -n 's/.*"\$inserts" -ge \([0-9]\{1,\}\).*/\1/p' <<<"$backup_block" | head -1)"
[ -n "$inserts_min" ] \
  || fail 'não encontrei o piso de INSERT do gate de volume'
[ "$inserts_min" -ge 20 ] \
  || fail "piso de INSERT é $inserts_min; baixo demais para provar que há dados"
# Bytes descomprimidos: o ataque no-data produziu 2861 bytes. Um piso de bytes
# reprova estrutura-sem-dados mesmo se a contagem de INSERT for burlada.
has_lit "$backup_block" 'bytes_sql=' \
  || fail 'prova não mede o tamanho descomprimido do dump'
# shellcheck disable=SC2016
bytes_min="$(sed -n 's/.*"\$bytes_sql" -ge \([0-9]\{1,\}\).*/\1/p' <<<"$backup_block" | head -1)"
[ -n "$bytes_min" ] \
  || fail 'não encontrei o piso de bytes do gate de volume'
[ "$bytes_min" -ge 100000 ] \
  || fail "piso de bytes é $bytes_min; baixo demais (ataque no-data gerou 2861)"
# Guard anti-vazamento AMPLO: a auditoria provou que proibir só `printf "$last"`
# deixa passar echo, ${last}, here-string e linha de continuação. A regra passa a
# ser por CONTEXTO: comparar $last é legítimo; imprimi-lo não. Só ${#last} pode
# aparecer numa linha de saída.
while IFS= read -r linha; do
  # shellcheck disable=SC2016
  case "$linha" in
    *'$last'*|*'${last}'*) : ;;
    *) continue ;;
  esac
  # Remove os usos permitidos antes de julgar a linha.
  resto="${linha//\$\{#last\}/}"
  # shellcheck disable=SC2016
  case "$resto" in
    *'$last'*|*'${last}'*) : ;;
    *) continue ;;
  esac
  # Sobrou um $last cru: só é aceitável em teste/comparação/atribuição.
  case "$resto" in
    *printf*|*echo*|*'cat '*|*tee*|*'>&2'*|*'>>'*)
      # shellcheck disable=SC2016
      fail 'step de backup imprime $last; só ${#last} pode ir para a saída (não vazar SQL)'
      ;;
  esac
done <<<"$backup_block"
backup_dir_line="$(grep -n 'BACKUP_DIR=%s' <<<"$backup_block" | head -1 | cut -d: -f1)"
valid_line="$(grep -n 'BACKUP_VALIDO' <<<"$backup_block" | head -1 | cut -d: -f1)"
[ -n "$backup_dir_line" ] \
  || fail 'step de backup não publica BACKUP_DIR; o summary não saberia onde está o backup'
[ -n "$valid_line" ] || fail 'não encontrei a validação do dump'
[ "$backup_dir_line" -gt "$valid_line" ] \
  || fail "BACKUP_DIR é publicado (linha $backup_dir_line) antes da validação do dump (linha $valid_line)"

# ---------------------------------------------------------------------------
# SEMÂNTICA DE SHELL, não presença de string.
#
# Auditoria de robustez aplicou 36 mutações no step de backup e 22 SOBREVIVERAM:
# todas as asserções acima verificam se um texto existe no bloco — inclusive em
# comentários — e nenhuma verificava status de saída, redirecionamento ou alvo.
# Exemplos que passavam: `exit 1` -> `exit 0`, `gzip -t "$dump" || true`,
# `tail -n 1` -> `head -n 1` mantendo `# tail -n 1` num comentário, `: > "$dump"`
# depois das provas, e `[ ... ] && false` neutralizando a comparação.
#
# O bloco abaixo analisa o step SEM comentários e afirma comportamento.
# shellcheck disable=SC2001
backup_code="$(sed 's/[[:space:]]*#.*$//' <<<"$backup_block" | grep -v '^[[:space:]]*$')"

# 1. Todo diagnóstico BACKUP_INVALIDO precisa abortar de fato. Comparar a
# quantidade global de mensagens com a de linhas `exit 1` não prova vínculo de
# controle e reprova um `echo ...; exit 1` legítimo. Verifica cada gate localmente.
if grep -qE 'exit[[:space:]]+0' <<<"$backup_code"; then
  fail 'step de backup contém exit 0; um gate que "reprova" e segue é enfeite de log'
fi
invalidos="$(grep -c 'BACKUP_INVALIDO' <<<"$backup_code" || true)"
[ "$invalidos" -ge 3 ] \
  || fail "apenas $invalidos diagnósticos BACKUP_INVALIDO; provas de marcador, volume e tabelas são obrigatórias"
while IFS=: read -r linha _; do
  janela="$(sed -n "${linha},$((linha + 1))p" <<<"$backup_code")"
  grep -qE '(^|;)[[:space:]]*exit[[:space:]]+1([[:space:]]*;|[[:space:]]*$)' <<<"$janela" \
    || fail "diagnóstico BACKUP_INVALIDO na linha $linha não aborta imediatamente"
done < <(grep -n 'BACKUP_INVALIDO' <<<"$backup_code")

# 2. Provas de envelope não podem ser neutralizadas por `|| true`.
# shellcheck disable=SC2016
for prova in 'test -s "$dump"' 'gzip -t "$dump"'; do
  linha="$(grep -F "$prova" <<<"$backup_code" | head -1)"
  [ -n "$linha" ] || fail "prova ausente no step de backup: $prova"
  case "$linha" in
    *'|| true'*|*'|| :'*|*'||true'*)
      fail "prova neutralizada por || true: $prova" ;;
  esac
done

# 3. A comparação do marcador não pode ser anulada mantendo o texto exigido.
# shellcheck disable=SC2016
cmp_line="$(grep -F '[ "$last" != ' <<<"$backup_code" | head -1)"
[ -n "$cmp_line" ] || fail 'não encontrei a comparação do marcador no código do backup'
case "$cmp_line" in
  *'&& false'*|*'|| true'*|*'&& :'*)
    fail 'comparação do marcador foi neutralizada (&& false / || true)' ;;
esac

# 4. A última linha precisa vir de tail, no CÓDIGO — não de um comentário.
# shellcheck disable=SC2016
last_assign="$(grep -F 'last="$(gzip -dc' <<<"$backup_code" | head -1)"
[ -n "$last_assign" ] || fail 'não encontrei a extração da última linha do dump'
case "$last_assign" in
  *'tail -n 1'*) : ;;
  *) fail 'extração do marcador não usa tail -n 1 no código (head -n 1 nunca reprovaria)' ;;
esac
case "$last_assign" in
  *"tr -d '\\r'"*) : ;;
  *) fail 'extração do marcador não normaliza CRLF no código' ;;
esac
case "$last_assign" in
  *"grep -v '^[[:space:]]*\$'"*) : ;;
  *) fail 'extração do marcador não filtra linhas vazias no código' ;;
esac

# 5. Contagens precisam ser ancoradas e não podem ter default fabricado.
# A âncora de `tabelas` aceita um sufixo APÓS o padrão (a crase de abertura de
# `^CREATE TABLE \``), que é mais restritiva, não menos: sem ela o padrão casa o
# corpo de uma ROUTINE emitido verbatim por --routines. O que não se admite é
# âncora ausente ou mais frouxa que o padrão exigido.
for par in "tabelas:^CREATE TABLE" "inserts:^INSERT INTO"; do
  var="${par%%:*}"; pat="${par#*:}"
  linha="$(grep -F "${var}=\"\$(gzip -dc" <<<"$backup_code" | head -1)"
  [ -n "$linha" ] || fail "não encontrei a contagem de $var"
  case "$linha" in
    *"grep -c '${pat}"*) : ;;
    *) fail "contagem de $var não está ancorada em '${pat}'" ;;
  esac
  case "$linha" in
    *'|| echo'*|*'|| printf'*)
      fail "contagem de $var tem default fabricado; um erro viraria número aprovado" ;;
  esac
done

# 6. O produtor não pode receber opções que esvaziem o dump.
if grep -qE -- '--where|--no-data|--skip-triggers|--ignore-table' <<<"$backup_code"; then
  fail 'produtor recebe opção que reduz o conteúdo do dump (--where/--no-data/...)'
fi

# 7. Depois de validado, o dump não pode ser truncado nem escrito fora do
#    diretório publicado.
# shellcheck disable=SC2016
if grep -qE '^[[:space:]]*:[[:space:]]*>[[:space:]]*"\$dump"' <<<"$backup_code"; then
  fail 'dump é truncado depois das provas; BACKUP_DIR apontaria para diretório vazio'
fi
dump_assign="$(grep -F 'dump="' <<<"$backup_code" | head -1)"
# shellcheck disable=SC2016
case "$dump_assign" in
  *'"$backup_dir/'*) : ;;
  *) fail 'dump não é gravado dentro de $backup_dir; o backup publicado não conteria o dump' ;;
esac

# 8. O step de backup NÃO pode escrever no .htaccess: só o step de rename mexe
#    nele, e o bloco do /painel vive lá. A linha `- name:` do próprio step cita
#    ".htaccess" e não é código, então é descartada.
while IFS= read -r linha; do
  case "$linha" in
    *'- name:'*) continue ;;
  esac
  case "$linha" in
    *'.htaccess'*)
      case "$linha" in
        *'cp -p'*|*'test -s'*|*'chmod'*|*htaccess.bak*) : ;;
        *) fail 'step de backup manipula .htaccess além de copiá-lo; risco de derrubar /painel' ;;
      esac
      ;;
  esac
done <<<"$backup_code"

# 9. Permissões e consistência do dump em host compartilhado. O htaccess.bak
#    precisa ser copiado E verificado: um .htaccess.bak vazio some com a prova
#    de rollback do /painel.
for exigido in 'umask 077' 'chmod 700' 'chmod 600' '--single-transaction'; do
  has_lit "$backup_code" "$exigido" \
    || fail "step de backup perdeu $exigido (permissão ou consistência do dump)"
done
# shellcheck disable=SC2016
has_lit "$backup_code" 'cp -p "$document_root/.htaccess" "$backup_dir/htaccess.bak"' \
  || fail 'step de backup não copia o .htaccess preservando metadados'
# shellcheck disable=SC2016
has_lit "$backup_code" 'test -s "$backup_dir/htaccess.bak"' \
  || fail 'step de backup não verifica o htaccess.bak; backup vazio passaria'

# 10. O backup só roda em execute: em dry-run não pode haver escrita remota.
backup_if="$(awk '/name: Back up .htaccess/,/run: \|/' <<<"$code" | grep -F 'if:' | head -1)"
[ -n "$backup_if" ] || fail 'step de backup sem condição if'
case "$backup_if" in
  *"inputs.mode == 'execute'"*) : ;;
  *) fail 'condição do step de backup mudou; poderia rodar em dry-run' ;;
esac
if grep -qE "mode != 'execute'|always\(\)" <<<"$backup_if"; then
  fail 'condição do step de backup aceita modos não-execute'
fi

# O check do Turnstile precisa usar o CONTRATO REAL do módulo. A primeira versão
# testava uma constante UONIX_TURNSTILE_ENABLED que NÃO existe no repositório, e o
# dry-run em produção devolveu "TURNSTILE_INDEFINIDO" — falso alarme sobre um site
# que tinha o widget ativo. API inventada produz diagnóstico errado.
inventory_block="$(awk '/name: Report the real inventory/,/name: Acquire exclusive lock/' <<<"$code")"
[ -n "$inventory_block" ] || fail 'não encontrei o step de inventário'
has "$inventory_block" 'uonix_login_turnstile_is_active' \
  || fail 'check do Turnstile não usa uonix_login_turnstile_is_active (contrato real)'
if has "$inventory_block" 'UONIX_TURNSTILE_ENABLED'; then
  fail 'check do Turnstile usa UONIX_TURNSTILE_ENABLED, constante que não existe no repo'
fi

# Provas EXECUTÁVEIS dos controles novos. Não basta o texto do workflow conter os
# tokens certos: roda exatamente as funções/traps extraídos do heredoc remoto.
probe_root="$(mktemp -d "${TMPDIR:-/tmp}/uonix-cleanup-test.XXXXXX")" \
  || fail 'não foi possível criar diretório temporário para probes'
cleanup_probe_root() { rm -rf -- "$probe_root"; }
trap cleanup_probe_root EXIT

signal_code="$(awk '
  index($0, "cleanup_sql_tmp() {") { copy=1 }
  copy { print }
  copy && index($0, "exit 143") && index($0, "TERM") { exit }
' <<<"$backup_code")"
[ -n "$signal_code" ] || fail 'não foi possível extrair os traps para prova executável'
for spec in HUP:129 INT:130 TERM:143; do
  signal="${spec%%:*}"; expected_rc="${spec##*:}"
  case_dir="$probe_root/$signal"
  mkdir -p "$case_dir"
  probe_script="$case_dir/probe.sh"
  {
    printf '#!/usr/bin/env bash\nset -euo pipefail\n'
    printf 'sql_tmp=%q\n' "$case_dir/db.sql.partial"
    printf 'after=%q\n' "$case_dir/after"
    printf '%s\n' "$signal_code"
    # shellcheck disable=SC2016
    printf ': > "$sql_tmp"\nkill -%s "$$"\n: > "$after"\n' "$signal"
  } > "$probe_script"
  /bin/bash "$probe_script" >/dev/null 2>&1
  rc=$?
  [ "$rc" -eq "$expected_rc" ] \
    || fail "$signal saiu rc=$rc; esperado $expected_rc"
  [ ! -e "$case_dir/db.sql.partial" ] \
    || fail "$signal deixou SQL plaintext no disco"
  [ ! -e "$case_dir/after" ] \
    || fail "$signal limpou mas deixou o script continuar"
done

space_code="$(awk '
  index($0, "required_space_kb() {") { copy=1 }
  copy { print }
  copy && $0 ~ /^[[:space:]]*}[[:space:]]*$/ { exit }
' <<<"$backup_code")"
[ -n "$space_code" ] || fail 'não foi possível extrair o dimensionamento de espaço'
eval "$space_code"
[ "$(required_space_kb 1048576)" -eq 500000 ] \
  || fail 'banco pequeno não preserva piso de 500 MB'
expected_large=$(( ((3221225472 + 1023) / 1024) * 3 + 200000 ))
[ "$(required_space_kb 3221225472)" -eq "$expected_large" ] \
  || fail 'banco grande não aumenta proporcionalmente o espaço exigido'
if required_space_kb abc >/dev/null 2>&1; then
  fail 'tamanho de banco inválido é aceito pelo preflight'
fi

stale_code="$(awk '
  index($0, "cleanup_stale_sql() {") { copy=1 }
  copy { print }
  copy && $0 ~ /^[[:space:]]*}[[:space:]]*$/ { exit }
' <<<"$backup_code")"
[ -n "$stale_code" ] || fail 'não foi possível extrair a limpeza de órfãos'
stale_sql_removed=0
eval "$stale_code"
backup_root="$probe_root/backups"
mkdir -p "$backup_root/old-a" "$backup_root/old-b"
: > "$backup_root/old-a/db.sql.partial"
: > "$backup_root/old-b/db.sql.partial"
cleanup_stale_sql >/dev/null
[ ! -e "$backup_root/old-a/db.sql.partial" ] \
  && [ ! -e "$backup_root/old-b/db.sql.partial" ] \
  || fail 'limpeza executável não removeu SQL plaintext órfão'
[ "$stale_sql_removed" -eq 2 ] \
  || fail 'limpeza de órfãos não contabilizou os dois arquivos removidos'

cleanup_probe_root
trap - EXIT

printf 'PASS: gates do workflow de limpeza são fail-closed e preservam /painel.\n'
