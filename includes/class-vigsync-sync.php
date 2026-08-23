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
	 * - `user_security.insecure_usernames`: llista de noms prohibits, política global.
	 * - `file_integrity.*`: l'escaneig recorre el MATEIX sistema de fitxers a tots els
	 *   sites de la xarxa, així que les exclusions han de ser necessàriament iguals.
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
		'firewall'       => array( 'trusted_proxy_header' ),
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

		// 3. Valida l'esquema abans d'escriure res.
		$valid = Vigsync_Detector::validate_schema( $source_config );
		if ( is_wp_error( $valid ) ) {
			return self::abort( $valid->get_error_message() );
		}

		$sync_ip_lists  = (bool) Vigsync_Settings::get( 'sync_ip_lists', false );
		$sync_login     = (bool) Vigsync_Settings::get( 'sync_custom_login', true );
		$sync_two_factor = (bool) Vigsync_Settings::get( 'sync_two_factor', false );

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

			$log[] = self::sync_one_site( $target_id, $source_config, $sync_ip_lists, $sync_login, $sync_two_factor );
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
	 * @return array Entrada de log d'aquest site.
	 */
	private static function sync_one_site( $target_id, $source_config, $sync_ip_lists, $sync_login, $sync_two_factor ) {
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

			$payload = self::build_payload( $source_config, $existing, $sync_ip_lists, $sync_login, $sync_two_factor );

			// Quan es copien les llistes d'IPs, s'hi apliquen les mateixes regles de
			// validesa que Vigilante 2.9.9+ aplica al seu formulari.
			$rejected_ips = $sync_ip_lists ? self::strip_unmatchable_ips( $payload ) : array();

			// Si el contingut és idèntic, update_option retorna false; ho tractem com "omès".
			if ( $payload === $existing ) {
				$result['status']  = 'skipped';
				$result['message'] = __( 'Ja estava sincronitzat (sense canvis).', 'vigilante-network-sync' );
			} else {
				$saved = update_option( Vigsync_Detector::VIGILANTE_OPTION, $payload );
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
		foreach ( self::SITE_SPECIFIC_FIELDS as $section => $keys ) {
			foreach ( $keys as $key ) {
				if ( self::is_list_field( $key ) && $sync_ip_lists ) {
					continue;
				}
				if ( 'two_factor' === $key && $sync_two_factor ) {
					continue;
				}
				$preserved[ $section ][] = $key;
			}
		}

		// El slug de custom-login té casella pròpia (per defecte SÍ es copia).
		if ( ! $sync_login ) {
			$preserved['login_security'][] = 'custom_login_url';
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
