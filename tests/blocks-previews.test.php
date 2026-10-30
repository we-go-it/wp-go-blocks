<?php
// @require fixtures/blocks-skip-theme.php
// @require fixtures/blocks-boot-early.php

/**
 * A block's inserter preview, on the fixture theme's blocks
 * (tests/fixtures/blocks-theme/, `preview_name` "wgt-preview-{slug}"): the
 * upload renamed or refused, the attachment marked and titled, the picker's
 * library, and what reaches the browser, the inserter's images and the
 * screen's script with their JSON before them.
 *
 * No file is uploaded: the hooks get what WordPress would hand them (the
 * $_FILES entry, the new attachment's ID), and the request field the dialog
 * sends is set in $_REQUEST.
 */

use WpGo\Blocks\Core\Config;

use function WpGo\Blocks\Core\enqueue_settings_screen;
use function WpGo\Blocks\Core\mark_preview_upload;
use function WpGo\Blocks\Core\only_previews;
use function WpGo\Blocks\Core\package_asset;
use function WpGo\Blocks\Core\preview_filename;
use function WpGo\Blocks\Core\preview_upload_block;
use function WpGo\Blocks\Core\print_preview_images;
use function WpGo\Blocks\Core\rename_preview_upload;

use const WpGo\Blocks\Core\PREVIEW_META;
use const WpGo\Blocks\Core\PREVIEW_PARAM;
use const WpGo\Blocks\Core\PREVIEW_TYPES;
use const WpGo\Blocks\Core\SETTINGS_OPTION;

$admin  = get_current_user_id();
$editor = wp_insert_user(['user_login' => 'wgt-editor', 'user_pass' => wp_generate_password(), 'role' => 'editor']);

// a $_FILES entry, as WordPress hands it to wp_handle_upload_prefilter
$upload = fn (string $name, string $type = 'image/png') => ['name' => $name, 'type' => $type, 'tmp_name' => '/tmp/php123', 'error' => 0, 'size' => 1000];

/**
 * Run $fn with Config's preview_name set to $pattern.
 */
function wgt_with_pattern(?string $pattern, callable $fn): mixed
{
    $property = new ReflectionProperty(Config::class, 'settings');
    $saved    = $property->getValue();

    $property->setValue(null, ['preview_name' => $pattern] + $saved);

    try {
        return $fn();
    } finally {
        $property->setValue(null, $saved);
    }
}

/**
 * An image attachment with a `large` size, marked as a preview of $block or
 * not; inserted with no dialog field in the request.
 */
function wgt_image(?string $block, int $width = 1024, int $height = 576): int
{
    $id = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => 'wgt', 'post_status' => 'inherit'], 'wgt/preview.png');

    wp_update_attachment_metadata($id, [
        'width'  => $width * 2,
        'height' => $height * 2,
        'file'   => 'wgt/preview.png',
        'sizes'  => ['large' => ['file' => "preview-{$width}x{$height}.png", 'width' => $width, 'height' => $height, 'mime-type' => 'image/png']],
    ]);
    if ($block) {
        update_post_meta($id, PREVIEW_META, $block);
    }

    return $id;
}

section('A preview\'s file name');

same('wgt-preview-plain', preview_filename('acf/wgt-plain'), 'preview_name with {slug} the block\'s folder');
same('wgt-preview-other-name', preview_filename('acf/wgt-renamed'), 'the folder, not the block\'s name, when they differ');
same('wgt-preview-wgt-nothing', preview_filename('acf/wgt-nothing'), 'a block not listed: the last part of its name');
same('block-preview-plain', wgt_with_pattern('block-preview-{slug}', fn () => preview_filename('acf/wgt-plain')), 'the default pattern: block-preview-{slug}');
same('Anteprima-plain', wgt_with_pattern('Anteprima {slug}!', fn () => preview_filename('acf/wgt-plain')), 'through sanitize_file_name(): spaces and punctuation');
same('preview', wgt_with_pattern('preview', fn () => preview_filename('acf/wgt-plain')), 'a pattern without {slug}: every preview the same name');

section('Which upload is a preview');

$_REQUEST[ PREVIEW_PARAM ] = 'acf/wgt-plain';
same('acf/wgt-plain', preview_upload_block(), 'a listed block\'s name in the request field');
$_REQUEST[ PREVIEW_PARAM ] = 'acf/wgt-dev-tool';
same(null, preview_upload_block(), 'not a dev-* block\'s, registered but not listed');
$_REQUEST[ PREVIEW_PARAM ] = 'acf/wgt-nothing';
same(null, preview_upload_block(), 'not a block that isn\'t registered');
$_REQUEST[ PREVIEW_PARAM ] = 'acf/wgt-plain';
wp_set_current_user($editor);
same(null, preview_upload_block(), 'not from a user who can\'t edit theme options');
wp_set_current_user($admin);
unset($_REQUEST[ PREVIEW_PARAM ]);
same(null, preview_upload_block(), 'not without the field');

