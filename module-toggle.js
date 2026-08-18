#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const readline = require('readline');

const PHP_FILE = path.join(__dirname, 'sandbox-wp-debugger.php');
const MODULES_START_MARKER = '// Module toggle declarations start.';
const MODULES_END_MARKER = '// Module toggle declarations end.';
const MODULE_LINE_REGEX = /^( \* )?(new SWPD\\([a-zA-Z_][a-zA-Z0-9_]*)([^;]*;))$/;
const SLOW_QUERY_SORT_OPTIONS = ['execution', 'time', 'query', 'backtrace', 'connection'];
const SLOW_QUERY_DEFAULTS = {
	debug: false,
	slowMs: false,
	limit: -1,
	sort: 'execution'
};

// ANSI Color codes
const colors = {
	reset: '\x1b[0m',
	green: '\x1b[32m',
	red: '\x1b[31m',
	yellow: '\x1b[33m',
	cyan: '\x1b[36m',
	bright: '\x1b[1m'
};

// TUI state
let selectedIndex = 0;
let moduleStatus = {};
let MODULES = [];
let slowQueryOptions = { ...SLOW_QUERY_DEFAULTS };

function readPhpFile () {
	try {
		return fs.readFileSync(PHP_FILE, 'utf8');
	} catch (err) {
		if (err.code === 'ENOENT') {
			console.error(`Error: PHP file not found at ${PHP_FILE}`);
			console.error('Make sure you are running this script from the correct directory.');
		} else if (err.code === 'EACCES') {
			console.error(`Error: Permission denied reading ${PHP_FILE}`);
			console.error('Check file permissions and try again.');
		} else {
			console.error('Error reading PHP file:', err.message);
		}
		process.exit(1);
	}
}

function writePhpFile (content) {
	try {
		fs.writeFileSync(PHP_FILE, content, 'utf8');
	} catch (err) {
		if (err.code === 'EACCES') {
			console.error(`Error: Permission denied writing to ${PHP_FILE}`);
			console.error('Check file permissions and try again.');
		} else if (err.code === 'ENOSPC') {
			console.error('Error: No space left on device.');
		} else {
			console.error('Error writing PHP file:', err.message);
		}
		process.exit(1);
	}
}

function getModuleRegion (content) {
	const startMarkerIndex = content.indexOf(MODULES_START_MARKER);
	const endMarkerIndex = content.indexOf(MODULES_END_MARKER);

	if (startMarkerIndex === -1 || endMarkerIndex === -1 || endMarkerIndex <= startMarkerIndex) {
		throw new Error('Module declaration markers are missing or out of order.');
	}

	const regionStart = content.indexOf('\n', startMarkerIndex) + 1;
	const regionEnd = content.lastIndexOf('\n', endMarkerIndex);

	if (regionStart === 0 || regionEnd < regionStart) {
		throw new Error('Module declaration region is malformed.');
	}

	return {
		start: regionStart,
		end: regionEnd,
		content: content.slice(regionStart, regionEnd)
	};
}

function parseModules (content) {
	const region = getModuleRegion(content);
	const modules = [];

	region.content.split('\n').forEach(line => {
		const match = line.match(MODULE_LINE_REGEX);
		if (match) {
			modules.push({
				name: match[3],
				declaration: match[2],
				enabled: !match[1]
			});
		}
	});

	if (modules.length === 0) {
		throw new Error('No module declarations were found between the markers.');
	}

	return { region, modules };
}

function renderModules (modules) {
	const chunks = [];
	let disabledDeclarations = [];

	const flushDisabledDeclarations = () => {
		if (disabledDeclarations.length === 0) {
			return;
		}

		chunks.push([
			'/*',
			...disabledDeclarations.map(declaration => ` * ${declaration}`),
			' */'
		].join('\n'));
		disabledDeclarations = [];
	};

	modules.forEach(module => {
		if (module.enabled) {
			flushDisabledDeclarations();
			chunks.push(module.declaration);
		} else {
			disabledDeclarations.push(module.declaration);
		}
	});

	flushDisabledDeclarations();

	return `\n${chunks.join('\n\n')}\n`;
}

function getAvailableModules () {
	const content = readPhpFile();
	return parseModules(content).modules.map(module => module.name).sort();
}

function getModuleStatus () {
	const content = readPhpFile();
	const status = {};

	parseModules(content).modules.forEach(module => {
		status[module.name] = module.enabled;
	});

	return status;
}

