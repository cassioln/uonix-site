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

# Núcleo (#448): sem API, sem quarentena; entra no plano na frente de nada e por último; major bloqueia.
def plano_nucleo(de, para, extra=None, aceitar=frozenset()):
    inv_n = {"plugins": extra or [], "temas": [], "nucleo": [item("wordpress", de, para)]}
    return plano.planejar(inv_n, POLITICA, {}, HOJE, set(aceitar), set())


checar(plano._subiu_major("", "2.0", "plugin") and not plano._subiu_major("", "2.0", "nucleo"),
       "plugin com origem ilegível mantém o major (regra anterior); o núcleo não")
checar(plano._subiu_major("7.1.3", "7.2", "nucleo") and not plano._subiu_major("7.1.3", "7.1.9", "nucleo")
       and plano._subiu_major("1.9", "2.0", "plugin") and not plano._subiu_major("1.9", "1.10", "plugin"), "major por série x por número")
pn = plano_nucleo("7.1.3", "7.1.4", extra=[item("loco-translate", "2.8.9", "2.9.0")])
checar(pn["itens"][-1]["slug"] == "wordpress" and pn["itens"][-1]["tipo"] == "nucleo" and pn["itens"][-1]["camada"] == "nucleo",
       f"o núcleo deveria entrar por último: {pn['itens']}")
checar(pn["itens"][-1]["quarentena"] == "não se aplica" and pn["itens"][-1]["aplicacao"] == "um_por_vez", f"núcleo sem quarentena e um por vez: {pn['itens'][-1]}")
pn = plano_nucleo("7.1.3", "7.2")
checar(not pn["itens"] and "--aceitar-major=wordpress" in pn["fora"][0]["motivo"], f"major do núcleo deveria bloquear: {pn}")
pn = plano_nucleo("7.1.3", "7.2", aceitar={"wordpress"})
checar([i["slug"] for i in pn["itens"]] == ["wordpress"], f"--aceitar-major=wordpress deveria liberar: {pn}")
checar(plano.chave_api("nucleo", "wordpress") == "nucleo:wordpress" and plano.buscar_api("wordpress", "nucleo") is None,
       "o núcleo nunca consulta a API (o slug 'wordpress' dos plugins é outro projeto)")

# Tema (#445): a chave da API leva o prefixo "tema:", a data vem da API de temas e não há changelog.
def api_tema(versao, lancado):
    return {"version": versao, "last_updated": lancado, "sections": {"description": "Tema."}}


def plano_tema(de, para, cache_tema, extra_plugins=None, extra_cache=None):
    inv_t = {"plugins": extra_plugins or [], "temas": [item("kadence", de, para)]}
    return plano.planejar(inv_t, POLITICA, {**(extra_cache or {}), **cache_tema}, HOJE, set(), set())


pt = plano_tema("1.5.2", "1.5.3", {"tema:kadence": api_tema("1.5.3", "2026-09-27")})
checar([(i["slug"], i["tipo"], i["camada"], i["quarentena"]) for i in pt["itens"]] == [("kadence", "tema", "critica", "cumprida")],
       f"tema crítico lançado há mais de 7 dias deveria entrar: {pt}")
checar(pt["itens"][0]["novidades"] is None and pt["itens"][0]["changelog"] == "sem as versões do intervalo",
       f"tema não tem changelog na API: {pt['itens'][0]}")
pt = plano_tema("1.5.2", "1.5.3", {"tema:kadence": api_tema("1.5.3", "2026-10-07")})
checar(not pt["itens"] and "quarentena: lançada há 0 de 7" in pt["fora"][0]["motivo"], f"tema novo deveria cumprir quarentena: {pt}")
pt = plano_tema("1.5.2", "1.5.3", {})
checar(not pt["itens"] and "não verificável" in pt["fora"][0]["motivo"], f"tema sem API deveria ficar em quarentena não verificável: {pt}")
pt = plano_tema("1.5.2", "2.0.0", {"tema:kadence": api_tema("2.0.0", "2026-09-01")})
checar(not pt["itens"] and "--aceitar-major=kadence" in pt["fora"][0]["motivo"], f"major do tema deveria bloquear: {pt}")
# Plugin e tema de mesmo slug não trocam de resposta da API.
pt = plano_tema("1.5.2", "1.5.3", {"tema:kadence": api_tema("1.5.3", "2026-09-27")},
                extra_plugins=[item("kadence", "9.0", "9.1")], extra_cache={"kadence": api("9.1", "2026-10-07")})
