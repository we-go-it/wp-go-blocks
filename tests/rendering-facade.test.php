<?php

/**
 * Rendering, the facade and the engine: WpGoBlocks' page methods (context(),
 * render(), compile()), the fetches (get_post(), get_posts(), get_menu(),
 * and `global`), notice(), registered(), config(), use_renderer(); what
 * TimberRenderer and TimberEntities do as the theme's engine; and the
 * theme's own code that renders through it (its notice markup, its rich
 * text's buttons, which stay links while the blocks render as data).
 *
 * compile() takes a path inside the theme, so its rendered case uses the
 * theme's base-button view, the one the rich text's buttons compile: the
 * checks are on what it is given, not on the kit's markup.
 */

use WpGo\Blocks\Core\Config;
use WpGo\Blocks\Entities\Entities;
use WpGo\Blocks\Entities\TimberEntities;
use WpGo\Blocks\Renderer\PayloadRenderer;
use WpGo\Blocks\Renderer\TimberRenderer;

use function WpGo\Blocks\Core\declared_blocks;

section('The theme\'s notice markup');

// read before the helpers take the theme's listener off
same('<div class="wg-scaffold" data-block="error"><span class="wg-scaffold__name">my_helper(): broken</span></div>', WpGoBlocks::notice('my_helper(): broken'), 'the theme draws a notice like a dump\'s name, the message as given, no prefix added');

require __DIR__ . '/fixtures/rendering-helpers.php';

section('notice()');

check('WP_DEBUG is on in this environment: notices show (off, they are \'\', not testable in one request)', WP_DEBUG === true);
same('<div class="wp-go-blocks-notice">my_helper(): &lt;b&gt;broken&lt;/b&gt;</div>', WpGoBlocks::notice('my_helper(): <b>broken</b>'), 'the message is printed as given, escaped, in the package\'s markup');

$given  = [];
$listen = function ($html, $message) use (&$given) {
    $given[] = [$html, $message];

    return '<em>' . esc_html($message) . '</em>';
};
add_filter('wp_go_blocks/notice', $listen, 10, 2);
same('<em>x(): y</em>', WpGoBlocks::notice('x(): y'), 'wp_go_blocks/notice decides the markup');
same([['<div class="wp-go-blocks-notice">x(): y</div>', 'x(): y']], $given, 'it is handed the default markup and the plain message');
remove_filter('wp_go_blocks/notice', $listen, 10);

section('The theme\'s engine');

$theme_renderer = WpGoBlocks::renderer();
check('the theme renders with TimberRenderer', $theme_renderer instanceof TimberRenderer);
check('and fetches with TimberEntities', WpGoBlocks::entities() instanceof TimberEntities);
same('twig', $theme_renderer->extension(), 'its extension is twig');
same(array_keys(\Timber\Timber::context()), array_keys($theme_renderer->globals()), 'its globals are Timber::context()');

$functions = apply_filters('timber/twig/functions', []);
same('WpGo\\Blocks\\Core\\block', $functions['wp_go_render_block']['callable'] ?? null, 'boot() exposed block() to Twig as wp_go_render_block');
same(['html'], $functions['wp_go_render_block']['options']['is_safe'] ?? null, 'marked safe: its markup isn\'t escaped again');

$own = new TimberRenderer();
$own->expose('rx_function', fn () => 'rx');
$functions = apply_filters('timber/twig/functions', []);
check('expose() adds a Twig function on timber/twig/functions', is_callable($functions['rx_function']['callable'] ?? null) && ($functions['rx_function']['options']['is_safe'] ?? null) === ['html']);
check('beside the ones exposed before', isset($functions['wp_go_render_block']));

$view = ltrim(RX_VIEWS . '/rx-page.twig', '/'); // as render() makes it, outside the theme: Timber's `/` location
same("<header>Outside</header>\nBody X<footer>Outside</footer>\n", $own->render(RX_VIEWS . '/rx-page.twig', ['name' => 'X']), 'render() is Timber::compile() of the file, with the context');
check('render() of a file that isn\'t there is a notice naming it', str_starts_with($own->render(RX_VIEWS . '/rx-gone.twig', []), '<div class="wp-go-blocks-notice">TimberRenderer: no template `') && str_ends_with($own->render(RX_VIEWS . '/rx-gone.twig', []), '/rendering-views/rx-gone.twig`</div>'));
[$printed] = rx_output(fn () => $own->output([$view], ['name' => 'Y']));
check('output() is Timber::render(): the view printed', str_contains($printed, 'Body Y'), $printed);

section('TimberRenderer as a PageBody');

same('Body X', $own->body(['rx-nowhere.twig', $view], ['name' => 'X']), 'body() is the view\'s `content` block alone, the first view that exists');
$data = fn ($context, $file) => $context + ['rx_render_data' => '!' . basename($file)];
add_filter('timber/render/data', $data, 10, 2);
same('Body X!rx-page.twig', $own->body([$view], ['name' => 'X']), 'with the context filtered on timber/render/data, as Timber::render() does, the file given');
remove_filter('timber/render/data', $data, 10);
same(null, (new TimberRenderer('main'))->body([$view], ['name' => 'X']), 'null for a view without the block it is told to render');
same(null, $own->body(['rx-nowhere.twig'], []), 'null when no view exists');

