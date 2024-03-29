<?php
/**
 * Admin View: Settings
 *
 * @package BoekDB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="wrap boekdb">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php do_action( 'boekdb_before_settings' ); ?>

	<form method="post" id="mainform" action="" enctype="multipart/form-data">
		<?php self::show_messages(); // Display error messages if any ?>
		<?php wp_nonce_field( 'boekdb-settings', '_wpnonce', true ); ?>

		<!-- Main settings table -->
		<table class="wp-list-table widefat fixed striped table-view-list">
			<thead>
			<tr>
				<th class="manage-column"><?php esc_html_e( 'Naam', 'boekdb' ); ?></th>
				<th class="manage-column"><?php esc_html_e( 'Prefix', 'boekdb' ); ?></th>
				<th class="manage-column"><?php esc_html_e( 'Geïmporteerd', 'boekdb' ); ?></th>
				<th class="manage-column"><?php esc_html_e( 'BoekDB aantal', 'boekdb' ); ?></th>
				<th class="manage-column"><?php esc_html_e( 'Offset', 'boekdb' ); ?></th>
				<th class="manage-column"><?php esc_html_e( 'Import actief', 'boekdb' ); ?></th>
				<th class="manage-column"><?php esc_html_e( 'API Key', 'boekdb' ); ?></th>
				<th class="manage-column"><?php esc_html_e( 'Laatste import', 'boekdb' ); ?></th>
				<th class="manage-column"><?php esc_html_e( 'Actie', 'boekdb' ); ?></th>
			</tr>
			</thead>
			<tbody>
			<?php

			foreach ( $boekdb_etalages as $etalage ) :
				if ( $etalage->running > 0 ) {
					$disabled = 'disabled="disabled" aria-disabled="true"';
				} else {
					$disabled = '';
				}

				include 'html-admin-etalage-row.php'; // includes the etalage row template

			endforeach;
			?>
			</tbody>
		</table>

		<p class="submit">
			<?php submit_button( esc_html__( 'Draai import', 'boekdb' ), 'primary', 'run', false, $disabled ); ?>
			<?php submit_button( esc_html__( 'Stop imports', 'boekdb' ), $disabled !== '' ? 'primary' : 'secondary', 'stop', false ); ?>
			<?php submit_button( esc_html__( 'Oude data opruimen', 'boekdb' ), 'secondary', 'cleanup', false, $disabled ); ?>
			<?php submit_button( esc_html__( 'Test verbinding met BoekDB', 'boekdb' ), 'secondary', 'test', false ); ?>
		</p>
	</form>

	<?php require 'html-admin-etalage-form.php'; // includes the new etalage form ?>


</div>
