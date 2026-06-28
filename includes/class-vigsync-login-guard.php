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
	 * Bloqueja la pantalla de login del subsite amb un 404 idèntic al de Vigilante.
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

		// Mateix mecanisme de bloqueig que Vigilante: 404 real + wp_die.
		status_header( 404 );
		nocache_headers();
		wp_die(
			esc_html__( 'The page you are looking for does not exist.', 'vigilante-network-sync' ),
			esc_html__( '404 Not Found', 'vigilante-network-sync' ),
			array(
				'response'  => 404,
				'back_link' => false,
			)
		);
	}
}
