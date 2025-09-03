# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Sandbox WP Debugger is a WordPress mu-plugin that provides advanced debugging techniques for WordPress development environments. It offers granular debugging tools for wp_redirect issues, WP CLI error backtracking, filter/action hook analysis, and various performance monitoring features.

## Key Commands

### PHP Code Standards
```bash
# Install dependencies (VIP Coding Standards, PHP_CodeSniffer)
composer install

# Fix PHP 8.4 compatibility issues in PHPCS vendor files (run after composer install)
bin/fix-phpcs-php84.sh

# Run PHP CodeSniffer (linting) - uses WordPress-VIP-Go standards
composer lint
# OR: vendor/bin/phpcs

# Auto-fix PHP CodeSniffer issues where possible
composer lint:fix
# OR: vendor/bin/phpcbf
```

### PHP 8.4 Compatibility
PHP 8.4 introduces stricter type checking that causes PHPCS vendor files to fail. The `bin/fix-phpcs-php84.sh` script patches these issues:

- **Issue**: `trim()` no longer accepts null values
- **Files patched**: 
  - `vendor/wp-coding-standards/wpcs/WordPress/Sniffs/NamingConventions/PrefixAllGlobalsSniff.php`
  - `vendor/wp-coding-standards/wpcs/WordPress/Sniffs/WP/I18nSniff.php`
  - `vendor/wp-coding-standards/wpcs/WordPress/PHPCSHelper.php`
- **Solution**: Adds null coalescing operators and explicit nullable type declarations

Run the fix script after every `composer install` or `composer update`.

### Development Setup
This plugin must be installed as a WordPress mu-plugin (must-use plugin) to function properly, as it overrides WordPress core functions and needs to load early in the WordPress bootstrap process.

## Architecture

### Core Structure
- `sandbox-wp-debugger.php` - Main plugin file that loads all components
- `class-base.php` - Base class providing common functionality (logging, ASCII tables, text formatting)
- `helper-functions.php` - Global helper functions (`swpd_log`, `swpd_apply_filter_debug`, `swpd_do_action_debug`)
- `modules/` - Directory containing individual debugging modules (classes)

### Module System
The plugin uses a modular architecture where each debugging feature is implemented as a separate class in the `modules/` directory:

- `class-wp-redirect.php` - Debug wp_redirect() calls with detailed filter analysis
- `class-slow-queries.php` - Monitor and log slow database queries with timing
- `class-apply-filters.php` - Track how filter callbacks modify values
- `class-do-action.php` - Debug action hooks with custom callback execution
- `class-memcache.php` - Memcache debugging and statistics
- `class-slow-hooks.php` - Performance monitoring for slow WordPress hooks
- `class-rest-requests.php` - Debug WordPress REST API requests
- `class-es-queries.php` - Elasticsearch query monitoring
- `class-remote-requests.php` - Track external HTTP requests
- `class-timers.php` - General-purpose timing utilities
- And more specialized debugging modules

### Key Design Patterns

#### Namespace Structure
All classes use the `SWPD\` namespace to avoid conflicts.

#### Base Class Integration
All debugging modules extend or utilize the `SWPD\Base` class which provides:
- Singleton pattern implementation
- Standardized logging via `swpd_log()`
- ASCII table formatting for readable debug output
- Unicode-safe string padding utilities

#### Activation System
Debugging modules are commented out by default in `sandbox-wp-debugger.php` to prevent log noise. Enable specific modules by uncommenting their instantiation:
```php
// Uncomment to enable specific debugging tools
// new SWPD\WP_Redirect();
// new SWPD\Slow_Queries( array( 'debug' => true ) );
```

#### Helper Functions API
Global functions provide easy access to debugging features:
- `swpd_log()` - Core logging function with backtrace support
- `swpd_apply_filter_debug()` - Register filter debugging
- `swpd_do_action_debug()` - Register action debugging with custom callbacks

### PHP Standards Configuration
Uses custom PHPCS ruleset (`.phpcs.xml.dist`) with:
- WordPress-VIP-Go coding standards
- PHP 8.0+ compatibility checking via PHPCompatibilityWP
- WordPress-Extra and WordPress-Docs standards
- Custom text domain validation for 'sandbox-wp-debugger'
- SWPD prefix enforcement for global functions/variables
- Minimum WordPress version 6.1.1 support

## Development Notes

- All debugging output goes to PHP error logs via `error_log()`
- Modules can be selectively enabled to reduce log noise
- The plugin detects WordPress multisite environments and includes blog ID in debug output
- Designed specifically for development/staging environments, not production use
- Uses modern PHP features including typed parameters, return types, and named arguments