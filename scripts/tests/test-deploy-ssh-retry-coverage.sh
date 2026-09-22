#!/usr/bin/env bash
# Garante que os passos do deploy de produção que abrem SSH e JÁ FORAM decididos
# como retentáveis continuem passando por retry — e que o retry entregue o script
# remoto em toda tentativa.
#
# MOTIVAÇÃO (runs 35781556143, 35784786643 e 35786796832, todos do SHA 08a4d6f, em
# 28 minutos): o firewall da Locaweb corta sessões SSH sequenciais em rajada. O
# ponto de falha variou por execução — preflight, backup de banco, preflight de
# novo — porque o limite é por VOLUME de conexões e ACUMULA: às 21:17 nove conexões
# passaram e a décima caiu; às 21:32 nenhuma passou. Os passos de publicação já
# tinham retry e por isso não falhavam; preflight e backup de banco não tinham.
#
# Este guarda é ESTRUTURAL de propósito: reproduzir a falha é probabilístico e
# depende do host, então validar a correção pela reprodução não é viável. O que se
# pode afirmar sem conexão é que a proteção está no lugar. Mesmo padrão de
# test-clone-activation-options.sh e test-clone-path-bound-options.sh.
#
# ESCOPO HONESTO: este teste NÃO exige retry em todo passo que abre SSH. Camadas 3
# (trocar ssh_once por ssh_retry em uonix_exec) e 4 (multiplexar para reduzir o
# número de sessões) foram deliberadamente deixadas fora: a primeira muda o
# comportamento de todos os chamadores e exige análise por chamador; a segunda é
# mudança de arquitetura. Ampliar a exigência aqui antes dessa decisão
# transformaria o guarda num bloqueio sem correção disponível.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
RETRY_LIB="$ROOT_DIR/scripts/lib/ssh-retry.sh"
TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/uonix-retry-coverage.XXXXXX")"
trap 'rm -rf "$TMP_DIR"' EXIT HUP INT TERM

fail() {
  printf 'FAIL: %s\n' "$*" >&2
  exit 1
}

[ -f "$RETRY_LIB" ] || fail 'scripts/lib/ssh-retry.sh não encontrado.'

# --- Parte 1: cobertura estrutural no YAML e no script de backup -------------
python3 - "$ROOT_DIR" <<'PY'
import pathlib
import re
import sys

root = pathlib.Path(sys.argv[1])
production = (root / '.github/workflows/deploy-production.yml').read_text(encoding='utf-8')
backup_script = (root / 'scripts/backup-remote-database.sh').read_text(encoding='utf-8')


def fail(message):
    raise SystemExit(f'FAIL: {message}')


def executable(text):
    """Corpo executável: sem linhas de comentário e com continuações unidas.

    Comentários fora porque este arquivo documenta em prosa as próprias bandeiras
    que as asserções exigem — asserir sobre o texto cru deixaria a REMOÇÃO da
    bandeira passar impune, satisfeita pelo comentário que a explica.

    Continuações unidas porque o workflow quebra os comandos de SSH em muitas
    linhas com `\\`. Sem unir, `ssh-retry.sh` e `sshpass` caem em linhas
    diferentes e nenhuma asserção de vizinhança consegue relacioná-los.
    """
    without_comments = '\n'.join(
        line for line in text.splitlines()
        if line.strip() and not line.lstrip().startswith('#')
    )
    return re.sub(r'\\\n\s+', ' ', without_comments)


def named_step_run(document, step_name):
    lines = document.splitlines()
    name_index = next(
        index for index, line in enumerate(lines)
        if line.strip() == f'- name: {step_name}'
    )
    for index in range(name_index + 1, len(lines)):
        if lines[index].lstrip().startswith('- name:'):
            break
        match = re.match(r'^(\s*)run:\s*\|\s*$', lines[index])
        if not match:
            continue
        run_indent = len(match.group(1))
        body = []
        for body_line in lines[index + 1:]:
            indentation = len(body_line) - len(body_line.lstrip())
            if body_line.strip() and indentation <= run_indent:
                break
            body.append(body_line[run_indent + 2:] if body_line.strip() else '')
        return '\n'.join(body) + '\n'
    fail(f'step sem run literal: {step_name}')


