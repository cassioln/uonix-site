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
  o relatório vai só para o remetente. RELATORIO_PARA_TECNICO
  (uonix@ksio.dev) recebe o relatório técnico. RELATORIO_CCO (uonix@ksio.dev)
  recebe cópia oculta do e-mail do cliente: só no envelope SMTP, nunca no
  cabeçalho, então o cliente não vê.

O cliente só recebe o e-mail quando tudo deu certo: saída 0, todo plugin
confirmado na versão nova e smoke de produção todo verde. Em qualquer outro
caso, o cliente não é notificado e o relatório técnico (motivos, versões,
registro, comandos de diagnóstico e fim do log) vai para RELATORIO_PARA_TECNICO.
  Para testar no Mailpit do ambiente local: RELATORIO_SMTP_HOST=localhost,
  RELATORIO_SMTP_PORTA=1025, RELATORIO_SMTP_SEGURANCA=nenhuma. Sem SSL só é
  aceito para localhost, e aí a senha é dispensada e nunca enviada.
Saída: 0 enviado (ou montado, sem --enviar); 2 configuração incompleta;
1 falha no envio.
"""
from __future__ import annotations

import argparse
import base64
import urllib.parse
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

MARCA = "ksiodev"
ASSINANTE = "Cassio Lima"
SLOGAN = "Soluções tecnológicas, automação & IA"
SITE_EMPRESA = "ksio.dev"
EMAIL_ASSINANTE = "cassio@ksio.dev"
# Linguagem visual do ksio.dev (tokens do site, em ksiodev-site).
FUNDO = "#080d12"
TEXTO_CLARO = "#edf4f8"
SECUNDARIO = "#a7b8c6"
MUTED = "#8297a8"
LINHA = "#283948"
CIANO = "#00c9eb"
CIANO_TEXTO = "#007c91"  # o ciano escurecido, legível sobre branco
BORDA = "#dfe6ec"
DISPLAY = "'Sora','Avenir Next','Segoe UI',Helvetica,Arial,sans-serif"
CORPO = "'IBM Plex Sans','Helvetica Neue',Helvetica,Arial,sans-serif"
MONO = "'IBM Plex Mono',Menlo,Consolas,monospace"
# O símbolo <K> do ksio.dev em PNG (rsvg-convert de assets/brand/ksio-mark.svg, 3x),
# porque SVG não aparece no Gmail nem no Outlook. Vai anexado inline (CID).
LOGO = pathlib.Path(__file__).resolve().parent / "marca" / "ksio-mark.png"
LOGO_CID = "ksio-mark@ksio.dev"
TECNICO_PADRAO = "uonix@ksio.dev"
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
    "atualizado": ("Atualizado", "#00697a", "#dff7fb"),
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
    """As novidades da versão nova, como o fabricante escreveu. O campo "changelog" do
    plano não serve: é só o filtro de segurança e risco do intervalo inteiro."""
    if not plano_item:
        return []
    linhas = plano_item.get("novidades")
    if not isinstance(linhas, list):
        return []
    limpas = [re.sub(r"^\W+", "", l).strip() for l in linhas if isinstance(l, str)]
    return [l for l in limpas if not _ruido(l)]


# O plano quebra o HTML do changelog em linha a cada tag, então link e rótulo viram
# linhas soltas: "#69649" (o link do PR), "WooCommerce" (o <strong> do produto),
# "See changelog for all versions" e "." (vistos no WooCommerce 11.2.1, 2026-10-16).
# Só a exibição filtra; a detecção de segurança do plano segue com todas as linhas.
_VER_CHANGELOG = re.compile(r"^(see|view|read)\b.{0,20}\bchangelog\b"
                            r"|^(props|thanks)\b|^full changelog\b|^release notes\b", re.I)
# Chinês, japonês e coreano não separam palavras com espaço: uma frase inteira
# seria lida como "palavra solta" (revisão do #440).
_CJK = re.compile(r"[\u3040-\u30ff\u3400-\u4dbf\u4e00-\u9fff\uac00-\ud7af]{4,}")


def _ruido(linha: str) -> bool:
    return (not re.search(r"[^\W\d_]", linha)                       # sem letra: "69649", "."
            or (not re.search(r"\s", linha) and not _CJK.search(linha))  # palavra solta: "WooCommerce"
            # rótulo de link ("See changelog for all versions"): só se for curto, para
            # não derrubar uma frase real que comece com "Read the changelog…"
            or (bool(_VER_CHANGELOG.match(linha)) and len(linha.split()) <= 6))


# Quantos itens do changelog o e-mail mostra por plugin; o resto fica no link.
MAX_NOTAS = 8


def pagina_wporg(slug: str, tipo: str = "plugin") -> str:
    secao = "themes" if tipo == "tema" else "plugins"
    return f"https://wordpress.org/{secao}/{urllib.parse.quote(slug)}/"


def no_wporg(plano_item: dict | None) -> bool:
    """A API do wordpress.org respondeu para o plugin: a página oficial existe (plugin pago não tem)."""
    return bool(plano_item) and plano_item.get("changelog") not in (None, "indisponível")


def motivo_fora(motivo: str) -> str | None:
    """O motivo em linguagem de cliente. Só a quarentena com data de liberação tem tradução.
    Os outros motivos do plano (camada que não se atualiza, major bloqueado, código que não
    carrega no local, e a quarentena "não verificável", que depende de conferência manual) são
    internos e ficam só no relatório técnico. A idade da versão não entra: o lock vale até 72 h,
    e "há N dias" estaria defasado quando o e-mail sai."""
    m = re.search(r"libera em (\d{4})-(\d{2})-(\d{2})", motivo)
    if not motivo.startswith("quarentena") or not m:
        return None
    return f"versão lançada recentemente; entra no site a partir de {m.group(3)}/{m.group(2)}/{m.group(1)}."


def dias_quarentena(ctx: dict) -> int:
    """O prazo da política, lido do motivo do plano ("lançada há 6 de 7 dias")."""
    prazos = [int(m.group(1)) for f in ctx["fora"] for m in [re.search(r"de (\d+) dias", f.get("motivo", ""))] if m]
    return max(prazos) if prazos else 7


def explicacao_quarentena(ctx: dict) -> str:
    return (f"Por segurança, versões novas de plugins essenciais só são aplicadas depois de {dias_quarentena(ctx)} dias "
            "do lançamento. Nesse período, problemas que surgem logo após uma versão sair costumam ser "
            "corrigidos pelo próprio fabricante, e o site não fica exposto a eles. "
            "Quando o fabricante informa que a versão corrige uma falha de segurança, ela é aplicada sem esperar esse prazo.")


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
            # Toda saída 30 do job acontece antes de qualquer mudança: nada foi
            # aplicado, e a versão de produção não foi lida (no drift, nem é a "de").
            "final": "" if saida == "30" else finais.get(item["slug"], ""),
            "situacao": "nao_aplicado" if saida == "30" else situacao(item, finais),
            "seguranca": bool(p and p.get("seguranca")),
            "notas": notas(p),
            "pagina": pagina_wporg(item["slug"], item.get("tipo", "plugin")) if no_wporg(p) else "",
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
        "registro": aplicacao,
        "itens": itens,
        "fora": [{"slug": f.get("slug", ""), "nome": NOMES.get(f.get("slug", ""), (f.get("slug", ""), ""))[0],
                  "de": f.get("de", ""), "para": f.get("para", ""), "motivo": f.get("motivo", ""),
                  "motivo_cliente": motivo_fora(f.get("motivo", ""))}
                 for f in lock.get("fora", [])],
        "smoke_total": len(smoke),
        "smoke_falhas": falhas_smoke,
    }


def resumo(ctx: dict) -> str:
    if ctx["saida"] == "0" and any(i["situacao"] != "atualizado" for i in ctx["itens"]):
        return RESUMO_0_PARCIAL
    return RESULTADOS[ctx["saida"]][3]


def pendencia(item: dict) -> str:
    """Correção não aplicada com certeza é "pendente"; com estado desconhecido, "a confirmar"."""
    return "pendente" if item["situacao"] in ("mantido", "nao_aplicado") else "a confirmar"


def titulo_itens(ctx: dict) -> str:
    return "Itens previstos (nenhum foi aplicado)" if ctx["saida"] == "30" else "O que foi feito"


def assunto(ctx: dict) -> str:
    # Só o sucesso completo vai ao cliente, então o assunto não carrega estado;
    # a data separa os ciclos na caixa de entrada.
    return f"[{MARCA}] Relatório de manutenção — Site {CLIENTE} ({ctx['quando']:%d/%m/%Y})"


def _selo(texto: str, cor: str, fundo: str) -> str:
    return (f'<span style="display:inline-block;padding:3px 10px;border-radius:4px;background:{fundo};'
            f'color:{cor};font-family:{CORPO};font-size:12px;font-weight:600;">{html.escape(texto)}</span>')


def _rotulo_secao(texto: str) -> str:
    return (f'<div style="font-family:{MONO};font-size:11px;letter-spacing:1.5px;text-transform:uppercase;'
            f'color:{CIANO_TEXTO};margin:0 0 10px;">{html.escape(texto)}</div>')


def marca_html(logo_src: str | None, tamanho: int = 22) -> str:
    """Logo como no ksio.dev: o símbolo <K> e "ksio" com ".dev" em ciano, sobre fundo escuro."""
    altura = round(tamanho * 1.27)
    imagem = (f'<img src="{html.escape(logo_src)}" width="{round(altura * 171 / 120)}" height="{altura}" alt="" '
              f'style="display:inline-block;vertical-align:middle;border:0;margin-right:10px;">') if logo_src else ""
    return (f'{imagem}<span style="font-family:{DISPLAY};font-size:{tamanho}px;font-weight:600;letter-spacing:-.5px;'
            f'color:{TEXTO_CLARO};vertical-align:middle;">ksio<span style="color:{CIANO};">.dev</span></span>')


def assinatura_html(logo_src: str | None) -> str:
    e = html.escape
    imagem = (f'<td width="52" style="vertical-align:top;padding-right:14px;"><img src="{e(logo_src)}" width="40" height="28" '
              f'alt="" style="display:block;border:0;"></td>') if logo_src else ""
    return (f'<table role="presentation" cellpadding="0" cellspacing="0"><tr>{imagem}'
            f'<td style="vertical-align:top;border-left:1px solid {LINHA};padding-left:14px;">'
            f'<div style="font-family:{DISPLAY};font-size:16px;font-weight:600;color:{TEXTO_CLARO};letter-spacing:-.2px;">{e(ASSINANTE)}</div>'
            f'<div style="font-family:{CORPO};font-size:12px;color:{SECUNDARIO};margin-top:3px;">'
            f'{e(MARCA)} &middot; {e(SLOGAN)}</div>'
            f'<div style="font-family:{CORPO};font-size:12px;margin-top:8px;">'
            f'<a href="https://{SITE_EMPRESA}" style="color:{CIANO};text-decoration:none;">{SITE_EMPRESA}</a>'
            f' <span style="color:{LINHA};">&middot;</span> '
            f'<a href="mailto:{EMAIL_ASSINANTE}" style="color:{CIANO};text-decoration:none;">{EMAIL_ASSINANTE}</a></div>'
            f'</td></tr></table>')


def assinatura_texto() -> str:
    return f"--\n{ASSINANTE}\n{MARCA} | {SLOGAN}\nhttps://{SITE_EMPRESA} | {EMAIL_ASSINANTE}"


def nome_html(item: dict) -> str:
    e = html.escape
    if not item.get("pagina"):
        return e(item["nome"])
    return (f'<a href="{e(item["pagina"])}" style="color:{FUNDO};text-decoration:none;'
            f'border-bottom:1px solid {CIANO};">{e(item["nome"])}</a>')


def novidades_html(item: dict) -> str:
    """O changelog da versão nova, como o fabricante escreveu, com link para o completo."""
    e = html.escape
    estilo_link = f"color:{CIANO_TEXTO};text-decoration:none;"
    if not item["notas"]:
        if not item.get("pagina"):
            return ""
        return (f'<div style="font-size:12px;color:{MUTED};margin-top:6px;">Novidades desta versão: '
                f'<a href="{e(item["pagina"])}#developers" style="{estilo_link}">ver na página do plugin</a></div>')
    itens = "".join(f'<li style="margin:2px 0;">{e(n)}</li>' for n in item["notas"][:MAX_NOTAS])
    resto = len(item["notas"]) - MAX_NOTAS
    mais = ""
    if item.get("pagina"):
        texto = f"e mais {resto} alteração(ões): ver o changelog completo" if resto > 0 else "ver o changelog completo"
        mais = f'<div style="margin-top:4px;"><a href="{e(item["pagina"])}#developers" style="{estilo_link}">{e(texto)}</a></div>'
    elif resto > 0:
        mais = f'<div style="margin-top:4px;">e mais {resto} alteração(ões)</div>'
    return (f'<div style="font-size:12px;color:{MUTED};margin-top:8px;line-height:1.5;">'
            f'<div style="font-family:{MONO};font-size:10px;letter-spacing:1px;text-transform:uppercase;color:{CIANO_TEXTO};">'
            f'Novidades da versão {e(item["para"])}</div>'
            f'<ul style="margin:4px 0 0;padding-left:16px;">{itens}</ul>{mais}</div>')


def fora_cliente(ctx: dict) -> list[dict]:
    """Só o que de fato aguarda o próximo ciclo (período de observação); o resto é assunto técnico."""
    return [f for f in ctx["fora"] if f["motivo_cliente"]]


def corpo_html(ctx: dict, logo_src: str | None = f"cid:{LOGO_CID}") -> str:
    e = html.escape
    rotulo = RESULTADOS[ctx["saida"]][0]
    atualizados = sum(1 for i in ctx["itens"] if i["situacao"] == "atualizado")
    com_seguranca = sum(1 for i in ctx["itens"] if i["seguranca"] and i["situacao"] == "atualizado")
    if ctx["smoke_total"]:
        verdes = ctx["smoke_total"] - len(ctx["smoke_falhas"])
        smoke_txt, smoke_legenda = f"{verdes}/{ctx['smoke_total']}", "verificações do site ok"
    else:
        smoke_txt, smoke_legenda = "—", "verificação do site não executada"

    def indicador(valor: str, legenda: str) -> str:
        return (f'<td width="33%" style="padding:16px 10px 14px;text-align:center;background:#ffffff;'
                f'border:1px solid {BORDA};border-top:3px solid {CIANO};border-radius:4px;">'
                f'<div style="font-family:{DISPLAY};font-size:26px;font-weight:600;letter-spacing:-.8px;color:{FUNDO};">{e(valor)}</div>'
                f'<div style="font-family:{CORPO};font-size:12px;color:{MUTED};margin-top:4px;">{e(legenda)}</div></td>')

    linhas = []
    for i in ctx["itens"]:
        s_txt, s_cor, s_fundo = SITUACOES[i["situacao"]]
        final = i["final"] or "—"
        nota = ""
        aplicado = i["situacao"] == "atualizado"
        # Selo e notas do fabricante descrevem a versão nova: só valem se ela
        # está instalada. Sem isso, a correção é pendente.
        if i["seguranca"] and aplicado:
            nota += _selo("Correção de segurança", CIANO, FUNDO) + " "
        elif i["seguranca"]:
            nota += _selo(f"Correção de segurança {pendencia(i)}", "#b45309", "#fef3c7") + " "
        if aplicado:
            nota += novidades_html(i)
        papel = " · ".join(x for x in (i["funcao"], "essencial" if i["critico"] else "") if x)
        bloco_nota = f'<div style="margin-top:8px;">{nota}</div>' if nota else ""
        linhas.append(
            '<tr>'
            f'<td style="padding:14px 10px 14px 0;border-bottom:1px solid {BORDA};vertical-align:top;">'
            f'<div style="font-weight:600;color:{FUNDO};">{nome_html(i)}</div>'
            f'<div style="font-size:12px;color:{MUTED};margin-top:1px;">{e(papel)}</div>{bloco_nota}</td>'
            f'<td style="padding:14px 10px;border-bottom:1px solid {BORDA};vertical-align:top;font-family:{MONO};font-size:13px;color:#334155;">'
            f'{e(i["de"])} <span style="color:{CIANO_TEXTO};">&rarr;</span> {e(final)}</td>'
            f'<td style="padding:14px 0 14px 10px;border-bottom:1px solid {BORDA};vertical-align:top;text-align:right;">{_selo(s_txt, s_cor, s_fundo)}</td>'
            '</tr>')

    blocos_extra = []
    if ctx["smoke_falhas"]:
        itens = "".join(f"<li>{e(f)}</li>" for f in ctx["smoke_falhas"])
        blocos_extra.append(
            f'<div style="margin-top:28px;">{_rotulo_secao("Pontos com falha na verificação")}'
            f'<ul style="margin:0;padding-left:18px;font-size:13px;color:#334155;line-height:1.6;">{itens}</ul></div>')
    if fora_cliente(ctx):
        itens = "".join(
            f'<li><strong style="color:{FUNDO};">{e(f["nome"])}</strong> {e(f["de"])} &rarr; {e(f["para"])}: {e(f["motivo_cliente"])}</li>'
            for f in fora_cliente(ctx))
        blocos_extra.append(
            f'<div style="margin-top:28px;">{_rotulo_secao("Quarentena")}'
            f'<p style="margin:0 0 8px;font-size:13px;line-height:1.6;color:#334155;">{e(explicacao_quarentena(ctx))}</p>'
            f'<ul style="margin:0;padding-left:18px;font-size:13px;color:#334155;line-height:1.7;">{itens}</ul></div>')

    return f"""<!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light only"><title>{e(assunto(ctx))}</title></head>
