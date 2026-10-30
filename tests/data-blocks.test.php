<?php

/**
 * Rendering as data, a block: PayloadRenderer as a renderer (extension,
 * globals, render()), a block's payload with and without a `.payload.php`,
 * the variant per post type and the `payload` post type that would collide,
 * `block` cut down, JSON as it's encoded, wp_go_blocks/html given the JSON.
 * And the blocks drawn for a block, outside any page: by its loader or its
 * `.payload.php` they're nodes in its payload (one, several, inside markup,
 * the content's marker left out); handed in its `context` option outside
 * any render they're its JSON, a string.
 */

require __DIR__ . '/fixtures/data-blocks.php';

use WpGo\Blocks\Core\Config;
use WpGo\Blocks\Renderer\PayloadRenderer;
use WpGo\Blocks\Renderer\TimberRenderer;

// Timber's globals built first, for the site's renderer: the payload
// renderer keeps a global context of its own
$timber_context = WpGoBlocks::context();
$renderer = new PayloadRenderer();
$site = WpGoBlocks::use_renderer($renderer);

$decode = fn (string $json) => json_decode($json, true);

section('As a renderer');

same('payload.php', $renderer->extension(), 'a block\'s template is <slug>.payload.php');
same([], $renderer->globals(), 'no engine globals');
$renderer->expose('dx_fn', fn () => 'x');
check('expose() does nothing: no template calls back into PHP', true);
check('the site\'s renderer was a TimberRenderer, now swapped', $site instanceof TimberRenderer && WpGoBlocks::renderer() === $renderer);

$json = $renderer->render(DX_FIXTURES . '/data-blocks/dx-card/none.payload.php', ['title' => 'A/B é &amp;', 'gone' => null, 'block' => ['name' => 'acf/x', 'id' => 'block_1', 'anchor' => 'top', 'align' => 'wide', 'className' => 'c', 'data' => ['secret' => 1], 'path' => '/srv/blocks/x', 'supports' => []]]);
same('{"title":"A/B é &","block":{"name":"acf/x","id":"block_1","anchor":"top","align":"wide","className":"c"}}', $json, 'without the file the context is the payload: made data, `block` cut to name, id, anchor, align, className, slashes and Unicode unescaped');

section('A block\'s payload, drawn outside any page');

$card = $decode(WpGoBlocks::block('dx-card', ['title' => 'Hi &amp; you', 'body' => 'Para', 'count' => '3']));
same('Hi & you', $card['title'] ?? null, 'its fields are in it, text decoded');
same("<p>Para</p>\n", $card['body'] ?? null, 'a WYSIWYG is HTML, as ACF formats it');
same(3, $card['count'] ?? null, 'a number is a number');
same(false, $card['is_preview'] ?? null, 'is_preview is in it');
same(['name', 'align', 'id'], array_keys($card['block'] ?? []), '`block` holds what identifies it, nothing of ACF\'s data, path or supports');
same('acf/dx-card', $card['block']['name'] ?? null, 'its name');
check('none of Timber\'s globals in it (site, theme), though they were built for the site\'s renderer', isset($timber_context['site']) && !array_intersect_key($card, ['site' => 1, 'theme' => 1, 'user' => 1, 'request' => 1]));
same([], WpGoBlocks::context(), 'the payload renderer\'s own global context is empty (nothing on wp_go_blocks/context here)');

$GLOBALS['dx_shaped_runs'] = 0;
$shaped = $decode(WpGoBlocks::block('dx-shaped', ['title' => 'Shaped']));
same(['heading' => 'Shaped', 'block' => $shaped['block'] ?? null], $shaped, 'a .payload.php replaces the whole context: what it returns, nulls left out');
same(1, $GLOBALS['dx_shaped_runs'], 'it is required on every render, once');
WpGoBlocks::block('dx-shaped', ['title' => 'Again']);
same(2, $GLOBALS['dx_shaped_runs'], 'and again on the next');

