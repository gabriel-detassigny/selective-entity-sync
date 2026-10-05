<?php
/**
 * Smoke tests: the plugin loads inside WordPress.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Integration;

use SelectiveEntitySync\Admin\AdminPage;
use WP_UnitTestCase;

/**
 * @coversNothing
 */
class PluginTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->reset_admin_menu();
	}

	public function tear_down(): void {
		$this->reset_admin_menu();
		parent::tear_down();
	}

	public function test_plugin_is_loaded(): void {
		$this->assertTrue( defined( 'SELECTIVE_ENTITY_SYNC_VERSION' ) );
		$this->assertGreaterThan( 0, did_action( 'selective_entity_sync_loaded' ) );
	}

	public function test_admin_page_is_registered_for_administrators(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		do_action( 'admin_menu' );

		$this->assertContains( AdminPage::MENU_SLUG, $this->get_tools_submenu_slugs() );
	}

	public function test_admin_page_is_hidden_from_editors(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		do_action( 'admin_menu' );

		$this->assertNotContains( AdminPage::MENU_SLUG, $this->get_tools_submenu_slugs() );
	}

	public function test_capability_filter_grants_access_to_editors(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		add_filter(
			'selective_entity_sync_capability',
			static function () {
				return 'edit_others_posts';
			}
		);

		do_action( 'admin_menu' );

		$this->assertContains( AdminPage::MENU_SLUG, $this->get_tools_submenu_slugs() );
	}

	/**
	 * @return string[]
	 */
	private function get_tools_submenu_slugs(): array {
		global $submenu;

		return array_column( $submenu['tools.php'] ?? array(), 2 );
	}

	/**
	 * Admin menu globals are not reset by the WP test suite between tests.
	 */
	private function reset_admin_menu(): void {
		global $menu, $submenu, $_registered_pages, $_parent_pages;

		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- Resetting core globals for test isolation.
		$menu              = array();
		$submenu           = array();
		$_registered_pages = array();
		$_parent_pages     = array();
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
	}
}
