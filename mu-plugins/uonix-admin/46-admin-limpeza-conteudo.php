<?php
/**
 * Ferramentas ksio.dev - limpeza de conteúdo operacional.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'uox_content_register_ksio_tools_menu' );

function uox_content_register_ksio_tools_menu() {
	add_menu_page(
		'ksio.dev',
		'ksio.dev',
		'manage_options',
		'ksio-dev',
		'uox_content_render_ksio_tools_home',
		'dashicons-admin-tools',
		58
	);

	add_submenu_page(
		'ksio-dev',
		'Ferramentas ksio.dev',
		'Visão Geral',
		'manage_options',
		'ksio-dev',
		'uox_content_render_ksio_tools_home'
	);

	add_submenu_page(
		'ksio-dev',
		'Limpeza de Conteúdo',
		'Limpeza de Conteúdo',
		'manage_options',
		'ksio-dev-limpeza-conteudo',
		'uox_content_render_cleanup_page'
	);
}

function uox_content_render_ksio_tools_home() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Você não tem permissão para acessar esta página.' );
	}

	?>
	<div class="wrap">
		<h1>ksio.dev</h1>
		<p>Ferramentas internas de manutenção do site Uônix.</p>

		<div class="card" style="max-width:720px;">
			<h2>Limpeza de Conteúdo</h2>
			<p>Remove entradas de formulários, currículos recebidos e pedidos WooCommerce selecionados.</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=ksio-dev-limpeza-conteudo' ) ); ?>">
					Abrir ferramenta
				</a>
			</p>
		</div>
	</div>
	<?php
}

function uox_content_table_exists( $table_name ) {
	global $wpdb;

	return $wpdb->get_var(
		$wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
	) === $table_name;
}

function uox_content_column_exists( $table_name, $column_name ) {
	global $wpdb;

	$safe_table = preg_replace( '/[^A-Za-z0-9_]/', '', $table_name );

	if ( $safe_table !== $table_name ) {
		return false;
	}

	return (bool) $wpdb->get_var(
		$wpdb->prepare( "SHOW COLUMNS FROM `{$safe_table}` LIKE %s", $column_name )
	);
}

function uox_content_delete_ids_in_table( $table_name, $column_name, $ids ) {
	global $wpdb;

	if ( empty( $ids ) ) {
		return 0;
	}

	$safe_table  = preg_replace( '/[^A-Za-z0-9_]/', '', $table_name );
	$safe_column = preg_replace( '/[^A-Za-z0-9_]/', '', $column_name );

	if ( $safe_table !== $table_name || $safe_column !== $column_name ) {
		return 0;
	}

	if ( ! uox_content_table_exists( $table_name ) || ! uox_content_column_exists( $table_name, $column_name ) ) {
		return 0;
	}

	$deleted = 0;
	$chunks  = array_chunk( array_map( 'absint', $ids ), 500 );

	foreach ( $chunks as $chunk ) {
		$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$safe_table}` WHERE `{$safe_column}` IN ({$placeholders})",
				$chunk
			)
		);

		if ( false !== $result ) {
			$deleted += (int) $result;
		}
	}

	return $deleted;
}

function uox_content_get_fluent_forms() {
	global $wpdb;

	$forms_table       = $wpdb->prefix . 'fluentform_forms';
	$submissions_table = $wpdb->prefix . 'fluentform_submissions';

	if ( ! uox_content_table_exists( $forms_table ) ) {
		return array();
	}

	$forms = $wpdb->get_results(
		"SELECT id, title FROM `{$forms_table}` ORDER BY id ASC",
		ARRAY_A
	);

	if ( empty( $forms ) ) {
		return array();
	}

	foreach ( $forms as &$form ) {
		$form['id']            = absint( $form['id'] );
		$form['title']         = sanitize_text_field( $form['title'] ?? '' );
		$form['entries_count'] = 0;

		if ( uox_content_table_exists( $submissions_table ) ) {
			$form['entries_count'] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$submissions_table}` WHERE form_id = %d",
					$form['id']
				)
			);
		}
	}

	unset( $form );

	return $forms;
}

function uox_content_clean_fluent_forms( array $form_ids ) {
	global $wpdb;

	$form_ids = array_values( array_filter( array_map( 'absint', $form_ids ) ) );

	if ( empty( $form_ids ) ) {
		return array(
			'success' => false,
			'message' => 'Nenhum formulário foi selecionado.',
		);
	}

	$submissions_table = $wpdb->prefix . 'fluentform_submissions';

	if ( ! uox_content_table_exists( $submissions_table ) ) {
		return array(
			'success' => false,
			'message' => 'Tabela fluentform_submissions não encontrada.',
		);
	}

	$placeholders = implode( ',', array_fill( 0, count( $form_ids ), '%d' ) );
	$submission_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT id FROM `{$submissions_table}` WHERE form_id IN ({$placeholders})",
			$form_ids
		)
	);

	$submission_ids = array_map( 'absint', $submission_ids );

	if ( empty( $submission_ids ) ) {
		return array(
			'success' => true,
			'message' => 'Nenhuma entrada encontrada nos formulários selecionados.',
			'deleted' => array(
				'submissions'  => 0,
				'meta'         => 0,
				'details'      => 0,
				'logs'         => 0,
				'transactions' => 0,
			),
		);
	}

	$meta_table         = $wpdb->prefix . 'fluentform_submission_meta';
	$details_table      = $wpdb->prefix . 'fluentform_entry_details';
	$logs_table         = $wpdb->prefix . 'fluentform_logs';
	$transactions_table = $wpdb->prefix . 'fluentform_transactions';

	$deleted_meta = uox_content_delete_ids_in_table(
		$meta_table,
		'response_id',
		$submission_ids
	);

	$deleted_details = uox_content_delete_ids_in_table(
		$details_table,
		'submission_id',
		$submission_ids
	);

	$deleted_logs = uox_content_delete_ids_in_table(
		$logs_table,
		'source_id',
		$submission_ids
	);

	$deleted_transactions = uox_content_delete_ids_in_table(
		$transactions_table,
		'submission_id',
		$submission_ids
	);

	$deleted_submissions = uox_content_delete_ids_in_table(
		$submissions_table,
		'id',
		$submission_ids
	);

	return array(
		'success' => true,
		'message' => 'Entradas do Fluent Forms removidas.',
		'deleted' => array(
			'submissions'  => $deleted_submissions,
			'meta'         => $deleted_meta,
			'details'      => $deleted_details,
			'logs'         => $deleted_logs,
			'transactions' => $deleted_transactions,
		),
	);
}

function uox_content_get_curriculos_dir_path() {
	if ( function_exists( 'uonix_get_curriculos_dir_path' ) ) {
		return uonix_get_curriculos_dir_path();
	}

	$upload_dir = wp_upload_dir();

	return $upload_dir['basedir'] . '/curriculos-recebidos';
}

function uox_content_count_curriculos() {
	$dir_path = uox_content_get_curriculos_dir_path();
	$count    = 0;

	if ( is_dir( $dir_path ) ) {
		$iterator = new FilesystemIterator( $dir_path, FilesystemIterator::SKIP_DOTS );

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			if ( preg_match( '/^cv_uonix_.+\.(pdf|doc|docx)$/i', $file->getFilename() ) ) {
				$count++;
			}
		}
	}

	return $count;
}

function uox_content_clean_curriculos() {
	global $wpdb;

	$dir_path      = uox_content_get_curriculos_dir_path();
	$deleted_files = 0;
	$file_errors   = 0;

	if ( is_dir( $dir_path ) ) {
		$iterator = new FilesystemIterator( $dir_path, FilesystemIterator::SKIP_DOTS );

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$filename = $file->getFilename();

			if ( ! preg_match( '/^cv_uonix_.+\.(pdf|doc|docx)$/i', $filename ) ) {
				continue;
			}

			if ( @unlink( $file->getPathname() ) ) {
				$deleted_files++;
			} else {
				$file_errors++;
			}
		}
	}

	$deleted_tokens = (int) $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE %s
			    OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_uonix_cv_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_uonix_cv_' ) . '%'
		)
	);

	return array(
		'success' => 0 === $file_errors,
		'message' => 0 === $file_errors
			? 'Currículos recebidos removidos.'
			: 'Alguns currículos não puderam ser removidos.',
		'deleted' => array(
			'files'  => $deleted_files,
			'tokens' => $deleted_tokens,
			'errors' => $file_errors,
		),
	);
}

function uox_content_count_woocommerce_orders() {
	if ( ! function_exists( 'wc_orders_count' ) || ! function_exists( 'wc_get_order_statuses' ) ) {
		return null;
	}

	$count = 0;

	foreach ( array_keys( wc_get_order_statuses() ) as $status ) {
		$count += (int) wc_orders_count( $status );
	}

	return $count;
}

function uox_content_clean_woocommerce_orders() {
	if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'wc_get_order' ) ) {
		return array(
			'success' => false,
			'message' => 'WooCommerce não está disponível.',
		);
	}

	$deleted = 0;
	$errors  = 0;
	$exclude = array();
	$guard   = 0;

	do {
		$args = array(
			'type'    => 'shop_order',
			'limit'   => 100,
			'return'  => 'ids',
			'status'  => function_exists( 'wc_get_order_statuses' ) ? array_keys( wc_get_order_statuses() ) : 'any',
			'orderby' => 'ID',
			'order'   => 'ASC',
		);

		if ( ! empty( $exclude ) ) {
			$args['exclude'] = $exclude;
		}

		$order_ids = wc_get_orders( $args );
		$guard++;

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( $order && $order->delete( true ) ) {
				$deleted++;
				continue;
			}

			$errors++;
			$exclude[] = absint( $order_id );
		}
	} while ( ! empty( $order_ids ) && $guard < 200 );

	if ( function_exists( 'wc_delete_shop_order_transients' ) ) {
		wc_delete_shop_order_transients();
	}

	return array(
		'success' => 0 === $errors,
		'message' => 0 === $errors
			? 'Pedidos WooCommerce removidos.'
			: 'Alguns pedidos WooCommerce não puderam ser removidos.',
		'deleted' => array(
			'orders' => $deleted,
			'errors' => $errors,
		),
	);
}

function uox_content_render_result_notice( array $result ) {
	$class = ! empty( $result['success'] ) ? 'notice notice-success' : 'notice notice-error';

	?>
	<div class="<?php echo esc_attr( $class ); ?>" style="padding:12px;">
		<p><strong><?php echo esc_html( $result['message'] ?? 'Operação concluída.' ); ?></strong></p>

		<?php if ( ! empty( $result['deleted'] ) && is_array( $result['deleted'] ) ) : ?>
			<ul style="list-style:disc;margin-left:20px;">
				<?php foreach ( $result['deleted'] as $label => $value ) : ?>
					<li><?php echo esc_html( ucfirst( $label ) ); ?>: <?php echo esc_html( (string) $value ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
	<?php
}

function uox_content_render_cleanup_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Você não tem permissão para acessar esta página.' );
	}

	$results = array();

	if (
		isset( $_POST['uox_content_cleanup_action'] ) &&
		'limpar' === $_POST['uox_content_cleanup_action']
	) {
		check_admin_referer( 'uox_content_cleanup_nonce' );

		$confirmation = isset( $_POST['uox_content_confirmation'] )
			? sanitize_text_field( wp_unslash( $_POST['uox_content_confirmation'] ) )
			: '';

		if ( 'LIMPAR CONTEUDO' !== $confirmation ) {
			$results[] = array(
				'success' => false,
				'message' => 'Confirmação inválida. Digite LIMPAR CONTEUDO exatamente como indicado.',
			);
		} else {
			$form_ids = isset( $_POST['uox_ff_form_ids'] ) && is_array( $_POST['uox_ff_form_ids'] )
				? array_map( 'absint', wp_unslash( $_POST['uox_ff_form_ids'] ) )
				: array();

			if ( ! empty( $form_ids ) ) {
				$results[] = uox_content_clean_fluent_forms( $form_ids );
			}

			if ( ! empty( $_POST['uox_clean_curriculos'] ) ) {
				$results[] = uox_content_clean_curriculos();
			}

			if ( ! empty( $_POST['uox_clean_orders'] ) ) {
				$results[] = uox_content_clean_woocommerce_orders();
			}

			if ( empty( $results ) ) {
				$results[] = array(
					'success' => false,
					'message' => 'Nenhuma opção de limpeza foi selecionada.',
				);
			}
		}
	}

	$forms            = uox_content_get_fluent_forms();
	$curriculos_count = uox_content_count_curriculos();
	$orders_count     = uox_content_count_woocommerce_orders();

	?>
	<div class="wrap">
		<h1>Limpeza de Conteúdo</h1>
		<p>Ferramenta interna para remover dados de teste e conteúdo operacional selecionado.</p>

		<div class="notice notice-warning" style="padding:12px;">
			<p><strong>Atenção:</strong> esta ação é irreversível. Faça backup do banco antes de executar.</p>
			<p>Marque somente os grupos que deseja limpar e confirme manualmente antes de executar.</p>
		</div>

		<?php foreach ( $results as $result ) : ?>
			<?php uox_content_render_result_notice( $result ); ?>
		<?php endforeach; ?>

		<form method="post" style="margin-top:24px;max-width:920px;">
			<?php wp_nonce_field( 'uox_content_cleanup_nonce' ); ?>
			<input type="hidden" name="uox_content_cleanup_action" value="limpar">

			<div class="card" style="max-width:none;margin-bottom:16px;">
				<h2>Fluent Forms</h2>
				<p>Selecione os formulários cujas entradas serão apagadas.</p>

				<?php if ( empty( $forms ) ) : ?>
					<p>Nenhum formulário do Fluent Forms encontrado.</p>
				<?php else : ?>
					<p>
						<label>
							<input type="checkbox" id="uox_ff_select_all">
							Selecionar todos os formulários
						</label>
					</p>

					<table class="widefat striped">
						<thead>
							<tr>
								<th style="width:48px;">Limpar</th>
								<th>ID</th>
								<th>Formulário</th>
								<th>Entradas</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $forms as $form ) : ?>
								<tr>
									<td>
										<input type="checkbox" class="uox-ff-form-checkbox" name="uox_ff_form_ids[]" value="<?php echo esc_attr( $form['id'] ); ?>">
									</td>
									<td><?php echo esc_html( (string) $form['id'] ); ?></td>
									<td><?php echo esc_html( $form['title'] ?: 'Formulário sem título' ); ?></td>
									<td><?php echo esc_html( (string) $form['entries_count'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="card" style="max-width:none;margin-bottom:16px;">
				<h2>Currículos recebidos</h2>
				<p>Remove arquivos da pasta <code>curriculos-recebidos</code> e seus tokens temporários de download.</p>
				<label>
					<input type="checkbox" name="uox_clean_curriculos" value="1">
					Limpar currículos recebidos
					<?php if ( null !== $curriculos_count ) : ?>
						(<?php echo esc_html( (string) $curriculos_count ); ?> arquivo(s))
					<?php endif; ?>
				</label>
			</div>

			<div class="card" style="max-width:none;margin-bottom:16px;">
				<h2>Pedidos WooCommerce</h2>
				<p>Remove definitivamente os pedidos/orçamentos do WooCommerce usando a API do WooCommerce.</p>
				<label>
					<input type="checkbox" name="uox_clean_orders" value="1">
					Limpar pedidos WooCommerce
					<?php if ( null !== $orders_count ) : ?>
						(<?php echo esc_html( (string) $orders_count ); ?> pedido(s))
					<?php endif; ?>
				</label>
			</div>

			<div class="card" style="max-width:none;">
				<h2>Confirmação</h2>
				<p>Para executar as limpezas selecionadas, digite <strong>LIMPAR CONTEUDO</strong>.</p>
				<p>
					<input
						type="text"
						name="uox_content_confirmation"
						value=""
						placeholder="LIMPAR CONTEUDO"
						style="width:100%;max-width:320px;"
						required
					>
				</p>
				<p>
					<button
						type="submit"
						class="button button-primary"
						onclick="return confirm('Confirma a exclusão definitiva dos conteúdos selecionados?');"
					>
						Executar limpeza
					</button>
				</p>
			</div>
		</form>
	</div>

	<script>
	document.addEventListener('DOMContentLoaded', function() {
		const selectAll = document.getElementById('uox_ff_select_all');
		const checkboxes = Array.from(document.querySelectorAll('.uox-ff-form-checkbox'));

		if (!selectAll || !checkboxes.length) return;

		selectAll.addEventListener('change', function() {
			checkboxes.forEach(function(checkbox) {
				checkbox.checked = selectAll.checked;
			});
		});
	});
	</script>
	<?php
}
