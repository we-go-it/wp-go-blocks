<?php

namespace WpGo\Blocks\Core;

/**
 * Rendering a block.json block, with the Renderer boot() was given
 * (TimberRenderer for Twig).
 *
 * Every block.json names the shared callback below, through the facade:
 *
 *   "acf": { "mode": "preview", "renderCallback": "WpGoBlocks::render_block" }
 *
 * The callback renders <folder>/<folder>.<extension> (`.twig` with Timber) with
 * every ACF field already in
 * the context, so a block only needs its own PHP when the template needs data
 * that is not an ACF field. See run_loader().
 *
 * Where the context comes from, in the order it is merged, each over the one
 * before:
 *
 *   - the renderer's globals         global_context(), below
 *   - the block's fields             fields-read.php
 *   - what the template handed in    block()'s `context` (template.php)
 *   - `block` and `is_preview`       ACF's block array (`drawn` true when block()
 *                                    drew it), and whether in the editor
 *   - the block's own loader         run_loader(), below
 *   - its post type's loader         <slug>.<post type>.php, see variant()
 */

/**
 * Shared render callback for every block.json block.
 *
 * ACF calls acf_setup_meta() before this runs, so ACF reads this block's
 * values. $block['path'] is the block folder (set by ACF from the
 * block.json location).
 */
function render_block($block, $content = '', $is_preview = false, $post_id = 0): void
{
    $renderer = Config::renderer();

    if (!$renderer) {
        return;
    }

    $slug = basename(wp_normalize_path($block['path']));

    // Every block that renders is recorded, placed or drawn: the head parsed
    // only the queried post's content, and this may be another's (a page
    // shown in place of a 404, a post rendered inside another) or no post's
    // (a template's). assets.php adds what the head missed once the page has
    // rendered. By its name, as registered() keys the blocks it looks up.
    declared_blocks((string) ($block['name'] ?? 'acf/' . $slug));

    // What block() handed this render, if it drew the block: found by the
    // token in its attributes, and only there. The attribute itself is never
    // read as values: a post's content, or an editor's request, can set it.
    $token  = $block['wp_go_context'] ?? null;
    $handed = is_string($token) ? (hand_offs()[ $token ] ?? null) : null;

    // ACF copies every saved attribute into $block, `drawn` too: both go
    unset($block['wp_go_context'], $block['drawn']);

    // Drawn by block(), it says so, to a template, a loader and a
    // `wp_go_blocks/html` filter: its markup goes to the block or the page
    // that drew it, not to the editor, even inside a preview. Set from the
    // hand-off alone, so a post's content cannot claim it.
    if ($handed !== null) {
        $block['drawn'] = true;
    }

    // Drawn inside a block in the editor's preview, it is in the preview too.
    $is_preview = (bool) $is_preview || !empty($handed['is_preview']);

    // The post the block renders for: ACF's, which in the editor is the post
    // being edited, else the current one.
    $post_id = is_numeric($post_id) && (int) $post_id > 0 ? (int) $post_id : (int) get_the_ID();
    $variant = variant($block['path'], $slug, $post_id, $renderer->extension());

    // Not get_fields(): a field its own conditional logic hides is not part of
    // this block, and neither is an empty one. Both are dropped before the
    // context is built, so a template never receives a property that is not
    // there. See fields().
    /**
     * A block's fields as a whole, before the context is built — for what one
     * group's value cannot do alone (`wp_go_blocks/group_value`): reading its
     * siblings, or adding a field that was never stored.
     *
     * @param array<string,mixed> $fields The block's visible, non-empty fields.
     * @param string              $slug   The block's slug.
     */
    $fields = apply_filters('wp_go_blocks/fields', fields($block), $slug);

    $context = array_merge(
        // the global context: the renderer's (Timber's `site`, `theme`, `post`…),
        // through `wp_go_blocks/context`. Its `post` is the request's, not
        // necessarily the one this block renders for: that is $post_id
        global_context(),
        $fields,
        // block(…, { context }): what a template drawing the block composed
        $handed['context'] ?? [],
        [
            'block'      => $block,
            'is_preview' => $is_preview,
        ]
    );

    // What this block draws in turn (block()) is drawn in the same preview,
    // or out of it; the outer block's setting is back once it is done.
    $was_previewing = previewing($is_preview);

    // and is drawn inside this block: as data, it is this block's payload's
    // (PayloadRenderer::drawn())
    rendering(1);

    try {
        $context = run_loader($block['path'] . '/' . $slug . '.php', $context, $fields, $block, $is_preview, $post_id);

        if ($variant['php']) {
            $context = run_loader($variant['php'], $context, $fields, $block, $is_preview, $post_id);
        }

        $template = $variant['template'] ?? $block['path'] . '/' . $slug . '.' . $renderer->extension();
        $html     = $renderer->render($template, $context);
    } finally {
        rendering(-1);
        previewing($was_previewing);
    }

    /**
     * A block's markup, before it is printed — to wrap it, or to add to it
     * (a dev tool's dump of the context, say).
     *
     * A block a template drew (block()) has `$context['block']['drawn']`
     * true: inside a preview it is in the preview too, but its markup goes to
     * the block that drew it, not to the editor — so a filter that replaces a
     * preview with something else leaves a drawn one alone.
     *
     * @param string              $html
     * @param string              $slug
     * @param array<string,mixed> $context    What the template was given.
     * @param bool                $is_preview In the editor, or drawn inside a block that is.
     */
    echo apply_filters('wp_go_blocks/html', $html, $slug, $context, (bool) $is_preview);
}

