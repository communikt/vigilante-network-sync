<?php
/**
 * Gestió de la configuració del plugin (opció de XARXA).
 *
 * Tota la config del nostre plugin viu en una única opció de xarxa
 * (get_site_option/update_site_option), no en opcions per-blog.
 *
 * @package Vigilante_Network_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Vigsync_Settings
 */
class Vigsync_Settings {

	/**
	 * Nom de l'opció de xarxa.
	 */
	const OPTION_NAME = 'vigsync_settings';

	/**
	 * Versió de l'esquema de dades del plugin (per a migracions d'opcions).
	 */
	const DB_VERSION = 2;

	/**
	 * Nom de l'opció de xarxa que desa la versió de l'esquema instal·lada.
	 */
	const DB_VERSION_OPTION = 'vigsync_db_version';

	/**
	 * Retorna els valors per defecte de la configuració.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		return array(
			'source_site_id'               => get_main_site_id(),
			'login_block_enabled'          => false,
			'sync_ip_lists'                => false,
			'sync_custom_login'            => true,
			'sync_two_factor'              => false,
			'notify_email'                 => get_site_option( 'admin_email' ),
			'last_known_vigilante_version' => '',
			'last_sync'                    => array(),
		);
	}

	/**
	 * Migració d'opcions entre versions de l'esquema (idempotent).
	 *
	 * v2: el mode "redirect de login" es substitueix pel mode "bloqueig de login".
	 * Es migra el valor de la clau antiga `login_redirect_enabled` a la nova
	 * `login_block_enabled` i s'elimina la clau obsoleta.
	 */
	public static function maybe_upgrade() {
		$installed = (int) get_site_option( self::DB_VERSION_OPTION, 0 );
		if ( $installed >= self::DB_VERSION ) {
			return;
		}

		$saved = get_site_option( self::OPTION_NAME, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		// v1 → v2: renomena login_redirect_enabled → login_block_enabled.
		if ( array_key_exists( 'login_redirect_enabled', $saved ) ) {
			if ( ! array_key_exists( 'login_block_enabled', $saved ) ) {
				$saved['login_block_enabled'] = (bool) $saved['login_redirect_enabled'];
			}
			unset( $saved['login_redirect_enabled'] );
			update_site_option( self::OPTION_NAME, $saved );
		}

		update_site_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Instal·la els valors per defecte en activar (només si no existeixen).
	 */
	public static function install_defaults() {
		$existing = get_site_option( self::OPTION_NAME, array() );
		$defaults = self::get_defaults();

		// En activar, deixa constància de la versió de Vigilante present (si n'hi ha).
		if ( defined( 'VIGILANTE_VERSION' ) ) {
			$defaults['last_known_vigilante_version'] = VIGILANTE_VERSION;
		}

		$merged = wp_parse_args( is_array( $existing ) ? $existing : array(), $defaults );
		update_site_option( self::OPTION_NAME, $merged );

		// Marca l'esquema actual perquè maybe_upgrade() no actuï en instal·lacions noves.
		update_site_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Retorna tota la configuració (amb defaults aplicats).
	 *
	 * @return array
	 */
	public static function get_all() {
		$saved = get_site_option( self::OPTION_NAME, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return wp_parse_args( $saved, self::get_defaults() );
	}

	/**
	 * Retorna un valor concret de configuració.
	 *
	 * @param string $key     Clau.
	 * @param mixed  $default Valor per defecte si no existeix.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::get_all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Actualitza un conjunt de valors de configuració.
	 *
	 * @param array $values Parells clau => valor a fusionar.
	 * @return bool
	 */
	public static function update( array $values ) {
		$all = self::get_all();
		$all = array_merge( $all, $values );
		return update_site_option( self::OPTION_NAME, $all );
	}

	/**
	 * ID del site principal (font de la sincronització).
	 *
	 * Si l'ID desat ja no existeix a la xarxa, recau a get_main_site_id().
	 *
	 * @return int
	 */
	public static function get_source_site_id() {
		$id = absint( self::get( 'source_site_id', get_main_site_id() ) );

		if ( ! $id || ! get_site( $id ) ) {
			$id = (int) get_main_site_id();
		}

		return $id;
	}

	/**
	 * Indica si l'ID de site principal desat era invàlid (ja no existeix).
	 *
	 * Útil per avisar a l'admin que s'ha aplicat el fallback.
	 *
	 * @return bool
	 */
	public static function source_site_id_was_invalid() {
		$saved = absint( self::get( 'source_site_id', 0 ) );
		return ( $saved && ! get_site( $saved ) );
	}
}
