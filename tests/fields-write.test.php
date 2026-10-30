<?php

/**
 * Fields, writing: field_data() turns values shaped as fields() hands them
 * back into the block's data as the editor stores it. Every shape round
 * trips (written, then read, it is what was given plus the defaults); the
 * data is exactly what ACF captures from the editor's form for the same
 * values, pointers included (a cloned field's at its source key, a seamless
 * one's in a row at its compound key); a nameless clone at the top is posted
 * under its clone's key, as the form does; a top-level clone's flat names are
 * gathered into it; what can't be placed is a warning and left out. And it
 * writes nothing: no query runs, ACF's local meta is as it was.
 */

require __DIR__ . '/fixtures/fields-blocks.php';

use function WpGo\Blocks\Core\field_data;

$image = fx_attachment('fx one');
$other_image = fx_attachment('fx two');

section('Round trips: written, then read');

// What fields() hands back for these blocks, written again, is read back
// the same: every shape the read test covers
$values = [
    'card' => ['subtitle' => 'Sub'],
    'items' => [
        [
            'title' => 'First',
            'box' => ['label' => 'Box', 'points' => [['n' => 4, 's' => 'x'], ['n' => 0]]],
            'heading' => ['rich' => false, 'text' => 'Row heading', 'tag' => 'h3'],
            'pic_image' => $image,
            'pic_alt' => 'Pic',
        ],
        ['title' => 'Second', 'heading' => ['rich' => false, 'tag' => 'h2']],
    ],
    'fc' => [
        ['acf_fc_layout' => 'text', 'body' => 'Body', 'on' => false, 'heading' => ['rich' => false, 'text' => 'Flexible', 'tag' => 'h2']],
        ['acf_fc_layout' => 'divider'],
        ['acf_fc_layout' => 'text', 'on' => true, 'extra' => 'Shown', 'heading' => ['rich' => false, 'tag' => 'h2']],
    ],
    'title' => ['rich' => false, 'text' => 'Title', 'tag' => 'h3'],
    'subtitle' => ['rich' => true, 'text_rich' => "<p>Rich</p>\n", 'tag' => 'h2'],
    'container' => ['padding_top' => 'large', 'background' => 'dark'],
    'image' => $image,
    'alt' => 'Cover',
    'media' => ['type' => 'video', 'image' => $other_image, 'alt' => 'M', 'video_url' => 'https://example.test/v'],
    'pair' => ['a' => 'A', 'h' => ['rich' => false, 'text' => 'Pair heading', 'tag' => 'h4'], 'image' => $image, 'alt' => 'HS'],
];
[$data, $warnings] = fx_write('fx-shapes', $values);
same([], $warnings, 'every shape is placed without a warning');
same($values, fx_read('fx-shapes', $data), 'a group, a repeater in a group in a repeater, flexible content, clones of every display, a nameless clone, a clone in a prefixed clone: read back as written');

$plain = ['eyebrow' => 'E', 'label' => 'L', 'count' => 9, 'level' => 2.5, 'on' => true, 'body' => "<p>B</p>\n", 'size' => 'Small', 'photo' => $image, 'gallery' => [$image, $other_image], 'link' => ['title' => 'Go', 'url' => 'https://example.test/', 'target' => '_blank'], 'zero' => '0'];
$written = $plain;
unset($written['size']);
same($plain, fx_read('fx-plain', fx_write('fx-plain', $written)[0]), 'plain fields read back as written, the one left out at its default');

$rows = ['rows' => [
    ['c_padding_top' => 'small', 'c_background' => 'dark', 'd_padding_top' => 'none', 'd_background' => 'none', 'd_gradient' => 'Soft'],
    ['c_padding_top' => 'none', 'c_background' => 'none', 'c_gradient' => 'First', 'd_padding_top' => 'large', 'd_background' => 'light'],
]];
same($rows, fx_read('fx-rows', fx_write('fx-rows', $rows)[0]), 'two seamless containers in a row, by their stored names, read back apart');

$defaults = fx_read('fx-shapes', []);
same(array_replace($defaults, ['title' => ['rich' => false, 'text' => 'Short', 'tag' => 'h2']]), fx_read('fx-shapes', fx_write('fx-shapes', ['title' => ['text' => 'Short']])[0]), 'only what is given is stored: the rest reads at its defaults');
same(array_replace($defaults, ['title' => ['rich' => false, 'text' => 'Stored name', 'tag' => 'h2']]), fx_read('fx-shapes', fx_write('fx-shapes', ['title' => ['title_text' => 'Stored name']])[0]), 'a clone\'s sub field can be given by the name it is stored under too');

section('Stored as ACF stores it');

