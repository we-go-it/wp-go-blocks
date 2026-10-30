<?php

/**
 * Rendering as data, a page (PayloadRenderer::output(), capture()): with a
 * body, the view (fixtures/data-views/) sets the order — what its base draws
 * around the body isn't the page's, the content goes where the view prints
 * the page's own post's content, once, `content_at` where it starts, on the
 * page or on the drawn block holding it; without a body, the PHP template's
 * calls set it. A block drawn for another block's context, or inside a
 * render, is that block's node, not the page's. And the rest of the page:
 * `template`, `context`, the post behind a password, use_content(),
 * capture(), the page printed as the site's renderer, collecting().
 */

require __DIR__ . '/fixtures/data-blocks.php';

use WpGo\Blocks\Core\Config;
use WpGo\Blocks\Renderer\PayloadRenderer;

add_filter('timber/locations', fn ($locations) => array_merge((array) $locations, [[DX_FIXTURES . '/data-views']]));

$timber = WpGoBlocks::renderer();

$content = dx_paragraph('First paragraph') . "\n\n" . dx_block('dx-card', ['title' => 'In content']);
$page = dx_post(['post_name' => 'dx-page', 'post_title' => 'DX page', 'post_content' => $content]);

/**
 * A page built as the content API builds it: the payload renderer in place,
 * the post the global one, `$template` run inside capture(). The site's
 * renderer given to it when `$site` is: then the view is its body.
 */
function dx_capture(int $post_id, callable $template, mixed $site = null): ?array
{
    global $wp_query, $wp_the_query;

    $renderer = new PayloadRenderer($site);
    $previous = WpGoBlocks::use_renderer($renderer);
    $saved = [$wp_query, $wp_the_query, $GLOBALS['post'] ?? null];

    // the post as the main query, as ContentApi::render() sets it: Timber's
    // loops put it back with wp_reset_postdata()
    $wp_query = $wp_the_query = new WP_Query(['page_id' => $post_id, 'post_type' => 'page', 'post_status' => get_post_status($post_id)]);
    $GLOBALS['post'] = get_post($post_id);
    setup_postdata($GLOBALS['post']);

    try {
        $built = $renderer->capture($template);
    } finally {
        WpGoBlocks::use_renderer($previous);
        [$wp_query, $wp_the_query, $GLOBALS['post']] = $saved;
    }

    // as a client reads it
    return $built === null ? null : json_decode((string) wp_json_encode($built), true);
}

/** The page rendered with a view of the fixtures, given its context. */
function dx_view(int $post_id, string $view, array $context = []): ?array
{
    return dx_capture($post_id, fn () => WpGoBlocks::render([$view], WpGoBlocks::context(['post' => WpGoBlocks::get_post($post_id)] + $context)), WpGoBlocks::renderer());
}

section('With a body: the view sets the order');

$built = dx_view($page, 'page-dx-view.twig');
same(['template', 'context', 'blocks', 'content_at'], array_keys((array) $built), 'a page is { template, context, blocks, content_at }');
same(['acf/dx-card:Intro+t', 'core/paragraph', 'acf/dx-card:In content', 'acf/dx-card:Outro+t'], dx_names($built['blocks'] ?? []), 'the view\'s blocks and the content\'s, in the order the view prints them');
same(1, $built['content_at'] ?? null, 'content_at is where the content\'s blocks start');
check('what the base draws around the body (a header) isn\'t the page\'s', !in_array('acf/dx-card:Header+t', dx_names($built['blocks'] ?? []), true));
same(1, count(array_filter(dx_names($built['blocks'] ?? []), fn ($name) => $name === 'core/paragraph')), 'the content printed twice is placed once, at the first');
same('page-dx-view.twig', $built['template'] ?? null, 'template is the view the theme named');
same(['post' => ['id' => $page, 'type' => 'page', 'title' => 'DX page', 'url' => get_permalink($page)]], $built['context'] ?? null, 'context is what the template gave render(), as data');

$sticky = dx_view($page, 'page-dx-sticky.twig');
same(['acf/dx-columns+t'], dx_names($sticky['blocks'] ?? []), 'a block drawn with a `content` option is one block on the page');
same(['acf/dx-card:Lead+t', 'core/paragraph', 'acf/dx-card:In content'], dx_names($sticky['blocks'][0]['inner_blocks'] ?? []), 'its `content` is its inner_blocks: what it drew, then the post\'s content');
same(1, $sticky['blocks'][0]['content_at'] ?? null, 'content_at is on that block');
check('and not on the page', !array_key_exists('content_at', (array) $sticky));
same('right', $sticky['blocks'][0]['payload']['side'] ?? null, 'the drawn block keeps its payload');

