<?php

/**
 * Rendering, a drawn block: block() (wp_go_render_block(), WpGoBlocks::block())
 * with the fixture blocks of tests/fixtures/rendering-blocks/. A block that
 * isn't registered, one that draws itself (directly, or through another),
 * the `context` option handed over through a token and nothing a post's
 * content saves under `wp_go_context`, the block id that stays the same for
 * the same values, the `post_id` and `content` options, the preview handed
 * down to what a previewed block draws, and the state put back however a
 * render ends.
 *
 * Templates draw through Twig's wp_go_render_block(), the function boot()
 * exposed: rx-outer draws rx-card, rx-self itself, rx-a and rx-b each other.
 */

use function WpGo\Blocks\Core\declared_blocks;
use function WpGo\Blocks\Core\hand_offs;
use function WpGo\Blocks\Core\previewing;
use function WpGo\Blocks\Core\rendering;

require __DIR__ . '/fixtures/rendering-helpers.php';

// The templates' trailing newlines, out of the way of comparing markup
$flat = fn (string $html) => str_replace("\n", '', trim($html));

$plain = rx_post('post', 'RX plain');
$typed = rx_post('rxtype', 'RX typed');

section('A block that isn\'t registered');

same(rx_notice('wp_go_render_block(): no block registered as `acf/rx-nope`'), WpGoBlocks::block('rx-nope'), 'a slug no block has is a notice naming the block, the message starting with what reports it');
same(rx_notice('wp_go_render_block(): no block registered as `acf/rx-nope`'), WpGoBlocks::block('acf/rx-nope'), 'the same with the acf/ prefix');
check('block() records nothing itself: only a block that renders is recorded', !in_array('acf/rx-nope', declared_blocks(), true));

section('Drawing a block, with a context');

$values  = ['title' => 'Drawn', 'twice' => 'field'];
$handed  = ['handed' => 'handed', 'title' => 'from context', 'block' => 'from context', 'override_me' => 'context', 'empty_handed' => '', 'null_handed' => null];

rx_forget();
$html = WpGoBlocks::block('rx-card', $values, ['context' => $handed]);
$seen = rx_seen('rx-card');
$ctx  = $seen['context'] ?? [];

same('<p class="rx-card">from context|handed|page|drawn|loader</p>', $flat($html), 'the block is drawn through ACF, and block() returns its markup');
same('from context', $ctx['title'] ?? null, 'the context option wins over the field of the same name');
check('`block` wins over a context key named `block`', is_array($ctx['block'] ?? null) && ($ctx['block']['name'] ?? '') === 'acf/rx-card');
same('loader', $ctx['override_me'] ?? null, 'the loader wins over the context option');
check('the context is handed over untouched: \'\' is kept', array_key_exists('empty_handed', $ctx) && $ctx['empty_handed'] === '');
check('and so is null', array_key_exists('null_handed', $ctx) && $ctx['null_handed'] === null);
same(true, $ctx['block']['drawn'] ?? null, 'a drawn block has block.drawn true');
same(true, $ctx['loader_saw']['drawn'] ?? null, 'its loader sees $block[\'drawn\']');
same(false, $seen['is_preview'] ?? null, 'drawn by a page, outside any block, it isn\'t a preview');
check('`block` doesn\'t carry the token', !array_key_exists('wp_go_context', $ctx['block'] ?? []));

$token = (string) ($ctx['loader_saw']['hand_offs'] ?? '');
check('while it rendered, its hand-off waited under one token, a UUID', wp_is_uuid($token), $token);
same([], hand_offs(), 'once it is done, the hand-off is gone');
same(0, rendering(), 'and rendering() is back to 0');
check('the drawn block is recorded by its name', in_array('acf/rx-card', declared_blocks(), true));

section('The block id');

$expected = acf_get_block_id(['name' => 'acf/rx-card', 'mode' => 'preview', 'data' => WpGoBlocks::field_data('rx-card', $values), 'wp_go_context' => $handed]);
// ACF's block array has it prefixed (acf_ensure_block_id_prefix())
same('block_' . $expected, $ctx['block']['id'] ?? null, 'the id is the one ACF would make from the attributes with the context in them, not the token');

