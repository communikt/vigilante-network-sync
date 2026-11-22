<?php
/**
 * Motor de sincronització de la configuració de Vigilante entre sites.
 *
 * Llegeix la config del site principal i la replica a la resta de sites de la
 * xarxa, generant un log de resultats per-site.
 *
 * @package Vigilante_Network_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Vigsync_Sync
 */
class Vigsync_Sync {

	/**
	 * Camps específics de site que NO es copien per defecte.
	 *
	 * Es preserva el valor existent al site destí per a aquests camps, tret que
	 * l'opció corresponent estigui activa (sync_ip_lists per a les llistes d'IPs i
	 * User-Agents, sync_two_factor per a la configuració de 2FA).
	 *
	 * La secció `login_security.two_factor` es preserva perquè els secrets TOTP
	 * viuen en taules per-blog (xifrats amb AUTH_KEY); copiar només el "mètode
	 * activat" sense el secret deixaria l'usuari sense poder validar. Amb mètode
	 * e-mail no hi ha secret, però es preserva igualment per defecte per prudència.
	 *
	 * ⚠️ Des de Vigilante 2.11.10 aquest raonament ja no s'aguanta, i el valor per
	 * defecte es manté només per no canviar el comportament de cap xarxa sense
	 * avisar. D'una banda la matrícula TOTP es busca a la taula de TOTS els sites
	 * que el compte pot arribar, així que copiar la configuració ja no deixa ningú
	 * sense poder validar. De l'altra, la política de segon factor va passar a ser
	 * la UNIÓ del que demana cada site de la xarxa (la cookie que emet WordPress
	 * val a tota la xarxa, i llegir-la per site era un bypass): ara un
	 * `two_factor.enabled` residual a qualsevol subsite imposa segon factor a
	 * comptes de tota la instal·lació. Per això la pàgina d'ajustos detecta la
	 * divergència (`Vigsync_Detector::sites_with_divergent_two_factor()`) i
	 * recomana marcar la casella quan Vigilante decideix el 2FA per xarxa.
	 *
	 * `email.additional_recipients` es preserva sempre: qui ha de rebre els avisos
	 * d'un site és una decisió d'aquell site (sovint el client), no del principal.
	 *
	 * Estructura: secció => llista de claus.
	 *
	 * @var array
	 */
	const SITE_SPECIFIC_FIELDS = array(
		'firewall'       => array( 'ip_whitelist', 'ip_blacklist', 'ua_whitelist', 'ua_blacklist' ),
		'login_security' => array( 'ip_whitelist', 'two_factor' ),
		'activity_log'   => array( 'excluded_ips' ),
		'email'          => array( 'additional_recipients' ),
	);

	/**
	 * Camps que Vigilante marca com a "dades de l'usuari" però que SÍ volem propagar.
	 *
	 * `Vigsync_Detector::get_user_data_keys()` (Vigilante 2.9.8+) serveix de xarxa de
	 * seguretat: tot camp que Vigilante hi declari i que nosaltres no coneguem es
	 * preserva del destí. Aquesta llista són les excepcions conscients, camps que en
	 * una xarxa multisite EN SUBDIRECTORI són uniformes per definició i que, si no es
	 * propaguen, obliguen a configurar-los site per site:
	 *
	 * - `firewall.trusted_proxy_header`: depèn del hosting/CDN, idèntic a tota la xarxa.
	 * - `firewall.trusted_proxies`: la parella de l'anterior, vegeu la nota de sota.
	 * - `user_security.insecure_usernames`: llista de noms prohibits, política global.
	 * - `file_integrity.*`: l'escaneig recorre el MATEIX sistema de fitxers a tots els
	 *   sites de la xarxa, així que les exclusions han de ser necessàriament iguals.
	 *
	 * ⚠️ `firewall.trusted_proxies` (Vigilante 2.11.9+) va entrar a
	 * `get_user_data_keys()` i, per tant, la xarxa de seguretat el preservava del
	 * destí mentre `trusted_proxy_header` SÍ es copiava. Aquella meitat sola no
	 * serveix de res: des de la 2.11.9 Vigilante només fa cas de la capçalera
	 * reenviada si la connexió ve d'un peer que pugui verificar com a proxy
	 * (`Vigilante_IP_Utils::peer_is_trusted_proxy()`), i amb la llista buida això
	 * es redueix a «¿és una adreça de la meva pròpia xarxa privada?» (o els rangs
	 * de Cloudflare, per a `CF-Connecting-IP`). Darrere d'un proxy públic que no
	 * sigui Cloudflare, el subsite ignorava la capçalera i veia TOTS els visitants
	 * amb l'adreça del proxy: les llistes blanca i negra no coincidien mai, i el
	 * rate limiting i el bloqueig de login s'acumulaven sobre una sola adreça.
	 * Les dues claus descriuen la mateixa realitat (el hosting/CDN que hi ha
	 * davant), que és única per a tota la instal·lació, així que viatgen juntes.
	 *
	 * La llista de Vigilante respon a una pregunta diferent de la nostra ("què no ha
	 * d'esborrar un botó de restauració" vs. "què és propi de cada site d'una xarxa"),
	 * per això no s'adopta tal qual.
	 *
	 * Vigilante 2.9.9 va retirar de l'esquema `firewall.country_blocking` i
	 * `file_integrity.suspicious_patterns` (eren ajustos que cap codi llegia) i els va
	 * treure també de `get_user_data_keys()`. Com que aquesta llista només es consulta
	 * contra el que retorna aquell mètode, mantenir-los aquí era codi mort: s'han
	 * eliminat perquè la llista no descrigui un esquema que ja no existeix.
	 *
	 * Estructura: secció => llista de claus.
	 *
	 * @var array
	 */
	const NETWORK_UNIFORM_FIELDS = array(
		'firewall'       => array( 'trusted_proxy_header', 'trusted_proxies' ),
		'user_security'  => array( 'insecure_usernames' ),
		'file_integrity' => array( 'excluded_paths', 'excluded_extensions' ),
	);

