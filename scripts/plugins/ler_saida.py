#!/usr/bin/env python3
"""Separa a saída da leitura de produção do atualizar.sh em JSON de plugins e
temas, pelos marcadores @@PLUGINS, @@TEMAS e @@FIM (#393, #413).

O shell remoto pode imprimir ruído antes e depois dos marcadores (banner,
avisos do profile); só o que está entre eles conta. Qualquer falha (marcador
ausente, JSON inválido, lista de plugins vazia) grava a saída bruta em
<destino>.bruto.txt para diagnóstico e sai com 1. Nunca produz um inventário
vazio com sucesso.

Uso: ler_saida.py DESTINO.json < saida-bruta
"""
from __future__ import annotations

import json
import pathlib
import sys


class SaidaInvalida(ValueError):
    pass


def separar(texto: str) -> dict:
    partes: dict[str, list[str]] = {}
    atual = None
    for linha in texto.splitlines():
        if linha.startswith("@@"):
            atual = linha[2:].strip()
            partes[atual] = []
        elif atual:
            partes[atual].append(linha)
    for marcador in ("PLUGINS", "TEMAS", "FIM"):
        if marcador not in partes:
            raise SaidaInvalida(f"marcador @@{marcador} ausente")
    try:
        dados = {"plugins": json.loads("\n".join(partes["PLUGINS"])),
                 "temas": json.loads("\n".join(partes["TEMAS"]))}
    except ValueError as erro:
        raise SaidaInvalida(f"JSON inválido: {erro}") from erro
    if not isinstance(dados["plugins"], list) or not dados["plugins"]:
        raise SaidaInvalida("lista de plugins vazia")
    if not isinstance(dados["temas"], list):
        raise SaidaInvalida("lista de temas inválida")
    return dados


def main(argv: list[str]) -> int:
    if len(argv) != 1:
        print(__doc__, file=sys.stderr)
        return 2
    destino = pathlib.Path(argv[0])
    texto = sys.stdin.read()
    try:
        dados = separar(texto)
    except SaidaInvalida as erro:
        bruto = destino.with_name(destino.name + ".bruto.txt")
        bruto.write_text(texto)
        print(f"Erro: saída de produção inválida ({erro}). Saída bruta em {bruto}", file=sys.stderr)
        return 1
    destino.write_text(json.dumps(dados, ensure_ascii=False, indent=1) + "\n")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
