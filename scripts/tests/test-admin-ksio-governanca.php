<?php
/**
 * Testes da governança do menu ksio.dev (49-admin-ksio-governanca.php).
 *
 * O menu ksio.dev é só do usuário `ksiodev`; as ferramentas aparecem para os demais
 * como item próprio quando liberadas, com a capacidade de sempre. Padrão: oculto.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ );

$failures = 0;
function uox_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		++$failures;
		fwrite( STDERR, "FAIL: {$message}\n" );
	}
}

class Uox_Die_Exception extends RuntimeException {}
class Uox_Redirect_Exception extends RuntimeException {
	public $url;
	public function __construct( $url ) { parent::__construct( 'redirect' ); $this->url = (string) $url; }
}

// ---- Usuário atual, capacidades e opções ----
$GLOBALS['uox_user']       = null; // null = deslogado
$GLOBALS['uox_caps']       = array();
$GLOBALS['uox_options']    = array();
$GLOBALS['uox_referer_ok'] = true;
$GLOBALS['uox_menus']      = array();
$GLOBALS['uox_submenus']   = array();
$GLOBALS['uox_actions']    = array();

function is_user_logged_in() { return null !== $GLOBALS['uox_user']; }
function wp_get_current_user() {
	$u             = new stdClass();
	$u->user_login = null === $GLOBALS['uox_user'] ? '' : $GLOBALS['uox_user'];
	return $u;
}
function current_user_can( $cap ) { return in_array( $cap, $GLOBALS['uox_caps'], true ); }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['uox_options'] ) ? $GLOBALS['uox_options'][ $key ] : $default; }
// `update_option` segue o core: aplica `pre_update_option_{$key}` e desiste se o valor
// voltar igual ao antigo, inclusive na primeira gravação, em que o antigo é `false`.
function update_option( $key, $value, $autoload = null ) {
	$antigo = get_option( $key );
	foreach ( $GLOBALS['uox_filters'][ 'pre_update_option_' . $key ] ?? array() as $filtro ) {
		$value = call_user_func( $filtro[0], $value, $antigo, $key );
	}
	if ( $value === $antigo || serialize( $value ) === serialize( $antigo ) ) {
		return false;
	}
	$GLOBALS['uox_options'][ $key ] = $value;
	return true;
}
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['uox_actions'][] = array( $hook, $callback, $priority ); return true; }
$GLOBALS['uox_filters'] = array();
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { $GLOBALS['uox_filters'][ $hook ][] = array( $callback, $priority, $args ); return true; }
function add_menu_page( $page_title, $menu_title, $cap, $slug, $callback = '', $icon = '', $position = null ) {
	$GLOBALS['uox_menus'][] = compact( 'menu_title', 'cap', 'slug', 'callback', 'icon', 'position' );
}
function add_submenu_page( $parent, $page_title, $menu_title, $cap, $slug, $callback = '' ) {
	$GLOBALS['uox_submenus'][] = compact( 'parent', 'menu_title', 'cap', 'slug', 'callback' );
}
function wp_die( $message = '', $title = '', $args = array() ) { throw new Uox_Die_Exception( (string) $message ); }
function check_admin_referer( $action = -1 ) {
	if ( ! $GLOBALS['uox_referer_ok'] ) { throw new Uox_Die_Exception( 'nonce' ); }
	return true;
}
function wp_safe_redirect( $url ) { throw new Uox_Redirect_Exception( $url ); }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function admin_url( $path = '' ) { return 'https://uonix.com.br/wp-admin/' . $path; }
function esc_url( $url ) { return (string) $url; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $text, $domain = '' ) { return $text; }
function wp_nonce_field( $action = -1 ) { echo '<input type="hidden" name="_wpnonce" value="x">'; }
function submit_button( $text = '' ) { echo '<button>' . $text . '</button>'; }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function wp_unslash( $v ) { return $v; }

// `$wpdb` mudo: as páginas de Limpeza e Clone seguem depois da guarda quando deixam
// passar, e sem isto imprimiriam avisos de propriedade em nulo no log do CI.
class Uox_Wpdb_Mudo {
	public $prefix = 'wp_';
	public function __call( $metodo, $args ) { return null; }
}
$GLOBALS['wpdb'] = new Uox_Wpdb_Mudo();

$RAIZ = dirname( __DIR__, 2 );
require_once $RAIZ . '/mu-plugins/uonix-admin/49-admin-ksio-governanca.php';
require_once $RAIZ . '/mu-plugins/uonix-admin/50-admin-intelligence-license.php';
require_once $RAIZ . '/mu-plugins/uonix-admin/46-admin-limpeza-conteudo.php';
require_once $RAIZ . '/mu-plugins/uonix-admin/48-admin-clone-ambientes.php';

$ADMIN  = array( 'manage_options', 'edit_posts', 'read' );
$EDITOR = array( 'edit_posts', 'read' );
$OPCAO  = uonix_ksio_visibility_option();

/** Loga como `$login` com `$caps`, zera os menus e roda o registro. */
function uox_menus_de( $login, array $caps ) {
	$GLOBALS['uox_user']     = $login;
	$GLOBALS['uox_caps']     = $caps;
	$GLOBALS['uox_menus']    = array();
	$GLOBALS['uox_submenus'] = array();
	uonix_ksio_register_menus();
	return array( 'menus' => $GLOBALS['uox_menus'], 'submenus' => $GLOBALS['uox_submenus'] );
}
function uox_slugs( array $itens ) { return array_column( $itens, 'slug' ); }
function uox_liberar( array $chaves ) {
	$v = array();
	foreach ( $chaves as $c ) { $v[ $c ] = true; }
	$GLOBALS['uox_options'][ uonix_ksio_visibility_option() ] = $v;
}

