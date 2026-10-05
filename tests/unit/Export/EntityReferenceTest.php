<?php
/**
 * Tests for EntityReference.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Unit\Export;

use InvalidArgumentException;
use SelectiveEntitySync\Export\EntityReference;
use SelectiveEntitySync\Tests\Unit\TestCase;

/**
 * @covers \SelectiveEntitySync\Export\EntityReference
 */
class EntityReferenceTest extends TestCase {

	public function test_factories_and_key(): void {
		$post = EntityReference::post( 12 );
		$term = EntityReference::term( 12 );

		$this->assertSame( 'post', $post->get_object_type() );
		$this->assertSame( 12, $post->get_id() );
		$this->assertSame( 'post:12', $post->get_key() );
		$this->assertSame( 'term:12', $term->get_key() );
	}

	public function test_rejects_unsupported_type(): void {
		$this->expectException( InvalidArgumentException::class );

		new EntityReference( 'user', 1 );
	}

	public function test_rejects_non_positive_id(): void {
		$this->expectException( InvalidArgumentException::class );

		EntityReference::post( 0 );
	}
}
