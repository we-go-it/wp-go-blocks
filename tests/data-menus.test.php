<?php

/**
 * Rendering as data, menus (Payload/Menus.php, through PayloadRenderer's
 * menu() and menus()): a menu found by location, then by ID, slug or name;
 * its items a tree by parent in menu order; each item's kind, object, url
 * worked out from what it links to, its own attributes, classes and ACF
 * fields; `kind` the item's own type for anything else, which a theme
 * renames on wp_go_blocks/payload_menu_item (the base theme's container
 * item is `container`); rows a filter adds made data with the rest.
 */

use WpGo\Blocks\Renderer\PayloadRenderer;

register_post_type('dx_event', ['public' => true, 'has_archive' => 'dx-events', 'rewrite' => ['slug' => 'dx-event']]);
register_taxonomy('dx_topic', 'page', ['public' => true]);
register_nav_menus(['dx_main' => 'DX main', 'dx_empty' => 'DX empty', 'dx_footer' => 'DX footer']);

$about = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'About', 'post_name' => 'dx-about']);
$term = wp_insert_term('Topic', 'dx_topic', ['slug' => 'dx-topic']);
$term_id = (int) $term['term_id'];

$menu = wp_create_nav_menu('DX Main menu');
$add = fn (array $args) => wp_update_nav_menu_item($menu, 0, $args + ['menu-item-status' => 'publish']);

// added out of order: the tree comes in menu order
$custom = $add(['menu-item-type' => 'custom', 'menu-item-title' => 'Out &amp; about', 'menu-item-url' => 'https://example.test/out', 'menu-item-target' => '_blank', 'menu-item-xfn' => 'noopener', 'menu-item-description' => 'Elsewhere', 'menu-item-classes' => 'dx-a  dx-b', 'menu-item-position' => 4]);
$page_item = $add(['menu-item-type' => 'post_type', 'menu-item-object' => 'page', 'menu-item-object-id' => $about, 'menu-item-title' => 'About us', 'menu-item-position' => 1]);
$term_item = $add(['menu-item-type' => 'taxonomy', 'menu-item-object' => 'dx_topic', 'menu-item-object-id' => $term_id, 'menu-item-title' => 'Topic', 'menu-item-position' => 2]);
$archive_item = $add(['menu-item-type' => 'post_type_archive', 'menu-item-object' => 'dx_event', 'menu-item-title' => 'Events', 'menu-item-position' => 3]);
$child = $add(['menu-item-type' => 'custom', 'menu-item-title' => 'Child', 'menu-item-url' => 'https://example.test/child', 'menu-item-parent-id' => $page_item, 'menu-item-position' => 6]);
$first_child = $add(['menu-item-type' => 'custom', 'menu-item-title' => 'First child', 'menu-item-url' => 'https://example.test/first', 'menu-item-parent-id' => $page_item, 'menu-item-position' => 5]);
$grandchild = $add(['menu-item-type' => 'custom', 'menu-item-title' => 'Grandchild', 'menu-item-url' => 'https://example.test/g', 'menu-item-parent-id' => $child, 'menu-item-position' => 7]);
$container = $add(['menu-item-type' => 'wg_nav_container', 'menu-item-title' => 'Panel', 'menu-item-url' => '', 'menu-item-position' => 8]);
$own_type = $add(['menu-item-type' => 'dx_own', 'menu-item-title' => 'Own type', 'menu-item-url' => '', 'menu-item-position' => 9]);

// the item's ACF fields
acf_add_local_field_group([
    'key' => 'group_dx_menu_item',
    'title' => 'DX menu item',
    'location' => [[['param' => 'nav_menu_item', 'operator' => '==', 'value' => 'all']]],
    'fields' => [['key' => 'field_dx_menu_layout', 'name' => 'dx_layout', 'label' => 'Layout', 'type' => 'text']],
]);
update_field('field_dx_menu_layout', 'columns', $container);