section('The upload renamed, or refused');

$_REQUEST[ PREVIEW_PARAM ] = 'acf/wgt-renamed';
same('wgt-preview-other-name.png', rename_preview_upload($upload('Screenshot 2026.PNG'))['name'], 'a PNG renamed to the pattern, its extension lowercased');
same('wgt-preview-other-name.jpeg', rename_preview_upload($upload('photo.jpeg', 'image/jpeg'))['name'], 'a JPEG keeps its own extension');
foreach (['photo.jpg', 'photo.gif', 'photo.webp', 'photo.avif', 'photo.heic'] as $name) {
    $file = rename_preview_upload($upload($name));
    check("a raster $name is taken and renamed", empty($file['error']) && str_starts_with($file['name'], 'wgt-preview-other-name.'));
}
check('every PREVIEW_TYPES is a raster image type', !array_diff(PREVIEW_TYPES, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/heic']));

$refused = rename_preview_upload($upload('logo.svg', 'image/svg+xml'));
same('A block preview is a JPEG, PNG, GIF, WebP, AVIF or HEIC image.', $refused['error'], 'an SVG is refused, the error saying what a preview may be');
same('logo.svg', $refused['name'], 'and not renamed');
same(true, is_string(rename_preview_upload($upload('brochure.pdf', 'application/pdf'))['error']), 'a PDF is refused');
same(true, is_string(rename_preview_upload($upload('photo.txt', 'image/png'))['error']), 'by its extension: a .txt the browser calls image/png is refused');
same(true, is_string(rename_preview_upload($upload('noextension', 'image/png'))['error']), 'a file with no extension is refused');
same('wgt-preview-other-name.png', apply_filters('wp_handle_upload_prefilter', $upload('a.png'))['name'], 'through wp_handle_upload_prefilter, as WordPress calls it');

unset($_REQUEST[ PREVIEW_PARAM ]);
same($upload('logo.svg', 'image/svg+xml'), rename_preview_upload($upload('logo.svg', 'image/svg+xml')), 'without the field, any upload is left as it is, an SVG included');
$_REQUEST[ PREVIEW_PARAM ] = 'acf/wgt-dev-tool';
same($upload('Photo.PNG'), rename_preview_upload($upload('Photo.PNG')), 'with a block that isn\'t listed, too');
unset($_REQUEST[ PREVIEW_PARAM ]);

section('The attachment marked');

$_REQUEST[ PREVIEW_PARAM ] = 'acf/wgt-renamed';
$marked = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => 'wgt-preview-other-name', 'post_status' => 'inherit'], 'wgt/wgt-preview-other-name.png');
unset($_REQUEST[ PREVIEW_PARAM ]);
same('acf/wgt-renamed', get_post_meta($marked, PREVIEW_META, true), 'on add_attachment, a preview carries its block\'s name');
same('Block preview: WGT Renamed', get_the_title($marked), 'and is titled with the block\'s registered title');

$plain = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => 'holiday', 'post_status' => 'inherit'], 'wgt/holiday.png');
same('', get_post_meta($plain, PREVIEW_META, true), 'an upload without the field isn\'t marked');
same('holiday', get_the_title($plain), 'nor retitled');

wp_set_current_user($editor);
$_REQUEST[ PREVIEW_PARAM ] = 'acf/wgt-renamed';
mark_preview_upload($plain);
same('', get_post_meta($plain, PREVIEW_META, true), 'nor by a user who can\'t edit theme options');
wp_set_current_user($admin);
unset($_REQUEST[ PREVIEW_PARAM ]);

section('The picker\'s library');

$query = ['post_type' => 'attachment', 'meta_query' => [['key' => 'other', 'compare' => 'EXISTS']]];
same($query, only_previews($query), 'without the flag, the library\'s query is left as it is');
$_REQUEST['query'] = [PREVIEW_PARAM => '1'];
same(
    ['post_type' => 'attachment', 'meta_query' => [['key' => 'other', 'compare' => 'EXISTS'], ['key' => PREVIEW_META, 'compare' => 'EXISTS']]],
    only_previews($query),
    'with query[wp_go_block_preview], only previews: a meta_query beside the others'
);
$found = (new WP_Query(only_previews(['post_type' => 'attachment', 'post_status' => 'inherit', 'post__in' => [$marked, $plain], 'fields' => 'ids'])))->posts;
same([$marked], $found, 'which lists the marked attachment, not the other');
same([$marked], (new WP_Query(apply_filters('ajax_query_attachments_args', ['post_type' => 'attachment', 'post_status' => 'inherit', 'post__in' => [$marked, $plain], 'fields' => 'ids'])))->posts, 'through ajax_query_attachments_args too');
unset($_REQUEST['query']);

