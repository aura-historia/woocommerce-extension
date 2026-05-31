const fs = require('node:fs');
const path = require('node:path');
const {
	envCwd,
	runWpEnvTestsCli,
	wpEnvBinary,
} = require('./run-wp-env-tests-cli');

const projectRoot = process.cwd();
const projectDir = path.basename(projectRoot);
const targetPath = process.argv[2];
const pluginCheckPackage = process.env.AHPC_PLUGIN_CHECK_PACKAGE || 'plugin-check';
const pluginCheckRequirePath = '../plugin-check/cli.php';
const pluginCheckFields = [
	'file',
	'line',
	'column',
	'type',
	'severity',
	'code',
	'message',
	'docs',
].join(',');
const normalizedTargetPath = toPosixPath(targetPath || '');
const pluginCheckCommandArgs = [
	'wp',
	'--quiet',
	`--require=${pluginCheckRequirePath}`,
	'plugin',
	'check',
	normalizedTargetPath,
	'--format=strict-json',
	`--fields=${pluginCheckFields}`,
];

if (!targetPath) {
	fail('Usage: node scripts/run-plugin-check.js <plugin-path>');
}

if (!fs.existsSync(wpEnvBinary)) {
	fail('wp-env binary not found. Run `npm ci` first.');
}

if (!fs.existsSync(path.resolve(projectRoot, targetPath))) {
	fail(
		`Plugin Check target does not exist: ${targetPath}. Run \`npm run release:zip\` first.`
	);
}

console.log(`Running Plugin Check (PCP) against ${targetPath}.`);

const pluginCheckVersion = ensurePluginCheckInstalled();
const checkResult = runWpEnvCommand(pluginCheckCommandArgs, { allowFailure: true });

if (checkResult.status !== 0) {
	failWithCommandResult(
		'Plugin Check execution failed.',
		pluginCheckCommandArgs,
		checkResult
	);
	process.exit(checkResult.status === null ? 1 : checkResult.status);
}

const rawOutput = checkResult.stdout.trim();

if (
	!rawOutput ||
	rawOutput.includes('Checks complete. No errors found.') ||
	rawOutput.includes('Success: Checks complete. No errors found.')
) {
	handlePass(pluginCheckVersion);
}

const parsedIssues = parseIssues(rawOutput);

if (!Array.isArray(parsedIssues)) {
	failWithCommandResult(
		'Unable to parse Plugin Check output. Raw output is shown below for debugging.',
		pluginCheckCommandArgs,
		checkResult
	);
	process.exit(1);
}

const issues = parsedIssues.map(normalizeIssue).sort(compareIssues);
const errorCount = issues.filter((issue) => issue.type === 'ERROR').length;
const warningCount = issues.filter((issue) => issue.type === 'WARNING').length;

if (issues.length === 0) {
	handlePass(pluginCheckVersion);
}

console.error(
	[
		`Plugin Check (PCP) ${pluginCheckVersion} found ${issues.length} issue${issues.length === 1 ? '' : 's'}.`,
		`${errorCount} error${errorCount === 1 ? '' : 's'}, ${warningCount} warning${warningCount === 1 ? '' : 's'}.`,
	].join(' ')
);

for (const [file, fileIssues] of groupIssuesByFile(issues)) {
	console.error(`\n${file}`);

	for (const issue of fileIssues) {
		const location = formatLocation(issue);
		const severity = issue.severity > 0 ? ` severity ${issue.severity}` : '';
		console.error(
			`  ${location} ${issue.type}${severity} [${issue.code}] ${issue.message}`
		);

		if (issue.docs) {
			console.error(`      Docs: ${issue.docs}`);
		}

		emitGitHubAnnotation(issue);
	}
}

writeStepSummary(pluginCheckVersion, issues);
process.exit(1);

function handlePass(pluginCheckVersion) {
	console.log(`Plugin Check (PCP) ${pluginCheckVersion} passed with no warnings or errors.`);
	writeStepSummary(pluginCheckVersion, []);
	process.exit(0);
}

