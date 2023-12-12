<?php
/**
 * Admin View: New Etalage Form
 *
 * @package BoekDB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>

<hr/>
<h2><?php esc_html_e('Nieuwe etalage toevoegen', 'boekdb'); ?></h2>

<form method="post" action="" id="etalageform" enctype="multipart/form-data">
    <table class="form-table">
        <tbody>
        <tr>
            <th><label for="etalage_name"><?php esc_html_e('Naam:', 'boekdb'); ?></label></th>
            <td><input type="text" name="etalage_name" id="etalage_name" placeholder="<?php esc_attr_e('naam', 'boekdb'); ?>" required></td>
        </tr>
        <tr>
            <th><label for="etalage_api_key"><?php esc_html_e('API Key:', 'boekdb'); ?></label></th>
            <td><input type="text" name="etalage_api_key" id="etalage_api_key" placeholder="<?php esc_attr_e('api-key', 'boekdb'); ?>" required></td>
        </tr>
        </tbody>
    </table>

    <p class="submit">
        <input type="submit" class="button-primary boekdb-save-button" name="save" value="<?php esc_attr_e('Toevoegen', 'boekdb'); ?>" />
    </p>
</form>

<hr/>