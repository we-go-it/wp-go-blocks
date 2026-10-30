<?php

/**
 * The fields tests' blocks and field groups, registered in memory: nothing
 * of the theme's, so the shapes checked are the package's alone.
 *
 * Four groups are only ever cloned (inactive, located nowhere), the way a
 * theme shares a heading, an image, a container and a media group:
 *
 *   group_fx_heading    rich (toggle), text (shown when not rich), text_rich (when rich), tag
 *   group_fx_image      image, alt
 *   group_fx_container  a tab, padding_top, a message, background, gradient (shown when the
 *                       background is none), an accordion
 *   group_fx_media      type, a nameless seamless clone of the image group, video_url (for a video)
 *   group_fx_pair       a, h (a heading clone, as a group), hs (an image clone, seamless, prefixed)
 *
 * And the blocks that use them:
 *
 *   fx-plain   one field of each plain kind, with defaults, and the fields that hold no value
 *   fx-shapes  a group, a repeater with a group holding a repeater, flexible content, clones of
 *              every display, a nameless seamless clone at the top, a clone inside a prefixed clone
 *   fx-cond    conditional logic: one field per operator, OR and AND, posts, terms and users
 *   fx-rows    a repeater whose row holds two seamless clones of the container (c, d)
 *
 * Loaded by the test files with require (ACF is up by then), not @require.
 */

use function WpGo\Blocks\Core\field_data;
use function WpGo\Blocks\Core\fields;

// The shared groups, cloned only
foreach ([
    'group_fx_heading' => [
        ['key' => 'field_fx_heading_rich', 'name' => 'rich', 'type' => 'true_false'],
        ['key' => 'field_fx_heading_text', 'name' => 'text', 'type' => 'text',
            'conditional_logic' => [[['field' => 'field_fx_heading_rich', 'operator' => '!=', 'value' => '1']]]],
        ['key' => 'field_fx_heading_text_rich', 'name' => 'text_rich', 'type' => 'wysiwyg',
            'conditional_logic' => [[['field' => 'field_fx_heading_rich', 'operator' => '==', 'value' => '1']]]],
        ['key' => 'field_fx_heading_tag', 'name' => 'tag', 'type' => 'select', 'choices' => ['h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4'], 'default_value' => 'h2', 'return_format' => 'value'],
    ],
    'group_fx_image' => [
        ['key' => 'field_fx_image_image', 'name' => 'image', 'type' => 'image', 'return_format' => 'id'],
        ['key' => 'field_fx_image_alt', 'name' => 'alt', 'type' => 'text'],
    ],
    'group_fx_container' => [
        ['key' => 'field_fx_container_tab', 'name' => '', 'type' => 'tab'],
        ['key' => 'field_fx_container_padding_top', 'name' => 'padding_top', 'type' => 'select', 'choices' => ['none' => 'None', 'small' => 'Small', 'large' => 'Large'], 'default_value' => 'none', 'return_format' => 'value'],
        ['key' => 'field_fx_container_message', 'name' => '', 'type' => 'message', 'message' => 'Spacing'],
        ['key' => 'field_fx_container_background', 'name' => 'background', 'type' => 'select', 'choices' => ['none' => 'None', 'dark' => 'Dark', 'light' => 'Light'], 'default_value' => 'none', 'return_format' => 'value'],
        ['key' => 'field_fx_container_gradient', 'name' => 'gradient', 'type' => 'text',
            'conditional_logic' => [[['field' => 'field_fx_container_background', 'operator' => '==', 'value' => 'none']]]],
        ['key' => 'field_fx_container_accordion', 'name' => '', 'type' => 'accordion'],
    ],
    'group_fx_media' => [
        ['key' => 'field_fx_media_type', 'name' => 'type', 'type' => 'select', 'choices' => ['image' => 'Image', 'video' => 'Video'], 'default_value' => 'image', 'return_format' => 'value'],
        ['key' => 'field_fx_media_image', 'name' => '', 'type' => 'clone', 'clone' => ['group_fx_image'], 'display' => 'seamless', 'prefix_name' => 0, 'layout' => 'block'],
        ['key' => 'field_fx_media_video_url', 'name' => 'video_url', 'type' => 'url',
            'conditional_logic' => [[['field' => 'field_fx_media_type', 'operator' => '==', 'value' => 'video']]]],
    ],
    'group_fx_pair' => [
        ['key' => 'field_fx_pair_a', 'name' => 'a', 'type' => 'text'],
        ['key' => 'field_fx_pair_h', 'name' => 'h', 'type' => 'clone', 'clone' => ['group_fx_heading'], 'display' => 'group', 'prefix_name' => 1, 'layout' => 'block'],
        ['key' => 'field_fx_pair_hs', 'name' => 'hs', 'type' => 'clone', 'clone' => ['group_fx_image'], 'display' => 'seamless', 'prefix_name' => 1, 'layout' => 'block'],
    ],
] as $fx_group => $fx_fields) {
    acf_add_local_field_group([
        'key' => $fx_group,
        'title' => $fx_group,
        'active' => false,
        'location' => [],
        'fields' => array_map(fn ($field) => $field + ['label' => $field['name'] ?: $field['key']], $fx_fields),
    ]);
}

