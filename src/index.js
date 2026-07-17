/**
 * Editor entry point: registers the writing assistant sidebar plugin.
 */
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { __ } from '@wordpress/i18n';

import Sidebar from './sidebar';
import './editor.scss';

const SIDEBAR_NAME = 'aiwr-sidebar';

function AIWriterSidebar() {
	return (
		<>
			<PluginSidebarMoreMenuItem target={ SIDEBAR_NAME } icon="edit">
				{ __( 'AI Writer', 'wp-ai-writer' ) }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar
				name={ SIDEBAR_NAME }
				title={ __( 'AI Writer', 'wp-ai-writer' ) }
				icon="edit"
			>
				<Sidebar />
			</PluginSidebar>
		</>
	);
}

registerPlugin( 'wp-ai-writer', { render: AIWriterSidebar } );
