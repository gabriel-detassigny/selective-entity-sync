<?php
/**
 * Tests for PackagePath.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Unit\Manifest;

use SelectiveEntitySync\Manifest\PackagePath;
use SelectiveEntitySync\Tests\Unit\TestCase;

/**
 * @covers \SelectiveEntitySync\Manifest\PackagePath
 */
class PackagePathTest extends TestCase {

	/**
	 * @dataProvider valid_paths
	 */
	public function test_valid_paths( string $path ): void {
		$this->assertTrue( PackagePath::is_valid( $path ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function valid_paths(): array {
		return array(
			'media file' => array( 'media/3f2b8c1e-4a5d-4e6f-8a9b-0c1d2e3f4a5b/photo-1_large.JPG' ),
			'top level'  => array( 'file.txt' ),
		);
	}

	/**
	 * @dataProvider invalid_paths
	 */
	public function test_invalid_paths( string $path ): void {
		$this->assertFalse( PackagePath::is_valid( $path ) );
	}

	/**
	 * @return array<string, array{string}>
	 */
	public function invalid_paths(): array {
		return array(
			'empty'            => array( '' ),
			'manifest'         => array( 'manifest.json' ),
			'parent traversal' => array( 'media/../../wp-config.php' ),
			'leading dotdot'   => array( '../x.jpg' ),
			'current dir'      => array( 'media/./x.jpg' ),
			'absolute'         => array( '/etc/passwd' ),
			'backslash'        => array( 'media\\..\\x.jpg' ),
			'windows drive'    => array( 'C:/x.jpg' ),
			'trailing slash'   => array( 'media/' ),
			'double slash'     => array( 'media//x.jpg' ),
			'space'            => array( 'media/my photo.jpg' ),
			'unicode'          => array( 'media/photo-é.jpg' ),
			'null byte'        => array( "media/x.jpg\0.php" ),
			'trailing newline' => array( "media/x.jpg\n" ),
			'too long'         => array( str_repeat( 'a', 256 ) ),
			'too deep'         => array( 'a/b/c/d/e/f/g/h/i.jpg' ),
		);
	}
}