function ensurePluginCheckInstalled() {
	const isInstalled = runWpEnvCommand(
		['wp', '--quiet', 'plugin', 'is-installed', 'plugin-check'],
		{ allowFailure: true }
	);

	if (isInstalled.status !== 0) {
		console.log(`Installing Plugin Check (PCP) from ${pluginCheckPackage}.`);
		runWpEnvCommand([
			'wp',
			'--quiet',
			'plugin',
			'install',
			pluginCheckPackage,
			'--activate',
		]);
	} else {
		const isActive = runWpEnvCommand(
			['wp', '--quiet', 'plugin', 'is-active', 'plugin-check'],
			{ allowFailure: true }
		);

		if (isActive.status !== 0) {
			console.log('Activating Plugin Check (PCP).');
			runWpEnvCommand([
				'wp',
				'--quiet',
				'plugin',
				'activate',
				'plugin-check',
			]);
		}
	}

	const versionResult = runWpEnvCommand([
		'wp',
		'--quiet',
		'plugin',
		'get',
		'plugin-check',
		'--field=version',
	]);
	const version = versionResult.stdout.trim() || 'unknown';
	console.log(`Using Plugin Check (PCP) ${version}.`);
	return version;
}

function runWpEnvCommand(commandArgs, { allowFailure = false } = {}) {
	const result = runWpEnvTestsCli(commandArgs, {
		allowFailure,
		encoding: 'utf8',
		maxBuffer: 20 * 1024 * 1024,
	});

	if (result.error) {
		fail(result.error.message);
	}

	if (!allowFailure && result.status !== 0) {
		failWithCommandResult('wp-env command failed.', commandArgs, result);
		process.exit(result.status === null ? 1 : result.status);
	}

	return result;
}

function parseIssues(rawOutput) {
	const candidates = [rawOutput];
	const firstBracket = rawOutput.indexOf('[');
	const lastBracket = rawOutput.lastIndexOf(']');

	if (firstBracket !== -1 && lastBracket !== -1 && lastBracket > firstBracket) {
		candidates.push(rawOutput.slice(firstBracket, lastBracket + 1));
	}

	for (const candidate of candidates) {
		try {
			return JSON.parse(candidate);
		} catch (error) {
			// Try the next candidate.
		}
	}

	return null;
}

function normalizeIssue(issue) {
	return {
		...issue,
		file: normalizeIssueFile(issue.file),
		line: normalizeNumber(issue.line),
		column: normalizeNumber(issue.column),
		severity: normalizeNumber(issue.severity),
		code: String(issue.code || '').trim() || 'unknown_code',
		message: String(issue.message || '').trim() || 'Unknown Plugin Check message.',
		docs: String(issue.docs || '').trim(),
		type: String(issue.type || '').trim() || 'WARNING',
	};
}

