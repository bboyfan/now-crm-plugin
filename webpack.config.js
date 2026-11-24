/**
 * Build configuration for the NOW CRM browser assets.
 *
 * Extends the WordPress scripts defaults so WordPress packages are externalized
 * through the DependencyExtractionWebpackPlugin, and writes the compiled files
 * (plus the generated .asset.php) into assets/build/ for enqueueing by PHP.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		'cart-track': path.resolve( __dirname, 'src/cart-track/index.ts' ),
		'campaign-editor': path.resolve(
			__dirname,
			'src/campaign-editor/index.tsx'
		),
		'content-editor': path.resolve(
			__dirname,
			'src/content-editor/index.tsx'
		),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'assets/build' ),
		filename: '[name].js',
	},
};
