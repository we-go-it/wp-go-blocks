<?php

namespace WpGo\Blocks\Core;

/**
 * Per-block CSS and JS, loaded only on pages that actually render the block.
 *
 * Each block folder ships as its own entry (the Vite plugin,
 * `@we-go/wp-go-blocks/vite`), so blocks/feature-tabs/feature-tabs.scss and .js
 * compile to standalone files instead of being folded into the main bundle.
 * Where they were built to is the theme's AssetResolver's answer (boot()'s
 * `assets`); without one, nothing here runs.
 *
 * Both tags go in <head>, so every block asset starts downloading in parallel
 * with the main bundle rather than waiting for the body (`head_priorities`):
 *
 *   priority 15  <script type="module">  before the main bundle, so a block's
 *                                        Alpine.data() runs before Alpine.start()
 *   priority 20  the main bundle          (the theme's own)
 *   priority 25  <link rel="stylesheet">  after the main stylesheet, so block
 *                                        rules can override component rules
 *
 * Module scripts are deferred and execute in document order, which is what makes
 * the ordering above meaningful: the tags all download together, but a block's
 * JS still runs before the main bundle starts Alpine.
 *
 * Every tag carries data-block-asset, so a front end that swaps only part of
 * the page on navigation (barba) can find the incoming page's block assets in
 * the fetched HTML and add the missing ones itself.
 */

/**
 * Every block name the head knows the request renders, innerBlocks included:
 * those parsed out of post_content, plus any that rendered before wp_head.
 */
function blocks_on_page(): array
{
    static $names = null;

    if ($names !== null) {
        return $names;
    }

    // A singular page renders its post's content, so its blocks are known
    // before the head is written. A list renders what its template decides —
    // a title, an excerpt, a card — so parsing every post would load the
    // assets of blocks it never draws: with the buffer, the slots below
    // carry exactly the ones that render instead. Without it, parsing is all
    // there is, and loading too much beats leaving a block unstyled.
    if (is_singular()) {
        $posts = [ get_queried_object() ];
    } else {
        $posts = Config::get('buffer') ? [] : (array) ($GLOBALS['wp_query']->posts ?? []);
    }

    $found = [];

    foreach ($posts as $post) {
        /**
         * Whether the post's content is what the page renders. False for a
         * page that ignores it and draws its blocks with wp_go_render_block(), which
         * declares them itself.
         *
         * @param bool     $parse
         * @param \WP_Post $post
         */
        if (!$post instanceof \WP_Post || !apply_filters('wp_go_blocks/parse_content', true, $post)) {
            continue;
        }

        if ($post->post_content && has_blocks($post->post_content)) {
            collect_block_names(parse_blocks($post->post_content), $found);
        }
    }

    // Blocks a PHP template draws are not in anyone's post_content, so
    // parsing alone cannot find them. render_block() records each block as
    // it renders (declared_blocks()): those rendered after wp_head, nearly
    // all of them, reach the head through the slots below.
    return $names = array_values(array_unique(
        array_merge(array_keys($found), declared_blocks())
    ));
}

/**
 * @param array $blocks Result of parse_blocks().
 * @param array $found  Collected as a set, by reference.
 */
function collect_block_names(array $blocks, array &$found): void
{
    foreach ($blocks as $block) {
        $name = $block['blockName'] ?? null;

        if ($name) {
            $found[$name] = true;
        }

        if (!empty($block['innerBlocks'])) {
            collect_block_names($block['innerBlocks'], $found);
        }
    }
}

/** A set of asset URLs with nothing in it. */
const NO_ASSETS = [ 'css' => [], 'js' => [], 'preload' => [] ];

/**
 * Several sets of asset URLs as one, each URL once, in the order first met.
 *
 * @param array{css?: string[], js?: string[], preload?: string[]} ...$sets
 * @return array{css: string[], js: string[], preload: string[]}
 */
function merge_assets(array ...$sets): array
{
    $assets = NO_ASSETS;

    foreach ($sets as $set) {
        foreach ($assets as $kind => $urls) {
            $assets[$kind] = array_merge($urls, (array) ($set[$kind] ?? []));
        }
    }

    return array_map(fn ($urls) => array_values(array_unique($urls)), $assets);
}