$footer = wp_create_nav_menu('DX Footer');
wp_update_nav_menu_item($footer, 0, ['menu-item-type' => 'custom', 'menu-item-title' => 'F', 'menu-item-url' => 'https://example.test/f', 'menu-item-status' => 'publish']);

// the menu shows in two locations, the footer in its own; dx_empty has none
set_theme_mod('nav_menu_locations', ['dx_main' => $menu, 'dx_footer' => $footer, 'dx_also' => $menu]);

section('Finding a menu');

$data = PayloadRenderer::menu('dx_main');
same(['id', 'name', 'slug', 'location', 'items'], array_keys((array) $data), 'a menu is { id, name, slug, location, items }');
same([$menu, 'DX Main menu', 'dx-main-menu', 'dx_main'], [$data['id'] ?? null, $data['name'] ?? null, $data['slug'] ?? null, $data['location'] ?? null], 'by location: its menu, `location` the one asked');
same('dx_also', PayloadRenderer::menu('dx_also')['location'] ?? null, 'asked by another location that shows it, that location');
$by_id = PayloadRenderer::menu($menu);
same($data['items'], $by_id['items'] ?? null, 'by ID, the same menu');
same('dx_main', $by_id['location'] ?? null, 'asked by ID, `location` is the first location that shows it');
same($menu, PayloadRenderer::menu('dx-main-menu')['id'] ?? null, 'by slug');
same($menu, PayloadRenderer::menu('DX Main menu')['id'] ?? null, 'by name');
same(null, PayloadRenderer::menu('dx_empty'), 'a location without a menu is null');
same(null, PayloadRenderer::menu('no-such-menu'), 'a name that is nothing is null');
same(null, PayloadRenderer::menu(null), 'null is null');
same(null, PayloadRenderer::menu(0), '0 is null');

$unplaced = wp_create_nav_menu('DX Nowhere');
check('a menu in no location has no `location`', !array_key_exists('location', (array) PayloadRenderer::menu($unplaced)));

$all = PayloadRenderer::menus();
check('menus() has every registered location that has a menu, by location', isset($all['dx_main'], $all['dx_footer']));
check('a location with no menu is left out', !array_key_exists('dx_empty', $all));
check('a location that isn\'t registered is left out, whatever it is given', !array_key_exists('dx_also', $all));

// A location with no menu, named as a menu is: menu() takes a name that
// is no location as a menu's slug, but menus() is the locations' menus.
register_nav_menus(['dx-slugged' => 'DX slugged']);
wp_create_nav_menu('dx-slugged');
same(null, PayloadRenderer::menus()['dx-slugged'] ?? null, 'a location with no menu is left out, even when a menu has its name for a slug');
same($data, $all['dx_main'] ?? null, 'each is menu() for its location');

section('The tree');

$items = $data['items'];
same(['About us', 'Topic', 'Events', 'Out & about', 'Panel', 'Own type'], array_column($items, 'title'), 'the top level, in menu order, children not among them; titles as text');
$about_row = $items[0];
same(['First child', 'Child'], array_column($about_row['children'], 'title'), 'children under their parent, in menu order');
same(['Grandchild'], array_column($about_row['children'][1]['children'], 'title'), 'at any depth');
same([], $items[1]['children'], 'an item with none has `children` empty');

section('Each item');

same(['id' => $page_item, 'title' => 'About us', 'kind' => 'post', 'object' => ['type' => 'page', 'id' => $about], 'url' => get_permalink($about), 'classes' => []], array_diff_key($about_row, ['children' => 1]), 'a page item: kind post, object { type, id }, its url, no empty keys');
same(['kind' => 'term', 'object' => ['taxonomy' => 'dx_topic', 'id' => $term_id], 'url' => get_term_link($term_id, 'dx_topic')], array_intersect_key($items[1], ['kind' => 1, 'object' => 1, 'url' => 1]), 'a term item: kind term, object { taxonomy, id }, the term\'s link');
same(['kind' => 'archive', 'object' => ['type' => 'dx_event'], 'url' => get_post_type_archive_link('dx_event')], array_intersect_key($items[2], ['kind' => 1, 'object' => 1, 'url' => 1]), 'an archive item: kind archive, object { type }, the archive\'s link');

