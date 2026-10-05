/**
 * External dependencies
 */
const { defineConfig } = require( '@playwright/test' );

// Dedicated wp-env ports (see .wp-env.json) so this project can run alongside other wp-env sites.
process.env.WP_BASE_URL ??= 'http://localhost:8899';

/**
 * WordPress dependencies
 */
const baseConfig = require( '@wordpress/scripts/config/playwright.config' );

module.exports = defineConfig( {
	...baseConfig,
	testDir: './tests/e2e/specs',
} );
