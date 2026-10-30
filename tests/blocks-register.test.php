<?php
// @require fixtures/blocks-skip-theme.php
// @require fixtures/blocks-boot-early.php

/**
 * Registration, from a theme of the test's own: tests/fixtures/blocks-theme/,
 * booted on `init` at 5 (blocks-boot-early.php) with the base theme skipped.
 * Its blocks folder holds a plain block, one with its own icon and post
 * types, one named otherwise than its folder, a `dev-*` one, one a level too
 * deep, one whose block.json doesn't parse, and one taking a name already
 * taken. Then the default icon, the category, and the Blocks screen's
 * settings applied as a block registers.
 */

use WpGo\Blocks\Core\Config;

use function WpGo\Blocks\Core\add_category;
use function WpGo\Blocks\Core\apply_settings;
use function WpGo\Blocks\Core\block_icon;
use function WpGo\Blocks\Core\default_icon;
use function WpGo\Blocks\Core\registered;

$root     = wp_normalize_path(__DIR__ . '/fixtures/blocks-theme');
$registry = WP_Block_Type_Registry::get_instance();
$boot     = $GLOBALS['wgt_boot'];
$svg      = trim((string) file_get_contents($root . '/icon.svg'));

/**
 * Run $fn with Config's settings changed for its length: the category's
 * cases need settings the fixture file doesn't have, and the rest of the
 * file needs the fixture's back.
 */
function wgt_with_config(array $over, callable $fn): mixed
{
    $property = new ReflectionProperty(Config::class, 'settings');
    $saved    = $property->getValue();

    $property->setValue(null, array_replace($saved, $over));

    try {
        return $fn();
    } finally {
        $property->setValue(null, $saved);
    }
}

section('Booted from its own theme, on init before 10');

same([], $boot['warnings'], 'booted on init at 5, under the 10 the blocks register at: no warning');
same('', $boot['init_saw'], 'the init option runs before the settings are stored: it sees the default category');
same($root, Config::root(), 'the theme is the config file\'s folder, normalised, no trailing slash');
same('blocks', Config::get('blocks'), '"/blocks/" in the file has its slashes trimmed');
same($root . '/blocks', Config::blocks_dir(), 'the blocks folder is absolute, under the theme');
same(['main' => 'app/site.js', 'editor' => 'src/editor.js'], WpGoBlocks::config('entries'), 'entries are merged key by key with the default: the file\'s main, the default editor');
same("a key the package doesn't know", WpGoBlocks::config('shop'), 'a key the package doesn\'t know is kept and readable');
same('wgt-preview-{slug}', WpGoBlocks::config('preview_name'), 'preview_name comes from the file');
same(null, WpGoBlocks::config('category_title'), 'category_title not set is null');

section('The blocks folder registers');

same(
    ['acf/wgt-dev-tool', 'acf/wgt-renamed', 'acf/wgt-own-icon', 'acf/wgt-plain'],
    array_keys(registered()),
    'registered() holds the blocks that registered, in their folders\' order (dev-tool, other-name, own-icon, plain)'
);
same(registered(), WpGoBlocks::registered(), 'the facade\'s registered() is the same store');
same($root . '/blocks/plain', registered()['acf/wgt-plain'] ?? null, 'each block\'s folder, absolute');
same($root . '/blocks/other-name', registered()['acf/wgt-renamed'] ?? null, 'a block named otherwise than its folder registers from its folder');
check('every block registered is in WordPress\'s registry', count(array_filter(array_keys(registered()), fn ($name) => $registry->is_registered($name))) === 4);
check('a block.json deeper than one level isn\'t registered', !$registry->is_registered('acf/wgt-too-deep'));
check('a block.json that doesn\'t parse registers nothing', !$registry->is_registered('acf/wgt-broken') && !isset(registered()['acf/wgt-broken']));
check('and WordPress said why (its notice decoding the file)', (bool) array_filter($boot['notices'], fn ($notice) => str_contains($notice, 'broken/block.json')));
same('WGT Plain', $registry->get_registered('acf/wgt-plain')->title ?? null, 'a second block.json taking a name already taken doesn\'t replace the first');
same($root . '/blocks/plain', registered()['acf/wgt-plain'] ?? null, 'and registered() keeps the first one\'s folder');
check('WordPress said why, with _doing_it_wrong()', (bool) array_filter($boot['wrong'], fn ($message) => str_contains($message, 'acf/wgt-plain')));
same(registered(), registered('acf/wgt-nothing'), 'registered() with a name alone adds nothing');

section('The default icon');

check('the icon file is read, trimmed', block_icon() === $svg && $svg !== '');
same($svg, $registry->get_registered('acf/wgt-plain')->icon ?? null, 'a block of the folder with no icon of its own gets the theme\'s SVG');
same('star-filled', $registry->get_registered('acf/wgt-own-icon')->icon ?? null, 'a block.json that sets its own icon keeps it');
check('a core block (not in the blocks folder) doesn\'t get it', ($registry->get_registered('core/paragraph')->icon ?? null) !== $svg);

