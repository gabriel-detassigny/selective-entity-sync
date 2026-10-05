/**
 * External dependencies
 */
const fs = require( 'fs' );

/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

test.describe( 'Export', () => {
	test.beforeAll( async ( { requestUtils } ) => {
		await requestUtils.activatePlugin( 'selective-entity-sync' );
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllPages();

		const parent = await requestUtils.createPage( {
			title: 'E2E Parent Page',
			status: 'publish',
		} );
		await requestUtils.createPage( {
			title: 'E2E Child Page',
			status: 'draft',
			parent: parent.id,
		} );
		await requestUtils.createPost( {
			title: 'E2E Unrelated Post',
			status: 'publish',
		} );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPosts();
		await requestUtils.deleteAllPages();
	} );

	test.beforeEach( async ( { admin } ) => {
		await admin.visitAdminPage( 'tools.php', 'page=selective-entity-sync' );
	} );

	test( 'lists exportable content, including drafts', async ( { page } ) => {
		const table = page.getByRole( 'table' );

		await expect(
			table.getByText( 'E2E Child Page', { exact: true } )
		).toBeVisible();
		await expect(
			table.getByText( 'E2E Parent Page', { exact: true } )
		).toBeVisible();
		await expect(
			table.getByText( 'E2E Unrelated Post', { exact: true } )
		).toBeVisible();
	} );

	test( 'searches content', async ( { page } ) => {
		await page
			.getByRole( 'searchbox', { name: 'Search content' } )
			.fill( 'Unrelated' );

		const table = page.getByRole( 'table' );
		await expect(
			table.getByText( 'E2E Unrelated Post', { exact: true } )
		).toBeVisible();
		await expect(
			table.getByText( 'E2E Child Page', { exact: true } )
		).toBeHidden();
	} );

	test( 'previews and downloads a package with dependencies', async ( {
		page,
	} ) => {
		const row = page
			.getByRole( 'row' )
			.filter( { hasText: 'E2E Child Page' } );
		await row.getByRole( 'checkbox' ).check();

		// Use the bulk action in the selection footer, not a row's own "Export" action.
		await page
			.locator( '.dataviews-bulk-actions-footer__container' )
			.getByRole( 'button', { name: 'Export' } )
			.click();

		const dialog = page.getByRole( 'dialog', { name: 'Export content' } );
		await expect(
			dialog.getByText( '1 selected item, 1 dependency, 0 files (0 B).' )
		).toBeVisible();

		const parentRow = dialog
			.getByRole( 'row' )
			.filter( { hasText: 'E2E Parent Page' } );
		await expect(
			parentRow.getByRole( 'cell', { name: 'Dependency' } )
		).toBeVisible();

		const downloadPromise = page.waitForEvent( 'download' );
		await dialog
			.getByRole( 'button', { name: 'Download package' } )
			.click();
		const download = await downloadPromise;

		expect( download.suggestedFilename() ).toMatch(
			/^selective-entity-sync-[a-z0-9-]+-\d{8}-\d{6}\.zip$/
		);
		const path = await download.path();
		const header = fs.readFileSync( path ).subarray( 0, 4 );
		expect( header.toString( 'binary' ) ).toBe( 'PK\x03\x04' );

		await expect( dialog.getByText( /Package downloaded:/ ) ).toBeVisible();
	} );
} );