# Limites da cadência, com a razão por extenso.
#
# O limite da Locaweb acumula, então MAIS tentativas em MENOS tempo piora: as três
# execuções de 2026-09-22 foram disparadas em sequência achando que a falha era
# transitória, e a terceira ficou pior que a segunda. Por isso o teto de tentativas
# e o piso de espaçamento, e não um "tem retry" qualquer.
MAX_ATTEMPTS_CEILING = 3
MIN_DELAY_SECONDS = 60

# --- 1.1 preflight: leitura pura, tem de passar por ssh-retry.sh --------------
preflight = executable(named_step_run(
    production, 'Preflight SSH window before any remote write'
))
ssh_lines = [line for line in preflight.splitlines() if 'sshpass' in line]
if len(ssh_lines) != 1:
    fail(
        'esperada exatamente uma invocação de sshpass no preflight, '
        f'encontradas {len(ssh_lines)}'
    )

# Vizinhança ANCORADA: `ssh-retry.sh <flags> <n> <d> --` imediatamente antes do
# sshpass, na mesma linha lógica. Um grep solto por "ssh-retry.sh" no step passaria
# com a chamada real desprotegida e a string aparecendo em outro lugar.
protection = re.search(
    r'bash scripts/lib/ssh-retry\.sh\s+--replay-stdin\s+(\d+)\s+(\d+)\s+--\s+sshpass -e ssh\b',
    ssh_lines[0],
)
if not protection:
    fail(
        'o preflight abre SSH sem passar por scripts/lib/ssh-retry.sh --replay-stdin; '
        'uma única conexão cortada volta a reprovar o deploy inteiro. Linha: '
        + ssh_lines[0].strip()[:160]
    )
attempts, delay = int(protection.group(1)), int(protection.group(2))
if attempts < 2:
    fail(f'retry do preflight com {attempts} tentativa(s): não há retry algum')
if attempts > MAX_ATTEMPTS_CEILING:
    fail(
        f'retry do preflight com {attempts} tentativas excede o teto de '
        f'{MAX_ATTEMPTS_CEILING}: cada tentativa soma uma conexão ao contador da '
        'Locaweb, que acumula'
    )
if delay < MIN_DELAY_SECONDS:
    fail(
        f'espaçamento de {delay}s no retry do preflight é menor que o piso de '
        f'{MIN_DELAY_SECONDS}s; rajada curta alimenta o limite por volume em vez '
        'de esperar que ele recue'
    )

# --- 1.2 toda invocação com heredoc precisa de --replay-stdin ----------------
# E, no sentido inverso, nenhuma invocação SEM heredoc pode tê-la: com a bandeira,
# ssh-retry.sh lê stdin até EOF, e num `rsync` isso pendura o passo.
retry_lines = [
    line for line in executable(production).splitlines()
    if 'scripts/lib/ssh-retry.sh' in line
]
if not retry_lines:
    fail('deploy-production.yml não invoca scripts/lib/ssh-retry.sh em lugar algum')

with_heredoc = 0
for line in retry_lines:
    # Qualquer tag de heredoc, não só a literal 'REMOTE': o repositório já usa
    # outras (UONIX_DB_BACKUP em backup-remote-database.sh), e alguém que copie
    # esse padrão para o workflow reintroduziria o fail-open com a suíte verde.
    reads_stdin = re.search(r"<<'[A-Z_]+'\s*$", line) is not None
    replays = '--replay-stdin' in line
    if reads_stdin:
        with_heredoc += 1
        if not replays:
            fail(
                'invocação de ssh-retry.sh alimentada por heredoc sem --replay-stdin: '
                'a 2ª tentativa receberia stdin vazio, `bash -s` sairia 0 e o retry '
                'declararia sucesso sem executar nada. Linha: ' + line.strip()[:160]
            )
    elif replays:
        fail(
            '--replay-stdin numa invocação que não lê stdin: ssh-retry.sh bloquearia '
            'esperando EOF. Linha: ' + line.strip()[:160]
        )

