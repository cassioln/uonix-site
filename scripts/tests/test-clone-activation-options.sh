#!/usr/bin/env bash
# Garante que opções que ATIVAM comportamento sejam preservadas no destino ao
# clonar o banco entre ambientes.
#
# A categoria é diferente da de test-clone-path-bound-options.sh. Lá o problema é
# um valor inválido fora do ambiente de origem (caminho de disco). Aqui o valor é
# perfeitamente válido no destino — e é justamente por isso que é perigoso: ele
# LIGA no destino uma automação que ninguém pediu ali.
#
# MOTIVAÇÃO (defeito pego em revisão, 2026-09-22): o relatório executivo semanal
# passou a ser agendado por um invariante — existe evento agendado se, e somente
# se, existe destinatário (mu-plugins/uonix-admin/57-admin-intelligence-report.php).
# Isso foi apresentado como contido por ambiente, com o argumento de que a opção
# `uonix_executive_report_recipients` vive no banco de cada ambiente.
#
# O argumento era falso. O clone preserva `cron` no destino, então o EVENTO não
# viaja — mas os DESTINATÁRIOS viajavam. Num clone `prod -> qa`, o destino herdava
# a lista e o callback de `init` criava o evento semanal no QA na requisição
# seguinte. O guard de e-mail de 49-email-environment-label.php contém o envio,
# redirecionando para a caixa segura, mas o painel do QA passava a exibir um
# "próximo disparo" concreto: afirmava automação ativa que ninguém configurou ali.
#
# Preservar a opção do destino resolve na raiz: um ambiente onde nunca se
# cadastrou destinatário continua com a lista vazia, e nada é agendado.

set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLONE="$ROOT_DIR/scripts/clone-environment.sh"

[ -f "$CLONE" ] || {
  echo "FALHA: scripts/clone-environment.sh não encontrado." >&2
  exit 1
}

failures=0

report() {
  printf '  FALHA %s\n' "$1" >&2
  failures=$((failures + 1))
}

# Extrai o predicado SQL de opções protegidas do próprio script, sem executá-lo.
protected_sql="$(
  awk '/^protected_options_where\(\) \{/{flag=1; next} /^SQL$/{flag=0} flag' "$CLONE" |
    grep -v "^  cat <<'SQL'$"
)"

[ -n "$protected_sql" ] || {
  echo 'FALHA: não foi possível extrair protected_options_where() do script.' >&2
  exit 1
}

# Opções que ligam automação no destino e PRECISAM ser preservadas.
# Ao criar uma opção que, sozinha, ativa envio, agendamento ou integração
# externa, inclua-a aqui.
activation_options=(
  'uonix_executive_report_recipients'
)

# O casamento é ancorado DENTRO da lista `IN (...)`, e não um grep solto pelo
# nome: a opção pode aparecer no predicado numa cláusula que não protege — por
# exemplo um `NOT LIKE` — e um grep solto daria por protegida o que não está.
for option in "${activation_options[@]}"; do
  if ! printf '%s' "$protected_sql" | grep -qE "IN \([^)]*'$option'"; then
    report "opção '$option' liga automação no destino e NÃO está na lista IN de protected_options_where(); clonar ativaria o agendamento no ambiente clonado."
  fi
done

# `cron` precisa continuar protegida junto: proteger o destinatário e deixar o
# evento viajar traria de volta o mesmo problema pelo outro lado.
if ! printf '%s' "$protected_sql" | grep -qE "IN \([^)]*'cron'"; then
  report "a opção 'cron' saiu de protected_options_where(); o evento agendado da origem passaria a viajar para o destino."
fi

# Opções de ESTADO, categoria distinta das de ativação acima.
#
# Elas não ligam automação no destino: fazem a interface do destino AFIRMAR algo
# que só vale na origem. `uonix_intelligence_anomaly_state` guarda o resultado da
# última verificação de anomalia e o sinalizador de "já avisei" por gatilho. Herdada
# num clone `prod -> qa`, o painel do QA exibe o badge "anomalia crítica detectada"
# com o detalhe do incidente da PRODUÇÃO, e o sinalizador herdado faz o QA achar que
# já avisou sobre um episódio que nunca observou.
#
# Diferente das de ativação, aqui o guard de e-mail não contém nada: o dano é na
# afirmação da tela, não no envio.
state_options=(
  'uonix_intelligence_anomaly_state'
)

for option in "${state_options[@]}"; do
  if ! printf '%s' "$protected_sql" | grep -qE "IN \([^)]*'$option'"; then
    report "opção de estado '$option' NÃO está na lista IN de protected_options_where(); o ambiente clonado passaria a afirmar na tela um estado que é da origem."
  fi
done

# Quem consome o invariante precisa continuar amarrando agendamento a
# destinatário. Se essa amarração sair, proteger a opção deixa de bastar.
REPORT_MODULE="$ROOT_DIR/mu-plugins/uonix-admin/57-admin-intelligence-report.php"
if [ -f "$REPORT_MODULE" ]; then
  if ! grep -q 'uonix_intelligence_maybe_schedule_report' "$REPORT_MODULE"; then
    report 'o módulo do relatório não tem mais o callback que mantém o invariante; esta proteção pressupõe que agendamento depende de destinatário.'
  fi
else
  report "módulo do relatório não encontrado em $REPORT_MODULE."
fi

if [ "$failures" -ne 0 ]; then
  printf 'FALHA: %s problema(s) na proteção de opções que ativam automação.\n' "$failures" >&2
  exit 1
fi

printf 'PASS: %s opção(ões) de ativação protegida(s) no clone, e o invariante segue no lugar.\n' "${#activation_options[@]}"