// ---------------------------------------------------------------------------
// 1. Registro no admin_menu.
// ---------------------------------------------------------------------------
$hooks = array();
foreach ( $GLOBALS['uox_actions'] as $a ) { $hooks[ $a[0] ] = $a; }
uox_assert( isset( $hooks['admin_menu'] ) && 'uonix_ksio_register_menus' === $hooks['admin_menu'][1], 'o 49 deve registrar os menus no admin_menu' );
// O CALLBACK, e não só o hook: ligado à tela, o botão "Salvar" imprimiria a página em
// vez de gravar (achado BAIXO da revisão do PR #310).
uox_assert( 'uonix_ksio_save_visibility' === ( $hooks['admin_post_uonix_ksio_save_visibility'][1] ?? '' ), 'o handler de visibilidade deve ser uonix_ksio_save_visibility' );

// ---------------------------------------------------------------------------
// 2. O dono: ksio.dev com os cinco submenus, e o Insights SÓ dentro dele.
// ---------------------------------------------------------------------------
$GLOBALS['uox_options'] = array();
$dono = uox_menus_de( 'ksiodev', $ADMIN );
uox_assert( array( 'ksio-dev' ) === uox_slugs( $dono['menus'] ), 'o dono tem um único topo, ksio-dev; obteve ' . json_encode( uox_slugs( $dono['menus'] ) ) );
uox_assert(
	array( 'ksio-dev', 'ksio-dev-limpeza-conteudo', 'ksio-dev-clone-ambientes', 'uonix-analytics', 'ksio-dev-visibilidade' ) === uox_slugs( $dono['submenus'] ),
	'o dono vê Visão Geral, Limpeza, Clone, Uônix Insights e Visibilidade, nos slugs de sempre; obteve ' . json_encode( uox_slugs( $dono['submenus'] ) )
);
uox_assert( array( 'ksio-dev' ) === array_values( array_unique( array_column( $dono['submenus'], 'parent' ) ) ), 'todos os submenus do dono ficam em ksio-dev' );
$subInsights = $dono['submenus'][3];
uox_assert( 'edit_posts' === $subInsights['cap'] && 'uonix_render_analytics_dashboard_page' === $subInsights['callback'], 'o Insights do dono usa a página e a capacidade de sempre' );
// A capacidade de cada item do dono (sugestão da revisão do PR #310).
uox_assert( 'manage_options' === ( $dono['menus'][0]['cap'] ?? '' ), 'o topo ksio-dev do dono exige manage_options' );
uox_assert(
	array( 'manage_options', 'manage_options', 'manage_options', 'edit_posts', 'manage_options' ) === array_column( $dono['submenus'], 'cap' ),
	'as capacidades dos submenus do dono são as de sempre; obteve ' . json_encode( array_column( $dono['submenus'], 'cap' ) )
);

