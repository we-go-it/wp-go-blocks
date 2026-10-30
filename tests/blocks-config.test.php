<?php

/**
 * Config, the settings boot() resolved, set directly here with the theme's
 * own put back after each case (Config::set() is boot()'s, and this request's
 * blocks were booted from the base theme); and the base theme's boot as it
 * stands: its settings, its blocks, and boot() called a second time.
 */

use WpGo\Blocks\Core\Config;
use WpGo\Blocks\Entities\TimberEntities;
use WpGo\Blocks\Entities\WordPressEntities;
use WpGo\Blocks\Renderer\PayloadRenderer;
use WpGo\Blocks\Renderer\TimberRenderer;

use function WpGo\Blocks\boot;
use function WpGo\Blocks\Core\listed_blocks;
use function WpGo\Blocks\Core\registered;

$theme = wp_normalize_path(get_template_directory());
$json  = json_decode((string) file_get_contents($theme . '/wp-go-blocks.json'), true);

/**
 * Run $fn with Config set to $settings under $root, then the theme's
 * settings back, whatever $fn does.
 */
function wgt_config_set(string $root, array $settings, callable $fn): mixed
{
    $settings_property = new ReflectionProperty(Config::class, 'settings');
    $root_property     = new ReflectionProperty(Config::class, 'root');
    $saved             = [$settings_property->getValue(), $root_property->getValue()];

    Config::set($root, $settings);

    try {
        return $fn();
    } finally {
        $settings_property->setValue(null, $saved[0]);
        $root_property->setValue(null, $saved[1]);
    }
}

section('The defaults');

$defaults = wgt_config_set('/srv/theme', [], fn () => array_map(fn ($key) => Config::get($key), array_combine(
    $keys = ['category', 'blocks', 'entries', 'bundles', 'icon', 'category_title', 'preview_name', 'assets', 'renderer', 'entities', 'init', 'content_api', 'head_priorities', 'buffer'],
    $keys
)));
same([
    'category'        => '',
    'blocks'          => 'blocks',
    'entries'         => ['main' => 'src/main.js', 'editor' => 'src/editor.js'],
    'bundles'         => [],
    'icon'            => null,
    'category_title'  => null,
    'preview_name'    => 'block-preview-{slug}',
    'assets'          => null,
    'renderer'        => null,
    'entities'        => null,
    'init'            => null,
    'content_api'     => false,
    'head_priorities' => ['scripts' => 15, 'styles' => 25],
    'buffer'          => true,
], $defaults, 'nothing set, every key has its default (preview_name block-preview-{slug} among them)');

section('Config::set()');

wgt_config_set('/srv/theme/', ['blocks' => '/parts/blocks/', 'entries' => ['main' => 'app.js'], 'shop' => 42, 'preview_name' => 'p-{slug}'], function () {
    same('/srv/theme', Config::root(), 'the root loses its trailing slash');
    same('parts/blocks', Config::get('blocks'), 'blocks has its slashes trimmed');
    same('/srv/theme/parts/blocks', Config::blocks_dir(), 'the blocks folder is the root and blocks');
    same(['main' => 'app.js', 'editor' => 'src/editor.js'], Config::get('entries'), 'entries merged key by key: the given main, the default editor');
    same(42, Config::get('shop'), 'a key the package doesn\'t know is kept');
    same(null, Config::get('nope'), 'a key nobody has is null');
    same('p-{slug}', Config::get('preview_name'), 'a given preview_name replaces the default');
});

same('C:/srv/theme', wgt_config_set('C:\\srv\\theme\\', [], fn () => Config::root()), 'the root is normalised (backslashes) as well as trimmed');
same(
    ['scripts' => 5],
    wgt_config_set('/srv/theme', ['head_priorities' => ['scripts' => 5]], fn () => Config::get('head_priorities')),
    'a key other than entries is replaced whole, not merged (boot() reads the missing priority\'s default itself)'
);

section('The objects');

wgt_config_set('/srv/theme', ['renderer' => 'twig', 'entities' => new stdClass(), 'assets' => 'vite'], function () {
    same(null, Config::renderer(), 'a renderer that isn\'t a Renderer reads as none');
    same(null, Config::entities(), 'entities that aren\'t an Entities read as none');
    same(null, Config::assets(), 'an assets that isn\'t an AssetResolver reads as none');
});

$timber  = new TimberRenderer();
$payload = new PayloadRenderer();
wgt_config_set('/srv/theme', ['renderer' => $timber, 'entities' => $entities = new WordPressEntities()], function () use ($timber, $payload, $entities) {
    same($timber, Config::renderer(), 'the renderer given is the renderer');
    same($entities, Config::entities(), 'the entities given are the entities');
    same($timber, Config::use_renderer($payload), 'use_renderer() returns the one it replaces');
    same($payload, WpGoBlocks::renderer(), 'and the facade renders with the new one');
    same($payload, Config::use_renderer(null), 'swapped for none, the previous comes back');
    same(null, Config::renderer(), 'and there is no renderer');
});

section('The base theme\'s boot');

same($theme, Config::root(), 'the theme is the folder wp-go-blocks.json is in');
same($json['category'], WpGoBlocks::config('category'), 'the file\'s category');
same(array_replace(['main' => 'src/main.js', 'editor' => 'src/editor.js'], $json['entries'] ?? []), WpGoBlocks::config('entries'), 'the file\'s entries, merged');
check('the renderer and entities are the ones functions.php gives', Config::renderer() instanceof TimberRenderer && Config::entities() instanceof TimberEntities);
same($theme . '/' . trim($json['blocks'] ?? 'blocks', '/'), Config::blocks_dir(), 'the blocks folder');

$registered = registered();
check('registered() lists the theme\'s blocks', count($registered) > 0);
check('each from a folder of the blocks folder with a block.json', count(array_filter($registered, fn ($dir) => dirname(wp_normalize_path($dir)) === Config::blocks_dir() && is_file($dir . '/block.json'))) === count($registered));
check('each in WordPress\'s registry', count(array_filter(array_keys($registered), fn ($name) => WP_Block_Type_Registry::get_instance()->is_registered($name))) === count($registered));
same(
    count(glob(Config::blocks_dir() . '/*/block.json')),
    count($registered),
    'every block.json of the folder registered'
);
same(
    array_keys(array_filter($registered, fn ($dir) => !str_starts_with(basename($dir), 'dev-'))),
    array_keys(listed_blocks()),
    'the Blocks screen lists them all but the dev-* folders'
);

section('boot() a second time');

$warnings = [];
set_error_handler(function ($level, $message) use (&$warnings) {
    $warnings[] = $message;

    return true;
});
$thrown = null;
try {
    boot('/no/such/file.json', []);
} catch (Throwable $e) {
    $thrown = $e;
} finally {
    restore_error_handler();
}
same([], $warnings, 'called again with a file that doesn\'t exist: no warning');
same(null, $thrown, 'and, with no renderer, no exception: it returns at once');
same($theme, Config::root(), 'the settings are the first boot\'s');
