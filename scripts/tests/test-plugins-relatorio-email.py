#!/usr/bin/env python3
"""Relatório por e-mail da aplicação em produção: leitura das versões finais do
log do job, situação de cada item, texto para o cliente, escape do changelog,
configuração do SMTP (sem SSL só para localhost, senha nunca enviada nesse
caso) e a integração com o `atualizar.sh` (o relatório nunca muda a saída).
Sem rede: o SMTP é falso."""
from __future__ import annotations

import contextlib
import io
import json
import os
import pathlib
import subprocess
import sys
import tempfile

RAIZ = pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0, str(RAIZ / "scripts" / "plugins"))
import relatorio_email as rel  # noqa: E402

falhas: list[str] = []


def checar(cond: bool, msg: str) -> None:
    if not cond:
        falhas.append(msg)


LOG = """== preflight: versões de origem
  sem drift
== versões finais
  kadence-blocks 9.9.9
  bnfw 1.0
== versões finais
  woocommerce-products-filter 1.4.6
  kadence-blocks 3.7.12.1
  woo-rfq-for-woocommerce
CONCLUÍDO (backup em /x)
  site ok
@@STATUS=0
"""

ITENS = [
    {"slug": "woocommerce-products-filter", "tipo": "plugin", "de": "1.4.5", "para": "1.4.6", "camada": "acoplada", "aplicacao": "lote"},
    {"slug": "kadence-blocks", "tipo": "plugin", "de": "3.7.12.1", "para": "3.8.0", "camada": "critica", "aplicacao": "um_por_vez"},
    {"slug": "woo-rfq-for-woocommerce", "tipo": "plugin", "de": "2.4.16", "para": "2.4.17", "camada": "critica", "aplicacao": "um_por_vez"},
    {"slug": "plugin-sem-nome", "tipo": "plugin", "de": "1.0", "para": "1.1", "camada": "comum", "aplicacao": "lote"},
]


def pasta_fixture(tmp: pathlib.Path, stamp: str) -> pathlib.Path:
    (tmp / "lock.json").write_text(json.dumps({"itens": ITENS, "resultado": "verde", "fora": [
        {"slug": "woocommerce", "de": "11.1.2", "para": "11.2.1", "motivo": "quarentena: lançada há 6 de 7 dias (libera em 2026-10-16)"}]}))
    (tmp / "plano.json").write_text(json.dumps({"itens": [
        {"slug": "woocommerce-products-filter", "seguranca": True, "changelog": ["Security fix <script>alert(1)</script>"],
         "novidades": ["Security fix <script>alert(1)</script>"]},
        {"slug": "kadence-blocks", "seguranca": True, "changelog": ["* Security: Improved SVG sanitization."],
         "novidades": ["* Security: Improved SVG sanitization."]},
        # o "changelog" do plano é o filtro de segurança e risco: nunca vira novidade
        {"slug": "woo-rfq-for-woocommerce", "seguranca": False, "changelog": ["Removed: legacy quote form"], "novidades": None},
        {"slug": "plugin-sem-nome", "seguranca": None, "changelog": "sem as versões do intervalo"}]}))
    (tmp / f"aplicacao-{stamp}.json").write_text(json.dumps(
        {"executado_em": "2026-10-15T13:12:00Z", "backup": stamp, "lock_sha12": "0f93b1dbf12d", "status_job": "0", "smoke_producao": "20"}))
    (tmp / f"aplicar-{stamp}.log").write_text(LOG)
    (tmp / f"smoke-producao-{stamp}.json").write_text(json.dumps(
        {"pagina /": {"ok": True, "detalhe": "http 200"}, "Store API: produtos": {"ok": False, "detalhe": "http 500, 0 item(ns)"}}))
    return tmp


# 1. versões finais: só o último bloco (bnfw só aparece no primeiro), o bloco
# termina na primeira linha sem recuo ("  site ok" depois dele não é plugin) e
# uma linha sem versão conta como presente e vazia
finais = rel.versoes_finais(LOG)
checar(finais == {"woocommerce-products-filter": "1.4.6", "kadence-blocks": "3.7.12.1", "woo-rfq-for-woocommerce": ""},
       f"versões finais erradas: {finais}")
checar(rel.versoes_finais("sem bloco\n") == {}, "log sem o bloco deveria dar vazio")

# 1b. #430: formato novo, uma linha @@VERSAO por item
LOG_NOVO = "== versões finais\n@@VERSAO woocommerce-products-filter 1.4.6\n@@VERSAO kadence-blocks 3.7.12.1\n@@VERSAO woo-rfq-for-woocommerce ?\nCONCLUÍDO\n@@STATUS=0\n"
checar(rel.versoes_finais(LOG_NOVO) == {"woocommerce-products-filter": "1.4.6", "kadence-blocks": "3.7.12.1", "woo-rfq-for-woocommerce": ""},
       f"@@VERSAO errado: {rel.versoes_finais(LOG_NOVO)}")
# Warning do PHP no meio: as linhas marcadas seguem lidas, sem cortar o resto (o formato antigo cortava)
ruido = "== versões finais\n@@VERSAO a 1.0\nWarning: Undefined variable $x\n@@VERSAO b 2.0\n@@STATUS=0\n"
checar(rel.versoes_finais(ruido) == {"a": "1.0", "b": "2.0"}, f"ruído não pode cortar as linhas marcadas: {rel.versoes_finais(ruido)}")
# sem @@STATUS (conexão cortada): nada é confirmado, nem a linha completa
checar(rel.versoes_finais("== versões finais\n@@VERSAO a 1.0\n@@VERSAO b 2.") == {}, "sem @@STATUS nenhum item pode ser confirmado")
# linha cortada no meio da versão com @@STATUS ausente não vira \"mantido\" falso
corte = {"slug": "b", "tipo": "plugin", "de": "2", "para": "2.0.1"}
checar(rel.situacao(corte, rel.versoes_finais("@@VERSAO b 2")) == "sem_confirmacao", "versão cortada sem @@STATUS deveria ser 'sem_confirmacao'")
# versão com lixo é descartada (vira vazia = 'verificar'), nunca igual a de/para
checar(rel.versoes_finais("@@VERSAO a Warning:\n@@STATUS=0\n") == {"a": ""}, "versão inválida deveria virar vazia")
# o bloco antigo só vale quando não há nenhum @@VERSAO (logs já gravados)
checar(rel.versoes_finais("== versões finais\n  a 1.0\n@@STATUS=0\n") == {"a": "1.0"}, "log antigo deveria seguir lido pelo bloco")

