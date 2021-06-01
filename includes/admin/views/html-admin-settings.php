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
	<?php do_action( 'boekdb_before_settings' ); ?>
	<form method="post" id="mainform" action="" enctype="multipart/form-data">
		<h1>BoekDB Instellingen</h1>
		<?php

		self::show_messages();

		?>
		<table class="form-table">
			<tbody>
            <?php foreach($etalages as $etalage) : ?>
			<tr>
				<th scope="row">
					<label for="my-text-field">BoekDB API Key</label>
				</th>

				<td>
					<input type="text" placeholder="BoekDB API Key" id="boekdb_api_key" name="boekdb_api_key">
					<br>
					<span class="description">BoekDB API Key invullen</span>
				</td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<p class="submit">
			<?php if ( empty( $GLOBALS['hide_save_button'] ) ) : ?>
				<button name="save" class="button-primary boekdb-save-button" type="submit" value="<?php esc_attr_e( 'Save changes', 'boekdb' ); ?>"><?php esc_html_e( 'Save changes', 'boekdb' ); ?></button>
			<?php endif; ?>
			<?php wp_nonce_field( 'boekdb-settings' ); ?>
		</p>
	</form>
</div>
