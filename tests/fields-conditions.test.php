<?php

/**
 * Fields, conditional logic: a field its rules hide is not read. Every
 * operator ACF's form has, `!==` and `==pattern` included (a pattern PHP
 * can't compile passes, as an unknown operator does), OR between rule
 * groups and AND within one, posts, terms and users compared by ID, alone
 * or in a list; a rule matched against the formatted value; a rule naming a
 * field at another level passing. Inside seamless clones a rule finds the
 * sibling from its own clone first: two containers in one repeater row,
 * each gated by its own background. First the rules one at a time
 * (rule_passes()), then a block read through fields().
 */

require __DIR__ . '/fixtures/fields-blocks.php';

use function WpGo\Blocks\Core\clone_scope;
use function WpGo\Blocks\Core\conditions_pass;
use function WpGo\Blocks\Core\rule_passes;

$rule = fn (string $operator, $value, $actual, string $scope = '') => rule_passes(['field' => 'field_x', 'operator' => $operator, 'value' => $value], ['field_x' => $actual], $scope);

section('The operators');

check('== passes on an equal value', $rule('==', 'b', 'b'));
check('== fails on another', !$rule('==', 'b', 'a'));
check('== compares as strings: 5 is "5"', $rule('==', '5', 5));
check('== 1 matches a true/false that is on', $rule('==', '1', true));
check('== 1 doesn\'t match one that is off', !$rule('==', '1', false));
check('== passes when a list holds the value (a checkbox)', $rule('==', 'y', ['x', 'y']));
check('== fails when the list doesn\'t', !$rule('==', 'z', ['x', 'y']));
check('!= passes on another value', $rule('!=', 'b', 'a'));
check('!= fails on the value', !$rule('!=', 'b', 'b'));
check('!= 1 matches a true/false that is off', $rule('!=', '1', false));
check('!= passes when a list doesn\'t hold the value', $rule('!=', 'z', ['x', 'y']));
check('!= fails when it does', !$rule('!=', 'y', ['x', 'y']));
check('!== (ACF 6.8\'s "is not equal to") passes on another value', $rule('!==', 'b', 'a'));
check('!== fails on the value: not an unknown operator that passes', !$rule('!==', 'b', 'b'));
check('!== fails when a list holds the value', !$rule('!==', 'y', ['x', 'y']));
check('==empty passes on \'\'', $rule('==empty', '', ''));
check('==empty passes on null', $rule('==empty', '', null));
check('==empty passes on []', $rule('==empty', '', []));
check('==empty passes on false: a true/false that is off is empty', $rule('==empty', '', false));
check('==empty fails on "0"', !$rule('==empty', '', '0'));
check('==empty fails on 0', !$rule('==empty', '', 0));
check('!=empty passes on a value', $rule('!=empty', '', 'x'));
check('!=empty fails on \'\'', !$rule('!=empty', '', ''));
check('==contains passes on a substring', $rule('==contains', 'ell', 'hello'));
check('==contains fails without it', !$rule('==contains', 'zz', 'hello'));
check('==contains passes on an item of a list', $rule('==contains', 'b', ['a', 'b']));
check('==contains fails when the list hasn\'t it', !$rule('==contains', 'c', ['a', 'b']));
check('!=contains passes without the substring', $rule('!=contains', 'zz', 'hello'));
check('!=contains fails with it', !$rule('!=contains', 'ell', 'hello'));
check('!=contains fails when the list has the item', !$rule('!=contains', 'b', ['a', 'b']));
check('==pattern passes on a match', $rule('==pattern', '^con', 'contatti'));
check('==pattern ignores case, as ACF\'s form does', $rule('==pattern', '^CON', 'Contatti'));
check('==pattern fails on no match', !$rule('==pattern', '^x', 'Contatti'));
check('==pattern takes a pattern with slashes as it is', $rule('==pattern', 'a/b', 'a/b'));
check('==pattern takes one with #, ~ and escapes', $rule('==pattern', '^#\d+~$', '#12~'));
check('==pattern passes on a pattern PHP can\'t compile', $rule('==pattern', '([', 'anything'));
check('> passes on a greater number', $rule('>', '10', '12'));
check('> fails on a smaller one', !$rule('>', '10', 5));
check('> fails on what isn\'t a number', !$rule('>', '10', 'abc'));
check('< passes on a smaller number', $rule('<', '10', 5));
check('< fails on what isn\'t a number', !$rule('<', '10', ''));
check('an operator nobody knows passes', $rule('==unknown', 'x', 'y'));
check('a rule with no operator is ==', rule_passes(['field' => 'field_x', 'value' => 'y'], ['field_x' => 'y']) && !rule_passes(['field' => 'field_x', 'value' => 'z'], ['field_x' => 'y']));
check('a rule naming a field that isn\'t a sibling passes', rule_passes(['field' => 'field_elsewhere', 'operator' => '==', 'value' => 'x'], ['field_x' => 'y']));

