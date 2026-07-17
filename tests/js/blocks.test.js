/**
 * Block helper tests.
 */
jest.mock(
	'@wordpress/blocks',
	() => ( {
		rawHandler: jest.fn( () => [ { name: 'core/paragraph' } ] ),
	} ),
	{ virtual: true }
);

import { rawHandler } from '@wordpress/blocks';
import { htmlToBlocks } from '../../src/utils/blocks';

describe( 'htmlToBlocks', () => {
	beforeEach( () => {
		rawHandler.mockClear();
	} );

	it( 'delegates conversion to rawHandler', () => {
		const blocks = htmlToBlocks( '<p>Hello</p>' );

		expect( rawHandler ).toHaveBeenCalledWith( { HTML: '<p>Hello</p>' } );
		expect( blocks ).toEqual( [ { name: 'core/paragraph' } ] );
	} );

	it( 'coerces nullish input to an empty string', () => {
		htmlToBlocks( null );

		expect( rawHandler ).toHaveBeenCalledWith( { HTML: '' } );
	} );
} );
