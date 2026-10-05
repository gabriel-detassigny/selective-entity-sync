<?php
/**
 * Manifest JSON codec.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Manifest;

use JsonException;

/**
 * Encodes manifests to JSON and decodes and validates untrusted JSON.
 */
class ManifestCodec {

	/**
	 * Maximum nesting depth accepted when decoding.
	 */
	public const MAX_DEPTH = 128;

	/**
	 * Schema validator.
	 *
	 * @var Schema
	 */
	private $schema;

	/**
	 * Constructor.
	 *
	 * @param Schema $schema Schema validator.
	 */
	public function __construct( Schema $schema ) {
		$this->schema = $schema;
	}

	/**
	 * Encodes a manifest as JSON.
	 *
	 * @param array<string, mixed> $data Manifest data, usually from Manifest::to_array().
	 * @return string
	 * @throws ManifestException When the data cannot be encoded.
	 */
	public function encode( array $data ): string {
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		if ( ! is_string( $json ) ) {
			throw new ManifestException( __( 'The manifest could not be encoded as JSON.', 'selective-entity-sync' ) );
		}

		return $json;
	}

	/**
	 * Decodes and validates an untrusted JSON manifest.
	 *
	 * @param string $json JSON string.
	 * @return Manifest
	 * @throws ManifestException When the JSON is malformed or the manifest is invalid.
	 */
	public function decode( string $json ): Manifest {
		try {
			$data = json_decode( $json, true, self::MAX_DEPTH, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			throw new ManifestException(
				sprintf(
					/* translators: %s: JSON parser error message. */
					__( 'The manifest is not valid JSON: %s', 'selective-entity-sync' ),
					$e->getMessage()
				),
				0,
				$e
			);
		}

		if ( ! is_array( $data ) ) {
			throw new ManifestException( __( 'The manifest must be a JSON object.', 'selective-entity-sync' ) );
		}

		$this->schema->validate( $data );

		return Manifest::from_array( $data );
	}
}