WpGoBlocks::block('rx-card', $values, ['context' => $handed]);
same($ctx['block']['id'] ?? null, rx_seen('rx-card')['context']['block']['id'] ?? 'none', 'the same block drawn with the same values keeps its id');

WpGoBlocks::block('rx-card', $values, ['context' => ['handed' => 'other']]);
check('another context gives another id', ($ctx['block']['id'] ?? null) !== (rx_seen('rx-card')['context']['block']['id'] ?? null));

WpGoBlocks::block('rx-card', ['title' => 'Other'], ['context' => $handed]);
check('other values give another id', ($ctx['block']['id'] ?? null) !== (rx_seen('rx-card')['context']['block']['id'] ?? null));

section('`wp_go_context` saved in a post\'s content');

// An attribute is anyone's to write: what a post saves under the name
// block() uses is never read as values, whatever its shape, and never fails
$saved = [
    'an array of values' => ['title' => 'Injected', 'handed' => 'injected'],
    'a string'           => 'not-a-token',
    'a number'           => 42,
    'the token of a draw that has ended' => $token,
];
foreach ($saved as $what => $attribute) {
    rx_forget();
    $html = rx_placed(rx_markup('acf/rx-card', ['title' => 'Saved'], ['wp_go_context' => $attribute]), $plain);
    $ctx  = rx_seen('rx-card')['context'] ?? [];

    same('<p class="rx-card">Saved||page|placed|loader</p>', $flat($html), "$what under wp_go_context: the block renders its own values, nothing handed, not drawn");
    check("$what: `block` doesn't carry it", !array_key_exists('wp_go_context', $ctx['block'] ?? ['wp_go_context' => 1]));
}

section('The post a drawn block renders for');

rx_forget();
$html = WpGoBlocks::block('rx-card', ['title' => 'For typed'], ['post_id' => $typed]);
same('<p class="rx-card rx-card--rxtype">variant|For typed|variant|loader</p>', $flat($html), 'post_id picks the variant: an rxtype post\'s template and loader');
same($typed, rx_seen('rx-card')['context']['loader_saw']['post_id'] ?? null, 'and is the loaders\' $post_id');
same('For typed', rx_seen('rx-card')['context']['title'] ?? null, 'its fields are still its data, not the post\'s');

rx_forget();
WpGoBlocks::block('rx-card', ['title' => 'No post']);
same(0, rx_seen('rx-card')['context']['loader_saw']['post_id'] ?? null, 'without post_id, and no current post, its $post_id is 0');

$GLOBALS['post'] = get_post($typed);
rx_forget();
$html = WpGoBlocks::block('rx-card', ['title' => 'Current']);
$GLOBALS['post'] = null;
same('<p class="rx-card rx-card--rxtype">variant|Current|variant|loader</p>', $flat($html), 'without post_id, it renders for the current post, its variant included');

section('Inner blocks: the `content` option');

$html = WpGoBlocks::block('rx-wrap', [], ['content' => '<p>Inside</p>']);
check('the content takes the place of <InnerBlocks />', str_contains($html, '<p>Inside</p>') && !str_contains($html, 'InnerBlocks'), $html);

section('A block that draws itself');

same('<i class="rx-self">' . rx_notice('wp_go_render_block(): `acf/rx-self` draws itself (acf/rx-self → acf/rx-self)') . '</i>', $flat(WpGoBlocks::block('rx-self')), 'drawn, a block whose template draws it again gets a notice there, naming the loop');
same('<i class="rx-a"><i class="rx-b">' . rx_notice('wp_go_render_block(): `acf/rx-a` draws itself (acf/rx-a → acf/rx-b → acf/rx-a)') . '</i></i>', $flat(WpGoBlocks::block('rx-a')), 'through another block too: A draws B draws A');
same('<i class="rx-self"><i class="rx-self">' . rx_notice('wp_go_render_block(): `acf/rx-self` draws itself (acf/rx-self → acf/rx-self)') . '</i></i>', $flat(rx_placed(rx_markup('acf/rx-self'), $plain)), 'placed, it goes one level deeper first: only the draws are on the stack');
same('<i class="rx-a"><i class="rx-b"><i class="rx-a">' . rx_notice('wp_go_render_block(): `acf/rx-b` draws itself (acf/rx-b → acf/rx-a → acf/rx-b)') . '</i></i></i>', $flat(rx_placed(rx_markup('acf/rx-a'), $plain)), 'placed A draws B, B draws A, and A\'s second B is the loop');
same('<p class="rx-card">After||page|drawn|loader</p>', $flat(WpGoBlocks::block('rx-card', ['title' => 'After'])), 'after a loop, the stack is empty: the next draw renders');

