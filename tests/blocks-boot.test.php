<?php
// @require fixtures/blocks-skip-theme.php

/**
 * boot() itself, in a request where the base theme hasn't booted the package
 * (blocks-skip-theme.php): the warnings for a config file it can't use, the
 * required renderer and entities (a call that throws leaves the package
 * unbooted), then one boot after `init` from a theme with no blocks folder,
 * which warns twice, and a second call, which does nothing.
 */

use WpGo\Blocks\Core\Config;
use WpGo\Blocks\Entities\TimberEntities;
use WpGo\Blocks\Renderer\TimberRenderer;

use function WpGo\Blocks\boot;
use function WpGo\Blocks\Core\block_icon;
use function WpGo\Blocks\Core\default_icon;
use function WpGo\Blocks\Core\registered;

$fixtures = wp_normalize_path(__DIR__ . '/fixtures');

/**
 * One call to boot(): the warnings it gave, the exception it threw, if any.
 *
 * @return array{warnings:string[],error:?string}
 */
function wgt_boot(string $file, array $options = []): array
{
    $warnings = [];
    $error    = null;

    set_error_handler(static function ($level, $message) use (&$warnings) {
        $warnings[] = $message;

        return true;
    }, E_USER_WARNING);

    try {
        boot($file, $options);
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } finally {
        restore_error_handler();
    }

    return ['warnings' => $warnings, 'error' => $error];
}

$valid  = ['renderer' => new TimberRenderer(), 'entities' => new TimberEntities()];
$inits  = 0;
$init   = static function () use (&$inits) {
    $inits++;
};

section('Before any boot');

same('', Config::root(), 'nothing has set the settings');
same(null, WpGoBlocks::renderer(), 'the facade has no renderer');
same([], registered(), 'no block is registered');
same(false, has_action('init', 'WpGo\\Blocks\\Core\\register_blocks'), 'no hook is added');

section('A config file it can\'t use');

$missing = $fixtures . '/blocks-configs/no-such-file.json';
$result  = wgt_boot($missing, ['init' => $init]);
same(["WpGo\\Blocks\\boot(): can't read $missing, so its settings are the defaults"], $result['warnings'], 'an unreadable file: a warning naming it');
check('and, with no renderer given, the call throws', $result['error'] !== null);

$comment = $fixtures . '/blocks-configs/comment.json';
same(
    ["WpGo\\Blocks\\boot(): $comment is not valid JSON (Syntax error), so its settings are the defaults"],
    wgt_boot($comment)['warnings'],
    'a file with a comment (JSON has none): a warning with the parser\'s error'
);

$scalar = $fixtures . '/blocks-configs/scalar.json';
same(
    ["WpGo\\Blocks\\boot(): $scalar is not valid JSON (not an object), so its settings are the defaults"],
    wgt_boot($scalar)['warnings'],
    'valid JSON that isn\'t an object: a warning saying so'
);

section('The renderer and the entities are required');

$late = $fixtures . '/blocks-configs/late.json';

$result = wgt_boot($late, ['entities' => $valid['entities']]);
check('no renderer: InvalidArgumentException naming `renderer`', str_contains((string) $result['error'], '`renderer` is required'));
same([], $result['warnings'], 'a readable file gives no warning');

$result = wgt_boot($late, ['renderer' => 'twig', 'entities' => $valid['entities']]);
check('a renderer that isn\'t a Renderer: the same', str_contains((string) $result['error'], '`renderer` is required'));

$result = wgt_boot($late, ['renderer' => $valid['renderer']]);
check('no entities: InvalidArgumentException naming `entities`', str_contains((string) $result['error'], '`entities` is required'));

$result = wgt_boot($late, ['renderer' => $valid['renderer'], 'entities' => new stdClass()]);
check('entities that aren\'t an Entities: the same', str_contains((string) $result['error'], '`entities` is required'));

same(0, $inits, 'a call that throws doesn\'t run `init`');
same('', Config::root(), 'and stores no settings: the package is still unbooted');
same(false, has_action('init', 'WpGo\\Blocks\\Core\\register_blocks'), 'and adds no hook');

