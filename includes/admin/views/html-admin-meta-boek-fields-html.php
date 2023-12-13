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

<div style="margin-top:20px">
	<h4>Alternatieve URL's voor etalages:</h4>
	<?php
	global $post;
	$alternate_urls = boekdb_get_alternate_urls( $post->ID );
	if ( ! empty( $alternate_urls ) ) {
		echo '<ul>';
		foreach ( $alternate_urls as $alternate_url ) {
			echo '<li>Etalage: ' . esc_html( $alternate_url['name'] ) . '<br><a href="' . esc_url( $alternate_url['url'] ) . '">' . esc_html( $alternate_url['url'] ) . '</a></li>';
		}
		echo '</ul>';
	} else {
		echo "<p>Er zijn geen alternatieve URL's voor etalages.</p>";
	}
	?>
</div>

<?php if ( isset( $meta['boekdb_annotatie'] ) ) : ?>
	<h4>Annotatie</h4>
	<textarea name="boekdb_annotatie" id="boekdb_annotatie" cols="120" rows="5"><?php echo $meta['boekdb_annotatie'][0]; ?></textarea>
	<?php if ( $annotatie_overwritten === '1' ) : ?>
		<br /><em>Originele annotatie uit BoekDB:</em>
		<p><?php echo $meta['boekdb_annotatie_org'][0]; ?></p>
	<?php endif; ?>
	<hr />
<?php endif; ?>

<?php if ( isset( $meta['boekdb_flaptekst'] ) ) : ?>
	<h4>Flaptekst</h4>
	<textarea name="boekdb_flaptekst" id="boekdb_flaptekst" cols="120" rows="9"><?php echo $meta['boekdb_flaptekst'][0]; ?></textarea>
	<?php if ( $flaptekst_overwritten === '1' ) : ?>
		<br /><em>Originele flaptekst uit BoekDB:</em>
		<p><?php echo $meta['boekdb_flaptekst_org'][0]; ?></p>
	<?php endif; ?>
	<hr />
<?php endif; ?>

<?php if ( ! is_null( $quotes ) ) : ?>
	<h4>Recensiequotes</h4>
	<table class="widefat fixed">
		<tr>
			<th style="text-align: left;">Tonen</th>
			<th style="text-align: left;">Tekst</th>
			<th style="text-align: left;">Auteur</th>
			<th style="text-align: left;">Bron</th>
			<th style="text-align: left;">Datum</th>
		</tr>
	<?php foreach ( $quotes as $hash => $quote ) : ?>
		<?php $checked = $quote['tonen'] ? 'checked' : ''; ?>
		<input type="hidden" id="quote[<?php echo $hash; ?>]" value="off" name="quote[<?php echo $hash; ?>]">
		<tr>
			<td>
				<input type="checkbox" id="quote[<?php echo $hash; ?>]" value="on" name="quote[<?php echo $hash; ?>]" <?php echo $checked; ?>>
			</td>
			<td style="width: 450px;"><?php echo strip_tags( $quote['tekst'] ); ?></td>
			<td><?php echo $quote['auteur']; ?></td>
			<td><?php echo $quote['bron']; ?></td>
			<td><?php echo $quote['datum']; ?></td>
		</tr>
	<?php endforeach; ?>
	</table>
<?php endif; ?>

<h4>Overige velden:</h4>
<?php
$skip = array(
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
<?php foreach ( $meta as $name => $value ) : ?>
	<?php if ( boekdb_startswith( $name, 'boekdb_' ) ) : ?>
		<?php
		$name = substr( $name, 7 );
		if ( in_array( $name, $skip ) ) {
			continue;}
		?>
		<tr>
			<th style="vertical-align: top; text-align: left;"><?php echo $name; ?></th>
			<td><?php echo $value[0]; ?></td>
		</tr>
	<?php endif; ?>
<?php endforeach; ?>
</table>