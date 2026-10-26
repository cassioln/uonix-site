#!/usr/bin/env python3
"""Monta o plano de um ensaio de atualização a partir do inventário de
produção e da política (#393).

Para cada plugin ou tema com atualização disponível, decide se entra no ensaio
e em que ordem, ou por que fica de fora:

- `decisao_pendente` e `propria` nunca entram;
- versão nova indisponível por requisito de PHP/WP não entra;
- major na camada cuja regra é `bloqueia` só entra com --aceitar-major=<slug>;
- na camada com quarentena, a versão precisa ter sido lançada há pelo menos
  `quarentena_dias`. A quarentena é dispensada quando o changelog do intervalo
  cita correção de segurança, ou com --dispensar-quarentena=<slug>. Se a data
  de lançamento não puder ser lida (plugin fora do wordpress.org), a
  quarentena não é verificável e o item fica de fora sem a dispensa explícita.

A data e o changelog vêm da API do wordpress.org. Em teste, --api-cache aponta
para um JSON {slug: resposta da API | null} e nada é buscado na rede. Tema leva o
prefixo "tema:" na chave ("tema:kadence"), porque plugin e tema podem ter o mesmo slug.

Ordem: camadas em lote (acoplada, comum) primeiro; a crítica depois, um por
vez, com o woocommerce por último, porque é o que migra o banco.

Uso: plano.py --inventario ARQ [--politica ARQ] [--api-cache ARQ] [--hoje AAAA-MM-DD]
              [--aceitar-major=a,b] [--dispensar-quarentena=a,b] [--saida ARQ]
Saída: 0 com plano gravado (mesmo vazio), 2 em erro de entrada.
"""
from __future__ import annotations

import argparse
import datetime as dt
import html
import json
import pathlib
import re
import sys
import urllib.parse
import urllib.request

RAIZ = pathlib.Path(__file__).resolve().parents[2]
POLITICA_PADRAO = RAIZ / "ops" / "plugins" / "politica.json"
API = "https://api.wordpress.org/plugins/info/1.2/"
API_TEMAS = "https://api.wordpress.org/themes/info/1.2/"
# Correção de segurança exige CONTEXTO, não a palavra solta: "Compatibility with
# Wordfence Security", "Added Security headers settings page" e "New permission
# checks screen" dispensariam a quarentena de um crítico por engano (#418).
# Um falso negativo só mantém a quarentena (conservador); um falso positivo a
# dispensa. Por isso palavras ambíguas só contam com contexto: "harden" só com o
# alvo da correção, "unauthenticated" só como exploração.
_CONTEXTO = re.compile(
    r"^\W*security\b[^:\n]{0,20}:"                                   # "Security:", "* Security fix:"
    r"|\bsecurity (fix|fixes|issue|issues|patch|vulnerabilit\w*|hardening|release|update)\b"
    r"|\b(improv|harden|strengthen|enhanc|tighten)\w* (the |plugin |overall )?security\b"
    r"|\bharden\w* (input |output |the )?(nonce|sanitiz|escap|validat|permission|capabilit|access|security)"
    r"|allow\w* unauthenticated|unauthenticated (users?|attackers?|visitors?)\b.{0,40}\b(could|can|to|were able|was able)\b"
    r"|broken access control|(missing|insufficient|improper) (authori[sz]ation|capability|permission|nonce|access)( check)?"
    r"|permission checks? (across|for|on|in|when)\b|output escaping|\bunescaped html\b",
    re.I | re.M)
# "security" diante de um recurso não é correção: "Security: new settings tab",
# "Improved security scan performance", "Security update notifications" (#425).
# Vale só para o próprio "security" do recurso: "Fixed security issue in the
# security settings page" continua correção (revisão do #436).
_RECURSO_NOME = (r"(settings?|page|tab|scan\w*|dashboard|widget|ui|headers?|notices?|checker|notifications?"
                 r"|options?|log|report|checklist|screen|panel|menu|section|toggle)")
_RECURSO = re.compile(
    r"\bsecurity( (update|release)s?)? " + _RECURSO_NOME + r"\b"
    r"|^\W*security\b[^:\n]{0,20}:\s*new\s+(\w+\s+){0,2}" + _RECURSO_NOME + r"\b", re.I | re.M)
