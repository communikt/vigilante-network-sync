<?php
/**
 * Redirect de login unificat (opcional).
 *
 * Als subsites, redirigeix la pantalla de login cap al login personalitzat del
 * site principal, perquè el 2FA s'enroli un sol cop. Disseny "fail-open": si
 * falla qualsevol precondició, no fa res i el login normal segueix funcionant.
 *
 * @package Vigilante_Network_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Vigsync_Login_Redirect
 */
class Vigsync_Login_Redirect {

	/**
	 * Registra els hooks.
	 *
	 * Els hooks s'enganxen sempre i el gating complet (is_eligible) es fa dins de
	 * cada callback. Així s'evita la fragilitat per l'ordre de càrrega de plugins:
	 * a plugins_loaded, Vigilante potser encara no ha definit VIGILANTE_VERSION,
	 * però els callbacks corren més tard (pàgina de login), quan ja és fiable.
	 * El cost en repòs és nul: is_eligible() surt d'hora si l'opció està desactivada.
	 */
	public function __construct() {
		add_filter( 'login_url', array( $this, 'filter_login_url' ), 99, 2 );
		add_action( 'login_init', array( $this, 'maybe_redirect' ), 1 );
	}

	/**
	 * Comprova totes les precondicions perquè el redirect actuï.
	 *
	 * Si en falla una, retorna false i el plugin no toca el login (fail-open).
	 *
	 * @return bool
	 */
	public function is_eligible() {
		// Kill-switch d'emergència via wp-config.php.
		if ( defined( 'VIGSYNC_DISABLE_REDIRECT' ) && VIGSYNC_DISABLE_REDIRECT ) {
			return false;
		}

		// L'opció ha d'estar activada.
		if ( ! Vigsync_Settings::get( 'login_redirect_enabled', false ) ) {
			return false;
		}

		// Vigilante ha d'estar actiu.
		if ( ! Vigsync_Detector::is_vigilante_active() ) {
			return false;
		}

		// No actuem al site principal (que és on viu el login real).
		if ( $this->is_source_site() ) {
			return false;
		}

		// El principal ha de tenir custom-login actiu i slug no buit.
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
	 * URL de login del site principal (amb redirect_to opcional).
	 *
	 * @param string $redirect Destí després del login.
	 * @return string
	 */
	private function source_login_url( $redirect = '' ) {
		$slug      = Vigsync_Detector::get_source_custom_login_slug();
		$source_id = Vigsync_Settings::get_source_site_id();
		$base      = trailingslashit( get_site_url( $source_id ) );
		$url       = $base . $slug . '/';

		if ( '' !== $redirect ) {
			$url = add_query_arg( 'redirect_to', rawurlencode( $redirect ), $url );
		}

		return $url;
	}

	/**
	 * Filtra login_url perquè als subsites apunti al login del principal.
	 *
	 * @param string $login_url URL de login generada per WordPress.
	 * @param string $redirect  Destí després del login.
	 * @return string
	 */
	public function filter_login_url( $login_url, $redirect ) {
		// Doble comprovació per si l'estat ha canviat dins de la petició.
		if ( ! $this->is_eligible() ) {
			return $login_url;
		}

		return $this->source_login_url( (string) $redirect );
	}

	/**
	 * Redirigeix la pantalla de login del subsite cap al login del principal.
	 *
	 * Només actua sobre la pantalla de login estàndard (GET, sense acció
	 * especial i amb l'usuari desconnectat), per no trencar fluxos com el
	 * restabliment de contrasenya, el logout o el postpass.
	 */
	public function maybe_redirect() {
		if ( ! $this->is_eligible() ) {
			return;
		}

		// Usuari ja autenticat: deixa el flux normal (p. ex. logout).
		if ( is_user_logged_in() ) {
			return;
		}

		// Només peticions GET.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'GET' !== $method ) {
			return;
		}

		// Només la pantalla de login per defecte; deixa passar accions especials.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lectura de routing, sense processar dades.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( '' !== $action && 'login' !== $action ) {
			return;
		}

		// Conserva el destí original perquè l'usuari hi torni després del 2FA.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Només es reenvia, es valida a destí.
		$redirect = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';

		$destination = $this->source_login_url( $redirect );

		// Guarda anti-bucle: no redirigir si ja som al destí.
		$current = ( is_ssl() ? 'https://' : 'http://' ) . ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' ) . ( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' );
		if ( untrailingslashit( strtok( $current, '?' ) ) === untrailingslashit( strtok( $destination, '?' ) ) ) {
			return;
		}

		wp_safe_redirect( $destination );
		exit;
	}
}
