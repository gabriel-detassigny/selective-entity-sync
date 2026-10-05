<?php
/**
 * Base unit test case.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase as PolyfillTestCase;

/**
 * Sets up and tears down Brain Monkey for every test.
 */
abstract class TestCase extends PolyfillTestCase {

	protected function set_up(): void {
		parent::set_up();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
	}

	protected function tear_down(): void {
		Monkey\tearDown();
		parent::tear_down();
	}
}