/**
 * A block, its field group located on it. Rendered by nothing, unless
 * $settings says otherwise (the package's render_block() and a `path`).
 *
 * @param list<array<string,mixed>> $fields
 * @param array<string,mixed>       $settings acf_register_block_type()'s.
 */
function fx_block(string $slug, array $fields, array $settings = []): void
{
    acf_register_block_type($settings + ['name' => $slug, 'title' => $slug, 'render_callback' => '__return_null']);

    $label = function (array $field) use (&$label) {
        $field += ['label' => $field['name'] ?: $field['key']];

        if (isset($field['sub_fields'])) {
            $field['sub_fields'] = array_map($label, $field['sub_fields']);
        }

        if (isset($field['layouts'])) {
            foreach ($field['layouts'] as $i => $layout) {
                $field['layouts'][$i]['sub_fields'] = array_map($label, $layout['sub_fields'] ?? []);
                $field['layouts'][$i] += ['label' => $layout['name'], 'display' => 'block'];
            }
        }

        return $field;
    };

    acf_add_local_field_group([
        'key' => 'group_' . str_replace('-', '_', $slug),
        'title' => $slug,
        'location' => [[['param' => 'block', 'operator' => '==', 'value' => "acf/$slug"]]],
        'fields' => array_map($label, $fields),
    ]);
}

fx_block('fx-plain', [
    ['key' => 'field_fx_plain_tab', 'name' => '', 'type' => 'tab'],
    ['key' => 'field_fx_plain_eyebrow', 'name' => 'eyebrow', 'type' => 'text'],
    ['key' => 'field_fx_plain_label', 'name' => 'label', 'type' => 'text', 'default_value' => 'Read more'],
    ['key' => 'field_fx_plain_count', 'name' => 'count', 'type' => 'number', 'default_value' => 3],
    ['key' => 'field_fx_plain_level', 'name' => 'level', 'type' => 'range'],
    ['key' => 'field_fx_plain_on', 'name' => 'on', 'type' => 'true_false'],
    ['key' => 'field_fx_plain_body', 'name' => 'body', 'type' => 'wysiwyg'],
    ['key' => 'field_fx_plain_size', 'name' => 'size', 'type' => 'select', 'choices' => ['s' => 'Small', 'l' => 'Large'], 'default_value' => 's', 'return_format' => 'label'],
    ['key' => 'field_fx_plain_message', 'name' => '', 'type' => 'message', 'message' => 'Hello'],
    ['key' => 'field_fx_plain_photo', 'name' => 'photo', 'type' => 'image', 'return_format' => 'id'],
    ['key' => 'field_fx_plain_gallery', 'name' => 'gallery', 'type' => 'gallery', 'return_format' => 'id'],
    ['key' => 'field_fx_plain_link', 'name' => 'link', 'type' => 'link', 'return_format' => 'array'],
    ['key' => 'field_fx_plain_accordion', 'name' => '', 'type' => 'accordion'],
    ['key' => 'field_fx_plain_related', 'name' => 'related', 'type' => 'relationship', 'return_format' => 'id'],
    ['key' => 'field_fx_plain_zero', 'name' => 'zero', 'type' => 'text'],
]);

