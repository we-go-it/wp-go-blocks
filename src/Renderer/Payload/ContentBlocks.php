<?php

namespace WpGo\Blocks\Renderer\Payload;

/**
 * A post's content as a page's blocks (PayloadRenderer::output()): a tree,
 * inner blocks under `inner_blocks`.
 *
 *   - an ACF block is its payload: rendered on its own (its inner blocks
 *     taken off, and walked here instead) with the PayloadRenderer, its JSON
 *     decoded. `registered: false` for one that no longer exists.
 *   - any other block is its HTML, inner blocks included, as WordPress
 *     renders it — unless ACF blocks are among them, at any depth: then it is
 *     its `attrs` and its `inner_blocks`, walked the same way, as its HTML
 *     would carry their JSON. A synced pattern (`core/block`)'s inner blocks
 *     are the pattern's.
 *   - classic content, and the freeform HTML between blocks, is
 *     `core/freeform` with its HTML.
 *
 * HTML is as `the_content` finishes it: shortcodes run, images given their
 * `srcset` and lazy loading — and, for a classic post's content, paragraphs.
 */
final class ContentBlocks
{
    /** @var array<int,true> the synced patterns being walked, by ID: one inside itself is left out, as WordPress does */
    private static array $patterns = [];

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function of(string $content): array
    {
        return self::walk(parse_blocks($content), !has_blocks($content));
    }

    /**
     * @param array<int,array<string,mixed>> $parsed
     * @param bool                           $classic The whole content is
     *                                                classic: paragraphs are
     *                                                its line breaks (wpautop()).
     * @return array<int,array<string,mixed>>
     */
    private static function walk(array $parsed, bool $classic = false): array
    {
        $out = [];

        foreach ($parsed as $block) {
            $name = $block['blockName'] ?? null;

            if (!$name) {
                $html = trim((string) ($block['innerHTML'] ?? ''));

                // the line breaks between blocks are freeform too
                if ($html !== '') {
                    $out[] = ['name' => 'core/freeform', 'html' => self::html($classic ? shortcode_unautop(wpautop($html)) : $html)];
                }

                continue;
            }

            // a synced pattern inside itself: WordPress halts there, and so does the walk
            if ($name === 'core/block' && isset(self::$patterns[(int) ($block['attrs']['ref'] ?? 0)])) {
                continue;
            }

            if (!str_starts_with($name, 'acf/')) {
                $inner = self::inside($block, fn (array $inner) => self::holds_acf($inner) ? self::walk($inner) : null);

                $out[] = $inner === null
                    ? ['name' => $name, 'html' => self::html(\render_block($block))]
                    : Normalizer::without_nulls(['name' => $name, 'attrs' => $block['attrs'] ?: null, 'inner_blocks' => $inner]);

                continue;
            }

            if (!\WP_Block_Type_Registry::get_instance()->is_registered($name)) {
                $out[] = ['name' => $name, 'registered' => false];

                continue;
            }

            $inner = $block['innerBlocks'] ?? [];
            $alone = array_merge($block, ['innerBlocks' => [], 'innerContent' => [], 'innerHTML' => '']);
            $json  = json_decode(trim(\render_block($alone)), true);

            $out[] = Normalizer::without_nulls([
                'name'         => $name,
                'payload'      => is_array($json) ? $json : null,
                'inner_blocks' => $inner ? self::walk($inner) : null,
            ]);
        }

        return $out;
    }

    /** Whether ACF blocks are among these, at any depth. */
    private static function holds_acf(array $parsed): bool
    {
        foreach ($parsed as $block) {
            if (str_starts_with((string) ($block['blockName'] ?? ''), 'acf/') || self::inside($block, self::holds_acf(...))) {
                return true;
            }
        }

        return false;
    }

    /**
     * `$fn` given a block's inner blocks: a synced pattern's are the
     * pattern's, while it is walked. None for one WordPress wouldn't render —
     * not published, behind a password, or inside itself.
     */
    private static function inside(array $block, callable $fn): mixed
    {
        $ref = ($block['blockName'] ?? '') === 'core/block' ? (int) ($block['attrs']['ref'] ?? 0) : 0;

        if (!$ref) {
            return $fn($block['innerBlocks'] ?? []);
        }

        $pattern = get_post($ref);

        if (!$pattern || $pattern->post_type !== 'wp_block' || $pattern->post_status !== 'publish' || $pattern->post_password !== '' || isset(self::$patterns[$ref])) {
            return $fn([]);
        }

        self::$patterns[$ref] = true;

        try {
            return $fn(parse_blocks($pattern->post_content));
        } finally {
            unset(self::$patterns[$ref]);
        }
    }

    /** HTML as `the_content` finishes it, after the blocks: shortcodes, then images. */
    private static function html(string $html): string
    {
        return wp_filter_content_tags(do_shortcode(trim($html)));
    }
}
