/**
 * SSE parser, content-type branching, and fallback/retry tests.
 */
import { splitSSE, generate, AIWRError } from '../../src/api/client';

const { TextEncoder, TextDecoder } = require( 'util' );

if ( ! global.TextEncoder ) {
	global.TextEncoder = TextEncoder;
}
if ( ! global.TextDecoder ) {
	global.TextDecoder = TextDecoder;
}

function jsonResponse( body, { ok = true, status = 200 } = {} ) {
	return {
		ok,
		status,
		headers: {
			get: ( h ) =>
				'content-type' === h.toLowerCase() ? 'application/json' : null,
		},
		json: async () => body,
	};
}

function sseResponse( chunks ) {
	const encoder = new TextEncoder();
	let i = 0;

	return {
		ok: true,
		status: 200,
		headers: {
			get: ( h ) =>
				'content-type' === h.toLowerCase() ? 'text/event-stream' : null,
		},
		body: {
			getReader: () => ( {
				read: async () => {
					if ( i < chunks.length ) {
						return {
							done: false,
							value: encoder.encode( chunks[ i++ ] ),
						};
					}
					return { done: true, value: undefined };
				},
			} ),
		},
	};
}

beforeEach( () => {
	window.aiwrEditor = {
		restUrl: '/wp-json/aiwr/v1/generate',
		nonce: 'test-nonce',
	};
} );

describe( 'splitSSE', () => {
	it( 'parses a single event', () => {
		const { events, rest } = splitSSE(
			'event: delta\ndata: {"text":"hi"}\n\n'
		);
		expect( rest ).toBe( '' );
		expect( events ).toEqual( [
			{ event: 'delta', data: '{"text":"hi"}' },
		] );
	} );

	it( 'parses multiple events in one buffer', () => {
		const buffer =
			'event: delta\ndata: {"text":"a"}\n\nevent: delta\ndata: {"text":"b"}\n\n';
		const { events } = splitSSE( buffer );
		expect( events ).toHaveLength( 2 );
		expect( events[ 1 ].data ).toBe( '{"text":"b"}' );
	} );

	it( 'returns the incomplete trailing event as rest', () => {
		const { events, rest } = splitSSE(
			'event: delta\ndata: {"text":"a"}\n\nevent: delta\ndata: {"tex'
		);
		expect( events ).toHaveLength( 1 );
		expect( rest ).toBe( 'event: delta\ndata: {"tex' );
	} );

	it( 'ignores comment keepalive lines', () => {
		const { events } = splitSSE(
			': ping\nevent: delta\ndata: {"text":"x"}\n\n'
		);
		expect( events ).toEqual( [
			{ event: 'delta', data: '{"text":"x"}' },
		] );
	} );

	it( 'joins multi-line data and strips the single leading space', () => {
		const { events } = splitSSE( 'data: line one\ndata: line two\n\n' );
		expect( events[ 0 ].data ).toBe( 'line one\nline two' );
	} );
} );

