<?php

/**
 * wp-go-blocks booted from tests/fixtures/blocks-theme/ on `init` at 10, the
 * priority its blocks register at (with blocks-skip-theme.php, so the base
 * theme hasn't booted it first): too late, and boot() says so. Its warnings
 * are kept for the test to read.
 */

$GLOBALS['wgt_boot'] = ['warnings' => []];

WP_CLI::add_wp_hook('init', static function () {
    set_error_handler(static function ($level, $message) {
        $GLOBALS['wgt_boot']['warnings'][] = $message;

        return true;
    }, E_USER_WARNING);

    try {
        \WpGo\Blocks\boot(__DIR__ . '/blocks-theme/wp-go-blocks.json', [
            'renderer' => new \WpGo\Blocks\Renderer\TimberRenderer(),
            'entities' => new \WpGo\Blocks\Entities\TimberEntities(),
        ]);
    } finally {
        restore_error_handler();
    }
}, 10);
