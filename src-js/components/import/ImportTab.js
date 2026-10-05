/**
 * WordPress dependencies
 */
import { Button, FormFileUpload, Notice, Spinner } from '@wordpress/components';
import { dateI18n, getSettings } from '@wordpress/date';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import {
	continueImport,
	discardPackage,
	importPackage,
	uploadPackage,
} from '../../api';
import { actionLabel, matchLabel } from './labels';
import ImportReport from './ImportReport';

const WRITABLE = [ 'create', 'update' ];

export default function ImportTab() {
	const [ plan, setPlan ] = useState( null );
	const [ report, setReport ] = useState( null );
	const [ deselected, setDeselected ] = useState( [] );
	const [ busy, setBusy ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ progress, setProgress ] = useState( null );

	const writable = useMemo(
		() =>
			( plan?.items || [] ).filter( ( item ) =>
				WRITABLE.includes( item.action )
			),
		[ plan ]
	);
	const selectedCount = writable.length - deselected.length;

	// "indeterminate" can only be set from JavaScript.
	const selectAllRef = useRef();
	useEffect( () => {
		if ( selectAllRef.current ) {
			selectAllRef.current.indeterminate =
				deselected.length > 0 && selectedCount > 0;
		}
	}, [ deselected, selectedCount ] );

	const reset = () => {
		setPlan( null );
		setReport( null );
		setDeselected( [] );
		setError( null );
	};

	const onUpload = async ( event ) => {
		const file = event.target.files?.[ 0 ];
		event.target.value = '';
		if ( ! file ) {
			return;
		}

		reset();
		setBusy( 'upload' );
		try {
			setPlan( await uploadPackage( file ) );
		} catch ( e ) {
			setError( e.message );
		} finally {
			setBusy( null );
		}
	};

	const onImport = async () => {
		setBusy( 'import' );
		setError( null );
		try {
			// Large packages are imported over several requests.
			let result = await importPackage( plan.token, deselected );
			setProgress( result );
			while ( ! result.done ) {
				result = await continueImport( plan.token );
				setProgress( result );
			}
			setReport( result.report );
			setPlan( null );
		} catch ( e ) {
			setError( e.message );
		} finally {
			setBusy( null );
			setProgress( null );
		}
	};

	const onCancel = async () => {
		const token = plan?.token;
		reset();
		if ( token ) {
			await discardPackage( token ).catch( () => {} );
		}
	};

	const toggle = ( uuid, checked ) =>
		setDeselected( ( current ) =>
			checked
				? current.filter( ( id ) => id !== uuid )
				: [ ...current, uuid ]
		);

	if ( report ) {
		return <ImportReport report={ report } onDone={ reset } />;
	}

	return (
		<div className="selective-entity-sync-import">
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ ! plan && (
				<div className="selective-entity-sync-import__upload">
					<p>
						{ __(
							'Upload a package exported from another site. You will see what will be created or updated before anything is changed.',
							'selective-entity-sync'
						) }
					</p>
					<FormFileUpload
						accept=".zip,application/zip"
						onChange={ onUpload }
						render={ ( { openFileDialog } ) => (
							<Button
								variant="primary"
								onClick={ openFileDialog }
								isBusy={ 'upload' === busy }
								disabled={ !! busy }
								accessibleWhenDisabled
							>
								{ __(
									'Choose package…',
									'selective-entity-sync'
								) }
							</Button>
						) }
					/>
				</div>
			) }

			{ plan && (
				<div className="selective-entity-sync-import__plan">
					<p>
						{ sprintf(
							/* translators: 1: File name, 2: Source site URL, 3: Export date. */
							__(
								'%1$s, exported from %2$s on %3$s.',
								'selective-entity-sync'
							),
							plan.filename,
							plan.source.site_url,
							dateI18n(
								getSettings().formats.datetime,
								plan.source.exported_at
							)
						) }
					</p>

					<table className="widefat striped selective-entity-sync-import__table">
						<thead>
							<tr>
								<td className="check-column">
									<input
										type="checkbox"
										ref={ selectAllRef }
										aria-label={ __(
											'Select all',
											'selective-entity-sync'
										) }
										checked={
											writable.length > 0 &&
											0 === deselected.length
										}
										onChange={ ( event ) =>
											setDeselected(
												event.target.checked
													? []
													: writable.map(
															( item ) =>
																item.uuid
														)
											)
										}
									/>
								</td>
								<th scope="col">
									{ __( 'Title', 'selective-entity-sync' ) }
								</th>
								<th scope="col">
									{ __( 'Type', 'selective-entity-sync' ) }
								</th>
								<th scope="col">
									{ __( 'Action', 'selective-entity-sync' ) }
								</th>
								<th scope="col">
									{ __( 'Details', 'selective-entity-sync' ) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ plan.items.map( ( item ) => {
								const isWritable = WRITABLE.includes(
									item.action
								);
								const title =
									item.title ||
									__( '(no title)', 'selective-entity-sync' );

								return (
									<tr key={ item.uuid }>
										<td className="check-column">
											{ isWritable && (
												<input
													type="checkbox"
													aria-label={ sprintf(
														/* translators: %s: Item title. */
														__(
															'Import %s',
															'selective-entity-sync'
														),
														title
													) }
													checked={
														! deselected.includes(
															item.uuid
														)
													}
													onChange={ ( event ) =>
														toggle(
															item.uuid,
															event.target.checked
														)
													}
												/>
											) }
										</td>
										<td>{ title }</td>
										<td>{ item.subtype_label }</td>
										<td>
											<span
												className={ `selective-entity-sync-import__action is-${ item.action }` }
											>
												{ actionLabel( item.action ) }
											</span>
										</td>
										<td>
											{ item.reason ||
												matchLabel( item.match ) }
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>

					{ progress && (
						<div className="selective-entity-sync-import__progress">
							<progress
								value={ progress.processed }
								max={ Math.max( progress.total, 1 ) }
							/>
							<span>
								{ sprintf(
									/* translators: 1: Number of items imported so far, 2: Total number of items. */
									__(
										'Importing… %1$d of %2$d',
										'selective-entity-sync'
									),
									progress.processed,
									progress.total
								) }
							</span>
						</div>
					) }

					<div className="selective-entity-sync-import__actions">
						<Button
							variant="tertiary"
							onClick={ onCancel }
							disabled={ !! busy }
							accessibleWhenDisabled
						>
							{ __( 'Cancel', 'selective-entity-sync' ) }
						</Button>
						<Button
							variant="primary"
							onClick={ onImport }
							isBusy={ 'import' === busy }
							disabled={ !! busy || 0 === selectedCount }
							accessibleWhenDisabled
						>
							{ sprintf(
								/* translators: %d: Number of items. */
								_n(
									'Import %d item',
									'Import %d items',
									selectedCount,
									'selective-entity-sync'
								),
								selectedCount
							) }
						</Button>
						{ 'import' === busy && ! progress && <Spinner /> }
					</div>
				</div>
			) }
		</div>
	);
}
