<?php

namespace WpGo\Blocks;

use WpGo\Blocks\Core\Config;
use WpGo\Blocks\Core\ContentApi;
use WpGo\Blocks\Entities\Entities;
use WpGo\Blocks\Renderer\Renderer;

// `WpGoBlocks::context()`, `WpGoBlocks::render()`… in any template, with no
// `use`, as Timber's own facade is.
if (!class_exists('WpGoBlocks', false)) {
    class_alias(WpGoBlocks::class, 'WpGoBlocks');
}

/**
 * Set the package up for one theme. Call once, from the theme's functions.php:
 *
 *   \WpGo\Blocks\boot(__DIR__ . '/wp-go-blocks.json', [
 *       'renderer' => new TimberRenderer(),
 *       'entities' => new TimberEntities(),
 *       'assets'   => new MyAssetResolver(),
 *   ]);
 *
 * wp-go-blocks.json is shared with the Vite plugin (`@we-go/wp-go-blocks/vite`), so
 * the two halves agree on where the blocks are:
 *
 *   category        the theme's block category, which a block.json names
 *                   (`"category": "<category>"`); none is added without it
 *   blocks          the blocks folder, theme-relative (default `blocks`)
 *   entries         { main, editor } — the Vite plugin's inputs, globs allowed
 *                   (`src/editor.*.js`); a resolver may read `main` to leave
 *                   out what the main bundle preloads
 *   bundles         extra entry bases that are not blocks (`form/form`)
 *   icon            an SVG file, theme-relative: every block's default icon
 *   category_title  the block category's label (default: the category, capitalised)
 *   preview_name    a block preview's file name, `{slug}` the block's folder
 *                   (default `block-preview-{slug}`): Appearance → Blocks
 *
 * Options, in code:
 *
 *   renderer         required: the Renderer blocks render with — new
 *                    TimberRenderer() for Twig through Timber
 *   entities         required: the Entities posts and menus are fetched with —
 *                    new TimberEntities() for Timber's, new WordPressEntities()
 *                    for WordPress's own
 *   init             a callable run once, as the package boots: the engine's own
 *                    setup (fn () => \Timber\Timber::init()), so the theme's
 *                    functions.php names it in one place
 *   content_api      true to answer GET /wp-json/wp-go-blocks/v1/content?slug=&lang=
 *                    with a published page as data (ContentApi). Off by default
 *   assets           an AssetResolver; without one no per-block CSS or JS is printed
 *   head_priorities  { scripts, styles } on wp_head (default 15 and 25: either
 *                    side of a main bundle printed at 20)
 *   buffer           false to skip the response buffer that fills the late
 *                    slots (blocks rendered after wp_head). Without it, a
 *                    list parses each of its posts for their blocks, and
 *                    what renders after wp_head gets only its stylesheets,
 *                    on wp_footer: its JS would run after Alpine.start()
 *
 * The theme is the folder wp-go-blocks.json is in: every path above is relative
 * to it, and so is the template a block renders from.
 *
 * @param array<string,mixed> $options
 */
