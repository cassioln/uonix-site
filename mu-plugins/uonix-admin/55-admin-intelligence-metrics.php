<?php
/**
 * Central de Inteligência — camada de dados.
 *
 * Detecta oportunidades acionáveis a partir do snapshot já sincronizado pelo
 * 53-admin-analytics-metrics.php. Não faz chamada de rede, não agenda cron e não
 * renderiza nada: consome o snapshot existente e devolve estrutura pronta para o
 * painel e para o e-mail.
 *
 * Contrato: docs/uonix-insights-inteligencia.md
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uonix_intelligence_seo_rules' ) ) {
	/**
	 * Limiares da regra "Vitórias Fáceis" (striking distance).
	 *
	 * A issue #193 apresenta três faixas divergentes para a posição (4-15, 4-12 e
	 * 4-10). O contrato fixa 4 a 12. `max_ctr` está em fração, não em porcentagem,
	 * porque é assim que a Search Console API devolve o CTR.
	 *
	 * `min_impressions` é PISO DE RUÍDO, não critério de relevância. A priorização
	 * por volume é feita por ordenação: a função ordena os candidatos por impressões
	 * decrescentes e devolve os primeiros. Quem separa oportunidade boa de ruim é o
	 * ranking, não o piso.
	 *
	 * O valor anterior era 100 e vinha da especificação de produto, não de medição.
	 * Medido em produção em 2026-09-22: a consulta de maior volume do site inteiro
	 * tem 106 impressões em 30 dias, então o critério eliminava 110 das 111 consultas
	 * e o módulo devolvia zero por construção.
	 *
	 * O piso existe porque, neste volume, `max_ctr` já implica ZERO clique: para
	 * qualquer N até 33 impressões, `clicks < 0,03 * N` só é satisfeito com nenhum
	 * clique. Então toda linha admitida tem zero clique, e abaixo de um punhado de
	 * impressões isso não é oportunidade perdida — é consulta que quase ninguém
	 * teve a chance de clicar. O piso descarta essa ausência de informação.
	 */
	function uonix_intelligence_seo_rules() {
		return array(
			'min_position'    => 4.0,
			'max_position'    => 12.0,
			'min_impressions' => 5,
			'max_ctr'         => 0.03,
			'period_days'     => 30,
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_seo_differentiators' ) ) {
	/**
	 * Diferenciais que a sugestão por IA (54) pode afirmar, e só eles. Confirmados
	 * pela Uônix como verdadeiros e completos em 2026-09-30.
	 */
	function uonix_intelligence_seo_differentiators() {
		return array( 'Aço Inox 304/316', 'Laudo com ART', 'Conforme NBR 16325', 'Ensaio de Arrancamento', 'Pronta Entrega' );
	}
}

if ( ! function_exists( 'uonix_intelligence_normalize_term' ) ) {
	/**
	 * Normaliza para comparação: minúsculas e acentos reduzidos, sem depender de
	 * intl/iconv, que não estão garantidos no host.
	 */
	function uonix_intelligence_normalize_term( $value ) {
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $value, 'UTF-8' ) : strtolower( (string) $value );
		$map = array(
			'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
			'é' => 'e', 'ê' => 'e', 'è' => 'e',
			'í' => 'i', 'î' => 'i',
			'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
			'ú' => 'u', 'û' => 'u', 'ü' => 'u',
			'ç' => 'c',
		);
		return strtr( $value, $map );
	}
}

