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
	 * Indica si Vigilante ja gestiona la configuració de tota la xarxa.
	 *
	 * Des de la 3.1.0, en una xarxa migrada Vigilante guarda una sola configuració
	 * com a opció de xarxa (`vigilante_network_options`), que només es canvia des
	 * de l'administració de xarxa, i cada site guarda al seu `vigilante_options`
	 * només les claus que són seves (`get_site_keys()`). La migració no toca les
	 * opcions dels subsites, per poder tornar a la 3.0.14: el que hi escrivim
	 * després de migrar ja no té efecte en els ajustos de xarxa. A més, la 3.1.0
	 * tanca ella mateixa el login dels subsites. Amb això, el sync de la
	 * configuració i el bloqueig de login d'aquest plugin ja no fan falta, i
	 * s'aparten (retirada ordenada, v2.1.0).
	 *
	 * Mètode anunciat per en Fernando Tellado per correu el 2026-11-19, abans
	 * d'escriure'l: es comprova amb `is_callable()` perquè, si al final no és
	 * estàtic o canvia de nom, el plugin segueixi funcionant com fins ara en
	 * comptes de petar. En aquest cas cal revisar-ho quan surti la 3.1.0.
	 *
	 * @return bool
	 */
	public static function network_config_active() {
		if ( ! class_exists( 'Vigilante_Settings' ) || ! is_callable( array( 'Vigilante_Settings', 'network_config_active' ) ) ) {
			return false;
		}

		return (bool) Vigilante_Settings::network_config_active();
	}

	/**
	 * Claus de Vigilante que segueixen per site en una xarxa migrada (3.1.0+).
	 *
	 * Mateixa forma que `get_user_data_keys()`: secció => llista de claus.
	 *
	 * @return array<string,string[]>|null Null si aquesta versió no ofereix el mètode.
	 */
	public static function get_site_keys() {
		if ( ! class_exists( 'Vigilante_Settings' ) || ! is_callable( array( 'Vigilante_Settings', 'get_site_keys' ) ) ) {
			return null;
		}

		$keys = Vigilante_Settings::get_site_keys();

		return is_array( $keys ) ? $keys : null;
	}

	/**
	 * Indica si aquesta versió de Vigilante tanca ella mateixa el login dels subsites.
	 *
	 * La 3.1.0 porta `Vigilante_Login_Security::subsite_login_closed( $site_id = 0 )`,
	 * estàtic i booleà (noms confirmats per en Fernando Tellado per correu el
	 * 2026-11-19). El tancament natiu no espera la migració de la configuració:
	 * només depèn que la xarxa sigui per subdirectori, del slug del principal i de
	 * l'interruptor de `wp-config.php` de Vigilante, i tot això existeix abans de
	 * migrar. Per això aquí només es mira que el mètode existeixi, no què torna.
	 *
	 * @return bool
	 */
	public static function native_login_close_available() {
		return class_exists( 'Vigilante_Login_Security' )
			&& is_callable( array( 'Vigilante_Login_Security', 'subsite_login_closed' ) );
	}

	/**
	 * Indica si Vigilante tanca el login d'un subsite (3.1.0+).
	 *
	 * @param int $site_id ID del site; 0 és el site actual.
	 * @return bool|null Null si aquesta versió de Vigilante no té el tancament natiu.
	 */
	public static function subsite_login_closed( $site_id = 0 ) {
		if ( ! self::native_login_close_available() ) {
			return null;
		}

		return (bool) Vigilante_Login_Security::subsite_login_closed( absint( $site_id ) );
	}

	/**
	 * Estat del tancament natiu del login, subsite per subsite (3.1.0+).
	 *
	 * Vigilante contesta site a site (en Fernando Tellado, 2026-11-20): tanca un
	 * subsite amb el mateix domini que el principal, però no un amb domini
	 * mapejat, ni cap en una xarxa de subdominis. Per això es pregunta a tots, no
	 * a un de sol. El principal de la xarxa no es tanca mai i no hi compta.
	 *
	 * ⚠️ Recorre tots els sites. Només es crida des de la pàgina d'ajustos.
	 *
	 * @return array{total:int,open:array<int,string>}|null Null sense tancament natiu;
	 *         `open`: ID del site => etiqueta (domini + ruta) dels que queden oberts.
	 */
	public static function native_login_status() {
		if ( ! self::native_login_close_available() ) {
			return null;
		}

		$sites = get_sites(
			array(
				'number'       => 0,
				'site__not_in' => array( get_main_site_id() ),
				'archived'     => 0,
				'deleted'      => 0,
				'spam'         => 0,
			)
		);

		$open = array();
		foreach ( $sites as $site ) {
			if ( ! self::subsite_login_closed( (int) $site->blog_id ) ) {
				$open[ (int) $site->blog_id ] = untrailingslashit( $site->domain . $site->path );
			}
		}

		return array(
			'total' => count( $sites ),
			'open'  => $open,
		);
	}

	/**
	 * Indica si el bloqueig de login d'aquest plugin s'ha d'apartar.
	 *
	 * Ens apartem quan la xarxa està migrada a la configuració de xarxa de la
	 * 3.1.0 o quan Vigilante ja té el tancament natiu del login dels subsites,
	 * el que passi primer (el tancament natiu existeix des que s'actualitza, abans
	 * de migrar). N'hi ha prou que el mètode EXISTEIXI, encara que torni false:
	 * l'interruptor de `wp-config.php` de Vigilante ha de ser l'únic control, i no
	 * hem de tornar a tancar el que l'administrador ha volgut obrir. Així tampoc
	 * queda cap estona amb el login dels subsites obert ni tancat dues vegades.
	 *
	 * @return bool
	 */
	public static function vigilante_handles_network() {
		return self::network_config_active() || self::native_login_close_available();
	}

	/**
	 * Primera versió de Vigilante que avisa de les línies que descarta en desar.
	 *
	 * Des de la 3.0.12, `Vigilante_Settings::validate_options()` dispara
	 * `vigilante_firewall_lines_dropped` amb les línies de la llista negra que no
	 * desa (petició nostra, B130 al seu changelog).
	 */
	const DROPPED_LINES_ACTION_SINCE = '3.0.12';

	/**
	 * Indica si la versió de Vigilante carregada dispara `vigilante_firewall_lines_dropped`.
	 *
	 * Es mira la versió i no `has_action()`: l'acció la dispara Vigilante, no l'escolta,
	 * així que no hi ha cap registre que la delati abans de desar.
	 *
	 * @param string|null $version Versió a avaluar; per defecte, la carregada.
	 * @return bool
	 */
	public static function vigilante_reports_dropped_lines( $version = null ) {
		$version = null === $version ? self::vigilante_version() : (string) $version;

		return '' !== $version && version_compare( $version, self::DROPPED_LINES_ACTION_SINCE, '>=' );
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
	 * Indica si una config de Vigilante té el login ocult actiu.
	 *
	 * Helper PUR (testejable amb stubs). Mateix criteri amb què Vigilante decideix
	 * si en aquell site registra el login ocult (`init_custom_login()`) i, des de la
	 * 3.0.9, si hi captura la còpia de la pàgina 404 del tema: mòdul
	 * `login_security` encès i `custom_login_url` no buit.
	 *
	 * @param mixed $config Config de Vigilante d'un site (`vigilante_options`).
	 * @return bool
	 */
	public static function config_has_custom_login( $config ) {
		if ( ! is_array( $config ) || empty( $config['modules']['login_security'] ) ) {
			return false;
		}

		$slug = isset( $config['login_security']['custom_login_url'] ) ? $config['login_security']['custom_login_url'] : '';

		return is_string( $slug ) && '' !== trim( $slug );
	}

	/**
	 * Subsites sense el login ocult de Vigilante actiu.
	 *
	 * Amb el bloqueig de login als subsites, cada subsite ha de tenir el login
	 * ocult de Vigilante (el mateix slug que el principal, que hi porta la
	 * sincronització): és el que fa que Vigilante hi capturi la còpia de la pàgina
	 * 404 del tema i que hi respongui 404 a wp-admin i als atalls `/login` i
	 * `/wp-login.php`, que el nostre bloqueig no toca. Sense el slug, el bloqueig
	 * serveix la pàgina plana (es distingeix d'una URL inexistent) i `wp-admin`
	 * redirigeix a wp-login.php en lloc de donar 404.
	 *
	 * Un site sense config de Vigilante hi compta: Vigilante hi fa servir els
	 * defaults, que tenen el slug buit.
	 *
	 * ⚠️ Recorre tots els sites. Només es crida des de la pàgina d'ajustos.
	 *
	 * @return array<int,string> ID del site => etiqueta (domini + ruta).
	 */
	public static function sites_without_custom_login() {
		if ( ! self::is_vigilante_active() ) {
			return array();
		}

		$source_id     = Vigsync_Settings::get_source_site_id();
		$source_domain = self::source_domain();
		$missing       = array();

		$sites = get_sites(
			array(
				'number'   => 0,
				'archived' => 0,
				'deleted'  => 0,
				'spam'     => 0,
			)
		);

		foreach ( $sites as $site ) {
			$site_id = (int) $site->blog_id;

			if ( $site_id === $source_id ) {
				continue;
			}

			// En un site amb domini propi el bloqueig no actua (vegeu
			// site_shares_source_domain()): no cal avisar-ne.
			if ( ! self::same_domain( $site->domain, $source_domain ) ) {
				continue;
			}

			if ( ! self::config_has_custom_login( self::get_site_config( $site_id ) ) ) {
				$missing[ $site_id ] = untrailingslashit( $site->domain . $site->path );
			}
		}

		return $missing;
	}

	/**
	 * Indica si dos dominis de `wp_blogs` són el mateix (sense distingir majúscules).
	 *
	 * Helper PUR, testejable sense WordPress.
	 *
	 * @param string $a Domini d'un site.
	 * @param string $b Domini d'un altre site.
	 * @return bool
	 */
	public static function same_domain( $a, $b ) {
		$a = strtolower( trim( (string) $a ) );
		$b = strtolower( trim( (string) $b ) );

		return '' !== $a && $a === $b;
	}

	/**
	 * Domini del site principal (font), o cadena buida si no es troba.
	 *
	 * @return string
	 */
	private static function source_domain() {
		$source = get_site( Vigsync_Settings::get_source_site_id() );

		return $source ? (string) $source->domain : '';
	}

	/**
	 * Indica si un site comparteix domini amb el principal (font).
	 *
	 * En una xarxa per subdirectori, un site amb un domini mapejat no comparteix
	 * la cookie d'autenticació amb el domini del principal: iniciar sessió al
	 * principal no l'autentica, així que bloquejar-ne el login deixaria els seus
	 * administradors sense cap manera d'entrar al seu wp-admin. Fail-open: si no
	 * es pot llegir algun dels dos sites, es considera que NO el comparteix.
	 *
	 * @param int $site_id ID del site.
	 * @return bool
	 */
	public static function site_shares_source_domain( $site_id ) {
		$site = get_site( absint( $site_id ) );

		return $site ? self::same_domain( $site->domain, self::source_domain() ) : false;
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

	/**
	 * Indica si aquesta versió de Vigilante decideix el 2FA per a tota la xarxa.
	 *
	 * Fins a la 2.11.9, els ajustos de segon factor, els rols forçats i la matrícula
	 * es llegien del site on arribava el login, mentre que la cookie que emet
	 * WordPress val a tota la xarxa. Des de la 2.11.10 la política és la UNIÓ del
	 * que demana cada site: n'hi ha prou que un site la demani perquè el compte
	 * l'hagi de passar, arribi el login per on arribi.
	 *
	 * Això canvia el significat de preservar `login_security.two_factor` per site.
	 * Abans era prudència sense efectes (cada site decidia el seu). Ara un
	 * `two_factor.enabled` residual a QUALSEVOL subsite imposa segon factor a
	 * comptes de tota la xarxa, sense que cap pantalla ho expliqui.
	 *
	 * Es detecta pel mètode que implementa la unió, no per número de versió: si el
	 * disseny torna enrere, la comprovació torna enrere amb ell.
	 *
	 * @return bool
	 */
	public static function two_factor_is_network_wide() {
		return class_exists( 'Vigilante_Settings' )
			&& method_exists( 'Vigilante_Settings', 'two_factor_required_for' );
	}

	/**
	 * Normalitza el que un site DEMANA com a segon factor, per poder comparar-ho.
	 *
	 * Replica el criteri de `Vigilante_Settings::two_factor_site_requires()`: un
	 * site amb el mòdul `login_security` apagat no demana res (encara que li quedi
	 * un `two_factor.enabled` orfe), i un amb el mòdul encès però el segon factor
	 * desactivat, tampoc. Les llistes s'ordenen perquè dos sites amb els mateixos
	 * rols en un altre ordre no comptin com a divergents.
	 *
	 * @param array $config Config de Vigilante d'un site.
	 * @return array Descripció comparable de la demanda d'aquell site.
	 */
	private static function two_factor_demand_of( $config ) {
		if ( ! is_array( $config ) || empty( $config['modules']['login_security'] ) ) {
			return array( 'enabled' => false );
		}

		$two_factor = ( isset( $config['login_security']['two_factor'] ) && is_array( $config['login_security']['two_factor'] ) )
			? $config['login_security']['two_factor']
			: array();

		if ( empty( $two_factor['enabled'] ) ) {
			return array( 'enabled' => false );
		}

		// El mateix valor per defecte que fa servir Vigilante quan no hi ha rols desats.
		$roles = isset( $two_factor['enforced_roles'] )
			? array_map( 'strval', (array) $two_factor['enforced_roles'] )
			: array( 'administrator', 'editor' );

		// Vigilante 3.0.0 escrivia un "0" literal a la llista quan es desmarcava la
		// primera casella del grup (corregit a la 3.0.1, però el que ja és desat no
		// es neteja). No és cap rol, així que no demana res a ningú.
		$roles = array_values( array_diff( $roles, array( '', '0' ) ) );

		// Sense cap rol real, Vigilante no demana segon factor a ningú
		// (`array_intersect()` buit), per molt que `enabled` sigui cert. Des de la
		// 3.0.1 és un estat que es pot desar de debò desmarcant tots els rols.
		if ( ! $roles ) {
			return array( 'enabled' => false );
		}
		sort( $roles );

		$excluded = isset( $two_factor['excluded_users'] )
			? array_filter( array_map( 'absint', (array) $two_factor['excluded_users'] ) )
			: array();
		sort( $excluded );

		$method = isset( $two_factor['method'] ) ? (string) $two_factor['method'] : 'email';

		return array(
			'enabled'  => true,
			// Vigilante llegeix com a 'email' qualsevol mètode que no conegui.
			'method'   => in_array( $method, array( 'email', 'totp' ), true ) ? $method : 'email',
			'roles'    => array_values( $roles ),
			'excluded' => array_values( $excluded ),
		);
	}

	/**
	 * Sites de la xarxa que demanen un segon factor diferent del site principal.
	 *
	 * Només té sentit amb una versió de Vigilante que decideixi el 2FA per xarxa
	 * (vegeu `two_factor_is_network_wide()`); amb versions anteriors retorna una
	 * llista buida perquè la divergència no tenia conseqüències.
	 *
	 * ⚠️ Recorre tots els sites amb `switch_to_blog()`. Es crida només des de la
	 * pàgina d'ajustos del plugin, que és on es pot fer alguna cosa al respecte,
	 * mai des d'un `admin_notices` global.
	 *
	 * @return array<int,string> ID del site => etiqueta (domini + ruta).
	 */
	public static function sites_with_divergent_two_factor() {
		if ( ! self::two_factor_is_network_wide() || ! self::is_vigilante_active() ) {
			return array();
		}

		$source_id = Vigsync_Settings::get_source_site_id();
		$source    = self::get_site_config( $source_id );

		if ( ! is_array( $source ) ) {
			return array();
		}

		$reference = self::two_factor_demand_of( $source );
		$divergent = array();

		$sites = get_sites(
			array(
				'number'   => 0,
				'archived' => 0,
				'deleted'  => 0,
				'spam'     => 0,
			)
		);

		foreach ( $sites as $site ) {
			$site_id = (int) $site->blog_id;

			if ( $site_id === $source_id ) {
				continue;
			}

			$config = self::get_site_config( $site_id );

			// Un site sense config de Vigilante no demana res i tampoc no en desa:
			// no divergeix, s'hi sincronitzarà quan toqui.
			if ( ! is_array( $config ) ) {
				continue;
			}

			if ( self::two_factor_demand_of( $config ) !== $reference ) {
				$divergent[ $site_id ] = untrailingslashit( $site->domain . $site->path );
			}
		}

		return $divergent;
	}

	/**
	 * Aplica al payload el mateix sanejat que Vigilante li aplicarà en desar-lo.
	 *
	 * `Vigilante_Settings::validate_options()` no és només el validador del seu
	 * formulari: `register_setting()` l'enganxa com a `sanitize_callback` de
	 * `vigilante_options`, així que TOT `update_option()` d'aquesta opció hi passa,
	 * inclòs el nostre. El que es desa mai és el payload que hi enviem, sinó la seva
	 * versió sanejada.
	 *
	 * Des de Vigilante 2.11.0 això importa de debò: el validador va deixar de
	 * conservar les claus que el seu esquema no declara (abans les desava
	 * "sanejades"). Si la configuració del principal en porta cap —residu d'una
	 * versió antiga, o d'una importació—, el payload i el que queda desat difereixen
	 * sempre, i `update_option()` retorna false en cada sincronització perquè el
	 * valor desat ja és el definitiu. Sense aquest mètode no podem distingir aquell
	 * false d'un error d'escriptura real.
	 *
	 * S'instancia la classe de Vigilante a posta en comptes de replicar-ne el
	 * criteri: així el dia que el seu esquema canviï, aquest mètode canvia amb ell.
	 *
	 * @param array $config Payload a desar.
	 * @return array|null El payload tal com quedarà desat, o null si aquesta versió
	 *                    de Vigilante no ofereix el validador.
	 */
	public static function sanitize_like_vigilante( $config ) {
		if ( ! class_exists( 'Vigilante_Settings' ) || ! method_exists( 'Vigilante_Settings', 'validate_options' ) ) {
			return null;
		}

		try {
			$settings  = new Vigilante_Settings();
			$sanitized = $settings->validate_options( $config );
		} catch ( Throwable $e ) {
			return null;
		}

		return is_array( $sanitized ) ? $sanitized : null;
	}
}
