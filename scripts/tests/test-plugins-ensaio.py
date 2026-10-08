#!/usr/bin/env python3
"""Ensaio local de plugins (#393, entrega 2): plano, comparação de smoke e o
fluxo de executar() com um ambiente local falso. Sem rede e sem Podman."""
from __future__ import annotations

import contextlib
import datetime as dt
import io
import json
import pathlib
import sys
import tempfile

RAIZ = pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0, str(RAIZ / "scripts" / "plugins"))
import ensaio  # noqa: E402
import plano  # noqa: E402

POLITICA = json.loads((RAIZ / "ops/plugins/politica.json").read_text())
HOJE = dt.date(2026, 10, 7)
falhas: list[str] = []


def checar(cond: bool, msg: str) -> None:
    if not cond:
        falhas.append(msg)


def item(nome, de, para, status="active", update="available"):
    return {"name": nome, "status": status, "version": de, "update": update, "update_version": para, "auto_update": "off"}


def api(versao, lancado, changelog=""):
    return {"version": versao, "last_updated": f"{lancado} 2:40pm GMT", "sections": {"changelog": changelog}}


CL_SEG = "<h4>3.7.12.1</h4><ul><li>Security: Improved output escaping.</li></ul><h4>3.7.12</h4><ul><li>Fix: x</li></ul>"
CL_NORMAL = "<h4>11.2.0</h4><ul><li>Fix: carrinho</li></ul><h4>11.1.2</h4><ul><li>Fix: y</li></ul>"

# --------------------------------------------------------------------------- #
# 1. plano.py
# --------------------------------------------------------------------------- #
inv = {"plugins": [
    item("kadence-blocks", "3.7.12", "3.7.12.1"),        # crítica, lançada ontem, segurança -> entra
    item("woocommerce", "11.1.2", "11.2.0"),             # crítica, lançada hoje, sem segurança -> quarentena
    item("seo-by-rank-math", "1.0.279", "1.0.280"),      # crítica, sem API -> não verificável
    item("megamenu", "3.10.8", "4.0.0"),                 # crítica, major -> bloqueia
    item("fluentform", "6.2.15", "6.2.16"),              # crítica, lançada há 10 dias -> cumprida
    item("google-site-kit", "1.188.0", "1.189.0"),       # acoplada -> entra sem quarentena
    item("loco-translate", "2.8.9", "2.9.0"),            # comum -> entra
    item("wordpress-importer", "0.9.6", "0.9.7"),        # decisao_pendente -> fora
    item("user-role-editor", "4.66.2", "4.67", update="unavailable"),  # requisito -> fora
    item("plugin-novo", "1.0", "1.1"),                   # sem classificação -> fora
    item("bnfw", "1.9.9.2", "", update="none"),          # sem pendência -> ignorado
], "temas": [item("kadence-child", "1.4", "1.5")]}       # propria -> fora
cache = {
    "kadence-blocks": api("3.7.12.1", "2026-10-06", CL_SEG),
    "woocommerce": api("11.2.0", "2026-10-07", CL_NORMAL),
    "seo-by-rank-math": None,
    "megamenu": api("4.0.0", "2026-09-01"),
    "fluentform": api("6.2.16", "2026-09-27"),
    "google-site-kit": api("1.189.0", "2026-10-07"),
    "loco-translate": api("2.9.0", "2026-10-07"),
}
pl = plano.planejar(inv, POLITICA, cache, HOJE, set(), set())
entram = [i["slug"] for i in pl["itens"]]
fora = {f["slug"]: f["motivo"] for f in pl["fora"]}
checar(entram == ["google-site-kit", "loco-translate", "fluentform", "kadence-blocks"],
       f"ordem/seleção do plano inesperada: {entram}")
