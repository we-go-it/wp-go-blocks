<?php
// @require fixtures/blocks-skip-theme.php
// @require fixtures/blocks-boot-early.php

/**
 * The Blocks screen's model, on the fixture theme's blocks
 * (tests/fixtures/blocks-theme/, booted by blocks-boot-early.php): the post
 * types it offers, what it shows for each block, the dialog's save, and the
 * screen's markup.
 *
 * The save is called in-process as admin-post.php would: $_POST and $_REQUEST
 * set, slashed as WordPress has them, its redirect and its wp_die() turned
 * into exceptions so the test reads where it went and goes on.
 *
 * The post types are the test's own, registered before anything asks
 * editor_post_types() (it keeps its answer for the request): one edited in
 * the block editor, one in the classic editor, one hidden from the admin, and
 * one named like WordPress's own.
 */

use function WpGo\Blocks\Core\block_json;
use function WpGo\Blocks\Core\block_post_types;
use function WpGo\Blocks\Core\block_settings;
use function WpGo\Blocks\Core\editor_post_types;
use function WpGo\Blocks\Core\json_post_types;
use function WpGo\Blocks\Core\json_title;
use function WpGo\Blocks\Core\listed_blocks;
use function WpGo\Blocks\Core\preview_image;
use function WpGo\Blocks\Core\save_settings;
use function WpGo\Blocks\Core\settings_screen;

use const WpGo\Blocks\Core\PREVIEW_META;
use const WpGo\Blocks\Core\SETTINGS_OPTION;

$root = wp_normalize_path(__DIR__ . '/fixtures/blocks-theme');

foreach ([
    'wgt_event'   => ['show_ui' => true, 'show_in_rest' => true],
    'wgt_classic' => ['show_ui' => true, 'show_in_rest' => true],
    'wgt_hidden'  => ['show_ui' => false, 'show_in_rest' => true],
    'wp_wgt'      => ['show_ui' => true, 'show_in_rest' => true],
] as $type => $args) {
    register_post_type($type, $args + ['label' => strtoupper($type), 'public' => true, 'supports' => ['title', 'editor']]);
}
add_filter('use_block_editor_for_post_type', fn ($use, $type) => $type === 'wgt_classic' ? false : $use, 10, 2);

class WgtRedirect extends Exception
{
}

class WgtDie extends Exception
{
}

// the save's way out: a redirect, or wp_die() with its status
add_filter('wp_redirect', function ($location) {
    throw new WgtRedirect($location);
}, 1);
add_filter('wp_die_handler', fn () => function ($message, $title, $args) {
    throw new WgtDie((string) ($args['response'] ?? ''));
}, PHP_INT_MAX);

/**
 * The dialog's form posted: where save_settings() sent the browser
 * ('updated', 'rejected'), or the status it died with ('403', '400'…).
 */
function wgt_save(array $post, bool $nonce = true): string
{
    $post += ['action' => 'wp_go_blocks_settings_save'];
    if ($nonce) {
        $post['_wpnonce'] = wp_create_nonce('wp_go_blocks_settings_save');
    }

    // as WordPress has them: slashed
    $_POST    = wp_slash($post);
    $_REQUEST = $_POST;

    try {
        save_settings();
    } catch (WgtRedirect $redirect) {
        parse_str((string) parse_url($redirect->getMessage(), PHP_URL_QUERY), $query);

        return ($query['page'] ?? '') === 'wp-go-blocks' ? (isset($query['updated']) ? 'updated' : (isset($query['rejected']) ? 'rejected' : 'elsewhere')) : 'elsewhere';
    } catch (WgtDie $die) {
        return 'die ' . $die->getMessage();
    } finally {
        $_POST = $_REQUEST = [];
    }

    return 'nothing';
}

/**
 * An attachment, as the media library has one; $marked: uploaded from the
 * dialog (the preview meta). A `large` size in its metadata, so
 * preview_image() finds it.
 */
function wgt_attachment(string $mime, bool $marked = true): int
{
    $extension = explode('/', $mime)[1] === 'svg+xml' ? 'svg' : explode('/', $mime)[1];
    $id        = wp_insert_attachment(['post_mime_type' => $mime, 'post_title' => 'wgt', 'post_status' => 'inherit'], "wgt/preview.$extension");

    wp_update_attachment_metadata($id, [
        'width'  => 1600,
        'height' => 900,
        'file'   => "wgt/preview.$extension",
        'sizes'  => ['large' => ['file' => "preview-1024x576.$extension", 'width' => 1024, 'height' => 576, 'mime-type' => $mime]],
    ]);
    if ($marked) {
        update_post_meta($id, PREVIEW_META, 'acf/wgt-plain');
    }

    return $id;
}

