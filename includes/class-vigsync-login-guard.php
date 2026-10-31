<?php
/**
 * Bloqueig de login als subsites (login unificat pel site principal).
 *
 * En una xarxa multisite en subdirectori, la cookie d'autenticació de WordPress
 * és compartida per tota la xarxa: amb iniciar sessió un sol cop al site
 * principal n'hi ha prou per accedir a tots els subsites. Per tant, per unificar
 * el login només cal TANCAR la pantalla de login dels subsites, no redirigir-la.
 *
 * Aquesta classe respon un 404 idèntic al de Vigilante a qualsevol intent de
 * carregar la pantalla de login d'un subsite (tant wp-login.php com el slug de
 * custom-login, perquè Vigilante el serveix fent `require wp-login.php`, que
 * dispara igualment `login_init`). Així el login només funciona al principal i
 * el slug "secret" no es revela mai des d'un subsite.
 *
 * Des de Vigilante 3.0.9 aquell 404 és la CÒPIA de la pàgina 404 del tema, que
 * Vigilante captura per site (opció `vigilante_hidden_404_copy`) i serveix a tots
 * els camins bloquejats, sense cap cookie, perquè wp-login.php no es pugui
 * distingir d'una pàgina que no existeix. Aquí es reprodueix el mateix
 * comportament amb la mateixa còpia (el mètode de Vigilante és privat), i sense
 * còpia vàlida se serveix la mateixa pàgina plana que Vigilante, byte a byte.
 * Fins a la v2.0.11 responíem amb `wp_die()`, que era la pàgina que Vigilante
 * donava fins a la 3.0.8: comparar-la amb un 404 real delatava el bloqueig.
 *
 * Disseny "fail-open": si falla qualsevol precondició, no fa res i el login
 * normal segueix funcionant. El site principal MAI es bloqueja.
 *
 * @package Vigilante_Network_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Vigsync_Login_Guard
 */
class Vigsync_Login_Guard {

	/**
	 * Registra els hooks.
	 *
	 * El hook s'enganxa sempre i tot el gating (is_eligible) es fa dins del
	 * callback. Així s'evita la fragilitat per l'ordre de càrrega de plugins:
	 * a plugins_loaded, Vigilante potser encara no ha definit VIGILANTE_VERSION,
	 * però el callback corre més tard (pàgina de login), quan ja és fiable.
	 *
	 * Prioritat 0: responem abans (o de forma consistent) respecte del propi
	 * bloqueig de Vigilante (prio 1), sense dependre de l'ordre de registre.
	 * El cost en repòs és nul: is_eligible() surt d'hora si l'opció està off.
	 */
	public function __construct() {
		add_action( 'login_init', array( $this, 'maybe_block' ), 0 );
	}

	/**
	 * Comprova totes les precondicions perquè el bloqueig actuï.
	 *
	 * Si en falla una, retorna false i el plugin no toca el login (fail-open).
	 *
	 * @return bool
	 */
	public function is_eligible() {
		// Kill-switches d'emergència via wp-config.php (es manté el nom antic per
		// retrocompatibilitat i s'afegeix el nou, més descriptiu).
		if ( defined( 'VIGSYNC_DISABLE_LOGIN_GUARD' ) && VIGSYNC_DISABLE_LOGIN_GUARD ) {
			return false;
		}
		if ( defined( 'VIGSYNC_DISABLE_REDIRECT' ) && VIGSYNC_DISABLE_REDIRECT ) {
			return false;
		}

		// L'opció ha d'estar activada.
		if ( ! Vigsync_Settings::get( 'login_block_enabled', false ) ) {
			return false;
		}

		// Vigilante ha d'estar actiu.
		if ( ! Vigsync_Detector::is_vigilante_active() ) {
			return false;
		}

		// Hard-guard de subdominis: en xarxes de subdominis la cookie d'auth no es
		// comparteix, així que bloquejar el login dels subsites deixaria l'admin
		// fora dels seus wp-admin. Es comprova també aquí (no només a la UI) per
		// cobrir migracions de v1 on l'opció es va heretar sense validació.
		if ( is_subdomain_install() ) {
			return false;
		}

		// No actuem al site principal (que és on viu el login real). Invariant
		// anti-lockout: sempre hi ha una porta oberta.
		if ( $this->is_source_site() ) {
			return false;
		}

		// El principal ha de tenir custom-login actiu (paritat amb la UI; el 404
		// funcionaria igual sense això, però evita sorpreses de configuració).
		if ( ! Vigsync_Detector::is_custom_login_enabled_on_source() ) {
			return false;
		}

		return true;
	}