# 2. situação de cada item
checar(rel.situacao(ITENS[0], finais) == "atualizado", "versão final = para deveria ser 'atualizado'")
checar(rel.situacao(ITENS[1], finais) == "mantido", "versão final = de deveria ser 'mantido'")
checar(rel.situacao(ITENS[2], finais) == "verificar", "versão final vazia deveria pedir verificação")
checar(rel.situacao(ITENS[3], finais) == "sem_confirmacao", "item ausente do log deveria ser 'sem_confirmacao'")

with tempfile.TemporaryDirectory() as t:
    stamp = "plugins-20261015-131200"
    pasta = pasta_fixture(pathlib.Path(t), stamp)
    aplicacao = json.loads((pasta / f"aplicacao-{stamp}.json").read_text())
    ctx = rel.montar(pasta, aplicacao, "50", LOG)

    # 3. texto para o cliente
    checar(ctx["itens"][0]["nome"] == "HUSKY Products Filter" and ctx["itens"][0]["funcao"] == "Filtros do catálogo",
           f"nome amigável errado: {ctx['itens'][0]}")
    checar(ctx["itens"][3]["nome"] == "plugin-sem-nome", "plugin fora do mapa deveria aparecer pelo slug")
    checar(ctx["itens"][1]["notas"] == ["Security: Improved SVG sanitization."], f"nota sem limpar o marcador: {ctx['itens'][1]['notas']}")
    checar(ctx["itens"][3]["notas"] == [], "changelog que não é lista não deveria virar nota")
    checar("16/10/2026" in ctx["fora"][0]["motivo_cliente"] and "libera" not in ctx["fora"][0]["motivo_cliente"],
           f"motivo da quarentena sem traduzir: {ctx['fora'][0]['motivo_cliente']}")
    checar(ctx["smoke_total"] == 2 and ctx["smoke_falhas"] == ["Store API: produtos: http 500, 0 item(ns)"],
           f"smoke errado: {ctx['smoke_total']} {ctx['smoke_falhas']}")
    checar(rel.assunto(ctx) == "[ksiodev] Relatório de manutenção — Site Uonix (15/10/2026)", f"assunto: {rel.assunto(ctx)}")
    checar(rel.assunto_tecnico(ctx) == "[ksiodev][técnico] Relatório de manutenção — Site Uonix (15/10/2026): Intervenção necessária, saída 50",
           f"assunto técnico: {rel.assunto_tecnico(ctx)}")
    checar(rel.montar(pasta, aplicacao, "99", LOG)["saida"] == "40", "saída desconhecida deveria virar 40")

    # 4. o changelog vem do wordpress.org: nunca entra cru no HTML
    corpo = rel.corpo_html(ctx)
    checar("<script>" not in corpo and "&lt;script&gt;" in corpo, "changelog deveria ser escapado no HTML")
    checar("Pontos com falha" in corpo and ">Quarentena</div>" in corpo and "Aguardando" not in corpo,
           "HTML sem as seções de falha e de quarentena")
    checar("depois de 7 dias do lançamento" in corpo and "sem esperar esse prazo" in corpo,
           "a seção Quarentena deveria explicar o motivo e o prazo")
    checar(ctx["fora"][0]["motivo_cliente"] == "versão lançada recentemente; entra no site a partir de 16/10/2026.",
           f"item da quarentena: {ctx['fora'][0]['motivo_cliente']}")
    checar("Quarentena:" in rel.corpo_texto(ctx) and "sem esperar esse prazo" in rel.corpo_texto(ctx), "texto sem a quarentena")
    checar(rel.motivo_fora("quarentena: lançada há 2 de 14 dias (libera em 2026-11-01)") ==
           "versão lançada recentemente; entra no site a partir de 01/11/2026.", "quarentena com outro prazo")
    # o formato "não verificável" do plano.py depende de conferência manual: só no técnico
    checar(rel.motivo_fora("quarentena de 7 dias não verificável (data de lançamento desconhecida)") is None,
           "quarentena não verificável não pode ir ao cliente")
    checar(rel.dias_quarentena({"fora": [{"motivo": "quarentena: lançada há 2 de 14 dias (libera em 2026-11-01)"}]}) == 14,
           "o prazo deveria vir do motivo")
    checar("1/4" in corpo, "indicador de atualizados deveria contar só os confirmados")
    checar("<script>" in rel.corpo_texto(ctx), "a versão em texto é texto puro e pode trazer o changelog literal")

    # 4b. selo e notas de segurança só com a versão nova instalada (Kadence ficou na anterior)
    checar("Correção de segurança pendente" in corpo and "Improved SVG" not in corpo,
           "item mantido não pode exibir a nota nem o selo de correção aplicada")
    texto = rel.corpo_texto(ctx)
    checar("[correção de segurança pendente]" in texto and "Improved SVG" not in texto, "texto: item mantido com correção aplicada")

    # 4c. saída 30: nada foi aplicado, mesmo que o log tenha um bloco de versões
    ctx30 = rel.montar(pasta, aplicacao, "30", LOG)
    checar(all(i["situacao"] == "nao_aplicado" and i["final"] == "" for i in ctx30["itens"]),
           f"saída 30 deveria marcar tudo como não aplicado: {[(i['slug'], i['situacao'], i['final']) for i in ctx30['itens']]}")
    corpo30 = rel.corpo_html(ctx30)
    checar("Itens previstos (nenhum foi aplicado)" in corpo30 and "O que foi feito" not in corpo30,
           "saída 30 não pode dizer 'O que foi feito'")
    checar("Correção de segurança<" not in corpo30 and "Security fix" not in corpo30,
           "saída 30 não pode exibir correção de segurança como aplicada")
    ctx30["smoke_total"], ctx30["smoke_falhas"] = 0, []
    checar("verificação do site não executada" in rel.corpo_html(ctx30) and "verificações do site ok" not in rel.corpo_html(ctx30),
           "sem smoke, o indicador não pode dizer 'verificações do site ok'")

    # 4c'. estado desconhecido: a correção fica "a confirmar", nunca "pendente"
    ctx40 = rel.montar(pasta, aplicacao, "40", "")
    corpo40 = rel.corpo_html(ctx40)
    checar("Correção de segurança a confirmar" in corpo40 and "pendente" not in corpo40,
           "sem confirmação, a correção não pode ser dada como pendente")
    checar("[correção de segurança a confirmar]" in rel.corpo_texto(ctx40), "texto: correção a confirmar")

    # 4d. saída 0 com item não confirmado: o resumo não afirma "todas"
    checar(rel.resumo(rel.montar(pasta, aplicacao, "0", LOG)) == rel.RESUMO_0_PARCIAL,
           "saída 0 com item não confirmado deveria usar o resumo parcial")
    tudo = rel.montar(pasta, aplicacao, "0", "== versões finais\n" + "".join(f"  {i['slug']} {i['para']}\n" for i in ITENS))
    checar(rel.resumo(tudo) == rel.RESULTADOS["0"][3], "saída 0 com tudo confirmado deveria usar o resumo completo")

    # 5. integração: relatorio --sem-enviar recalcula a saída do registro (0 + smoke 20 = 50)
    env = {k: v for k, v in os.environ.items() if not k.startswith(("LOCAWEB_", "RELATORIO_"))}
    env["UONIX_CHECKOUT_PRINCIPAL"] = t  # sem .env
    script = str(RAIZ / "scripts/plugins/atualizar.sh")
    r = subprocess.run(["bash", script, "relatorio", f"--aplicacao={pasta}/aplicacao-{stamp}.json", "--sem-enviar"],
                       capture_output=True, text=True, env=env)
    previa = pasta / f"relatorio-{stamp}.html"
    checar(r.returncode == 0 and previa.is_file(), f"relatorio --sem-enviar falhou (rc={r.returncode}): {r.stderr}")
    checar(previa.is_file() and "Intervenção necessária" in previa.read_text(), "saída recalculada deveria ser 50")
    sem_status = pasta / "aplicacao-plugins-sem-status.json"
    sem_status.write_text(json.dumps({"backup": "plugins-sem-status", "status_job": "", "smoke_producao": ""}))
    r = subprocess.run(["bash", script, "relatorio", f"--aplicacao={sem_status}", "--sem-enviar"], capture_output=True, text=True, env=env)
    previa = pasta / "relatorio-plugins-sem-status.html"
    checar(r.returncode == 0 and previa.is_file() and "Resultado não confirmado" in previa.read_text(),
           f"registro sem status deveria dar 40, sem 'unbound variable' (rc={r.returncode}): {r.stderr}")

    # 6. enviar_relatorio nunca muda a saída, nem com configuração ausente
    r = subprocess.run(["bash", "-c", f'UONIX_ATUALIZAR_BIBLIOTECA=1 source "$0"; enviar_relatorio {pasta} {stamp} 0; echo "rc=$?"', script],
                       capture_output=True, text=True, env=env)
    checar("rc=0" in r.stdout and "AVISO: relatório não enviado" in r.stderr,
           f"envio sem senha deveria só avisar (stdout={r.stdout!r} stderr={r.stderr!r})")
    r = subprocess.run(["bash", "-c", f'UONIX_ATUALIZAR_BIBLIOTECA=1 source "$0"; UONIX_RELATORIO_EMAIL=0 enviar_relatorio {pasta} {stamp} 0; echo "rc=$?"', script],
                       capture_output=True, text=True, env=env)
    checar("rc=0" in r.stdout and "AVISO" not in r.stderr, f"UONIX_RELATORIO_EMAIL=0 deveria só gravar a prévia: {r.stderr!r}")