checar(sorted((i["tipo"], i["quarentena"]) for i in pt["itens"]) == [("tema", "cumprida")]
       and any(f["tipo"] == "plugin" for f in pt["fora"]),
       f"plugin e tema homônimos não podem compartilhar a resposta da API: {pt}")
checar(plano.chave_api("tema", "kadence") == "tema:kadence" and plano.chave_api("plugin", "kadence") == "kadence",
       "chave_api errada")

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
# Corte por item (relatório): tags de bloco separam itens; <code>, <a> e <strong> ficam dentro da
# frase. O corte por tag, usado na decisão de segurança, não muda.
html_gsk = ("<h4>= 1.190.0 =</h4><p><strong>Enhanced</strong></p><ul>"
            "<li>Add the <code>rrmSurveyCTA</code> feature flag. See <a href=\"https://x/1\">#13716</a>.</li>"
            "<li>Add a dashboard callout introducing the <em>Add features</em> button. See <a href=\"https://x/2\">#13360</a>.</li>"
            "<li>Fix cut-off axis labels. Props someone. See <a>#13218</a>, <a>#13219</a>.</li></ul>"
            "<p>See changelog for all versions.</p><h4>= 1.189.0 =</h4><ul><li>Velho</li></ul>")
info_gsk = {"version": "1.190.0", "last_updated": "2026-10-19", "sections": {"changelog": html_gsk}}
por_item = plano.trecho_changelog(info_gsk, "1.189.0", "1.190.0", so_para=True, por_item=True)
checar(por_item == ["Enhanced",
                    "Add the rrmSurveyCTA feature flag. See #13716.",
                    "Add a dashboard callout introducing the Add features button. See #13360.",
                    "Fix cut-off axis labels. Props someone. See #13218, #13219.",
                    "See changelog for all versions."], f"corte por item: {por_item}")
por_tag = plano.trecho_changelog(info_gsk, "1.189.0", "1.190.0", so_para=True)
checar("Add the" in por_tag and "rrmSurveyCTA" in por_tag, f"o corte por tag deveria continuar fragmentando: {por_tag}")
checar(plano.trecho_changelog(info_gsk, "1.189.0", "1.190.0") == plano.trecho_changelog(info_gsk, "1.189.0", "1.190.0", por_item=False),
       "o padrão (sem por_item) não pode mudar")
# O caminho padrão (decisão de segurança) não normaliza espaços: o corte por item é quem normaliza.
info_esp = {"version": "2", "last_updated": "2026-10-20", "sections": {"changelog":
            "<h4>= 2.0 =</h4><p>Cross  site scripting hole</p><p>SQL\xa0 injection</p><h4>= 1.9 =</h4>"}}
cru = plano.trecho_changelog(info_esp, "1.9", "2.0")
checar(cru == ["Cross  site scripting hole", "SQL\xa0 injection"], f"o padrão não pode normalizar espaços: {cru}")
checar(not any(plano.SEGURANCA.search(l) for l in cru), "a decisão de segurança não pode mudar com espaço duplo")
checar(plano.trecho_changelog(info_esp, "1.9", "2.0", por_item=True) == ["Cross site scripting hole", "SQL injection"],
       "o corte por item normaliza espaços")
# cabeçalho com tag inline e texto longo continua sendo cabeçalho no corte por item (revisão do #441)
cab_inline = {"version": "2", "last_updated": "2026-10-20", "sections": {"changelog":
              "<h4>= 2.0 =</h4><p>Item novo</p><h4>= 1.9 =</h4><p><strong>1.9</strong> <em>Released January 1st 2026 as a maintenance update for everyone</em></p><p>Item velho</p>"}}
checar(plano.trecho_changelog(cab_inline, "1.9", "2.0", so_para=True, por_item=True) == ["Item novo"],
       f"item velho não pode vazar nas novidades: {plano.trecho_changelog(cab_inline, '1.9', '2.0', so_para=True, por_item=True)}")
