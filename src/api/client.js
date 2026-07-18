/**
 * REST client for the generate proxy.
 *
 * Handles both response shapes: an SSE stream (text/event-stream) and a plain JSON body. When a
 * streamed request fails at the network or parse level before the first delta, it retries exactly
 * once without streaming. The provider key never reaches this layer; only the proxy URL and nonce.
 */

/**
 * Error carrying the plugin's standard { code, message } envelope.
 *
 * `retriable` marks a streaming transport/parse failure that occurred before any content arrived,
 * which the caller may retry without streaming.
 */
export class AIWRError extends Error {
	constructor( {
		code,
		message,
		status,
		retriable = false,
		sawDelta = false,
	} ) {
		super( message || '' );
		this.name = 'AIWRError';
		this.code = code || 'aiwr_error';
		this.status = status || 0;
		this.retriable = retriable;
		this.sawDelta = sawDelta;
	}
}

function getConfig() {
	return ( typeof window !== 'undefined' && window.aiwrEditor ) || {};
}

/**
 * Split an SSE buffer into complete events plus the incomplete trailing remainder.
 *
 * @param {string} buffer Accumulated stream text.
 * @return {{events: Array<{event: string, data: string}>, rest: string}} Parsed events and remainder.
 */
export function splitSSE( buffer ) {
	const chunks = buffer.split( '\n\n' );
	const rest = chunks.pop();
	const events = [];

	for ( const raw of chunks ) {
		if ( ! raw.trim() ) {
			continue;
		}

		let event = 'message';
		const dataLines = [];

		for ( const line of raw.split( '\n' ) ) {
			if ( line.startsWith( ':' ) ) {
				continue;
			}
			if ( line.startsWith( 'event:' ) ) {
				event = line.slice( 6 ).trim();
			} else if ( line.startsWith( 'data:' ) ) {
				dataLines.push( line.slice( 5 ).replace( /^ /, '' ) );
			}
		}

		events.push( { event, data: dataLines.join( '\n' ) } );
	}

	return { events, rest };
}

function parseJSON( text ) {
	try {
		return JSON.parse( text );
	} catch {
		return null;
	}
}

async function readStream( response, onDelta ) {
	const reader = response.body.getReader();
	const decoder = new TextDecoder();
	let buffer = '';
	let assembled = '';
	let sawDelta = false;
	let done = null;

	for (;;) {
		let chunk;
		try {
			chunk = await reader.read();
		} catch {
			throw new AIWRError( {
				code: 'aiwr_stream_interrupted',
				retriable: ! sawDelta,
				sawDelta,
			} );
		}

		if ( chunk.done ) {
			break;
		}

		buffer += decoder.decode( chunk.value, { stream: true } );
		const parsed = splitSSE( buffer );
		buffer = parsed.rest;

		for ( const ev of parsed.events ) {
			if ( 'delta' === ev.event ) {
				const data = parseJSON( ev.data );
				if ( data && 'string' === typeof data.text ) {
					assembled += data.text;
					sawDelta = true;
					if ( onDelta ) {
						onDelta( assembled );
					}
				}
			} else if ( 'done' === ev.event ) {
				done = parseJSON( ev.data ) || {};
			} else if ( 'error' === ev.event ) {
				const envelope = parseJSON( ev.data ) || {};
				throw new AIWRError( {
					code: envelope.code,
					message: envelope.message,
					status: envelope.data && envelope.data.status,
					retriable: false,
					sawDelta,
				} );
			}
		}
	}

	if ( ! done ) {
		throw new AIWRError( {
			code: 'aiwr_stream_incomplete',
			retriable: ! sawDelta,
			sawDelta,
		} );
	}

	return {
		streamed: true,
		text: assembled,
		usage: done.usage || null,
		costEstimate:
			'undefined' === typeof done.cost_estimate
				? null
				: done.cost_estimate,
	};
}

async function doRequest( params ) {
	const {
		action,
		input,
		options = {},
		postId = 0,
		stream = false,
		onDelta,
	} = params;
	const config = getConfig();

	const body = JSON.stringify( {
		action,
		stream: !! stream,
		post_id: postId,
		input,
		options,
	} );

	let response;
	try {
		response = await fetch( config.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			body,
		} );
	} catch {
		throw new AIWRError( { code: 'aiwr_network_error', retriable: true } );
	}

	const contentType = response.headers.get( 'Content-Type' ) || '';

	if ( contentType.includes( 'text/event-stream' ) && response.body ) {
		return readStream( response, onDelta );
	}

	const data = await response.json().catch( () => null );

	if ( ! response.ok ) {
		const envelope = data && data.code ? data : {};
		throw new AIWRError( {
			code: envelope.code,
			message: envelope.message,
			status:
				( envelope.data && envelope.data.status ) || response.status,
			retriable: false,
		} );
	}

	return {
		streamed: false,
		result: data ? data.result : null,
		usage: data ? data.usage : null,
		costEstimate: data ? data.cost_estimate : null,
	};
}

/**
 * Run a generate request, retrying once without streaming on an early stream failure.
 *
 * @param {Object} params Request parameters (action, input, options, postId, stream, onDelta).
 * @return {Promise<Object>} Normalized result.
 */
export async function generate( params ) {
	try {
		return await doRequest( params );
	} catch ( err ) {
		if ( params.stream && err instanceof AIWRError && err.retriable ) {
			return doRequest( { ...params, stream: false } );
		}
		throw err;
	}
}
