#!/usr/bin/env python3
"""Ensaio local de uma atualização de plugins (#393, entrega 2).

Executa, contra o ambiente local (Podman, http://localhost:8080), o plano gerado
por plano.py, e só grava o lock de versões quando tudo fica verde:

1. clone prod -> local (ou reuso de um clone desta esteira com menos de 24 h);
2. alinhamento: versões e ativação do local iguais às de produção, porque o
   clone exclui alguns plugins e preserva a lista de ativos do destino;
3. backup do banco local por mariadb-dump, validado;
4. smoke ANTES, como linha de base;
5. atualização na ordem do plano: lote (acoplada e comum) e, depois, a camada
   crítica um por vez; `wp wc update` depois do woocommerce; contratos
   estáticos do que mudou e smoke depois de cada etapa;
6. lock e relatório.

Só reprova o que era verde na linha de base e ficou vermelho (regressão). O que
já falhava antes aparece no relatório como pré-existente.

O WP-CLI roda dentro do container do site (mesmo PHP que serve as páginas), a
partir de um phar conferido pelo sha512 publicado.

Saída: 0 verde (lock gravado) ou nada a ensaiar; 20 regressão ou contrato
quebrado; 30 falha de preparação (clone, alinhamento, backup, WP-CLI).
"""
from __future__ import annotations

import argparse
import datetime as dt
import gzip
import hashlib
import http.cookiejar
import json
import os
import pathlib
import re
import subprocess
import sys
import urllib.error
import urllib.request

RAIZ = pathlib.Path(__file__).resolve().parents[2]
PHAR_URL = "https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar"
PHAR = "/tmp/wp-cli.phar"
# No local, o fluent-smtp fica inativo de propósito: o e-mail vai ao Mailpit
# (política de SMTP do clone). Ele é atualizado, mas não ativado.
EXCECOES_ATIVACAO = {"fluent-smtp": "inactive"}
VALIDADE_CLONE = dt.timedelta(hours=24)


class Falha(Exception):
    """Falha de preparação: o ensaio não chegou a testar nada."""


