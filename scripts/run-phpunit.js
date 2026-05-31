const { runWpEnvTestsCli } = require('./run-wp-env-tests-cli');

const result = runWpEnvTestsCli( [ 'vendor/bin/phpunit' ], {
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