# 6a. #431: o relatório lê o lock gravado no registro, não o que estiver ao lado; o tempo do envio é limitado;
# SIGINT durante o envio não troca a saída final
with tempfile.TemporaryDirectory() as t2:
    t2 = pathlib.Path(t2)
    stamp2 = "plugins-20261027-100000"
    outra = t2 / "outra-pasta"; outra.mkdir()
    lock_novo = {"itens": [{"slug": "loco-translate", "tipo": "plugin", "de": "2.8.9", "para": "2.9.0", "camada": "comum", "aplicacao": "lote"}],
                 "resultado": "verde", "fora": [], "sha256": "abcdef012345" + "0" * 52}
    (outra / "lock-copia.json").write_text(json.dumps(lock_novo))
    (t2 / "plano.json").write_text(json.dumps({"itens": []}))
    aplic = {"executado_em": "2026-10-27T10:00:00Z", "backup": stamp2, "lock_sha12": "abcdef012345", "status_job": "0",
             "smoke_producao": "0", "lock": str(outra / "lock-copia.json")}
    (t2 / f"aplicacao-{stamp2}.json").write_text(json.dumps(aplic))
    (t2 / f"smoke-producao-{stamp2}.json").write_text(json.dumps({"pagina /": {"ok": True, "detalhe": "http 200"}}))
    log2 = "@@VERSAO loco-translate 2.9.0\n@@STATUS=0\n"
    c = rel.montar(t2, aplic, "0", log2)
    checar([i["slug"] for i in c["itens"]] == ["loco-translate"] and c["itens"][0]["situacao"] == "atualizado",
           f"o relatório deveria ler o lock do caminho gravado no registro: {c['itens']}")
    # sem o caminho gravado e sem lock.json ao lado: lock vazio, que NUNCA vira \"Concluída\" para o cliente
    sem = dict(aplic); sem.pop("lock")
    c0 = rel.montar(t2, sem, "0", log2)
    checar(c0["itens"] == [] and not rel.para_cliente(c0), "sem lock legível, o relatório não pode ir ao cliente")
    checar(any("não conseguiu ler o plano" in m for m in rel.motivos(c0)), "o motivo técnico deveria dizer que não leu o plano")
    # lock de outro ciclo (sha12 diferente) não vale, mesmo estando no caminho gravado
    outro_ciclo = dict(aplic, lock_sha12="ffffffffffff")
    cx = rel.montar(t2, outro_ciclo, "0", log2)
    checar(cx["itens"] == [], f"lock com sha12 diferente do registro é de outro ciclo e não vale: {cx['itens']}")
    # compatibilidade: registro antigo (sem \"lock\") com lock.json ao lado e mesmo sha12
    (t2 / "lock.json").write_text(json.dumps(lock_novo))
    cc = rel.montar(t2, sem, "0", log2)
    checar([i["slug"] for i in cc["itens"]] == ["loco-translate"], "registro antigo deveria cair no lock.json ao lado")

    # o registro grava o caminho absoluto do lock
    r = subprocess.run(["bash", "-c", f'UONIX_ATUALIZAR_BIBLIOTECA=1 source "$0"; LOCK_APLICADO="/a b/c\\"d.json" registrar_aplicacao {t2} reg-teste abc 0 0 >/dev/null; cat {t2}/aplicacao-reg-teste.json', script],
                       capture_output=True, text=True, env=env)
    try:
        gravado = json.loads(r.stdout)
    except ValueError:
        gravado = {}
    checar(gravado.get("lock") == '/a b/c"d.json' and gravado.get("status_job") == "0", f"registro sem o lock (ou JSON inválido): {r.stdout!r} {r.stderr!r}")

    # tempo total: um python que dorme é morto no limite, a saída final não muda e o AVISO aparece
    falso = t2 / "falso"; falso.mkdir()
    (falso / "relatorio_email.py").write_text("import time; time.sleep(30)\n")
    cmd = (f'UONIX_ATUALIZAR_BIBLIOTECA=1 source "$0"; ROOT_DIR={falso.parent}; mkdir -p {falso.parent}/scripts/plugins; '
           f'cp {falso}/relatorio_email.py {falso.parent}/scripts/plugins/relatorio_email.py; '
           f'UONIX_RELATORIO_LIMITE=2 enviar_relatorio {t2} {stamp2} 0; echo "rc=$?"')
    import time as _t
    inicio = _t.monotonic()
    r = subprocess.run(["bash", "-c", cmd, script], capture_output=True, text=True, env=env, timeout=25)
    gasto = _t.monotonic() - inicio
    checar("rc=0" in r.stdout and "AVISO: relatório não enviado" in r.stderr and gasto < 15,
           f"envio lento deveria ser cortado no limite e só avisar (gasto={gasto:.1f}s, stdout={r.stdout!r}, stderr={r.stderr!r})")