# --------------------------------------------------------------------------- #
# Ambiente local real
# --------------------------------------------------------------------------- #
class Local:
    def __init__(self, checkout: pathlib.Path):
        self.checkout = checkout
        self.app = os.environ.get("UONIX_LOCAL_APP_CONTAINER", "uonix-local-app")
        self.db = os.environ.get("LOCAL_DB_CONTAINER", "uonix-local-db")
        self.db_name = os.environ.get("LOCAL_DB_NAME", "uonix_db")
        self.db_user = os.environ.get("LOCAL_DB_USER", "uonix_user")
        self.db_pass = os.environ.get("LOCAL_DB_PASSWORD", "uonix_pass")
        self.base = os.environ.get("UONIX_LOCAL_URL", "http://localhost:8080").rstrip("/")
        self.wp_content = checkout / "local" / "wp-content"

    def _exec(self, args: list[str], timeout: int = 600) -> subprocess.CompletedProcess:
        return subprocess.run(["podman", "exec", self.app, *args], capture_output=True, text=True, timeout=timeout)

    def preparar_wpcli(self) -> str:
        esperado = urllib.request.urlopen(PHAR_URL + ".sha512", timeout=30).read().decode().split()[0]
        atual = self._exec(["sh", "-c", f"sha512sum {PHAR} 2>/dev/null | cut -d' ' -f1"]).stdout.strip()
        if atual != esperado:
            r = self._exec(["sh", "-c", f"curl -fsSL -o {PHAR}.novo {PHAR_URL} && mv {PHAR}.novo {PHAR}"], 300)
            if r.returncode != 0:
                raise Falha(f"download do wp-cli.phar falhou: {r.stderr.strip()}")
            atual = self._exec(["sh", "-c", f"sha512sum {PHAR} | cut -d' ' -f1"]).stdout.strip()
        if atual != esperado:
            raise Falha("wp-cli.phar não confere com o sha512 publicado")
        return self.wp("cli", "version").strip()

    def wp(self, *args: str, verificar: bool = True, timeout: int = 900) -> str:
        r = self._exec(["php", "-d", "memory_limit=512M", PHAR, "--allow-root", "--path=/var/www/html", *args], timeout)
        if verificar and r.returncode != 0:
            raise RuntimeError(f"wp {' '.join(args)} falhou (exit {r.returncode}): {r.stderr.strip()[-400:]}")
        return r.stdout

    def eval_json(self, php: str):
        saida = self.wp("eval", php)
        m = re.search(r"@@J(.*)@@J", saida, re.S)
        if not m:
            raise RuntimeError(f"wp eval sem retorno marcado: {saida[-300:]}")
        return json.loads(m.group(1))

    def backup_banco(self, destino: pathlib.Path) -> int:
        cmd = ["podman", "exec", "-e", f"MYSQL_PWD={self.db_pass}", self.db, "mariadb-dump", "-u", self.db_user,
               "--skip-ssl", "--single-transaction", "--default-character-set=utf8mb4", self.db_name]
        with subprocess.Popen(cmd, stdout=subprocess.PIPE) as dump, gzip.open(destino, "wb") as saida:
            for bloco in iter(lambda: dump.stdout.read(1 << 20), b""):
                saida.write(bloco)
        if dump.returncode != 0:
            raise Falha(f"mariadb-dump saiu com {dump.returncode}")
        tabelas = int(subprocess.run(
            ["podman", "exec", "-e", f"MYSQL_PWD={self.db_pass}", self.db, "mariadb", "-u", self.db_user,
             "--skip-ssl", "-N", "-e",
             f"select count(*) from information_schema.tables where table_schema='{self.db_name}'"],
            capture_output=True, text=True, check=True).stdout.strip())
        criadas, completo = 0, False
        with gzip.open(destino, "rt", errors="ignore") as f:
            for linha in f:
                criadas += linha.startswith("CREATE TABLE")
                completo = completo or linha.startswith("-- Dump completed")
        if not completo or criadas != tabelas:
            raise Falha(f"backup inválido: {criadas} de {tabelas} tabelas, completo={completo}")
        return tabelas

    def http(self, caminho: str, jar=None, dados: bytes | None = None, cabecalhos: dict | None = None):
        url = caminho if caminho.startswith("http") else self.base + caminho
        req = urllib.request.Request(url, data=dados, headers=cabecalhos or {})
        abridor = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar or http.cookiejar.CookieJar()))
        try:
            with abridor.open(req, timeout=60) as r:
                return r.status, r.read().decode(errors="ignore"), dict(r.headers)
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode(errors="ignore"), dict(e.headers)

    def tamanho_log(self) -> int:
        log = self.wp_content / "debug.log"
        return log.stat().st_size if log.exists() else 0

    def log_desde(self, posicao: int) -> str:
        log = self.wp_content / "debug.log"
        if not log.exists():
            return ""
        with log.open("rb") as f:
            f.seek(posicao)
            return f.read().decode(errors="ignore")


# --------------------------------------------------------------------------- #
# Smoke
# --------------------------------------------------------------------------- #
ALVOS_PHP = r"""
$q = function ($tipo) { $p = get_posts(array('post_type' => $tipo, 'post_status' => 'publish', 'numberposts' => 1)); return $p ? get_permalink($p[0]) : null; };
$produto = null;
if (function_exists('wc_get_products')) { foreach (wc_get_products(array('status' => 'publish', 'type' => 'simple', 'limit' => 30)) as $p) { if ($p->is_purchasable()) { $produto = $p->get_id(); break; } } }
echo '@@J' . wp_json_encode(array(
  'paginas' => array_values(array_filter(array(home_url('/'), home_url('/blog/'), home_url('/servicos/'), home_url('/produtos/'),
     function_exists('wc_get_page_id') ? get_permalink(wc_get_page_id('cart')) : null, home_url('/sitemap_index.xml'),
     home_url('/wp-json/'), wp_login_url(), $q('product'), $q('post'), post_type_exists('servicos') ? $q('servicos') : null))),
  'produto' => $produto,
)) . '@@J';
"""


