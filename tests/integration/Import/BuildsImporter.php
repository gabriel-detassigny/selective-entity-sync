<?php
/**
 * Importer factory for tests.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Integration\Import;

use SelectiveEntitySync\Content\BlockReferenceMap;
use SelectiveEntitySync\Content\ContentReferenceFinder;
use SelectiveEntitySync\Content\ContentRewriter;
use SelectiveEntitySync\Export\ExportSettings;
use SelectiveEntitySync\Identity\EntityUuid;
use SelectiveEntitySync\Import\AuthorResolver;
use SelectiveEntitySync\Import\Handlers\AttachmentHandler;
use SelectiveEntitySync\Import\Handlers\PostHandler;
use SelectiveEntitySync\Import\Handlers\TermHandler;
use SelectiveEntitySync\Import\Importer;
use SelectiveEntitySync\Import\ImportPlanner;
use SelectiveEntitySync\Import\MetaImporter;
use SelectiveEntitySync\Manifest\ManifestCodec;
use SelectiveEntitySync\Manifest\Schema;
use SelectiveEntitySync\Package\Package;
use SelectiveEntitySync\Package\PackageLimits;
use SelectiveEntitySync\Package\PackageReader;
use SelectiveEntitySync\Storage\TempStorage;

/**
 * Builds an Importer wired like the plugin does, and reads packages.
 */
trait BuildsImporter {

	/**
	 * Temp directories to delete in tear_down.
	 *
	 * @var string[]
	 */
	protected $temp_dirs = array();

	protected function build_importer(): Importer {
		$settings = new ExportSettings();
		$meta     = new MetaImporter( $settings );
		$authors  = new AuthorResolver();
		$map      = new BlockReferenceMap();

		return new Importer(
			new ImportPlanner(
				new EntityUuid(),
				array(
					new PostHandler( $settings, $meta, $authors, new ContentReferenceFinder( $map ), new ContentRewriter( $map ) ),
					new AttachmentHandler( $meta, $authors ),
					new TermHandler( $meta ),
				)
			),
			new EntityUuid()
		);
	}

	protected function read_package( string $zip_path ): Package {
		$storage           = new TempStorage();
		$directory         = $storage->create_directory();
		$this->temp_dirs[] = $directory;

		return ( new PackageReader( new ManifestCodec( new Schema() ), new PackageLimits() ) )->read( $zip_path, $directory );
	}

	protected function delete_temp_dirs(): void {
		$storage = new TempStorage();
		foreach ( $this->temp_dirs as $directory ) {
			$storage->delete( $directory );
		}
		$this->temp_dirs = array();
	}
}
