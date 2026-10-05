<?php
/**
 * Internationalization.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Support;

use SelectiveEntitySync\Contracts\Hookable;

/**
 * Loads the plugin's translations.
 *
 * The plugin is not hosted on WordPress.org yet, so translations bundled in
 * /languages have to be loaded explicitly.
 */
class I18n implements Hookable {

	public const TEXT_DOMAIN = 'selective-entity-sync';

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file Absolute path to the main plugin file.
	 */
	public function __construct( string $plugin_file ) {
		$this->plugin_file = $plugin_file;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Loads PHP translations.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( self::TEXT_DOMAIN, false, dirname( plugin_basename( $this->plugin_file ) ) . '/languages' );
	}

	/**
	 * Returns the absolute path to the languages directory, for script translations.
	 *
	 * @return string
	 */
	public function get_languages_path(): string {
		return plugin_dir_path( $this->plugin_file ) . 'languages';
	}
}
