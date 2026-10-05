<?php
/**
 * WP-CLI command.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Cli;

use SelectiveEntitySync\Exception\SyncException;
use SelectiveEntitySync\Export\Exporter;
use SelectiveEntitySync\Storage\TempStorage;
use WP_CLI;

/**
 * Sync selected content between WordPress sites.
 */
class Command {

	/**
	 * Exporter.
	 *
	 * @var Exporter
	 */
	private $exporter;

	/**
	 * Temporary storage.
	 *
	 * @var TempStorage
	 */
	private $storage;

	/**
	 * Constructor.
	 *
	 * @param Exporter    $exporter Exporter.
	 * @param TempStorage $storage  Temporary storage.
	 */
	public function __construct( Exporter $exporter, TempStorage $storage ) {
		$this->exporter = $exporter;
		$this->storage  = $storage;
	}

	/**
	 * Exports content and its dependencies to a package.
	 *
	 * Dependencies (parent pages, terms, featured images, media and synced
	 * patterns used in content) are included automatically.
	 *
	 * ## OPTIONS
	 *
	 * --post_ids=<ids>
	 * : Comma-separated IDs of the posts, pages or other content to export.
	 *
	 * [--file=<path>]
	 * : Where to write the package. Default: selective-entity-sync-export-<date>.zip in the current directory.
	 *
	 * [--dry-run]
	 * : List what would be exported without writing a package.
	 *
	 * ## EXAMPLES
	 *
	 *     # Export two pages and everything they depend on.
	 *     $ wp selective-entity-sync export --post_ids=12,34 --file=/tmp/sync.zip
	 *
	 *     # See what would be exported.
	 *     $ wp selective-entity-sync export --post_ids=12 --dry-run
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Associative arguments.
	 * @return void
	 */
	public function export( array $args, array $assoc_args ): void {
		$post_ids = array_filter( array_map( 'intval', explode( ',', (string) ( $assoc_args['post_ids'] ?? '' ) ) ) );

		try {
			if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ) ) {
				$this->print_summary( $this->exporter->plan( $post_ids )->get_summary() );
				return;
			}

			$result = $this->exporter->export( $post_ids );
		} catch ( SyncException $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}

		$destination = (string) ( $assoc_args['file'] ?? getcwd() . '/selective-entity-sync-export-' . gmdate( 'Ymd-His' ) . '.zip' );
		$copied      = copy( $result->get_package_path(), $destination );
		$this->storage->delete( $result->get_directory() );

		$this->print_summary( $result->get_plan()->get_summary() );

		if ( ! $copied ) {
			WP_CLI::error( sprintf( 'Could not write the package to %s.', $destination ) );
			return;
		}

		WP_CLI::success( sprintf( 'Exported %d entities to %s.', count( $result->get_manifest()->get_entities() ), $destination ) );
	}

	/**
	 * Prints an export summary.
	 *
	 * @param array{entities: array<int, array<string, mixed>>, file_count: int, file_bytes: int, warnings: string[]} $summary Summary.
	 * @return void
	 */
	private function print_summary( array $summary ): void {
		$rows = array();
		foreach ( $summary['entities'] as $entity ) {
			$rows[] = array(
				'type'      => $entity['type'],
				'subtype'   => $entity['subtype'],
				'source_id' => $entity['source_id'],
				'title'     => $entity['title'],
				'included'  => $entity['reference_only'] ? 'reference only' : ( $entity['selected'] ? 'selected' : 'dependency' ),
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'type', 'subtype', 'source_id', 'title', 'included' ) );
		WP_CLI::log( sprintf( '%d file(s), %s.', $summary['file_count'], size_format( $summary['file_bytes'] ) ) );

		foreach ( $summary['warnings'] as $warning ) {
			WP_CLI::warning( $warning );
		}
	}
}
