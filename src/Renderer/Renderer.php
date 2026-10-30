<?php

namespace WpGo\Blocks\Renderer;

/**
 * What blocks render with: a template engine. Everything else in the package —
 * registration, fields, variants, loaders, assets — is the same whatever it is.
 *
 * boot() requires one; there is no default:
 *
 *   \WpGo\Blocks\boot(__DIR__ . '/wp-go-blocks.json', [
 *       'renderer' => new TimberRenderer(),
 *       'entities' => new TimberEntities(),
 *   ]);
 *
 * The package ships TimberRenderer (Twig, through Timber) and PayloadRenderer
 * (JSON); another engine is a class of its own (new MyBladeRenderer()).
 *
 * The block's templates, `<slug>.<extension>` and its variants'
 * `<slug>.<post type>.<extension>`, are then written for that engine. The
 * posts and menus they read are not the renderer's to fetch: they come from
 * the Entities boot() was given (Config::entities()), which a renderer reads
 * too when it needs one.
 */
interface Renderer
{
    /**
     * The templates' extension, without the dot: `twig`, `blade.php`, `latte`.
     * Not plain `php`: `<slug>.php` is the block's loader.
     */
    public function extension(): string;

    /**
     * What every block's context starts from, under its own values: the
     * engine's global context (Timber's `site`, `theme`, `user`, `post`…), or [].
     *
     * @return array<string,mixed>
     */
    public function globals(): array;

    /**
     * One template, with its context, as HTML.
     *
     * The file may not be there: a block folder without its template. What
     * that renders is the renderer's call — TimberRenderer reports it with a
     * notice(), PayloadRenderer renders the context instead.
     *
     * @param string              $file    Absolute path.
     * @param array<string,mixed> $context
     */
    public function render(string $file, array $context): string;

    /**
     * Print a page: what a theme's PHP template calls at its end, through
     * render_page(), in place of the engine's own call. The arguments are the
     * engine's, passed through as given, however many — for Timber,
     * Timber::render()'s ($filenames, $data, $expires, $cache_mode).
     */
    public function output(mixed ...$args): void;

    /**
     * Make a PHP function callable from templates under `$name` — `wp_go_render_block` —
     * returning HTML that must not be escaped again. Called once, from boot().
     */
    public function expose(string $name, callable $fn): void;
}
