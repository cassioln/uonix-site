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
# predicado governa snapshot e DELETE.

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
protected_sql="$(
  awk '/^protected_options_where\(\) \{/{flag=1; next} /^SQL$/{flag=0} flag' "$CLONE" |
    grep -v "^  cat <<'SQL'$"
)"

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
php_option="$(
  awk '/function uonix_analytics_metrics_snapshot_option\(/{f=1} f{print} f&&/^\t}$/{exit}' "$METRICS" |
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
  # 53-admin-analytics-metrics.php ainda lê v2 e v1, e elas carregam o mesmo dado.
  for legado in uonix_analytics_metrics_snapshot_v1 uonix_analytics_metrics_snapshot_v2_30; do
    case "$legado" in
      "$snapshot_prefix"*) : ;;
      *) report "o padrão protegido não cobre o snapshot legado '$legado', que o fallback em cascata ainda lê." ;;
    esac
  done
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
      *) report "o padrão protegido ('$sitekit_prefix%') não cobre '$opt'." ;;
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

# Exige os DOIS caminhos: restore_options() tem um DELETE para ambiente remoto e
# outro para o local. Aceitar "existe algum" deixaria passar a remoção de um dos
# dois — e aí o clone para aquele destino voltaria a herdar.
delete_paths="$(grep -cE 'DELETE FROM .*\$\{?where' "$CLONE")"
if [ "${delete_paths:-0}" -lt 2 ]; then
  report "esperava 2 DELETE parametrizados pelo predicado em restore_options() (remoto e local), encontrei ${delete_paths:-0}; é o DELETE que impede a herança, e sem ele esta guarda não protege nada."
fi

if [ "$failures" -ne 0 ]; then
  printf 'FALHA: %s problema(s) na proteção de opções não herdáveis.\n' "$failures" >&2
  exit 1
fi

printf 'PASS: snapshot de métricas (prefixo derivado: %s) e Site Kit (%s) protegidos no clone; legados cobertos; 2 DELETE no lugar.\n' \
  "${snapshot_prefix:-?}" "${sitekit_prefix:-?}"
