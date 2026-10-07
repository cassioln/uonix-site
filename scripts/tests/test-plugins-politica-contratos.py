#!/usr/bin/env python3
"""Política e contratos de plugins (#393): coerência entre os dois arquivos,
verificador de contratos contra fixture e classificação do inventário.

Não depende do WordPress nem de rede.
"""
from __future__ import annotations

import json
import pathlib
import subprocess
import sys
import tempfile

RAIZ = pathlib.Path(__file__).resolve().parents[2]
POLITICA = json.loads((RAIZ / "ops/plugins/politica.json").read_text())
CONTRATOS = json.loads((RAIZ / "ops/plugins/contratos.json").read_text())["contratos"]
CONTRATOS_PY = RAIZ / "scripts/plugins/contratos.py"
INVENTARIO_PY = RAIZ / "scripts/plugins/inventario.py"

falhas: list[str] = []


def checar(condicao: bool, mensagem: str) -> None:
    if not condicao:
        falhas.append(mensagem)


def rodar(*args: str) -> subprocess.CompletedProcess:
    return subprocess.run([sys.executable, *args], capture_output=True, text=True)


# 1. Política: camadas conhecidas e campos obrigatórios.
camadas = POLITICA["camadas"]
for nome, regra in camadas.items():
    for campo in ("descricao", "aplicacao", "quarentena_dias", "major"):
        checar(campo in regra, f"camada {nome} sem {campo}")
    checar(regra.get("aplicacao") in {"um_por_vez", "lote", "nunca"}, f"camada {nome}: aplicacao inválida")
    checar(regra.get("major") in {"bloqueia", "alerta"}, f"camada {nome}: major inválido")
for chave in ("plugins", "temas"):
    for slug, regra in POLITICA[chave].items():
        checar(regra.get("camada") in camadas, f"{chave} {slug}: camada desconhecida {regra.get('camada')}")
        checar(isinstance(regra.get("contrato"), bool), f"{chave} {slug}: campo contrato deve ser booleano")
        checar(bool(regra.get("motivo")), f"{chave} {slug}: sem motivo")

# 2. Contratos: cobertura nos dois sentidos, tipos e caminhos vivos.
exigidos = {slug: chave for chave in ("plugins", "temas")
            for slug, regra in POLITICA[chave].items() if regra["contrato"]}
for slug, chave in exigidos.items():
    checar(slug in CONTRATOS, f"{slug} exige contrato (politica.json) e não tem entrada em contratos.json")
for slug, contrato in CONTRATOS.items():
    chave = "temas" if contrato.get("tipo") == "tema" else "plugins"
    checar(slug in POLITICA[chave], f"contrato de {slug} sem classificação em politica.json ({chave})")
    checar(POLITICA[chave].get(slug, {}).get("contrato") is True, f"contrato de {slug} existe, mas politica.json diz contrato=false")
    usado = contrato.get("usado_em", [])
    checar(bool(usado), f"contrato de {slug} sem usado_em")
    for caminho in usado:
        checar((RAIZ / caminho).exists(), f"contrato de {slug}: usado_em aponta para caminho inexistente: {caminho}")
    for campo, valor in contrato.items():
        if campo in {"usado_em", "funcoes", "classes", "hooks", "shortcodes", "marcadores",
                     "post_types", "rotas_rest", "hooks_dinamicos"}:
            checar(isinstance(valor, list) and all(isinstance(v, str) and v for v in valor),
                   f"contrato de {slug}: {campo} deve ser lista de textos não vazios")
        elif campo not in {"tipo", "wrapper_hooks"}:
            falhas.append(f"contrato de {slug}: campo desconhecido {campo}")