/**
 * A block's files for the post type it renders on, if it has any:
 *
 *   blocks/<slug>/<slug>.<post type>.php          run after <slug>.php, merged over it
 *   blocks/<slug>/<slug>.<post type>.<extension>  rendered instead of <slug>.<extension>
 *                                                 (`.twig` with Timber)
 *
 * Either is optional. They are how a block takes what only that post type can
 * give it — composed from the post, not authored in the block — and they apply
 * wherever the block renders for such a post: placed in the content, drawn by a
 * template, in the editor's preview, in ACF's preload. Keyed by post type, not
 * by template file, because every one of those knows the post type for sure.
 *
 * A template variant usually includes the block's own template with what it
 * adds. Hypothetically — the theme has no `event` post type, and its sticky
 * columns no `sticky_content` — an event's card in the sticky column:
 *
 *   {# blocks/sticky-columns/sticky-columns.event.twig #}
 *   {% include 'blocks/sticky-columns/sticky-columns.twig' with {
 *     sticky_content: include('components/sticky-event-card/sticky-event-card.twig', card),
 *   } %}
 *
 * A post type named like the first part of the renderer's extension (`payload`,
 * with `payload.php`) has no loader variant: its `<slug>.<post type>.php` is
 * the block's own template, not a loader.
 *
 * @param string $extension The renderer's, without the dot.
 * @return array{php: ?string, template: ?string} Absolute paths, or null.
 */
function variant(string $path, string $slug, int $post_id, string $extension): array
{
    $type = $post_id > 0 ? (string) get_post_type($post_id) : '';

    if ($type === '' || $type !== sanitize_key($type)) {
        return ['php' => null, 'template' => null];
    }

    $base = $path . '/' . $slug . '.' . $type;

    // `<slug>.payload.php`, for a `payload` post type, is the block's
    // template with the payload renderer: required as a loader, the payload
    // it returns would be merged into the context
    $collides = $type . '.php' === $extension;

    return [
        'php'      => !$collides && is_readable($base . '.php') ? $base . '.php' : null,
        'template' => is_readable($base . '.' . $extension) ? $base . '.' . $extension : null,
    ];
}