	/**
	 * Executa la sincronització.
	 *
	 * @return array {
	 *     Resultat global.
	 *
	 *     @type bool   $success Si la sincronització s'ha pogut iniciar.
	 *     @type string $message Missatge global (p. ex. motiu d'avortament).
	 *     @type array  $log     Llista de resultats per-site.
	 * }
	 */
	public static function run() {
		// 1. Vigilante ha d'estar actiu.
		if ( ! Vigsync_Detector::is_vigilante_active() ) {
			return self::abort( __( 'Vigilante no està actiu en aquesta xarxa. No s\'ha sincronitzat res.', 'vigilante-network-sync' ) );
		}

		// 2. Llegeix la config del site principal.
		$source_id     = Vigsync_Settings::get_source_site_id();
		$source_config = Vigsync_Detector::get_site_config( $source_id );

		$sync_ip_lists  = (bool) Vigsync_Settings::get( 'sync_ip_lists', false );
		// Amb el tancament natiu de Vigilante 3.1.0 el nostre bloqueig s'aparta i
		// el slug ja no ha de viatjar per força: decideix la casella.
		$sync_login     = self::login_slug_travels(
			(bool) Vigsync_Settings::get( 'sync_custom_login', true ),
			(bool) Vigsync_Settings::get( 'login_block_enabled', false ) && ! Vigsync_Detector::native_login_close_available()
		);
		$sync_two_factor = (bool) Vigsync_Settings::get( 'sync_two_factor', false );

		// Vigilante 3.1.0+ en una xarxa migrada ja aplica una sola configuració a
		// tota la xarxa: copiar-la als subsites no tindria cap efecte. L'únic que
		// queda per site i que val la pena copiar són les llistes d'IPs i
		// User-Agents, i només si la casella és marcada.
		$lists_only = Vigsync_Detector::network_config_active();
		if ( $lists_only && ! $sync_ip_lists ) {
			return self::abort( __( 'Vigilante ja gestiona la configuració de tota la xarxa des de l\'administració de xarxa: no hi ha res a sincronitzar. Aquest plugin només pot copiar-hi les llistes d\'IPs i User-Agents del principal, si marques la casella corresponent.', 'vigilante-network-sync' ) );
		}

		// 3. Valida l'esquema abans d'escriure res. En una xarxa migrada no es
		// demana l'esquema sencer: el `vigilante_options` del principal pot portar
		// només les claus pròpies del site (els mòduls i la resta viuen a l'opció de
		// xarxa), i de la font només es llegeixen les llistes. N'hi ha prou que sigui
		// un array; si no hi ha cap llista, cada destí queda «sense canvis».
		$valid = $lists_only
			? ( is_array( $source_config ) ? true : Vigsync_Detector::validate_schema( $source_config ) )
			: Vigsync_Detector::validate_schema( $source_config );
		if ( is_wp_error( $valid ) ) {
			return self::abort( $valid->get_error_message() );
		}

		$log   = array();
		$sites = get_sites(
			array(
				'number'   => 0,
				'archived' => 0,
				'deleted'  => 0,
				'spam'     => 0,
			)
		);

		foreach ( $sites as $site ) {
			$target_id = (int) $site->blog_id;

			// El site principal és la font: no se sobreescriu a si mateix.
			if ( $target_id === $source_id ) {
				continue;
			}

			$log[] = self::sync_one_site( $target_id, $source_config, $sync_ip_lists, $sync_login, $sync_two_factor, $lists_only );
		}

		// Desa un resum del darrer sync.
		$summary = self::summarize( $log, $source_id );
		Vigsync_Settings::update( array( 'last_sync' => $summary ) );

		return array(
			'success' => true,
			'message' => sprintf(
				/* translators: 1: nombre de sites correctes, 2: nombre total de sites destí */
				__( 'Sincronització completada: %1$d de %2$d sites actualitzats correctament.', 'vigilante-network-sync' ),
				$summary['ok'],
				$summary['total']
			),
			'log'     => $log,
		);
	}

