<?php

/**
 * Rendering as data, the site API: GET /site's keys (name, description,
 * url, lang, languages, menus), in a language or the request's own; the
 * menus there before wp_go_blocks/site, so a theme can change or drop them,
 * and decoded once when it leaves them; what the theme adds made data; the
 * payload renderer in place while the filter runs; an unknown language.
 */

require __DIR__ . '/fixtures/data-blocks.php';

use WpGo\Blocks\Core\Config;
use WpGo\Blocks\Renderer\PayloadRenderer;
use WpGo\Blocks\Renderer\TimberRenderer;
use WpGo\Languages\Core\State;

// A REST request's language state, as WP-Go Languages sets it for one
$state = [State::$front, State::$context];
State::$front = true;
State::$context = 'rest';

register_nav_menus(['dx_site' => 'DX site']);
$menu = wp_create_nav_menu('DX Site menu');
wp_update_nav_menu_item($menu, 0, ['menu-item-type' => 'custom', 'menu-item-title' => 'Q&amp;amp;A', 'menu-item-url' => 'https://example.test/qa', 'menu-item-status' => 'publish']);
set_theme_mod('nav_menu_locations', array_merge((array) get_theme_mod('nav_menu_locations', []), ['dx_site' => $menu]));

section('The answer');

[$status, $site] = dx_rest('GET', '/site');
same(200, $status, 'GET /site answers anyone');
same(['name', 'description', 'url', 'languages', 'menus'], array_slice(array_keys((array) $site), 0, 5), 'name, description, url, languages, menus first, no lang without one asked; then the theme\'s');
same(get_bloginfo('name'), $site['name'] ?? null, 'name is the site\'s title');
same(get_bloginfo('description'), $site['description'] ?? null, 'description its tagline');
same(WpGoLanguages::home_url('it'), $site['url'] ?? null, 'url is the home, in the request\'s language');
same(['it', 'en'], array_column($site['languages'] ?? [], 'code'), 'languages are the site\'s, in order');
same(['code', 'name', 'locale', 'url'], array_keys($site['languages'][0] ?? []), 'each { code, name, locale, url }');
same(WpGoLanguages::home_url('en'), $site['languages'][1]['url'] ?? null, 'url each language\'s home');
same(PayloadRenderer::menus()['dx_site'] ?? 'none', $site['menus']['dx_site'] ?? null, 'menus: every location that has a menu, as menu() gives it');
same('Q&amp;A', $site['menus']['dx_site']['items'][0]['title'] ?? null, 'a menu item\'s title decoded once, not again as the answer is made data');
check('the theme\'s filter adds its own keys after', count($site) > 5);

[$status, $site_en] = dx_rest('GET', '/site', ['lang' => 'en']);
same([200, 'en'], [$status, $site_en['lang'] ?? null], 'with lang=en, lang in the answer');
same(WpGoLanguages::home_url('en'), $site_en['url'] ?? null, 'url is the English home');
same(['lang', 'languages'], array_slice(array_keys($site_en), 3, 2), 'lang before languages');
check('the language is back after', WpGoLanguages::current() === 'it' && State::$stack === []);

[$status, $error, $code] = dx_rest('GET', '/site', ['lang' => 'de']);
same([400, 'wp_go_blocks_bad_lang'], [$status, $code], 'a language the site doesn\'t have is a 400');

section('wp_go_blocks/site');

$seen = null;
add_filter('wp_go_blocks/site', $spy = function (array $site, ?string $lang) use (&$seen) {
    $seen = [
        'keys' => array_keys($site),
        'menus' => array_key_exists('menus', $site),
        'lang' => $lang,
        'current' => WpGoLanguages::current(),
        'renderer' => WpGoBlocks::renderer(),
    ];

    return $site;
}, 20, 2);
dx_rest('GET', '/site', ['lang' => 'en']);
same(['name', 'description', 'url', 'lang', 'languages', 'menus'], array_slice($seen['keys'] ?? [], 0, 6), 'the filter gets every key, the menus among them');
check('the menus are already there when it runs', $seen['menus'] ?? false);
same('en', $seen['lang'] ?? null, 'its $lang is the language asked for');
same('en', $seen['current'] ?? null, 'already current');
check('the payload renderer is the renderer while it runs', ($seen['renderer'] ?? null) instanceof PayloadRenderer);
check('the site\'s renderer is back after', Config::renderer() instanceof TimberRenderer);
dx_rest('GET', '/site');
check('without lang, $lang is null', is_array($seen) && array_key_exists('lang', $seen) && $seen['lang'] === null);
check('the menus are there, the location\'s among them', $seen['menus'] ?? false);
remove_filter('wp_go_blocks/site', $spy, 20);

add_filter('wp_go_blocks/site', $drop = function (array $site): array {
    unset($site['menus']);

    return $site;
}, 20);
check('a theme can drop the menus: what the filter returns is the answer', !array_key_exists('menus', dx_rest('GET', '/site')[1]));
remove_filter('wp_go_blocks/site', $drop, 20);

$page = dx_post(['post_name' => 'dx-site-page', 'post_title' => 'Site page']);
add_filter('wp_go_blocks/site', $change = function (array $site) use ($page): array {
    $site['menus'] = ['custom' => ['title' => 'Mine &amp; yours', 'page' => get_post($page), 'gone' => null]];
    $site['footer_extra'] = ['phone' => null, 'when' => new DateTimeImmutable('2026-10-17T00:00:00+00:00')];
    $site['name'] = 'Renamed';

    return $site;
}, 20);
$changed = dx_rest('GET', '/site')[1];
remove_filter('wp_go_blocks/site', $change, 20);
same(['custom' => ['title' => 'Mine & yours', 'page' => ['id' => $page, 'type' => 'page', 'title' => 'Site page', 'url' => get_permalink($page)]]], $changed['menus'] ?? null, 'menus the theme changes are what it gave, made data');
same(['when' => '2026-10-17T00:00:00+00:00'], $changed['footer_extra'] ?? null, 'what the theme adds is made data, nulls left out');
same('Renamed', $changed['name'] ?? null, 'and it can change the package\'s keys');

section('No menus');

// no location registered at all (menus() of a location with none is
// data-menus.test.php's)
$registered = $GLOBALS['_wp_registered_nav_menus'];
$GLOBALS['_wp_registered_nav_menus'] = [];
add_filter('wp_go_blocks/site', $raw = fn (array $site) => $site, PHP_INT_MAX);
$request = new WP_REST_Request('GET', '/wp-go-blocks/v1/site');
$response = rest_do_request($request);
check('with no menu anywhere, menus is an empty object, {}', ($response->get_data()['menus'] ?? null) instanceof stdClass);
remove_filter('wp_go_blocks/site', $raw, PHP_INT_MAX);
$GLOBALS['_wp_registered_nav_menus'] = $registered;

[State::$front, State::$context] = $state;