<body style="margin:0;padding:0;background:#eef2f5;font-family:{CORPO};">
<div style="display:none;max-height:0;overflow:hidden;">{e(rotulo)}: {atualizados} de {len(ctx["itens"])} plugin(s) atualizados em {SITE}.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2f5;padding:24px 12px;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:6px;overflow:hidden;border:1px solid {BORDA};">
<tr><td bgcolor="{FUNDO}" style="background:{FUNDO};padding:24px 30px 0;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>
    <td>{marca_html(logo_src)}</td>
    <td align="right" style="font-family:{MONO};font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:{MUTED};">Relatório de manutenção</td>
  </tr></table>
</td></tr>
<tr><td bgcolor="{FUNDO}" style="background:{FUNDO};padding:30px 30px 30px;border-bottom:3px solid {CIANO};">
  <div style="font-family:{CORPO};font-size:13px;color:{SECUNDARIO};">{e(CLIENTE)} &middot; {e(SITE)}</div>
  <h1 style="margin:8px 0 10px;font-family:{DISPLAY};font-size:30px;line-height:1.15;font-weight:500;letter-spacing:-1px;color:{TEXTO_CLARO};">
    Atualização de plugins <span style="color:{CIANO};">{e(rotulo.lower())}.</span></h1>
  <div style="font-family:{CORPO};font-size:13px;color:{MUTED};">Executada em {ctx["quando"]:%d/%m/%Y às %H:%M}</div>