# Piso de contagem: sem ele, apagar TODAS as invocações com heredoc satisfaria o
# laço acima por vacuidade (nenhuma linha para reprovar).
if with_heredoc < 5:
    fail(
        f'apenas {with_heredoc} invocação(ões) de ssh-retry.sh com heredoc; eram 5 '
        '(marcador de código, reserva e verificação do manifesto, poda de módulos) '
        'mais o preflight. Alguma proteção saiu do workflow.'
    )

# --- 1.3 backup de banco: a variante com retry, na chamada do dump -----------
backup_code = executable(backup_script)
if not re.search(
    r'^\s*if ! output="\$\(uonix_transport_ssh_retry ', backup_code, re.M
):
    fail(
        'scripts/backup-remote-database.sh não usa uonix_transport_ssh_retry na '
        'chamada do dump; o backup volta a cair na primeira conexão cortada'
    )
if 'uonix_transport_ssh_once' in backup_code:
    fail(
        'scripts/backup-remote-database.sh ainda referencia uonix_transport_ssh_once '
        'em código executável'
    )

# --- 1.4 a cadência precisa CHEGAR ao script, pelo env do job deploy ---------
deploy_start = production.index('\n  deploy:')
deploy_end = production.index('- name: Validate canonical production target')
deploy_header = production[deploy_start:deploy_end]
cadence = {}
for name in ('UONIX_TRANSPORT_MAX_ATTEMPTS', 'UONIX_TRANSPORT_RETRY_DELAY'):
    # `[1-9][0-9]*` e não `\d+`: um valor com zero à esquerda ('03') passaria
    # aqui como 3 e a lib o rejeitaria em tempo de execução — suíte verde e
    # backup de banco morto na primeira chamada.
    match = re.search(rf"^\s+{name}:\s*'?([1-9][0-9]*)'?\s*$", deploy_header, re.M)
    if not match:
        fail(
            f'{name} não está no env do job deploy; os dois checkpoints de banco '
            'cairiam no default 5x10 da lib, que é a rajada curta que o host pune'
        )
    cadence[name] = int(match.group(1))
if cadence['UONIX_TRANSPORT_MAX_ATTEMPTS'] < 2:
    fail('UONIX_TRANSPORT_MAX_ATTEMPTS < 2 desliga o retry do backup de banco')
if cadence['UONIX_TRANSPORT_MAX_ATTEMPTS'] > MAX_ATTEMPTS_CEILING:
    fail(
        f"UONIX_TRANSPORT_MAX_ATTEMPTS={cadence['UONIX_TRANSPORT_MAX_ATTEMPTS']} "
        f'excede o teto de {MAX_ATTEMPTS_CEILING}'
    )
if cadence['UONIX_TRANSPORT_RETRY_DELAY'] < MIN_DELAY_SECONDS:
    fail(
        f"UONIX_TRANSPORT_RETRY_DELAY={cadence['UONIX_TRANSPORT_RETRY_DELAY']} é "
        f'menor que o piso de {MIN_DELAY_SECONDS}s'
    )

print(
    f'  cobertura: preflight {attempts}x{delay}s, {with_heredoc} invocações com '
    f"replay de stdin, banco {cadence['UONIX_TRANSPORT_MAX_ATTEMPTS']}x"
    f"{cadence['UONIX_TRANSPORT_RETRY_DELAY']}s"
)
PY

