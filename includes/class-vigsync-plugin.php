<?php
/**
 * Classe principal (singleton): arrenca les peces del plugin.
 *
 * @package Vigilante_Network_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Vigsync_Plugin
 */
final class Vigsync_Plugin {

	/**
	 * Instància única.
	 *
	 * @var Vigsync_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Retorna la instància única.
	 *
	 * @return Vigsync_Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Interfície de xarxa (la pàgina només es registra en context de network admin).
		new Vigsync_Network_Admin();

		// Redirect de login (auto-gated per precondicions; fail-open).
		new Vigsync_Login_Redirect();

		// Salvaguarda després d'actualitzar el nostre plugin.
		add_action( 'upgrader_process_complete', array( $this, 'after_update' ), 10, 2 );
	}

	/**
	 * Després d'una actualització del plugin, re-valida l'esquema de Vigilante.
	 *
	 * Si l'esquema ja no es reconeix, desactiva només el redirect (fail-open) per
	 * no arriscar l'accés, i deixa constància.
	 *
	 * @param WP_Upgrader $upgrader Instància de l'actualitzador.
	 * @param array       $data     Dades del procés d'actualització.
	 */
	public function after_update( $upgrader, $data ) {
		if ( empty( $data['type'] ) || 'plugin' !== $data['type'] ) {
			return;
		}

		$plugins = isset( $data['plugins'] ) ? (array) $data['plugins'] : array();
		if ( ! in_array( VIGSYNC_BASENAME, $plugins, true ) ) {
			return;
		}

		if ( ! Vigsync_Detector::is_vigilante_active() ) {
			return;
		}

		$valid = Vigsync_Detector::validate_schema( Vigsync_Detector::get_source_config() );
		if ( is_wp_error( $valid ) && Vigsync_Settings::get( 'login_redirect_enabled', false ) ) {
			Vigsync_Settings::update( array( 'login_redirect_enabled' => false ) );

			$email = sanitize_email( (string) Vigsync_Settings::get( 'notify_email', '' ) );
			if ( is_email( $email ) ) {
				wp_mail(
					$email,
					__( 'Vigilante Network Sync: redirect de login desactivat per seguretat', 'vigilante-network-sync' ),
					sprintf(
						/* translators: %s: missatge d'error de validació */
						__( "Després d'actualitzar Vigilante Network Sync, l'esquema de Vigilante no s'ha pogut validar (%s). S'ha desactivat el redirect de login per precaució. Revisa la configuració.", 'vigilante-network-sync' ),
						$valid->get_error_message()
					)
				);
			}
		}
	}
}
