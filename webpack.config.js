/**
 * The stock @wordpress/scripts webpack config, with `devServer.proxy` converted
 * from the object form to the array form.
 *
 * webpack-dev-server is pinned to 5.x in the package.json overrides to clear the
 * source-code-exposure and request-forgery advisories; wp-scripts 33 still emits
 * the v4 object form, which v5 rejects at schema validation, so `wp-scripts start
 * --hot` would refuse to boot. This performs the same normalisation v4 did
 * internally. Drop this file once wp-scripts ships a v5-compatible devServer.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config.js' );

const toProxyArray = ( proxy ) =>
	Object.entries( proxy ).map( ( [ context, options ] ) =>
		typeof options === 'string'
			? { context, target: options }
			: { ...options, context }
	);

const withProxyArray = ( config ) => {
	const proxy = config.devServer && config.devServer.proxy;

	if ( ! proxy || Array.isArray( proxy ) ) {
		return config;
	}

	return {
		...config,
		devServer: { ...config.devServer, proxy: toProxyArray( proxy ) },
	};
};

module.exports = Array.isArray( defaultConfig )
	? defaultConfig.map( withProxyArray )
	: withProxyArray( defaultConfig );