section('The post types offered');

$offered = editor_post_types();
same(['post', 'page', 'wgt_event'], array_keys($offered), 'those edited in the block editor: post, page, and the test\'s own');
same('WGT_EVENT', $offered['wgt_event'], 'by their label');
check('not attachment, nor WordPress\'s own wp_* (patterns, navigation), nor one named wp_ by its plugin', !array_intersect(['attachment', 'wp_block', 'wp_navigation', 'wp_wgt'], array_keys($offered)));
check('not one edited in the classic editor, nor one without an admin screen', !isset($offered['wgt_classic']) && !isset($offered['wgt_hidden']));

section('What the screen shows for a block');

same(['acf/wgt-renamed', 'acf/wgt-own-icon', 'acf/wgt-plain'], array_keys(listed_blocks()), 'the screen lists every registered block but the dev-* folders');
same('WGT Own Icon', json_title($root . '/blocks/own-icon'), 'json_title(): the title in the folder\'s block.json');
same('', json_title($root . '/blocks/no-such-block'), 'and none for a folder without one');
same([], block_json($root . '/blocks/broken'), 'block_json() of a block.json that doesn\'t parse is empty');
same(['page'], json_post_types($root . '/blocks/own-icon'), 'json_post_types(): block.json\'s acf.postTypes among the offered (wgt_classic isn\'t offered)');
same(['post', 'page', 'wgt_event'], json_post_types($root . '/blocks/plain'), 'a block.json naming none allows every offered type');

update_option(SETTINGS_OPTION, ['acf/wgt-own-icon' => ['post_types' => ['wgt_event', 'wgt_classic', 'gone']]]);
same(['wgt_event'], block_post_types('acf/wgt-own-icon', $root . '/blocks/own-icon'), 'block_post_types(): the stored ones among the offered, what else is stored not shown');
same(['post', 'page', 'wgt_event'], block_post_types('acf/wgt-plain', $root . '/blocks/plain'), 'nothing stored: block.json\'s');
update_option(SETTINGS_OPTION, ['acf/wgt-own-icon' => ['post_types' => []]]);
same([], block_post_types('acf/wgt-own-icon', $root . '/blocks/own-icon'), 'stored as none: none');
same(['post_types' => []], block_settings('acf/wgt-own-icon'), 'block_settings() gives one block\'s');
same([], block_settings('acf/wgt-plain'), 'and nothing for a block with nothing stored');

$png     = wgt_attachment('image/png');
$deleted = wgt_attachment('image/png');
wp_delete_attachment($deleted, true);
update_option(SETTINGS_OPTION, ['acf/wgt-plain' => ['image' => $png], 'acf/wgt-renamed' => ['image' => $deleted]]);
$image = preview_image('acf/wgt-plain');
check('preview_image(): the stored image\'s large size', str_ends_with((string) ($image['src'] ?? ''), '/wgt/preview-1024x576.png'));
same('1024/576', $image['ratio'] ?? null, 'and its ratio, width/height, for CSS');
same(null, preview_image('acf/wgt-renamed'), 'an image deleted since is none');
same(null, preview_image('acf/wgt-own-icon'), 'a block with no image has none');

section('The save: who and which block');

delete_option(SETTINGS_OPTION);

$editor = wp_insert_user(['user_login' => 'wgt-editor', 'user_pass' => wp_generate_password(), 'role' => 'editor']);
$admin  = get_current_user_id();
wp_set_current_user($editor);
same('die 403', wgt_save(['block' => 'acf/wgt-plain', 'title' => 'X']), 'without edit_theme_options: 403');
wp_set_current_user($admin);

same('die 403', wgt_save(['block' => 'acf/wgt-plain', 'title' => 'X'], false), 'without the nonce: refused (403)');
same('die 400', wgt_save(['block' => 'acf/wgt-dev-tool', 'title' => 'X']), 'a dev-* block, registered but not listed: 400');
same('die 400', wgt_save(['block' => 'acf/wgt-nothing', 'title' => 'X']), 'a block that isn\'t registered: 400');
same('die 400', wgt_save(['title' => 'X']), 'no block: 400');
same(false, get_option(SETTINGS_OPTION), 'none of these saved anything');

