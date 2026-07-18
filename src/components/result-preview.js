/**
 * Result preview: shows generated content while it streams and offers confirm/discard actions.
 *
 * The generated HTML is never injected with innerHTML. For the read-only preview it is reduced to
 * plain text; the untouched HTML is converted to blocks with rawHandler only when inserted.
 */
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

/**
 * Reduce sanitized HTML to readable plain text for the preview pane.
 *
 * @param {string} html Server-sanitized HTML.
 * @return {string} Plain text with block boundaries turned into line breaks.
 */
export function toPreviewText( html ) {
	return String( html || '' )
		.replace( /<\/(p|h2|h3|li|blockquote|ul|ol)>/gi, '\n' )
		.replace( /<[^>]*>/g, '' )
		.replace( /\n{3,}/g, '\n\n' )
		.trim();
}

export default function ResultPreview( {
	text,
	isStreaming,
	onInsert,
	onDiscard,
	insertLabel,
} ) {
	if ( ! text ) {
		return null;
	}

	return (
		<div className="aiwr-preview" aria-busy={ isStreaming }>
			<div className="aiwr-preview__body">
				{ toPreviewText( text ) }
				{ isStreaming && (
					<span className="aiwr-preview__caret" aria-hidden="true" />
				) }
			</div>
			{ ! isStreaming && (
				<div className="aiwr-preview__actions">
					{ onInsert && (
						<Button variant="primary" onClick={ onInsert }>
							{ insertLabel }
						</Button>
					) }
					<Button variant="tertiary" onClick={ onDiscard }>
						{ __( 'Discard', 'wp-ai-writer' ) }
					</Button>
				</div>
			) }
		</div>
	);
}
