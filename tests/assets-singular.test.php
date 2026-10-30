<?php

/**
 * Assets on a singular page, with the buffer (the default): the post's
 * content parsed before the head, the head's tags in their order, the late
 * slots filled once the page has rendered, through the real buffer.
 *
 * What the head prints is worked out once per request (statics), so this
 * file is one request: each step builds on the one before, as a page does.
 * The fixture theme and the fake resolver are in fixtures/assets-helpers.php.
 */

use WpGo\Blocks\Core\Config;

use function WpGo\Blocks\Core\assets_on_page;
use function WpGo\Blocks\Core\blocks_on_page;
use function WpGo\Blocks\Core\declare_bundle;
use function WpGo\Blocks\Core\declared_blocks;
use function WpGo\Blocks\Core\declared_bundles;
use function WpGo\Blocks\Core\print_scripts;
use function WpGo\Blocks\Core\print_styles;
use function WpGo\Blocks\Core\start_buffer;

use const WpGo\Blocks\Core\CSS_SLOT;
use const WpGo\Blocks\Core\JS_SLOT;

require __DIR__ . '/fixtures/assets-helpers.php';

section('What boot() hooks, with the theme\'s resolver');

// the theme boots with its resolver and the default priorities and buffer:
// read before the fixture's settings replace them
check('the theme gives boot() a resolver', Config::assets() instanceof \WpGo\Blocks\Assets\AssetResolver);
same(15, has_action('wp_head', 'WpGo\\Blocks\\Core\\print_scripts'), 'the block scripts print on wp_head at 15, before a main bundle at 20');
same(25, has_action('wp_head', 'WpGo\\Blocks\\Core\\print_styles'), 'the block stylesheets print on wp_head at 25, after it');
same(0, has_action('template_redirect', 'WpGo\\Blocks\\Core\\start_buffer'), 'the buffer opens on template_redirect at 0');
same(false, has_action('wp_footer', 'WpGo\\Blocks\\Core\\print_late_styles'), 'with the buffer, nothing waits on wp_footer');
same([], declared_blocks(), 'nothing has rendered yet in this request');

$resolver = new AssetsFakeResolver();
assets_configure(['assets' => $resolver, 'buffer' => true, 'renderer' => new AssetsFakeRenderer()]);
assets_register();

// what parse_content is asked, at the end of the filter so the theme's and
// Cache Tags' have had their say
$asked = [];
add_filter('wp_go_blocks/parse_content', function ($parse, $post) use (&$asked) {
    $asked[] = [$post->ID, $parse];

    return $parse;
}, 999, 2);

$post = assets_post(
    assets_block('acf/assets-alpha')
    . assets_block('core/group', assets_block('acf/assets-inner') . assets_block('core/group', assets_block('acf/assets-gamma')))
    . assets_block('core/paragraph', '<p>Text</p>')
    . assets_block('acf/assets-missing')
    . assets_block('acf/assets-plain')
);
$other = assets_post(assets_block('acf/assets-beta'), 'Another post');

assets_query(['p' => $post->ID]);

check('the page is singular', is_singular());

section('Before the head');

// a template that draws a block before it renders its view: render_block()
// records it, and the head knows it
$early = assets_render('early');
check('a block renders through render_block()', str_contains($early, 'data-rendered="acf/assets-early"'), $early);
same(['acf/assets-early'], declared_blocks(), 'render_block() records the block it renders');

// and something declares the form bundle before wp_head
declare_bundle('form/form');
declare_bundle('form/form');
same(['form/form'], declared_bundles(), 'a bundle declared twice is recorded once');

section('Which blocks the head knows');

same(
    ['acf/assets-alpha', 'core/group', 'acf/assets-inner', 'acf/assets-gamma', 'core/paragraph', 'acf/assets-missing', 'acf/assets-plain', 'acf/assets-early'],
    blocks_on_page(),
    'the queried post\'s blocks, inner blocks at any depth, each once, then what rendered before the head'
);
same([[$post->ID, true]], $asked, 'wp_go_blocks/parse_content is asked once, for the queried post, and no other post is parsed');

section('The head');

$scripts = assets_output(fn () => print_scripts());
$styles  = assets_output(fn () => print_styles());

// the content's registered blocks with files (alpha, inner, gamma; plain has
// none; a core block and an unregistered one have no entry), the one drawn
// before the head (early), the bundle declared by then (form/form)
same(
    ['blocks/alpha/alpha.scss', 'blocks/alpha/alpha.js', 'blocks/inner/inner.scss', 'blocks/gamma/gamma.js', 'blocks/early/early.scss', 'blocks/early/early.js', 'form/form.scss', 'form/form.js'],
    $resolver->asked,
    'the resolver is asked for each block\'s and bundle\'s .scss then .js, only those that exist in the theme'
);

same(
    assets_tags('preload', 'chunk-shared.js', 'blocks/alpha/alpha-chunk.js', 'blocks/gamma/gamma-chunk.js', 'blocks/early/early-chunk.js', 'form/form-chunk.js')
    . assets_tags('js', 'blocks/alpha/alpha.js', 'blocks/gamma/gamma.js', 'blocks/early/early.js', 'form/form.js')
    . JS_SLOT . "\n",
    $scripts,
    'print_scripts(): the preloads, then the module scripts, each URL once in the order met, then the scripts\' marker'
);

same(
    assets_tags('css', 'blocks/alpha/alpha.css', 'shared.css', 'blocks/inner/inner.css', 'blocks/early/early.css', 'form/form.css') . CSS_SLOT . "\n",
    $styles,
    'print_styles(): the stylesheets, a script\'s imported one too, each once, then the stylesheets\' marker'
);

