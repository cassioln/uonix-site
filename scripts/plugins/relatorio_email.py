#!/usr/bin/env python3
"""Relatório por e-mail de uma aplicação em produção (atualizar.sh aplicar-producao).

Monta, a partir dos arquivos que a esteira já grava na pasta do ensaio, um
e-mail HTML (com alternativa em texto) com o resultado: cada plugin com a
versão de antes e a que ficou instalada, as correções de segurança do
changelog, o smoke de produção, o backup e o que ficou fora do ciclo.

A versão "depois" vem do bloco "== versões finais" do log do job, ou seja, do
que o WP-CLI de produção leu no fim; nunca do plano. Sem log, ela aparece como
"não confirmada".

Uso:
  relatorio_email.py --dir DIR --aplicacao ARQ.json --saida-final N
                     [--log ARQ] [--previa ARQ.html] [--enviar]

Sem --enviar, só monta (e grava a prévia, se pedida). O envio usa SMTP com
SSL; a configuração vem do ambiente (o atualizar.sh carrega o .env):
  RELATORIO_SMTP_HOST (mail.ksio.dev), RELATORIO_SMTP_PORTA (465),
  RELATORIO_SMTP_USUARIO (cassio@ksio.dev), RELATORIO_SMTP_SENHA ou
  RELATORIO_SMTP_SENHA_FILE, RELATORIO_DE (o usuário), RELATORIO_PARA
  (o remetente; aceita vários, separados por vírgula). Sem RELATORIO_PARA,
  o relatório vai só para o remetente.
  Para testar no Mailpit do ambiente local: RELATORIO_SMTP_HOST=localhost,
  RELATORIO_SMTP_PORTA=1025, RELATORIO_SMTP_SEGURANCA=nenhuma. Sem SSL só é
  aceito para localhost, e aí a senha é dispensada e nunca enviada.
Saída: 0 enviado (ou montado, sem --enviar); 2 configuração incompleta;
1 falha no envio.
"""
from __future__ import annotations

import argparse
import datetime as dt
import html
import json
import os
import pathlib
import re
import smtplib
import ssl
import sys
from email.message import EmailMessage
from email.utils import formataddr, formatdate, make_msgid

EMPRESA = "KsioDev"
CLIENTE = "Uonix"
SITE = "uonix.com.br"

# Rótulo, cor e resumo de cada saída final do aplicar-producao.
RESULTADOS = {
    "0": ("Concluída", "#15803d", "#dcfce7",
          "Todas as atualizações foram aplicadas e o site passou na verificação final."),
    "20": ("Interrompida e revertida", "#b45309", "#fef3c7",
           "Uma etapa falhou na verificação e foi revertida; o site está no ar. A tabela mostra a versão que ficou em cada plugin."),
    "30": ("Recusada, sem alteração", "#475569", "#e2e8f0",
           "A aplicação foi recusada antes de alterar o site (versões divergentes, site já com falha ou manutenção indisponível)."),
    "40": ("Resultado não confirmado", "#b91c1c", "#fee2e2",
           "O processo no servidor não terminou de forma confirmada (conexão perdida ou interrupção). O estado está sendo verificado manualmente."),
    "50": ("Intervenção necessária", "#b91c1c", "#fee2e2",
           "O site não passou na verificação ou a reversão ficou incompleta. A equipe técnica já está atuando."),
}

# Situação de cada item, comparando a versão final com o lock.
SITUACOES = {
    "atualizado": ("Atualizado", "#15803d", "#dcfce7"),
    "mantido": ("Versão anterior mantida", "#b45309", "#fef3c7"),
    "verificar": ("Verificar", "#b91c1c", "#fee2e2"),
    "sem_confirmacao": ("Não confirmado", "#475569", "#e2e8f0"),
    "nao_aplicado": ("Não aplicado", "#475569", "#e2e8f0"),
}

# Saída 0 com algum item sem a versão nova confirmada no log: o site passou,
# mas o relatório não pode afirmar que "todas" foram aplicadas.
RESUMO_0_PARCIAL = ("O site passou na verificação final, mas a versão instalada de algum plugin não pôde ser "
                    "confirmada pelo relatório. A tabela mostra o que foi confirmado.")

