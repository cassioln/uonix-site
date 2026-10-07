#!/usr/bin/env bash
# Esteira de atualização de plugins (#393).
#
#   atualizar.sh inventario [--entrada=ARQ]
#       Lê de produção, numa única conexão SSH, os plugins e temas com versão,
#       atualização disponível e atualização automática, e classifica pela
#       política. Com --entrada, usa um JSON já salvo e não conecta.
#
#   atualizar.sh contratos [--plugins-dir=DIR] [--temas-dir=DIR] [SLUG ...]
#       Verifica os contratos contra o fonte instalado. O padrão é o ambiente
#       local, que é onde o ensaio acontece.
#
#   atualizar.sh ensaiar [--entrada=ARQ] [--reusar-clone]
#                        [--aceitar-major=a,b] [--dispensar-quarentena=a,b]
#       Lê o inventário de produção, monta o plano (plano.py) e ensaia no local
#       (ensaio.py): clone prod -> local, alinhamento, backup, smoke antes,
#       atualização na ordem do plano com smoke e contratos a cada etapa.
#       Grava o lock só quando tudo fica verde. Saída 0, 20 ou 30.
#       Muta SOMENTE o ambiente local; produção é só lida.
#
#   atualizar.sh aplicar-producao --lock=ARQ --confirmacao='ATUALIZAR PROD <sha12>'
#       Aplica em PRODUÇÃO as versões exatas de um lock verde do ensaio:
#       valida o lock, sonda a janela SSH, faz backup do banco, envia o job
#       (remoto-aplicar.sh) e o executa desacoplado da conexão, e roda o smoke
#       de produção. Saída 0 aplicado; 20 parou e restaurou; 30 recusado sem
#       alterar nada; 40 conexão perdida com o job em andamento (ver estado).
#
#   atualizar.sh estado-producao [--backup=ID]
#       Só leitura: manutenção, job vivo, status e fim do log do último job
#       (ou do backup indicado). É o primeiro passo depois de qualquer queda.
#
# Exige a janela SSH da Locaweb aberta para o inventário. As credenciais vêm do
# .env do checkout principal, como no clone e no backup de banco.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

# O checkout principal guarda o que não é versionado: .env, local/wp-content e
# backups/. Rodando de uma worktree, ROOT_DIR não tem nada disso.
checkout_principal() {
  if [ -n "${UONIX_CHECKOUT_PRINCIPAL:-}" ]; then
    printf '%s\n' "$UONIX_CHECKOUT_PRINCIPAL"
    return
  fi
  local comum
  comum="$(git -C "$ROOT_DIR" rev-parse --path-format=absolute --git-common-dir 2>/dev/null)" || {
    printf '%s\n' "$ROOT_DIR"
    return
  }
  dirname "$comum"
}
CHECKOUT="$(checkout_principal)"
SAIDA_DIR="${UONIX_PLUGINS_SAIDA_DIR:-${CHECKOUT}/tmp/plugins}"

uso() {
  # O bloco de comentário do topo, sem depender de número de linha.
  awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "${BASH_SOURCE[0]}"
}

# Credenciais do checkout principal, para a leitura de produção e para o clone
# do ensaio (o clone roda de ROOT_DIR, que numa worktree não tem .env).
carregar_env() {
  if [ -z "${LOCAWEB_DOCUMENT_ROOT:-}" ] && [ -f "${CHECKOUT}/.env" ]; then
    set -a
    # shellcheck source=/dev/null
    source "${CHECKOUT}/.env"
    set +a
  fi
}

# Roda um comando com limite de tempo real para as conexões SSH deste projeto.
# Janela da Locaweb fechada pendura em silêncio, e este Mac não tem `timeout`.
# Um alarme no processo pai não basta: o ssh filho manteria o stdout aberto e
# o chamador esperaria por ele. O cão de guarda mata os ssh que usam o diretório
# de sockets do transporte, ou seja, só os deste projeto.
# Uso: com_vigia SEGUNDOS comando [args...]; devolve o status do comando.
com_vigia() {
  local limite="$1" vigia status=0
  shift
  local sockets="${UONIX_SSH_CONTROL_DIR:-${RUNNER_TEMP:-/tmp/uonix-ssh-${UID:-0}}}"
  ( sleep "$limite"; pkill -9 -f -- "$sockets" ) >/dev/null 2>&1 &
  vigia=$!
  "$@" || status=$?
  kill "$vigia" 2>/dev/null || true
  return "$status"
}