def alvos(local) -> dict:
    return local.eval_json(ALVOS_PHP)


def smoke(local, alvo: dict, contratos: dict) -> dict:
    """Roda os checks e devolve {nome: {"ok": bool, "detalhe": str}}."""
    r: dict[str, dict] = {}
    inicio = local.tamanho_log()

    for url in alvo["paginas"]:
        caminho = re.sub(r"^https?://[^/]+", "", url) or "/"
        status, corpo, _ = local.http(url + ("&" if "?" in url else "?") + "uonix_smoke=1")
        erro = bool(re.search(r"critical error|Fatal error", corpo, re.I))
        r[f"pagina {caminho}"] = {"ok": status == 200 and not erro, "detalhe": f"http {status}{' com erro fatal' if erro else ''}"}

    # Marcadores medidos ligando e desligando o plugin no local (2026-10-07).
    # O texto `mega-menu-item` sozinho NÃO serve: nosso CSS inline o repete, e
    # sobravam 71 ocorrências com o Max Mega Menu desativado.
    _, home, _ = local.http("/?uonix_smoke=1")
    for nome, padrao in (
        ("megamenu: itens", r"<li[^>]*class=[\"'][^\"']*\bmega-menu-item\b"),            # 32 ligado, 0 desligado
        ("megamenu: widgets nas colunas", r"\bmega-menu-item-type-widget\b"),             # incidente de 2026-10-02
        ("meta description", r"<meta name=[\"']description[\"']"),                     # 1 com Rank Math, 0 sem
        ("ld+json", r"application/ld\+json"),                                          # 1 com Rank Math, 0 sem
    ):
        n = len(re.findall(padrao, home))
        r[f"home {nome}"] = {"ok": n > 0, "detalhe": f"{n} ocorrência(s)"}

    if alvo.get("produto"):
        jar = http.cookiejar.CookieJar()
        _, _, cab = local.http("/wp-json/wc/store/v1/cart", jar)
        nonce = next((v for k, v in cab.items() if k.lower() == "nonce"), "")
        status, corpo, _ = local.http("/wp-json/wc/store/v1/cart/add-item", jar,
                                      json.dumps({"id": alvo["produto"], "quantity": 1}).encode(),
                                      {"Nonce": nonce, "Content-Type": "application/json"})
        try:
            itens = json.loads(corpo).get("items_count")
        except ValueError:
            itens = None
        r["cotação: adicionar produto (Store API)"] = {"ok": status in (200, 201) and itens == 1,
                                                      "detalhe": f"http {status}, items_count={itens}"}

    tipos = sorted({t for c in contratos.values() for t in c.get("post_types", [])})
    rotas = sorted({t for c in contratos.values() for t in c.get("rotas_rest", [])})
    estado = local.eval_json(
        "$rotas = rest_get_server()->get_routes();"
        f"$t = {json.dumps(tipos)}; $r = {json.dumps(rotas)};"
        "echo '@@J' . wp_json_encode(array("
        "'tipos' => array_combine($t, array_map('post_type_exists', $t)) ?: new stdClass,"
        "'rotas' => array_combine($r, array_map(function ($x) use ($rotas) { return isset($rotas[$x]); }, $r)) ?: new stdClass,"
        "'wc' => defined('WC_VERSION') ? WC_VERSION : null,"
        "'wc_db' => get_option('woocommerce_db_version'))) . '@@J';")
    for t, ok in estado["tipos"].items():
        r[f"post type {t}"] = {"ok": bool(ok), "detalhe": "registrado" if ok else "AUSENTE"}
    for rota, ok in estado["rotas"].items():
        r[f"rota REST {rota}"] = {"ok": bool(ok), "detalhe": "registrada" if ok else "AUSENTE"}
    if estado.get("wc"):
        ok = str(estado.get("wc_db", "")).split("-")[0] == estado["wc"]
        r["banco do WooCommerce migrado"] = {"ok": ok, "detalhe": f"plugin {estado['wc']}, banco {estado.get('wc_db')}"}

    fatais = [l for l in local.log_desde(inicio).splitlines() if "PHP Fatal" in l]
    r["debug.log sem PHP Fatal novo"] = {"ok": not fatais, "detalhe": fatais[0][-200:] if fatais else "0"}
    return r


