<?php

/**
 * Rendering as data, languages through Core\I18n with WP-Go Languages (the
 * dev site's: it, the default, and en): which plugin, which codes are
 * languages, switch() and restore() balanced (a code that isn't one never
 * switches, so never restores), in() putting the language back however its
 * callable ends, the homes, the languages, a post's language and its
 * published translations, each in its own language.
 */

require __DIR__ . '/fixtures/data-blocks.php';

use WpGo\Blocks\Core\I18n;
use WpGo\Languages\Core\State;

// A REST request's language state, as WP-Go Languages sets it for one:
// the site's URLs carry their language
$state = [State::$front, State::$context];
State::$front = true;
State::$context = 'rest';

$depth = fn () => count(State::$stack);

section('The plugin, the languages');

same('wp-go-languages', I18n::plugin(), 'WP-Go Languages is the plugin, with WPML absent');
check('it and en are languages', I18n::is_language('it') && I18n::is_language('en'));
check('de is not', !I18n::is_language('de'));
check('an empty code is not', !I18n::is_language(''));

$languages = I18n::languages();
same(['it', 'en'], array_column((array) $languages, 'code'), 'languages() lists the active languages, the default first');
same(['code', 'name', 'locale', 'url'], array_keys($languages[0] ?? []), 'each { code, name, locale, url }');
same(array_column(WpGoLanguages::languages(), 'locale'), array_column((array) $languages, 'locale'), 'the locales are the plugin\'s');
same([WpGoLanguages::home_url('it'), WpGoLanguages::home_url('en')], array_column((array) $languages, 'url'), 'each url its home');

same(WpGoLanguages::home_url('it'), I18n::home_url(), 'home_url() with no language is the current one\'s');
same(WpGoLanguages::home_url('en'), I18n::home_url('en'), 'home_url(\'en\') the English home');
check('the English home carries its segment', str_contains(I18n::home_url('en'), '/en/'));

section('switch() and restore()');

same('it', WpGoLanguages::current(), 'Italian, the default, to start with');
same(true, I18n::switch('en'), 'switch(\'en\') switches');
same('en', WpGoLanguages::current(), 'English is current');
same(1, $depth(), 'one switch stacked');
I18n::restore();
same(['it', 0], [WpGoLanguages::current(), $depth()], 'restore() puts Italian back, nothing stacked');

same(false, I18n::switch('de'), 'a code that isn\'t a language doesn\'t switch');
same(false, I18n::switch(''), 'nor does an empty one');
same(['it', 0], [WpGoLanguages::current(), $depth()], 'nothing changed, nothing to restore');

section('in()');

$ran = I18n::in('en', fn (?string $lang) => [$lang, WpGoLanguages::current(), $depth()]);
same(['en', 'en', 1], $ran, 'in(\'en\', $fn) runs $fn with English current, and gives it the code');
same(['it', 0], [WpGoLanguages::current(), $depth()], 'and puts Italian back after');

same([null, 'it'], I18n::in('', fn (?string $lang) => [$lang, WpGoLanguages::current()]), 'with no language, $fn gets null, in the current one');
same([null, 'it'], I18n::in('de', fn (?string $lang) => [$lang, WpGoLanguages::current()]), 'with one that isn\'t a language, null too');

$nested = I18n::in('en', fn () => I18n::in('it', fn () => [WpGoLanguages::current(), $depth()]) + [2 => WpGoLanguages::current()]);
same(['it', 2, 'en'], $nested, 'nested, each level its language, the outer one back after the inner');
same(['it', 0], [WpGoLanguages::current(), $depth()], 'balanced: nothing left stacked');

$thrown = null;
try {
    I18n::in('en', function () {
        throw new RuntimeException('dx');
    });
} catch (RuntimeException $e) {
    $thrown = $e->getMessage();
}
same('dx', $thrown, 'what $fn throws goes through');
same(['it', 0], [WpGoLanguages::current(), $depth()], 'and the language is put back all the same');

$en = dx_post(['post_name' => 'dx-i18n-en', 'lang' => 'en']);
$it = dx_post(['post_name' => 'dx-i18n-it']);
WpGoLanguages::link($en, $it);
same([$en], array_map(fn (WP_Post $post) => $post->ID, I18n::in('en', fn () => get_posts(['post_type' => 'page', 'post_name__in' => ['dx-i18n-en', 'dx-i18n-it'], 'suppress_filters' => false]))), 'inside in(\'en\'), queries keep to English posts');
same([$it], array_map(fn (WP_Post $post) => $post->ID, get_posts(['post_type' => 'page', 'post_name__in' => ['dx-i18n-en', 'dx-i18n-it'], 'suppress_filters' => false])), 'outside, to the request\'s language');

section('A post\'s language and translations');

same('en', I18n::language_of(get_post($en)), 'language_of() is the post\'s language');
same('it', I18n::language_of(get_post($it)), 'the Italian one\'s');

$translations = I18n::translations(get_post($it));
same(['it', 'en'], array_keys($translations), 'translations() has every published one, itself included, by language');
same(['id' => $en, 'slug' => 'dx-i18n-en', 'url' => get_permalink($en)], $translations['en'] ?? null, 'each { id, slug, url }');
check('each url in its own language, whatever the current one', str_contains($translations['en']['url'] ?? '', '/en/') && !str_contains($translations['it']['url'] ?? '/en/', '/en/'));
same($translations, I18n::in('en', fn () => I18n::translations(get_post($it))), 'the same from inside another language');

wp_update_post(['ID' => $en, 'post_status' => 'draft']);
same(['it'], array_keys(I18n::translations(get_post($it))), 'a translation that isn\'t published is left out');
$alone = dx_post(['post_name' => 'dx-i18n-alone']);
same(['it'], array_keys(I18n::translations(get_post($alone))), 'a post without translations has itself');

[State::$front, State::$context] = $state;