function toggleModule (moduleName, enable) {
	let content = readPhpFile();
	const parsed = parseModules(content);
	const module = parsed.modules.find(item => item.name === moduleName);

	if (!module) {
		throw new Error(`Module declaration not found for '${moduleName}'.`);
	}

	module.enabled = enable;
	content = content.slice(0, parsed.region.start) + renderModules(parsed.modules) + content.slice(parsed.region.end);

	writePhpFile(content);
}

function parseSlowQueryOptions (declaration) {
	const options = { ...SLOW_QUERY_DEFAULTS };
	const debugMatch = declaration.match(/'debug'\s*=>\s*(true|false)/);
	const slowMsMatch = declaration.match(/'slow_ms'\s*=>\s*(false|\d+(?:\.\d+)?)/);
	const limitMatch = declaration.match(/'limit'\s*=>\s*(-?\d+)/);
	const sortMatch = declaration.match(/'sort'\s*=>\s*'([^']+)'/);

	if (debugMatch) options.debug = debugMatch[1] === 'true';
	if (slowMsMatch) options.slowMs = slowMsMatch[1] === 'false' ? false : Number(slowMsMatch[1]);
	if (limitMatch) options.limit = Number(limitMatch[1]);
	if (sortMatch && SLOW_QUERY_SORT_OPTIONS.includes(sortMatch[1])) options.sort = sortMatch[1];

	return options;
}

function formatSlowQueryDeclaration (options) {
	const slowMs = options.slowMs === false ? 'false' : options.slowMs;
	return `new SWPD\\Slow_Queries( array( 'debug' => ${options.debug}, 'slow_ms' => ${slowMs}, 'limit' => ${options.limit}, 'sort' => '${options.sort}' ) );`;
}

function parseSlowQueryCliOptions (args) {
	const options = {};

	args.forEach(arg => {
		const match = arg.match(/^--([a-z-]+)=(.+)$/);
		if (!match) {
			throw new Error(`Invalid Slow_Queries option '${arg}'. Use --option=value.`);
		}

		const [, name, value] = match;
		switch (name) {
		case 'debug':
			if (!['true', 'false'].includes(value)) throw new Error('--debug must be true or false.');
			options.debug = value === 'true';
			break;
		case 'slow-ms':
			if (value === 'false') {
				options.slowMs = false;
			} else if (!Number.isNaN(Number(value)) && Number(value) >= 0) {
				options.slowMs = Number(value);
			} else {
				throw new Error('--slow-ms must be false or a non-negative number.');
			}
			break;
		case 'limit':
			if (!Number.isInteger(Number(value)) || Number(value) < -1) throw new Error('--limit must be -1 or a non-negative integer.');
			options.limit = Number(value);
			break;
		case 'sort':
			if (!SLOW_QUERY_SORT_OPTIONS.includes(value)) {
				throw new Error(`--sort must be one of: ${SLOW_QUERY_SORT_OPTIONS.join(', ')}.`);
			}
			options.sort = value;
			break;
		default:
			throw new Error(`Unknown Slow_Queries option '--${name}'.`);
		}
	});

	return options;
}

function configureSlowQueries (optionArgs) {
	let content = readPhpFile();
	const parsed = parseModules(content);
	const module = parsed.modules.find(item => item.name === 'Slow_Queries');

	if (!module) {
		throw new Error('Slow_Queries module declaration was not found.');
	}

	const options = {
		...parseSlowQueryOptions(module.declaration),
		...parseSlowQueryCliOptions(optionArgs)
	};
	module.declaration = formatSlowQueryDeclaration(options);
	content = content.slice(0, parsed.region.start) + renderModules(parsed.modules) + content.slice(parsed.region.end);
	writePhpFile(content);

	return options;
}

function getSlowQueryOptions () {
	const module = parseModules(readPhpFile()).modules.find(item => item.name === 'Slow_Queries');
	return module ? parseSlowQueryOptions(module.declaration) : { ...SLOW_QUERY_DEFAULTS };
}

// Utility Functions
function levenshteinDistance (str1, str2) {
	const matrix = [];
	for (let i = 0; i <= str2.length; i++) {
		matrix[i] = [i];
	}
	for (let j = 0; j <= str1.length; j++) {
		matrix[0][j] = j;
	}
	for (let i = 1; i <= str2.length; i++) {
		for (let j = 1; j <= str1.length; j++) {
			if (str2.charAt(i - 1) === str1.charAt(j - 1)) {
				matrix[i][j] = matrix[i - 1][j - 1];
			} else {
				matrix[i][j] = Math.min(
					matrix[i - 1][j - 1] + 1,
					matrix[i][j - 1] + 1,
					matrix[i - 1][j] + 1
				);
			}
		}
	}
	return matrix[str2.length][str1.length];
}

