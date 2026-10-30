<?php

/**
 * Fields, reading: what a block's template receives from its stored data.
 * Every field the block defines is read, a missing one at its default, by
 * ACF's own formatting; empty values and the fields their conditions hide
 * are dropped; groups, clones, repeaters and flexible content come out in
 * the shapes the docs list (a named seamless clone at the top put back as
 * itself, a nameless one and one below the top flat, a clone inside a
 * prefixed clone by its own names); the three filters run where they say.
 * The data is written by hand, by stored name and without pointers, so the
 * reading is checked apart from field_data() (fields-write.test.php). The
 * operators are fields-conditions.test.php's.
 */

require __DIR__ . '/fixtures/fields-blocks.php';

use function WpGo\Blocks\Core\block_fields;
use function WpGo\Blocks\Core\field_objects;
use function WpGo\Blocks\Core\holds_value;
use function WpGo\Blocks\Core\is_absent;
use function WpGo\Blocks\Core\strip_absent;

// The theme's shapes listen for its own groups only, and its dev images only
// with ?dev-images: these blocks get the package's shapes alone. The filter
// sections below add their own listeners and take them off again.

section('Every field the block defines');

$names = array_column(block_fields('acf/fx-plain'), 'name');
same(['eyebrow', 'label', 'count', 'level', 'on', 'body', 'size', 'photo', 'gallery', 'link', 'related', 'zero'], $names, 'the fields are listed in their order, a tab, a message and an accordion left out');

$shapes = block_fields('acf/fx-shapes');
same(['card', 'items', 'fc', 'title', 'subtitle', 'container', 'image', 'alt', 'media', 'pair'], array_column($shapes, 'name'), 'a named seamless clone is listed once, as itself; a nameless one\'s sub fields are listed in its place');
$by_name = array_column($shapes, null, 'name');
same('clone', $by_name['container']['type'], 'the named seamless clone is put back as the clone field');
same('field_fx_shapes_container', $by_name['container']['key'], 'with the clone\'s own key');
same('field_fx_shapes_cover', $by_name['image']['_clone'] ?? null, 'a nameless clone\'s sub field is still marked with its clone\'s key');
check('no field the block\'s fields keep is marked with a named clone\'s key', !array_filter($shapes, fn ($field) => ($field['_clone'] ?? '') === 'field_fx_shapes_container'));
same([], block_fields('acf/fx-nothing-registered'), 'a block nobody registered has no fields');

check('a tab holds no value', !holds_value(['type' => 'tab', 'name' => 'container_']));
check('a message holds no value, even named as a prefixed clone names it', !holds_value(['type' => 'message', 'name' => 'container_']));
check('an accordion holds no value', !holds_value(['type' => 'accordion', 'name' => 'x']));
check('a field with no name holds no value', !holds_value(['type' => 'text', 'name' => '']));
check('a named text field holds one', holds_value(['type' => 'text', 'name' => 'x']));

// field_objects() reads with the block's meta set up, as fields() does
acf_setup_meta(['eyebrow' => 'E'], 'block_fxobjects', true);
$objects = field_objects(['name' => 'acf/fx-plain']);
$acf_objects = get_field_objects('block_fxobjects');
acf_reset_meta('block_fxobjects');

same($names, array_keys($objects), 'field_objects() has an object for every field, by name, stored or not');
same('E', $objects['eyebrow']['value'], 'each with its value');
same('Read more', $objects['label']['value'], 'a field the data doesn\'t have is there with its default');
same('field_fx_plain_label', $objects['label']['key'], 'each is ACF\'s field array, its key included');
check('get_field_objects() on the same data, with no pointers, finds nothing: why the package reads by definition', empty($acf_objects));

section('Defaults, for what the data doesn\'t have');

same(['label' => 'Read more', 'count' => 3, 'on' => false, 'size' => 'Small'], fx_read('fx-plain', []), 'a block with no data gets every default: text, number, true/false, a select by its return format');
same('Read more', fx_read('fx-plain', ['eyebrow' => 'E'])['label'] ?? null, 'a field left out of a block that has others still reads its default');
check('a field stored empty is empty, not its default: the editor cleared it', !array_key_exists('label', fx_read('fx-plain', ['label' => ''])));
same(10, fx_read('fx-plain', ['count' => 10])['count'] ?? null, 'a stored value beats the default');