[$data] = fx_write('fx-plain', ['photo' => ['ID' => $image, 'id' => $image, 'url' => 'https://example.test/x.jpg'], 'gallery' => [$image, $other_image], 'related' => [$image]]);
same($image, $data['photo'] ?? null, 'an image given as its array is stored as its ID');
same([(string) $image, (string) $other_image], $data['gallery'] ?? null, 'a gallery\'s IDs are stored as strings');
same([(string) $image], $data['related'] ?? null, 'so are a relationship\'s');
same('', fx_write('fx-shapes', ['items' => []])[0]['items'] ?? null, 'an empty repeater is stored as \'\'');
same(['text', 'divider'], fx_write('fx-shapes', ['fc' => [['acf_fc_layout' => 'text', 'body' => 'B'], ['acf_fc_layout' => 'divider']]])[0]['fc'] ?? null, 'flexible content stores the list of its rows\' layouts');

// two writes in a row: nothing of the first is left in the second
fx_write('fx-shapes', ['items' => [['title' => 'a'], ['title' => 'b'], ['title' => 'c']]]);
same(
    ['items_0_title' => 'a', '_items_0_title' => 'field_fx_shapes_items_title', 'items' => 1, '_items' => 'field_fx_shapes_items'],
    fx_write('fx-shapes', ['items' => [['title' => 'a']]])[0],
    'a write after one with more rows stores only its own'
);

section('What the editor stores, pointers included');

// The editor's form for the same values: by field key, a named seamless clone
// at the top under its key with compound keys, one below the top flat in its
// row at compound keys; ACF captures it as it does on a block's save
$editor = fx_editor([
    'field_fx_shapes_card' => ['field_fx_shapes_card_subtitle' => 'Sub'],
    'field_fx_shapes_items' => [
        [
            'field_fx_shapes_items_title' => 'First',
            'field_fx_shapes_items_box' => [
                'field_fx_shapes_items_box_label' => 'Box',
                'field_fx_shapes_items_box_points' => [
                    ['field_fx_shapes_items_box_points_n' => 4, 'field_fx_shapes_items_box_points_s' => 'x'],
                    ['field_fx_shapes_items_box_points_n' => 0],
                ],
            ],
            'field_fx_shapes_items_heading' => ['field_fx_heading_rich' => false, 'field_fx_heading_text' => 'Row heading', 'field_fx_heading_tag' => 'h3'],
            'field_fx_shapes_items_pic_field_fx_image_image' => $image,
            'field_fx_shapes_items_pic_field_fx_image_alt' => 'Pic',
        ],
        [
            'field_fx_shapes_items_title' => 'Second',
            'field_fx_shapes_items_heading' => ['field_fx_heading_rich' => false, 'field_fx_heading_tag' => 'h2'],
        ],
    ],
    'field_fx_shapes_fc' => [
        ['acf_fc_layout' => 'text', 'field_fx_shapes_fc_body' => 'Body', 'field_fx_shapes_fc_on' => false, 'field_fx_shapes_fc_heading' => ['field_fx_heading_rich' => false, 'field_fx_heading_text' => 'Flexible', 'field_fx_heading_tag' => 'h2']],
        ['acf_fc_layout' => 'divider'],
        ['acf_fc_layout' => 'text', 'field_fx_shapes_fc_on' => true, 'field_fx_shapes_fc_extra' => 'Shown', 'field_fx_shapes_fc_heading' => ['field_fx_heading_rich' => false, 'field_fx_heading_tag' => 'h2']],
    ],
    'field_fx_shapes_title' => ['field_fx_heading_rich' => false, 'field_fx_heading_text' => 'Title', 'field_fx_heading_tag' => 'h3'],
    'field_fx_shapes_subtitle' => ['field_fx_heading_rich' => true, 'field_fx_heading_text_rich' => "<p>Rich</p>\n", 'field_fx_heading_tag' => 'h2'],
    'field_fx_shapes_container' => [
        'field_fx_shapes_container_field_fx_container_padding_top' => 'large',
        'field_fx_shapes_container_field_fx_container_background' => 'dark',
    ],
    'field_fx_shapes_cover' => [
        'field_fx_shapes_cover_field_fx_image_image' => $image,
        'field_fx_shapes_cover_field_fx_image_alt' => 'Cover',
    ],
    'field_fx_shapes_media' => [
        'field_fx_media_type' => 'video',
        'field_fx_media_image_field_fx_image_image' => $other_image,
        'field_fx_media_image_field_fx_image_alt' => 'M',
        'field_fx_media_video_url' => 'https://example.test/v',
    ],
    'field_fx_shapes_pair' => [
        'field_fx_pair_a' => 'A',
        'field_fx_pair_h' => ['field_fx_heading_rich' => false, 'field_fx_heading_text' => 'Pair heading', 'field_fx_heading_tag' => 'h4'],
        'field_fx_pair_hs_field_fx_image_image' => $image,
        'field_fx_pair_hs_field_fx_image_alt' => 'HS',
    ],
]);
[$data] = fx_write('fx-shapes', $values);
same(fx_sorted($editor), fx_sorted($data), 'field_data() stores exactly what the editor stores for the same values: names, values and pointers');

