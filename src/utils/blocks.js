/**
 * Block helpers: html-to-blocks conversion, and later selected-block text and missing-alt scanning.
 */
import { rawHandler } from '@wordpress/blocks';

/**
 * Convert sanitized HTML into an array of blocks using core's raw-paste handler.
 *
 * rawHandler applies the same safe transforms as pasting, so scripts and unknown markup are dropped
 * rather than injected as raw HTML.
 *
 * @param {string} html Server-sanitized HTML.
 * @return {Array} Parsed blocks.
 */
export function htmlToBlocks( html ) {
	return rawHandler( { HTML: String( html || '' ) } );
}

/**
 * Block types the rewrite action can read and replace.
 */
export const REWRITABLE_BLOCKS = [
	'core/paragraph',
	'core/heading',
	'core/list',
	'core/quote',
];

/**
 * Whether a block is one the rewrite action supports.
 *
 * @param {Object} block Block object.
 * @return {boolean} True when the block type is supported.
 */
export function isRewritableBlock( block ) {
	return !! block && REWRITABLE_BLOCKS.includes( block.name );
}

function stripTags( value ) {
	return String( value ?? '' )
		.replace( /<[^>]*>/g, '' )
		.replace( /\s+/g, ' ' )
		.trim();
}

/**
 * Extract the readable text of a supported block, including nested list/quote items.
 *
 * @param {Object} block Block object from the editor store.
 * @return {string} Plain text, or an empty string for unsupported blocks.
 */
export function blockToText( block ) {
	if ( ! isRewritableBlock( block ) ) {
		return '';
	}

	const attributes = block.attributes || {};
	const inner = Array.isArray( block.innerBlocks ) ? block.innerBlocks : [];

	if ( inner.length ) {
		return inner
			.map(
				( child ) =>
					blockToText( child ) ||
					stripTags( ( child.attributes || {} ).content )
			)
			.filter( Boolean )
			.join( '\n' );
	}

	switch ( block.name ) {
		case 'core/paragraph':
		case 'core/heading':
			return stripTags( attributes.content );
		case 'core/list':
			return stripTags( attributes.values );
		case 'core/quote':
			return stripTags( attributes.value );
		default:
			return '';
	}
}
