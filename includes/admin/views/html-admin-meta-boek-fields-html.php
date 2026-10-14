<?php
/**
 * Admin Meta Boek Fields
 *
 * @package BoekDB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<?php if ( isset( $meta['boekdb_annotatie'] ) ) : ?>
	<h4>Annotatie</h4>
	<textarea name="boekdb_annotatie" id="boekdb_annotatie" cols="120" rows="5"><?php echo esc_textarea( $meta['boekdb_annotatie'][0] ); ?></textarea>
	<?php if ( '1' === $annotatie_overwritten ) : ?>
		<br /><em>Originele annotatie uit BoekDB:</em>
		<p><?php echo wp_kses_post( $meta['boekdb_annotatie_org'][0] ); ?></p>
	<?php endif; ?>
	<hr />
<?php endif; ?>

<?php if ( isset( $meta['boekdb_flaptekst'] ) ) : ?>
	<h4>Flaptekst</h4>
	<textarea name="boekdb_flaptekst" id="boekdb_flaptekst" cols="120" rows="9"><?php echo esc_textarea( $meta['boekdb_flaptekst'][0] ); ?></textarea>
	<?php if ( '1' === $flaptekst_overwritten ) : ?>
		<br /><em>Originele flaptekst uit BoekDB:</em>
		<p><?php echo wp_kses_post( $meta['boekdb_flaptekst_org'][0] ); ?></p>
	<?php endif; ?>
	<hr />
<?php endif; ?>

<?php if ( is_array( $quotes ) ) : ?>
	<h4>Recensiequotes</h4>
	<table class="widefat fixed">
		<tr>
			<th style="text-align: left;">Tonen</th>
			<th style="text-align: left;">Tekst</th>
			<th style="text-align: left;">Auteur</th>
			<th style="text-align: left;">Bron</th>
			<th style="text-align: left;">Datum</th>
		</tr>
	<?php foreach ( $quotes as $boekdb_hash => $boekdb_quote ) : ?>
		<input type="hidden" id="quote[<?php echo esc_attr( $boekdb_hash ); ?>]" value="off" name="quote[<?php echo esc_attr( $boekdb_hash ); ?>]">
		<tr>
			<td>
				<input type="checkbox" id="quote[<?php echo esc_attr( $boekdb_hash ); ?>]" value="on" name="quote[<?php echo esc_attr( $boekdb_hash ); ?>]" <?php checked( ! empty( $boekdb_quote['tonen'] ) ); ?>>
			</td>
			<td style="width: 450px;"><?php echo esc_html( wp_strip_all_tags( $boekdb_quote['tekst'] ) ); ?></td>
			<td><?php echo esc_html( $boekdb_quote['auteur'] ); ?></td>
			<td><?php echo esc_html( $boekdb_quote['bron'] ); ?></td>
			<td><?php echo esc_html( $boekdb_quote['datum'] ); ?></td>
		</tr>
	<?php endforeach; ?>
	</table>
<?php endif; ?>

<h4>Overige velden:</h4>
<?php
$boekdb_skip = array(
	'flaptekst',
	'annotatie',
	'flaptekst_org',
	'annotatie_org',
	'recensiequotes',
	'recensiequotes_org',
	'file_voorbeeld_id',
	'0',
	'primair',
);
?>
<table class="widefat fixed">
<?php foreach ( $meta as $boekdb_name => $boekdb_value ) : ?>
	<?php if ( boekdb_startswith( $boekdb_name, 'boekdb_' ) ) : ?>
		<?php
		$boekdb_name = substr( $boekdb_name, 7 );
		if ( in_array( $boekdb_name, $boekdb_skip, true ) ) {
			continue;}
		?>
		<tr>
			<th style="vertical-align: top; text-align: left;"><?php echo esc_html( $boekdb_name ); ?></th>
			<td><?php echo esc_html( $boekdb_value[0] ); ?></td>
		</tr>
	<?php endif; ?>
<?php endforeach; ?>
</table>
