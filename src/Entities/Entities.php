<?php

namespace WpGo\Blocks\Entities;

/**
 * Where a theme's posts and menus come from: what `WpGoBlocks::get_post()`,
 * `::get_posts()` and `::get_menu()` hand its templates. Rendering is the
 * Renderer's; this is fetching only.
 *
 * boot() requires one, as it does a renderer; there is no default:
 *
 *   \WpGo\Blocks\boot(__DIR__ . '/wp-go-blocks.json', [
 *       'renderer' => new TimberRenderer(),
 *       'entities' => new TimberEntities(),
 *   ]);
 *
 * The package ships WordPressEntities (WordPress's own objects) and
 * TimberEntities (Timber's). The arguments of each method are the
 * implementation's own, passed through as given.
 */
interface Entities
{
    /**
     * A post: a WP_Post, a Timber\Post… With Timber, Timber::get_post()'s
     * arguments: none for the queried post, an ID, a WP_Post, or query args.
     */
    public function get_post(mixed ...$args): mixed;

    /**
     * Posts. With Timber, Timber::get_posts()'s arguments: none for the main
     * query's, or query args.
     *
     * @return iterable<mixed>
     */
    public function get_posts(mixed ...$args): iterable;

    /**
     * A menu. With Timber, Timber::get_menu()'s arguments: a location, a slug,
     * a name or an ID.
     */
    public function get_menu(mixed ...$args): mixed;
}
