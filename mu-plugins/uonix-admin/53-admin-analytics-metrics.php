<?php
/**
 * Uônix Insights — métricas agregadas GA4 e Search Console.
 *
 * Este módulo apenas lê APIs Google sob demanda/cron e persiste agregados.
 * Não emite tags, não coleta dados do visitante e não faz chamadas no render do admin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'uonix_analytics_metrics_error' ) ) {
	function uonix_analytics_metrics_error( $code ) {
		return new WP_Error( $code, 'Configuração de métricas indisponível.' );
	}
}

if ( ! function_exists( 'uonix_analytics_metrics_get_config' ) ) {
	function uonix_analytics_metrics_get_config( $key_path = null, $document_root = null, $ga4_property_id = '445033830', $search_console_site_url = 'sc-domain:uonix.com.br' ) {
		$key_path = null === $key_path && defined( 'UONIX_GOOGLE_ANALYTICS_SERVICE_ACCOUNT_FILE' )
			? UONIX_GOOGLE_ANALYTICS_SERVICE_ACCOUNT_FILE
			: $key_path;
		$document_root = null === $document_root && defined( 'ABSPATH' ) ? ABSPATH : $document_root;
		$key_path = is_string( $key_path ) ? trim( $key_path ) : '';

		if ( '' === $key_path || ! is_file( $key_path ) || is_link( $key_path ) || ! is_readable( $key_path ) ) {
			return uonix_analytics_metrics_error( 'service_account_file_invalid' );
		}

		$real_key_path = realpath( $key_path );
		$real_document_root = is_string( $document_root ) && '' !== $document_root ? realpath( $document_root ) : false;
		if ( false === $real_key_path || ( false !== $real_document_root && 0 === strpos( $real_key_path, trailingslashit( $real_document_root ) ) ) ) {
			return uonix_analytics_metrics_error( 'service_account_file_unsafe' );
		}

		$credentials = json_decode( (string) file_get_contents( $real_key_path ), true );
		if ( ! is_array( $credentials ) || 'service_account' !== ( $credentials['type'] ?? '' ) || empty( $credentials['client_email'] ) || empty( $credentials['private_key'] ) || empty( $credentials['token_uri'] ) ) {
			return uonix_analytics_metrics_error( 'service_account_json_invalid' );
		}

		$ga4_property_id = trim( (string) $ga4_property_id );
		if ( '445033830' !== $ga4_property_id ) {
			return uonix_analytics_metrics_error( 'ga4_property_invalid' );
		}
		if ( 'sc-domain:uonix.com.br' !== $search_console_site_url ) {
			return uonix_analytics_metrics_error( 'search_console_site_invalid' );
		}
		$token_uri = isset( $credentials['token_uri'] ) ? (string) $credentials['token_uri'] : '';
		$token_parts = wp_parse_url( $token_uri );
		if ( ! is_array( $token_parts ) || 'https' !== ( $token_parts['scheme'] ?? '' ) || 'oauth2.googleapis.com' !== strtolower( $token_parts['host'] ?? '' ) ) {
			return uonix_analytics_metrics_error( 'service_account_token_uri_invalid' );
		}

		return array(
			'key_path'                => $real_key_path,
			'credentials'             => $credentials,
			'ga4_property_id'         => $ga4_property_id,
			'search_console_site_url' => $search_console_site_url,
		);
	}
}

if ( ! function_exists( 'uonix_analytics_metrics_compare' ) ) {
	function uonix_analytics_metrics_compare( $current, $previous ) {
		$current  = (float) $current;
		$previous = (float) $previous;
		$delta    = $current - $previous;

		if ( $previous > 0 ) {
			return array(
				'current'        => $current,
				'previous'       => $previous,
				'delta_absolute' => $delta,
				'delta_percent'  => round( ( $delta / $previous ) * 100, 1 ),
				'state'          => 'comparable',
			);
		}

		return array(
			'current'        => $current,
			'previous'       => $previous,
			'delta_absolute' => $delta,
			'delta_percent'  => null,
			'state'          => $current > 0 ? 'new' : 'empty',
		);
	}
}

if ( ! function_exists( 'uonix_analytics_metrics_normalize_path' ) ) {
	function uonix_analytics_metrics_normalize_path( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$parts = wp_parse_url( $value );
		if ( false === $parts || ! is_array( $parts ) ) {
			return '';
		}
		if ( isset( $parts['host'] ) && '' !== $parts['host'] && 'uonix.com.br' !== strtolower( $parts['host'] ) && 'www.uonix.com.br' !== strtolower( $parts['host'] ) ) {
			return '';
		}

		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		if ( '' === $path || '/' !== $path[0] ) {
			return '';
		}
		return $path;
	}
}

if ( ! function_exists( 'uonix_analytics_metrics_sanitize_query' ) ) {
	function uonix_analytics_metrics_sanitize_query( $query ) {
		$query = trim( wp_strip_all_tags( (string) $query ) );
		if ( '' === $query || strlen( $query ) > 120 ) {
			return '';
		}
		if ( preg_match( '/[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}/', $query ) ) {
			return '';
		}
		if ( preg_match( '/(?:\+?\d[\s().-]*){8,}/', $query ) ) {
			return '';
		}
		if ( preg_match( '#(?:https?://|www\.)#i', $query ) ) {
			return '';
		}
		return $query;
	}
}

if ( ! function_exists( 'uonix_analytics_metrics_number' ) ) {
	function uonix_analytics_metrics_number( $value ) {
		return is_numeric( $value ) ? (float) $value : null;
	}
}

if ( ! function_exists( 'uonix_analytics_metrics_normalize_ga4' ) ) {
	function uonix_analytics_metrics_normalize_ga4( $data ) {
		$current  = isset( $data['summary_current'] ) && is_array( $data['summary_current'] ) ? $data['summary_current'] : array();
		$previous = isset( $data['summary_previous'] ) && is_array( $data['summary_previous'] ) ? $data['summary_previous'] : array();
		$pages    = array();

		foreach ( isset( $data['landing_pages'] ) && is_array( $data['landing_pages'] ) ? $data['landing_pages'] : array() as $row ) {
			$path = uonix_analytics_metrics_normalize_path( isset( $row['path'] ) ? $row['path'] : '' );
			$sessions = uonix_analytics_metrics_number( $row['sessions'] ?? null );
			if ( '' === $path || null === $sessions ) {
				continue;
			}
			$pages[] = array( 'path' => $path, 'sessions' => $sessions );
			if ( 10 === count( $pages ) ) {
				break;
			}
		}
		foreach ( array( 'activeUsers', 'sessions' ) as $metric ) {
			if ( null === uonix_analytics_metrics_number( $current[ $metric ] ?? null ) || null === uonix_analytics_metrics_number( $previous[ $metric ] ?? null ) ) {
				return uonix_analytics_metrics_error( 'ga4_metric_missing' );
			}
		}

		return array(
			'summary' => array(
				'active_users' => uonix_analytics_metrics_compare( isset( $current['activeUsers'] ) ? $current['activeUsers'] : 0, isset( $previous['activeUsers'] ) ? $previous['activeUsers'] : 0 ),
				'sessions'     => uonix_analytics_metrics_compare( isset( $current['sessions'] ) ? $current['sessions'] : 0, isset( $previous['sessions'] ) ? $previous['sessions'] : 0 ),
			),
			'landing_pages' => $pages,
		);
	}
}

if ( ! function_exists( 'uonix_analytics_metrics_normalize_search_console' ) ) {
	function uonix_analytics_metrics_normalize_search_console( $data ) {
		$current  = isset( $data['summary_current'] ) && is_array( $data['summary_current'] ) ? $data['summary_current'] : array();
		$previous = isset( $data['summary_previous'] ) && is_array( $data['summary_previous'] ) ? $data['summary_previous'] : array();
		$queries  = array();
		$pages    = array();

		foreach ( isset( $data['queries'] ) && is_array( $data['queries'] ) ? $data['queries'] : array() as $row ) {
			$query = uonix_analytics_metrics_sanitize_query( isset( $row['query'] ) ? $row['query'] : '' );
			$clicks = uonix_analytics_metrics_number( $row['clicks'] ?? null );
			$impressions = uonix_analytics_metrics_number( $row['impressions'] ?? null );
			$ctr = uonix_analytics_metrics_number( $row['ctr'] ?? null );
			$position = uonix_analytics_metrics_number( $row['position'] ?? null );
			if ( '' === $query || null === $clicks || null === $impressions || null === $ctr || null === $position ) {
				continue;
			}
			$queries[] = array( 'query' => $query, 'clicks' => $clicks, 'impressions' => $impressions, 'ctr' => $ctr, 'position' => $position );
			if ( 10 === count( $queries ) ) break;
		}
		foreach ( isset( $data['pages'] ) && is_array( $data['pages'] ) ? $data['pages'] : array() as $row ) {
			$page = uonix_analytics_metrics_normalize_path( isset( $row['page'] ) ? $row['page'] : '' );
			$clicks = uonix_analytics_metrics_number( $row['clicks'] ?? null );
			$impressions = uonix_analytics_metrics_number( $row['impressions'] ?? null );
			$ctr = uonix_analytics_metrics_number( $row['ctr'] ?? null );
			$position = uonix_analytics_metrics_number( $row['position'] ?? null );
			if ( '' === $page || null === $clicks || null === $impressions || null === $ctr || null === $position ) {
				continue;
			}
			$pages[] = array( 'page' => $page, 'clicks' => $clicks, 'impressions' => $impressions, 'ctr' => $ctr, 'position' => $position );
			if ( 10 === count( $pages ) ) break;
		}

		foreach ( array( 'clicks', 'impressions', 'ctr', 'position' ) as $metric ) {
			if ( null === uonix_analytics_metrics_number( $current[ $metric ] ?? null ) || null === uonix_analytics_metrics_number( $previous[ $metric ] ?? null ) ) {
				return uonix_analytics_metrics_error( 'search_console_metric_missing' );
			}
		}
		$summary = array(
			'clicks' => uonix_analytics_metrics_compare( $current['clicks'], $previous['clicks'] ),
			'impressions' => uonix_analytics_metrics_compare( $current['impressions'], $previous['impressions'] ),
			'ctr' => uonix_analytics_metrics_compare( $current['ctr'], $previous['ctr'] ),
			'position' => uonix_analytics_metrics_compare( $current['position'], $previous['position'] ),
		);

		return array( 'summary' => $summary, 'queries' => $queries, 'pages' => $pages );
	}
}

if ( ! function_exists( 'uonix_analytics_metrics_periods' ) ) {
	function uonix_analytics_metrics_periods( $today = null ) {
		$timezone = new DateTimeZone( 'America/Sao_Paulo' );
		$today = $today ? new DateTimeImmutable( $today, $timezone ) : new DateTimeImmutable( 'today', $timezone );
		$current_end = $today->modify( '-1 day' );
		$current_start = $current_end->modify( '-29 days' );
		$previous_end = $current_start->modify( '-1 day' );
		return array(
			'current' => array( 'start' => $current_start->format( 'Y-m-d' ), 'end' => $current_end->format( 'Y-m-d' ) ),
			'previous' => array( 'start' => $previous_end->modify( '-29 days' )->format( 'Y-m-d' ), 'end' => $previous_end->format( 'Y-m-d' ) ),
		);
	}
}

if ( ! function_exists( 'uonix_analytics_metrics_snapshot_option' ) ) {
	function uonix_analytics_metrics_snapshot_option() { return 'uonix_analytics_metrics_snapshot_v1'; }
}

if ( ! function_exists( 'uonix_analytics_metrics_get_snapshot' ) ) {
	function uonix_analytics_metrics_get_snapshot() {
		$snapshot = function_exists( 'get_option' ) ? get_option( uonix_analytics_metrics_snapshot_option(), false ) : false;
		return is_array( $snapshot ) ? $snapshot : false;
	}
}

if ( ! function_exists( 'uonix_analytics_metrics_base64url' ) ) {
	function uonix_analytics_metrics_base64url( $value ) {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}
}

if ( ! function_exists( 'uonix_analytics_metrics_get_access_token' ) ) {
	function uonix_analytics_metrics_get_access_token( $config, $transport = null ) {
		$credentials = $config['credentials'];
		$now = time();
		$header = uonix_analytics_metrics_base64url( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
		$claims = uonix_analytics_metrics_base64url( wp_json_encode( array(
			'iss' => $credentials['client_email'],
			'scope' => 'https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/webmasters.readonly',
			'aud' => $credentials['token_uri'], 'iat' => $now, 'exp' => $now + 3600,
		) ) );
		$input = $header . '.' . $claims;
		if ( ! function_exists( 'openssl_sign' ) || ! openssl_sign( $input, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256 ) ) return uonix_analytics_metrics_error( 'jwt_signing_failed' );
		$jwt = $input . '.' . uonix_analytics_metrics_base64url( $signature );
		$transport = is_callable( $transport ) ? $transport : 'wp_remote_post';
		$response = call_user_func( $transport, $credentials['token_uri'], array( 'timeout' => 20, 'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ), 'body' => array( 'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt ) ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) return uonix_analytics_metrics_error( 'token_request_failed' );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) && ! empty( $body['access_token'] ) ? (string) $body['access_token'] : uonix_analytics_metrics_error( 'token_response_invalid' );
	}
}

if ( ! function_exists( 'uonix_analytics_metrics_google_json' ) ) {
	function uonix_analytics_metrics_google_json( $url, $access_token, $body = null ) {
		$args = array( 'timeout' => 20, 'headers' => array( 'Authorization' => 'Bearer ' . $access_token, 'Content-Type' => 'application/json' ) );
		$response = null === $body ? wp_remote_get( $url, $args ) : wp_remote_post( $url, $args + array( 'body' => wp_json_encode( $body ) ) );
		if ( is_wp_error( $response ) ) return uonix_analytics_metrics_error( 'google_transport_failed' );
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return $code >= 200 && $code < 300 && is_array( $data ) ? $data : uonix_analytics_metrics_error( 'google_http_' . $code );
	}
}

if ( ! function_exists( 'uonix_analytics_metrics_ga4_report' ) ) {
	function uonix_analytics_metrics_ga4_report( $property_id, $access_token, $period, $dimensions = array(), $limit = 1 ) {
		$body = array(
			'dateRanges' => array( array( 'startDate' => $period['start'], 'endDate' => $period['end'] ) ),
			'dimensions' => $dimensions,
			'metrics' => array( array( 'name' => 'activeUsers' ), array( 'name' => 'sessions' ) ),
			'limit' => (string) $limit,
		);
		return uonix_analytics_metrics_google_json( 'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode( $property_id ) . ':runReport', $access_token, $body );
	}
}

if ( ! function_exists( 'uonix_analytics_metrics_ga4_rows' ) ) {
	function uonix_analytics_metrics_ga4_rows( $report, $dimension_key = null ) {
		$rows = array();
		foreach ( isset( $report['rows'] ) && is_array( $report['rows'] ) ? $report['rows'] : array() as $row ) {
			$dimensions = isset( $row['dimensionValues'] ) ? $row['dimensionValues'] : array();
			$metrics = isset( $row['metricValues'] ) ? $row['metricValues'] : array();
			$entry = array( 'activeUsers' => isset( $metrics[0]['value'] ) ? $metrics[0]['value'] : 0, 'sessions' => isset( $metrics[1]['value'] ) ? $metrics[1]['value'] : 0 );
			if ( null !== $dimension_key ) $entry[ $dimension_key ] = isset( $dimensions[0]['value'] ) ? $dimensions[0]['value'] : '';
			$rows[] = $entry;
		}
		return $rows;
	}
}

if ( ! function_exists( 'uonix_analytics_metrics_search_console_rows' ) ) {
	function uonix_analytics_metrics_search_console_rows( $access_token, $site_url, $period, $dimension = null ) {
		$body = array( 'startDate' => $period['start'], 'endDate' => $period['end'], 'rowLimit' => null === $dimension ? 1 : 10 );
		if ( null !== $dimension ) $body['dimensions'] = array( $dimension );
		$report = uonix_analytics_metrics_google_json( 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( $site_url ) . '/searchAnalytics/query', $access_token, $body );
		if ( is_wp_error( $report ) ) return $report;
		$rows = array();
		foreach ( isset( $report['rows'] ) && is_array( $report['rows'] ) ? $report['rows'] : array() as $row ) {
			$rows[] = array( 'key' => isset( $row['keys'][0] ) ? $row['keys'][0] : '', 'clicks' => isset( $row['clicks'] ) ? $row['clicks'] : 0, 'impressions' => isset( $row['impressions'] ) ? $row['impressions'] : 0, 'ctr' => isset( $row['ctr'] ) ? $row['ctr'] : 0, 'position' => isset( $row['position'] ) ? $row['position'] : 0 );
		}
		return $rows;
	}
}

if ( ! function_exists( 'uonix_analytics_metrics_fetch_google_data' ) ) {
	function uonix_analytics_metrics_fetch_google_data( $config, $periods ) {
		$token = uonix_analytics_metrics_get_access_token( $config );
		if ( is_wp_error( $token ) ) return $token;
		$ga_current = uonix_analytics_metrics_ga4_report( $config['ga4_property_id'], $token, $periods['current'] );
		$ga_previous = uonix_analytics_metrics_ga4_report( $config['ga4_property_id'], $token, $periods['previous'] );
		$ga_pages = uonix_analytics_metrics_ga4_report( $config['ga4_property_id'], $token, $periods['current'], array( array( 'name' => 'landingPagePlusQueryString' ) ), 10 );
		$gsc_current = uonix_analytics_metrics_search_console_rows( $token, $config['search_console_site_url'], $periods['current'] );
		$gsc_previous = uonix_analytics_metrics_search_console_rows( $token, $config['search_console_site_url'], $periods['previous'] );
		$gsc_queries = uonix_analytics_metrics_search_console_rows( $token, $config['search_console_site_url'], $periods['current'], 'query' );
		$gsc_pages = uonix_analytics_metrics_search_console_rows( $token, $config['search_console_site_url'], $periods['current'], 'page' );
		foreach ( array( $ga_current, $ga_previous, $ga_pages, $gsc_current, $gsc_previous, $gsc_queries, $gsc_pages ) as $result ) if ( is_wp_error( $result ) ) return $result;
		$ga_current_rows = uonix_analytics_metrics_ga4_rows( $ga_current );
		$ga_previous_rows = uonix_analytics_metrics_ga4_rows( $ga_previous );
		return array(
			'ga4' => array( 'summary_current' => isset( $ga_current_rows[0] ) ? $ga_current_rows[0] : array(), 'summary_previous' => isset( $ga_previous_rows[0] ) ? $ga_previous_rows[0] : array(), 'landing_pages' => array_map( function( $row ) { return array( 'path' => $row['path'], 'sessions' => $row['sessions'] ); }, uonix_analytics_metrics_ga4_rows( $ga_pages, 'path' ) ) ),
			'search_console' => array( 'summary_current' => isset( $gsc_current[0] ) ? $gsc_current[0] : array(), 'summary_previous' => isset( $gsc_previous[0] ) ? $gsc_previous[0] : array(), 'queries' => array_map( function( $row ) { $row['query'] = $row['key']; return $row; }, $gsc_queries ), 'pages' => array_map( function( $row ) { $row['page'] = $row['key']; return $row; }, $gsc_pages ) ),
		);
	}
}

if ( ! function_exists( 'uonix_analytics_metrics_sync' ) ) {
	function uonix_analytics_metrics_sync( $fetcher = null, $config = null ) {
		$config = is_array( $config ) ? $config : uonix_analytics_metrics_get_config();
		if ( is_wp_error( $config ) ) {
			return $config;
		}
		$lock_name = 'uonix_analytics_metrics_sync_lock';
		$now = time();
		if ( ! add_option( $lock_name, $now, '', 'no' ) ) {
			$locked_at = (int) get_option( $lock_name, 0 );
			if ( $locked_at > 0 && $locked_at < ( $now - 600 ) ) {
				delete_option( $lock_name );
			}
			if ( ! add_option( $lock_name, $now, '', 'no' ) ) {
				return uonix_analytics_metrics_error( 'sync_locked' );
			}
		}
		try {
			$fetcher = is_callable( $fetcher ) ? $fetcher : 'uonix_analytics_metrics_fetch_google_data';
			$periods = uonix_analytics_metrics_periods();
			$data = call_user_func( $fetcher, $config, $periods );
			if ( ! is_array( $data ) || ! isset( $data['ga4'], $data['search_console'] ) ) throw new RuntimeException( 'google_response_invalid' );
			$ga4 = uonix_analytics_metrics_normalize_ga4( $data['ga4'] );
			$search_console = uonix_analytics_metrics_normalize_search_console( $data['search_console'] );
			if ( is_wp_error( $ga4 ) ) throw new RuntimeException( $ga4->get_error_code() );
			if ( is_wp_error( $search_console ) ) throw new RuntimeException( $search_console->get_error_code() );
			$snapshot = array(
				'version' => 1, 'status' => 'updated', 'updated_at' => gmdate( 'c' ), 'periods' => $periods,
				'ga4' => $ga4, 'search_console' => $search_console,
			);
			if ( function_exists( 'update_option' ) ) update_option( uonix_analytics_metrics_snapshot_option(), $snapshot, false );
			return $snapshot;
		} catch ( Throwable $error ) {
			$previous = uonix_analytics_metrics_get_snapshot();
			if ( $previous ) {
				$previous['status'] = 'stale';
				$previous['error'] = sanitize_key( $error->getMessage() );
				if ( function_exists( 'update_option' ) ) update_option( uonix_analytics_metrics_snapshot_option(), $previous, false );
				return $previous;
			}
			return uonix_analytics_metrics_error( sanitize_key( $error->getMessage() ) );
		} finally {
			delete_option( $lock_name );
		}
	}
}

if ( ! function_exists( 'uonix_analytics_metrics_schedule' ) ) {
	function uonix_analytics_metrics_schedule() {
		if ( ! wp_next_scheduled( 'uonix_analytics_metrics_daily_sync' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'uonix_analytics_metrics_daily_sync' );
		}
	}
}
add_action( 'init', 'uonix_analytics_metrics_schedule', 10, 0 );
add_action( 'uonix_analytics_metrics_daily_sync', 'uonix_analytics_metrics_sync', 10, 0 );

if ( ! function_exists( 'uonix_analytics_metrics_manual_refresh' ) ) {
	function uonix_analytics_metrics_manual_refresh() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'uonix_analytics_metrics_refresh' ) ) {
			wp_die( esc_html__( 'Você não tem permissão para atualizar métricas.', 'uonix' ) );
		}
		uonix_analytics_metrics_sync();
		wp_safe_redirect( admin_url( 'admin.php?page=uonix-analytics&uonix_metrics_refresh=1' ) );
		exit;
	}
}
add_action( 'admin_post_uonix_analytics_metrics_refresh', 'uonix_analytics_metrics_manual_refresh' );