section('Formatting, numbers');

$plain = fx_read('fx-plain', ['body' => 'Hello', 'size' => 'l', 'count' => '7', 'level' => '2.5', 'link' => ['title' => 'Go', 'url' => 'https://example.test/', 'target' => '']]);
same("<p>Hello</p>\n", $plain['body'] ?? null, 'a wysiwyg comes through ACF\'s formatting (wpautop)');
same('Large', $plain['size'] ?? null, 'a select comes by its return format, here its label');
same(7, $plain['count'] ?? null, 'a number saved as a string arrives as a number');
same(2.5, $plain['level'] ?? null, 'so does a range, a float when it is one');
same(7, fx_read('fx-plain', ['count' => 7])['count'] ?? null, 'a number written as an int stays one');
same(['title' => 'Go', 'url' => 'https://example.test/', 'target' => ''], $plain['link'] ?? null, 'a link arrives as ACF returns it');

section('Empty values');

$read = fx_read('fx-plain', ['eyebrow' => '', 'photo' => '', 'gallery' => '', 'link' => '', 'related' => '', 'on' => '0', 'zero' => '0', 'count' => '0']);
check('an empty text is dropped', !array_key_exists('eyebrow', $read));
check('an image with nothing chosen (ACF\'s false) is dropped', !array_key_exists('photo', $read));
check('an empty gallery is dropped', !array_key_exists('gallery', $read));
check('an empty link is dropped', !array_key_exists('link', $read));
check('an empty relationship is dropped', !array_key_exists('related', $read));
same(false, $read['on'] ?? 'absent', 'a true/false that is off is there, false: an answer, not an absence');
same('0', $read['zero'] ?? 'absent', 'a text holding "0" is a value');
same(0, $read['count'] ?? 'absent', 'a number holding 0 is a value');

check('null is absent', is_absent(null));
check('\'\' is absent', is_absent(''));
check('[] is absent', is_absent([]));
check('false is absent on an image', is_absent(false, 'image'));
check('false is absent with no type', is_absent(false));
check('false is not absent on a true/false', !is_absent(false, 'true_false'));
check('0 is not absent', !is_absent(0));
check('\'0\' is not absent', !is_absent('0'));
check('[0] is not absent', !is_absent([0]));

section('Groups, clones, repeaters, flexible content');

$image = fx_attachment('fx cover');
$stored = [
    'card_subtitle' => 'Sub', 'card_note' => '',
    'items' => 2,
    'items_0_title' => 'First',
    'items_0_box_label' => 'Box',
    'items_0_box_points' => 3,
    'items_0_box_points_0_n' => '', 'items_0_box_points_0_s' => '',
    'items_0_box_points_1_n' => '4', 'items_0_box_points_1_s' => 'x',
    'items_0_box_points_2_n' => '0', 'items_0_box_points_2_s' => '',
    'items_0_heading_text' => 'Row heading', 'items_0_heading_tag' => 'h3',
    'items_0_pic_image' => $image, 'items_0_pic_alt' => 'Pic',
    'items_1_title' => 'Second',
    'items_1_box_points' => 1, 'items_1_box_points_0_s' => '',
    'fc' => ['text', 'divider', 'text'],
    'fc_0_body' => 'Body', 'fc_0_on' => '0', 'fc_0_extra' => 'stale', 'fc_0_heading_text' => 'Flexible',
    'fc_2_on' => '1', 'fc_2_extra' => 'Shown',
    'title_text' => 'Title', 'title_tag' => 'h3',
    'rich' => '1', 'text' => 'stale', 'text_rich' => 'Rich',
    'container_background' => 'dark', 'container_gradient' => 'stale',
    'image' => $image, 'alt' => 'Cover',
    'media_type' => 'video', 'media_image' => $image, 'media_alt' => 'M', 'media_video_url' => 'https://example.test/v',
    'pair_a' => 'A', 'pair_h_text' => 'Pair heading', 'pair_h_tag' => 'h4', 'pair_hs_image' => $image, 'pair_hs_alt' => 'HS',
];
$read = fx_read('fx-shapes', $stored);