# Nome comercial e função, para quem lê o relatório não precisar conhecer o slug.
# Plugin fora do mapa aparece pelo slug.
NOMES = {
    "woocommerce": ("WooCommerce", "Loja e catálogo de produtos"),
    "woo-rfq-for-woocommerce": ("NP Quote Request for WooCommerce", "Solicitação de orçamento"),
    "kadence-blocks": ("Kadence Blocks", "Blocos das páginas"),
    "woocommerce-products-filter": ("HUSKY Products Filter", "Filtros do catálogo"),
    "woocommerce-products-slider": ("Product Slider for WooCommerce", "Carrossel de produtos"),
    "woo-checkout-field-editor-pro": ("Checkout Field Editor", "Campos do formulário de orçamento"),
    "wb-custom-product-tabs-for-woocommerce": ("Custom Product Tabs", "Abas da página de produto"),
    "bnfw": ("BNFW", "Notificações por e-mail"),
    "compressx": ("CompressX", "Otimização de imagens"),
    "wp-crontrol": ("WP Crontrol", "Tarefas agendadas"),
    "fluentform": ("Fluent Forms", "Formulários de contato"),
    "fluentforms-pdf": ("Fluent Forms PDF", "PDFs dos formulários"),
    "fluent-smtp": ("FluentSMTP", "Envio de e-mails do site"),
    "seo-by-rank-math": ("Rank Math SEO", "SEO"),
    "seo-by-rank-math-pro": ("Rank Math SEO PRO", "SEO"),
    "google-site-kit": ("Site Kit by Google", "Analytics e Search Console"),
    "fast-indexing-api": ("Instant Indexing", "Indexação no Google"),
    "megamenu": ("Max Mega Menu", "Menu de navegação"),
    "conditional-menus": ("Conditional Menus", "Menus por página"),
    "pods": ("Pods", "Campos personalizados"),
    "pods-alternative-cache": ("Pods Alternative Cache", "Cache dos campos personalizados"),
    "loco-translate": ("Loco Translate", "Traduções"),
    "classic-editor": ("Classic Editor", "Editor de conteúdo"),
    "duplicate-page": ("Duplicate Page", "Duplicação de páginas"),
    "pdf-embedder": ("PDF Embedder", "PDFs nas páginas"),
    "user-role-editor": ("User Role Editor", "Permissões de usuários"),
    "was-this-article-helpful": ("Was This Article Helpful", "Avaliação de artigos"),
    "wp-super-cache": ("WP Super Cache", "Cache de páginas"),
}


class ConfiguracaoIncompleta(Exception):
    pass


def ler_json(caminho: pathlib.Path) -> dict:
    try:
        return json.loads(caminho.read_text())
    except (OSError, ValueError):
        return {}


def versoes_finais(log: str) -> dict[str, str]:
    """Lê o bloco "== versões finais" do log do job remoto (o último, se houver mais de um)."""
    versoes: dict[str, str] = {}
    dentro = False
    for linha in log.splitlines():
        if linha.startswith("== versões finais"):
            dentro, versoes = True, {}
            continue
        if dentro:
            m = re.match(r"^  (\S+) ?(\S*)\s*$", linha)
            if not m:
                dentro = False
                continue
            versoes[m.group(1)] = m.group(2)
    return versoes


def situacao(item: dict, finais: dict[str, str]) -> str:
    if item["slug"] not in finais:
        return "sem_confirmacao"
    final = finais[item["slug"]]
    if final == item["para"]:
        return "atualizado"
    if final == item["de"]:
        return "mantido"
    return "verificar"


def notas(plano_item: dict | None) -> list[str]:
    if not plano_item:
        return []
    linhas = plano_item.get("changelog")
    if not isinstance(linhas, list):
        return []
    limpas = [re.sub(r"^\W+", "", l).strip() for l in linhas if isinstance(l, str)]
    return [l for l in limpas if l][:2]


def motivo_fora(motivo: str) -> str:
    """O motivo do plano em linguagem de cliente; a quarentena vira "período de observação"."""
    m = re.search(r"libera em (\d{4})-(\d{2})-(\d{2})", motivo)
    if motivo.startswith("quarentena") and m:
        return (f"versão lançada há poucos dias; por segurança, entra depois do período de observação, "
                f"a partir de {m.group(3)}/{m.group(2)}/{m.group(1)}.")
    if motivo.startswith("quarentena"):
        return "versão lançada há poucos dias; entra depois do período de observação."
    return motivo