	/**
	 * Sincronitza un únic site destí.
	 *
	 * @param int   $target_id      ID del site destí.
	 * @param array $source_config  Config completa del site principal.
	 * @param bool  $sync_ip_lists  Si s'han de copiar també les llistes d'IPs.
	 * @param bool  $sync_login     Si s'ha de copiar el slug de custom-login.
	 * @param bool  $sync_two_factor Si s'ha de copiar la configuració de 2FA.
	 * @param bool  $lists_only      Xarxa migrada a Vigilante 3.1.0+: només es copien les llistes.
	 * @return array Entrada de log d'aquest site.
	 */
	private static function sync_one_site( $target_id, $source_config, $sync_ip_lists, $sync_login, $sync_two_factor, $lists_only = false ) {
		$site   = get_site( $target_id );
		$label  = $site ? untrailingslashit( $site->domain . $site->path ) : (string) $target_id;
		$result = array(
			'site_id' => $target_id,
			'label'   => $label,
			'status'  => 'error',
			'message' => '',
		);

		switch_to_blog( $target_id );
		try {
			$existing = get_option( Vigsync_Detector::VIGILANTE_OPTION, array() );
			if ( ! is_array( $existing ) ) {
				$existing = array();
			}

			$payload = $lists_only
				? self::build_lists_payload( $source_config, $existing, Vigsync_Detector::get_site_keys(), Vigsync_Detector::login_whitelist_from_main() )
				: self::build_payload( $source_config, $existing, $sync_ip_lists, $sync_login, $sync_two_factor );

			// Quan es copien les llistes d'IPs, s'hi apliquen les mateixes regles de
			// validesa que Vigilante 2.9.9+ aplica al seu formulari.
			$rejected_ips = $sync_ip_lists ? self::strip_unmatchable_ips( $payload ) : array();

			// Vigilante 3.0.11+ no desa les línies noves de la llista negra que
			// bloquejarien persones o el mateix site. Des de la 3.0.12 ho diu ell
			// mateix amb una acció durant l'escriptura; a la 3.0.11 cal simular-ho
			// abans. Sense la casella, les llistes són les del destí, ja desades, i
			// no n'hi pot descartar cap.
			$reports_dropped = Vigsync_Detector::vigilante_reports_dropped_lines();
			$not_stored      = ( $sync_ip_lists && ! $reports_dropped ) ? self::lines_vigilante_will_drop( $payload ) : array();

			// Si el contingut és idèntic, update_option retorna false; ho tractem com "omès".
			if ( $payload === $existing ) {
				$result['status']  = 'skipped';
				$result['message'] = __( 'Ja estava sincronitzat (sense canvis).', 'vigilante-network-sync' );
			} else {
				$write = self::write_options( $payload );
				$saved = $write['saved'];
				if ( $reports_dropped ) {
					$not_stored = self::format_dropped_lines( $write['dropped'] );
				}

				if ( $saved ) {
					// Força la regeneració de regles de login al destí.
					delete_option( 'vigilante_login_rules_version' );
					$result['status']  = 'ok';
					$result['message'] = __( 'Configuració sincronitzada.', 'vigilante-network-sync' );

					if ( $rejected_ips ) {
						$result['message'] .= ' ' . sprintf(
							/* translators: %s: llista d'entrades d'IP descartades, separades per comes */
							__( 'Entrades d\'IP descartades perquè no poden coincidir mai: %s.', 'vigilante-network-sync' ),
							implode( ', ', $rejected_ips )
						);
					}
					$result['message'] .= self::not_stored_note( $not_stored );
				} elseif ( self::already_stored( $payload, $existing, $write['stored'] ) ) {
					$result['status']  = 'skipped';
					$result['message'] = __( 'Ja estava sincronitzat: el validador de Vigilante adapta el payload en desar-lo, i el resultat ja era el que hi havia al destí.', 'vigilante-network-sync' )
						. self::not_stored_note( $not_stored );
				} else {
					$result['status']  = 'error';
					$result['message'] = __( 'update_option ha retornat false (possible error d\'escriptura).', 'vigilante-network-sync' );
				}
			}
		} catch ( Exception $e ) {
			$result['status']  = 'error';
			$result['message'] = $e->getMessage();
		} finally {
			restore_current_blog();
		}

		return $result;
	}

	/**
	 * Indica si el destí ja conté exactament el que Vigilante desaria d'aquest payload.
	 *
	 * `update_option()` retorna false en dos casos que no s'assemblen gens: quan
	 * l'escriptura falla i quan el valor desat ja és idèntic al que s'hi escriu.
	 * Fins ara donàvem per fet el primer, i això era prou bo mentre el payload i el
	 * que quedava desat coincidien.
	 *
	 * Des de Vigilante 2.11.0 ja no coincideixen sempre. El seu
	 * `validate_options()` és el `sanitize_callback` de `vigilante_options`, així
	 * que sanejar el payload no és opcional: hi passa tot `update_option()`. I des
	 * d'aquella versió el sanejat DESCARTA les claus que el seu esquema no declara,
	 * en comptes de conservar-les. Si la configuració del principal en porta cap
	 * —residu d'una versió antiga, o d'una importació—, cada sincronització
	 * enviava un payload que no es pot desar tal qual, rebia false i escrivia
	 * "possible error d'escriptura" a TOTS els subsites, per sempre, tot i que la
	 * configuració hi era correcta. Un log que crida a l'error quan no n'hi ha cap
	 * és pitjor que no tenir-ne: en una xarxa és l'única prova que el sync funciona.
	 *
	 * Des de la 2.0.15 es compara el valor que `update_option()` mateix ha comparat
	 * (capturat per `write_options()` després del sanejat), sense tornar a cridar el
	 * validador: des de Vigilante 3.0.12 cada crida dispara
	 * `vigilante_firewall_lines_dropped`, i qui l'escolti l'ha de rebre un cop per
	 * escriptura. Si no hi ha valor capturat, es refà el sanejat amb el validador
	 * de Vigilante com abans. Si coincideixen, l'escriptura no tenia res a fer i el
	 * destí està sincronitzat. Si no, el false és un error de veritat i es reporta.
	 *
	 * Sense valor capturat ni validador disponible (Vigilante molt antic) es
	 * retorna false: val més mantenir l'avís que empassar-se un error real.
	 *
	 * @param array      $payload  Payload que s'ha intentat desar.
	 * @param array      $existing Configuració que hi havia al destí abans d'escriure.
	 * @param array|null $stored   Valor que `update_option()` ha intentat desar, o null.
	 * @return bool
	 */
	private static function already_stored( $payload, $existing, $stored = null ) {
		$sanitized = is_array( $stored ) ? $stored : Vigsync_Detector::sanitize_like_vigilante( $payload );

		if ( null === $sanitized ) {
			return false;
		}

		return self::same_option_value( $sanitized, $existing );
	}

