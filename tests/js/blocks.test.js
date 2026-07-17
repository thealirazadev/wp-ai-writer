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
import {
	htmlToBlocks,
	blockToText,
	isRewritableBlock,
} from '../../src/utils/blocks';

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

describe( 'isRewritableBlock', () => {
	it( 'accepts supported blocks and rejects others', () => {
		expect( isRewritableBlock( { name: 'core/paragraph' } ) ).toBe( true );
		expect( isRewritableBlock( { name: 'core/heading' } ) ).toBe( true );
		expect( isRewritableBlock( { name: 'core/image' } ) ).toBe( false );
		expect( isRewritableBlock( null ) ).toBe( false );
	} );
} );

describe( 'blockToText', () => {
	it( 'strips tags from paragraph content', () => {
		const block = {
			name: 'core/paragraph',
			attributes: { content: 'Hello <strong>bold</strong> world' },
		};
		expect( blockToText( block ) ).toBe( 'Hello bold world' );
	} );

	it( 'reads heading content', () => {
		const block = {
			name: 'core/heading',
			attributes: { content: 'A Title' },
		};
		expect( blockToText( block ) ).toBe( 'A Title' );
	} );

	it( 'joins nested list items with newlines', () => {
		const block = {
			name: 'core/list',
			attributes: {},
			innerBlocks: [
				{ name: 'core/list-item', attributes: { content: 'One' } },
				{ name: 'core/list-item', attributes: { content: 'Two' } },
			],
		};
		expect( blockToText( block ) ).toBe( 'One\nTwo' );
	} );

	it( 'returns an empty string for unsupported blocks', () => {
		expect( blockToText( { name: 'core/image', attributes: {} } ) ).toBe(
			''
		);
	} );
} );