same(['subtitle' => 'Sub'], $read['card'] ?? null, 'a group is an array of its sub fields by name, its empty ones dropped');
same([
    'title' => 'First',
    'box' => ['label' => 'Box', 'points' => [['n' => 4, 's' => 'x'], ['n' => 0]]],
    'heading' => ['rich' => false, 'text' => 'Row heading', 'tag' => 'h3'],
    'pic_image' => $image,
    'pic_alt' => 'Pic',
], $read['items'][0] ?? null, 'a repeater\'s row: a group in it holding a repeater, its empty row dropped and the rest renumbered, a heading clone by its own names, a seamless clone flat by its stored names');
same(['title' => 'Second', 'heading' => ['rich' => false, 'tag' => 'h2']], $read['items'][1] ?? null, 'a group whose sub fields are all empty is dropped from its row');
check('the rows are a list', array_is_list($read['items'] ?? [1 => 1]));
same([
    ['acf_fc_layout' => 'text', 'body' => 'Body', 'on' => false, 'heading' => ['rich' => false, 'text' => 'Flexible', 'tag' => 'h2']],
    ['acf_fc_layout' => 'divider'],
    ['acf_fc_layout' => 'text', 'on' => true, 'extra' => 'Shown', 'heading' => ['rich' => false, 'tag' => 'h2']],
], $read['fc'] ?? null, 'flexible content: rows with their layout, a field hidden by its row\'s toggle dropped in that row only, a layout with no fields kept as itself');
same(['rich' => false, 'text' => 'Title', 'tag' => 'h3'], $read['title'] ?? null, 'a prefixed clone shown as a group, by its source\'s names: title.text, not title.title_text');
same(['rich' => true, 'text_rich' => "<p>Rich</p>\n", 'tag' => 'h2'], $read['subtitle'] ?? null, 'an unprefixed one the same way, the text its toggle hides gone and the stale value with it');
same(['padding_top' => 'none', 'background' => 'dark'], $read['container'] ?? null, 'a named seamless clone at the top is one array under its name, a hidden sub field dropped, its tab, message and accordion absent');
same($image, $read['image'] ?? null, 'a nameless seamless clone at the top: its sub fields beside the block\'s own');
same('Cover', $read['alt'] ?? null, 'all of them');
same(['type' => 'video', 'image' => $image, 'alt' => 'M', 'video_url' => 'https://example.test/v'], $read['media'] ?? null, 'a seamless clone inside a clone stays flat among its siblings: media.image');
same(['a' => 'A', 'h' => ['rich' => false, 'text' => 'Pair heading', 'tag' => 'h4'], 'image' => $image, 'alt' => 'HS'], $read['pair'] ?? null, 'inside a prefixed clone, a clone is read by its own names (h.text, from pair_h_text) and a prefixed seamless one by its source\'s (image, from pair_hs_image)');
same(['card', 'items', 'fc', 'title', 'subtitle', 'container', 'image', 'alt', 'media', 'pair'], array_keys($read), 'the fields come in their order');

// ACF's own reading of the clone inside the prefixed clone, by key (no pointers here)
acf_setup_meta($stored, 'block_fxpair', true);
$acf = get_field('field_fx_shapes_pair', 'block_fxpair');
acf_reset_meta('block_fxpair');
same($acf['h']['text'] ?? 'ACF has none', $read['pair']['h']['text'] ?? null, 'the clone inside the prefixed clone reads as get_field() reads it');
same($acf['image'] ?? 'ACF has none', $read['pair']['image'] ?? null, 'and so does its seamless clone');

// The same data with pointers: fields() reads by definition, so they change nothing
$pointed = $stored + ['_title_text' => 'field_fx_heading_text', '_title' => 'field_fx_shapes_title', '_items_0_title' => 'field_fx_shapes_items_title'];
same($read, fx_read('fx-shapes', $pointed), 'the pointers change nothing in what is read');