$fixed = dx_view($page, 'page-dx-fixed.twig');
same(['acf/dx-card:Fixed+t'], dx_names($fixed['blocks'] ?? []), 'a view that never prints the content has none of the content\'s blocks');
check('and no content_at', !array_key_exists('content_at', (array) $fixed));

$other = dx_post(['post_name' => 'dx-other', 'post_content' => dx_paragraph('Other post')]);
$related = dx_view($page, 'page-dx-related.twig', ['others' => WpGoBlocks::get_posts(['post_type' => 'page', 'post__in' => [$other], 'post_status' => 'publish'])]);
same(['acf/dx-card:Mid+t', 'core/paragraph', 'acf/dx-card:In content'], dx_names($related['blocks'] ?? []), 'other posts\' content, printed in a loop, is markup thrown away: the page\'s own is placed');
same(1, $related['content_at'] ?? null, 'where the page prints its own');
check('none of the other post\'s content is in the page', !str_contains((string) wp_json_encode($related), 'Other post'));

// Timber's post.content doesn't set the global post up outside a loop: the
// marker filter then takes the other post's content for the page's.
$single = dx_view($page, 'page-dx-other.twig', ['other' => WpGoBlocks::get_post($other)]);
same(['acf/dx-card:Mid+t', 'core/paragraph', 'acf/dx-card:In content'], dx_names($single['blocks'] ?? []), 'another post\'s content printed outside a loop isn\'t taken for the page\'s content');

$context_view = dx_view($page, 'page-dx-context.twig');
same(['acf/dx-host:Outer+t'], dx_names($context_view['blocks'] ?? []), 'a block drawn for another\'s context, and printed by the view too, isn\'t on the page: only the block it was handed to');
$outer = $context_view['blocks'][0]['payload'] ?? [];
same(['acf/dx-card', 'CTA', true], [$outer['cta']['name'] ?? null, $outer['cta']['payload']['title'] ?? null, $outer['cta']['template'] ?? null], 'it is a node in that block\'s payload');
// post.content goes through the_content's other filters after the marker
// (wpautop wraps it in a paragraph), so the value isn't the marker alone
check('the content\'s marker in a context value is left out of the payload, the post.content around it too', !array_key_exists('c', $outer), 'c: ' . wp_json_encode($outer['c'] ?? null));
check('a drawn block in markup composed for the context is that markup, the block as its JSON', str_starts_with((string) ($outer['wrapped'] ?? ''), '<p>{"title":"Wrapped"'));
check('the content handed in a context value isn\'t placed on the page', !array_key_exists('content_at', (array) $context_view) && !in_array('core/paragraph', dx_names($context_view['blocks'] ?? []), true));
check('no marker left in the page', !str_contains((string) wp_json_encode($context_view), 'wg-drawn'));

// Blocks the PHP template drew before render(): markers, in the body where
// the view prints them, nowhere if it doesn't
$drawn = dx_capture($page, function () use ($page) {
    $top = WpGoBlocks::block('dx-card', ['title' => 'Top']);
    $unused = WpGoBlocks::block('dx-card', ['title' => 'Unused']);
    WpGoBlocks::render(['page-dx-drawn.twig'], WpGoBlocks::context(['post' => WpGoBlocks::get_post($page), 'top' => $top]));
}, $timber);
same(['acf/dx-card:Top+t', 'core/paragraph', 'acf/dx-card:In content'], dx_names($drawn['blocks'] ?? []), 'a block the PHP template drew is where the view prints it; one the view doesn\'t print is dropped');

$GLOBALS['dx_host_loader'] = fn () => ['inside' => WpGoBlocks::block('dx-card', ['title' => 'Inside'])];
$GLOBALS['dx_host_payload'] = fn () => ['kept' => 'nothing drawn'];
$dropped = dx_capture($page, function () use ($page) {
    WpGoBlocks::block('dx-host', ['title' => 'Host']);
    WpGoBlocks::render(['page-dx-fixed.twig'], WpGoBlocks::context(['post' => WpGoBlocks::get_post($page)]));
}, $timber);
unset($GLOBALS['dx_host_loader'], $GLOBALS['dx_host_payload']);
same(['acf/dx-card:Fixed+t'], dx_names($dropped['blocks'] ?? []), 'a block drawn inside another\'s render is never the page\'s, even when that block\'s payload leaves it out');