# 6a'. SIGINT durante o envio (Ctrl-C do operador na espera) não troca a saída final por 130: a função
# termina com rc=0 e o AVISO de reenvio. O relatório falso dorme; o SIGINT vai ao grupo do bash.
with tempfile.TemporaryDirectory() as t3:
    t3 = pathlib.Path(t3)
    (t3 / "scripts/plugins").mkdir(parents=True)
    (t3 / "scripts/plugins/relatorio_email.py").write_text("import time; time.sleep(8)\n")
    (t3 / "aplicacao-x.json").write_text("{}")
    cmd = (f'UONIX_ATUALIZAR_BIBLIOTECA=1 source "$0"; ROOT_DIR={t3}; (sleep 1; kill -INT $$) & '
           f'UONIX_RELATORIO_LIMITE=20 enviar_relatorio {t3} x 0; echo "rc=$?"')
    r = subprocess.run(["bash", "-c", cmd, script], capture_output=True, text=True, env=env, timeout=40)
    checar("rc=0" in r.stdout, f"Ctrl-C durante o envio não pode trocar a saída final (stdout={r.stdout!r} rc={r.returncode} stderr={r.stderr!r})")

# 6b. as chamadas do cmd_aplicar_producao: o relatório roda nos três finais
# depois do job, e a gravação do log não pode abortar o script sob set -e
fonte = (RAIZ / "scripts/plugins/atualizar.sh").read_text()
corpo_aplicar = fonte.split("cmd_aplicar_producao() {", 1)[1].split("\n}\n", 1)[0]
checar(corpo_aplicar.count('enviar_relatorio "$dir" "$stamp" 40') == 1
       and corpo_aplicar.count('enviar_relatorio "$dir" "$stamp" 30') == 1
       and corpo_aplicar.count('enviar_relatorio "$dir" "$stamp" "$final"') == 1,
       "cmd_aplicar_producao deveria chamar o relatório nas saídas 40, 30 e final")
gravacao = [l for l in corpo_aplicar.splitlines() if 'aplicar-${stamp}.log"' in l]
checar(len(gravacao) == 1 and gravacao[0].rstrip().endswith("\\"), "a gravação do log deveria continuar na linha seguinte")
indice = corpo_aplicar.splitlines().index(gravacao[0]) if gravacao else -1
checar(indice >= 0 and corpo_aplicar.splitlines()[indice + 1].lstrip().startswith("|| echo"),
       "a gravação do log deveria ter um '|| echo' de proteção contra set -e")
r = subprocess.run(["bash", "-c", 'set -euo pipefail; s=x; printf "%s\\n" "$s" 2>/dev/null > /nao/existe/a.log || echo AVISO >&2; echo depois'],
                   capture_output=True, text=True)
checar(r.returncode == 0 and "depois" in r.stdout, "o padrão de proteção deveria seguir sob set -e")

# 7. configuração
def config_invalida(env: dict, trecho: str) -> None:
    try:
        rel.configuracao(env)
    except rel.ConfiguracaoIncompleta as e:
        checar(trecho in str(e), f"configuração recusada pelo motivo errado: {e} (esperado: {trecho})")
        return
    falhas.append(f"configuração deveria ser recusada ({trecho})")


config_invalida({}, "RELATORIO_SMTP_SENHA")
config_invalida({"RELATORIO_SMTP_SEGURANCA": "nenhuma", "RELATORIO_SMTP_SENHA": "x"}, "só para localhost")
config_invalida({"RELATORIO_SMTP_SEGURANCA": "starttls", "RELATORIO_SMTP_SENHA": "x"}, "'ssl' ou 'nenhuma'")
config_invalida({"RELATORIO_SMTP_SENHA": "x", "RELATORIO_SMTP_PORTA": "abc"}, "não é um número")
cfg = rel.configuracao({"RELATORIO_SMTP_SENHA": "segredo"})
checar((cfg["host"], cfg["porta"], cfg["seguranca"], cfg["de"], cfg["para"]) == ("mail.ksio.dev", 465, "ssl", "cassio@ksio.dev", ["cassio@ksio.dev"]),
       f"padrões errados: {cfg}")
cfg = rel.configuracao({"RELATORIO_SMTP_SENHA": "s", "RELATORIO_PARA": " a@x.com, b@y.com ,"})
checar(cfg["para"] == ["a@x.com", "b@y.com"], f"destinatários errados: {cfg['para']}")
local = rel.configuracao({"RELATORIO_SMTP_HOST": "localhost", "RELATORIO_SMTP_PORTA": "1025",
                          "RELATORIO_SMTP_SEGURANCA": "nenhuma", "RELATORIO_SMTP_SENHA": "nao-pode-sair"})
checar(local["senha"] == "", "sem SSL a senha nunca pode ser carregada")
with tempfile.NamedTemporaryFile("w", delete=False) as f:
    f.write("do-arquivo\n")
checar(rel.configuracao({"RELATORIO_SMTP_SENHA_FILE": f.name})["senha"] == "do-arquivo", "senha do arquivo não foi lida")
os.unlink(f.name)
config_invalida({"RELATORIO_SMTP_SENHA_FILE": "/nao/existe"}, "RELATORIO_SMTP_SENHA_FILE")


