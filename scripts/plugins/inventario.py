#!/usr/bin/env python3
"""Classifica o inventário de plugins e temas de produção pela política de
ops/plugins/politica.json e imprime o que há para atualizar, por camada.

Entrada: JSON com {"plugins": [...], "temas": [...]}, cada lista no formato de
`wp plugin list --format=json` / `wp theme list --format=json` com os campos
name, status, version, update, update_version e auto_update.

Bandeiras, que levam a saída 10:
- versão major pendente em plugin da camada crítica (a política bloqueia);
- plugin ou tema instalado sem classificação na política;
- atualização automática ligada (a esteira deve ser a única via de mudança).

Plugin presente na política e ausente de produção é listado, sem bandeira.

Uso: inventario.py --entrada ARQ [--politica ARQ]
Saída: 0 sem bandeira, 10 com bandeira, 2 em erro de entrada.
"""
from __future__ import annotations

import argparse
import json
import pathlib
import re
import sys

RAIZ = pathlib.Path(__file__).resolve().parents[2]
POLITICA_PADRAO = RAIZ / "ops" / "plugins" / "politica.json"
IGNORADOS = {"must-use", "dropin"}
ORDEM = ["critica", "acoplada", "comum", "decisao_pendente", "propria"]


def maior(versao: str) -> int | None:
    m = re.match(r"\s*v?(\d+)", versao or "")
    return int(m.group(1)) if m else None


def relatorio(inventario: dict, politica: dict) -> tuple[list[str], int]:
    linhas: list[str] = []
    bandeiras: list[str] = []
    camadas = politica["camadas"]
    por_camada: dict[str, list[str]] = {c: [] for c in ORDEM}
    instalados: set[tuple[str, str]] = set()

    for tipo, chave in (("plugin", "plugins"), ("tema", "temas")):
        classificados = politica.get(chave, {})
        for item in inventario.get(chave, []):
            nome, status = item.get("name", ""), item.get("status", "")
            if status in IGNORADOS:
                continue
            instalados.add((chave, nome))
            regra = classificados.get(nome)
            if str(item.get("auto_update", "off")).lower() in {"on", "enabled", "true", "1"}:
                bandeiras.append(f"atualização automática LIGADA: {tipo} {nome}")
            if regra is None:
                bandeiras.append(f"sem classificação na política: {tipo} {nome} ({status} {item.get('version', '')})")
                continue
            if item.get("update") not in {"available", "true", True}:
                continue
            de, para = item.get("version", ""), item.get("update_version", "")
            camada = regra["camada"]
            nota = ""
            if maior(de) is not None and maior(para) is not None and maior(para) > maior(de):
                nota = " **MAJOR**"
                if camadas[camada]["major"] == "bloqueia":
                    bandeiras.append(f"major em camada {camada}: {nome} {de} -> {para} (exige decisão)")
            if camadas[camada]["aplicacao"] == "nunca":
                nota += " (não atualizar pela esteira)"
            por_camada[camada].append(f"- {tipo} `{nome}` {de} → {para}{nota}")

    for camada in ORDEM:
        regra = camadas[camada]
        itens = por_camada[camada]
        linhas.append(f"## {camada} — {len(itens)} pendente(s)")
        linhas.append(f"_aplicação: {regra['aplicacao']}; quarentena: {regra['quarentena_dias']} dia(s); major: {regra['major']}_")
        linhas.extend(itens or ["- nada pendente"])
        linhas.append("")

    ausentes = [f"{chave[:-1]} {nome}" for chave in ("plugins", "temas")
                for nome in politica.get(chave, {}) if (chave, nome) not in instalados]
    if ausentes:
        linhas.append("## Na política e ausentes da produção")
        linhas.extend(f"- {a}" for a in sorted(ausentes))
        linhas.append("")

    linhas.append(f"## Bandeiras — {len(bandeiras)}")
    linhas.extend(f"- {b}" for b in bandeiras)
    if not bandeiras:
        linhas.append("- nenhuma")
    return linhas, (10 if bandeiras else 0)


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--entrada", required=True, type=pathlib.Path)
    parser.add_argument("--politica", type=pathlib.Path, default=POLITICA_PADRAO)
    args = parser.parse_args(argv)
    try:
        inventario = json.loads(args.entrada.read_text())
        politica = json.loads(args.politica.read_text())
    except (OSError, ValueError) as erro:
        print(f"ERRO: entrada ou política ilegível: {erro}")
        return 2
    if not isinstance(inventario.get("plugins"), list) or not inventario["plugins"]:
        print("ERRO: inventário sem lista de plugins; a leitura de produção falhou?")
        return 2
    linhas, codigo = relatorio(inventario, politica)
    print("\n".join(linhas))
    return codigo


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
