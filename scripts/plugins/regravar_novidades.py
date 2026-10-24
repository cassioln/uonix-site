#!/usr/bin/env python3
"""Regrava o campo "novidades" de um plano.json com o corte por item (ver plano.trecho_changelog).

Planos gravados antes do corte por item guardam as novidades em fragmentos. Este script lê o
changelog de cada plugin do plano no wordpress.org e refaz só esse campo; o original fica ao lado,
em plano.antes-por-item.json. Não muda a decisão de segurança nem o resto do plano.

Uso: regravar_novidades.py PASTA_DO_ENSAIO
"""
from __future__ import annotations

import json
import pathlib
import shutil
import sys
import urllib.request

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
import plano  # noqa: E402


def main(argv: list[str]) -> int:
    if len(argv) != 1:
        print(__doc__)
        return 2
    pasta = pathlib.Path(argv[0])
    arquivo = pasta / "plano.json"
    copia = pasta / "plano.antes-por-item.json"
    if not copia.exists():
        shutil.copy2(arquivo, copia)
    dados = json.loads(copia.read_text())
    for item in dados["itens"]:
        url = ("https://api.wordpress.org/plugins/info/1.2/?action=plugin_information"
               f"&request[slug]={item['slug']}&request[fields][sections]=1")
        with urllib.request.urlopen(url, timeout=30) as r:  # noqa: S310 - URL fixa do wordpress.org
            info = json.load(r)
        item["novidades"] = plano.trecho_changelog(info, item["de"], item["para"], so_para=True, por_item=True)
        print(f"{item['slug']} {item['para']}: {len(item['novidades'] or [])} item(ns)")
    arquivo.write_text(json.dumps(dados, ensure_ascii=False, indent=1) + "\n")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
