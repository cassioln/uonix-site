# Clone de ambientes

> [!CAUTION]
> Um clone real é uma operação destrutiva no **destino**: o banco da origem é
> importado e os diretórios runtime autorizados são espelhados. Não é uma cópia
> incremental nem um deploy de código. Sempre execute e revise o `dry-run` antes
> de usar `execute`.

Este documento é o runbook operacional do clone entre os quatro ambientes Uonix.
O contrato geral de URLs, branches e políticas continua em
[ambientes.md](ambientes.md).

## Navegação rápida

- [O que é copiado](#o-que-é-copiado)
- [O que não é copiado](#o-que-não-é-copiado)
- [Dry-run e preflight](#dry-run-e-preflight)
- [Backup, retenção e rollback](#backup-retenção-e-rollback)
- [Como executar pelo GitHub Actions](#como-executar-pelo-github-actions)
- [Como usar o painel ksio.dev](#como-usar-o-painel-ksiodev)
- [Como executar um par com local no Mac](#como-executar-um-par-com-local-no-mac)
- [Validações pós-clone](#validações-pós-clone)
- [Checklist operacional](#checklist-operacional)

## Fontes de verdade

- `scripts/clone-environment.sh`: implementação canônica do clone.
- `scripts/lib/environment-map.sh`: mapa canônico de URL, título, tipo, host,
  document root e raiz de backup de cada ambiente.
- `scripts/lib/ssh-transport.sh`: transporte SSH/rsync e validação dos arquivos de
  credencial.
- `.github/workflows/clone-environment.yml`: entrada manual para clones entre
  ambientes remotos.
- `ksio.dev > Clone de Ambientes`: interface administrativa que dispara o
  workflow remoto ou prepara um comando para o Mac, conforme o par escolhido.

Se houver divergência entre este documento e a implementação, interrompa a
operação e corrija a divergência antes de clonar.

## O que o clone faz

O clone combina duas operações:

1. importa no destino o banco completo da origem, restaurando depois os dados e
   opções que pertencem ao destino;
2. espelha no destino três diretórios runtime de `wp-content`: `uploads`,
   `plugins` e `languages`, respeitando exclusões explícitas.

O clone **não** publica WordPress core, tema filho, MU-plugins, `wp-config.php` ou
configuração do servidor. Código versionado continua sendo responsabilidade dos
workflows de deploy.

## Ambientes

| Código | Função | Hospedagem e document root | URL e título após o clone | `WP_ENVIRONMENT_TYPE` | Transporte |
| --- | --- | --- | --- | --- | --- |
| `prod` | Produção | Locaweb — `/home/storage/f/34/12/siteuonix1/public_html` | `https://uonix.com.br` — `Uônix` | `production` | SSH com senha em arquivo privado |
| `qa` | Homologação | HostGator — `/home2/uonix/public_html` | `https://uonix.ksio.dev` — `QA - UONIX` | `staging` | SSH com chave privada |
| `dev` | Desenvolvimento remoto | HostGator — `/home2/uonix/dev_uonix` | `https://test.uonix.ksio.dev` — `DEV - UONIX` | `development` | SSH com chave privada |
| `local` | Desenvolvimento local | Podman — `/var/www/html`, com `local/wp-content` montado do Mac | `http://localhost:8080` — `LOCAL - UONIX` | `local` | Podman no Mac |

As branches de deploy continuam sendo `master` para `prod`, `qa` para QA, `dev`
para DEV e `local` para o ambiente local. O workflow de clone roda em `master`
para usar a implementação canônica da ferramenta, mas não publica `master` no
destino nem troca sua branch de deploy.

O Compose local usa MariaDB 10.11, WordPress/PHP 8.2 e Mailpit. O site responde na
porta `8080`; SMTP e interface do Mailpit usam `1025` e `8025`. O procedimento de
recriação do ambiente está em [local/README.md](../local/README.md).

## Pares permitidos e executor

Origem e destino iguais são sempre proibidos. Os 12 pares direcionais restantes
são capacidade técnica:

```text
prod  -> qa, dev, local
qa    -> prod, dev, local
dev   -> prod, qa, local
local -> prod, qa, dev
```

| Tipo de par | Pares | Executor |
| --- | --- | --- |
| Remoto para remoto | `prod ↔ qa`, `prod ↔ dev`, `qa ↔ dev` | GitHub Actions em `master` |
| Qualquer par com `local` | `local ↔ prod`, `local ↔ qa`, `local ↔ dev` | Mac, usando `scripts/clone-environment.sh` |

O workflow não aceita `local`. Também não existe cópia direta host a host: o
runner do GitHub ou o Mac funciona como ponte privada, gera manifestos SHA-256 e
verifica o payload antes e depois do envio.

> [!CAUTION]
> A CLI no Mac aceita **qualquer par**, inclusive `qa → prod` e `dev → prod`,
> exigindo apenas `--confirmation='CLONAR X PARA PROD'`. Os gates
> `ENABLE_CLONE_PRODUCTION`, Environment `production-clone` e confirmação
> vinculada ao SHA **só existem no workflow**. Executar esses pares pelo Mac
> ignora todas essas proteções. O que a tabela acima classifica como "GitHub
> Actions" é o caminho pretendido, não o único caminho tecnicamente possível.

## O que é copiado

| Componente da origem | Comportamento no destino |
| --- | --- |
| Banco WordPress | É importado por inteiro antes das restaurações específicas do destino. Conteúdo, produtos, pedidos, formulários, tabelas próprias de plugins e opções não protegidas passam a refletir a origem. |
| `wp-content/uploads/` | É espelhado com `rsync --delete`, exceto os caminhos listados em [Exclusões globais](#exclusões-globais). |
| `wp-content/plugins/` | É espelhado com `rsync --delete`, exceto plugins gerenciados por ambiente e exclusões globais. |
| `wp-content/languages/` | É espelhado com `rsync --delete`, respeitando as exclusões globais. |

### Banco e identidade do destino

Depois de importar o banco da origem, o script:

1. preserva ou substitui usuários conforme a opção escolhida;
2. substitui a URL da origem pela URL do destino em todas as tabelas com o
   prefixo WordPress, usando uma passagem para JSON escapado e outra para URL
   literal;
3. não altera a coluna `guid` durante o `search-replace`;
4. redefine `home`, `siteurl` e `blogname` com os valores canônicos do destino;
5. restaura as opções protegidas que foram fotografadas antes da importação;
6. remapeia autores quando os usuários do destino são preservados.

As duas passagens de URL evitam URLs antigas em atributos JSON de blocos
Gutenberg. O script nunca substitui apenas o hostname, pois `uonix.ksio.dev` é
substring de `test.uonix.ksio.dev` e isso poderia produzir
`test.test.uonix.ksio.dev`.

### Pré-condição: mesmo prefixo de tabelas

Origem e destino precisam usar o mesmo prefixo WordPress. O ambiente local usa
`wpis_`, igual ao dump de QA. O preflight atual confirma que consegue ler o
prefixo em cada ambiente, mas não compara automaticamente os dois valores.

Antes de qualquer novo par — e sempre que um `wp-config.php` mudar — compare os
resultados de `wp db prefix` na origem e no destino. Se forem diferentes,
interrompa: importar um dump com outro prefixo pode manter as tabelas antigas
ativas e criar tabelas paralelas, dando uma falsa impressão de clone saudável.

### Usuários e autores

Por padrão, `replace_users=false` e a CLI não recebe `--replace-users`:

- as tabelas `users` e `usermeta` do **destino** são fotografadas antes da
  importação e restauradas depois;
- logins, senhas, roles e capabilities do destino permanecem;
- posts da origem são associados ao usuário de mesmo `user_login` no destino;
- se o login não existir, o autor vira o primeiro administrador do destino; se
  não houver administrador, usa-se o primeiro usuário disponível.

Com `replace_users=true` ou `--replace-users`, `users` e `usermeta` vêm da origem
e o remapeamento de autores não é executado. Use essa opção somente quando a
substituição de contas, senhas, roles e capabilities fizer parte explícita da
janela aprovada.

### Opções do banco que pertencem ao destino

As opções abaixo são fotografadas no destino, protegidas por checksum e
restauradas depois da importação:

| Tipo | Nomes ou padrões preservados |
| --- | --- |
| Identidade administrativa e ativação | `admin_email`, `active_plugins`, `active_sitewide_plugins`, `auto_update_plugins`, `cron` |
| Caminho absoluto específico do host | `downloaded_font_files` |
| Migração, imagem e formulários | `%ai1wm%`, `compressx%`, `%fluentform%`, `_fluent_%`, `fluent_%` |
| E-mail e integrações | `%fluentmail%`, `%mailchimp%`, `%smtp%`, `mailserver_%` |
| Desafios antirrobô | `%turnstile%`, `%captcha%`, `%recaptcha%`, `%hcaptcha%`, `%wp_captcha%` |
| Logs e backup | `%wp_mail_logging%`, `%mail_logging%`, `%wpvivid%` |

Os sublinhados escapados no predicado SQL representam caracteres literais. Essa
proteção é deliberadamente específica: opções que não estejam nessa lista vêm da
origem. Ao introduzir uma configuração nova que pertença ao host — especialmente
um caminho absoluto — atualize `protected_options_where()` e o teste
`scripts/tests/test-clone-path-bound-options.sh` antes de clonar.

#### Transporte multibyte-safe das opções protegidas

Estar na lista de proteção garante que a opção seja *fotografada*, não que ela
chegue íntegra. O transporte também precisa preservar bytes.

Todo cliente `mysql`/`mariadb` usado no snapshot e na restauração passa
`--default-character-set=utf8mb4` explicitamente. Isso não é redundância: a
conexão, sem essa opção, assume o default do servidor — medido como `latin1` no
MySQL 5.7.44-48 da HostGator. Combinado com `--raw`, que desliga o escaping, um
valor `utf8mb4` é convertido na saída e o serialize PHP chega cortado.

O sintoma real (2026-08-10): `fluentmail-settings` chegou ao DEV com 283 dos
1202 bytes, cortado entre os dois bytes do `Ô` de `SITE UÔNIX` (`0xC3 0x94`).
Como `unserialize()` devolve `false` para serialize inválido, `get_option()`
passou a retornar a string crua em vez do array, e o painel do Fluent SMTP ficou
em loading infinito ao salvar — indistinguível, para o usuário, de
"configurações perdidas".

Duas conclusões operacionais:

- **Dump e restore precisam usar o mesmo charset.** A assimetria é o defeito: o
  restore já usava `utf8mb4` e só o dump não usava.
- **Qualquer opção protegida com acentuação está exposta** ao mesmo problema, não
  só as do Fluent SMTP. `sender_name`, títulos e mensagens em português são os
  casos mais prováveis.

O teste `scripts/tests/test-protected-options-charset.sh` trava essa regressão e
falha se qualquer função que use `--raw` perder o charset explícito.

> [!IMPORTANT]
> Não existe proteção genérica para todo nome contendo `license`, `api_key`,
> gateway de pagamento ou credencial de integração. Se o valor estiver em uma
> opção não abrangida pelos padrões acima — ou em uma tabela própria de plugin —
> ele vem da origem. Inventarie e proteja a configuração antes de introduzir uma
> integração nova.

### Dados pessoais no banco

O clone não anonimiza, mascara nem seleciona registros do banco. Um clone de
produção pode levar para QA, DEV ou local pedidos, clientes, submissões de
formulários, logs armazenados em tabelas e outros dados pessoais. Preservar os
usuários administrativos do destino não altera esse fato.

As exclusões de `curriculos-recebidos/` e
`wp-personal-data-exports/` valem somente para arquivos no runtime. Registros ou
metadados equivalentes armazenados no banco continuam fazendo parte do dump.
Trate qualquer clone com produção como operação com dados reais e aplique as
regras de acesso e retenção correspondentes.

## Arquivos runtime e semântica de espelho

Cada diretório existente na origem passa por esta sequência:

1. download para uma área temporária no runner ou no Mac;
2. aplicação das exclusões;
3. criação e validação local de manifesto SHA-256;
4. envio ao destino com `rsync --delete`;
5. validação do mesmo manifesto no destino;
6. remoção do manifesto temporário remoto.

Essa é uma **sincronização de espelho**, não uma mesclagem:

- arquivo não excluído que exista apenas no destino é removido;
- arquivo não excluído que exista nos dois lados é substituído pela versão da
  origem;
- item explicitamente excluído não é enviado e não é removido pelo `--delete`,
  pois o transporte não usa `--delete-excluded`;
- se um dos três diretórios inteiros não existir na origem, esse diretório é
  ignorado e o correspondente no destino fica como está;
- um erro operacional de transporte não é confundido com diretório ausente: a
  operação falha e, se a mutação já começou, aciona rollback.

> [!WARNING]
> Antes de clonar `plugins`, confira plugins ativos exclusivos do destino. Um
> diretório de plugin que só exista no destino e não esteja na lista de exclusão
> será removido pelo espelho, embora `active_plugins` seja preservado. O clone
> valida apenas os plugins críticos descritos adiante.

### Exclusões globais

Os padrões abaixo não são copiados dentro de `uploads`, `plugins` ou `languages`:

- metadados e temporários: `.DS_Store`, `._*`, `*~`, `*.log`;
- cache e logs: `cache/`, `wc-logs/`, `logs/`;
- staging e lixeira: `wp-staging/`, `wpmc-trash/`;
- exportações e dados pessoais: `wp-personal-data-exports/`,
  `curriculos-recebidos/`;
- artefatos de formulários/e-mail: `FLUENT_PDF_TEMPLATES/`;
- backups e staging de plugins: `ai1wm-backups/`, `wpvivid_uploads/`,
  `wpvividbackups/`, `wpvivid_staging/`;
- runtime exclusivamente local: `uonix-local/`.

Em especial, os **arquivos** em `curriculos-recebidos/` e
`wp-personal-data-exports/` nunca atravessam ambientes pelo clone. Isso não
anonimiza registros equivalentes armazenados no banco.

### Diretórios de plugins que não são copiados

Dentro de `wp-content/plugins`, estes diretórios permanecem gerenciados pelo
destino:

- `all-in-one-wp-migration-10GB/`;
- `compressx/`;
- `fluent-smtp/`;
- `fluentform/`;
- `wp-mail-logging/`;
- `wpvivid-backuprestore/`.

Há uma particularidade: `fluent-smtp` precisa estar previamente instalado nos destinos remotos; o
  pós-clone o ativa. No local, ele fica desativado porque o transporte obrigatório
  é o Mailpit. `fluentform` também tem seu diretório excluído da cópia e precisa
  estar instalado e ativo no destino **antes** do clone; o script apenas valida
  sua presença no pós-clone, não o instala nem ativa.

O diretório `wp-content/compressx` e o diretório `wp-content/compressx-nextgen`
são runtime específico do destino. Eles entram no backup para rollback, mas não
fazem parte dos três diretórios sincronizados.

## O que não é copiado

| Item | Resultado |
| --- | --- |
| WordPress core | Não é copiado nem atualizado. |
| `wp-config.php`, `.env`, credenciais, chaves e configuração PHP/Apache | Permanecem exclusivamente no destino. |
| Conexão e servidor do banco | `DB_HOST`, `DB_NAME`, `DB_USER`, senha, grants e configuração MariaDB/MySQL permanecem no destino; o SQL é importado no banco já configurado. |
| Tema filho e demais temas | Não são copiados pelo clone. O tema filho versionado chega apenas pelo deploy. |
| MU-plugins | Não são copiados pelo clone. Chegam apenas pelo deploy. |
| `.htaccess` do document root | Não é lido, copiado nem alterado pelo clone; rewrites e políticas do host permanecem no destino. |
| DNS, TLS, Cloudflare, WAF e configuração de hospedagem | Não são alterados pelo clone. |
| Git, branches e checkout | Não são transferidos para o destino. O workflow apenas usa o SHA canônico para executar a implementação correta. |
| Usuários do banco | Não vêm da origem por padrão; só vêm com `--replace-users`. |
| Opções protegidas | São restauradas do destino conforme a lista anterior. |
| Cache do site | Não vem da origem. Depois do clone, caches selecionados e transients do destino são limpos. |
| Arquivos de logs, backups, staging e temporários | Não vêm da origem conforme as exclusões; registros equivalentes no banco ainda podem vir. |
| Arquivos de currículos e exportações de dados pessoais | Os diretórios excluídos não são transferidos; dados armazenados no banco ainda vêm no dump. |
| Plugins explicitamente excluídos | Seus diretórios não vêm da origem; a política pós-clone pode ajustar ativação ou remover legado específico. |
| Runtime CompressX do destino | Não é substituído pela origem. |

`wp-content/.htaccess`, se existir, entra no backup de rollback porque está sob
`wp-content`; isso não deve ser confundido com o `.htaccess` do document root,
que o clone não gerencia.

### Consequência da separação entre código e dados

O checkout de `master` no workflow escolhe somente a versão da ferramenta de
clone; ele não publica esse SHA no destino. Antes do clone, o destino precisa ter
WordPress, tema, MU-plugins e plugins compatíveis com o banco da origem.

Há uma assimetria importante:

- `active_plugins` é opção protegida e permanece com a lista do destino, embora
  diretórios de plugins não excluídos sejam espelhados da origem;
- `template`, `stylesheet` e `theme_mods_*` não são opções protegidas e vêm da
  origem, embora os arquivos de temas não sejam copiados.

Assim, um plugin ativo só no destino pode perder seu diretório, e um tema
selecionado pela origem pode não existir no destino. Confirme compatibilidade de
código e plugins antes da janela; publique código pelo workflow de deploy, nunca
tentando compensar essa diferença pelo clone.

## Dry-run e preflight

`--dry-run` não cria backup e não altera banco ou arquivos. Ele valida:

- origem e destino canônicos e diferentes;
- raiz de backup absoluta e dentro da allowlist do ambiente;
- credenciais/arquivos de transporte e host keys aprovadas;
- acesso SSH quando o ambiente é remoto;
- presença de `rsync`, `gzip`, `tar`, `sha256sum`, `cmp`, cliente e produtor de
  dump MySQL/MariaDB;
- document root, `wp-content`, WP-CLI, prefixo do banco, `home` e `siteurl`;
- Compose, `local/wp-content`, banco MariaDB, `shasum` e `cmp` quando o par envolve
  `local`;
- identidade real dos bancos: origem e destino não podem apontar para o mesmo par
  host/schema, mesmo que os nomes dos ambientes sejam diferentes;
- lista de diretórios que seria espelhada, política de usuários e confirmação
  necessária para produção.

O dry-run não estima o tamanho do banco/runtime nem comprova espaço livre no
runner, no Mac ou no destino. A checagem de capacidade continua sendo um gate
operacional manual antes do execute.

O dry-run também **não** verifica: `curl` (usado no smoke HTTP e na validação de
imagens), se a raiz de backup confere com o valor canônico do ambiente
(comparação que só existe no workflow), nem se os plugins críticos
(`fluentform`, `fluent-smtp`) estão instalados no destino — essas falhas só
aparecem após o início da mutação.

A \"allowlist\" de raiz de backup validada pela CLI verifica apenas que o
caminho é absoluto e está sob a própria raiz configurada; a comparação com o
valor canônico do ambiente é feita exclusivamente pelo workflow.

Qualquer `--execute` faz novamente esse preflight no mesmo processo antes da
primeira escrita. No GitHub Actions há ainda um step de `dry-run` separado antes
do step `execute`; portanto uma execução remota real passa por dois preflights.

## Ordem de uma execução real

1. validar novamente o pedido e os dois ambientes;
2. adquirir lock exclusivo no destino;
3. confirmar novamente que os bancos são distintos;
4. criar e validar o backup do destino;
5. fotografar usuários do destino, salvo quando `--replace-users` foi solicitado;
6. fotografar opções protegidas do destino;
7. exportar e validar o banco da origem e o mapa de autores;
8. marcar o início da mutação;
9. importar o banco da origem no destino;
10. restaurar usuários do destino, quando aplicável;
11. ajustar URLs e título do destino;
12. restaurar opções protegidas;
13. remapear autores ausentes;
14. espelhar `uploads`, `plugins` e `languages` com manifestos;
15. aplicar a política SMTP;
16. limpar caches e transients;
17. executar validações WordPress, políticas por ambiente e smoke HTTP;
18. validar a entrega de imagens otimizadas em `prod` e `qa`;
19. publicar resumo sanitizado e liberar o lock.

O banco da origem e os arquivos da origem são somente lidos. O backup é feito
apenas do destino, porque é o destino que será alterado.

## Backup, retenção e rollback

### Caminhos

| Destino | Raiz de backup |
| --- | --- |
| `prod` | `/home/storage/f/34/12/siteuonix1/_uonix-clone-backups/prod` |
| `qa` | `/home2/uonix/_uonix-clone-backups/qa` |
| `dev` | `/home2/uonix/_uonix-clone-backups/dev` |
| `local` | `<repositório>/backups/clone/local` |

Cada execução usa um subdiretório identificado pelo run. Permanecem os cinco
backups mais recentes por ambiente.

### Conteúdo do backup do destino

- dump completo do banco em `gzip` (remoto inclui `--routines --triggers
  --events`; local não inclui rotinas nem eventos);
- `uploads`, `plugins` e `languages` — o tar do backup exclui subdiretórios
  `cache/`, `wc-logs/` e `wp-staging/`, portanto esses itens não são
  restaurados pelo rollback;
- `compressx` e `compressx-nextgen`, se existirem;
- `wp-content/.htaccess`, se existir;
- snapshots de usuários e opções protegidas, quando aplicáveis;
- checksums dos snapshots.

O backup não inclui WordPress core, temas, MU-plugins, `wp-config.php` nem o
`.htaccess` do document root, porque o clone não altera esses itens.

O dump só é aceito quando:

- o arquivo existe e não está vazio;
- `gzip -t` aprova o envelope;
- a última linha não vazia é o marcador `-- Dump completed`;
- a leitura chega ao final mesmo quando valores SQL contêm bytes NUL. Para isso,
  as verificações usam `grep -a`, evitando o falso `binary file matches`.

Na Locaweb, `proc_open` é desabilitado e `wp db export/import/query` não são
confiáveis. O clone obtém as credenciais pelo WP-CLI e usa `mysqldump`, `mysql`
ou `mariadb-dump`/`mariadb` diretamente com `MYSQL_PWD` para todas as operações
de banco em hosts remotos — export, import, consultas do mapa de autores e
restauração de opções. O fallback para `wp-cli` só é usado quando o cliente
nativo não está disponível.

### Rollback automático

Qualquer falha depois do início da mutação — inclusive sinal `HUP`, `INT` ou
`TERM` — tenta restaurar automaticamente o backup do mesmo run. O rollback:

1. valida novamente dump e tar;
2. extrai os arquivos primeiro em staging;
3. restaura o banco;
4. troca os itens de `wp-content` com mecanismo de desfazer em caso de falha;
5. limpa o cache.

Falha antes da mutação não precisa de rollback. Se o rollback também falhar, o
run continua reprovado e registra o caminho do backup que deve ser preservado
para recuperação manual.

## Locks, concorrência e transporte

- O workflow usa `concurrency.group = uonix-environment-<destino>` e não cancela
  uma operação em andamento.
- O script cria `<document-root>/.uonix-operation.lock` nos ambientes remotos e
  um lock sob `backups/clone/.locks` no local.
- O lock contém o `RUN_ID` do proprietário e só pode ser liberado pelo próprio
  run. Ele protege exclusivamente o **destino** contra escrita concorrente. A
  origem não recebe lock: um deploy ou clone cujo destino seja a origem pode
  ocorrer em paralelo e produzir snapshot temporalmente inconsistente.
- Nunca remova um lock manualmente sem confirmar que não existe run ativo e que
  o `owner` é órfão. O diagnóstico está em
  [migracao-locaweb.md#14a-lock-de-ambiente-órfão](migracao-locaweb.md#14a-lock-de-ambiente-órfão).
- SSH usa `StrictHostKeyChecking=yes`; não existe `accept-new`.
- Chave, senha e `known_hosts` precisam ser arquivos não vazios com modo `0400`
  ou `0600`.
- As sessões usam `ControlMaster` para evitar rajadas de autenticação e são
  encerradas no cleanup, especialmente para não deixar uma sessão Locaweb por
  senha reutilizável.
- Operações idempotentes de transporte podem repetir falhas SSH de conexão. Uma
  mutação não é repetida cegamente; rollback é o caminho de recuperação.

## Como executar pelo GitHub Actions

Use esse caminho apenas quando origem e destino forem remotos (`prod`, `qa` ou
`dev`). O workflow é manual, roda somente a implementação em `master` e faz
checkout do SHA canônico da solicitação.

### Pré-requisitos do GitHub

O Environment `clone-operations` precisa fornecer os Secrets consumidos pelo
workflow:

- `HOSTGATOR_SSH_PRIVATE_KEY`;
- `HOSTGATOR_SSH_KNOWN_HOSTS`;
- `LOCAWEB_SSH_PASSWORD`;
- `LOCAWEB_SSH_KNOWN_HOSTS`.

As Variables de topologia usadas e comparadas com a allowlist do workflow são:

- `PRODUCTION_URL`, `QA_URL`, `DEVELOPMENT_URL`;
- `LOCAWEB_SSH_HOST`, `LOCAWEB_SSH_PORT`, `LOCAWEB_SSH_USER`;
- `LOCAWEB_DOCUMENT_ROOT`, `LOCAWEB_ACCOUNT_ROOT`, `LOCAWEB_PHP_BIN`,
  `LOCAWEB_WP_BIN`;
- `HOSTGATOR_SSH_HOST`, `HOSTGATOR_SSH_PORT`, `HOSTGATOR_SSH_USER`;
- `HOSTGATOR_QA_ROOT`, `HOSTGATOR_DEV_ROOT`, `HOSTGATOR_CLONE_BACKUP_ROOT`.

Nunca registre valores de Secret, senha, chave privada ou conteúdo de
`wp-config.php` nesta documentação ou nos logs.

### Dry-run remoto

Pelo GitHub:

1. abra **Actions > Clone Environment > Run workflow**;
2. selecione a branch `master`;
3. escolha `source` e `target` diferentes;
4. mantenha `mode = dry-run`;
5. mantenha `replace_users = false`, salvo decisão explícita em contrário;
6. deixe `confirmation` vazio;
7. se `prod` participar como origem ou destino, abra antes a janela SSH Locaweb
   de três horas;
8. execute e revise todos os logs e o resumo do job.

O mesmo dispatch pode ser feito pelo `gh`:

```bash
gh workflow run clone-environment.yml \
  --repo cassioln/uonix-site \
  --ref master \
  -f source=qa \
  -f target=dev \
  -f mode=dry-run \
  -f replace_users=false
```

Troque o par do exemplo pelo par aprovado. O dry-run para destino `prod` também
usa `clone-operations`, não escreve no host e deve manter `confirmation` vazio.

### Execução remota com destino QA ou DEV

1. conclua e revise um dry-run verde para o mesmo par;
2. confirme que não há deploy ou clone em andamento para o destino;
3. se `prod` for a origem, habilite antes a janela SSH Locaweb de três horas;
4. faça novo dispatch em `master` com `mode = execute`;
5. repita a mesma escolha de `replace_users` revisada no dry-run;
6. deixe `confirmation` vazio;
7. acompanhe o run até o resumo final e verifique o destino.

Exemplo QA para DEV:

```bash
gh workflow run clone-environment.yml \
  --repo cassioln/uonix-site \
  --ref master \
  -f source=qa \
  -f target=dev \
  -f mode=execute \
  -f replace_users=false
```

### Execução remota com destino produção

Clone para produção é fail-closed e não pode ser disparado pelo painel WordPress.
O input do workflow precisa citar o SHA exato de `master`:

```text
CLONAR <ORIGEM> PARA PROD @ <SHA-DE-40-CARACTERES>
```

Na auditoria de **2026-08-06**:

- `production-clone` exige revisão de `cassioln` e permite self-review;
- o Environment não possui Secrets de transporte;
- a Variable de repositório `ENABLE_CLONE_PRODUCTION` está ausente.

Portanto, a escrita em produção está atualmente bloqueada por múltiplos gates.
Para uma janela futura, depois de aprovação operacional:

1. habilite a janela SSH Locaweb de três horas;
2. configure no Environment `production-clone`, fora do Git, os quatro Secrets
   de transporte listados acima;
3. execute e revise um `dry-run` para o mesmo par;
4. capture o SHA vigente de `master`;
5. crie temporariamente `ENABLE_CLONE_PRODUCTION=true`;
6. dispare `mode=execute` com a confirmação exata vinculada ao SHA;
7. espere `validate-request` concluir e o deployment aguardar aprovação do
   Environment;
8. remova `ENABLE_CLONE_PRODUCTION` **antes** de aprovar o Environment, fechando
   a janela lógica;
9. confira run, SHA, origem, destino, `replace_users`, concorrência e dry-run;
10. aprove o Environment e acompanhe backup, mutação, smoke e resumo;
11. ao final, confirme novamente que `ENABLE_CLONE_PRODUCTION` está ausente.

O workflow transforma a confirmação com SHA na frase curta exigida internamente
pelo script (`CLONAR <ORIGEM> PARA PROD`). Não tente contornar o workflow alterando
o texto.

## Como usar o painel `ksio.dev`

O painel exige `manage_options` e valida nonce, ambientes, modo e par.

- Par remoto sem escrita em produção: dispara
  `.github/workflows/clone-environment.yml` em `master`, desde que
  `UONIX_GITHUB_TOKEN` esteja configurado fora do Git no ambiente onde o painel
  é aberto (veja [Onde configurar](#onde-configurar-uonixgithubtoken)).
- Dry-run com destino produção: pode disparar o workflow; a confirmação fica
  vazia porque não há escrita.
- Execute com destino produção: é recusado pelo painel, pois ele não consegue
  vincular a confirmação ao SHA que o workflow usará. Faça o dispatch manual no
  GitHub Actions.
- Par com `local` **sem** destino produção: o painel **não executa** o clone.
  Ele mostra um comando sanitizado para copiar e executar no Mac depois de
  preparar credenciais e revisar a janela.
- Par `local → prod`: **não há caminho pelo painel.** O painel recusa qualquer
  `execute` com destino `prod` antes de verificar se `local` participa. O
  workflow também rejeita `local`. A CLI no Mac é o único executor possível,
  sem os gates do GitHub (veja o alerta em [Pares permitidos](#pares-permitidos-e-executor)).

O painel nunca recebe credenciais SSH no formulário e não as inclui no comando
gerado.

### Onde configurar `UONIX_GITHUB_TOKEN`

O painel só dispara o workflow quando `UONIX_GITHUB_TOKEN` está definido como
constante PHP no ambiente **onde o painel é aberto**. O predicado é
`uox_clone_has_github_token()`: exige `define()` com valor não vazio.

O token é necessário apenas para pares **remoto ↔ remoto**. Pares que envolvem
`local` são resolvidos como `mac` — o painel apenas monta um comando para você
executar no Mac e nunca chama a API do GitHub.

| Ambiente | Onde definir |
| --- | --- |
| `local` | `local/compose.yml`: a env var `UONIX_GITHUB_TOKEN` é repassada aos serviços `wordpress` e `wpcli`, e o `WORDPRESS_CONFIG_EXTRA` faz o `define()` condicional. O valor vem do `.env` da raiz, que está no `.gitignore`. |
| `qa` | `/home2/uonix/public_html/wp-config.php`, antes da linha `stop editing`. |
| `dev` | `/home2/uonix/dev_uonix/wp-config.php`, antes da linha `stop editing`. |
| `prod` | `/home/storage/f/34/12/siteuonix1/public_html/wp-config.php`. Só necessário se o painel for usado a partir de produção; `execute` com destino `prod` continua recusado. |

No `local`, exporte o `.env` antes de subir os containers para a variável chegar
ao Compose:

```bash
cd "$(git rev-parse --show-toplevel)"
set -a && . ./.env && set +a
podman-compose -p uonix-local -f local/compose.yml up -d --force-recreate wordpress
```

O `define()` no Compose é condicional: sem a variável no host, a constante
simplesmente não é criada e o painel exibe o erro orientando a configuração —
não há fallback silencioso nem valor vazio aceito.

Para conferir se o ambiente está apto, sem imprimir o valor:

```bash
podman exec uonix-local-app php -r 'require "/var/www/html/wp-config.php";
  echo defined("UONIX_GITHUB_TOKEN") && "" !== trim(UONIX_GITHUB_TOKEN) ? "OK\n" : "AUSENTE\n";'
```

> [!IMPORTANT]
> Nunca versione o valor do token. No `local` ele vive só no `.env` (ignorado
> pelo Git); nos ambientes remotos, apenas no `wp-config.php` do host, que
> também não é versionado. Ao editar um `wp-config.php` remoto, faça backup
> datado e valide com `php -l` antes de substituir o arquivo em uso.

O token precisa de permissão para disparar workflows no repositório
(`actions: write` em token fine-grained). Um token sem esse escopo faz o painel
falhar no dispatch mesmo com a constante presente.

## Como executar um par com `local` no Mac

### Pré-requisitos

1. repositório atualizado no SHA aprovado;
2. containers `uonix-local-app`, `uonix-local-db` e Mailpit em execução;
3. `local/wp-content` montado e acessível;
4. `podman-compose`, `rsync`, `gzip`, `tar`, `shasum` e `cmp` disponíveis;
5. Variables de topologia necessárias ao par exportadas conforme
   `scripts/lib/environment-map.sh`;
6. arquivos de credencial e `known_hosts` com modo `0400` ou `0600`;
7. `sshpass` disponível e janela SSH Locaweb aberta por três horas se `prod` for
   origem ou destino.

### Variables de topologia no Mac

O script não carrega um `.env` automaticamente. Exporte no shell as Variables do
ambiente remoto que participa do par:

| Ambiente | Variables não secretas |
| --- | --- |
| `qa` ou `dev` | `QA_URL`, `DEVELOPMENT_URL`, `HOSTGATOR_SSH_HOST`, `HOSTGATOR_SSH_PORT`, `HOSTGATOR_SSH_USER`, `HOSTGATOR_QA_ROOT`, `HOSTGATOR_DEV_ROOT`, `HOSTGATOR_CLONE_BACKUP_ROOT` |
| `prod` | `PRODUCTION_URL`, `LOCAWEB_SSH_HOST`, `LOCAWEB_SSH_PORT`, `LOCAWEB_SSH_USER`, `LOCAWEB_DOCUMENT_ROOT`, `LOCAWEB_ACCOUNT_ROOT`, `LOCAWEB_PHP_BIN`, `LOCAWEB_WP_BIN` |

Use os valores canônicos aprovados para o ambiente; não copie valores de logs ou
de um host diferente.

Para HostGator, configure no shell:

```bash
export HOSTGATOR_SSH_KEY='/caminho/privado/para/chave'
export HOSTGATOR_SSH_KNOWN_HOSTS_FILE='/caminho/privado/para/known-hosts'
```

Para Locaweb, nunca coloque a senha diretamente na linha de comando:

```bash
export LOCAWEB_SSH_PASSWORD_FILE='/caminho/privado/para/arquivo-de-senha'
export LOCAWEB_SSH_KNOWN_HOSTS_FILE='/caminho/privado/para/known-hosts'
```

### Exemplo QA para local

Primeiro, somente preflight:

```bash
cd "$(git rev-parse --show-toplevel)"
scripts/clone-environment.sh --source=qa --target=local --dry-run
```

Depois de revisar o dry-run e aprovar a substituição do ambiente local:

```bash
scripts/clone-environment.sh --source=qa --target=local --execute
```

O resultado esperado mantém título e URLs locais, usuários/opções protegidos,
Fluent SMTP desativado, Mailpit acessível em `mailpit:1025`, Turnstile desativado e
analytics desativado.

### Outros pares com local

Troque somente `--source` e `--target`, mantenha o ciclo dry-run/revisão/execute e
use `--replace-users` apenas quando essa substituição tiver sido aprovada.

Para `local -> prod`, a CLI exige:

```text
--confirmation='CLONAR LOCAL PARA PROD'
```

> [!DANGER]
> Essa execução no Mac não passa pelo Environment `production-clone` nem consulta
> `ENABLE_CLONE_PRODUCTION`. A CLI também aceita `qa → prod` e `dev → prod` com
> a mesma exigência de frase curta. A proteção técnica local é o preflight, o
> backup, o lock e a frase exata — **nada disso substitui os gates do GitHub.**
> Capacidade técnica não equivale a autorização: execute somente em janela
> explicitamente aprovada e acompanhada.

## Políticas aplicadas depois da cópia

O clone não termina logo após importar banco e arquivos. Ele também:

- ativa `fluent-smtp` em `prod`, `qa` e `dev` e falha se o plugin não estiver
  previamente instalado;
- desativa `fluent-smtp` no `local` e valida o Mailpit sem autenticação ou TLS;
- remove caches selecionados (`min`, `critical-css`, `background-css`, `busting`
  e `wp-rocket`), apaga transients e executa `wp cache flush` (best-effort:
  falhas na remoção de diretórios e transients não interrompem o clone);
- preserva a configuração Turnstile do destino e valida que ela está habilitada
  remotamente e desabilitada no local;
- valida redirecionamento seguro de e-mail em QA/DEV;
- exige analytics desativado fora de produção;
- valida coerência da política de indexação com `blog_public`.

O clone não purga a borda Cloudflare. Em QA, uma resposta antiga ainda pode vir da
borda mesmo depois da limpeza no host.

## Validações pós-clone

O destino só é considerado saudável quando todas estas verificações passam:

- `wp core is-installed`;
- `home`, `siteurl`, `blogname` e `WP_ENVIRONMENT_TYPE` canônicos;
- política Turnstile efetiva;
- política de e-mail segura em QA/DEV;
- analytics desativado fora de produção;
- política de indexação coerente;
- `fluentform` ativo;
- estado correto de `fluent-smtp`;
- Mailpit disponível e PHPMailer apontando para `mailpit:1025` no local;
- home acessível por HTTP;
- `wp-login.php` acessível por HTTP;
- três imagens críticas entregues como AVIF ou WebP em `prod` e `qa`.

Produtos e REST **não** são endpoints do smoke do clone. Se a janela exigir essas
validações, faça-as adicionalmente depois do run.

### WAF e retentativas HTTP

Home e login têm até sete tentativas. As esperas são:

```text
5s, 10s, 20s, 40s, 60s, 60s
```

Total de espera: 195 segundos, além do tempo de cada requisição.

- `2xx` e `3xx`: sucesso;
- `404` e `410`: falha imediata de conteúdo;
- `408`, `409`, `425`, `429`, `403`, `406`, `500`, `502`, `503` e `504`:
  retentativa até o limite;
- falha de transporte do `curl`: retentativa até o limite;
- qualquer outro status: falha imediata;
- erro persistente depois da sétima tentativa: falha e rollback.

O User-Agent do smoke é `uonix-clone-smoke/1.0`, facilitando localizar a requisição
no log do WAF. Sucesso somente depois de retentativa gera aviso no log; não fica
silencioso.

## Como interpretar o resultado

O resumo sanitizado do workflow informa:

- origem e destino;
- modo solicitado e modo executado;
- escolha de substituição de usuários;
- identificador do backup;
- quantidade de diretórios runtime processados;
- quantidade de arquivos runtime validados.

Sucesso significa que banco, runtime autorizado e políticas pós-clone passaram nos
gates descritos. Sucesso **não** significa que:

- código foi publicado;
- WordPress core ou plugins excluídos foram atualizados;
- produtos e REST foram verificados;
- Cloudflare foi purgada;
- qualquer item fora do escopo explícito foi copiado.

## Checklist operacional

### Antes

- [ ] Confirmar origem e destino em voz alta; nunca confiar só na posição dos campos.
- [ ] Confirmar que o destino pode ser sobrescrito.
- [ ] Confirmar que origem e destino usam o mesmo prefixo de tabelas WordPress.
- [ ] Decidir explicitamente se usuários serão preservados ou substituídos.
- [ ] Verificar plugins ativos exclusivos do destino contra a semântica `--delete`.
- [ ] Confirmar que `fluentform` e `fluent-smtp` estão instalados e ativos no destino remoto.
- [ ] Confirmar que `curl` está disponível (smoke HTTP e validação de imagens).
- [ ] Confirmar espaço e raiz de backup.
- [ ] Confirmar credenciais e `known_hosts` privados.
- [ ] Abrir janela SSH Locaweb se `prod` participar.
- [ ] Confirmar ausência de deploy/clone ativo para o destino.
- [ ] Executar e revisar o dry-run para o mesmo par e a mesma política de usuários.
- [ ] Para produção, conferir SHA, guard temporário, Environment e aprovação.
- [ ] Se for usar a CLI no Mac com destino produção, revisar o alerta em
  [Pares permitidos](#pares-permitidos-e-executor): os gates do GitHub não se aplicam.

### Depois

- [ ] Confirmar run verde e ler o resumo sanitizado.
- [ ] Registrar o identificador do backup do destino.
- [ ] Validar home e `wp-login.php` no domínio canônico.
- [ ] Validar produtos e REST se a janela exigir cobertura além do smoke padrão.
- [ ] Confirmar usuários, roles e autores conforme a opção escolhida.
- [ ] Confirmar e-mail, Turnstile, analytics e indexação do destino.
- [ ] Em QA, distinguir origem de cache da borda Cloudflare.
- [ ] Confirmar que não restou lock órfão.
- [ ] Em janela de produção, confirmar que `ENABLE_CLONE_PRODUCTION` voltou a ficar ausente.

## Referências

- [Contrato de ambientes](ambientes.md)
- [Deploy de código](deploy.md)
- [Ambiente local](../local/README.md)
- [Particularidades da Locaweb](migracao-locaweb.md)
- [Cloudflare Turnstile](turnstile.md)