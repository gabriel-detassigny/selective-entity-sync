<?php
/**
 * Tests for Plugin.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Unit;

use Brain\Monkey\Actions;
use SelectiveEntitySync\Plugin;

/**
 * @covers \SelectiveEntitySync\Plugin
 */
class PluginTest extends TestCase {

	public function test_boot_registers_hooks_and_fires_loaded_action_once(): void {
		$plugin = new Plugin( '/path/to/selective-entity-sync.php', '1.2.3' );

		Actions\expectDone( 'selective_entity_sync_loaded' )->once()->with( $plugin );

		$plugin->boot();
		$plugin->boot();

		$this->assertNotFalse( has_action( 'init', 'SelectiveEntitySync\Support\I18n->load_textdomain()' ) );
		$this->assertNotFalse( has_action( 'admin_menu', 'SelectiveEntitySync\Admin\AdminPage->register_page()' ) );
	}

	public function test_get_version(): void {
		$this->assertSame( '1.2.3', ( new Plugin( '/path/to/file.php', '1.2.3' ) )->get_version() );
	}
}