$in_folder = ['name' => 'acf/x', 'file' => $root . '/blocks/x/block.json'];
same($svg, default_icon($in_folder)['icon'] ?? null, 'default_icon(): a file in the blocks folder gets the icon');
same($in_folder + ['icon' => 'admin-home'], default_icon($in_folder + ['icon' => 'admin-home']), 'default_icon(): one with an icon is left as it is');
same(['name' => 'acf/x', 'file' => $root . '/blocks-elsewhere/x/block.json'], default_icon(['name' => 'acf/x', 'file' => $root . '/blocks-elsewhere/x/block.json']), 'a folder whose name only starts like the blocks folder\'s isn\'t the blocks folder');
same(['name' => 'acf/x'], default_icon(['name' => 'acf/x']), 'metadata with no file gets no icon');

section('The category');

$core = [
    ['slug' => 'text', 'title' => 'Text', 'icon' => null],
    ['slug' => 'media', 'title' => 'Media', 'icon' => null],
];

$categories = add_category($core);
same(['slug' => 'wgt-blocks', 'title' => 'Wgt-blocks'], $categories[0], 'the theme\'s category goes first, titled with the category capitalised');
same(array_merge([$categories[0]], $core), $categories, 'the others follow, as they were');

same(
    [['slug' => 'text', 'title' => 'Text', 'icon' => null], ['slug' => 'media', 'title' => 'Media', 'icon' => null]],
    wgt_with_config(['category' => 'text'], fn () => array_values(add_category(array_reverse($core)))),
    'a category equal to a core slug is moved first, keeping its title and icon, not added twice'
);
same(
    ['slug' => 'text', 'title' => 'Testo', 'icon' => null],
    wgt_with_config(['category' => 'text', 'category_title' => 'Testo'], fn () => add_category($core)[0]),
    'with category_title, the core category moved first takes that title'
);
same(2, wgt_with_config(['category' => 'text'], fn () => count(add_category($core))), 'and is there once');
same(
    ['slug' => 'wgt-blocks', 'title' => 'Our blocks'],
    wgt_with_config(['category_title' => 'Our blocks'], fn () => add_category($core)[0]),
    'category_title names a new category'
);
same($core, wgt_with_config(['category' => ''], fn () => add_category($core)), 'with no category, the list is left as it is');

$editor_categories = get_block_categories(new WP_Block_Editor_Context(['name' => 'core/edit-post']));
same('wgt-blocks', $editor_categories[0]['slug'] ?? null, 'through block_categories_all, the editor\'s categories start with the theme\'s');
same(1, count(array_filter($editor_categories, fn ($c) => $c['slug'] === 'text')), 'and WordPress\'s own are each there once');

section('The screen\'s settings, applied as a block registers');

update_option('wp_go_blocks_settings', [
    'acf/wgt-plain'    => ['title' => 'Cifre d\'oro', 'post_types' => []],
    'acf/wgt-own-icon' => ['post_types' => ['wgt_event', 'wgt_classic']],
    'acf/wgt-renamed'  => ['title' => '', 'image' => 12],
    'acf/wgt-dev-tool' => ['title' => 'Dev, renamed'],
]);

$plain = ['name' => 'acf/wgt-plain', 'title' => 'WGT Plain', 'acf' => ['postTypes' => ['page']]];
$out   = apply_settings($plain);
same('Cifre d\'oro', $out['title'], 'a stored name replaces block.json\'s title');
same(false, $out['supports']['inserter'] ?? null, 'post types stored as none ticked turn the inserter off');
same(['page'], $out['acf']['postTypes'], 'and leave acf.postTypes alone (empty, ACF would read it as every post type)');

$own = apply_settings(['name' => 'acf/wgt-own-icon', 'title' => 'WGT Own Icon', 'acf' => ['postTypes' => ['page']]]);
same(['wgt_event', 'wgt_classic'], $own['acf']['postTypes'], 'stored post types replace acf.postTypes whole');
same('WGT Own Icon', $own['title'], 'no stored name, block.json\'s title stays');
check('ticked post types leave the inserter on', !isset($own['supports']['inserter']));

$renamed = ['name' => 'acf/wgt-renamed', 'title' => 'WGT Renamed'];
same($renamed, apply_settings($renamed), 'an empty stored name, and an image, change nothing in the metadata');
same(['name' => 'acf/wgt-unknown', 'title' => 'X'], apply_settings(['name' => 'acf/wgt-unknown', 'title' => 'X']), 'a block with nothing stored is left as it is');
same('Dev, renamed', apply_settings(['name' => 'acf/wgt-dev-tool', 'title' => 'WGT Dev Tool'])['title'], 'an entry is applied to whatever block has its name, a dev-* one too (only the save keeps to the listed blocks)');

// Registered again, the block goes through block_type_metadata with the
// option set: what WordPress and ACF end up with
$registry->unregister('acf/wgt-own-icon');
$registry->unregister('acf/wgt-plain');
$own_type   = register_block_type($root . '/blocks/own-icon');
$plain_type = register_block_type($root . '/blocks/plain');

same(['wgt_event', 'wgt_classic'], $own_type->post_types ?? null, 'ACF reads the stored post types as the block\'s post_types');
same('Cifre d\'oro', $plain_type->title ?? null, 'the registered block has the stored name');
same(false, $plain_type->supports['inserter'] ?? null, 'and, with none ticked, no inserter');
same($svg, $plain_type->icon ?? null, 'the default icon still applies alongside');

delete_option('wp_go_blocks_settings');
$registry->unregister('acf/wgt-plain');
same('WGT Plain', register_block_type($root . '/blocks/plain')->title ?? null, 'with nothing stored, block.json\'s title is back');