section('Posts, terms and users, by ID');

$post = get_post(wp_insert_post(['post_title' => 'fx one', 'post_status' => 'publish', 'post_type' => 'post']));
$other = get_post(wp_insert_post(['post_title' => 'fx two', 'post_status' => 'publish', 'post_type' => 'post']));
$term = get_term(wp_insert_term('fx term one', 'category')['term_id']);
$other_term = get_term(wp_insert_term('fx term two', 'category')['term_id']);
$user = get_user_by('id', wp_insert_user(['user_login' => 'fx_user_one', 'user_pass' => wp_generate_password(), 'user_email' => 'fx-one@example.test']));
$other_user = get_user_by('id', wp_insert_user(['user_login' => 'fx_user_two', 'user_pass' => wp_generate_password(), 'user_email' => 'fx-two@example.test']));

check('a post is its ID', $rule('==', (string) $post->ID, $post));
check('another ID isn\'t it', !$rule('==', (string) $other->ID, $post));
check('!== a post fails on its ID', !$rule('!==', (string) $post->ID, $post));
check('!== a post passes on another', $rule('!==', (string) $other->ID, $post));
check('a list of posts holds an ID', $rule('==', (string) $other->ID, [$post, $other]));
check('!== fails when the list holds it', !$rule('!==', (string) $other->ID, [$post, $other]));
check('==contains finds an ID in a list of posts', $rule('==contains', (string) $other->ID, [$post, $other]));
check('a term is its term_id', $rule('==', (string) $term->term_id, $term));
check('a list of terms holds a term_id', $rule('==', (string) $other_term->term_id, [$term, $other_term]));
check('!= fails on a list of terms holding it', !$rule('!=', (string) $term->term_id, [$term, $other_term]));
check('a user is its ID', $rule('==', (string) $user->ID, $user));
check('a list of users holds an ID', $rule('==', (string) $other_user->ID, [$user, $other_user]));
check('!== a user passes on another', $rule('!==', (string) $other_user->ID, $user));

section('Rule groups: OR between, AND within');

$by_key = ['field_a' => 'b', 'field_t' => true];
$is = fn (string $key, string $value) => ['field' => $key, 'operator' => '==', 'value' => $value];
check('no logic: shown', conditions_pass(0, $by_key) && conditions_pass([], $by_key) && conditions_pass('', $by_key));
check('one group, its one rule passing: shown', conditions_pass([[$is('field_a', 'b')]], $by_key));
check('one group, its rule failing: hidden', !conditions_pass([[$is('field_a', 'c')]], $by_key));
check('OR: the second group passing is enough', conditions_pass([[$is('field_a', 'c')], [$is('field_a', 'b')]], $by_key));
check('OR: no group passing hides it', !conditions_pass([[$is('field_a', 'c')], [$is('field_a', 'd')]], $by_key));
check('AND: every rule in the group passing shows it', conditions_pass([[$is('field_a', 'b'), $is('field_t', '1')]], $by_key));
check('AND: one rule failing hides it', !conditions_pass([[$is('field_a', 'b'), $is('field_t', '0')]], $by_key));
check('a group that isn\'t a list of rules is skipped', !conditions_pass(['junk', [$is('field_a', 'c')]], $by_key));

