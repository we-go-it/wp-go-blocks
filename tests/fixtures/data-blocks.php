<?php

/**
 * The rendering-as-data tests' blocks, registered in memory with their field
 * groups, each rendered by the package's render_block() from a folder under
 * fixtures/data-blocks/ (the folder's name is the slug, as render_block()
 * reads it). Nothing of the theme's: the payloads checked are these blocks'.
 *
 *   dx-card     title (text), body (wysiwyg), count (number): no loader, no
 *               payload file, so its payload is its context
 *   dx-shaped   a `.payload.php` that reshapes the context, and one for the
 *               `dxtype` post type; it counts its runs in $GLOBALS['dx_shaped_runs']
 *   dx-scalar   a `.payload.php` that returns a string, not an array
 *   dx-host     a loader that runs $GLOBALS['dx_host_loader']($context) when a
 *               test sets it: what a block draws for itself, decided per test
 *   dx-columns  a block a page draws with a `content` option, and that holds
 *               inner blocks in a post's content
 *
 * Loaded by the test files with require (ACF is up by then), not @require.
 * Also: dx_block(), dx_paragraph(), dx_post(), dx_image() to make content,
 * dx_rest() to ask the routes, dx_names() to read a page's blocks.
 */

const DX_FIXTURES = __DIR__;

foreach ([
    'dx-card' => [
        ['key' => 'field_dx_card_title', 'name' => 'title', 'type' => 'text'],
        ['key' => 'field_dx_card_body', 'name' => 'body', 'type' => 'wysiwyg'],
        ['key' => 'field_dx_card_count', 'name' => 'count', 'type' => 'number'],
    ],
    'dx-shaped' => [
        ['key' => 'field_dx_shaped_title', 'name' => 'title', 'type' => 'text'],
    ],
    'dx-scalar' => [
        ['key' => 'field_dx_scalar_title', 'name' => 'title', 'type' => 'text'],
    ],
    'dx-host' => [
        ['key' => 'field_dx_host_title', 'name' => 'title', 'type' => 'text'],
    ],
    'dx-columns' => [
        ['key' => 'field_dx_columns_side', 'name' => 'side', 'type' => 'text'],
    ],
] as $dx_slug => $dx_fields) {
    acf_register_block_type([
        'name' => $dx_slug,
        'title' => $dx_slug,
        'render_callback' => 'WpGo\Blocks\Core\render_block',
        'path' => __DIR__ . '/data-blocks/' . $dx_slug,
        'mode' => 'preview',
        'supports' => ['jsx' => true, 'align' => true, 'anchor' => true],
    ]);

    acf_add_local_field_group([
        'key' => 'group_' . str_replace('-', '_', $dx_slug),
        'title' => $dx_slug,
        'location' => [[['param' => 'block', 'operator' => '==', 'value' => "acf/$dx_slug"]]],
        'fields' => array_map(fn ($field) => $field + ['label' => $field['name']], $dx_fields),
    ]);
}
unset($dx_slug, $dx_fields);

/**
 * A placed block's comment, as the editor saves an ACF block: its fields in
 * `data`, by name.
 *
 * @param array<string,mixed> $data
 */
function dx_block(string $slug, array $data = [], string $inner = ''): string
{
    $attrs = wp_json_encode(['name' => "acf/$slug", 'data' => $data, 'mode' => 'preview'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return $inner === ''
        ? "<!-- wp:acf/$slug $attrs /-->"
        : "<!-- wp:acf/$slug $attrs -->$inner<!-- /wp:acf/$slug -->";
}

/** A core paragraph's comment and markup. */
function dx_paragraph(string $text): string
{
    return "<!-- wp:paragraph -->\n<p>$text</p>\n<!-- /wp:paragraph -->";
}

/**
 * A post, in Italian (the site's default language) unless `lang` says
 * otherwise. Published, a page, unless $args says otherwise.
 *
 * @param array<string,mixed> $args wp_insert_post()'s, plus `lang`.
 */
function dx_post(array $args): int
{
    $lang = $args['lang'] ?? 'it';
    unset($args['lang']);

    $id = wp_insert_post(wp_slash($args + ['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $args['post_name'] ?? 'DX']), true);

    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }

    if (class_exists('WpGoLanguages', false) && !defined('ICL_SITEPRESS_VERSION')) {
        WpGoLanguages::set_language($id, $lang);
    }

    return $id;
}

/**
 * A request to the package's routes, in-process: [status, data, error code].
 *
 * @param array<string,mixed> $params
 * @return array{0:int, 1:mixed, 2:?string}
 */
function dx_rest(string $method, string $route, array $params = []): array
{
    $request = new WP_REST_Request($method, '/wp-go-blocks/v1' . $route);

    foreach ($params as $key => $value) {
        $request->set_param($key, $value);
    }

    $response = rest_do_request($request);
    $data = rest_get_server()->response_to_data($response, false);

    // what a client receives: objects as JSON gives them, arrays
    $data = json_decode((string) wp_json_encode($data), true);

    unset($data['cache_tags']);

    return [$response->get_status(), $data, is_array($data) ? ($data['code'] ?? null) : null];
}

/**
 * The names of a list of blocks (a page's, a node's inner blocks), with what
 * tells them apart: `acf/dx-card:Intro` for a card titled Intro, `+t` when a
 * template drew it.
 *
 * @param array<int,array<string,mixed>> $blocks
 * @return list<string>
 */
function dx_names(array $blocks): array
{
    return array_map(fn (array $block) => $block['name']
        . (isset($block['payload']['title']) && is_string($block['payload']['title']) ? ':' . $block['payload']['title'] : '')
        . (!empty($block['template']) ? '+t' : ''), $blocks);
}

/**
 * An image attachment with no file on disk: what image() reads is the
 * attachment's post and metadata, never the file. `$sizes` are the sizes
 * WordPress would have made: [name => [width, height]].
 *
 * @param array<string,array{0:int,1:int}> $sizes
 */
function dx_image(int $width, int $height, array $sizes = [], string $mime = 'image/jpeg'): int
{
    $file = '2026/10/dx-photo-' . wp_generate_password(6, false) . ($mime === 'application/pdf' ? '.pdf' : '.jpg');
    $id = wp_insert_attachment(['post_mime_type' => $mime, 'post_title' => 'DX photo', 'post_status' => 'inherit'], $file);
    $meta = ['width' => $width, 'height' => $height, 'file' => $file, 'sizes' => []];
    $base = pathinfo($file, PATHINFO_FILENAME);

    foreach ($sizes as $name => [$w, $h]) {
        $meta['sizes'][ $name ] = ['file' => "$base-{$w}x{$h}.jpg", 'width' => $w, 'height' => $h, 'mime-type' => $mime];
    }

    wp_update_attachment_metadata($id, $meta);

    return $id;
}
