# Registro das Operações de Tratamento de Dados Pessoais (RoPA)
## Uônix Montagens e Consultoria Técnica Ltda.

> **Documento de Governança e Conformidade com a LGPD (Artigo 37 da Lei nº 13.709/2018)**  
> **Versão:** 1.0  
> **Data de Elaboração:** 07 de Setembro de 2026  
> **Classificação:** Documento Interno de Governança / Auditoria Regulatória

---

## 1. Identificação do Agente de Tratamento (Controlador)

* **Razão Social:** Uônix Montagens e Consultoria Técnica Ltda.
* **Nome Fantasia:** Uônix
* **CNPJ:** 20.775.536/0001-96
* **Sede:** Rua Melo Franco, nº 115, Sala 01, Jardim Munhoz, Guarulhos - SP, CEP 07033-220
* **Atividade Principal:** Fabricação e fornecimento de sistemas e dispositivos de ancoragem predial, linhas de vida industriais, componentes de proteção contra quedas e serviços técnicos especializados.
* **Website:** `https://www.uonix.com.br`
* **Encarregado pelo Tratamento de Dados Pessoais (DPO):** Comitê de Governança e Privacidade Uônix
* **Canal Oficial de Atendimento ao Titular (Art. 41):** `administrativo@uonix.com.br` | Formulário de Contato do Website (assunto "LGPD")

---

## 2. Matriz de Mapeamento dos Fluxos de Dados (Inventário Técnico do Site)

