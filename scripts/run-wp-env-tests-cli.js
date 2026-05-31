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
const wpEnvConfig = process.env.AHPC_WP_ENV_CONFIG;
const wpEnvContainer = process.env.AHPC_WP_ENV_CONTAINER || 'tests-cli';

module.exports = {
	envCwd,
	runWpEnvTestsCli,
	wpEnvBinary,
};

/**
 * Runs a `wp-env run tests-cli ...` command and retries only when wp-env
 * reports the transient "Environment not initialized" startup race.
 *
 * @param {string[]} commandArgs Arguments passed after `wp-env run tests-cli`.
 * @param {Object} [options] Spawn options for the underlying command.
 * @param {BufferEncoding} [options.encoding='utf8'] String encoding for output.
 * @param {number} [options.maxBuffer] Max stdout/stderr buffer size.
 * @return {import('node:child_process').SpawnSyncReturns<string>} The final
 * spawnSync result, whether from a success, a non-retryable failure, or the
 * last retry attempt.
 */
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
		const wpEnvArgs = [ 'run', wpEnvContainer ];

		if ( wpEnvConfig ) {
			wpEnvArgs.push( `--config=${ wpEnvConfig }` );
		}

		wpEnvArgs.push( `--env-cwd=${ envCwd }`, ...commandArgs );

		result = spawnSync(
			wpEnvBinary,
			wpEnvArgs,
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
			`wp-env environment is still initializing (attempt ${ attempt }/${ RETRY_COUNT }). Retrying in ${ RETRY_DELAY_MS / 1000 }s...\n`
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

/**
 * Sleeps synchronously between retry attempts so spawnSync-based callers can
 * keep their current control flow without a busy wait or Atomics.wait.
 *
 * @param {number} milliseconds Delay length.
 */
function sleep( milliseconds ) {
	// These callers use spawnSync and need a synchronous pause between retries
	// without a CPU-heavy busy wait or SharedArrayBuffer-dependent Atomics.wait.
	spawnSync( process.execPath, [
		'-e',
		`setTimeout( () => {}, ${ milliseconds } )`,
	] );
}
