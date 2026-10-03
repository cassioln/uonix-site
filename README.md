# Uonix Site

Repositório de código próprio do site Uonix.

## Estrutura

- `.github/workflows/`: deploys via GitHub Actions.
- `themes/kadence-child/`: tema filho ativo do site e snippets visuais acoplados ao Kadence.
- `plugins/plugins-customizados/`: plugins próprios, quando existirem.
- `mu-plugins/`: módulos globais do site carregados por `uonix-core.php`.
- `local/`: ambiente local Podman.
- `scripts/`: comandos auxiliares locais.
- `docs/`: documentação operacional.

Plugins de terceiros, uploads e arquivos gerados pelo WordPress não são versionados.

## Branches

- `qa`: promoção de código para QA.
- `master`: branch da produção provisória.
- `local`: recebe alterações de `master`, sem merge automático de volta.

Os workflows de deploy ficam fail-closed até a guarda explícita de cada ambiente ser aprovada. Produção não possui deploy automático autorizado.

## Ambientes

- Produção: `https://uonix.com.br/` (branch `master`, Locaweb).
- QA: `https://uonix.ksio.dev/` (branch `qa`, HostGator).
- Local: `http://localhost:8080/` (branch `local`, Podman no Mac).
- Tema principal: `themes/kadence-child`
- Caminho QA do tema: `/home2/uonix/public_html/wp-content/themes/kadence-child`
- Caminho QA dos MU-plugins: `/home2/uonix/public_html/wp-content/mu-plugins`

O contrato completo de hosts, document roots, políticas e guardas está em [docs/ambientes.md](docs/ambientes.md).

## Local

```bash
podman-compose -p uonix-local -f local/compose.yml up -d
podman-compose -p uonix-local -f local/compose.yml down
```

- WordPress local: `http://localhost:8080`
- Mailpit local: `http://localhost:8025`

Para o local ficar fiel ao servidor, mantenha `local/wp-content/plugins` e `local/wp-content/uploads` sincronizados fora do Git e importe um dump atual do banco.

O passo a passo para recriar o ambiente local apos apagar containers, imagens ou volumes fica em [local/README.md](local/README.md).

## Clone de Ambientes

A ferramenta `ksio.dev > Clone de Ambientes` somente solicita o workflow manual `.github/workflows/clone-environment.yml`; ela não escreve banco ou arquivos diretamente. A ferramenta suporta os três ambientes canônicos (`prod`, `qa` e `local`); pares com `local` são executados pelo Mac como ponte privada.

**Quem vê a ferramenta.** O menu `ksio.dev` é exclusivo do usuário `ksiodev`, e nenhum outro administrador o vê. Dentro dele ficam Limpeza de Conteúdo, Clone de Ambientes, Uônix Insights e a tela **Visibilidade para usuários**. É nela que o `ksiodev` escolhe quais dessas ferramentas os demais usuários veem. O padrão é tudo oculto. Uma ferramenta liberada aparece para os demais como item próprio do menu, com a capacidade de sempre: `manage_options` para Limpeza e Clone, e `edit_posts` para o Insights. A regra fica em `mu-plugins/uonix-admin/49-admin-ksio-governanca.php` (ver a seção "Como usar o painel `ksio.dev`" em [docs/clone-ambientes.md](docs/clone-ambientes.md)).

Leia [docs/clone-ambientes.md](docs/clone-ambientes.md) antes de qualquer clone. Destino `prod` exige aprovação explícita, confirmação dinâmica, backup validado e preflight/dry-run no mesmo processo.