// Liberar para os demais não muda o menu do dono.
uox_liberar( array( 'limpeza', 'clone', 'analytics' ) );
$donoLib = uox_menus_de( 'ksiodev', $ADMIN );
uox_assert( array( 'ksio-dev' ) === uox_slugs( $donoLib['menus'] ), 'com tudo liberado o dono continua sem o Insights como item próprio' );

// ---------------------------------------------------------------------------
// 3. Os demais: padrão OCULTO, e o menu ksio.dev nunca.
// ---------------------------------------------------------------------------
$GLOBALS['uox_options'] = array();
$root = uox_menus_de( 'root', $ADMIN );
uox_assert( array() === $root['menus'] && array() === $root['submenus'], 'administrador que não é o dono não recebe menu nenhum com o padrão; obteve ' . json_encode( $root ) );
uox_assert( array( 'limpeza' => false, 'clone' => false, 'analytics' => false ) === uonix_ksio_tools_visibility(), 'sem opção gravada, as três ficam ocultas' );

$GLOBALS['uox_options'][ $OPCAO ] = 'corrompida';
uox_assert( array( 'limpeza' => false, 'clone' => false, 'analytics' => false ) === uonix_ksio_tools_visibility(), 'opção que não é array dá tudo oculto' );
$GLOBALS['uox_options'][ $OPCAO ] = array( 'analytics' => 1, 'clone' => 'yes', 'extra' => true );
uox_assert( array( 'limpeza' => false, 'clone' => false, 'analytics' => false ) === uonix_ksio_tools_visibility(), 'só true estrito libera, e chave desconhecida é ignorada' );

// Cada ferramenta liberada vira item próprio, com a capacidade de sempre.
uox_liberar( array( 'limpeza', 'clone', 'analytics' ) );
$rootLib = uox_menus_de( 'root', $ADMIN );
uox_assert( array() === $rootLib['submenus'], 'para os demais não há submenu nenhum' );
uox_assert( ! in_array( 'ksio-dev', uox_slugs( $rootLib['menus'] ), true ), 'o menu ksio.dev nunca aparece para os demais' );
$porSlug = array();
foreach ( $rootLib['menus'] as $m ) { $porSlug[ $m['slug'] ] = $m; }
uox_assert( 'manage_options' === ( $porSlug['ksio-dev-limpeza-conteudo']['cap'] ?? '' ), 'Limpeza liberada exige manage_options' );
uox_assert( 'manage_options' === ( $porSlug['ksio-dev-clone-ambientes']['cap'] ?? '' ), 'Clone liberado exige manage_options' );
uox_assert( 'edit_posts' === ( $porSlug['uonix-analytics']['cap'] ?? '' ) && 'dashicons-chart-area' === ( $porSlug['uonix-analytics']['icon'] ?? '' ) && 3 === ( $porSlug['uonix-analytics']['position'] ?? 0 ), 'Insights liberado volta a ser o item de sempre: edit_posts, ícone e posição 3' );

uox_liberar( array( 'analytics' ) );
uox_assert( array( 'uonix-analytics' ) === uox_slugs( uox_menus_de( 'marketing', $EDITOR )['menus'] ), 'só o que foi liberado aparece' );

// ---------------------------------------------------------------------------
// 4. can_access_tool: o dono sempre; os demais com liberação E capacidade.
// ---------------------------------------------------------------------------
uox_liberar( array( 'limpeza', 'analytics' ) );
$GLOBALS['uox_user'] = 'marketing';
$GLOBALS['uox_caps'] = $EDITOR;
uox_assert( false === uonix_ksio_can_access_tool( 'limpeza' ), 'editor com Limpeza liberada não pode: falta manage_options' );
uox_assert( true === uonix_ksio_can_access_tool( 'analytics' ), 'editor com Insights liberado pode' );
uox_assert( false === uonix_ksio_can_access_tool( 'clone' ), 'ferramenta oculta: não pode' );
uox_assert( false === uonix_ksio_can_access_tool( 'inexistente' ), 'chave desconhecida nunca dá acesso' );