	/**
	 * Compara dos valors d'opció com ho fa `update_option()`.
	 *
	 * El core dona l'escriptura per innecessària si els valors són idèntics o si
	 * serialitzen igual (`wp-includes/option.php`). Lògica pura.
	 *
	 * @param mixed $a Un valor.
	 * @param mixed $b L'altre.
	 * @return bool
	 */
	public static function same_option_value( $a, $b ) {
		if ( $a === $b ) {
			return true;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Mateixa comparació que maybe_serialize() fa per a arrays a update_option().
		return is_array( $a ) && is_array( $b ) && serialize( $a ) === serialize( $b );
	}

	/**
	 * Línies descartades per Vigilante durant l'escriptura en curs.
	 *
	 * @var array
	 */
	private static $captured_dropped = array();

	/**
	 * Valor que `update_option()` ha intentat desar, ja sanejat per Vigilante.
	 *
	 * @var array|null
	 */
	private static $captured_value = null;

	/**
	 * Desa la configuració al site actual i recull què n'ha fet Vigilante.
	 *
	 * Durant el `update_option()`, i només durant aquest, s'escolten dues coses:
	 *
	 * - `vigilante_firewall_lines_dropped` (Vigilante 3.0.12+): les línies de la
	 *   llista negra que el validador no desa, amb el veredicte i els navegadors
	 *   que bloquejarien. Abans les calculàvem cridant el validador a mà, cosa
	 *   que ara faria saltar l'acció dos o tres cops de més per subsite.
	 * - `pre_update_option_vigilante_options` a la prioritat més tardana: el
	 *   valor ja sanejat que el core compara amb el desat. Serveix per distingir
	 *   el false de «no hi havia res a fer» del false d'error sense tornar a
	 *   sanejar.
	 *
	 * Els dos ganxos es treuen sempre (finally), també si l'escriptura peta.
	 *
	 * @param array $payload Payload a desar.
	 * @return array {
	 *     @type bool       $saved   Retorn de `update_option()`.
	 *     @type array|null $stored  Valor sanejat que s'ha intentat desar, o null.
	 *     @type array      $dropped Línies descartades, per clau de llista.
	 * }
	 */
	private static function write_options( $payload ) {
		$option = Vigsync_Detector::VIGILANTE_OPTION;

		self::$captured_dropped = array();
		self::$captured_value   = null;

		add_action( 'vigilante_firewall_lines_dropped', array( __CLASS__, 'capture_dropped_lines' ), 10, 1 );
		add_filter( 'pre_update_option_' . $option, array( __CLASS__, 'capture_value_to_store' ), PHP_INT_MAX, 1 );

		try {
			$saved = update_option( $option, $payload );
		} finally {
			remove_action( 'vigilante_firewall_lines_dropped', array( __CLASS__, 'capture_dropped_lines' ), 10 );
			remove_filter( 'pre_update_option_' . $option, array( __CLASS__, 'capture_value_to_store' ), PHP_INT_MAX );
		}

		return array(
			'saved'   => (bool) $saved,
			'stored'  => self::$captured_value,
			'dropped' => self::$captured_dropped,
		);
	}

	/**
	 * Callback de `vigilante_firewall_lines_dropped`: acumula les línies descartades.
	 *
	 * Vigilante la dispara un cop per escriptura, però s'acumula per si mai
	 * arribés dos cops (p. ex. un altre `update_option()` niat).
	 *
	 * @param mixed $dropped Claus `ua_blacklist` i/o `ip_blacklist` amb les línies.
	 * @return void
	 */
	public static function capture_dropped_lines( $dropped ) {
		if ( ! is_array( $dropped ) ) {
			return;
		}

		foreach ( $dropped as $key => $lines ) {
			if ( ! is_string( $key ) || ! is_array( $lines ) ) {
				continue;
			}
			if ( ! isset( self::$captured_dropped[ $key ] ) ) {
				self::$captured_dropped[ $key ] = array();
			}
			foreach ( $lines as $line ) {
				self::$captured_dropped[ $key ][] = $line;
			}
		}
	}

	/**
	 * Callback de `pre_update_option_vigilante_options`: guarda el valor sense tocar-lo.
	 *
	 * @param mixed $value Valor sanejat que el core desarà.
	 * @return mixed El mateix valor.
	 */
	public static function capture_value_to_store( $value ) {
		self::$captured_value = is_array( $value ) ? $value : null;

		return $value;
	}

	/**
	 * Converteix el que passa `vigilante_firewall_lines_dropped` en línies del log.
	 *
	 * Lògica pura. Mateix format que `lines_not_stored()` («secció.clau: línia»),
	 * més el motiu quan Vigilante el dona: els navegadors que bloquejaria, el
	 * navegador de qui sincronitza o el mateix WordPress. Les adreces d'IP són
	 * sempre del mateix site.
	 *
	 * @param array $dropped {
	 *     @type array[]  $ua_blacklist Entrades amb `line`, `verdict` i `labels`.
	 *     @type string[] $ip_blacklist Adreces o rangs.
	 * }
	 * @return string[] Línies etiquetades, sense repetir.
	 */
	public static function format_dropped_lines( $dropped ) {
		$out = array();

		if ( ! is_array( $dropped ) ) {
			return $out;
		}

		if ( isset( $dropped['ua_blacklist'] ) && is_array( $dropped['ua_blacklist'] ) ) {
			foreach ( $dropped['ua_blacklist'] as $entry ) {
				$line   = '';
				$reason = '';

				if ( is_array( $entry ) ) {
					$line   = isset( $entry['line'] ) && is_scalar( $entry['line'] ) ? trim( (string) $entry['line'] ) : '';
					$labels = array();
					if ( isset( $entry['labels'] ) && is_array( $entry['labels'] ) ) {
						foreach ( $entry['labels'] as $label ) {
							if ( is_scalar( $label ) && '' !== trim( (string) $label ) ) {
								$labels[] = trim( (string) $label );
							}
						}
					}
					$reason = self::dropped_reason( isset( $entry['verdict'] ) ? $entry['verdict'] : '', $labels );
				} elseif ( is_scalar( $entry ) ) {
					$line = trim( (string) $entry );
				}

				if ( '' !== $line ) {
					$out[] = 'firewall.ua_blacklist: ' . $line . ( '' !== $reason ? ' (' . $reason . ')' : '' );
				}
			}
		}

		if ( isset( $dropped['ip_blacklist'] ) && is_array( $dropped['ip_blacklist'] ) ) {
			foreach ( $dropped['ip_blacklist'] as $ip ) {
				if ( is_scalar( $ip ) && '' !== trim( (string) $ip ) ) {
					$out[] = 'firewall.ip_blacklist: ' . trim( (string) $ip ) . ' (' . __( 'adreça del mateix site', 'vigilante-network-sync' ) . ')';
				}
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Motiu llegible del veredicte de Vigilante sobre una línia de User-Agent.
	 *
	 * @param mixed    $verdict own, site o browser.
	 * @param string[] $labels  Navegadors que bloquejaria (només amb browser).
	 * @return string Buit si el veredicte no és cap dels coneguts.
	 */
	private static function dropped_reason( $verdict, $labels ) {
		switch ( $verdict ) {
			case 'browser':
				if ( empty( $labels ) ) {
					return __( 'bloquejaria navegadors normals', 'vigilante-network-sync' );
				}
				/* translators: %s: navegadors que la línia bloquejaria, separats per comes */
				return sprintf( __( 'bloquejaria %s', 'vigilante-network-sync' ), implode( ', ', $labels ) );
			case 'own':
				return __( 'bloquejaria el navegador de qui sincronitza', 'vigilante-network-sync' );
			case 'site':
				return __( 'bloquejaria el mateix WordPress', 'vigilante-network-sync' );
			default:
				return '';
		}
	}

	/**
	 * Llistes que el validador de Vigilante esporga en desar (3.0.11+).
	 *
	 * Des de la 3.0.11, `Vigilante_Settings::validate_options()` treu de la llista
	 * negra de User-Agents les línies que bloquejarien navegadors normals, el
	 * navegador de qui desa o el mateix WordPress, i de la llista negra d'IPs les
	 * adreces del propi site. NOMÉS les que el site no tenia desades: una línia
	 * antiga es conserva. Com que el validador és el `sanitize_callback` de
	 * l'opció i el sync hi passa dins de `switch_to_blog()`, una línia antiga del
	 * principal no arriba a un subsite que no la tingués, i fins ara ho feia en
	 * silenci.
	 *
	 * Estructura: secció => llista de claus.
	 *
	 * @var array
	 */
	const VIGILANTE_FILTERED_LISTS = array(
		'firewall' => array( 'ua_blacklist', 'ip_blacklist' ),
	);

	/**
	 * Línies del payload que Vigilante no desarà en el site actual.
	 *
	 * Es refà el sanejat amb el validador de Vigilante, dins del site destí, i es
	 * compara. El veredicte de Vigilante sobre una línia de User-Agent depèn també
	 * del navegador del superadministrador que prem «Sincronitza ara»: per això es
	 * calcula a cada sincronització i no un cop per sempre.
	 *
	 * Sense validador disponible no es pot saber, i es retorna una llista buida.
	 *
	 * Només per a Vigilante 3.0.11: des de la 3.0.12 la mateixa informació arriba
	 * per `vigilante_firewall_lines_dropped` (vegeu `write_options()`), i cridar
	 * aquí el validador faria saltar l'acció una vegada de més.
	 *
	 * @param array $payload Payload que es desarà.
	 * @return string[] Línies etiquetades amb la seva secció i clau.
	 */
	private static function lines_vigilante_will_drop( $payload ) {
		$sanitized = Vigsync_Detector::sanitize_like_vigilante( $payload );

		return null === $sanitized ? array() : self::lines_not_stored( $payload, $sanitized );
	}

	/**
	 * Compara les llistes esporgades per Vigilante entre el payload i el que es desa.
	 *
	 * Lògica pura. Una línia que arriba al site amb una altra forma (el validador
	 * també la saneja) compta com a no desada tal com venia, que és el que diu la
	 * nota del log.
	 *
	 * @param array $payload   Payload enviat.
	 * @param array $sanitized El mateix payload tal com Vigilante el desa.
	 * @return string[] Línies etiquetades amb la seva secció i clau, sense repetir.
	 */
	public static function lines_not_stored( $payload, $sanitized ) {
		$dropped = array();

		foreach ( self::VIGILANTE_FILTERED_LISTS as $section => $keys ) {
			foreach ( $keys as $key ) {
				if ( ! isset( $payload[ $section ][ $key ] ) || ! is_array( $payload[ $section ][ $key ] ) ) {
					continue;
				}

				$kept = array();
				if ( isset( $sanitized[ $section ][ $key ] ) && is_array( $sanitized[ $section ][ $key ] ) ) {
					foreach ( $sanitized[ $section ][ $key ] as $line ) {
						if ( is_scalar( $line ) ) {
							$kept[] = trim( (string) $line );
						}
					}
				}

				foreach ( $payload[ $section ][ $key ] as $line ) {
					if ( ! is_scalar( $line ) ) {
						continue;
					}
					$line = trim( (string) $line );
					if ( '' === $line || in_array( $line, $kept, true ) ) {
						continue;
					}
					$dropped[] = $section . '.' . $key . ': ' . $line;
				}
			}
		}

		return array_values( array_unique( $dropped ) );
	}

	/**
	 * Nota del log per a les línies que Vigilante no ha desat.
	 *
	 * @param string[] $not_stored Línies etiquetades.
	 * @return string Text precedit d'un espai, o buit si no n'hi ha cap.
	 */
	private static function not_stored_note( $not_stored ) {
		if ( empty( $not_stored ) ) {
			return '';
		}

		return ' ' . sprintf(
			/* translators: %s: línies de la llista negra, separades per comes */
			__( 'Vigilante no ha desat en aquest site aquestes línies de la llista negra perquè bloquejarien persones o el mateix site (al principal es conserven si ja hi eren desades): %s.', 'vigilante-network-sync' ),
			implode( ', ', $not_stored )
		);
	}

	/**
	 * Construeix el payload a desar al site destí.
	 *
	 * Parteix de la config del principal i hi reinjecta el que NO s'ha de copiar:
	 *
	 * 1. **Ajustos de fitxer compartit** (Vigilante 2.9.8+): `security_headers`,
	 *    part de `wp_hardening` i la meitat «.htaccess» de `firewall`. Aquests
	 *    fitxers són únics a la xarxa i només els escriu el site principal, així que
	 *    la còpia local del subsite no s'aplica mai; es preserva la del destí per no
	 *    deixar-hi un valor que contradiu el fitxer real.
	 * 2. **Camps específics de site** (llistes d'IP/UA, 2FA, destinataris d'avisos),
	 *    amb les caselles que permeten copiar-los igualment.
	 * 3. **Camps que Vigilante declara com a dades de l'usuari** i que no coneixem:
	 *    es preserven per prudència (xarxa de seguretat per a versions futures),
	 *    tret dels de `NETWORK_UNIFORM_FIELDS`.
	 *
	 * @param array $source          Config del principal.
	 * @param array $existing        Config actual del destí.
	 * @param bool  $sync_ip_lists   Si es copien les llistes d'IPs i User-Agents.
	 * @param bool  $sync_login      Si es copia el slug de custom-login.
	 * @param bool  $sync_two_factor Si es copia la configuració de 2FA.
	 * @return array
	 */
	private static function build_payload( $source, $existing, $sync_ip_lists, $sync_login, $sync_two_factor ) {
		$payload = $source;

		// 1. Ajustos que només tenen efecte en fitxers compartits per tota la xarxa.
		foreach ( Vigsync_Detector::get_shared_file_settings() as $section => $keys ) {
			if ( true === $keys ) {
				self::preserve_section( $payload, $existing, $section );
				continue;
			}

			foreach ( (array) $keys as $key ) {
				self::preserve_key( $payload, $existing, $section, $key );
			}
		}

		// 2 i 3. Camps preservats per-site (els nostres + els que declari Vigilante).
		foreach ( self::get_preserved_fields( $sync_ip_lists, $sync_login, $sync_two_factor ) as $section => $keys ) {
			foreach ( $keys as $key ) {
				self::preserve_key( $payload, $existing, $section, $key );
			}
		}

		// Amb la casella de llistes, una llista buida del principal no esborra la del
		// destí (2.1.1), igual que en una xarxa migrada (vegeu build_lists_payload()).
		if ( $sync_ip_lists ) {
			foreach ( self::SITE_SPECIFIC_FIELDS as $section => $keys ) {
				foreach ( $keys as $key ) {
					if ( self::is_list_field( $key ) && isset( $source[ $section ] ) && is_array( $source[ $section ] )
						&& array_key_exists( $key, $source[ $section ] ) && self::list_is_empty( $source[ $section ][ $key ] ) ) {
						self::preserve_key( $payload, $existing, $section, $key );
					}
				}
			}
		}

		// El slug de custom-login, quan no es sincronitza, es deixa SEMPRE declarat
		// (cadena buida si el destí no en tenia). Vigilante ja el faria servir buit
		// via els seus defaults, però és el camp del qual depèn l'accés: val més que
		// el valor efectiu quedi escrit i no dependre d'un merge de defaults.
		if ( ! $sync_login && ! isset( $payload['login_security']['custom_login_url'] ) ) {
			$payload['login_security']['custom_login_url'] = '';
		}

		// CSP report-uri és una URL absoluta específica de site: preserva la del destí.
		// (Amb Vigilante 2.9.8+ ja hi arriba preservada tota la secció `security_headers`;
		// això cobreix les versions anteriors, on la secció sí que es copiava.)
		if ( isset( $payload['security_headers']['csp'] ) && is_array( $payload['security_headers']['csp'] ) ) {
			$existing_uri = isset( $existing['security_headers']['csp']['report_uri'] )
				? $existing['security_headers']['csp']['report_uri']
				: '';
			$payload['security_headers']['csp']['report_uri'] = $existing_uri;
		}

		return $payload;
	}

	/**
	 * Llistes que es poden copiar en una xarxa migrada a Vigilante 3.1.0+.
	 *
	 * Estructura: secció => llista de claus.
	 *
	 * @var array
	 */
	const LIST_FIELDS = array(
		'firewall'       => array( 'ip_whitelist', 'ip_blacklist', 'ua_whitelist', 'ua_blacklist' ),
		'login_security' => array( 'ip_whitelist' ),
		'activity_log'   => array( 'excluded_ips' ),
	);

	/**
	 * Construeix el payload d'una xarxa migrada: el destí tal com és, amb les llistes del principal.
	 *
	 * Helper PUR. En una xarxa migrada, Vigilante aplica la configuració de xarxa i
	 * llegeix del `vigilante_options` de cada site només les claus que hi segueixen
	 * per site. D'aquestes, només es copien les llistes d'IPs i User-Agents: el slug
	 * de login, els destinataris d'avisos, la CSP report-uri i les claus d'HTTPS són
	 * decisions de cada site. La resta del destí no es toca, ni tan sols les claus que
	 * ja no s'apliquen, perquè Vigilante les conserva per poder tornar a la 3.0.14.
	 *
	 * Una llista només viatja si Vigilante la declara per site (`$site_keys`): si una
	 * versió futura en passés alguna a la configuració de xarxa, copiar-la no tindria
	 * efecte. Sense la llista de Vigilante (null) es fa servir la nostra. Si el
	 * principal no té una llista, o la té buida, la del destí es manté (2.1.1: fins
	 * aleshores una llista buida del principal esborrava la pròpia del subsite; ho va
	 * veure en Fernando Tellado provant la 3.1.0). Buidar una llista al principal, per
	 * tant, no la buida als subsites: es fa a cada site.
	 *
	 * La llista blanca de login (`login_security.ip_whitelist`) no viatja quan la que
	 * compta a tota la xarxa és la del principal (Vigilante 3.1.0, vegeu
	 * `Vigsync_Detector::login_whitelist_from_main()`): copiar-la no canviaria res.
	 *
	 * @param array      $source                    Config del principal.
	 * @param array      $existing                  Config actual del destí.
	 * @param array|null $site_keys                 Claus per site segons Vigilante, o null.
	 * @param bool       $login_whitelist_from_main Si la llista blanca de login és la del principal.
	 * @return array
	 */
	public static function build_lists_payload( $source, $existing, $site_keys, $login_whitelist_from_main = false ) {
		$payload = $existing;

		foreach ( self::LIST_FIELDS as $section => $keys ) {
			foreach ( $keys as $key ) {
				if ( is_array( $site_keys ) && ! ( isset( $site_keys[ $section ] ) && in_array( $key, (array) $site_keys[ $section ], true ) ) ) {
					continue;
				}
				if ( $login_whitelist_from_main && 'login_security' === $section && 'ip_whitelist' === $key ) {
					continue;
				}
				if ( ! isset( $source[ $section ] ) || ! is_array( $source[ $section ] ) || ! array_key_exists( $key, $source[ $section ] ) ) {
					continue;
				}
				if ( self::list_is_empty( $source[ $section ][ $key ] ) ) {
					continue;
				}
				if ( ! isset( $payload[ $section ] ) || ! is_array( $payload[ $section ] ) ) {
					$payload[ $section ] = array();
				}
				$payload[ $section ][ $key ] = $source[ $section ][ $key ];
			}
		}

		return $payload;
	}

	/**
	 * Indica si una llista de Vigilante és buida (array sense línies o cadena en blanc).
	 *
	 * @param mixed $value Valor de la llista.
	 * @return bool
	 */
	private static function list_is_empty( $value ) {
		if ( is_array( $value ) ) {
			return 0 === count( $value );
		}

		return null === $value || '' === trim( (string) $value );
	}

	/**
	 * Decideix si el slug de custom-login es copia als subsites.
	 *
	 * Helper PUR. La casella «Copiar també el slug de custom-login» decideix, tret
	 * que el bloqueig de login als subsites estigui actiu: aleshores el slug viatja
	 * sempre. Sense ell, Vigilante no registra el login ocult al subsite, no hi
	 * captura la còpia de la pàgina 404 del tema (3.0.9+) i no hi respon 404 a
	 * wp-admin ni als atalls; el bloqueig serviria la pàgina plana, que es distingeix
	 * d'una URL inexistent. Ho va preguntar en Fernando Tellado el 2026-10-31.
	 *
	 * @param bool $sync_custom_login   Valor de la casella.
	 * @param bool $login_block_enabled Si el bloqueig de login als subsites és actiu.
	 * @return bool
	 */
	public static function login_slug_travels( $sync_custom_login, $login_block_enabled ) {
		return (bool) $sync_custom_login || (bool) $login_block_enabled;
	}

	/**
	 * Llista definitiva de camps a preservar del destí, segons les opcions actives.
	 *
	 * @param bool $sync_ip_lists   Si es copien les llistes d'IPs i User-Agents.
	 * @param bool $sync_login      Si es copia el slug de custom-login.
	 * @param bool $sync_two_factor Si es copia la configuració de 2FA.
	 * @return array<string,string[]> Secció => claus.
	 */
	public static function get_preserved_fields( $sync_ip_lists, $sync_login, $sync_two_factor ) {
		$preserved = array();

		// Els nostres camps de sempre, filtrats per les caselles.
		// Amb Vigilante 3.1.0 la llista blanca de login que compta a tota la xarxa és la
		// del principal: copiar-la no faria res, així que es queda la del destí.
		$login_whitelist_from_main = Vigsync_Detector::login_whitelist_from_main();

		foreach ( self::SITE_SPECIFIC_FIELDS as $section => $keys ) {
			foreach ( $keys as $key ) {
				if ( self::is_list_field( $key ) && $sync_ip_lists
					&& ! ( $login_whitelist_from_main && 'login_security' === $section && 'ip_whitelist' === $key ) ) {
					continue;
				}
				if ( 'two_factor' === $key && $sync_two_factor ) {
					continue;
				}
				$preserved[ $section ][] = $key;
			}
		}

		// El slug de custom-login té casella pròpia (per defecte SÍ es copia). L'avís als
		// usuaris quan canvia la URL d'accés va amb el slug: Vigilante 3.1.0 el passa a
		// `get_site_keys()` perquè es decideix allà on és l'adreça.
		if ( ! $sync_login ) {
			$preserved['login_security'][] = 'custom_login_url';
			$preserved['login_security'][] = 'notify_on_login_url_change';
		}

		// Xarxa de seguretat: camps que Vigilante declara com a dades de l'usuari i
		// que no són a la nostra llista d'uniformes de xarxa.
		foreach ( Vigsync_Detector::get_user_data_keys() as $section => $keys ) {
			foreach ( (array) $keys as $key ) {
				if ( self::is_network_uniform( $section, $key ) ) {
					continue;
				}
				if ( self::is_list_field( $key ) && $sync_ip_lists ) {
					continue;
				}
				if ( 'two_factor' === $key && $sync_two_factor ) {
					continue;
				}
				if ( 'custom_login_url' === $key && $sync_login ) {
					continue;
				}
				if ( ! isset( $preserved[ $section ] ) || ! in_array( $key, $preserved[ $section ], true ) ) {
					$preserved[ $section ][] = $key;
				}
			}
		}

		return $preserved;
	}

	/**
	 * Preserva una secció sencera del destí dins del payload.
	 *
	 * @param array $payload  Payload en construcció (per referència).
	 * @param array $existing Config del destí.
	 * @param string $section Secció.
	 */
	private static function preserve_section( &$payload, $existing, $section ) {
		if ( array_key_exists( $section, $existing ) ) {
			$payload[ $section ] = $existing[ $section ];
		} else {
			unset( $payload[ $section ] );
		}
	}

	/**
	 * Preserva una clau concreta del destí (o l'elimina si el destí no en tenia).
	 *
	 * @param array  $payload  Payload en construcció (per referència).
	 * @param array  $existing Config del destí.
	 * @param string $section  Secció.
	 * @param string $key      Clau.
	 */
	private static function preserve_key( &$payload, $existing, $section, $key ) {
		if ( isset( $existing[ $section ] ) && array_key_exists( $key, (array) $existing[ $section ] ) ) {
			$payload[ $section ][ $key ] = $existing[ $section ][ $key ];
		} elseif ( isset( $payload[ $section ][ $key ] ) ) {
			unset( $payload[ $section ][ $key ] );
		}
	}

	/**
	 * Indica si un camp és una llista d'excepcions del tallafoc (IPs o User-Agents).
	 *
	 * @param string $key Clau.
	 * @return bool
	 */
	private static function is_list_field( $key ) {
		return in_array(
			$key,
			array( 'ip_whitelist', 'ip_blacklist', 'ua_whitelist', 'ua_blacklist', 'excluded_ips' ),
			true
		);
	}

	/**
	 * Indica si un camp és uniforme a tota la xarxa i per tant s'ha de propagar.
	 *
	 * @param string $section Secció.
	 * @param string $key     Clau.
	 * @return bool
	 */
	private static function is_network_uniform( $section, $key ) {
		return isset( self::NETWORK_UNIFORM_FIELDS[ $section ] )
			&& in_array( $key, self::NETWORK_UNIFORM_FIELDS[ $section ], true );
	}

	/**
	 * Camps que contenen llistes d'adreces IP (no de User-Agents).
	 *
	 * Estructura: secció => llista de claus.
	 *
	 * @var array
	 */
	const IP_LIST_FIELDS = array(
		'firewall'       => array( 'ip_whitelist', 'ip_blacklist' ),
		'login_security' => array( 'ip_whitelist' ),
		'activity_log'   => array( 'excluded_ips' ),
	);

	/**
	 * Treu del payload les entrades d'IP que Vigilante no pot fer coincidir.
	 *
	 * Només s'aplica quan la casella de copiar les llistes està activa: si no ho està,
	 * les llistes del destí es preserven i no som ningú per esporgar-les. Les entrades
	 * descartades es retornen perquè quedin al log del sync i no desapareguin en
	 * silenci: una llista d'IPs és un mecanisme d'accés i cal veure què s'hi ha tocat.
	 *
	 * Amb Vigilante anterior a 2.9.9 el validador no existeix i el payload no es toca.
	 *
	 * @param array $payload Payload en construcció (per referència).
	 * @return string[] Entrades descartades, etiquetades amb la seva secció i clau.
	 */
	private static function strip_unmatchable_ips( &$payload ) {
		$rejected = array();

		foreach ( self::IP_LIST_FIELDS as $section => $keys ) {
			foreach ( $keys as $key ) {
				if ( ! isset( $payload[ $section ][ $key ] ) || ! is_array( $payload[ $section ][ $key ] ) ) {
					continue;
				}

				$split = Vigsync_Detector::split_ip_list( $payload[ $section ][ $key ] );
				if ( null === $split ) {
					continue;
				}

				// S'assigna sempre que hi hagi diferència, no només quan hi ha
				// entrades rebutjades: split_list() també descarta les línies buides
				// i els duplicats, i el destí ha de quedar amb la mateixa llista que
				// Vigilante hi desaria des del seu formulari.
				if ( $split['valid'] !== $payload[ $section ][ $key ] ) {
					$payload[ $section ][ $key ] = $split['valid'];
				}

				foreach ( $split['rejected'] as $entry ) {
					$rejected[] = $section . '.' . $key . ': ' . $entry;
				}
			}
		}

		return $rejected;
	}

	/**
	 * Construeix un resum del log.
	 *
	 * @param array $log       Log per-site.
	 * @param int   $source_id ID del site principal.
	 * @return array
	 */
	private static function summarize( $log, $source_id ) {
		$counts = array(
			'ok'      => 0,
			'skipped' => 0,
			'error'   => 0,
		);

		foreach ( $log as $entry ) {
			if ( isset( $counts[ $entry['status'] ] ) ) {
				$counts[ $entry['status'] ]++;
			}
		}

		return array(
			'time'      => time(),
			'source_id' => (int) $source_id,
			'total'     => count( $log ),
			'ok'        => $counts['ok'] + $counts['skipped'], // "Correcte" inclou els omesos sense canvis.
			'errors'    => $counts['error'],
			'log'       => $log,
		);
	}

	/**
	 * Resultat d'avortament.
	 *
	 * @param string $message Motiu.
	 * @return array
	 */
	private static function abort( $message ) {
		return array(
			'success' => false,
			'message' => $message,
			'log'     => array(),
		);
	}
}
