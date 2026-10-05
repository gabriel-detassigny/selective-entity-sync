<?php
/**
 * WP-CLI registration.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Cli;

use SelectiveEntitySync\Contracts\Hookable;
use WP_CLI;

/**
 * Registers the `wp selective-entity-sync` command when running under WP-CLI.
 */
class CliCommands implements Hookable {

	/**
	 * Command instance.
	 *
	 * @var Command
	 */
	private $command;

	/**
	 * Constructor.
	 *
	 * @param Command $command Command instance.
	 */
	public function __construct( Command $command ) {
		$this->command = $command;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'cli_init', array( $this, 'register_command' ) );
	}

	/**
	 * Registers the command.
	 *
	 * @return void
	 */
	public function register_command(): void {
		WP_CLI::add_command( 'selective-entity-sync', $this->command );
	}
}