$GLOBALS['uox_options'] = array();
$GLOBALS['uox_user']    = 'root';
$GLOBALS['uox_caps']    = $ADMIN;
uox_assert( false === uonix_ksio_can_access_tool( 'analytics' ), 'Insights oculto: nem o administrador que não é o dono pode' );
$GLOBALS['uox_user'] = 'ksiodev';
foreach ( array( 'limpeza', 'clone', 'analytics' ) as $c ) {
	uox_assert( true === uonix_ksio_can_access_tool( $c ), "o dono pode {$c} mesmo com tudo oculto" );
}
uox_assert( false === uonix_ksio_can_access_tool( 'inexistente' ), 'chave desconhecida nunca dá acesso, nem ao dono' );

// ---------------------------------------------------------------------------
// 5. Identificação do dono: login exato, logado.
// ---------------------------------------------------------------------------
foreach ( array( 'KSIODEV', 'ksiodev2', ' ksiodev', 'ksio.dev' ) as $parecido ) {
	$GLOBALS['uox_user'] = $parecido;
	uox_assert( false === uonix_ksio_is_owner(), "login parecido não é o dono: '{$parecido}'" );
}
$GLOBALS['uox_user'] = null;
uox_assert( false === uonix_ksio_is_owner(), 'deslogado não é o dono' );
$GLOBALS['uox_user'] = 'ksiodev';
uox_assert( true === uonix_ksio_is_owner(), 'ksiodev logado é o dono' );
uox_assert( 'ksiodev' === uonix_ksio_owner_login(), 'o dono padrão é ksiodev' );
// A constante sobrescreve, para o ambiente sem o usuário `ksiodev`. Processo separado,
// porque constante não se redefine.
$sub = shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg(
	'define("ABSPATH", 1); define("UONIX_KSIO_OWNER_LOGIN", "operador-qa"); function add_action() {} function add_filter() {} '
	. 'require ' . var_export( $RAIZ . '/mu-plugins/uonix-admin/49-admin-ksio-governanca.php', true ) . '; echo uonix_ksio_owner_login();'
) );
uox_assert( 'operador-qa' === $sub, 'UONIX_KSIO_OWNER_LOGIN sobrescreve o dono; obteve ' . var_export( $sub, true ) );

// Quem altera as Configurações do Insights: o dono, e com manage_options. Ver o
// Insights liberado não basta.
uox_liberar( array( 'analytics' ) );
$configura = array(
	'dono administrador'                  => array( 'ksiodev', $ADMIN, true ),
	'dono sem manage_options'             => array( 'ksiodev', $EDITOR, false ),
	'administrador com Insights liberado' => array( 'root', $ADMIN, false ),
	'editor com Insights liberado'        => array( 'marketing', $EDITOR, false ),
	'login parecido, administrador'       => array( 'KSIODEV', $ADMIN, false ),
	'deslogado'                           => array( null, $ADMIN, false ),
);
foreach ( $configura as $caso => $linha ) {
	$GLOBALS['uox_user'] = $linha[0];
	$GLOBALS['uox_caps'] = $linha[1];
	uox_assert( $linha[2] === uonix_ksio_can_configure_insights(), "configura o Insights, {$caso}: esperado " . var_export( $linha[2], true ) );
}
$GLOBALS['uox_options'] = array();

// ---------------------------------------------------------------------------
// 6. Handler de salvar: só o dono, com nonce, e só as chaves do registro.
// ---------------------------------------------------------------------------
$GLOBALS['uox_options'] = array();
$GLOBALS['uox_user']    = 'root';
$GLOBALS['uox_caps']    = $ADMIN;
$_POST = array( 'uonix_ksio_visible' => array( 'clone' => '1' ) );
/** Roda o handler e diz se ele RECUSOU (wp_die). Sem a guarda ele seguiria até o redirect. */
function uox_salvar_recusou() {
	try {
		uonix_ksio_save_visibility();
	} catch ( Uox_Die_Exception $e ) {
		return true;
	} catch ( Uox_Redirect_Exception $e ) {
		return false;
	}
	return false;
}
uox_assert( uox_salvar_recusou(), 'administrador que não é o dono deveria ser recusado ao salvar' );
uox_assert( array() === $GLOBALS['uox_options'], 'e a opção não pode mudar' );

