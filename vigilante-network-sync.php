<?php
/**
 * Plugin Name: Vigilante Network Sync
 * Plugin URI: https://github.com/communikt/vigilante-network-sync
 * Description: Capa de xarxa per a multisite que replica la configuració del plugin Vigilante des del site principal a la resta de sites, amb redirect de login unificat opcional. Complementa Vigilante; no el modifica.
 * Version: 1.0.1
 * Vigilante compat: 2.8.0
 * Author: Albert Calzada (communikt)
 * Author URI: https://communikt.com
 * Text Domain: vigilante-network-sync
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Network: true
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Vigilante_Network_Sync
 */

// Evita l'accés directe.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Constants del plugin.
 */
define( 'VIGSYNC_VERSION', '1.0.1' );
define( 'VIGSYNC_FILE', __FILE__ );
define( 'VIGSYNC_DIR', plugin_dir_path( __FILE__ ) );
define( 'VIGSYNC_URL', plugin_dir_url( __FILE__ ) );
define( 'VIGSYNC_BASENAME', plugin_basename( __FILE__ ) );
define( 'VIGSYNC_INCLUDES_DIR', VIGSYNC_DIR . 'includes/' );

// Requisits mínims.
define( 'VIGSYNC_MIN_PHP_VERSION', '7.4' );

/**
 * Comprova els requisits abans de carregar.
 *
 * Aquest plugin només té sentit en una xarxa multisite, ja que la seva funció
 * és sincronitzar configuració entre sites de la xarxa.
 *
 * @return bool True si es compleixen els requisits.
 */
function vigsync_check_requirements() {
	$meets = true;

	if ( version_compare( PHP_VERSION, VIGSYNC_MIN_PHP_VERSION, '<' ) ) {
		$meets = false;
	}

	if ( ! is_multisite() ) {
		$meets = false;
		add_action( 'admin_notices', 'vigsync_not_multisite_notice' );
		add_action( 'network_admin_notices', 'vigsync_not_multisite_notice' );
	}

	return $meets;
}

/**
 * Avís quan el plugin s'activa fora d'una xarxa multisite.
 */
function vigsync_not_multisite_notice() {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'Vigilante Network Sync requereix una instal·lació WordPress multisite. Desactiva el plugin.', 'vigilante-network-sync' )
	);
}

/**
 * Carrega les classes del plugin i arrenca.
 */
function vigsync_load_plugin() {
	if ( ! vigsync_check_requirements() ) {
		return;
	}

	require_once VIGSYNC_INCLUDES_DIR . 'class-vigsync-settings.php';
	require_once VIGSYNC_INCLUDES_DIR . 'class-vigsync-detector.php';
	require_once VIGSYNC_INCLUDES_DIR . 'class-vigsync-sync.php';
	require_once VIGSYNC_INCLUDES_DIR . 'class-vigsync-login-redirect.php';
	require_once VIGSYNC_INCLUDES_DIR . 'class-vigsync-network-admin.php';
	require_once VIGSYNC_INCLUDES_DIR . 'class-vigsync-plugin.php';

	// Inicialitza l'actualitzador automàtic (GitHub Releases via PUC), si està disponible.
	vigsync_init_updater();

	Vigsync_Plugin::get_instance();
}
add_action( 'plugins_loaded', 'vigsync_load_plugin' );

/**
 * Carrega el fitxer de traduccions des de /languages.
 *
 * Com que el plugin no es distribueix per wordpress.org, cal carregar el domini
 * manualment. Es fa a 'init' per complir amb les recomanacions actuals de WP.
 */
function vigsync_load_textdomain() {
	load_plugin_textdomain(
		'vigilante-network-sync',
		false,
		dirname( VIGSYNC_BASENAME ) . '/languages'
	);
}
add_action( 'init', 'vigsync_load_textdomain' );

/**
 * Inicialitza el Plugin Update Checker apuntant al repositori públic de GitHub.
 *
 * La llibreria es vendoritza a /lib/plugin-update-checker/. Si encara no hi és,
 * el plugin segueix funcionant amb normalitat; només no tindrà auto-update.
 */
function vigsync_init_updater() {
	$puc = VIGSYNC_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';

	if ( ! file_exists( $puc ) ) {
		return;
	}

	require_once $puc;

	// Compatibilitat amb les diferents versions del namespace de PUC.
	if ( class_exists( '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
		$factory = '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';
	} elseif ( class_exists( '\\YahnisElsts\\PluginUpdateChecker\\v5p3\\PucFactory' ) ) {
		$factory = '\\YahnisElsts\\PluginUpdateChecker\\v5p3\\PucFactory';
	} elseif ( class_exists( 'Puc_v4_Factory' ) ) {
		$factory = 'Puc_v4_Factory';
	} else {
		return;
	}

	$update_checker = $factory::buildUpdateChecker(
		'https://github.com/communikt/vigilante-network-sync/',
		VIGSYNC_FILE,
		'vigilante-network-sync'
	);

	// Usa els assets (ZIP) adjunts a cada Release de GitHub.
	$api = $update_checker->getVcsApi();
	if ( $api && method_exists( $api, 'enableReleaseAssets' ) ) {
		$api->enableReleaseAssets();
	}
}

/**
 * Hook d'activació (a nivell d'arrel del fitxer, mai dins de cap constructor).
 *
 * Fixa el site principal per defecte i desa la versió de Vigilante detectada.
 *
 * @param bool $network_wide True si s'activa a tota la xarxa.
 */
function vigsync_activate( $network_wide ) {
	if ( ! is_multisite() || ! $network_wide ) {
		// El plugin està marcat com "Network: true", però protegim igualment.
		return;
	}

	require_once VIGSYNC_INCLUDES_DIR . 'class-vigsync-settings.php';
	Vigsync_Settings::install_defaults();
}
register_activation_hook( __FILE__, 'vigsync_activate' );