# Classe de vulnerabilidade. As FORTES quase nunca nomeiam recurso e contam
# sozinhas ("SQL injection in REST endpoint"). As FRACAS também nomeiam recurso
# ("Added XSS filter toggle", "SSRF protection setting", "Vulnerability scanner"):
# só contam com verbo de correção ou qualificador de aviso de vulnerabilidade
# ("Stored XSS", "Authenticated (Contributor+) Stored Cross-Site Scripting",
# "Potential XSS", "XSS vulnerability"), e não diante de nome de recurso ("XSS
# filter", "CSRF token"), salvo se a linha falar em bypass ou exploit (#425).
# CVE só aparece para vulnerabilidade e conta sozinho.
_CVE = re.compile(r"\bcve-\d{4}", re.I)
_CLASSE_FORTE_TEXTO = (r"cross[- ]site (?:scripting|request forgery)|privilege escalation|sql injection"
                       r"|object injection|arbitrary file (?:upload|deletion|download|read)")
_CLASSE_FORTE = re.compile(_CLASSE_FORTE_TEXTO, re.I)
_CLASSE_FRACA = r"\bxss\b|\bcsrf\b|\bssrf\b|open redirect|\bvulnerab\w*"
_CLASSE_FRACA_RE = re.compile(_CLASSE_FRACA, re.I)
_CORRECAO = re.compile(
    r"\b(fix\w*|patch(ed|es|ing)?|prevent\w*|resolv\w*|address(ed|es|ing)|mitigat\w*|remediat\w*"
    r"|correct(ed|s|ing)?|avoid\w*|sanitiz\w*|escap\w*|block\w*|harden\w*)\b|\bprotect\w* against\b"
    r"|\b(stored|reflected|dom[- ]based|persistent|blind|potential|possible|authenticated|unauthenticated)\b"
    r"|(" + _CLASSE_FRACA + r")\s+(vulnerabilit\w*|issue|flaw|exploit)", re.I)
# Nome de recurso que segue a classe. Diante de um destes, a ocorrência da classe é o nome de um
# recurso ("XSS filter", "CSRF token"), e não uma vulnerabilidade (#425). A supressão vale por
# OCORRÊNCIA, e não para a linha toda (#437): em "Fixed XSS in the XSS filter settings" o primeiro
# XSS é a falha corrigida. Uma LISTA de classes antes do recurso é um nome só ("XSS and CSRF filter
# settings", "XSS vulnerability scanner"); uma palavra entre a classe e o recurso ("file upload
# EXTENSION list") é aceita, menos preposição e conjunção, que separam a falha do recurso
# ("XSS vulnerability IN token handling" é falha).
_RECURSO_DA_CLASSE = (r"(?:filter|toggle|protection|token|scanner|scan|rules?|report|settings?|options?"
                      r"|integration|firewall|detection|module|list|notices?|attempts?)")
_CLASSES_TODAS = r"\bxss\b|\bcsrf\b|\bssrf\b|open redirect|\bvulnerabilit(?:y|ies)\b|" + _CLASSE_FORTE_TEXTO
_LISTA_DE_CLASSES = r"(?:(?:" + _CLASSES_TODAS + r")(?:\s*,\s*|\s+(?:and|or)\s+|\s+))+"
_PALAVRA_DE_LIGACAO = (r"(?!(?:in|on|of|for|to|via|from|when|with|through|by|at|and|or|the|a|an"
                       r"|affecting|within|involving|against|inside|into|across|caused|found|reported|that|which|where)\b)\w+\s+")
_CLASSE_RECURSO = re.compile(
    r"(?P<lista>" + _LISTA_DE_CLASSES + r")(?:" + _PALAVRA_DE_LIGACAO + r")?(?P<r>" + _RECURSO_DA_CLASSE + r")\b", re.I)
# O recurso também pode vir ANTES da classe: "Added escaping toggle for XSS", "Setting for CSRF".
_RECURSO_ANTES = re.compile(
    r"\b(?:toggle|setting|option|rule|filter|module|feature|list|notice)s?\s+(?:for|against|of)\s+(?:(?:the|a|an)\s+)?"
    r"(?P<c>(?:" + _CLASSES_TODAS + r")(?:(?:\s*,\s*|\s+(?:and|or)\s+)(?:" + _CLASSES_TODAS + r"))*)", re.I)
