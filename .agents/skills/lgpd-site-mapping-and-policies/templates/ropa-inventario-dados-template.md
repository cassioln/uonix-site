# Template: Registro das Operações de Tratamento de Dados Pessoais (RoPA / Inventário)

Este documento serve como a planilha central de governança e inventário de dados pessoais, exigida pelas boas práticas da LGPD (Art. 37 da Lei 13.709/2018).

---

## 1. Identificação do Controlador e Governança

- **Controlador:** [Razão Social Completa]  
- **CNPJ:** [00.000.000/0000-00]  
- **Sede:** [Endereço completo]  
- **Encarregado pelo Tratamento de Dados Pessoais (DPO):** [Nome ou Entidade Responsável]  
- **E-mail de Contato do DPO:** [privacidade@seusite.com.br]  
- **Data da Última Revisão:** [DD/MM/AAAA]

---

## 2. Matriz de Inventário de Dados (Mapeamento dos Fluxos do Site)

| ID | Ponto de Coleta / Canal | Dados Pessoais Coletados | Categoria do Titular | Finalidade Específica do Tratamento | Base Legal (LGPD) | Tempo de Retenção | Compartilhamento / Operadores | Medidas de Segurança Aplicadas |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **01** | **Formulário de Contato Comercial** | Nome, E-mail, Telefone/WhatsApp, Empresa, Mensagem | Potencial Cliente (Prospect B2B) | Responder à mensagem, qualificar a demanda e enviar proposta técnica/comercial. | Art. 7º, V (Procedimentos preliminares a contrato) ou Art. 7º, IX (Legítimo Interesse) | 2 anos após o último contato ou até oposição do titular. | Provedor de Hospedagem, Servidor SMTP, Sistema de CRM. | HTTPS/TLS, banco de dados protegido, acesso restrito com credenciais. |
| **02** | **Solicitação de Orçamento / WooCommerce** | Nome, E-mail, Telefone, Empresa, Cidade/UF, Itens de interesse | Cliente / Solicitante de Cotação | Elaborar cotação formal de ancoragem e sistemas de segurança, formalizar proposta comercial. | Art. 7º, V (Procedimentos preliminares a pedido do titular) | 5 anos (para resguardo em prazos prescricionais cíveis e comerciais). | Banco de dados WordPress/WooCommerce, ERP interno de faturamento. | Criptografia em trânsito, controle de perfil de usuário admin/gerente. |
| **03** | **Newsletter / Materiais Educativos** | Nome, E-mail | Visitante / Assinante | Envio periódico de artigos técnicos, atualizações de normas (ex.: NR-18, NR-35) e novidades. | Art. 7º, I (Consentimento do titular) | Até a revogação do consentimento (botão de descadastro/opt-out presente em todo e-mail). | Plataforma de E-mail Marketing / CRM (ex.: RD Station, Mailchimp). | Duplo opt-in (quando ativo), link de descadastro automático de 1 clique. |
| **04** | **Trabalhe Conosco / Vagas** | Nome, E-mail, Telefone, Cidade, Arquivo de Currículo (PDF/Word) | Candidato a Vaga de Emprego | Recrutamento e seleção de profissionais para o quadro da empresa. | Art. 7º, V (Procedimentos preliminares relacionados a contrato de trabalho) | 6 a 12 meses após a conclusão do processo seletivo. | Apenas equipe interna de RH / Diretoria. Não compartilhado com terceiros. | Arquivos protegidos contra acesso público direto (sem indexação em buscadores). |
| **05** | **Comentários no Blog** | Nome, E-mail, Site (opcional), IP do autor, Comentário | Leitor / Visitante do Blog | Publicação de opinião/dúvida sobre artigos técnicos e moderação anti-spam. | Art. 7º, IX (Legítimo Interesse) e Art. 7º, I (Consentimento ao submeter) | Enquanto o artigo correspondente permanecer publicado no site. | Banco de dados WordPress, serviço de moderação anti-spam. | Moderação prévia por administrador, Turnstile anti-bot. |
| **06** | **Navegação & Cookies Analíticos (GA4 / GTM)** | Endereço IP (truncado/anonimizado), tipo de navegador, páginas visitadas, tempo de permanência | Visitante do Site | Mensuração de tráfego, análise de páginas mais lidas e melhoria contínua da arquitetura do site. | Art. 7º, I (Consentimento via CMP) ou Art. 7º, IX (Legítimo Interesse com garantia de opt-out) | Padrão GA4 (14 meses). | Google Ireland Limited / Google LLC. | Consent Mode v2 configurado, retenção configurada na conta Google Analytics. |
| **07** | **Pixels de Publicidade (Meta / Google Ads)** | Identificadores de dispositivo, parâmetros de URL, eventos de conversão | Visitante do Site | Mensuração do retorno de anúncios digitais e remarketing direcionado. | Art. 7º, I (Consentimento específico via banner AdOpt) | Conforme políticas das plataformas (até 90–180 dias). | Meta Platforms, Google LLC. | Disparo condicionado ao opt-in no banner AdOpt; bloqueio se rejeitado. |
| **08** | **First-Party Storage (Autopreenchimento)** | Nome, Empresa, E-mail, Telefone | Visitante recorrente | Facilitar o preenchimento de formulários subsequentes no mesmo navegador. | Art. 7º, I (Consentimento ao aceitar cookies na AdOpt) | Até a limpeza de cookies pelo usuário ou revogação no banner da AdOpt. | Exclusivamente local no navegador do usuário (`localStorage`). | Não enviado a servidores até que um formulário seja voluntariamente submetido. |

---

## 3. Gestão de Operadores e Prestadores de Serviços

| Fornecedor / Terceiro | Função / Serviço | Dados Tratados | País do Servidor | Salvaguarda Contratual |
| :--- | :--- | :--- | :--- | :--- |
| **Provedor de Hospedagem** | Servidores e banco de dados | Todos os dados cadastrais do site | Brasil / EUA | Termos de Serviço com cláusulas de confidencialidade e segurança |
| **Google (GA4 / GTM / Workspace)** | Analytics, tags e e-mail corporativo | Logs, métricas de navegação, e-mails | EUA / Global | Cláusulas Contratuais Padrão (SCC) e DPA da Google |
| **AdOpt (CMP)** | Gestão de consentimento e logs de cookies | ID anônimo de visitante, escolha de cookies | Brasil | Acordo em conformidade direta com a LGPD |
| **Cloudflare** | CDN, proteção DDoS e Turnstile anti-spam | Endereço IP e telemetria de bot | Global | DPA Cloudflare com criptografia de ponta a ponta |
