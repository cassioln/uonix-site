<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * UONIX Snippets - WooCommerce - tela de confirmacao de pedido.
 *
 * Origem: qa-uonix.code-snippets.php.
 * Arquivo gerado pela organizacao dos snippets exportados do site.
 */

// -----------------------------------------------------------------------------
// Bloco 1 - linhas 4203-4725 do export original.
// -----------------------------------------------------------------------------
/**
 * Tela de confirmacao pedido
 */
/**
 * UÔNIX: Master Thank You Page V32.0 (Design Premium e Compacto)
 * ---------------------------------------------------------
 * - Layout Super Compacto (redução de paddings e margins).
 * - Tabela de Produtos Zebrada para melhor leitura.
 * - Otimização de Impressão A4 (margens mínimas para caber em 1 página).
 * - Botão Imprimir: Fora da <ul>, alinhado à direita como link minimalista.
 * - Resumo: Número e Data à esquerda em container flexbox.
 * - PDF Dinâmico: Nome do arquivo inclui o Nº do Pedido.
 */

add_action('wp_footer', function() {
    if ( ! is_wc_endpoint_url( 'order-received' ) ) return;
    ?>
    <style id="uonix-thankyou-v32-css">
        /* =========================================================
           1. INTERFACE WEB (NAVEGADOR) - DESIGN PREMIUM COMPACTO
           ========================================================= */
        
        /* Mensagem de Sucesso Principal */
        .woo-orcamento .woocommerce-order::before {
            content: "Solicitação recebida com sucesso!";
            display: block; 
            color: #0e3780; /* Azul Corporativo */
            text-align: left;
            padding: 10px 0 5px 0; /* COMPACTO */
            font-size: 28px; /* COMPACTO */
            font-weight: 900; 
            text-transform: uppercase;
            letter-spacing: -0.5px;
            line-height: 1.1;
        }

        /* Texto Secundário ("Em breve entraremos em contato") */
        .woocommerce-notice--success {
            text-align: left !important; 
            color: #475569 !important;
            font-size: 16px !important; 
            padding: 0 0 15px 0 !important; /* COMPACTO */
            border: none !important;
            font-weight: 500 !important;
        }

        .uonix-full-orange-line { display: none !important; }

        /* NOVO PADRÃO DE TÍTULOS DE SEÇÃO */
        .uonix-main-section-title,
        .woocommerce-column__title {
            color: #0e3780 !important; 
            font-size: 20px !important; /* COMPACTO */
            font-weight: 900 !important; 
            text-transform: uppercase !important; 
            letter-spacing: -0.5px !important;
            margin: 25px 0 15px 0 !important; /* COMPACTO */
            display: block !important;
            padding-left: 12px !important;
            border-left: 5px solid #f76a0c !important; /* Barra Laranja */
            line-height: 1.2 !important;
        }

        /* CAIXA DE RESUMO DO PEDIDO */
        .uonix-resumo-wrapper {
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            background: #f8fafc !important;
            border: 1px solid #e2e8f0 !important;
            border-radius: 6px !important; 
            padding: 15px 20px !important; /* COMPACTO */
            margin-bottom: 20px; /* COMPACTO */
            flex-wrap: wrap;
            gap: 15px;
        }

        .woocommerce-order-overview {
            display: flex !important;
            gap: 10px !important; 
            list-style: none !important;
            margin: 0 !important;
            padding: 0 !important;
            border: none !important;
        }

        .woocommerce-order-overview li { 
            border: none !important; padding: 0 !important; font-size: 12px; 
            text-transform: uppercase; color: #64748b !important; font-weight: 700;
        }

        .woocommerce-order-overview li strong { 
            display: block; color: #1a2b3c !important; font-size: 18px; font-weight: 900; margin-top: 2px; 
        }

        /* BOTÃO IMPRIMIR */
        .uonix-print-btn {
            background: #ffffff !important; color: #0e3780 !important;
            padding: 8px 16px !important; /* COMPACTO */
            text-transform: uppercase; font-weight: 800; font-size: 15px; cursor: pointer;
            border: 1px solid #e2e8f0 !important; border-radius: 4px !important;
            transition: all 0.3s ease; display: flex; align-items: center; gap: 6px;
        }

        .uonix-print-btn::before {
            content: ''; display: inline-block; width: 14px; height: 14px; background-color: currentColor;
            -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 512 512'%3E%3Cpath d='M128 0C92.7 0 64 28.7 64 64v96h64V64H354.7L384 93.3V160h64V93.3c0-17-6.7-33.3-18.7-45.3L400 18.7C388 6.7 371.7 0 354.7 0H128zM512 256c0-35.3-28.7-64-64-64H64c-35.3 0-64 28.7-64 64v160c0 35.3 28.7 64 64v-64h384v64c35.3 0 64-28.7 64-64V256zM432 272c8.8 0 16 7.2 16 16s-7.2 16-16 16s-16-7.2-16-16s7.2-16 16-16zm-64 112H144v64c0 17.7 14.3 32 32 32H336c17.7 0 32-14.3 32-32v-64z'/%3E%3C/svg%3E") no-repeat center / contain;
            mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 512 512'%3E%3Cpath d='M128 0C92.7 0 64 28.7 64 64v96h64V64H354.7L384 93.3V160h64V93.3c0-17-6.7-33.3-18.7-45.3L400 18.7C388 6.7 371.7 0 354.7 0H128zM512 256c0-35.3-28.7-64-64-64H64c-35.3 0-64 28.7-64 64v160c0 35.3 28.7 64 64v-64h384v64c35.3 0 64-28.7 64-64V256zM432 272c8.8 0 16 7.2 16 16s-7.2 16-16 16s-16-7.2-16-16s7.2-16 16-16zm-64 112H144v64c0 17.7 14.3 32 32 32H336c17.7 0 32-14.3 32-32v-64z'/%3E%3C/svg%3E") no-repeat center / contain;
        }

        .uonix-print-btn:hover { color: #f76a0c !important; border-color: #f76a0c !important; }

        /* FAIXAS DE CABEÇALHO DAS TABELAS */
        .uonix-bar {
            background-color: #0e3780 !important; 
            color: #ffffff !important;
            padding: 8px 15px !important; /* COMPACTO */
            font-size: 13px !important; 
            font-weight: 800 !important;
            text-transform: uppercase !important; 
            display: flex !important;
            justify-content: space-between; 
            width: 100% !important; 
            margin: 0 !important;
            border: 1px solid #0e3780;
            border-radius: 6px 6px 0 0; 
            box-sizing: border-box;
        }

        /* TABELA DE PRODUTOS ZEBRADA E COMPACTA */
        .woocommerce-table--order-details { 
            width: 100% !important; 
            border-collapse: collapse !important; 
            border: 1px solid #e2e8f0 !important;
            border-radius: 0 0 6px 6px !important;
            margin-bottom: 20px !important; /* COMPACTO */
            overflow: hidden;
        }
        
        .woocommerce-table--order-details thead { display: none !important; }
        
        /* Efeito Zebrado nas Linhas */
        .woocommerce-table--order-details tbody tr:nth-child(even) td {
            background-color: #f8fafc !important; /* Azul/Cinza bem clarinho */
        }
        .woocommerce-table--order-details tbody tr:nth-child(odd) td {
            background-color: #ffffff !important; /* Branco */
        }

        .woocommerce-table--order-details tbody td { 
            padding: 10px 15px !important; /* COMPACTO */
            border-bottom: 1px solid #eef2f7; 
            vertical-align: middle !important; 
        } 
		.woocommerce table.shop_table td  {
			line-height: 1 !important;
			
		}
        .woocommerce-table__product-name a { 
            color: #1a2b3c !important; font-weight: 800; text-decoration: none; font-size: 16px; display: block; 
        }
        .woocommerce-table__product-name a:hover { color: #f76a0c !important; }
        
        .wc-item-meta { 
            margin: 2px 0 0 0 !important; padding: 0 !important; list-style: none !important; 
            font-size: 12px !important; color: #64748b !important; font-weight: 600 !important; 
        }
        .wc-item-meta p { display: inline; margin: 0; }
        
        .uonix-qtd-cell { 
            width: 60px; text-align: center !important; font-weight: 800; 
            color: #0e3780 !important; font-size: 15px; 
            background: transparent !important; /* Herda a cor do Zebrado */
            border-left: 1px solid #eef2f7;
        }

        /* TABELA DE DADOS DO SOLICITANTE COMPACTA */
        .shop_table.custom-fields {
            width: 100% !important; border: 1px solid #e2e8f0 !important; border-top: none !important;
            border-radius: 0 0 6px 6px !important; margin-bottom: 20px !important; border-collapse: collapse !important;
        }

        .shop_table.custom-fields tbody tr { border-top: 1px solid #eef2f7; }

        .shop_table.custom-fields th { 
            width: 180px !important; color: #64748b !important; font-size: 15px; 
            text-align: left !important; border: none !important; padding: 8px 15px !important; /* COMPACTO */
            font-weight: 700 !important; background: #ffffff !important;
        }
        
        .shop_table.custom-fields td { 
            color: #1a2b3c !important; font-weight: 700 !important; border: none !important; 
            padding: 8px 15px !important; background: #ffffff !important; font-size: 16px; /* COMPACTO */
        }

        /* TEXTO DE OBSERVAÇÃO E ENDEREÇO COMPACTOS */
        .uonix-obs-content, .woocommerce-customer-details address { 
            padding: 10px 15px !important; /* COMPACTO */
            border: 1px solid #e2e8f0 !important; border-top: none !important;
            border-radius: 0 0 6px 6px !important; margin-bottom: 20px !important;
            font-style: normal !important; color: #1a2b3c !important; font-weight: 600 !important;
            line-height: 1.4 !important; background: #ffffff; font-size: 15px;
        }

        /* OCULTAR ELEMENTOS REDUNDANTES */
        .woocommerce-order-details__title, .woocommerce-table--order-details tfoot, 
        .woocommerce-order-overview__total, .product-total, .product-quantity, .woocommerce-order-overview__payment-method, h2.woocommerce-column__title { 
			display: none !important; 
		}

        /* Responsividade Básica */
        @media (max-width: 768px) {
            .uonix-resumo-wrapper { flex-direction: column; align-items: flex-start !important; }
            .uonix-print-container { width: 100%; }
            .uonix-print-btn { width: 100%; justify-content: center; }
            .shop_table.custom-fields th, .shop_table.custom-fields td { display: block; width: 100% !important; padding: 6px 15px !important; }
            .shop_table.custom-fields th { padding-bottom: 0 !important; color: #f76a0c !important; }
            .shop_table.custom-fields td { padding-top: 2px !important; }
        }

        /* =========================================================
           2. CONFIGURAÇÃO DE IMPRESSÃO (A4 COMPACTO)
           ========================================================= */
        #uonix-print-header, #uonix-print-footer { display: none; }

        @media print {
            @page { size: A4; margin: 5mm 10mm; } /* Margens super finas para caber mais */
            
            header, footer, .uonix-print-btn, .uonix-floating-cart-wrapper, .kadence-header-row, 
            .woocommerce-order::before, .woocommerce-notice--success, .uonix-full-orange-line,
            .uonix-main-section-title, .uonix-resumo-wrapper { display: none !important; }
            
            body { background: #fff !important; font-size: 9pt !important; color: #000 !important; line-height: 1.3 !important;}
            .woo-orcamento { width: 100% !important; margin: 0 !important; padding: 0 !important; }

            /* Cabeçalho no Topo */
            #uonix-print-header { 
                display: flex !important; justify-content: space-between; align-items: flex-start; 
                border-bottom: 2px solid #1a202c; padding-bottom: 8px; margin-bottom: 12px;
            }
            #uonix-print-header img { width: 140px; }
            .print-header-meta { text-align: right; color: #1a202c; }
            .print-doc-title { font-weight: 900; font-size: 12pt; text-transform: uppercase; margin-bottom: 2px; }
            .print-order-info { font-size: 9pt; font-weight: 700; text-transform: uppercase; }

            /* Tabelas e Caixas Compactas */
            .uonix-bar { 
                background-color: #e2e8f0 !important; color: #000 !important; border: 1px solid #ccc; 
                -webkit-print-color-adjust: exact; print-color-adjust: exact; 
                margin-top: 8px !important; border-radius: 0 !important; padding: 4px 8px !important; font-size: 9pt !important;
            }
            
            .woocommerce-table--order-details { margin-bottom: 8px !important; border: 1px solid #ccc !important; }
            .woocommerce-table--order-details tbody td { padding: 4px 8px !important; font-size: 9pt !important; border-bottom: 1px solid #eee; }
            .woocommerce-table__product-name a { font-size: 9pt !important; color: #000 !important;}
            .wc-item-meta { font-size: 8pt !important; margin: 0 !important; }

            /* Efeito Zebrado Ativo na Impressão */
            .woocommerce-table--order-details tbody tr:nth-child(even) td {
                background-color: #f4f6f8 !important;
                -webkit-print-color-adjust: exact; print-color-adjust: exact;
            }

            /* Compactar Dados do Solicitante (Texto Corrido no PDF) */
            .shop_table.custom-fields, .woocommerce-customer-details address { 
                display: block !important; border: 1px solid #ccc !important; padding: 6px !important; margin-top: 0 !important; margin-bottom: 8px !important; border-radius: 0 !important;
            }
            .shop_table.custom-fields tbody, .shop_table.custom-fields tr, .shop_table.custom-fields th, .shop_table.custom-fields td { 
                display: inline !important; padding: 0 !important; font-size: 8pt !important; background: transparent !important;
            }
            .shop_table.custom-fields th { font-weight: bold !important; color: #000 !important; }
            .shop_table.custom-fields th::after { content: " "; }
            .shop_table.custom-fields tr::after { content: " | "; }
            .shop_table.custom-fields tr:last-child::after { content: ""; }
            
            /* Rodapé Corporativo Oficial */
            #uonix-print-footer {
                display: block !important; position: fixed; bottom: 0; width: 100%;
                text-align: center; font-size: 7pt; color: #666; border-top: 1px solid #ccc; padding-top: 4px;
            }
        }
    </style>

    <div id="uonix-print-header">
        <div class="header-left">
            <img src="/wp-content/uploads/2026/01/logo-uonix-600x188.png" alt="Uônix">
        </div>
        <div class="header-right print-header-meta">
            <div class="print-doc-title">Solicitação de Orçamento</div>
            <div class="print-order-info">
                Pedido de Orçamento: <span id="print-data-number"></span> | Data: <span id="print-data-date"></span>
            </div>
        </div>
    </div>

    <div id="uonix-print-footer">
        Rua Melo Franco, 115 - Sala 01 | Guarulhos - SP | CEP: 07033-220 | CNPJ: 20.775.536/0001-96<br>
        Telefone 11 4372 9366
    </div>

    <script id="uonix-thankyou-v30-js">
    (function($) {
        $(document).ready(function() {
            // 1. Coleta de dados e Wrapper de Resumo
            var orderNum = $('.woocommerce-order-overview__order strong').text().trim();
            var orderDate = $('.woocommerce-order-overview__date strong').text().trim();
            
            $('#print-data-number').text(orderNum);
            $('#print-data-date').text(orderDate);

            var $ulResumo = $('.woocommerce-order-overview');
            $ulResumo.wrap('<div class="uonix-resumo-wrapper"></div>');
            $('<div class="uonix-print-container"><button class="uonix-print-btn" id="btn-uonix-print">Imprimir Pedido de Orçamento</button></div>').insertAfter($ulResumo);

            $('<h2 class="uonix-main-section-title">Detalhes do pedido de orçamento</h2>').insertAfter('.uonix-resumo-wrapper');
            
            // 2. Tabela de Itens e Qtd
            $('<div class="uonix-bar"><span>Itens Solicitados</span><span>Qtd.</span></div>').insertBefore('.woocommerce-table--order-details');
            $('.woocommerce-table--order-details tbody tr.order_item').each(function() {
                var $row = $(this);
                var $link = $row.find('.product-name a');
                $link.html($link.html().replace(/<br\s*[\/]?>/gi, ' - '));
                var qty = $row.find('.product-quantity').text().replace(/[^\d]/g, '');
                $row.append('<td class="uonix-qtd-cell">' + (qty ? qty : '1') + '</td>');
            });

            // 3. Nome do PDF Inteligente
            $('#btn-uonix-print').on('click', function() {
                var originalTitle = document.title;
                document.title = 'Solicitacao-Uonix-Pedido-' + orderNum;
                window.print();
                document.title = originalTitle;
            });

            // 4. Seções e Reordenação Dados Solicitante
            var obsText = $('.woocommerce-table--order-details tfoot td').text().trim();
            if (obsText !== "" && obsText.length > 5) {
                $('<div class="uonix-bar">Observação</div><div class="uonix-obs-content">' + obsText + '</div>').insertAfter('.woocommerce-table--order-details');
            }

            var $customTable = $('.woocommerce-table--custom-fields');
            if ($customTable.length) {
                $('<div class="uonix-bar">Dados do Solicitante</div>').insertBefore($customTable);

                $customTable.find('th').each(function() {
                    var txt = $(this).text();
                    if (txt.indexOf('Nome do Solicitante') > -1) $(this).text('Responsável:');
                });

                /* ====== ALTERAÇÃO 1: ORDEM + TELEFONE + E-MAIL ====== */
                var $address = $('.woocommerce-customer-details address');
                var phoneText = $.trim($address.find('.woocommerce-customer-details--phone').text());
                var emailText = $.trim($address.find('.woocommerce-customer-details--email').text());

                // remove do address para não duplicar
                $address.find('.woocommerce-customer-details--phone, .woocommerce-customer-details--company .woocommerce-customer-details--email').remove();

                function rowExists(label) {
                    var exists = false;
                    $customTable.find('tbody tr th').each(function() {
                        if ($.trim($(this).text()) === label) exists = true;
                    });
                    return exists;
                }

                if (phoneText && !rowExists('Telefone:')) {
                    $customTable.find('tbody').append('<tr><th>Telefone:</th><td>' + phoneText + '</td></tr>');
                }
                if (emailText && !rowExists('E-mail:')) {
                    $customTable.find('tbody').append('<tr><th>E-mail:</th><td>' + emailText + '</td></tr>');
                }

                // Ajusta automaticamente o rótulo CPF/CNPJ conforme a quantidade de dígitos
				function uonixOnlyDigits(value) {
					return (value || '').replace(/\D/g, '');
				}

				function uonixFormatDoc(digits) {
					if (digits.length === 11) {
						return digits.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
					}

					if (digits.length === 14) {
						return digits.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5');
					}

					return digits;
				}

				$customTable.find('tbody tr').each(function() {
					var $tr = $(this);
					var $th = $tr.find('th').first();
					var $td = $tr.find('td').first();

					var label = $.trim($th.text()).replace(/\s+/g, ' ');
					var value = $.trim($td.text());
					var digits = uonixOnlyDigits(value);

					// Só mexe nas linhas que já vierem como CPF ou CNPJ
					if (/^(CPF|CNPJ):?$/i.test(label)) {
						if (digits.length === 11) {
							$th.text('CPF:');
							$td.text(uonixFormatDoc(digits));
						} else if (digits.length === 14) {
							$th.text('CNPJ:');
							$td.text(uonixFormatDoc(digits));
						}
					}
				});

				// ORDEM: Responsável, Telefone, E-mail, Nome da Empresa, CPF/CNPJ
				var order = ["Responsável:", "Telefone:", "E-mail:", "Nome da Empresa:", "CPF:", "CNPJ:"];

                var rows = $customTable.find('tbody tr').get();
                rows.sort(function(a, b) {
                    var aKey = $.trim($(a).find('th').text());
                    var bKey = $.trim($(b).find('th').text());
                    var ai = order.indexOf(aKey); if (ai === -1) ai = 999;
                    var bi = order.indexOf(bKey); if (bi === -1) bi = 999;
                    return ai - bi;
                });
                $.each(rows, function(index, row) {
                    $customTable.children('tbody').append(row);
                });
            }

            /* ====== ALTERAÇÃO 2: ENDEREÇO ====== */
            $('<div class="uonix-bar">Endereço</div>').insertBefore('.woocommerce-customer-details address');

            var $address = $('.woocommerce-customer-details address');
            var numero = '';

            function uonixEscapeRegExp(value) {
                return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            }

            function uonixAddressHasNumber(address, number) {
                address = $.trim(String(address || '').replace(/\s+/g, ' '));
                number = $.trim(String(number || '').replace(/\s+/g, ' '));

                if (!address || !number) return false;

                return new RegExp('(^|[\\s,.-])' + uonixEscapeRegExp(number) + '($|[\\s,.-])', 'i').test(address);
            }

            function uonixJoinAddressNumber(address, number) {
                address = $.trim(String(address || '').replace(/\s+/g, ' '));
                number = $.trim(String(number || '').replace(/\s+/g, ' '));

                if (!address || !number) return address;

                if (uonixAddressHasNumber(address, number)) {
                    return $.trim(address.replace(new RegExp(',\\s*(' + uonixEscapeRegExp(number) + ')(?=$|[\\s,.-])', 'i'), ' $1').replace(/\s+/g, ' '));
                }

                return address + ' ' + number;
            }

            // tenta pegar "Numero:" dentro da tabela custom fields
            if ($customTable && $customTable.length) {
                $customTable.find('tbody tr').each(function() {
                    var $tr = $(this);
                    var th = $.trim($tr.find('th').text()).toLowerCase();

                    if (th === 'numero:' || th === 'número:' || th === 'numero' || th === 'número') {
                        numero = $.trim($tr.find('td').text());
                        $tr.remove(); // remove do "Dados do Solicitante"
                    }
                });
            }

            // remove telefone/email restantes no address
            $address.find('.woocommerce-customer-details--phone, .woocommerce-customer-details--email').remove();

            // lê linhas do address atual
            var addressHtml = $address.html() || '';
            var textWithBreaks = addressHtml.replace(/<br\s*\/?>/gi, '\n');
            var addressText = $('<div>').html(textWithBreaks).text();
            var lines = addressText.split('\n').map(function(s){ return $.trim(s); }).filter(Boolean);
            
            // Remove "Nome da Empresa" do endereço se estiver como primeira linha
            var companyName = '';
            if ($customTable && $customTable.length) {
              $customTable.find('tbody tr').each(function () {
                var thTxt = $.trim($(this).find('th').text());
                if (thTxt === 'Nome da Empresa:' || thTxt === 'Nome da Empresa') {
                  companyName = $.trim($(this).find('td').text());
                }
              });
            }

            if (companyName && lines.length) {
              var firstLine = (lines[0] || '').toLowerCase();
              var comp = companyName.toLowerCase();
              if (firstLine === comp || firstLine.indexOf(comp) > -1) {
                lines.shift();
              }
            }

            // CEP
            var cep = '';
            for (var i = 0; i < lines.length; i++) {
                var m = lines[i].match(/\b\d{5}-?\d{3}\b/);
                if (m) { cep = m[0].replace('-', ''); break; }
            }
            if (cep && cep.length === 8) cep = cep.slice(0,5) + '-' + cep.slice(5);

            // UF e cidade
            var uf = '', city = '';
            for (var j = 0; j < lines.length; j++) {
                if (/^[A-Z]{2}$/.test(lines[j])) {
                    uf = lines[j];
                    city = (j > 0) ? lines[j - 1] : '';
                    break;
                }
            }

            // Rua e complemento
            var street = lines[0] || '';
            var complement = '';

            if (city) {
                var cityIndex = lines.indexOf(city);
                if (cityIndex > 1) {
                    complement = lines.slice(1, cityIndex).join(' ');
                } else if (lines.length > 3) {
                    complement = lines[1] || '';
                }
            } else if (lines.length > 1) {
                complement = lines[1] || '';
            }

            // monta no padrão desejado
            var out = [];
            if (street) out.push(uonixJoinAddressNumber(street, numero));
            if (complement) out.push(complement);
            if (city || uf) out.push((city ? city : '') + (city && uf ? ' / ' : '') + (uf ? uf : ''));
            if (cep) out.push('CEP: ' + cep);

            if (out.length) $address.html(out.join('<br>'));

            // 5. Garante Header no Topo (Impressão)
            $('#uonix-print-header').prependTo('.woocommerce-order');
        });
    })(jQuery);
    </script>
    <?php
}, 100);
