<?php

/**
 * No theme for this request: the base theme's functions.php isn't loaded, so
 * nothing has booted wp-go-blocks when the test runs, and the test boots it
 * itself (its warnings, its order against `init`, a fixture theme of its
 * own). What `wp --skip-themes` does, which run.php can't pass: the active
 * theme read as none while WordPress loads it, put back once it has
 * (after_setup_theme), so the rest of the request sees the site as it is.
 */

$wgt_no_theme = static fn () => '';
$wgt_hooks    = ['pre_option_template', 'option_template', 'pre_option_stylesheet', 'option_stylesheet'];

WP_CLI::add_wp_hook('setup_theme', static function () use ($wgt_no_theme, $wgt_hooks) {
    foreach ($wgt_hooks as $hook) {
        add_filter($hook, $wgt_no_theme, 999);
    }
    // WordPress memoises the theme's folders (6.4+)
    $GLOBALS['wp_stylesheet_path'] = null;
    $GLOBALS['wp_template_path']   = null;
}, 999);

WP_CLI::add_wp_hook('after_setup_theme', static function () use ($wgt_no_theme, $wgt_hooks) {
    foreach ($wgt_hooks as $hook) {
        remove_filter($hook, $wgt_no_theme, 999);
    }
    $GLOBALS['wp_stylesheet_path'] = null;
    $GLOBALS['wp_template_path']   = null;
}, 0);
