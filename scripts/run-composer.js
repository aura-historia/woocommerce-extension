const { spawnSync } = require('node:child_process');
const path = require('node:path');

const pluginDir = path.basename(process.cwd());
const binary = path.join(
	process.cwd(),
	'node_modules',
	'.bin',
	process.platform === 'win32' ? 'wp-env.cmd' : 'wp-env'
);
const composerArgs = process.argv.slice(2);

const result = spawnSync(
	binary,
	[
		'run',
		'tests-cli',
		`--env-cwd=wp-content/plugins/${pluginDir}`,
		'composer',
		...composerArgs,
	],
	{ encoding: 'utf8' }
);

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