	/**
	 * Indica si el site actual és el site principal (font).
	 *
	 * @return bool
	 */
	private function is_source_site() {
		return (int) get_current_blog_id() === (int) Vigsync_Settings::get_source_site_id();
	}

	/**
	 * Decideix si una petició a la pantalla de login s'ha de bloquejar.
	 *
	 * Helper PUR (sense dependències de WordPress) perquè sigui testejable amb
	 * stubs. Replica les exclusions de Vigilante per no trencar fluxos legítims
	 * (logout, restabliment de contrasenya, app passwords, modal de re-login...).
	 *
	 * @param string $method    Mètode HTTP (GET, POST...).
	 * @param string $action    Valor de l'acció de wp-login.php ($_REQUEST['action']).
	 * @param bool   $logged_in Si l'usuari ja està autenticat.
	 * @param bool   $interim   Si és el modal de re-autenticació (interim-login).
	 * @return bool True si cal respondre 404; false si s'ha de deixar passar.
	 */
	public static function should_block_request( $method, $action, $logged_in, $interim ) {
		// Usuari ja autenticat: mai bloquegem (logout, re-auth, perfil...).
		if ( $logged_in ) {
			return false;
		}

		// Només bloquegem la càrrega GET de la pantalla; els POST (login, postpass,
		// resetpass...) passen: un POST directe no revela el slug.
		if ( 'GET' !== strtoupper( (string) $method ) ) {
			return false;
		}

		// Modal de re-autenticació: no tocar.
		if ( $interim ) {
			return false;
		}

		// Accions especials de wp-login.php que cal deixar passar (logout, rp,
		// resetpass, lostpassword, retrievepassword, postpass, confirmaction,
		// confirm_admin_email, authorize_application...). Qualsevol acció no buida
		// i diferent de 'login' no és la pantalla d'accés estàndard.
		$action = (string) $action;
		if ( '' !== $action && 'login' !== $action ) {
			return false;
		}

		// Pantalla de login estàndard (acció buida o 'login'): bloquejar.
		return true;
	}

