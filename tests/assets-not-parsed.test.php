<?php

/**
 * A singular page whose content isn't what it renders (a fixed layout): the
 * theme says so on wp_go_blocks/parse_content, and its content's blocks
 * aren't loaded, while those it draws still are. And, first, the part with
 * no resolver: nothing to print.
 */

use WpGo\Blocks\Core\Config;

use function WpGo\Blocks\Core\blocks_on_page;
use function WpGo\Blocks\Core\entry_assets;
use function WpGo\Blocks\Core\print_scripts;
use function WpGo\Blocks\Core\print_styles;

use const WpGo\Blocks\Core\CSS_SLOT;
use const WpGo\Blocks\Core\JS_SLOT;
use const WpGo\Blocks\Core\NO_ASSETS;

require __DIR__ . '/fixtures/assets-helpers.php';

section('Without a resolver');

assets_configure(['assets' => null, 'renderer' => new AssetsFakeRenderer()]);
assets_register();

same(null, Config::assets(), 'no resolver');
check('the fixture has the block\'s stylesheet', is_readable(ASSETS_FIXTURE . '/blocks/beta/beta.scss'));
same(NO_ASSETS, entry_assets('blocks/beta/beta'), 'a block\'s files have no URLs: nothing to print');

section('A page that doesn\'t render its content');

$resolver = new AssetsFakeResolver();
assets_configure(['assets' => $resolver, 'buffer' => true, 'renderer' => new AssetsFakeRenderer()]);

$post = assets_post(assets_block('acf/assets-alpha') . assets_block('acf/assets-gamma'), 'A fixed layout');

$given = null;
add_filter('wp_go_blocks/parse_content', function ($parse, $candidate) use (&$given, $post) {
    $given = $candidate;

    return $parse && $candidate->ID !== $post->ID;
}, 999, 2);

assets_query(['p' => $post->ID]);

// the layout draws its own blocks, one before the head
assets_render('early');

check('the page is singular', is_singular());
same(['acf/assets-early'], blocks_on_page(), 'its content\'s blocks are left out; what rendered before the head is still there');
check('the filter is handed the queried post', $given instanceof WP_Post && $given->ID === $post->ID);

same(
    assets_tags('preload', 'chunk-shared.js', 'blocks/early/early-chunk.js') . assets_tags('js', 'blocks/early/early.js') . JS_SLOT . "\n",
    assets_output(fn () => print_scripts()),
    'the head\'s scripts: the drawn block\'s only'
);
same(
    assets_tags('css', 'blocks/early/early.css', 'shared.css') . CSS_SLOT . "\n",
    assets_output(fn () => print_styles()),
    'the head\'s stylesheets: likewise'
);
same(['blocks/early/early.scss', 'blocks/early/early.js'], $resolver->asked, 'the content\'s blocks\' files are never asked for');
