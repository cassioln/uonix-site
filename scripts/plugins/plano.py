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
para um JSON {slug: resposta da API | null} e nada é buscado na rede.

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
# Correção de segurança exige CONTEXTO, não a palavra solta: "Compatibility with
# Wordfence Security", "Added Security headers settings page" e "New permission
# checks screen" dispensariam a quarentena de um crítico por engano (#418).
# Um falso negativo só mantém a quarentena (conservador); um falso positivo a
# dispensa. Por isso a lista é de construções de correção e de classes de
# vulnerabilidade, e não de palavras.
SEGURANCA = re.compile(
    r"^\W*security\b[^:\n]{0,20}:"                                   # "Security:", "* Security fix:"
    r"|\bsecurity (fix|fixes|issue|issues|patch|vulnerabilit\w*|hardening|release|update)\b"
    r"|\b(improv|harden|strengthen|enhanc|tighten)\w* (the |plugin |overall )?security\b"
    r"|\bharden(s|ed|ing)\b"                                          # "Hardens input sanitization..."
    r"|\bcve-\d{4}|\bxss\b|cross[- ]site (scripting|request forgery)|\bcsrf\b|\bssrf\b"
    r"|vulnerab|privilege escalation|sql injection|object injection|open redirect"
    r"|arbitrary file (upload|deletion|download|read)|\bunauthenticated\b"
    r"|broken access control|(missing|insufficient|improper) (authori[sz]ation|capability|permission|nonce|access)( check)?"
    r"|permission checks? (across|for|on|in|when)\b|output escaping|\bunescaped html\b",
    re.I | re.M)
# Cabeçalho de versão: "= 1.2.3 =", "#### 1.2.3", "[4.66.2] 25.09.2026",
# "1.2.3 - 2026-01-01", "v.1.2.3", "Version: 1.2.4", "Release 1.2.4".
# A versão não pode ser seguida de letra ("* 1.5x faster loading" não é cabeçalho).
CABECALHO = re.compile(r"^[=#*\[\s]*(?:v\.?\s*|version:?\s*|release\s+)?(\d+(?:\.\d+){1,3})(?![\d.]|[a-z])", re.I)
# Data no formato dd.mm.aaaa não é versão ("25.09.2026 - Fixed XSS").
DATA_COMO_VERSAO = re.compile(r"^\d{1,2}\.\d{1,2}\.\d{4}$")
DATA_SOLTA = re.compile(r"^\s*\d{1,2}[./-]\d{1,2}[./-]\d{2,4}\s*$")
RISCO = re.compile(r"breaking|\bremoved\b|deprecat|database (update|migration)|requires php|drop(ped)? support", re.I)
ORDEM_CAMADAS = {"acoplada": 0, "comum": 1, "critica": 2}


def versao(v: str) -> tuple[int, ...]:
    return tuple(int(x) for x in re.findall(r"\d+", v or ""))


def maior(v: str) -> int | None:
    partes = versao(v)
    return partes[0] if partes else None


def buscar_api(slug: str) -> dict | None:
    query = urllib.parse.urlencode({
        "action": "plugin_information", "request[slug]": slug,
        "request[fields][sections]": 1, "request[fields][versions]": 0,
    })
    try:
        with urllib.request.urlopen(f"{API}?{query}", timeout=30) as resposta:
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


def trecho_changelog(info: dict | None, de: str, para: str) -> list[str] | None:
    """Linhas do changelog entre `de` (exclusive) e `para` (inclusive)."""
    if not info:
        return None
    bruto = info.get("sections", {}).get("changelog", "")
    linhas = [l.strip() for l in html.unescape(re.sub(r"<[^>]+>", "\n", bruto)).splitlines() if l.strip()]
    trecho, dentro, achou = [], False, False
    for linha in linhas:
        m = None if DATA_SOLTA.match(linha) else CABECALHO.match(linha)
        if m and DATA_COMO_VERSAO.match(m.group(1)):
            m = None
        # Linha que é cabeçalho marcado (=, #, [) pode ser longa (data e título);
        # linha comum só conta se for curta, para não confundir "1.2 compat" no meio.
        marcado = linha.lstrip()[:1] in {"=", "#", "["}
        if m and (marcado or len(linha) < 60):
            dentro = versao(de) < versao(m.group(1)) <= versao(para)
            achou = achou or dentro
            continue
        if dentro:
            trecho.append(linha)
    return trecho if achou else None


def planejar(inventario: dict, politica: dict, api: dict, hoje: dt.date,
             aceitar_major: set[str], dispensar: set[str]) -> dict:
    itens, fora = [], []
    camadas = politica["camadas"]
    for tipo, chave in (("plugin", "plugins"), ("tema", "temas")):
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

            info = api.get(nome)
            trecho = trecho_changelog(info, de, para)
            seguranca = None if trecho is None else any(SEGURANCA.search(l) for l in trecho)
            base["seguranca"] = seguranca
            base["changelog"] = ("indisponível" if info is None else
                                 "sem as versões do intervalo" if trecho is None else
                                 [l for l in trecho if SEGURANCA.search(l) or RISCO.search(l)][:8])

            if (maior(para) or 0) > (maior(de) or 0) and camadas[camada]["major"] == "bloqueia" \
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
    pendentes = [i.get("name") for c in ("plugins", "temas") for i in inventario.get(c, [])
                 if i.get("update") in {"available", "unavailable"}]
    if a.api_cache:
        api = json.loads(a.api_cache.read_text())
    else:
        api = {slug: buscar_api(slug) for slug in pendentes}
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
