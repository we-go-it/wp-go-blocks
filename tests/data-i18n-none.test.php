<?php

// @require fixtures/data-no-languages.php

/**
 * Rendering as data with no languages plugin (WP-Go Languages left out for
 * this request, no WPML): Core\I18n answers for one language, and the
 * content API ignores `lang`, any `lang`, with no `lang`, no `languages`
 * and empty `translations` in its answers.
 */

require __DIR__ . '/fixtures/data-blocks.php';

use WpGo\Blocks\Core\I18n;

section('Core\I18n with no plugin');

same(false, class_exists('WpGoLanguages', false), 'WP-Go Languages is not loaded');
same(null, I18n::plugin(), 'no plugin');
check('no code is a language', !I18n::is_language('it') && !I18n::is_language('en'));
same(false, I18n::switch('en'), 'switch() switches nothing');
I18n::restore();
check('restore() does nothing, and doesn\'t fail', true);
same([null, 'ran'], I18n::in('en', fn (?string $lang) => [$lang, 'ran']), 'in() runs $fn, given null');
same(null, I18n::languages(), 'languages() is null');
same(home_url('/'), I18n::home_url(), 'home_url() is the site\'s home');
same(home_url('/'), I18n::home_url('en'), 'whatever language asked');

$page = dx_post(['post_name' => 'dx-solo', 'post_content' => dx_paragraph('Solo')]);
same('', I18n::language_of(get_post($page)), 'a post has no language: \'\'');
same([], I18n::translations(get_post($page)), 'nor translations');

section('The content API ignores lang');

same(true, \WpGo\Blocks\Core\Config::get('content_api'), 'the routes are on without a languages plugin too');

[$status, $data] = dx_rest('GET', '/content', ['slug' => 'dx-solo']);
same(200, $status, 'a page by its slug');
same([], $data['translations'] ?? null, 'translations is {}');
check('no lang', !array_key_exists('lang', (array) $data));

[$status, $data] = dx_rest('GET', '/content', ['slug' => 'dx-solo', 'lang' => 'en']);
same(200, $status, 'lang=en is ignored: the page');
check('and no lang in the answer', !array_key_exists('lang', (array) $data));
same(200, dx_rest('GET', '/content', ['slug' => 'dx-solo', 'lang' => 'de'])[0], 'any lang is ignored, not a 400');

$request = new WP_REST_Request('GET', '/wp-go-blocks/v1/content');
$request->set_param('slug', 'dx-solo');
check('translations encodes as {}, an object', (rest_do_request($request)->get_data()['translations'] ?? null) instanceof stdClass);

[$status, $site] = dx_rest('GET', '/site', ['lang' => 'de']);
same(200, $status, 'GET /site ignores lang too');
check('no lang and no languages in it', !array_key_exists('lang', (array) $site) && !array_key_exists('languages', (array) $site));
same(home_url('/'), $site['url'] ?? null, 'url is the site\'s home');