function findSimilarModules (moduleName, availableModules) {
	return availableModules
		.map(module => ({
			name: module,
			distance: levenshteinDistance(moduleName.toLowerCase(), module.toLowerCase())
		}))
		.filter(item => item.distance <= 3 && item.distance > 0)
		.sort((a, b) => a.distance - b.distance)
		.slice(0, 3)
		.map(item => item.name);
}

function validateModuleName (moduleName, availableModules) {
	if (availableModules.includes(moduleName)) {
		return { valid: true };
	}

	const suggestions = findSimilarModules(moduleName, availableModules);
	if (suggestions.length > 0) {
		return {
			valid: false,
			error: `Unknown module '${moduleName}'. Did you mean: ${suggestions.join(', ')}?`
		};
	}

	return {
		valid: false,
		error: `Unknown module '${moduleName}'. Use 'list' to see available modules.`
	};
}

function confirmAction (message) {
	return new Promise((resolve) => {
		const rl = readline.createInterface({
			input: process.stdin,
			output: process.stdout
		});

		rl.question(`${message} (y/N): `, (answer) => {
			rl.close();
			resolve(answer.toLowerCase() === 'y' || answer.toLowerCase() === 'yes');
		});
	});
}

function parseModuleList (moduleString) {
	return moduleString.split(',').map(m => m.trim()).filter(m => m.length > 0);
}

// CLI Functions
function showUsage () {
	console.log('Usage:');
	console.log('  node module-toggle.js                      - Interactive TUI mode');
	console.log('  node module-toggle.js list                 - Show all modules and their status');
	console.log('  node module-toggle.js help                 - Show this help message');
	console.log('  node module-toggle.js enable <module(s)>   - Enable one or more modules');
	console.log('  node module-toggle.js disable <module(s)>  - Disable one or more modules');
	console.log('  node module-toggle.js toggle <module>      - Toggle a module on/off');
	console.log('  node module-toggle.js configure Slow_Queries [options]');
	console.log('  node module-toggle.js enable-all           - Enable all modules');
	console.log('  node module-toggle.js disable-all          - Disable all modules (with confirmation)');
	console.log('');
	console.log('For multiple modules, separate with commas: enable Mod1,Mod2,Mod3');
	console.log('Slow_Queries options: --debug=true|false --slow-ms=false|N --limit=-1|N');
	console.log(`                      --sort=${SLOW_QUERY_SORT_OPTIONS.join('|')}`);
	console.log('Options can also follow: enable Slow_Queries');
	console.log('');
	console.log('Available modules:');
	getAvailableModules().forEach(module => console.log(`  ${module}`));
}

function showStatus () {
	const modules = getAvailableModules();
	const status = getModuleStatus();
	const enabledCount = Object.values(status).filter(Boolean).length;
	const totalCount = modules.length;
	const currentSlowQueryOptions = getSlowQueryOptions();

	console.log('Module Status:');
	console.log('==============');
	console.log(`${enabledCount}/${totalCount} modules enabled`);
	console.log('');

	modules.forEach(module => {
		const enabled = status[module];
		const symbol = enabled ? `${colors.green}✓${colors.reset}` : `${colors.red}✗${colors.reset}`;
		const state = enabled ? `${colors.green}ENABLED${colors.reset}` : `${colors.red}DISABLED${colors.reset}`;

		// Add usage hints for common modules
		let hint = '';
		if (module === 'Slow_Queries') hint = ` (sort=${currentSlowQueryOptions.sort}, debug=${currentSlowQueryOptions.debug})`;
		else if (module === 'WP_Redirect') hint = ' (debugs wp_redirect issues)';
		else if (module === 'Apply_Filters') hint = ' (tracks filter modifications)';
		else if (module === 'Remote_Requests') hint = ' (logs HTTP requests)';
		else if (module === 'Timers') hint = ' (performance timing utilities)';

		console.log(`${symbol} ${module.padEnd(20)} ${state}${colors.yellow}${hint}${colors.reset}`);
	});

	if (enabledCount === 0) {
		console.log('');
		console.log(`${colors.yellow}💡 Tip: Enable modules to start debugging. Use 'enable <module>' or the TUI mode.${colors.reset}`);
	} else if (enabledCount === totalCount) {
		console.log('');
		console.log(`${colors.yellow}⚠️  All modules enabled - this may generate significant log output.${colors.reset}`);
	}
}