section('WpGoBlocks::context()');

$context = WpGoBlocks::context(['rx_extra' => 'extra', 'site' => 'over']);
check('it is the renderer\'s globals (Timber\'s theme)', isset($context['theme']));
same('extra', $context['rx_extra'] ?? null, '$extra is merged over them');
same('over', $context['site'] ?? null, 'and wins over a global of the same name');

section('WpGoBlocks::render()');

[$printed] = rx_output(fn () => WpGoBlocks::render(['rx-nowhere.twig', $view], ['name' => 'Page']));
same("<header>Outside</header>\nBody Page<footer>Outside</footer>\n", $printed, 'it prints the first view that exists, with its context: Timber::render()\'s arguments, as given');

section('WpGoBlocks::compile()');

$button = WpGoBlocks::compile('views/lib/base-button/base-button', ['type' => 'primary', 'url' => 'https://rx.test/compiled', 'label' => 'RX compiled']);
check('a theme-relative path without its extension renders the .twig with the context', str_contains($button, 'https://rx.test/compiled') && str_contains($button, 'RX compiled'), $button);
same($button, WpGoBlocks::compile('/views/lib/base-button/base-button', ['type' => 'primary', 'url' => 'https://rx.test/compiled', 'label' => 'RX compiled']), 'a leading slash changes nothing');
same(rx_notice('TimberRenderer: no template `views/rx-missing-view.twig`'), WpGoBlocks::compile('views/rx-missing-view'), 'a view that isn\'t there is a notice naming it, theme-relative');

section('No renderer');

$previous = WpGoBlocks::use_renderer(null);
same('', WpGoBlocks::compile('views/lib/base-button/base-button'), 'compile() gives \'\'');
$warnings = rx_warnings(function () use (&$printed, $view) {
    [$printed] = rx_output(fn () => WpGoBlocks::render([$view], []));
});
same(['WpGo\\Blocks\\Core\\render_page(): no renderer'], $warnings, 'render() warns');
same('', $printed, 'and prints nothing');
[$printed] = rx_output(fn () => WpGoBlocks::render_block(['name' => 'acf/rx-unrendered', 'path' => RX_BLOCKS . '/rx-card'], ''));
same('', $printed, 'render_block() prints nothing');
check('and records nothing', !in_array('acf/rx-unrendered', declared_blocks(), true));
same('', WpGoBlocks::block('rx-card', ['title' => 'Nothing']), 'a drawn block is \'\'');
WpGoBlocks::use_renderer($previous);

section('use_renderer()');

$payload  = new PayloadRenderer();
$globals  = [];
$listener = function ($context) use (&$globals) {
    $globals[] = $context;

    return $context;
};
add_filter('wp_go_blocks/context', $listener);

$replaced = WpGoBlocks::use_renderer($payload);
same($theme_renderer, $replaced, 'it returns the renderer it replaces');
same($payload, WpGoBlocks::renderer(), 'which is then the one in use');
same($payload, WpGoBlocks::config('renderer'), 'config() reads it too');
same([], WpGoBlocks::context(), 'the page context is the new renderer\'s: the payload renderer\'s globals are []');
same([[]], $globals, 'its own globals, through wp_go_blocks/context, handed []');
same(['label' => 'RX'], json_decode(WpGoBlocks::compile('views/lib/base-button/base-button', ['label' => 'RX']), true), 'compile() renders with it: the context as JSON');

$rich = '<p>Text <a class="button-primary" href="https://rx.test/go" target="_blank">Go there</a></p>';
same($rich, wg_wysiwyg_buttons($rich), 'the theme\'s rich-text buttons stay links while the blocks render as data: no JSON in the text');

same($payload, WpGoBlocks::use_renderer($replaced), 'put back, it returns the swapped one');
same($theme_renderer, WpGoBlocks::renderer(), 'and the theme\'s renders again');
remove_filter('wp_go_blocks/context', $listener);

$buttons = wg_wysiwyg_buttons($rich);
check('with Twig, the rich-text button is compiled through the renderer', $buttons !== $rich && str_contains($buttons, 'https://rx.test/go') && str_contains($buttons, 'Go there') && !str_contains($buttons, 'class="button-primary" href'), $buttons);

section('Posts and menus');

$first  = rx_post('rxtype', 'RX first');
$second = rx_post('rxtype', 'RX second');

$post = WpGoBlocks::get_post($first);
check('get_post() is a Timber\\Post', $post instanceof \Timber\Post);
same($first, $post->ID ?? null, 'the one asked for');

$posts = WpGoBlocks::get_posts(['post_type' => 'rxtype', 'orderby' => 'ID', 'order' => 'ASC']);
check('get_posts() with query args is iterable', is_iterable($posts));
same([$first, $second], array_map(fn ($p) => $p->ID, iterator_to_array($posts)), 'holding the posts the query finds');

