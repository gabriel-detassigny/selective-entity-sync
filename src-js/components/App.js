/**
 * WordPress dependencies
 */
import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const TABS = [
	{
		name: 'export',
		title: __( 'Export', 'selective-entity-sync' ),
	},
	{
		name: 'import',
		title: __( 'Import', 'selective-entity-sync' ),
	},
];

export default function App() {
	return (
		<div className="selective-entity-sync-app">
			<TabPanel tabs={ TABS }>
				{ ( tab ) => (
					<div className="selective-entity-sync-app__panel">
						{ 'export' === tab.name ? (
							<p>
								{ __(
									'Select the content to export from this site.',
									'selective-entity-sync'
								) }
							</p>
						) : (
							<p>
								{ __(
									'Upload a manifest exported from another site.',
									'selective-entity-sync'
								) }
							</p>
						) }
					</div>
				) }
			</TabPanel>
		</div>
	);
}