section('Drawn inside a preview');

rx_forget();
$html = (string) acf_rendered_block(['name' => 'acf/rx-outer', 'mode' => 'preview', 'data' => WpGoBlocks::field_data('rx-outer', ['inner_title' => 'In preview'])], '', true, $plain);
$inner = rx_seen('rx-card');
$outer = rx_seen('rx-outer');

same(true, $outer['is_preview'] ?? null, 'the outer block is ACF\'s preview');
same(true, $inner['is_preview'] ?? null, 'the block it draws is in the preview too: is_preview true on wp_go_blocks/html');
same(true, $inner['context']['is_preview'] ?? null, 'and in its template');
same(true, $inner['context']['loader_saw']['is_preview'] ?? null, 'and its loader\'s $is_preview');
same(true, $inner['context']['block']['drawn'] ?? null, 'it knows it was drawn: its markup goes back to the outer block, not to the editor');
same('from-outer', $inner['context']['handed'] ?? null, 'the context the outer template handed it arrived');
same(2, $inner['context']['loader_saw']['rendering'] ?? null, 'while it renders, rendering() counts two blocks, one inside the other');
check('the outer block\'s markup holds the drawn one\'s', str_contains($flat($html), '<div class="rx-outer">preview|<p class="rx-card">In preview|from-outer|preview|drawn|loader</p></div>'), $html);
same(false, previewing(), 'after the preview, previewing() is false again');
same(0, rendering(), 'and rendering() 0');

rx_forget();
WpGoBlocks::block('rx-card', ['title' => 'After the preview']);
same(false, rx_seen('rx-card')['is_preview'] ?? null, 'a block drawn by a page after it isn\'t a preview');

rx_forget();
$html = rx_placed(rx_markup('acf/rx-outer', ['inner_title' => 'On the site']), $plain);
same('<div class="rx-outer">page|<p class="rx-card">On the site|from-outer|page|drawn|loader</p></div>', $flat($html), 'drawn inside a block on the site, it isn\'t a preview');

section('A loader that throws');

// ACF buffers the render: an exception leaves its buffer open, closed here
$level  = ob_get_level();
$thrown = null;
try {
    WpGoBlocks::block('rx-throw');
} catch (RuntimeException $e) {
    $thrown = $e->getMessage();
}
while (ob_get_level() > $level) {
    ob_end_clean();
}

same('rx-throw: thrown on purpose', $thrown, 'the loader\'s exception comes through block()');
same(0, rendering(), 'rendering() is back to 0');
same(false, previewing(), 'previewing() is put back');
same([], hand_offs(), 'the hand-off is dropped');
same('<p class="rx-throw">calm</p>', $flat(WpGoBlocks::block('rx-throw', ['calm' => 'yes'])), 'and the drawing stack is empty: the same block draws again without a loop notice');

$thrown = null;
try {
    acf_rendered_block(['name' => 'acf/rx-throw', 'mode' => 'preview', 'data' => []], '', true, $plain);
} catch (RuntimeException $e) {
    $thrown = $e->getMessage();
}
while (ob_get_level() > $level) {
    ob_end_clean();
}

same('rx-throw: thrown on purpose', $thrown, 'previewed, the exception comes through ACF');
same(false, previewing(), 'and previewing() is false again, not left at the preview\'s true');
same(0, rendering(), 'rendering() is 0');