$read = fx_read('fx-shapes', ['card_subtitle' => '', 'items' => 1, 'items_0_box_points' => 0, 'container_background' => 'none', 'container_gradient' => 'Soft']);
check('a group left with nothing is dropped', !array_key_exists('card', $read));
same(['padding_top' => 'none', 'background' => 'none', 'gradient' => 'Soft'], $read['container'] ?? null, 'the container\'s gradient shows when its background is none: a condition inside a seamless clone at the top');
same([['heading' => ['rich' => false, 'tag' => 'h2']]], $read['items'] ?? null, 'a row with a true/false in it is never empty: the heading\'s toggle is an answer');
same([['acf_fc_layout' => 'divider']], fx_read('fx-shapes', ['fc' => ['divider']])['fc'] ?? null, 'a row of a layout with no fields is kept');
same([['acf_fc_layout' => 'gone']], fx_read('fx-shapes', ['fc' => ['gone']])['fc'] ?? null, 'a row whose layout the field no longer has keeps its layout\'s name, nothing else');

section('A loader\'s return: strip_absent()');

same(['a' => 1, 'list' => ['x', 'y'], 'off' => false], strip_absent(['a' => 1, 'b' => null, 'c' => '', 'd' => [], 'list' => ['x', null, '', 'y'], 'off' => false]), 'null, \'\' and [] go, false stays, a list is renumbered where an item went');
check('a list stays a list', array_is_list(strip_absent(['list' => [null, 'x']])['list'] ?? []));
same('["x"]', json_encode(strip_absent(['list' => [null, 'x']])['list'] ?? null), 'and is a JSON array, not an object with a hole');
same(['keyed' => [2 => 'x']], strip_absent(['keyed' => [1 => null, 2 => 'x']]), 'a keyed array keeps its keys');
same(['deep' => ['inner' => ['kept' => 0]]], strip_absent(['deep' => ['inner' => ['kept' => 0, 'gone' => ''], 'empty' => ['x' => null]]]), 'at any depth, and an array left empty goes too');
same([], strip_absent([null, '', []]), 'a list of nothing is empty');
same(['0', 0, false], strip_absent(['0', 0, false]), '0, \'0\' and false are values');

section('wp_go_blocks/field_value');

$seen = [];
$fill = fx_attachment('fx placeholder');
$listener = function ($value, $field) use (&$seen, $fill) {
    $seen[$field['key']][] = $value;

    if ($field['type'] === 'image' && empty($value)) {
        return $fill;
    }

    return $field['key'] === 'field_fx_shapes_card' ? '' : $value;
};
add_filter('wp_go_blocks/field_value', $listener, 10, 2);
$read = fx_read('fx-shapes', ['image' => '', 'card_subtitle' => 'Sub', 'items' => 1, 'items_0_box_label' => 'L', 'items_0_box_points' => 1, 'items_0_box_points_0_n' => '5', 'fc' => ['text', 'text'], 'fc_0_on' => '0', 'fc_0_extra' => 'stale', 'fc_1_on' => '1', 'fc_1_extra' => 'Shown', 'rich' => '1', 'text' => 'stale', 'text_rich' => 'R', 'container_background' => 'dark', 'container_gradient' => 'stale']);
remove_filter('wp_go_blocks/field_value', $listener, 10);

same($fill, $read['image'] ?? null, 'a value the filter fills in survives the empty check');
check('a value the filter empties is dropped', !array_key_exists('card', $read));
check('it runs for a top-level field', isset($seen['field_fx_shapes_items']));
same(['L'], $seen['field_fx_shapes_items_box_label'] ?? null, 'for a group\'s sub field, in a row');
check('not for the sub fields of a group it emptied: they are never read', !isset($seen['field_fx_shapes_card_subtitle']));
same([5], $seen['field_fx_shapes_items_box_points_n'] ?? null, 'for a field three levels down, already a number');
same(['Shown'], $seen['field_fx_shapes_fc_extra'] ?? null, 'not for a field its condition hides, in the row where it is hidden');
check('not for a seamless clone\'s hidden sub field at the top', !isset($seen['field_fx_shapes_container_field_fx_container_gradient']));
same('stale', $seen['field_fx_shapes_subtitle'][0]['text'] ?? null, 'a clone\'s value is ACF\'s raw array when it runs: its hidden sub field still in it');

