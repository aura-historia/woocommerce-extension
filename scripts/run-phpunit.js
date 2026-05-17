const { spawnSync } = require('node:child_process');
const path = require('node:path');

const pluginDir = path.basename(process.cwd());
const wpEnvBinary = path.join(
	process.cwd(),
	'node_modules',
	'.bin',
	process.platform === 'win32' ? 'wp-env.cmd' : 'wp-env'
);

const result = spawnSync(
	wpEnvBinary,
	[
		'run',
		'tests-cli',
		`--env-cwd=wp-content/plugins/${pluginDir}`,
		'vendor/bin/phpunit',
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
