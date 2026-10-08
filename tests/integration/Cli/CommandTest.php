<?php
/**
 * Tests for the WP-CLI command.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Integration\Cli;

use SelectiveEntitySync\Cli\Command;
use SelectiveEntitySync\Manifest\ManifestCodec;
use SelectiveEntitySync\Manifest\Schema;
use SelectiveEntitySync\Package\PackageLimits;
use SelectiveEntitySync\Package\PackageReader;
use SelectiveEntitySync\Storage\TempStorage;
use SelectiveEntitySync\Tests\Integration\Export\BuildsExporter;
use SelectiveEntitySync\Tests\Integration\Import\BuildsImporter;
use WP_CLI;
use WP_CLI\ExitException;
use WP_CLI\Loggers\Execution;
use WP_UnitTestCase;

// WP-CLI loads its helper functions itself when it boots; tests load them directly.
require_once dirname( __DIR__, 3 ) . '/vendor/wp-cli/wp-cli/php/utils.php';

/**
 * @covers \SelectiveEntitySync\Cli\Command
 */
class CommandTest extends WP_UnitTestCase {

	use BuildsExporter;
	use BuildsImporter;

	/**
	 * @var string
	 */
	private $work_dir;

	/**
	 * Records WP-CLI output.
	 *
	 * @var Execution
	 */
	private $logger;

	public function set_up(): void {
		parent::set_up();
		$this->work_dir = ( new TempStorage() )->create_directory();
		// The WP_CLI constant (trusted CLI runs) isn't defined under PHPUnit; act as an administrator instead.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		// Real WP-CLI (installed for the i18n tooling): record output, and make error() throw instead of exiting.
		$this->logger = new Execution();
		WP_CLI::set_logger( $this->logger );
		$this->set_capture_exit( true );
	}

	public function tear_down(): void {
		$this->set_capture_exit( false );
		if ( is_dir( $this->work_dir . '/readonly' ) ) {
			chmod( $this->work_dir . '/readonly', 0700 );
		}
		( new TempStorage() )->delete( $this->work_dir );
		parent::tear_down();
	}

	private function set_capture_exit( bool $capture ): void {
		$property = new \ReflectionProperty( WP_CLI::class, 'capture_exit' );
		$property->setAccessible( true );
		$property->setValue( null, $capture );
	}

	private function command(): Command {
		return new Command( $this->build_exporter(), new TempStorage(), new PackageReader( new ManifestCodec( new Schema() ), new PackageLimits() ), $this->build_importer() );
	}

	public function test_export_writes_the_package_to_the_given_file(): void {
		$post = self::factory()->post->create();
		$file = $this->work_dir . '/export.zip';

		$this->command()->export(
			array(),
			array(
				'post_ids' => (string) $post,
				'file'     => $file,
			) 
		);

		$this->assertFileExists( $file );
		$this->assertStringContainsString( 'Success: Exported', $this->logger->stdout );
	}

	public function test_export_to_an_unwritable_location_fails_cleanly_before_exporting(): void {
		$post     = self::factory()->post->create();
		$readonly = $this->work_dir . '/readonly';
		mkdir( $readonly );
		chmod( $readonly, 0500 );

		try {
			$this->command()->export(
				array(),
				array(
					'post_ids' => (string) $post,
					'file'     => $readonly . '/export.zip',
				) 
			);
			$this->fail( 'The export should have stopped.' );
		} catch ( ExitException $e ) {
			$error = $this->logger->stderr;
			$this->assertStringContainsString( 'not writable', $error );
			$this->assertStringContainsString( '--file', $error, 'The error tells the user how to fix it.' );
		}

		$this->assertNull( ( new \SelectiveEntitySync\Identity\EntityUuid() )->find( 'post', $post ), 'Nothing is exported (no UUIDs assigned) when the destination is unusable.' );
	}
}