_ATAQUE = re.compile(r"\b(bypass\w*|exploit\w*|attack\w*)\b", re.I)
# Linha que ANUNCIA um recurso novo ("Added XSS filter ... to prevent CSRF"): se ela nomeia um recurso de
# segurança, o resto da linha descreve o que o recurso faz, e não uma falha corrigida (revisão do #453).
_ANUNCIA_RECURSO = re.compile(r"^\W*(?:add\w*|new|introduc\w*|improv\w*|enhanc\w*|support\w*)\b", re.I)
# Changelog de terceiros: o custo das expressões de lista é quadrático no tamanho da linha (0,5 s a 5 mil
# caracteres). Acima disto a linha é cortada; só pode perder um positivo (mantém a quarentena).
LIMITE_DE_LINHA = 2000


def _inicios_de_classe(regex: re.Pattern, grupo: str, linha: str) -> set[int]:
    """Posições de TODA classe que aparece dentro do grupo (a lista inteira é o nome do recurso)."""
    inicios: set[int] = set()
    for m in regex.finditer(linha):
        base = m.start(grupo)
        inicios |= {base + c.start() for c in re.finditer(_CLASSES_TODAS, m.group(grupo), re.I)}
    return inicios


def _posicoes_security(regex: re.Pattern, linha: str) -> set[int]:
    return {m.start() + m.group(0).lower().index("security") for m in regex.finditer(linha)
            if "security" in m.group(0).lower()}


class _Seguranca:
    """Mesma interface de um re.Pattern (`.search`), com as regras acima."""

    @staticmethod
    def _ocorrencia_livre(linha: str, classe: re.Pattern) -> bool:
        """Há alguma ocorrência da classe que NÃO seja o nome de um recurso (#437)? Se todas são nome de
        recurso, só vale a linha que fala em bypass, exploit ou attack ("Fixed CSRF token validation
        bypass"): a falha, então, é do próprio recurso. Vale para a classe forte e para a fraca."""
        linha = linha[:LIMITE_DE_LINHA]
        recursos = (_inicios_de_classe(_CLASSE_RECURSO, "lista", linha)
                    | _inicios_de_classe(_RECURSO_ANTES, "c", linha))
        livres = [m for m in classe.finditer(linha) if m.start() not in recursos]
        if recursos:
            # Com um recurso na linha, a palavra genérica "vulnerable/vulnerabilities" não prova uma falha
            # ("Added XSS filter; fixed vulnerable plugin list"), e a linha que anuncia um recurso novo
            # descreve o recurso ("Added CSRF token to prevent CSRF"). Só XSS, CSRF etc. nomeados fora do
            # recurso contam, e nunca numa linha que anuncia (revisão do #453).
            livres = [m for m in livres if not m.group(0).lower().startswith("vulnerab")]
            if _ANUNCIA_RECURSO.match(linha):
                livres = []
        if livres:
            return True
        return bool(recursos) and bool(_ATAQUE.search(linha))

    def search(self, linha: str):
        if m := _CVE.search(linha):
            return m
        if m := _CLASSE_FORTE.search(linha):
            # Classe forte diante de recurso ("SQL injection protection setting") segue a regra
            # da fraca: só conta a ocorrência fora do nome do recurso, ou a linha com bypass/exploit.
            if self._ocorrencia_livre(linha, _CLASSE_FORTE):
                return m
        if (m := _CLASSE_FRACA_RE.search(linha)) and _CORRECAO.search(linha) \
                and self._ocorrencia_livre(linha, _CLASSE_FRACA_RE):
            return m
        recurso = _posicoes_security(_RECURSO, linha)
        for m in _CONTEXTO.finditer(linha):
            texto = m.group(0).lower()
            if "security" not in texto or m.start() + texto.index("security") not in recurso:
                return m
        return None