def montar(pasta: pathlib.Path, aplicacao: dict, saida_final: str, log: str, agora: dt.datetime | None = None) -> dict:
    lock = ler_json(pasta / "lock.json")
    plano = {i.get("slug"): i for i in ler_json(pasta / "plano.json").get("itens", [])}
    stamp = aplicacao.get("backup", "")
    smoke = ler_json(pasta / f"smoke-producao-{stamp}.json") if stamp else {}
    saida = saida_final if saida_final in RESULTADOS else "40"
    finais = versoes_finais(log)
    itens = []
    for item in lock.get("itens", []):
        p = plano.get(item["slug"])
        itens.append({
            "slug": item["slug"],
            "nome": NOMES.get(item["slug"], (item["slug"], ""))[0],
            "funcao": NOMES.get(item["slug"], ("", ""))[1],
            "critico": item.get("camada") == "critica",
            "de": item["de"],
            "para": item["para"],
            # Toda saída 30 do job acontece antes de qualquer mudança: nada foi aplicado.
            "final": item["de"] if saida == "30" else finais.get(item["slug"], ""),
            "situacao": "nao_aplicado" if saida == "30" else situacao(item, finais),
            "seguranca": bool(p and p.get("seguranca")),
            "notas": notas(p),
        })
    falhas_smoke = [f"{nome}: {r.get('detalhe', '')}" for nome, r in smoke.items() if not r.get("ok")]
    executado = aplicacao.get("executado_em") or aplicacao.get("aplicado_em") or ""
    try:
        quando = dt.datetime.strptime(executado, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=dt.timezone.utc).astimezone()
    except ValueError:
        quando = agora or dt.datetime.now().astimezone()
    return {
        "saida": saida,
        "quando": quando,
        "backup": stamp,
        "lock": aplicacao.get("lock_sha12", ""),
        "itens": itens,
        "fora": [{"nome": NOMES.get(f.get("slug", ""), (f.get("slug", ""), ""))[0], "de": f.get("de", ""),
                  "para": f.get("para", ""), "motivo": motivo_fora(f.get("motivo", ""))}
                 for f in lock.get("fora", [])],
        "smoke_total": len(smoke),
        "smoke_falhas": falhas_smoke,
    }


def resumo(ctx: dict) -> str:
    if ctx["saida"] == "0" and any(i["situacao"] != "atualizado" for i in ctx["itens"]):
        return RESUMO_0_PARCIAL
    return RESULTADOS[ctx["saida"]][3]


def titulo_itens(ctx: dict) -> str:
    return "Itens previstos (nenhum foi aplicado)" if ctx["saida"] == "30" else "O que foi feito"


def assunto(ctx: dict) -> str:
    rotulo = RESULTADOS[ctx["saida"]][0]
    return f"[{CLIENTE}] Atualização de plugins em {ctx['quando']:%d/%m/%Y}: {rotulo}"


def _selo(texto: str, cor: str, fundo: str) -> str:
    return (f'<span style="display:inline-block;padding:3px 10px;border-radius:999px;background:{fundo};'
            f'color:{cor};font-size:12px;font-weight:600;">{html.escape(texto)}</span>')


