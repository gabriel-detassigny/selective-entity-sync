<?php
/**
 * Plugin composition root.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync;

use SelectiveEntitySync\Admin\AdminPage;
use SelectiveEntitySync\Cli\CliCommands;
use SelectiveEntitySync\Cli\Command;
use SelectiveEntitySync\Content\BlockReferenceMap;
use SelectiveEntitySync\Content\ContentReferenceFinder;
use SelectiveEntitySync\Contracts\Hookable;
use SelectiveEntitySync\Export\Collectors\AttachmentCollector;
use SelectiveEntitySync\Export\Collectors\MetaCollector;
use SelectiveEntitySync\Export\Collectors\PostCollector;
use SelectiveEntitySync\Export\Collectors\TermCollector;
use SelectiveEntitySync\Export\Exporter;
use SelectiveEntitySync\Export\ExportSettings;
use SelectiveEntitySync\Identity\EntityUuid;
use SelectiveEntitySync\Manifest\ManifestCodec;
use SelectiveEntitySync\Manifest\Schema;
use SelectiveEntitySync\Package\PackageWriter;
use SelectiveEntitySync\Rest\EntitiesController;
use SelectiveEntitySync\Rest\ExportController;
use SelectiveEntitySync\Storage\TempStorage;
use SelectiveEntitySync\Support\Capabilities;
use SelectiveEntitySync\Support\I18n;

/**
 * Builds the plugin services and registers their hooks.
 */
class Plugin {

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file Absolute path to the main plugin file.
	 * @param string $version     Plugin version.
	 */
	public function __construct( string $plugin_file, string $version ) {
		$this->plugin_file = $plugin_file;
		$this->version     = $version;
	}

	/**
	 * Returns the plugin version.
	 *
	 * @return string
	 */
	public function get_version(): string {
		return $this->version;
	}

	/**
	 * Builds services and registers hooks. Safe to call more than once.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		foreach ( $this->get_services() as $service ) {
			$service->register_hooks();
		}

		/**
		 * Fires once Selective Entity Sync has registered all of its services.
		 *
		 * Add-ons should hook here to register their own integrations.
		 *
		 * @since 0.1.0
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'selective_entity_sync_loaded', $this );
	}

	/**
	 * Builds the list of hookable services.
	 *
	 * @return Hookable[]
	 */
	private function get_services(): array {
		$capabilities = new Capabilities();
		$i18n         = new I18n( $this->plugin_file );
		$storage      = new TempStorage();
		$settings     = new ExportSettings();
		$uuids        = new EntityUuid();
		$schema       = new Schema();
		$meta         = new MetaCollector( $settings );
		$exporter     = new Exporter(
			$settings,
			$uuids,
			array(
				new PostCollector( $settings, $meta, new ContentReferenceFinder( new BlockReferenceMap() ) ),
				new AttachmentCollector( $meta ),
				new TermCollector( $meta ),
			),
			new PackageWriter( new ManifestCodec( $schema ), $schema ),
			$storage,
			$this->version
		);

		return array(
			$i18n,
			new AdminPage( $this->plugin_file, $capabilities, $i18n ),
			$storage,
			new EntitiesController( $capabilities, $settings, $uuids ),
			new ExportController( $capabilities, $settings, $exporter, $storage ),
			new CliCommands( new Command( $exporter, $storage ) ),
		);
	}
}
