<?php
/**
 * Desinstal·lació: neteja l'opció de xarxa del plugin.
 *
 * No toca la configuració de Vigilante (vigilante_options) de cap site: és dada
 * de l'altre plugin i ha de persistir.
 *
 * @package Vigilante_Network_Sync
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Només té sentit en multisite, que és on s'emmagatzemen les opcions de xarxa.
if ( is_multisite() ) {
	delete_site_option( 'vigsync_settings' );
	delete_site_option( 'vigsync_db_version' );
}
