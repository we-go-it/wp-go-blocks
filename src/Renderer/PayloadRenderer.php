<?php

namespace WpGo\Blocks\Renderer;

use WpGo\Blocks\Core\Config;
use WpGo\Blocks\Renderer\Payload\ContentBlocks;
use WpGo\Blocks\Renderer\Payload\Menus;
use WpGo\Blocks\Renderer\Payload\Normalizer;

/**
 * A Renderer that renders data, not markup: each block as the JSON of its
 * context, and a page as its context plus its blocks. An
 * experiment — what a block would hand a front end that renders it itself.
 *
 * A block's template is `<slug>.payload.php` (and a variant's
 * `<slug>.<post type>.payload.php`): a PHP file with `$context` in scope that
 * returns the block's payload, an array. Without one, the payload is the
 * context itself. Either way it is made plain data (normalize()): posts,
 * terms, dates and Twig markup become arrays and strings.
 *
 * Its globals are empty, so a payload holds the block's own values — its
 * fields, a template's `context`, its loaders' — and not the engine's
 * `site`, `theme`… The `block` ACF hands over is cut down to what identifies
 * it. Posts and menus come from the Entities boot() was given, whichever
 * they are (WordPress's, Timber's); normalize() turns them into data.
 *
 * A page's blocks are also the ones it draws itself (wp_go_render_block()), where it
 * draws them, with the content's in its place: in its view, given a site
 * renderer that can render a page's body (PageBody); else in its PHP
 * template, in the order it calls them. See output().
 *
 * The page is assembled here; the rest is under `Payload/`: the content's
 * blocks (ContentBlocks), menus (Menus), and plain data (Normalizer). Their
 * public statics are reached from here, which is what a theme calls.
 */
final class PayloadRenderer implements Renderer
{
    /** The markers in markup, a page's blocks read from it (tree()): a drawn block's number (marker()), or `content` */
    private const MARKERS = '/<!--wg-drawn:(\d+|content)-->/';

    /** The content's marker: where the post's content goes */
    private const CONTENT = '<!--wg-drawn:content-->';

    /** @var array<string,mixed>|null the page output() built, for capture() */
    private ?array $captured = null;

    /** True while capture() runs: output() keeps the page rather than printing it */
    private bool $capturing = false;

    /** True once output() has printed the page, as the site's renderer */
    private bool $printed = false;

    /** @var array<int,string> post ID → the content output() reads instead of the stored one */
    private array $contents = [];

    /** True while output() renders the page's body */
    private bool $drawing = false;

    /**
     * @var array<int,array{name:string, payload:mixed, content:string, inside:bool, nested:bool}>
     * the blocks drawn, by marker: the page's, and the ones drawn for a block, which are that
     * block's, not the page's — drawn while it renders (`inside`: its loader's, its
     * `.payload.php`'s), or placed in its payload (`nested`: handed to it as its
     * `context`, say)
     */
    private array $drawn = [];

    /**
     * @param Renderer|null $site The site's renderer, to render a page's body
     *                            with when it can (PageBody).
     */
    public function __construct(private ?Renderer $site = null)
    {
    }

    /**
     * Run `$fn` — a page template, say — and return the page its output()
     * call built, as an array. Null when it made none.
     *
     * @return array<string,mixed>|null
     */
    public function capture(callable $fn): ?array
    {
        $this->captured  = null;
        $this->capturing = true;
        $this->drawn     = [];

        try {
            $fn();
        } finally {
            $this->capturing = false;
        }

        return $this->captured;
    }

    /**
     * Render a post's page with `$content` as its content instead of the
     * stored one: the block editor's unsaved blocks (ContentApi's preview).
     * Nothing is stored.
     */
    public function use_content(int $post_id, string $content): self
    {
        $this->contents[$post_id] = $content;

        return $this;
    }

    public function extension(): string
    {
        return 'payload.php';
    }

    public function globals(): array
    {
        return [];
    }

