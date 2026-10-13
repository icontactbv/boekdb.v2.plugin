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
	<?php $boekdb_etalage_status = $boekdb_status['etalages'][ (int) $etalage->id ]; ?>
	<td><?php echo esc_html( BoekDB_Admin_Settings::progress_text( $boekdb_etalage_status ) ); ?></td>
	<td>
		<?php
		$boekdb_failed = BoekDB_Import::failed_products( $etalage->id );
		if ( count( $boekdb_failed ) > 0 ) :
			$boekdb_failed_isbns = implode( ', ', $boekdb_failed );
			?>
			<span class="boekdb-failed" title="<?php echo esc_attr( $boekdb_failed_isbns ); ?>">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of books. */
						_n( '%d niet geïmporteerd', '%d niet geïmporteerd', count( $boekdb_failed ), 'boekdb' ),
						count( $boekdb_failed )
					)
				);
				?>
			</span>
			<br /><small><?php echo esc_html( $boekdb_failed_isbns ); ?></small>
		<?php else : ?>
			<?php echo esc_html( '–' ); ?>
		<?php endif; ?>
	</td>
	<td><?php echo esc_html( BoekDB_Admin_Settings::state_text( $boekdb_etalage_status ) ); ?></td>
	<td><small><?php echo esc_html( $etalage->api_key ); ?></small></td>
	<td>
		<?php if ( is_null( $boekdb_etalage_status['finished'] ) ) : ?>
			<?php echo esc_html( $etalage->last_import ); ?>
		<?php else : ?>
			<span title="<?php /* translators: %s: the moment the next run fetches changes from. */ echo esc_attr( sprintf( __( 'De volgende ronde haalt wijzigingen op vanaf %s.', 'boekdb' ), $etalage->last_import ) ); ?>">
				<?php echo esc_html( wp_date( 'Y-m-d H:i:s', $boekdb_etalage_status['finished'] ) ); ?>
			</span>
		<?php endif; ?>
	</td>
	<td>
		<button type="submit" name="reset" class="button-primary boekdb-save-button" value="<?php echo esc_attr( $etalage->id ); ?>" <?php echo $disabled; ?>><?php esc_html_e( 'Reset', 'boekdb' ); ?></button>
		<button type="submit" name="delete" class="button-primary boekdb-save-button" value="<?php echo esc_attr( $etalage->id ); ?>" <?php echo $disabled; ?>><?php esc_html_e( 'Verwijder', 'boekdb' ); ?></button>
	</td>
</tr>