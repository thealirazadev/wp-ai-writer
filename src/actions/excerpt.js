/**
 * Excerpt action panel: summarize the post into an excerpt and apply it.
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Spinner, Notice } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { speak } from '@wordpress/a11y';

import { generate } from '../api/client';
import ResultPreview from '../components/result-preview';

function toPlainText( value ) {
	return String( value || '' )
		.replace( /<[^>]*>/g, '' )
		.replace( /\s+/g, ' ' )
		.trim();
}

export default function ExcerptPanel() {
	const [ busy, setBusy ] = useState( false );
	const [ streaming, setStreaming ] = useState( false );
	const [ preview, setPreview ] = useState( '' );
	const [ error, setError ] = useState( null );

	const { content, postId } = useSelect( ( select ) => {
		const editor = select( editorStore );
		return {
			content: editor.getEditedPostContent() || '',
			postId: editor.getCurrentPostId(),
		};
	}, [] );
	const { editPost } = useDispatch( editorStore );

	async function onGenerate() {
		setBusy( true );
		setError( null );
		setPreview( '' );
		setStreaming( true );

		try {
			const out = await generate( {
				action: 'excerpt',
				stream: true,
				postId,
				input: { content },
				onDelta: ( text ) => setPreview( text ),
			} );

			const text = out.streamed
				? out.text
				: ( out.result && out.result.excerpt ) || '';
			setPreview( text );
			speak( __( 'Excerpt ready.', 'wp-ai-writer' ) );
		} catch ( err ) {
			setPreview( '' );
			setError( {
				code: err.code,
				message:
					err.message ||
					__(
						'Something went wrong. Please try again.',
						'wp-ai-writer'
					),
			} );
			speak( __( 'Excerpt generation failed.', 'wp-ai-writer' ) );
		} finally {
			setBusy( false );
			setStreaming( false );
		}
	}

	function onApply() {
		const excerpt = toPlainText( preview );
		if ( excerpt ) {
			editPost( { excerpt } );
			speak( __( 'Excerpt applied to the post.', 'wp-ai-writer' ) );
		}
		setPreview( '' );
	}

	const isNotice = error && 'aiwr_not_configured' === error.code;

	return (
		<div className="aiwr-action">
			<p className="aiwr-action__help">
				{ __(
					'Summarize the current post into an excerpt.',
					'wp-ai-writer'
				) }
			</p>

			<div className="aiwr-action__submit">
				<Button
					variant="primary"
					isBusy={ busy }
					aria-disabled={ busy }
					disabled={ busy }
					onClick={ onGenerate }
				>
					{ busy
						? __( 'Generating', 'wp-ai-writer' )
						: __( 'Generate', 'wp-ai-writer' ) }
				</Button>
				{ busy && <Spinner /> }
			</div>

			{ error && (
				<Notice
					status={ isNotice ? 'info' : 'error' }
					isDismissible={ ! isNotice }
					onRemove={ () => setError( null ) }
				>
					{ isNotice
						? __(
								'The writing assistant is not configured yet. Ask an administrator to set it up.',
								'wp-ai-writer'
						  )
						: error.message }
				</Notice>
			) }

			<ResultPreview
				text={ preview }
				isStreaming={ streaming }
				onInsert={ onApply }
				onDiscard={ () => setPreview( '' ) }
				insertLabel={ __( 'Apply to excerpt', 'wp-ai-writer' ) }
			/>
		</div>
	);
}
