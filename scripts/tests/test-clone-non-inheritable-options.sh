#!/usr/bin/env bash
# Garante que opções NÃO HERDÁVEIS não atravessem ambientes no clone.
#
# "Não herdável" = o valor é perfeitamente válido no destino, e é justamente por
# isso que é perigoso: herdado da origem, ele faz o destino se comportar como se
# fosse a origem. Categoria distinta das outras duas guardas sobre o mesmo
# predicado:
#
#   test-clone-path-bound-options.sh   -> valor INVÁLIDO fora da origem (caminho de disco)
#   test-clone-activation-options.sh   -> valor que LIGA automação no destino
#   esta                               -> valor que é DADO PESSOAL ou CREDENCIAL/
#                                         conexão privada e não deve repousar em
#                                         ambiente que não precisa dele
#
# O mecanismo que faz a proteção funcionar é o `DELETE FROM ... WHERE <predicado>`
# de restore_options(), não a preservação: num destino que nunca teve a opção não
# existe linha para preservar, e o que impede a herança é a remoção da linha que
# acabou de vir da origem. Estar no predicado garante os dois, porque o mesmo
# predicado governa snapshot e DELETE — asserido no fim deste arquivo.
#
# LIMITE DESTA PROTEÇÃO, para não prometer mais do que ela entrega: o predicado
# governa apenas `wp_options`. Os tokens OAuth POR USUÁRIO do Site Kit ficam em
# `usermeta`, cobertos por outro mecanismo — snapshot_users/restore_users. Esse
# mecanismo tem gate `PRESERVE_DESTINATION_USERS`, desligado por `--replace-users`.
# Logo, um clone `--replace-users` de produção LEVA os tokens de usuário para o
# destino, e esta guarda não cobre esse caminho. É pré-existente e explicitamente
# opt-in, mas a meta "a conexão OAuth não repousa onde não precisa" só é atingida
# no caminho padrão.

set -uo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLONE="$ROOT_DIR/scripts/clone-environment.sh"
METRICS="$ROOT_DIR/mu-plugins/uonix-admin/53-admin-analytics-metrics.php"
ANALYTICS_LGPD="$ROOT_DIR/mu-plugins/uonix-integrations/38-integracoes-analytics-lgpd.php"

for f in "$CLONE" "$METRICS" "$ANALYTICS_LGPD"; do
  [ -f "$f" ] || { printf 'FALHA: arquivo ausente: %s\n' "$f" >&2; exit 1; }
done

failures=0

report() {
  printf '  FALHA %s\n' "$1" >&2
  failures=$((failures + 1))
}

# Extrai o predicado SQL do próprio script, sem executá-lo.
#
# `grep -v '^[[:space:]]*--'` descarta comentário SQL, e não é zelo: sem ele, uma
# cláusula comentada (`-- OR option_name LIKE ...`) satisfaz todas as asserções
# abaixo enquanto a proteção está MORTA. `--` seguido de espaço é comentário de
# linha no MySQL, e o resto do predicado continua válido.
protected_sql="$(
  awk '/^protected_options_where\(\) \{/{flag=1; next} /^SQL$/{flag=0} flag' "$CLONE" |
    grep -v "^  cat <<'SQL'$" |
    grep -v '^[[:space:]]*--'
)"

# Corpo de uma função do script, para asserir sobre o que ela chama.
corpo_da_funcao() {
  sed -n "/^$1() {/,/^}/p" "$CLONE"
}

[ -n "$protected_sql" ] || {
  echo 'FALHA: não foi possível extrair protected_options_where() do script.' >&2
  exit 1
}

# Devolve o prefixo literal de uma cláusula LIKE que termina em `%`, sem o escape
# de underscore. Vazio quando não há cláusula cobrindo o termo.
prefixo_protegido() {
  printf '%s' "$protected_sql" |
    grep -oE "LIKE '[^']*$1%'" |
    head -1 |
    sed -E "s/^LIKE '//; s/%'$//; s/\\\\_/_/g"
}