$nobody = dx_capture($page, function () use ($page) {
    WpGoBlocks::block('dx-card', ['title' => 'From PHP']);
    WpGoBlocks::render(['page-dx-nobody.twig'], WpGoBlocks::context(['post' => WpGoBlocks::get_post($page)]));
}, $timber);
same(['acf/dx-card:From PHP+t', 'core/paragraph', 'acf/dx-card:In content'], dx_names($nobody['blocks'] ?? []), 'a view with no content block gives no body: the PHP template\'s calls set the order');

check('the marker filter on the_content is gone after the body', !has_filter('the_content', 'WpGo\Blocks\Renderer\PayloadRenderer::body') && dx_the_content_markers() === 0);

section('Without a body: the PHP template sets the order');

$plain = dx_capture($page, function () use ($page) {
    WpGoBlocks::block('dx-card', ['title' => 'Intro']);
    WpGoBlocks::block('dx-columns', ['side' => 'left'], ['content' => WpGoBlocks::content()]);
    WpGoBlocks::render(['single-dx.twig'], ['post' => get_post($page)]);
});
same(['acf/dx-card:Intro+t', 'acf/dx-columns+t'], dx_names($plain['blocks'] ?? []), 'the blocks in the order the template draws them');
same(['core/paragraph', 'acf/dx-card:In content'], dx_names($plain['blocks'][1]['inner_blocks'] ?? []), 'WpGoBlocks::content() as a block\'s content puts the content\'s blocks there');
same(0, $plain['blocks'][1]['content_at'] ?? null, 'content_at on that block');
check('and not on the page', !array_key_exists('content_at', (array) $plain));

$after = dx_capture($page, function () use ($page) {
    WpGoBlocks::block('dx-card', ['title' => 'Only']);
    WpGoBlocks::render(['single-dx.twig'], ['post' => get_post($page)]);
});
same(['acf/dx-card:Only+t', 'core/paragraph', 'acf/dx-card:In content'], dx_names($after['blocks'] ?? []), 'without WpGoBlocks::content(), the content comes after the drawn blocks');
same(1, $after['content_at'] ?? null, 'content_at after them');

$none = dx_capture($page, fn () => WpGoBlocks::render(['single-dx.twig'], ['post' => get_post($page)]));
same(['core/paragraph', 'acf/dx-card:In content'], dx_names($none['blocks'] ?? []), 'with no block drawn, the blocks are the content\'s');
same(0, $none['content_at'] ?? null, 'content_at 0');

$inner = dx_capture($page, function () use ($page) {
    WpGoBlocks::block('dx-columns', [], ['content' => WpGoBlocks::block('dx-card', ['title' => 'Inner']) . WpGoBlocks::content()]);
    WpGoBlocks::render(['single-dx.twig'], ['post' => get_post($page)]);
});
same(['acf/dx-columns+t'], dx_names($inner['blocks'] ?? []), 'a block drawn as another\'s content isn\'t on the page itself');
same(['acf/dx-card:Inner+t', 'core/paragraph', 'acf/dx-card:In content'], dx_names($inner['blocks'][0]['inner_blocks'] ?? []), 'it is in that block\'s inner_blocks, before the content');
same(1, $inner['blocks'][0]['content_at'] ?? null, 'content_at counted on that level');

$twice = dx_capture($page, function () use ($page) {
    WpGoBlocks::block('dx-columns', ['side' => 'a'], ['content' => WpGoBlocks::content()]);
    WpGoBlocks::block('dx-columns', ['side' => 'b'], ['content' => WpGoBlocks::content()]);
    WpGoBlocks::render(['single-dx.twig'], ['post' => get_post($page)]);
});
same([2, 0], [count($twice['blocks'][0]['inner_blocks'] ?? []), count($twice['blocks'][1]['inner_blocks'] ?? [])], 'the content given to two blocks is placed once, in the first');
check('the second block has no content_at', !array_key_exists('content_at', $twice['blocks'][1] ?? []));

