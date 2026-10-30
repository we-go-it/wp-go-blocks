<?php

/**
 * Rendering as data, normalising: any value a payload can hold made plain
 * data (Normalizer, through PayloadRenderer's statics). Scalars as they are;
 * arrays walked, an object's null keys left out and a list's kept; strings
 * without markup decoded once; posts, terms, users, images, Timber's own,
 * dates, JsonSerializable, Stringable and stdClass each in their shape; any
 * other object its class alone; the depth limit counting objects only. And
 * image(), text(), compact(), without_nulls() on their own.
 */

require __DIR__ . '/fixtures/data-blocks.php';

use WpGo\Blocks\Renderer\Payload\Normalizer;
use WpGo\Blocks\Renderer\PayloadRenderer;

// The theme adds its blurred placeholder to every image; with no file on
// disk it has none. Taken off, so what's checked is the package's image.
remove_all_filters('wp_go_blocks/payload_image');

section('Scalars, arrays, nulls');

same(3, PayloadRenderer::normalize(3), 'an integer is itself');
same(2.5, PayloadRenderer::normalize(2.5), 'a float is itself');
same(false, PayloadRenderer::normalize(false), 'a boolean is itself');
same(null, PayloadRenderer::normalize(null), 'null alone is null');
same(['a' => 1, 'c' => '', 'd' => 0, 'e' => false, 'f' => []], PayloadRenderer::normalize(['a' => 1, 'b' => null, 'c' => '', 'd' => 0, 'e' => false, 'f' => []]), 'an object\'s null keys are left out, its empty values kept');
same([1, null, 3], PayloadRenderer::normalize([1, null, 3]), 'a list keeps its nulls: their positions are the data');
same(['outer' => ['inner' => ['x' => 1]]], PayloadRenderer::normalize(['outer' => ['inner' => ['x' => 1, 'y' => null]], 'gone' => null]), 'nulls are left out at every depth');

section('Strings: text(), entities decoded once');

same('HR & payroll', PayloadRenderer::text('HR &#038; payroll'), 'a string without markup has its entities decoded');
same('Tom &amp; Jerry', PayloadRenderer::text('Tom &amp;amp; Jerry'), 'decoded once: a doubly encoded entity comes out encoded once');
same('<p>A &amp; B</p>', PayloadRenderer::text('<p>A &amp; B</p>'), 'a string with a tag is HTML, left as it is');
same('<!-- c --> &amp;', PayloadRenderer::text('<!-- c --> &amp;'), 'a comment counts as markup');
same('a < b & c', PayloadRenderer::text('a < b &amp; c'), 'a lone < is not a tag: the string is text');
same('R&D', PayloadRenderer::text('R&D'), 'an ampersand that is no entity stays');
same('plain', PayloadRenderer::text('plain'), 'a string with no ampersand is untouched');
same(['t' => 'A & B', 'h' => '<b>A &amp; B</b>'], PayloadRenderer::normalize(['t' => 'A &amp; B', 'h' => '<b>A &amp; B</b>']), 'normalize() runs every string through text()');

section('compact() and without_nulls()');

$value = ['a' => null, 'b' => ['c' => null, 'd' => 'X &amp; Y'], 'e' => [1, null]];
same(['b' => ['d' => 'X & Y'], 'e' => [1, null]], PayloadRenderer::compact($value), 'compact() leaves nulls out at every depth, strings made text, a list kept whole');
same(['b' => ['c' => null, 'd' => 'X &amp; Y'], 'e' => [1, null]], PayloadRenderer::compact($value, false), 'compact($value, false) works on this level alone, strings as they are');
same(['a' => 0, 'b' => ''], Normalizer::without_nulls(['a' => 0, 'b' => '', 'c' => null]), 'without_nulls() drops the null keys only');
same([null, 1], Normalizer::without_nulls([null, 1]), 'without_nulls() leaves a list as it is');
same(['a' => ['b' => null]], Normalizer::without_nulls(['a' => ['b' => null]]), 'without_nulls() is this level alone');

section('Posts, terms, users');

$page = dx_test_page();
$json = PayloadRenderer::normalize(get_post($page));
same(['id' => $page, 'type' => 'page', 'title' => 'HR & payroll', 'url' => get_permalink($page)], $json, 'a WP_Post is { id, type, title, url }, the title as text (WordPress gives it as HR &#038; payroll)');
same($json, PayloadRenderer::normalize(\Timber\Timber::get_post($page)), 'a Timber post gives the same');

register_taxonomy('dx_topic', 'page', ['public' => true]);
$term = wp_insert_term('R&D', 'dx_topic', ['slug' => 'r-and-d']);
$term_id = (int) $term['term_id'];
$expected = ['id' => $term_id, 'taxonomy' => 'dx_topic', 'name' => 'R&D', 'slug' => 'r-and-d'];
same($expected, PayloadRenderer::normalize(get_term($term_id)), 'a WP_Term is { id, taxonomy, name, slug }, the name as text (stored R&amp;D)');
same($expected, PayloadRenderer::normalize(\Timber\Timber::get_term($term_id)), 'a Timber term gives the same');