if ( ! function_exists( 'uonix_intelligence_unavailable' ) ) {
	/**
	 * Resposta padronizada quando não há base para afirmar nada.
	 *
	 * Fail-soft e explícito: o consumidor recebe o motivo, não um array vazio que
	 * se confunde com "nenhuma oportunidade encontrada". São estados diferentes.
	 */
	function uonix_intelligence_unavailable( $reason, $extra = array() ) {
		return array_merge(
			array(
				'available'   => false,
				'reason'      => (string) $reason,
				'source'      => 'search_console',
				'synced_at'   => '',
				'stale'       => true,
				'period_days' => uonix_intelligence_seo_rules()['period_days'],
				'universe'    => 0,
				'rows'        => array(),
			),
			is_array( $extra ) ? $extra : array()
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_seo_opportunities' ) ) {
	/**
	 * Oportunidades de SEO em distância de salto, a partir do snapshot de 30 dias.
	 *
	 * Cada resposta carrega `source`, `synced_at` e `stale` para que o bloco que a
	 * renderize possa declarar sua própria procedência, inclusive declarar-se
	 * desatualizado sozinho — exigência do contrato.
	 *
	 * @param array|false|null $snapshot Snapshot já carregado, ou null para ler.
	 * @param int              $limit    Máximo de linhas devolvidas.
	 */
	function uonix_intelligence_seo_opportunities( $snapshot = null, $limit = 5 ) {
		$rules = uonix_intelligence_seo_rules();
		$limit = is_int( $limit ) && $limit > 0 ? $limit : 5;

		if ( null === $snapshot ) {
			$snapshot = function_exists( 'uonix_analytics_metrics_get_snapshot' )
				? uonix_analytics_metrics_get_snapshot( $rules['period_days'] )
				: false;
		}
		if ( ! is_array( $snapshot ) ) {
			return uonix_intelligence_unavailable( 'snapshot_missing' );
		}

		$synced_at = isset( $snapshot['updated_at'] ) ? (string) $snapshot['updated_at'] : '';
		$stale     = function_exists( 'uonix_analytics_metrics_snapshot_is_fresh' )
			? ! uonix_analytics_metrics_snapshot_is_fresh( $snapshot )
			: true;
		$context   = array( 'synced_at' => $synced_at, 'stale' => $stale );

		// Snapshot legado (v1) não tem `period_days`, e `mark_stale()` pode promover um
		// payload legado para a chave corrente. Sem esta distinção, a ausência do campo
		// seria reportada como "período divergente" — diagnóstico falso que manda quem
		// depura olhar para o seletor de período em vez de para a migração.
		if ( ! isset( $snapshot['period_days'] ) ) {
			return uonix_intelligence_unavailable( 'snapshot_legacy', $context );
		}

		// Os limiares são expressos em 30 dias. Aplicá-los a outro período produziria
		// número sem significado, então é recusa explícita, não adaptação silenciosa.
		if ( (int) $snapshot['period_days'] !== $rules['period_days'] ) {
			return uonix_intelligence_unavailable( 'period_mismatch', $context );
		}

		// Snapshot v2 não tem `queries_extended`. Ausência é dado insuficiente, jamais
		// zero oportunidades: com 10 consultas a regra não teria o que peneirar.
		if ( ! isset( $snapshot['search_console']['queries_extended'] ) || ! is_array( $snapshot['search_console']['queries_extended'] ) ) {
			return uonix_intelligence_unavailable( 'queries_extended_missing', $context );
		}
		$universe = $snapshot['search_console']['queries_extended'];

		// Página líder por consulta (53). Ausente no snapshot é `null`, "aguardando a
		// próxima sincronização"; presente sem a consulta é '', "não identificada".
		$query_pages = isset( $snapshot['search_console']['query_pages'] ) && is_array( $snapshot['search_console']['query_pages'] )
			? $snapshot['search_console']['query_pages']
			: null;

		$matches = array();
		foreach ( $universe as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['query'], $row['impressions'], $row['ctr'], $row['position'] ) ) {
				continue;
			}
			$query       = (string) $row['query'];
			$impressions = (float) $row['impressions'];
			$ctr         = (float) $row['ctr'];
			$position    = (float) $row['position'];
			if ( '' === $query ) {
				continue;
			}
			if ( $position < $rules['min_position'] || $position > $rules['max_position'] ) {
				continue;
			}
			if ( $impressions <= $rules['min_impressions'] ) {
				continue;
			}
			if ( $ctr >= $rules['max_ctr'] ) {
				continue;
			}
			$matches[] = array(
				'query'       => $query,
				'position'    => $position,
				'impressions' => $impressions,
				'ctr'         => $ctr,
				'clicks'      => isset( $row['clicks'] ) ? (float) $row['clicks'] : 0.0,
				'target_page' => null === $query_pages ? null : ( isset( $query_pages[ $query ] ) && is_string( $query_pages[ $query ] ) ? $query_pages[ $query ] : '' ),
			);
		}

		// Maior volume primeiro: entre duas consultas igualmente próximas do topo, a
		// de mais impressões devolve mais clique pelo mesmo esforço. Empate resolvido
		// pela consulta, para a ordem ser estável entre execuções.
		usort(
			$matches,
			function ( $a, $b ) {
				if ( $a['impressions'] === $b['impressions'] ) {
					return strcmp( $a['query'], $b['query'] );
				}
				return ( $a['impressions'] < $b['impressions'] ) ? 1 : -1;
			}
		);

		return array(
			'available'   => true,
			'reason'      => '',
			'source'      => 'search_console',
			'synced_at'   => $synced_at,
			'stale'       => $stale,
			'period_days' => $rules['period_days'],
			'universe'    => count( $universe ),
			'rows'        => array_slice( $matches, 0, $limit ),
		);
	}
}