$one = WpGoBlocks::get_posts($first);
check('get_posts() of an ID, which Timber answers with one post, is a list of it', is_array($one) && array_is_list($one) && count($one) === 1 && ($one[0]->ID ?? null) === $first);
same([$first], array_map(fn ($p) => $p->ID, (array) WpGoBlocks::get_posts(['ID' => $first])), 'the same for [\'ID\' => …]');
same([], WpGoBlocks::get_posts(999999999), 'get_posts() of what Timber gives null for (a post that isn\'t there) is []');
same([], (new TimberEntities())->get_posts(999999999), 'TimberEntities does the normalising');

$menu_id = wp_create_nav_menu('RX menu ' . wp_generate_password(6, false));
wp_update_nav_menu_item($menu_id, 0, ['menu-item-title' => 'RX item', 'menu-item-url' => 'https://rx.test/', 'menu-item-status' => 'publish']);
$menu = WpGoBlocks::get_menu($menu_id);
check('get_menu() is a Timber\\Menu', $menu instanceof \Timber\Menu);
same($menu_id, (int) ($menu->id ?? 0), 'the one asked for');
same(['RX item'], array_map(fn ($item) => $item->title(), $menu->items ?? []), 'with its items');

section('`global` on a fetch');

// Entities that remember their arguments, swapped in for the length of a
// check: the settings have no setter for them
$recorder = new class implements Entities {
    public array $calls = [];

    public function get_post(mixed ...$args): mixed
    {
        $this->calls[] = ['get_post', $args];

        return 'a post';
    }

    public function get_posts(mixed ...$args): iterable
    {
        $this->calls[] = ['get_posts', $args];

        if (($args[0]['rx_throw'] ?? false)) {
            throw new RuntimeException('rx: fetch failed');
        }

        return ['posts'];
    }

    public function get_menu(mixed ...$args): mixed
    {
        $this->calls[] = ['get_menu', $args];

        return 'a menu';
    }
};

$events = [];
$start  = function () use (&$events) {
    $events[] = 'start';
};
$end    = function ($result) use (&$events) {
    $events[] = ['end', $result];
};
add_action('wp_go_blocks/global_start', $start);
add_action('wp_go_blocks/global_end', $end);

rx_with_settings(['entities' => $recorder], function () use ($recorder, &$events) {
    same(['posts'], WpGoBlocks::get_posts(['post_type' => 'office', 'global' => true]), 'a global fetch returns what the entities fetched');
    same([['get_posts', [['post_type' => 'office']]]], $recorder->calls, '`global` is taken out before the arguments go on, the rest as given');
    same(['start', ['end', ['posts']]], $events, 'wp_go_blocks/global_start before it, wp_go_blocks/global_end after it with the result');

    $recorder->calls = $events = [];
    same('a menu', WpGoBlocks::get_menu('main', ['global' => true, 'depth' => 2]), 'in any array argument: get_menu()\'s options');
    same([['get_menu', ['main', ['depth' => 2]]]], $recorder->calls, 'taken out of that argument alone');
    same(['start', ['end', 'a menu']], $events, 'announced the same');

    $recorder->calls = $events = [];
    same('a post', WpGoBlocks::get_post(12, ['global' => false]), 'global false: the fetch as it is');
    same([['get_post', [12, []]]], $recorder->calls, '`global` is taken out all the same');
    same([], $events, 'and nothing is announced');

    $events = [];
    $thrown = null;
    try {
        WpGoBlocks::get_posts(['rx_throw' => true, 'global' => true]);
    } catch (RuntimeException $e) {
        $thrown = $e->getMessage();
    }
    same('rx: fetch failed', $thrown, 'a fetch that throws throws through');
    same(['start', ['end', null]], $events, 'global_end still fires, with null');
});

remove_action('wp_go_blocks/global_start', $start);
remove_action('wp_go_blocks/global_end', $end);

rx_with_settings(['entities' => null], function () {
    same(null, WpGoBlocks::entities(), 'without entities, entities() is null');
    same([], WpGoBlocks::get_posts(['post_type' => 'post']), 'get_posts() is []');
    same(null, WpGoBlocks::get_post(1), 'get_post() is null');
    same(null, WpGoBlocks::get_menu('main'), 'get_menu() is null');
});

section('registered() and config()');

$registered = WpGoBlocks::registered();
check('registered() has the theme\'s blocks', count($registered) > 0);
check('each by its name, acf/…, registered in WordPress', !array_filter(array_keys($registered), fn ($name) => !str_starts_with($name, 'acf/') || !WP_Block_Type_Registry::get_instance()->is_registered($name)));
check('each with its folder, in the theme\'s blocks folder, holding its block.json', !array_filter($registered, fn ($dir) => !str_starts_with(wp_normalize_path($dir), Config::blocks_dir() . '/') || !is_file($dir . '/block.json')));
check('a block registered outside the package\'s own registration isn\'t in it', !isset($registered['acf/rx-card']));

same(Config::get('blocks'), WpGoBlocks::config('blocks'), 'config() is the resolved setting');
same(null, WpGoBlocks::config('rx-no-such-setting'), 'null for a setting that doesn\'t exist');