// TUI Functions
function enterAltBuffer () {
	process.stdout.write('\x1b[?1049h'); // Enter alternate screen buffer
	process.stdout.write('\x1b[2J\x1b[H'); // Clear screen and go to top
}

function exitAltBuffer () {
	process.stdout.write('\x1b[?1049l'); // Exit alternate screen buffer
}

function clearScreen () {
	process.stdout.write('\x1b[2J\x1b[H');
}

function hideCursor () {
	process.stdout.write('\x1b[?25l');
}

function showCursor () {
	process.stdout.write('\x1b[?25h');
}

function drawInterface () {
	clearScreen();

	console.log(`${colors.cyan}┌───────────────────────────────────────────────────────┐${colors.reset}`);
	console.log(`${colors.cyan}│${colors.bright}               Sandbox WP Debugger Toggle              ${colors.reset}${colors.cyan}│${colors.reset}`);
	console.log(`${colors.cyan}├───────────────────────────────────────────────────────┤${colors.reset}`);
	console.log(`${colors.cyan}│ Use ↑↓, ENTER to toggle, S to change query sort, Q quit│${colors.reset}`);
	console.log(`${colors.cyan}└───────────────────────────────────────────────────────┘${colors.reset}`);
	console.log('');

	MODULES.forEach((module, index) => {
		const isSelected = index === selectedIndex;
		const isEnabled = moduleStatus[module];
		const statusChar = isEnabled ? '✓' : '✗';
		const statusColor = isEnabled ? colors.green : colors.red;
		const statusText = isEnabled ? 'ENABLED ' : 'DISABLED';

		const arrow = isSelected ? `${colors.yellow}►${colors.reset} ` : '  ';
		const highlight = isSelected ? '\x1b[7m' : '';
		const reset = isSelected ? '\x1b[0m' : '';

		const optionText = module === 'Slow_Queries' ? ` sort=${slowQueryOptions.sort}` : '';
		console.log(`${highlight}${arrow}${statusColor}${statusChar}${colors.reset} ${module.padEnd(20)} ${statusText}${optionText}${reset}`);
	});

	console.log('');
	console.log(`${colors.cyan}Press S on Slow_Queries to cycle its sort mode${colors.reset}`);
}

function setupInput () {
	process.stdin.setRawMode(true);
	process.stdin.resume();
	process.stdin.setEncoding('utf8');

	process.stdin.on('data', (key) => {
		switch (key) {
		case '\u0003': // Ctrl+C
		case 'q':
		case 'Q':
			cleanup();
			process.exit(0);
			break;

		case '\u001b[A': // Up arrow
			selectedIndex = Math.max(0, selectedIndex - 1);
			drawInterface();
			break;

		case '\u001b[B': // Down arrow
			selectedIndex = Math.min(MODULES.length - 1, selectedIndex + 1);
			drawInterface();
			break;

		case '\r': { // Enter
			const selectedModule = MODULES[selectedIndex];
			const currentStatus = moduleStatus[selectedModule];
			toggleModule(selectedModule, !currentStatus);
			moduleStatus = getModuleStatus();
			drawInterface();
			break;
		}

		case 's':
		case 'S': {
			const selectedModule = MODULES[selectedIndex];
			if (selectedModule === 'Slow_Queries') {
				const currentIndex = SLOW_QUERY_SORT_OPTIONS.indexOf(slowQueryOptions.sort);
				const nextSort = SLOW_QUERY_SORT_OPTIONS[(currentIndex + 1) % SLOW_QUERY_SORT_OPTIONS.length];
				slowQueryOptions = configureSlowQueries([`--sort=${nextSort}`]);
				drawInterface();
			}
			break;
		}
		}
	});
}

function cleanup () {
	showCursor();
	exitAltBuffer();
	process.stdin.setRawMode(false);
	process.stdin.pause();
}

function startTUI () {
	// Handle cleanup on exit
	process.on('SIGINT', cleanup);
	process.on('SIGTERM', cleanup);
	process.on('exit', cleanup);

	MODULES = getAvailableModules();
	moduleStatus = getModuleStatus();
	slowQueryOptions = getSlowQueryOptions();

	enterAltBuffer();
	hideCursor();
	drawInterface();
	setupInput();
}