checar("quarentena: lançada há 0 de 7" in fora.get("woocommerce", ""), f"woocommerce deveria cumprir quarentena: {fora.get('woocommerce')}")
checar("não verificável" in fora.get("seo-by-rank-math", ""), "sem API, a quarentena deveria ser não verificável")
checar("--aceitar-major=megamenu" in fora.get("megamenu", ""), "major na crítica deveria bloquear")
checar("não é atualizada" in fora.get("wordpress-importer", ""), "decisao_pendente deveria ficar fora")
checar("não é atualizada" in fora.get("kadence-child", ""), "camada propria deveria ficar fora")
checar("exige PHP" in fora.get("user-role-editor", ""), "unavailable deveria ficar fora")
checar("sem classificação" in fora.get("plugin-novo", ""), "plugin sem classificação deveria ficar fora")
checar("bnfw" not in entram and "bnfw" not in fora, "plugin sem pendência não deveria aparecer")
kb = next(i for i in pl["itens"] if i["slug"] == "kadence-blocks")
checar(kb["seguranca"] is True and kb["quarentena"] == "dispensada (segurança)", f"segurança deveria dispensar a quarentena: {kb}")
ff = next(i for i in pl["itens"] if i["slug"] == "fluentform")
checar(ff["quarentena"] == "cumprida", "fluentform lançado há 10 dias deveria ter quarentena cumprida")

pl2 = plano.planejar(inv, POLITICA, cache, HOJE, {"megamenu"}, {"woocommerce", "seo-by-rank-math"})
entram2 = [i["slug"] for i in pl2["itens"]]
checar("megamenu" in entram2 and entram2[-1] == "woocommerce",
       f"--aceitar-major e --dispensar-quarentena deveriam liberar; woocommerce por último: {entram2}")

checar(plano.trecho_changelog(api("2", "x", "<p>sem versões</p>"), "1", "2") is None,
       "changelog sem as versões do intervalo deveria dar None, não lista vazia")
checar(plano.data_lancamento(api("11.2.1", "2026-10-07"), "11.2.0") is None,
       "a data da API só vale quando a versão da API é a versão-alvo")

# --------------------------------------------------------------------------- #
# 2. comparar()
# --------------------------------------------------------------------------- #
base = {"a": {"ok": True, "detalhe": ""}, "b": {"ok": False, "detalhe": "já ruim"}}
reg, pre = ensaio.comparar(base, {"a": {"ok": False, "detalhe": "quebrou"}, "b": {"ok": False, "detalhe": "já ruim"},
                                  "c": {"ok": False, "detalhe": "novo"}})
checar(reg == ["a: quebrou", "c: novo"] and pre == ["b: já ruim"], f"comparar() errado: {reg} {pre}")
reg, _ = ensaio.comparar({"banco do WooCommerce migrado": {"ok": True, "detalhe": ""}}, {})
checar(reg == ["banco do WooCommerce migrado: check sumiu da execução atual"], f"check sumido não virou regressão: {reg}")

# M3: falsos positivos de segurança e cabeçalhos que não fecham a seção.
def cl(t):
    return {"version": "1.2.5", "last_updated": "2026-10-01", "sections": {"changelog": t}}
casos_cl = {
    "v.1.2.3 fecha a seção": ("<p>v.1.2.5</p><p>Fix a</p><p>v.1.2.3</p><p>CSRF old</p>", False),
    "Release fecha a seção": ("<p>Release 1.2.5</p><p>Fix a</p><p>Release 1.2.3</p><p>CSRF old</p>", False),
    "cabeçalho longo fecha": ("<h4>= 1.2.5 - 2026-10-01 - uma descrição bem longa do lançamento aqui =</h4><p>Fix</p>"
                              "<h4>= 1.2.3 - 2026-01-01 - outra descrição bem longa do lançamento aqui =</h4><p>xss old</p>", False),
    "Version: com dois-pontos": ("<p>Version: 1.2.5</p><p>Security: fix</p><p>Version: 1.2.3</p>", True),
    "data solta não fecha": ("<p>[1.2.5] 25.09.2026</p><p>25.09.2026</p><p>Security fix</p><p>[1.2.3]</p>", True),
    "palavras ambíguas": ("<p>= 1.2.5 =</p><p>Escape key closes modal</p><p>Secure cookie option added</p>"
                          "<p>Sanitize filename</p><p>= 1.2.3 =</p>", False),
}
for nome, (texto, esperado) in casos_cl.items():
    trecho = plano.trecho_changelog(cl(texto), "1.2.3", "1.2.5")
    seg = None if trecho is None else any(plano.SEGURANCA.search(l) for l in trecho)
    checar(seg == esperado, f"changelog [{nome}]: segurança={seg}, esperado {esperado} ({trecho})")