$custom_row = $items[3];
same('custom', $custom_row['kind'], 'a custom link: kind custom');
check('a custom link has no object', !array_key_exists('object', $custom_row));
same('https://example.test/out', $custom_row['url'] ?? null, 'it keeps its own url');
same(['_blank', 'noopener', 'Elsewhere'], [$custom_row['target'] ?? null, $custom_row['rel'] ?? null, $custom_row['description'] ?? null], 'target, rel (the item\'s xfn) and description are the item\'s');
same(['dx-a', 'dx-b'], $custom_row['classes'] ?? null, 'classes are what the editor typed, empty ones dropped');
check('an item without target, rel or description has none of the keys', !array_intersect_key($about_row, ['target' => 1, 'rel' => 1, 'description' => 1]));

same('container', $items[4]['kind'] ?? null, 'the base theme names its container item (wg_nav_container) `container`, on wp_go_blocks/payload_menu_item');
same(['dx_layout' => 'columns'], $items[4]['fields'] ?? null, 'an item\'s ACF fields are its `fields`');
check('an item with no fields has no `fields`', !array_key_exists('fields', $about_row));
same('dx_own', $items[5]['kind'] ?? null, 'an item type nobody names is its own type');
check('an item with neither object nor url has no `url`', !array_key_exists('url', $items[5]));

// The url comes from what the item links to, now: renamed after the item
// was saved, the page's new address, not the one stored with the item.
wp_update_post(['ID' => $about, 'post_name' => 'dx-about-renamed']);
same(get_permalink($about), PayloadRenderer::menu('dx_main')['items'][0]['url'] ?? null, 'a page item\'s url follows the page, not the url stored with the item');
check('the renamed address is the one given', str_contains((string) (PayloadRenderer::menu('dx_main')['items'][0]['url'] ?? ''), 'dx-about-renamed'));

section('wp_go_blocks/payload_menu_item');

$seen = [];
$filter = function (array $row, WP_Post $item) use (&$seen, $about) {
    $seen[] = [$item->ID, array_key_exists('target', $row) && $row['target'] === null];

    if ($row['kind'] === 'dx_own') {
        $row['kind'] = 'mine';
        $row['children'] = [[
            'id' => 1, 'title' => 'Added &amp; made data', 'kind' => 'post', 'object' => ['type' => 'page', 'id' => $about],
            'url' => get_permalink($about), 'post' => get_post($about), 'gone' => null, 'classes' => [], 'children' => [],
        ]];
    }

    return $row;
};
add_filter('wp_go_blocks/payload_menu_item', $filter, 20, 2);
$filtered = PayloadRenderer::menu('dx_main');
remove_filter('wp_go_blocks/payload_menu_item', $filter, 20);

check('the filter gets every item, with the raw menu item', count($seen) === 9 && in_array($own_type, array_column($seen, 0), true));
check('it gets the row with its nulls still in it', $seen[0][1] === true);
$own = $filtered['items'][5];
same('mine', $own['kind'], 'what the filter returns is the item: a theme names its own kind');
same('Added & made data', $own['children'][0]['title'] ?? null, 'rows it adds are made data with the rest of the menu');
same(['id' => $about, 'type' => 'page', 'title' => 'About', 'url' => get_permalink($about)], $own['children'][0]['post'] ?? null, 'a post it puts in comes out as data');
check('a null it puts in is left out', !array_key_exists('gone', $own['children'][0]));

section('Titles: decoded once');

$encoded = wp_update_nav_menu_item($footer, 0, ['menu-item-type' => 'custom', 'menu-item-title' => 'Q&amp;amp;A', 'menu-item-url' => 'https://example.test/qa', 'menu-item-status' => 'publish']);
$footer_items = PayloadRenderer::menu('dx_footer')['items'];
same('Q&amp;A', end($footer_items)['title'] ?? null, 'a title is decoded once, never twice');
