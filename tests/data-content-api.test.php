<?php

/**
 * Rendering as data, the content API (ContentApi), asked in-process with
 * rest_do_request(): GET /content by slug — a page's path, another type's
 * slug, a hierarchical type's path, a slug two types share (409), the
 * front page for an empty slug, published only, a password (401 unless its
 * cookie), `lang` and `type` checked; the answer's shape, with the page's
 * view read for its order; the PHP template WordPress would pick, in its
 * order, then template_include; buffers closed and everything swapped put
 * back, even when the template throws; POST /content, the editor's preview.
 * The site's routes are on (`content_api`, with WP-Go's dev tools) and
 * WP-Go Languages has it and en.
 */

require __DIR__ . '/fixtures/data-blocks.php';

use WpGo\Blocks\Core\Config;
use WpGo\Blocks\Renderer\TimberRenderer;
use WpGo\Languages\Core\State;

// A REST request's language state, as WP-Go Languages sets it for one: the
// site's URLs carry their language, and the front page option is the
// current language's (under WP-CLI it is neither).
$state = [State::$front, State::$context];
State::$front = true;
State::$context = 'rest';

add_filter('timber/locations', fn ($locations) => array_merge((array) $locations, [[DX_FIXTURES . '/data-views']]));

register_post_type('dx_event', ['public' => true, 'hierarchical' => false]);
register_post_type('dx_doc', ['public' => true, 'hierarchical' => true]);
register_post_type('dx_hidden', ['public' => false]);

$content = dx_paragraph('Hello') . "\n\n" . dx_block('dx-card', ['title' => 'Card']);
$templates = DX_FIXTURES . '/data-templates';

/** The language state after a request: the one before, nothing stacked. */
function dx_language_back(): bool
{
    return WpGoLanguages::current() === 'it' && State::$switched === null && State::$stack === [];
}

section('The routes');

$routes = rest_get_server()->get_routes();
check('GET and POST /content and GET /site are registered with content_api on', isset($routes['/wp-go-blocks/v1/content'], $routes['/wp-go-blocks/v1/site']));
same(true, Config::get('content_api'), 'the theme turns content_api on with WP-Go\'s dev tools');

section('A page by its slug');

$page = dx_post(['post_name' => 'dx-about', 'post_title' => 'About &amp; us', 'post_content' => $content]);
[$status, $data] = dx_rest('GET', '/content', ['slug' => 'dx-about']);
same(200, $status, 'a published page by its slug');
same(['post', 'translations', 'template', 'context', 'blocks', 'content_at'], array_keys((array) $data), 'the answer: post, translations, template, context, blocks, content_at; no lang without one asked');
same(['id' => $page, 'type' => 'page', 'title' => 'About & us', 'url' => get_permalink($page)], $data['post'] ?? null, 'post is { id, type, title, url }');
same(['it' => ['id' => $page, 'slug' => 'dx-about', 'url' => get_permalink($page)]], $data['translations'] ?? null, 'translations has the page itself, in its language');
same('page-dx-about.twig', $data['template'] ?? null, 'template is the first view the theme\'s page.php names, whether it exists or not');
same(['post' => $data['post'] ?? null], $data['context'] ?? null, 'context is what page.php added (post): the payload renderer has no globals of Timber\'s');
same(['core/paragraph', 'acf/dx-card:Card'], dx_names($data['blocks'] ?? []), 'its blocks, the content\'s, page.twig printing it');
same(0, $data['content_at'] ?? null, 'content_at 0');
check('the answer has no marker in it', !str_contains((string) wp_json_encode($data), 'wg-drawn'));
same(200, dx_rest('GET', '/content', ['slug' => '/dx-about/'])[0], 'slashes at either end are ignored');
check('the language is back after the request', dx_language_back());
check('the site\'s renderer is back after the request', WpGoBlocks::renderer() instanceof TimberRenderer);

$view = dx_post(['post_name' => 'dx-view', 'post_content' => $content]);
[$status, $data] = dx_rest('GET', '/content', ['slug' => 'dx-view']);
same(['acf/dx-card:Intro+t', 'core/paragraph', 'acf/dx-card:Card', 'acf/dx-card:Outro+t'], dx_names($data['blocks'] ?? []), 'with the page\'s own view (page-dx-view.twig), the view\'s order: its drawn blocks around the content');
same(1, $data['content_at'] ?? null, 'content_at where the view prints the content');

