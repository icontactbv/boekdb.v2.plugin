<?php
/**
 * Voorbeeld-template voor ophalen van boek-informatie
 *
 * @package BoekDB
 */

get_header();

/* Start the Loop */
while ( have_posts() ) :
	the_post();
	?>
	<article id="post-
	<?php
	the_ID();
	?>
	" 
	<?php
	post_class();
	?>
	>

		<header class="entry-header alignwide">
			<?php
			the_title( '<h1 class="entry-title">', '</h1>' );
			?>
			<?php
			twenty_twenty_one_post_thumbnail();
			?>
		</header><!-- .entry-header -->

		<div class="entry-content">
			<?php
			the_content();

			$boekdb_boek_data = boekdb_boek_data( get_the_ID() );
			echo '<table>';
			echo '<tr><th>Key</th><th>Waarde</th></tr>';
			foreach ( $boekdb_boek_data as $boekdb_key => $boekdb_value ) {
				echo '<tr><td>' . esc_html( $boekdb_key ) . '</td><td>' . esc_html( $boekdb_value ) . '</td></tr>';
			}
			if ( isset( $boekdb_boek_data['file_voorbeeld_id'] ) ) {
				echo '<tr><td>Fragment</td><td>';
				echo wp_get_attachment_link( $boekdb_boek_data['file_voorbeeld_id'] );
				echo '</td></tr>';
			}
			if ( isset( $boekdb_boek_data['file_backcover_id'] ) ) {
				echo '<tr><td>Back cover</td><td>';
				$boekdb_image_attributes = wp_get_attachment_image_src( $boekdb_boek_data['file_backcover_id'] );
				if ( $boekdb_image_attributes ) :
					?>
					<a href="
					<?php
					echo esc_url( wp_get_original_image_url( $boekdb_boek_data['file_backcover_id'] ) );
					?>
					">
						<img src="
						<?php
						echo esc_url( $boekdb_image_attributes[0] );
						?>
						" width="
						<?php
						echo esc_attr( $boekdb_image_attributes[1] );
						?>
						" height="
						<?php
						echo esc_attr( $boekdb_image_attributes[2] );
						?>
						"/>
					</a>
					<?php
				endif;
				echo '</td></tr>';
			}
			if ( isset( $boekdb_boek_data['serie_beeld_id'] ) ) {
				echo '<tr><td>Seriebeeld</td><td>';
				$boekdb_image_attributes = wp_get_attachment_image_src( $boekdb_boek_data['serie_beeld_id'] );
				if ( $boekdb_image_attributes ) :
					?>
					<a href="
					<?php
					echo esc_url( wp_get_original_image_url( $boekdb_boek_data['serie_beeld_id'] ) );
					?>
					">
						<img src="
						<?php
						echo esc_url( $boekdb_image_attributes[0] );
						?>
						" width="
						<?php
						echo esc_attr( $boekdb_image_attributes[1] );
						?>
						" height="
						<?php
						echo esc_attr( $boekdb_image_attributes[2] );
						?>
						"/>
					</a>
					<?php
				endif;
				echo '</td></tr>';
			}
			echo '</table>';

			$boekdb_alle_betrokkenen = boekdb_betrokkenen_data( get_the_ID() );
			echo '<table>';
			foreach ( $boekdb_alle_betrokkenen as $boekdb_rol => $boekdb_betrokkenen ) {
				foreach ( $boekdb_betrokkenen as $boekdb_betrokkene ) {
					echo '<tr><td>';
					if ( isset( $boekdb_betrokkene['auteursfoto_id'] ) ) {
						echo '<strong>Auteursfoto</strong>:<br />';
						$boekdb_image_attributes = wp_get_attachment_image_src( $boekdb_betrokkene['auteursfoto_id'] );
						if ( $boekdb_image_attributes ) :
							?>
							<a href="
							<?php
							echo esc_url( wp_get_original_image_url( $boekdb_betrokkene['auteursfoto_id'] ) );
							?>
							">
								<img src="
								<?php
								echo esc_url( $boekdb_image_attributes[0] );
								?>
								" width="
								<?php
								echo esc_attr( $boekdb_image_attributes[1] );
								?>
								" height="
								<?php
								echo esc_attr( $boekdb_image_attributes[2] );
								?>
								"/>
							</a>
							<?php
						endif;
						echo '</td></tr>';
					}
				}
			}

			if ( isset( $boekdb_boek_data['actieprijzen'] ) ) {
				echo '<strong>Actieprijzen</strong><br />';
				$boekdb_actieprijzen = maybe_unserialize( $boekdb_boek_data['actieprijzen'] );
				foreach ( $boekdb_actieprijzen as $boekdb_actieprijs ) {
					echo 'Actieprijs: ' . esc_html( $boekdb_actieprijs['actieprijs'] ) . '<br />';
					// A date that cannot be read is left out rather than ending the page.
					$boekdb_van = DateTime::createFromFormat( 'Y-m-d', $boekdb_actieprijs['actieperiode_start'] );
					$boekdb_tot = DateTime::createFromFormat( 'Y-m-d', $boekdb_actieprijs['actieperiode_einde'] );
					if ( $boekdb_van ) {
						echo 'Van: ' . esc_html( $boekdb_van->format( 'd-m-Y' ) ) . '<br/>';
					}
					if ( $boekdb_tot ) {
						echo 'Tot: ' . esc_html( $boekdb_tot->format( 'd-m-Y' ) ) . '<br/>';
					}
					echo '<br />';
				}
			}

			if ( isset( $boekdb_boek_data['links'] ) ) {
				echo '<strong>Links</strong><br />';
				$boekdb_links = maybe_unserialize( $boekdb_boek_data['links'] );
				foreach ( $boekdb_links as $boekdb_link ) {
					echo 'Link naar ' . esc_html( $boekdb_link['soort'] ) . ' : ' . esc_html( $boekdb_link['url'] ) . '<br />';
				}
			}

			if ( isset( $boekdb_boek_data['literaireprijzen'] ) ) {
				echo '<strong>Literaire prijzen</strong><br />';
				$boekdb_literaireprijzen = maybe_unserialize( $boekdb_boek_data['literaireprijzen'] );
				foreach ( $boekdb_literaireprijzen as $boekdb_literaireprijs ) {
					echo esc_html( $boekdb_literaireprijs['prestatie'] ) . ' ' . esc_html( $boekdb_literaireprijs['naam'] ) . ' ' . esc_html( $boekdb_literaireprijs['jaar'] ) . '<br />';
					echo esc_html( $boekdb_literaireprijs['omschrijving'] ) . '<br />';
					echo esc_html( $boekdb_literaireprijs['land'] ) . '<br />';
				}
			}

			if ( isset( $boekdb_boek_data['recensielinks'] ) ) {
				echo '<strong>Recensies</strong><br />';
				$boekdb_recensielinks = maybe_unserialize( $boekdb_boek_data['recensielinks'] );
				foreach ( $boekdb_recensielinks as $boekdb_link ) {
					echo esc_html( $boekdb_link['bron'] ) . ' ' . esc_html( $boekdb_link['datum'] ) . ' ' . esc_html( $boekdb_link['soort'] ) . ':<br />';
					echo esc_html( $boekdb_link['url'] ) . '<br />';
				}
			}

			if ( isset( $boekdb_boek_data['recensiequotes'] ) ) {
				echo '<strong>Quotes</strong><br />';
				$boekdb_recensiequotes = maybe_unserialize( $boekdb_boek_data['recensiequotes'] );
				foreach ( $boekdb_recensiequotes as $boekdb_quote ) {
					echo wp_kses_post( $boekdb_quote['tekst'] ) . '<br />';
					echo esc_html( $boekdb_quote['auteur'] ) . ' - ' . esc_html( $boekdb_quote['bron'] ) . ' - ' . esc_html( $boekdb_quote['datum'] ) . '<br />';
				}
			}


			wp_link_pages(
				array(
					'before'   => '<nav class="page-links" aria-label="' . esc_attr__(
						'Page',
						'boekdb'
					) . '">',
					'after'    => '</nav>',
					/* translators: %: Page number. */
					'pagelink' => esc_html__( 'Page %', 'boekdb' ),
				)
			);
	?>
		</div><!-- .entry-content -->

		<footer class="entry-footer default-max-width">
			<?php
			twenty_twenty_one_entry_meta_footer();
			?>
		</footer><!-- .entry-footer -->

		<?php
		if ( ! is_singular( 'attachment' ) ) :
			?>
			<?php
			get_template_part( 'template-parts/post/author-bio' );
			?>
			<?php
		endif;
		?>

	</article><!-- #post-
	<?php
	the_ID();
	?>
	-->
	<?php
endwhile; // End of the loop.

get_footer();
