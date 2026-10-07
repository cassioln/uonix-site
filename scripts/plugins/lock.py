#!/usr/bin/env python3
"""Valida um lock de ensaio antes de aplicá-lo em produção (#393, entrega 3).

Recusa o lock quando:
- o sha256 não confere (lock editado depois do ensaio);
- o resultado não é "verde";
- ele é mais velho que --max-horas (padrão 72): as versões de origem podem ter
  mudado em produção. O preflight remoto também barra drift, mas um lock velho
  é sinal de que o ensaio deve ser refeito;
- algum item tem código que não carrega no local (EXCECOES_ATIVACAO do ensaio):
  ele nunca foi exercitado e não pode ir para produção por esta via;
- algum slug é inválido (vazio, com "/", "." ou "..").

Saída padrão, quando válido: a 1ª linha é o sha256 curto (12 caracteres, usado
na frase de confirmação); as seguintes são "slug de para camada", na ordem do
lock, com a camada crítica marcada como "critica".

Uso: lock.py validar --lock ARQ [--max-horas N] [--agora AAAA-MM-DDTHH:MM:SS]
Saída: 0 válido, 2 inválido.
"""
from __future__ import annotations

import argparse
import datetime as dt
import hashlib
import json
import pathlib
import re
import sys

sys.path.insert(0, str(pathlib.Path(__file__).resolve().parent))
from ensaio import EXCECOES_ATIVACAO  # noqa: E402

SLUG = re.compile(r"^[a-z0-9][a-z0-9._-]*$")


class LockInvalido(ValueError):
    pass


def validar(lock: dict, agora: dt.datetime, max_horas: float) -> tuple[str, list[str]]:
    dados = dict(lock)
    sha = dados.pop("sha256", None)
    if not sha:
        raise LockInvalido("lock sem sha256")
    if hashlib.sha256(json.dumps(dados, ensure_ascii=False, indent=1).encode()).hexdigest() != sha:
        raise LockInvalido("sha256 não confere: o lock foi alterado depois do ensaio")
    if dados.get("resultado") != "verde":
        raise LockInvalido(f"resultado do ensaio é {dados.get('resultado')!r}, não 'verde'")
    try:
        gerado = dt.datetime.fromisoformat(dados["gerado_em"])
    except (KeyError, ValueError):
        raise LockInvalido("lock sem gerado_em válido")
    idade = (agora - gerado).total_seconds() / 3600
    if idade > max_horas:
        raise LockInvalido(f"lock tem {idade:.0f} h (máximo {max_horas:.0f} h); refaça o ensaio")
    itens = dados.get("itens") or []
    if not itens:
        raise LockInvalido("lock sem itens")
    linhas = []
    for i in itens:
        slug = i.get("slug", "")
        if not SLUG.match(slug) or slug in {".", ".."}:
            raise LockInvalido(f"slug inválido no lock: {slug!r}")
        if i.get("tipo") != "plugin":
            raise LockInvalido(f"{slug}: só plugins são aplicados por esta via (tipo {i.get('tipo')!r})")
        if slug in EXCECOES_ATIVACAO:
            raise LockInvalido(f"{slug}: o código não carrega no ensaio local; não pode ser aplicado por esta via")
        if not i.get("de") or not i.get("para"):
            raise LockInvalido(f"{slug}: versão de origem ou destino ausente")
        camada = "critica" if i.get("aplicacao") == "um_por_vez" else i.get("camada", "comum")
        linhas.append(f"{slug} {i['de']} {i['para']} {camada}")
    return sha[:12], linhas


def main(argv: list[str]) -> int:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = p.add_subparsers(dest="comando", required=True)
    v = sub.add_parser("validar")
    v.add_argument("--lock", required=True, type=pathlib.Path)
    v.add_argument("--max-horas", type=float, default=72)
    v.add_argument("--agora")
    a = p.parse_args(argv)
    try:
        lock = json.loads(a.lock.read_text())
        agora = dt.datetime.fromisoformat(a.agora) if a.agora else dt.datetime.now()
        sha, linhas = validar(lock, agora, a.max_horas)
    except (OSError, ValueError) as erro:
        print(f"Erro: lock inválido: {erro}", file=sys.stderr)
        return 2
    print(sha)
    print("\n".join(linhas))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
