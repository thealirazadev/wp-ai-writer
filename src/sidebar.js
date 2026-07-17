/**
 * Sidebar shell: action navigation across the five writing actions.
 */
import { __ } from '@wordpress/i18n';
import { TabPanel, Notice } from '@wordpress/components';

const ACTIONS = [
	{ name: 'draft', title: __( 'Draft', 'wp-ai-writer' ) },
	{ name: 'rewrite', title: __( 'Rewrite', 'wp-ai-writer' ), disabled: true },
	{ name: 'seo', title: __( 'SEO', 'wp-ai-writer' ), disabled: true },
	{ name: 'excerpt', title: __( 'Excerpt', 'wp-ai-writer' ), disabled: true },
	{
		name: 'alt_text',
		title: __( 'Alt text', 'wp-ai-writer' ),
		disabled: true,
	},
];

function ComingSoon() {
	return (
		<Notice status="info" isDismissible={ false }>
			{ __( 'This action is coming in a later phase.', 'wp-ai-writer' ) }
		</Notice>
	);
}

function renderPanel( name ) {
	switch ( name ) {
		case 'draft':
			return (
				<p className="aiwr-panel__placeholder">
					{ __( 'Draft', 'wp-ai-writer' ) }
				</p>
			);
		default:
			return <ComingSoon />;
	}
}

export default function Sidebar() {
	return (
		<div className="aiwr-sidebar">
			<TabPanel className="aiwr-tabs" tabs={ ACTIONS }>
				{ ( tab ) => (
					<div className="aiwr-panel">
						{ renderPanel( tab.name ) }
					</div>
				) }
			</TabPanel>
		</div>
	);
}