/**
 * Compiled asset URLs for one entry pair: `<base>.scss` and `<base>.js`, both
 * theme-relative and both optional. A block is one (blocks/x/x); so is any
 * other bundle built on its own (wp-go-blocks.json `bundles`), like form/form.
 *
 * Kept per base: the head asks for a block's, and the slots ask again once
 * the page has rendered.
 *
 * @return array{css: string[], js: string[], preload: string[]}
 */
function entry_assets(string $base): array
{
    static $found = [];

    if (isset($found[$base])) {
        return $found[$base];
    }

    $resolver = Config::assets();
    $sets     = [];

    foreach ($resolver ? [ 'scss', 'js' ] : [] as $extension) {
        $source = $base . '.' . $extension;

        if (is_readable(Config::root() . '/' . $source)) {
            $sets[] = $resolver->urls($source);
        }
    }

    return $found[$base] = merge_assets(...$sets);
}

/**
 * Record a bundle that is not a block, for whatever renders it — a form a
 * plugin draws, say. Declared before wp_head, it is printed with the blocks;
 * after, which is the usual case, it goes through the slots below.
 *
 * Only a bundle wp-go-blocks.json lists is built. Any other would load from
 * the dev server and be missing from the manifest, working until the site
 * ships: it is refused here instead, in development too.
 *
 * @param string $base An entry base, as entry_assets() takes it (`form/form`).
 */
function declare_bundle(string $base): void
{
    if (!in_array($base, (array) Config::get('bundles'), true)) {
        trigger_error("declare_bundle(): `$base` is not in wp-go-blocks.json `bundles`, so it is never built", E_USER_WARNING);

        return;
    }

    declared_bundles($base);
}

/**
 * The bundles declare_bundle() recorded. With an argument, adds to them.
 *
 * @return string[]
 */
function declared_bundles(?string $add = null): array
{
    static $bases = [];

    if ($add !== null && !in_array($add, $bases, true)) {
        $bases[] = $add;
    }

    return $bases;
}

/**
 * The entry base of each registered block among `$names`; any other name is
 * skipped.
 *
 * @param string[] $names Block names, `acf/<slug>`.
 * @return string[]
 */
function entry_bases(array $names): array
{
    $registered = registered();
    $blocks     = (string) Config::get('blocks');
    $bases      = [];

    foreach ($names as $name) {
        if (isset($registered[$name])) {
            $slug    = basename($registered[$name]);
            $bases[] = "$blocks/$slug/$slug";
        }
    }

    return $bases;
}

/**
 * entry_assets() for several entries, merged, each URL once.
 *
 * @param string[] $bases
 * @return array{css: string[], js: string[], preload: string[]}
 */
function entries_assets(array $bases): array
{
    return merge_assets(...array_map(__NAMESPACE__ . '\entry_assets', $bases));
}

/**
 * The script tags for a set of assets: the preloads, then the modules.
 *
 * @param array{js: string[], preload: string[]} $assets
 */
function script_tags(array $assets): string
{
    $html = '';

    foreach ($assets['preload'] as $url) {
        $html .= sprintf('<link rel="modulepreload" crossorigin data-block-asset href="%s">' . "\n", esc_url($url));
    }

    foreach ($assets['js'] as $url) {
        $html .= sprintf('<script type="module" crossorigin data-block-asset src="%s"></script>' . "\n", esc_url($url));
    }

    return $html;
}

/**
 * The stylesheet tags for a set of assets.
 *
 * @param array{css: string[]} $assets
 */
function style_tags(array $assets): string
{
    $html = '';

    foreach ($assets['css'] as $url) {
        $html .= sprintf('<link rel="stylesheet" crossorigin data-block-asset href="%s">' . "\n", esc_url($url));
    }

    return $html;
}

/**
 * What the head prints: the blocks known by then, and the bundles declared by
 * then. Worked out once, so it is also the list of what the slots below must
 * not repeat.
 *
 * @return array{css: string[], js: string[], preload: string[]}
 */
function assets_on_page(): array
{
    static $assets = null;

    return $assets ??= entries_assets(array_merge(entry_bases(blocks_on_page()), declared_bundles()));
}

/**
 * Before the main bundle, so Alpine.data() runs before Alpine.start().
 *
 * On `wp_head`.
 */
function print_scripts(): void
{
    echo script_tags(assets_on_page());

    if (Config::get('buffer')) {
        echo JS_SLOT . "\n";
    }
}

/**
 * After the main stylesheet, so a block can override a component.
 *
 * On `wp_head`.
 */
