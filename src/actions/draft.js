/**
 * Draft action panel: prompt in, streaming preview out.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import {
	TextareaControl,
	Button,
	Spinner,
	Notice,
} from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { speak } from '@wordpress/a11y';

import { generate } from '../api/client';
import { htmlToBlocks } from '../utils/blocks';
import ResultPreview from '../components/result-preview';

const MAX_PROMPT = 2000;

export default function DraftPanel() {
	const [ prompt, setPrompt ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ streaming, setStreaming ] = useState( false );
	const [ preview, setPreview ] = useState( '' );
	const [ error, setError ] = useState( null );

	const postId = useSelect(
		( select ) => select( editorStore ).getCurrentPostId(),
		[]
	);
	const { insertBlocks } = useDispatch( blockEditorStore );

	const length = prompt.trim().length;
	const tooLong = prompt.length > MAX_PROMPT;
	const canGenerate = length > 0 && ! tooLong && ! busy;

	async function onGenerate() {
		setBusy( true );
		setError( null );
		setPreview( '' );
		setStreaming( true );

		try {
			const out = await generate( {
				action: 'draft',
				stream: true,
				postId,
				input: { prompt: prompt.trim() },
				onDelta: ( text ) => setPreview( text ),
			} );

			const html = out.streamed
				? out.text
				: ( out.result && out.result.html ) || '';
			setPreview( html );
			speak( __( 'Draft ready.', 'wp-ai-writer' ) );
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
			speak( __( 'Draft generation failed.', 'wp-ai-writer' ) );
		} finally {
			setBusy( false );
			setStreaming( false );
		}
	}

	function onInsert() {
		const blocks = htmlToBlocks( preview );
		if ( blocks.length ) {
			insertBlocks( blocks );
			speak( __( 'Draft inserted into the post.', 'wp-ai-writer' ) );
		}
		setPreview( '' );
	}

	const isNotice = error && 'aiwr_not_configured' === error.code;

	return (
		<div className="aiwr-action">
			<TextareaControl
				label={ __( 'Prompt', 'wp-ai-writer' ) }
				help={
					tooLong
						? sprintf(
								/* translators: %d: maximum prompt length. */
								__(
									'Prompts must be %d characters or fewer.',
									'wp-ai-writer'
								),
								MAX_PROMPT
						  )
						: __(
								'Describe what you want written.',
								'wp-ai-writer'
						  )
				}
				value={ prompt }
				onChange={ setPrompt }
				rows={ 4 }
				__nextHasNoMarginBottom
			/>

			<div className="aiwr-action__submit">
				<Button
					variant="primary"
					isBusy={ busy }
					aria-disabled={ ! canGenerate }
					disabled={ ! canGenerate }
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
				onInsert={ onInsert }
				onDiscard={ () => setPreview( '' ) }
				insertLabel={ __( 'Insert into post', 'wp-ai-writer' ) }
			/>
		</div>
	);
}