for real in ("* Security: Improved output escaping for block attributes.",
             "Improved plugin security with additional input validation and output escaping hardening.",
             "Hardens input sanitization and permission checks across field settings, blocks, and entries",
             "Fixed unescaped HTML loophole in the file path placeholder UI", "Security fix: Thanks to crow and Wordfence."):
    checar(bool(plano.SEGURANCA.search(real)), f"correção de segurança real não reconhecida: {real}")

# #418: a palavra solta não basta; exige contexto de correção.
for falso in ("Compatibility with Wordfence Security 8.0", "Compatibility with Solid Security and All In One WP Security",
              "Added Security headers settings page", "Fixed unauthorized error message text",
              "New permission checks screen", "Escape key closes the modal", "Secure cookie option added",
              "Hardened Mode toggle added", "Tweak - Hardening guide link", "Added hardening options page",
              "Added unauthenticated access option for public forms", "New: unauthenticated form view setting"):
    checar(not plano.SEGURANCA.search(falso), f"falso positivo de segurança (dispensaria a quarentena): {falso}")
for verdadeiro in ("Security Fix: administrator-role protection only excluded the administrator role",
                   "Fixed a Broken Access Control vulnerability in the REST endpoint",
                   "Added missing authorization check on the export action", "Fixed missing capability check",
                   "Fixed a Cross-Site Scripting issue in the widget", "Patched CVE-2026-12345",
                   "Fixed PHP Object Injection in the importer", "Fixed arbitrary file upload in the form",
                   "Fixed an SSRF in the URL preview", "Fixed an issue that allowed unauthenticated users to read entries",
                   "Hardened nonce verification", "Security update for the shortcode handler",
                   "Hardens input sanitization and permission checks across field settings",
                   "Fixed an issue that allowed unauthenticated REST requests to export entries",
                   "Unauthenticated attackers could read private posts"):
    checar(bool(plano.SEGURANCA.search(verdadeiro)), f"correção de segurança não reconhecida: {verdadeiro}")

# #418: cabeçalhos falsos não abrem nem fecham seção.
for nome, texto, esperado in (
    ("1.5x não é versão", "<p>= 1.2.5 =</p><p>* 1.5x faster loading</p><p>Security: fix</p><p>= 1.2.3 =</p>", True),
    ("data dd.mm.aaaa não é versão", "<p>= 1.2.5 =</p><p>25.09.2026 - Fixed XSS in widget</p><p>= 1.2.3 =</p>", True),
):
    trecho = plano.trecho_changelog(cl(texto), "1.2.3", "1.2.5")
    seg = None if trecho is None else any(plano.SEGURANCA.search(l) for l in trecho)
    checar(seg == esperado, f"changelog [{nome}]: segurança={seg}, esperado {esperado} ({trecho})")


# --------------------------------------------------------------------------- #
# 3. executar() com ambiente falso
# --------------------------------------------------------------------------- #
class FakeLocal:
    def __init__(self, quebra_em=None, falha_backup=False):
        self.instalados = {"kadence-blocks": "3.7.12", "google-site-kit": "1.188.0", "fluent-smtp": "2.4.1"}
        self.ativos = {"kadence-blocks", "google-site-kit"}
        self.temas = {"kadence": "1.5.2", "kadence-child": "0.0-local"}
        self.chamadas: list[tuple] = []
        self.quebra_em, self.falha_backup = quebra_em, falha_backup

    def preparar_wpcli(self):
        return "WP-CLI fake"

    def wp(self, *args, verificar=True, timeout=0):
        self.chamadas.append(args)
        if args[:2] == ("plugin", "list"):
            return json.dumps([{"name": n, "version": v, "status": "active" if n in self.ativos else "inactive"}
                               for n, v in self.instalados.items()])
        if args[:2] == ("theme", "list"):
            return json.dumps([{"name": n, "version": v, "status": "active"} for n, v in self.temas.items()])
        if args[1] in {"install", "update"}:
            versao = next(a.split("=", 1)[1] for a in args if a.startswith("--version="))
            (self.temas if args[0] == "theme" else self.instalados)[args[2]] = versao
        elif args[:2] == ("plugin", "activate"):
            self.ativos.add(args[2])
        elif args[:2] == ("plugin", "deactivate"):
            self.ativos.discard(args[2])
        elif args[0] == "eval":
            return "8.5.11"
        return ""

    def backup_banco(self, destino):
        if self.falha_backup:
            raise ensaio.Falha("backup inválido: 3 de 166 tabelas")
        destino.write_text("dump")
        return 166

    def eval_json(self, php):
        return {"paginas": [], "produto": None}