# --- Parte 2: a bandeira que o workflow exige precisa mesmo funcionar ---------
# Asserção estrutural sozinha não basta: se a lib ignorasse --replay-stdin, o
# workflow ficaria verde aqui e fail-open em produção.

# 2.1 O fato de plataforma que torna o replay obrigatório: `bash -s` sem entrada
# não executa nada e sai 0. É por isso que uma tentativa com stdin vazio APROVA
# o preflight em vez de reprová-lo.
if ! bash -s < /dev/null; then
  fail 'bash -s sem entrada não saiu 0; a premissa do replay mudou e o comentário do workflow precisa ser revisto.'
fi

# 2.2 O payload tem de chegar em TODA tentativa, não só na primeira.
cat > "$TMP_DIR/consumer.sh" <<'CONSUMER'
#!/usr/bin/env bash
set -u
attempt=0
[ -f "$PROBE_COUNT" ] && attempt="$(cat "$PROBE_COUNT")"
attempt=$((attempt + 1))
printf '%s\n' "$attempt" > "$PROBE_COUNT"
payload="$(cat)"
printf 'attempt=%s bytes=%s\n' "$attempt" "${#payload}" >> "$PROBE_LOG"
[ "$attempt" -ge 3 ] || exit 255
exit 0
CONSUMER

PROBE_COUNT="$TMP_DIR/count"
PROBE_LOG="$TMP_DIR/log"
export PROBE_COUNT PROBE_LOG
: > "$PROBE_LOG"

bash "$RETRY_LIB" --replay-stdin 3 0 -- bash "$TMP_DIR/consumer.sh" 2>/dev/null <<'PAYLOAD'
echo payload-remoto
PAYLOAD

[ "$(cat "$PROBE_COUNT")" = 3 ] \
  || fail "esperadas 3 tentativas com --replay-stdin, houve $(cat "$PROBE_COUNT")"
if grep -q 'bytes=0' "$PROBE_LOG"; then
  fail "alguma tentativa recebeu stdin vazio com --replay-stdin: $(cat "$PROBE_LOG")"
fi

# 2.3 Payload vazio é recusado (exit 64), não tratado como sucesso: pedir replay
# sem payload só pode ser erro de chamador.
set +e
bash "$RETRY_LIB" --replay-stdin 2 0 -- true < /dev/null >/dev/null 2>&1
empty_status=$?
set -e
[ "$empty_status" -eq 64 ] \
  || fail "--replay-stdin com stdin vazio deveria sair 64, saiu $empty_status"

# 2.4 A bandeira não pode ter afrouxado o critério "só 255 retenta" nem corrompido
# o status propagado. Uma condição composta mal escrita devolveria o status do
# teste da bandeira em vez do status do comando.
rm -f "$PROBE_COUNT"
: > "$PROBE_LOG"
cat > "$TMP_DIR/logic-failure.sh" <<'LOGIC'
#!/usr/bin/env bash
set -u
attempt=0
[ -f "$PROBE_COUNT" ] && attempt="$(cat "$PROBE_COUNT")"
attempt=$((attempt + 1))
printf '%s\n' "$attempt" > "$PROBE_COUNT"
cat > /dev/null
exit 7
LOGIC
set +e
bash "$RETRY_LIB" --replay-stdin 3 0 -- bash "$TMP_DIR/logic-failure.sh" <<'PAYLOAD' >/dev/null 2>&1
echo payload-remoto
PAYLOAD
logic_status=$?
set -e
[ "$logic_status" -eq 7 ] \
  || fail "falha de lógica remota deveria propagar exit 7 com --replay-stdin, propagou $logic_status"
[ "$(cat "$PROBE_COUNT")" = 1 ] \
  || fail "exit não-255 não deve retentar com --replay-stdin; tentativas=$(cat "$PROBE_COUNT")"

printf 'PASS: preflight e backup de banco passam por retry espaçado, todo heredoc retentado é reenviado, e a bandeira de replay preserva o critério de 255.\n'