section('wp_go_blocks/group_value');

$seen = [];
$listener = function ($value, $field) use (&$seen) {
    $seen[$field['key']][] = $value;

    if (in_array('group_fx_heading', (array) ($field['clone'] ?? []), true)) {
        return isset($value['text']) || isset($value['text_rich']) ? ['text' => $value['text'] ?? $value['text_rich']] : [];
    }

    return $field['key'] === 'field_fx_shapes_container' ? [] : $value;
};
add_filter('wp_go_blocks/group_value', $listener, 10, 2);
$read = fx_read('fx-shapes', $stored);
remove_filter('wp_go_blocks/group_value', $listener, 10);

same(['text' => 'Title'], $read['title'] ?? null, 'what it returns is the group\'s value');
same(['text' => "<p>Rich</p>\n"], $read['subtitle'] ?? null, 'it gets the group with its hidden sub fields already gone');
check('[] drops the group', !array_key_exists('container', $read));
same([['acf_fc_layout' => 'text', 'body' => 'Body', 'on' => false, 'heading' => ['text' => 'Flexible']], ['acf_fc_layout' => 'divider'], ['acf_fc_layout' => 'text', 'on' => true, 'extra' => 'Shown']], $read['fc'] ?? null, 'it runs inside a flexible content\'s rows, and a heading it drops goes from its row');
same(['text' => 'Row heading'], $read['items'][0]['heading'] ?? null, 'and inside a repeater\'s rows');
same(['text' => 'Pair heading'], $read['pair']['h'] ?? null, 'and for a clone inside a clone');
foreach (['field_fx_shapes_card' => 'a group', 'field_fx_shapes_items_box' => 'a group in a row', 'field_fx_shapes_media' => 'a clone shown as a group', 'field_fx_shapes_pair' => 'a clone holding clones', 'field_fx_shapes_container' => 'a named seamless clone at the top'] as $key => $what) {
    check("it runs for $what", isset($seen[$key]));
}
foreach (['field_fx_media_image' => 'the nameless image clone inside media', 'field_fx_shapes_items_pic' => 'the seamless image clone in a row', 'field_fx_pair_hs' => 'the seamless image clone inside pair', 'field_fx_shapes_cover' => 'the nameless clone at the top'] as $key => $what) {
    check("it doesn't run for $what: a seamless clone below the top, or with no name, is part of its parent", !isset($seen[$key]));
}
same(['type' => 'video', 'image' => $image, 'alt' => 'M', 'video_url' => 'https://example.test/v'], $seen['field_fx_shapes_media'][0] ?? null, 'the enclosing group gets the seamless clone\'s fields as its own');

section('wp_go_blocks/fields');

// A block rendered by the package, its template in a fixture folder: the
// filter is render_block()'s, once per block, with the block's slug
fx_block('fx-render', [
    ['key' => 'field_fx_render_title', 'name' => 'title', 'type' => 'clone', 'clone' => ['group_fx_heading'], 'display' => 'group', 'prefix_name' => 1, 'layout' => 'block'],
], ['render_callback' => 'WpGo\Blocks\Core\render_block', 'path' => __DIR__ . '/fixtures/fields-render/fx-render']);

$seen = [];
$listener = function (array $fields, string $slug) use (&$seen) {
    $seen[] = [$slug, $fields];
    $fields['extra'] = 'Added';

    return $fields;
};
add_filter('wp_go_blocks/fields', $listener, 10, 2);
$html = (string) acf_rendered_block(['name' => 'acf/fx-render', 'mode' => 'preview', 'data' => ['title_text' => 'Hello']], '', false, 0);
$alone = fx_read('fx-render', ['title_text' => 'Hello']);
remove_filter('wp_go_blocks/fields', $listener, 10);

same([['fx-render', ['title' => ['rich' => false, 'text' => 'Hello', 'tag' => 'h2']]]], $seen, 'it runs once for the block, with fields()\'s result and the block\'s slug');
check('what it returns is what the template gets', str_contains($html, '>Hello|Added|page</p>'));
check('fields() alone doesn\'t apply it', !array_key_exists('extra', $alone));