</td></tr>
<tr><td style="padding:28px 30px 4px;">
  <p style="margin:0;font-size:15px;line-height:1.6;color:{FUNDO};">Olá, equipe {e(CLIENTE)}!</p>
  <p style="margin:8px 0 0;font-size:15px;line-height:1.6;color:#334155;">{e(resumo(ctx))}</p>
</td></tr>
<tr><td style="padding:18px 24px 4px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="6"><tr>
    {indicador(f"{atualizados}/{len(ctx['itens'])}", "plugins atualizados")}
    {indicador(str(com_seguranca), "correções de segurança")}
    {indicador(smoke_txt, smoke_legenda)}
  </tr></table>
</td></tr>
<tr><td style="padding:26px 30px 0;">
  {_rotulo_secao(titulo_itens(ctx))}
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;font-family:{CORPO};">
    <tr>
      <th align="left" width="56%" style="padding:0 10px 8px 0;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.8px;color:{MUTED};border-bottom:1px solid {BORDA};">Plugin</th>
      <th align="left" width="26%" style="padding:0 10px 8px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.8px;color:{MUTED};border-bottom:1px solid {BORDA};">Versão</th>
      <th align="right" width="18%" style="padding:0 0 8px 10px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.8px;color:{MUTED};border-bottom:1px solid {BORDA};">Situação</th>
    </tr>
    {"".join(linhas)}
  </table>
  {"".join(blocos_extra)}