def corpo_html(ctx: dict) -> str:
    e = html.escape
    rotulo, cor, fundo, _ = RESULTADOS[ctx["saida"]]
    atualizados = sum(1 for i in ctx["itens"] if i["situacao"] == "atualizado")
    com_seguranca = sum(1 for i in ctx["itens"] if i["seguranca"] and i["situacao"] == "atualizado")
    if ctx["smoke_total"]:
        verdes = ctx["smoke_total"] - len(ctx["smoke_falhas"])
        smoke_txt, smoke_legenda = f"{verdes} de {ctx['smoke_total']}", "verificações do site ok"
    else:
        smoke_txt, smoke_legenda = "—", "verificação do site não executada"

    def indicador(valor: str, legenda: str) -> str:
        return (f'<td width="33%" style="padding:14px 8px;text-align:center;border:1px solid #e2e8f0;border-radius:8px;">'
                f'<div style="font-size:22px;font-weight:700;color:#0f172a;">{e(valor)}</div>'
                f'<div style="font-size:12px;color:#64748b;margin-top:2px;">{e(legenda)}</div></td>')

    linhas = []
    for i in ctx["itens"]:
        s_txt, s_cor, s_fundo = SITUACOES[i["situacao"]]
        final = i["final"] or "—"
        nota = ""
        aplicado = i["situacao"] == "atualizado"
        # Selo e notas do fabricante descrevem a versão nova: só valem se ela
        # está instalada. Sem isso, a correção é pendente.
        if i["seguranca"] and aplicado:
            nota += _selo("Correção de segurança", "#1d4ed8", "#dbeafe") + " "
        elif i["seguranca"]:
            nota += _selo("Correção de segurança pendente", "#b45309", "#fef3c7") + " "
        if i["notas"] and aplicado:
            nota += "".join(f'<div style="font-size:12px;color:#64748b;margin-top:4px;font-style:italic;">{e(n)}</div>'
                            for n in i["notas"])
        papel = " · ".join(x for x in (i["funcao"], "essencial" if i["critico"] else "") if x)
        bloco_nota = f'<div style="margin-top:6px;">{nota}</div>' if nota else ""
        linhas.append(
            '<tr>'
            f'<td style="padding:12px 10px;border-bottom:1px solid #e2e8f0;vertical-align:top;">'
            f'<div style="font-weight:600;color:#0f172a;">{e(i["nome"])}</div>'
            f'<div style="font-size:12px;color:#64748b;">{e(papel)}</div>{bloco_nota}</td>'
            f'<td style="padding:12px 10px;border-bottom:1px solid #e2e8f0;vertical-align:top;font-family:Menlo,Consolas,monospace;font-size:13px;color:#334155;">'
            f'{e(i["de"])} &rarr; {e(final)}</td>'
            f'<td style="padding:12px 10px;border-bottom:1px solid #e2e8f0;vertical-align:top;text-align:right;">{_selo(s_txt, s_cor, s_fundo)}</td>'
            '</tr>')

    blocos_extra = []
    if ctx["smoke_falhas"]:
        itens = "".join(f"<li>{e(f)}</li>" for f in ctx["smoke_falhas"])
        blocos_extra.append(
            f'<h3 style="margin:28px 0 8px;font-size:15px;color:#b91c1c;">Pontos com falha na verificação</h3>'
            f'<ul style="margin:0;padding-left:18px;font-size:13px;color:#334155;line-height:1.6;">{itens}</ul>')
    if ctx["fora"]:
        itens = "".join(
            f'<li><strong>{e(f["nome"])}</strong> {e(f["de"])} &rarr; {e(f["para"])}: {e(f["motivo"])}</li>' for f in ctx["fora"])
        blocos_extra.append(
            f'<h3 style="margin:28px 0 8px;font-size:15px;color:#0f172a;">Aguardando o próximo ciclo</h3>'
            f'<ul style="margin:0;padding-left:18px;font-size:13px;color:#334155;line-height:1.6;">{itens}</ul>')

    return f"""<!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{e(assunto(ctx))}</title></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
<div style="display:none;max-height:0;overflow:hidden;">{e(rotulo)}: {atualizados} de {len(ctx["itens"])} plugin(s) atualizados em {SITE}.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;">
<tr><td style="background:#0f172a;padding:22px 28px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
    <td style="color:#ffffff;font-size:20px;font-weight:700;letter-spacing:.3px;">{EMPRESA}</td>
    <td align="right" style="color:#94a3b8;font-size:12px;">Relatório de manutenção</td>
  </tr></table>
</td></tr>
<tr><td style="padding:28px 28px 8px;">
  <div style="font-size:13px;color:#64748b;">{e(CLIENTE)} &middot; {e(SITE)}</div>
  <h1 style="margin:6px 0 12px;font-size:22px;color:#0f172a;">Atualização de plugins</h1>
  {_selo(rotulo, cor, fundo)}
  <p style="margin:14px 0 0;font-size:14px;line-height:1.6;color:#334155;">{e(resumo(ctx))}</p>
  <p style="margin:6px 0 0;font-size:13px;color:#64748b;">Executada em {ctx["quando"]:%d/%m/%Y às %H:%M}.</p>
</td></tr>
<tr><td style="padding:20px 28px 4px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="6"><tr>
    {indicador(f"{atualizados}/{len(ctx['itens'])}", "plugins atualizados")}
    {indicador(str(com_seguranca), "correções de segurança")}
    {indicador(smoke_txt, smoke_legenda)}
  </tr></table>
</td></tr>
<tr><td style="padding:20px 28px 0;">
  <h3 style="margin:0 0 8px;font-size:15px;color:#0f172a;">{e(titulo_itens(ctx))}</h3>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
    <tr>
      <th align="left" width="56%" style="padding:8px 10px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#64748b;border-bottom:2px solid #e2e8f0;">Plugin</th>
      <th align="left" width="26%" style="padding:8px 10px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#64748b;border-bottom:2px solid #e2e8f0;">Versão</th>
      <th align="right" width="18%" style="padding:8px 10px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#64748b;border-bottom:2px solid #e2e8f0;">Situação</th>
    </tr>
    {"".join(linhas)}
  </table>
  {"".join(blocos_extra)}
</td></tr>
<tr><td style="padding:28px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border-radius:8px;">
  <tr><td style="padding:16px 18px;font-size:13px;line-height:1.7;color:#334155;">
    <strong style="color:#0f172a;">Como foi feito</strong><br>
    Cada versão foi ensaiada antes numa cópia do site, com os fluxos principais verificados.
    Em produção: backup do banco e das pastas, modo de manutenção, aplicação em etapas com verificação
    a cada etapa e reversão automática em caso de falha.<br>
    <span style="color:#64748b;">Backup: {e(ctx["backup"] or "—")} &middot; ensaio {e(ctx["lock"] or "—")}</span>
  </td></tr></table>
</td></tr>
<tr><td style="padding:18px 28px;border-top:1px solid #e2e8f0;font-size:12px;color:#94a3b8;line-height:1.6;">
  {EMPRESA} &middot; <a href="mailto:cassio@ksio.dev" style="color:#64748b;">cassio@ksio.dev</a><br>
  Relatório gerado automaticamente pela esteira de atualização.
</td></tr>
</table>
</td></tr></table>
</body></html>
"""


