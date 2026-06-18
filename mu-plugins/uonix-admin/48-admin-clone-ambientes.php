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
		'local' => 'Localhost',
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
	return defined( 'UONIX_GITHUB_WORKFLOW_REF' ) ? UONIX_GITHUB_WORKFLOW_REF : 'qa';
}

function uox_clone_get_local_repo_path() {
	return defined( 'UONIX_LOCAL_REPO_PATH' ) ? UONIX_LOCAL_REPO_PATH : '/Users/cassio/GitHubPessoal/uonix-site';
}

function uox_clone_has_github_token() {
	return defined( 'UONIX_GITHUB_TOKEN' ) && '' !== trim( (string) UONIX_GITHUB_TOKEN );
}

function uox_clone_dispatch_workflow( $source, $target, $include_git_files, $preserve_users, $confirm_production ) {
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
						'source'                     => $source,
						'target'                     => $target,
						'include_git_files'          => uox_clone_bool_string( $include_git_files ),
						'preserve_destination_users' => uox_clone_bool_string( $preserve_users ),
						'confirm_production'         => $confirm_production,
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
		$body = wp_remote_retrieve_body( $response );
		return new WP_Error(
			'uox_clone_dispatch_failed',
			sprintf( 'GitHub Actions retornou HTTP %d. Resposta: %s', $status, $body )
		);
	}

	return true;
}

function uox_clone_build_local_command( $source, $target, $include_git_files, $preserve_users, $confirm_production ) {
	$repo_path = uox_clone_get_local_repo_path();
	$args      = array(
		'cd ' . escapeshellarg( $repo_path ),
		'&&',
		'SSH_KEY="$HOME/.ssh/uonix_github_actions_staging_nopass"',
		'scripts/clone-environment.sh',
		'--source=' . escapeshellarg( $source ),
		'--target=' . escapeshellarg( $target ),
		'--include-git-files=' . ( $include_git_files ? '1' : '0' ),
		'--preserve-destination-users=' . ( $preserve_users ? '1' : '0' ),
	);

	if ( 'prod' === $target ) {
		$args[] = '--confirm-production=' . escapeshellarg( $confirm_production );
	}

	$args[] = '--yes';

	return implode( ' ', $args );
}

function uox_clone_get_result_from_post() {
	if ( empty( $_POST['uox_clone_action'] ) || 'clone' !== $_POST['uox_clone_action'] ) {
		return null;
	}

	check_admin_referer( 'uox_clone_nonce' );

	$source = isset( $_POST['uox_clone_source'] ) ? sanitize_key( wp_unslash( $_POST['uox_clone_source'] ) ) : '';
	$target = isset( $_POST['uox_clone_target'] ) ? sanitize_key( wp_unslash( $_POST['uox_clone_target'] ) ) : '';

	$include_git_files = ! empty( $_POST['uox_clone_include_git_files'] );
	$preserve_users    = ! empty( $_POST['uox_clone_preserve_users'] );
	$confirm_production = isset( $_POST['uox_clone_confirm_production'] )
		? sanitize_text_field( wp_unslash( $_POST['uox_clone_confirm_production'] ) )
		: '';

	if ( ! uox_clone_is_valid_env( $source ) || ! uox_clone_is_valid_env( $target ) ) {
		return new WP_Error( 'uox_clone_invalid_env', 'Origem ou destino inválido.' );
	}

	if ( $source === $target ) {
		return new WP_Error( 'uox_clone_same_env', 'Origem e destino não podem ser iguais.' );
	}

	if ( 'prod' === $target && 'CLONAR PARA PRODUCAO' !== $confirm_production ) {
		return new WP_Error(
			'uox_clone_missing_production_confirmation',
			'Para clonar para produção, digite CLONAR PARA PRODUCAO no campo de confirmação.'
		);
	}

	if ( 'local' === $source || 'local' === $target ) {
		return array(
			'type'    => 'command',
			'message' => 'Execute o comando abaixo no terminal do Mac para clonar envolvendo localhost.',
			'command' => uox_clone_build_local_command( $source, $target, $include_git_files, $preserve_users, $confirm_production ),
		);
	}

	$dispatch = uox_clone_dispatch_workflow( $source, $target, $include_git_files, $preserve_users, $confirm_production );

	if ( is_wp_error( $dispatch ) ) {
		return $dispatch;
	}

	return array(
		'type'    => 'success',
		'message' => 'Workflow de clone disparado no GitHub Actions. Acompanhe a execução no repositório.',
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

	$result = uox_clone_get_result_from_post();
	$envs   = uox_clone_env_labels();

	?>
	<div class="wrap">
		<h1>Clone de Ambientes</h1>
		<p>Ferramenta interna para clonar banco e arquivos runtime entre produção, QA e localhost.</p>

		<?php uox_clone_render_notice( $result ); ?>

		<div class="notice notice-warning" style="padding:12px;">
			<p><strong>Atenção:</strong> esta ferramenta cria backup antes do clone, mas a operação substitui banco e arquivos runtime do destino.</p>
			<p>Use produção como destino somente após validar origem, backup e confirmação manual.</p>
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
								<option value="qa">QA</option>
								<option value="local">Localhost</option>
								<option value="prod">Produção</option>
							</select>
						</td>
					</tr>
				</table>
			</div>

			<div class="card" style="max-width:none;margin-bottom:16px;">
				<h2>Opções</h2>
				<p>
					<label>
						<input type="checkbox" name="uox_clone_preserve_users" value="1" checked>
						Preservar usuários atuais do destino
					</label>
				</p>
				<p>
					<label>
						<input type="checkbox" name="uox_clone_include_git_files" value="1">
						Incluir arquivos versionados do git no clone
					</label>
				</p>
			</div>

			<div class="card" style="max-width:none;margin-bottom:16px;">
				<h2>Confirmação de produção</h2>
				<p>Obrigatório apenas quando o destino for produção.</p>
				<input
					type="text"
					name="uox_clone_confirm_production"
					value=""
					placeholder="CLONAR PARA PRODUCAO"
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
