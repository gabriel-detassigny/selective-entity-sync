/**
 * WordPress dependencies
 */
import { Button, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { resultLabel } from './labels';

export default function ImportReport( { report, onDone } ) {
	const { counts } = report;
	const failed = counts.failed > 0;

	return (
		<div className="selective-entity-sync-import__report">
			<Notice
				status={ failed ? 'warning' : 'success' }
				isDismissible={ false }
			>
				{ sprintf(
					/* translators: 1: Created count, 2: Updated count, 3: Skipped count, 4: Failed count. */
					__(
						'Import finished: %1$d created, %2$d updated, %3$d skipped, %4$d failed.',
						'selective-entity-sync'
					),
					counts.created,
					counts.updated,
					counts.skipped,
					counts.failed
				) }
			</Notice>

			{ report.warnings.length > 0 && (
				<Notice status="warning" isDismissible={ false }>
					<ul>
						{ report.warnings.map( ( warning, index ) => (
							<li key={ index }>{ warning }</li>
						) ) }
					</ul>
				</Notice>
			) }

			<table className="widefat striped selective-entity-sync-import__table">
				<thead>
					<tr>
						<th scope="col">
							{ __( 'Title', 'selective-entity-sync' ) }
						</th>
						<th scope="col">
							{ __( 'Type', 'selective-entity-sync' ) }
						</th>
						<th scope="col">
							{ __( 'Result', 'selective-entity-sync' ) }
						</th>
						<th scope="col">
							{ __( 'Details', 'selective-entity-sync' ) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ report.items.map( ( item ) => {
						const title =
							item.title ||
							__( '(no title)', 'selective-entity-sync' );

						return (
							<tr key={ item.uuid }>
								<td>
									{ item.edit_link ? (
										<a href={ item.edit_link }>{ title }</a>
									) : (
										title
									) }
								</td>
								<td>{ item.subtype_label }</td>
								<td>
									<span
										className={ `selective-entity-sync-import__action is-${ item.result }` }
									>
										{ resultLabel( item.result ) }
									</span>
								</td>
								<td>{ item.message }</td>
							</tr>
						);
					} ) }
				</tbody>
			</table>

			<div className="selective-entity-sync-import__actions">
				<Button variant="secondary" onClick={ onDone }>
					{ __( 'Import another package', 'selective-entity-sync' ) }
				</Button>
			</div>
		</div>
	);
}