/**
 * Optional per-block data loader: blocks/<slug>/<slug>.php, and its post
 * type's, blocks/<slug>/<slug>.<post type>.php (variant()), run after it.
 *
 * Runs once per rendered block, just before the template, and returns an
 * array that is merged over the context — so it can add data the fields don't
 * hold (a query, an API call, a computed value) or rewrite a field before the
 * template sees it:
 *
 *   <?php
 *   // blocks/<slug>/<slug>.php
 *   return [
 *       'events' => Timber::get_posts([
 *           'post_type'      => 'event',
 *           'posts_per_page' => $fields['count'] ?? 3,
 *       ]),
 *   ];
 *
 * In scope: $fields (this block's ACF values), $block, $is_preview, $post_id
 * (the post the block renders for: in the editor, the one being edited — where
 * Timber's `post` is not it) and $context (everything the template would
 * already have). Returning anything other than an array leaves the context
 * untouched.
 *
 * The file is required on every render, so it must not declare functions or
 * classes at the top level — a second block of the same type on the page would
 * fatal on the redeclare. Put shared helpers in the theme's own includes.
 */
function run_loader(string $file, array $context, array $fields, array $block, bool $is_preview, int $post_id = 0): array
{
    if (!is_readable($file)) {
        return $context;
    }

    $data = (static function () use ($file, $context, $fields, $block, $is_preview, $post_id) {
        return require $file;
    })();

    if (!is_array($data)) {
        return $context;
    }

    // Same rule as the fields: a loader's absent value is not sent either, so
    // a template sees one contract whether a value came from ACF or a query.
    return array_merge($context, strip_absent($data));
}

/**
 * The renderer's global context, with `$extra` merged over it: what a theme's
 * PHP template starts its context from, in place of the engine's own call
 * (Timber::context()), and what every block's context starts from too.
 *
 *   $context = WpGoBlocks::context();
 *
 * @param array<string,mixed> $extra
 * @return array<string,mixed>
 */
function context(array $extra = []): array
{
    return array_merge(global_context(), $extra);
}

/**
 * The renderer's globals, through `wp_go_blocks/context`: what every page's and
 * every block's context starts from. Built once per request and kept, as
 * Timber keeps its own — so a value added on the filter is computed once, and
 * one added later in the request, after the first page or block has read it,
 * is not seen.
 *
 * Kept, its `post` (Timber's) is the request's: the queried post, or none in
 * an editor's preview. A block that renders for another post — in the editor,
 * in another post's content — still gets that one. The post it renders for is
 * a loader's `$post_id`; a loader that needs it as an entity fetches it
 * (`'post' => WpGoBlocks::get_post($post_id)`). Not swapped in here: the
 * globals are the renderer's, whose `post` may not be a post at all, and a
 * block would pay a fetch to replace a value it most likely never reads.
 *
 * @return array<string,mixed>
 */
function global_context(): array
{
    // one per renderer: a request that swaps it (Config::use_renderer())
    // must not read the other one's globals
    static $contexts = [];

    $renderer = Config::renderer();
    $key      = $renderer ? spl_object_id($renderer) : 0;
    $context  = &$contexts[$key];

    if ($context === null) {
        /**
         * The global context, whatever renders: what every page's and every
         * block's context starts from. Add a value here rather than on the
         * engine's own filter (Timber's `timber/context`), and it reaches the
         * templates whichever renderer is in use.
         *
         * @param array<string,mixed> $context The renderer's globals (Timber's
         *                                     context with TimberRenderer).
         */
        $context = (array) apply_filters('wp_go_blocks/context', $renderer ? $renderer->globals() : []);
    }

    return $context;
}

/**
 * Print a page with the renderer the blocks use: what a theme's PHP template
 * calls at its end, in place of the engine's own call, so the templates don't
 * name the engine either.
 *
 *   WpGoBlocks::render(['single-event.twig', 'base.twig'], $context);
 *
 * The arguments are passed through to the renderer's output() as given, however
 * many: with TimberRenderer, Timber::render()'s.
 */
function render_page(mixed ...$args): void
{
    $renderer = Config::renderer();

    if (!$renderer) {
        trigger_error('WpGo\\Blocks\\Core\\render_page(): no renderer', E_USER_WARNING);

        return;
    }

    $renderer->output(...$args);
}
