<tr>
    <td><?php echo esc_html( $etalage->name ); ?></td>
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
        <input name="reset" class="button-primary boekdb-save-button" type="submit" value="<?php echo esc_attr($etalage->id); ?>" <?php echo $disabled; ?> />
        <input name="delete" class="button-primary boekdb-save-button" type="submit" value="<?php echo esc_attr($etalage->id); ?>" <?php echo $disabled; ?> />
    </td>
</tr>