# 8. envio com SMTP falso
class SmtpFalso:
    instancias: list["SmtpFalso"] = []

    def __init__(self, host, porta, **kw):
        self.host, self.porta, self.kw, self.logins, self.envios = host, porta, kw, [], []
        SmtpFalso.instancias.append(self)

    def __enter__(self):
        return self

    def __exit__(self, *a):
        return False

    def login(self, u, s):
        self.logins.append((u, s))

    def send_message(self, msg, from_addr, to_addrs):
        self.envios.append((msg, from_addr, to_addrs))


ctx_min = {"saida": "0", "quando": rel.dt.datetime(2026, 10, 15, 10, 12), "backup": "b", "lock": "l",
           "itens": [], "fora": [], "smoke_total": 0, "smoke_falhas": []}
cfg = rel.configuracao({"RELATORIO_SMTP_SENHA": "segredo", "RELATORIO_PARA": "marketing@uonix.com.br"})
rel.enviar(rel.mensagem(ctx_min, cfg["de"], cfg["para"]), cfg, smtp_ssl=SmtpFalso, smtp_local=None)
s = SmtpFalso.instancias[-1]
checar((s.host, s.porta) == ("mail.ksio.dev", 465) and "context" in s.kw, "SSL deveria conectar com contexto TLS")
checar(s.logins == [("cassio@ksio.dev", "segredo")], f"login errado: {s.logins}")
checar(s.envios and s.envios[0][2] == ["marketing@uonix.com.br"], "destinatário errado no envio")
msg = s.envios[0][0]
checar(msg["From"] == "Cassio Lima <cassio@ksio.dev>" and msg.is_multipart(), f"remetente ou formato errado: {msg['From']}")
checar(msg.get_body(("plain",)) is not None and msg.get_body(("html",)) is not None, "deveria ter texto e HTML")
imagens = [p for p in msg.walk() if p.get_content_type() == "image/png"]
checar(len(imagens) == 1 and imagens[0]["Content-ID"] == f"<{rel.LOGO_CID}>" and f"cid:{rel.LOGO_CID}" in msg.get_body(("html",)).get_content(),
       "o logo deveria ir inline (CID) e ser referenciado no HTML")
checar(bool(imagens) and imagens[0].get_content_disposition() == "inline", "o logo não pode chegar como anexo (Gmail mostraria o clipe)")
rel.enviar(rel.mensagem(ctx_min, local["de"], local["para"]), local, smtp_ssl=None, smtp_local=SmtpFalso)
s = SmtpFalso.instancias[-1]
checar((s.host, s.porta) == ("localhost", 1025) and s.logins == [], "sem SSL não pode fazer login")

# 10. marca e assinatura: ksiodev, Cassio Lima, ksio.dev, sem dizer que é automação
html_cli = rel.corpo_html(ctx_min)
txt_cli = rel.corpo_texto(ctx_min)
for trecho in ("Cassio Lima", "ksiodev &middot; Soluções tecnológicas, automação &amp; IA", 'href="https://ksio.dev"',
               "mailto:cassio@ksio.dev", 'ksio<span style="color:#00c9eb;">.dev</span>', f"cid:{rel.LOGO_CID}", "#080d12",
               "Olá, equipe Uonix!", "Atualização de plugins <span"):
    checar(trecho in html_cli, f"HTML do cliente sem: {trecho}")
for trecho in ("Cassio Lima", "ksiodev | Soluções tecnológicas, automação & IA", "https://ksio.dev"):
    checar(trecho in txt_cli, f"texto do cliente sem: {trecho}")
for proibido in ("KsioDev", "automaticamente", "automática", "esteira", "ensaio"):
    checar(proibido not in html_cli and proibido not in txt_cli, f"e-mail do cliente não pode conter: {proibido}")

# 10b. motivos internos do plano nunca chegam ao cliente; ficam no técnico
MOTIVOS_INTERNOS = ["sem classificação na política", "camada decisao_pendente não é atualizada pela esteira",
                    "major na camada critica: exige --aceitar-major=woocommerce",
                    "código não carrega no local (inativo por política); ensaiar à parte"]
with tempfile.TemporaryDirectory() as t:
    stamp = "plugins-20261015-131200"
    pasta = pasta_fixture(pathlib.Path(t), stamp)
    lock_dados = json.loads((pasta / "lock.json").read_text())
    lock_dados["fora"] += [{"slug": f"interno-{n}", "de": "1", "para": "2", "motivo": m} for n, m in enumerate(MOTIVOS_INTERNOS)]
    (pasta / "lock.json").write_text(json.dumps(lock_dados))
    c = rel.montar(pasta, json.loads((pasta / f"aplicacao-{stamp}.json").read_text()), "0", "")
    cliente_html, cliente_txt = rel.corpo_html(c), rel.corpo_texto(c)
    for m in MOTIVOS_INTERNOS:
        checar(m not in cliente_html and m not in cliente_txt, f"motivo interno no e-mail do cliente: {m}")
    checar("interno-" not in cliente_html and "WooCommerce" in cliente_html, "só a quarentena deveria aparecer ao cliente")
    for proibido in ("esteira", "ensaiar", "aceitar-major", "política"):
        checar(proibido not in cliente_html and proibido not in cliente_txt, f"e-mail do cliente com: {proibido}")
    tec = rel.corpo_tecnico(c, "")
    checar(all(m in tec for m in MOTIVOS_INTERNOS), "o técnico deveria trazer todos os motivos internos")

    # o log técnico mascara a query string de URLs (token de licença em pacote pago)
    tec = rel.corpo_tecnico(c, "Baixando pacote de https://exemplo.com/pacote.zip?license=SEGREDO123&x=1\n")
    checar("SEGREDO123" not in tec and "https://exemplo.com/pacote.zip?[omitido]" in tec, "query string do log deveria ser omitida")

    # prévia em arquivo: o logo vai como data URI; sem o PNG, sai sem imagem e sem erro
    checar((rel.logo_data_uri() or "").startswith("data:image/png;base64,"), "prévia deveria embutir o logo")
    original_logo = rel.LOGO
    rel.LOGO = pathlib.Path(t) / "nao-existe.png"
    sem_logo = rel.mensagem(c, "cassio@ksio.dev", ["x@y"])
    rel.LOGO = original_logo
    checar(not [p for p in sem_logo.walk() if p.get_content_type() == "image/png"]
           and "<img" not in sem_logo.get_body(("html",)).get_content(), "sem o PNG, o e-mail sai sem imagem")

# 10b'. sobras do HTML do changelog não viram novidade (WooCommerce 11.2.1, 2026-10-16)
woo = {"novidades": ["WooCommerce", "Fix – Restore how the classic checkout sends order review and coupon requests.",
                     "#69649", "Fix – Accept legacy four-digit Argentine postcodes in the Checkout block again.",
                     "#69662", "See changelog for all versions", ".", "   ", "* 2.0", "View the full changelog here"]}