function boot(string $config_file, array $options = []): void
{
    static $booted = false;

    if ($booted) {
        return;
    }

    $json     = is_readable($config_file) ? json_decode((string) file_get_contents($config_file), true) : null;
    $settings = array_replace(is_array($json) ? $json : [], $options);

    // Unread, the settings would be the defaults with no sign of why: say so.
    // JSON has no comments: a file with one doesn't parse, and gets the second
    // warning.
    if (!is_readable($config_file)) {
        trigger_error("WpGo\\Blocks\\boot(): can't read $config_file, so its settings are the defaults", E_USER_WARNING);
    } elseif (!is_array($json)) {
        $why = json_last_error() !== JSON_ERROR_NONE ? json_last_error_msg() : 'not an object';

        trigger_error("WpGo\\Blocks\\boot(): $config_file is not valid JSON ($why), so its settings are the defaults", E_USER_WARNING);
    }

    // No defaults: which engine renders, and what it reads, are the theme's to
    // say, not something guessed from what happens to be installed. Checked
    // before booting, so a call that throws leaves the package unbooted rather
    // than half set up.
    if (!($settings['renderer'] ?? null) instanceof Renderer) {
        throw new \InvalidArgumentException(
            'WpGo\\Blocks\\boot(): `renderer` is required: a WpGo\\Blocks\\Renderer\\Renderer, such as new \\WpGo\\Blocks\\Renderer\\TimberRenderer()'
        );
    }

    if (!($settings['entities'] ?? null) instanceof Entities) {
        throw new \InvalidArgumentException(
            'WpGo\\Blocks\\boot(): `entities` is required: a WpGo\\Blocks\\Entities\\Entities, such as new \\WpGo\\Blocks\\Entities\\TimberEntities()'
        );
    }

    $booted = true;

    if (is_callable($settings['init'] ?? null)) {
        ($settings['init'])();
    }

    Config::set(dirname($config_file), $settings);

    // A blocks folder that isn't there registers no block, with no sign of why,
    // as an unread config file would: say so too.
    if (!is_dir(Config::blocks_dir())) {
        trigger_error('WpGo\\Blocks\\boot(): there is no blocks folder at ' . Config::blocks_dir() . ', so no block registers', E_USER_WARNING);
    }

    // The blocks register on `init` (10), below: booted after it, or on it
    // from priority 10 on, none registers, and nothing else says why. Not
    // registered right away instead: what boot() hooks past `init` (the
    // settings page on admin_menu, the content API on rest_api_init, the head
    // and the buffer) would be just as late, a package half set up.
    $init = $GLOBALS['wp_filter']['init'] ?? null;

    if (did_action('init') && !(doing_action('init') && $init instanceof \WP_Hook && $init->current_priority() < 10)) {
        trigger_error('WpGo\\Blocks\\boot(): called too late: the blocks register on `init` (priority 10), which has run, so none registers. Call it as the theme loads, from functions.php', E_USER_WARNING);
    }

    Config::renderer()->expose('wp_go_render_block', 'WpGo\\Blocks\\Core\\block');

    add_action('init', 'WpGo\\Blocks\\Core\\register_blocks');

    if (Config::get('content_api')) {
        add_action('rest_api_init', [ContentApi::class, 'register']);
    }
    add_filter('block_type_metadata', 'WpGo\\Blocks\\Core\\default_icon');
    add_action('enqueue_block_editor_assets', 'WpGo\\Blocks\\Core\\print_preview_images');
    add_filter('block_type_metadata', 'WpGo\\Blocks\\Core\\apply_settings');
    add_action('admin_menu', 'WpGo\\Blocks\\Core\\add_settings_page');
    add_action('admin_enqueue_scripts', 'WpGo\\Blocks\\Core\\enqueue_settings_screen');
    add_action('admin_post_wp_go_blocks_settings_save', 'WpGo\\Blocks\\Core\\save_settings');
    add_filter('wp_handle_upload_prefilter', 'WpGo\\Blocks\\Core\\rename_preview_upload');
    add_action('add_attachment', 'WpGo\\Blocks\\Core\\mark_preview_upload');
    add_filter('ajax_query_attachments_args', 'WpGo\\Blocks\\Core\\only_previews');
    add_filter('block_categories_all', 'WpGo\\Blocks\\Core\\add_category');

    if (Config::assets()) {
        $priorities = (array) Config::get('head_priorities');

        add_action('wp_head', 'WpGo\\Blocks\\Core\\print_scripts', (int) ($priorities['scripts'] ?? 15));
        add_action('wp_head', 'WpGo\\Blocks\\Core\\print_styles', (int) ($priorities['styles'] ?? 25));

        if (Config::get('buffer')) {
            add_action('template_redirect', 'WpGo\\Blocks\\Core\\start_buffer', 0);
        }
    }
}
