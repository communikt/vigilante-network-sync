<?php
/**
 * Tests de lògica pura (sense WordPress) per a Vigilante Network Sync.
 *
 * Exercita la lògica que no depèn de WordPress mitjançant stubs i reflexió:
 *  - Vigsync_Sync::build_payload()  (preservació de camps per-site, inclòs 2FA)
 *  - Vigsync_Sync::build_payload()  amb Vigilante 2.9.8+ (ajustos de fitxer compartit)
 *  - Vigsync_Login_Guard::should_block_request()  (decisió de bloqueig)
 *
 * Ús:  php tests/test-logic.php
 *
 * @package Vigilante_Network_Sync
 */

// Les classes tenen una guarda `if ( ! defined( 'ABSPATH' ) ) exit;`.
define( 'ABSPATH', __DIR__ . '/' );

require_once __DIR__ . '/../includes/class-vigsync-detector.php';
require_once __DIR__ . '/../includes/class-vigsync-sync.php';
require_once __DIR__ . '/../includes/class-vigsync-login-guard.php';

$tests  = 0;
$failed = 0;

/**
 * Assert d'igualtat estructural.
 *
 * @param mixed  $expected Valor esperat.
 * @param mixed  $actual   Valor obtingut.
 * @param string $label    Descripció del cas.
 */
function vigsync_assert( $expected, $actual, $label ) {
	global $tests, $failed;
	$tests++;
	if ( $expected === $actual ) {
		echo "  ✓ {$label}\n";
		return;
	}
	$failed++;
	echo "  ✗ {$label}\n";
	echo '      esperat: ' . var_export( $expected, true ) . "\n";
	echo '      obtingut: ' . var_export( $actual, true ) . "\n";
}

/**
 * Crida un mètode privat estàtic via reflexió.
 *
 * @param string $class  Nom de la classe.
 * @param string $method Nom del mètode.
 * @param array  $args   Arguments.
 * @return mixed
 */
function vigsync_call_private( $class, $method, array $args ) {
	$ref = new ReflectionMethod( $class, $method );
	// setAccessible() és necessari a PHP < 8.1 i no té efecte (deprecat) a partir de 8.1.
	if ( PHP_VERSION_ID < 80100 ) {
		$ref->setAccessible( true );
	}
	return $ref->invokeArgs( null, $args );
}

// ---------------------------------------------------------------------------
// build_payload(): preservació de camps per-site (IPs, CSP, 2FA, custom-login).
// ---------------------------------------------------------------------------

$source = array(
	'modules'          => array( 'login_security' => 1 ),
	'login_security'   => array(
		'custom_login_url' => 'acceso',
		'ip_whitelist'     => array( '1.1.1.1' ),
		'two_factor'       => array(
			'enabled' => true,
			'method'  => 'totp',
			'secret'  => 'SECRET-DEL-PRINCIPAL',
		),
	),
	'firewall'         => array(
		'ip_whitelist' => array( '2.2.2.2' ),
		'ip_blacklist' => array( '3.3.3.3' ),
	),
	'activity_log'     => array( 'excluded_ips' => array( '4.4.4.4' ) ),
	'security_headers' => array(
		'hsts' => array( 'enabled' => true ),
		'csp'  => array( 'report_uri' => 'https://principal/csp' ),
	),
);

echo "build_payload():\n";

// (a) 2FA preservat per defecte: el destí té config pròpia → no s'hi empeny la del principal.
$existing_a = array(
	'login_security'   => array(
		'two_factor' => array(
			'enabled' => false,
			'method'  => 'email',
		),
	),
	'security_headers' => array( 'csp' => array( 'report_uri' => 'https://subsite/csp' ) ),
);
$payload_a  = vigsync_call_private(
	'Vigsync_Sync',
	'build_payload',
	array( $source, $existing_a, false, true, false )
);
vigsync_assert(
	$existing_a['login_security']['two_factor'],
	$payload_a['login_security']['two_factor'],
	'(a) two_factor es preserva del destí (no es copia method=totp sense secret)'
);
vigsync_assert( 'acceso', $payload_a['login_security']['custom_login_url'], '(a) custom-login es copia (sync_login=true)' );
vigsync_assert( 'https://subsite/csp', $payload_a['security_headers']['csp']['report_uri'], '(a) CSP report_uri es preserva del destí' );