$GLOBALS['uox_user']       = 'ksiodev';
$GLOBALS['uox_referer_ok'] = false;
uox_assert( uox_salvar_recusou(), 'sem nonce o dono também deveria ser recusado' );
uox_assert( array() === $GLOBALS['uox_options'], 'sem nonce a opção não muda' );

$GLOBALS['uox_referer_ok'] = true;
$_POST = array( 'uonix_ksio_visible' => array( 'analytics' => '1', 'extra' => '1', 'clone' => '' ) );
$destino = '';
try {
	uonix_ksio_save_visibility();
} catch ( Uox_Redirect_Exception $e ) {
	$destino = $e->url;
}
uox_assert( array( 'limpeza' => false, 'clone' => false, 'analytics' => true ) === ( $GLOBALS['uox_options'][ $OPCAO ] ?? null ), 'o dono grava exatamente as três chaves do registro; a extra do POST é ignorada; obteve ' . json_encode( $GLOBALS['uox_options'][ $OPCAO ] ?? null ) );
uox_assert( false !== strpos( $destino, 'page=ksio-dev-visibilidade' ) && false !== strpos( $destino, 'uonix_ksio_saved=1' ), 'e volta para a tela com o aviso' );

$_POST = array( 'uonix_ksio_visible' => 'nao-array' );
try {
	uonix_ksio_save_visibility();
} catch ( Uox_Redirect_Exception $e ) {
	// esperado
}
uox_assert( array( 'limpeza' => false, 'clone' => false, 'analytics' => false ) === $GLOBALS['uox_options'][ $OPCAO ], 'campo que não é array desliga tudo, sem erro' );

// ---------------------------------------------------------------------------
// 7. Tela de visibilidade: só o dono, e reflete o gravado.
// ---------------------------------------------------------------------------
uox_liberar( array( 'clone' ) );
$GLOBALS['uox_user'] = 'ksiodev';
ob_start();
uonix_ksio_render_visibility_page();
$tela = (string) ob_get_clean();
uox_assert( 1 === preg_match( '/name="uonix_ksio_visible\[clone\]" value="1" checked/', $tela ), 'a tela marca a ferramenta liberada' );
uox_assert( 1 === preg_match( '/name="uonix_ksio_visible\[limpeza\]" value="1" >/', $tela ), 'e deixa desmarcada a oculta' );
uox_assert( false !== strpos( $tela, 'value="uonix_ksio_save_visibility"' ) && false !== strpos( $tela, '_wpnonce' ), 'o formulário declara a ação e o nonce' );
uox_assert( false !== strpos( $tela, 'Visível para administradores e editores' ), 'a tela diz quem passa a ver o Insights' );

$GLOBALS['uox_user'] = 'root';
try {
	ob_start();
	uonix_ksio_render_visibility_page();
	ob_end_clean();
	uox_assert( false, 'a tela de visibilidade deveria recusar quem não é o dono' );
} catch ( Uox_Die_Exception $e ) {
	ob_end_clean();
}