section('Paths, types, ambiguity');

$services = dx_post(['post_name' => 'dx-services']);
$consulting = dx_post(['post_name' => 'dx-consulting', 'post_parent' => $services]);
$other_parent = dx_post(['post_name' => 'dx-other']);
$other_consulting = dx_post(['post_name' => 'dx-consulting', 'post_parent' => $other_parent]);
same($consulting, dx_rest('GET', '/content', ['slug' => 'dx-services/dx-consulting'])[1]['post']['id'] ?? null, 'a child page by its whole path');
same($other_consulting, dx_rest('GET', '/content', ['slug' => 'dx-other/dx-consulting'])[1]['post']['id'] ?? null, 'the same slug under another parent is that one');
same(404, dx_rest('GET', '/content', ['slug' => 'dx-consulting'])[0], 'a child page by its slug alone is no page: the whole path is needed');
same(404, dx_rest('GET', '/content', ['slug' => 'dx-other/dx-services'])[0], 'a path that isn\'t one is a 404');

$guide = dx_post(['post_type' => 'dx_doc', 'post_name' => 'dx-guide']);
$step = dx_post(['post_type' => 'dx_doc', 'post_name' => 'dx-step', 'post_parent' => $guide]);
same($step, dx_rest('GET', '/content', ['slug' => 'dx-guide/dx-step'])[1]['post']['id'] ?? null, 'a hierarchical post type\'s post by its path');

$event = dx_post(['post_type' => 'dx_event', 'post_name' => 'dx-gala', 'post_content' => $content]);
[$status, $data] = dx_rest('GET', '/content', ['slug' => 'dx-gala']);
same([200, $event, 'dx_event'], [$status, $data['post']['id'] ?? null, $data['post']['type'] ?? null], 'another public type\'s post by its slug');
same(404, dx_rest('GET', '/content', ['slug' => 'dx-x/dx-gala'])[0], 'a slug with a / is never a non-hierarchical type\'s post');
same(404, dx_rest('GET', '/content', ['slug' => 'dx-x/dx-gala', 'type' => 'dx_event'])[0], 'not even asked by its type');

$shared_page = dx_post(['post_name' => 'dx-open-day']);
$shared_event = dx_post(['post_type' => 'dx_event', 'post_name' => 'dx-open-day']);
[$status, $data, $code] = dx_rest('GET', '/content', ['slug' => 'dx-open-day']);
same([409, 'wp_go_blocks_ambiguous'], [$status, $code], 'a slug two public types have is a 409: neither wins');
same('More than one post type has that slug (`page`, `dx_event`): ask with `type`.', $data['message'] ?? null, 'the message names the types');
same($shared_page, dx_rest('GET', '/content', ['slug' => 'dx-open-day', 'type' => 'page'])[1]['post']['id'] ?? null, 'with type=page, the page');
same($shared_event, dx_rest('GET', '/content', ['slug' => 'dx-open-day', 'type' => 'dx_event'])[1]['post']['id'] ?? null, 'with type=dx_event, the event');
same($page, dx_rest('GET', '/content', ['slug' => 'dx-about', 'type' => 'PAGE'])[1]['post']['id'] ?? null, 'type is read through sanitize_key()');

[$status, $data, $code] = dx_rest('GET', '/content', ['slug' => 'dx-about', 'type' => 'dx_hidden']);
same([400, 'wp_go_blocks_bad_type'], [$status, $code], 'a type that isn\'t public is a 400');
same('No public post type `dx_hidden`.', $data['message'] ?? null, 'naming it');
same('wp_go_blocks_bad_type', dx_rest('GET', '/content', ['slug' => 'dx-about', 'type' => 'nope', 'lang' => 'de'])[2], 'type is checked before lang');

$attachment = wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => 'dx', 'post_name' => 'dx-attached', 'post_status' => 'inherit'], '2026/10/dx-attached.jpg');
same(404, dx_rest('GET', '/content', ['slug' => 'dx-attached'])[0], 'attachments aren\'t looked in');
same(404, dx_rest('GET', '/content', ['slug' => 'dx-nothing-here'])[0], 'nothing at the slug is a 404');
same('wp_go_blocks_not_found', dx_rest('GET', '/content', ['slug' => 'dx-nothing-here'])[2], 'wp_go_blocks_not_found');

section('Published only');

