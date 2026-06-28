<?php
/**
 * Tests de lògica pura (sense WordPress) per a Vigilante Network Sync.
 *
 * Exercita la lògica que no depèn de WordPress mitjançant stubs i reflexió:
 *  - Vigsync_Sync::build_payload()  (preservació de camps per-site, inclòs 2FA)
 *  - Vigsync_Login_Guard::should_block_request()  (decisió de bloqueig)
 *
 * Ús:  php tests/test-logic.php
 *
 * @package Vigilante_Network_Sync
 */

// Les classes tenen una guarda `if ( ! defined( 'ABSPATH' ) ) exit;`.
define( 'ABSPATH', __DIR__ . '/' );

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
	'security_headers' => array( 'csp' => array( 'report_uri' => 'https://principal/csp' ) ),
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
