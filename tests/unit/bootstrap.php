<?php
/**
 * Unit test bootstrap. WordPress is not loaded; use Brain Monkey to mock WP functions.
 *
 * @package SelectiveEntitySync
 */

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/wordpress/' );
}