sec_a = {"version": "2", "last_updated": "2026-10-20", "sections": {"changelog": "<h4>= 2.0 =</h4><p>Security: Escape output.</p><p>New tab.</p><h4>= 1.9 =</h4>"}}
checar(any(plano.SEGURANCA.search(l) for l in plano.trecho_changelog(sec_a, "1.9", "2.0")), "a decisão de segurança segue com o corte por tag")

# regravar_novidades.py: refaz só o campo "novidades" de um plano antigo (rede simulada).
import io as _io, tempfile as _tf, urllib.request as _ur
sys.path.insert(0, str(pathlib.Path(plano.__file__).resolve().parent))
import regravar_novidades as _rg
_html = "<h4>= 2.0 =</h4><ul><li>Add the <code>x</code> flag. See <a>#1</a>.</li></ul><h4>= 1.9 =</h4><ul><li>Velho</li></ul>"
class _Resp(_io.BytesIO):
    def __enter__(self): return self
    def __exit__(self, *a): return False
_original = _ur.urlopen
_ur.urlopen = lambda url, timeout=0: _Resp(json.dumps({"version": "2.0", "last_updated": "2026-10-20", "sections": {"changelog": _html}}).encode())
with _tf.TemporaryDirectory() as _d:
    _pasta = pathlib.Path(_d)
    _antigo = {"gerado_em": "x", "itens": [{"slug": "p", "de": "1.9", "para": "2.0", "seguranca": True,
               "changelog": ["Security fix"], "novidades": ["Add the", "x", "flag. See", "#1", "."]}], "fora": []}
    (_pasta / "plano.json").write_text(json.dumps(_antigo))
    with contextlib.redirect_stdout(io.StringIO()):
        _rc = _rg.main([str(_pasta)])
    _novo = json.loads((_pasta / "plano.json").read_text())
    checar(_rc == 0 and _novo["itens"][0]["novidades"] == ["Add the x flag. See #1."], f"regravar: {_novo['itens'][0]['novidades']}")
    checar(_novo["itens"][0]["changelog"] == ["Security fix"] and _novo["itens"][0]["seguranca"] is True,
           "regravar não pode mexer na decisão de segurança")
    checar(json.loads((_pasta / "plano.antes-por-item.json").read_text())["itens"][0]["novidades"][0] == "Add the", "o original fica guardado")
_ur.urlopen = _original

# Novidades para o relatório do cliente: o trecho inteiro, só da versão nova.
texto = "<h4>= 1.2.5 =</h4><p>New: dark mode</p><p>Removed: old API</p><h4>= 1.2.4 =</h4><p>Fix b</p><h4>= 1.2.3 =</h4><p>Fix a</p>"
checar(plano.trecho_changelog(cl(texto), "1.2.3", "1.2.5", so_para=True) == ["New: dark mode", "Removed: old API"],
       f"novidades deveriam ser só da 1.2.5: {plano.trecho_changelog(cl(texto), '1.2.3', '1.2.5', so_para=True)}")
checar(plano.trecho_changelog(cl(texto), "1.2.3", "1.2.5") == ["New: dark mode", "Removed: old API", "Fix b"],
       "o intervalo continua com as duas versões")
checar(plano.trecho_changelog(cl("<h4>= 1.2.4 =</h4><p>Fix b</p>"), "1.2.3", "1.2.5", so_para=True) is None,
       "sem a seção da versão nova, as novidades ficam indisponíveis")
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

# #425: "security" diante de recurso e classe de vulnerabilidade como nome de recurso.
for falso in ("Security: new settings tab", "Security Headers: new page", "Security Hardening: new checklist UI",
              "Improved security settings page layout", "Enhanced security dashboard widget",
              "Improved security scan performance", "Security update notifications in dashboard",
              "Added Security Release notices", "Tweak: Security update checker UI",
              "Added XSS filter toggle", "New CSRF token settings", "SSRF protection setting",
              "New: open redirect setting", "Vulnerability scanner integration added",
              "Added XSS protection for the IP address field", "New IP address field for the open redirect allowlist"):
    checar(not plano.SEGURANCA.search(falso), f"falso positivo de segurança (#425): {falso}")