// ---------------------------------------------------------------------------
// Destinatários do relatório executivo.
//
// E-mail de destinatário não é segredo, então `wp_options` é legítimo aqui — ao
// contrário de token de API, que fica em constante no wp-config conforme o
// contrato. Ver docs/uonix-insights-inteligencia.md.
// ---------------------------------------------------------------------------

if ( ! function_exists( 'uonix_intelligence_recipients_option' ) ) {
	function uonix_intelligence_recipients_option() {
		return 'uonix_executive_report_recipients';
	}
}

if ( ! function_exists( 'uonix_intelligence_report_hook' ) ) {
	/**
	 * Nome do evento de cron do relatório executivo.
	 *
	 * Existe como acessor, e não como string solta, porque quem exibe o próximo
	 * disparo e quem agenda o envio são arquivos diferentes. Se cada um escrevesse
	 * o nome à mão, uma divergência faria o painel afirmar "não agendado" para
	 * sempre, sem nada reprovar.
	 */
	function uonix_intelligence_report_hook() {
		return 'uonix_intelligence_weekly_report';
	}
}

if ( ! function_exists( 'uonix_intelligence_recipients_limit' ) ) {
	/**
	 * Teto de destinatários.
	 *
	 * Não é limitação técnica: é contenção de dano. Uma lista que cresce sem limite
	 * transforma um relatório interno em lista de distribuição, e cada endereço a
	 * mais é uma cópia de dado de desempenho comercial fora do controle.
	 */
	function uonix_intelligence_recipients_limit() {
		return 10;
	}
}

if ( ! function_exists( 'uonix_intelligence_sanitize_recipients' ) ) {
	/**
	 * Normaliza uma lista de destinatários.
	 *
	 * Aceita array ou texto com um endereço por linha (também tolera vírgula e
	 * ponto-e-vírgula como separadores). Descarta o que não for e-mail válido,
	 * deduplica sem diferenciar caixa e respeita o teto.
	 *
	 * @return array{recipients: array<int, string>, rejected: int}
	 */
	function uonix_intelligence_sanitize_recipients( $raw ) {
		if ( is_string( $raw ) ) {
			$partes = preg_split( '/[\r\n,;]+/', $raw );
			$raw    = is_array( $partes ) ? $partes : array();
		}
		if ( ! is_array( $raw ) ) {
			return array( 'recipients' => array(), 'rejected' => 0 );
		}

		$aceitos  = array();
		$vistos   = array();
		$recusados = 0;
		$limite   = uonix_intelligence_recipients_limit();

		foreach ( $raw as $item ) {
			if ( ! is_scalar( $item ) ) {
				++$recusados;
				continue;
			}
			$email = trim( (string) $item );
			if ( '' === $email ) {
				continue;
			}
			$email = function_exists( 'sanitize_email' ) ? sanitize_email( $email ) : $email;
			if ( '' === $email || ( function_exists( 'is_email' ) && ! is_email( $email ) ) ) {
				++$recusados;
				continue;
			}
			$chave = strtolower( $email );
			if ( isset( $vistos[ $chave ] ) ) {
				continue;
			}
			if ( count( $aceitos ) >= $limite ) {
				++$recusados;
				continue;
			}
			$vistos[ $chave ] = true;
			$aceitos[]        = $email;
		}

		return array( 'recipients' => $aceitos, 'rejected' => $recusados );
	}
}

if ( ! function_exists( 'uonix_intelligence_get_recipients' ) ) {
	function uonix_intelligence_get_recipients() {
		$saved = function_exists( 'get_option' ) ? get_option( uonix_intelligence_recipients_option(), array() ) : array();
		$normalizado = uonix_intelligence_sanitize_recipients( is_array( $saved ) ? $saved : array() );
		return $normalizado['recipients'];
	}
}

