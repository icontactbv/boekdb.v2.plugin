<?php
/**
 * Voorbeeld-template voor ophalen van boek-informatie
 *
 */

get_header();

/* Start the Loop */
while ( have_posts() ) :
	the_post();
	?>
    <article id="post-<?php
	the_ID(); ?>" <?php
	post_class(); ?>>

        <header class="entry-header alignwide">
			<?php
			the_title( '<h1 class="entry-title">', '</h1>' ); ?>
			<?php
			twenty_twenty_one_post_thumbnail(); ?>
        </header><!-- .entry-header -->

        <div class="entry-content">
            <?php
		the_content();

		$data = boekdb_boek_data(get_the_ID());
		echo '<table>';
		echo '<tr><th>Key</th><th>Waarde</th></tr>';
		foreach($data as $key => $value) {
		    echo '<tr><td>'.$key.'</td><td>'.$value.'</td></tr>';
        }
		if(isset($data['file_voorbeeld_id'])) {
		    echo '<tr><td>Fragment</td><td>';
		    echo wp_get_attachment_link( $data['file_voorbeeld_id'] );
		    echo '</td></tr>';
		}
		if(isset($data['file_backcover_id'])) {
		    echo '<tr><td>Back cover</td><td>';
			$image_attributes = wp_get_attachment_image_src( $data['file_backcover_id'] );
			if ( $image_attributes ) : ?>
                <a href="<?php echo wp_get_original_image_url( $data['file_backcover_id'] ) ?>">
                    <img src="<?php echo $image_attributes[0]; ?>" width="<?php echo $image_attributes[1]; ?>" height="<?php echo $image_attributes[2]; ?>" />
                </a>
			<?php endif;
			echo '</td></tr>';
		}
		echo '</table>';

		wp_link_pages(
			array(
				'before'   => '<nav class="page-links" aria-label="' . esc_attr__( 'Page', 'twentytwentyone' ) . '">',
				'after'    => '</nav>',
				/* translators: %: Page number. */
				'pagelink' => esc_html__( 'Page %', 'twentytwentyone' ),
			)
		);
		?>
        </div><!-- .entry-content -->

        <footer class="entry-footer default-max-width">
			<?php
			twenty_twenty_one_entry_meta_footer(); ?>
        </footer><!-- .entry-footer -->

		<?php
		if ( ! is_singular( 'attachment' ) ) : ?>
			<?php
			get_template_part( 'template-parts/post/author-bio' ); ?>
		<?php
		endif; ?>

    </article><!-- #post-<?php
the_ID(); ?> -->
<?php
endwhile; // End of the loop.

get_footer();