$handed = dx_capture($page, function () use ($page) {
    WpGoBlocks::block('dx-host', ['title' => 'Outer'], ['context' => ['cta' => WpGoBlocks::block('dx-card', ['title' => 'CTA']), 'c' => WpGoBlocks::content()]]);
    WpGoBlocks::render(['single-dx.twig'], ['post' => get_post($page)]);
});
same(['acf/dx-host:Outer+t', 'core/paragraph', 'acf/dx-card:In content'], dx_names($handed['blocks'] ?? []), 'a block handed in another\'s context isn\'t on the page; the content held in a payload still comes after the page\'s blocks');
same('CTA', $handed['blocks'][0]['payload']['cta']['payload']['title'] ?? null, 'it is a node in that block\'s payload');
check('the content\'s marker is left out of the payload', !array_key_exists('c', $handed['blocks'][0]['payload'] ?? []));

$GLOBALS['dx_host_loader'] = fn () => ['inside' => WpGoBlocks::block('dx-card', ['title' => 'Inside'])];
$loader = dx_capture($page, function () use ($page) {
    WpGoBlocks::block('dx-host', ['title' => 'Host']);
    WpGoBlocks::render(['single-dx.twig'], ['post' => get_post($page)]);
});
unset($GLOBALS['dx_host_loader']);
same(['acf/dx-host:Host+t', 'core/paragraph', 'acf/dx-card:In content'], dx_names($loader['blocks'] ?? []), 'a block a loader draws inside a page is not listed at the top level');
same('Inside', $loader['blocks'][0]['payload']['inside']['payload']['title'] ?? null, 'it is in its host\'s payload');

section('The template\'s name, the context, the post');

$named = fn (array|string $views) => dx_capture($page, fn () => WpGoBlocks::render($views, ['post' => get_post($page)]))['template'] ?? null;
same('page-page.twig', $named(['page-.twig', 'page-page.twig', 'page.twig']), 'a name with an empty part (an auto-draft\'s page-.twig) is skipped');
same('page-.twig', $named(['page-.twig']), 'the first anyway when every name has one');
same('', $named([]), 'no names, an empty template');
same('only.twig', $named('only.twig'), 'one name given as a string');

$empty = dx_capture($page, fn () => WpGoBlocks::render(['x.twig'], []));
same([], $empty['context'] ?? null, 'an empty context is an object, {}');
$raw = (new PayloadRenderer());
$raw_built = $raw->capture(fn () => $raw->output(['x.twig'], []));
check('{} as a stdClass, not []', ($raw_built['context'] ?? null) instanceof stdClass);

$with_nulls = dx_capture($page, fn () => WpGoBlocks::render(['x.twig'], ['post' => WpGoBlocks::get_post($page), 'none' => null, 'when' => new DateTimeImmutable('2026-10-17T00:00:00+00:00')]));
same(['post' => ['id' => $page, 'type' => 'page', 'title' => 'DX page', 'url' => get_permalink($page)], 'when' => '2026-10-17T00:00:00+00:00'], $with_nulls['context'] ?? null, 'the context made data, a Timber post among it, nulls left out');

// No `post` in the context: the entities' current post, the main query's
global $wp_query, $wp_the_query;
$saved_query = [$wp_query, $wp_the_query];
$wp_query = $wp_the_query = new WP_Query(['page_id' => $page, 'post_type' => 'page']);
$queried = dx_capture($page, fn () => WpGoBlocks::render(['x.twig'], []));
[$wp_query, $wp_the_query] = $saved_query;
same(['core/paragraph', 'acf/dx-card:In content'], dx_names($queried['blocks'] ?? []), 'without `post` in the context, the queried post\'s content');

// No post anywhere: neither in the context nor queried (WP-CLI's main query)
$alone = new PayloadRenderer();
$previous = WpGoBlocks::use_renderer($alone);
$saved_post = $GLOBALS['post'] ?? null;
$GLOBALS['post'] = null;
$nopost = $alone->capture(function () {
    WpGoBlocks::block('dx-card', ['title' => 'Alone']);
    WpGoBlocks::render(['x.twig'], []);
});
$GLOBALS['post'] = $saved_post;
WpGoBlocks::use_renderer($previous);
same(['acf/dx-card:Alone+t'], dx_names(json_decode((string) wp_json_encode($nopost['blocks'] ?? []), true)), 'with no post at all, the drawn blocks and nothing else');

section('A post behind a password, the editor\'s content');

