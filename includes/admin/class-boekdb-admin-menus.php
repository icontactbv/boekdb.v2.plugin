<?php
/**
 * Setup menus in WP admin.
 *
 * @package BoekDB\Admin
 * @version 2.5.0
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'BoekDB_Admin_Menus', false ) ) {
	return new BoekDB_Admin_Menus();
}

/**
 * BoekDB_Admin_Menus Class.
 */
class BoekDB_Admin_Menus {

	/**
	 * Hook in tabs.
	 */
	public function __construct() {
		// Add menus.
		add_action( 'admin_menu', array( $this, 'settings_menu' ), 50 );
	}

	/**
	 * Add menu item.
	 */
	public function settings_menu() {
		$settings_page = add_options_page( 'BoekDB', 'BoekDB', 'activate_plugins', 'boekdb-settings', array( $this, 'settings_page' ) );
	}

	/**
	 * Init the settings page.
	 */
	public function settings_page() {
		BoekDB_Admin_Settings::output();
	}

	/**
	 * Add custom nav meta box.
	 *
	 * Adapted from http://www.johnmorrisonline.com/how-to-add-a-fully-functional-custom-meta-box-to-wordpress-navigation-menus/.
	 */
	public function add_nav_menu_meta_boxes() {
		add_meta_box( 'boekdb_endpoints_nav_link', __( 'BoekDB endpoints', 'boekdb' ), array( $this, 'nav_menu_links' ), 'nav-menus', 'side', 'low' );
	}

	/**
	 * Output menu links.
	 */
	public function nav_menu_links() {
		// Get items from account menu.
		$endpoints = boekdb_get_account_menu_items();

		// Remove dashboard item.
		if ( isset( $endpoints['dashboard'] ) ) {
			unset( $endpoints['dashboard'] );
		}

		// Include missing lost password.
		$endpoints['lost-password'] = __( 'Lost password', 'boekdb' );

		$endpoints = apply_filters( 'boekdb_custom_nav_menu_items', $endpoints );

		?>
		<div id="posttype-boekdb-endpoints" class="posttypediv">
			<div id="tabs-panel-boekdb-endpoints" class="tabs-panel tabs-panel-active">
				<ul id="boekdb-endpoints-checklist" class="categorychecklist form-no-clear">
					<?php
					$i = -1;
					foreach ( $endpoints as $key => $value ) :
						?>
						<li>
							<label class="menu-item-title">
								<input type="checkbox" class="menu-item-checkbox" name="menu-item[<?php echo esc_attr( $i ); ?>][menu-item-object-id]" value="<?php echo esc_attr( $i ); ?>" /> <?php echo esc_html( $value ); ?>
							</label>
							<input type="hidden" class="menu-item-type" name="menu-item[<?php echo esc_attr( $i ); ?>][menu-item-type]" value="custom" />
							<input type="hidden" class="menu-item-title" name="menu-item[<?php echo esc_attr( $i ); ?>][menu-item-title]" value="<?php echo esc_attr( $value ); ?>" />
							<input type="hidden" class="menu-item-url" name="menu-item[<?php echo esc_attr( $i ); ?>][menu-item-url]" value="<?php echo esc_url( boekdb_get_account_endpoint_url( $key ) ); ?>" />
							<input type="hidden" class="menu-item-classes" name="menu-item[<?php echo esc_attr( $i ); ?>][menu-item-classes]" />
						</li>
						<?php
						$i--;
					endforeach;
					?>
				</ul>
			</div>
			<p class="button-controls">
				<span class="list-controls">
					<a href="<?php echo esc_url( admin_url( 'nav-menus.php?page-tab=all&selectall=1#posttype-boekdb-endpoints' ) ); ?>" class="select-all"><?php esc_html_e( 'Select all', 'boekdb' ); ?></a>
				</span>
				<span class="add-to-menu">
					<button type="submit" class="button-secondary submit-add-to-menu right" value="<?php esc_attr_e( 'Add to menu', 'boekdb' ); ?>" name="add-post-type-menu-item" id="submit-posttype-boekdb-endpoints"><?php esc_html_e( 'Add to menu', 'boekdb' ); ?></button>
					<span class="spinner"></span>
				</span>
			</p>
		</div>
		<?php
	}

	/**
	 * Add the "Visit Store" link in admin bar main menu.
	 *
	 * @since 2.4.0
	 * @param WP_Admin_Bar $wp_admin_bar Admin bar instance.
	 */
	public function admin_bar_menus( $wp_admin_bar ) {
		if ( ! is_admin() || ! is_admin_bar_showing() ) {
			return;
		}

		// Show only when the user is a member of this site, or they're a super admin.
		if ( ! is_user_member_of_blog() && ! is_super_admin() ) {
			return;
		}

		// Don't display when shop page is the same of the page on front.
		if ( intval( get_option( 'page_on_front' ) ) === boekdb_get_page_id( 'shop' ) ) {
			return;
		}

		// Add an option to visit the store.
		$wp_admin_bar->add_node(
			array(
				'parent' => 'site-name',
				'id'     => 'view-store',
				'title'  => __( 'Visit Store', 'boekdb' ),
				'href'   => boekdb_get_page_permalink( 'shop' ),
			)
		);
	}
}

return new BoekDB_Admin_Menus();