foreach (['draft', 'private', 'pending'] as $status_name) {
    dx_post(['post_name' => "dx-$status_name", 'post_status' => $status_name]);
    same(404, dx_rest('GET', '/content', ['slug' => "dx-$status_name"])[0], "a $status_name page is a 404, even to a logged-in administrator");
}
dx_post(['post_name' => 'dx-future', 'post_status' => 'future', 'post_date' => '2099-01-01 00:00:00']);
same(404, dx_rest('GET', '/content', ['slug' => 'dx-future'])[0], 'a scheduled page is a 404');

section('Behind a password');

$locked = dx_post(['post_name' => 'dx-locked', 'post_password' => 'secret', 'post_content' => dx_paragraph('Locked words')]);
[$status, $data, $code] = dx_rest('GET', '/content', ['slug' => 'dx-locked']);
same([401, 'wp_go_blocks_password'], [$status, $code], 'a page behind a password is a 401');
check('none of it in the answer', !str_contains((string) wp_json_encode($data), 'Locked words'));
check('being logged in (the administrator) doesn\'t count, as on the site', is_user_logged_in());

require_once ABSPATH . WPINC . '/class-phpass.php';
$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = (new PasswordHash(8, true))->HashPassword('secret');
[$status, $data] = dx_rest('GET', '/content', ['slug' => 'dx-locked']);
same(200, $status, 'with its password\'s cookie, the page');
check('and its content', str_contains((string) wp_json_encode($data['blocks'] ?? []), 'Locked words'));
$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = (new PasswordHash(8, true))->HashPassword('wrong');
same(401, dx_rest('GET', '/content', ['slug' => 'dx-locked'])[0], 'another password\'s cookie is a 401');
unset($_COOKIE[ 'wp-postpass_' . COOKIEHASH ]);

section('Languages');

$it = dx_post(['post_name' => 'dx-chi-siamo', 'post_title' => 'Chi siamo', 'post_content' => dx_paragraph('Ciao')]);
$en = dx_post(['post_name' => 'dx-who-we-are', 'post_title' => 'Who we are', 'post_content' => dx_paragraph('Hello in English'), 'lang' => 'en']);
WpGoLanguages::link($en, $it);

[$status, $data] = dx_rest('GET', '/content', ['slug' => 'dx-who-we-are', 'lang' => 'en']);
same([200, $en, 'en'], [$status, $data['post']['id'] ?? null, $data['lang'] ?? null], 'lang=en: the English page by its English slug, lang in the answer');
same(['it', 'en'], array_keys($data['translations'] ?? []), 'its translations, itself included, by language');
check('each translation\'s url in its own language', str_contains($data['translations']['en']['url'] ?? '', '/en/') && !str_contains($data['translations']['it']['url'] ?? '/en/', '/en/'));
same(['id' => $it, 'slug' => 'dx-chi-siamo', 'url' => $data['translations']['it']['url'] ?? null], $data['translations']['it'] ?? null, 'a translation is { id, slug, url }');
check('the post\'s url is the English one', str_contains($data['post']['url'] ?? '', '/en/'));
same(['core/paragraph'], dx_names($data['blocks'] ?? []), 'rendered from the English post\'s content');
check('the language is back after the request', dx_language_back());

same(404, dx_rest('GET', '/content', ['slug' => 'dx-chi-siamo', 'lang' => 'en'])[0], 'the Italian slug in English is a 404: the slug is the language\'s own');
[$status, $data] = dx_rest('GET', '/content', ['slug' => 'dx-chi-siamo']);
same([200, $it], [$status, $data['post']['id'] ?? null], 'without lang, the request\'s language (Italian here)');
check('and no lang in the answer', !array_key_exists('lang', (array) $data));
same(404, dx_rest('GET', '/content', ['slug' => 'dx-who-we-are'])[0], 'without lang, the English slug isn\'t the request\'s language\'s');

[$status, $data, $code] = dx_rest('GET', '/content', ['slug' => 'dx-chi-siamo', 'lang' => 'de']);
same([400, 'wp_go_blocks_bad_lang'], [$status, $code], 'a language the site doesn\'t have is a 400');
same('No language `de`.', $data['message'] ?? null, 'naming it');
same(200, dx_rest('GET', '/content', ['slug' => 'dx-who-we-are', 'lang' => 'EN'])[0], 'lang is read through sanitize_key()');

