<?php

namespace WpGo\Blocks\Core;

/**
 * What the block settings look like (block-settings.php has what they are):
 * Appearance → Blocks, and the script that paints a block's preview image
 * into the inserter.
 *
 * Their CSS and JS are files in the package's assets/, printed inline: the
 * package lives in vendor/, outside the web root, so its files have no URL.
 * What the scripts need from PHP (the labels, the request field, the images)
 * goes before them as one JSON object.
 */

/**
 * One of the package's files in assets/, to print inline.
 */
function package_asset(string $file): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/assets/' . $file);
}

/**
 * The script that paints the images into the inserter. The panel names the
 * block by its title alone, so the script finds the block by title.
 *
 * On `enqueue_block_editor_assets`: the editor's own page, not the canvas.
 */
function print_preview_images(): void
{
    $images = [];

    foreach (array_keys(listed_blocks()) as $name) {
        if ($image = preview_image($name)) {
            $images[$name] = $image;
        }
    }

    if (!$images) {
        return;
    }

    wp_register_script('wp-go-blocks-previews', false, ['wp-blocks'], null, true);
    wp_enqueue_script('wp-go-blocks-previews');
    wp_add_inline_script('wp-go-blocks-previews', 'var wpGoBlocksPreviews = ' . wp_json_encode($images, JSON_UNESCAPED_SLASHES) . ';', 'before');
    wp_add_inline_script('wp-go-blocks-previews', package_asset('inserter-previews.js'));
}

/**
 * The screen: Appearance → Blocks.
 *
 * On `admin_menu`.
 */
function add_settings_page(): void
{
    add_theme_page(
        _x('Blocks', 'src/Core/block-settings-screen.php', 'wp-go-blocks'),
        _x('Blocks', 'src/Core/block-settings-screen.php', 'wp-go-blocks'),
        'edit_theme_options',
        SETTINGS_PAGE,
        __NAMESPACE__ . '\\settings_screen'
    );
}

/**
 * The screen's CSS and JS, and the media library its dialog picks from: on
 * its own page only.
 *
 * On `admin_enqueue_scripts`.
 */
function enqueue_settings_screen(string $hook_suffix): void
{
    if ($hook_suffix !== get_plugin_page_hookname(SETTINGS_PAGE, 'themes.php')) {
        return;
    }

    wp_enqueue_media();

    wp_register_style('wp-go-blocks-screen', false);
    wp_enqueue_style('wp-go-blocks-screen');
    wp_add_inline_style('wp-go-blocks-screen', package_asset('blocks-screen.css'));

    wp_register_script('wp-go-blocks-screen', false, ['media-views'], null, true);
    wp_enqueue_script('wp-go-blocks-screen');
    wp_add_inline_script('wp-go-blocks-screen', 'var wpGoBlocksScreen = ' . wp_json_encode([
        'param'  => PREVIEW_PARAM,
        'types'  => PREVIEW_TYPES,
        'labels' => [
            'pick'   => _x('Block preview', 'src/Core/block-settings-screen.php', 'wp-go-blocks'),
            'button' => _x('Use this image', 'src/Core/block-settings-screen.php', 'wp-go-blocks'),
        ],
    ]) . ';', 'before');
    wp_add_inline_script('wp-go-blocks-screen', package_asset('blocks-screen.js'));
}

/**
 * Every block in a list, edited one at a time in a dialog (as WP-Go's
 * Redirects screen does): a row, or its pencil, opens it; its form saves that
 * block alone (save_settings()). blocks-screen.js runs it.
 */