section('A rule inside a seamless clone: its own clone first');

$by_key = ['field_c_field_bg' => 'dark', 'field_d_field_bg' => 'none', 'field_bg' => 'dark'];
$none = ['field' => 'field_bg', 'operator' => '==', 'value' => 'none'];
check('a rule on a field of clone d reads d\'s background', rule_passes($none, $by_key, 'field_d_'));
check('one on a field of clone c reads c\'s', !rule_passes($none, $by_key, 'field_c_'));
check('with no scope, the source key as it is', !rule_passes($none, $by_key));
check('a scope whose sibling isn\'t there falls back to the source key', !rule_passes($none, $by_key, 'field_e_'));
same('field_c_', clone_scope(['key' => 'field_c_field_bg', '__key' => 'field_bg']), 'a seamless clone\'s field: the start of its compound key');
same('', clone_scope(['key' => 'field_bg']), 'a field of no clone: none');
same('', clone_scope(['key' => 'field_bg', '__key' => 'field_bg']), 'a clone shown as a group keeps its source keys: none');

section('A block read with its conditions');

// Every rule on a field of the block's own: one field per operator, gated by
// the values stored for the first few. Registered here, its rules naming
// posts, terms and users made above.
$gate = fn (string $name, string $key, string $operator, $value = '') => ['key' => "field_fx_cond_$name", 'name' => $name, 'type' => 'text', 'conditional_logic' => [[['field' => "field_fx_cond_$key", 'operator' => $operator, 'value' => (string) $value]]]];
fx_block('fx-cond', [
    ['key' => 'field_fx_cond_sel', 'name' => 'sel', 'type' => 'select', 'choices' => ['a' => 'A', 'b' => 'B', 'c' => 'C'], 'default_value' => 'a', 'return_format' => 'value'],
    ['key' => 'field_fx_cond_label', 'name' => 'label', 'type' => 'select', 'choices' => ['a' => 'A', 'b' => 'B'], 'default_value' => 'b', 'return_format' => 'label'],
    ['key' => 'field_fx_cond_multi', 'name' => 'multi', 'type' => 'checkbox', 'choices' => ['x' => 'X', 'y' => 'Y'], 'return_format' => 'value'],
    ['key' => 'field_fx_cond_num', 'name' => 'num', 'type' => 'number', 'default_value' => 5],
    ['key' => 'field_fx_cond_tf', 'name' => 'tf', 'type' => 'true_false'],
    ['key' => 'field_fx_cond_txt', 'name' => 'txt', 'type' => 'text'],
    ['key' => 'field_fx_cond_post', 'name' => 'post', 'type' => 'post_object', 'post_type' => ['post'], 'return_format' => 'object'],
    ['key' => 'field_fx_cond_posts', 'name' => 'posts', 'type' => 'relationship', 'post_type' => ['post'], 'return_format' => 'object'],
    ['key' => 'field_fx_cond_term', 'name' => 'term', 'type' => 'taxonomy', 'taxonomy' => 'category', 'field_type' => 'select', 'return_format' => 'object', 'load_terms' => 0, 'save_terms' => 0],
    ['key' => 'field_fx_cond_terms', 'name' => 'terms', 'type' => 'taxonomy', 'taxonomy' => 'category', 'field_type' => 'checkbox', 'return_format' => 'object', 'load_terms' => 0, 'save_terms' => 0],
    ['key' => 'field_fx_cond_user', 'name' => 'user', 'type' => 'user', 'return_format' => 'object'],
    ['key' => 'field_fx_cond_users', 'name' => 'users', 'type' => 'user', 'multiple' => 1, 'return_format' => 'object'],
    $gate('if_eq', 'sel', '==', 'b'),
    $gate('if_ne', 'sel', '!=', 'b'),
    $gate('if_nee', 'sel', '!==', 'b'),
    $gate('if_label', 'label', '==', 'b'),
    $gate('if_multi', 'multi', '==', 'y'),
    $gate('if_gt', 'num', '>', 10),
    $gate('if_lt', 'num', '<', 10),
    $gate('if_on', 'tf', '==', 1),
    $gate('if_off', 'tf', '!=', 1),
    $gate('if_empty', 'txt', '==empty'),
    $gate('if_filled', 'txt', '!=empty'),
    $gate('if_contains', 'txt', '==contains', 'ell'),
    $gate('if_lacks', 'txt', '!=contains', 'ell'),
    $gate('if_pattern', 'txt', '==pattern', '^HEL'),
    $gate('if_bad_pattern', 'txt', '==pattern', '(['),
    $gate('if_unknown', 'txt', '==someday', 'x'),
    $gate('if_post', 'post', '==', $post->ID),
    $gate('if_not_post', 'post', '!==', $post->ID),
    $gate('if_posts', 'posts', '==', $other->ID),
    $gate('if_term', 'term', '==', $term->term_id),
    $gate('if_terms', 'terms', '==', $other_term->term_id),
    $gate('if_user', 'user', '==', $user->ID),
    $gate('if_users', 'users', '==', $other_user->ID),
    ['key' => 'field_fx_cond_or', 'name' => 'if_or', 'type' => 'text', 'conditional_logic' => [
        [['field' => 'field_fx_cond_sel', 'operator' => '==', 'value' => 'b']],
        [['field' => 'field_fx_cond_sel', 'operator' => '==', 'value' => 'c']],
    ]],
    ['key' => 'field_fx_cond_and', 'name' => 'if_and', 'type' => 'text', 'conditional_logic' => [
        [['field' => 'field_fx_cond_sel', 'operator' => '==', 'value' => 'b'], ['field' => 'field_fx_cond_tf', 'operator' => '==', 'value' => '1']],
    ]],
    ['key' => 'field_fx_cond_rows', 'name' => 'rows', 'type' => 'repeater', 'sub_fields' => [
        ['key' => 'field_fx_cond_rows_kind', 'name' => 'kind', 'type' => 'select', 'choices' => ['a' => 'A', 'b' => 'B'], 'default_value' => 'a', 'return_format' => 'value'],
        ['key' => 'field_fx_cond_rows_b', 'name' => 'only_b', 'type' => 'text', 'conditional_logic' => [[['field' => 'field_fx_cond_rows_kind', 'operator' => '==', 'value' => 'b']]]],
        ['key' => 'field_fx_cond_rows_up', 'name' => 'by_top', 'type' => 'text', 'conditional_logic' => [[['field' => 'field_fx_cond_tf', 'operator' => '==', 'value' => '1']]]],
    ]],
]);

