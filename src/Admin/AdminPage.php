<?php
/**
 * Admin page.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Admin;

use SelectiveEntitySync\Contracts\Hookable;
use SelectiveEntitySync\Support\Capabilities;
use SelectiveEntitySync\Support\I18n;

/**
 * Registers the Tools → Selective Entity Sync screen, which hosts the React app.
 */
class AdminPage implements Hookable {

	public const MENU_SLUG = 'selective-entity-sync';

	public const SCRIPT_HANDLE = 'selective-entity-sync-admin';

	public const ROOT_ELEMENT_ID = 'selective-entity-sync-root';

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Capability checker.
	 *
	 * @var Capabilities
	 */
	private $capabilities;

	/**
	 * Translations loader.
	 *
	 * @var I18n
	 */
	private $i18n;

	/**
	 * Hook suffix returned by add_management_page(), or empty before registration.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param string       $plugin_file  Absolute path to the main plugin file.
	 * @param Capabilities $capabilities Capability checker.
	 * @param I18n         $i18n         Translations loader.
	 */
	public function __construct( string $plugin_file, Capabilities $capabilities, I18n $i18n ) {
		$this->plugin_file  = $plugin_file;
		$this->capabilities = $capabilities;
		$this->i18n         = $i18n;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Adds the page under the Tools menu.
	 *
	 * @return void
	 */
	public function register_page(): void {
		$hook_suffix = add_management_page(
			__( 'Selective Entity Sync', 'selective-entity-sync' ),
			__( 'Selective Entity Sync', 'selective-entity-sync' ),
			$this->capabilities->get_capability(),
			self::MENU_SLUG,
			array( $this, 'render' )
		);

		$this->hook_suffix = is_string( $hook_suffix ) ? $hook_suffix : '';
	}

	/**
	 * Outputs the page container the React app mounts into.
	 *
	 * @return void
	 */
	public function render(): void {
		printf(
			'<div class="wrap"><h1>%1$s</h1><div id="%2$s"></div></div>',
			esc_html__( 'Selective Entity Sync', 'selective-entity-sync' ),
			esc_attr( self::ROOT_ELEMENT_ID )
		);
	}

	/**
	 * Enqueues the admin app on the plugin page only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		$asset_file = plugin_dir_path( $this->plugin_file ) . 'build/index.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'build/index.js', $this->plugin_file ),
			$asset['dependencies'],
			$asset['version'],
			array( 'in_footer' => true )
		);

		wp_set_script_translations( self::SCRIPT_HANDLE, I18n::TEXT_DOMAIN, $this->i18n->get_languages_path() );

		wp_enqueue_style( 'wp-components' );

		if ( is_readable( plugin_dir_path( $this->plugin_file ) . 'build/style-index.css' ) ) {
			wp_enqueue_style(
				self::SCRIPT_HANDLE,
				plugins_url( 'build/style-index.css', $this->plugin_file ),
				array( 'wp-components' ),
				$asset['version']
			);
			wp_style_add_data( self::SCRIPT_HANDLE, 'rtl', 'replace' );
		}
	}
}
