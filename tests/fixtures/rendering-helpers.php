<?php

/**
 * What the rendering tests share, loaded by each once WordPress is up (a
 * plain require): the fixture blocks, registered from their folders the way
 * the theme's are (register_block_type() on a block.json whose `acf` names
 * `WpGoBlocks::render_block`), their field groups, a post type for the
 * variants, and the helpers that render and watch.
 *
 *   rendering-blocks/rx-card        title, site, block, override_me, twice; a loader that
 *                                   reports its scope; a variant for `rxtype` (loader and
 *                                   template); rx-card.payload.php, a loader for a `payload`
 *                                   post with Twig and the template with PayloadRenderer
 *   rendering-blocks/rx-outer       draws rx-card, handing it a context
 *   rendering-blocks/rx-wrap        <InnerBlocks />
 *   rendering-blocks/rx-self        draws itself
 *   rendering-blocks/rx-a, rx-b     draw each other
 *   rendering-blocks/rx-throw       a loader that throws unless `calm` is set
 *   rendering-blocks/rx-notemplate  a block.json and nothing else
 *   rendering-blocks/rx-folder      registered as acf/rx-named: the slug is the folder's
 *
 * The theme's own listeners on `wp_go_blocks/html` (its dev tools' dumps) and
 * `wp_go_blocks/notice` (its notice markup) are taken off: what is checked is
 * the package's. A test that wants the theme's reads them before requiring
 * this file.
 */

use WpGo\Blocks\Core\Config;

const RX_BLOCKS = __DIR__ . '/rendering-blocks';
const RX_VIEWS  = __DIR__ . '/rendering-views';

remove_all_filters('wp_go_blocks/html');
remove_all_filters('wp_go_blocks/notice');

foreach (glob(RX_BLOCKS . '/*/block.json') as $rx_file) {
    register_block_type(dirname($rx_file));
}
unset($rx_file);

// rx-card's fields, as plain texts: names that collide with the layers over
// them (site, block) and with what its loaders return (override_me, twice)
acf_add_local_field_group([
    'key'      => 'group_rx_card',
    'title'    => 'rx-card',
    'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/rx-card']]],
    'fields'   => array_map(fn (string $name) => ['key' => "field_rx_card_$name", 'name' => $name, 'label' => $name, 'type' => 'text'], ['title', 'site', 'block', 'override_me', 'twice']),
]);
acf_add_local_field_group([
    'key'      => 'group_rx_outer',
    'title'    => 'rx-outer',
    'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/rx-outer']]],
    'fields'   => [['key' => 'field_rx_outer_inner_title', 'name' => 'inner_title', 'label' => 'inner_title', 'type' => 'text']],
]);
acf_add_local_field_group([
    'key'      => 'group_rx_throw',
    'title'    => 'rx-throw',
    'location' => [[['param' => 'block', 'operator' => '==', 'value' => 'acf/rx-throw']]],
    'fields'   => [['key' => 'field_rx_throw_calm', 'name' => 'calm', 'label' => 'calm', 'type' => 'text']],
]);

// A post type with variants, and one named like PayloadRenderer's extension
register_post_type('rxtype', ['public' => true, 'label' => 'RX type']);
register_post_type('payload', ['public' => true, 'label' => 'Payload']);

/**
 * Every block's markup as `wp_go_blocks/html` hands it over, last of the
 * filters: [slug, context, is_preview, html], in the order they rendered.
 */
$GLOBALS['rx_seen'] = [];
add_filter('wp_go_blocks/html', function ($html, $slug, $context, $is_preview) {
    $GLOBALS['rx_seen'][] = ['slug' => $slug, 'context' => $context, 'is_preview' => $is_preview, 'html' => $html, 'args' => func_num_args()];

    return $html;
}, 1000, 4);

/**
 * What the last render of $slug was handed on `wp_go_blocks/html`, or null.
 *
 * @return array{slug: string, context: array<string,mixed>, is_preview: mixed, html: string}|null
 */
function rx_seen(string $slug): ?array
{
    foreach (array_reverse($GLOBALS['rx_seen']) as $seen) {
        if ($seen['slug'] === $slug) {
            return $seen;
        }
    }

    return null;
}

function rx_forget(): void
{
    $GLOBALS['rx_seen'] = [];
}

/**
 * A block as the editor saves it in post_content: its values stored the
 * editor's way (field_data()), and any attribute besides.
 *
 * @param array<string,mixed> $values By field name.
 * @param array<string,mixed> $attrs  More attributes, as saved.
 */
function rx_markup(string $name, array $values = [], array $attrs = []): string
{
    $slug = substr($name, 4);

    return serialize_block([
        'blockName'    => $name,
        'attrs'        => ['name' => $name, 'data' => $values ? WpGoBlocks::field_data($slug, $values) : [], 'mode' => 'preview'] + $attrs,
        'innerBlocks'  => [],
        'innerHTML'    => '',
        'innerContent' => [],
    ]);
}

/**
 * Markup rendered as WordPress renders a post's content on the site:
 * do_blocks(), each ACF block through acf_render_block_callback(), with
 * $post_id the current post (0: none).
 */
function rx_placed(string $markup, int $post_id = 0): string
{
    global $post;

    $previous = $post;
    $post     = $post_id ? get_post($post_id) : null;

    if ($post) {
        setup_postdata($post);
    }

    try {
        return do_blocks($markup);
    } finally {
        $post = $previous;
        wp_reset_postdata();
    }
}

/**
 * A post of $type, in the test's transaction.
 */
function rx_post(string $type = 'post', string $title = 'RX post', string $content = ''): int
{
    return (int) wp_insert_post(['post_type' => $type, 'post_title' => $title, 'post_status' => 'publish', 'post_content' => $content]);
}

/**
 * What $fn printed, and what it returned.
 *
 * @return array{0: string, 1: mixed}
 */
function rx_output(callable $fn): array
{
    ob_start();

    try {
        $returned = $fn();
    } finally {
        $printed = (string) ob_get_clean();
    }

    return [$printed, $returned];
}

/**
 * $fn's E_USER_WARNINGs and E_USER_NOTICEs, collected rather than printed.
 *
 * @return list<string>
 */
function rx_warnings(callable $fn): array
{
    $warnings = [];

    set_error_handler(function ($level, $message) use (&$warnings) {
        $warnings[] = $message;

        return true;
    }, E_USER_WARNING | E_USER_NOTICE);

    try {
        $fn();
    } finally {
        restore_error_handler();
    }

    return $warnings;
}

/**
 * The default notice markup for $message: what the package prints with no
 * listener on `wp_go_blocks/notice`.
 */
function rx_notice(string $message): string
{
    return '<div class="wp-go-blocks-notice">' . esc_html($message) . '</div>';
}

/**
 * $fn with Config's settings changed for its length: what the facade has no
 * setter for (the entities). Reflection, as the settings are private.
 *
 * @param array<string,mixed> $changes
 */
function rx_with_settings(array $changes, callable $fn): mixed
{
    $property = new ReflectionProperty(Config::class, 'settings');
    $saved    = $property->getValue();

    $property->setValue(null, array_replace($saved, $changes));

    try {
        return $fn();
    } finally {
        $property->setValue(null, $saved);
    }
}
