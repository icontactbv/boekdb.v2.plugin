<?php
/**
 * Admin View: Etalage Row
 *
 * @package BoekDB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<tr>
	<td><?php echo esc_html( $etalage->name ); ?></td>
	<td><?php echo esc_html( $etalage->prefix ); ?></td>
	<td><?php echo esc_html( $etalage->boeken ); ?></td>
	<td><?php echo esc_html( $etalage->isbns ); ?></td>
	<td><?php echo esc_html( $etalage->offset ); ?></td>
	<td>
		<?php
		switch ( $etalage->running ) {
			case 0:
				esc_html_e( 'Nee', 'boekdb' );
				break;
			case 1:
				esc_html_e( 'Bezig', 'boekdb' );
				break;
			case 2:
				esc_html_e( 'In planning', 'boekdb' );
				break;
		}
		?>
	</td>
	<td><small><?php echo esc_html( $etalage->api_key ); ?></small></td>
	<td><?php echo esc_html( $etalage->last_import ); ?></td>
	<td>
		<button type="submit" name="reset" class="button-primary boekdb-save-button" value="<?php echo esc_attr( $etalage->id ); ?>" <?php echo $disabled; ?>><?php esc_html_e( 'Reset', 'boekdb' ); ?></button>
		<button type="submit" name="delete" class="button-primary boekdb-save-button" value="<?php echo esc_attr( $etalage->id ); ?>" <?php echo $disabled; ?>><?php esc_html_e( 'Verwijder', 'boekdb' ); ?></button>
	</td>
</tr>