SEGURANCA = _Seguranca()
# Cabeçalho de versão: "= 1.2.3 =", "#### 1.2.3", "[4.66.2] 25.09.2026",
# "1.2.3 - 2026-01-01", "v.1.2.3", "Version: 1.2.4", "Release 1.2.4".
# A versão não pode ser seguida de letra ("* 1.5x faster loading" não é cabeçalho),
# exceto o sufixo de pré-release colado ("= 1.2.3a =", "= 2.0.0beta =", "## 1.2.3-rc1"),
# que conta como a versão numérica (#425).
CABECALHO = re.compile(r"^[=#*\[\s]*(?:v\.?\s*|version:?\s*|release\s+)?(\d+(?:\.\d+){1,3})(?![\d.]|[a-z])", re.I)
# O sufixo de pré-release só vale em cabeçalho marcado (=, #, [): numa linha comum,
# "* 2.0b improvements" viraria a versão 2.0 (revisão do #436).
CABECALHO_PRE = re.compile(r"^[=#\[\s]*(?:v\.?\s*|version:?\s*|release\s+)?(\d+(?:\.\d+){1,3})"
                           r"-?(?:alpha|beta|rc|a|b)\.?\d*(?![\d.]|[a-z])", re.I)
# Data no formato dd.mm.aaaa não é versão ("25.09.2026 - Fixed XSS"). Só uma data
# plausível: "1.2.1000" é versão de 3 partes (#425).
DATA_COMO_VERSAO = re.compile(r"^(0?[1-9]|[12]\d|3[01])\.(0?[1-9]|1[0-2])\.(19|20)\d{2}$")
DATA_SOLTA = re.compile(r"^\s*\d{1,2}[./-]\d{1,2}[./-]\d{2,4}\s*$")
RISCO = re.compile(r"breaking|\bremoved\b|deprecat|database (update|migration)|requires php|drop(ped)? support", re.I)
ORDEM_CAMADAS = {"acoplada": 0, "comum": 1, "critica": 2, "nucleo": 3}


def versao(v: str) -> tuple[int, ...]:
    return tuple(int(x) for x in re.findall(r"\d+", v or ""))


def maior(v: str, tipo: str = "plugin") -> int | tuple[int, int] | None:
    """O que a política chama de "major". No núcleo é a série x.y (7.1 -> 7.2 muda o banco e traz
    recursos; 7.1.3 -> 7.1.4 é correção). Em plugin e tema, o primeiro número (#448)."""
    partes = versao(v)
    if not partes:
        return None
    return (partes[0], partes[1] if len(partes) > 1 else 0) if tipo == "nucleo" else partes[0]


def chave_api(tipo: str, slug: str) -> str:
    """Chave do item no mapa da API: plugin, tema e núcleo podem ter o mesmo slug, então só o plugin vai sem prefixo."""
    return slug if tipo == "plugin" else f"{tipo}:{slug}"


def _subiu_major(de: str, para: str, tipo: str) -> bool:
    """O destino tem major maior que a origem. Plugin e tema mantêm a regra anterior (versão
    ilegível vale 0); no núcleo, a série x.y, e sem versão legível dos dois lados não há major."""
    a, b = maior(de, tipo), maior(para, tipo)
    if tipo == "nucleo":
        return a is not None and b is not None and b > a
    return (b or 0) > (a or 0)


def buscar_api(slug: str, tipo: str = "plugin") -> dict | None:
    # O núcleo não está em nenhuma das duas APIs: o slug "wordpress" da API de plugins é outro
    # projeto. Sem data de lançamento nem changelog, a camada "nucleo" não tem quarentena (#448).
    if tipo == "nucleo":
        return None
    # A API de temas dá versão e data (a quarentena precisa delas), mas não traz changelog:
    # `sections` só tem a descrição, então o tema segue sem changelog nem novidades (#445).
    if tipo == "tema":
        endpoint, acao = API_TEMAS, "theme_information"
    else:
        endpoint, acao = API, "plugin_information"
    query = urllib.parse.urlencode({
        "action": acao, "request[slug]": slug,
        "request[fields][sections]": 1, "request[fields][versions]": 0,
    })
    try:
        with urllib.request.urlopen(f"{endpoint}?{query}", timeout=30) as resposta:
            dados = json.load(resposta)
    except (OSError, ValueError):
        return None
    return None if not isinstance(dados, dict) or "error" in dados else dados


