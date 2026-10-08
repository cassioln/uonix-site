#!/usr/bin/env python3
"""Smoke SOMENTE LEITURA de produção, para depois de uma aplicação (#393).

Só faz GET: páginas principais, marcadores da home (os mesmos do ensaio local,
MARCADORES_HOME), Store API de produtos e sitemap. Não adiciona ao carrinho nem
envia formulário, porque isso criaria sessão, lead ou e-mail reais. Esses fluxos
são provados no ensaio local, com o mesmo banco e as mesmas versões.

Uso: smoke_producao.py [--url https://uonix.com.br] [--saida ARQ.json]
Saída: 0 tudo verde, 20 algum check vermelho.
"""
from __future__ import annotations

import argparse
import json
import pathlib
import random
import re
import sys
import urllib.error
import urllib.request

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from ensaio import MARCADORES_HOME  # noqa: E402

PAGINAS = ("/", "/blog/", "/servicos/", "/produtos/", "/cotacao/", "/sitemap_index.xml", "/wp-json/", "/wp-login.php")


def http_get(url: str) -> tuple[int, str]:
    req = urllib.request.Request(url, headers={"User-Agent": "uonix-smoke/1.0"})
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            return r.status, r.read().decode(errors="ignore")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode(errors="ignore")
    except (urllib.error.URLError, OSError) as e:
        return 0, str(e)


def smoke(base: str, get=http_get, extras: tuple[str, ...] = ()) -> dict:
    def pedir(caminho: str) -> tuple[int, str]:
        sep = "&" if "?" in caminho else "?"
        return get(f"{base}{caminho}{sep}uonix_smoke={random.randint(1, 10**9)}")

    r: dict[str, dict] = {}
    for caminho in PAGINAS + tuple(extras):
        status, corpo = pedir(caminho)
        erro = bool(re.search(r"critical error|Fatal error", corpo, re.I))
        r[f"pagina {caminho}"] = {"ok": status == 200 and not erro, "detalhe": f"http {status}{' com erro fatal' if erro else ''}"}
    _, home = pedir("/")
    for nome, padrao in MARCADORES_HOME:
        n = len(re.findall(padrao, home))
        r[f"home {nome}"] = {"ok": n > 0, "detalhe": f"{n} ocorrência(s)"}
    status, corpo = pedir("/wp-json/wc/store/v1/products?per_page=1")
    try:
        n = len(json.loads(corpo))
    except ValueError:
        n = 0
    r["Store API: produtos"] = {"ok": status == 200 and n >= 1, "detalhe": f"http {status}, {n} item(ns)"}
    _, mapa = pedir("/sitemap_index.xml")
    n = mapa.count("<sitemap>")
    r["sitemap: sub-sitemaps"] = {"ok": n > 0, "detalhe": str(n)}
    return r


def main(argv: list[str]) -> int:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--url", default="https://uonix.com.br")
    p.add_argument("--saida", type=pathlib.Path)
    p.add_argument("--extra", action="append", default=[], help="caminho adicional a conferir")
    a = p.parse_args(argv)
    resultado = smoke(a.url.rstrip("/"), extras=tuple(a.extra))
    for nome, res in resultado.items():
        print(f"{'ok' if res['ok'] else 'XX'}  {nome:45} {res['detalhe']}")
    vermelhos = [n for n, res in resultado.items() if not res["ok"]]
    print(f"{len(resultado) - len(vermelhos)} de {len(resultado)} verdes")
    if a.saida:
        a.saida.write_text(json.dumps(resultado, ensure_ascii=False, indent=1) + "\n")
    return 20 if vermelhos else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
