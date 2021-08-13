# Sandbox WP Debugger

A mu-plugin which is filtering / overriding WordPress functions in order to add some extra debugging information to it.

It can be used for quickly debugging following issues:

* wp_redirect issues
* WP CLI error backtracking
* apply_filters

## Installation

The plugin needs to be placed in mu-plugins, since it's overriding some WordPress internals and thus needs to be loaded as soon as possible.

After checking out this repository, create a `sandbox-wp-debugger.php` file in the wp-content/mu-plugins directory and place following to it:

```
<?php require_once( __DIR__ . '/sandbox-wp-debugger/sandbox-wp-debugger.php' );
```

## Example output

### wp-redirect

```
[14-Mar-2017 14:44:00 UTC] == Sandbox WP Debug : wp_redirect() debug ==
[14-Mar-2017 14:44:00 UTC] Location used for redirection: 'https://example.org/wp-admin/post.php?post=1234&action=edit&message=10'
[14-Mar-2017 14:44:00 UTC] Status used for redirection: 302
[14-Mar-2017 14:44:00 UTC] === Aditional debug data: ===
[14-Mar-2017 14:44:00 UTC] Location before applying `wp_redirect` filters: 'https://example.org/wp-admin/post.php?post=1234&action=edit&message=10'
[14-Mar-2017 14:44:00 UTC] Location after applying `wp_redirect` filters: 'https://example.org/wp-admin/post.php?post=1234&action=edit&message=10'
[14-Mar-2017 14:44:00 UTC] Status before applying `wp_redirect_status` filters: 302
[14-Mar-2017 14:44:00 UTC] Blog ID: 15797879
[14-Mar-2017 14:44:00 UTC] Backtrace: redirect_post, wp_redirect
[14-Mar-2017 14:44:00 UTC] == / wp_redirect() ==
```

### WP CLI's error:

```
Error: Example error message!
Backtrace: include('bin/wp-cli/php/wp-cli.php'), WP_CLI\Runner->after_wp_load, WP_CLI\Runner->_run_command, WP_CLI\Runner->run_command, WP_CLI\Dispatcher\Subcommand->invoke, call_user_func, WP_CLI\Dispatcher\CommandFactory::WP_CLI\Dispatcher\{closure}, call_user_func, Some_Command->find, WP_CLI::error, SWPD_WPCOM_WP_CLI_Logger->error, SWPD_WPCOM_WP_CLI_Logger->_line
```

### apply_filters

#### usage

```
wp shell --url=example.org
wp> swpd_apply_filter_debug( 'set_url_scheme', false );
wp> home_url();
```

By setting the second param of `swpd_apply_filter_debug` functin to `true` the output will contain only the filters which changed the value.

#### log output

```
[24-Mar-2017 12:33:50 UTC] Initial value: 'https://example.org'
[24-Mar-2017 12:33:50 UTC] array (
  'value' => '\'http://example.org\'',
  'idx' => '\'some_url_filter\'',
  'the_' => 'array (
  \'function\' => \'some_url_filter\',
  \'accepted_args\' => 3,
)',
  'priority' => 10,
)
[24-Mar-2017 12:33:50 UTC] array (
  'value' => '\'http://example.org\'',
  'idx' => '\'00000000090129780000000057b3cd97\'',
  'the_' => 'array (
  \'function\' =>
  Closure::__set_state(array(
  )),
  \'accepted_args\' => 2,
)',
  'priority' => 10,
)
```

### do_action

A custom callback can be registered to run after each already registered callback to a hook, so the state of global variables and data can be examined.

#### usage

```
wp shell --url=example.org
wp> swpd_do_action_debug( 'widgets_init', function( $value, $idx, $the_, $priority ) { global $wp_widget_factory; if ( empty( $wp_widget_factory->widgets ) ) { var_dump( $the_ ); die; } } );
wp> wp_widgets_init();
```
