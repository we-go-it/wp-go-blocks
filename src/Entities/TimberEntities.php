<?php

namespace WpGo\Blocks\Entities;

/**
 * Entities as Timber 2 wraps them: Timber\Post, its collections, Timber\Menu.
 * Needs timber/timber, which the package only suggests.
 */
final class TimberEntities implements Entities
{
    public function get_post(mixed ...$args): mixed
    {
        return \Timber\Timber::get_post(...$args);
    }

    /**
     * Always posts, as the interface promises. Timber::get_posts() is a
     * collection for a query, but one Timber\Post for an ID (`$id`,
     * `['ID' => $id]`) and null for what it can't query — [] here, and
     * the post alone in a list.
     */
    public function get_posts(mixed ...$args): iterable
    {
        $posts = \Timber\Timber::get_posts(...$args);

        if ($posts === null) {
            return [];
        }

        return is_iterable($posts) ? $posts : [$posts];
    }

    public function get_menu(mixed ...$args): mixed
    {
        return \Timber\Timber::get_menu(...$args);
    }
}