fx_block('fx-shapes', [
    ['key' => 'field_fx_shapes_card', 'name' => 'card', 'type' => 'group', 'sub_fields' => [
        ['key' => 'field_fx_shapes_card_subtitle', 'name' => 'subtitle', 'type' => 'text'],
        ['key' => 'field_fx_shapes_card_note', 'name' => 'note', 'type' => 'text'],
    ]],
    ['key' => 'field_fx_shapes_items', 'name' => 'items', 'type' => 'repeater', 'sub_fields' => [
        ['key' => 'field_fx_shapes_items_title', 'name' => 'title', 'type' => 'text'],
        ['key' => 'field_fx_shapes_items_box', 'name' => 'box', 'type' => 'group', 'sub_fields' => [
            ['key' => 'field_fx_shapes_items_box_label', 'name' => 'label', 'type' => 'text'],
            ['key' => 'field_fx_shapes_items_box_points', 'name' => 'points', 'type' => 'repeater', 'sub_fields' => [
                ['key' => 'field_fx_shapes_items_box_points_n', 'name' => 'n', 'type' => 'number'],
                ['key' => 'field_fx_shapes_items_box_points_s', 'name' => 's', 'type' => 'text'],
            ]],
        ]],
        ['key' => 'field_fx_shapes_items_heading', 'name' => 'heading', 'type' => 'clone', 'clone' => ['group_fx_heading'], 'display' => 'group', 'prefix_name' => 1, 'layout' => 'block'],
        ['key' => 'field_fx_shapes_items_pic', 'name' => 'pic', 'type' => 'clone', 'clone' => ['group_fx_image'], 'display' => 'seamless', 'prefix_name' => 1, 'layout' => 'block'],
    ]],
    ['key' => 'field_fx_shapes_fc', 'name' => 'fc', 'type' => 'flexible_content', 'layouts' => [
        'layout_fx_text' => ['key' => 'layout_fx_text', 'name' => 'text', 'sub_fields' => [
            ['key' => 'field_fx_shapes_fc_body', 'name' => 'body', 'type' => 'text'],
            ['key' => 'field_fx_shapes_fc_on', 'name' => 'on', 'type' => 'true_false'],
            ['key' => 'field_fx_shapes_fc_extra', 'name' => 'extra', 'type' => 'text',
                'conditional_logic' => [[['field' => 'field_fx_shapes_fc_on', 'operator' => '==', 'value' => '1']]]],
            ['key' => 'field_fx_shapes_fc_heading', 'name' => 'heading', 'type' => 'clone', 'clone' => ['group_fx_heading'], 'display' => 'group', 'prefix_name' => 1, 'layout' => 'block'],
        ]],
        'layout_fx_divider' => ['key' => 'layout_fx_divider', 'name' => 'divider', 'sub_fields' => []],
    ]],
    ['key' => 'field_fx_shapes_title', 'name' => 'title', 'type' => 'clone', 'clone' => ['group_fx_heading'], 'display' => 'group', 'prefix_name' => 1, 'layout' => 'block'],
    ['key' => 'field_fx_shapes_subtitle', 'name' => 'subtitle', 'type' => 'clone', 'clone' => ['group_fx_heading'], 'display' => 'group', 'prefix_name' => 0, 'layout' => 'block'],
    ['key' => 'field_fx_shapes_container', 'name' => 'container', 'type' => 'clone', 'clone' => ['group_fx_container'], 'display' => 'seamless', 'prefix_name' => 1, 'layout' => 'block'],
    ['key' => 'field_fx_shapes_cover', 'name' => '', 'type' => 'clone', 'clone' => ['group_fx_image'], 'display' => 'seamless', 'prefix_name' => 0, 'layout' => 'block'],
    ['key' => 'field_fx_shapes_media', 'name' => 'media', 'type' => 'clone', 'clone' => ['group_fx_media'], 'display' => 'group', 'prefix_name' => 1, 'layout' => 'block'],
    ['key' => 'field_fx_shapes_pair', 'name' => 'pair', 'type' => 'clone', 'clone' => ['group_fx_pair'], 'display' => 'group', 'prefix_name' => 1, 'layout' => 'block'],
]);

fx_block('fx-rows', [
    ['key' => 'field_fx_rows_rows', 'name' => 'rows', 'type' => 'repeater', 'sub_fields' => [
        ['key' => 'field_fx_rows_c', 'name' => 'c', 'type' => 'clone', 'clone' => ['group_fx_container'], 'display' => 'seamless', 'prefix_name' => 1, 'layout' => 'block'],
        ['key' => 'field_fx_rows_d', 'name' => 'd', 'type' => 'clone', 'clone' => ['group_fx_container'], 'display' => 'seamless', 'prefix_name' => 1, 'layout' => 'block'],
    ]],
]);

/**
 * fields() as ACF's render hands a block over: the block's data set up as
 * its meta first, as acf_render_block() does.
 *
 * @param array<string,mixed> $data The block's stored data.
 * @return array<string,mixed>
 */
function fx_read(string $slug, array $data): array
{
    static $n = 0;
    $id = 'block_fxread' . ++$n;
    $block = acf_prepare_block(['name' => "acf/$slug", 'data' => $data, 'id' => $id]);

    acf_setup_meta($block['data'], $id, true);

    try {
        return fields($block);
    } finally {
        acf_reset_meta($id);
    }
}

/**
 * What the editor stores for a block whose form posted $request (values by
 * field key, nested as the form names its inputs): ACF's own capture, as
 * acf_parse_save_blocks_callback() runs it.
 *
 * @param array<string,mixed> $request
 * @return array<string,mixed>
 */
function fx_editor(array $request): array
{
    $stored = acf_setup_meta($request, 'block_fxeditor');
    acf_reset_meta('block_fxeditor');

    return $stored;
}

/**
 * field_data(), its E_USER_WARNINGs collected rather than printed.
 *
 * @return array{0: array<string,mixed>, 1: list<string>}
 */
function fx_write(string $slug, array $values): array
{
    $warnings = [];

    set_error_handler(function ($level, $message) use (&$warnings) {
        $warnings[] = $message;

        return true;
    }, E_USER_WARNING);

    try {
        return [field_data($slug, $values), $warnings];
    } finally {
        restore_error_handler();
    }
}

/**
 * A block's stored data sorted by name: two writes compared whatever order
 * their values were given in.
 *
 * @param array<string,mixed> $data
 * @return array<string,mixed>
 */
function fx_sorted(array $data): array
{
    ksort($data);

    return $data;
}

/**
 * An image in the media library, made in the test's transaction.
 */
function fx_attachment(string $title = 'fx image'): int
{
    return (int) wp_insert_attachment(['post_title' => $title, 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit', 'guid' => 'http://example.test/' . sanitize_title($title) . '.jpg']);
}
