<?php
/**
 * Translation loading tests.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Integration;

use SelectiveEntitySync\Admin\AdminPage;
use SelectiveEntitySync\Support\I18n;
use WP_UnitTestCase;

/**
 * @covers \SelectiveEntitySync\Support\I18n
 */
class I18nTest extends WP_UnitTestCase {

	private function use_french(): void {
		$french = static function () {
			return 'fr_FR';
		};
		add_filter( 'plugin_locale', $french );
		add_filter( 'determine_locale', $french );
	}

	public function tear_down(): void {
		unload_textdomain( I18n::TEXT_DOMAIN );
		parent::tear_down();
	}

	public function test_php_strings_are_translated_from_the_bundled_files(): void {
		$this->use_french();
		unload_textdomain( I18n::TEXT_DOMAIN );

		( new I18n( SELECTIVE_ENTITY_SYNC_FILE ) )->load_textdomain();

		$this->assertSame( 'Sélectionnez au moins un élément à exporter.', __( 'Select at least one item to export.', 'selective-entity-sync' ) );
		/* translators: %d: Maximum number of items. */
		$this->assertSame( 'Vous pouvez exporter au maximum 5 éléments à la fois.', sprintf( __( 'You can export at most %d items at once.', 'selective-entity-sync' ), 5 ) );
	}

	public function test_script_translations_resolve_for_the_built_script(): void {
		$this->use_french();
		wp_register_script( AdminPage::SCRIPT_HANDLE, plugins_url( 'build/index.js', SELECTIVE_ENTITY_SYNC_FILE ), array(), '1', true );

		$json = load_script_textdomain( AdminPage::SCRIPT_HANDLE, I18n::TEXT_DOMAIN, ( new I18n( SELECTIVE_ENTITY_SYNC_FILE ) )->get_languages_path() );

		$this->assertIsString( $json, 'The JSON file name must match the md5 of build/index.js.' );
		$this->assertStringContainsString( '"Export":["Exporter"]', $json );
		$this->assertStringContainsString( '"%d file":["%d fichier","%d fichiers"]', $json, 'Plurals use French plural forms.' );
		$this->assertStringContainsString( 'nplurals=2; plural=(n > 1);', $json );
	}

	public function test_pot_file_lists_every_string_in_the_source(): void {
		$pot = (string) file_get_contents( dirname( __DIR__, 2 ) . '/languages/selective-entity-sync.pot' );

		foreach ( array( 'Select at least one item to export.', 'Sorry, you are not allowed to sync content.', 'Download package', 'Import %d item' ) as $string ) {
			$this->assertStringContainsString( 'msgid "' . $string . '"', $pot );
		}
	}
}