same('field_fx_heading_text', $data['_title_text'] ?? null, 'a clone\'s sub field points at its key in its own group');
same('field_fx_shapes_title', $data['_title'] ?? null, 'the clone\'s own entry points at the clone');
same('', $data['title'] ?? null, 'and holds \'\'');
same('field_fx_heading_text', $data['_items_0_heading_text'] ?? null, 'a clone in a row too');
same('field_fx_shapes_items_pic_field_fx_image_image', $data['_items_0_pic_image'] ?? null, 'a seamless clone\'s sub field in a row points at its compound key');
same('field_fx_container_background', $data['_container_background'] ?? null, 'a named seamless clone\'s sub field at the top points at its source key');
same('field_fx_image_image', $data['_media_image'] ?? null, 'a nameless clone\'s sub field inside a clone at its source key');
same('field_fx_heading_text', $data['_pair_h_text'] ?? null, 'a clone inside a prefixed clone at its source key');
same('field_fx_image_image', $data['_image'] ?? null, 'a nameless clone\'s at the top at its source key');
same(2, $data['items'] ?? null, 'a repeater stores its row count');

// get_fields() finds a block's fields through the pointers alone
acf_setup_meta($data, 'block_fxpointers', true);
$acf = get_fields('block_fxpointers');
acf_reset_meta('block_fxpointers');
same('Title', $acf['title']['text'] ?? null, 'get_fields() reads the written data through its pointers');
same('First', $acf['items'][0]['title'] ?? null, 'a repeater\'s rows too');

section('A nameless clone at the top');

[$data, $warnings] = fx_write('fx-shapes', ['image' => $image, 'alt' => 'Cover']);
same([], $warnings, 'its sub fields are given flat, as fields() hands them back');
same(['image' => $image, '_image' => 'field_fx_image_image', 'alt' => 'Cover', '_alt' => 'field_fx_image_alt'], $data, 'they are stored beside the block\'s own, as the editor stores them');
same($data, fx_editor(['field_fx_shapes_cover' => ['field_fx_shapes_cover_field_fx_image_image' => $image, 'field_fx_shapes_cover_field_fx_image_alt' => 'Cover']]), 'posted under the clone\'s key, as the editor\'s form names them');
same([], fx_editor(['field_fx_shapes_cover_field_fx_image_image' => $image]), 'given at the top at their compound keys, ACF would save nothing: why they are moved under the clone');

section('A top-level clone\'s flat names');

$nested = fx_write('fx-shapes', ['container' => ['padding_top' => 'large', 'background' => 'dark']])[0];
same($nested, fx_write('fx-shapes', ['container_padding_top' => 'large', 'container_background' => 'dark'])[0], 'a seamless clone given flat, by its stored names, is stored as given as its array');
same(fx_write('fx-shapes', ['title' => ['text' => 'T']])[0], fx_write('fx-shapes', ['title_text' => 'T'])[0], 'so is a clone shown as a group');
same('dark', fx_write('fx-shapes', ['container' => ['background' => 'light'], 'container_background' => 'dark'])[0]['container_background'] ?? null, 'given both ways, the flat one wins');

// A name the block's own field has and a clone's sub field has too: the
// block's own field takes it
fx_block('fx-gather', [
    ['key' => 'field_fx_gather_tag', 'name' => 'tag', 'type' => 'text'],
    ['key' => 'field_fx_gather_heading', 'name' => 'heading', 'type' => 'clone', 'clone' => ['group_fx_heading'], 'display' => 'group', 'prefix_name' => 0, 'layout' => 'block'],
]);
[$data, $warnings] = fx_write('fx-gather', ['tag' => 'own', 'text' => 'Gathered']);
same([], $warnings, 'the clone\'s flat names and the block\'s own are placed');
same('field_fx_gather_tag', $data['_tag'] ?? null, 'a name that is one of the block\'s own fields is that field\'s');
same('field_fx_heading_text', $data['_text'] ?? null, 'the clone\'s own name goes to the clone');
same('field_fx_gather_heading', $data['_heading'] ?? null, 'which gets its entry');

section('Warnings, and what can\'t be placed left out');