def comparar(base: dict, atual: dict) -> tuple[list[str], list[str]]:
    """(regressões, pré-existentes). Check ausente da base conta como novo vermelho."""
    regressoes, preexistentes = [], []
    for nome, res in atual.items():
        if res["ok"]:
            continue
        if base.get(nome, {"ok": True})["ok"]:
            regressoes.append(f"{nome}: {res['detalhe']}")
        else:
            preexistentes.append(f"{nome}: {res['detalhe']}")
    return regressoes, preexistentes


# --------------------------------------------------------------------------- #
# Alinhamento com produção
# --------------------------------------------------------------------------- #
def alinhar(local, inventario: dict, ignorar: set[str] = frozenset()) -> list[str]:
    """Iguala versões e ativação às de produção. `ignorar`: código próprio,
    publicado por deploy e ausente do wordpress.org (camada `propria`)."""
    acoes = []
    for tipo, chave in (("plugin", "plugins"), ("theme", "temas")):
        locais = {i["name"]: i for i in json.loads(local.wp(tipo, "list", "--fields=name,status,version", "--format=json"))}
        for prod in inventario.get(chave, []):
            nome, versao, status = prod["name"], prod.get("version", ""), prod.get("status", "")
            if status in {"must-use", "dropin"} or not versao or nome in ignorar:
                continue
            atual = locais.get(nome)
            if atual is None or atual.get("version") != versao:
                local.wp(tipo, "install", nome, f"--version={versao}", "--force")
                acoes.append(f"{tipo} {nome}: {atual.get('version') if atual else 'ausente'} -> {versao}")
            if tipo == "plugin":
                alvo = EXCECOES_ATIVACAO.get(nome, "active" if status == "active" else "inactive")
                ativo = (atual or {}).get("status") == "active"
                if alvo == "active" and not ativo:
                    local.wp("plugin", "activate", nome)
                    acoes.append(f"plugin {nome}: ativado")
                elif alvo == "inactive" and ativo:
                    local.wp("plugin", "deactivate", nome)
                    acoes.append(f"plugin {nome}: desativado")
    divergentes = verificar_alinhamento(local, inventario, ignorar)
    if divergentes:
        raise Falha("alinhamento incompleto: " + "; ".join(divergentes))
    return acoes


def verificar_alinhamento(local, inventario: dict, ignorar: set[str] = frozenset()) -> list[str]:
    divergentes = []
    for tipo, chave in (("plugin", "plugins"), ("theme", "temas")):
        locais = {i["name"]: i for i in json.loads(local.wp(tipo, "list", "--fields=name,status,version", "--format=json"))}
        for prod in inventario.get(chave, []):
            nome = prod["name"]
            if prod.get("status") in {"must-use", "dropin"} or not prod.get("version") or nome in ignorar:
                continue
            atual = locais.get(nome, {})
            if atual.get("version") != prod.get("version"):
                divergentes.append(f"{nome} versão local {atual.get('version')} != produção {prod.get('version')}")
            if tipo == "plugin":
                alvo = EXCECOES_ATIVACAO.get(nome, "active" if prod.get("status") == "active" else "inactive")
                if (atual.get("status") == "active") != (alvo == "active"):
                    divergentes.append(f"{nome} status local {atual.get('status')} != esperado {alvo}")
    return divergentes


# --------------------------------------------------------------------------- #
# Execução
# --------------------------------------------------------------------------- #
def etapas(plano: dict) -> list[tuple[str, list[dict]]]:
    lote = [i for i in plano["itens"] if i.get("aplicacao") == "lote"]
    criticos = [i for i in plano["itens"] if i.get("aplicacao") == "um_por_vez"]
    seq = [("lote: " + ", ".join(i["slug"] for i in lote), lote)] if lote else []
    return seq + [(i["slug"], [i]) for i in criticos]


