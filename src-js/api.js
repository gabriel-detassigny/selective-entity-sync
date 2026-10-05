/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __ } from '@wordpress/i18n';

export const NAMESPACE = '/selective-entity-sync/v1';

/**
 * Fetches the exportable post types and statuses.
 *
 * @return {Promise<{post_types: Array<{name: string, label: string}>, statuses: Array<{name: string, label: string}>}>} Options.
 */
export function fetchExportOptions() {
	return apiFetch( { path: `${ NAMESPACE }/export/options` } );
}

/**
 * Fetches a page of exportable content.
 *
 * @param {Object} query REST query arguments (search, post_type, status, page, per_page, orderby, order).
 * @return {Promise<{items: Object[], totalItems: number, totalPages: number}>} Items and pagination info.
 */
export async function fetchEntities( query ) {
	const response = await apiFetch( {
		path: addQueryArgs( `${ NAMESPACE }/entities`, query ),
		parse: false,
	} );

	return {
		items: await response.json(),
		totalItems: parseInt( response.headers.get( 'X-WP-Total' ) || '0', 10 ),
		totalPages: parseInt(
			response.headers.get( 'X-WP-TotalPages' ) || '0',
			10
		),
	};
}

/**
 * Previews an export.
 *
 * @param {number[]} postIds Selected post IDs.
 * @return {Promise<Object>} Summary: entities, file_count, file_bytes, warnings.
 */
export function previewExport( postIds ) {
	return apiFetch( {
		path: `${ NAMESPACE }/export/preview`,
		method: 'POST',
		data: { post_ids: postIds },
	} );
}

/**
 * Builds an export package and saves it through the browser.
 *
 * @param {number[]} postIds Selected post IDs.
 * @return {Promise<string>} The downloaded file name.
 */
export async function downloadExport( postIds ) {
	let response;
	try {
		response = await apiFetch( {
			path: `${ NAMESPACE }/export`,
			method: 'POST',
			data: { post_ids: postIds },
			parse: false,
		} );
	} catch ( error ) {
		throw await toError( error );
	}

	const filename =
		/filename="([^"]+)"/.exec(
			response.headers.get( 'Content-Disposition' ) || ''
		)?.[ 1 ] || 'selective-entity-sync-export.zip';

	const url = window.URL.createObjectURL( await response.blob() );
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = filename;
	document.body.appendChild( link );
	link.click();
	link.remove();
	window.setTimeout( () => window.URL.revokeObjectURL( url ), 1000 );

	return filename;
}

/**
 * Uploads a package and returns its import plan.
 *
 * @param {File} file Package file (.zip).
 * @return {Promise<Object>} Plan: token, filename, source, items, counts.
 */
export function uploadPackage( file ) {
	const body = new window.FormData();
	body.append( 'package', file );

	return apiFetch( {
		path: `${ NAMESPACE }/import/packages`,
		method: 'POST',
		body,
	} );
}

/**
 * Imports an uploaded package.
 *
 * @param {string}   token Package token.
 * @param {string[]} skip  UUIDs not to write.
 * @return {Promise<Object>} Report: items, counts, warnings.
 */
export function importPackage( token, skip ) {
	return apiFetch( {
		path: `${ NAMESPACE }/import/packages/${ token }/import`,
		method: 'POST',
		data: { skip },
	} );
}

/**
 * Discards an uploaded package.
 *
 * @param {string} token Package token.
 * @return {Promise<Object>} Response.
 */
export function discardPackage( token ) {
	return apiFetch( {
		path: `${ NAMESPACE }/import/packages/${ token }`,
		method: 'DELETE',
	} );
}

/**
 * Normalises errors thrown by apiFetch (with `parse: false`, a raw Response).
 *
 * @param {Response|Error|Object} error Thrown value.
 * @return {Promise<{message: string}>} Error with a message.
 */
async function toError( error ) {
	if ( error && typeof error.json === 'function' ) {
		try {
			const body = await error.json();
			if ( body?.message ) {
				return { message: body.message };
			}
		} catch {
			// Not JSON: fall through to the generic message.
		}
	}

	return {
		message:
			error?.message ||
			__(
				'The export failed. Please try again.',
				'selective-entity-sync'
			),
	};
}