checar(rel.notas(woo) == ["Fix – Restore how the classic checkout sends order review and coupon requests.",
                          "Fix – Accept legacy four-digit Argentine postcodes in the Checkout block again."],
       f"sobras do changelog deveriam sair: {rel.notas(woo)}")
reais = ["Fixed XSS in widget", "Tweak: faster 2x loading", "Dev – Bump PHP to 8.1", "Tested up to 6.9", "PHP 8.4",
         "Security fix", "Performance improvements", "Corrigido erro no checkout", "修复了小工具的错误",
         "ウィジェットの不具合を修正", "Read the changelog format guide for details on deprecated hooks"]
checar(rel.notas({"novidades": reais}) == reais, f"itens reais não podem sair: {set(reais) - set(rel.notas({'novidades': reais}))}")
sobras = ["Props @user", "Thanks to @john for the report", "Thanks @john!", "Full changelog: https://x/y",
          "Full Changelog", "Release notes", "Release Notes:", "@user", "v2.0.0", "https://exemplo.com/a"]
checar(rel.notas({"novidades": sobras}) == [], f"sobras deveriam sair: {rel.notas({'novidades': sobras})}")

# 10b''. referência a PR e data não são novidade (google-site-kit 1.190.0, kadence 3.8.0.2)
entradas = ["Add the rrmSurveyCTA feature flag. See #13716.", "Fix labels. Props a, b. See #13218, #13219.",
            "Improve errors. See #1 and #2.", "Fix x! See #1.", "Fix x? See #5.", "Fix; see #4", "Loading... done. See #1.",
            "Fix. See #1..", "Fixed #123 in the importer", "Please see #1 for details", "Fix: no longer fails. See the docs.",
            "Release Date: 22nd October 2026", "Release date: 1/1/2026"]
# "Fix; see #4" e "Fix. See #1.." viram uma palavra só sem a referência, e palavra solta sai.
esperadas = ["Add the rrmSurveyCTA feature flag.", "Fix labels. Props a, b.", "Improve errors.", "Fix x!", "Fix x?",
             "Loading... done.", "Fixed #123 in the importer", "Please see #1 for details",
             "Fix: no longer fails. See the docs."]
obtidas = rel.notas({"novidades": entradas})
checar(obtidas == esperadas, f"referência a PR e data: {obtidas}")
# frases reais que começam com "Date"/"Release date" (sem dois-pontos) precisam ficar
reais_data = ["Date picker now supports ranges", "Release date format fixed", "Release dates are now localized",
              "Date: ranges now supported in the picker"]
checar(rel.notas({"novidades": reais_data}) == reais_data, f"frases reais com 'date' não podem sair: {rel.notas({'novidades': reais_data})}")

# 10b'. tema no lock (#445): rótulo "plugins e temas", link de /themes/ sem a âncora #developers,
# nome amigável e o texto de backup com tema-<slug>.tar.gz
with tempfile.TemporaryDirectory() as t:
    stamp = "plugins-20261026-181500"
    pasta = pathlib.Path(t)
    itens_t = [{"slug": "kadence", "tipo": "tema", "de": "1.5.1", "para": "1.5.2", "camada": "critica", "aplicacao": "um_por_vez"},
               {"slug": "fluentform", "tipo": "plugin", "de": "6.2.15", "para": "6.2.16", "camada": "critica", "aplicacao": "um_por_vez"}]
    (pasta / "lock.json").write_text(json.dumps({"itens": itens_t, "resultado": "verde", "fora": []}))
    (pasta / "plano.json").write_text(json.dumps({"itens": [
        {"slug": "kadence", "tipo": "tema", "seguranca": False, "changelog": "sem as versões do intervalo", "novidades": None},
        # plugin homônimo no plano: não pode vazar para o tema
        {"slug": "kadence", "tipo": "plugin", "seguranca": True, "changelog": ["Security: x"], "novidades": ["* Security: x"]},
        {"slug": "fluentform", "seguranca": False, "changelog": ["x"], "novidades": ["* Fix: campo"]}]}))
    (pasta / f"aplicacao-{stamp}.json").write_text(json.dumps(
        {"executado_em": "2026-10-26T18:15:00Z", "backup": stamp, "lock_sha12": "c530cf0c854c", "status_job": "0", "smoke_producao": "0"}))
    (pasta / f"smoke-producao-{stamp}.json").write_text(json.dumps({"pagina /": {"ok": True, "detalhe": "http 200"}}))
    log_t = "== versões finais\n  kadence 1.5.2\n  fluentform 6.2.16\n"
    c = rel.montar(pasta, json.loads((pasta / f"aplicacao-{stamp}.json").read_text()), "0", log_t)
    h, txt = rel.corpo_html(c), rel.corpo_texto(c)
    checar(c["itens"][0]["seguranca"] is False and c["itens"][0]["notas"] == [], f"plano do homônimo vazou para o tema: {c['itens'][0]}")
    checar(c["itens"][0]["nome"] == "Kadence (tema)" and c["itens"][0]["tipo"] == "tema", f"nome do tema errado: {c['itens'][0]}")
    checar(c["itens"][0]["pagina"] == "https://wordpress.org/themes/kadence/", f"página do tema errada: {c['itens'][0]['pagina']}")
    checar('href="https://wordpress.org/themes/kadence/"' in h, "nome do tema sem link para wordpress.org/themes")
    checar("themes/kadence/#developers" not in h, "página de tema não tem a âncora #developers")
    checar("ver na página do tema" in h, "tema sem notas deveria apontar para a página do tema")
    checar("Atualização de plugins e temas" in h and "plugins e temas atualizados" in h and "atualização de plugins e temas" in txt,
           "rótulo deveria ser 'plugins e temas'")
    checar(rel.rotulo_itens(c) == "plugins e temas", "rotulo_itens errado")
    checar(rel.para_cliente(c), "sucesso completo com tema deveria ir ao cliente")
    c1 = rel.montar(pasta, json.loads((pasta / f"aplicacao-{stamp}.json").read_text()), "0", log_t)
    c1["itens"] = [i for i in c1["itens"] if i["tipo"] == "tema"]
    checar(rel.rotulo_itens(c1) == "temas", "só tema deveria dizer 'temas'")
    c1["itens"] = [i for i in c1["itens"] if i["tipo"] != "tema"] or [{"tipo": "plugin"}]
    checar(rel.rotulo_itens(c1) == "plugins", "só plugin deveria seguir dizendo 'plugins'")