if ( ! function_exists( 'uonix_intelligence_save_recipients' ) ) {
	/**
	 * Persiste a lista de destinatários.
	 *
	 * Escrita é só do dono do ksio.dev, com nonce. A visualização do painel segue
	 * `edit_posts`, como o resto do Insights: ler quem recebe é diferente de mudar
	 * quem recebe.
	 */
	function uonix_intelligence_save_recipients() {
		// Esconder o formulário não bloqueia um POST direto. Sem o 49 não há como
		// reconhecer o dono, e a gravação é recusada.
		if ( ! function_exists( 'uonix_ksio_can_configure_insights' ) || ! uonix_ksio_can_configure_insights() ) {
			wp_die( esc_html__( 'Sem permissão para alterar os destinatários do relatório.', 'uonix' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'uonix_intelligence_save_recipients' );

		// Aceita texto (um por linha) e também array, que é o formato de um campo
		// repetido. Tratar array como entrada inválida faria um POST legítimo
		// `uonix_recipients[]=` apagar a lista inteira e reportar sucesso.
		$raw = isset( $_POST['uonix_recipients'] ) ? wp_unslash( $_POST['uonix_recipients'] ) : '';
		if ( is_array( $raw ) ) {
			$entrada = $raw;
		} elseif ( is_scalar( $raw ) ) {
			$entrada = (string) $raw;
		} else {
			$entrada = '';
		}
		$normalizado = uonix_intelligence_sanitize_recipients( $entrada );

		if ( function_exists( 'update_option' ) ) {
			update_option( uonix_intelligence_recipients_option(), $normalizado['recipients'], false );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'uonix-analytics',
					'tab'  => 'settings',
					'uonix_recipients_saved'    => count( $normalizado['recipients'] ),
					'uonix_recipients_rejected' => $normalizado['rejected'],
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
add_action( 'admin_post_uonix_intelligence_save_recipients', 'uonix_intelligence_save_recipients' );

if ( ! function_exists( 'uonix_intelligence_recipients_guard_write' ) ) {
	/**
	 * Trava de gravação da opção dos destinatários: quem não é o dono não muda o
	 * valor, por caminho nenhum que passe por `update_option()`, inclusive
	 * `/wp-admin/options.php`. O único gravador legítimo é o handler
	 * `uonix_intelligence_save_recipients()`, acima, que já roda como dono.
	 *
	 * O WP-CLI passa, como na trava da licença (50): quem tem SSH já tem acesso
	 * mais amplo que o painel. Roda por último no filtro específico da opção.
	 */
	function uonix_intelligence_recipients_guard_write( $value, $old_value ) {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return $value;
		}
		if ( function_exists( 'uonix_ksio_can_configure_insights' ) && uonix_ksio_can_configure_insights() ) {
			return $value;
		}

		return $old_value;
	}
}
add_filter( 'pre_update_option_' . uonix_intelligence_recipients_option(), 'uonix_intelligence_recipients_guard_write', PHP_INT_MAX, 2 );

if ( ! function_exists( 'uonix_intelligence_resolve_term_path' ) ) {
	/**
	 * Termo de taxonomia pública que um caminho do site abre, ou null (#307, #344).
	 *
	 * `url_to_postid()` só resolve post: categoria de produto, categoria e tag do blog ficam
	 * de fora. `/olhal-de-ancoragem/`, a terceira página mais encontrada na busca, é o arquivo
	 * da `product_cat` #34, servido por uma regra do Rank Math que tira a base da categoria.
	 *
	 * **A regra de reescrita decide, e não o slug.** O slug `olhal-de-ancoragem` existe em três
	 * taxonomias (product_cat, post_tag e product_tag, medido em produção em 2026-10-01).
	 * Procurá-lo em cada taxonomia escolheria pela ordem da busca. Aqui as regras são
	 * percorridas na ordem gravada, como em `url_to_postid()` do core:
	 *   - a primeira regra que casa decide;
	 *   - a regra de página é pulada quando a página não existe (`use_verbose_page_rules`);
	 *   - se a regra que decide não for de taxonomia pública, não há termo;
	 *   - feed e embed do termo, e regra que também identifica um post, não contam.
	 *
	 * Endereço que o WordPress redireciona para o termo, como a URL com a base antiga
	 * (`/product-category/olhal-de-ancoragem/`, canonicalizada por `redirect_canonical()`),
	 * resolve para o próprio termo de destino.
	 *
	 * Só lê: a opção `rewrite_rules`, que é autoload, e o termo pelo slug.
	 *
	 * @param string     $path  Caminho ou URL do próprio site.
	 * @param array|null $rules Para teste; o padrão é a opção `rewrite_rules`.
	 * @return array{taxonomy: string, id: int, name: string, description: string}|null
	 */
	function uonix_intelligence_resolve_term_path( $path, $rules = null ) {
		$caminho = is_string( $path ) ? (string) wp_parse_url( $path, PHP_URL_PATH ) : '';
		$pedido  = trim( $caminho, '/' );
		if ( '' === $pedido || ! function_exists( 'get_taxonomies' ) || ! function_exists( 'get_term_by' ) ) {
			return null;
		}
		$regras = null === $rules ? ( function_exists( 'get_option' ) ? get_option( 'rewrite_rules' ) : array() ) : $rules;
		if ( ! is_array( $regras ) || array() === $regras ) {
			return null;
		}

		$por_variavel = array();
		foreach ( (array) get_taxonomies( array( 'public' => true ), 'objects' ) as $nome => $taxonomia ) {
			if ( is_object( $taxonomia ) && ! empty( $taxonomia->query_var ) && is_string( $taxonomia->query_var ) ) {
				$por_variavel[ $taxonomia->query_var ] = (string) $nome;
			}
		}
		global $wp_rewrite;
		$verboso = is_object( $wp_rewrite ) && ! empty( $wp_rewrite->use_verbose_page_rules );

		foreach ( $regras as $expressao => $consulta ) {
			if ( ! is_string( $consulta ) ) {
				continue;
			}
			// O core casa o pedido cru; o `WP::parse_request()` também tenta decodificado.
			if ( 1 !== preg_match( '#^' . $expressao . '#', $pedido, $m ) && 1 !== preg_match( '#^' . $expressao . '#', urldecode( $pedido ), $m ) ) {
				continue;
			}
			if ( $verboso && 1 === preg_match( '/pagename=\$matches\[([0-9]+)\]/', $consulta, $vm ) ) {
				$pagina = isset( $m[ $vm[1] ] ) && function_exists( 'get_page_by_path' ) ? get_page_by_path( $m[ $vm[1] ] ) : null;
				if ( ! $pagina ) {
					continue;
				}
			}
			$query = preg_replace( '!^.+\?!', '', $consulta );
			$query = preg_replace_callback(
				'/\$matches\[([0-9]+)\]/',
				static function ( $x ) use ( $m ) {
					return isset( $m[ (int) $x[1] ] ) ? urlencode( $m[ (int) $x[1] ] ) : '';
				},
				$query
			);
			parse_str( $query, $vars );
			// Feed e embed do termo não são a página que se otimiza. Regra que também identifica
			// um post (`name`, `p`, `pagename`… ou a `query_var` de um tipo de post) serve o post,
			// e não o arquivo do termo (BAIXOS 1 e 2 da revisão do PR #372).
			$de_post = array( 'name', 'p', 'pagename', 'page_id', 'attachment', 'attachment_id' );
			if ( function_exists( 'get_post_types' ) ) {
				foreach ( (array) get_post_types( array( 'public' => true ), 'objects' ) as $tipo ) {
					if ( is_object( $tipo ) && ! empty( $tipo->query_var ) && is_string( $tipo->query_var ) ) {
						$de_post[] = $tipo->query_var;
					}
				}
			}
			if ( isset( $vars['feed'] ) || isset( $vars['embed'] ) || array() !== array_intersect( $de_post, array_keys( array_filter( $vars, static function ( $v ) { return is_string( $v ) && '' !== $v; } ) ) ) ) {
				return null;
			}
			foreach ( $vars as $variavel => $valor ) {
				if ( ! isset( $por_variavel[ $variavel ] ) || ! is_string( $valor ) || '' === trim( $valor, '/' ) ) {
					continue;
				}
				// Categoria aninhada chega como `pai/filha`: o termo é o último segmento.
				$partes = explode( '/', trim( $valor, '/' ) );
				$termo  = get_term_by( 'slug', (string) end( $partes ), $por_variavel[ $variavel ] );
				if ( ! is_object( $termo ) || ! isset( $termo->term_id ) ) {
					return null;
				}

				return array(
					'taxonomy'    => $por_variavel[ $variavel ],
					'id'          => (int) $termo->term_id,
					'name'        => isset( $termo->name ) ? (string) $termo->name : '',
					'description' => isset( $termo->description ) ? (string) $termo->description : '',
				);
			}

			return null;
		}

		return null;
	}
}
