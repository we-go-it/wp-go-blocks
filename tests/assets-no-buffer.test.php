<?php

/**
 * Assets with boot()'s `buffer` off, on a list: each post parsed (through
 * wp_go_blocks/parse_content, post by post), no markers, and what renders
 * after the head gets its stylesheets on wp_footer, its scripts not at all.
 *
 * The theme boots with the buffer on: the setting is read when each print
 * runs, so the fixture's settings turning it off is what a theme booted
 * with `'buffer' => false` gets (less the template_redirect hook, which
 * isn't run here).
 */

use function WpGo\Blocks\Core\blocks_on_page;
use function WpGo\Blocks\Core\declare_bundle;
use function WpGo\Blocks\Core\print_late_styles;
use function WpGo\Blocks\Core\print_scripts;
use function WpGo\Blocks\Core\print_styles;

use const WpGo\Blocks\Core\CSS_SLOT;
use const WpGo\Blocks\Core\JS_SLOT;

require __DIR__ . '/fixtures/assets-helpers.php';

$resolver = new AssetsFakeResolver();
assets_configure(['assets' => $resolver, 'buffer' => false, 'renderer' => new AssetsFakeRenderer()]);
assets_register();

$first  = assets_post(assets_block('acf/assets-alpha') . assets_block('core/group', assets_block('acf/assets-inner')), 'First');
$second = assets_post(assets_block('acf/assets-beta'), 'Second: its content isn\'t rendered');
$third  = assets_post(assets_block('acf/assets-gamma') . assets_block('acf/assets-alpha'), 'Third');

// the second post's page doesn't render its content: its blocks aren't loaded
$asked = [];
add_filter('wp_go_blocks/parse_content', function ($parse, $post) use (&$asked, $second) {
    $asked[] = $post->ID;

    return $parse && $post->ID !== $second->ID;
}, 999, 2);

assets_query(['post__in' => [$first->ID, $second->ID, $third->ID], 'post_type' => 'post', 'orderby' => 'post__in']);

section('A list parses each of its posts');

check('the page is a list', !is_singular());

// a block the template draws before wp_head
assets_render('early');

same(
    ['acf/assets-alpha', 'core/group', 'acf/assets-inner', 'acf/assets-gamma', 'acf/assets-early'],
    blocks_on_page(),
    'each post\'s blocks, inner ones too, each once, in the list\'s order, then what rendered before the head'
);
same([$first->ID, $second->ID, $third->ID], $asked, 'wp_go_blocks/parse_content is asked for each post');
check('a post the filter turns down is not parsed', !in_array('acf/assets-beta', blocks_on_page(), true));

section('The head, without markers');

$scripts = assets_output(fn () => print_scripts());
$styles  = assets_output(fn () => print_styles());

same(
    assets_tags('preload', 'chunk-shared.js', 'blocks/alpha/alpha-chunk.js', 'blocks/gamma/gamma-chunk.js', 'blocks/early/early-chunk.js')
    . assets_tags('js', 'blocks/alpha/alpha.js', 'blocks/gamma/gamma.js', 'blocks/early/early.js'),
    $scripts,
    'print_scripts(): the tags, and no marker'
);
same(
    assets_tags('css', 'blocks/alpha/alpha.css', 'shared.css', 'blocks/inner/inner.css', 'blocks/early/early.css'),
    $styles,
    'print_styles(): the tags, and no marker'
);
check('neither marker anywhere', !str_contains($scripts . $styles, JS_SLOT) && !str_contains($scripts . $styles, CSS_SLOT));
same(10, has_action('wp_footer', 'WpGo\\Blocks\\Core\\print_late_styles'), 'print_styles() hooks print_late_styles() on wp_footer');

section('What renders after the head');

// the body: a block the head has, one it doesn't, and the form's bundle
assets_render('alpha');
assets_render('late');
declare_bundle('form/form');

same(
    assets_tags('css', 'blocks/late/late.css', 'form/form.css'),
    assets_output(fn () => print_late_styles()),
    'on wp_footer, the stylesheets of what rendered late, less the head\'s: no script, no preload'
);