$user = wp_insert_user(['user_login' => 'dx_author', 'user_pass' => 'dx-secret-pass', 'user_email' => 'dx-author@example.test', 'display_name' => 'Ada &amp; Co', 'role' => 'author']);
$expected = ['id' => $user, 'name' => 'Ada & Co', 'url' => get_author_posts_url($user)];
same($expected, PayloadRenderer::normalize(get_userdata($user)), 'a WP_User is { id, name, url }: the display name and the author page');
same($expected, PayloadRenderer::normalize(\Timber\Timber::get_user($user)), 'a Timber user gives the same');
$encoded = (string) wp_json_encode(PayloadRenderer::normalize(['by' => get_userdata($user), 'also' => \Timber\Timber::get_user($user)]));
check('never the email, the login or the password hash', !str_contains($encoded, 'dx-author@example.test') && !str_contains($encoded, 'dx_author"') && !str_contains($encoded, get_userdata($user)->user_pass) && !str_contains($encoded, 'user_pass'));

section('Images');

$photo = dx_image(1600, 1200, ['medium' => [300, 225], 'large' => [1024, 768], 'thumbnail' => [150, 150]]);
update_post_meta($photo, '_wp_attachment_image_alt', '  Our office  ');
$image = PayloadRenderer::image($photo);
same(['id', 'url', 'width', 'height', 'alt', 'mime', 'srcset'], array_keys((array) $image), 'an image is { id, url, width, height, alt, mime, srcset }');
same(wp_get_attachment_url($photo), $image['url'] ?? null, 'url is the full size\'s');
same([1600, 1200], [$image['width'] ?? null, $image['height'] ?? null], 'width and height are the full size\'s');
same('Our office', $image['alt'] ?? null, 'alt is the media library\'s, trimmed');
same('image/jpeg', $image['mime'] ?? null, 'mime is the attachment\'s');
check('srcset is WordPress\'s for the full size: the full image and the sizes with its proportions', str_contains((string) ($image['srcset'] ?? ''), ' 1600w') && str_contains((string) $image['srcset'], '-300x225.jpg 300w') && str_contains((string) $image['srcset'], '-1024x768.jpg 1024w'));
check('a hard-cropped size (the thumbnail) is not in the srcset', !str_contains((string) ($image['srcset'] ?? ''), '150x150'));
same($image, PayloadRenderer::image((string) $photo), 'an ID given as a numeric string works the same');
same($image, Normalizer::image($photo), 'the static hands over to Normalizer::image()');

same(null, PayloadRenderer::image(0), 'image(0) is null');
same(null, PayloadRenderer::image(-4), 'a negative ID is null');
same(null, PayloadRenderer::image('logo'), 'a non-numeric value is null');
same(null, PayloadRenderer::image(null), 'null is null');
same(null, PayloadRenderer::image($page), 'a page\'s ID is no image: null');
$pdf = dx_image(0, 0, [], 'application/pdf');
same(null, PayloadRenderer::image($pdf), 'an attachment that is no image (a PDF) is null');

$seen = [];
add_filter('wp_go_blocks/payload_image', $dx_lqip = function (array $image, int $id) use (&$seen) {
    $seen[] = $id;

    return $image + ['lqip' => 'data:dx'];
}, 10, 2);
same('data:dx', PayloadRenderer::image($photo)['lqip'] ?? null, 'wp_go_blocks/payload_image adds what the theme knows of an image');
same([$photo], $seen, 'the filter is given the attachment\'s ID');
remove_filter('wp_go_blocks/payload_image', $dx_lqip, 10);

same($image, PayloadRenderer::normalize(get_post($photo)), 'an image attachment as a post is an image, not a post');
same($image, PayloadRenderer::normalize(\Timber\Timber::get_post($photo)), 'so is a Timber image');
same(['id' => $pdf, 'type' => 'attachment'], array_intersect_key((array) PayloadRenderer::normalize(get_post($pdf)), ['id' => 0, 'type' => 0]), 'an attachment that is no image is a post');

// An image with no sizes has no srcset (null); a theme's placeholder can be
// null too. In a payload, a null key is left out: an image inside one too.
$bare = dx_image(800, 600);
add_filter('wp_go_blocks/payload_image', $dx_none = fn (array $image) => $image + ['lqip' => null]);
$in_payload = PayloadRenderer::normalize(['photo' => get_post($bare)]);
check('an image attachment in a payload has no null keys (its missing srcset, a null placeholder)', is_array($in_payload['photo'] ?? null) && !in_array(null, $in_payload['photo'], true), 'got ' . wp_json_encode($in_payload));
remove_filter('wp_go_blocks/payload_image', $dx_none);

section('Menus, dates, markup');

