#!/usr/bin/env python3
"""Aplicação em produção (#393, entrega 3), lado do Mac: validação do lock,
smoke de produção com HTTP falso e guardas do `atualizar.sh aplicar-producao`
que precisam recusar sem abrir conexão. Sem rede e sem SSH."""
from __future__ import annotations

import datetime as dt
import hashlib
import json
import os
import pathlib
import subprocess
import sys
import tempfile

RAIZ = pathlib.Path(__file__).resolve().parents[2]
sys.path.insert(0, str(RAIZ / "scripts" / "plugins"))
import lock as lockmod  # noqa: E402
import smoke_producao  # noqa: E402

AGORA = dt.datetime(2026, 10, 7, 22, 0, 0)
falhas: list[str] = []


def checar(cond: bool, msg: str) -> None:
    if not cond:
        falhas.append(msg)


def lock(itens=None, resultado="verde", gerado="2026-10-07T18:55:00", adulterar=False) -> dict:
    corpo = {"gerado_em": gerado, "php_local": "8.5.11", "resultado": resultado, "fora": [],
             "itens": itens if itens is not None else [
                 {"slug": "google-site-kit", "tipo": "plugin", "de": "1.188.0", "para": "1.189.0", "camada": "acoplada", "aplicacao": "lote"},
                 {"slug": "kadence-blocks", "tipo": "plugin", "de": "3.7.12", "para": "3.7.12.1", "camada": "critica", "aplicacao": "um_por_vez"}]}
    corpo["sha256"] = hashlib.sha256(json.dumps(corpo, ensure_ascii=False, indent=1).encode()).hexdigest()
    if adulterar:
        corpo["itens"][0]["para"] = "9.9.9"
    return corpo


def invalido(l: dict, trecho: str, horas: float = 72) -> None:
    try:
        lockmod.validar(l, AGORA, horas)
    except lockmod.LockInvalido as e:
        checar(trecho in str(e), f"lock recusado pelo motivo errado: {e} (esperado: {trecho})")
        return
    falhas.append(f"lock deveria ser recusado ({trecho})")


# 1. lock.py
sha, linhas = lockmod.validar(lock(), AGORA, 72)
checar(len(sha) == 12, "sha curto deveria ter 12 caracteres")
checar(linhas == ["google-site-kit 1.188.0 1.189.0 acoplada", "kadence-blocks 3.7.12 3.7.12.1 critica"], f"linhas erradas: {linhas}")
invalido(lock(adulterar=True), "sha256 não confere")
invalido(lock(resultado="regressao"), "não 'verde'")
invalido(lock(gerado="2026-10-03T10:00:00"), "refaça o ensaio")
invalido(lock(itens=[]), "sem itens")
invalido(lock(itens=[{"slug": "fluent-smtp", "tipo": "plugin", "de": "2.4.1", "para": "2.4.2", "camada": "critica", "aplicacao": "um_por_vez"}]), "não carrega")
invalido(lock(itens=[{"slug": "../wp-content", "tipo": "plugin", "de": "1", "para": "2", "camada": "comum", "aplicacao": "lote"}]), "slug inválido")
invalido(lock(itens=[{"slug": "", "tipo": "plugin", "de": "1", "para": "2", "camada": "comum", "aplicacao": "lote"}]), "slug inválido")
invalido(lock(itens=[{"slug": "kadence", "tipo": "tema", "de": "1", "para": "2", "camada": "critica", "aplicacao": "um_por_vez"}]), "só plugins")
invalido(lock(itens=[{"slug": "x", "tipo": "plugin", "de": "", "para": "2", "camada": "comum", "aplicacao": "lote"}]), "ausente")


# 2. smoke_producao.py com HTTP falso
HOME_OK = ('<meta name="description" content="x"><script type="application/ld+json">{}</script>'
           '<li class="mega-menu-item mega-menu-item-type-widget">a</li>')


def fake(home=HOME_OK, status_pagina=200):
    def get(url):
        if "/wp-json/wc/store/v1/products" in url:
            return 200, '[{"id": 1}]'
        if "sitemap_index.xml" in url:
            return 200, "<sitemap></sitemap><sitemap></sitemap>"
        if url.split("?")[0].endswith(".br/"):
            return status_pagina, home
        return status_pagina, "<html>ok</html>"
    return get


r = smoke_producao.smoke("https://x.com.br", fake(), extras=("/servico/a/",))
checar(all(v["ok"] for v in r.values()), f"smoke verde com tudo certo: {[k for k, v in r.items() if not v['ok']]}")
checar("pagina /servico/a/" in r, "caminho extra não conferido")
r = smoke_producao.smoke("https://x.com.br", fake(home='<meta name="description"><style>.mega-menu-item{}</style>'))
checar(not r["home megamenu: itens"]["ok"], "megamenu só no CSS deveria ficar vermelho")
checar(not r["home ld+json"]["ok"], "ld+json ausente deveria ficar vermelho")
r = smoke_producao.smoke("https://x.com.br", lambda url: (0, "connection refused"))
checar(not any(v["ok"] for v in r.values()), "site fora do ar deveria deixar todos vermelhos")


# 3. guardas do atualizar.sh: recusam sem abrir conexão (sem .env, sem SSH)
with tempfile.TemporaryDirectory() as tmp:
    arq = pathlib.Path(tmp) / "lock.json"
    l = lock(gerado=dt.datetime.now().isoformat(timespec="seconds"))
    arq.write_text(json.dumps(l, ensure_ascii=False, indent=1))
    env = {k: v for k, v in os.environ.items() if not k.startswith("LOCAWEB_")}
    env["UONIX_CHECKOUT_PRINCIPAL"] = tmp  # sem .env: qualquer tentativa de conexão falharia
    script = str(RAIZ / "scripts/plugins/atualizar.sh")
    r = subprocess.run(["bash", script, "aplicar-producao", f"--lock={arq}", "--confirmacao=ATUALIZAR PROD errado"],
                       capture_output=True, text=True, env=env)
    checar(r.returncode == 30 and f"ATUALIZAR PROD {l['sha256'][:12]}" in r.stderr,
           f"confirmação errada deveria sair 30 indicando a frase certa (rc={r.returncode}): {r.stderr}")
    checar("sonda" not in r.stdout, "confirmação errada não pode chegar à sonda SSH")
    r = subprocess.run(["bash", script, "aplicar-producao", f"--lock={tmp}/nao-existe.json"],
                       capture_output=True, text=True, env=env)
    checar(r.returncode == 30, f"lock ausente deveria sair 30 (rc={r.returncode})")
    arq.write_text(json.dumps(lock(adulterar=True), ensure_ascii=False, indent=1))
    r = subprocess.run(["bash", script, "aplicar-producao", f"--lock={arq}", "--confirmacao=x"],
                       capture_output=True, text=True, env=env)
    checar(r.returncode == 30 and "sha256 não confere" in r.stderr, f"lock adulterado deveria sair 30 (rc={r.returncode})")

if falhas:
    print("FAIL:")
    for f in falhas:
        print(f"  - {f}")
    sys.exit(1)
print("OK: lock, smoke de produção e guardas do aplicar-producao")
