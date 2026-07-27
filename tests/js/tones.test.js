/**
 * Shared tone preset list and site-default resolution.
 */
import { TONES, DEFAULT_TONE, siteDefaultTone } from '../../src/tones';

describe( 'TONES', () => {
	it( 'covers the built-in presets', () => {
		const values = TONES.map( ( option ) => option.value );
		expect( values ).toEqual(
			expect.arrayContaining( [
				'professional',
				'friendly',
				'concise',
				'persuasive',
				'casual',
				'confident',
			] )
		);
	} );

	it( 'gives every preset a non-empty value and label', () => {
		for ( const option of TONES ) {
			expect( typeof option.value ).toBe( 'string' );
			expect( option.value.length ).toBeGreaterThan( 0 );
			expect( typeof option.label ).toBe( 'string' );
			expect( option.label.length ).toBeGreaterThan( 0 );
		}
	} );
} );

describe( 'siteDefaultTone', () => {
	afterEach( () => {
		delete window.aiwrEditor;
	} );

	it( 'returns the configured tone when it is a known preset', () => {
		window.aiwrEditor = { defaultTone: 'persuasive' };
		expect( siteDefaultTone() ).toBe( 'persuasive' );
	} );

	it( 'falls back to the default when the config is missing', () => {
		delete window.aiwrEditor;
		expect( siteDefaultTone() ).toBe( DEFAULT_TONE );
	} );

	it( 'falls back to the default when the configured tone is unknown', () => {
		window.aiwrEditor = { defaultTone: 'zany' };
		expect( siteDefaultTone() ).toBe( DEFAULT_TONE );
	} );
} );
