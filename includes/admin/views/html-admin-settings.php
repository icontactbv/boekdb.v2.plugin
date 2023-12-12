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
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

	<?php do_action( 'boekdb_before_settings' ); ?>

    <form method="post" id="mainform" action="" enctype="multipart/form-data">
        <?php self::show_messages(); // Display error messages if any ?>
        <?php wp_nonce_field( 'boekdb-settings', '_wpnonce', true ); ?>

        <!-- Main settings table -->
        <table class="form-table wp-list-table widefat striped">
            <thead>
            <tr>
                <th><?php esc_html_e('Naam', 'boekdb'); ?></th>
                <th><?php esc_html_e('Geïmporteerd', 'boekdb'); ?></th>
                <th><?php esc_html_e('BoekDB aantal', 'boekdb'); ?></th>
                <th><?php esc_html_e('Offset', 'boekdb'); ?></th>
                <th><?php esc_html_e('Import actief', 'boekdb'); ?></th>
                <th><?php esc_html_e('API Key', 'boekdb'); ?></th>
                <th><?php esc_html_e('Laatste afgeronde import', 'boekdb'); ?></th>
                <th><?php esc_html_e('Actie', 'boekdb'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php

            foreach ( $etalages as $etalage ) :
                if ($etalage->running > 0) {
                    $disabled = 'disabled="disabled" aria-disabled="true"';
                } else {
                    $disabled = '';
                }

                include 'views/html-admin-etalage-row.php'; // includes the etalage row template

            endforeach; ?>
            </tbody>
        </table>

        <?php include 'views/html-admin-etalage-form.php'; // includes the new etalage form ?>

        <?php submit_button( esc_html__('Test verbinding met BoekDB', 'boekdb'), 'primary', 'test'); ?>

    </form>
</div>
