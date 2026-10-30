<?php

namespace WpGo\Blocks\Core;

/**
 * Appearance → Blocks: per block, the name the editor shows (over block.json's)
 * and the image the inserter previews it with. Stored together in the
 * `wp_go_blocks_settings` option:
 *
 *   block name => { title?: string, image?: attachment ID, post_types?: string[] }
 *
 * The name replaces block.json's "title", and the post types its
 * `acf.postTypes`, as the block registers (apply_settings()): ACF offers the
 * block in the inserter of those post types only; none ticked, in none. The
 * image is a media library attachment uploaded as a block preview
 * (preview-uploads.php): named after wp-go-blocks.json's `preview_name`
 * pattern, titled "Block preview: <name>", and marked with
 * `_wp_go_block_preview` (the block's name), which is how the dialog's picker
 * lists only previews and the save takes nothing else.
 *
 * Not through block.json's `example`: with one, WordPress renders the block live
 * in an iframe of its own on every hover, which loads the whole canvas (every
 * block's JS and CSS) and asks ACF for the markup. Without one, the panel only
 * says "No preview available", and print_preview_images() paints the image
 * there. A block without an image keeps the message.
 *
 * Here, what is stored and how it applies; the screen that edits it, and the
 * inserter's script, are block-settings-screen.php.
 */

/** The option the screen saves. */
const SETTINGS_OPTION = 'wp_go_blocks_settings';

/** The screen's slug, under Appearance. */
const SETTINGS_PAGE = 'wp-go-blocks';

/** The attachment meta a block preview carries: the block's name. */
const PREVIEW_META = '_wp_go_block_preview';

/**
 * The request field the dialog's picker sends: on an upload, the block's name;
 * on the library's query, any value (only previews).
 */
const PREVIEW_PARAM = 'wp_go_block_preview';

/**
 * What a preview may be: a raster image, which WordPress makes the `large`
 * size of. Not an SVG: wp_attachment_is_image() says yes to one (its type is
 * image/…), but WordPress knows no size for it, so the panel would show it
 * square whatever its shape.
 */
const PREVIEW_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/heic'];

/**
 * The blocks the screen lists: every registered one but the developers' own
 * (`dev-*` folders).
 *
 * @return array<string,string> block name => folder
 */
function listed_blocks(): array
{
    return array_filter(registered(), fn ($dir) => !str_starts_with(basename($dir), 'dev-'));
}

/**
 * One block's settings, or all of them.
 *
 * @return array<string,mixed>
 */
function block_settings(?string $name = null): array
{
    $all = (array) get_option(SETTINGS_OPTION, []);

    return $name === null ? $all : (array) ($all[ $name ] ?? []);
}

/**
 * What the screen set, over block.json: the name over its "title", the post
 * types over its `acf.postTypes`. ACF reads those after this filter (on
 * `block_type_metadata_settings`). None ticked hides the block from every
 * inserter, as an empty `acf.postTypes` would mean all of them.
 *
 * On `block_type_metadata`.
 *
 * @param array<string,mixed> $metadata
 * @return array<string,mixed>
 */
function apply_settings($metadata)
{
    $settings = block_settings((string) ($metadata['name'] ?? ''));
    $title    = (string) ($settings['title'] ?? '');

    if ($title !== '') {
        $metadata['title'] = $title;
    }

    if (isset($settings['post_types'])) {
        if ($settings['post_types']) {
            $metadata['acf']['postTypes'] = $settings['post_types'];
        } else {
            $metadata['supports']['inserter'] = false;
        }
    }

    return $metadata;
}

/**
 * The post types the screen offers: those edited in the block editor, but
 * WordPress's own (`wp_*`: patterns, templates, navigation). Patterns need no
 * ticking: ACF adds `wp_block` to any `acf.postTypes`. Once per request, as
 * post types are all registered by the time anything asks.
 *
 * @return array<string,string> name => label
 */
function editor_post_types(): array
{
    static $types = null;

    if ($types !== null) {
        return $types;
    }

    $types = [];

    foreach (get_post_types(['show_in_rest' => true, 'show_ui' => true], 'objects') as $type) {
        if ($type->name !== 'attachment' && !str_starts_with($type->name, 'wp_') && use_block_editor_for_post_type($type->name)) {
            $types[ $type->name ] = $type->labels->name;
        }
    }

    return $types;
}

/**
 * A block's own block.json, decoded: read once per request, however many
 * times the screen and the save ask. Empty when it can't be read.
 *
 * @return array<string,mixed>
 */