[$data, $warnings] = fx_write('fx-shapes', [
    'nope' => 1,
    'card' => ['subtitle' => 'Sub', 'nope' => 1],
    'title' => ['text' => 'T', 'nope' => 1],
    'items' => [['title' => 'a'], ['answer' => 'x']],
    'fc' => [['acf_fc_layout' => 'text', 'body' => 'B'], ['acf_fc_layout' => 'gone'], ['body' => 'no layout']],
    'pair_h_text' => 'nested stored name',
]);
same([
    'field_data(): `fx-shapes` has no field `nope`',
    'field_data(): `card` on `fx-shapes` has no sub field `nope`',
    'field_data(): `title` on `fx-shapes` has no sub field `nope`',
    'field_data(): `items.1` on `fx-shapes` has no sub field `answer`',
    'field_data(): `fc` on `fx-shapes` has no layout `gone`',
    'field_data(): `fc` on `fx-shapes` has no layout ``',
    'field_data(): `fx-shapes` has no field `pair_h_text`',
], $warnings, 'one warning per name it can\'t place, with its path: a field, a sub field, a row\'s, a layout, a nested clone\'s stored name given at the top');
same('Sub', $data['card_subtitle'] ?? null, 'the rest is stored');
same(['text'], $data['fc'] ?? null, 'a row with a layout the field hasn\'t is left out');
same(2, $data['items'] ?? null, 'a row with only an unknown name is still a row');
check('nothing is stored under a name nothing reads', !array_filter(array_keys($data), fn ($name) => str_contains($name, 'nope') || str_contains($name, 'answer')));

same(['field_data(): `rows.0` on `fx-rows` has no sub field `c`'], fx_write('fx-rows', ['rows' => [['c' => ['background' => 'dark']]]])[1], 'a seamless clone in a row is given flat, as read: as its array it is a warning');

// a text where rows or sub fields go: ACF would drop it without a word
[$data, $warnings] = fx_write('fx-shapes', ['card' => 'x', 'items' => 'y', 'fc' => 'z', 'title' => 'str', 'subtitle' => ['text' => 'Kept']]);
same([
    'field_data(): `card` on `fx-shapes` takes an array',
    'field_data(): `items` on `fx-shapes` takes an array',
    'field_data(): `fc` on `fx-shapes` takes an array',
    'field_data(): `title` on `fx-shapes` takes an array',
], $warnings, 'a text given to a group, a repeater, flexible content or a clone is a warning');
same('Kept', $data['subtitle_text'] ?? $data['text'] ?? null, 'and the rest is stored');
same([], fx_write('fx-shapes', ['card' => '', 'items' => null])[1], 'empty, as ACF stores it, is no warning');
same([[], []], fx_write('fx-shapes', []), 'no values: [] and no warning');
same([], fx_write('fx-shapes', ['nope' => 1])[0], 'nothing it can place: []');

section('It writes nothing');

global $wpdb;

// ACF reads the field groups once; after that, nothing reaches the database
field_data('fx-shapes', ['title' => ['text' => 'warm']]);
$queries = [];
$listener = function ($query) use (&$queries) {
    $queries[] = $query;

    return $query;
};
add_filter('query', $listener);
$count = $wpdb->num_queries;
fx_write('fx-shapes', $values);
fx_write('fx-plain', ['eyebrow' => null, 'label' => null, 'photo' => null, 'on' => false]);
fx_write('fx-shapes', ['items' => [], 'title' => null, 'container' => null]);
remove_filter('query', $listener);
same([], $queries, 'no query runs, a null value (a delete) included');
same($count, $wpdb->num_queries, 'none counted either');

$seen = [];
$listener = function ($value, $post_id, $field) use (&$seen) {
    $seen[] = $post_id;

    return $value;
};
add_filter('acf/update_value', $listener, 10, 3);
fx_write('fx-plain', ['eyebrow' => 'E']);
remove_filter('acf/update_value', $listener, 10);
same(['block_wpgoblocksfielddata'], array_values(array_unique($seen)), 'ACF\'s acf/update_value filters run, for a block id of one word');

// Drawn from inside a block's render: that block's meta is ACF's main one,
// and stays so
$meta = acf_get_instance('ACF_Local_Meta');
acf_setup_meta(['eyebrow' => 'Outer', '_eyebrow' => 'field_fx_plain_eyebrow'], 'block_fxouter', true);
$before = [$meta->post_id, $meta->meta];
fx_write('fx-shapes', $values);
same($before, [$meta->post_id, $meta->meta], 'ACF\'s local meta is as it was: the outer block\'s still set up and main, nothing of the write left');
same('Outer', get_field('eyebrow'), 'get_field() still reads the outer block');
acf_reset_meta('block_fxouter');

same(field_data('fx-shapes', $values), WpGoBlocks::field_data('fx-shapes', $values), 'WpGoBlocks::field_data() is the same');