# 10b''. núcleo (#448): nome, rótulo, link de versões sem âncora, mesmo sem a API
with tempfile.TemporaryDirectory() as t:
    stamp = "plugins-20261026-190000"
    pasta = pathlib.Path(t)
    (pasta / "lock.json").write_text(json.dumps({"itens": [
        {"slug": "wordpress", "tipo": "nucleo", "de": "7.1.3", "para": "7.1.4", "camada": "nucleo", "aplicacao": "um_por_vez"}],
        "resultado": "verde", "fora": []}))
    (pasta / "plano.json").write_text(json.dumps({"itens": [
        {"slug": "wordpress", "tipo": "nucleo", "seguranca": None, "changelog": "indisponível", "novidades": None}]}))
    (pasta / f"aplicacao-{stamp}.json").write_text(json.dumps(
        {"executado_em": "2026-10-26T19:00:00Z", "backup": stamp, "lock_sha12": "abc", "status_job": "0", "smoke_producao": "0"}))
    (pasta / f"smoke-producao-{stamp}.json").write_text(json.dumps({"pagina /": {"ok": True, "detalhe": "http 200"}}))
    c = rel.montar(pasta, json.loads((pasta / f"aplicacao-{stamp}.json").read_text()), "0", "== versões finais\n  wordpress 7.1.4\n")
    h, txt = rel.corpo_html(c), rel.corpo_texto(c)
    checar(c["itens"][0]["nome"] == "WordPress" and c["itens"][0]["funcao"] == "Núcleo do site", f"nome do núcleo errado: {c['itens'][0]}")
    checar(c["itens"][0]["pagina"] == "https://wordpress.org/news/category/releases/", f"página do núcleo errada: {c['itens'][0]['pagina']}")
    checar("Atualização de núcleo do WordPress" in h and "núcleo do WordPress atualizados" in h, "rótulo do núcleo errado")
    checar("releases/#developers" not in h and "página de versões do WordPress" in h, "link do núcleo sem âncora e com o texto certo")
    checar(rel.para_cliente(c), "sucesso completo do núcleo deveria ir ao cliente")
    for tipos, esperado in ((["plugin", "nucleo"], "plugins e núcleo do WordPress"), (["plugin", "tema", "nucleo"], "plugins, temas e núcleo do WordPress"),
                            (["tema", "nucleo"], "temas e núcleo do WordPress"), ([], "plugins")):
        got = rel.rotulo_itens({"itens": [{"tipo": t} for t in tipos]})
        checar(got == esperado, f"rotulo_itens({tipos}) = {got!r}, esperado {esperado!r}")


# 10c. nome com link para a página oficial (só se a API do wordpress.org respondeu) e changelog da versão
with tempfile.TemporaryDirectory() as t:
    stamp = "plugins-20261015-131200"
    pasta = pasta_fixture(pathlib.Path(t), stamp)
    plano_dados = json.loads((pasta / "plano.json").read_text())
    plano_dados["itens"][0]["novidades"] = [f"* Mudança {n}" for n in range(11)]
    plano_dados["itens"][3]["changelog"] = "indisponível"  # plugin pago: sem página no wordpress.org
    (pasta / "plano.json").write_text(json.dumps(plano_dados))
    log_ok = "== versões finais\n" + "".join(f"  {i['slug']} {i['para']}\n" for i in ITENS)
    c = rel.montar(pasta, json.loads((pasta / f"aplicacao-{stamp}.json").read_text()), "0", log_ok)
    h, txt = rel.corpo_html(c), rel.corpo_texto(c)
    checar('href="https://wordpress.org/plugins/woocommerce-products-filter/"' in h, "nome do plugin sem link para o wordpress.org")
    checar("wordpress.org/plugins/plugin-sem-nome" not in h, "plugin sem página no wordpress.org não pode ter link")
    checar("Mudança 7" in h and "Mudança 8" not in h and "e mais 3 alteração(ões): ver o changelog completo" in h,
           "changelog deveria mostrar 8 itens e o link para o resto")
    checar('wordpress.org/plugins/woo-rfq-for-woocommerce/#developers' in h and "ver na página do plugin" in h,
           "sem trecho do changelog, deveria apontar para a página do plugin")
    checar("Novidades da versão 3.8.0" in h and "Improved SVG sanitization." in h, "Kadence sem as novidades da versão")
    checar("https://wordpress.org/plugins/woocommerce-products-filter/" in txt and "    - Mudança 0" in txt
           and "e mais 3 alteração(ões)" in txt, "texto sem link ou sem changelog")
    checar(rel.pagina_wporg("a b/../c") == "https://wordpress.org/plugins/a%20b/../c/" , "slug deveria ser codificado na URL")
    checar("Removed: legacy quote form" not in h and "Removed: legacy quote form" not in txt,
           "o filtro de segurança e risco do plano não pode aparecer como novidade")
    checar(rel.pagina_wporg("kadence", "tema") == "https://wordpress.org/themes/kadence/", "tema deveria apontar para /themes/")