describe( 'generate', () => {
	it( 'returns a JSON result when the server responds with JSON', async () => {
		global.fetch = jest.fn().mockResolvedValue(
			jsonResponse( {
				action: 'draft',
				result: { html: '<p>Hi</p>' },
				usage: { input_tokens: 10, output_tokens: 5 },
				cost_estimate: null,
			} )
		);

		const out = await generate( {
			action: 'draft',
			input: { prompt: 'hi' },
		} );

		expect( out.streamed ).toBe( false );
		expect( out.result.html ).toBe( '<p>Hi</p>' );
		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'throws the server envelope on a JSON error', async () => {
		global.fetch = jest.fn().mockResolvedValue(
			jsonResponse(
				{
					code: 'aiwr_rate_limited',
					message: 'Too many requests.',
					data: { status: 429 },
				},
				{ ok: false, status: 429 }
			)
		);

		await expect(
			generate( { action: 'draft', input: { prompt: 'hi' } } )
		).rejects.toMatchObject( {
			code: 'aiwr_rate_limited',
			status: 429,
		} );
	} );

	it( 'assembles delta events and reports usage on a stream', async () => {
		global.fetch = jest
			.fn()
			.mockResolvedValue(
				sseResponse( [
					'event: delta\ndata: {"text":"Compost "}\n\n',
					'event: delta\ndata: {"text":"soil."}\n\n',
					'event: done\ndata: {"usage":{"input_tokens":8,"output_tokens":4},"cost_estimate":0.01}\n\n',
				] )
			);

		const deltas = [];
		const out = await generate( {
			action: 'draft',
			stream: true,
			input: { prompt: 'compost' },
			onDelta: ( text ) => deltas.push( text ),
		} );

		expect( out.streamed ).toBe( true );
		expect( out.text ).toBe( 'Compost soil.' );
		expect( out.usage.output_tokens ).toBe( 4 );
		expect( out.costEstimate ).toBe( 0.01 );
		expect( deltas[ deltas.length - 1 ] ).toBe( 'Compost soil.' );
	} );

	it( 'throws on an SSE error event without retrying', async () => {
		global.fetch = jest
			.fn()
			.mockResolvedValue(
				sseResponse( [
					'event: error\ndata: {"code":"aiwr_provider_error","message":"boom","data":{"status":502}}\n\n',
				] )
			);

		await expect(
			generate( {
				action: 'draft',
				stream: true,
				input: { prompt: 'x' },
			} )
		).rejects.toMatchObject( { code: 'aiwr_provider_error' } );
		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'retries once without streaming after a network failure', async () => {
		global.fetch = jest
			.fn()
			.mockRejectedValueOnce( new Error( 'network down' ) )
			.mockResolvedValueOnce(
				jsonResponse( {
					action: 'draft',
					result: { html: '<p>Fallback</p>' },
					usage: { input_tokens: 3, output_tokens: 2 },
					cost_estimate: null,
				} )
			);

		const out = await generate( {
			action: 'draft',
			stream: true,
			input: { prompt: 'x' },
		} );

		expect( out.streamed ).toBe( false );
		expect( out.result.html ).toBe( '<p>Fallback</p>' );
		expect( global.fetch ).toHaveBeenCalledTimes( 2 );
		expect(
			JSON.parse( global.fetch.mock.calls[ 1 ][ 1 ].body ).stream
		).toBe( false );
	} );

	it( 'retries when the stream ends before any delta', async () => {
		global.fetch = jest
			.fn()
			.mockResolvedValueOnce( sseResponse( [ ': ping\n\n' ] ) )
			.mockResolvedValueOnce(
				jsonResponse( {
					action: 'draft',
					result: { html: '<p>Recovered</p>' },
					usage: { input_tokens: 1, output_tokens: 1 },
					cost_estimate: null,
				} )
			);

		const out = await generate( {
			action: 'draft',
			stream: true,
			input: { prompt: 'x' },
		} );

		expect( out.result.html ).toBe( '<p>Recovered</p>' );
		expect( global.fetch ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'parses JSON when a streamed request is silently downgraded', async () => {
		global.fetch = jest.fn().mockResolvedValue(
			jsonResponse( {
				action: 'draft',
				result: { html: '<p>Downgraded</p>' },
				usage: { input_tokens: 5, output_tokens: 5 },
				cost_estimate: null,
			} )
		);

		const out = await generate( {
			action: 'draft',
			stream: true,
			input: { prompt: 'x' },
		} );

		expect( out.streamed ).toBe( false );
		expect( out.result.html ).toBe( '<p>Downgraded</p>' );
		expect( global.fetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'exposes AIWRError instances', async () => {
		global.fetch = jest.fn().mockResolvedValue(
			jsonResponse(
				{
					code: 'aiwr_not_configured',
					message: 'nope',
					data: { status: 409 },
				},
				{ ok: false, status: 409 }
			)
		);

		const error = await generate( {
			action: 'draft',
			input: { prompt: 'x' },
		} ).catch( ( e ) => e );

		expect( error ).toBeInstanceOf( AIWRError );
	} );
} );