// (b) sync_two_factor=true → es copia la config del principal.
$payload_b = vigsync_call_private(
	'Vigsync_Sync',
	'build_payload',
	array( $source, $existing_a, false, true, true )
);
vigsync_assert(
	$source['login_security']['two_factor'],
	$payload_b['login_security']['two_factor'],
	'(b) two_factor es copia del principal quan sync_two_factor=true'
);

// (c) destí SENSE two_factor i flag false → la clau queda eliminada (no s'empeny TOTP).
$existing_c = array( 'login_security' => array() );
$payload_c  = vigsync_call_private(
	'Vigsync_Sync',
	'build_payload',
	array( $source, $existing_c, false, true, false )
);
vigsync_assert(
	false,
	array_key_exists( 'two_factor', $payload_c['login_security'] ),
	'(c) sense two_factor al destí i flag off → clau unset (mai TOTP sense secret)'
);

// (d) IP lists preservades per defecte; custom-login preservat si sync_login=false.
$existing_d = array(
	'firewall'       => array(
		'ip_whitelist' => array( 'DEST-WL' ),
		'ip_blacklist' => array( 'DEST-BL' ),
	),
	'login_security' => array(
		'ip_whitelist'     => array( 'DEST-LOGIN-WL' ),
		'custom_login_url' => 'entrada-propia',
	),
	'activity_log'   => array( 'excluded_ips' => array( 'DEST-IP' ) ),
);
$payload_d  = vigsync_call_private(
	'Vigsync_Sync',
	'build_payload',
	array( $source, $existing_d, false, false, false )
);
vigsync_assert( array( 'DEST-WL' ), $payload_d['firewall']['ip_whitelist'], '(d) firewall ip_whitelist preservada del destí' );
vigsync_assert( array( 'DEST-BL' ), $payload_d['firewall']['ip_blacklist'], '(d) firewall ip_blacklist preservada del destí' );
vigsync_assert( array( 'DEST-LOGIN-WL' ), $payload_d['login_security']['ip_whitelist'], '(d) login ip_whitelist preservada del destí' );
vigsync_assert( 'entrada-propia', $payload_d['login_security']['custom_login_url'], '(d) custom-login preservat (sync_login=false)' );

// (d2) sync_ip_lists=true → es copien les IPs del principal.
$payload_d2 = vigsync_call_private(
	'Vigsync_Sync',
	'build_payload',
	array( $source, $existing_d, true, true, false )
);
vigsync_assert( array( '2.2.2.2' ), $payload_d2['firewall']['ip_whitelist'], '(d2) firewall ip_whitelist copiada del principal (sync_ip_lists=true)' );

// ---------------------------------------------------------------------------
// build_payload() amb Vigilante 2.9.8+: ajustos de fitxer compartit i xarxa de
// seguretat de get_user_data_keys(). Fins aquí la classe Vigilante_Settings no
// existia, així que s'ha exercitat el camí de compatibilitat (Vigilante < 2.9.8).
// ---------------------------------------------------------------------------

vigsync_assert(
	true,
	$payload_a['security_headers']['hsts']['enabled'],
	'(e) Vigilante < 2.9.8: security_headers segueix copiant-se del principal'
);
vigsync_assert(
	'https://subsite/csp',
	$payload_a['security_headers']['csp']['report_uri'],
	'(e) Vigilante < 2.9.8: només el CSP report_uri es preserva'
);

/*
 * Stub de Vigilante 2.9.8: reprodueix les dues llistes que publica el plugin.
 *
 * Es declara DINS d'un condicional a propòsit: PHP registra les declaracions de
 * classe de primer nivell en compilar el fitxer, abans d'executar cap línia, i
 * llavors els casos (a)-(e) de sobre ja veurien Vigilante_Settings definida i no
 * exercitarien mai el camí de compatibilitat amb Vigilante < 2.9.8. Dins d'un
 * bloc condicional la classe només existeix a partir d'aquí.
 */
