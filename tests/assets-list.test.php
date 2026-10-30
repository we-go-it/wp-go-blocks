<?php

/**
 * Assets on a list (the blog, an archive, a search), with the buffer: no
 * post is parsed, the head carries only its markers, and the late slots
 * carry exactly the blocks the template renders. Then fill_slots() on its
 * own, its edge cases, and declare_bundle()'s refusal.
 *
 * One request, as in assets-singular.test.php: the head's set is worked out
 * once.
 */

use function WpGo\Blocks\Core\blocks_on_page;
use function WpGo\Blocks\Core\declare_bundle;
use function WpGo\Blocks\Core\declared_bundles;
use function WpGo\Blocks\Core\fill_slots;
use function WpGo\Blocks\Core\late_assets;
use function WpGo\Blocks\Core\print_scripts;
use function WpGo\Blocks\Core\print_styles;

use const WpGo\Blocks\Core\CSS_SLOT;
use const WpGo\Blocks\Core\JS_SLOT;

require __DIR__ . '/fixtures/assets-helpers.php';

$resolver = new AssetsFakeResolver();
assets_configure(['assets' => $resolver, 'buffer' => true, 'renderer' => new AssetsFakeRenderer()]);
assets_register();

$asked = 0;
add_filter('wp_go_blocks/parse_content', function ($parse) use (&$asked) {
    $asked++;

    return $parse;
}, 999);

$first  = assets_post(assets_block('acf/assets-alpha') . assets_block('acf/assets-beta'), 'First');
$second = assets_post(assets_block('acf/assets-gamma'), 'Second');

$query = assets_query(['post__in' => [$first->ID, $second->ID], 'post_type' => 'post', 'orderby' => 'post__in']);

section('A list parses nothing');

check('the page is a list, not singular', !is_singular());
same(2, count($query->posts), 'its posts, whose content has blocks');
same([], blocks_on_page(), 'no post is parsed: the template decides what each shows');
same(0, $asked, 'wp_go_blocks/parse_content is not asked on a list with the buffer');

$scripts = assets_output(fn () => print_scripts());
$styles  = assets_output(fn () => print_styles());

same(JS_SLOT . "\n", $scripts, 'the head prints only the scripts\' marker');
same(CSS_SLOT . "\n", $styles, 'and only the stylesheets\' marker');
same([], $resolver->asked, 'the resolver is asked nothing for the head');

section('fill_slots(), nothing rendered late');

$page = "<html><head>\n<title>t</title>\n$scripts<script src=\"main.js\"></script>\n$styles</head><body>x</body></html>";

same(\WpGo\Blocks\Core\NO_ASSETS, late_assets(), 'nothing recorded, nothing late');
same("<html><head>\n<title>t</title>\n<script src=\"main.js\"></script>\n</head><body>x</body></html>", fill_slots($page), 'each marker is replaced with nothing, its line break too');

section('fill_slots(), what it leaves alone');

same('{"a":"' . JS_SLOT . '"}', fill_slots('{"a":"' . JS_SLOT . '"}'), 'a response with no </head> (JSON, a feed) comes back as it went in');
same('<head></head><body>' . JS_SLOT . CSS_SLOT . '</body>', fill_slots('<head></head><body>' . JS_SLOT . CSS_SLOT . '</body>'), 'markers only after </head> are the content\'s, and stay');
same('<head><title>t</title></head><body></body>', fill_slots('<head><title>t</title></head><body></body>'), 'a head without markers comes back as it went in');

section('The template renders its cards');

// the list's template draws one block per post, the same block twice, and a
// block of no post's content
$body = assets_render('late') . assets_render('late') . assets_render('gamma');

declare_bundle('form/form');

$late = late_assets();

same(array_map('assets_url', ['blocks/late/late.js', 'blocks/gamma/gamma.js', 'form/form.js']), $late['js'], 'the late scripts: each block rendered, once, then the bundle declared');
same(array_map('assets_url', ['blocks/late/late.css', 'shared.css', 'form/form.css']), $late['css'], 'the late stylesheets: the blocks\', a script\'s imported one, the bundle\'s, each once');
same(array_map('assets_url', ['chunk-shared.js', 'blocks/late/late-chunk.js', 'blocks/gamma/gamma-chunk.js', 'form/form-chunk.js']), $late['preload'], 'the late preloads, the shared chunk once');
check('neither of the content\'s other blocks, which no card drew', !str_contains(json_encode($late), 'alpha') && !str_contains(json_encode($late), 'beta'));

$filled = fill_slots($page);

same(
    "<html><head>\n<title>t</title>\n"
    . assets_tags('preload', 'chunk-shared.js', 'blocks/late/late-chunk.js', 'blocks/gamma/gamma-chunk.js', 'form/form-chunk.js')
    . assets_tags('js', 'blocks/late/late.js', 'blocks/gamma/gamma.js', 'form/form.js')
    . "<script src=\"main.js\"></script>\n"
    . assets_tags('css', 'blocks/late/late.css', 'shared.css', 'form/form.css')
    . '</head><body>x</body></html>',
    $filled,
    'the slots carry exactly what rendered: scripts at the first marker, stylesheets at the second'
);

same(
    '<HEAD>' . assets_tags('css', 'blocks/late/late.css', 'shared.css', 'form/form.css') . '</HEAD>',
    fill_slots('<HEAD>' . CSS_SLOT . '</HEAD>'),
    'a head with the stylesheets\' marker alone gets its stylesheets, </HEAD> found in capitals too'
);

section('declare_bundle()');

$warnings = [];
set_error_handler(function ($level, $message) use (&$warnings) {
    $warnings[] = [$level, $message];

    return true;
}, E_USER_WARNING);

try {
    declare_bundle('extra/extra');
    WpGoBlocks::declare_bundle('form/form');
} finally {
    restore_error_handler();
}

same(1, count($warnings), 'a base wp-go-blocks.json doesn\'t list is refused with one warning, a listed one with none');
same(E_USER_WARNING, $warnings[0][0] ?? null, 'an E_USER_WARNING');
check('naming the base and `bundles`', str_contains($warnings[0][1] ?? '', '`extra/extra` is not in wp-go-blocks.json `bundles`'), $warnings[0][1] ?? '');
same(['form/form'], declared_bundles(), 'the refused base is not recorded, though its files exist; WpGoBlocks::declare_bundle() records a listed one once');
check('and nothing of it is loaded', !str_contains(json_encode(late_assets()), 'extra'));
check('the resolver was never asked for it', !in_array('extra/extra.js', $resolver->asked, true));
