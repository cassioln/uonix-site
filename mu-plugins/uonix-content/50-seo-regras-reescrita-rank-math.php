<?php
/**
 * Devolve as regras de reescrita do Rank Math quando uma regravação as perdeu (#305).
 *
 * POR QUE ESTE ARQUIVO EXISTE
 *
 * Entre 16 e 28/09/2026, as 5 categorias de produto e o `/sitemap_index.xml` deram 404 em
 * produção, e ninguém percebeu por 12 dias (#302). A causa não foi código nem configuração:
 * a opção `rewrite_rules` foi regravada SEM as regras do Rank Math.
 *
 *   - O Rank Math não carrega módulo nenhum numa requisição de heartbeat
 *     (`includes/module/class-manager.php`, `Helper::is_heartbeat()`). Sem módulo, faltam as
 *     regras do sitemap, registradas no `init` pelo `Sitemap\Router`, e as das categorias sem
 *     base, que o `WooCommerce\Permalink_Watcher` acrescenta na hora da regravação, pelo filtro
 *     `rewrite_rules_array`.
 *   - O WooCommerce regrava as regras no `init:5` de qualquer requisição, heartbeat incluso,
 *     depois de se atualizar (`WC_Install::check_version()`) ou com a fila
 *     `woocommerce_queue_flush_rewrite_rules` ligada.
 *   - O Rank Math não se corrige sozinho: só regrava ao ser ativado, ao ligar ou desligar
 *     módulo e ao criar, editar ou apagar uma `product_cat`.
 *
 * Basta uma atualização do WooCommerce cuja primeira requisição seja um heartbeat, o que é
 * provável num site de pouco tráfego com o painel aberto, para a quebra voltar.
 *
 * COMO, E POR QUE ASSIM
 *
 * Em `wp_loaded`, depois do `init` inteiro, a guarda compara as regras de sitemap que o Rank
 * Math ACABOU de registrar nesta requisição (`$wp_rewrite->extra_rules_top`, com consulta
 * `index.php?sitemap=...` ou `index.php?xsl=...`) com as gravadas em `rewrite_rules`. Se
 * alguma falta, agenda `flush_rewrite_rules( false )` no `shutdown`, o mesmo adiamento que o
 * próprio Rank Math usa.
 *
 *   - A regra de sitemap é o sinal de que a última regravação rodou sem os módulos. As regras
 *     das categorias sem base somem junto com ela, e voltam junto na regravação, porque esta
 *     requisição tem os módulos carregados. Limite: com o módulo de sitemap desligado, a guarda
 *     não tem sinal e não faz nada.
 *   - Só compara o que foi registrado nesta requisição. Se um dia o Rank Math mudar a regra,
 *     ou o módulo não carregar, nada é registrado e nada é regravado: a guarda não entra em
 *     loop por causa de uma expressão fixa que deixou de existir.
 *   - NUNCA regrava num heartbeat, que é exatamente o defeito. Nessa requisição o Rank Math
 *     nem registra as regras, e a guarda confere o heartbeat do mesmo jeito que ele, antes.
 *   - O caminho normal percorre só as regras que os plugins registram com `add_rewrite_rule()`
 *     nesta requisição (22 no WordPress local em 2026-10-02, contra 459 gravadas), e faz um
 *     `isset` por regra de sitemap (três hoje) na `rewrite_rules`, que é autoload. Nenhuma
 *     consulta extra ao banco.
 *   - Cada reparo grava `uonix_rewrite_rules_repair`, sem autoload, com a hora e as regras que
 *     faltavam, e escreve no log do PHP. Um novo reparo só depois de
 *     `uonix_rewrite_guard_cooldown()` (15 min): se a regra nunca "pegar", a guarda regrava no
 *     máximo 4 vezes por hora, não a cada requisição.
 *
 * Em produção, o WP-Cron roda pelo `crontab` a cada 5 minutos, pelo WP-CLI, que é requisição
 * comum com os módulos carregados (`docs/ambientes.md`). Então o reparo acontece em minutos
 * mesmo sem visita.
 *
 * @package Uonix\Content
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'uonix_rewrite_guard_repair_option' ) ) {
	function uonix_rewrite_guard_repair_option() {
		return 'uonix_rewrite_rules_repair';
	}
}

if ( ! function_exists( 'uonix_rewrite_guard_cooldown' ) ) {
	/**
	 * Intervalo mínimo entre dois reparos, em segundos.
	 */
	function uonix_rewrite_guard_cooldown() {
		return 15 * MINUTE_IN_SECONDS;
	}
}