def corpo_texto(ctx: dict) -> str:
    rotulo = RESULTADOS[ctx["saida"]][0]
    linhas = [f"{EMPRESA} | Relatório de manutenção", f"{CLIENTE} ({SITE}): atualização de plugins", "",
              f"Resultado: {rotulo}", resumo(ctx), f"Executada em {ctx['quando']:%d/%m/%Y às %H:%M}.", "",
              f"{titulo_itens(ctx)}:"]
    for i in ctx["itens"]:
        aplicado = i["situacao"] == "atualizado"
        seg = (" [correção de segurança]" if aplicado else " [correção de segurança pendente]") if i["seguranca"] else ""
        papel = f" ({i['funcao']})" if i["funcao"] else ""
        linhas.append(f"- {i['nome']}{papel}: {i['de']} -> {i['final'] or '?'}, {SITUACOES[i['situacao']][0]}{seg}")
        linhas += [f"    {n}" for n in i["notas"]] if aplicado else []
    if ctx["smoke_total"]:
        linhas += ["", f"Verificação do site: {ctx['smoke_total'] - len(ctx['smoke_falhas'])} de {ctx['smoke_total']} ok"]
        linhas += [f"  falha: {f}" for f in ctx["smoke_falhas"]]
    if ctx["fora"]:
        linhas += ["", "Aguardando o próximo ciclo:"]
        linhas += [f"- {f['nome']} {f['de']} -> {f['para']}: {f['motivo']}" for f in ctx["fora"]]
    linhas += ["", f"Backup: {ctx['backup'] or '-'} | ensaio {ctx['lock'] or '-'}", "",
               f"{EMPRESA} | cassio@ksio.dev", "Relatório gerado automaticamente pela esteira de atualização."]
    return "\n".join(linhas) + "\n"


LOCAIS = ("localhost", "127.0.0.1", "::1")


