<?php
/**
 * Tests de lògica pura (sense WordPress) per a Vigilante Network Sync.
 *
 * Exercita la lògica que no depèn de WordPress mitjançant stubs i reflexió:
 *  - Vigsync_Sync::build_payload()  (preservació de camps per-site, inclòs 2FA)
 *  - Vigsync_Sync::build_payload()  amb Vigilante 2.9.8+ (ajustos de fitxer compartit)
 *  - Vigsync_Login_Guard::should_block_request()  (decisió de bloqueig)
 *  - Vigsync_Login_Guard::copy_is_valid() / replace_token() / cookie_header_is_clearing()
 *    (rèplica del 404 del login ocult de Vigilante 3.0.9)
 *
 * Ús:  php tests/test-logic.php
 *
 * @package Vigilante_Network_Sync
 */

// Les classes tenen una guarda `if ( ! defined( 'ABSPATH' ) ) exit;`.
define( 'ABSPATH', __DIR__ . '/' );

/*
 * Stubs de WordPress. Només les funcions que la lògica pura sota prova fa servir
 * de debò, amb la mateixa implementació que el core: si el criteri de WordPress
 * canviés, el test deixaria de descriure la realitat i és millor que el stub sigui
 * literalment el que fa el core i no una aproximació.
 */
if ( ! function_exists( 'absint' ) ) {
	/**
	 * Enter no negatiu (wp-includes/functions.php).
	 *
	 * @param mixed $maybeint Valor a convertir.
	 * @return int
	 */
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}

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

/**
 * Crida strip_unmatchable_ips() (privat i amb argument per referència).
 *
 * ReflectionMethod::invokeArgs() passa els arguments per valor, així que no serveix
 * per a un mètode que modifica el payload in situ: cal una closure lligada a la
 * classe, que sí en conserva la referència.
 *
 * @param array $payload Payload a esporgar (per referència).
 * @return string[] Entrades descartades.
 */