# Um `NOT LIKE` com o mesmo texto passaria por uma checagem ingênua de presença e
# faria o oposto do pretendido.
nao_eh_exclusao() {
  if printf '%s' "$protected_sql" | grep -qE "NOT[[:space:]]+LIKE[[:space:]]*'[^']*$1"; then
    report "'$1' aparece num NOT LIKE; isso o EXCLUI da proteção em vez de protegê-lo."
  fi
}

# ===========================================================================
# GRUPO 1 — DADO PESSOAL: o snapshot de métricas
#
# MOTIVAÇÃO (medido em 2026-09-22, issue 253): o snapshot persiste o texto cru
# das consultas do Search Console — até 1000 por período, contra 10 antes. O
# filtro de PII descarta e-mail, telefone, URL e texto longo, mas deixa passar
# classes inteiras: nome próprio completo, CPF com barra, CEP, placa, e-mail
# escrito com "arroba". Sem esta proteção o universo de consultas de PRODUÇÃO
# desembarcava em QA e na máquina local a cada clone. O snapshot também não tem
# TTL: nenhum `delete_option` existe.
# ===========================================================================

# O prefixo é DERIVADO de dentro de uonix_analytics_metrics_snapshot_option(), e
# não escrito à mão aqui nem buscado no arquivo inteiro: as versões legadas v1 e
# v2 também aparecem no arquivo (fallback em cascata), então uma busca ampla
# continuaria achando um nome "parecido" depois de a opção corrente ser
# renomeada para fora do padrão.
# Extraído do `return` da função, e não do corpo delimitado por chave: o stop
# anterior era `/^\t}$/`, um tab literal, então reformatar o arquivo para espaços
# — ou tirar o wrapper `function_exists` — fazia o awk imprimir até o fim do
# arquivo e a extração voltar ao comportamento amplo que esta asserção existe
# para evitar. Ancorar no `return` não depende de indentação.
php_option="$(
  awk '/function uonix_analytics_metrics_snapshot_option\(/{f=1}
       f && /return/ {print; exit}' "$METRICS" |
    grep -oE "'uonix_[a-z0-9_]+'" |
    tr -d "'" |
    head -1
)"

snapshot_prefix="$(prefixo_protegido snapshot)"

if [ -z "$php_option" ]; then
  report 'não encontrei o nome da opção de snapshot dentro de uonix_analytics_metrics_snapshot_option(); a derivação do prefixo quebrou.'
elif [ -z "$snapshot_prefix" ]; then
  report "protected_options_where() não tem cláusula LIKE cobrindo o snapshot; a opção '$php_option' viajaria no clone."
else
  case "$php_option" in
    "$snapshot_prefix"*) : ;;
    *) report "o padrão protegido ('$snapshot_prefix%') NÃO cobre a opção que o módulo gera ('$php_option'); o snapshot viajaria." ;;
  esac

  # As versões LEGADAS também precisam estar cobertas: o fallback em cascata de
  # 53-admin-analytics-metrics.php ainda as lê, e elas carregam o mesmo dado.
  #
  # A lista é DERIVADA da cascata, não escrita à mão. Escrita à mão, ela divergiria
  # em silêncio, e a divergência é assimétrica: acrescentar uma geração ao fallback
  # sem acrescentá-la aqui deixaria a nova geração viajar no clone com o teste
  # VERDE — e o dado em questão é o texto cru de consultas do Search Console, que
  # é o motivo de a proteção existir.
  #
  # Dentro da cascata, a versão corrente aparece como CHAMADA de função e as
  # legadas como LITERAIS entre aspas. Por isso extrair os literais devolve
  # exatamente os legados: a corrente já é conferida acima, por `$php_option`.
  #
  # Ancorado em `function ...cascade(` e no `return`, como a extração de
  # `$php_option` acima, e pelo mesmo motivo: não depender de indentação. Trocar
  # tabs por espaços no PHP não pode alterar o que este teste cobre.
  legados="$(
    awk '/function uonix_analytics_metrics_snapshot_option_cascade\(/{f=1}
         f && /return/ {exit}
         f {print}' "$METRICS" |
      grep -oE "'uonix_[a-z0-9_]+'" |
      tr -d "'" |
      sort -u
  )"

  # Falha FECHADA: lista vazia faria o laço não executar e o teste passar sem
  # verificar nada — o padrão de "teste passando por motivo errado" que este
  # repositório já viu várias vezes. Sem cascata legível, isto reprova.
  if [ -z "$legados" ]; then
    report 'não consegui derivar os snapshots legados de uonix_analytics_metrics_snapshot_option_cascade(); a extração quebrou e a cobertura dos legados ficaria vazia.'
  else
    while IFS= read -r legado; do
      [ -n "$legado" ] || continue
      case "$legado" in
        "$snapshot_prefix"*) : ;;
        *) report "o padrão protegido não cobre o snapshot legado '$legado', que o fallback em cascata ainda lê." ;;
      esac
    done <<EOF