ler_producao() {
  local destino="$1"
  carregar_env
  : "${LOCAWEB_DOCUMENT_ROOT:?Defina LOCAWEB_DOCUMENT_ROOT (rode do checkout principal, que tem o .env)}"
  : "${LOCAWEB_PHP_BIN:?Defina LOCAWEB_PHP_BIN}"
  : "${LOCAWEB_WP_BIN:?Defina LOCAWEB_WP_BIN}"

  local wp campos remoto bruto
  wp="$(printf '%q -d disable_functions= %q --path=%q' "$LOCAWEB_PHP_BIN" "$LOCAWEB_WP_BIN" "$LOCAWEB_DOCUMENT_ROOT")"
  campos='name,status,version,update,update_version,auto_update'
  remoto="cd $(printf '%q' "$LOCAWEB_DOCUMENT_ROOT") || exit 1
echo '@@PLUGINS'; ${wp} plugin list --fields=${campos} --format=json 2>/dev/null || exit 3
printf '\n@@TEMAS\n'; ${wp} theme list --fields=${campos} --format=json 2>/dev/null || exit 3
printf '\n@@FIM\n'"
  # O printf com quebra de linha antes do marcador é necessário: o WP-CLI
  # imprime o JSON sem quebra final, e o marcador grudaria na última linha.

  # Primeiro plano, sem retry: uma leitura, uma conexão (a Locaweb bloqueia
  # rajadas de conexões curtas), com limite de tempo real (com_vigia).
  # shellcheck source=scripts/lib/ssh-transport.sh
  source "${ROOT_DIR}/scripts/lib/ssh-transport.sh"
  local limite="${UONIX_PLUGINS_LIMITE_SSH:-150}" status=0
  bruto="$(com_vigia "$limite" uonix_transport_ssh_once prod "$remoto")" || status=$?
  uonix_transport_close_master prod
  if [ "$status" -ne 0 ]; then
    echo "Erro: leitura de produção falhou (exit ${status}; janela SSH aberta? limite ${limite}s)" >&2
    return 1
  fi

  # Separa pelos marcadores; falha de qualquer tipo guarda a saída bruta (#413).
  printf '%s\n' "$bruto" | python3 "${ROOT_DIR}/scripts/plugins/ler_saida.py" "$destino"
}

cmd_inventario() {
  local entrada=""
  for arg in "$@"; do
    case "$arg" in
      --entrada=*) entrada="${arg#*=}" ;;
      *) echo "Erro: argumento desconhecido: $arg" >&2; return 2 ;;
    esac
  done
  if [ -z "$entrada" ]; then
    mkdir -p "$SAIDA_DIR"
    entrada="${SAIDA_DIR}/inventario-prod-$(date -u +%Y%m%d-%H%M%S).json"
    ler_producao "$entrada" || return 1
    echo "Inventário salvo em ${entrada#"${ROOT_DIR}"/}"
    echo
  fi
  python3 "${ROOT_DIR}/scripts/plugins/inventario.py" --entrada="$entrada"
}

cmd_contratos() {
  local plugins_dir="${CHECKOUT}/local/wp-content/plugins"
  local temas_dir="${CHECKOUT}/local/wp-content/themes"
  local slugs=()
  for arg in "$@"; do
    case "$arg" in
      --plugins-dir=*) plugins_dir="${arg#*=}" ;;
      --temas-dir=*) temas_dir="${arg#*=}" ;;
      --*) echo "Erro: argumento desconhecido: $arg" >&2; return 2 ;;
      *) slugs+=("$arg") ;;
    esac
  done
  python3 "${ROOT_DIR}/scripts/plugins/contratos.py" verificar \
    --plugins-dir="$plugins_dir" --temas-dir="$temas_dir" ${slugs[@]+"${slugs[@]}"}
}

cmd_ensaiar() {
  local entrada="" reusar="" opcoes=()
  for arg in "$@"; do
    case "$arg" in
      --entrada=*) entrada="${arg#*=}" ;;
      --reusar-clone) reusar="--reusar-clone" ;;
      --aceitar-major=*|--dispensar-quarentena=*) opcoes+=("$arg") ;;
      *) echo "Erro: argumento desconhecido: $arg" >&2; return 2 ;;
    esac
  done
  carregar_env
  local dir
  dir="${SAIDA_DIR}/ensaio-$(date -u +%Y%m%d-%H%M%S)"
  mkdir -p "$dir"
  if [ -n "$entrada" ]; then
    cp "$entrada" "${dir}/inventario-prod.json"
  else
    ler_producao "${dir}/inventario-prod.json" || return 30
  fi
  python3 "${ROOT_DIR}/scripts/plugins/plano.py" --inventario "${dir}/inventario-prod.json" \
    --saida "${dir}/plano.json" ${opcoes[@]+"${opcoes[@]}"} || return 30
  echo
  python3 "${ROOT_DIR}/scripts/plugins/ensaio.py" --inventario "${dir}/inventario-prod.json" \
    --plano "${dir}/plano.json" --checkout "$CHECKOUT" --saida "$dir" ${reusar:+"$reusar"}
}

