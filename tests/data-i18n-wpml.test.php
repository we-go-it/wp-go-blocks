<?php

// @require fixtures/data-wpml-stub.php

/**
 * Rendering as data with WPML (a stub: fixtures/data-wpml-stub.php), through
 * Core\I18n: WPML first, its languages, the switch to a language and back to
 * the one before (wpml_current_language, wpml_switch_language) however the
 * callable ends, the homes from wpml_permalink and wpml_home_url, a post's
 * language and its published translations by trid. And the content API
 * asking it: an unknown `lang` a 400, a known one switched to and back.
 */

require __DIR__ . '/fixtures/data-blocks.php';

use WpGo\Blocks\Core\I18n;

$wpml = &$GLOBALS['dx_wpml'];
$home = rtrim(get_option('home'), '/');

section('The plugin, the languages');

same('wpml', I18n::plugin(), 'WPML is the plugin when it is active, WP-Go Languages or not');
check('it and en are languages, from wpml_active_languages', I18n::is_language('it') && I18n::is_language('en'));
check('de is not', !I18n::is_language('de'));

same([
    ['code' => 'it', 'name' => 'Italiano', 'locale' => 'it_IT', 'url' => $home . '/'],
    ['code' => 'en', 'name' => 'English', 'locale' => 'en_US', 'url' => $home . '/en/'],
], I18n::languages(), 'languages(): code, native name, default locale, and each url its home (wpml_permalink), not WPML\'s url');

same($home . '/en/', I18n::home_url('en'), 'home_url(\'en\') through wpml_permalink');
same($home . '/', I18n::home_url(), 'home_url() with no language through wpml_home_url, the current one\'s');

section('Switching');

$wpml['switches'] = [];
same(true, I18n::switch('en'), 'switch(\'en\') switches');
same(['en', 'en'], [$wpml['current'], end($wpml['switches'])], 'through wpml_switch_language');
same($home . '/en/', I18n::home_url(), 'the current home is then English');
I18n::restore();
same(['en', 'it'], $wpml['switches'], 'restore() switches back to the language before, read from wpml_current_language');
same('it', $wpml['current'], 'Italian is current again');

$wpml['switches'] = [];
same(false, I18n::switch('de'), 'a code that isn\'t a language doesn\'t switch');
same([], $wpml['switches'], 'nothing asked of WPML');

$wpml['switches'] = [];
same(['en', 'en'], I18n::in('en', fn (?string $lang) => [$lang, $GLOBALS['dx_wpml']['current']]), 'in(\'en\', $fn): $fn given en, with en current');
same(['en', 'it'], $wpml['switches'], 'switched there and back');

$wpml['switches'] = [];
I18n::in('en', fn () => I18n::in('it', fn () => null));
same(['en', 'it', 'en', 'it'], $wpml['switches'], 'nested: each restore goes back to the language the switch left');

$wpml['switches'] = [];
try {
    I18n::in('en', function () {
        throw new RuntimeException('dx');
    });
} catch (RuntimeException $e) {
}
same(['en', 'it'], $wpml['switches'], 'what $fn throws doesn\'t keep the language');

section('A post\'s language and translations');

$it = dx_post(['post_name' => 'dx-wpml-it']);
$en = dx_post(['post_name' => 'dx-wpml-en']);
$draft = dx_post(['post_name' => 'dx-wpml-draft', 'post_status' => 'draft']);
$wpml['elements'] = [
    "post_page:$it" => ['trid' => 7001, 'lang' => 'it'],
    "post_page:$en" => ['trid' => 7001, 'lang' => 'en'],
    "post_page:$draft" => ['trid' => 7002, 'lang' => 'en'],
];
same('en', I18n::language_of(get_post($en)), 'language_of() through wpml_element_language_code');
same('', I18n::language_of(get_post(dx_post(['post_name' => 'dx-wpml-none']))), 'a post WPML knows nothing of has \'\'');

$translations = I18n::translations(get_post($it));
same(['it', 'en'], array_keys($translations), 'translations() by the post\'s trid, itself included');
same(['id' => $en, 'slug' => 'dx-wpml-en', 'url' => $home . '/en' . substr(get_permalink($en), strlen($home))], $translations['en'] ?? null, 'each { id, slug, url }, the url through wpml_permalink in its own language');
same(get_permalink($it), $translations['it']['url'] ?? null, 'the default language\'s as it is');

$wpml['elements']["post_page:$it"]['trid'] = 7002;
same(['it'], array_keys(I18n::translations(get_post($it))), 'a translation that isn\'t published is left out');

section('The content API');

$wpml['switches'] = [];
[$status, , $code] = dx_rest('GET', '/content', ['slug' => 'dx-wpml-it', 'lang' => 'de']);
same([400, 'wp_go_blocks_bad_lang'], [$status, $code], 'a language WPML doesn\'t have is a 400');
same([], $wpml['switches'], 'nothing switched for it');

[$status, $data] = dx_rest('GET', '/content', ['slug' => 'dx-wpml-en', 'lang' => 'en']);
same([200, 'en'], [$status, $data['lang'] ?? null], 'lang=en answers in English');
same('it', $wpml['current'], 'and Italian is current again after');
check('switched to English and back, for the lookup and for the answer', $wpml['switches'] === ['en', 'it', 'en', 'it']);

[$status, $site] = dx_rest('GET', '/site', ['lang' => 'en']);
same([200, 'en', ['it', 'en']], [$status, $site['lang'] ?? null, array_column($site['languages'] ?? [], 'code')], 'GET /site in English, with WPML\'s languages');
same($home . '/en/', $site['url'] ?? null, 'its url the English home');