function normalizeIssueFile(file) {
	let normalized = toPosixPath(String(file || '').trim());
	const repoPrefix = `/wp-content/plugins/${projectDir}/`;
	const targetPrefix = `${normalizedTargetPath.replace(/^\.\//, '').replace(/\/$/, '')}/`;

	if (!normalized) {
		return normalizedTargetPath.replace(/^\.\//, '');
	}

	const repoPrefixIndex = normalized.indexOf(repoPrefix);
	if (repoPrefixIndex !== -1) {
		normalized = normalized.slice(repoPrefixIndex + repoPrefix.length);
	}

	if (normalized.startsWith(`${projectDir}/`)) {
		normalized = normalized.slice(projectDir.length + 1);
	}

	if (targetPrefix !== '/' && normalized.startsWith(targetPrefix)) {
		normalized = normalized.slice(targetPrefix.length);
	}

	return normalized.replace(/^\.\//, '') || normalizedTargetPath.replace(/^\.\//, '');
}

function groupIssuesByFile(issues) {
	const grouped = new Map();

	for (const issue of issues) {
		if (!grouped.has(issue.file)) {
			grouped.set(issue.file, []);
		}

		grouped.get(issue.file).push(issue);
	}

	return grouped;
}

function compareIssues(left, right) {
	if (left.file !== right.file) {
		return left.file.localeCompare(right.file);
	}

	if (left.line !== right.line) {
		return left.line - right.line;
	}

	if (left.column !== right.column) {
		return left.column - right.column;
	}

	if (left.type !== right.type) {
		return left.type.localeCompare(right.type);
	}

	return left.code.localeCompare(right.code);
}

function formatLocation(issue) {
	if (issue.line <= 0) {
		return '-';
	}

	if (issue.column <= 0) {
		return `${issue.line}`;
	}

	return `${issue.line}:${issue.column}`;
}

function emitGitHubAnnotation(issue) {
	if (process.env.GITHUB_ACTIONS !== 'true') {
		return;
	}

	const annotationType = issue.type === 'ERROR' ? 'error' : 'warning';
	const properties = [
		`file=${escapeGitHubCommandProperty(issue.file)}`,
		`title=${escapeGitHubCommandProperty(`Plugin Check ${issue.type} ${issue.code}`)}`,
	];

	if (issue.line > 0) {
		properties.push(`line=${issue.line}`);
	}

	if (issue.column > 0) {
		properties.push(`col=${issue.column}`);
	}

	const messageLines = [issue.message];
	if (issue.docs) {
		messageLines.push(`Docs: ${issue.docs}`);
	}

	process.stdout.write(
		`::${annotationType} ${properties.join(',')}::${escapeGitHubCommandMessage(messageLines.join('\n'))}\n`
	);
}

function writeStepSummary(pluginCheckVersion, issues) {
	if (!process.env.GITHUB_STEP_SUMMARY) {
		return;
	}

	const summaryLines = [
		'## Plugin Check (PCP)',
		'',
		`- Version: \`${pluginCheckVersion}\``,
		`- Target: \`${normalizedTargetPath}\``,
	];

	if (issues.length === 0) {
		summaryLines.push('- Result: passed with no warnings or errors.');
		summaryLines.push('');
		fs.appendFileSync(process.env.GITHUB_STEP_SUMMARY, `${summaryLines.join('\n')}\n`);
		return;
	}

	const errorCount = issues.filter((issue) => issue.type === 'ERROR').length;
	const warningCount = issues.filter((issue) => issue.type === 'WARNING').length;

	summaryLines.push(
		`- Result: failed with **${issues.length}** issue${issues.length === 1 ? '' : 's'} (**${errorCount}** error${errorCount === 1 ? '' : 's'}, **${warningCount}** warning${warningCount === 1 ? '' : 's'}).`,
		'',
		'| File | Location | Type | Code | Message |',
		'| --- | --- | --- | --- | --- |'
	);

	for (const issue of issues) {
		summaryLines.push(
			`| \`${escapeMarkdownTableCell(issue.file)}\` | \`${escapeMarkdownTableCell(formatLocation(issue))}\` | ${issue.type} | \`${escapeMarkdownTableCell(issue.code)}\` | ${escapeMarkdownTableCell(issue.message)} |`
		);
	}

	summaryLines.push('');
	fs.appendFileSync(process.env.GITHUB_STEP_SUMMARY, `${summaryLines.join('\n')}\n`);
}

function failWithCommandResult(message, commandArgs, result) {
	console.error(message);
	console.error();
	console.error(`Command: wp-env run tests-cli --env-cwd=${envCwd} ${commandArgs.join(' ')}`);

	if (result.stdout && result.stdout.trim()) {
		console.error('\nSTDOUT:\n');
		console.error(result.stdout.trim());
	}

	if (result.stderr && result.stderr.trim()) {
		console.error('\nSTDERR:\n');
		console.error(result.stderr.trim());
	}
}

function fail(message) {
	console.error(message);
	process.exit(1);
}

function normalizeNumber(value) {
	const normalized = Number.parseInt(String(value || '0'), 10);
	return Number.isNaN(normalized) ? 0 : normalized;
}

function toPosixPath(value) {
	return value.replace(/\\/g, '/');
}

function escapeGitHubCommandProperty(value) {
	return String(value)
		.replace(/%/g, '%25')
		.replace(/\r/g, '%0D')
		.replace(/\n/g, '%0A')
		.replace(/:/g, '%3A')
		.replace(/,/g, '%2C');
}

function escapeGitHubCommandMessage(value) {
	return String(value)
		.replace(/%/g, '%25')
		.replace(/\r/g, '%0D')
		.replace(/\n/g, '%0A');
}

function escapeMarkdownTableCell(value) {
	return String(value).replace(/\|/g, '\\|').replace(/\n/g, ' ');
}