function block_json(string $dir): array
{
    static $read = [];

    $read[ $dir ] ??= (array) json_decode((string) @file_get_contents($dir . '/block.json'), true);

    return $read[ $dir ];
}

/**
 * The post types a block's own block.json allows, among editor_post_types():
 * its `acf.postTypes`, or all of them when it names none.
 *
 * @return string[]
 */
function json_post_types(string $dir): array
{
    $named = (array) (block_json($dir)['acf']['postTypes'] ?? []);
    $all   = array_keys(editor_post_types());

    return $named ? array_values(array_intersect($all, $named)) : $all;
}

/**
 * The post types a block is allowed in now, among editor_post_types(): the
 * screen's, else block.json's. What the screen stores beyond those (see
 * save_settings()) it doesn't show.
 *
 * @return string[]
 */
function block_post_types(string $name, string $dir): array
{
    $settings = block_settings($name);

    return isset($settings['post_types'])
        ? array_values(array_intersect(array_keys(editor_post_types()), (array) $settings['post_types']))
        : json_post_types($dir);
}

/**
 * The title in a block's own block.json: the screen's placeholder, and what a
 * name equal to it is saved as (none).
 */
function json_title(string $dir): string
{
    return (string) (block_json($dir)['title'] ?? '');
}

/**
 * A block's preview image: `src` and `ratio` (width/height, for CSS), or null.
 *
 * @return array{src:string,ratio:string}|null
 */
function preview_image(string $name): ?array
{
    $chosen = (int) (block_settings($name)['image'] ?? 0);
    $image  = $chosen ? wp_get_attachment_image_src($chosen, 'large') : false;

    return $image ? ['src' => $image[0], 'ratio' => $image[1] . '/' . $image[2]] : null;
}

/**
 * The dialog's save: one block, among the listed ones. Its name and post types
 * only when they differ from block.json's, its image only when it is one; a
 * block left with none of them is dropped. Autoloaded: the names are read on
 * every request, as the blocks register.
 *
 * An image that is no block preview (not one of PREVIEW_TYPES, or not
 * uploaded from a dialog) saves nothing: the screen says so, rather than "Block saved." over
 * a preview that isn't there. The picker offers no other, so it takes a
 * forged request, or an image deleted meanwhile.
 *
 * On `admin_post_wp_go_blocks_settings_save`.
 */
function save_settings(): void
{
    if (!current_user_can('edit_theme_options')) {
        wp_die('', 403);
    }

    check_admin_referer('wp_go_blocks_settings_save');

    $name   = (string) wp_unslash($_POST['block'] ?? '');
    $listed = listed_blocks();

    if (!isset($listed[ $name ])) {
        wp_die('', 400);
    }

    $title = sanitize_text_field((string) wp_unslash($_POST['title'] ?? ''));
    $image = absint($_POST['image'] ?? 0);
    $block = [];

    if ($title !== '' && $title !== json_title($listed[ $name ])) {
        $block['title'] = $title;
    }

    // the post types ticked, among those offered, in their order
    $offered = array_keys(editor_post_types());
    $types   = array_values(array_intersect($offered, array_map('strval', (array) wp_unslash($_POST['post_types'] ?? []))));

    // Stored, the list replaces block.json's whole, so those it names that the
    // screen doesn't offer (one not edited in the block editor) go with the
    // ticks: ticking among the offered ones says nothing about them. None
    // ticked is none at all.
    if ($types !== json_post_types($listed[ $name ])) {
        $named = (array) (block_json($listed[ $name ])['acf']['postTypes'] ?? []);

        $block['post_types'] = $types ? array_values(array_unique(array_merge($types, array_diff($named, $offered)))) : [];
    }

    // a block preview only: uploaded from a dialog, so named to the pattern
    if ($image) {
        if (!in_array(get_post_mime_type($image), PREVIEW_TYPES, true) || !get_post_meta($image, PREVIEW_META, true)) {
            wp_safe_redirect(add_query_arg(['page' => SETTINGS_PAGE, 'rejected' => 1], admin_url('themes.php')));
            exit;
        }

        $block['image'] = $image;
    }

    $saved = block_settings();
    unset($saved[ $name ]);

    if ($block) {
        $saved[ $name ] = $block;
    }

    update_option(SETTINGS_OPTION, $saved, true);

    wp_safe_redirect(add_query_arg(['page' => SETTINGS_PAGE, 'updated' => 1], admin_url('themes.php')));
    exit;
}