def configuracao(env: dict[str, str]) -> dict:
    host = env.get("RELATORIO_SMTP_HOST", "mail.ksio.dev")
    seguranca = env.get("RELATORIO_SMTP_SEGURANCA", "ssl")
    if seguranca not in ("ssl", "nenhuma"):
        raise ConfiguracaoIncompleta("RELATORIO_SMTP_SEGURANCA deve ser 'ssl' ou 'nenhuma'")
    if seguranca == "nenhuma" and host not in LOCAIS:
        raise ConfiguracaoIncompleta(f"sem SSL só para localhost (Mailpit), não para {host}")
    usuario = env.get("RELATORIO_SMTP_USUARIO", "cassio@ksio.dev")
    senha = "" if seguranca == "nenhuma" else env.get("RELATORIO_SMTP_SENHA", "")
    arquivo = env.get("RELATORIO_SMTP_SENHA_FILE", "")
    if seguranca == "ssl" and not senha and arquivo:
        try:
            senha = pathlib.Path(arquivo).expanduser().read_text().strip()
        except OSError as erro:
            raise ConfiguracaoIncompleta(f"não li RELATORIO_SMTP_SENHA_FILE: {erro}") from erro
    if seguranca == "ssl" and not senha:
        raise ConfiguracaoIncompleta("defina RELATORIO_SMTP_SENHA ou RELATORIO_SMTP_SENHA_FILE no .env")
    de = env.get("RELATORIO_DE", usuario)
    para = [p.strip() for p in env.get("RELATORIO_PARA", de).split(",") if p.strip()]
    try:
        porta = int(env.get("RELATORIO_SMTP_PORTA", "465"))
    except ValueError as erro:
        raise ConfiguracaoIncompleta("RELATORIO_SMTP_PORTA não é um número") from erro
    return {"host": host, "porta": porta, "seguranca": seguranca,
            "usuario": usuario, "senha": senha, "de": de, "para": para}


def mensagem(ctx: dict, de: str, para: list[str]) -> EmailMessage:
    msg = EmailMessage()
    msg["Subject"] = assunto(ctx)
    msg["From"] = formataddr((EMPRESA, de))
    msg["To"] = ", ".join(para)
    msg["Date"] = formatdate(localtime=True)
    msg["Message-ID"] = make_msgid(domain=de.rsplit("@", 1)[-1])
    msg.set_content(corpo_texto(ctx))
    msg.add_alternative(corpo_html(ctx), subtype="html")
    return msg


def enviar(msg: EmailMessage, cfg: dict, smtp_ssl=smtplib.SMTP_SSL, smtp_local=smtplib.SMTP) -> None:
    if cfg["seguranca"] == "nenhuma":
        conexao = smtp_local(cfg["host"], cfg["porta"], timeout=30)
    else:
        conexao = smtp_ssl(cfg["host"], cfg["porta"], context=ssl.create_default_context(), timeout=30)
    with conexao:
        if cfg["senha"]:
            conexao.login(cfg["usuario"], cfg["senha"])
        conexao.send_message(msg, from_addr=cfg["de"], to_addrs=cfg["para"])


def main(argv: list[str]) -> int:
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--dir", type=pathlib.Path, required=True, help="pasta do ensaio (lock.json, plano.json)")
    p.add_argument("--aplicacao", type=pathlib.Path, required=True, help="aplicacao-<backup>.json")
    p.add_argument("--saida-final", required=True, help="saída do aplicar-producao (0, 20, 30, 40, 50)")
    p.add_argument("--log", type=pathlib.Path, help="log do job remoto (com o bloco '== versões finais')")
    p.add_argument("--previa", type=pathlib.Path, help="grava o HTML aqui")
    p.add_argument("--enviar", action="store_true")
    a = p.parse_args(argv)
    log = a.log.read_text(errors="replace") if a.log and a.log.is_file() else ""
    ctx = montar(a.dir, ler_json(a.aplicacao), a.saida_final, log)
    if a.previa:
        a.previa.write_text(corpo_html(ctx))
        print(f"prévia: {a.previa}")
    if not a.enviar:
        return 0
    try:
        cfg = configuracao(dict(os.environ))
    except ConfiguracaoIncompleta as erro:
        print(f"relatório não enviado: {erro}", file=sys.stderr)
        return 2
    try:
        enviar(mensagem(ctx, cfg["de"], cfg["para"]), cfg)
    except (OSError, smtplib.SMTPException) as erro:
        print(f"relatório não enviado: {type(erro).__name__}: {erro}", file=sys.stderr)
        return 1
    except Exception as erro:  # noqa: BLE001 - a mensagem poderia citar a senha (ex.: UnicodeEncodeError no login)
        print(f"relatório não enviado: {type(erro).__name__}", file=sys.stderr)
        return 1
    print(f"relatório enviado para {', '.join(cfg['para'])}: {assunto(ctx)}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