$locked = dx_post(['post_name' => 'dx-locked', 'post_password' => 'secret', 'post_content' => dx_paragraph('Locked words')]);
$locked_page = dx_capture($locked, function () use ($locked) {
    WpGoBlocks::block('dx-card', ['title' => 'Around']);
    WpGoBlocks::render(['x.twig'], ['post' => get_post($locked)]);
});
same(['acf/dx-card:Around+t'], dx_names($locked_page['blocks'] ?? []), 'behind a password, no content\'s blocks: the rest of the page is there');
check('none of its content leaks', !str_contains((string) wp_json_encode($locked_page), 'Locked words'));

$stored = get_post($locked)->post_content;
$renderer = new PayloadRenderer();
same($renderer, $renderer->use_content($locked, dx_paragraph('Editor words')), 'use_content() returns the renderer');
$previous = WpGoBlocks::use_renderer($renderer);
$edited = $renderer->capture(fn () => $renderer->output(['x.twig'], ['post' => get_post($locked)]));
WpGoBlocks::use_renderer($previous);
check('with the editor\'s content (use_content()), the content\'s blocks even behind a password', str_contains((string) wp_json_encode($edited), 'Editor words'));
same($stored, get_post($locked)->post_content, 'nothing is stored');

section('capture()');

$renderer = new PayloadRenderer();
same(null, $renderer->capture(fn () => null), 'a template that renders no page gives null');
$previous = WpGoBlocks::use_renderer($renderer);
try {
    $renderer->capture(function () {
        throw new RuntimeException('dx');
    });
} catch (RuntimeException $e) {
}
check('a template that throws leaves capture() off: collecting no more', !$renderer->collecting());
same(null, $renderer->content(), 'content() is null when not collecting');
$fresh = $renderer->capture(function () use ($renderer, $page) {
    same('<!--wg-drawn:content-->', $renderer->content(), 'content() is the content\'s marker while collecting');
    check('collecting() inside capture()', $renderer->collecting());
    check('block() returns a marker while collecting', str_starts_with(WpGoBlocks::block('dx-card', ['title' => 'M']), '<!--wg-drawn:'));
    $renderer->output(['x.twig'], ['post' => get_post($page)]);
});
same(['acf/dx-card:M+t', 'core/paragraph', 'acf/dx-card:In content'], dx_names(json_decode((string) wp_json_encode($fresh['blocks'] ?? []), true)), 'blocks drawn in an earlier capture aren\'t in the next: each starts empty');
WpGoBlocks::use_renderer($previous);

section('The payload renderer as the site\'s renderer');

// A page request: after template_redirect, not REST, not AJAX. WP-CLI has
// none; the action counted as done is what collecting() reads.
global $wp_actions;
$was_redirect = $wp_actions['template_redirect'] ?? null;
$wp_actions['template_redirect'] = 1;

$site_renderer = new PayloadRenderer();
$previous = WpGoBlocks::use_renderer($site_renderer);
$GLOBALS['post'] = get_post($page);

check('as the site\'s renderer, after template_redirect, it collects', $site_renderer->collecting());
$marker = WpGoBlocks::block('dx-card', ['title' => 'Site']);
check('block() returns a marker on a page request', str_starts_with($marker, '<!--wg-drawn:'));
add_filter('wp_doing_ajax', '__return_true');
check('not in an AJAX request', !$site_renderer->collecting());
remove_filter('wp_doing_ajax', '__return_true');
$other_renderer = new PayloadRenderer();
check('a payload renderer that isn\'t the site\'s doesn\'t collect', !$other_renderer->collecting());

ob_start();
WpGoBlocks::render(['single-dx.twig'], ['post' => get_post($page)]);
$printed = json_decode((string) ob_get_clean(), true);
same(['acf/dx-card:Site+t', 'core/paragraph', 'acf/dx-card:In content'], dx_names($printed['blocks'] ?? []), 'output() prints the page as JSON, its blocks in the PHP template\'s order');
same('single-dx.twig', $printed['template'] ?? null, 'with its template');
check('once printed, it collects no more', !$site_renderer->collecting());
check('a block drawn after it is its JSON', str_starts_with(WpGoBlocks::block('dx-card', ['title' => 'Late']), '{"title":"Late"'));

WpGoBlocks::use_renderer($previous);
if ($was_redirect === null) {
    unset($wp_actions['template_redirect']);
} else {
    $wp_actions['template_redirect'] = $was_redirect;
}

/** How many filters on the_content sit at PHP_INT_MIN: the body's marker, if left. */
function dx_the_content_markers(): int
{
    return count($GLOBALS['wp_filter']['the_content']->callbacks[PHP_INT_MIN] ?? []);
}