# As linhas reais de 2026-10 (HUSKY 1.4.6, BNFW 1.10, WP Crontrol 1.22.0, Kadence 3.8.0) seguem reconhecidas,
# e também classe com verbo de correção, mesmo que a linha cite um recurso.
for verdadeiro in ("Security fix. Thanks to Sawyer for the responsible disclosure and to Wordfence for coordinating it",
                   "Fixed: Various security issues to better harden the plugin.",
                   "Security: Hardens several permission and integrity checks for cron events.",
                   "* Security: Improved SVG sanitization and reject vector imports that can’t be safely cleaned.",
                   "Prevented XSS in the shortcode output", "XSS vulnerability in the widget title",
                   "Fixed XSS in the security settings page", "Resolved a CSRF issue on the export action",
                   "Addressed an open redirect in the login form", "Security: Added nonce verification to the AJAX handler",
                   "Added missing capability check to the security settings page"):
    checar(bool(plano.SEGURANCA.search(verdadeiro)), f"correção de segurança não reconhecida (#425): {verdadeiro}")
# Revisão do #436: a primeira versão do #425 perdia avisos reais (formato Wordfence/Patchstack,
# classe forte sem verbo, recurso suprimindo a linha inteira, "Security: new <correção>").
for verdadeiro in ("Authenticated (Contributor+) Stored Cross-Site Scripting",
                   "Stored XSS in the admin area (reported by Wordfence)", "SQL injection in REST endpoint",
                   "Potential XSS", "Sanitize input to avoid XSS", "Escape output to protect against CSRF",
                   "Block SQL injection in search", "Hardened security settings page against CSRF",
                   "Fixed security issue in the security settings page",
                   "Security fix: security log readable by subscribers",
                   "Security update: patched the security headers module",
                   "Security: Escape output on the security settings page",
                   "Security: new nonce check on import", "Security: New sanitization for user input",
                   "Fixed CSRF token validation bypass", "Reflected XSS reported by Patchstack"):
    checar(bool(plano.SEGURANCA.search(verdadeiro)), f"aviso de segurança real não reconhecido (revisão #436): {verdadeiro}")
# ... e os falsos positivos que já existiam antes do #425: classe fraca diante de nome de recurso.
for falso in ("Fixed layout of the XSS filter toggle", "Fix: typo in CSRF protection setting label",
              "Fixed SSRF protection setting not saving", "Fixed vulnerability scanner integration",
              "Fixed issue with vulnerability report export", "Fixed a crash in the CSRF token refresh",
              "Prevent duplicate XSS filter rules", "Integrated with Patchstack for vulnerability data"):
    checar(not plano.SEGURANCA.search(falso), f"falso positivo de segurança (revisão #436): {falso}")

# #425: pré-release colado abre seção e conta como a versão numérica; 1.2.1000 é versão, não data.
for nome, texto, para, esperado in (
    ("pré-release colado", "<p>= 1.2.5a =</p><p>Security: fix</p><p>= 1.2.3 =</p><p>old</p>", "1.2.5", ["Security: fix"]),
    ("beta colado", "<p>= 2.0.0beta =</p><p>New UI</p><p>= 1.9.0 =</p><p>old</p>", "2.0.0", ["New UI"]),
    ("versão 1.2.1000", "<p>= 1.2.1000 =</p><p>Fix</p><p>= 1.2.999 =</p><p>old</p>", "1.2.1000", ["Fix"]),
):
    obtido = plano.trecho_changelog(cl(texto), "1.0", para, so_para=True)
    checar(obtido == esperado, f"cabeçalho [{nome}]: {obtido}, esperado {esperado}")
checar(plano.DATA_COMO_VERSAO.match("25.09.2026") and not plano.DATA_COMO_VERSAO.match("1.2.1000"),
       "dd.mm.aaaa é data; 1.2.1000 é versão")
# o sufixo só vale em cabeçalho marcado: "* 2.0b improvements" não é versão (revisão do #436)
for linha in ("* 2.0b improvements", "* 1.2a tweaks", "* 3.0rc ready", "* 2.0beta testers welcome", "2.0b improvements"):
    checar(plano.trecho_changelog(cl(f"<p>= 1.2.5 =</p><p>{linha}</p><p>Security: fix</p><p>= 1.2.3 =</p>"),
                                  "1.2.3", "1.2.5") == [linha, "Security: fix"], f"linha comum virou cabeçalho: {linha}")
