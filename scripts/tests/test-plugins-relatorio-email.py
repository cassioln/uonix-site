#!/usr/bin/env python3
"""Relatório por e-mail da aplicação em produção: leitura das versões finais do
log do job, situação de cada item, texto para o cliente, escape do changelog,
configuração do SMTP (sem SSL só para localhost, senha nunca enviada nesse
caso) e a integração com o `atualizar.sh` (o relatório nunca muda a saída).
Sem rede: o SMTP é falso."""
from __future__ import annotations

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
        {"slug": "woocommerce-products-filter", "seguranca": True, "changelog": ["Security fix <script>alert(1)</script>"]},
        {"slug": "kadence-blocks", "seguranca": True, "changelog": ["* Security: Improved SVG sanitization."]},
        {"slug": "woo-rfq-for-woocommerce", "seguranca": False, "changelog": []},
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
    checar("16/10/2026" in ctx["fora"][0]["motivo"] and "quarentena" not in ctx["fora"][0]["motivo"],
           f"motivo da quarentena sem traduzir: {ctx['fora'][0]['motivo']}")
    checar(ctx["smoke_total"] == 2 and ctx["smoke_falhas"] == ["Store API: produtos: http 500, 0 item(ns)"],
           f"smoke errado: {ctx['smoke_total']} {ctx['smoke_falhas']}")
    checar(rel.assunto(ctx) == "[Uonix] Atualização de plugins em 15/10/2026: Intervenção necessária", f"assunto: {rel.assunto(ctx)}")
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
    checar(all(i["situacao"] == "nao_aplicado" and i["final"] == i["de"] for i in ctx30["itens"]),
           f"saída 30 deveria marcar tudo como não aplicado: {[(i['slug'], i['situacao'], i['final']) for i in ctx30['itens']]}")
    corpo30 = rel.corpo_html(ctx30)
    checar("Itens previstos (nenhum foi aplicado)" in corpo30 and "O que foi feito" not in corpo30,
           "saída 30 não pode dizer 'O que foi feito'")
    checar("Correção de segurança<" not in corpo30 and "Security fix" not in corpo30,
           "saída 30 não pode exibir correção de segurança como aplicada")

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
checar(msg["From"] == "KsioDev <cassio@ksio.dev>" and msg.is_multipart(), f"remetente ou formato errado: {msg['From']}")
checar([p.get_content_type() for p in msg.iter_parts()] == ["text/plain", "text/html"], "deveria ter texto e HTML")
rel.enviar(rel.mensagem(ctx_min, local["de"], local["para"]), local, smtp_ssl=None, smtp_local=SmtpFalso)
s = SmtpFalso.instancias[-1]
checar((s.host, s.porta) == ("localhost", 1025) and s.logins == [], "sem SSL não pode fazer login")

# 9. uma exceção inesperada no envio não pode vazar a senha (ex.: UnicodeEncodeError no login)
import contextlib  # noqa: E402
import io  # noqa: E402

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