section('Booted after init, from a theme with no blocks folder');

$empty  = $fixtures . '/blocks-theme-empty';
$result = wgt_boot($empty . '/wp-go-blocks.json', $valid + ['init' => $init]);

same(null, $result['error'], 'a correct call after failed ones boots');
same(
    [
        "WpGo\\Blocks\\boot(): there is no blocks folder at $empty/no-such-folder, so no block registers",
        'WpGo\\Blocks\\boot(): called too late: the blocks register on `init` (priority 10), which has run, so none registers. Call it as the theme loads, from functions.php',
    ],
    $result['warnings'],
    'two warnings: no blocks folder, then called too late for init'
);
same(1, $inits, '`init` ran once');
same($empty, Config::root(), 'the settings are stored, the theme being the file\'s folder');
same('wgt-empty', WpGoBlocks::config('category'), 'with the file\'s keys');
same('block-preview-{slug}', WpGoBlocks::config('preview_name'), 'and the defaults for the rest, preview_name\'s among them');
check('the renderer and the entities are the ones given', WpGoBlocks::renderer() === $valid['renderer'] && WpGoBlocks::entities() === $valid['entities']);
same([], registered(), 'booted too late, no block registers');

foreach ([
    ['init', 'register_blocks'],
    ['block_type_metadata', 'default_icon'],
    ['block_type_metadata', 'apply_settings'],
    ['block_categories_all', 'add_category'],
    ['admin_menu', 'add_settings_page'],
    ['admin_enqueue_scripts', 'enqueue_settings_screen'],
    ['admin_post_wp_go_blocks_settings_save', 'save_settings'],
    ['wp_handle_upload_prefilter', 'rename_preview_upload'],
    ['add_attachment', 'mark_preview_upload'],
    ['ajax_query_attachments_args', 'only_previews'],
    ['enqueue_block_editor_assets', 'print_preview_images'],
] as [$hook, $function]) {
    same(10, has_filter($hook, 'WpGo\\Blocks\\Core\\' . $function), "$function() is on $hook at 10");
}
$metadata = array_keys($GLOBALS['wp_filter']['block_type_metadata']->callbacks[10] ?? []);
check('default_icon() runs before apply_settings()', array_search('WpGo\\Blocks\\Core\\default_icon', $metadata, true) < array_search('WpGo\\Blocks\\Core\\apply_settings', $metadata, true));
same(false, has_action('rest_api_init', ['WpGo\\Blocks\\Core\\ContentApi', 'register']), 'content_api off by default: no REST routes');
same(false, has_action('wp_head', 'WpGo\\Blocks\\Core\\print_scripts'), 'no assets resolver: no head hooks');
same(false, has_action('template_redirect', 'WpGo\\Blocks\\Core\\start_buffer'), 'and no buffer');

section('No blocks folder, no default icon');

// The fixture's icon is readable: what keeps it off every block is the
// missing folder (realpath() false), not a missing icon
check('the icon itself is there', block_icon() !== '');
same(
    ['name' => 'core/paragraph', 'file' => '/anywhere/core/paragraph/block.json'],
    default_icon(['name' => 'core/paragraph', 'file' => '/anywhere/core/paragraph/block.json']),
    'with no blocks folder, default_icon() gives no block the theme\'s icon'
);
same(
    ['name' => 'acf/x', 'file' => $empty . '/no-such-folder/x/block.json'],
    default_icon(['name' => 'acf/x', 'file' => $empty . '/no-such-folder/x/block.json']),
    'not even one whose file claims to be in the missing folder'
);

section('Booted again');

$result = wgt_boot($fixtures . '/blocks-theme/wp-go-blocks.json', $valid + ['init' => $init]);
same(['warnings' => [], 'error' => null], $result, 'a second call warns of nothing and throws nothing');
same($empty, Config::root(), 'and changes no setting');
same(1, $inits, 'and doesn\'t run `init` again');
same(['warnings' => [], 'error' => null], wgt_boot($missing), 'even a call that would have thrown and warned');