$legados
EOF
  fi
fi

nao_eh_exclusao snapshot

# ===========================================================================
# GRUPO 2 — CREDENCIAL E CONEXÃO PRIVADA: Google Site Kit
#
# MOTIVAÇÃO: o Site Kit está ativo em produção, e guarda em `wp_options` tanto a
# conexão OAuth quanto os IDs dos módulos (`googlesitekit_credentials`,
# `googlesitekit_<modulo>_settings`). Duas razões para não herdar:
#
# 1. CREDENCIAL. `docs/snippets.md` registra que "os IDs e a conexão OAuth do
#    Site Kit são estado privado do WordPress". Herdar significa a credencial de
#    produção repousando em QA e na máquina local — o mesmo motivo pelo qual smtp,
#    turnstile e captcha já estão protegidos.
# 2. CONTRATO DE AMBIENTE. `docs/ambientes.md` exige, para QA e local,
#    "Desabilitado; não configurar IDs GTM/GA4/AdOpt". Um QA que herda a conexão
#    do Site Kit passa a ler e possivelmente emitir tag da propriedade de
#    produção, contrariando o contrato — e interage com a guarda de contagem
#    dupla de 38-integracoes-analytics-lgpd.php.
#
# Proteger faz o destino manter o que é dele: onde nunca se conectou o Site Kit,
# o resultado é ausência de conexão, que é exatamente o estado desejado.
# ===========================================================================

sitekit_prefix="$(prefixo_protegido googlesitekit)"

if [ -z "$sitekit_prefix" ]; then
  report 'protected_options_where() não cobre as options do Google Site Kit; um clone levaria a conexão OAuth e os IDs de produção para o destino.'
else
  # Options reais do Site Kit, conferidas contra os módulos que
  # 38-integracoes-analytics-lgpd.php inspeciona.
  for opt in googlesitekit_credentials \
             googlesitekit_analytics-4_settings \
             googlesitekit_tagmanager_settings \
             googlesitekit_ads_settings \
             googlesitekit_adsense_settings \
             googlesitekit_active_modules; do
    case "$opt" in
      "$sitekit_prefix"*) : ;;
      # A mensagem diz "prefixo ancorado", e não "não cobre": um curinga inicial
      # (`%googlesitekit%`) protegeria MAIS, não menos, e dizer "não cobre" nesse
      # caso mandaria quem for corrigir para a direção errada.
      *) report "o padrão protegido ('$sitekit_prefix%') não é um prefixo ancorado que cubra '$opt'; exija prefixo ancorado, sem curinga inicial." ;;
    esac
  done
fi

nao_eh_exclusao googlesitekit

# A guarda pressupõe que o código gerenciado realmente lê options do Site Kit —
# se essa integração sair, esta proteção perde a razão de ser e deve ser revista
# junto, em vez de ficar como resíduo.
# Ancorado nas duas linhas que CONSTROEM a chave da option, e não num grep solto
# por "googlesitekit_": o arquivo menciona o termo em vários comentários, então
# uma checagem de presença continuaria passando mesmo depois de a leitura real
# sair do código.
if ! grep -qE "'googlesitekit_'\s*\.\s*\\\$modulo" "$ANALYTICS_LGPD"; then
  report 'o módulo de analytics/LGPD não monta mais a chave dinâmica das options do Site Kit; revise se esta proteção ainda faz sentido.'