$gated = ['if_eq', 'if_ne', 'if_nee', 'if_label', 'if_multi', 'if_gt', 'if_lt', 'if_on', 'if_off', 'if_empty', 'if_filled', 'if_contains', 'if_lacks', 'if_pattern', 'if_bad_pattern', 'if_unknown', 'if_post', 'if_not_post', 'if_posts', 'if_term', 'if_terms', 'if_user', 'if_users', 'if_or', 'if_and'];
$shown = function (array $stored) use ($gated) {
    $read = fx_read('fx-cond', $stored + array_fill_keys($gated, 'v'));

    return array_values(array_intersect($gated, array_keys($read)));
};

same(
    ['if_eq', 'if_multi', 'if_gt', 'if_on', 'if_filled', 'if_contains', 'if_pattern', 'if_bad_pattern', 'if_unknown', 'if_post', 'if_posts', 'if_term', 'if_terms', 'if_user', 'if_users', 'if_or', 'if_and'],
    $shown(['sel' => 'b', 'multi' => ['x', 'y'], 'num' => '12', 'tf' => '1', 'txt' => 'Hello', 'post' => $post->ID, 'posts' => [(string) $post->ID, (string) $other->ID], 'term' => $term->term_id, 'terms' => [$term->term_id, $other_term->term_id], 'user' => $user->ID, 'users' => [$user->ID, $other_user->ID]]),
    'one set of values: each field shown or hidden as its operator says, posts, terms and users by ID, one or a list'
);
same(
    ['if_ne', 'if_nee', 'if_lt', 'if_off', 'if_empty', 'if_lacks', 'if_bad_pattern', 'if_unknown', 'if_not_post', 'if_or'],
    $shown(['sel' => 'c', 'label' => 'b', 'num' => '7', 'tf' => '0', 'txt' => '', 'post' => $other->ID, 'posts' => [(string) $post->ID]]),
    'the other values: each the other way, and the label select never matching its value (the formatted value is compared)'
);
same(
    ['if_ne', 'if_nee', 'if_gt', 'if_off', 'if_filled', 'if_contains', 'if_bad_pattern', 'if_unknown', 'if_not_post'],
    $shown(['num' => 100, 'txt' => 'Say hello']),
    'the rest at their defaults: a select at its default is compared as one, a pattern anchored at the start fails mid-text, no post chosen is not that post'
);

