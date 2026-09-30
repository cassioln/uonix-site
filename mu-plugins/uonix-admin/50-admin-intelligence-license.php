<?php
/**
 * Licença da Central de Inteligência: o envio automático depende dela.
 *
 * O estado vem de duas camadas, com a mesma regra (`uonix_intelligence_license_evaluate()`):
 *
 * - as constantes do `wp-config.php`, a trava forte, que só muda com acesso ao servidor:
 *   - `KSIODEV_INTELLIGENCE_STATUS`: `active` (contratado), `trial` (cortesia) ou
 *     `suspended` (pausado);
 *   - `KSIODEV_INTELLIGENCE_VALID_UNTIL`: último dia com envio, `AAAA-MM-DD`, no fuso
 *     do site;
 * - a opção `uonix_intelligence_license`, gravada pelo dono do ksio.dev na aba
 *   Configurações do Uônix Insights, com os mesmos dois campos.
 *
 * O envio só sai quando as duas camadas permitem: vale a mais restritiva, e o painel
 * nunca religa o que a constante pausou. Sem constante e sem opção, o envio segue como
 * sempre. Com qualquer uma presente, valor que a regra não reconhece pausa o envio.
 * Isso cobre erro no valor, não no nome: constante com nome errado é constante
 * ausente, e o envio segue. A doc manda conferir com `wp eval` depois de cada mudança.
 *
 * A opção só é gravada pelo dono: `uonix_intelligence_license_guard_write()` barra
 * qualquer outro usuário, inclusive por `/wp-admin/options.php`. O WP-CLI passa, porque
 * quem tem SSH já altera o `wp-config.php`. Não há filtro de leitura sobre a
 * constante, então nenhum plugin reverte a suspensão dela. Decisão e motivos:
 * docs/uonix-insights-inteligencia.md, seção *Licenciamento*.
 *
 * Quem consulta: `uonix_intelligence_send_report()` (57), inclusive no envio de
 * teste, e `uonix_intelligence_anomaly_send_alert()` (58). Os dois tratam a
 * ausência deste arquivo como licença inativa.
 *
 * Hooks, e só estes dois, ambos restritos à própria licença: o handler do formulário
 * (`admin_post_uonix_intelligence_save_license`) e a trava de gravação da opção.
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

if ( ! function_exists( 'uonix_intelligence_license_option' ) ) {
	function uonix_intelligence_license_option() {
		return 'uonix_intelligence_license';
	}
}

if ( ! function_exists( 'uonix_intelligence_license_panel_evaluate' ) ) {
	/**
	 * A camada do painel, pela mesma regra das constantes.
	 *
	 * `null` é opção ausente: o painel não restringe. Opção presente que não seja
	 * `{status, valid_until}` com valores reconhecidos pausa, como constante inválida.
	 * As duas chaves são obrigatórias: `valid_until` ausente, nulo ou com o nome
	 * errado pausa com `invalid`. Só a string vazia é "sem data-limite".
	 *
	 * @param mixed             $gravado Valor da opção, ou null se ela não existe.
	 * @param DateTimeInterface $today   Hoje, no fuso do site.
	 */
	function uonix_intelligence_license_panel_evaluate( $gravado, DateTimeInterface $today ) {
		if ( null === $gravado ) {
			return uonix_intelligence_license_evaluate( null, null, $today );
		}
		if ( ! is_array( $gravado ) ) {
			return uonix_intelligence_license_evaluate( '', null, $today );
		}

		// Chave da data ausente, com o nome errado ou nula não é "sem data-limite": é
		// opção malformada, e pausa com `invalid` qualquer que seja o status gravado.
		if ( ! array_key_exists( 'valid_until', $gravado ) || null === $gravado['valid_until'] ) {
			return uonix_intelligence_license_evaluate( '', null, $today );
		}

		$status = array_key_exists( 'status', $gravado ) ? $gravado['status'] : '';
		$data   = '' !== $gravado['valid_until'] ? $gravado['valid_until'] : null;

		// Status ausente não é "só a data": no painel o status é sempre escolhido.
		return uonix_intelligence_license_evaluate( null === $status ? '' : $status, $data, $today );
	}
}