def executar(local, plano: dict, inventario: dict, contratos: dict, verificar_contratos, saida: pathlib.Path,
             clonar=None, proprios: set[str] = frozenset()) -> int:
    relatorio = [f"# Ensaio local — {dt.datetime.now().isoformat(timespec='seconds')}", ""]

    def fim(codigo: int, motivo: str) -> int:
        relatorio.extend(["", f"**Resultado: {motivo}** (saída {codigo})"])
        (saida / "relatorio.md").write_text("\n".join(relatorio) + "\n")
        print("\n".join(relatorio))
        return codigo

    if not plano["itens"]:
        relatorio.append("Nada a ensaiar.")
        relatorio += [f"- fora: {f['slug']} {f['de']} -> {f['para']}: {f['motivo']}" for f in plano["fora"]]
        return fim(0, "nada a ensaiar")

    try:
        if clonar:
            relatorio.append(f"- clone: {clonar()}")
        relatorio.append(f"- WP-CLI: {local.preparar_wpcli()}")
        acoes = alinhar(local, inventario, proprios)
        relatorio.append(f"- alinhamento com produção: {len(acoes)} ação(ões)")
        relatorio += [f"  - {a}" for a in acoes]
        tabelas = local.backup_banco(saida / "db-local-antes.sql.gz")
        relatorio.append(f"- backup do banco local: {tabelas} tabelas em {saida.name}/db-local-antes.sql.gz")
        alvo = alvos(local)
        base = smoke(local, alvo, contratos)
    except (Falha, RuntimeError, subprocess.SubprocessError, OSError, ValueError) as erro:
        return fim(30, f"falha de preparação: {erro}")

    vermelhos_base = [f"{n}: {r['detalhe']}" for n, r in base.items() if not r["ok"]]
    relatorio += ["", f"## Linha de base: {len(base)} checks, {len(vermelhos_base)} já vermelhos"]
    relatorio += [f"- pré-existente: {v}" for v in vermelhos_base]
    (saida / "smoke-antes.json").write_text(json.dumps(base, ensure_ascii=False, indent=1))

    for nome_etapa, itens in etapas(plano):
        relatorio += ["", f"## {nome_etapa}"]
        try:
            for i in itens:
                local.wp("theme" if i["tipo"] == "tema" else "plugin", "update", i["slug"], f"--version={i['para']}")
                relatorio.append(f"- {i['slug']} {i['de']} -> {i['para']}")
                if i["slug"] == "woocommerce":
                    local.wp("wc", "update")
                    relatorio.append("- `wp wc update` executado")
        except RuntimeError as erro:
            return fim(20, f"atualização falhou em {nome_etapa}: {erro}")
        com_contrato = [i["slug"] for i in itens if i["slug"] in contratos]
        if com_contrato:
            ok, detalhe = verificar_contratos(com_contrato)
            relatorio.append(f"- contratos ({', '.join(com_contrato)}): {'ok' if ok else 'QUEBRADO'}")
            if not ok:
                relatorio += [f"  {l}" for l in detalhe.splitlines() if l.strip()]
                return fim(20, f"contrato quebrado em {nome_etapa}")
        atual = smoke(local, alvo, contratos)
        regressoes, preexistentes = comparar(base, atual)
        relatorio.append(f"- smoke: {len(atual)} checks, {len(regressoes)} regressão(ões), {len(preexistentes)} pré-existente(s)")
        if regressoes:
            relatorio += [f"  - REGRESSÃO {r}" for r in regressoes]
            (saida / "smoke-falha.json").write_text(json.dumps(atual, ensure_ascii=False, indent=1))
            return fim(20, f"regressão depois de {nome_etapa}")

    lock = {
        "gerado_em": dt.datetime.now().isoformat(timespec="seconds"),
        "php_local": local.wp("eval", "echo PHP_VERSION;").strip(),
        "itens": [{k: i[k] for k in ("slug", "tipo", "de", "para", "camada", "aplicacao")} for i in plano["itens"]],
        "fora": [{k: f.get(k) for k in ("slug", "de", "para", "motivo")} for f in plano["fora"]],
        "resultado": "verde",
    }
    texto = json.dumps(lock, ensure_ascii=False, indent=1)
    lock["sha256"] = hashlib.sha256(texto.encode()).hexdigest()
    (saida / "lock.json").write_text(json.dumps(lock, ensure_ascii=False, indent=1) + "\n")
    relatorio += ["", f"Lock gravado: {saida.name}/lock.json (sha256 {lock['sha256'][:12]})"]
    relatorio += [f"- fora do ensaio: {f['slug']} {f['de']} -> {f['para']}: {f['motivo']}" for f in plano["fora"]]
    return fim(0, "verde")


