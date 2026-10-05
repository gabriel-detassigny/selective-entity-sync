/**
 * External dependencies
 */
const { defineConfig } = require( '@playwright/test' );

// E2E tests run against the dedicated test environment (.wp-env.test.json), on its own port
// so this project can run alongside other wp-env sites.
process.env.WP_BASE_URL ??= 'http://localhost:8899';

/**
 * WordPress dependencies
 */
const baseConfig = require( '@wordpress/scripts/config/playwright.config' );

module.exports = defineConfig( {
	...baseConfig,
	testDir: './tests/e2e/specs',
	webServer: {
		...baseConfig.webServer,
		command: 'npm run wp-env:test start',
	},
} );