if ( ! function_exists( 'uonix_rewrite_guard_is_sitemap_query' ) ) {
	/**
	 * A consulta é de uma regra de sitemap? As três do Rank Math hoje: o índice, os
	 * sitemaps por tipo (`sitemap=`) e a folha de estilo (`xsl=`).
	 */
	function uonix_rewrite_guard_is_sitemap_query( $consulta ) {
		return is_string( $consulta ) && ( 0 === strpos( $consulta, 'index.php?sitemap=' ) || 0 === strpos( $consulta, 'index.php?xsl=' ) );
	}
}

if ( ! function_exists( 'uonix_rewrite_guard_missing' ) ) {
	/**
	 * Regras de sitemap registradas nesta requisição que não estão gravadas.
	 *
	 * @param array $registradas `$wp_rewrite->extra_rules_top`.
	 * @param array $gravadas    Opção `rewrite_rules`.
	 * @return string[] As expressões que faltam.
	 */
	function uonix_rewrite_guard_missing( array $registradas, array $gravadas ) {
		$faltam = array();
		foreach ( $registradas as $expressao => $consulta ) {
			if ( uonix_rewrite_guard_is_sitemap_query( $consulta ) && ! isset( $gravadas[ $expressao ] ) ) {
				$faltam[] = (string) $expressao;
			}
		}
		return $faltam;
	}
}

if ( ! function_exists( 'uonix_rewrite_guard_check' ) ) {
	/**
	 * Confere as regras e agenda a regravação quando as do Rank Math sumiram.
	 *
	 * @param int|null $now Para teste; o padrão é `time()`.
	 * @return string Estado, para teste e diagnóstico: `heartbeat`, `no_permalinks`,
	 *                `not_registered`, `empty`, `ok`, `cooldown` ou `scheduled`.
	 */
	function uonix_rewrite_guard_check( $now = null ) {
		// Mesmo critério de `RankMath\Helper::is_heartbeat()`: qualquer POST com essa ação.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- só leitura do nome da ação.
		if ( isset( $_POST['action'] ) && 'heartbeat' === $_POST['action'] ) {
			return 'heartbeat';
		}

		global $wp_rewrite;
		if ( ! is_object( $wp_rewrite ) || ! method_exists( $wp_rewrite, 'using_permalinks' ) || ! $wp_rewrite->using_permalinks() ) {
			return 'no_permalinks';
		}

		$registradas = isset( $wp_rewrite->extra_rules_top ) && is_array( $wp_rewrite->extra_rules_top ) ? $wp_rewrite->extra_rules_top : array();
		$tem_sitemap = false;
		foreach ( $registradas as $consulta ) {
			if ( uonix_rewrite_guard_is_sitemap_query( $consulta ) ) {
				$tem_sitemap = true;
				break;
			}
		}
		if ( ! $tem_sitemap ) {
			return 'not_registered';
		}

		// Vazia ou malformada, o próprio WordPress regenera sob demanda (`wp_rewrite_rules()`).
		$gravadas = get_option( 'rewrite_rules' );
		if ( ! is_array( $gravadas ) || array() === $gravadas ) {
			return 'empty';
		}

		$faltam = uonix_rewrite_guard_missing( $registradas, $gravadas );
		if ( array() === $faltam ) {
			return 'ok';
		}

		// Só chega aqui com as regras quebradas: a leitura extra não pesa no caminho normal.
		// O registro também faz uma segunda conferência na mesma requisição cair no intervalo.
		$agora    = is_int( $now ) ? $now : time();
		$anterior = get_option( uonix_rewrite_guard_repair_option() );
		if ( is_array( $anterior ) && isset( $anterior['at'] ) && is_int( $anterior['at'] )
			&& $anterior['at'] <= $agora && ( $agora - $anterior['at'] ) < uonix_rewrite_guard_cooldown() ) {
			return 'cooldown';
		}

		update_option(
			uonix_rewrite_guard_repair_option(),
			array(
				'at'      => $agora,
				'missing' => array_slice( $faltam, 0, 5 ),
			),
			false
		);
		error_log( sprintf( 'UONIX: rewrite_rules sem as regras do Rank Math (%s); regravação agendada no shutdown (#305).', implode( ', ', array_slice( $faltam, 0, 5 ) ) ) );
		add_action( 'shutdown', 'uonix_rewrite_guard_flush', 10, 0 );

		return 'scheduled';
	}
}

if ( ! function_exists( 'uonix_rewrite_guard_flush' ) ) {
	/**
	 * Regravação suave: só a opção, sem mexer no `.htaccess`.
	 */
	function uonix_rewrite_guard_flush() {
		flush_rewrite_rules( false );
	}
}

// `accepted_args = 0`: o callback não usa argumento, e `wp_loaded` não passa nenhum.
add_action( 'wp_loaded', 'uonix_rewrite_guard_check', 10, 0 );
