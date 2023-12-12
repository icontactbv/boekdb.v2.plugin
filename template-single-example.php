<?php
/**
 * Voorbeeld-template voor ophalen van boek-informatie
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

			$boek_data = boekdb_boek_data( get_the_ID() );
			echo '<table>';
			echo '<tr><th>Key</th><th>Waarde</th></tr>';
			foreach ( $boek_data as $key => $value ) {
				echo '<tr><td>' . $key . '</td><td>' . $value . '</td></tr>';
			}
			if ( isset( $boek_data['file_voorbeeld_id'] ) ) {
				echo '<tr><td>Fragment</td><td>';
				echo wp_get_attachment_link( $boek_data['file_voorbeeld_id'] );
				echo '</td></tr>';
			}
			if ( isset( $boek_data['file_backcover_id'] ) ) {
				echo '<tr><td>Back cover</td><td>';
				$image_attributes = wp_get_attachment_image_src( $boek_data['file_backcover_id'] );
				if ( $image_attributes ) :
					?>
					<a href="
					<?php
					echo wp_get_original_image_url( $boek_data['file_backcover_id'] )
					?>
					">
						<img src="
						<?php
						echo $image_attributes[0];
						?>
						" width="
						<?php
						echo $image_attributes[1];
						?>
						" height="
						<?php
						echo $image_attributes[2];
						?>
						"/>
					</a>
					<?php
				endif;
				echo '</td></tr>';
			}
			if ( isset( $boek_data['serie_beeld_id'] ) ) {
				echo '<tr><td>Seriebeeld</td><td>';
				$image_attributes = wp_get_attachment_image_src( $boek_data['serie_beeld_id'] );
				if ( $image_attributes ) :
					?>
					<a href="
					<?php
					echo wp_get_original_image_url( $boek_data['serie_beeld_id'] )
					?>
					">
						<img src="
						<?php
						echo $image_attributes[0];
						?>
						" width="
						<?php
						echo $image_attributes[1];
						?>
						" height="
						<?php
						echo $image_attributes[2];
						?>
						"/>
					</a>
					<?php
				endif;
				echo '</td></tr>';
			}
			echo '</table>';

			$alle_betrokkenen = boekdb_betrokkenen_data( get_the_ID() );
			echo '<table>';
			foreach ( $alle_betrokkenen as $rol => $betrokkenen ) {
				foreach ( $betrokkenen as $betrokkene ) {
					echo '<tr><td>';
					if ( isset( $betrokkene['auteursfoto_id'] ) ) {
						echo '<strong>Auteursfoto</strong>:<br />';
						$image_attributes = wp_get_attachment_image_src( $betrokkene['auteursfoto_id'] );
						if ( $image_attributes ) :
							?>
							<a href="
							<?php
							echo wp_get_original_image_url( $betrokkene['auteursfoto_id'] )
							?>
							">
								<img src="
								<?php
								echo $image_attributes[0];
								?>
								" width="
								<?php
								echo $image_attributes[1];
								?>
								" height="
								<?php
								echo $image_attributes[2];
								?>
								"/>
							</a>
							<?php
						endif;
						echo '</td></tr>';
					}
				}
			}

			if ( isset( $boek_data['actieprijzen'] ) ) {
				echo '<strong>Actieprijzen</strong><br />';
				$actieprijzen = unserialize( $boek_data['actieprijzen'] );
				foreach ( $actieprijzen as $actieprijs ) {
					echo 'Actieprijs: ' . $actieprijs['actieprijs'] . '<br />';
					echo 'Van: ' . DateTime::createFromFormat(
						'Y-m-d',
						$actieprijs['actieperiode_start']
					)->format( 'd-m-Y' ) . '<br/>';
					echo 'Tot: ' . DateTime::createFromFormat(
						'Y-m-d',
						$actieprijs['actieperiode_einde']
					)->format( 'd-m-Y' ) . '<br/>';
					echo '<br />';
				}
			}

			if ( isset( $boek_data['links'] ) ) {
				echo '<strong>Links</strong><br />';
				$links = unserialize( $boek_data['links'] );
				foreach ( $links as $link ) {
					echo 'Link naar ' . $link['soort'] . ' : ' . $link['url'] . '<br />';
				}
			}

			if ( isset( $boek_data['literaireprijzen'] ) ) {
				echo '<strong>Literaire prijzen</strong><br />';
				$literaireprijzen = unserialize( $boek_data['literaireprijzen'] );
				foreach ( $literaireprijzen as $literaireprijs ) {
					echo $literaireprijs['prestatie'] . ' ' . $literaireprijs['naam'] . ' ' . $literaireprijs['jaar'] . '<br />';
					echo $literaireprijs['omschrijving'] . '<br />';
					echo $literaireprijs['land'] . '<br />';
				}
			}

			if ( isset( $boek_data['recensielinks'] ) ) {
				echo '<strong>Recensies</strong><br />';
				$recensielinks = unserialize( $boek_data['recensielinks'] );
				foreach ( $recensielinks as $link ) {
					echo $link['bron'] . ' ' . $link['datum'] . ' ' . $link['soort'] . ':<br />';
					echo $link['url'] . '<br />';
				}
			}

			if ( isset( $boek_data['recensiequotes'] ) ) {
				echo '<strong>Quotes</strong><br />';
				$recensiequotes = unserialize( $boek_data['recensiequotes'] );
				foreach ( $recensiequotes as $quote ) {
					echo $quote['tekst'] . '<br />';
					echo $quote['auteur'] . ' - ' . $quote['bron'] . ' - ' . $quote['datum'] . '<br />';
				}
			}


			wp_link_pages(
				array(
					'before'   => '<nav class="page-links" aria-label="' . esc_attr__(
						'Page',
						'twentytwentyone'
					) . '">',
					'after'    => '</nav>',
					/* translators: %: Page number. */
					'pagelink' => esc_html__( 'Page %', 'twentytwentyone' ),
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
