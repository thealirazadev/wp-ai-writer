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
