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
            <tr>
                <th scope="col">Naam</th>
                <th scope="col">Boeken</th>
                <th scope="col">API Key</th>
                <th scope="col">Laatste import</th>
            </tr>
            <?php foreach($etalages as $etalage) : ?>
			<tr>
				<td><?php echo $etalage->name ?></td>
                <td><?php echo $etalage->boeken ?></td>
                <td><?php echo $etalage->api_key ?></td>
                <td><?php echo $etalage->last_import ?></td>
			</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

        <hr />

        <h2>Nieuwe etalage toevoegen</h2>
        <p>
            <label for="etalage_name">Naam:</label>
            <input type="text" name="etalage_name" placeholder="naam">
        </p>
        <p>
            <label for="etalage_api_key">API Key:</label>
            <input type="text" name="etalage_api_key" placeholder="api-key">
        </p>
		<p class="submit">
			<?php if ( empty( $GLOBALS['hide_save_button'] ) ) : ?>
				<button name="save" class="button-primary boekdb-save-button" type="submit" value="save">Opslaan</button>
			<?php endif; ?>
			<?php wp_nonce_field( 'boekdb-settings' ); ?>
            <button name="run" class="button-primary boekdb-save-button" type="submit" value="run">Draai import</button>
		</p>
	</form>

</div>
