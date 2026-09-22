<?php
/**
 * Smoke da Central de Inteligência contra a API real do Search Console.
 *
 * NÃO É TESTE DE CI, por desenho. Os testes de `scripts/tests/` usam stubs e
 * respostas fixas: eles provam a normalização do dado, não que a API devolve o
 * que se supôs. Este script fecha essa lacuna, e por depender de rede e de
 * credencial real ele roda à mão.
 *
 * Contrato: docs/uonix-insights-inteligencia.md — teste com stub é porta de
 * MERGE; este smoke é porta de ATIVAÇÃO. Nenhum módulo é ativado (nem cron, nem
 * envio automático) antes de ele passar.
 *
 * Uso, no ambiente que tem a service account configurada:
 *
 *   wp eval-file scripts/maintenance/smoke-intelligence-seo.php
 *   wp eval-file scripts/maintenance/smoke-intelligence-seo.php --sync
 *
 * Com `--sync` força uma sincronização nova antes de avaliar; sem ele, avalia o
 * snapshot que já existe. Não envia e-mail em nenhum caso: disparar é decisão
 * separada, feita pelo botão "Enviar Teste Agora" ou por `wp cron event run`.
 *
 * Saída: relatório legível e código de saída 0 quando o caminho de dados está
 * íntegro, 1 quando não está. Não imprime PII: as consultas vêm do snapshot, que
 * já passou pela sanitização, e ainda assim só as três primeiras são exibidas.
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Este script precisa do WordPress carregado. Use: wp eval-file " . basename( __FILE__ ) . "\n" );
	exit( 1 );
}

$forcar_sync = in_array( '--sync', (array) ( $argv ?? array() ), true );
$problemas   = array();

echo "=== Smoke da Central de Inteligência ===\n";
echo 'Ambiente: ' . ( defined( 'UONIX_ENV' ) ? UONIX_ENV : 'indefinido' ) . "\n\n";

// 1. Dependências carregadas.
foreach ( array( 'uonix_analytics_metrics_get_snapshot', 'uonix_intelligence_seo_opportunities', 'uonix_intelligence_get_recipients', 'uonix_intelligence_report_html' ) as $funcao ) {
	if ( ! function_exists( $funcao ) ) {
		$problemas[] = "Função ausente: {$funcao}. O módulo não está carregado neste ambiente.";
	}
}
if ( array() !== $problemas ) {
	foreach ( $problemas as $problema ) {
		echo "FALHA: {$problema}\n";
	}
	exit( 1 );
}

// 2. Sincronização, quando pedida.
if ( $forcar_sync ) {
	echo "Forçando sincronização de 30 dias contra a API real...\n";
	$sync = uonix_analytics_metrics_sync( null, null, 30 );
	if ( is_wp_error( $sync ) ) {
		echo 'FALHA: sincronização retornou erro: ' . $sync->get_error_code() . "\n";
		exit( 1 );
	}
	echo 'Sincronização concluída com status: ' . ( $sync['status'] ?? 'desconhecido' ) . "\n\n";
}

// 3. Snapshot.
$snapshot = uonix_analytics_metrics_get_snapshot( 30 );
if ( ! is_array( $snapshot ) ) {
	echo "FALHA: não há snapshot de 30 dias. Rode novamente com --sync.\n";
	exit( 1 );
}

$versao  = isset( $snapshot['version'] ) ? (int) $snapshot['version'] : 0;
$fresco  = uonix_analytics_metrics_snapshot_is_fresh( $snapshot );
$universo = isset( $snapshot['search_console']['queries_extended'] ) && is_array( $snapshot['search_console']['queries_extended'] )
	? count( $snapshot['search_console']['queries_extended'] )
	: -1;

echo "Snapshot: versão {$versao}, status " . ( $snapshot['status'] ?? '?' ) . ', ' . ( $fresco ? 'fresco' : 'vencido' ) . "\n";
echo 'Atualizado em: ' . ( $snapshot['updated_at'] ?? '?' ) . "\n";
echo 'Consultas no universo de mineração: ' . ( -1 === $universo ? 'campo ausente' : $universo ) . "\n";
echo 'Tamanho serializado do snapshot: ' . number_format( strlen( serialize( $snapshot ) ) / 1024, 1 ) . " KB\n\n";

if ( $versao < 3 ) {
	$problemas[] = "Snapshot ainda é v{$versao}. Rode com --sync para gerar o v3.";
}
if ( -1 === $universo ) {
	$problemas[] = 'O snapshot não tem `queries_extended`. A mineração não tem universo.';
} elseif ( $universo <= 10 ) {
	$problemas[] = "Universo de apenas {$universo} consultas. Esperado ordem de centenas: verifique se o rowLimit chegou à API.";
}

// 4. Regra aplicada ao dado real.
$rules    = uonix_intelligence_seo_rules();
$analysis = uonix_intelligence_seo_opportunities( $snapshot, 5 );

echo 'Regra: posição ' . $rules['min_position'] . ' a ' . $rules['max_position']
	. ', mais de ' . $rules['min_impressions'] . ' impressões, CTR abaixo de ' . ( $rules['max_ctr'] * 100 ) . "%\n";
echo 'Disponível: ' . ( $analysis['available'] ? 'sim' : 'não (' . $analysis['reason'] . ')' ) . "\n";
echo 'Oportunidades encontradas: ' . count( $analysis['rows'] ) . "\n\n";

if ( ! $analysis['available'] ) {
	$problemas[] = 'A análise está indisponível: ' . $analysis['reason'];
} else {
	foreach ( array_slice( $analysis['rows'], 0, 3 ) as $indice => $row ) {
		echo '  ' . ( $indice + 1 ) . '. "' . $row['query'] . '"'
			. ' — posição ' . number_format( (float) $row['position'], 1 )
			. ', ' . (int) $row['impressions'] . ' impressões'
			. ', CTR ' . number_format( (float) $row['ctr'] * 100, 2 ) . '%'
			. ( array() !== $row['suggestion'] ? ' → ' . implode( ' · ', $row['suggestion'] ) : '' )
			. "\n";
		// Coerência: se a regra funciona, toda linha devolvida respeita os limiares.
		if ( $row['position'] < $rules['min_position'] || $row['position'] > $rules['max_position'] ) {
			$problemas[] = 'Linha fora da faixa de posição devolvida pela regra: ' . $row['query'];
		}
		if ( $row['impressions'] <= $rules['min_impressions'] ) {
			$problemas[] = 'Linha abaixo do volume mínimo devolvida pela regra: ' . $row['query'];
		}
		if ( $row['ctr'] >= $rules['max_ctr'] ) {
			$problemas[] = 'Linha com CTR acima do limite devolvida pela regra: ' . $row['query'];
		}
	}
	echo "\n";
}

// 5. Montagem do e-mail, sem enviar.
$context = uonix_intelligence_report_context();
$html    = uonix_intelligence_report_html( $context );
echo 'Assunto gerado: ' . uonix_intelligence_report_subject( $context ) . "\n";
echo 'Tamanho do corpo HTML: ' . number_format( strlen( $html ) / 1024, 1 ) . " KB\n";
if ( false === strpos( $html, 'Fonte: Search Console' ) ) {
	$problemas[] = 'O corpo do e-mail não declara a procedência.';
}

// 6. Destinatários e agendamento.
$destinatarios = uonix_intelligence_get_recipients();
echo 'Destinatários cadastrados: ' . count( $destinatarios ) . "\n";
$proximo = wp_next_scheduled( uonix_intelligence_report_hook() );
echo 'Envio automático: ' . ( $proximo ? 'agendado para ' . gmdate( 'c', $proximo ) : 'não agendado' ) . "\n";
if ( array() === $destinatarios ) {
	echo "AVISO: sem destinatário cadastrado o envio não sairia. Não é falha do caminho de dados.\n";
}

// 7. Veredicto.
echo "\n=== Veredicto ===\n";
if ( array() !== $problemas ) {
	foreach ( $problemas as $problema ) {
		echo "FALHA: {$problema}\n";
	}
	echo "\nSmoke REPROVADO. Não ative o envio automático.\n";
	exit( 1 );
}

echo "Smoke APROVADO: caminho de dados íntegro, regra coerente com o dado real e e-mail montável.\n";
echo "Nenhum e-mail foi enviado por este script.\n";
exit( 0 );