# 11. roteamento: o cliente só recebe sucesso completo; o resto vai para o técnico
with tempfile.TemporaryDirectory() as t:
    stamp = "plugins-20261015-131200"
    pasta = pasta_fixture(pathlib.Path(t), stamp)
    (pasta / f"smoke-producao-{stamp}.json").write_text(json.dumps({"pagina /": {"ok": True, "detalhe": "http 200"}}))
    aplicacao = json.loads((pasta / f"aplicacao-{stamp}.json").read_text())
    log_ok = "== versões finais\n" + "".join(f"  {i['slug']} {i['para']}\n" for i in ITENS) + "CONCLUÍDO\n@@STATUS=0\n"
    checar(rel.para_cliente(rel.montar(pasta, aplicacao, "0", log_ok)), "sucesso completo deveria ir para o cliente")
    for saida in ("20", "30", "40", "50", "99"):
        c = rel.montar(pasta, aplicacao, saida, log_ok)
        checar(not rel.para_cliente(c) and any(m.startswith(f"saída {c['saida']}") for m in rel.motivos(c)),
               f"saída {saida} não pode ir para o cliente e precisa do motivo")
    parcial = rel.montar(pasta, aplicacao, "0", LOG)
    checar(not rel.para_cliente(parcial) and any(m.startswith("kadence-blocks:") for m in rel.motivos(parcial)),
           "saída 0 com item não atualizado não pode ir para o cliente")
    (pasta / f"smoke-producao-{stamp}.json").write_text(json.dumps({"pagina /": {"ok": False, "detalhe": "http 500"}}))
    checar(not rel.para_cliente(rel.montar(pasta, aplicacao, "0", log_ok)), "smoke vermelho não pode ir para o cliente")
    (pasta / f"smoke-producao-{stamp}.json").unlink()
    sem_smoke = rel.montar(pasta, aplicacao, "0", log_ok)
    checar(not rel.para_cliente(sem_smoke) and any("smoke" in m for m in rel.motivos(sem_smoke)),
           "sem smoke não pode ir para o cliente")
    (pasta / f"smoke-producao-{stamp}.json").write_text(json.dumps({"pagina /": {"ok": True, "detalhe": "http 200"}}))
    (pasta / "lock.json").write_text(json.dumps({"itens": [], "fora": []}))
    checar(not rel.para_cliente(rel.montar(pasta, aplicacao, "0", log_ok)), "lock vazio não pode ir para o cliente")
    pasta_fixture(pathlib.Path(t), stamp)
    (pasta / f"smoke-producao-{stamp}.json").write_text(json.dumps({"pagina /": {"ok": True, "detalhe": "http 200"}}))
    (pasta / f"aplicar-{stamp}.log").write_text(log_ok)

    # o main escolhe destino e mensagem
    enviados: list = []
    original_enviar, original_env = rel.enviar, dict(os.environ)
    rel.enviar = lambda msg, cfg, para=None, **kw: enviados.append((msg, para))
    os.environ.update({"RELATORIO_SMTP_SENHA": "s", "RELATORIO_PARA": "marketing@uonix.com.br"})
    os.environ.pop("RELATORIO_PARA_TECNICO", None)
    args = ["--dir", t, "--aplicacao", f"{t}/aplicacao-{stamp}.json", "--log", f"{t}/aplicar-{stamp}.log", "--enviar"]
    with contextlib.redirect_stdout(io.StringIO()):
        rc_ok = rel.main(args + ["--saida-final", "0"])
        (pasta / f"aplicar-{stamp}.log").write_text(LOG + "linha-final-do-log\n")
        rc_falha = rel.main(args + ["--saida-final", "20"])
        (pasta / f"aplicar-{stamp}.log").write_text(log_ok)
        os.environ.update({"RELATORIO_PARA": "Marketing@Uonix.com.br", "RELATORIO_CCO": "marketing@uonix.com.br"})
        rel.main(args + ["--saida-final", "0"])
    rel.enviar = original_enviar
    os.environ.clear()
    os.environ.update(original_env)
    checar(rc_ok == 0 and rc_falha == 0 and len(enviados) == 3, f"deveria enviar três vezes: rc={rc_ok},{rc_falha} n={len(enviados)}")
    if len(enviados) == 3:
        (m_ok, p_ok), (m_tec, p_tec), (_, p_dup) = enviados
        checar(p_dup == ["Marketing@Uonix.com.br"], f"cópia oculta igual ao cliente (outra caixa) não pode duplicar: {p_dup}")
        checar(p_ok == ["marketing@uonix.com.br", "uonix@ksio.dev"] and "[técnico]" not in m_ok["Subject"],
               f"sucesso: deveria ir ao cliente com cópia oculta: {p_ok} {m_ok['Subject']}")
        checar(m_ok["To"] == "marketing@uonix.com.br" and m_ok["Bcc"] is None and "uonix@ksio.dev" not in m_ok.as_string(),
               "a cópia oculta não pode aparecer no cabeçalho nem no corpo do e-mail do cliente")
        corpo_tec = m_tec.get_body(("plain",)).get_content()
        checar(p_tec == ["uonix@ksio.dev"] and m_tec["Subject"].startswith("[ksiodev][técnico]"), f"falha deveria ir ao técnico: {p_tec} {m_tec['Subject']}")
        checar("O CLIENTE NÃO FOI NOTIFICADO" in corpo_tec and "linha-final-do-log" in corpo_tec
               and "estado-producao --backup=plugins-20261015-131200" in corpo_tec,
               "relatório técnico sem aviso, fim do log ou comando de diagnóstico")
        checar("marketing@uonix.com.br" not in m_tec["To"], "falha não pode ir ao cliente")

cfg = rel.configuracao({"RELATORIO_SMTP_SENHA": "s"})
checar(cfg["para_tecnico"] == ["uonix@ksio.dev"], f"técnico padrão errado: {cfg['para_tecnico']}")
checar(cfg["cco"] == ["uonix@ksio.dev"], f"cópia oculta padrão errada: {cfg['cco']}")
checar(rel.configuracao({"RELATORIO_SMTP_SENHA": "s", "RELATORIO_CCO": ""})["cco"] == [], "RELATORIO_CCO vazio desliga a cópia")
checar(rel.configuracao({"RELATORIO_SMTP_SENHA": "s", "RELATORIO_CCO": "a@x, A@X ,b@y"})["cco"] == ["a@x", "b@y"],
       "a cópia oculta deveria eliminar duplicatas sem diferenciar maiúsculas")
checar(rel.configuracao({"RELATORIO_SMTP_SENHA": "s", "RELATORIO_PARA_TECNICO": "a@x, b@y"})["para_tecnico"] == ["a@x", "b@y"],
       "RELATORIO_PARA_TECNICO deveria aceitar vários")

# 9. uma exceção inesperada no envio não pode vazar a senha (ex.: UnicodeEncodeError no login)

with tempfile.TemporaryDirectory() as t:
    stamp = "plugins-20261015-131200"
    pasta = pasta_fixture(pathlib.Path(t), stamp)
    original_enviar, original_env = rel.enviar, dict(os.environ)

    def enviar_que_explode(msg, cfg, **kw):
        raise UnicodeEncodeError("ascii", cfg["senha"], 0, 1, "ordinal not in range")

    rel.enviar = enviar_que_explode
    os.environ.update({"RELATORIO_SMTP_SENHA": "çsenha-secreta"})
    erro = io.StringIO()
    with contextlib.redirect_stderr(erro), contextlib.redirect_stdout(io.StringIO()):
        rc = rel.main(["--dir", t, "--aplicacao", f"{t}/aplicacao-{stamp}.json", "--saida-final", "0", "--enviar"])
    rel.enviar = original_enviar
    os.environ.clear()
    os.environ.update(original_env)
    # str(UnicodeEncodeError) cita o caractere e a posição, não a senha inteira
    checar(rc == 1 and "xe7" not in erro.getvalue() and "ç" not in erro.getvalue() and "UnicodeEncodeError" in erro.getvalue(),
           f"exceção inesperada deveria sair 1 sem citar a senha: rc={rc} {erro.getvalue()!r}")

if falhas:
    print("FAIL:")
    for f in falhas:
        print(f"  - {f}")
    sys.exit(1)
print("OK: relatório por e-mail da aplicação em produção")