// ---------------------------------------------------------------------------
// 8. Páginas: a URL direta de ferramenta oculta é recusada, antes de qualquer saída.
// ---------------------------------------------------------------------------
$GLOBALS['uox_options'] = array();
$GLOBALS['uox_user']    = 'root';
$GLOBALS['uox_caps']    = $ADMIN;
foreach ( array( 'uox_content_render_cleanup_page' => 'Limpeza', 'uox_clone_render_page' => 'Clone', 'uox_content_render_ksio_tools_home' => 'Visão Geral' ) as $pagina => $nome ) {
	ob_start();
	$recusou = false;
	try {
		call_user_func( $pagina );
	} catch ( Uox_Die_Exception $e ) {
		$recusou = true;
		uox_assert( '' === ob_get_contents(), "{$nome} recusa antes de imprimir qualquer coisa" );
	} catch ( Throwable $e ) {
		// Sem a guarda a página segue e quebra noutro ponto (banco, WooCommerce); só
		// `wp_die` conta como recusa.
		$recusou = false;
	}
	ob_end_clean();
	uox_assert( $recusou, "{$nome} oculta deveria recusar o administrador que não é o dono" );
}
// A guarda consulta a chave da PRÓPRIA ferramenta, e deixa passar quem pode (achado
// BAIXO da revisão do PR #310). "Passou" = não recusou com a mensagem de permissão; a
// página segue e pode quebrar adiante, por falta de banco ou WooCommerce neste teste.
/** A página recusou por permissão? */
function uox_pagina_recusou( $pagina ) {
	ob_start();
	try {
		call_user_func( $pagina );
		$recusou = false;
	} catch ( Uox_Die_Exception $e ) {
		$recusou = false !== strpos( $e->getMessage(), 'Você não tem permissão para acessar esta página.' );
	} catch ( Throwable $e ) {
		$recusou = false;
	}
	ob_end_clean();
	return $recusou;
}
uox_liberar( array( 'clone' ) );
$GLOBALS['uox_user'] = 'root';
$GLOBALS['uox_caps'] = $ADMIN;
uox_assert( true === uox_pagina_recusou( 'uox_content_render_cleanup_page' ), 'com só o Clone liberado, a Limpeza recusa o administrador: a guarda usa a chave limpeza' );
uox_assert( false === uox_pagina_recusou( 'uox_clone_render_page' ), 'e o Clone deixa passar: a guarda usa a chave clone' );
uox_liberar( array( 'limpeza' ) );
uox_assert( false === uox_pagina_recusou( 'uox_content_render_cleanup_page' ), 'com só a Limpeza liberada, a Limpeza deixa passar' );
uox_assert( true === uox_pagina_recusou( 'uox_clone_render_page' ), 'e o Clone recusa' );
$GLOBALS['uox_options'] = array();
$GLOBALS['uox_user']    = 'ksiodev';
uox_assert( false === uox_pagina_recusou( 'uox_content_render_cleanup_page' ) && false === uox_pagina_recusou( 'uox_clone_render_page' ), 'o dono passa pelas duas guardas com tudo oculto' );
$GLOBALS['uox_user'] = 'root';

// A Visão Geral é do dono mesmo com tudo liberado.
uox_liberar( array( 'limpeza', 'clone', 'analytics' ) );
ob_start();
try {
	uox_content_render_ksio_tools_home();
	uox_assert( false, 'a Visão Geral do ksio.dev é só do dono, mesmo com tudo liberado' );
} catch ( Uox_Die_Exception $e ) {
	uox_assert( true, '' );
}
ob_end_clean();

// ---------------------------------------------------------------------------
// 9. A Visão Geral mostra ao dono o estado da licença. Fica no fim porque constante
// não se desfaz.
// ---------------------------------------------------------------------------
/** HTML da Visão Geral renderizada pelo dono. */
function uox_home_do_dono() {
	$GLOBALS['uox_user'] = 'ksiodev';
	ob_start();
	uox_content_render_ksio_tools_home();
	return (string) ob_get_clean();
}
$home = uox_home_do_dono();
uox_assert( false !== strpos( $home, 'uonix-license-card' ), 'a Visão Geral do dono traz o cartão da licença' );
uox_assert( false !== strpos( $home, 'Sem controle configurado' ), 'sem constante o cartão diz que não há controle configurado' );
uox_assert( false !== strpos( $home, 'admin.php?page=uonix-analytics&tab=settings#uonix-license-settings' ), 'o cartão leva ao bloco da licença na aba Configurações' );
uox_assert( false === strpos( $home, 'Não há como mudar por esta tela' ), 'o cartão não diz mais que a licença só muda pelo wp-config.php' );

// A licença do painel também aparece no cartão, com a origem.
$GLOBALS['uox_options']['uonix_intelligence_license'] = array( 'status' => 'suspended', 'valid_until' => '' );
$home_painel = uox_home_do_dono();
uox_assert( false !== strpos( $home_painel, 'está suspenso' ) && false !== strpos( $home_painel, 'Origem: painel do Uônix Insights.' ), 'com o painel suspenso o cartão diz que está suspenso, e por onde' );
unset( $GLOBALS['uox_options']['uonix_intelligence_license'] );

