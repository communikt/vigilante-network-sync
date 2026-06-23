<?php
/**
 * Vista: pàgina de configuració a Network Admin.
 *
 * Variables disponibles (passades des de Vigsync_Network_Admin::render_page):
 *
 * @var array       $settings         Configuració actual del plugin.
 * @var bool        $vigilante_active Si Vigilante està actiu.
 * @var int         $source_id        ID del site principal efectiu.
 * @var bool        $custom_login_on  Si el principal té custom-login actiu.
 * @var string      $source_slug      Slug de login del principal.
 * @var bool        $id_was_invalid   Si l'ID desat era invàlid (fallback aplicat).
 * @var array|null  $this->action_result Resultat de la darrera acció.
 *
 * @package Vigilante_Network_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$result = $this->action_result;
$sites  = get_sites(
	array(
		'number'   => 0,
		'archived' => 0,
		'deleted'  => 0,
		'spam'     => 0,
	)
);
?>
<div class="wrap vigsync-wrap">
	<h1><?php esc_html_e( 'Vigilante Network Sync', 'vigilante-network-sync' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Replica la configuració de Vigilante des del site principal a la resta de sites de la xarxa i, opcionalment, unifica el login.', 'vigilante-network-sync' ); ?>
	</p>

	<?php if ( $result && ! empty( $result['message'] ) ) : ?>
		<div class="notice notice-<?php echo $result['success'] ? 'success' : 'error'; ?> is-dismissible">
			<p><?php echo esc_html( $result['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( ! $vigilante_active ) : ?>
		<div class="notice notice-error">
			<p><?php esc_html_e( 'Vigilante no està actiu en aquesta xarxa. Activa Vigilante per poder sincronitzar.', 'vigilante-network-sync' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $id_was_invalid ) : ?>
		<div class="notice notice-warning">
			<p><?php esc_html_e( 'El site principal desat ja no existeix; s\'ha aplicat el principal de la xarxa per defecte. Reviseu-ho a sota.', 'vigilante-network-sync' ); ?></p>
		</div>
	<?php endif; ?>

	<!-- ESTAT -->
	<h2><?php esc_html_e( 'Estat', 'vigilante-network-sync' ); ?></h2>
	<table class="widefat striped vigsync-status">
		<tbody>
			<tr>
				<th><?php esc_html_e( 'Vigilante actiu', 'vigilante-network-sync' ); ?></th>
				<td>
					<?php if ( $vigilante_active ) : ?>
						<span class="vigsync-ok">✓ <?php echo esc_html( Vigsync_Detector::vigilante_version() ); ?></span>
					<?php else : ?>
						<span class="vigsync-bad">✗ <?php esc_html_e( 'No detectat', 'vigilante-network-sync' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Site principal (font)', 'vigilante-network-sync' ); ?></th>
				<td>
					<?php
					$source_site = get_site( $source_id );
					echo esc_html( '#' . $source_id . ' — ' . ( $source_site ? untrailingslashit( $source_site->domain . $source_site->path ) : '' ) );
					?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Custom-login al principal', 'vigilante-network-sync' ); ?></th>
				<td>
					<?php if ( $custom_login_on ) : ?>
						<span class="vigsync-ok">✓ <?php echo esc_html( $source_slug ); ?></span>
					<?php else : ?>
						<span class="vigsync-bad"><?php esc_html_e( 'No configurat', 'vigilante-network-sync' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Redirect de login elegible', 'vigilante-network-sync' ); ?></th>
				<td>
					<?php echo $custom_login_on ? esc_html__( 'Sí', 'vigilante-network-sync' ) : esc_html__( 'No (cal custom-login al principal)', 'vigilante-network-sync' ); ?>
				</td>
			</tr>
		</tbody>
	</table>

	<!-- CONFIGURACIÓ -->
	<h2><?php esc_html_e( 'Configuració', 'vigilante-network-sync' ); ?></h2>
	<form method="post" action="">
		<?php wp_nonce_field( 'vigsync_save_settings' ); ?>
		<input type="hidden" name="vigsync_action" value="save_settings">

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="vigsync_source_site"><?php esc_html_e( 'Site principal', 'vigilante-network-sync' ); ?></label></th>
				<td>
					<select name="source_site_id" id="vigsync_source_site">
						<?php foreach ( $sites as $site ) : ?>
							<option value="<?php echo esc_attr( $site->blog_id ); ?>" <?php selected( (int) $site->blog_id, $source_id ); ?>>
								<?php echo esc_html( '#' . $site->blog_id . ' — ' . untrailingslashit( $site->domain . $site->path ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'El site del qual es llegeix la configuració de Vigilante. Per defecte, el principal de la xarxa (normalment #1).', 'vigilante-network-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Sincronització', 'vigilante-network-sync' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="sync_custom_login" value="1" <?php checked( ! empty( $settings['sync_custom_login'] ) ); ?>>
						<?php esc_html_e( 'Copiar també el slug de custom-login al destí', 'vigilante-network-sync' ); ?>
					</label><br>
					<label>
						<input type="checkbox" name="sync_ip_lists" value="1" <?php checked( ! empty( $settings['sync_ip_lists'] ) ); ?>>
						<?php esc_html_e( 'Copiar també les llistes d\'IPs (whitelist/blacklist)', 'vigilante-network-sync' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Per defecte es preserven les llistes d\'IPs i la CSP report-uri de cada site (camps específics per site).', 'vigilante-network-sync' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Login unificat', 'vigilante-network-sync' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="login_redirect_enabled" value="1" <?php checked( ! empty( $settings['login_redirect_enabled'] ) ); ?> <?php disabled( ! $custom_login_on ); ?>>
						<?php esc_html_e( 'Redirigir els logins dels subsites al login del site principal', 'vigilante-network-sync' ); ?>
					</label>
					<?php if ( ! $custom_login_on ) : ?>
						<p class="description vigsync-bad"><?php esc_html_e( 'Deshabilitat: cal que el site principal tingui el custom-login de Vigilante configurat.', 'vigilante-network-sync' ); ?></p>
					<?php else : ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: URL de login del principal */
								esc_html__( 'Els logins dels subsites es redirigiran a: %s', 'vigilante-network-sync' ),
								'<code>' . esc_html( trailingslashit( get_site_url( $source_id ) ) . $source_slug . '/' ) . '</code>'
							);
							?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="vigsync_email"><?php esc_html_e( 'Email d\'avisos', 'vigilante-network-sync' ); ?></label></th>
				<td>
					<input type="email" name="notify_email" id="vigsync_email" class="regular-text" value="<?php echo esc_attr( $settings['notify_email'] ); ?>">
					<p class="description"><?php esc_html_e( 'Rebrà un avís quan Vigilante canviï de versió (un cop per versió).', 'vigilante-network-sync' ); ?></p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Desar configuració', 'vigilante-network-sync' ) ); ?>
	</form>

	<!-- SINCRONITZAR ARA -->
	<h2><?php esc_html_e( 'Sincronitzar ara', 'vigilante-network-sync' ); ?></h2>
	<form method="post" action="">
		<?php wp_nonce_field( 'vigsync_run_sync' ); ?>
		<input type="hidden" name="vigsync_action" value="run_sync">
		<p><?php esc_html_e( 'Replica la configuració del site principal a la resta de sites de la xarxa.', 'vigilante-network-sync' ); ?></p>
		<?php submit_button( __( 'Sincronitza ara', 'vigilante-network-sync' ), 'primary', 'submit', false, $vigilante_active ? array() : array( 'disabled' => 'disabled' ) ); ?>
	</form>

	<?php if ( $result && ! empty( $result['log'] ) ) : ?>
		<h3><?php esc_html_e( 'Resultat de la sincronització', 'vigilante-network-sync' ); ?></h3>
		<table class="widefat striped vigsync-log">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Site', 'vigilante-network-sync' ); ?></th>
					<th><?php esc_html_e( 'Estat', 'vigilante-network-sync' ); ?></th>
					<th><?php esc_html_e( 'Detall', 'vigilante-network-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $result['log'] as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( '#' . $entry['site_id'] . ' — ' . $entry['label'] ); ?></td>
						<td>
							<?php
							$status_class = 'ok' === $entry['status'] ? 'vigsync-ok' : ( 'error' === $entry['status'] ? 'vigsync-bad' : 'vigsync-muted' );
							$status_label = array(
								'ok'      => __( 'Correcte', 'vigilante-network-sync' ),
								'skipped' => __( 'Sense canvis', 'vigilante-network-sync' ),
								'error'   => __( 'Error', 'vigilante-network-sync' ),
							);
							?>
							<span class="<?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( isset( $status_label[ $entry['status'] ] ) ? $status_label[ $entry['status'] ] : $entry['status'] ); ?></span>
						</td>
						<td><?php echo esc_html( $entry['message'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<!-- RESILIÈNCIA -->
	<h2><?php esc_html_e( 'Compatibilitat amb Vigilante', 'vigilante-network-sync' ); ?></h2>
	<p>
		<?php
		printf(
			/* translators: 1: versió validada, 2: versió coneguda */
			esc_html__( 'Versió de Vigilante validada per aquesta release: %1$s. Última versió marcada com a compatible: %2$s.', 'vigilante-network-sync' ),
			'<code>' . esc_html( Vigsync_Detector::compat_vigilante_version() ) . '</code>',
			'<code>' . esc_html( $settings['last_known_vigilante_version'] ? $settings['last_known_vigilante_version'] : '—' ) . '</code>'
		);
		?>
	</p>
	<form method="post" action="">
		<?php wp_nonce_field( 'vigsync_mark_compatible' ); ?>
		<input type="hidden" name="vigsync_action" value="mark_compatible">
		<?php submit_button( __( 'He revisat: marca la versió actual com a compatible', 'vigilante-network-sync' ), 'secondary', 'submit', false ); ?>
	</form>

	<!-- AJUDA -->
	<h2><?php esc_html_e( 'Recomanacions', 'vigilante-network-sync' ); ?></h2>
	<div class="vigsync-help">
		<p><strong><?php esc_html_e( 'Mode redirect (recomanat per a 2FA un sol cop):', 'vigilante-network-sync' ); ?></strong>
			<?php esc_html_e( 'configura el custom-login només al site principal, deixa\'l buit als subsites i activa el login unificat. Tots els logins van al principal i el 2FA s\'enrola un sol cop.', 'vigilante-network-sync' ); ?></p>
		<p><strong><?php esc_html_e( 'Mode independent:', 'vigilante-network-sync' ); ?></strong>
			<?php esc_html_e( 'mateix slug a tots els sites (via sync) i redirect desactivat. Cada site amaga el seu propi login; el 2FA s\'enrola per-site.', 'vigilante-network-sync' ); ?></p>
		<p><strong><?php esc_html_e( 'Recuperació d\'emergència:', 'vigilante-network-sync' ); ?></strong>
			<?php esc_html_e( 'defineix VIGSYNC_DISABLE_REDIRECT a true al wp-config.php per desactivar el redirect sense tocar la base de dades.', 'vigilante-network-sync' ); ?></p>
	</div>
</div>
