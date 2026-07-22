/**
 * SEO action panel: generate an SEO title and meta description with copy and apply actions.
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Spinner, Notice } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { speak } from '@wordpress/a11y';

import { generate } from '../api/client';

function CopyButton( { text, label } ) {
	const [ copied, setCopied ] = useState( false );

	async function onCopy() {
		try {
			await window.navigator.clipboard.writeText( text );
			setCopied( true );
			speak( __( 'Copied to clipboard.', 'wp-ai-writer' ) );
			window.setTimeout( () => setCopied( false ), 2000 );
		} catch {
			// Clipboard is unavailable; nothing to apply.
		}
	}

	return (
		<Button variant="secondary" onClick={ onCopy }>
			{ copied ? __( 'Copied', 'wp-ai-writer' ) : label }
		</Button>
	);
}

export default function SeoPanel() {
	const [ busy, setBusy ] = useState( false );
	const [ result, setResult ] = useState( null );
	const [ error, setError ] = useState( null );

	const { title, content, postId } = useSelect( ( select ) => {
		const editor = select( editorStore );
		return {
			title: editor.getEditedPostAttribute( 'title' ) || '',
			content: editor.getEditedPostContent() || '',
			postId: editor.getCurrentPostId(),
		};
	}, [] );
	const { editPost } = useDispatch( editorStore );

	async function onGenerate() {
		setBusy( true );
		setError( null );
		setResult( null );

		try {
			const out = await generate( {
				action: 'seo',
				stream: false,
				postId,
				input: { title, content },
			} );
			setResult( out.result );
			speak( __( 'SEO text ready.', 'wp-ai-writer' ) );
		} catch ( err ) {
			setError( {
				code: err.code,
				message:
					err.message ||
					__(
						'Something went wrong. Please try again.',
						'wp-ai-writer'
					),
			} );
			speak( __( 'SEO generation failed.', 'wp-ai-writer' ) );
		} finally {
			setBusy( false );
		}
	}

	function useAsTitle() {
		if ( result && result.seo_title ) {
			editPost( { title: result.seo_title } );
			speak( __( 'Post title updated.', 'wp-ai-writer' ) );
		}
	}

	async function saveMetaDescription() {
		if ( ! result || ! result.meta_description ) {
			return;
		}

		try {
			await generate( {
				action: 'apply_seo_meta',
				stream: false,
				postId,
				input: { meta_description: result.meta_description },
			} );
			speak( __( 'Meta description saved.', 'wp-ai-writer' ) );
		} catch ( err ) {
			setError( {
				code: err.code,
				message:
					err.message ||
					__(
						'The meta description could not be saved.',
						'wp-ai-writer'
					),
			} );
			speak(
				__( 'Saving the meta description failed.', 'wp-ai-writer' )
			);
		}
	}

	const isNotice = error && 'aiwr_not_configured' === error.code;

	return (
		<div className="aiwr-action">
			<p className="aiwr-action__help">
				{ __(
					'Generate an SEO title and meta description from the current post.',
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

			{ result && (
				<div className="aiwr-result">
					<div className="aiwr-result__field">
						<span className="aiwr-result__label">
							{ __( 'SEO title', 'wp-ai-writer' ) }
						</span>
						<p className="aiwr-result__value">
							{ result.seo_title }
						</p>
						<div className="aiwr-result__actions">
							<CopyButton
								text={ result.seo_title }
								label={ __( 'Copy title', 'wp-ai-writer' ) }
							/>
							<Button variant="primary" onClick={ useAsTitle }>
								{ __( 'Use as post title', 'wp-ai-writer' ) }
							</Button>
						</div>
					</div>

					<div className="aiwr-result__field">
						<span className="aiwr-result__label">
							{ __( 'Meta description', 'wp-ai-writer' ) }
						</span>
						<p className="aiwr-result__value">
							{ result.meta_description }
						</p>
						<div className="aiwr-result__actions">
							<CopyButton
								text={ result.meta_description }
								label={ __(
									'Copy description',
									'wp-ai-writer'
								) }
							/>
							<Button
								variant="primary"
								onClick={ saveMetaDescription }
							>
								{ __(
									'Save meta description',
									'wp-ai-writer'
								) }
							</Button>
						</div>
					</div>
				</div>
			) }
		</div>
	);
}
