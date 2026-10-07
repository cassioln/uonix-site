#!/usr/bin/env python3
"""Verifica, no código-fonte de plugins e temas instalados, os contratos de
ops/plugins/contratos.json: o que mu-plugins e tema filho usam de cada um.

A checagem é estática e não carrega o WordPress. Ela cobre o que se prova lendo
o fonte do plugin:

- funcoes: `function nome(` declarada;
- classes: `class Nome` declarada, no namespace indicado quando houver;
- hooks: o plugin ainda dispara o hook com nome literal (do_action/apply_filters).
  Com `wrapper_hooks` no contrato, também vale o disparo pelo método próprio do
  plugin sem o prefixo: o Rank Math dispara `rank_math/json_ld` como
  `$this->do_filter( 'json_ld' )`;
- shortcodes: `add_shortcode( 'nome'` registrado, ou `addShortCode`, do
  framework do Fluent Forms;
- marcadores: texto que precisa continuar no fonte (classe CSS, nome de opção,
  ou o trecho que monta o nome de um hook dinâmico).

`post_types`, `rotas_rest` e `hooks_dinamicos` não se provam pelo fonte. Eles
ficam no contrato para a checagem em execução do ensaio local (#393).

Uso:
  contratos.py verificar --plugins-dir DIR [--temas-dir DIR] [--contratos ARQ] [SLUG ...]

Sai com 0 quando tudo está presente, 20 quando algum símbolo sumiu e 2 em erro
de uso ou de arquivo.
"""
from __future__ import annotations

import argparse
import json
import pathlib
import re
import sys

RAIZ = pathlib.Path(__file__).resolve().parents[2]
CONTRATOS_PADRAO = RAIZ / "ops" / "plugins" / "contratos.json"
EXTENSOES = {".php", ".js", ".json", ".inc"}
CAMPOS_ESTATICOS = ("funcoes", "classes", "hooks", "shortcodes", "marcadores")
DISPARO = r"(?:do_action|do_action_ref_array|do_action_deprecated|apply_filters|apply_filters_ref_array|apply_filters_deprecated)"


def ler_fontes(diretorio: pathlib.Path) -> list[tuple[str, str]]:
    fontes = []
    for arquivo in sorted(diretorio.rglob("*")):
        if arquivo.is_file() and arquivo.suffix in EXTENSOES:
            try:
                fontes.append((str(arquivo.relative_to(diretorio)), arquivo.read_text(errors="ignore")))
            except OSError:
                continue
    return fontes


def presente(campo: str, nome: str, fontes: list[tuple[str, str]], wrapper: dict | None = None) -> bool:
    if campo == "funcoes":
        padrao = re.compile(r"\bfunction\s+&?" + re.escape(nome) + r"\s*\(")
        return any(padrao.search(texto) for _, texto in fontes)
    if campo == "classes":
        partes = nome.strip("\\").split("\\")
        classe, namespace = partes[-1], "\\".join(partes[:-1])
        decl = re.compile(r"^\s*(?:(?:abstract|final|readonly)\s+)*(?:class|interface|trait|enum)\s+" + re.escape(classe) + r"\b", re.M)
        ns = re.compile(r"^\s*namespace\s+" + re.escape(namespace) + r"\s*[;{]", re.M) if namespace else None
        return any(decl.search(t) and (ns is None or ns.search(t)) for _, t in fontes)
    if campo == "hooks":
        padroes = [re.compile(DISPARO + r"\(\s*['\"]" + re.escape(nome) + r"['\"]")]
        prefixo = (wrapper or {}).get("prefixo", "")
        if prefixo and nome.startswith(prefixo):
            metodos = "|".join(re.escape(m) for m in wrapper.get("metodos", []))
            padroes.append(re.compile(r"->(?:" + metodos + r")\(\s*['\"]" + re.escape(nome[len(prefixo):]) + r"['\"]"))
        return any(p.search(texto) for p in padroes for _, texto in fontes)
    if campo == "shortcodes":
        padrao = re.compile(r"(?:add_shortcode|addShortCode|addShortcode)\(\s*['\"]" + re.escape(nome) + r"['\"]")
        return any(padrao.search(texto) for _, texto in fontes)
    if campo == "marcadores":
        return any(nome in texto for _, texto in fontes)
    raise ValueError(campo)


def verificar(contratos: dict, plugins_dir: pathlib.Path, temas_dir: pathlib.Path | None, slugs: list[str]) -> int:
    alvos = slugs or sorted(contratos)
    faltas = 0
    for slug in alvos:
        contrato = contratos.get(slug)
        if contrato is None:
            print(f"ERRO: {slug} não tem contrato em contratos.json")
            faltas += 1
            continue
        base = (temas_dir if contrato.get("tipo") == "tema" else plugins_dir)
        if base is None:
            print(f"ERRO: {slug} é tema, informe --temas-dir")
            faltas += 1
            continue
        diretorio = base / slug
        if not diretorio.is_dir():
            print(f"ERRO: {slug} não está instalado em {base}")
            faltas += 1
            continue
        fontes = ler_fontes(diretorio)
        ausentes = [(campo, nome) for campo in CAMPOS_ESTATICOS
                    for nome in contrato.get(campo, [])
                    if not presente(campo, nome, fontes, contrato.get("wrapper_hooks"))]
        total = sum(len(contrato.get(c, [])) for c in CAMPOS_ESTATICOS)
        if ausentes:
            faltas += len(ausentes)
            print(f"FALHA {slug}: {len(ausentes)} de {total} itens ausentes")
            usado_em = ", ".join(contrato.get("usado_em", [])) or "?"
            for campo, nome in ausentes:
                print(f"  - {campo}: {nome}  (usado em: {usado_em})")
        elif total == 0:
            print(f"--    {slug}: sem item estático; o contrato só se prova em execução")
        else:
            print(f"ok    {slug}: {total} itens presentes")
    return 20 if faltas else 0


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = parser.add_subparsers(dest="comando", required=True)
    v = sub.add_parser("verificar")
    v.add_argument("--plugins-dir", required=True, type=pathlib.Path)
    v.add_argument("--temas-dir", type=pathlib.Path)
    v.add_argument("--contratos", type=pathlib.Path, default=CONTRATOS_PADRAO)
    v.add_argument("slugs", nargs="*")
    args = parser.parse_args(argv)
    try:
        contratos = json.loads(args.contratos.read_text())["contratos"]
    except (OSError, ValueError, KeyError) as erro:
        print(f"ERRO: não foi possível ler {args.contratos}: {erro}")
        return 2
    if not args.plugins_dir.is_dir():
        print(f"ERRO: diretório de plugins inexistente: {args.plugins_dir}")
        return 2
    return verificar(contratos, args.plugins_dir, args.temas_dir, args.slugs)


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
