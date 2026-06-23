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
	 * l'opció sync_ip_lists estigui activa (per a les llistes d'IPs).
	 *
	 * Estructura: secció => llista de claus.
	 *
	 * @var array
	 */
	const SITE_SPECIFIC_FIELDS = array(
		'firewall'      => array( 'ip_whitelist', 'ip_blacklist' ),
		'login_security' => array( 'ip_whitelist' ),
		'activity_log'  => array( 'excluded_ips' ),
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

		$sync_ip_lists = (bool) Vigsync_Settings::get( 'sync_ip_lists', false );
		$sync_login    = (bool) Vigsync_Settings::get( 'sync_custom_login', true );

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

			$log[] = self::sync_one_site( $target_id, $source_config, $sync_ip_lists, $sync_login );
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
	 * @param int   $target_id     ID del site destí.
	 * @param array $source_config Config completa del site principal.
	 * @param bool  $sync_ip_lists Si s'han de copiar també les llistes d'IPs.
	 * @param bool  $sync_login    Si s'ha de copiar el slug de custom-login.
	 * @return array Entrada de log d'aquest site.
	 */
	private static function sync_one_site( $target_id, $source_config, $sync_ip_lists, $sync_login ) {
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

			$payload = self::build_payload( $source_config, $existing, $sync_ip_lists, $sync_login );

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
	 * Parteix de la config del principal i hi reinjecta els camps específics de
	 * site que s'han de preservar del destí.
	 *
	 * @param array $source        Config del principal.
	 * @param array $existing      Config actual del destí.
	 * @param bool  $sync_ip_lists Si es copien les llistes d'IPs.
	 * @param bool  $sync_login    Si es copia el slug de custom-login.
	 * @return array
	 */
	private static function build_payload( $source, $existing, $sync_ip_lists, $sync_login ) {
		$payload = $source;

		// Preserva els camps específics de site (excepte IPs si s'ha demanat copiar-les).
		foreach ( self::SITE_SPECIFIC_FIELDS as $section => $keys ) {
			foreach ( $keys as $key ) {
				$is_ip_list = self::is_ip_list_field( $key );

				if ( $is_ip_list && $sync_ip_lists ) {
					// Es copia del principal: no fem res (ja ve dins $payload).
					continue;
				}

				// Preserva el valor del destí (o l'elimina si el destí no en tenia).
				if ( isset( $existing[ $section ] ) && array_key_exists( $key, (array) $existing[ $section ] ) ) {
					$payload[ $section ][ $key ] = $existing[ $section ][ $key ];
				} elseif ( isset( $payload[ $section ][ $key ] ) ) {
					unset( $payload[ $section ][ $key ] );
				}
			}
		}

		// CSP report-uri és una URL absoluta específica de site: preserva la del destí.
		if ( isset( $source['security_headers']['csp'] ) ) {
			$existing_uri = isset( $existing['security_headers']['csp']['report_uri'] )
				? $existing['security_headers']['csp']['report_uri']
				: '';
			$payload['security_headers']['csp']['report_uri'] = $existing_uri;
		}

		// Si no s'ha de sincronitzar el custom-login, preserva el del destí (sovint buit).
		if ( ! $sync_login ) {
			$existing_slug = isset( $existing['login_security']['custom_login_url'] )
				? $existing['login_security']['custom_login_url']
				: '';
			$payload['login_security']['custom_login_url'] = $existing_slug;
		}

		return $payload;
	}

	/**
	 * Indica si una clau de camp és una llista d'IPs.
	 *
	 * @param string $key Clau.
	 * @return bool
	 */
	private static function is_ip_list_field( $key ) {
		return in_array( $key, array( 'ip_whitelist', 'ip_blacklist', 'excluded_ips' ), true );
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