# --------------------------------------------------------------------------- #
# Clone
# --------------------------------------------------------------------------- #
def clonar_producao(checkout: pathlib.Path, marcador: pathlib.Path, reusar: bool):
    def rodar() -> str:
        if reusar:
            try:
                m = json.loads(marcador.read_text())
                quando = dt.datetime.fromisoformat(m["concluido_em"])
            except (OSError, ValueError, KeyError):
                raise Falha(f"--reusar-clone sem marcador válido em {marcador}")
            if dt.datetime.now() - quando > VALIDADE_CLONE:
                raise Falha(f"clone de {m['concluido_em']} tem mais de 24 h; rode sem --reusar-clone")
            return f"reusado (backup {m.get('backup')}, {m['concluido_em']})"
        env = dict(os.environ, LOCAL_WP_CONTENT=str(checkout / "local/wp-content"),
                   LOCAL_COMPOSE_FILE=str(checkout / "local/compose.yml"),
                   LOCAL_BACKUP_ROOT=str(checkout / "backups/clone"))
        r = subprocess.run(["bash", str(RAIZ / "scripts/clone-environment.sh"), "--source=prod", "--target=local",
                            "--execute"], env=env, capture_output=True, text=True)
        (marcador.parent / "clone-ultimo.log").write_text(r.stdout + r.stderr)
        m = re.search(r"Clone concluído: prod -> local; backup: (\S+)", r.stdout)
        if r.returncode != 0 or not m:
            raise Falha(f"clone prod -> local falhou (exit {r.returncode}); ver {marcador.parent / 'clone-ultimo.log'}")
        marcador.write_text(json.dumps({"concluido_em": dt.datetime.now().isoformat(timespec="seconds"),
                                        "backup": m.group(1)}) + "\n")
        return f"executado (backup {m.group(1)})"
    return rodar


def main(argv: list[str]) -> int:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--inventario", required=True, type=pathlib.Path)
    p.add_argument("--plano", required=True, type=pathlib.Path)
    p.add_argument("--checkout", required=True, type=pathlib.Path, help="checkout principal (local/, .env, backups/)")
    p.add_argument("--saida", required=True, type=pathlib.Path)
    p.add_argument("--reusar-clone", action="store_true")
    a = p.parse_args(argv)
    inventario = json.loads(a.inventario.read_text())
    plano = json.loads(a.plano.read_text())
    contratos = json.loads((RAIZ / "ops/plugins/contratos.json").read_text())["contratos"]
    a.saida.mkdir(parents=True, exist_ok=True)
    local = Local(a.checkout)

    def verificar_contratos(slugs: list[str]) -> tuple[bool, str]:
        r = subprocess.run([sys.executable, str(RAIZ / "scripts/plugins/contratos.py"), "verificar",
                            "--plugins-dir", str(local.wp_content / "plugins"),
                            "--temas-dir", str(local.wp_content / "themes"), *slugs], capture_output=True, text=True)
        return r.returncode == 0, r.stdout + r.stderr

    politica = json.loads((RAIZ / "ops/plugins/politica.json").read_text())
    proprios = {n for c in ("plugins", "temas") for n, r in politica[c].items() if r["camada"] == "propria"}
    marcador = a.checkout / "tmp" / "plugins" / "ultimo-clone.json"
    marcador.parent.mkdir(parents=True, exist_ok=True)
    return executar(local, plano, inventario, contratos, verificar_contratos, a.saida,
                    clonar_producao(a.checkout, marcador, a.reusar_clone), proprios)


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