checar(plano.trecho_changelog(cl("<h4>## 1.2.5-rc1</h4><p>Fix</p><h4>## 1.2.3</h4><p>old</p>"), "1.2.3", "1.2.5",
                              so_para=True) == ["Fix"], "## 1.2.5-rc1 deveria abrir a seção")

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
        self.tema_ativo = "kadence-child"
        self.chamadas: list[tuple] = []
        self.quebra_em, self.falha_backup = quebra_em, falha_backup
        self.nucleo, self.falha_update_db = "7.1.3", False

    def locale(self):
        return "pt_BR"

    def preparar_wpcli(self):
        return "WP-CLI fake"

    def wp(self, *args, verificar=True, timeout=0):
        self.chamadas.append(args)
        if args[:2] == ("plugin", "list"):
            return json.dumps([{"name": n, "version": v, "status": "active" if n in self.ativos else "inactive"}
                               for n, v in self.instalados.items()])
        if args[:2] == ("theme", "list"):
            return json.dumps([{"name": n, "version": v, "status": "active" if n == self.tema_ativo else "inactive"}
                               for n, v in self.temas.items()])
        if args[0] == "core":
            if args[1] == "version":
                return self.nucleo + "\n"
            if args[1] in {"download", "update"}:
                self.nucleo = next(a.split("=", 1)[1] for a in args if a.startswith("--version="))
            elif args[1] == "update-db" and self.falha_update_db:
                raise RuntimeError("wp core update-db falhou (exit 1)")
            return ""
        if args[:2] == ("theme", "activate"):
            self.tema_ativo = args[2]
            return ""
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


# #428: verificar_alinhamento com estado desalinhado (o FakeLocal sempre alinha).
class LocalFixo:
    """Só responde ao `list`, com o estado dado: a verificação nunca muda nada."""

    def __init__(self, plugins, temas):
        self.plugins, self.temas = plugins, temas

    def wp(self, *args, **kw):
        if args[:2] == ("plugin", "list"):
            return json.dumps(self.plugins)
        if args[:2] == ("theme", "list"):
            return json.dumps(self.temas)
        raise AssertionError(f"verificar_alinhamento não pode mutar o local: {args}")


inv_alinh = {"plugins": [{"name": "comum-a", "version": "2.0", "status": "active"},
                         {"name": "comum-b", "version": "1.0", "status": "inactive"},
                         {"name": "ignorado-velho", "version": "9.0", "status": "active"},
                         {"name": "ignorado-inativo", "version": "1.0", "status": "inactive"},
                         {"name": "fluent-smtp", "version": "2.4.1", "status": "active"},
                         {"name": "mu", "version": "1", "status": "must-use"}],
             "temas": [{"name": "pai", "version": "1.0", "status": "parent"},
                       {"name": "filho", "version": "1.4", "status": "active"}]}
ign_alinh = {"ignorado-velho", "ignorado-inativo", "filho"}
alinhado = LocalFixo([{"name": "comum-a", "version": "2.0", "status": "active"},
                      {"name": "comum-b", "version": "1.0", "status": "inactive"},
                      {"name": "ignorado-velho", "version": "1.0", "status": "active"},
                      {"name": "fluent-smtp", "version": "2.4.1", "status": "inactive"}],
                     [{"name": "pai", "version": "1.0", "status": "inactive"},
                      {"name": "filho", "version": "0.0-local", "status": "active"}])
checar(ensaio.verificar_alinhamento(alinhado, inv_alinh, ign_alinh) == [],
       f"alinhado (ignorado com outra versão, ignorado inativo ausente, fluent-smtp inativo por exceção, pai sem alvo) "
       f"deveria passar: {ensaio.verificar_alinhamento(alinhado, inv_alinh, ign_alinh)}")
desalinhado = LocalFixo([{"name": "comum-a", "version": "1.9", "status": "inactive"},
                         {"name": "ignorado-velho", "version": "1.0", "status": "inactive"},
                         {"name": "fluent-smtp", "version": "2.4.1", "status": "active"}],
                        [{"name": "pai", "version": "1.0", "status": "active"}])