def smoke_fake(local, alvo, contratos):
    local.smokes = getattr(local, "smokes", 0) + 1
    if getattr(local, "explode_no_smoke", None) == local.smokes:
        raise RuntimeError("wp eval falhou (exit 255): PHP Fatal")
    quebrado = local.quebra_em and local.instalados.get(local.quebra_em[0]) == local.quebra_em[1]
    return {"home megamenu": {"ok": not quebrado, "detalhe": "0" if quebrado else "66"},
            "pagina /x": {"ok": False, "detalhe": "http 404"}}  # pré-existente: não pode reprovar


ensaio.smoke = smoke_fake
_executar = ensaio.executar


def executar(*args, **kw):
    with contextlib.redirect_stdout(io.StringIO()):
        return _executar(*args, **kw)


ensaio.alvos = lambda local: {"paginas": [], "produto": None}
inv_prod = {"plugins": [{"name": "kadence-blocks", "version": "3.7.12", "status": "active"},
                        {"name": "google-site-kit", "version": "1.188.0", "status": "active"},
                        {"name": "fluent-smtp", "version": "2.4.1", "status": "active"}],
            "temas": [{"name": "kadence", "version": "1.5.2", "status": "parent"},
                      {"name": "kadence-child", "version": "1.4.3", "status": "active"}]}
plano_exec = {"itens": [
    {"slug": "google-site-kit", "tipo": "plugin", "de": "1.188.0", "para": "1.189.0", "camada": "acoplada", "aplicacao": "lote"},
    {"slug": "kadence-blocks", "tipo": "plugin", "de": "3.7.12", "para": "3.7.12.1", "camada": "critica", "aplicacao": "um_por_vez"},
], "fora": [{"slug": "woocommerce", "de": "11.1.2", "para": "11.2.0", "motivo": "quarentena"}]}
contratos_ok = lambda slugs: (True, "ok")  # noqa: E731