section('The inserter\'s images');

$scripts = wp_scripts();

delete_option(SETTINGS_OPTION);
print_preview_images();
same(false, $scripts->query('wp-go-blocks-previews', 'registered'), 'no block with an image: nothing is printed');

$plain_image   = wgt_image('acf/wgt-plain', 1024, 576);
$renamed_image = wgt_image('acf/wgt-renamed', 600, 800);
$dev_image     = wgt_image('acf/wgt-dev-tool');
$gone          = wgt_image('acf/wgt-own-icon');
wp_delete_attachment($gone, true);
update_option(SETTINGS_OPTION, [
    'acf/wgt-plain'    => ['image' => $plain_image],
    'acf/wgt-renamed'  => ['image' => $renamed_image, 'title' => 'Altro'],
    'acf/wgt-dev-tool' => ['image' => $dev_image],
    'acf/wgt-own-icon' => ['image' => $gone],
]);

print_preview_images();
$handle = $scripts->query('wp-go-blocks-previews', 'registered');
check('the script is registered and enqueued', $handle instanceof _WP_Dependency && $scripts->query('wp-go-blocks-previews', 'enqueued'));
same(false, $handle->src ?? null, 'with no src: printed inline (vendor/ has no URL)');
same(['wp-blocks'], $handle->deps ?? null, 'after wp-blocks, whose getBlockTypes() it reads');
same(1, $scripts->get_data('wp-go-blocks-previews', 'group'), 'in the footer');

$before = (array) $scripts->get_data('wp-go-blocks-previews', 'before');
$before = array_values(array_filter($before));
check('one inline script before it, declaring wpGoBlocksPreviews', count($before) === 1 && preg_match('/^var wpGoBlocksPreviews = (.*);$/s', $before[0], $declared));
$images = json_decode($declared[1] ?? 'null', true);
same(['acf/wgt-renamed', 'acf/wgt-plain'], array_keys((array) $images), 'the listed blocks with an image, in their registration order: not the dev-* one, not the one whose image is gone');
same('1024/576', $images['acf/wgt-plain']['ratio'] ?? null, 'each with its large size\'s ratio');
same('600/800', $images['acf/wgt-renamed']['ratio'] ?? null, 'a portrait one too');
check('and its large size\'s URL, slashes unescaped', str_ends_with((string) ($images['acf/wgt-plain']['src'] ?? ''), '/wgt/preview-1024x576.png') && !str_contains($declared[1], '\\/'));
same([package_asset('inserter-previews.js')], array_values(array_filter((array) $scripts->get_data('wp-go-blocks-previews', 'after'))), 'then assets/inserter-previews.js, inline');
wp_deregister_script('wp-go-blocks-previews');

section('The screen\'s assets');

$styles = wp_styles();

enqueue_settings_screen('appearance_page_some-other-page');
check('on another admin page, nothing is enqueued', !$scripts->query('wp-go-blocks-screen', 'registered') && !$styles->query('wp-go-blocks-screen', 'registered'));

$GLOBALS['admin_page_hooks']['themes.php'] = 'appearance';
same('appearance_page_wp-go-blocks', get_plugin_page_hookname('wp-go-blocks', 'themes.php'), 'the screen\'s hook suffix, under Appearance');
enqueue_settings_screen('appearance_page_wp-go-blocks');

$script = $scripts->query('wp-go-blocks-screen', 'registered');
check('on the screen, its script is enqueued, inline', $script instanceof _WP_Dependency && $script->src === false && $scripts->query('wp-go-blocks-screen', 'enqueued'));
same(['media-views'], $script->deps ?? null, 'after the media library\'s views');
check('the media library is enqueued for the dialog\'s picker', (bool) did_action('wp_enqueue_media'));
check('its style is enqueued, inline: assets/blocks-screen.css', $styles->query('wp-go-blocks-screen', 'enqueued') && in_array(package_asset('blocks-screen.css'), (array) $styles->get_data('wp-go-blocks-screen', 'after'), true));

$before = array_values(array_filter((array) $scripts->get_data('wp-go-blocks-screen', 'before')));
check('one inline script before it, declaring wpGoBlocksScreen', count($before) === 1 && preg_match('/^var wpGoBlocksScreen = (.*);$/s', $before[0], $declared));
same(
    ['param' => 'wp_go_block_preview', 'types' => PREVIEW_TYPES, 'labels' => ['pick' => 'Block preview', 'button' => 'Use this image']],
    json_decode($declared[1] ?? 'null', true),
    'the request field, the raster types, the labels'
);
same([package_asset('blocks-screen.js')], array_values(array_filter((array) $scripts->get_data('wp-go-blocks-screen', 'after'))), 'then assets/blocks-screen.js, inline');
