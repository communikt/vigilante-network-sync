<?php
/**
 * Detecció i lectura de l'estat del plugin Vigilante.
 *
 * Centralitza tota interacció de lectura amb Vigilante: detecció d'activitat,
 * lectura de la config del site principal, validació d'esquema i vigilant de
 * versió. No escriu mai a Vigilante.
 *
 * @package Vigilante_Network_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Vigsync_Detector
 */
class Vigsync_Detector {

	/**
	 * Nom de l'opció de configuració de Vigilante (per-site).
	 */
	const VIGILANTE_OPTION = 'vigilante_options';

	/**
	 * Cache per-petició de la config llegida per site (evita switch_to_blog repetits).
	 *
	 * @var array
	 */
	private static $config_cache = array();

	/**
	 * Detecta si Vigilante està actiu i carregat.
	 *
	 * La constant VIGILANTE_VERSION es defineix a plugins_loaded, per tant és
	 * la senyal més fiable que el plugin està carregat en aquesta petició.
	 *
	 * @return bool
	 */
	public static function is_vigilante_active() {
		return defined( 'VIGILANTE_VERSION' );
	}

	/**
	 * Versió de Vigilante actualment carregada (o cadena buida).
	 *
	 * @return string
	 */
	public static function vigilante_version() {
		return defined( 'VIGILANTE_VERSION' ) ? (string) VIGILANTE_VERSION : '';
	}

	/**
	 * Llegeix la configuració de Vigilante d'un site concret de la xarxa.
	 *
	 * Sempre aparella switch_to_blog/restore_current_blog (try/finally).
	 *
	 * @param int $site_id ID del site.
	 * @return array|null Array de config o null si no n'hi ha.
	 */
	public static function get_site_config( $site_id ) {
		$site_id = absint( $site_id );
		if ( ! $site_id ) {
			return null;
		}

		if ( array_key_exists( $site_id, self::$config_cache ) ) {
			return self::$config_cache[ $site_id ];
		}

		switch_to_blog( $site_id );
		try {
			$config = get_option( self::VIGILANTE_OPTION, null );
		} finally {
			restore_current_blog();
		}

		$config                          = is_array( $config ) ? $config : null;
		self::$config_cache[ $site_id ] = $config;

		return $config;
	}

	/**
	 * Invalida la cache de config (p. ex. després d'escriure en un site).
	 *
	 * @param int|null $site_id Site concret, o null per netejar-ho tot.
	 */
	public static function flush_cache( $site_id = null ) {
		if ( null === $site_id ) {
			self::$config_cache = array();
		} else {
			unset( self::$config_cache[ absint( $site_id ) ] );
		}
	}

	/**
	 * Configuració del site principal (font de la sincronització).
	 *
	 * @return array|null
	 */
	public static function get_source_config() {
		return self::get_site_config( Vigsync_Settings::get_source_site_id() );
	}