with tempfile.TemporaryDirectory() as tmp:
    saida = pathlib.Path(tmp)

    # 3a. verde: lock com as versões exatas; fluent-smtp segue inativo; tema próprio intocado;
    # o clone é invalidado antes da primeira atualização (A1).
    loc = FakeLocal()
    invalidar = lambda: loc.chamadas.append(("INVALIDAR-CLONE",))  # noqa: E731
    r = executar(loc, plano_exec, inv_prod, {"kadence-blocks": {}}, contratos_ok, saida, None, {"kadence-child"}, invalidar)
    pos_inv = loc.chamadas.index(("INVALIDAR-CLONE",)) if ("INVALIDAR-CLONE",) in loc.chamadas else 10**6
    pos_upd = next((n for n, c in enumerate(loc.chamadas) if len(c) > 1 and c[1] == "update"), -1)
    checar(0 <= pos_inv < pos_upd, f"clone deveria ser invalidado antes da 1ª atualização ({pos_inv} vs {pos_upd})")
    lock = json.loads((saida / "lock.json").read_text()) if (saida / "lock.json").exists() else {}
    checar(r == 0, f"caminho verde saiu {r}")
    checar([(i["slug"], i["para"]) for i in lock.get("itens", [])] == [("google-site-kit", "1.189.0"), ("kadence-blocks", "3.7.12.1")],
           f"lock sem as versões ensaiadas: {lock.get('itens')}")
    checar(lock.get("sha256") and len(lock["sha256"]) == 64, "lock sem sha256")
    checar("fluent-smtp" not in loc.ativos, "fluent-smtp não pode ser ativado no local (Mailpit)")
    checar(not any(c[:3] == ("theme", "install", "kadence-child") for c in loc.chamadas), "tema próprio não pode ser instalado do wordpress.org")
    checar(("plugin", "update", "kadence-blocks", "--version=3.7.12.1") in loc.chamadas, "update deveria usar --version exata")

    # 3b. regressão no crítico: para, saída 20, sem lock.
    (saida / "lock.json").unlink()
    loc = FakeLocal(quebra_em=("kadence-blocks", "3.7.12.1"))
    r = executar(loc, plano_exec, inv_prod, {}, contratos_ok, saida, None, {"kadence-child"})
    checar(r == 20 and not (saida / "lock.json").exists(), f"regressão deveria sair 20 sem lock (saiu {r})")
    checar("REGRESSÃO home megamenu" in (saida / "relatorio.md").read_text(), "relatório sem a regressão")

    # 3c. contrato quebrado: saída 20 antes do smoke.
    loc = FakeLocal()
    r = executar(loc, plano_exec, inv_prod, {"kadence-blocks": {}},
                        lambda s: (False, "FALHA kadence-blocks: marcadores ausentes"), saida, None, {"kadence-child"})
    checar(r == 20 and "contrato quebrado em kadence-blocks" in (saida / "relatorio.md").read_text(),
           f"contrato quebrado deveria sair 20 (saiu {r})")

    # 3d. backup inválido: saída 30, nada atualizado, clone segue reutilizável.
    loc = FakeLocal(falha_backup=True)
    r = executar(loc, plano_exec, inv_prod, {}, contratos_ok, saida, None, {"kadence-child"},
                 lambda: loc.chamadas.append(("INVALIDAR-CLONE",)))
    checar(("INVALIDAR-CLONE",) not in loc.chamadas, "falha de preparação não deveria invalidar o clone")
    checar(r == 30 and not any(c[1] == "update" for c in loc.chamadas if len(c) > 1),
           f"backup inválido deveria sair 30 sem atualizar nada (saiu {r})")

    # 3e. plano vazio: saída 0 sem tocar no local.
    loc = FakeLocal()
    r = executar(loc, {"itens": [], "fora": plano_exec["fora"]}, inv_prod, {}, contratos_ok, saida)
    checar(r == 0 and not loc.chamadas, "plano vazio não deveria tocar no local")

    # 3f. alinhamento que não converge: saída 30.
    class Teimoso(FakeLocal):
        def wp(self, *args, **kw):
            if len(args) > 1 and args[1] == "install":
                self.chamadas.append(args)
                return ""
            return super().wp(*args, **kw)
    loc = Teimoso()
    loc.instalados["kadence-blocks"] = "3.7.10"
    r = executar(loc, plano_exec, inv_prod, {}, contratos_ok, saida, None, {"kadence-child"})
    checar(r == 30 and "alinhamento incompleto" in (saida / "relatorio.md").read_text(),
           f"alinhamento que não converge deveria sair 30 (saiu {r})")

# --------------------------------------------------------------------------- #
# 4. ler_saida.py: leitura de produção (#413)
# --------------------------------------------------------------------------- #
import subprocess  # noqa: E402

LER = RAIZ / "scripts/plugins/ler_saida.py"
PLUG = '[{"name":"bnfw","status":"active","version":"1.9","update":"none","update_version":"","auto_update":"off"}]'
TEMA = '[{"name":"kadence","status":"parent","version":"1.5.2","update":"none","update_version":"","auto_update":"off"}]'
cenarios = {
    "ruído antes e depois": (f"Bem-vindo à Locaweb\n@@PLUGINS\n{PLUG}\n@@TEMAS\n{TEMA}\n@@FIM\nlogout\n", 0),
    "sem @@FIM": (f"@@PLUGINS\n{PLUG}\n@@TEMAS\n{TEMA}\n", 1),
    "aviso PHP no JSON": (f"@@PLUGINS\nPHP Deprecated: x\n{PLUG}\n@@TEMAS\n{TEMA}\n@@FIM\n", 1),
    "banner grudado no marcador": (f"banner@@PLUGINS\n{PLUG}\n@@TEMAS\n{TEMA}\n@@FIM\n", 1),
    "lista de plugins vazia": ("@@PLUGINS\n[]\n@@TEMAS\n[]\n@@FIM\n", 1),
    "saída vazia": ("", 1),
}
with tempfile.TemporaryDirectory() as tmp:
    for nome, (texto, esperado) in cenarios.items():
        destino = pathlib.Path(tmp) / f"{abs(hash(nome))}.json"
        r = subprocess.run([sys.executable, str(LER), str(destino)], input=texto, capture_output=True, text=True)
        bruto = destino.with_name(destino.name + ".bruto.txt")
        checar(r.returncode == esperado, f"ler_saida [{nome}]: saiu {r.returncode}, esperado {esperado}")
        if esperado == 0:
            dados = json.loads(destino.read_text()) if destino.exists() else {}
            checar([p["name"] for p in dados.get("plugins", [])] == ["bnfw"], f"ler_saida [{nome}]: plugins errados")
            checar(not bruto.exists(), f"ler_saida [{nome}]: não deveria gravar saída bruta no sucesso")
        else:
            checar(not destino.exists(), f"ler_saida [{nome}]: gravou inventário apesar da falha")
            checar(bruto.exists() and bruto.read_text() == texto, f"ler_saida [{nome}]: saída bruta não foi guardada")

