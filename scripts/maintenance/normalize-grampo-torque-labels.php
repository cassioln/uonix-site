<?php
/**
 * Normaliza os dois campos "Torque" das fichas técnicas do Grampo.
 *
 * Uso local:
 *   wp eval-file /caminho/normalize-grampo-torque-labels.php -- --product-id=10382 --dry-run
 *   wp eval-file /caminho/normalize-grampo-torque-labels.php -- --product-id=10382 --execute
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Execute este script via WP-CLI eval-file.\n" );
	exit( 1 );
}

/**
 * Renomeia duas ocorrências legadas de Torque sem inferir unidade pelo valor.
 * A ordem foi confirmada na ficha original: N·m seguido de kgf·m.
 *
 * @param array<string, mixed> $sheet Ficha normalizada.
 * @return array{sheet: array<string, mixed>, changed: bool, error: string|null}
 */
function uonix_normalize_grampo_torque_labels( array $sheet ) {
	$positions = array();

	foreach ( $sheet['sections'] as $section_index => $section ) {
		foreach ( $section['items'] as $item_index => $item ) {
			if ( isset( $item['label'] ) && 'Torque' === $item['label'] ) {
				$positions[] = array( $section_index, $item_index );
			}
		}
	}

	if ( 0 === count( $positions ) ) {
		return array( 'sheet' => $sheet, 'changed' => false, 'error' => null );
	}

	if ( 2 !== count( $positions ) ) {
		return array(
			'sheet'   => $sheet,
			'changed' => false,
			'error'   => 'A ficha contém ' . count( $positions ) . ' rótulos legados "Torque"; esperado exatamente 2.',
		);
	}

	$sheet['sections'][ $positions[0][0] ]['items'][ $positions[0][1] ]['label'] = 'Torque (N·m)';
	$sheet['sections'][ $positions[1][0] ]['items'][ $positions[1][1] ]['label'] = 'Torque (kgf·m)';

	return array( 'sheet' => $sheet, 'changed' => true, 'error' => null );
}

$options = array();
foreach ( isset( $argv ) && is_array( $argv ) ? $argv : array() as $argument ) {
	if ( 0 === strpos( $argument, '--product-id=' ) ) {
		$options['product_id'] = absint( substr( $argument, strlen( '--product-id=' ) ) );
	}
	if ( '--dry-run' === $argument ) {
		$options['dry_run'] = true;
	}
	if ( '--execute' === $argument ) {
		$options['execute'] = true;
	}
}

$product_id = isset( $options['product_id'] ) ? $options['product_id'] : 0;
$dry_run    = ! empty( $options['dry_run'] );
$execute    = ! empty( $options['execute'] );

// wp eval-file não repassa argumentos desconhecidos ao $argv. Aceitar variáveis
// de ambiente mantém a invocação segura no container local.
if ( ! $product_id && false !== getenv( 'UONIX_PRODUCT_ID' ) ) {
	$product_id = absint( getenv( 'UONIX_PRODUCT_ID' ) );
}
if ( ! $dry_run && '1' === getenv( 'UONIX_DRY_RUN' ) ) {
	$dry_run = true;
}
if ( ! $execute && '1' === getenv( 'UONIX_EXECUTE' ) ) {
	$execute = true;
}

if ( ! $product_id || ( $dry_run === $execute ) ) {
	fwrite( STDERR, "Uso: --product-id=ID + --dry-run/--execute, ou UONIX_PRODUCT_ID + UONIX_DRY_RUN/UONIX_EXECUTE.\n" );
	exit( 1 );
}

$product = wc_get_product( $product_id );
if ( ! $product || ! $product->is_type( 'variable' ) ) {
	fwrite( STDERR, "Produto #$product_id não é variável ou não existe.\n" );
	exit( 1 );
}

$scanned = 0;
$changed = 0;
$skipped = 0;
$writes  = 0;
$verified = 0;

foreach ( $product->get_children() as $variation_id ) {
	++$scanned;
	$variation = wc_get_product( $variation_id );
	$stored    = $variation ? $variation->get_meta( Uonix_VTS_Schema::META_KEY, true ) : null;

	if ( ! is_array( $stored ) ) {
		++$skipped;
		continue;
	}

	$normalized = Uonix_VTS_Schema::normalize_sheet( $stored );
	if ( ! $normalized['ok'] ) {
		fwrite( STDERR, "Variação #$variation_id possui ficha inválida; nenhuma escrita foi feita.\n" );
		exit( 1 );
	}

	$result = uonix_normalize_grampo_torque_labels( $normalized['sheet'] );
	if ( null !== $result['error'] ) {
		fwrite( STDERR, "Variação #$variation_id: {$result['error']}\n" );
		exit( 1 );
	}
	if ( ! $result['changed'] ) {
		++$skipped;
		continue;
	}

	++$changed;
	if ( $dry_run ) {
		echo "DRY-RUN variation=$variation_id change=Torque->Torque (N·m),Torque->Torque (kgf·m)\n";
		continue;
	}

	$variation->update_meta_data( Uonix_VTS_Schema::META_KEY, $result['sheet'] );
	$variation->save();
	++$writes;

	$reloaded = wc_get_product( $variation_id );
	$after    = $reloaded ? $reloaded->get_meta( Uonix_VTS_Schema::META_KEY, true ) : null;
	$after_normalized = is_array( $after ) ? Uonix_VTS_Schema::normalize_sheet( $after ) : array( 'ok' => false );
	$labels = array();
	if ( ! empty( $after_normalized['ok'] ) ) {
		foreach ( $after_normalized['sheet']['sections'] as $section ) {
			foreach ( $section['items'] as $item ) {
				$labels[] = $item['label'];
			}
		}
	}
	if ( ! in_array( 'Torque (N·m)', $labels, true ) || ! in_array( 'Torque (kgf·m)', $labels, true ) ) {
		fwrite( STDERR, "Variação #$variation_id não passou no readback após a escrita.\n" );
		exit( 1 );
	}
	++$verified;
	echo "EXECUTE variation=$variation_id verified=1\n";
}

wc_delete_product_transients( $product_id );
echo "SUMMARY scanned=$scanned changed=$changed skipped=$skipped writes=$writes verified=$verified\n";