section('The save: the name');

same('updated', wgt_save(['block' => 'acf/wgt-plain', 'title' => 'Cifre d\'oro <b>!</b>', 'post_types' => ['post', 'page', 'wgt_event']]), 'a save goes back to the screen with updated=1');
same(['acf/wgt-plain' => ['title' => 'Cifre d\'oro !']], get_option(SETTINGS_OPTION), 'the name stored, unslashed and sanitised; the post types as block.json\'s, not stored');
check('the option is autoloaded', in_array($GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare("SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", SETTINGS_OPTION)), ['yes', 'on', 'auto-on'], true));

wgt_save(['block' => 'acf/wgt-renamed', 'title' => 'Altro', 'post_types' => ['post', 'page', 'wgt_event']]);
wgt_save(['block' => 'acf/wgt-plain', 'title' => 'WGT Plain', 'post_types' => ['post', 'page', 'wgt_event']]);
same(['acf/wgt-renamed' => ['title' => 'Altro']], get_option(SETTINGS_OPTION), 'a name equal to block.json\'s isn\'t stored: the block, left with nothing, is dropped; the other block\'s entry kept');
wgt_save(['block' => 'acf/wgt-renamed', 'title' => '', 'post_types' => ['post', 'page', 'wgt_event']]);
same([], get_option(SETTINGS_OPTION), 'an empty name isn\'t stored either');

section('The save: the post types');

$saved = fn (string $name) => get_option(SETTINGS_OPTION)[ $name ] ?? null;

wgt_save(['block' => 'acf/wgt-own-icon', 'title' => '', 'post_types' => ['page']]);
same(null, $saved('acf/wgt-own-icon'), 'ticked as block.json allows (page, among the offered): nothing stored');

wgt_save(['block' => 'acf/wgt-own-icon', 'title' => '', 'post_types' => ['wgt_event']]);
same(['post_types' => ['wgt_event', 'wgt_classic']], $saved('acf/wgt-own-icon'), 'ticked otherwise: the ticks, and block.json\'s type the screen doesn\'t offer (wgt_classic) kept');

wgt_save(['block' => 'acf/wgt-own-icon', 'title' => '', 'post_types' => ['wgt_event', 'page']]);
same(['post_types' => ['page', 'wgt_event', 'wgt_classic']], $saved('acf/wgt-own-icon'), 'the ticks in the offered order, whatever the posted one');

wgt_save(['block' => 'acf/wgt-own-icon', 'title' => '']);
same(['post_types' => []], $saved('acf/wgt-own-icon'), 'none ticked: stored as none, the unoffered type included (no inserter)');

wgt_save(['block' => 'acf/wgt-plain', 'title' => '', 'post_types' => ['page', 'wp_block', 'wgt_classic', 'nope']]);
same(['post_types' => ['page']], $saved('acf/wgt-plain'), 'only offered types are taken from the post: the rest posted are dropped');

wgt_save(['block' => 'acf/wgt-plain', 'title' => '', 'post_types' => ['wp_block', 'nope']]);
same(['post_types' => []], $saved('acf/wgt-plain'), 'none of the offered ticked (only unknown ones posted) is none');

section('The save: the image');

delete_option(SETTINGS_OPTION);
$unmarked = wgt_attachment('image/png', false);
$svg      = wgt_attachment('image/svg+xml');
$pdf      = wgt_attachment('application/pdf');
$jpeg     = wgt_attachment('image/jpeg');
$webp     = wgt_attachment('image/webp');

same('updated', wgt_save(['block' => 'acf/wgt-plain', 'title' => 'Con immagine', 'image' => (string) $png, 'post_types' => ['post', 'page', 'wgt_event']]), 'a raster preview: saved');
same(['title' => 'Con immagine', 'image' => $png], $saved('acf/wgt-plain'), 'its ID stored, as an integer');
wgt_save(['block' => 'acf/wgt-renamed', 'title' => '', 'image' => $jpeg, 'post_types' => ['post', 'page', 'wgt_event']]);
wgt_save(['block' => 'acf/wgt-own-icon', 'title' => '', 'image' => $webp, 'post_types' => ['page']]);
same(['image' => $jpeg], $saved('acf/wgt-renamed'), 'a JPEG preview is one');
same(['image' => $webp], $saved('acf/wgt-own-icon'), 'a WebP preview is one; an image alone keeps a block in the option');