function settings_screen(): void
{
    $blocks = [];

    foreach (listed_blocks() as $name => $dir) {
        $settings = block_settings($name);
        $image    = preview_image($name);
        $blocks[] = [
            'name'    => $name,
            'default' => json_title($dir) ?: $name,
            'title'   => (string) ($settings['title'] ?? ''),
            // an image deleted since is none: saved again, it would be refused
            'chosen'  => $image ? (int) $settings['image'] : 0,
            'image'   => $image,
            'types'   => block_post_types($name, $dir),
            'json'    => json_post_types($dir),
            'custom'  => isset($settings['post_types']),
        ];
    }

    usort($blocks, fn ($a, $b) => strcasecmp($a['title'] ?: $a['default'], $b['title'] ?: $b['default']));

    $types  = editor_post_types();
    $listed = fn (array $names) => $names
        ? implode(', ', array_map(fn ($type) => $types[ $type ] ?? $type, $names))
        : _x('None: not in the inserter', 'src/Core/block-settings-screen.php', 'wp-go-blocks');
    ?>
    <div class="wrap wg-blocks">
        <h1><?php echo esc_html(_x('Blocks', 'src/Core/block-settings-screen.php', 'wp-go-blocks')); ?></h1>
        <p class="description"><?php echo esc_html(_x('The name the editor shows for each block, the image shown when it is hovered in the inserter, and the post types it can be added to. Left as they are, a block keeps the name and post types in its block.json, and has no preview.', 'src/Core/block-settings-screen.php', 'wp-go-blocks')); ?></p>

        <?php if (!empty($_GET['updated'])) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html(_x('Block saved.', 'src/Core/block-settings-screen.php', 'wp-go-blocks')); ?></p></div>
        <?php elseif (!empty($_GET['rejected'])) : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html(_x('Not saved: that image is not a block preview. Choose a JPEG, PNG, GIF, WebP, AVIF or HEIC image uploaded from the dialog.', 'src/Core/block-settings-screen.php', 'wp-go-blocks')); ?></p></div>
        <?php endif; ?>

        <table class="widefat striped wg-blocks__table">
            <thead>
                <tr>
                    <th class="wg-blocks__col-preview"><?php echo esc_html(_x('Preview', 'src/Core/block-settings-screen.php', 'wp-go-blocks')); ?></th>
                    <th><?php echo esc_html(_x('Name', 'src/Core/block-settings-screen.php', 'wp-go-blocks')); ?></th>
                    <th><?php echo esc_html(_x('Post types', 'src/Core/block-settings-screen.php', 'wp-go-blocks')); ?></th>
                    <th class="wg-blocks__col-actions"><span class="screen-reader-text"><?php echo esc_html(_x('Actions', 'src/Core/block-settings-screen.php', 'wp-go-blocks')); ?></span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($blocks as $block) : ?>
                    <tr class="wg-blocks__row" data-block="<?php echo esc_attr(wp_json_encode([
                        'name'    => $block['name'],
                        'title'   => $block['title'],
                        'default' => $block['default'],
                        'image'   => $block['chosen'],
                        'src'     => $block['image']['src'] ?? '',
                        'types'   => $block['types'],
                        'json'    => $block['json'],
                    ])); ?>">
                        <td>
                            <?php if ($block['image']) : ?>
                                <img class="wg-blocks__thumb" src="<?php echo esc_url($block['image']['src']); ?>" alt="">
                            <?php else : ?>
                                <span class="wg-blocks__thumb wg-blocks__thumb--none"><?php echo esc_html(_x('No preview', 'src/Core/block-settings-screen.php', 'wp-go-blocks')); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?php echo esc_html($block['title'] ?: $block['default']); ?></strong>
                            <span class="description"><code><?php echo esc_html($block['name']); ?></code><?php
                                if ($block['title']) {
                                    echo ' · ' . esc_html(sprintf(_x('in block.json: %s', 'src/Core/block-settings-screen.php', 'wp-go-blocks'), $block['default']));
                                }
                            ?></span>
                        </td>
                        <td>
                            <?php echo esc_html($listed($block['types'])); ?>
                            <?php if ($block['custom']) : ?>
                                <span class="description"><?php echo esc_html(sprintf(_x('in block.json: %s', 'src/Core/block-settings-screen.php', 'wp-go-blocks'), $listed($block['json']))); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="wg-blocks__actions">
                            <button type="button" class="button-link wg-blocks__edit" title="<?php echo esc_attr(_x('Edit', 'src/Core/block-settings-screen.php', 'wp-go-blocks')); ?>" aria-label="<?php echo esc_attr(_x('Edit', 'src/Core/block-settings-screen.php', 'wp-go-blocks')); ?>">
                                <span class="dashicons dashicons-edit" aria-hidden="true"></span>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <dialog class="wg-blocks__modal" aria-labelledby="wg-blocks-editor-title">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wp_go_blocks_settings_save">
                <?php wp_nonce_field('wp_go_blocks_settings_save'); ?>
                <input type="hidden" name="block" value="">
                <input type="hidden" name="image" value="" class="wg-blocks__chosen">

                <h2 id="wg-blocks-editor-title"><?php echo esc_html(_x('Edit block', 'src/Core/block-settings-screen.php', 'wp-go-blocks')); ?></h2>
                <p class="description wg-blocks__name"><code></code></p>

                <div class="wg-blocks__field">
                    <label class="wg-blocks__label" for="wg-blocks-title"><?php echo esc_html(_x('Name', 'src/Core/block-settings-screen.php', 'wp-go-blocks')); ?></label>
                    <input type="text" id="wg-blocks-title" name="title" class="large-text">
                    <span class="description"><?php echo esc_html(_x('Empty: the name in its block.json, shown greyed out.', 'src/Core/block-settings-screen.php', 'wp-go-blocks')); ?></span>
                </div>

                <div class="wg-blocks__field">
                    <span class="wg-blocks__label"><?php echo esc_html(_x('Inserter preview', 'src/Core/block-settings-screen.php', 'wp-go-blocks')); ?></span>
                    <img class="wg-blocks__image" alt="" hidden>
                    <span class="wg-blocks__none"><?php echo esc_html(_x('No preview', 'src/Core/block-settings-screen.php', 'wp-go-blocks')); ?></span>
                    <p>
                        <button type="button" class="button wg-blocks__choose"><?php echo esc_html(_x('Choose image', 'src/Core/block-settings-screen.php', 'wp-go-blocks')); ?></button>
                        <button type="button" class="button-link button-link-delete wg-blocks__clear"><?php echo esc_html(_x('Remove image', 'src/Core/block-settings-screen.php', 'wp-go-blocks')); ?></button>
                    </p>
                </div>

                <fieldset class="wg-blocks__field">
                    <legend class="wg-blocks__label"><?php echo esc_html(_x('Post types', 'src/Core/block-settings-screen.php', 'wp-go-blocks')); ?></legend>
                    <div class="wg-blocks__types">
                        <?php foreach ($types as $type => $label) : ?>
                            <label><input type="checkbox" name="post_types[]" value="<?php echo esc_attr($type); ?>"> <?php echo esc_html($label); ?></label>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="button-link wg-blocks__reset-types"><?php echo esc_html(_x('As in block.json', 'src/Core/block-settings-screen.php', 'wp-go-blocks')); ?></button>
                    <span class="description"><?php echo esc_html(_x('Where the inserter offers it. Posts that already use it keep it, but in a post type unticked here the editor may show it as unsupported.', 'src/Core/block-settings-screen.php', 'wp-go-blocks')); ?></span>
                </fieldset>

                <div class="wg-blocks__modal-actions">
                    <button type="button" class="button wg-blocks__cancel"><?php echo esc_html(_x('Cancel', 'src/Core/block-settings-screen.php', 'wp-go-blocks')); ?></button>
                    <button type="submit" class="button button-primary"><?php echo esc_html(_x('Save', 'src/Core/block-settings-screen.php', 'wp-go-blocks')); ?></button>
                </div>
            </form>
        </dialog>
    </div>
    <?php
}
