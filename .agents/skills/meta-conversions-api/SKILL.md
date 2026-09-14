---
name: meta-conversions-api
description: "Implementar, auditar e otimizar a Meta Conversions API (CAPI), Meta Pixel, Parameter Builder Library, deduplicação de eventos (event_name + event_id), qualidade de correspondência de eventos (EMQ - Event Match Quality), conformidade com LGPD/AdOpt e integração server-side com WordPress/PHP, Node.js e GTM. Use quando o usuário mencionar 'Meta CAPI', 'Conversions API', 'Pixel da Meta', 'Facebook CAPI', 'EMQ', 'Event Match Quality', 'deduplicação Meta', 'fbp', 'fbc', 'fbclid', 'hashes SHA-256 Meta', 'test_event_code' ou 'API de Conversões da Meta'."
metadata:
  version: 1.0.0
---

# Meta Conversions API (CAPI) & Meta Pixel — Guia de Engenharia

Especialista em integração, governança e otimização da **Meta Conversions API (CAPI)** e do **Meta Pixel**, seguindo estritamente a documentação oficial da Meta para desenvolvedores ([Meta Ads & Commerce Documentation](https://developers.facebook.com/documentation/ads-commerce/conversions-api)).

---

## 1. Modelo Híbrido Recomendado pela Meta

A Meta recomenda oficialmente a **arquitetura híbrida (Redundância com Deduplicação)**:
1. **Client-Side (Navegador / GTM):** Dispara `fbq('track', ...)` com `eventID`. Captura interações imediatas com baixa latência e grava os cookies de primeira parte (`_fbp`, `_fbc`).
2. **Server-Side (CAPI via Servidor):** Dispara a requisição HTTP direta da aplicação backend para a Graph API da Meta com o mesmo `event_name` e `event_id`. Garante 100% de entrega mesmo com ad blockers, falhas de rede, fechamento prematuro do navegador ou restrições de cookies (iOS / Safari ITP).
3. **Deduplicação Automática:** A Meta processa ambos os eventos; se chegarem com o mesmo `event_name` e `event_id` dentro de uma janela de 48 horas, a Meta contabiliza apenas **1 conversão**, somando os dados de correspondência de ambos para enriquecer o sinal.

---

## 2. Contrato Oficial da Graph API v20+

### Endpoint
```http
POST https://graph.facebook.com/v20.0/{DATASET_OR_PIXEL_ID}/events
```

### Headers Obrigatórios
* `Content-Type: application/json`
* `Authorization: Bearer {META_CAPI_ACCESS_TOKEN}` (ou passado como query param `?access_token=...`)

### Payload Canônico (JSON)
```json
{
  "data": [
    {
      "event_name": "Lead",
      "event_time": 1726185600,
      "event_id": "uonix-rfq-85942",
      "event_source_url": "https://www.uonix.com.br/orcamento-ancoragem-predial/",
      "action_source": "website",
      "user_data": {
        "em": ["f660ab912ec121d1b1e928a0bb4bc61b15f5ad44d5efdc4e1c92a25e99b8e44a"],
        "ph": ["1203d922f3d2f9..."],
        "fn": ["6981881..."],
        "client_ip_address": "201.86.120.45",
        "client_user_agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)...",
        "fbp": "fb.1.1726180000.123456789",
        "fbc": "fb.1.1726180000.IwAR1..."
      },
      "custom_data": {
        "content_name": "Solicitação de Orçamento Ancoragem Predial",
        "currency": "BRL",
        "value": 2500.00
      }
    }
  ],
  "test_event_code": "TEST12345"
}
```

> [!NOTE]
> O parâmetro `"test_event_code"` deve ser incluído **apenas** durante testes e validações na aba *Eventos de Teste* do Gerenciador de Eventos. Em produção, este parâmetro deve ser omitido.

---

## 3. Especificação dos Parâmetros de Dados do Usuário (`user_data`)

A pontuação de **Qualidade de Correspondência do Evento (EMQ - Event Match Quality)** da Meta depende diretamente da normalização correta dos dados do usuário. 

### Regras Estritas de Formatação e Criptografia (SHA-256)

Todos os campos de PII (Informações de Identificação Pessoal) **devem ser normalizados antes do hash SHA-256**:

| Parâmetro | Descrição | Regra de Normalização Prévia | Hash SHA-256? |
| :--- | :--- | :--- | :---: |
| `em` | E-mail | Minúsculas (`strtolower`), sem espaços nas pontas (`trim`). | **SIM** |
| `ph` | Telefone | Apenas dígitos numéricos. Código do país obrigatório (DDI Brasil = 55). Ex: `5511999998888`. | **SIM** |
| `fn` | Primeiro Nome | Minúsculas, sem espaços extras, sem pontuação. | **SIM** |
| `ln` | Sobrenome | Minúsculas, sem espaços extras, sem pontuação. | **SIM** |
| `ct` | Cidade | Minúsculas, sem espaços extras, sem acentos (ASCII). | **SIM** |
| `st` | Estado | Código de 2 letras em minúsculas (ex: `sp`, `rj`, `mg`). | **SIM** |
| `zp` | CEP / Código Postal | Apenas números ou código postal sem hífen/espaços (ex: `01310100`). | **SIM** |
| `country` | País | Código ISO 3166-1 alpha-2 em minúsculas (ex: `br`). | **SIM** |
| `external_id` | ID Único no CRM | String única associada ao usuário (ex: ID no banco). | **SIM** |
| `client_ip_address` | IP do visitante | IP real do usuário (IPv4 ou IPv6). | **NÃO** |
| `client_user_agent` | Navegador | String de User Agent enviada no cabeçalho HTTP. | **NÃO** |
| `fbp` | Cookie do Pixel | Valor do cookie primário `_fbp` salvo no navegador. | **NÃO** |
| `fbc` | Cookie de Clique | Valor do cookie `_fbc` (criado quando há `fbclid` na URL). | **NÃO** |

> [!CAUTION]
> **NUNCA aplique hash em `client_ip_address`, `client_user_agent`, `fbp` ou `fbc`!**
> A Meta espera esses campos em texto puro (*raw*). Aplicar hash neles invalida a correspondência.

---

## 4. Deduplicação Determinística (Client + Server)

Para evitar duplicidade de contagem nas campanhas de anúncios:
1. **Chave Primária de Deduplicação:** O par `event_name` + `event_id`.
2. **GTM (Navegador):**
   ```javascript
   fbq('track', 'Lead', {
     content_name: 'Solicitação de Orçamento Ancoragem Predial',
     currency: 'BRL',
     value: 2500.00
   }, {
     eventID: 'uonix-rfq-85942'
   });
   ```
3. **CAPI (Servidor PHP / Backend):**
   ```json
   {
     "event_name": "Lead",
     "event_id": "uonix-rfq-85942",
     ...
   }
   ```
4. **Resolução pela Meta:**
   * Se o evento do navegador chegar primeiro: o evento é registrado. Quando o servidor chega depois, a Meta cruza os dados adicionais de IP/User-Agent e deduplica.
   * Se o evento do servidor chegar primeiro (ex: navegador bloqueou o JS do Pixel): o evento é registrado pelo servidor sem perda.

---

## 5. Captura dos Cookies `_fbp` e `_fbc`

No backend (PHP / Node / Python), recupere os cookies nativos da Meta:

```php
// Captura do _fbp
$fbp = isset($_COOKIE['_fbp']) ? sanitize_text_field($_COOKIE['_fbp']) : null;

// Captura do _fbc (ou montagem com base no fbclid recebido na query string)
$fbc = null;
if (isset($_COOKIE['_fbc'])) {
    $fbc = sanitize_text_field($_COOKIE['_fbc']);
} elseif (!empty($_GET['fbclid'])) {
    // Formato canônico da Meta: fb.{subdomínio_index}.{timestamp_ms}.{fbclid}
    $fbc = 'fb.1.' . round(microtime(true) * 1000) . '.' . sanitize_text_field($_GET['fbclid']);
}
```

---

## 6. Captura Confiável do IP Real do Cliente (Reverse Proxy / CDN)

Em servidores atrás de Cloudflare, Nginx ou balanceadores de carga:

```php
function get_real_client_ip() {
    $headers = [
        'HTTP_CF_CONNECTING_IP', // Cloudflare
        'HTTP_X_FORWARDED_FOR',   // Proxies / Load Balancers
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR'
    ];

    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ipList = explode(',', $_SERVER[$header]);
            $ip = trim($ipList[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}
```

---

## 7. Mapeamento de Eventos Padrão (Standard Events) da Uônix

| Ação do Usuário no Site | Evento Meta Canônico | Gatilho GTM v32 | Parâmetros `custom_data` Recomendados |
| :--- | :--- | :--- | :--- |
| **Solicitação de Orçamento (RFQ)** | `Lead` | Envio de formulário de RFQ | `content_name: 'Solicitação de Orçamento'`, `currency: 'BRL'`, `value: ...` |
| **Formulário de Contato** | `Contact` | Envio de formulário de contato | `content_name: 'Contato via Formulário Institucional'` |
| **Download de Checklist Técnico** | `CompleteRegistration` | Download de material rico | `content_name: 'Checklist Técnico de Ancoragem Predial'` |
| **Assinatura de Newsletter** | `Subscribe` | Envio de formulário de newsletter | `content_name: 'Newsletter Uônix'` |
| **Clique no Botão do WhatsApp** | `Contact` (+ `trackCustom`) | Clique no botão de WhatsApp | `content_name: 'Contato WhatsApp'`, `custom_data: { button_type: 'floating' }` |
| **Clique em Telefone** | `Contact` | Clique em link `tel:` | `content_name: 'Contato Telefone'` |
| **Clique em E-mail** | `Contact` | Clique em link `mailto:` | `content_name: 'Contato Email'` |
| **Adicionar ao Carrinho (B2B)** | `AddToCart` | Adicionar item | `content_ids`, `content_type: 'product'` |
| **Iniciar Finalização de RFQ** | `InitiateCheckout` | Avançar etapa de orçamento | `content_category: 'Orçamento de Ancoragem Predial'` |

---

## 8. Governança LGPD (Consent Mode & AdOpt)

Para conformidade estrita com a **LGPD (Lei 13.709/2018)**:
1. **Client-Side:** As tags GTM só disparam se `ad_storage: 'granted'` tiver sido emitido pela ponte AdOpt.
2. **Server-Side:** 
   * Se o usuário **rejeitou** cookies de marketing ou revogou o consentimento no AdOpt, o backend deve **não enviar** o evento para a Conversions API ou enviar com os dados de identificação pessoal omitidos/anonimizados.
   * Não envie dados sensíveis (origem racial, saúde, orientação religiosa). Apenas dados de contato corporativo e contexto B2B.

---

## 9. Classe Helper Canônica em PHP (Pronta para Produção)

```php
<?php
/**
 * Uonix Meta Conversions API Client
 */
class Uonix_Meta_CAPI {

    private static function hash_field($value) {
        if (empty($value)) return null;
        return hash('sha256', trim(strtolower((string)$value)));
    }

    private static function format_phone($phone) {
        if (empty($phone)) return null;
        $clean = preg_replace('/\D/', '', (string)$phone);
        // Adiciona DDI Brasil se faltar
        if (strlen($clean) === 10 || strlen($clean) === 11) {
            $clean = '55' . $clean;
        }
        return self::hash_field($clean);
    }

    public static function send_event($event_name, $event_id, $user_data = [], $custom_data = [], $test_code = null) {
        $pixel_id = getenv('META_PIXEL_ID') ?: '461430774437593';
        $token = getenv('META_CAPI_ACCESS_TOKEN');

        if (empty($token) || empty($pixel_id)) {
            return false;
        }

        // Construção do user_data segundo o Parameter Builder
        $formatted_user_data = [
            'client_ip_address' => self::get_ip(),
            'client_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ];

        if (!empty($user_data['email'])) {
            $formatted_user_data['em'] = [self::hash_field($user_data['email'])];
        }
        if (!empty($user_data['phone'])) {
            $formatted_user_data['ph'] = [self::format_phone($user_data['phone'])];
        }
        if (!empty($user_data['first_name'])) {
            $formatted_user_data['fn'] = [self::hash_field($user_data['first_name'])];
        }
        if (!empty($user_data['last_name'])) {
            $formatted_user_data['ln'] = [self::hash_field($user_data['last_name'])];
        }
        if (!empty($_COOKIE['_fbp'])) {
            $formatted_user_data['fbp'] = sanitize_text_field($_COOKIE['_fbp']);
        }
        if (!empty($_COOKIE['_fbc'])) {
            $formatted_user_data['fbc'] = sanitize_text_field($_COOKIE['_fbc']);
        }

        $payload = [
            'data' => [
                [
                    'event_name' => $event_name,
                    'event_time' => time(),
                    'event_id' => $event_id,
                    'event_source_url' => home_url(add_query_arg([], $GLOBALS['wp']->request ?? '')),
                    'action_source' => 'website',
                    'user_data' => $formatted_user_data,
                    'custom_data' => $custom_data,
                ]
            ]
        ];

        if (!empty($test_code)) {
            $payload['test_event_code'] = $test_code;
        }

        $url = "https://graph.facebook.com/v20.0/{$pixel_id}/events";

        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 10,
            'blocking' => false, // Não trava o carregamento da página do visitante
        ]);

        return !is_wp_error($response);
    }

    private static function get_ip() {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }
}
```

---

## 10. Checklist de Validação e Diagnóstico

Antes de considerar qualquer evento pronto para produção:
- [ ] O `event_name` corresponde exatamente à nomenclatura padrão da Meta (`Lead`, `Contact`, `Subscribe`, etc.).
- [ ] O `event_id` gerado no servidor é idêntico ao emitido no DataLayer para o GTM.
- [ ] Emails e telefones estão normalizados e criptografados com SHA-256.
- [ ] Telefone inclui o código de país `55` (E.164).
- [ ] `client_ip_address`, `client_user_agent`, `fbp` e `fbc` foram enviados em texto puro (sem hash).
- [ ] O evento foi testado na aba **Eventos de Teste** do Gerenciador de Eventos usando `test_event_code`.
- [ ] O status retornado da API foi `{"events_received": 1, "messages": []}`.
- [ ] A nota de Qualidade de Correspondência (EMQ) atingiu o nível **Bom** ou **Excelente** (acima de 7.0/10).