    /**
     * A block's payload, as JSON. The blocks drawn for it — by its loader or
     * its `.payload.php`, or handed to it in its `context` option — are in it
     * as nodes, where their markers are (nested()).
     */
    public function render(string $file, array $context): string
    {
        return (string) wp_json_encode($this->nested($this->payload($file, $context)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * A page: its context — what its PHP template built — and its blocks, as a
     * tree of payloads.
     *
     * The blocks are the ones the page draws itself (wp_go_render_block()), marked
     * `template: true`, a block's `content` option its inner blocks, and the
     * post's content's blocks where the page puts the content. Where that is,
     * among the blocks of the same level, is `content_at`: on the page, or on the
     * drawn block whose inner blocks hold them — absent when the page leaves the
     * content out (a fixed layout).
     *
     * Where the page draws them is its body as the site's renderer draws it
     * (PageBody), the view: in the order it prints them, the content where it
     * prints the content; the markup around them is thrown away. A block the
     * PHP template drew is there where the view prints what it returned.
     *
     * Without a body — no site renderer that draws one, as when this is the
     * site's renderer — it is the PHP template's calls, in their order; one
     * given as another's `content` is inside it. The content is where a call is
     * given `WpGoBlocks::content()` (content()), else after them. With no call,
     * the blocks are the content's, at 0. The content is there once, at the
     * first place the page puts it. A block drawn for another block — by its
     * loader, or handed to it in its `context` option — is in that block's
     * payload instead (nested()).
     *
     * A post behind a password gives no content's blocks, unless its
     * password was given (post_password_required()) or the content is the
     * editor's (use_content()): the rest of the page is there, as WordPress
     * shows it around its password form.
     *
     * The arguments are the ones the theme gives the page's render: the
     * templates it names first, then its context. `template` is the first of
     * them, the one the theme names for this post (`page-about-us.twig`),
     * whether it exists or not: which one renders is the site renderer's
     * business — past one named after nothing, though: an auto-draft has no
     * slug yet, and `page-.twig` names no page. Inside capture() nothing is
     * printed: the page is kept for it to hand back (ContentApi). Otherwise —
     * the site's own renderer — the page is printed, as JSON.
     */
    public function output(mixed ...$args): void
    {
        $templates = (array) ($args[0] ?? []);
        $context   = is_array($args[1] ?? null) ? $args[1] : [];
        $post      = $this->post($context);
        $body      = $this->body($templates, $context, $post) ?? $this->drawn_body();
        $content   = $post && (isset($this->contents[$post->ID]) || !post_password_required($post))
            ? ContentBlocks::of($this->contents[$post->ID] ?? $post->post_content)
            : [];
        $at        = 0;
        $blocks    = $body !== null ? $this->tree($body, $content, $at) : $content;
        $named     = array_filter($templates, fn ($name) => !str_contains((string) $name, '-.'));

        // no nulls, as in the payloads: absent
        $page = Normalizer::without_nulls([
            'template'   => (string) (reset($named) ?: ($templates[0] ?? '')),
            // an object even when empty: `{}`, not `[]`
            'context'    => self::normalize($context) ?: new \stdClass(),
            'blocks'     => $blocks,
            'content_at' => $at,
        ]);

        if ($this->capturing) {
            $this->captured = $page;

            return;
        }

        $this->printed = true;
        $this->drawn   = [];

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=' . get_option('blog_charset'));
        }

        echo wp_json_encode($page, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Whether a page is being built, so the blocks it draws are its own:
     * inside capture() (the content API), while its body renders, and, as the
     * site's renderer, on a page request from its template until output() —
     * not in a REST or ajax request (the editor's block previews, a listing),
     * which want a block's payload back.
     */
    public function collecting(): bool
    {
        if ($this->capturing || $this->drawing) {
            return true;
        }

        $rest = function_exists('wp_is_serving_rest_request') ? wp_is_serving_rest_request() : (defined('REST_REQUEST') && REST_REQUEST);

        return !$this->printed && Config::renderer() === $this && did_action('template_redirect') && !$rest && !wp_doing_ajax();
    }

    /**
     * What wp_go_render_block() returns for a block it drew while a page is
     * built (collecting()) or while another block renders (its loader's, its
     * `.payload.php`'s): a marker in its place, the block kept — for tree(),
     * or for the payload of the block it was drawn for (nested()). Otherwise
     * its payload, as it came.
     *
     * One drawn while another renders is that block's, never the page's, even
     * if its payload leaves it out.
     *
     * @param string $name    The block's name, `acf/<slug>`.
     * @param string $json    Its payload, as render() made it.
     * @param string $content Its `content` option: markup the view composed,
     *                        or what the PHP template gave it — with markers
     *                        of its own.
     */
    public function drawn(string $name, string $json, string $content): string
    {
        $inside = \WpGo\Blocks\Core\rendering() > 0;

        if (!$inside && !$this->collecting()) {
            return $json;
        }

        $this->drawn[] = ['name' => $name, 'payload' => json_decode(trim($json), true), 'content' => $content, 'inside' => $inside, 'nested' => false];

        return self::marker(array_key_last($this->drawn));
    }

    /**
     * The content's marker, for `WpGoBlocks::content()` while a page is built:
     * where the post's content's blocks go. Null otherwise.
     */
    public function content(): ?string
    {
        return $this->collecting() ? self::CONTENT : null;
    }

    /** A drawn block's marker, by its number. */
    private static function marker(int $i): string
    {
        return "<!--wg-drawn:$i-->";
    }

    /**
     * The page's body from the site's renderer, wp_go_render_block() leaving markers in
     * it, and the post's content, printed by the view (`the_content` for the
     * page's post), the content's marker, so it is not rendered there too.
     * Another post's content — a list of related posts, one post printed
     * outside a loop — is its own. The marker is the whole of what
     * `the_content` gives, whatever its later filters wrap it in (wpautop's
     * paragraph): a value of the context made of it is the marker alone
     * (nested()). Null when there is no body.
     *
     * @param array<int,string>   $templates
     * @param array<string,mixed> $context
     */
    private function body(array $templates, array $context, ?\WP_Post $post): ?string
    {
        if (!$this->site instanceof PageBody) {
            return null;
        }

        $marker = static fn ($content) => $post && self::is_content_of($post, $content) ? self::CONTENT : $content;
        // the marker, not what the filters after it made of it
        $whole = static fn ($content) => is_string($content) && str_contains($content, self::CONTENT) ? self::CONTENT : $content;

        $this->drawing = true;
        // first, so the filters after it have no blocks to render
        add_filter('the_content', $marker, PHP_INT_MIN);
        add_filter('the_content', $whole, PHP_INT_MAX);

        try {
            return $this->site->body($templates, $context);
        } finally {
            remove_filter('the_content', $marker, PHP_INT_MIN);
            remove_filter('the_content', $whole, PHP_INT_MAX);
            $this->drawing = false;
        }
    }

    /**
     * Whether `the_content` is filtering the page's post's content: the post
     * in the loop is the page's (Timber's loops set it up) and the content is
     * its own. Timber's `post.content` doesn't set the global post up, so
     * another post's printed outside a loop runs with the page's post still
     * in it: its content tells it apart. The page's is as stored (Timber's),
     * as get_the_content() gives it (the_content(): a `more` tag's anchor, a
     * page of a paged post), or a part of it (Timber's paged or teaser-less
     * content).
     *
     * @param mixed $content
     */
    private static function is_content_of(\WP_Post $post, $content): bool
    {
        if (get_the_ID() !== $post->ID || !is_string($content)) {
            return false;
        }

        if ($content === $post->post_content || (trim($content) !== '' && str_contains($post->post_content, $content))) {
            return true;
        }

        return $content === get_the_content();
    }

    /**
     * The PHP template's blocks, as markup of their markers (tree()): the ones
     * not inside another's `content` nor another block's (`inside`, `nested`), in
     * the order they were drawn, and the content's marker after them unless
     * one of them holds it. Null when it drew none.
     */
    private function drawn_body(): ?string
    {
        if (!$this->drawn) {
            return null;
        }

        preg_match_all(self::MARKERS, implode('', array_column($this->drawn, 'content')), $inside);

        // the content, in a payload, is not placed (nested()): held there, it
        // still goes after the page's blocks
        $page = array_filter($this->drawn, fn ($drawn) => !$drawn['inside'] && !$drawn['nested']);
        $held = str_contains(implode('', array_column($page, 'content')), self::CONTENT);

        $markup = '';

        foreach (array_keys($page) as $i) {
            if (!in_array((string) $i, $inside[1], true)) {
                $markup .= self::marker($i);
            }
        }

        return $held ? $markup : $markup . self::CONTENT;
    }

    /**
     * The blocks in markup, by its markers, in order: each drawn block with its
     * `content` walked the same way for its inner blocks, and `$content` where
     * the content's marker first is — once, its later markers left empty
     * (`$placed`). `$at` is where that is on this level, or null.
     *
     * A block already placed in a payload (`nested`) isn't placed again. One drawn while a block renders
     * (`inside`) is never the page's own (`$top`), but is the inner block of
     * the one it was drawn for: `content` of a block drawn in a loader.
     *
     * @param array<int,array<string,mixed>> $content
     * @return array<int,array<string,mixed>>
     */
    private function tree(string $markup, array $content, ?int &$at, bool &$placed = false, bool $top = true): array
    {
        $at  = null;
        $out = [];

        preg_match_all(self::MARKERS, $markup, $markers);

        foreach ($markers[1] as $marker) {
            if ($marker === 'content') {
                if (!$placed) {
                    $placed = true;
                    $at     = count($out);
                    array_push($out, ...$content);
                }

                continue;
            }

            $drawn = $this->drawn[(int) $marker] ?? null;

            // a block in another's payload is there, not on this level too
            if ($drawn && ($drawn['nested'] || ($top && $drawn['inside']))) {
                continue;
            }

            $node = $this->node((int) $marker, $content, $placed);

            if ($node !== null) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * A drawn block as a node: `{ name, payload, template: true }`, its
     * `content` walked for its `inner_blocks` (tree()). Null for a number
     * with no kept block (a stray marker).
     *
     * @param array<int,array<string,mixed>> $content
     * @return array<string,mixed>|null
     */
    private function node(int $i, array $content, bool &$placed): ?array
    {
        $drawn = $this->drawn[$i] ?? null;

        if (!$drawn) {
            return null;
        }

        $inner = $this->tree($drawn['content'], $content, $inner_at, $placed, false);

        return Normalizer::without_nulls([
            'name'         => $drawn['name'],
            'payload'      => is_array($drawn['payload']) ? $drawn['payload'] : null,
            'template'     => true,
            'inner_blocks' => $inner ?: null,
            'content_at'   => $inner_at,
        ]);
    }

    /**
     * A payload with the blocks drawn for it in place of their markers: a
     * string that is a marker becomes that block's node (node()), one of
     * several markers a list of them; at any depth. A string with markup
     * around a marker keeps the markup, the block in it its payload's JSON, as
     * wp_go_render_block() returns it outside a page. Either way the block is
     * this payload's, not the page's (`nested`). The post's content isn't
     * placed in a payload: its marker there is left out, and a value of
     * nothing but such markers is absent.
     *
     * @param mixed $value
     * @return mixed
     */
    private function nested($value)
    {
        if (is_array($value)) {
            return Normalizer::without_nulls(array_map(fn ($item) => $this->nested($item), $value));
        }

        if (!is_string($value) || !str_contains($value, '<!--wg-drawn:')) {
            return $value;
        }

        $nodes = [];
        $rest  = preg_replace_callback(self::MARKERS, function (array $match) use (&$nodes) {
            if ($match[1] === 'content') {
                return '';
            }

            $i      = (int) $match[1];
            $placed = true;
            $node   = $this->node($i, [], $placed);

            if ($node === null) {
                return '';
            }

            $this->drawn[$i]['nested'] = true;
            $nodes[]                   = $node;

            return (string) wp_json_encode($node['payload'] ?? new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }, $value);

        if (trim(preg_replace(self::MARKERS, '', $value)) !== '') {
            return $rest;
        }

        // nothing left: absent, as a null is in a payload
        return match (count($nodes)) {
            0       => null,
            1       => $nodes[0],
            default => $nodes,
        };
    }

    /**
     * A menu as data, by location, ID, slug or name, or null: Menus::menu().
     *
     * @return array<string,mixed>|null
     */
    public static function menu(mixed $which): ?array
    {
        return Menus::menu($which);
    }

    /**
     * Every registered menu location that has a menu, as data, by location:
     * Menus::menus().
     *
     * @return array<string,array<string,mixed>>
     */
    public static function menus(): array
    {
        return Menus::menus();
    }

    /**
     * An image attachment as data, or null: Normalizer::image().
     *
     * @return array<string,mixed>|null
     */
    public static function image(mixed $id): ?array
    {
        return Normalizer::image($id);
    }

    /**
     * An array without its null values: Normalizer::compact().
     *
     * @param array<mixed> $value
     * @return array<mixed>
     */
    public static function compact(array $value, bool $deep = true): array
    {
        return Normalizer::compact($value, $deep);
    }

    /** A string as a front end reads it: Normalizer::text(). */
    public static function text(string $value): string
    {
        return Normalizer::text($value);
    }

    /**
     * Any value as plain data, for JSON: Normalizer::normalize().
     *
     * @param mixed $value
     * @return mixed
     */
    public static function normalize($value, int $depth = 0)
    {
        return Normalizer::normalize($value, $depth);
    }

    /** Nothing to expose: no template calls back into PHP. */
    public function expose(string $name, callable $fn): void
    {
    }

    /**
     * @param array<string,mixed> $context
     * @return mixed
     */
    private function payload(string $file, array $context)
    {
        if (isset($context['block']) && is_array($context['block'])) {
            $context['block'] = array_intersect_key($context['block'], array_flip(['name', 'id', 'anchor', 'align', 'className']));
        }

        if (is_readable($file)) {
            $payload = (static function () use ($file, $context) {
                return require $file;
            })();

            return self::normalize(is_array($payload) ? $payload : []);
        }

        return self::normalize($context);
    }

    /**
     * The post a page's context shows: its `post` (a Timber post or a
     * WP_Post), else the queried one, as the entities fetch it.
     *
     * @param array<string,mixed> $context
     */
    private function post(array $context): ?\WP_Post
    {
        $post = $context['post'] ?? Config::entities()?->get_post();
        $id   = is_object($post) && isset($post->ID) ? (int) $post->ID : 0;

        return $id ? get_post($id) : null;
    }
}
