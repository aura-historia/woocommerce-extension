const { spawnSync } = require('node:child_process');
const path = require('node:path');

const projectRoot = process.cwd();
const projectDir = path.basename(projectRoot);
const wpEnvBinary = path.join(
	projectRoot,
	'node_modules',
	'.bin',
	process.platform === 'win32' ? 'wp-env.cmd' : 'wp-env'
);
const envCwd = `wp-content/plugins/${projectDir}`;
const ENVIRONMENT_NOT_INITIALIZED = 'Environment not initialized';
const RETRY_COUNT = 12;
const RETRY_DELAY_MS = 5000;

module.exports = {
	envCwd,
	runWpEnvTestsCli,
	wpEnvBinary,
};

function runWpEnvTestsCli(commandArgs, options = {}) {
	const {
		encoding = 'utf8',
		maxBuffer,
	} = options;
	const spawnOptions = { encoding };

	if ( maxBuffer !== undefined ) {
		spawnOptions.maxBuffer = maxBuffer;
	}

	let result;

	for ( let attempt = 1; attempt <= RETRY_COUNT; attempt++ ) {
		result = spawnSync(
			wpEnvBinary,
			[ 'run', 'tests-cli', `--env-cwd=${ envCwd }`, ...commandArgs ],
			spawnOptions
		);

		if ( result.error || result.status === 0 ) {
			return result;
		}

		if (
			! shouldRetry( result ) ||
			attempt === RETRY_COUNT
		) {
			return result;
		}

		process.stderr.write(
			`wp-env test environment is still initializing (attempt ${ attempt }/${ RETRY_COUNT }). Retrying in ${ RETRY_DELAY_MS / 1000 }s...\n`
		);
		sleep( RETRY_DELAY_MS );
	}

	return result;
}

function shouldRetry( result ) {
	return [ result.stdout, result.stderr ].some(
		( output ) =>
			typeof output === 'string' &&
			output.includes( ENVIRONMENT_NOT_INITIALIZED )
	);
}

function sleep( milliseconds ) {
	// These callers use spawnSync and need a synchronous pause between retries
	// without a CPU-heavy busy wait or SharedArrayBuffer-dependent Atomics.wait.
	spawnSync( process.execPath, [
		'-e',
		`setTimeout( () => {}, ${ milliseconds } )`,
	] );
}