$draft_en = dx_post(['post_name' => 'dx-draft-en', 'post_status' => 'draft', 'lang' => 'en']);
$published_it = dx_post(['post_name' => 'dx-published-it']);
WpGoLanguages::link($draft_en, $published_it);
same(['it'], array_keys(dx_rest('GET', '/content', ['slug' => 'dx-published-it'])[1]['translations'] ?? []), 'translations list the published ones only');

section('The front page');

$front = dx_post(['post_name' => 'dx-front', 'post_content' => dx_paragraph('Front words')]);
$front_en = dx_post(['post_name' => 'dx-front-en', 'post_content' => dx_paragraph('Front in English'), 'lang' => 'en']);
WpGoLanguages::link($front_en, $front);
update_option('show_on_front', 'page');
update_option('page_on_front', $front);

[$status, $data] = dx_rest('GET', '/content', ['slug' => '']);
same([200, $front], [$status, $data['post']['id'] ?? null], 'an empty slug is the front page');
same($front, dx_rest('GET', '/content', [])[1]['post']['id'] ?? null, 'so is no slug at all');
same($front, dx_rest('GET', '/content', ['slug' => '/'])[1]['post']['id'] ?? null, 'and a lone slash');
same($front_en, dx_rest('GET', '/content', ['slug' => '', 'lang' => 'en'])[1]['post']['id'] ?? null, 'with lang=en, its English translation');
same($front, dx_rest('GET', '/content', ['slug' => '', 'type' => 'page'])[1]['post']['id'] ?? null, 'with type=page, still the front page');
same(404, dx_rest('GET', '/content', ['slug' => '', 'type' => 'dx_event'])[0], 'with any other type, a 404');

wp_update_post(['ID' => $front_en, 'post_status' => 'draft']);
same(404, dx_rest('GET', '/content', ['slug' => '', 'lang' => 'en'])[0], 'a front page whose translation isn\'t published is a 404 in that language');
same(200, dx_rest('GET', '/content', ['slug' => ''])[0], 'and still the page in its own');

$draft_front = dx_post(['post_name' => 'dx-draft-front', 'post_status' => 'draft']);
update_option('page_on_front', $draft_front);
same(404, dx_rest('GET', '/content', ['slug' => ''])[0], 'a front page that isn\'t published is a 404');
update_option('page_on_front', $event);
same(404, dx_rest('GET', '/content', ['slug' => ''])[0], 'a front page that isn\'t a page is a 404');

update_option('page_on_front', $front);
update_option('show_on_front', 'posts');
same(404, dx_rest('GET', '/content', ['slug' => ''])[0], 'on "Your latest posts" there is no static front page, whatever page_on_front still holds: a 404');
update_option('page_on_front', 0);
same(404, dx_rest('GET', '/content', ['slug' => ''])[0], 'no front page at all, a 404');

section('Which PHP template runs');

$picked = null;
$spy = function ($template) use (&$picked) {
    $picked = $template;

    return $template;
};
add_filter('template_include', $spy, PHP_INT_MAX);
$flags = fn (array $data) => $data['context']['flags'] ?? null;

dx_rest('GET', '/content', ['slug' => 'dx-about']);
same('page.php', basename((string) $picked), 'a page: the theme\'s page.php');

add_filter('page_template', '__return_empty_string');
[$status] = dx_rest('GET', '/content', ['slug' => 'dx-about']);
same([200, 'index.php'], [$status, basename((string) $picked)], 'without a page template or singular.php, index.php');
remove_filter('page_template', '__return_empty_string');

dx_rest('GET', '/content', ['slug' => 'dx-gala']);
same('single.php', basename((string) $picked), 'a post of another type: single.php');

$front_template = fn () => "$templates/dx-front.php";
$privacy_template = fn () => "$templates/dx-privacy.php";
add_filter('frontpage_template', $front_template);
add_filter('privacypolicy_template', $privacy_template);
update_option('show_on_front', 'page');
update_option('page_on_front', $front);
update_option('wp_page_for_privacy_policy', $front);

[$status, $data] = dx_rest('GET', '/content', ['slug' => '']);
same('dx-front.php', basename((string) $picked), 'the front page: front-page.php first, before the privacy policy\'s');
same(['front_page' => true, 'page' => true, 'single' => false, 'singular' => true, 'queried' => $front], array_diff_key((array) $flags($data), ['privacy' => 1]), 'queried as a page: is_page(), is_front_page(), the queried object');

