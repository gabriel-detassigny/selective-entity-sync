<?php
/**
 * Import job.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Import;

/**
 * An import in progress, which can be run in several batches (requests).
 *
 * Holds the write order, the position reached, the entities waiting for a
 * second write (dependency cycles), and the saved context and report. It is
 * plain data so it can be stored between requests (see to_array()).
 */
class ImportJob {

	public const PHASE_WRITE = 'write';

	public const PHASE_FIXUP = 'fixup';

	public const PHASE_DONE = 'done';

	/**
	 * Plan items to write, keyed by UUID.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $items;

	/**
	 * UUIDs in write order.
	 *
	 * @var string[]
	 */
	private $order;

	/**
	 * Current phase.
	 *
	 * @var string
	 */
	private $phase = self::PHASE_WRITE;

	/**
	 * Index of the next entity to process in the current phase.
	 *
	 * @var int
	 */
	private $position = 0;

	/**
	 * UUIDs written successfully during the write phase.
	 *
	 * @var string[]
	 */
	private $written = array();

	/**
	 * Saved ImportContext state.
	 *
	 * @var array<string, mixed>
	 */
	private $context_state = array();

	/**
	 * Report so far.
	 *
	 * @var ImportReport
	 */
	private $report;

	/**
	 * Constructor.
	 *
	 * @param array<string, array<string, mixed>> $items  Plan items to write, keyed by UUID.
	 * @param string[]                            $order  UUIDs in write order.
	 * @param ImportReport                        $report Report holding the entities that are not written.
	 */
	public function __construct( array $items, array $order, ImportReport $report ) {
		$this->items  = $items;
		$this->order  = $order;
		$this->report = $report;
	}

	/**
	 * Rebuilds a job saved with to_array().
	 *
	 * @param array<string, mixed> $data Saved job.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$report = new ImportReport();
		$report->restore_state( (array) ( $data['report'] ?? array() ) );

		$job                = new self( (array) ( $data['items'] ?? array() ), array_map( 'strval', (array) ( $data['order'] ?? array() ) ), $report );
		$job->phase         = in_array( $data['phase'] ?? '', array( self::PHASE_WRITE, self::PHASE_FIXUP, self::PHASE_DONE ), true ) ? (string) $data['phase'] : self::PHASE_WRITE;
		$job->position      = max( 0, (int) ( $data['position'] ?? 0 ) );
		$job->written       = array_map( 'strval', (array) ( $data['written'] ?? array() ) );
		$job->context_state = (array) ( $data['context'] ?? array() );

		return $job;
	}

	/**
	 * Returns the job as plain data for storage.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'items'    => $this->items,
			'order'    => $this->order,
			'phase'    => $this->phase,
			'position' => $this->position,
			'written'  => $this->written,
			'context'  => $this->context_state,
			'report'   => $this->report->get_state(),
		);
	}

	/**
	 * Returns a plan item to write.
	 *
	 * @param string $uuid Entity UUID.
	 * @return array<string, mixed>|null
	 */
	public function get_item( string $uuid ): ?array {
		return $this->items[ $uuid ] ?? null;
	}

	/**
	 * Returns the UUIDs to write, in order.
	 *
	 * @return string[]
	 */
	public function get_order(): array {
		return $this->order;
	}

	/**
	 * Returns the current phase.
	 *
	 * @return string
	 */
	public function get_phase(): string {
		return $this->phase;
	}

	/**
	 * Moves to a phase, restarting its position.
	 *
	 * @param string $phase One of the PHASE_* constants.
	 * @return void
	 */
	public function set_phase( string $phase ): void {
		$this->phase    = $phase;
		$this->position = 0;
	}

	/**
	 * Returns the position in the current phase.
	 *
	 * @return int
	 */
	public function get_position(): int {
		return $this->position;
	}

	/**
	 * Advances the position in the current phase.
	 *
	 * @return void
	 */
	public function advance(): void {
		++$this->position;
	}

	/**
	 * Records an entity written during the write phase.
	 *
	 * @param string $uuid Entity UUID.
	 * @return void
	 */
	public function mark_written( string $uuid ): void {
		$this->written[] = $uuid;
	}

	/**
	 * Returns the UUIDs written during the write phase.
	 *
	 * @return string[]
	 */
	public function get_written(): array {
		return $this->written;
	}

	/**
	 * Returns the saved context state.
	 *
	 * @return array<string, mixed>
	 */
	public function get_context_state(): array {
		return $this->context_state;
	}

	/**
	 * Saves the context state.
	 *
	 * @param array<string, mixed> $state Context state.
	 * @return void
	 */
	public function set_context_state( array $state ): void {
		$this->context_state = $state;
	}

	/**
	 * Returns the report so far.
	 *
	 * @return ImportReport
	 */
	public function get_report(): ImportReport {
		return $this->report;
	}

	/**
	 * Whether the import is finished.
	 *
	 * @return bool
	 */
	public function is_done(): bool {
		return self::PHASE_DONE === $this->phase;
	}

	/**
	 * Returns the progress of the write phase.
	 *
	 * @return array{processed: int, total: int}
	 */
	public function get_progress(): array {
		$total = count( $this->order );

		return array(
			'processed' => self::PHASE_WRITE === $this->phase ? min( $this->position, $total ) : $total,
			'total'     => $total,
		);
	}
}
