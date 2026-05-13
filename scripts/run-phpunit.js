const { spawnSync } = require('node:child_process');
const path = require('node:path');

const pluginDir = path.basename(process.cwd());
const wpEnvBinary = path.join(
	process.cwd(),
	'node_modules',
	'.bin',
	process.platform === 'win32' ? 'wp-env.cmd' : 'wp-env'
);

const installPathResult = spawnSync(wpEnvBinary, ['install-path'], {
	encoding: 'utf8',
});

if ( installPathResult.error ) {
	console.error(installPathResult.error.message);
	process.exit(1);
}

if ( installPathResult.status !== 0 ) {
	if ( installPathResult.stdout ) {
		process.stdout.write(installPathResult.stdout);
	}
	if ( installPathResult.stderr ) {
		process.stderr.write(installPathResult.stderr);
	}
	process.exit(installPathResult.status === null ? 1 : installPathResult.status);
}

const installPath = installPathResult.stdout
	.split(/\r?\n/)
	.map((line) => line.trim())
	.find(Boolean);

if ( ! installPath ) {
	console.error('Could not determine the wp-env install path.');
	process.exit(1);
}

const composeFile = path.join(installPath, 'docker-compose.yml');
const result = spawnSync(
	'docker',
	[
		'compose',
		'-f',
		composeFile,
		'exec',
		'-T',
		'-w',
		`/var/www/html/wp-content/plugins/${pluginDir}`,
		'tests-cli',
		'vendor/bin/phpunit',
	],
	{ stdio: 'inherit' }
);

if ( result.error ) {
	console.error(result.error.message);
	process.exit(1);
}

process.exit(result.status === null ? 1 : result.status);