// Main CLI logic
async function main () {
	const args = process.argv.slice(2);

	if (args.length === 0) {
		startTUI();
	} else {
		const command = args[0];
		const moduleName = args[1];

		switch (command) {
		case 'list':
			MODULES = getAvailableModules();
			moduleStatus = getModuleStatus();
			showStatus();
			break;

		case 'enable': {
			if (!moduleName) {
				console.error('Error: Module name(s) required');
				console.error('Usage: enable <module> or enable <mod1,mod2,mod3>');
				process.exit(1);
			}

			const availableModules = getAvailableModules();
			const moduleNames = parseModuleList(moduleName);
			const results = [];
			const optionArgs = args.slice(2);

			if (optionArgs.length > 0) {
				if (moduleNames.length !== 1 || moduleNames[0] !== 'Slow_Queries') {
					throw new Error('Configuration options can only be used when enabling Slow_Queries by itself.');
				}
				configureSlowQueries(optionArgs);
			}

			for (const module of moduleNames) {
				const validation = validateModuleName(module, availableModules);
				if (!validation.valid) {
					console.error(`Error: ${validation.error}`);
					process.exit(1);
				}
				toggleModule(module, true);
				results.push(module);
			}

			if (results.length === 1) {
				console.log(`${colors.green}✓${colors.reset} Enabled ${results[0]}`);
			} else {
				console.log(`${colors.green}✓${colors.reset} Enabled ${results.length} modules: ${results.join(', ')}`);
			}
			break;
		}

		case 'configure': {
			if (moduleName !== 'Slow_Queries') {
				throw new Error('Only Slow_Queries currently has configurable options.');
			}

			const options = configureSlowQueries(args.slice(2));
			console.log(`${colors.green}✓${colors.reset} Configured Slow_Queries: debug=${options.debug}, slow-ms=${options.slowMs}, limit=${options.limit}, sort=${options.sort}`);
			break;
		}

		case 'disable': {
			if (!moduleName) {
				console.error('Error: Module name(s) required');
				console.error('Usage: disable <module> or disable <mod1,mod2,mod3>');
				process.exit(1);
			}

			const availableModules = getAvailableModules();
			const moduleNames = parseModuleList(moduleName);
			const results = [];

			for (const module of moduleNames) {
				const validation = validateModuleName(module, availableModules);
				if (!validation.valid) {
					console.error(`Error: ${validation.error}`);
					process.exit(1);
				}
				toggleModule(module, false);
				results.push(module);
			}

			if (results.length === 1) {
				console.log(`${colors.red}✗${colors.reset} Disabled ${results[0]}`);
			} else {
				console.log(`${colors.red}✗${colors.reset} Disabled ${results.length} modules: ${results.join(', ')}`);
			}
			break;
		}

		case 'toggle': {
			if (!moduleName) {
				console.error('Error: Module name required');
				process.exit(1);
			}
			const availableModules = getAvailableModules();
			const validation = validateModuleName(moduleName, availableModules);
			if (!validation.valid) {
				console.error(`Error: ${validation.error}`);
				process.exit(1);
			}

			MODULES = availableModules;
			const status = getModuleStatus();
			const isEnabled = status[moduleName];
			toggleModule(moduleName, !isEnabled);
			const newStatus = !isEnabled;
			const statusColor = newStatus ? colors.green : colors.red;
			const statusSymbol = newStatus ? '✓' : '✗';
			const statusText = newStatus ? 'Enabled' : 'Disabled';
			console.log(`${statusColor}${statusSymbol}${colors.reset} ${statusText} ${moduleName}`);
			break;
		}

		case 'enable-all': {
			const modules = getAvailableModules();
			modules.forEach(module => toggleModule(module, true));
			console.log(`${colors.green}✓${colors.reset} Enabled all ${modules.length} modules`);
			console.log(`${colors.yellow}⚠️  This may generate significant log output.${colors.reset}`);
			break;
		}

		case 'disable-all': {
			const modules = getAvailableModules();
			const confirmed = await confirmAction(`This will disable all ${modules.length} modules.`);
			if (confirmed) {
				modules.forEach(module => toggleModule(module, false));
				console.log(`${colors.red}✗${colors.reset} Disabled all modules`);
			} else {
				console.log('Operation cancelled.');
			}
			break;
		}

		case 'help':
			showUsage();
			break;

		default:
			console.error(`Error: Unknown command '${command}'`);
			showUsage();
			process.exit(1);
		}
	}
}

main().catch(error => {
	console.error(`Error: ${error.message}`);
	process.exitCode = 1;
});