if ( ! function_exists( 'uonix_intelligence_license_combine' ) ) {
	/**
	 * Junta as duas camadas: vale a mais restritiva.
	 *
	 * Se alguma pausa, o estado é o dela, e a constante vem primeiro, por ser a trava
	 * forte. Se as duas enviam, vale o prazo mais curto; sem prazo nas duas, a
	 * constante. As camadas seguem no retorno, para a tela do dono.
	 *
	 * @return array{configured: bool, status: string, valid_until: string, sending: bool, reason: string, source: string, constant: array, panel: array}
	 */
	function uonix_intelligence_license_combine( array $constante, array $painel ) {
		$camadas = array( 'constant' => $constante, 'panel' => $painel );
		$vale    = '';

		foreach ( $camadas as $origem => $camada ) {
			if ( empty( $camada['sending'] ) ) {
				$vale = $origem;
				break;
			}
		}

		if ( '' === $vale ) {
			foreach ( $camadas as $origem => $camada ) {
				if ( empty( $camada['configured'] ) ) {
					continue;
				}
				// Enviando, `valid_until` é vazio ou uma data já validada, e `AAAA-MM-DD`
				// se compara como texto.
				if ( '' === $vale || ( '' !== $camada['valid_until'] && ( '' === $camadas[ $vale ]['valid_until'] || $camada['valid_until'] < $camadas[ $vale ]['valid_until'] ) ) ) {
					$vale = $origem;
				}
			}
		}

		$base = '' === $vale ? $constante : $camadas[ $vale ];

		return array(
			'configured'  => ! empty( $constante['configured'] ) || ! empty( $painel['configured'] ),
			'status'      => $base['status'],
			'valid_until' => $base['valid_until'],
			'sending'     => ! empty( $constante['sending'] ) && ! empty( $painel['sending'] ),
			'reason'      => $base['reason'],
			'source'      => $vale,
			'constant'    => $constante,
			'panel'       => $painel,
		);
	}
}

