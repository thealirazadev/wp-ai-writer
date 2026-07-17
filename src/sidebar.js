/**
 * Sidebar shell: action navigation across the five writing actions.
 */
import { __ } from '@wordpress/i18n';
import { TabPanel, Notice } from '@wordpress/components';

import DraftPanel from './actions/draft';
import RewritePanel from './actions/rewrite';
import SeoPanel from './actions/seo';
import ExcerptPanel from './actions/excerpt';

const ACTIONS = [
	{ name: 'draft', title: __( 'Draft', 'wp-ai-writer' ) },
	{ name: 'rewrite', title: __( 'Rewrite', 'wp-ai-writer' ) },
	{ name: 'seo', title: __( 'SEO', 'wp-ai-writer' ) },
	{ name: 'excerpt', title: __( 'Excerpt', 'wp-ai-writer' ) },
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
			return <DraftPanel />;
		case 'rewrite':
			return <RewritePanel />;
		case 'seo':
			return <SeoPanel />;
		case 'excerpt':
			return <ExcerptPanel />;
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