$with_post = $decode(WpGoBlocks::block('dx-shaped', [], ['context' => ['dx_post' => $page = dx_post(['post_name' => 'dx-shaped-page', 'post_title' => 'Shaped page'])]]));
same(['id' => $page, 'type' => 'page', 'title' => 'Shaped page', 'url' => get_permalink($page)], $with_post['post'] ?? null, 'what it returns is made data: a post is { id, type, title, url }');

same('[]', WpGoBlocks::block('dx-scalar', ['title' => 'S']), 'a .payload.php that returns anything but an array gives an empty payload');

register_post_type('dxtype', ['public' => true]);
$event = dx_post(['post_type' => 'dxtype', 'post_name' => 'dx-event']);
same(['heading' => 'On an event', 'variant' => 'dxtype'], $decode(WpGoBlocks::block('dx-shaped', ['title' => 'On an event'], ['post_id' => $event])), 'on a dxtype post, <slug>.dxtype.payload.php instead');
same('On a page', $decode(WpGoBlocks::block('dx-shaped', ['title' => 'On a page'], ['post_id' => $page]))['heading'] ?? null, 'on a page, the block\'s own');

// A post type called `payload`: its loader variant would be
// dx-shaped.payload.php, the block's own payload file. Required as a
// loader too, it would run twice, its payload merged into the context.
register_post_type('payload', ['public' => true]);
$typed = dx_post(['post_type' => 'payload', 'post_name' => 'dx-payload-type']);
$GLOBALS['dx_shaped_runs'] = 0;
$collide = $decode(WpGoBlocks::block('dx-shaped', ['title' => 'Typed'], ['post_id' => $typed]));
same(1, $GLOBALS['dx_shaped_runs'], 'for a `payload` post type, <slug>.payload.php is the template only, not a loader too');
same('Typed', $collide['heading'] ?? null, 'and the payload is the block\'s own');

section('wp_go_blocks/html gets the JSON');

$seen = null;
add_filter('wp_go_blocks/html', $html_filter = function ($html, $slug, $context, $is_preview) use (&$seen) {
    $seen = [$html, $slug, $context['title'] ?? null, $is_preview];

    return $html;
}, 10, 4);
$out = WpGoBlocks::block('dx-card', ['title' => 'Filtered']);
remove_filter('wp_go_blocks/html', $html_filter, 10);
same($out, $seen[0] ?? null, 'the filter\'s $html is the payload\'s JSON');
same(['dx-card', 'Filtered', false], array_slice((array) $seen, 1), 'with the slug, the context and is_preview');

section('Blocks drawn for a block: nodes in its payload');

$GLOBALS['dx_host_loader'] = fn (array $context) => [
    'intro' => WpGoBlocks::block('dx-card', ['title' => 'Nested']),
    'spaced' => "\n  " . WpGoBlocks::block('dx-card', ['title' => 'Spaced']) . "\n",
    'pair' => WpGoBlocks::block('dx-card', ['title' => 'P1']) . "\n" . WpGoBlocks::block('dx-card', ['title' => 'P2']),
    'wrapped' => '<div class="w">' . WpGoBlocks::block('dx-card', ['title' => 'Wrapped']) . '</div>',
    'deep' => ['list' => [WpGoBlocks::block('dx-card', ['title' => 'Deep'])]],
    'columns' => WpGoBlocks::block('dx-columns', [], ['content' => WpGoBlocks::block('dx-card', ['title' => 'Inner'])]),
];
$host = $decode(WpGoBlocks::block('dx-host', ['title' => 'Host']));
unset($GLOBALS['dx_host_loader']);