| ID | Fluxo / Ponto de Coleta | Dados Pessoais Tratados | Categorias de Titulares | Finalidade Específica | Base Legal (LGPD) | Tempo de Retenção | Compartilhamento / Operadores | Medidas de Segurança Técnicas |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **01** | **Formulário de Contato Geral**<br>(`/#contato` - Fluent Forms ID 3) | Nome completo, e-mail corporativo, telefone/WhatsApp, empresa e mensagem. | Potenciais clientes (prospects B2B) e parceiros. | Atendimento a dúvidas comerciais, consultoria preliminar e triagem de demandas técnicas. | **Art. 7º, V** (Procedimentos preliminares a contrato a pedido do titular) e **Art. 7º, IX** (Legítimo Interesse). | 2 anos após a conclusão do atendimento ou encerramento das tratativas. | Provedor de hospedagem em nuvem, servidor de envio de e-mails (SMTP) e equipe interna de vendas. | HTTPS/TLS, validação contra spam via Cloudflare Turnstile, banco de dados MySQL com acesso restrito por credenciais. |
| **02** | **Modal Flutuante de Checklist Técnico**<br>(`29-form-captura-lead.php` / `30-sticky-lead.php`) | Nome, empresa, e-mail e telefone. | Profissionais de segurança do trabalho, engenheiros e compradores industriais. | Disponibilização de material técnico de ancoragem e contato comercial direcionado para especificação de projetos. | **Art. 7º, V** (Procedimentos preliminares a contrato) e **Art. 7º, IX** (Legítimo Interesse). | 2 anos após o download ou até manifestação de oposição do titular. | Provedor de hospedagem, CRM comercial e equipe técnica de vendas. | Sanitização estrita de entradas, nonce de segurança WordPress, proteção anti-bot. |
| **03** | **Orçamento / Cotação WooCommerce**<br>(`/cotacao/` e `/finalizar-orcamento/`) | Nome completo, e-mail, telefone, empresa, cidade, estado e itens do catálogo selecionados. *(Nota: Não há transação por cartão no site; trata-se de cotação B2B)*. | Clientes B2B, construtoras, indústrias e empresas de engenharia. | Emissão de proposta técnica e comercial formal de ancoragem, memorial de cálculo preliminar e cotação de frete. | **Art. 7º, V** (Execução de procedimentos preliminares e contrato comercial a pedido do titular). | 5 anos após a emissão da proposta comercial (resguardo de direitos e prazos prescricionais do Código Civil). | Banco de dados WordPress/WooCommerce, sistema interno de faturamento/ERP e representantes comerciais. | Criptografia SSL/TLS, banco de dados seguro, logs de transação, controle estrito de papéis (roles) no WordPress. |
| **04** | **Trabalhe Conosco / Envio de Currículo**<br>(`33-form-trabalhe-conosco.php`) | Nome, e-mail, telefone, cidade, área de interesse e arquivo de currículo (PDF/Word contendo histórico profissional e formação). | Candidatos a vagas de emprego na Uônix. | Triagem, avaliação de competências e recrutamento para oportunidades de trabalho na fábrica e escritório técnico. | **Art. 7º, V** (Procedimentos preliminares relacionados a contrato de trabalho a pedido do titular). | 6 a 12 meses após a conclusão do processo seletivo da vaga pleiteada. | Exclusivamente equipe de Recursos Humanos e Diretoria. **Não compartilhado com terceiros.** | Uploads armazenados em diretório seguro sem listagem pública, nomes de arquivos randomizados, bloqueio de extensões executáveis. |
| **05** | **Comentários no Blog Técnico**<br>(`10-comentarios-master.php`) | Nome, e-mail, empresa, endereço IP do autor, data/hora e texto do comentário. | Leitores, estudantes de engenharia e profissionais do setor. | Publicação de perguntas técnicas, interação com a comunidade e combate a spam e ofensas. | **Art. 7º, IX** (Legítimo Interesse do controlador) e **Art. 7º, I** (Consentimento voluntário ao submeter). | Enquanto o artigo correspondente permanecer publicado no blog ou até solicitação de exclusão pelo titular. | Banco de dados WordPress, moderação interna e serviço de validação anti-spam Cloudflare Turnstile. | Moderação prévia por administrador, Turnstile anti-bot, campo empresa higienizado antes da gravação. |
| **06** | **Inscrição de Newsletter Técnica**<br>(`32-form-newsletter.php`) | Endereço de e-mail e nome (quando fornecido). | Assinantes voluntários de informativos. | Envio de artigos técnicos, orientações sobre normas de segurança (NR-18, NR-35) e novidades industriais. | **Art. 7º, I** (Consentimento livre e inequívoco do titular). | Até a revogação do consentimento (exercício de *opt-out* pelo link presente no rodapé de cada envio). | Plataforma de automação de e-mail marketing / CRM. | Mecanismo de descadastro em 1 clique presente em todas as mensagens disparadas. |
| **07** | **Persistência First-Party & Autopreenchimento (PR #159)**<br>(`49-forms-global-autofill.php`) | Nome, empresa, e-mail, telefone, cidade e estado. *(Restrição técnica: CPF/CNPJ e endereços residenciais completos NUNCA são persistidos)*. | Visitantes recorrentes do website. | Conveniência do usuário: autopreencher dados de contato entre múltiplos formulários do site, evitando redigitação manual. | **Art. 7º, I** (Consentimento específico condicionado ao aceite da categoria no banner da AdOpt). | 1 ano ou até a limpeza de dados pelo usuário ou revogação de cookies. | Exclusivamente local no navegador do titular (`localStorage` e cookie `uonix_lead_profile`). **Não transferido para servidores sem submissão ativa**. | Modelo estrito **Fail-Closed**: sem consentimento expresso da AdOpt, nenhum dado é gravado; em caso de rejeição (`_adoptReject`), dados locais são eliminados imediatamente. |
| **08** | **Cookies, Métricas e Rastreamento**<br>(`38-integracoes-analytics-lgpd.php`) | Endereço IP (anonimizado), identificadores de cookie (`_ga`, `_gid`, `_fbp`), parâmetros de URL e telemetria de navegação. | Todos os visitantes do site. | Diagnóstico de integridade do portal, estatísticas de audiência (GA4 via GTM), medição de conversão de anúncios e proteção contra bots. | **Art. 7º, I** (Consentimento via banner AdOpt para cookies analíticos e de marketing) e **Art. 7º, IX** (Legítimo Interesse para cookies essenciais). | De 24 horas (`_gid`) a 2 anos (`_ga`), conforme especificação de cada ferramenta. | Google LLC (GA4, GTM), Meta Platforms (Meta Pixel), Cloudflare (Turnstile) e AdOpt (CMP). | Google Consent Mode v2 implementado, disparo de tags condicionado ao consentimento no banner AdOpt, mascaramento de IPs. |

---

## 3. Gestão de Fornecedores e Operadores (Terceiros Envolvidos)

| Operador / Prestador | Serviço Prestado | Localização dos Servidores | Mecanismo de Conformidade e Salvaguarda |
| :--- | :--- | :--- | :--- |
| **Provedor de Hospedagem / Nuvem** | Servidores Web, PHP e Banco de Dados MySQL | Brasil / EUA | Acordo de Processamento de Dados (DPA) com garantia de confidencialidade e criptografia em repouso. |
| **Cloudflare Inc.** | Proteção contra tráfego malicioso (Turnstile), DNS e CDN | Global | DPA Cloudflare em conformidade com padrões internacionais e encriptação TLS. |
| **AdOpt (goadopt.io)** | Plataforma de Gestão de Consentimento (CMP) e cookies | Brasil | Solução brasileira nativa para atendimento estrito à LGPD e registros auditáveis de consentimento. |
| **Google LLC** | Google Tag Manager (GTM) e Google Analytics 4 (GA4) | EUA / União Europeia | Cláusulas Contratuais Padrão (SCC) e configuração de anonimização de IP ativada. |
| **Meta Platforms Inc.** | Mensuração de conversões de anúncios (Meta Pixel) | EUA | Termos de Ferramentas para Empresas da Meta com opção de tratamento restrito de dados. |

---

## 4. Política de Retenção, Descarte e Eliminação (Arts. 15 e 16)

Os dados pessoais tratados pela Uônix são eliminados ou anonimizados nas seguintes hipóteses:
1. **Alcance da Finalidade:** Quando o atendimento ou processo de cotação for finalizado e não houver necessidade legal de retenção.
2. **Revogação do Consentimento:** Nas hipóteses baseadas em consentimento (newsletter e cookies de marketing), os dados são excluídos ou desativados mediante solicitação do titular.
3. **Decurso do Prazo Regulatório:**
   * Logs de acesso: 6 meses (Art. 15 do Marco Civil da Internet).
   * Documentos pré-contratuais e orçamentos: 5 anos (Prescrição cível geral - Código Civil Art. 206).
   * Currículos não selecionados: até 12 meses.
4. **Descarte Seguro:** Exclusão lógica definitiva de registros em bancos de dados e destruição segura de arquivos temporários anexados.

---

## 5. Procedimento de Atendimento aos Direitos do Titular (Art. 18)

1. **Recepção de Demandas:** As requisições de confirmação, acesso, correção, eliminação, portabilidade ou revogação são recebidas pelo e-mail `administrativo@uonix.com.br` ou via Formulário de Contato no site (opção "LGPD").
2. **Validação de Identidade:** O DPO poderá solicitar confirmação de dados para evitar vazamento ou entrega indevida a terceiros fraudadores.
3. **Prazo de Resposta:** Resposta simplificada imediata ou declaração clara e completa em até 15 (quinze) dias úteis, conforme regulamentação da ANPD.
4. **Gratuidade:** Todo atendimento e fornecimento de informações ao titular é realizado de forma 100% gratuita.

---
*Documento auditado e validado em conformidade com a Lei Geral de Proteção de Dados Pessoais (Lei 13.709/2018).*