if ( ! class_exists( 'Vigilante_Settings' ) ) {
	/**
	 * Stub de la classe de configuració de Vigilante 2.9.8.
	 */
	class Vigilante_Settings {

		/**
		 * Ajustos que només tenen efecte en fitxers compartits per la xarxa.
		 *
		 * @return array
		 */
		public static function get_shared_file_settings() {
			return array(
				'security_headers' => true,
				'wp_hardening'     => array( 'disallow_file_edit', 'disallow_file_mods', 'force_ssl_admin', 'force_ssl_login', 'wp_debug', 'disable_wp_cron' ),
				'firewall'         => array( 'disable_directory_browsing', 'protect_wp_config', 'protect_wp_includes', 'protect_uploads_php', 'protect_sensitive_files', 'protect_wp_cron', 'limit_http_methods' ),
			);
		}

		/**
		 * Camps que Vigilante considera dades escrites per l'usuari del site.
		 *
		 * @return array
		 */
		public static function get_user_data_keys() {
			return array(
				'firewall'       => array( 'ip_whitelist', 'ip_blacklist', 'ua_whitelist', 'ua_blacklist', 'trusted_proxy_header', 'country_blocking' ),
				'login_security' => array( 'ip_whitelist', 'custom_login_url', 'two_factor', 'camp_futur_desconegut' ),
				'user_security'  => array( 'insecure_usernames' ),
				'file_integrity' => array( 'excluded_paths', 'excluded_extensions', 'suspicious_patterns' ),
				'email'          => array( 'additional_recipients' ),
			);
		}
	}
}


echo "build_payload() amb Vigilante 2.9.8+:\n";

$source_98 = array(
	'modules'          => array( 'login_security' => 1 ),
	'login_security'   => array(
		'custom_login_url'      => 'acceso',
		'max_login_attempts'    => 5,
		'camp_futur_desconegut' => 'DEL-PRINCIPAL',
	),
	'firewall'         => array(
		'ua_whitelist'         => array( 'UA-PRINCIPAL' ),
		'trusted_proxy_header' => 'HTTP_CF_CONNECTING_IP',
		'protect_wp_config'    => true,
		'rate_limiting'        => array( 'enabled' => true, 'max_requests' => 120 ),
	),
	'security_headers' => array(
		'hsts' => array( 'enabled' => true ),
		'csp'  => array( 'report_uri' => 'https://principal/csp' ),
	),
	'wp_hardening'     => array(
		'force_ssl_admin' => true,
		'xmlrpc_mode'     => 'pingback',
	),
	'user_security'    => array( 'insecure_usernames' => array( 'admin', 'root' ) ),
	'file_integrity'   => array( 'excluded_paths' => array( '/wp-content/cache' ) ),
	'email'            => array( 'additional_recipients' => array( 'avisos@principal.com' ) ),
);

$existing_98 = array(
	'login_security'   => array(
		'camp_futur_desconegut' => 'DEL-SUBSITE',
	),
	'firewall'         => array(
		'ua_whitelist'         => array( 'UA-SUBSITE' ),
		'trusted_proxy_header' => '',
		'protect_wp_config'    => false,
	),
	'security_headers' => array(
		'hsts' => array( 'enabled' => false ),
		'csp'  => array( 'report_uri' => 'https://subsite/csp' ),
	),
	'wp_hardening'     => array(
		'force_ssl_admin' => false,
		'xmlrpc_mode'     => 'full',
	),
	'user_security'    => array( 'insecure_usernames' => array() ),
	'file_integrity'   => array( 'excluded_paths' => array() ),
	'email'            => array( 'additional_recipients' => array( 'cliente@subsite.com' ) ),
);

$p98 = vigsync_call_private(
	'Vigsync_Sync',
	'build_payload',
	array( $source_98, $existing_98, false, true, false )
);

// Fitxers compartits: es preserva el que hi ha al destí (no s'hi empeny res).
vigsync_assert( false, $p98['security_headers']['hsts']['enabled'], '(f) security_headers: secció sencera preservada del destí' );
vigsync_assert( 'https://subsite/csp', $p98['security_headers']['csp']['report_uri'], '(f) CSP report_uri segueix sent el del destí' );
vigsync_assert( false, $p98['firewall']['protect_wp_config'], '(f) firewall.protect_wp_config (.htaccess) preservat del destí' );
vigsync_assert( false, $p98['wp_hardening']['force_ssl_admin'], '(f) wp_hardening.force_ssl_admin (wp-config) preservat del destí' );

