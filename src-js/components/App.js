/**
 * WordPress dependencies
 */
import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import ExportTab from './export/ExportTab';
import ImportTab from './import/ImportTab';

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
							<ExportTab />
						) : (
							<ImportTab />
						) }
					</div>
				) }
			</TabPanel>
		</div>
	);
}
