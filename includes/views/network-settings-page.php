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

	<?php if ( is_subdomain_install() ) : ?>
		<div class="notice notice-warning">
			<p><strong><?php esc_html_e( 'Xarxa de subdominis detectada.', 'vigilante-network-sync' ); ?></strong>
			<?php esc_html_e( 'El bloqueig de login unificat requereix una xarxa en subdirectori (la cookie d\'autenticació de WordPress es comparteix entre sites del mateix domini). En una xarxa de subdominis, iniciar sessió al principal NO autentica els subsites, i bloquejar-ne el login et deixaria fora dels seus wp-admin. Per això el bloqueig està deshabilitat en aquesta xarxa. La sincronització de configuració sí funciona normalment.', 'vigilante-network-sync' ); ?></p>
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
				<th><?php esc_html_e( 'Bloqueig de login elegible', 'vigilante-network-sync' ); ?></th>
				<td>
					<?php
					if ( is_subdomain_install() ) {
						echo esc_html__( 'No (xarxa de subdominis: cookie d\'auth no compartida)', 'vigilante-network-sync' );
					} elseif ( ! $custom_login_on ) {
						echo esc_html__( 'No (cal custom-login al principal)', 'vigilante-network-sync' );
					} else {
						echo esc_html__( 'Sí', 'vigilante-network-sync' );
					}
					?>
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
					</label><br>
					<label>
						<input type="checkbox" name="sync_two_factor" value="1" <?php checked( ! empty( $settings['sync_two_factor'] ) ); ?>>
						<?php esc_html_e( 'Copiar també la configuració de 2FA (només recomanat si el mètode és e-mail)', 'vigilante-network-sync' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Per defecte es preserven les llistes d\'IPs, la CSP report-uri i la configuració de 2FA de cada site (camps específics per site). Atenció: amb el mètode TOTP, els secrets viuen en taules per-blog i no es poden sincronitzar; copiar la config de 2FA deixaria els usuaris sense poder validar al destí.', 'vigilante-network-sync' ); ?></p>
				</td>
			</tr>
			<?php $block_eligible = $custom_login_on && ! is_subdomain_install(); ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Login unificat', 'vigilante-network-sync' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="login_block_enabled" value="1" <?php checked( ! empty( $settings['login_block_enabled'] ) ); ?> <?php disabled( ! $block_eligible ); ?>>
						<?php esc_html_e( 'Bloquejar el login als subsites (el login només es fa al site principal)', 'vigilante-network-sync' ); ?>
					</label>
					<?php if ( is_subdomain_install() ) : ?>
						<p class="description vigsync-bad"><?php esc_html_e( 'Deshabilitat: la xarxa és de subdominis i la cookie d\'autenticació no es comparteix; el bloqueig et deixaria fora dels wp-admin dels subsites.', 'vigilante-network-sync' ); ?></p>
					<?php elseif ( ! $custom_login_on ) : ?>
						<p class="description vigsync-bad"><?php esc_html_e( 'Deshabilitat: cal que el site principal tingui el custom-login de Vigilante configurat.', 'vigilante-network-sync' ); ?></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Els subsites respondran un 404 a qualsevol intent de login (wp-login.php i slug), sense revelar el slug del principal. Inicia sessió un sol cop al principal; la cookie de xarxa et dona accés a tots els subsites.', 'vigilante-network-sync' ); ?></p>
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
		<p><strong><?php esc_html_e( 'Mode bloqueig (recomanat per a 2FA un sol cop, xarxa multi-idioma):', 'vigilante-network-sync' ); ?></strong>
			<?php esc_html_e( 'configura el custom-login al site principal, mantén el slug copiat als subsites (sync) i activa el bloqueig de login. El login només funciona al principal; els subsites responen 404 sense revelar el slug. El 2FA s\'enrola un sol cop al principal i la cookie de xarxa cobreix la resta.', 'vigilante-network-sync' ); ?></p>
		<p><strong><?php esc_html_e( 'Mode independent:', 'vigilante-network-sync' ); ?></strong>
			<?php esc_html_e( 'mateix slug a tots els sites (via sync) i bloqueig desactivat. Cada site amaga el seu propi login; el 2FA s\'enrola per-site (recorda: amb TOTP, no sincronitzis la config de 2FA).', 'vigilante-network-sync' ); ?></p>
		<p><strong><?php esc_html_e( 'Recuperació d\'emergència:', 'vigilante-network-sync' ); ?></strong>
			<?php esc_html_e( 'defineix VIGSYNC_DISABLE_LOGIN_GUARD a true al wp-config.php per desactivar el bloqueig sense tocar la base de dades (també s\'accepta l\'antic VIGSYNC_DISABLE_REDIRECT). El site principal mai es bloqueja.', 'vigilante-network-sync' ); ?></p>
	</div>
</div>
