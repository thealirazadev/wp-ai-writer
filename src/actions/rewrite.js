/**
 * Rewrite action panel: rewrite the selected block with tone and length presets.
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { SelectControl, Button, Spinner, Notice } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { speak } from '@wordpress/a11y';

import { generate } from '../api/client';
import { htmlToBlocks, blockToText, isRewritableBlock } from '../utils/blocks';
import ResultPreview from '../components/result-preview';

const TONES = [
	{ value: 'professional', label: __( 'Professional', 'wp-ai-writer' ) },
	{ value: 'friendly', label: __( 'Friendly', 'wp-ai-writer' ) },
	{ value: 'casual', label: __( 'Casual', 'wp-ai-writer' ) },
	{ value: 'confident', label: __( 'Confident', 'wp-ai-writer' ) },
];

const LENGTHS = [
	{ value: 'shorter', label: __( 'Shorter', 'wp-ai-writer' ) },
	{ value: 'same', label: __( 'Same length', 'wp-ai-writer' ) },
	{ value: 'longer', label: __( 'Longer', 'wp-ai-writer' ) },
];

export default function RewritePanel() {
	const [ tone, setTone ] = useState( 'professional' );
	const [ length, setLength ] = useState( 'same' );
	const [ busy, setBusy ] = useState( false );
	const [ streaming, setStreaming ] = useState( false );
	const [ preview, setPreview ] = useState( '' );
	const [ error, setError ] = useState( null );

	const selectedBlock = useSelect(
		( select ) => select( blockEditorStore ).getSelectedBlock(),
		[]
	);
	const { replaceBlocks } = useDispatch( blockEditorStore );

	const supported = isRewritableBlock( selectedBlock );
	const sourceText = supported ? blockToText( selectedBlock ) : '';
	const canGenerate = supported && sourceText.trim().length > 0 && ! busy;

	async function onGenerate() {
		setBusy( true );
		setError( null );
		setPreview( '' );
		setStreaming( true );

		try {
			const out = await generate( {
				action: 'rewrite',
				stream: true,
				input: { text: sourceText },
				options: { tone, length },
				onDelta: ( text ) => setPreview( text ),
			} );

			const html = out.streamed
				? out.text
				: ( out.result && out.result.html ) || '';
			setPreview( html );
			speak( __( 'Rewrite ready.', 'wp-ai-writer' ) );
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
			speak( __( 'Rewrite failed.', 'wp-ai-writer' ) );
		} finally {
			setBusy( false );
			setStreaming( false );
		}
	}

	function onReplace() {
		const blocks = htmlToBlocks( preview );
		if ( selectedBlock && blocks.length ) {
			replaceBlocks( selectedBlock.clientId, blocks );
			speak( __( 'Block content replaced.', 'wp-ai-writer' ) );
		}
		setPreview( '' );
	}

	const isNotice = error && 'aiwr_not_configured' === error.code;

	if ( ! supported ) {
		return (
			<div className="aiwr-action">
				<Notice status="info" isDismissible={ false }>
					{ __(
						'Select a paragraph, heading, list, or quote block to rewrite.',
						'wp-ai-writer'
					) }
				</Notice>
			</div>
		);
	}

	return (
		<div className="aiwr-action">
			<div className="aiwr-action__source">
				<span className="aiwr-action__source-label">
					{ __( 'Selected text', 'wp-ai-writer' ) }
				</span>
				<p className="aiwr-action__source-text">{ sourceText }</p>
			</div>

			<SelectControl
				label={ __( 'Tone', 'wp-ai-writer' ) }
				value={ tone }
				options={ TONES }
				onChange={ setTone }
				__nextHasNoMarginBottom
			/>
			<SelectControl
				label={ __( 'Length', 'wp-ai-writer' ) }
				value={ length }
				options={ LENGTHS }
				onChange={ setLength }
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
				onInsert={ onReplace }
				onDiscard={ () => setPreview( '' ) }
				insertLabel={ __( 'Replace block content', 'wp-ai-writer' ) }
			/>
		</div>
	);
}
