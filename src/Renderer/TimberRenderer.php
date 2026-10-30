<?php

namespace WpGo\Blocks\Renderer;

use WpGo\Blocks\Core\Config;

use function WpGo\Blocks\Core\notice;

/**
 * A Renderer: Twig, through Timber 2. Needs timber/timber, which the package
 * only suggests.
 *
 * Templates are found through Timber's locations, so the theme (the folder
 * wp-go-blocks.json is in) must be one of them; a block's path is made relative
 * to it. Exposed functions are Twig functions, marked safe, so the markup they
 * return is not escaped whether or not the theme autoescapes. Its posts and
 * menus are usually Timber's too (TimberEntities).
 *
 * A page's body (PageBody) is the view's Twig block `$body`, `content` unless
 * told otherwise: the block a view fills under `{% extends "base.twig" %}`.
 */
final class TimberRenderer implements Renderer, PageBody
{
    /** @var array<string,callable> */
    private array $functions = [];

    public function __construct(private string $body = 'content')
    {
    }

    public function extension(): string
    {
        return 'twig';
    }

    public function globals(): array
    {
        return \Timber\Timber::context();
    }

    /**
     * A template that isn't there is a theme bug — a block folder without its
     * `.twig`, a compile() of a misspelt path — so it says so with WP_DEBUG
     * on, where Timber would render nothing at all. Not the package's rule
     * for every renderer: PayloadRenderer renders a block without a template
     * as its context.
     */
    public function render(string $file, array $context): string
    {
        $relative = ltrim(str_replace(Config::root(), '', wp_normalize_path($file)), '/');

        if (!is_readable($file)) {
            return notice("TimberRenderer: no template `$relative`");
        }

        return (string) \Timber\Timber::compile($relative, $context);
    }

    public function output(mixed ...$args): void
    {
        \Timber\Timber::render(...$args);
    }

    /**
     * The view's `$body` block, with the context Timber::render() would give the
     * view (`timber/render/data`). Null when no candidate exists or it has no
     * such block.
     */
    public function body(array $templates, array $context): ?string
    {
        $loader = new \Timber\Loader();
        $file   = $loader->choose_template($templates);

        if (!is_string($file)) {
            return null;
        }

        $data     = (array) apply_filters('timber/render/data', $context, $file);
        $template = $loader->get_twig()->load($file);

        return $template->hasBlock($this->body, $data) ? $template->renderBlock($this->body, $data) : null;
    }

    public function expose(string $name, callable $fn): void
    {
        if (!$this->functions) {
            add_filter('timber/twig/functions', [$this, 'twig_functions']);
        }

        $this->functions[$name] = $fn;
    }

    /**
     * On `timber/twig/functions`.
     *
     * @param array<string,mixed> $functions
     * @return array<string,mixed>
     */
    public function twig_functions($functions)
    {
        foreach ($this->functions as $name => $fn) {
            $functions[$name] = ['callable' => $fn, 'options' => ['is_safe' => ['html']]];
        }

        return $functions;
    }
}
