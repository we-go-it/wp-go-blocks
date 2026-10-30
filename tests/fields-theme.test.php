<?php

/**
 * Fields, with the theme's real blocks: each block's fields are given a
 * representative value (made up here from the field's type, nothing of the
 * dev site's content) and written with field_data(), then read with
 * fields(). Every value is placed without a warning, every pointer names a
 * field ACF knows, ACF's get_fields() reads every field fields() reads, and
 * what fields() hands back, written again, reads back the same. Then the
 * theme's shapes (field-shapes.php, on wp_go_blocks/group_value) are checked
 * lightly: a block's heading, container, media and CTA are what the theme's
 * own functions make of the package's values.
 */

require __DIR__ . '/fixtures/fields-blocks.php';

use function WpGo\Blocks\Core\block_fields;
use function WpGo\Blocks\Core\holds_value;
use function WpGo\Blocks\Core\registered;

$blocks = array_keys(registered());

if (!check('the theme registers blocks', count($blocks) > 0)) {
    return;
}

$image = fx_attachment('fx theme image');
$page = wp_insert_post(['post_title' => 'fx theme page', 'post_type' => 'page', 'post_status' => 'publish']);
$skipped = [];

/**
 * A value for a field, by its type: a choice other than the default where
 * there is one, so conditions open, and sub fields by the names fields()
 * hands them back under (a clone's by its source's names).
 */
$sample = function (array $field) use (&$sample, $image, $page, &$skipped) {
    $subs = function (array $fields, bool $in_clone) use (&$sample) {
        $values = [];

        foreach ($fields as $sub) {
            if (!holds_value($sub)) {
                continue;
            }

            $name = $in_clone ? ($sub['__name'] ?? $sub['_name'] ?? $sub['name']) : ($sub['_name'] ?? $sub['name']);
            $value = $sample($sub);

            if ($value !== null) {
                $values[$name] = $value;
            }
        }

        return $values;
    };

    $choices = array_keys((array) ($field['choices'] ?? []));
    $choice = $choices[1] ?? $choices[0] ?? null;

    switch ($field['type']) {
        case 'text':
        case 'textarea':
            return 'Sample ' . $field['_name'];
        case 'wysiwyg':
            return '<p>Sample ' . $field['_name'] . '</p>';
        case 'email':
            return 'fx@example.test';
        case 'url':
            return 'https://example.test/' . $field['_name'];
        case 'number':
        case 'range':
            return isset($field['max']) && is_numeric($field['max']) ? (int) $field['max'] : 2;
        case 'true_false':
            return true;
        case 'select':
        case 'radio':
        case 'button_group':
            return !empty($field['multiple']) ? [$choice] : $choice;
        case 'checkbox':
            return [$choice];
        case 'image':
        case 'file':
            return $image;
        case 'post_object':
        case 'page_link':
            return !empty($field['multiple']) ? [$page] : $page;
        case 'relationship':
            return [$page];
        case 'link':
            return ['title' => 'Sample', 'url' => 'https://example.test/', 'target' => ''];
        case 'group':
            return $subs((array) $field['sub_fields'], false);
        case 'clone':
            return $subs((array) $field['sub_fields'], true);
        case 'repeater':
            return [$subs((array) $field['sub_fields'], false)];
        case 'flexible_content':
            return array_values(array_map(fn ($layout) => ['acf_fc_layout' => $layout['name']] + $subs((array) ($layout['sub_fields'] ?? []), false), (array) $field['layouts']));
        default:
            // a type this test has no sample for (a plugin's own): left out,
            // read at its default
            $skipped[$field['type']] = true;

            return null;
    }
};

// The package's values alone, then with the theme's shapes
$theme_shapes = isset($GLOBALS['wp_filter']['wp_go_blocks/group_value']) ? clone $GLOBALS['wp_filter']['wp_go_blocks/group_value'] : null;
$raw = function (callable $read) use ($theme_shapes) {
    remove_all_filters('wp_go_blocks/group_value');

    try {
        return $read();
    } finally {
        if ($theme_shapes) {
            $GLOBALS['wp_filter']['wp_go_blocks/group_value'] = clone $theme_shapes;
        }
    }
};

$shapes = ['group_wg_heading' => 'wg_heading', 'group_wg_container' => 'wg_container', 'group_wg_media' => 'wg_media_group', 'group_wg_image' => 'wg_media_group', 'group_wg_cta' => 'wg_cta'];
$shaped = [];

foreach ($blocks as $name) {
    $slug = substr($name, 4);
    $fields = block_fields($name);

    section($slug);

    if (!$fields) {
        same([[], []], fx_write($slug, []), 'no fields: nothing to write');
        continue;
    }

    $values = [];

    foreach ($fields as $field) {
        $value = $sample($field);

        if ($value !== null) {
            $values[$field['name']] = $value;
        }
    }

    [$data, $warnings] = fx_write($slug, $values);
    same([], $warnings, 'a value for every field is placed without a warning');

    $unknown = array_filter($data, fn ($value, $key) => str_starts_with($key, '_') && is_string($value) && !acf_get_field($value), ARRAY_FILTER_USE_BOTH);
    same([], $unknown, 'every pointer names a field ACF knows');

    $read = $raw(fn () => fx_read($slug, $data));
    check('fields() reads something back', $read !== []);

    acf_setup_meta($data, 'block_fxtheme', true);
    $acf = get_fields('block_fxtheme') ?: [];
    acf_reset_meta('block_fxtheme');
    same([], array_values(array_diff(array_keys($read), array_keys($acf))), 'ACF\'s get_fields() finds every field fields() reads, through the pointers');

    [$again, $warnings] = fx_write($slug, $read);
    same([], $warnings, 'what fields() hands back is written again without a warning');
    same($read, $raw(fn () => fx_read($slug, $again)), 'and reads back the same');

    // The theme's shapes, on the same data
    $theme = fx_read($slug, $data);

    foreach ($fields as $field) {
        $clones = array_intersect_key($shapes, array_flip((array) ($field['clone'] ?? [])));

        if ($field['type'] !== 'clone' || !$clones || !isset($read[$field['name']])) {
            continue;
        }

        $function = reset($clones);
        $expected = $function($read[$field['name']]);
        $shaped[$function] = true;

        same($expected ?: null, $theme[$field['name']] ?? null, "`{$field['name']}` is what $function() makes of it");
    }
}

section('The theme\'s shapes met');

foreach (array_unique(array_values($shapes)) as $function) {
    check("a block has a field $function() shapes", isset($shaped[$function]));
}

if ($skipped) {
    echo 'note: no sample for ' . implode(', ', array_keys($skipped)) . ' (read at the default)' . PHP_EOL;
}
