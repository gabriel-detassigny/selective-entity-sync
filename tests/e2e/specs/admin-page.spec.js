/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'Admin page', () => {
	// PHP integration tests reinstall the shared tests database, so never assume the plugin is active.
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'selective-entity-sync' );
	} );

	test( 'is reachable from the Tools menu and renders the app', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'tools.php', 'page=selective-entity-sync' );

		await expect(
			page.getByRole( 'heading', {
				level: 1,
				name: 'Selective Entity Sync',
			} )
		).toBeVisible();
		await expect(
			page.getByRole( 'tab', { name: 'Export' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'tab', { name: 'Import' } )
		).toBeVisible();
	} );
} );
