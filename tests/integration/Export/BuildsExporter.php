<?php
/**
 * Exporter factory for tests.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Integration\Export;

use SelectiveEntitySync\Content\BlockReferenceMap;
use SelectiveEntitySync\Content\ContentReferenceFinder;
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
use SelectiveEntitySync\Storage\TempStorage;

/**
 * Builds an Exporter wired like the plugin does.
 */
trait BuildsExporter {

	protected function build_exporter(): Exporter {
		$settings = new ExportSettings();
		$meta     = new MetaCollector( $settings );
		$schema   = new Schema();

		return new Exporter(
			$settings,
			new EntityUuid(),
			array(
				new PostCollector( $settings, $meta, new ContentReferenceFinder( new BlockReferenceMap() ) ),
				new AttachmentCollector( $meta ),
				new TermCollector( $meta ),
			),
			new PackageWriter( new ManifestCodec( $schema ), $schema ),
			new TempStorage(),
			'0.1.0-test'
		);
	}

	/**
	 * Creates an attachment backed by a real uploaded file.
	 */
	protected function create_image_attachment( string $file = 'canola.jpg' ): int {
		return (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/' . $file );
	}
}