$node = $host['intro'] ?? null;
same(['name', 'payload', 'template'], array_keys((array) $node), 'one its loader draws is a node where the value was: { name, payload, template }');
same(['acf/dx-card', 'Nested', true], [$node['name'] ?? null, $node['payload']['title'] ?? null, $node['template'] ?? null], 'the drawn block\'s name, its payload, template true');
same('Spaced', $host['spaced']['payload']['title'] ?? null, 'whitespace around the marker aside, still a node');
same(['P1', 'P2'], array_map(fn ($n) => $n['payload']['title'] ?? null, (array) ($host['pair'] ?? [])), 'several markers and nothing else: a list of nodes');
check('a block inside markup the loader composed stays markup, the block in it its payload\'s JSON', is_string($host['wrapped'] ?? null) && str_starts_with($host['wrapped'], '<div class="w">{"title":"Wrapped"') && str_ends_with($host['wrapped'], '}</div>'));
same('Deep', $host['deep']['list'][0]['payload']['title'] ?? null, 'at any depth of the payload');
same(['acf/dx-columns', 'Inner'], [$host['columns']['name'] ?? null, $host['columns']['inner_blocks'][0]['payload']['title'] ?? null], 'a drawn block\'s `content` option is its node\'s inner_blocks');
check('no marker is left anywhere in the payload', !str_contains((string) wp_json_encode($host), 'wg-drawn'));
same(0, \WpGo\Blocks\Core\rendering(), 'the count of blocks rendering is back to 0');

$GLOBALS['dx_host_payload'] = fn (array $context) => ['made' => WpGoBlocks::block('dx-card', ['title' => 'From payload'])];
$from_file = $decode(WpGoBlocks::block('dx-host', ['title' => 'Host']));
unset($GLOBALS['dx_host_payload']);
same('From payload', $from_file['made']['payload']['title'] ?? null, 'one its .payload.php draws is a node too');

// WpGoBlocks::content() outside a page is the post's content as HTML; the
// content's marker, inside a page, is left out of a payload. Here, a marker
// put in as a value, as a page hands one in (data-page.test.php has it
// from a page).
$GLOBALS['dx_host_loader'] = fn () => ['c' => '<!--wg-drawn:content-->', 'mix' => '<!--wg-drawn:content-->' . WpGoBlocks::block('dx-card', ['title' => 'With content'])];
$content_marker = $decode(WpGoBlocks::block('dx-host', ['title' => 'Host']));
unset($GLOBALS['dx_host_loader']);
check('a value of nothing but the content\'s marker is absent: the content isn\'t placed in a payload', is_array($content_marker) && !array_key_exists('c', $content_marker));
same('With content', $content_marker['mix']['payload']['title'] ?? null, 'beside a block, the content\'s marker is dropped and the block is its node');

$GLOBALS['dx_host_loader'] = fn () => ['stray' => '<!--wg-drawn:999-->'];
$stray = $decode(WpGoBlocks::block('dx-host', ['title' => 'Host']));
unset($GLOBALS['dx_host_loader']);
check('a marker no block was kept for is dropped', is_array($stray) && !array_key_exists('stray', $stray));

section('Handed in its `context` option, outside any page and any render');

$handed = $decode(WpGoBlocks::block('dx-host', ['title' => 'Host'], ['context' => ['cta' => WpGoBlocks::block('dx-card', ['title' => 'CTA'])]]));
check('drawn before the host renders, outside a page, the block is its JSON: a string in the payload', is_string($handed['cta'] ?? null) && ($decode($handed['cta'])['title'] ?? null) === 'CTA');
check('outside a page and a render, block() returns the payload\'s JSON, not a marker', str_starts_with(WpGoBlocks::block('dx-card', ['title' => 'Alone']), '{"title":"Alone"'));

section('The editor\'s block preview');

// What WP-Go's data modes rely on: ACF renders the block, the payload
// renderer swapped in, and the block's markup is its JSON.
$preview = (string) acf_rendered_block(['name' => 'acf/dx-card', 'mode' => 'preview', 'data' => ['title' => 'Previewed']], '', true, 0);
same('Previewed', $decode(trim($preview))['title'] ?? null, 'an ACF preview of a block is its payload\'s JSON');
same(true, $decode(trim($preview))['is_preview'] ?? null, 'with is_preview true');

WpGoBlocks::use_renderer($site);
same($site, Config::renderer(), 'the site\'s renderer is back');
