=== Sandbox WP Debugger ===
Contributors: @david-binda
Tags: debug
Requires at least: 3.7
Tested up to: 5.8
Stable tag: 0.1.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Advanced WordPress debugging tools for your Sandbox

== Description ==

Adds some advanced techniques for tracking bugs to your sandbox

== Installation ==

The plugin needs to be placed in mu-plugins, since it's overriding some WordPress internals and thus needs to be loaded as soon as possible.

After checking out this repository, create a sandbox-wp-debugger.php file in the wp-content/mu-plugins directory and place following to it:

```
<?php require_once( __DIR__ . '/sandbox-wp-debugger/sandbox-wp-debugger.php' );
```

== Changelog ==

= 0.1.0 =
* Inital version
