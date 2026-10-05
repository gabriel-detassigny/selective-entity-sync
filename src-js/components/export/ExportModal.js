/**
 * WordPress dependencies
 */
import { Button, Flex, FlexItem, Notice, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { downloadExport, previewExport } from '../../api';

/**
 * Returns how an entity is included, for display.
 *
 * @param {Object} entity Summary entity.
 * @return {string} Label.
 */
function inclusionLabel( entity ) {
	if ( entity.reference_only ) {
		return __( 'Reference only', 'selective-entity-sync' );
	}

	return entity.selected
		? __( 'Selected', 'selective-entity-sync' )
		: __( 'Dependency', 'selective-entity-sync' );
}

export default function ExportModal( { postIds, onClose } ) {
	const [ summary, setSummary ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ isDownloading, setIsDownloading ] = useState( false );
	const [ downloaded, setDownloaded ] = useState( null );

	useEffect( () => {
		previewExport( postIds )
			.then( setSummary )
			.catch( ( e ) => setError( e.message ) );
	}, [ postIds ] );

	const onDownload = async () => {
		setIsDownloading( true );
		setError( null );
		try {
			setDownloaded( await downloadExport( postIds ) );
		} catch ( e ) {
			setError( e.message );
		} finally {
			setIsDownloading( false );
		}
	};

	if ( ! summary && ! error ) {
		return (
			<Flex justify="center">
				<Spinner />
			</Flex>
		);
	}

	const selected = summary?.entities.filter( ( e ) => e.selected ) || [];
	const dependencies =
		summary?.entities.filter( ( e ) => ! e.selected ) || [];

	return (
		<div className="selective-entity-sync-export-modal">
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ downloaded && (
				<Notice status="success" isDismissible={ false }>
					{ sprintf(
						/* translators: %s: File name. */
						__(
							'Package downloaded: %s. Import it on the target site.',
							'selective-entity-sync'
						),
						downloaded
					) }
				</Notice>
			) }

			{ summary && (
				<>
					<p className="selective-entity-sync-export-modal__summary">
						{ sprintf(
							/* translators: 1: Number of selected items, 2: Number of dependencies, 3: Number of files, 4: Total file size. */
							__(
								'%1$s, %2$s, %3$s (%4$s).',
								'selective-entity-sync'
							),
							sprintf(
								/* translators: %d: Number of items. */
								_n(
									'%d selected item',
									'%d selected items',
									selected.length,
									'selective-entity-sync'
								),
								selected.length
							),
							sprintf(
								/* translators: %d: Number of dependencies. */
								_n(
									'%d dependency',
									'%d dependencies',
									dependencies.length,
									'selective-entity-sync'
								),
								dependencies.length
							),
							sprintf(
								/* translators: %d: Number of files. */
								_n(
									'%d file',
									'%d files',
									summary.file_count,
									'selective-entity-sync'
								),
								summary.file_count
							),
							summary.file_size
						) }
					</p>

					{ summary.warnings.length > 0 && (
						<Notice status="warning" isDismissible={ false }>
							<ul className="selective-entity-sync-export-modal__warnings">
								{ summary.warnings.map( ( warning, index ) => (
									<li key={ index }>{ warning }</li>
								) ) }
							</ul>
						</Notice>
					) }

					<div className="selective-entity-sync-export-modal__table-wrapper">
						<table className="widefat striped">
							<thead>
								<tr>
									<th scope="col">
										{ __(
											'Title',
											'selective-entity-sync'
										) }
									</th>
									<th scope="col">
										{ __(
											'Type',
											'selective-entity-sync'
										) }
									</th>
									<th scope="col">
										{ __(
											'Included as',
											'selective-entity-sync'
										) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ summary.entities.map( ( entity ) => (
									<tr key={ entity.uuid }>
										<td>
											{ entity.title ||
												__(
													'(no title)',
													'selective-entity-sync'
												) }
										</td>
										<td>{ entity.subtype_label }</td>
										<td>{ inclusionLabel( entity ) }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				</>
			) }

			<Flex justify="flex-end">
				<FlexItem>
					<Button variant="tertiary" onClick={ onClose }>
						{ downloaded
							? __( 'Close', 'selective-entity-sync' )
							: __( 'Cancel', 'selective-entity-sync' ) }
					</Button>
				</FlexItem>
				<FlexItem>
					<Button
						variant="primary"
						onClick={ onDownload }
						isBusy={ isDownloading }
						disabled={ ! summary || isDownloading }
						accessibleWhenDisabled
					>
						{ __( 'Download package', 'selective-entity-sync' ) }
					</Button>
				</FlexItem>
			</Flex>
		</div>
	);
}