function vigsync_strip_ips( array &$payload ) {
	$call = Closure::bind(
		static function ( &$p ) {
			return Vigsync_Sync::strip_unmatchable_ips( $p );
		},
		null,
		Vigsync_Sync::class
	);

	return $call( $payload );
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

echo "already_stored() sense validador (Vigilante < 2.11.0):\n";

/*
 * Aquí Vigilante_Settings encara no existeix, així que és el moment d'exercitar
 * el camí de compatibilitat: sense validador no es pot afirmar que el destí ja
 * estigui sincronitzat, i el false d'update_option() s'ha de seguir reportant.
 */
vigsync_assert(
	false,
	vigsync_call_private( 'Vigsync_Sync', 'already_stored', array( array( 'firewall' => array( 'enabled' => true ) ), array() ) ),
	'(m) sense validador → no s\'empassa el false d\'update_option()'
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
				// Llista real de Vigilante 3.0.7: 'protect_wp_cron' es va retirar (bloquejava
				// també el cron del servidor que crida wp-cron.php per la seva adreça).
				'firewall'         => array( 'disable_directory_browsing', 'protect_wp_config', 'protect_wp_includes', 'protect_uploads_php', 'protect_sensitive_files', 'limit_http_methods', 'block_php_in_plugins', 'block_php_in_themes' ),
			);
		}

		/**
		 * Camps que Vigilante considera dades escrites per l'usuari del site.
		 *
		 * @return array
		 */
		public static function get_user_data_keys() {
			// Llista real de Vigilante 3.0.0: 'country_blocking' i 'suspicious_patterns'
			// van desaparèixer de l'esquema a la 2.9.9 (eren ajustos que cap codi
			// llegia) i 'trusted_proxies' hi va entrar a la 2.11.9.
			// 'camp_futur_desconegut' no hi és a Vigilante: el posem per exercitar la
			// xarxa de seguretat davant d'un camp que encara no coneixem.
			return array(
				'firewall'       => array( 'ip_whitelist', 'ip_blacklist', 'ua_whitelist', 'ua_blacklist', 'trusted_proxy_header', 'trusted_proxies' ),
				'login_security' => array( 'ip_whitelist', 'custom_login_url', 'two_factor', 'camp_futur_desconegut' ),
				'user_security'  => array( 'insecure_usernames' ),
				'file_integrity' => array( 'excluded_paths', 'excluded_extensions' ),
				'email'          => array( 'additional_recipients' ),
			);
		}

		/**
		 * Validador de Vigilante 2.11.0+: descarta el que l'esquema no declara.
		 *
		 * `register_setting()` l'enganxa com a `sanitize_callback` de
		 * `vigilante_options`, així que tot `update_option()` hi passa. Fins a la
		 * 2.10.5 les claus desconegudes es desaven igualment (sanejades); des de la
		 * 2.11.0 desapareixen. No cal reproduir l'esquema sencer: només el
		 * comportament que canvia el resultat del sincronitzador.
		 *
		 * @param array $input Configuració a desar.
		 * @return array
		 */
		public function validate_options( $input ) {
			$schema = array(
				'modules'        => array( 'login_security', 'firewall' ),
				'login_security' => array( 'custom_login_url', 'max_login_attempts', 'ip_whitelist', 'two_factor' ),
				'firewall'       => array( 'ip_whitelist', 'ua_whitelist', 'trusted_proxy_header', 'trusted_proxies' ),
			);

			$validated = array();
			foreach ( (array) $input as $section => $data ) {
				if ( ! is_array( $data ) || ! isset( $schema[ $section ] ) ) {
					continue;
				}
				foreach ( $data as $key => $value ) {
					if ( in_array( $key, $schema[ $section ], true ) ) {
						$validated[ $section ][ $key ] = $value;
					}
				}
			}

			return $validated;
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
		'trusted_proxies'      => array( '203.0.113.10', '198.51.100.0/24' ),
		'protect_wp_config'    => true,
		'protect_wp_cron'      => true,
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
		'trusted_proxies'      => array(),
		'protect_wp_config'    => false,
		'protect_wp_cron'      => false,
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
// Un ajust que Vigilante treu de la seva llista deixa de preservar-se: la llista es
// llegeix de Vigilante, no la dupliquem. Vigilante 3.0.7 va retirar protect_wp_cron,
// i el seu validador el descarta en desar, així que el residu del destí desapareix.
vigsync_assert( true, $p98['firewall']['protect_wp_cron'], '(f) protect_wp_cron (retirat a Vigilante 3.0.7) ja no es preserva del destí' );

// La resta de la mateixa secció SÍ es propaga (és PHP-runtime, per-site).
vigsync_assert( array( 'enabled' => true, 'max_requests' => 120 ), $p98['firewall']['rate_limiting'], '(g) firewall.rate_limiting (PHP) es copia del principal' );
vigsync_assert( 'pingback', $p98['wp_hardening']['xmlrpc_mode'], '(g) wp_hardening.xmlrpc_mode es copia del principal' );
vigsync_assert( 5, $p98['login_security']['max_login_attempts'], '(g) login_security.max_login_attempts es copia del principal' );

// Camps uniformes a la xarxa: es propaguen tot i ser "user data" per a Vigilante.
vigsync_assert( 'HTTP_CF_CONNECTING_IP', $p98['firewall']['trusted_proxy_header'], '(h) trusted_proxy_header es propaga (uniforme a la xarxa)' );
// La parella de l'anterior. Vigilante 2.11.9+ només fa cas de la capçalera si la
// connexió ve d'un peer d'aquesta llista; copiar-ne una meitat i preservar l'altra
// deixava el subsite veient tots els visitants amb l'adreça del proxy.
vigsync_assert( array( '203.0.113.10', '198.51.100.0/24' ), $p98['firewall']['trusted_proxies'], '(h) trusted_proxies es propaga amb la seva capçalera (v2.0.8)' );
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
// strip_unmatchable_ips(): esporgada de les llistes d'IPs amb el validador de
// Vigilante 2.9.9. Cal exercitar PRIMER el camí sense validador (Vigilante < 2.9.9),
// per la mateixa raó de hoisting que amb Vigilante_Settings.
// ---------------------------------------------------------------------------

echo "strip_unmatchable_ips():\n";

$ips_brutes = array(
	'firewall'       => array(
		'ip_whitelist' => array( '8.8.8.8', '999.999.999.999/99', '10.0.0.0/8', 'paraula', '2001:db8::/32' ),
		'ip_blacklist' => array( '192.168.1.*' ),
		'ua_whitelist' => array( 'no-soc-una-ip' ),
	),
	'login_security' => array( 'ip_whitelist' => array( '1.2.3.4', '' ) ),
	'activity_log'   => array( 'excluded_ips' => array( 'localhost' ) ),
);

$sense_validador = $ips_brutes;
$rebutjades      = vigsync_strip_ips( $sense_validador );
vigsync_assert( array(), $rebutjades, '(k) Vigilante < 2.9.9: sense validador no es rebutja res' );
vigsync_assert( $ips_brutes, $sense_validador, '(k) Vigilante < 2.9.9: el payload no es toca gens' );

/*
 * Stub de Vigilante_IP_Utils 2.9.9, declarat dins d'un condicional pel mateix
 * motiu que Vigilante_Settings: si no, els asserts (k) de sobre ja el veurien
 * definit i el camí de compatibilitat no es provaria mai.
 */
if ( ! class_exists( 'Vigilante_IP_Utils' ) ) {
	/**
	 * Stub del validador d'IPs de Vigilante 2.9.9.
	 */
	class Vigilante_IP_Utils {

		/**
		 * Separa una llista en entrades que poden coincidir i les que no.
		 *
		 * @param array|string $list Llista d'entrades.
		 * @return array
		 */
		public static function split_list( $list ) {
			$valid    = array();
			$rejected = array();

			foreach ( (array) $list as $entry ) {
				$entry = trim( (string) $entry );
				if ( '' === $entry ) {
					continue;
				}
				if ( self::is_valid_pattern( $entry ) ) {
					$valid[] = $entry;
				} else {
					$rejected[] = $entry;
				}
			}

			return array(
				'valid'    => array_values( array_unique( $valid ) ),
				'rejected' => array_values( array_unique( $rejected ) ),
			);
		}

		/**
		 * Reprodueix el criteri de Vigilante: adreça exacta, CIDR o comodí.
		 *
		 * @param string $pattern Entrada a validar.
		 * @return bool
		 */
		private static function is_valid_pattern( $pattern ) {
			if ( filter_var( $pattern, FILTER_VALIDATE_IP ) ) {
				return true;
			}

			if ( false !== strpos( $pattern, '/' ) ) {
				list( $subnet, $bits ) = array_pad( explode( '/', $pattern, 2 ), 2, '' );
				if ( '' === $bits || ! ctype_digit( $bits ) || ! filter_var( $subnet, FILTER_VALIDATE_IP ) ) {
					return false;
				}
				$packed = inet_pton( $subnet );
				return ( false !== $packed && (int) $bits <= strlen( $packed ) * 8 );
			}

			if ( false !== strpos( $pattern, '*' ) ) {
				$bare = str_replace( '*', '', $pattern );
				return ( '' !== $bare && (bool) preg_match( '/^[0-9a-f:.]+$/i', $bare ) );
			}

			return false;
		}
	}
}

$amb_validador = $ips_brutes;
$rebutjades    = vigsync_strip_ips( $amb_validador );

vigsync_assert(
	array( '8.8.8.8', '10.0.0.0/8', '2001:db8::/32' ),
	$amb_validador['firewall']['ip_whitelist'],
	'(l) es conserven adreça exacta, CIDR IPv4 i CIDR IPv6'
);
vigsync_assert(
	array(
		'firewall.ip_whitelist: 999.999.999.999/99',
		'firewall.ip_whitelist: paraula',
		'activity_log.excluded_ips: localhost',
	),
	$rebutjades,
	'(l) les entrades impossibles es reporten etiquetades amb secció i clau'
);
vigsync_assert( array( '192.168.1.*' ), $amb_validador['firewall']['ip_blacklist'], '(l) el comodí IPv4 és vàlid i es conserva' );
vigsync_assert( array( 'no-soc-una-ip' ), $amb_validador['firewall']['ua_whitelist'], '(l) les llistes de User-Agent no es toquen mai' );
vigsync_assert( array( '1.2.3.4' ), $amb_validador['login_security']['ip_whitelist'], '(l) login_security.ip_whitelist esporgada (buit descartat sense avís)' );
vigsync_assert( array(), $amb_validador['activity_log']['excluded_ips'], '(l) activity_log.excluded_ips buidada de l\'entrada impossible' );

// ---------------------------------------------------------------------------
// already_stored(): distingir el false "no hi havia res a fer" del false d'error.
//
// Des de Vigilante 2.11.0 el payload i el que queda desat poden no coincidir
// (el validador descarta les claus fora d'esquema). Quan passa, update_option()
// retorna false en cada sincronització tot i que el destí és correcte, i abans
// d'aquest mètode això omplia el log d'errors inexistents a tots els subsites.
// ---------------------------------------------------------------------------

echo "already_stored() amb validador (Vigilante 2.11.0+):\n";

$payload_residu = array(
	'login_security' => array( 'custom_login_url' => 'acceso', 'residu_versio_antiga' => 'x' ),
	'firewall'       => array( 'trusted_proxy_header' => 'HTTP_CF_CONNECTING_IP' ),
);

// El destí ja té el payload sanejat: la clau de residu no s'hi pot desar mai.
$desat_al_desti = array(
	'login_security' => array( 'custom_login_url' => 'acceso' ),
	'firewall'       => array( 'trusted_proxy_header' => 'HTTP_CF_CONNECTING_IP' ),
);

vigsync_assert(
	true,
	vigsync_call_private( 'Vigsync_Sync', 'already_stored', array( $payload_residu, $desat_al_desti ) ),
	'(m) clau fora d\'esquema al principal → el destí ja està sincronitzat, no és error'
);

vigsync_assert(
	false,
	vigsync_call_private( 'Vigsync_Sync', 'already_stored', array( $payload_residu, array( 'login_security' => array( 'custom_login_url' => 'una-altra' ) ) ) ),
	'(m) el destí té un valor diferent → el false és un error de debò'
);

vigsync_assert(
	false,
	vigsync_call_private( 'Vigsync_Sync', 'already_stored', array( $payload_residu, array() ) ),
	'(m) destí buit → error, no es dóna per sincronitzat'
);

// ---------------------------------------------------------------------------
// two_factor_demand_of(): normalització de la demanda de segon factor d'un site.
//
// Des de Vigilante 2.11.10 la política de 2FA és la UNIÓ del que demana cada site
// de la xarxa, així que preservar `login_security.two_factor` per site ja no és
// inert: una diferència afecta comptes de tota la xarxa. Aquest helper és el que
// decideix si dos sites demanen el mateix, i replica el criteri de
// `Vigilante_Settings::two_factor_site_requires()`.
// ---------------------------------------------------------------------------

echo "two_factor_demand_of():\n";

$apagat = array( 'enabled' => false );

vigsync_assert(
	$apagat,
	vigsync_call_private( 'Vigsync_Detector', 'two_factor_demand_of', array( array() ) ),
	'(n) config buida → no demana res'
);

// El parany dels dos nivells d'interruptor: Vigilante ignora un two_factor orfe
// si el mòdul login_security del site està apagat.
vigsync_assert(
	$apagat,
	vigsync_call_private(
		'Vigsync_Detector',
		'two_factor_demand_of',
		array(
			array(
				'modules'        => array( 'login_security' => 0 ),
				'login_security' => array( 'two_factor' => array( 'enabled' => true, 'method' => 'totp' ) ),
			),
		)
	),
	'(n) mòdul login_security apagat → el two_factor orfe no compta'
);

vigsync_assert(
	$apagat,
	vigsync_call_private(
		'Vigsync_Detector',
		'two_factor_demand_of',
		array(
			array(
				'modules'        => array( 'login_security' => 1 ),
				'login_security' => array( 'two_factor' => array( 'enabled' => false, 'method' => 'totp' ) ),
			),
		)
	),
	'(n) mòdul encès però 2FA desactivat → no demana res'
);

$demanda_base = array(
	'modules'        => array( 'login_security' => 1 ),
	'login_security' => array(
		'two_factor' => array(
			'enabled'        => true,
			'method'         => 'totp',
			'enforced_roles' => array( 'editor', 'administrator' ),
			'excluded_users' => array( '7', 3 ),
		),
	),
);

vigsync_assert(
	array(
		'enabled'  => true,
		'method'   => 'totp',
		'roles'    => array( 'administrator', 'editor' ),
		'excluded' => array( 3, 7 ),
	),
	vigsync_call_private( 'Vigsync_Detector', 'two_factor_demand_of', array( $demanda_base ) ),
	'(n) rols i exclusions normalitzats (ordenats, IDs a enter)'
);

// Mateixa demanda escrita en un altre ordre: no ha de comptar com a divergent.
$demanda_reordenada                                                 = $demanda_base;
$demanda_reordenada['login_security']['two_factor']['enforced_roles'] = array( 'administrator', 'editor' );
$demanda_reordenada['login_security']['two_factor']['excluded_users'] = array( 3, '7' );

vigsync_assert(
	vigsync_call_private( 'Vigsync_Detector', 'two_factor_demand_of', array( $demanda_base ) ),
	vigsync_call_private( 'Vigsync_Detector', 'two_factor_demand_of', array( $demanda_reordenada ) ),
	'(n) el mateix en un altre ordre NO és divergència'
);

// Un mètode que Vigilante no coneix el llegeix com a 'email'; si no ho repliquéssim,
// dos sites que demanen el mateix sortirien com a divergents.
$metode_rar                                                = $demanda_base;
$metode_rar['login_security']['two_factor']['method']      = 'sms';
$metode_email                                              = $demanda_base;
$metode_email['login_security']['two_factor']['method']    = 'email';

vigsync_assert(
	vigsync_call_private( 'Vigsync_Detector', 'two_factor_demand_of', array( $metode_email ) ),
	vigsync_call_private( 'Vigsync_Detector', 'two_factor_demand_of', array( $metode_rar ) ),
	'(n) un mètode desconegut es llegeix com a email (com fa Vigilante)'
);

// Sense rols desats, Vigilante aplica administrator+editor: dos sites, un amb la
// clau i l'altre sense, demanen el mateix.
$sense_rols = $demanda_base;
unset( $sense_rols['login_security']['two_factor']['enforced_roles'], $sense_rols['login_security']['two_factor']['excluded_users'] );

$amb_rols_per_defecte                                                   = $demanda_base;
$amb_rols_per_defecte['login_security']['two_factor']['enforced_roles']  = array( 'administrator', 'editor' );
$amb_rols_per_defecte['login_security']['two_factor']['excluded_users']  = array();

vigsync_assert(
	vigsync_call_private( 'Vigsync_Detector', 'two_factor_demand_of', array( $amb_rols_per_defecte ) ),
	vigsync_call_private( 'Vigsync_Detector', 'two_factor_demand_of', array( $sense_rols ) ),
	'(n) sense enforced_roles s\'aplica el defecte de Vigilante (administrator+editor)'
);

// I la divergència de debò sí que es veu.
$altres_rols                                                    = $demanda_base;
$altres_rols['login_security']['two_factor']['enforced_roles']   = array( 'administrator' );

vigsync_assert(
	true,
	vigsync_call_private( 'Vigsync_Detector', 'two_factor_demand_of', array( $demanda_base ) )
		!== vigsync_call_private( 'Vigsync_Detector', 'two_factor_demand_of', array( $altres_rols ) ),
	'(n) uns rols forçats diferents SÍ són divergència'
);

// Vigilante 3.0.0 desava un "0" literal quan es desmarcava la primera casella del
// grup de rols. No és cap rol: la demanda és la mateixa que sense el "0".
$amb_zero                                                   = $demanda_base;
$amb_zero['login_security']['two_factor']['enforced_roles'] = array( '0', 'editor', 'administrator' );

vigsync_assert(
	vigsync_call_private( 'Vigsync_Detector', 'two_factor_demand_of', array( $demanda_base ) ),
	vigsync_call_private( 'Vigsync_Detector', 'two_factor_demand_of', array( $amb_zero ) ),
	'(n) el "0" residual de Vigilante 3.0.0 no compta com a rol'
);

// 2FA activat però sense cap rol real: Vigilante no el demana a ningú, igual que
// un site amb el 2FA apagat. Tant la llista buida (3.0.1) com el ["0"] (3.0.0).
$sense_cap_rol                                                   = $demanda_base;
$sense_cap_rol['login_security']['two_factor']['enforced_roles'] = array();

vigsync_assert(
	$apagat,
	vigsync_call_private( 'Vigsync_Detector', 'two_factor_demand_of', array( $sense_cap_rol ) ),
	'(n) enabled amb enforced_roles buit → no demana res'
);

$nomes_zero                                                   = $demanda_base;
$nomes_zero['login_security']['two_factor']['enforced_roles'] = array( '0' );

vigsync_assert(
	$apagat,
	vigsync_call_private( 'Vigsync_Detector', 'two_factor_demand_of', array( $nomes_zero ) ),
	'(n) enabled amb enforced_roles ["0"] → no demana res'
);

// L'usuari 0 no existeix: una exclusió que absint() deixa en 0 no diferencia res.
$exclusio_brossa                                                   = $demanda_base;
$exclusio_brossa['login_security']['two_factor']['excluded_users'] = array( '7', 3, '', 'x' );

vigsync_assert(
	vigsync_call_private( 'Vigsync_Detector', 'two_factor_demand_of', array( $demanda_base ) ),
	vigsync_call_private( 'Vigsync_Detector', 'two_factor_demand_of', array( $exclusio_brossa ) ),
	'(n) exclusions que absint() deixa en 0 s\'ignoren'
);

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
// Rèplica del 404 de Vigilante 3.0.9 (helpers purs del login guard).
// ---------------------------------------------------------------------------

echo "\ncopy_is_valid():\n";
$now  = 1_800_000_000;
$copy = array(
	'body'  => '<html>404</html>',
	'type'  => 'text/html; charset=UTF-8',
	'links' => array(),
	'extra' => array(),
	'token' => 'zzabcdefghijklmn',
	'theme' => 'twentytwentyfive',
	'home'  => 'https://example.com/es',
	'taken' => $now - 3600,
);
vigsync_assert( true, Vigsync_Login_Guard::copy_is_valid( $copy, 'twentytwentyfive', 'https://example.com/es', $now ), 'còpia d\'avui, mateix tema i adreça → vàlida' );
vigsync_assert( false, Vigsync_Login_Guard::copy_is_valid( $copy, 'twentytwentyfour', 'https://example.com/es', $now ), 'tema canviat → no vàlida (pàgina plana)' );
vigsync_assert( false, Vigsync_Login_Guard::copy_is_valid( $copy, 'twentytwentyfive', 'https://example.com/fr', $now ), 'adreça canviada → no vàlida' );
vigsync_assert( true, Vigsync_Login_Guard::copy_is_valid( array_merge( $copy, array( 'taken' => $now - 604800 ) ), 'twentytwentyfive', 'https://example.com/es', $now ), 'exactament una setmana → encara vàlida' );
vigsync_assert( false, Vigsync_Login_Guard::copy_is_valid( array_merge( $copy, array( 'taken' => $now - 604801 ) ), 'twentytwentyfive', 'https://example.com/es', $now ), 'més d\'una setmana → caducada' );
vigsync_assert( false, Vigsync_Login_Guard::copy_is_valid( array_merge( $copy, array( 'token' => 'zzabc' ) ), 'twentytwentyfive', 'https://example.com/es', $now ), 'token curt → no vàlida' );
vigsync_assert( false, Vigsync_Login_Guard::copy_is_valid( array_merge( $copy, array( 'body' => '' ) ), 'twentytwentyfive', 'https://example.com/es', $now ), 'cos buit → no vàlida' );
vigsync_assert( false, Vigsync_Login_Guard::copy_is_valid( false, 'twentytwentyfive', 'https://example.com/es', $now ), 'sense opció → no vàlida' );

echo "\nreplace_token():\n";
$body = '<p>No trobat: /zzabcdefghijklmn/</p><a href="/zzabcdefghijklmn/">x</a><span>ZZABCDEFGHIJKLMN</span>';
vigsync_assert(
	'<p>No trobat: /es/wp-login.php</p><a href="/es/wp-login.php">x</a><span>es/wp-login.php</span>',
	Vigsync_Login_Guard::replace_token( $body, 'zzabcdefghijklmn', '/es/wp-login.php?redirect_to=%2Fes%2Fwp-admin%2F' ),
	'ruta sense barra final, query fora, token també en majúscules'
);
vigsync_assert(
	'<p>No trobat: /es/el-meu-slug/</p><a href="/es/el-meu-slug/">x</a><span>es/el-meu-slug</span>',
	Vigsync_Login_Guard::replace_token( $body, 'zzabcdefghijklmn', '/es/el-meu-slug/' ),
	'barra final només on la còpia la tenia i la petició la duia'
);
vigsync_assert(
	'<p>No trobat: /es/%3Cscript%3Ealert%281%29%3C/script%3E</p>',
	Vigsync_Login_Guard::replace_token( '<p>No trobat: /zzabcdefghijklmn/</p>', 'zzabcdefghijklmn', '/es/<script>alert(1)</script>' ),
	'tot el que no és lletra, dígit o / . _ ~ % - surt com a %XX (la barra es conserva)'
);
vigsync_assert(
	'<p>/es/categor%C3%ADa/</p>',
	Vigsync_Login_Guard::replace_token( '<p>/zzabcdefghijklmn/</p>', 'zzabcdefghijklmn', '/es/categor%C3%ADa/#x' ),
	'els %XX ja presents es conserven (no es tornen a codificar)'
);
vigsync_assert( '<p>sense token</p>', Vigsync_Login_Guard::replace_token( '<p>sense token</p>', 'zzabcdefghijklmn', '/es/wp-login.php' ), 'cos sense token → intacte' );

echo "\ncookie_header_is_clearing():\n";
vigsync_assert( false, Vigsync_Login_Guard::cookie_header_is_clearing( 'Set-Cookie: wordpress_test_cookie=WP%20Cookie%20check; path=/; secure', $now ), 'cookie de prova de wp-login.php → fora' );
vigsync_assert( true, Vigsync_Login_Guard::cookie_header_is_clearing( 'Set-Cookie: a=; expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=0; path=/', $now ), 'Max-Age=0 → cookie d\'esborrat, es conserva' );
vigsync_assert( false, Vigsync_Login_Guard::cookie_header_is_clearing( 'Set-Cookie: a=b; Max-Age=3600; path=/', $now ), 'Max-Age positiu → fora' );
vigsync_assert( true, Vigsync_Login_Guard::cookie_header_is_clearing( 'Set-Cookie: a=; Max-Age=5; Max-Age=0', $now ), 'diversos Max-Age: compta l\'últim' );
vigsync_assert( true, Vigsync_Login_Guard::cookie_header_is_clearing( 'Set-Cookie: a=; Max-Age=0x; expires=Thu, 01 Jan 1970 00:00:01 GMT', $now ), 'Max-Age que no és enter s\'ignora i decideix Expires' );
vigsync_assert( false, Vigsync_Login_Guard::cookie_header_is_clearing( 'Set-Cookie: a=b; expires=Fri, 01 Jan 2100 00:00:00 GMT', $now ), 'Expires futur → fora' );
vigsync_assert( false, Vigsync_Login_Guard::cookie_header_is_clearing( 'Content-Type: text/html', $now ), 'una capçalera que no és Set-Cookie → false' );

echo "\nsafe_charset() i plain_404_html():\n";
vigsync_assert( 'UTF-8', Vigsync_Login_Guard::safe_charset( 'UTF-8' ), 'UTF-8 passa net' );
vigsync_assert( 'UTF-8', Vigsync_Login_Guard::safe_charset( '' ), 'buit → UTF-8' );
vigsync_assert( 'utf-8script', Vigsync_Login_Guard::safe_charset( 'utf-8"><script' ), 'només [A-Za-z0-9_-]' );
// Literal de Vigilante 3.0.9, class-login-security.php, send_plain_404(): ha de ser byte a byte igual.
vigsync_assert(
	'<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="robots" content="noindex, nofollow"><meta name="viewport" content="width=device-width"><title>404 Not Found</title></head><body><h1>Page not found</h1><p>The page you are looking for does not exist.</p></body></html>',
	Vigsync_Login_Guard::plain_404_html( 'UTF-8' ),
	'pàgina plana idèntica a la de Vigilante 3.0.9'
);

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
