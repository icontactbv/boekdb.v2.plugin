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
	<?php
	do_action( 'boekdb_before_settings' ); ?>
    <form method="post" id="mainform" action="" enctype="multipart/form-data">
        <h1>BoekDB Instellingen</h1>
		<?php self::show_messages(); ?>
        <table class="form-table">
            <tbody>
            <tr>
                <th scope="col">Naam</th>
                <th scope="col">Geïmporteerd</th>
                <th scope="col">BoekDB aantal</th>
                <th scope="col">Offset</th>
                <th scope="col">Actief</th>
                <th scope="col">API Key</th>
                <th scope="col">Laatste afgeronde import</th>
                <th scope="col">Actie</th>
            </tr>
			<?php
            $disabled = '';
            foreach ( $etalages as $etalage ) :
				$disabled = $etalage->running == 1 ? 'disabled="disabled" aria-disabled="true"' : ''; ?>
                <tr>
                    <td><?php
						echo $etalage->name ?></td>
                    <td><?php
						echo $etalage->boeken ?></td>
                    <td><?php
		                echo $etalage->isbns ?></td>
                    <td><?php
		                echo $etalage->offset ?></td>
                    <td><?php
		                echo $etalage->running ?></td>
                    <td><?php
						echo $etalage->api_key ?></td>
                    <td><?php
						echo $etalage->last_import ?></td>
                    <td>
                        <?php if ($etalage->running == 1): ?>
                        <button name="stop" class="button-primary boekdb-save-button" type="submit" value="<?php echo $etalage->id; ?>">Stop</button>
                        <?php endif; ?>
	                    <?php if ($etalage->running == 0 && $etalage->offset > 0): ?>
                            <button name="start" class="button-primary boekdb-save-button" type="submit" value="<?php echo $etalage->id; ?>">Start</button>
	                    <?php endif; ?>
                        <button name="reset" class="button-primary boekdb-save-button" type="submit" value="<?php echo $etalage->id; ?>" <?php echo $disabled ?>>Reset</button>
                        <button name="delete" class="button-primary boekdb-save-button" type="submit" value="<?php echo $etalage->id; ?>" <?php echo $disabled ?>>Verwijder</button>
                    </td>
                </tr>
			<?php
			endforeach; ?>
            </tbody>
        </table>

        <hr/>

        <h2>Nieuwe etalage toevoegen</h2>
        <p>
            <label for="etalage_name">Naam:</label>
            <input type="text" name="etalage_name" id="etalage_name" placeholder="naam">
        </p>
        <p>
            <label for="etalage_api_key">API Key:</label>
            <input type="text" name="etalage_api_key" id="etalage_api_key" placeholder="api-key">
        </p>
        <p class="submit">
            <button name="save" class="button-primary boekdb-save-button" type="submit" value="save">Toevoegen</button>
        </p>

        <hr/>

        <p class="options">
            <input type="checkbox" id="overwrite_images" name="overwrite_images" value="1">
            <label for="overwrite_images">Overschrijf afbeeldingen bij import</label>
        </p>
        <p class="submit">
            <button name="run" class="button-primary boekdb-save-button" type="submit" value="run" <?php echo $disabled ?>>Draai import</button>
            <button name="cleanup" class="button-primary boekdb-save-button" type="submit" value="cleanup" <?php echo $disabled ?>>Oude data opruimen</button>
            <button name="test" class="button-primary boekdb-save-button" type="submit" value="test">Test verbinding met BoekDB</button>
			<?php
			wp_nonce_field( 'boekdb-settings' ); ?>
        </p>
        <p class="settings">
            
        </p>
    </form>

</div>