# 3. Verificador: fixture com cada tipo de item, positivo e controles negativos.
with tempfile.TemporaryDirectory() as tmp:
    base = pathlib.Path(tmp)
    plugin = base / "plugins" / "exemplo"
    plugin.mkdir(parents=True)
    (plugin / "exemplo.php").write_text("""<?php
namespace Exemplo\\Servicos;
class Envio {}
function exemplo_util( $a ) {}
do_action( 'exemplo_salvo', 1 );
$this->do_filter( 'json', array() );
add_shortcode( 'exemplo', 'cb' );
$app->addShortCode('exemplo_fw', function () {});
$css = 'exemplo-cabecalho';
""")
    contrato = {"exemplo": {
        "usado_em": ["x"], "funcoes": ["exemplo_util"], "classes": ["Exemplo\\Servicos\\Envio"],
        "hooks": ["exemplo_salvo", "ex/json"], "shortcodes": ["exemplo", "exemplo_fw"],
        "marcadores": ["exemplo-cabecalho"], "wrapper_hooks": {"prefixo": "ex/", "metodos": ["do_filter"]}}}
    arquivo = base / "contratos.json"

    def verificar(c: dict) -> subprocess.CompletedProcess:
        arquivo.write_text(json.dumps({"contratos": c}))
        return rodar(str(CONTRATOS_PY), "verificar", "--plugins-dir", str(base / "plugins"), "--contratos", str(arquivo))

    r = verificar(contrato)
    checar(r.returncode == 0, f"verificador reprovou fixture válida: {r.stdout}{r.stderr}")

    negativos = {
        "funcoes": ["exemplo_sumiu"],
        "classes": ["Outro\\Namespace\\Envio"],
        "hooks": ["exemplo_nao_disparado"],
        "shortcodes": ["exemplo_ausente"],
        "marcadores": ["classe-que-nao-existe"],
    }
    for campo, valor in negativos.items():
        c = json.loads(json.dumps(contrato))
        c["exemplo"][campo] = c["exemplo"][campo] + valor
        r = verificar(c)
        checar(r.returncode == 20 and valor[0] in r.stdout,
               f"controle negativo de {campo} não reprovou: rc={r.returncode} {r.stdout}")

    c = json.loads(json.dumps(contrato))
    del c["exemplo"]["wrapper_hooks"]
    r = verificar(c)
    checar(r.returncode == 20 and "ex/json" in r.stdout, "hook com prefixo passou sem wrapper_hooks declarado")

    r = verificar({"ausente": {"usado_em": ["x"], "funcoes": ["f"]}})
    checar(r.returncode == 20 and "não está instalado" in r.stdout, f"plugin ausente não reprovou: {r.stdout}")

    # 4. Inventário: classificação e bandeiras.
    pol = {"camadas": camadas, "plugins": {
        "critico": {"camada": "critica", "contrato": False, "motivo": "t"},
        "comum1": {"camada": "comum", "contrato": False, "motivo": "t"},
        "sumido": {"camada": "comum", "contrato": False, "motivo": "t"}},
        "temas": {"tema": {"camada": "critica", "contrato": False, "motivo": "t"}}}
    (base / "pol.json").write_text(json.dumps(pol))

    def inventario(plugins: list, temas: list | None = None) -> subprocess.CompletedProcess:
        (base / "inv.json").write_text(json.dumps({"plugins": plugins, "temas": temas or []}))
        return rodar(str(INVENTARIO_PY), "--entrada", str(base / "inv.json"), "--politica", str(base / "pol.json"))

    def item(nome, de="1.0", para="", status="active", auto="off"):
        return {"name": nome, "status": status, "version": de, "update": "available" if para else "none",
                "update_version": para, "auto_update": auto}

    r = inventario([item("critico", "1.2", "1.3"), item("comum1"), item("uonix-core", status="must-use")])
    checar(r.returncode == 0, f"inventário limpo saiu {r.returncode}: {r.stdout}")
    checar("`critico` 1.2 → 1.3" in r.stdout, "pendência da camada crítica não listada")
    checar("plugin sumido" in r.stdout, "plugin da política ausente da produção não listado")
    checar("uonix-core" not in r.stdout, "must-use deveria ser ignorado")

    r = inventario([item("critico", "1.9", "2.0")])
    checar(r.returncode == 10 and "major em camada critica" in r.stdout, "major na crítica não virou bandeira")
    r = inventario([item("comum1", "1.9", "2.0")])
    checar(r.returncode == 0 and "**MAJOR**" in r.stdout, "major na camada comum deveria só marcar, sem bandeira")
    r = inventario([item("novo")])
    checar(r.returncode == 10 and "sem classificação" in r.stdout, "plugin sem classificação não virou bandeira")
    r = inventario([item("comum1", auto="on")])
    checar(r.returncode == 10 and "LIGADA" in r.stdout, "atualização automática ligada não virou bandeira")
    r = inventario([item("comum1")], [item("tema-novo", status="inactive")])
    checar(r.returncode == 10 and "tema tema-novo" in r.stdout, "tema sem classificação não virou bandeira")
    r = inventario([])
    checar(r.returncode == 2, "inventário sem plugins deveria falhar (leitura de produção vazia)")

if falhas:
    print("FAIL:")
    for f in falhas:
        print(f"  - {f}")
    sys.exit(1)
print(f"OK: política ({len(POLITICA['plugins'])} plugins, {len(POLITICA['temas'])} temas), "
      f"{len(CONTRATOS)} contratos, verificador e inventário")
