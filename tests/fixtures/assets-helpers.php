<?php

/**
 * What the assets tests share, loaded by each once WordPress is up (a plain
 * require, not `@require`: the resolver interface comes with the package).
 *
 * The package runs with the fixture theme, tests/fixtures/assets-theme/, and
 * a resolver that answers every source with made-up URLs and remembers what
 * it was asked: so a test knows which files exist (the fixture's) and what
 * each one is built to (the resolver's), whatever the real theme has.
 *
 *   blocks/alpha, early, late, late-two   a stylesheet and a script
 *   blocks/beta, inner                    a stylesheet only
 *   blocks/gamma                          a script only
 *   blocks/plain                          neither
 *   form/form                             the bundle wp-go-blocks.json lists
 *   extra/extra                           a bundle it doesn't
 *
 * Each block is registered under `acf/assets-<folder>` with registered()
 * alone: entry_bases() reads only that, and nothing here needs WordPress's
 * block registry.
 */

use WpGo\Blocks\Assets\AssetResolver;
use WpGo\Blocks\Core\Config;
use WpGo\Blocks\Renderer\Renderer;

use function WpGo\Blocks\Core\registered;

const ASSETS_FIXTURE = __DIR__ . '/assets-theme';
const ASSETS_CDN     = 'https://cdn.test/';

/**
 * A stylesheet's URL is its own; a script's is its own, plus a chunk and a
 * stylesheet every script shares (as a vendor chunk is), and one of its own
 * chunk: so merging has something to make once.
 */
final class AssetsFakeResolver implements AssetResolver
{
    /** @var string[] Every source asked for, in order. */
    public array $asked = [];

    public function urls(string $source): array
    {
        $this->asked[] = $source;
        $name = substr($source, 0, (int) strrpos($source, '.'));

        if (str_ends_with($source, '.scss')) {
            return ['css' => [ASSETS_CDN . $name . '.css'], 'js' => [], 'preload' => []];
        }

        return [
            'css'     => [ASSETS_CDN . 'shared.css'],
            'js'      => [ASSETS_CDN . $name . '.js'],
            'preload' => [ASSETS_CDN . 'chunk-shared.js', ASSETS_CDN . $name . '-chunk.js'],
        ];
    }
}

/**
 * A renderer that draws nothing but the block's name, so render_block() can
 * run for real (it is what records a block) without Twig or a template file.
 */
final class AssetsFakeRenderer implements Renderer
{
    public function extension(): string
    {
        return 'fake';
    }

    public function globals(): array
    {
        return [];
    }

    public function render(string $file, array $context): string
    {
        return '<div data-rendered="' . esc_attr($context['block']['name'] ?? '') . '"></div>';
    }

    public function output(mixed ...$args): void
    {
    }

    public function expose(string $name, callable $fn): void
    {
    }
}

/**
 * The package's settings as boot() left them, with `$changes` over them, and
 * the fixture theme as the root. Config has no setter for one key: set()
 * takes them all.
 *
 * @param array<string,mixed> $changes
 */
function assets_configure(array $changes = []): void
{
    $settings = (new ReflectionProperty(Config::class, 'settings'))->getValue();

    Config::set(ASSETS_FIXTURE, array_replace($settings, ['blocks' => 'blocks', 'bundles' => ['form/form']], $changes));
}

/** The fixture's blocks, registered by name as the Blocks part would. */
function assets_register(): void
{
    foreach (['alpha', 'beta', 'gamma', 'inner', 'early', 'late', 'late-two', 'plain'] as $folder) {
        registered("acf/assets-$folder", ASSETS_FIXTURE . "/blocks/$folder");
    }
}

/** A block as it is stored in post_content, self-closing. */
function assets_block(string $name, string $inner = ''): string
{
    return $inner === ''
        ? "<!-- wp:$name /-->"
        : "<!-- wp:$name -->$inner<!-- /wp:$name -->";
}

/** A published post with that content, in the transaction. */
function assets_post(string $content, string $title = 'Assets test'): WP_Post
{
    $id = wp_insert_post(['post_title' => $title, 'post_content' => $content, 'post_status' => 'publish', 'post_type' => 'post'], true);

    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }

    return get_post($id);
}

/** The main query, as WordPress would have run it for these query vars. */
function assets_query(array $vars): WP_Query
{
    $GLOBALS['wp_query']     = new WP_Query($vars);
    $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];

    return $GLOBALS['wp_query'];
}

/** What a function prints. */
function assets_output(callable $fn): string
{
    ob_start();

    try {
        $fn();
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
}

/** The URL as the tags print it. */
function assets_url(string $path): string
{
    return ASSETS_CDN . $path;
}

/** A tag the part prints, as it prints it. */
function assets_tag(string $kind, string $path): string
{
    $url = esc_url(assets_url($path));

    return match ($kind) {
        'preload' => '<link rel="modulepreload" crossorigin data-block-asset href="' . $url . '">' . "\n",
        'js'      => '<script type="module" crossorigin data-block-asset src="' . $url . '"></script>' . "\n",
        'css'     => '<link rel="stylesheet" crossorigin data-block-asset href="' . $url . '">' . "\n",
    };
}

/** Several tags of one kind, in order. */
function assets_tags(string $kind, string ...$paths): string
{
    return implode('', array_map(fn ($path) => assets_tag($kind, $path), $paths));
}

/**
 * A block rendered for real, as ACF would call render_block() for it: its
 * recording is what the late slots read.
 */
function assets_render(string $folder): string
{
    return assets_output(fn () => \WpGo\Blocks\Core\render_block([
        'name' => "acf/assets-$folder",
        'id'   => 'block_assets_' . $folder,
        'path' => ASSETS_FIXTURE . "/blocks/$folder",
        'data' => [],
    ]));
}