$before = get_option(SETTINGS_OPTION);
same('rejected', wgt_save(['block' => 'acf/wgt-plain', 'title' => 'Nuovo', 'image' => $unmarked]), 'a raster image not uploaded from the dialog (no preview meta): rejected=1');
same('rejected', wgt_save(['block' => 'acf/wgt-plain', 'title' => 'Nuovo', 'image' => $svg]), 'an SVG, even marked as a preview: rejected');
same('rejected', wgt_save(['block' => 'acf/wgt-plain', 'title' => 'Nuovo', 'image' => $pdf]), 'a PDF marked as a preview: rejected');
same('rejected', wgt_save(['block' => 'acf/wgt-plain', 'title' => 'Nuovo', 'image' => $deleted]), 'an image deleted meanwhile: rejected');
same($before, get_option(SETTINGS_OPTION), 'a rejected save stores nothing, the name and post types included');

same('updated', wgt_save(['block' => 'acf/wgt-plain', 'title' => 'Con immagine', 'image' => '', 'post_types' => ['post', 'page', 'wgt_event']]), 'Remove image: an empty image field saves');
same(['title' => 'Con immagine'], $saved('acf/wgt-plain'), 'and the image is gone');
update_post_meta($png, PREVIEW_META, 'acf/wgt-renamed');
wgt_save(['block' => 'acf/wgt-plain', 'title' => 'Con immagine', 'image' => $png, 'post_types' => ['post', 'page', 'wgt_event']]);
same($png, $saved('acf/wgt-plain')['image'] ?? null, 'a preview uploaded for another block can be this one\'s (the meta is checked for presence)');

section('The screen');

delete_option(SETTINGS_OPTION);
update_option(SETTINGS_OPTION, [
    'acf/wgt-plain'    => ['title' => 'Zeta', 'image' => $png],
    'acf/wgt-own-icon' => ['post_types' => ['wgt_event', 'wgt_classic']],
    'acf/wgt-renamed'  => ['image' => $deleted],
]);
$_GET = ['page' => 'wp-go-blocks', 'updated' => '1'];
ob_start();
settings_screen();
$html = (string) ob_get_clean();
$_GET = [];

preg_match_all('/data-block="([^"]*)"/', $html, $rows);
$rows = array_map(fn ($row) => json_decode(html_entity_decode($row, ENT_QUOTES), true), $rows[1]);
$by   = array_column($rows, null, 'name');

same(['acf/wgt-own-icon', 'acf/wgt-renamed', 'acf/wgt-plain'], array_column($rows, 'name'), 'a row per listed block (no dev-*), sorted by the name shown (WGT Own Icon, WGT Renamed, Zeta)');
same(
    ['name' => 'acf/wgt-plain', 'title' => 'Zeta', 'default' => 'WGT Plain', 'image' => $png, 'src' => preview_image('acf/wgt-plain')['src'], 'types' => ['post', 'page', 'wgt_event'], 'json' => ['post', 'page', 'wgt_event']],
    $by['acf/wgt-plain'] ?? null,
    'a row carries the block\'s values for the dialog: name, title, block.json\'s title, image ID and URL, ticks, block.json\'s ticks'
);
same(['wgt_event'], $by['acf/wgt-own-icon']['types'] ?? null, 'the ticks are the stored types among the offered');
same(['page'], $by['acf/wgt-own-icon']['json'] ?? null, 'block.json\'s, for "As in block.json", among the offered');
same([0, ''], [$by['acf/wgt-renamed']['image'] ?? null, $by['acf/wgt-renamed']['src'] ?? null], 'an image deleted since: no ID (saved again it would be refused), no URL');
check('the dialog offers a box per offered post type', substr_count($html, 'name="post_types[]"') === 3 && str_contains($html, 'value="wgt_event"') && !str_contains($html, 'value="wgt_classic"'));
check('the form posts to admin-post.php with the action and the nonce', str_contains($html, 'name="action" value="wp_go_blocks_settings_save"') && str_contains($html, 'name="_wpnonce"'));
check('updated=1 shows the saved notice', str_contains($html, 'notice-success'));

$_GET = ['page' => 'wp-go-blocks', 'rejected' => '1'];
ob_start();
settings_screen();
$html = (string) ob_get_clean();
$_GET = [];
check('rejected=1 shows the not-a-preview error', str_contains($html, 'notice-error') && !str_contains($html, 'notice-success'));