$tags = array_diff(explode("\n", trim($scripts . $styles)), [JS_SLOT, CSS_SLOT]);
same([], array_values(array_filter($tags, fn ($tag) => !str_contains($tag, ' data-block-asset '))), 'every tag printed is on a line of its own and carries data-block-asset');
same(false, has_action('wp_footer', 'WpGo\\Blocks\\Core\\print_late_styles'), 'with the buffer, print_styles() hooks nothing on wp_footer');
same(8, count($resolver->asked), 'printing the stylesheets asks the resolver nothing more: the set is worked out once');

section('The late slots, through the buffer');

$level = ob_get_level();

// the page's own capture, as PHP's output would be at the end of the request
ob_start();
start_buffer();

// read now, checked once the page is out: a check printed here would be
// part of the page
$status = ob_get_status();
$opened = ob_get_level();

echo "<!doctype html>\n<html><head>\n<title>Assets</title>\n";
echo $scripts;
echo '<script type="module" crossorigin src="https://cdn.test/main.js"></script>' . "\n";
echo $styles;
// the same marker again in the head: only the first is the slot
echo JS_SLOT . "\n";
echo "</head>\n<body>\n";
// the marker typed into a post's content: the post's text
echo '<p>' . JS_SLOT . CSS_SLOT . "</p>\n";

// the body draws a block the head has, and one it doesn't
echo assets_render('alpha');
echo assets_render('late');

// a flush halfway down would hand fill_slots() the head alone: refused
$flushed = @ob_flush();

// what renders after it still reaches the head: another block, the head's
// bundle again, and a bundle declared only now (the same, as only one is listed)
echo assets_render('late-two');
declare_bundle('form/form');

echo "</body></html>\n";

ob_end_flush();
$html = (string) ob_get_clean();

same($level + 2, $opened, 'start_buffer() opens a buffer');
same('WpGo\\Blocks\\Core\\fill_slots', $status['name'] ?? null, 'whose callback is fill_slots()');
same(0, ($status['flags'] ?? 0) & PHP_OUTPUT_HANDLER_FLUSHABLE, 'and which is not flushable');
check('but can be ended, as the end of the request ends it', (bool) (($status['flags'] ?? 0) & PHP_OUTPUT_HANDLER_REMOVABLE));
same(false, $flushed, 'ob_flush() fails on the buffer');
same($level, ob_get_level(), 'the buffers closed back to where they started');
same(['acf/assets-early', 'acf/assets-alpha', 'acf/assets-late', 'acf/assets-late-two'], declared_blocks(), 'every block rendered was recorded, each once');

$head = substr($html, 0, (int) strpos($html, '</head>'));

same(
    "<!doctype html>\n<html><head>\n<title>Assets</title>\n"
    . substr($scripts, 0, -strlen(JS_SLOT . "\n"))
    . assets_tags('preload', 'blocks/late/late-chunk.js', 'blocks/late-two/late-two-chunk.js')
    . assets_tags('js', 'blocks/late/late.js', 'blocks/late-two/late-two.js')
    . '<script type="module" crossorigin src="https://cdn.test/main.js"></script>' . "\n"
    . substr($styles, 0, -strlen(CSS_SLOT . "\n"))
    . assets_tags('css', 'blocks/late/late.css', 'blocks/late-two/late-two.css')
    . JS_SLOT . "\n",
    $head,
    'each marker in the head replaced, with its line break, by the late tags: those rendered after the head, even after the refused flush, less what the head has'
);

check('the scripts\' slot is before the main bundle and the stylesheets\' after it', strpos($head, 'late.js') < strpos($head, 'main.js') && strpos($head, 'main.js') < strpos($head, 'late.css'));
same(1, substr_count($head, JS_SLOT), 'a second marker in the head is left as it is: only the first is the slot');
check('the marker typed in the body is untouched', str_contains($html, '<p>' . JS_SLOT . CSS_SLOT . "</p>\n"));
same(0, substr_count($head, CSS_SLOT), 'the stylesheets\' slot is gone from the head');

foreach (['chunk-shared.js', 'shared.css', 'blocks/alpha/alpha.js', 'blocks/alpha/alpha.css', 'form/form.js', 'form/form.css', 'blocks/late/late.js', 'blocks/late/late.css'] as $path) {
    same(1, substr_count($html, assets_url($path)), "$path is printed once in the page, whether the head or the slots needed it");
}

check('the body is as it rendered', str_contains($html, '<div data-rendered="acf/assets-late-two"></div></body>'));
check('the other post\'s block was never loaded', !str_contains($html, 'blocks/beta'));

same(
    ['blocks/alpha/alpha.scss', 'blocks/alpha/alpha.js', 'blocks/inner/inner.scss', 'blocks/gamma/gamma.js', 'blocks/early/early.scss', 'blocks/early/early.js', 'form/form.scss', 'form/form.js', 'blocks/late/late.scss', 'blocks/late/late.js', 'blocks/late-two/late-two.scss', 'blocks/late-two/late-two.js'],
    $resolver->asked,
    'the resolver is asked once per file for the whole request: the slots ask only for what the head didn\'t'
);
same(assets_on_page()['js'], array_map('assets_url', ['blocks/alpha/alpha.js', 'blocks/gamma/gamma.js', 'blocks/early/early.js', 'form/form.js']), 'what the head printed stays what it printed');