	/**
	 * Bloqueja la pantalla de login del subsite amb un 404 idèntic al de Vigilante
	 * (vegeu send_not_found()).
	 *
	 * No redirigeix (per no revelar el slug del principal): qualsevol intent de
	 * carregar el login d'un subsite "no existeix".
	 */
	public function maybe_block() {
		if ( ! $this->is_eligible() ) {
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lectura de routing, sense processar dades.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Només es comprova la presència del paràmetre.
		$interim = isset( $_REQUEST['interim-login'] );

		if ( ! self::should_block_request( $method, $action, is_user_logged_in(), $interim ) ) {
			return;
		}

		self::send_not_found();
	}

	/**
	 * Opció on Vigilante (3.0.9+) desa la còpia de la pàgina 404 del site.
	 *
	 * Mateix nom que `Vigilante_Login_Security::NOT_FOUND_OPTION`.
	 */
	const VIGILANTE_NOT_FOUND_OPTION = 'vigilante_hidden_404_copy';

	/**
	 * Serveix el 404 del login ocult tal com el serveix Vigilante.
	 *
	 * Si Vigilante arriba a exposar el seu propi mètode (`send_not_found()` és
	 * privat a la 3.0.9; se li ha demanat un punt d'entrada públic), es crida el
	 * seu i prou: `is_callable()` sobre un mètode privat des de fora retorna
	 * false, així que el camí propi només s'usa mentre no hi hagi alternativa.
	 *
	 * Rèplica de `Vigilante_Login_Security::send_not_found()` (3.0.9): la còpia
	 * de la pàgina 404 del site si és vàlida per al tema i l'adreça actuals,
	 * amb la ruta demanada al lloc del token, les capçaleres que WordPress envia
	 * amb un 404 (Content-Type, Link) i cap cookie que no sigui d'esborrat; i si
	 * no hi ha còpia, la pàgina plana de Vigilante, idèntica.
	 */
	private static function send_not_found() {
		if ( is_callable( array( 'Vigilante_Login_Security', 'send_not_found' ) ) ) {
			Vigilante_Login_Security::send_not_found();
			exit;
		}

		status_header( 404 );
		nocache_headers();
		self::keep_only_clearing_cookies();

		$copy = get_option( self::VIGILANTE_NOT_FOUND_OPTION );
		if ( ! self::copy_is_valid( $copy, (string) get_option( 'stylesheet' ), (string) get_option( 'home' ), time() ) ) {
			self::send_plain_404();
		}

		$request_uri = ( class_exists( 'Vigilante_IP_Utils' ) && method_exists( 'Vigilante_IP_Utils', 'request_uri' ) )
			? (string) Vigilante_IP_Utils::request_uri()
			: ( isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' );

		$body = self::replace_token( $copy['body'], $copy['token'], $request_uri );
		if ( ! is_string( $body ) ) {
			self::send_plain_404();
		}

		if ( ! headers_sent() ) {
			header( 'Content-Type: ' . self::one_line( $copy['type'] ) );
			foreach ( (array) ( isset( $copy['links'] ) ? $copy['links'] : array() ) as $link ) {
				$link = self::one_line( $link );
				if ( '' !== $link ) {
					header( 'Link: ' . $link, false );
				}
			}
			if ( isset( $copy['extra']['X-UA-Compatible'] ) && '' !== self::one_line( $copy['extra']['X-UA-Compatible'] ) ) {
				header( 'X-UA-Compatible: ' . self::one_line( $copy['extra']['X-UA-Compatible'] ) );
			}
		}

		// La pàgina 404 que aquest mateix site ha servit a un visitant sense sessió,
		// reproduïda tal com Vigilante la va capturar; l'única part d'aquesta petició
		// que hi entra és la ruta, escapada dins de replace_token(). Mateixa excepció
		// que fa Vigilante (class-login-security.php, send_not_found()).
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * La pàgina plana del login ocult de Vigilante, byte a byte.
	 *
	 * Deliberadament sense traduir i sense cap filtre de plantilla, com fa
	 * Vigilante: ha de ser la MATEIXA resposta que donaria ell.
	 */
	private static function send_plain_404() {
		status_header( 404 );
		nocache_headers();
		self::keep_only_clearing_cookies();

		$charset = self::safe_charset( (string) get_option( 'blog_charset' ) );
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=' . $charset );
		}
		echo self::plain_404_html( $charset ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML fix sense cap dada de la petició; el charset passa per safe_charset().
		exit;
	}

	/**
	 * Deixa a la resposta només les cookies que n'esborren una.
	 *
	 * wp-login.php envia `wordpress_test_cookie` (i `wp_lang`) abans de
	 * `login_init`, i un 404 real no envia cap cookie: delatava el bloqueig.
	 * Només es conserva una cookie ja caducada (Max-Age <= 0 o Expires passat),
	 * que és com un altre plugin tanca una sessió abans d'arribar aquí.
	 */
	private static function keep_only_clearing_cookies() {
		if ( headers_sent() ) {
			return;
		}
		$keep = array();
		foreach ( headers_list() as $line ) {
			if ( self::cookie_header_is_clearing( $line, time() ) ) {
				$keep[] = $line;
			}
		}
		header_remove( 'Set-Cookie' );
		foreach ( $keep as $line ) {
			header( $line, false );
		}
	}

	/*
	 * ------------------------------------------------------------------
	 * Helpers PURS (sense WordPress), testejables des de tests/test-logic.php.
	 * Repliquen el criteri de Vigilante 3.0.9 línia a línia; si Vigilante el
	 * canvia, cal revisar-los (la rutina de revisió mira class-login-security.php).
	 * ------------------------------------------------------------------
	 */

	/**
	 * Indica si una còpia de Vigilante descriu encara la pàgina 404 d'aquest site.
	 *
	 * Rèplica de `Vigilante_Login_Security::not_found_copy_is_valid()`: tema i
	 * adreça iguals als actuals, i no més d'una setmana d'antiguitat.
	 *
	 * @param mixed  $copy       Contingut de l'opció `vigilante_hidden_404_copy`.
	 * @param string $stylesheet Tema actiu (`get_option( 'stylesheet' )`).
	 * @param string $home       Adreça del site (`get_option( 'home' )`).
	 * @param int    $now        Instant actual (time()).
	 * @return bool
	 */
	public static function copy_is_valid( $copy, $stylesheet, $home, $now ) {
		return is_array( $copy )
			&& isset( $copy['body'], $copy['type'], $copy['token'], $copy['theme'], $copy['home'], $copy['taken'] )
			&& is_string( $copy['body'] ) && '' !== $copy['body']
			&& is_string( $copy['token'] ) && strlen( $copy['token'] ) >= 8
			&& ( (int) $now - (int) $copy['taken'] ) <= 604800 // WEEK_IN_SECONDS.
			&& (string) $stylesheet === (string) $copy['theme']
			&& (string) $home === (string) $copy['home'];
	}

	/**
	 * Posa la ruta demanada al lloc del token amb què es va capturar la còpia.
	 *
	 * Rèplica del tractament de `Vigilante_Login_Security::send_not_found()`:
	 * la ruta (sense query ni fragment) amb tot el que no sigui lletra, dígit o
	 * `/ . _ ~ % -` escrit com a %XX, d'una sola passada i sense distingir
	 * majúscules; la barra final només si la petició en duia.
	 *
	 * @param string $body        Cos de la còpia.
	 * @param string $token       Token de la captura (p. ex. "zzab12cd34ef56gh").
	 * @param string $request_uri URI demanada.
	 * @return string|null Null si PCRE falla (com fa Vigilante: llavors, pàgina plana).
	 */
	public static function replace_token( $body, $token, $request_uri ) {
		$path    = (string) strtok( (string) $request_uri, '?#' );
		$trimmed = (string) preg_replace_callback(
			'~[^A-Za-z0-9/._\~%-]~',
			function ( $c ) {
				return rawurlencode( $c[0] );
			},
			trim( $path, '/' )
		);
		$slash   = ( '' !== $trimmed && '/' === substr( $path, -1 ) ) ? '/' : '';
		// Després de l'escapat només queden caràcters segurs; htmlspecialchars és
		// la capa defensiva equivalent a l'esc_html() de Vigilante, sense WordPress.
		$safe    = htmlspecialchars( $trimmed, ENT_QUOTES, 'UTF-8' );
		$result  = preg_replace_callback(
			'~' . preg_quote( (string) $token, '~' ) . '(/?)~i',
			function ( $m ) use ( $safe, $slash ) {
				return $safe . ( '' !== $m[1] ? $slash : '' );
			},
			(string) $body
		);
		return is_string( $result ) ? $result : null;
	}

	/**
	 * Indica si una línia `Set-Cookie:` esborra la cookie (ja caducada).
	 *
	 * Rèplica de `Vigilante_Login_Security::keep_only_clearing_cookies()`: un
	 * Max-Age guanya sobre Expires i, si n'hi ha més d'un, compta l'últim, com
	 * fan els navegadors; un Max-Age que no sigui un enter s'ignora.
	 *
	 * @param string $line Línia de capçalera tal com la dona headers_list().
	 * @param int    $now  Instant actual (time()).
	 * @return bool False per a qualsevol línia que no sigui Set-Cookie.
	 */
	public static function cookie_header_is_clearing( $line, $now ) {
		$line = (string) $line;
		if ( 0 !== stripos( $line, 'Set-Cookie:' ) ) {
			return false;
		}
		if ( preg_match_all( '/;\s*max-age\s*=\s*(-?\d+)\s*(?=;|$)/i', $line, $m ) ) {
			return (int) end( $m[1] ) <= 0;
		}
		if ( preg_match( '/;\s*expires=([^;]+)/i', $line, $m ) ) {
			$when = strtotime( trim( $m[1] ) );
			return false !== $when && $when < (int) $now;
		}
		return false;
	}

	/**
	 * Charset net per a la capçalera i el <meta>, com el calcula Vigilante.
	 *
	 * @param string $charset Valor de `blog_charset`.
	 * @return string
	 */
	public static function safe_charset( $charset ) {
		$charset = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $charset );
		return '' === $charset ? 'UTF-8' : $charset;
	}

	/**
	 * HTML de la pàgina plana del login ocult de Vigilante 3.0.9, byte a byte.
	 *
	 * @param string $charset Charset ja net (safe_charset()).
	 * @return string
	 */
	public static function plain_404_html( $charset ) {
		return '<!DOCTYPE html><html><head><meta charset="' . htmlspecialchars( (string) $charset, ENT_QUOTES, 'UTF-8' ) . '"><meta name="robots" content="noindex, nofollow"><meta name="viewport" content="width=device-width"><title>404 Not Found</title></head><body><h1>Page not found</h1><p>The page you are looking for does not exist.</p></body></html>';
	}

	/**
	 * Un valor de capçalera en una sola línia (primer valor si n'hi ha diversos).
	 *
	 * @param mixed $value Valor.
	 * @return string
	 */
	private static function one_line( $value ) {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		return is_string( $value ) ? trim( (string) preg_replace( '/[\r\n]+/', ' ', $value ) ) : '';
	}
}
