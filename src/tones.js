/**
 * Shared tone presets for the draft and rewrite actions.
 *
 * The values mirror the server-side allowlist in AIWR_Prompts::tones(). The REST route validates
 * the chosen tone against that allowlist, so this list only drives the sidebar controls; it is
 * never the authority.
 */
import { __ } from '@wordpress/i18n';

export const DEFAULT_TONE = 'professional';

export const TONES = [
	{ value: 'professional', label: __( 'Professional', 'wp-ai-writer' ) },
	{ value: 'friendly', label: __( 'Friendly', 'wp-ai-writer' ) },
	{ value: 'concise', label: __( 'Concise', 'wp-ai-writer' ) },
	{ value: 'persuasive', label: __( 'Persuasive', 'wp-ai-writer' ) },
	{ value: 'casual', label: __( 'Casual', 'wp-ai-writer' ) },
	{ value: 'confident', label: __( 'Confident', 'wp-ai-writer' ) },
];

/**
 * The site default tone from the localized editor config, guarded to a known preset.
 *
 * @return {string} A tone value guaranteed to be in TONES.
 */
export function siteDefaultTone() {
	const config = ( typeof window !== 'undefined' && window.aiwrEditor ) || {};
	const tone = config.defaultTone;

	return TONES.some( ( option ) => option.value === tone )
		? tone
		: DEFAULT_TONE;
}