function print_styles(): void
{
    echo style_tags(assets_on_page());

    if (Config::get('buffer')) {
        echo CSS_SLOT . "\n";
    } else {
        add_action('wp_footer', __NAMESPACE__ . '\print_late_styles');
    }
}

/**
 * With boot()'s `buffer` off there are no slots, so what renders after the
 * head would have no CSS at all: its stylesheets go at the foot of the page
 * instead, applied late but applied. Not its scripts: a module there runs
 * after the main bundle's Alpine.start(), too late for the Alpine.data() a
 * block's JS registers. Neither reaches a front end that swaps pages, which
 * reads only the incoming head.
 *
 * On `wp_footer`, from print_styles().
 */
function print_late_styles(): void
{
    echo style_tags(late_assets());
}

/**
 * ---------------------------------------------------------------------------
 * Blocks a template renders are found after the head is already written.
 *
 * `wp_go_render_block()` can be called anywhere in a template, which is long after
 * `wp_head` has run — and the tags cannot simply move to the body, because
 * their position is the point: a block's `Alpine.data()` has to run before the
 * main bundle calls `Alpine.start()`, and a module script placed after it
 * executes after it.
 *
 * So the head keeps the position and gives up the timing. Each print leaves an
 * HTML comment where its late tags belong, the page renders into a buffer, and
 * the comments are replaced once the full set is known — in the head, in the
 * right order, without the template having to announce anything in advance.
 *
 * The buffer degrades safely: a response whose markers never render (a feed,
 * robots.txt, an early exit) passes through untouched, and a singular page's
 * own content has already printed its blocks' assets the ordinary way.
 * ---------------------------------------------------------------------------
 */

const JS_SLOT  = '<!--wg-block-js-->';
const CSS_SLOT = '<!--wg-block-css-->';

/**
 * The assets of every block rendered after the head — `declared_blocks()`
 * holds them, because `render_block()` records each one as it renders — and
 * of every bundle `declared_bundles()` recorded, minus whatever the head
 * already carried.
 *
 * @return array{css:string[], js:string[], preload:string[]}
 */
function late_assets(): array
{
    $printed = assets_on_page();
    $late    = entries_assets(array_merge(
        entry_bases(declared_blocks()),
        declared_bundles()
    ));

    foreach ($late as $kind => $urls) {
        $late[$kind] = array_values(array_diff($urls, $printed[$kind]));
    }

    return $late;
}

/**
 * Put the late tags where their markers are: the head's, the first of each.
 * The same comment typed into a post's content is the post's text, and stays
 * as it is.
 */
function fill_slots(string $html): string
{
    $head_end = stripos($html, '</head>');

    if ($head_end === false) {
        return $html;
    }

    $head = substr($html, 0, $head_end);

    if (!str_contains($head, JS_SLOT) && !str_contains($head, CSS_SLOT)) {
        return $html;
    }

    $late = late_assets();
    $head = fill_slot($head, JS_SLOT, script_tags($late));
    $head = fill_slot($head, CSS_SLOT, style_tags($late));

    return $head . substr($html, $head_end);
}

/**
 * One marker replaced with its tags, the line break after it too: the tags
 * end in their own.
 */
function fill_slot(string $head, string $slot, string $tags): string
{
    $at = strpos($head, $slot);

    if ($at === false) {
        return $head;
    }

    $length = strlen($slot) + (substr($head, $at + strlen($slot), 1) === "\n" ? 1 : 0);

    return substr_replace($head, $tags, $at, $length);
}

/**
 * Buffer the whole response so the slots can be filled.
 *
 * Whatever the response: one that prints no markers (a feed, robots.txt, a
 * JSON answer) comes back from fill_slots() as it went in, and the REST API
 * answers before `template_redirect`. Telling them apart by the request
 * instead would misjudge a page — wp_is_json_request() reads only the Accept
 * header — and leave its markers unfilled, for a page cache to keep.
 *
 * Not flushable, as WordPress's own template enhancement buffer isn't: an
 * ob_flush() halfway down the page would hand fill_slots() the head alone,
 * and fill the slots before the blocks drawn after it. ob_end_flush() and
 * the end of the request still hand it the whole page.
 *
 * On `template_redirect`, unless boot()'s `buffer` is off.
 */
function start_buffer(): void
{
    ob_start(__NAMESPACE__ . '\fill_slots', 0, PHP_OUTPUT_HANDLER_STDFLAGS ^ PHP_OUTPUT_HANDLER_FLUSHABLE);
}