def data_lancamento(info: dict | None, para: str) -> dt.date | None:
    """Data da versão `para`, se ela é a versão atual publicada na API."""
    if not info or info.get("version") != para:
        return None
    m = re.match(r"(\d{4})-(\d{2})-(\d{2})", str(info.get("last_updated", "")))
    return dt.date(int(m[1]), int(m[2]), int(m[3])) if m else None


# Tags que separam itens do changelog. As demais (<code>, <a>, <strong>…) ficam dentro
# da frase do item.
_BLOCOS = re.compile(r"</?(?:li|p|h[1-6]|ul|ol|div|br|tr|dt|dd|blockquote)\b[^>]*>", re.I)


def _linhas_changelog(bruto: str, por_item: bool) -> list[str]:
    """Uma linha por tag (como a detecção de segurança sempre leu) ou, com `por_item`, uma
    linha por item do changelog: "Add the <code>x</code> feature flag. See <a>#1</a>." deixa
    de virar quatro fragmentos soltos ("Add the", "x", "feature flag. See", "#1")."""
    if not por_item:
        # Caminho da decisão de segurança: idêntico ao que sempre foi, sem normalizar nada.
        texto = re.sub(r"<[^>]+>", "\n", bruto)
        return [l.strip() for l in html.unescape(texto).splitlines() if l.strip()]
    texto = re.sub(r"<[^>]+>", "", _BLOCOS.sub("\n", bruto))
    return [re.sub(r"\s+", " ", l).strip() for l in html.unescape(texto).splitlines() if l.strip()]


def trecho_changelog(info: dict | None, de: str, para: str, so_para: bool = False,
                     por_item: bool = False) -> list[str] | None:
    """Linhas do changelog entre `de` (exclusive) e `para` (inclusive); com `so_para`,
    só as da versão `para` (as novidades que o relatório mostra ao cliente). Com `por_item`,
    cada item é uma linha só; a decisão de segurança segue com o corte por tag."""
    if not info:
        return None
    bruto = info.get("sections", {}).get("changelog", "")
    linhas = _linhas_changelog(bruto, por_item)
    trecho, dentro, achou = [], False, False
    for linha in linhas:
        # Linha que é cabeçalho marcado (=, #, [) pode ser longa (data e título);
        # linha comum só conta se for curta, para não confundir "1.2 compat" no meio.
        marcado = linha.lstrip()[:1] in {"=", "#", "["}
        m = None if DATA_SOLTA.match(linha) else (CABECALHO.match(linha) or
                                                  (CABECALHO_PRE.match(linha) if marcado else None))
        if m and DATA_COMO_VERSAO.match(m.group(1)):
            m = None
        if m and (marcado or len(linha) < 60):
            dentro = (versao(m.group(1)) == versao(para)) if so_para else (versao(de) < versao(m.group(1)) <= versao(para))
            achou = achou or dentro
            continue
        if dentro:
            trecho.append(linha)
    return trecho if achou else None