$read = fx_read('fx-cond', ['tf' => '0', 'rows' => 2, 'rows_0_kind' => 'b', 'rows_0_only_b' => 'B', 'rows_0_by_top' => 'Top', 'rows_1_only_b' => 'stale']);
same([['kind' => 'b', 'only_b' => 'B', 'by_top' => 'Top'], ['kind' => 'a']], $read['rows'] ?? null, 'in a row, a rule reads its row\'s sibling; one naming a field at the top passes, where ACF\'s form would hide it');

section('Seamless clones, each gated by its own sibling');

$read = fx_read('fx-rows', [
    'rows' => 2,
    'rows_0_c_background' => 'dark', 'rows_0_c_gradient' => 'stale',
    'rows_0_d_background' => 'none', 'rows_0_d_gradient' => 'Soft',
    'rows_1_c_background' => 'none', 'rows_1_c_gradient' => 'First',
    'rows_1_d_background' => 'light', 'rows_1_d_gradient' => 'stale',
]);
same([
    ['c_padding_top' => 'none', 'c_background' => 'dark', 'd_padding_top' => 'none', 'd_background' => 'none', 'd_gradient' => 'Soft'],
    ['c_padding_top' => 'none', 'c_background' => 'none', 'c_gradient' => 'First', 'd_padding_top' => 'none', 'd_background' => 'light'],
], $read['rows'] ?? null, 'two containers in one row: each gradient follows its own container\'s background, whichever comes first');

// Two containers in a group, prefixed, and the same two inside a clone,
// where ACF keys both by their source names and the last one wins
acf_add_local_field_group(['key' => 'group_fx_containers', 'title' => 'fx containers', 'active' => false, 'location' => [], 'fields' => [
    ['key' => 'field_fx_two_c1', 'name' => 'c1', 'label' => 'c1', 'type' => 'clone', 'clone' => ['group_fx_container'], 'display' => 'seamless', 'prefix_name' => 1, 'layout' => 'block'],
    ['key' => 'field_fx_two_c2', 'name' => 'c2', 'label' => 'c2', 'type' => 'clone', 'clone' => ['group_fx_container'], 'display' => 'seamless', 'prefix_name' => 1, 'layout' => 'block'],
]]);
fx_block('fx-two', [
    ['key' => 'field_fx_twob_grp', 'name' => 'grp', 'type' => 'group', 'sub_fields' => [
        ['key' => 'field_fx_twob_grp_c1', 'name' => 'c1', 'type' => 'clone', 'clone' => ['group_fx_container'], 'display' => 'seamless', 'prefix_name' => 1, 'layout' => 'block'],
        ['key' => 'field_fx_twob_grp_c2', 'name' => 'c2', 'type' => 'clone', 'clone' => ['group_fx_container'], 'display' => 'seamless', 'prefix_name' => 1, 'layout' => 'block'],
    ]],
    ['key' => 'field_fx_twob_g', 'name' => 'g', 'type' => 'clone', 'clone' => ['group_fx_containers'], 'display' => 'group', 'prefix_name' => 1, 'layout' => 'block'],
]);
$stored = ['grp_c1_background' => 'dark', 'grp_c1_gradient' => 'stale', 'grp_c2_gradient' => 'Two', 'g_c1_background' => 'dark', 'g_c2_background' => 'light'];
$read = fx_read('fx-two', $stored);
same(['c1_padding_top' => 'none', 'c1_background' => 'dark', 'c2_padding_top' => 'none', 'c2_background' => 'none', 'c2_gradient' => 'Two'], $read['grp'] ?? null, 'in a group, the two containers stay apart by their prefixes, each gated by its own background');
acf_setup_meta($stored, 'block_fxtwo', true);
$acf = get_field('field_fx_twob_g', 'block_fxtwo');
acf_reset_meta('block_fxtwo');
same('light', $read['g']['background'] ?? null, 'inside a clone they collide on their source names: the last one wins');
same($acf['background'] ?? null, $read['g']['background'] ?? null, 'as get_field() has it');

