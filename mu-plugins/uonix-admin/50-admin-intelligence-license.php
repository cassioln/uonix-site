<?php
/**
 * Licença da Central de Inteligência: o envio automático depende dela.
 *
 * O estado vem de duas constantes do `wp-config.php`, e de mais lugar nenhum:
 *
 * - `KSIODEV_INTELLIGENCE_STATUS`: `active` (contratado), `trial` (cortesia) ou
 *   `suspended` (pausado);
 * - `KSIODEV_INTELLIGENCE_VALID_UNTIL`: último dia com envio, `AAAA-MM-DD`, no fuso
 *   do site.
 *
 * Sem nenhuma das duas, o envio segue como sempre. Com qualquer uma definida, vale
 * `uonix_intelligence_license_evaluate()`, e valor que ela não reconhece pausa o
 * envio. Isso cobre erro no valor, não no nome: constante com nome errado é
 * constante ausente, e o envio segue. A doc manda conferir com `wp eval` depois de
 * cada mudança.
 *
 * Não há filtro nem opção no banco, então nem um plugin nem um administrador do site
 * revertem a suspensão pelo painel. Decisão e motivos: docs/uonix-insights-inteligencia.md,
 * seção *Licenciamento*.
 *
 * Quem consulta: `uonix_intelligence_send_report()` (57), inclusive no envio de
 * teste, e `uonix_intelligence_anomaly_send_alert()` (58). Os dois tratam a
 * ausência deste arquivo como licença inativa.
 *
 * Este arquivo só define funções e não registra hook.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uonix_intelligence_license_evaluate' ) ) {
	/**
	 * Regra pura da licença. `null` é constante ausente.
	 *
	 * `trial` exige data-limite: cortesia sem fim é configuração incompleta, e pausa.
	 *
	 * @param mixed             $status      Valor de KSIODEV_INTELLIGENCE_STATUS, ou null.
	 * @param mixed             $valid_until Valor de KSIODEV_INTELLIGENCE_VALID_UNTIL, ou null.
	 * @param DateTimeInterface $today       Hoje, no fuso do site.
	 * @return array{configured: bool, status: string, valid_until: string, sending: bool, reason: string}
	 */
	function uonix_intelligence_license_evaluate( $status, $valid_until, DateTimeInterface $today ) {
		$estado = array(
			'configured'  => null !== $status || null !== $valid_until,
			'status'      => null === $status ? 'active' : ( is_string( $status ) ? $status : '' ),
			'valid_until' => is_string( $valid_until ) ? $valid_until : '',
			'sending'     => false,
			'reason'      => 'invalid',
		);

		if ( ! in_array( $estado['status'], array( 'active', 'trial', 'suspended' ), true ) ) {
			return $estado;
		}

		if ( 'suspended' === $estado['status'] ) {
			$estado['reason'] = 'suspended';
			return $estado;
		}

		if ( null === $valid_until ) {
			if ( 'trial' === $estado['status'] ) {
				return $estado;
			}
			$estado['sending'] = true;
			$estado['reason']  = '';
			return $estado;
		}

		// O formato de volta tem de bater com o de entrada: `2026-02-30` vira
		// `2026-03-02` no parser, e `2026-3-1` vira `2026-03-01`.
		$data = is_string( $valid_until ) ? DateTimeImmutable::createFromFormat( '!Y-m-d', $valid_until ) : false;
		if ( false === $data || $data->format( 'Y-m-d' ) !== $valid_until ) {
			return $estado;
		}

		if ( $today->format( 'Y-m-d' ) > $valid_until ) {
			$estado['reason'] = 'expired';
			return $estado;
		}

		$estado['sending'] = true;
		$estado['reason']  = '';
		return $estado;
	}
}

if ( ! function_exists( 'uonix_intelligence_license_state' ) ) {
	/**
	 * Estado da licença agora, lido das constantes.
	 *
	 * @param DateTimeInterface|null $today Para teste; o padrão é hoje no fuso do site.
	 */
	function uonix_intelligence_license_state( $today = null ) {
		if ( ! $today instanceof DateTimeInterface ) {
			$today = new DateTimeImmutable( 'now', function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' ) );
		}

		return uonix_intelligence_license_evaluate(
			defined( 'KSIODEV_INTELLIGENCE_STATUS' ) ? constant( 'KSIODEV_INTELLIGENCE_STATUS' ) : null,
			defined( 'KSIODEV_INTELLIGENCE_VALID_UNTIL' ) ? constant( 'KSIODEV_INTELLIGENCE_VALID_UNTIL' ) : null,
			$today
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_license_date_label' ) ) {
	/**
	 * `AAAA-MM-DD` para `DD/MM/AAAA`. Só é chamada com data já validada.
	 */
	function uonix_intelligence_license_date_label( $valid_until ) {
		$data = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $valid_until );

		return false === $data ? (string) $valid_until : $data->format( 'd/m/Y' );
	}
}

if ( ! function_exists( 'uonix_intelligence_license_message' ) ) {
	/**
	 * Aviso de contato para quem abre a Central com o envio pausado. Vazio quando o
	 * envio está ativo.
	 *
	 * @param array $state Retorno de uonix_intelligence_license_state().
	 */
	function uonix_intelligence_license_message( $state ) {
		if ( is_array( $state ) && ! empty( $state['sending'] ) ) {
			return '';
		}

		$motivo  = is_array( $state ) && isset( $state['reason'] ) ? (string) $state['reason'] : '';
		$pausado = 'o envio automático do relatório semanal e dos alertas de anomalia está pausado';

		if ( 'suspended' === $motivo ) {
			return 'O serviço da Central de Inteligência está suspenso, e ' . $pausado . '. Para reativar, fale com a ksio.dev.';
		}
		if ( 'expired' === $motivo ) {
			return sprintf(
				'A licença da Central de Inteligência venceu em %s, e %s. Para renovar, fale com a ksio.dev.',
				uonix_intelligence_license_date_label( $state['valid_until'] ),
				$pausado
			);
		}

		return 'A configuração de licença da Central de Inteligência não é válida, e ' . $pausado . ' até a correção. Fale com a ksio.dev.';
	}
}

if ( ! function_exists( 'uonix_intelligence_license_summary' ) ) {
	/**
	 * Uma linha para o dono do ksio.dev conferir, sem SSH, o que o `wp-config.php` diz.
	 *
	 * @param array $state Retorno de uonix_intelligence_license_state().
	 */
	function uonix_intelligence_license_summary( $state ) {
		if ( ! is_array( $state ) || empty( $state['sending'] ) ) {
			return uonix_intelligence_license_message( $state );
		}

		if ( empty( $state['configured'] ) ) {
			return 'Sem controle configurado: nenhuma constante KSIODEV_INTELLIGENCE_* no wp-config.php. O envio segue ativo.';
		}

		$rotulo = 'trial' === $state['status'] ? 'Cortesia (trial)' : 'Contratada (active)';
		$prazo  = '' !== $state['valid_until']
			? ' até ' . uonix_intelligence_license_date_label( $state['valid_until'] )
			: ', sem data-limite';

		return $rotulo . $prazo . '. O envio está ativo.';
	}
}