def planejar(inventario: dict, politica: dict, api: dict, hoje: dt.date,
             aceitar_major: set[str], dispensar: set[str]) -> dict:
    itens, fora = [], []
    camadas = politica["camadas"]
    for tipo, chave in (("plugin", "plugins"), ("tema", "temas"), ("nucleo", "nucleo")):
        for item in inventario.get(chave, []):
            nome = item.get("name", "")
            status_update = item.get("update")
            if status_update not in {"available", "unavailable"}:
                continue
            de, para = item.get("version", ""), item.get("update_version", "")
            base = {"slug": nome, "tipo": tipo, "de": de, "para": para}
            regra = politica.get(chave, {}).get(nome)
            if regra is None:
                fora.append({**base, "motivo": "sem classificação na política"})
                continue
            camada = regra["camada"]
            base["camada"] = camada
            if camadas[camada]["aplicacao"] == "nunca":
                fora.append({**base, "motivo": f"camada {camada} não é atualizada pela esteira"})
                continue
            if status_update == "unavailable":
                fora.append({**base, "motivo": "versão nova exige PHP ou WordPress acima do site"})
                continue

            info = api.get(chave_api(tipo, nome))
            trecho = trecho_changelog(info, de, para)
            seguranca = None if trecho is None else any(SEGURANCA.search(l) for l in trecho)
            base["seguranca"] = seguranca
            base["changelog"] = ("indisponível" if info is None else
                                 "sem as versões do intervalo" if trecho is None else
                                 [l for l in trecho if SEGURANCA.search(l) or RISCO.search(l)][:8])
            # O changelog acima é o filtro de segurança e risco do intervalo, para a
            # decisão; as novidades são o trecho inteiro só da versão nova, para o cliente.
            base["novidades"] = trecho_changelog(info, de, para, so_para=True, por_item=True) if info else None

            if _subiu_major(de, para, tipo) and camadas[camada]["major"] == "bloqueia" \
                    and nome not in aceitar_major:
                fora.append({**base, "motivo": f"major na camada {camada}: exige --aceitar-major={nome}"})
                continue

            dias = camadas[camada]["quarentena_dias"]
            lancado = data_lancamento(info, para)
            base["lancado_em"] = lancado.isoformat() if lancado else None
            if dias and nome not in dispensar and not seguranca:
                if lancado is None:
                    fora.append({**base, "motivo": f"quarentena de {dias} dias não verificável (data de lançamento desconhecida)"})
                    continue
                idade = (hoje - lancado).days
                if idade < dias:
                    fora.append({**base, "motivo": f"quarentena: lançada há {idade} de {dias} dias (libera em {(lancado + dt.timedelta(days=dias)).isoformat()})"})
                    continue
            base["quarentena"] = ("dispensada (segurança)" if dias and seguranca else
                                  "dispensada (manual)" if dias and nome in dispensar else
                                  "cumprida" if dias else "não se aplica")
            base["aplicacao"] = camadas[camada]["aplicacao"]
            itens.append(base)

    itens.sort(key=lambda i: (ORDEM_CAMADAS.get(i["camada"], 9), i["slug"] == "woocommerce", i["slug"]))
    return {"gerado_em": hoje.isoformat(), "itens": itens, "fora": fora}


def lista(valor: str | None) -> set[str]:
    return {v.strip() for v in (valor or "").split(",") if v.strip()}


def main(argv: list[str]) -> int:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--inventario", required=True, type=pathlib.Path)
    p.add_argument("--politica", type=pathlib.Path, default=POLITICA_PADRAO)
    p.add_argument("--api-cache", type=pathlib.Path)
    p.add_argument("--hoje")
    p.add_argument("--aceitar-major")
    p.add_argument("--dispensar-quarentena")
    p.add_argument("--saida", type=pathlib.Path)
    a = p.parse_args(argv)
    try:
        inventario = json.loads(a.inventario.read_text())
        politica = json.loads(a.politica.read_text())
        hoje = dt.date.fromisoformat(a.hoje) if a.hoje else dt.date.today()
    except (OSError, ValueError) as erro:
        print(f"ERRO: entrada ilegível: {erro}")
        return 2
    pendentes = [(tipo, i.get("name")) for tipo, c in (("plugin", "plugins"), ("tema", "temas"), ("nucleo", "nucleo"))
                 for i in inventario.get(c, []) if i.get("update") in {"available", "unavailable"}]
    if a.api_cache:
        api = json.loads(a.api_cache.read_text())
    else:
        api = {chave_api(tipo, slug): buscar_api(slug, tipo) for tipo, slug in pendentes}
    plano = planejar(inventario, politica, api, hoje, lista(a.aceitar_major), lista(a.dispensar_quarentena))
    texto = json.dumps(plano, ensure_ascii=False, indent=1)
    if a.saida:
        a.saida.write_text(texto + "\n")
    print(f"Plano: {len(plano['itens'])} no ensaio, {len(plano['fora'])} fora")
    for i in plano["itens"]:
        print(f"  + {i['camada']:9} {i['slug']} {i['de']} -> {i['para']}  quarentena: {i['quarentena']}"
              f"{'  [SEGURANÇA]' if i.get('seguranca') else ''}")
    for f in plano["fora"]:
        print(f"  - {f.get('camada', '?'):9} {f['slug']} {f['de']} -> {f['para']}  {f['motivo']}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
