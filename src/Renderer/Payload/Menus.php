<?php

namespace WpGo\Blocks\Renderer\Payload;

/**
 * The site's menus as data (PayloadRenderer::menu(), ::menus()): a tree of
 * items, the same on every page.
 */
final class Menus
{
    /**
     * A menu as data, found like WordPressEntities' — by location first, then
     * by ID, slug or name — or null:
     *
     *   { id, name, slug, location, items: [ { id, title, kind, object, url,
     *     target, rel, description, classes, fields, children: [ … ] } ] }
     *
     * `kind` is `post`, `term`, `archive`, `custom`, or the item's own type for
     * anything else (a theme's own item type: the theme can name it otherwise
     * on `wp_go_blocks/payload_menu_item`); `classes` the ones an editor
     * typed; `fields` the item's ACF fields. The same on every page: which
     * item is the current page is the front end's to tell, from its route.
     *
     * @return array<string,mixed>|null
     */
    public static function menu(mixed $which): ?array
    {
        $locations = get_nav_menu_locations();
        $location  = is_string($which) && isset($locations[$which]) ? $which : null;
        $menu      = $which !== null ? wp_get_nav_menu_object($location ? $locations[$location] : $which) : null;

        if (!$menu) {
            return null;
        }

        $items = wp_get_nav_menu_items($menu->term_id) ?: [];

        usort($items, fn ($a, $b) => $a->menu_order <=> $b->menu_order);

        $rows = [];

        foreach ($items as $item) {
            $rows[(int) $item->menu_item_parent][] = $item;
        }

        // made data once, the whole tree: its rows are raw until here, the
        // ones a theme adds too
        return Normalizer::normalize([
            'id'       => (int) $menu->term_id,
            'name'     => $menu->name,
            'slug'     => $menu->slug,
            'location' => $location ?? (array_search($menu->term_id, $locations, true) ?: null),
            'items'    => self::items($rows, 0),
        ]);
    }

    /**
     * Every registered menu location that has a menu, as data, by location:
     * the site API's `menus` (ContentApi::site()). The locations' own menus,
     * by the IDs they are given: a location with none is left out, even
     * when a menu has its name for a slug or a name (which menu() would
     * take it as).
     *
     * @return array<string,array<string,mixed>>
     */
    public static function menus(): array
    {
        $menus     = [];
        $locations = get_nav_menu_locations();

        foreach (array_keys(get_registered_nav_menus()) as $location) {
            if (empty($locations[$location])) {
                continue;
            }

            $menu = self::menu($location);

            if ($menu) {
                $menus[$location] = $menu;
            }
        }

        return $menus;
    }

    /**
     * One level of a menu, its children under each item.
     *
     * @param array<int,array<int,\WP_Post>> $rows Items by parent ID.
     * @return array<int,array<string,mixed>>
     */
    private static function items(array $rows, int $parent): array
    {
        $out = [];

        foreach ($rows[$parent] ?? [] as $item) {
            [$kind, $object] = match ($item->type) {
                'post_type'         => ['post', ['type' => $item->object, 'id' => (int) $item->object_id]],
                'taxonomy'          => ['term', ['taxonomy' => $item->object, 'id' => (int) $item->object_id]],
                'post_type_archive' => ['archive', ['type' => $item->object]],
                'custom'            => ['custom', null],
                default             => [$item->type, null],
            };

            $fields = function_exists('get_fields') ? get_fields($item->ID) : null;

            // From the object, not the item's stored `url`: that one was worked
            // out from the object as first saved, and a multilingual plugin
            // (WPML) that points the item at a translation fixes the link only
            // as wp_nav_menu() prints it. A custom link keeps its own.
            $url = match ($kind) {
                'post'    => get_permalink((int) $item->object_id),
                'term'    => get_term_link((int) $item->object_id, (string) $item->object),
                'archive' => get_post_type_archive_link((string) $item->object),
                default   => null,
            };
            $url = is_string($url) && $url !== '' ? $url : ($item->url !== '' ? $item->url : null);

            $row = [
                'id'          => (int) $item->ID,
                'title'       => $item->title,
                'kind'        => $kind,
                'object'      => $object,
                'url'         => $url,
                'target'      => $item->target !== '' ? $item->target : null,
                'rel'         => $item->xfn !== '' ? $item->xfn : null,
                'description' => $item->description !== '' ? $item->description : null,
                'classes'     => array_values(array_filter((array) $item->classes)),
                'fields'      => $fields ?: null,
                'children'    => self::items($rows, (int) $item->ID),
            ];

            /**
             * A menu item as data: for what a theme's own items hold that
             * WordPress doesn't — a container item's children read from somewhere
             * else than the menu (its `layout`'s), say — or what its own item
             * type is called (`kind`, the type itself until then). Rows added under
             * `children` take the same shape. Made data after, with the
             * rest of the menu (Normalizer::normalize()): posts, images and
             * nulls can go in as they are.
             *
             * @param array<string,mixed> $row  The item, nulls still in it.
             * @param \WP_Post            $item WordPress's menu item.
             */
            $out[] = (array) apply_filters('wp_go_blocks/payload_menu_item', $row, $item);
        }

        return $out;
    }
}