</td></tr>
<tr><td style="padding:28px 30px 8px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f7f9;border-left:3px solid {CIANO};">
  <tr><td style="padding:16px 18px;font-size:13px;line-height:1.7;color:#334155;">
    <strong style="color:{FUNDO};">Como foi feito</strong><br>
    Antes de qualquer alteração, cada versão foi testada numa cópia do site, com os fluxos principais
    verificados. Em produção, a atualização foi feita com backup completo (banco e arquivos), em etapas,
    com o site verificado a cada etapa.<br>
    <span style="color:{MUTED};">Referência do backup: <span style="font-family:{MONO};">{e(ctx["backup"] or "—")}</span></span>
  </td></tr></table>
</td></tr>
<tr><td style="padding:18px 30px 28px;font-size:15px;line-height:1.6;color:#334155;">
  Qualquer dúvida, fico à disposição.
</td></tr>
<tr><td bgcolor="{FUNDO}" style="background:{FUNDO};padding:24px 30px;">
  {assinatura_html(logo_src)}
</td></tr>
</table>
</td></tr></table>
</body></html>
"""


def corpo_texto(ctx: dict) -> str:
    rotulo = RESULTADOS[ctx["saida"]][0]
    linhas = [f"{CLIENTE} ({SITE}): atualização de plugins", "", f"Olá, equipe {CLIENTE}!", "",
              f"Resultado: {rotulo}", resumo(ctx), f"Executada em {ctx['quando']:%d/%m/%Y às %H:%M}.", "",
              f"{titulo_itens(ctx)}:"]
    for i in ctx["itens"]:
        aplicado = i["situacao"] == "atualizado"
        seg = (" [correção de segurança]" if aplicado else f" [correção de segurança {pendencia(i)}]") if i["seguranca"] else ""
        papel = f" ({i['funcao']})" if i["funcao"] else ""
        linhas.append(f"- {i['nome']}{papel}: {i['de']} -> {i['final'] or '?'}, {SITUACOES[i['situacao']][0]}{seg}")
        if i.get("pagina"):
            linhas.append(f"    {i['pagina']}")
        if aplicado and i["notas"]:
            linhas.append(f"    Novidades da versão {i['para']}:")
            linhas += [f"    - {n}" for n in i["notas"][:MAX_NOTAS]]
            if len(i["notas"]) > MAX_NOTAS:
                linhas.append(f"    e mais {len(i['notas']) - MAX_NOTAS} alteração(ões)")
    if ctx["smoke_total"]:
        linhas += ["", f"Verificação do site: {ctx['smoke_total'] - len(ctx['smoke_falhas'])} de {ctx['smoke_total']} ok"]
        linhas += [f"  falha: {f}" for f in ctx["smoke_falhas"]]
    if fora_cliente(ctx):
        linhas += ["", "Quarentena:", explicacao_quarentena(ctx)]
        linhas += [f"- {f['nome']} {f['de']} -> {f['para']}: {f['motivo_cliente']}" for f in fora_cliente(ctx)]
    linhas += ["", f"Referência do backup: {ctx['backup'] or '-'}", "",
               "Qualquer dúvida, fico à disposição.", "", assinatura_texto()]
    return "\n".join(linhas) + "\n"


def para_cliente(ctx: dict) -> bool:
    """O cliente só recebe o relatório quando tudo deu certo, sem nenhuma ressalva."""
    return (ctx["saida"] == "0" and bool(ctx["itens"])
            and all(i["situacao"] == "atualizado" for i in ctx["itens"])
            and ctx["smoke_total"] > 0 and not ctx["smoke_falhas"])


def motivos(ctx: dict) -> list[str]:
    """Por que o relatório não foi para o cliente."""
    m = []
    if ctx["saida"] != "0":
        rotulo, _, _, texto = RESULTADOS[ctx["saida"]]
        m.append(f"saída {ctx['saida']} ({rotulo}): {texto}")
    if not ctx["itens"]:
        m.append("lock sem itens: o relatório não conseguiu ler o plano (lock.json ao lado do registro?)")
    for i in ctx["itens"]:
        if i["situacao"] != "atualizado":
            m.append(f"{i['slug']}: {SITUACOES[i['situacao']][0]} (de {i['de']}, para {i['para']}, lido {i['final'] or '-'})")
    if not ctx["smoke_total"]:
        m.append("smoke de produção sem resultado (não rodou ou o arquivo não foi gravado)")
    m += [f"smoke: {f}" for f in ctx["smoke_falhas"]]
    return m


def assunto_tecnico(ctx: dict) -> str:
    return (f"[{MARCA}][técnico] Relatório de manutenção — Site {CLIENTE} ({ctx['quando']:%d/%m/%Y}): "
            f"{RESULTADOS[ctx['saida']][0]}, saída {ctx['saida']}")


def corpo_tecnico(ctx: dict, log: str) -> str:
    b = ctx["backup"] or "<backup>"
    linhas = [assunto_tecnico(ctx), "", "O CLIENTE NÃO FOI NOTIFICADO.", "", "Motivos:"]
    linhas += [f"- {m}" for m in motivos(ctx)] or ["- (nenhum)"]
    linhas += ["", "Itens (de -> para, lido no fim do job, situação):"]
    linhas += [f"- {i['slug']}{' (crítico)' if i['critico'] else ''}: {i['de']} -> {i['para']}, lido {i['final'] or '-'}, {SITUACOES[i['situacao']][0]}"
               for i in ctx["itens"]] or ["- (lock sem itens)"]
    if ctx["fora"]:
        linhas += ["", "Fora do ciclo:"] + [f"- {f['slug']} {f['de']} -> {f['para']}: {f['motivo']}" for f in ctx["fora"]]
    linhas += ["", f"Lock do ensaio: {ctx['lock'] or '-'}", f"Smoke de produção: {ctx['smoke_total'] - len(ctx['smoke_falhas'])} de {ctx['smoke_total']} verdes",
               "", "Registro (aplicacao-*.json):", json.dumps(ctx.get("registro", {}), ensure_ascii=False),
               "", "Próximos passos:",
               f"- estado de produção (só leitura): bash scripts/plugins/atualizar.sh estado-producao --backup={b}",
               f"- pastas salvas no servidor: <conta>/_uonix-deploy-backups/{b}/plugin-<slug>.tar.gz (e descartado-<slug>)",
               f"- banco antes da aplicação: <conta>/_uonix-deploy-backups/{b}/db-prod-{b}.sql.gz",
               "- reenviar este relatório (a saída é recalculada do registro, então vai de novo ao técnico; "
               "--saida-final=N força outra saída, e o cliente só recebe com sucesso completo): "
               f"bash scripts/plugins/atualizar.sh relatorio --aplicacao=<pasta do ensaio>/aplicacao-{b}.json",
               "", "Fim do log do job (últimas 120 linhas):"]
    # URL de pacote de plugin pago pode levar token de licença na query string.
    linhas += [re.sub(r"(https?://[^\s?]+)\?\S+", r"\1?[omitido]", l) for l in log.splitlines()[-120:]] if log else ["(sem log)"]
    return "\n".join(linhas) + "\n"


def mensagem_tecnica(ctx: dict, log: str, de: str, para: list[str]) -> EmailMessage:
    msg = EmailMessage()
    msg["Subject"] = assunto_tecnico(ctx)
    msg["From"] = formataddr((ASSINANTE, de))
    msg["To"] = ", ".join(para)
    msg["Date"] = formatdate(localtime=True)
    msg["Message-ID"] = make_msgid(domain=de.rsplit("@", 1)[-1])
    texto = corpo_tecnico(ctx, log)
    msg.set_content(texto)
    msg.add_alternative(f'<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"></head><body>'
                        f'<pre style="font-family:Menlo,Consolas,monospace;font-size:12px;line-height:1.5;white-space:pre-wrap;">'
                        f'{html.escape(texto)}</pre></body></html>', subtype="html")
    return msg


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
    tecnico = [p.strip() for p in env.get("RELATORIO_PARA_TECNICO", TECNICO_PADRAO).split(",") if p.strip()]
    cco = [p.strip() for p in env.get("RELATORIO_CCO", TECNICO_PADRAO).split(",") if p.strip()]
    cco = [c for n, c in enumerate(cco) if c.casefold() not in {x.casefold() for x in cco[:n]}]
    return {"host": host, "porta": porta, "seguranca": seguranca, "usuario": usuario, "senha": senha,
            "de": de, "para": para, "para_tecnico": tecnico, "cco": cco}


def mensagem(ctx: dict, de: str, para: list[str]) -> EmailMessage:
    msg = EmailMessage()
    msg["Subject"] = assunto(ctx)
    msg["From"] = formataddr((ASSINANTE, de))
    msg["To"] = ", ".join(para)
    msg["Date"] = formatdate(localtime=True)
    msg["Message-ID"] = make_msgid(domain=de.rsplit("@", 1)[-1])
    msg.set_content(corpo_texto(ctx))
    logo = LOGO.read_bytes() if LOGO.is_file() else None
    msg.add_alternative(corpo_html(ctx, f"cid:{LOGO_CID}" if logo else None), subtype="html")
    if logo:
        msg.get_body(("html",)).add_related(logo, "image", "png", cid=f"<{LOGO_CID}>",
                                                disposition="inline", filename="ksio-mark.png")
    return msg


def logo_data_uri() -> str | None:
    """Para a prévia em arquivo, que não resolve cid:."""
    return f"data:image/png;base64,{base64.b64encode(LOGO.read_bytes()).decode()}" if LOGO.is_file() else None


def enviar(msg: EmailMessage, cfg: dict, smtp_ssl=smtplib.SMTP_SSL, smtp_local=smtplib.SMTP, para: list[str] | None = None) -> None:
    if cfg["seguranca"] == "nenhuma":
        conexao = smtp_local(cfg["host"], cfg["porta"], timeout=30)
    else:
        conexao = smtp_ssl(cfg["host"], cfg["porta"], context=ssl.create_default_context(), timeout=30)
    with conexao:
        if cfg["senha"]:
            conexao.login(cfg["usuario"], cfg["senha"])
        conexao.send_message(msg, from_addr=cfg["de"], to_addrs=para if para is not None else cfg["para"])


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
    cliente = para_cliente(ctx)
    if a.previa:
        a.previa.write_text(corpo_html(ctx, logo_data_uri()) if cliente else
                            f'<!DOCTYPE html><meta charset="utf-8"><pre>{html.escape(corpo_tecnico(ctx, log))}</pre>')
        print(f"prévia ({'cliente' if cliente else 'técnica'}): {a.previa}")
    if not a.enviar:
        return 0
    try:
        cfg = configuracao(dict(os.environ))
    except ConfiguracaoIncompleta as erro:
        print(f"relatório não enviado: {erro}", file=sys.stderr)
        return 2
    para = cfg["para"] if cliente else cfg["para_tecnico"]
    # Cópia oculta só no envelope: o cabeçalho To continua só com o cliente.
    ja = {x.casefold() for x in para}
    extra = [c for c in cfg["cco"] if c.casefold() not in ja] if cliente else []
    envelope = para + extra
    try:
        msg = mensagem(ctx, cfg["de"], para) if cliente else mensagem_tecnica(ctx, log, cfg["de"], para)
        enviar(msg, cfg, para=envelope)
    except (OSError, smtplib.SMTPException) as erro:
        print(f"relatório não enviado: {type(erro).__name__}: {erro}", file=sys.stderr)
        return 1
    except Exception as erro:  # noqa: BLE001 - a mensagem poderia citar a senha (ex.: UnicodeEncodeError no login)
        print(f"relatório não enviado: {type(erro).__name__}", file=sys.stderr)
        return 1
    destino = (f"cliente; cópia oculta para {', '.join(extra) or '-'}" if cliente
               else "técnico; o cliente não foi notificado")
    print(f"relatório enviado para {', '.join(para)} ({destino}): {msg['Subject']}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
