/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Returns the label of a planned action.
 *
 * @param {string} action Plan action.
 * @return {string} Label.
 */
export function actionLabel( action ) {
	return (
		{
			create: __( 'Create', 'selective-entity-sync' ),
			update: __( 'Update', 'selective-entity-sync' ),
			skip: __( 'Skip', 'selective-entity-sync' ),
			link: __( 'Link to existing', 'selective-entity-sync' ),
			missing: __( 'Missing', 'selective-entity-sync' ),
		}[ action ] || action
	);
}

/**
 * Returns the label of an import result.
 *
 * @param {string} result Report result.
 * @return {string} Label.
 */
export function resultLabel( result ) {
	return (
		{
			created: __( 'Created', 'selective-entity-sync' ),
			updated: __( 'Updated', 'selective-entity-sync' ),
			skipped: __( 'Skipped', 'selective-entity-sync' ),
			linked: __( 'Linked', 'selective-entity-sync' ),
			missing: __( 'Missing', 'selective-entity-sync' ),
			failed: __( 'Failed', 'selective-entity-sync' ),
		}[ result ] || result
	);
}

/**
 * Describes how an entity was matched to existing content.
 *
 * @param {string|null} match Match method.
 * @return {string} Description, or an empty string.
 */
export function matchLabel( match ) {
	return (
		{
			uuid: __( 'Previously synced', 'selective-entity-sync' ),
			slug: __( 'Same slug', 'selective-entity-sync' ),
			file: __( 'Same file', 'selective-entity-sync' ),
			filter: __( 'Matched by customization', 'selective-entity-sync' ),
		}[ match ] || ''
	);
}
