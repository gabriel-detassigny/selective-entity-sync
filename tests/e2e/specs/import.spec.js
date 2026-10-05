/**
 * External dependencies
 */
const fs = require( 'fs' );

/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Exports a page through the Export tab and returns the downloaded package.
 *
 * @param {import('@playwright/test').Page} page  Page.
 * @param {string}                          title Title of the page to export.
 * @return {Promise<{name: string, mimeType: string, buffer: Buffer}>} The downloaded package, ready for setInputFiles().
 */
async function exportPage( page, title ) {
	await page
		.getByRole( 'row' )
		.filter( { hasText: title } )
		.getByRole( 'checkbox' )
		.check();
	await page
		.locator( '.dataviews-bulk-actions-footer__container' )
		.getByRole( 'button', { name: 'Export' } )
		.click();

	const dialog = page.getByRole( 'dialog', { name: 'Export content' } );
	const downloadPromise = page.waitForEvent( 'download' );
	await dialog.getByRole( 'button', { name: 'Download package' } ).click();
	const download = await downloadPromise;

	// Playwright stores downloads without their name: keep the real .zip name for re-upload.
	const file = {
		name: download.suggestedFilename(),
		mimeType: 'application/zip',
		buffer: fs.readFileSync( await download.path() ),
	};
	// The plugin's own "Close" button (the dialog also has an × button named "Close").
	await dialog
		.locator( '.selective-entity-sync-export-modal' )
		.getByRole( 'button', { name: 'Close' } )
		.click();

	return file;
}

test.describe( 'Import', () => {
	let parent;
	let child;

	test.beforeEach( async ( { admin, requestUtils } ) => {
		await requestUtils.activatePlugin( 'selective-entity-sync' );
		await requestUtils.deleteAllPages();

		parent = await requestUtils.createPage( {
			title: 'E2E Import Parent',
			status: 'publish',
		} );
		child = await requestUtils.createPage( {
			title: 'E2E Import Child',
			status: 'publish',
			parent: parent.id,
		} );

		await admin.visitAdminPage( 'tools.php', 'page=selective-entity-sync' );
	} );

	test.afterAll( async ( { requestUtils } ) => {
		await requestUtils.deleteAllPages();
	} );

	test( 'round trip: restores changed content and recreates deleted dependencies', async ( {
		page,
		requestUtils,
	} ) => {
		const packagePath = await exportPage( page, 'E2E Import Child' );

		// Change the target: edit the child, delete its parent.
		await requestUtils.rest( {
			path: `/wp/v2/pages/${ child.id }`,
			method: 'POST',
			data: { title: 'Edited on target' },
		} );
		await requestUtils.rest( {
			path: `/wp/v2/pages/${ parent.id }`,
			method: 'DELETE',
			params: { force: true },
		} );

		await page.getByRole( 'tab', { name: 'Import' } ).click();
		await page.locator( 'input[type="file"]' ).setInputFiles( packagePath );

		const childRow = page
			.getByRole( 'row' )
			.filter( { hasText: 'E2E Import Child' } );
		const parentRow = page
			.getByRole( 'row' )
			.filter( { hasText: 'E2E Import Parent' } );
		await expect(
			childRow.getByText( 'Update', { exact: true } )
		).toBeVisible();
		await expect( childRow.getByText( 'Previously synced' ) ).toBeVisible();
		await expect(
			parentRow.getByText( 'Create', { exact: true } )
		).toBeVisible();

		await page.getByRole( 'button', { name: 'Import 2 items' } ).click();

		await expect(
			page
				.locator( '.components-notice' )
				.getByText(
					'Import finished: 1 created, 1 updated, 0 skipped, 0 failed.'
				)
		).toBeVisible();
		await expect(
			page.getByRole( 'link', { name: 'E2E Import Child' } )
		).toBeVisible();

		const restored = await requestUtils.rest( {
			path: `/wp/v2/pages/${ child.id }`,
			params: { context: 'edit' },
		} );
		expect( restored.title.raw ).toBe( 'E2E Import Child' );

		const newParent = await requestUtils.rest( {
			path: `/wp/v2/pages/${ restored.parent }`,
			params: { context: 'edit' },
		} );
		expect( newParent.title.raw ).toBe( 'E2E Import Parent' );
		expect( newParent.id ).not.toBe( parent.id );
	} );

	test( 'deselected items are left untouched', async ( {
		page,
		requestUtils,
	} ) => {
		const packagePath = await exportPage( page, 'E2E Import Child' );
		await requestUtils.rest( {
			path: `/wp/v2/pages/${ child.id }`,
			method: 'POST',
			data: { title: 'Keep this title' },
		} );

		await page.getByRole( 'tab', { name: 'Import' } ).click();
		await page.locator( 'input[type="file"]' ).setInputFiles( packagePath );
		await page
			.getByRole( 'checkbox', { name: 'Import E2E Import Child' } )
			.uncheck();
		await page.getByRole( 'button', { name: 'Import 1 item' } ).click();

		await expect(
			page
				.locator( '.components-notice' )
				.getByText(
					'Import finished: 0 created, 1 updated, 1 skipped, 0 failed.'
				)
		).toBeVisible();

		const kept = await requestUtils.rest( {
			path: `/wp/v2/pages/${ child.id }`,
			params: { context: 'edit' },
		} );
		expect( kept.title.raw ).toBe( 'Keep this title' );
	} );

	test( 'rejects files that are not packages', async ( { page } ) => {
		await page.getByRole( 'tab', { name: 'Import' } ).click();
		await page.locator( 'input[type="file"]' ).setInputFiles( {
			name: 'not-a-package.zip',
			mimeType: 'application/zip',
			buffer: Buffer.from( 'hello' ),
		} );

		// Scoped to the notice: the message is also announced in a screen-reader region.
		await expect(
			page
				.locator( '.components-notice' )
				.getByText( /could not be uploaded/ )
		).toBeVisible();
	} );
} );