$menu = wp_create_nav_menu('DX menu');
wp_update_nav_menu_item($menu, 0, ['menu-item-type' => 'custom', 'menu-item-title' => 'Out', 'menu-item-url' => 'https://example.test/', 'menu-item-status' => 'publish']);
$timber_menu = \Timber\Timber::get_menu($menu);
$from_timber = PayloadRenderer::normalize($timber_menu);
same(PayloadRenderer::menu($menu), $from_timber, 'a Timber menu is the menu as data (menu()), not its markup');
same('Out', $from_timber['items'][0]['title'] ?? null, 'with its items');

$date = new DateTimeImmutable('2026-10-17 09:30:00', new DateTimeZone('Europe/Rome'));
same('2026-10-17T09:30:00+02:00', PayloadRenderer::normalize($date), 'a date is ISO 8601 (DATE_ATOM)');
same('2026-01-02T03:04:05+00:00', PayloadRenderer::normalize(new DateTime('2026-01-02 03:04:05', new DateTimeZone('UTC'))), 'a mutable DateTime too');

same('<b>Bold</b>', PayloadRenderer::normalize(new \Twig\Markup('<b>Bold</b>', 'UTF-8')), 'Twig\'s markup is its string');
$stringable = new class implements Stringable {
    public function __toString(): string
    {
        return 'Fish &amp; chips';
    }
};
same('Fish & chips', PayloadRenderer::normalize($stringable), 'anything Stringable is its string, made text');

section('JsonSerializable, stdClass, any other object');

$serializable = new class implements JsonSerializable {
    public function jsonSerialize(): mixed
    {
        return ['a' => 'A &amp; B', 'gone' => null, 'when' => new DateTimeImmutable('2026-10-17T00:00:00+00:00')];
    }
};
same(['a' => 'A & B', 'when' => '2026-10-17T00:00:00+00:00'], PayloadRenderer::normalize($serializable), 'a JsonSerializable is its data, walked: text, no nulls, objects inside made data');

$record = (object) ['a' => 'x &amp; y', 'b' => null, 'c' => (object) []];
$normalized = PayloadRenderer::normalize($record);
check('a stdClass stays an object', $normalized instanceof stdClass);
same('{"a":"x & y","c":{}}', wp_json_encode($normalized), 'its properties walked, nulls left out, an empty one {}');
same('{}', wp_json_encode(PayloadRenderer::normalize(new stdClass())), 'an empty stdClass encodes as {}');

same(['class' => 'WP_Query'], PayloadRenderer::normalize(new WP_Query(['post_type' => 'page', 'posts_per_page' => 1])), 'a WP_Query is { class } alone: none of its SQL');
same(['class' => 'Closure'], PayloadRenderer::normalize(fn () => 1), 'any other object, its class alone');
same(['q' => ['class' => 'WP_Query']], PayloadRenderer::normalize(['q' => new WP_Query(['post_type' => 'page', 'posts_per_page' => 1])]), 'inside a payload too');

section('Depth: objects inside objects, never arrays');

// A chain of stdClass, each holding the next under `n`: the 0th to the 6th
// are walked, the 7th is past the limit.
$chain = static function (int $count) {
    $inner = (object) ['leaf' => 'end'];

    for ($i = 1; $i < $count; $i++) {
        $inner = (object) ['n' => $inner];
    }

    return $inner;
};
$walk = static function ($value) {
    $depth = 0;

    while (is_object($value) && isset($value->n)) {
        $value = $value->n;
        $depth++;
    }

    return [$depth, $value];
};
[$depth, $last] = $walk(PayloadRenderer::normalize($chain(7)));
same([6, 'end'], [$depth, $last->leaf ?? null], 'seven objects inside each other come out whole');
[$depth, $last] = $walk(PayloadRenderer::normalize($chain(8)));
same([7, '…'], [$depth, $last], 'the eighth is past the limit: …');

$loop = new class implements JsonSerializable {
    public function jsonSerialize(): mixed
    {
        return ['self' => $this];
    }
};
check('an object that holds itself ends, at the limit', str_contains((string) wp_json_encode(PayloadRenderer::normalize($loop), JSON_UNESCAPED_UNICODE), '"self":"…"'));

$deep = ['post' => get_post($page)];
for ($i = 0; $i < 12; $i++) {
    $deep = ['k' => $deep];
}
$normalized = PayloadRenderer::normalize($deep);
for ($i = 0; $i < 12; $i++) {
    $normalized = $normalized['k'] ?? null;
}
same(['id' => $page, 'type' => 'page', 'title' => 'HR & payroll', 'url' => get_permalink($page)], $normalized['post'] ?? null, 'a 12-level array is walked whole: the post at its bottom is made data, not cut');

$arrays = (object) ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => ['h' => (object) ['x' => 1]]]]]]]]];
same(1, PayloadRenderer::normalize($arrays)->a['b']['c']['d']['e']['f']['g']['h']->x ?? null, 'arrays between objects don\'t count towards the limit');

/** The test's page, titled with an ampersand. */
function dx_test_page(): int
{
    static $id = 0;

    return $id ?: $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'HR & payroll', 'post_name' => 'dx-hr']);
}