div = ensaio.verificar_alinhamento(desalinhado, inv_alinh, ign_alinh)
for esperado in ("comum-a versão local 1.9 != produção 2.0", "comum-a status local inactive != esperado active",
                 "comum-b ausente no local", "ignorado-velho status local inactive != esperado active",
                 "fluent-smtp status local active != esperado inactive", "filho ativo em produção e ausente no local"):
    checar(esperado in div, f"verificar_alinhamento deveria apontar '{esperado}': {div}")
checar(not any("ignorado-inativo" in d or "ignorado-velho versão" in d or d.startswith("pai") or d.startswith("mu ")
               for d in div), f"ignorado não confere versão, ignorado inativo ausente e tema pai não divergem: {div}")

# #428: plugin ausente no local e ativo em produção: install cria e a ativação é alinhada.
inv_base = {"plugins": [{"name": "kadence-blocks", "version": "3.7.12", "status": "active"},
                        {"name": "google-site-kit", "version": "1.188.0", "status": "active"},
                        {"name": "fluent-smtp", "version": "2.4.1", "status": "active"}],
            "temas": [{"name": "kadence", "version": "1.5.2", "status": "parent"},
                      {"name": "kadence-child", "version": "1.4.3", "status": "active"}]}
fl = FakeLocal()
inv_ausente = {"plugins": inv_base["plugins"] + [{"name": "novo-ativo", "version": "3.0", "status": "active"}],
               "temas": inv_base["temas"]}
acoes = ensaio.alinhar(fl, inv_ausente, ignorar={"kadence-child"})
checar(fl.instalados.get("novo-ativo") == "3.0" and "novo-ativo" in fl.ativos,
       f"ausente no local deveria ser instalado e ativado: {fl.instalados} {fl.ativos}")
checar("plugin novo-ativo: ausente -> 3.0" in acoes and "plugin novo-ativo: ativado" in acoes, f"ações: {acoes}")
# ignorado inativo e ausente: sem aviso; ignorado ativo e ausente: aviso e reprovação
fl = FakeLocal()
inv_ign = {"plugins": inv_base["plugins"] + [{"name": "pago-inativo", "version": "1", "status": "inactive"}],
           "temas": inv_base["temas"]}
acoes = ensaio.alinhar(fl, inv_ign, ignorar={"kadence-child", "pago-inativo"})
checar(not any("pago-inativo" in a for a in acoes), f"ignorado inativo ausente não deveria gerar aviso: {acoes}")
fl = FakeLocal()
del fl.temas["kadence-child"]
fl.tema_ativo = "kadence"
try:
    ensaio.alinhar(fl, inv_base, ignorar={"kadence-child"})
    falhas.append("tema ativo em produção e ausente no local deveria reprovar o alinhamento")