fi
if ! grep -qE "'googlesitekit_ads_settings'" "$ANALYTICS_LGPD"; then
  report 'o módulo de analytics/LGPD não lê mais googlesitekit_ads_settings; revise se esta proteção ainda faz sentido.'
fi

# ===========================================================================
# INVARIANTES DO PREDICADO
# ===========================================================================

if ! printf '%s' "$protected_sql" | grep -qE '^option_name '; then
  report 'protected_options_where() não começa com uma cláusula option_name; o SQL montado ficaria inválido.'
fi

# O MESMO predicado tem de governar snapshot E DELETE.
#
# Esta é a asserção mais importante do arquivo, e a que faltava. O cabeçalho
# afirma que os dois usam o mesmo predicado, mas nada verificava. Se um refactor
# futuro estreitar o predicado de `snapshot_options()` e deixar o `DELETE` de
# `restore_options()` largo, o DELETE continua apagando tudo e o replay não traz
# mais as linhas do destino: **um único clone destrói em definitivo** o SMTP, o
# Turnstile, o captcha, o `admin_email`, o Site Kit e a lista de destinatários do
# PRÓPRIO destino — inclusive com destino produção. É a única falha desta família
# que destrói dado em vez de apenas deixar herdar.
for fn in snapshot_options restore_options; do
  corpo="$(corpo_da_funcao "$fn")"
  if [ -z "$corpo" ]; then
    report "não consegui extrair o corpo de $fn(); a asserção de predicado único não pode ser verificada."
  elif ! printf '%s' "$corpo" | grep -q 'protected_options_where'; then
    report "$fn() não chama protected_options_where(); snapshot e DELETE passariam a usar predicados diferentes, e um clone apagaria em definitivo os segredos do destino."
  fi
done

# Exige os DOIS caminhos de DELETE, um POR BRANCH e não por contagem no arquivo.
#
# `grep -c` no arquivo inteiro deixa passar a troca "remove o DELETE local e
# duplica o remoto": a contagem segue 2 e todo clone com destino local volta a
# herdar em silêncio. Um terceiro DELETE legítimo adicionado depois mascararia a
# remoção de um real pelo mesmo motivo.
restore_body="$(corpo_da_funcao restore_options)"
if [ -z "$restore_body" ]; then
  report 'não consegui extrair o corpo de restore_options() para verificar os caminhos de DELETE.'
else
  # O caminho local passa por local_db_query; o remoto monta delete_sql e envia.
  if ! printf '%s' "$restore_body" | grep -qE 'local_db_query "DELETE FROM .*\$\{?where'; then
    report 'restore_options() não tem o DELETE do caminho LOCAL parametrizado pelo predicado; clone com destino local voltaria a herdar.'
  fi
  if ! printf '%s' "$restore_body" | grep -qE 'delete_sql=.*DELETE FROM .*\$\{?where'; then
    report 'restore_options() não tem o DELETE do caminho REMOTO parametrizado pelo predicado; clone com destino remoto voltaria a herdar.'
  fi
fi

if [ "$failures" -ne 0 ]; then
  printf 'FALHA: %s problema(s) na proteção de opções não herdáveis.\n' "$failures" >&2
  exit 1
fi

# O número de legados derivados vai na saída de propósito. A lista não é mais
# escrita à mão, então não há como uma asserção fixar a contagem sem reintroduzir
# o número à mão que este teste acabou de remover. Imprimir torna visível um
# estreitamento silencioso da extração — de dois legados para um, por exemplo —
# sem transformar o número em contrato.
printf 'PASS: snapshot de métricas (prefixo derivado: %s) e Site Kit (%s) protegidos no clone; %s legado(s) derivado(s) da cascata e cobertos; 2 DELETE no lugar.\n' \
  "${snapshot_prefix:-?}" "${sitekit_prefix:-?}" \
  "$(printf '%s\n' "${legados:-}" | grep -c '[^[:space:]]' || true)"
