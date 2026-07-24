/**
 * Alt text action panel: list images missing alt text and fix them one at a time.
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Button, Spinner, Notice } from '@wordpress/components';
import { useSelect, useDispatch } from '@wordpress/data';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { speak } from '@wordpress/a11y';

import { generate } from '../api/client';
import { findImagesMissingAlt, imageLabel } from '../utils/blocks';

function AltTextRow( { image } ) {
	const [ busy, setBusy ] = useState( false );
	const [ alt, setAlt ] = useState( '' );
	const [ error, setError ] = useState( null );
	const { updateBlockAttributes } = useDispatch( blockEditorStore );

	const name = imageLabel( image.url );

	async function onGenerate() {
		setBusy( true );
		setError( null );

		try {
			const out = await generate( {
				action: 'alt_text',
				stream: false,
				input: { attachment_id: image.id },
			} );
			setAlt( ( out.result && out.result.alt_text ) || '' );
			speak( __( 'Alt text ready.', 'wp-ai-writer' ) );
		} catch ( err ) {
			setError(
				err.message ||
					__(
						'Something went wrong. Please try again.',
						'wp-ai-writer'
					)
			);
			speak( __( 'Alt text generation failed.', 'wp-ai-writer' ) );
		} finally {
			setBusy( false );
		}
	}

	async function onApply() {
		updateBlockAttributes( image.clientId, { alt } );

		try {
			await generate( {
				action: 'apply_alt_text',
				stream: false,
				input: { attachment_id: image.id, alt_text: alt },
			} );
			speak( __( 'Alt text applied.', 'wp-ai-writer' ) );
		} catch ( err ) {
			setError(
				err.message ||
					__(
						'The alt text was added to the block but could not be saved to the media library.',
						'wp-ai-writer'
					)
			);
		}
	}

	return (
		<li className="aiwr-alt-row">
			<img
				className="aiwr-alt-row__thumb"
				src={ image.url }
				alt=""
				width="40"
				height="40"
			/>
			<div className="aiwr-alt-row__body">
				<span className="aiwr-alt-row__name">{ name }</span>

				{ error && (
					<Notice
						status="error"
						isDismissible={ false }
						className="aiwr-alt-row__error"
					>
						{ error }
					</Notice>
				) }

				{ alt && <p className="aiwr-alt-row__value">{ alt }</p> }

				<div className="aiwr-alt-row__actions">
					<Button
						variant="secondary"
						isBusy={ busy }
						aria-disabled={ busy }
						disabled={ busy }
						aria-label={ sprintf(
							/* translators: %s: image file name. */
							__( 'Generate alt text for %s', 'wp-ai-writer' ),
							name
						) }
						onClick={ onGenerate }
					>
						{ busy
							? __( 'Generating', 'wp-ai-writer' )
							: __( 'Generate', 'wp-ai-writer' ) }
					</Button>
					{ busy && <Spinner /> }
					{ alt && (
						<Button
							variant="primary"
							aria-label={ sprintf(
								/* translators: %s: image file name. */
								__( 'Apply alt text to %s', 'wp-ai-writer' ),
								name
							) }
							onClick={ onApply }
						>
							{ __( 'Apply', 'wp-ai-writer' ) }
						</Button>
					) }
				</div>
			</div>
		</li>
	);
}

export default function AltTextPanel() {
	const images = useSelect(
		( select ) =>
			findImagesMissingAlt( select( blockEditorStore ).getBlocks() ),
		[]
	);

	const supported = images.filter( ( image ) => image.supported );
	const unsupported = images.filter( ( image ) => ! image.supported );

	if ( ! images.length ) {
		return (
			<div className="aiwr-action">
				<Notice status="success" isDismissible={ false }>
					{ __( 'All images have alt text.', 'wp-ai-writer' ) }
				</Notice>
			</div>
		);
	}

	return (
		<div className="aiwr-action">
			<ul className="aiwr-alt-list">
				{ supported.map( ( image ) => (
					<AltTextRow key={ image.clientId } image={ image } />
				) ) }
			</ul>

			{ unsupported.length > 0 && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'Some images use an external URL and are not supported yet.',
						'wp-ai-writer'
					) }
				</Notice>
			) }
		</div>
	);
}