with tempfile.TemporaryDirectory() as tmp:
    saida = pathlib.Path(tmp)

    # A2: fluent-smtp ativo em produção, inativo no local: fora do lock.
    plano_smtp = {"itens": plano_exec["itens"] + [
        {"slug": "fluent-smtp", "tipo": "plugin", "de": "2.4.1", "para": "2.4.2", "camada": "critica", "aplicacao": "um_por_vez"}],
        "fora": []}
    loc = FakeLocal()
    r = executar(loc, plano_smtp, inv_prod, {}, contratos_ok, saida, None, {"kadence-child"})
    lock = json.loads((saida / "lock.json").read_text())
    checar(r == 0 and "fluent-smtp" not in [i["slug"] for i in lock["itens"]],
           "fluent-smtp (código não carregado) não pode entrar no lock")
    checar(any(f["slug"] == "fluent-smtp" and "não carrega" in f["motivo"] for f in lock["fora"]),
           "fluent-smtp deveria aparecer em fora com o motivo")
    checar(("plugin", "update", "fluent-smtp", "--version=2.4.2") not in loc.chamadas, "fluent-smtp não deveria ser atualizado")

    # M1: exceção no smoke depois da linha de base -> 20 com relatório, sem lock.
    (saida / "lock.json").unlink()
    loc = FakeLocal()
    loc.explode_no_smoke = 2
    r = executar(loc, plano_exec, inv_prod, {}, contratos_ok, saida, None, {"kadence-child"})
    checar(r == 20 and not (saida / "lock.json").exists() and "RuntimeError" in (saida / "relatorio.md").read_text(),
           f"exceção no smoke deveria sair 20 com relatório (saiu {r})")

    # B1: versão instalada diferente da do plano -> 20.
    class Desvia(FakeLocal):
        def wp(self, *args, **kw):
            saida_wp = super().wp(*args, **kw)
            if len(args) > 2 and args[1] == "update" and args[2] == "google-site-kit":
                self.instalados["google-site-kit"] = "1.189.1"
            return saida_wp
    loc = Desvia()
    r = executar(loc, plano_exec, inv_prod, {}, contratos_ok, saida, None, {"kadence-child"})
    checar(r == 20 and "instalado 1.189.1, esperado 1.189.0" in (saida / "relatorio.md").read_text(),
           f"versão divergente após o update deveria sair 20 (saiu {r})")

    # B2: decisao_pendente com versão divergente não é reinstalado.
    inv_pro = {**inv_prod, "plugins": inv_prod["plugins"] + [{"name": "seo-by-rank-math-pro", "version": "3.0.110", "status": "inactive"}]}
    loc = FakeLocal()
    loc.instalados["seo-by-rank-math-pro"] = "3.0.100"
    r = executar(loc, plano_exec, inv_pro, {}, contratos_ok, saida, None, {"kadence-child", "seo-by-rank-math-pro"})
    checar(r == 0 and not any(c[:3] == ("plugin", "install", "seo-by-rank-math-pro") for c in loc.chamadas),
           f"decisao_pendente não deveria ser reinstalado (saiu {r})")

if falhas:
    print("FAIL:")
    for f in falhas:
        print(f"  - {f}")
    sys.exit(1)
print("OK: plano, comparação e fluxo do ensaio")