except ensaio.Falha as erro:
    checar("kadence-child ativo em produção e ausente no local" in str(erro), f"motivo errado: {erro}")


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

    # 3a'. núcleo (#448): alinha o local à versão de produção (7.1.3 -> 7.1.4), atualiza para o lock
    # (7.1.5) por último, com --locale do site e update-db; o lock leva o núcleo na ordem do plano.
    inv_nuc = {**inv_prod, "nucleo": [{"name": "wordpress", "version": "7.1.4", "status": "active"}]}
    plano_nuc = {"itens": plano_exec["itens"] + [
        {"slug": "wordpress", "tipo": "nucleo", "de": "7.1.4", "para": "7.1.5", "camada": "nucleo", "aplicacao": "um_por_vez"}],
        "fora": []}
    (saida / "lock.json").unlink(missing_ok=True)
    loc = FakeLocal()
    r = executar(loc, plano_nuc, inv_nuc, {}, contratos_ok, saida, None, {"kadence-child"})
    lock = json.loads((saida / "lock.json").read_text()) if (saida / "lock.json").exists() else {}
    checar(r == 0 and loc.nucleo == "7.1.5", f"núcleo verde saiu {r}, núcleo={loc.nucleo}")
    checar(("core", "download", "--version=7.1.4", "--force", "--skip-content", "--locale=pt_BR") in loc.chamadas,
           f"alinhamento do núcleo sem --locale e --skip-content: {[c for c in loc.chamadas if c[0] == 'core']}")
    checar(("core", "update", "--version=7.1.5", "--locale=pt_BR", "--force") in loc.chamadas, "core update sem a versão exata do lock e o locale")
    chamadas_core = [c[1] for c in loc.chamadas if c[0] == "core"]
    checar(chamadas_core.index("update-db") > chamadas_core.index("update"), f"update-db deveria vir depois do core update: {chamadas_core}")
    checar([i["slug"] for i in lock.get("itens", [])][-1:] == ["wordpress"] and lock["itens"][-1]["tipo"] == "nucleo",
           f"o núcleo deveria ser o último item do lock: {[i['slug'] for i in lock.get('itens', [])]}")
    ordem = [c for c in loc.chamadas if len(c) > 1 and c[1] == "update"]
    checar(ordem[-1][0] == "core", f"o núcleo deveria ser a última atualização do ensaio: {ordem}")

    # 3a''. núcleo local já na versão de produção: nada a baixar. update-db que falha: saída 20, sem lock.
    loc = FakeLocal(); loc.nucleo = "7.1.4"
    executar(loc, plano_nuc, inv_nuc, {}, contratos_ok, saida, None, {"kadence-child"})
    checar(not any(c[:2] == ("core", "download") for c in loc.chamadas), "núcleo já alinhado não deveria ser baixado de novo")
    (saida / "lock.json").unlink(missing_ok=True)
    loc = FakeLocal(); loc.falha_update_db = True
    r = executar(loc, plano_nuc, inv_nuc, {}, contratos_ok, saida, None, {"kadence-child"})
    checar(r == 20 and not (saida / "lock.json").exists(), f"update-db que falha deveria sair 20 sem lock (saiu {r})")
    # sem o item "nucleo" no inventário (leitura antiga), o ensaio ignora o núcleo em vez de falhar
    loc = FakeLocal()
    checar(ensaio.alinhar_nucleo(loc, inv_prod) == [] and not any(c[0] == "core" for c in loc.chamadas), "sem núcleo no inventário, nada a alinhar")

    # 3b. regressão no crítico: para, saída 20, sem lock.
    (saida / "lock.json").unlink(missing_ok=True)
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
# Núcleo (#448): marcadores opcionais; a menor versão da série vence a major; saída ruim não vira inventário.
NUC = lambda v, o: f"@@PLUGINS\n{PLUG}\n@@TEMAS\n{TEMA}\n@@NUCLEO_VERSAO\n{v}\n@@NUCLEO_ATUALIZACOES\n{o}\n@@FIM\n"
sys.path.insert(0, str(RAIZ / "scripts/plugins"))
import ler_saida  # noqa: E402

# update_type propositalmente trocado: a série vem das versões, não do rótulo do WP-CLI
n = ler_saida.separar(NUC("7.1.3", '[{"version":"7.1.4","update_type":"major"},{"version":"7.2","update_type":"minor"},{"version":"7.1.5","update_type":"patch"}]'))["nucleo"][0]
checar((n["name"], n["version"], n["update"], n["update_version"]) == ("wordpress", "7.1.3", "available", "7.1.5"),
       f"deveria escolher a menor versão da série mais nova (7.1.5): {n}")
n = ler_saida.separar(NUC("7.1.3", '[{"version":"7.2","update_type":"major"}]'))["nucleo"][0]
checar(n["update"] == "available" and n["update_version"] == "7.2", f"sem menor, a major é a oferta (a política a bloqueia): {n}")
n = ler_saida.separar(NUC("7.1.3", '[{"version":"7.1.3"},{"version":"7.0.9"}]'))["nucleo"][0]
checar(n["update"] == "none", f"oferta igual ou mais antiga que a instalada não é atualização: {n}")
n = ler_saida.separar(NUC("7.1.3", ""))["nucleo"][0]
checar(n["update"] == "none" and n["update_version"] == "", f"sem oferta (saída vazia do check-update): {n}")
checar("nucleo" not in ler_saida.separar(f"@@PLUGINS\n{PLUG}\n@@TEMAS\n{TEMA}\n@@FIM\n"), "sem os marcadores do núcleo, o inventário não tem 'nucleo'")
checar("nucleo" not in ler_saida.separar(NUC("", "[]")), "versão do núcleo vazia (core version falhou): sem 'nucleo'")
for ruim, motivo in ((NUC("7.1.3; rm -rf /", "[]"), "versão com lixo"), (NUC("7.1.3", "{nao-json"), "JSON ruim"), (NUC("7.1.3", '{"a":1}'), "objeto no lugar de lista")):
    try:
        ler_saida.separar(ruim)
        falhas.append(f"ler_saida: {motivo} deveria ser recusado")
    except ler_saida.SaidaInvalida:
        pass

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