define( 'KSIODEV_INTELLIGENCE_STATUS', 'suspended' );
uox_assert( false !== strpos( uox_home_do_dono(), 'está suspenso' ), 'com a licença suspensa o cartão diz que está suspenso' );
$GLOBALS['uox_user'] = 'root';

// ---------------------------------------------------------------------------
// 10. Trava da opção (#323): `update_option` de quem não é o dono não cria nem muda
//     a visibilidade. É o caminho do `/wp-admin/options.php`.
// ---------------------------------------------------------------------------
uox_assert(
	array( array( 'uonix_ksio_visibility_guard_write', PHP_INT_MAX, 2 ) ) === ( $GLOBALS['uox_filters'][ 'pre_update_option_' . $OPCAO ] ?? null ),
	'A trava fica no pre_update_option da visibilidade, por último e com 2 args'
);

$GLOBALS['uox_user']    = 'root';
$GLOBALS['uox_caps']    = $ADMIN;
$GLOBALS['uox_options'] = array();
uox_assert( false === update_option( $OPCAO, array( 'clone' => true ) ), 'Outro administrador não cria a opção da visibilidade' );
uox_assert( ! array_key_exists( $OPCAO, $GLOBALS['uox_options'] ) && false === uonix_ksio_can_access_tool( 'clone' ), 'Sem a opção criada, o Clone continua oculto para ele' );

$GLOBALS['uox_options'] = array( $OPCAO => array( 'limpeza' => false, 'clone' => false, 'analytics' => true ) );
update_option( $OPCAO, array( 'limpeza' => true, 'clone' => true, 'analytics' => true ) );
uox_assert( array( 'limpeza' => false, 'clone' => false, 'analytics' => true ) === get_option( $OPCAO ), 'Outro administrador não muda a visibilidade gravada' );
uox_assert( false === uonix_ksio_can_access_tool( 'clone' ) && true === uonix_ksio_can_access_tool( 'analytics' ), 'Depois da tentativa, ele vê só o que o dono liberou' );

$GLOBALS['uox_user'] = 'ksiodev';
update_option( $OPCAO, array( 'limpeza' => false, 'clone' => true, 'analytics' => true ) );
uox_assert( array( 'limpeza' => false, 'clone' => true, 'analytics' => true ) === get_option( $OPCAO ), 'O dono muda a visibilidade pelo update_option' );
$GLOBALS['uox_user']    = 'root';
$GLOBALS['uox_options'] = array();

// WP-CLI passa (receita de recuperação), em processo separado: a constante não se
// redefine. Sem funções de usuário carregadas, ninguém é o dono.
function uox_sub_49( $prefixo, $expressao ) {
	$codigo = 'define("ABSPATH", 1); function add_action() {} function add_filter() {} ' . $prefixo
		. ' require ' . var_export( dirname( __DIR__, 2 ) . '/mu-plugins/uonix-admin/49-admin-ksio-governanca.php', true ) . '; echo json_encode(' . $expressao . ');';
	return json_decode( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( $codigo ) . ' 2>&1' ), true );
}
$r = uox_sub_49( '', 'uonix_ksio_visibility_guard_write("novo", "velho")' );
uox_assert( 'velho' === $r, 'Fora do WP-CLI e sem o dono, a trava mantém o valor antigo; obteve ' . var_export( $r, true ) );
$r = uox_sub_49( 'define("WP_CLI", true);', 'uonix_ksio_visibility_guard_write("novo", "velho")' );
uox_assert( 'novo' === $r, 'No WP-CLI a trava deixa gravar; obteve ' . var_export( $r, true ) );
$r = uox_sub_49( 'define("WP_CLI", false);', 'uonix_ksio_visibility_guard_write("novo", "velho")' );
uox_assert( 'velho' === $r, 'WP_CLI definida como false não libera; obteve ' . var_export( $r, true ) );

// ---------------------------------------------------------------------------

if ( $failures > 0 ) {
	fwrite( STDERR, "\n{$failures} asserção(ões) falharam.\n" );
	exit( 1 );
}
fwrite( STDOUT, "OK: governança do menu ksio.dev.\n" );
