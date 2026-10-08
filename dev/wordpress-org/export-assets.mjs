/**
 * Exports the WordPress.org listing art (.wordpress-org/) from its SVG sources.
 *
 * Usage: node dev/wordpress-org/export-assets.mjs
 *
 * Renders with Playwright's Chromium (installed for the e2e tests). The banner text uses Inter, loaded from
 * Google Fonts while rendering, so an internet connection is needed.
 */

/**
 * External dependencies
 */
import { chromium } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..', '..' );
const out = join( root, '.wordpress-org' );

const exports = [
	{
		source: join( out, 'icon.svg' ),
		file: 'icon-128x128.png',
		width: 128,
		height: 128,
	},
	{
		source: join( out, 'icon.svg' ),
		file: 'icon-256x256.png',
		width: 256,
		height: 256,
	},
	{
		source: join( root, 'dev/wordpress-org/banner.svg' ),
		file: 'banner-772x250.png',
		width: 772,
		height: 250,
	},
	{
		source: join( root, 'dev/wordpress-org/banner.svg' ),
		file: 'banner-1544x500.png',
		width: 1544,
		height: 500,
	},
];

const browser = await chromium.launch();

for ( const { source, file, width, height } of exports ) {
	const page = await browser.newPage( { viewport: { width, height } } );
	await page.setContent(
		`<!doctype html><html><head>
		<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;700&display=block" rel="stylesheet">
		<style>html,body{margin:0}svg{display:block;width:${ width }px;height:${ height }px}</style>
		</head><body>${ readFileSync( source, 'utf8' ) }</body></html>`,
		{ waitUntil: 'networkidle' }
	);
	await page.evaluate( () => document.fonts.ready );
	await page.screenshot( { path: join( out, file ) } );
	await page.close();
	// eslint-disable-next-line no-console
	console.log( `Wrote .wordpress-org/${ file }` );
}

await browser.close();