$privacy = dx_post(['post_name' => 'dx-privacy']);
update_option('wp_page_for_privacy_policy', $privacy);
[$status, $data] = dx_rest('GET', '/content', ['slug' => 'dx-privacy']);
same('dx-privacy.php', basename((string) $picked), 'the privacy policy page: privacy-policy.php');
same(true, $flags($data)['privacy'] ?? null, 'is_privacy_policy() holds');

remove_filter('frontpage_template', $front_template);
remove_filter('privacypolicy_template', $privacy_template);
update_option('page_on_front', 0);
update_option('wp_page_for_privacy_policy', 0);

// WordPress's query treats the posts page's path apart (is_home): the
// lookup must still find that page, and only it. Older than the pages
// around it, so a query that loses the path gives another.
$blog = dx_post(['post_name' => 'dx-blog', 'post_date' => '2020-01-01 00:00:00']);
dx_post(['post_name' => 'dx-after-blog']);
update_option('page_on_front', $front);
update_option('page_for_posts', $blog);
[$status, $data] = dx_rest('GET', '/content', ['slug' => 'dx-blog', 'type' => 'page']);
same([200, $blog], [$status, $data['post']['id'] ?? null], 'the posts page by its slug is that page');
same('index.php', basename((string) $picked), 'rendered with the blog\'s template (home.php, then index.php), as the site does');
[$status, $data] = dx_rest('GET', '/content', ['slug' => 'dx-blog']);
same([200, $blog], [$status, $data['post']['id'] ?? null], 'without type too: no other hierarchical type\'s post matches its slug');
update_option('page_for_posts', 0);
update_option('show_on_front', 'posts');
update_option('page_on_front', 0);

$override = fn () => "$templates/dx-flags.php";
add_filter('template_include', $override, 50);
[$status, $data] = dx_rest('GET', '/content', ['slug' => 'dx-gala']);
same('dx-flags.php', basename((string) $picked), 'then template_include, as on the site');
same(['front_page' => false, 'privacy' => false, 'page' => false, 'single' => true, 'singular' => true, 'queried' => $event], $flags($data), 'a post of another type is queried as a single post');
same('dx-flags.twig', $data['template'] ?? null, 'the page is the one that template rendered');
remove_filter('template_include', $override, 50);
remove_filter('template_include', $spy, PHP_INT_MAX);

section('A template that misbehaves');

$use = function (string $file) use ($templates) {
    $filter = fn () => $file === '' ? '' : "$templates/$file";
    add_filter('template_include', $filter, PHP_INT_MAX);

    return fn () => remove_filter('template_include', $filter, PHP_INT_MAX);
};

$level = ob_get_level();
$off = $use('dx-silent.php');
[$status, $data, $code] = dx_rest('GET', '/content', ['slug' => 'dx-about']);
$off();
same([500, 'wp_go_blocks_no_page'], [$status, $code], 'a template that never calls render() is a 500');
same("The post's template rendered no page.", $data['message'] ?? null, 'saying so');

$off = $use('');
same('wp_go_blocks_no_page', dx_rest('GET', '/content', ['slug' => 'dx-about'])[2], 'no template at all is a 500 too');
$off();

$off = $use('dx-leaky.php');
ob_start();
[$status, $data] = dx_rest('GET', '/content', ['slug' => 'dx-about']);
$leaked = ob_get_clean();
$off();
same(200, $status, 'a template that prints around its render and leaves buffers open still answers');
same($level, ob_get_level(), 'its buffers are closed, back to the level before');
same('', $leaked, 'what it printed isn\'t printed');
same(['core/paragraph', 'acf/dx-card:Card'], dx_names($data['blocks'] ?? []), 'the page is what its render built');

global $wp_query, $wp_the_query;
$before = [$wp_query, $wp_the_query, $GLOBALS['post'] ?? null, Config::renderer()];
$off = $use('dx-throws.php');
$thrown = null;
try {
    dx_rest('GET', '/content', ['slug' => 'dx-who-we-are', 'lang' => 'en']);
} catch (Throwable $e) {
    $thrown = $e->getMessage();
}
$off();
same('dx template threw', $thrown, 'a template that throws: the exception goes through');
same($level, ob_get_level(), 'its buffer is closed all the same');
check('the main query is put back', $wp_query === $before[0] && $wp_the_query === $before[1]);
check('the global post is put back', ($GLOBALS['post'] ?? null) === $before[2]);
check('the site\'s renderer is put back', Config::renderer() === $before[3]);
check('the language is put back', dx_language_back());

