<?php

/**
 * wp-go-blocks booted from tests/fixtures/blocks-theme/ (with
 * blocks-skip-theme.php, so the base theme doesn't boot it first), on `init`
 * at 5: under the 10 the blocks register at, which the docs say registers
 * them with no warning. What boot() said, and what WordPress said about the
 * blocks that didn't register, are kept for the test to read.
 */

$GLOBALS['wgt_boot'] = ['warnings' => [], 'wrong' => [], 'notices' => [], 'init_saw' => null];

// A block.json WordPress refuses (a name taken) is a _doing_it_wrong():
// recorded, rather than printed among the checks
WP_CLI::add_wp_hook('doing_it_wrong_trigger_error', static fn ($trigger, $function) => str_starts_with((string) $function, 'WP_Block_Type_Registry') || $function === 'register_block_type_from_metadata' ? false : $trigger, 10, 2);
WP_CLI::add_wp_hook('doing_it_wrong_run', static function ($function, $message) {
    $GLOBALS['wgt_boot']['wrong'][] = $function . ': ' . $message;
}, 10, 2);

WP_CLI::add_wp_hook('init', static function () {
    set_error_handler(static function ($level, $message) {
        $GLOBALS['wgt_boot']['warnings'][] = $message;

        return true;
    }, E_USER_WARNING);

    try {
        \WpGo\Blocks\boot(__DIR__ . '/blocks-theme/wp-go-blocks.json', [
            'renderer' => new \WpGo\Blocks\Renderer\TimberRenderer(),
            'entities' => new \WpGo\Blocks\Entities\TimberEntities(),
            // runs before the settings are stored: it sees the defaults
            'init'     => static function () {
                \Timber\Timber::init();
                $GLOBALS['wgt_boot']['init_saw'] = \WpGo\Blocks\Core\Config::get('category');
            },
        ]);
    } finally {
        restore_error_handler();
    }
}, 5);

// The blocks register on `init` at 10: what WordPress says about a block.json
// it can't decode (a notice) is kept too, from just before to just after
WP_CLI::add_wp_hook('init', static function () {
    set_error_handler(static function ($level, $message) {
        $GLOBALS['wgt_boot']['notices'][] = $message;

        return true;
    }, E_USER_NOTICE | E_USER_WARNING);
}, 9);
WP_CLI::add_wp_hook('init', static fn () => restore_error_handler(), 11);
