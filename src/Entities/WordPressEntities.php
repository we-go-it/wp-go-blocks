<?php

namespace WpGo\Blocks\Entities;

/**
 * Entities as WordPress's own objects: a post is a WP_Post, posts are
 * WP_Query's, a menu is its items.
 */
final class WordPressEntities implements Entities
{
    /**
     * No argument: the queried post, else the loop's. An ID or a WP_Post:
     * that post. Query args: the first post they find.
     */
    public function get_post(mixed ...$args): mixed
    {
        $query = $args[0] ?? null;

        if ($query === null) {
            $queried = get_queried_object();

            return $queried instanceof \WP_Post ? $queried : get_post();
        }

        if (is_array($query)) {
            return $this->get_posts(array_merge($query, ['posts_per_page' => 1]))[0] ?? null;
        }

        return get_post($query);
    }

    /**
     * No argument: the main query's posts. Query args, or a WP_Query: its
     * posts.
     *
     * @return array<int,\WP_Post>
     */
    public function get_posts(mixed ...$args): iterable
    {
        $query = $args[0] ?? null;

        if ($query instanceof \WP_Query) {
            return $query->posts;
        }

        if (is_array($query)) {
            return (new \WP_Query($query))->posts;
        }

        return $GLOBALS['wp_query']->posts ?? [];
    }

    /**
     * A menu's items (WP_Post nav menu items, in order), found by location
     * first, then by ID, slug or name. Null when there is no such menu.
     *
     * @return array<int,\WP_Post>|null
     */
    public function get_menu(mixed ...$args): mixed
    {
        $which     = $args[0] ?? null;
        $locations = get_nav_menu_locations();

        if (is_string($which) && isset($locations[$which])) {
            $which = $locations[$which];
        }

        $menu = $which !== null ? wp_get_nav_menu_object($which) : null;

        return $menu ? (wp_get_nav_menu_items($menu->term_id) ?: []) : null;
    }
}
