const cliPath = require.resolve( '@wordpress/env/lib/cli.js', {
	paths: [ process.cwd(), __dirname ],
} );
const cli = require( cliPath )();
const keepAlive = setInterval( () => {}, 1000 );

cli.parseAsync( [ 'start', ...process.argv.slice( 2 ) ] ).finally( () => {
	clearInterval( keepAlive );
} );
