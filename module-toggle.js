#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const readline = require('readline');

const PHP_FILE = path.join(__dirname, 'sandbox-wp-debugger.php');

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

function getAvailableModules () {
	const content = readPhpFile();
	const moduleRegex = /^\/?\/?new SWPD\\([a-zA-Z_]+)/gm;
	const modules = [];
	let match;

	while ((match = moduleRegex.exec(content)) !== null) {
		if (!modules.includes(match[1])) {
			modules.push(match[1]);
		}
	}

	return modules.sort();
}

function getModuleStatus () {
	const content = readPhpFile();
	const status = {};

	MODULES.forEach(module => {
		const regex = new RegExp(`^(//)?new SWPD\\\\${module}`, 'm');
		const match = content.match(regex);
		if (match) {
			status[module] = !match[1]; // enabled if no // prefix
		}
	});

	return status;
}

function toggleModule (moduleName, enable) {
	let content = readPhpFile();
	const regex = new RegExp(`^(//)?new SWPD\\\\${moduleName}([^;]*;)`, 'm');

	if (enable) {
		content = content.replace(regex, `new SWPD\\${moduleName}$2`);
	} else {
		content = content.replace(regex, `//new SWPD\\${moduleName}$2`);
	}

	writePhpFile(content);
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
	console.log('  node module-toggle.js enable-all           - Enable all modules');
	console.log('  node module-toggle.js disable-all          - Disable all modules (with confirmation)');
	console.log('');
	console.log('For multiple modules, separate with commas: enable Mod1,Mod2,Mod3');
	console.log('');
	console.log('Available modules:');
	getAvailableModules().forEach(module => console.log(`  ${module}`));
}

function showStatus () {
	const modules = getAvailableModules();
	const status = getModuleStatus();
	const enabledCount = Object.values(status).filter(Boolean).length;
	const totalCount = modules.length;

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
		if (module === 'Slow_Queries') hint = ' (monitors database performance)';
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
	console.log(`${colors.cyan}│ Use ↑↓ arrows to navigate, ENTER to toggle, Q to quit │${colors.reset}`);
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

		console.log(`${highlight}${arrow}${statusColor}${statusChar}${colors.reset} ${module.padEnd(20)} ${statusText}${reset}`);
	});

	console.log('');
	console.log(`${colors.cyan}Press Q to quit${colors.reset}`);
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

main().catch(console.error);