// A nameless clone of the media group at the top: its fields are the
// block's own, their rules with compound keys
fx_block('fx-flat', [
    ['key' => 'field_fx_flat_t', 'name' => 't', 'type' => 'text'],
    ['key' => 'field_fx_flat_media', 'name' => '', 'type' => 'clone', 'clone' => ['group_fx_media'], 'display' => 'seamless', 'prefix_name' => 0, 'layout' => 'block'],
]);
$image = fx_attachment();
same(['t' => 'T', 'type' => 'image', 'image' => $image, 'alt' => 'A'], fx_read('fx-flat', ['t' => 'T', 'type' => 'image', 'image' => $image, 'alt' => 'A', 'video_url' => 'https://example.test/stale']), 'a nameless clone at the top: an image media hides its stale video address');
same(['type' => 'video', 'video_url' => 'https://example.test/v'], fx_read('fx-flat', ['type' => 'video', 'video_url' => 'https://example.test/v']), 'a video media shows it');

// The same inside a flexible content's row: two containers and a nameless
// media clone, flat in the row, each rule scoped to its own clone
fx_block('fx-layouts', [
    ['key' => 'field_fx_layouts_fc', 'name' => 'fc', 'type' => 'flexible_content', 'layouts' => [
        'layout_fx_box' => ['key' => 'layout_fx_box', 'name' => 'box', 'sub_fields' => [
            ['key' => 'field_fx_layouts_c', 'name' => 'c', 'type' => 'clone', 'clone' => ['group_fx_container'], 'display' => 'seamless', 'prefix_name' => 1, 'layout' => 'block'],
            ['key' => 'field_fx_layouts_d', 'name' => 'd', 'type' => 'clone', 'clone' => ['group_fx_container'], 'display' => 'seamless', 'prefix_name' => 1, 'layout' => 'block'],
            ['key' => 'field_fx_layouts_m', 'name' => '', 'type' => 'clone', 'clone' => ['group_fx_media'], 'display' => 'seamless', 'prefix_name' => 0, 'layout' => 'block'],
        ]],
    ]],
]);
same(
    [['acf_fc_layout' => 'box', 'c_padding_top' => 'none', 'c_background' => 'dark', 'd_padding_top' => 'none', 'd_background' => 'none', 'd_gradient' => 'Soft', 'type' => 'image', 'image' => $image]],
    fx_read('fx-layouts', ['fc' => ['box'], 'fc_0_c_background' => 'dark', 'fc_0_c_gradient' => 'stale', 'fc_0_d_gradient' => 'Soft', 'fc_0_type' => 'image', 'fc_0_image' => $image, 'fc_0_video_url' => 'https://example.test/stale'])['fc'] ?? null,
    'in a flexible content\'s row: each container gated by its own background, the media\'s stale video hidden'
);