// La resta de la mateixa secció SÍ es propaga (és PHP-runtime, per-site).
vigsync_assert( array( 'enabled' => true, 'max_requests' => 120 ), $p98['firewall']['rate_limiting'], '(g) firewall.rate_limiting (PHP) es copia del principal' );
vigsync_assert( 'pingback', $p98['wp_hardening']['xmlrpc_mode'], '(g) wp_hardening.xmlrpc_mode es copia del principal' );
vigsync_assert( 5, $p98['login_security']['max_login_attempts'], '(g) login_security.max_login_attempts es copia del principal' );

// Camps uniformes a la xarxa: es propaguen tot i ser "user data" per a Vigilante.
vigsync_assert( 'HTTP_CF_CONNECTING_IP', $p98['firewall']['trusted_proxy_header'], '(h) trusted_proxy_header es propaga (uniforme a la xarxa)' );
vigsync_assert( array( 'admin', 'root' ), $p98['user_security']['insecure_usernames'], '(h) insecure_usernames es propaga (política global)' );
vigsync_assert( array( '/wp-content/cache' ), $p98['file_integrity']['excluded_paths'], '(h) file_integrity.excluded_paths es propaga (mateix filesystem)' );

// Camps propis del site: preservats.
vigsync_assert( array( 'UA-SUBSITE' ), $p98['firewall']['ua_whitelist'], '(i) ua_whitelist preservada del destí' );
vigsync_assert( array( 'cliente@subsite.com' ), $p98['email']['additional_recipients'], '(i) email.additional_recipients preservats del destí' );
vigsync_assert( 'DEL-SUBSITE', $p98['login_security']['camp_futur_desconegut'], '(i) camp nou declarat per Vigilante i desconegut per nosaltres → preservat' );
vigsync_assert( 'acceso', $p98['login_security']['custom_login_url'], '(i) custom-login es copia igualment (sync_login=true)' );

// Amb les caselles actives, les llistes sí es copien.
$p98b = vigsync_call_private(
	'Vigsync_Sync',
	'build_payload',
	array( $source_98, $existing_98, true, false, false )
);
vigsync_assert( array( 'UA-PRINCIPAL' ), $p98b['firewall']['ua_whitelist'], '(j) ua_whitelist copiada del principal (sync_ip_lists=true)' );
vigsync_assert( '', $p98b['login_security']['custom_login_url'], '(j) custom-login preservat del destí (sync_login=false, destí sense valor → buit)' );

// ---------------------------------------------------------------------------
// should_block_request(): decisió de bloqueig de login al subsite.
// ---------------------------------------------------------------------------

echo "should_block_request():\n";

vigsync_assert( true, Vigsync_Login_Guard::should_block_request( 'GET', '', false, false ), 'GET + acció buida + deslogat → bloqueja' );
vigsync_assert( true, Vigsync_Login_Guard::should_block_request( 'GET', 'login', false, false ), 'GET + action=login → bloqueja' );
vigsync_assert( false, Vigsync_Login_Guard::should_block_request( 'POST', '', false, false ), 'POST → passa' );
vigsync_assert( false, Vigsync_Login_Guard::should_block_request( 'GET', 'logout', false, false ), 'action=logout → passa' );
vigsync_assert( false, Vigsync_Login_Guard::should_block_request( 'GET', 'lostpassword', false, false ), 'action=lostpassword → passa' );
vigsync_assert( false, Vigsync_Login_Guard::should_block_request( 'GET', 'rp', false, false ), 'action=rp (reset) → passa' );
vigsync_assert( false, Vigsync_Login_Guard::should_block_request( 'GET', 'resetpass', false, false ), 'action=resetpass → passa' );
vigsync_assert( false, Vigsync_Login_Guard::should_block_request( 'GET', '', true, false ), 'usuari logat → passa' );
vigsync_assert( false, Vigsync_Login_Guard::should_block_request( 'GET', '', false, true ), 'interim-login → passa' );

// ---------------------------------------------------------------------------
// Resum.
// ---------------------------------------------------------------------------

echo "\n";
if ( $failed > 0 ) {
	echo "RESULTAT: {$failed} de {$tests} asserts han fallat.\n";
	exit( 1 );
}
echo "RESULTAT: tots els {$tests} asserts han passat. ✓\n";
exit( 0 );
