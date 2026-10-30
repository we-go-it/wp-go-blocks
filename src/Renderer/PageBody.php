<?php

namespace WpGo\Blocks\Renderer;

/**
 * A Renderer that can render a page's body alone: the part of the view that is
 * the page's own, without the site around it (head, header, footer). Optional.
 *
 * PayloadRenderer renders it, given the site's renderer, to find the blocks the
 * view draws itself (wp_go_render_block()) and where the content goes among them. Without
 * it, a page's blocks are its content's only.
 */
interface PageBody
{
    /**
     * The page's body as markup, or null when the view has none.
     *
     * @param array<int,string>   $templates The candidates a theme's PHP template
     *                                       names, first found wins.
     * @param array<string,mixed> $context   The page's context.
     */
    public function body(array $templates, array $context): ?string;
}