# --------------------------------------------------------------------------- #
# 5. Alinhamento de ativação de itens ignorados e de temas (#419)
# --------------------------------------------------------------------------- #
with tempfile.TemporaryDirectory() as tmp:
    saida = pathlib.Path(tmp)
    inv_419 = {"plugins": inv_prod["plugins"] + [{"name": "seo-by-rank-math-pro", "version": "3.0.110", "status": "inactive"}],
               "temas": [{"name": "kadence", "version": "1.5.2", "status": "parent"},
                         {"name": "kadence-child", "version": "1.4.3", "status": "active"},
                         {"name": "twentytwentythree", "version": "1.7", "status": "inactive"}]}
    loc = FakeLocal()
    loc.instalados["seo-by-rank-math-pro"] = "3.0.100"     # versão diverge, e não deve ser reinstalado
    loc.ativos.add("seo-by-rank-math-pro")                 # ativo no local, inativo em produção
    loc.temas["twentytwentythree"] = "1.7"
    loc.tema_ativo = "twentytwentythree"                   # tema ativo diferente de produção
    r = executar(loc, plano_exec, inv_419, {}, contratos_ok, saida, None, {"kadence-child", "seo-by-rank-math-pro", "twentytwentythree"})
    checar(r == 0, f"alinhamento de ativação saiu {r}: {(saida / 'relatorio.md').read_text()[-400:]}")
    checar("seo-by-rank-math-pro" not in loc.ativos, "item ignorado ativo no local e inativo em produção deveria ser desativado")
    checar(not any(c[:3] == ("plugin", "install", "seo-by-rank-math-pro") for c in loc.chamadas),
           "item ignorado não pode ser reinstalado por versão")
    checar(loc.tema_ativo == "kadence-child", f"o tema ativo de produção deveria ser ativado no local (está {loc.tema_ativo})")
    checar(not any(c[:2] == ("theme", "install") for c in loc.chamadas), "tema ignorado não pode ser instalado")

# --------------------------------------------------------------------------- #
# 6. main() liga a invalidação ao marcador do clone (#419)
# --------------------------------------------------------------------------- #
with tempfile.TemporaryDirectory() as tmp:
    base = pathlib.Path(tmp)
    (base / "tmp" / "plugins").mkdir(parents=True)
    marcador = base / "tmp" / "plugins" / "ultimo-clone.json"
    marcador.write_text('{"concluido_em": "2026-10-08T10:00:00", "backup": "x"}')
    (base / "inv.json").write_text(json.dumps(inv_prod))
    (base / "plano.json").write_text(json.dumps(plano_exec))
    chamado = {}

    def executar_falso(local, plano_, inventario, contratos, verificar, saida, clonar, proprios, invalidar):
        chamado["proprios"] = proprios
        invalidar()
        return 0

    original = ensaio.executar
    ensaio.executar = executar_falso
    try:
        rc = ensaio.main(["--inventario", str(base / "inv.json"), "--plano", str(base / "plano.json"),
                          "--checkout", str(base), "--saida", str(base / "saida"), "--reusar-clone"])
    finally:
        ensaio.executar = original
    checar(rc == 0 and not marcador.exists(), "main() deveria passar a invalidação que apaga o marcador do clone")
    checar({"kadence-child", "seo-by-rank-math-pro", "wordpress-importer"} <= chamado.get("proprios", set()),
           f"main() deveria ignorar no alinhamento por versão as camadas com aplicacao 'nunca': {chamado.get('proprios')}")

if falhas:
    print("FAIL:")
    for f in falhas:
        print(f"  - {f}")
    sys.exit(1)
print("OK: plano, comparação e fluxo do ensaio")
