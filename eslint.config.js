/**
 * Flat ESLint config layered on the @wordpress/scripts defaults.
 *
 * The @wordpress/* packages are provided by WordPress at runtime and externalized by the build, so
 * they are intentionally not installed as dependencies. Turn off the import-resolution rules that
 * would otherwise flag them; resolution is handled by the build's externals.
 */
const wpConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...wpConfig,
	{
		rules: {
			'import/no-unresolved': 'off',
			'import/no-extraneous-dependencies': 'off',
		},
	},
];
