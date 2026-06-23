<?php
/**
 * Interfície a Network Admin: pàgina de configuració, sync, avisos de versió.
 *
 * @package Vigilante_Network_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Vigsync_Network_Admin
 */
class Vigsync_Network_Admin {

	/**
	 * Slug de la pàgina.
	 */
	const PAGE_SLUG = 'vigilante-network-sync';

	/**
	 * Capability requerida.
	 */
	const CAP = 'manage_network_options';

	/**
	 * Resultat de la darrera acció (per pintar a la pàgina).
	 *
	 * @var array|null
	 */
	private $action_result = null;

	/**
	 * Constructor: registra els hooks de xarxa.
	 */
	public function __construct() {
		add_action( 'network_admin_menu', array( $this, 'register_menu' ) );
		add_action( 'network_admin_notices', array( $this, 'version_watch_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registra la pàgina sota "Configuració" de la xarxa.
	 */
	public function register_menu() {
		add_submenu_page(
			'settings.php',
			__( 'Vigilante Network Sync', 'vigilante-network-sync' ),
			__( 'Vigilante Sync', 'vigilante-network-sync' ),
			self::CAP,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Carrega el CSS només a la nostra pàgina.
	 *
	 * @param string $hook Hook de la pàgina actual.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'vigsync-admin',
			VIGSYNC_URL . 'assets/css/admin.css',
			array(),
			VIGSYNC_VERSION
		);
	}

	/**
	 * Processa l'enviament de formularis (abans de renderitzar).
	 */
	private function handle_post() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) ) {
			return;
		}

		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'No tens permisos per fer aquesta acció.', 'vigilante-network-sync' ) );
		}

		$action = isset( $_POST['vigsync_action'] ) ? sanitize_key( wp_unslash( $_POST['vigsync_action'] ) ) : '';

		switch ( $action ) {
			case 'save_settings':
				check_admin_referer( 'vigsync_save_settings' );
				$this->save_settings();
				break;

			case 'run_sync':
				check_admin_referer( 'vigsync_run_sync' );
				$this->action_result = Vigsync_Sync::run();
				break;

			case 'mark_compatible':
				check_admin_referer( 'vigsync_mark_compatible' );
				Vigsync_Settings::update(
					array(
						'last_known_vigilante_version' => Vigsync_Detector::vigilante_version(),
					)
				);
				$this->action_result = array(
					'success' => true,
					'message' => __( 'Versió de Vigilante marcada com a compatible.', 'vigilante-network-sync' ),
					'log'     => array(),
				);
				break;
		}
	}

	/**
	 * Desa la configuració del plugin des del formulari.
	 */
	private function save_settings() {
		$source_id = isset( $_POST['source_site_id'] ) ? absint( wp_unslash( $_POST['source_site_id'] ) ) : get_main_site_id();

		// El redirect només es pot activar si el principal té custom-login.
		$redirect_requested = ! empty( $_POST['login_redirect_enabled'] );
		$redirect_enabled   = $redirect_requested && Vigsync_Detector::is_custom_login_enabled_on_source();

		$email = isset( $_POST['notify_email'] ) ? sanitize_email( wp_unslash( $_POST['notify_email'] ) ) : '';

		Vigsync_Settings::update(
			array(
				'source_site_id'         => $source_id,
				'login_redirect_enabled' => $redirect_enabled,
				'sync_ip_lists'          => ! empty( $_POST['sync_ip_lists'] ),
				'sync_custom_login'      => ! empty( $_POST['sync_custom_login'] ),
				'notify_email'           => $email,
			)
		);

		$message = __( 'Configuració desada.', 'vigilante-network-sync' );
		if ( $redirect_requested && ! $redirect_enabled ) {
			$message .= ' ' . __( 'Nota: el redirect de login no s\'ha activat perquè el site principal no té el custom-login de Vigilante configurat.', 'vigilante-network-sync' );
		}

		$this->action_result = array(
			'success' => true,
			'message' => $message,
			'log'     => array(),
		);
	}

	/**
	 * Avís a Network Admin quan canvia la versió de Vigilante.
	 *
	 * També envia un email (un sol cop per versió) si hi ha destinatari.
	 */
	public function version_watch_notice() {
		if ( ! current_user_can( self::CAP ) || ! Vigsync_Detector::is_vigilante_active() ) {
			return;
		}

		$changed    = Vigsync_Detector::vigilante_version_changed();
		$newer      = Vigsync_Detector::vigilante_newer_than_compat();

		if ( ! $changed && ! $newer ) {
			return;
		}

		// Envia l'email una sola vegada per versió detectada.
		$this->maybe_notify_by_email();

		$current = Vigsync_Detector::vigilante_version();
		$last    = (string) Vigsync_Settings::get( 'last_known_vigilante_version', '' );
		$page    = network_admin_url( 'settings.php?page=' . self::PAGE_SLUG );

		$lines = array();
		if ( $changed && '' !== $last ) {
			$lines[] = sprintf(
				/* translators: 1: versió antiga, 2: versió nova */
				__( 'Vigilante ha canviat de la versió %1$s a la %2$s.', 'vigilante-network-sync' ),
				esc_html( $last ),
				esc_html( $current )
			);
		}
		if ( $newer ) {
			$lines[] = sprintf(
				/* translators: 1: versió de Vigilante instal·lada, 2: versió validada */
				__( 'La versió de Vigilante instal·lada (%1$s) és més nova que la validada per aquesta versió de Vigilante Network Sync (%2$s). Revisa la compatibilitat abans de confiar-hi la sincronització i el redirect.', 'vigilante-network-sync' ),
				esc_html( $current ),
				esc_html( Vigsync_Detector::compat_vigilante_version() )
			);
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong><br>%s</p><p><a href="%s" class="button button-secondary">%s</a></p></div>',
			esc_html__( 'Vigilante Network Sync — canvi detectat a Vigilante', 'vigilante-network-sync' ),
			wp_kses_post( implode( '<br>', $lines ) ),
			esc_url( $page ),
			esc_html__( 'Revisar a la pàgina de Vigilante Sync', 'vigilante-network-sync' )
		);
	}

	/**
	 * Envia l'email d'avís de canvi de versió, un sol cop per versió.
	 */
	private function maybe_notify_by_email() {
		$email = sanitize_email( (string) Vigsync_Settings::get( 'notify_email', '' ) );
		if ( ! is_email( $email ) ) {
			return;
		}

		$current  = Vigsync_Detector::vigilante_version();
		$notified = (string) Vigsync_Settings::get( 'notified_vigilante_version', '' );

		if ( $notified === $current ) {
			return; // Ja s'ha notificat per a aquesta versió.
		}

		$subject = sprintf(
			/* translators: %s: nom de la xarxa */
			__( '[%s] Vigilante ha canviat de versió', 'vigilante-network-sync' ),
			wp_specialchars_decode( get_network()->site_name )
		);

		$body = sprintf(
			/* translators: 1: versió nova de Vigilante, 2: versió validada, 3: URL de la pàgina */
			__( "S'ha detectat un canvi a Vigilante (versió actual: %1\$s; validada per Vigilante Network Sync: %2\$s).\n\nRevisa la compatibilitat de la sincronització i del redirect de login abans de confiar-hi:\n%3\$s", 'vigilante-network-sync' ),
			$current,
			Vigsync_Detector::compat_vigilante_version(),
			network_admin_url( 'settings.php?page=' . self::PAGE_SLUG )
		);

		wp_mail( $email, $subject, $body );

		Vigsync_Settings::update( array( 'notified_vigilante_version' => $current ) );
	}

	/**
	 * Renderitza la pàgina de configuració.
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'No tens permisos per accedir a aquesta pàgina.', 'vigilante-network-sync' ) );
		}

		$this->handle_post();

		$settings           = Vigsync_Settings::get_all();
		$vigilante_active   = Vigsync_Detector::is_vigilante_active();
		$source_id          = Vigsync_Settings::get_source_site_id();
		$custom_login_on    = $vigilante_active && Vigsync_Detector::is_custom_login_enabled_on_source();
		$source_slug        = Vigsync_Detector::get_source_custom_login_slug();
		$id_was_invalid     = Vigsync_Settings::source_site_id_was_invalid();

		require VIGSYNC_INCLUDES_DIR . 'views/network-settings-page.php';
	}
}