if ( ! function_exists( 'uonix_intelligence_license_state' ) ) {
	/**
	 * Estado da licença agora: constantes e opção do painel, combinadas.
	 *
	 * @param DateTimeInterface|null $today Para teste; o padrão é hoje no fuso do site.
	 */
	function uonix_intelligence_license_state( $today = null ) {
		if ( ! $today instanceof DateTimeInterface ) {
			$today = new DateTimeImmutable( 'now', function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' ) );
		}

		$constante = uonix_intelligence_license_evaluate(
			defined( 'KSIODEV_INTELLIGENCE_STATUS' ) ? constant( 'KSIODEV_INTELLIGENCE_STATUS' ) : null,
			defined( 'KSIODEV_INTELLIGENCE_VALID_UNTIL' ) ? constant( 'KSIODEV_INTELLIGENCE_VALID_UNTIL' ) : null,
			$today
		);
		$painel = uonix_intelligence_license_panel_evaluate(
			function_exists( 'get_option' ) ? get_option( uonix_intelligence_license_option(), null ) : null,
			$today
		);

		return uonix_intelligence_license_combine( $constante, $painel );
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

if ( ! function_exists( 'uonix_intelligence_license_source_label' ) ) {
	/**
	 * De onde vem o estado que vale, para o dono.
	 */
	function uonix_intelligence_license_source_label( $source ) {
		$mapa = array(
			'constant' => 'wp-config.php',
			'panel'    => 'painel do Uônix Insights',
		);

		return is_string( $source ) && isset( $mapa[ $source ] ) ? $mapa[ $source ] : '';
	}
}

if ( ! function_exists( 'uonix_intelligence_license_summary' ) ) {
	/**
	 * Uma linha para o dono do ksio.dev conferir, sem SSH, o estado que vale e de onde
	 * ele vem.
	 *
	 * @param array $state Retorno de uonix_intelligence_license_state().
	 */
	function uonix_intelligence_license_summary( $state ) {
		$origem = is_array( $state ) && isset( $state['source'] ) ? uonix_intelligence_license_source_label( $state['source'] ) : '';

		if ( ! is_array( $state ) || empty( $state['sending'] ) ) {
			$aviso = uonix_intelligence_license_message( $state );
			return '' === $origem ? $aviso : $aviso . ' Origem: ' . $origem . '.';
		}

		if ( empty( $state['configured'] ) ) {
			return 'Sem controle configurado: nem constante KSIODEV_INTELLIGENCE_* no wp-config.php, nem licença no painel. O envio segue ativo.';
		}

		$rotulo = 'trial' === $state['status'] ? 'Cortesia (trial)' : 'Contratada (active)';
		$prazo  = '' !== $state['valid_until']
			? ' até ' . uonix_intelligence_license_date_label( $state['valid_until'] )
			: ', sem data-limite';

		return $rotulo . $prazo . ( '' === $origem ? '' : ', pelo ' . $origem ) . '. O envio está ativo.';
	}
}

if ( ! function_exists( 'uonix_intelligence_license_layer_summary' ) ) {
	/**
	 * O que uma camada sozinha diz, para a tela do dono separar constante e painel.
	 *
	 * @param array $layer Retorno de uonix_intelligence_license_evaluate().
	 */
	function uonix_intelligence_license_layer_summary( $layer ) {
		if ( ! is_array( $layer ) || empty( $layer['configured'] ) ) {
			return 'Sem controle.';
		}
		$motivo = isset( $layer['reason'] ) ? (string) $layer['reason'] : 'invalid';
		$data   = isset( $layer['valid_until'] ) ? (string) $layer['valid_until'] : '';

		if ( ! empty( $layer['sending'] ) ) {
			$rotulo = isset( $layer['status'] ) && 'trial' === $layer['status'] ? 'Cortesia (trial)' : 'Contratada (active)';
			return $rotulo . ( '' !== $data ? ' até ' . uonix_intelligence_license_date_label( $data ) : ', sem data-limite' ) . '. Envia.';
		}
		if ( 'suspended' === $motivo ) {
			return 'Suspensa. Pausa o envio.';
		}
		if ( 'expired' === $motivo ) {
			return 'Vencida em ' . uonix_intelligence_license_date_label( $data ) . '. Pausa o envio.';
		}

		return 'Configuração inválida. Pausa o envio.';
	}
}

if ( ! function_exists( 'uonix_intelligence_license_panel_input' ) ) {
	/**
	 * Valida o formulário do painel antes de gravar. Nada inválido é gravado: o erro
	 * volta para a tela, e a licença anterior fica como estava.
	 *
	 * Apagar exige o valor explícito `none` (#322). Status vazio ou ausente é erro:
	 * antes era "sem controle", e salvar o formulário com a opção malformada, ou um
	 * POST sem o campo, apagava a opção e tirava a pausa sem o dono pedir.
	 *
	 * @param mixed $status      Status enviado; `none` é "sem controle pelo painel", e `null` é campo ausente.
	 * @param mixed $valid_until Data enviada; `''` é "sem data-limite".
	 * @return array{error: string, value: array|null} `value` null sem erro: apagar a opção.
	 */
	function uonix_intelligence_license_panel_input( $status, $valid_until ) {
		if ( 'none' === $status ) {
			return array( 'error' => '', 'value' => null );
		}
		if ( null === $status || '' === $status ) {
			return array( 'error' => 'missing', 'value' => null );
		}
		if ( ! is_string( $status ) || ! in_array( $status, array( 'active', 'trial', 'suspended' ), true ) ) {
			return array( 'error' => 'status', 'value' => null );
		}
		if ( ! is_string( $valid_until ) ) {
			return array( 'error' => 'date', 'value' => null );
		}
		if ( '' !== $valid_until ) {
			$data = DateTimeImmutable::createFromFormat( '!Y-m-d', $valid_until );
			if ( false === $data || $data->format( 'Y-m-d' ) !== $valid_until ) {
				return array( 'error' => 'date', 'value' => null );
			}
		}
		if ( 'trial' === $status && '' === $valid_until ) {
			return array( 'error' => 'trial', 'value' => null );
		}

		return array( 'error' => '', 'value' => array( 'status' => $status, 'valid_until' => $valid_until ) );
	}
}

if ( ! function_exists( 'uonix_intelligence_save_license' ) ) {
	/**
	 * Grava a licença do painel. Só o dono do ksio.dev, com nonce.
	 *
	 * Grava só `status` e `valid_until`, nunca o que mais vier no POST (a lição da #249).
	 */
	function uonix_intelligence_save_license() {
		// Sem o 49 não há como reconhecer o dono, e a gravação é recusada.
		if ( ! function_exists( 'uonix_ksio_can_configure_insights' ) || ! uonix_ksio_can_configure_insights() ) {
			wp_die( esc_html__( 'Sem permissão para alterar a licença da Central de Inteligência.', 'uonix' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'uonix_intelligence_save_license' );

		$status = isset( $_POST['uonix_license_status'] ) ? wp_unslash( $_POST['uonix_license_status'] ) : null;
		$data   = isset( $_POST['uonix_license_valid_until'] ) ? wp_unslash( $_POST['uonix_license_valid_until'] ) : '';
		$data   = is_string( $data ) ? trim( $data ) : $data;

		$entrada = uonix_intelligence_license_panel_input( $status, $data );
		$retorno = array( 'page' => 'uonix-analytics', 'tab' => 'settings' );

		if ( '' !== $entrada['error'] ) {
			$retorno['uonix_license_error'] = $entrada['error'];
		} elseif ( null === $entrada['value'] ) {
			delete_option( uonix_intelligence_license_option() );
			$retorno['uonix_license_saved'] = 'cleared';
		} else {
			update_option( uonix_intelligence_license_option(), $entrada['value'], true );
			$retorno['uonix_license_saved'] = '1';
		}

		wp_safe_redirect( add_query_arg( $retorno, admin_url( 'admin.php' ) ) );
		exit;
	}
}
add_action( 'admin_post_uonix_intelligence_save_license', 'uonix_intelligence_save_license' );

if ( ! function_exists( 'uonix_intelligence_license_guard_write' ) ) {
	/**
	 * Trava de gravação da opção da licença: quem não é o dono não muda o valor, por
	 * caminho nenhum que passe por `update_option()`, inclusive `/wp-admin/options.php`.
	 * Devolver o valor antigo faz o WordPress desistir da gravação.
	 *
	 * O WP-CLI passa: quem tem SSH já altera o `wp-config.php`, que é a trava forte.
	 * Roda por último no filtro específico da opção.
	 */
	function uonix_intelligence_license_guard_write( $value, $old_value ) {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return $value;
		}
		if ( function_exists( 'uonix_ksio_can_configure_insights' ) && uonix_ksio_can_configure_insights() ) {
			return $value;
		}

		return $old_value;
	}
}
add_filter( 'pre_update_option_' . uonix_intelligence_license_option(), 'uonix_intelligence_license_guard_write', PHP_INT_MAX, 2 );
