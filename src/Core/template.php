<?php

namespace WpGo\Blocks\Core;

use WpGo\Blocks\Renderer\PayloadRenderer;

/**
 * Rendering a block from a PHP template, rather than from a post's content.
 *
 * Every block here stays an ordinary block: registered from its own
 * `block.json`, offered by the inserter wherever `acf.postTypes` allows it, and
 * placeable by hand like any other. This file adds a second way to reach the
 * same block — a template can render one itself:
 *
 *   {# views/single-service.twig #}
 *   {{ wp_go_render_block('page-intro') }}
 *   {{ post.content }}
 *
 * (`wp_go_render_block` in a template is block() here, exposed by the renderer.)
 *
 * What that buys, and what it costs, are the same fact seen twice: **the block
 * is not in `post_content`.** So the default lives in the template and every
 * post follows the moment it changes, with no migration — and the editor does
 * not see it, cannot move it, and cannot type into it.
 *
 * Which means a template-rendered block must not need authoring. It either
 * reads the post or it is given its values here, in code
 * (`wp_go_render_block('page-intro', { text: post.meta('description') })`).
 * A block whose copy an editor is meant to write belongs in the content,
 * placed by hand.
 *
 * Rendering as data (PayloadRenderer), a page's blocks are the ones it draws
 * too, in its `blocks`: its view's, where the view prints them, or, without a
 * view (no Twig: the payload renderer is the site's), its PHP template's, in
 * the order it calls block(). There, what block() returns is a marker, not
 * the block — it is only for the page's tree — and the post's content goes
 * where content() is given as a block's `content`. A block drawn while
 * another renders (by its loader, its `.payload.php`) is a marker too, page
 * or not; that block's payload has it as a node where the marker is, as has
 * one a page hands a block in its `context` option (PayloadRenderer::drawn()):
 *
 *   // single-service.php
 *   WpGoBlocks::block('sticky-columns', ['card_position' => 'right'], [
 *       'content' => WpGoBlocks::content(),
 *   ]);
 *
 * Its CSS and JS still land in the head, in the right order, with nothing
 * declared in advance — see the slot mechanism in `assets.php`. The values it
 * is given are stored the way the editor would store them (`fields-write.php`).
 * What a block needs from the post type it is on, placed or drawn, is its own
 * variant files' (variant(), render.php).
 */

/**
 * Render one block.
 *
 * Its fields are `$data`, always: nothing is read from a post. `post_id` only
 * says which post the block renders for, when it isn't the current one — the
 * post its variant is picked by, its loaders' `$post_id`, and the one a block
 * that keeps its values in post meta reads them from.
 *
 * @param string               $slug    Block slug, with or without the `acf/` prefix.
 * @param array<string,mixed>  $data    Field values, by field name. Keys are paired
 *                                      with their ACF field keys for you.
 * @param array<string,mixed>  $options `content` for InnerBlocks, `post_id` for the
 *                                      post it renders for, `context` for values
 *                                      handed straight to the block's template —
 *                                      markup the template composed, not field
 *                                      values (see render_block()).
 */