[$status] = dx_rest('GET', '/content', ['slug' => 'dx-about']);
same(200, $status, 'the next request answers as usual');
check('after a request that went well, the main query, the post and the renderer are the ones before', $wp_query === $before[0] && ($GLOBALS['post'] ?? null) === $before[2] && Config::renderer() === $before[3]);

section('The editor\'s preview: POST /content');

$editing = dx_post(['post_name' => 'dx-editing', 'post_status' => 'draft', 'post_content' => dx_paragraph('Stored words')]);
$stored = get_post($editing)->post_content;
$unsaved = dx_block('dx-card', ['title' => 'Unsaved']) . dx_paragraph('Unsaved words');

[$status, $data] = dx_rest('POST', '/content', ['post_id' => $editing, 'content' => $unsaved]);
same(200, $status, 'a draft previews, for someone who can edit it');
same(['acf/dx-card:Unsaved', 'core/paragraph'], dx_names($data['blocks'] ?? []), 'with the content sent instead of the stored one');
same($stored, get_post($editing)->post_content, 'nothing is stored');
same('page-dx-editing.twig', $data['template'] ?? null, 'the page as GET would give it, its template included');
same([], $data['translations'] ?? null, 'translations list the published ones: a draft isn\'t among its own');
same('it', $data['lang'] ?? null, 'in the post\'s own language');

$auto = dx_post(['post_status' => 'auto-draft', 'post_title' => 'Auto Draft', 'post_name' => '']);
[$status, $data] = dx_rest('POST', '/content', ['post_id' => $auto, 'content' => $unsaved]);
same(200, $status, 'an auto-draft just opened previews');
same('page-page.twig', $data['template'] ?? null, 'its template skips page-.twig, a name with no slug in it');
same(['acf/dx-card:Unsaved', 'core/paragraph'], dx_names($data['blocks'] ?? []), 'with the content sent');

[$status, $data] = dx_rest('POST', '/content', ['post_id' => $en, 'content' => dx_paragraph('English draft')]);
same('en', $data['lang'] ?? null, 'an English post previews in English, whatever the request\'s language');
check('the language is back after it', dx_language_back());

[$status, $data] = dx_rest('POST', '/content', ['post_id' => $locked, 'content' => dx_paragraph('Editor words')]);
same(200, $status, 'a post behind a password previews');
check('with the editor\'s content', str_contains((string) wp_json_encode($data['blocks'] ?? []), 'Editor words'));

$pattern = dx_post(['post_type' => 'wp_block', 'post_name' => 'dx-pattern', 'post_content' => dx_paragraph('x')]);
[$status, , $code] = dx_rest('POST', '/content', ['post_id' => $pattern, 'content' => dx_paragraph('x')]);
same([404, 'wp_go_blocks_not_found'], [$status, $code], 'a post whose type has no pages of its own is a 404');

[$status, , $code] = dx_rest('POST', '/content', ['post_id' => $editing]);
same([400, 'rest_missing_callback_param'], [$status, $code], 'without content, a 400');
[$status, , $code] = dx_rest('POST', '/content', ['content' => 'x']);
same([400, 'rest_missing_callback_param'], [$status, $code], 'without post_id, a 400');

[$status, , $code] = dx_rest('POST', '/content', ['post_id' => 99999999, 'content' => 'x']);
same([403, 'rest_forbidden'], [$status, $code], 'a post that doesn\'t exist is a 403: the permission is checked before anything is looked up');

$administrator = get_current_user_id();
$subscriber = wp_insert_user(['user_login' => 'dx_subscriber', 'user_pass' => 'dx-pass', 'user_email' => 'dx-sub@example.test', 'role' => 'subscriber']);
wp_set_current_user($subscriber);
[$status, , $code] = dx_rest('POST', '/content', ['post_id' => $editing, 'content' => 'x']);
same([403, 'rest_forbidden'], [$status, $code], 'logged in, but not allowed to edit it: a 403');
wp_set_current_user(0);
[$status, , $code] = dx_rest('POST', '/content', ['post_id' => $editing, 'content' => 'x']);
same([401, 'rest_forbidden'], [$status, $code], 'not logged in: a 401');
[$status] = dx_rest('GET', '/content', ['slug' => 'dx-about']);
same(200, $status, 'GET /content is anyone\'s');
wp_set_current_user($administrator);

[State::$front, State::$context] = $state;