sondar_janela() {
  # shellcheck source=scripts/lib/ssh-transport.sh
  source "${ROOT_DIR}/scripts/lib/ssh-transport.sh"
  local saida
  saida="$(com_vigia "${UONIX_PLUGINS_LIMITE_SONDA:-30}" uonix_transport_ssh_once prod 'echo JANELA-ABERTA')" || true
  uonix_transport_close_master prod
  [[ "$saida" == *JANELA-ABERTA* ]]
}

cmd_aplicar_producao() {
  local lock="" confirmacao="" max_horas="${UONIX_PLUGINS_LOCK_MAX_HORAS:-72}"
  for arg in "$@"; do
    case "$arg" in
      --lock=*) lock="${arg#*=}" ;;
      --confirmacao=*) confirmacao="${arg#*=}" ;;
      *) echo "Erro: argumento desconhecido: $arg" >&2; return 2 ;;
    esac
  done
  [ -f "$lock" ] || { echo "Erro: informe --lock=<lock.json de um ensaio verde>" >&2; return 30; }
  local validado sha lista dir
  validado="$(python3 "${ROOT_DIR}/scripts/plugins/lock.py" validar --lock "$lock" --max-horas "$max_horas")" || return 30
  sha="$(head -1 <<<"$validado")"
  lista="$(tail -n +2 <<<"$validado")"
  dir="$(cd "$(dirname "$lock")" && pwd)"
  echo "== lock ${sha} ($(wc -l <<<"$lista" | tr -d ' ') plugin(s)):"
  while IFS= read -r linha; do printf '   %s\n' "$linha"; done <<<"$lista"
  if [ "$confirmacao" != "ATUALIZAR PROD ${sha}" ]; then
    echo "Erro: confirmação ausente ou errada. Para aplicar, repita com:" >&2
    echo "  --confirmacao='ATUALIZAR PROD ${sha}'" >&2
    return 30
  fi

  carregar_env
  : "${LOCAWEB_ACCOUNT_ROOT:?Defina LOCAWEB_ACCOUNT_ROOT}" "${LOCAWEB_DOCUMENT_ROOT:?}" "${LOCAWEB_PHP_BIN:?}" "${LOCAWEB_WP_BIN:?}"
  echo "== sonda da janela SSH"
  sondar_janela || { echo "Erro: produção não respondeu em ${UONIX_PLUGINS_LIMITE_SONDA:-30}s (janela SSH fechada?). Nada foi alterado." >&2; return 30; }
  echo "   janela aberta"

  local stamp bk
  stamp="plugins-$(date -u +%Y%m%d-%H%M%S)"
  bk="${LOCAWEB_ACCOUNT_ROOT}/_uonix-deploy-backups/${stamp}"
  echo "== backup do banco: ${stamp}"
  com_vigia "${UONIX_PLUGINS_LIMITE_BACKUP:-900}" bash "${ROOT_DIR}/scripts/backup-remote-database.sh" \
    --environment=prod --output-dir="$bk" --backup-id="$stamp" \
    || { echo "Erro: backup do banco falhou. Nada foi alterado." >&2; return 30; }

  # O job vai pelo stdin, é gravado no servidor e roda sob nohup; a conexão só
  # acompanha o log. Se ela cair, o job termina sozinho (ver estado-producao).
  local args q_bk remoto saida status
  args="$(printf '%q ' "$LOCAWEB_DOCUMENT_ROOT" "$LOCAWEB_PHP_BIN" "$LOCAWEB_WP_BIN" "$bk" "$lista")"
  q_bk="$(printf '%q' "$bk")"
  remoto="set -u; mkdir -p ${q_bk} && chmod 700 ${q_bk} && cat > ${q_bk}/aplicar.sh || exit 1
