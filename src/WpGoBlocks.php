<?php

namespace WpGo\Blocks;

use WpGo\Blocks\Core\Config;
use WpGo\Blocks\Entities\Entities;
use WpGo\Blocks\Renderer\Renderer;

use function WpGo\Blocks\Core\block;
use function WpGo\Blocks\Core\content;
use function WpGo\Blocks\Core\context;
use function WpGo\Blocks\Core\declare_bundle;
use function WpGo\Blocks\Core\field_data;
use function WpGo\Blocks\Core\notice;
use function WpGo\Blocks\Core\registered;
use function WpGo\Blocks\Core\render_block;
use function WpGo\Blocks\Core\render_page;

/**
 * The package as a theme's PHP calls it, the way it calls Timber: static, and
 * global, aliased to `WpGoBlocks` (boot.php), so a template needs no `use`.
 *
 *   $context = WpGoBlocks::context();
 *   $context['post'] = Timber::get_post();
 *   WpGoBlocks::render(['single-event.twig', 'base.twig'], $context);
 *
 * The page methods (context(), render(), compile()) go to the renderer the
 * blocks use (Renderer: TimberRenderer, say) and the fetches (get_post(),
 * get_posts(), get_menu()) to the entities posts and menus come from
 * (Entities: TimberEntities), so a theme's templates name no engine. The rest
 * (block(), content(), field_data(), notice(), config()…) is the package's
 * own work, reached from here: with boot(), this is all a theme or a plugin
 * calls, and what is under WpGo\Blocks\Core stays internal.
 */
final class WpGoBlocks
{
    /**
     * The renderer's global context, `$extra` merged over it: what a page's
     * context starts from. Timber::context(), with TimberRenderer.
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    public static function context(array $extra = []): array
    {
        return context($extra);
    }

    /**
     * Print a page: the arguments go to the renderer as given, however many.
     * Timber::render()'s, with TimberRenderer.
     */
    public static function render(mixed ...$args): void
    {
        render_page(...$args);
    }

    /**
     * A template, rendered to a string. `$template` is relative to the theme
     * and has no extension: the renderer's is added
     * (`blocks/event-grid/event-grid-items` → `….twig` with Timber).
     *
     * @param array<string,mixed> $context
     */
    public static function compile(string $template, array $context = []): string
    {
        $renderer = Config::renderer();

        if (!$renderer) {
            return '';
        }

        return $renderer->render(Config::root() . '/' . ltrim($template, '/') . '.' . $renderer->extension(), $context);
    }

    /**
     * A post, from the entities: Timber::get_post(), with TimberEntities. The
     * arguments go to them as given, but for `global` (fetch()).
     */
    public static function get_post(mixed ...$args): mixed
    {
        return self::fetch(fn (array $args) => Config::entities()?->get_post(...$args), $args);
    }

    /**
     * Posts, from the entities: Timber::get_posts(), with TimberEntities.
     *
     * @return iterable<mixed>
     */
    public static function get_posts(mixed ...$args): iterable
    {
        return self::fetch(fn (array $args) => Config::entities()?->get_posts(...$args) ?? [], $args);
    }

    /**
     * A menu, from the entities: Timber::get_menu(), with TimberEntities.
     */
    public static function get_menu(mixed ...$args): mixed
    {
        return self::fetch(fn (array $args) => Config::entities()?->get_menu(...$args), $args);
    }

    /**
     * A fetch, with `'global' => true` in any of its array arguments (a
     * query's args, get_menu()'s or get_post()'s options) taken out, and
     * announced: what it fetches is the same on every page (a footer's
     * offices, the main menu), the site's rather than the page's.
     *
     *   wp_go_blocks/global_start  ()         before it
     *   wp_go_blocks/global_end    ($result)  after it, with what it fetched
     *
     * A page cache that tags pages by what they show (WP-Go's cache-tags
     * feature) files those as the site's, so changing them empties the
     * cache rather than every page being filed under them. With no one
     * listening, `global` changes nothing.
     *
     *   WpGoBlocks::get_posts(['post_type' => 'office', 'global' => true]);
     *   WpGoBlocks::get_menu('main', ['global' => true]);
     *   WpGoBlocks::get_post($id, ['global' => true]);
     */
    private static function fetch(callable $fetch, array $args): mixed
    {
        $global = false;
        foreach ($args as $i => $arg) {
            if (is_array($arg) && array_key_exists('global', $arg)) {
                $global = $global || (bool) $arg['global'];
                unset($args[ $i ]['global']);
            }
        }

        if (!$global) {
            return $fetch($args);
        }

        do_action('wp_go_blocks/global_start');
        $result = null;
        try {
            return $result = $fetch($args);
        } finally {
            do_action('wp_go_blocks/global_end', $result);
        }
    }

    /**
     * The renderCallback every block.json names:
     * `"renderCallback": "WpGoBlocks::render_block"`. ACF calls it, with two
     * arguments more (`$wp_block`, `$context`) that it doesn't read.
     *
     * @param array<string,mixed> $block
     */
    public static function render_block($block, $content = '', $is_preview = false, $post_id = 0): void
    {
        render_block($block, $content, $is_preview, $post_id);
    }

    /**
     * A block a template draws itself: `wp_go_render_block()`, from PHP. See block().
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $options
     */
    public static function block(string $slug, array $data = [], array $options = []): string
    {
        return block($slug, $data, $options);
    }

    /**
     * The post's content, where a template puts it: a marker for its blocks
     * while a page is built as data, its HTML otherwise. See content().
     */
    public static function content(): string
    {
        return content();
    }

    /**
     * Block name → its folder, for every block registered (on `init`).
     *
     * @return array<string,string>
     */
    public static function registered(): array
    {
        return registered();
    }

    /**
     * Values by field name → a block's data as the editor stores it, for a
     * seeder writing post_content.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function field_data(string $slug, array $data): array
    {
        return field_data($slug, $data);
    }

    /**
     * Load a bundle that is not a block (`form/form`) on the page being drawn:
     * for what a plugin renders. Only a base wp-go-blocks.json lists under
     * `bundles` is taken, as only those are built; any other is refused with
     * a warning.
     */
    public static function declare_bundle(string $base): void
    {
        declare_bundle($base);
    }

    /**
     * A template bug as a notice on the page with WP_DEBUG on, else ''.
     */
    public static function notice(string $message): string
    {
        return notice($message);
    }

    /**
     * A resolved setting: wp-go-blocks.json's, boot()'s options, or the default.
     */
    public static function config(string $key): mixed
    {
        return Config::get($key);
    }

    /**
     * The renderer the blocks use, or null without one.
     */
    public static function renderer(): ?Renderer
    {
        return Config::renderer();
    }

    /**
     * Render with another renderer for a while (one request rendering as
     * data): returns the one it replaces, to put back.
     */
    public static function use_renderer(?Renderer $renderer): ?Renderer
    {
        return Config::use_renderer($renderer);
    }

    /**
     * The entities posts and menus come from, or null without them.
     */
    public static function entities(): ?Entities
    {
        return Config::entities();
    }
}
