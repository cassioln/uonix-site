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
    checar("16/10/2026" in ctx["fora"][0]["motivo_cliente"] and "quarentena" not in ctx["fora"][0]["motivo_cliente"],
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
    checar("Pontos com falha" in corpo and "Aguardando o próximo ciclo" in corpo, "HTML sem as seções de falha e de fora")
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
    rel.enviar = original_enviar
    os.environ.clear()
    os.environ.update(original_env)
    checar(rc_ok == 0 and rc_falha == 0 and len(enviados) == 2, f"deveria enviar duas vezes: rc={rc_ok},{rc_falha} n={len(enviados)}")
    if len(enviados) == 2:
        (m_ok, p_ok), (m_tec, p_tec) = enviados
        checar(p_ok == ["marketing@uonix.com.br"] and "[técnico]" not in m_ok["Subject"], f"sucesso: {p_ok} {m_ok['Subject']}")
        corpo_tec = m_tec.get_body(("plain",)).get_content()
        checar(p_tec == ["uonix@ksio.dev"] and m_tec["Subject"].startswith("[ksiodev][técnico]"), f"falha deveria ir ao técnico: {p_tec} {m_tec['Subject']}")
        checar("O CLIENTE NÃO FOI NOTIFICADO" in corpo_tec and "linha-final-do-log" in corpo_tec
               and "estado-producao --backup=plugins-20261015-131200" in corpo_tec,
               "relatório técnico sem aviso, fim do log ou comando de diagnóstico")
        checar("marketing@uonix.com.br" not in m_tec["To"], "falha não pode ir ao cliente")

cfg = rel.configuracao({"RELATORIO_SMTP_SENHA": "s"})
checar(cfg["para_tecnico"] == ["uonix@ksio.dev"], f"técnico padrão errado: {cfg['para_tecnico']}")
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