( nohup bash ${q_bk}/aplicar.sh ${args} > ${q_bk}/aplicar.log 2>&1 < /dev/null & echo \$! > ${q_bk}/aplicar.pid )
sleep 2; pid=\$(cat ${q_bk}/aplicar.pid); echo \"== job remoto pid=\$pid\"
tail -n +1 -f ${q_bk}/aplicar.log & t=\$!
while kill -0 \"\$pid\" 2>/dev/null; do sleep 3; done
sleep 1; kill \"\$t\" 2>/dev/null
echo \"@@STATUS=\$(cat ${q_bk}/aplicar.status 2>/dev/null || echo ausente)\""
  echo "== job remoto (se a conexão cair, ele continua: rode estado-producao --backup=${stamp})"
  saida="$(com_vigia "${UONIX_PLUGINS_LIMITE_JOB:-1800}" uonix_transport_ssh_once prod "$remoto" \
    < "${ROOT_DIR}/scripts/plugins/remoto-aplicar.sh" | tee /dev/stderr)" || true
  uonix_transport_close_master prod
  status="$(sed -n 's/^@@STATUS=//p' <<<"$saida" | tail -1)"
  case "$status" in
    0|20|30) ;;
    *)
      echo "Erro: a conexão caiu antes do fim do job (status '${status:-desconhecido}')." >&2
      echo "  Não reexecute. Leia o estado: atualizar.sh estado-producao --backup=${stamp}" >&2
      registrar_aplicacao "$dir" "$stamp" "$sha" "conexao-perdida" ""
      return 40 ;;
  esac

  local smoke_rc="" extras=()
  if [ "$status" = 0 ]; then
    echo "== smoke de produção (só leitura)"
    while IFS= read -r caminho; do extras+=("--extra=${caminho}"); done < <(
      python3 -c 'import json,sys; [print(k[len("pagina "):]) for k in json.load(open(sys.argv[1])) if k.startswith("pagina ")]' \
        "${dir}/smoke-antes.json" 2>/dev/null | grep -vxE '/|/blog/|/servicos/|/produtos/|/cotacao/|/sitemap_index.xml|/wp-json/|/wp-login.php' || true)
    smoke_rc=0
    python3 "${ROOT_DIR}/scripts/plugins/smoke_producao.py" --saida "${dir}/smoke-producao.json" ${extras[@]+"${extras[@]}"} || smoke_rc=$?
  fi
  registrar_aplicacao "$dir" "$stamp" "$sha" "$status" "$smoke_rc"
  if [ "$status" = 0 ] && [ "$smoke_rc" != 0 ]; then
    echo "ATENÇÃO: aplicado, mas o smoke de produção ficou vermelho. Backup: ${stamp}" >&2
    return 20
  fi
  return "$status"
}

registrar_aplicacao() {
  local dir="$1" stamp="$2" sha="$3" status="$4" smoke="$5"
  printf '{"executado_em": "%s", "backup": "%s", "lock_sha12": "%s", "status_job": "%s", "smoke_producao": "%s"}\n' \
    "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$stamp" "$sha" "$status" "$smoke" > "${dir}/aplicacao-${stamp}.json"
  echo "== registro: ${dir}/aplicacao-${stamp}.json"
}

cmd_estado_producao() {
  local backup=""
  for arg in "$@"; do
    case "$arg" in
      --backup=*) backup="${arg#*=}" ;;
      *) echo "Erro: argumento desconhecido: $arg" >&2; return 2 ;;
    esac
  done
  case "$backup" in *[!A-Za-z0-9._-]*) echo "Erro: backup inválido" >&2; return 2 ;; esac
  carregar_env
  # shellcheck source=scripts/lib/ssh-transport.sh
  source "${ROOT_DIR}/scripts/lib/ssh-transport.sh"
  local raiz="${LOCAWEB_ACCOUNT_ROOT}/_uonix-deploy-backups"
  local remoto
  remoto="cd $(printf '%q' "$LOCAWEB_DOCUMENT_ROOT") || exit 1
echo '== manutenção'; if [ -e .maintenance ]; then echo 'ATIVA (.maintenance existe)'; else echo 'desligada'; fi
echo '== jobs de aplicação vivos'; ps -u \"\$(id -un)\" -o pid,etime,args | grep '[a]plicar.sh' || echo '(nenhum)'
b=$(printf '%q' "$backup"); [ -n \"\$b\" ] || b=\$(ls -1d $(printf '%q' "$raiz")/plugins-* 2>/dev/null | tail -1 | xargs -n1 basename)
d=$(printf '%q' "$raiz")/\$b
echo \"== job: \$b\"; echo \"status: \$(cat \"\$d/aplicar.status\" 2>/dev/null || echo 'ausente (rodando, ou não chegou a começar)')\"
echo '== fim do log'; tail -n 25 \"\$d/aplicar.log\" 2>/dev/null || echo '(sem log)'"
  com_vigia "${UONIX_PLUGINS_LIMITE_SSH:-150}" uonix_transport_ssh_once prod "$remoto" \
    || { echo "Erro: leitura de produção falhou (janela SSH aberta?)" >&2; uonix_transport_close_master prod; return 1; }
  uonix_transport_close_master prod
}

main() {
  local comando="${1:-}"
  [ "$#" -gt 0 ] && shift
  case "$comando" in
    inventario) cmd_inventario "$@" ;;
    contratos) cmd_contratos "$@" ;;
    ensaiar) cmd_ensaiar "$@" ;;
    aplicar-producao) cmd_aplicar_producao "$@" ;;
    estado-producao) cmd_estado_producao "$@" ;;
    -h|--help|'') uso ;;
    *) echo "Erro: subcomando desconhecido: $comando" >&2; uso >&2; return 2 ;;
  esac
}

main "$@"
