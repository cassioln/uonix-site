#!/usr/bin/env python3
"""Separa a saída da leitura de produção do atualizar.sh em JSON de plugins e
temas, pelos marcadores @@PLUGINS, @@TEMAS e @@FIM (#393, #413). O núcleo do WordPress
vem em @@NUCLEO_VERSAO (a versão) e @@NUCLEO_ATUALIZACOES (o JSON de `wp core
check-update`), ambos opcionais (#448): sem a versão, o inventário não tem "nucleo".

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
import re
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
    nucleo = _nucleo(partes)
    if nucleo:
        dados["nucleo"] = [nucleo]
    return dados


def _nucleo(partes: dict[str, list[str]]) -> dict | None:
    """Item do núcleo no mesmo formato de `wp plugin list`, ou None se a leitura não o trouxe.

    Das ofertas, vale a mais nova da MESMA série x.y da instalada (7.1.3 -> 7.1.5); só se não
    houver nenhuma, a mais nova de todas (7.2), que a política bloqueia. A série é calculada
    aqui, e não lida do `update_type` do WP-CLI, cujo vocabulário não foi medido."""
    versao = "\n".join(partes.get("NUCLEO_VERSAO", [])).strip()
    if not versao:
        return None
    if not re.fullmatch(r"\d+(?:\.\d+){1,3}", versao):
        raise SaidaInvalida(f"versão do núcleo inválida: {versao!r}")
    texto = "\n".join(partes.get("NUCLEO_ATUALIZACOES", [])).strip()
    try:
        ofertas = json.loads(texto) if texto else []
    except ValueError as erro:
        raise SaidaInvalida(f"JSON das atualizações do núcleo inválido: {erro}") from erro
    if not isinstance(ofertas, list):
        raise SaidaInvalida("atualizações do núcleo inválidas")

    def chave(o: dict) -> tuple[int, ...]:
        return tuple(int(x) for x in re.findall(r"\d+", str(o.get("version", ""))))

    validas = [o for o in ofertas if isinstance(o, dict) and re.fullmatch(r"\d+(?:\.\d+){1,3}", str(o.get("version", "")))
               and chave(o) > chave({"version": versao})]
    serie = chave({"version": versao})[:2]
    da_serie = [o for o in validas if chave(o)[:2] == serie]
    escolhida = max(da_serie or validas, key=chave, default=None)
    return {"name": "wordpress", "status": "active", "version": versao,
            "update": "available" if escolhida else "none",
            "update_version": str(escolhida["version"]) if escolhida else "", "auto_update": "off"}


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