	/**
	 * Valida que un array de config té l'esquema esperat de Vigilante.
	 *
	 * Protegeix contra canvis futurs d'esquema de Vigilante: si l'estructura no
	 * es reconeix, el sincronitzador avorta abans d'escriure res.
	 *
	 * @param mixed $config Config a validar.
	 * @return true|WP_Error True si és vàlida, WP_Error amb el motiu si no.
	 */
	public static function validate_schema( $config ) {
		if ( ! is_array( $config ) || empty( $config ) ) {
			return new WP_Error(
				'vigsync_empty_config',
				__( 'La configuració de Vigilante al site principal és buida o inexistent.', 'vigilante-network-sync' )
			);
		}

		// Claus mínimes que esperem trobar.
		$required = array( 'modules', 'login_security', 'firewall' );
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $config ) || ! is_array( $config[ $key ] ) ) {
				return new WP_Error(
					'vigsync_schema_mismatch',
					sprintf(
						/* translators: %s: nom de la clau de configuració que falta */
						__( "L'esquema de Vigilante no és el esperat: falta la secció «%s». S'avorta la sincronització per seguretat.", 'vigilante-network-sync' ),
						$key
					)
				);
			}
		}

		// La clau del login personalitzat ha d'existir (encara que sigui buida).
		if ( ! array_key_exists( 'custom_login_url', $config['login_security'] ) ) {
			return new WP_Error(
				'vigsync_schema_mismatch',
				__( "L'esquema de login de Vigilante ha canviat (no hi ha «custom_login_url»). S'avorta la sincronització per seguretat.", 'vigilante-network-sync' )
			);
		}

		return true;
	}

	/**
	 * Indica si el site principal té el custom-login de Vigilante actiu.
	 *
	 * Cal que el mòdul login_security estigui actiu i que el slug no sigui buit.
	 *
	 * @return bool
	 */
	public static function is_custom_login_enabled_on_source() {
		$config = self::get_source_config();
		if ( ! is_array( $config ) ) {
			return false;
		}

		$module_on = ! empty( $config['modules']['login_security'] );
		$slug      = self::get_source_custom_login_slug();

		return ( $module_on && '' !== $slug );
	}

	/**
	 * Slug de login personalitzat configurat al site principal (sanititzat).
	 *
	 * Es llegeix sempre en viu de la config del principal; mai es hardcodeja.
	 *
	 * @return string Slug o cadena buida.
	 */
	public static function get_source_custom_login_slug() {
		$config = self::get_source_config();
		if ( ! is_array( $config ) || empty( $config['login_security']['custom_login_url'] ) ) {
			return '';
		}

		return sanitize_title( $config['login_security']['custom_login_url'] );
	}

	/**
	 * Comprova si la versió de Vigilante ha canviat respecte de l'última coneguda.
	 *
	 * @return bool
	 */
	public static function vigilante_version_changed() {
		if ( ! self::is_vigilante_active() ) {
			return false;
		}

		$last = (string) Vigsync_Settings::get( 'last_known_vigilante_version', '' );

		// Primera vegada (sense referència): no es considera canvi.
		if ( '' === $last ) {
			return false;
		}

		return version_compare( self::vigilante_version(), $last, '!=' );
	}

	/**
	 * Versió de Vigilante contra la qual s'ha validat aquesta release del plugin.
	 *
	 * Es llegeix de la capçalera «Vigilante compat:» del fitxer principal.
	 *
	 * @return string
	 */
	public static function compat_vigilante_version() {
		$data = get_file_data( VIGSYNC_FILE, array( 'compat' => 'Vigilante compat' ) );
		return isset( $data['compat'] ) ? trim( $data['compat'] ) : '';
	}

	/**
	 * Indica si la versió de Vigilante instal·lada és més nova que la validada
	 * per aquesta release del nostre plugin (senyal de "toca actualitzar").
	 *
	 * @return bool
	 */
	public static function vigilante_newer_than_compat() {
		$compat = self::compat_vigilante_version();
		if ( '' === $compat || ! self::is_vigilante_active() ) {
			return false;
		}

		return version_compare( self::vigilante_version(), $compat, '>' );
	}

	/**
	 * Ajustos de Vigilante que només tenen efecte en fitxers compartits per la xarxa.
	 *
	 * Des de Vigilante 2.9.8, `wp-config.php` i el `.htaccess` de l'arrel només els
	 * escriu el site principal (`Vigilante_Settings::can_write_shared_files()`), i els
	 * subsites veuen aquestes seccions com a només-lectura, pintant els valors del
	 * principal. Per tant copiar-hi aquests camps no fa res: el que mana és el fitxer,
	 * que és únic. El sincronitzador els preserva del destí per no deixar-hi una còpia
	 * local que contradiu el fitxer real.
	 *
	 * Format: secció => true (tota la secció) | llista de claus.
	 *
	 * Amb Vigilante anterior a 2.9.8 el mètode no existeix i es retorna un array buit,
	 * que manté el comportament de sempre (copiar-ho tot).
	 *
	 * @return array<string,true|string[]>
	 */
	public static function get_shared_file_settings() {
		if ( ! class_exists( 'Vigilante_Settings' ) || ! method_exists( 'Vigilante_Settings', 'get_shared_file_settings' ) ) {
			return array();
		}

		$map = Vigilante_Settings::get_shared_file_settings();

		return is_array( $map ) ? $map : array();
	}

	/**
	 * Camps que Vigilante considera "dades escrites per l'usuari" del site.
	 *
	 * Des de 2.9.8, Vigilante manté a `Vigilante_Settings::get_user_data_keys()` la
	 * llista de camps que els seus botons de restauració mai esborren (llistes d'IP i
	 * User-Agent, slug de login, 2FA, exclusions d'escaneig, destinataris d'avisos...).
	 *
	 * La fem servir com a XARXA DE SEGURETAT del sincronitzador: qualsevol camp que
	 * Vigilante declari com a dada de l'usuari i que nosaltres no coneguem es preserva
	 * per defecte, de manera que una versió futura de Vigilante amb camps nous no ens
	 * agafa copiant-los sense pensar. Les excepcions conscients (camps que en una
	 * xarxa de subdirectori són uniformes i SÍ volem propagar) són a
	 * `Vigsync_Sync::NETWORK_UNIFORM_FIELDS`.
	 *
	 * @return array<string,string[]>
	 */
	public static function get_user_data_keys() {
		if ( ! class_exists( 'Vigilante_Settings' ) || ! method_exists( 'Vigilante_Settings', 'get_user_data_keys' ) ) {
			return array();
		}

		$keys = Vigilante_Settings::get_user_data_keys();

		return is_array( $keys ) ? $keys : array();
	}

	/**
	 * Separa una llista d'IPs en entrades que Vigilante pot fer coincidir i la resta.
	 *
	 * Des de 2.9.9, Vigilante valida el que s'escriu a les caixes d'IPs amb
	 * `Vigilante_IP_Utils::split_list()`: accepta adreces exactes, rangs CIDR i
	 * comodins (IPv4 i IPv6) i rebutja la resta, en comptes de desar en silenci
	 * entrades que no coincidiran mai i que fan patxoca en una llista de seguretat.
	 *
	 * Nosaltres escrivim `vigilante_options` directament, sense passar pel formulari
	 * de Vigilante, així que fem servir el seu mateix validador per no propagar als
	 * subsites entrades que el principal ja no acceptaria. Es reutilitza el mètode de
	 * Vigilante a posta: si el criteri canvia, canvia als dos llocs alhora.
	 *
	 * @param array|string $list Llista d'entrades (o text amb salts de línia).
	 * @return array|null Array amb claus `valid` i `rejected`, o null si aquesta
	 *                    versió de Vigilante no ofereix el validador (< 2.9.9).
	 */
	public static function split_ip_list( $list ) {
		if ( ! class_exists( 'Vigilante_IP_Utils' ) || ! method_exists( 'Vigilante_IP_Utils', 'split_list' ) ) {
			return null;
		}

		$split = Vigilante_IP_Utils::split_list( $list );

		if ( ! is_array( $split ) || ! isset( $split['valid'], $split['rejected'] ) ) {
			return null;
		}

		return array(
			'valid'    => (array) $split['valid'],
			'rejected' => (array) $split['rejected'],
		);
	}
}