function block(string $slug, array $data = [], array $options = []): string
{
    // The blocks being drawn, outermost first: a block whose template draws
    // itself, directly or through another, would never return.
    static $drawing = [];

    $slug = str_starts_with($slug, 'acf/') ? substr($slug, 4) : $slug;

    if (!\WP_Block_Type_Registry::get_instance()->is_registered('acf/' . $slug)) {
        return notice("wp_go_render_block(): no block registered as `acf/$slug`");
    }

    if (in_array($slug, $drawing, true)) {
        $loop = implode(' → ', array_map(fn ($drawn) => 'acf/' . $drawn, [...$drawing, $slug]));

        return notice("wp_go_render_block(): `acf/$slug` draws itself ($loop)");
    }

    $context    = (array) ($options['context'] ?? []);
    $content    = (string) ($options['content'] ?? '');
    $attributes = [
        'name' => 'acf/' . $slug,
        'mode' => 'preview',
        'data' => field_data($slug, $data),
    ];

    // The id ACF would make from the attributes, were the context in them
    // rather than the token below: the same block drawn with the same values
    // keeps the same id on every request, as a placed one does.
    $attributes['id'] = acf_get_block_id($attributes + ['wp_go_context' => $context]);

    // ACF carries attributes, nothing else, and an attribute is anyone's to
    // write: a post's content, an editor's preview request. So what this
    // render is handed waits here, and the attribute holds only the token
    // that finds it, one no one else can know (render_block()).
    $token     = wp_generate_uuid4();
    $hand_offs = &hand_offs();

    $attributes['wp_go_context'] = $token;
    $hand_offs[ $token ]         = [
        'context'    => $context,
        // Drawn inside a block the editor is previewing, it is part of that
        // preview. ACF itself is told false all the same: previewing, it
        // would leave `<InnerBlocks />` in place of the block's `content`.
        'is_preview' => previewing(),
    ];
    $drawing[] = $slug;

    try {
        $html = (string) acf_rendered_block(
            $attributes,
            $content,
            false,
            (int) ($options['post_id'] ?? 0)
        );
    } finally {
        array_pop($drawing);
        unset($hand_offs[ $token ]);
    }

    // As data, the page's blocks are the ones its view draws too, where it
    // draws them (PayloadRenderer::output()), and a block's payload holds
    // the ones drawn for it: a marker, while either is made
    $renderer = Config::renderer();

    return $renderer instanceof PayloadRenderer ? $renderer->drawn('acf/' . $slug, $html, $content) : $html;
}

/**
 * The post's content, where a template puts it — as a block's `content`, say.
 * As data, while a page is built, a marker for where its blocks go
 * (PayloadRenderer::content()); otherwise its HTML, as `the_content` makes it.
 */
function content(): string
{
    $renderer = Config::renderer();

    if ($renderer instanceof PayloadRenderer && ($marker = $renderer->content()) !== null) {
        return $marker;
    }

    $post = get_post();

    return $post ? (string) apply_filters('the_content', get_the_content(null, false, $post)) : '';
}

/**
 * What block() hands the renders it starts, by the token it gives each:
 * `context`, its `context` option, and `is_preview`, whether it draws inside
 * a preview. An entry lives while its render runs, and render_block() reads
 * it there.
 *
 * @return array<string,array{context: array<string,mixed>, is_preview: bool}>
 */
function &hand_offs(): array
{
    static $hand_offs = [];

    return $hand_offs;
}

/**
 * Whether the block being rendered is in the editor's preview, for block()
 * to pass on to what it draws there. With an argument, render_block() sets it
 * for the time it renders, and is handed back the value to restore.
 */
function previewing(?bool $set = null): bool
{
    static $previewing = false;

    $was = $previewing;

    if ($set !== null) {
        $previewing = $set;
    }

    return $was;
}

/**
 * How many blocks are rendering, one inside another: render_block() counts
 * itself in while its loaders and its template run (`+1`, then `-1`). Above
 * 0, a block block() draws is drawn for the one rendering, its loader or its
 * template: as data, part of that block's payload (PayloadRenderer::drawn()).
 */
function rendering(int $add = 0): int
{
    static $depth = 0;

    $depth += $add;

    return $depth;
}

/**
 * The names of every block rendered so far, placed or drawn, for assets.php
 * (render_block() records each). Reads the list; with an argument, adds to
 * it.
 *
 * @return array<int,string>
 */
function declared_blocks(?string $add = null): array
{
    static $names = [];

    if ($add !== null && !in_array($add, $names, true)) {
        $names[] = $add;
    }

    return $names;
}

/**
 * A missing block is a template bug, so it shows on the page with WP_DEBUG on
 * and renders nothing at all otherwise. Anything else that draws on a template's
 * behalf can report its own bugs the same way, so the message is printed as
 * given: each names what reports it (`wp_go_render_block(): …`).
 */
function notice(string $message): string
{
    if (!WP_DEBUG) {
        return '';
    }

    /**
     * The notice's markup, to style it like the rest of a theme's dev output.
     *
     * @param string $html
     * @param string $message Plain text.
     */
    return (string) apply_filters(
        'wp_go_blocks/notice',
        '<div class="wp-go-blocks-notice">' . esc_html($message) . '</div>',
        $message
    );
}
