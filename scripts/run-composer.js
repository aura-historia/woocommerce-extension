const { runWpEnvTestsCli } = require('./run-wp-env-tests-cli');

const composerArgs = process.argv.slice(2);

const result = runWpEnvTestsCli( [ 'composer', ...composerArgs ], {
	encoding: 'utf8',
} );

if ( result.stdout ) {
	process.stdout.write(result.stdout);
}

if ( result.stderr ) {
	process.stderr.write(result.stderr);
}

if ( result.error ) {
	console.error(result.error.message);
	process.exit(1);
}

process.exit(result.status === null ? 1 : result.status);
