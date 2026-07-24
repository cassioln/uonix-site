<?php
/**
 * Ferramenta ksio.dev para clonar ambientes Uonix.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'uox_clone_register_menu', 20 );

function uox_clone_register_menu() {
	add_submenu_page(
		'ksio-dev',
		'Clone de Ambientes',
		'Clone de Ambientes',
		'manage_options',
		'ksio-dev-clone-ambientes',
		'uox_clone_render_page'
	);
}

function uox_clone_env_labels() {
	return array(
		'prod'  => 'Produção',
		'qa'    => 'QA',
		'dev'   => 'DEV',
		'local' => 'Local',
	);
}

function uox_clone_env_details() {
	return array(
		'prod'  => array( 'url' => 'https://site.uonix.com.br', 'host' => 'Locaweb' ),
		'qa'    => array( 'url' => 'https://uonix.ksio.dev', 'host' => 'HostGator / public_html' ),
		'dev'   => array( 'url' => 'https://test.uonix.ksio.dev', 'host' => 'HostGator / dev_uonix' ),
		'local' => array( 'url' => 'http://localhost:8080', 'host' => 'Podman no Mac' ),
	);
}

function uox_clone_is_valid_env( $env ) {
	return in_array( $env, array_keys( uox_clone_env_labels() ), true );
}

function uox_clone_bool_string( $value ) {
	return $value ? 'true' : 'false';
}

function uox_clone_get_github_repo() {
	return defined( 'UONIX_GITHUB_REPO' ) ? UONIX_GITHUB_REPO : 'cassioln/uonix-site';
}

function uox_clone_get_workflow_ref() {
	return 'master';
}

function uox_clone_execution_mode( $source, $target ) {
	return ( 'local' === $source || 'local' === $target ) ? 'mac' : 'github-runner';
}

function uox_clone_required_confirmation( $source, $target ) {
	return sprintf( 'CLONAR %s PARA %s', strtoupper( $source ), strtoupper( $target ) );
}

function uox_clone_pair_requires_ssh_window( $source, $target ) {
	return 'prod' === $source || 'prod' === $target;
}

function uox_clone_get_local_repo_path() {
	return defined( 'UONIX_LOCAL_REPO_PATH' ) ? UONIX_LOCAL_REPO_PATH : '/Users/cassio/GitHubPessoal/uonix-site';
}

function uox_clone_has_github_token() {
	return defined( 'UONIX_GITHUB_TOKEN' ) && '' !== trim( (string) UONIX_GITHUB_TOKEN );
}

function uox_clone_dispatch_workflow( $source, $target, $mode, $replace_users, $confirmation ) {
	if ( ! uox_clone_has_github_token() ) {
		return new WP_Error(
			'uox_clone_missing_token',
			'Defina UONIX_GITHUB_TOKEN no wp-config.php para disparar o GitHub Actions pelo painel.'
		);
	}

	$repo = uox_clone_get_github_repo();
	$url  = 'https://api.github.com/repos/' . rawurlencode( $repo ) . '/actions/workflows/clone-environment.yml/dispatches';
	$url  = str_replace( '%2F', '/', $url );

	$response = wp_remote_post(
		$url,
		array(
			'timeout' => 20,
			'headers' => array(
				'Accept'               => 'application/vnd.github+json',
				'Authorization'        => 'Bearer ' . UONIX_GITHUB_TOKEN,
				'Content-Type'         => 'application/json',
				'User-Agent'           => 'uonix-wordpress-admin',
				'X-GitHub-Api-Version' => '2022-11-28',
			),
			'body'    => wp_json_encode(
				array(
					'ref'    => uox_clone_get_workflow_ref(),
					'inputs' => array(
						'source'        => $source,
						'target'        => $target,
						'mode'          => $mode,
						'replace_users' => uox_clone_bool_string( $replace_users ),
						'confirmation'  => $confirmation,
					),
				)
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	if ( 204 !== $status ) {
		return new WP_Error(
			'uox_clone_dispatch_failed',
			sprintf( 'GitHub Actions retornou HTTP %d. Consulte os logs administrativos para mais detalhes.', $status )
		);
	}

	return true;
}

function uox_clone_build_local_command( $source, $target, $mode, $replace_users, $confirmation ) {
	$repo_path = uox_clone_get_local_repo_path();
	$args      = array(
		'cd ' . escapeshellarg( $repo_path ),
		'&&',
		'scripts/clone-environment.sh',
		'--source=' . escapeshellarg( $source ),
		'--target=' . escapeshellarg( $target ),
		'dry-run' === $mode ? '--dry-run' : '--execute',
	);

	if ( $replace_users ) {
		$args[] = '--replace-users';
	}
	if ( 'execute' === $mode && 'prod' === $target ) {
		$args[] = '--confirmation=' . escapeshellarg( $confirmation );
	}

	return implode( ' ', $args );
}

function uox_clone_get_result_from_post() {
	if ( empty( $_POST['uox_clone_action'] ) || 'clone' !== $_POST['uox_clone_action'] ) {
		return null;
	}

	check_admin_referer( 'uox_clone_nonce' );

	$source       = isset( $_POST['uox_clone_source'] ) ? sanitize_key( wp_unslash( $_POST['uox_clone_source'] ) ) : '';
	$target       = isset( $_POST['uox_clone_target'] ) ? sanitize_key( wp_unslash( $_POST['uox_clone_target'] ) ) : '';
	$mode         = isset( $_POST['uox_clone_mode'] ) ? sanitize_key( wp_unslash( $_POST['uox_clone_mode'] ) ) : 'dry-run';
	$replace_users = ! empty( $_POST['uox_clone_replace_users'] );
	$confirmation = isset( $_POST['uox_clone_confirmation'] )
		? sanitize_text_field( wp_unslash( $_POST['uox_clone_confirmation'] ) )
		: '';

	if ( ! uox_clone_is_valid_env( $source ) || ! uox_clone_is_valid_env( $target ) ) {
		return new WP_Error( 'uox_clone_invalid_env', 'Origem ou destino inválido.' );
	}
	if ( ! in_array( $mode, array( 'dry-run', 'execute' ), true ) ) {
		return new WP_Error( 'uox_clone_invalid_mode', 'Modo inválido; escolha dry-run ou execute.' );
	}
	if ( $source === $target ) {
		return new WP_Error( 'uox_clone_same_env', 'Origem e destino não podem ser iguais.' );
	}

	$required_confirmation = uox_clone_required_confirmation( $source, $target );
	if ( 'prod' === $target && 'execute' === $mode && $required_confirmation !== $confirmation ) {
		return new WP_Error(
			'uox_clone_missing_production_confirmation',
			sprintf( 'Para clonar para produção, digite %s no campo de confirmação.', $required_confirmation )
		);
	}

	$ssh_notice = uox_clone_pair_requires_ssh_window( $source, $target )
		? ' Antes de prosseguir, habilite no painel a janela SSH Locaweb de três horas.'
		: '';

	if ( 'mac' === uox_clone_execution_mode( $source, $target ) ) {
		return array(
			'type'    => 'command',
			'message' => ( 'dry-run' === $mode ? 'Execute no Mac para validar sem alterar o destino.' : 'Execute no Mac somente após revisar o dry-run.' ) . $ssh_notice,
			'command' => uox_clone_build_local_command( $source, $target, $mode, $replace_users, $confirmation ),
		);
	}

	$dispatch = uox_clone_dispatch_workflow( $source, $target, $mode, $replace_users, $confirmation );
	if ( is_wp_error( $dispatch ) ) {
		return $dispatch;
	}

	return array(
		'type'    => 'success',
		'message' => 'Workflow de clone disparado em master. Acompanhe o dry-run obrigatório no GitHub Actions.' . $ssh_notice,
	);
}

function uox_clone_render_notice( $result ) {
	if ( null === $result ) {
		return;
	}

	if ( is_wp_error( $result ) ) {
		?>
		<div class="notice notice-error" style="padding:12px;">
			<p><strong><?php echo esc_html( $result->get_error_message() ); ?></strong></p>
		</div>
		<?php
		return;
	}

	$class = 'success' === ( $result['type'] ?? '' ) ? 'notice notice-success' : 'notice notice-info';
	?>
	<div class="<?php echo esc_attr( $class ); ?>" style="padding:12px;">
		<p><strong><?php echo esc_html( $result['message'] ?? 'Operação preparada.' ); ?></strong></p>

		<?php if ( ! empty( $result['command'] ) ) : ?>
			<textarea readonly rows="4" style="width:100%;font-family:monospace;"><?php echo esc_textarea( $result['command'] ); ?></textarea>
		<?php endif; ?>
	</div>
	<?php
}

function uox_clone_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Você não tem permissão para acessar esta página.' );
	}

	$result  = uox_clone_get_result_from_post();
	$envs    = uox_clone_env_labels();
	$details = uox_clone_env_details();

	?>
	<div class="wrap">
		<h1>Clone de Ambientes</h1>
		<p>Ferramenta interna para clonar banco e arquivos runtime entre produção, QA, DEV e local.</p>

		<?php uox_clone_render_notice( $result ); ?>

		<div class="notice notice-warning" style="padding:12px;">
			<p><strong>Atenção:</strong> toda execução cria backup antes da mutação e preserva usuários por padrão.</p>
			<p>Qualquer par envolvendo produção exige habilitar previamente a janela SSH Locaweb de três horas. Produção como destino exige a frase exata <code>CLONAR &lt;ORIGEM&gt; PARA PROD</code>.</p>
		</div>

		<div class="card" style="max-width:920px;margin-bottom:16px;">
			<h2>Status da Configuração</h2>
			<table class="widefat striped">
				<tbody>
					<tr>
						<td>Ambiente detectado</td>
						<td><code><?php echo esc_html( defined( 'UONIX_ENV' ) ? UONIX_ENV : 'indefinido' ); ?></code></td>
					</tr>
					<tr>
						<td>GitHub repo</td>
						<td><code><?php echo esc_html( uox_clone_get_github_repo() ); ?></code></td>
					</tr>
					<tr>
						<td>Workflow ref</td>
						<td><code><?php echo esc_html( uox_clone_get_workflow_ref() ); ?></code></td>
					</tr>
					<tr>
						<td>Token GitHub</td>
						<td><?php echo uox_clone_has_github_token() ? 'Configurado' : 'Não configurado em UONIX_GITHUB_TOKEN'; ?></td>
					</tr>
					<tr>
						<td>Path local</td>
						<td><code><?php echo esc_html( uox_clone_get_local_repo_path() ); ?></code></td>
					</tr>
				</tbody>
			</table>
		</div>

		<div class="card" style="max-width:920px;margin-bottom:16px;">
			<h2>Mapa dos ambientes</h2>
			<table class="widefat striped">
				<thead><tr><th>Ambiente</th><th>URL</th><th>Host</th></tr></thead>
				<tbody>
					<?php foreach ( $envs as $key => $label ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $label ); ?></strong></td>
							<td><code><?php echo esc_html( $details[ $key ]['url'] ); ?></code></td>
							<td><?php echo esc_html( $details[ $key ]['host'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>

		<form method="post" style="max-width:920px;">
			<?php wp_nonce_field( 'uox_clone_nonce' ); ?>
			<input type="hidden" name="uox_clone_action" value="clone">

			<div class="card" style="max-width:none;margin-bottom:16px;">
				<h2>Origem e destino</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="uox_clone_source">Origem</label></th>
						<td>
							<select id="uox_clone_source" name="uox_clone_source" required>
								<?php foreach ( $envs as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="uox_clone_target">Destino</label></th>
						<td>
							<select id="uox_clone_target" name="uox_clone_target" required>
								<?php foreach ( $envs as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>
			</div>

			<div class="card" style="max-width:none;margin-bottom:16px;">
				<h2>Modo e opções</h2>
				<p>
					<label for="uox_clone_mode"><strong>Modo</strong></label><br>
					<select id="uox_clone_mode" name="uox_clone_mode">
						<option value="dry-run" selected>Dry-run — somente validar</option>
						<option value="execute">Execute — dry-run obrigatório e depois mutação</option>
					</select>
				</p>
				<p>
					<label>
						<input type="checkbox" name="uox_clone_replace_users" value="1">
						Substituir usuários do destino (desmarcado preserva usuários e capabilities)
					</label>
				</p>
			</div>

			<div class="card" style="max-width:none;margin-bottom:16px;">
				<h2>Confirmação de produção</h2>
				<p>Obrigatória apenas em modo execute quando o destino for produção. Exemplo: <code>CLONAR QA PARA PROD</code>.</p>
				<input
					type="text"
					name="uox_clone_confirmation"
					value=""
					placeholder="CLONAR QA PARA PROD"
					style="width:100%;max-width:360px;"
				>
			</div>

			<p>
				<button
					type="submit"
					class="button button-primary"
					onclick="return confirm('Confirma a preparação do clone com origem e destino selecionados?');"
				>
					Preparar clone
				</button>
			</p>
		</form>
	</div>
	<?php
}
