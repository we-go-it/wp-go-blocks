<?php

/**
 * Rendering, a placed block: render_block() as ACF reaches it from a post's
 * content (do_blocks() on the markup the editor saves), with the fixture
 * blocks of tests/fixtures/rendering-blocks/. The slug from the folder, the
 * context and its merge order, the loaders and their return, the variants
 * by post type (and the one a post type named like the renderer's extension
 * doesn't get), the template, a folder without one, is_preview as ACF gives
 * it, the record of every block rendered, and the two filters a block
 * renders through.
 *
 * The globals are built once per renderer and request, so this file renders
 * with a TimberRenderer of its own, swapped in before anything reads them:
 * its `wp_go_blocks/context` listener is there from the first read.
 */

use WpGo\Blocks\Renderer\PayloadRenderer;
use WpGo\Blocks\Renderer\TimberRenderer;

use function WpGo\Blocks\Core\declared_blocks;
use function WpGo\Blocks\Core\previewing;
use function WpGo\Blocks\Core\rendering;
use function WpGo\Blocks\Core\variant;

require __DIR__ . '/fixtures/rendering-helpers.php';

$theme_renderer = WpGoBlocks::renderer();
$renderer       = new TimberRenderer();
WpGoBlocks::use_renderer($renderer);

// The globals' filter: what it was handed each time it ran, and a value of
// its own, plus two that the layers over it should hide
$globals_given = [];
add_filter('wp_go_blocks/context', function ($context) use (&$globals_given) {
    $globals_given[] = $context;
    $context['rx_global']   = 'global';
    $context['title']       = 'from the globals';
    $context['override_me'] = 'global';

    return $context;
});

$plain   = rx_post('post', 'RX plain');
$typed   = rx_post('rxtype', 'RX typed');
$payload = rx_post('payload', 'RX payload');

$card = ['title' => 'Hello', 'site' => 'my site', 'block' => 'a field named block', 'override_me' => 'field', 'twice' => 'field'];

section('A placed block, on the site');

check('no fixture block has rendered yet in this request', !in_array('acf/rx-card', declared_blocks(), true));

rx_forget();
$html = rx_placed(rx_markup('acf/rx-card', $card), $plain);
$seen = rx_seen('rx-card');
$ctx  = $seen['context'] ?? [];

same('<p class="rx-card">Hello||page|placed|loader</p>', trim($html), 'the block renders <slug>.twig from its folder, through ACF from the post\'s content');
same('rx-card', $seen['slug'] ?? null, 'its slug is its folder\'s name');
same(4, $seen['args'] ?? null, 'wp_go_blocks/html is given four arguments');
same(false, $seen['is_preview'] ?? null, 'on the site it is not a preview: $is_preview false, as a bool');
same(trim($html), trim($seen['html'] ?? ''), 'wp_go_blocks/html is given the template\'s markup');
check('the block is recorded, by its name, once it has rendered', in_array('acf/rx-card', declared_blocks(), true));

section('The context, layer over layer');

check('the renderer\'s globals reach the block (Timber\'s `theme`)', isset($ctx['theme']));
same('global', $ctx['rx_global'] ?? null, 'and what wp_go_blocks/context adds');
same('Hello', $ctx['title'] ?? null, 'a field wins over a global of the same name');
same('my site', $ctx['site'] ?? null, 'a field named `site` hides Timber\'s');
check('`block` is ACF\'s block array, over a field named `block`', is_array($ctx['block'] ?? null) && ($ctx['block']['name'] ?? '') === 'acf/rx-card');
same(false, $ctx['is_preview'] ?? null, '`is_preview` is in the context');
same('loader', $ctx['override_me'] ?? null, 'the loader wins over the field and the global');
same('loader', $ctx['twice'] ?? null, 'on a post type without a variant, only the block\'s loader runs');
check('a placed block is not marked drawn', !array_key_exists('drawn', $ctx['block'] ?? []));
check('`block` never carries a `wp_go_context`', !array_key_exists('wp_go_context', $ctx['block'] ?? []));

section('The loader');

$saw = $ctx['loader_saw'] ?? [];
same($plain, $saw['post_id'] ?? null, 'its $post_id is the post whose content renders');
same(false, $saw['is_preview'] ?? null, 'its $is_preview is false on the site');
same(false, $saw['drawn'] ?? null, 'its $block has no `drawn` for a placed block');
same('title,site,block,override_me,twice', $saw['fields'] ?? null, 'its $fields are the block\'s fields');
same('Hello', $saw['title'] ?? null, 'its $context has what the template would get so far (the field over the global)');
same(1, $saw['rendering'] ?? null, 'while it runs, rendering() counts the block in');
same(false, $saw['previewing'] ?? null, 'and previewing() is the block\'s is_preview');
same(false, $saw['this'] ?? null, 'it runs in a static closure: no $this');
check('null is dropped from its return', !array_key_exists('dropped_null', $ctx));
check('\'\' is dropped', !array_key_exists('dropped_empty', $ctx));
check('[] is dropped', !array_key_exists('dropped_list', $ctx));
same(false, $ctx['kept_false'] ?? 'absent', 'false is kept: a loader that returns it means it');
same(0, $ctx['kept_zero'] ?? 'absent', '0 is kept');
same(['kept' => 'x', 'list' => ['a']], $ctx['nested'] ?? null, 'at any depth, a list renumbered');
same(0, rendering(), 'once the block has rendered, rendering() is back to 0');
same(false, previewing(), 'and previewing() to false');

section('The globals, once per request and renderer');

same(1, count($globals_given), 'wp_go_blocks/context ran once for the first block');
check('it was handed the renderer\'s globals (Timber\'s context)', isset($globals_given[0]['site'], $globals_given[0]['theme']) && !isset($globals_given[0]['rx_global']));

rx_placed(rx_markup('acf/rx-card', ['title' => 'Again']), $plain);
same(1, count($globals_given), 'a second block reads the globals kept: the filter doesn\'t run again');

$late = fn ($context) => $context + ['rx_late' => 'late'];
add_filter('wp_go_blocks/context', $late);
rx_placed(rx_markup('acf/rx-card', ['title' => 'Late']), $plain);
check('a listener added after the globals were built isn\'t seen', !array_key_exists('rx_late', rx_seen('rx-card')['context'] ?? []));
same('Late', rx_seen('rx-card')['context']['title'] ?? null, 'and the block renders all the same');
remove_filter('wp_go_blocks/context', $late);

section('Variants by post type');

rx_forget();
$html = rx_placed(rx_markup('acf/rx-card', ['title' => 'Typed', 'twice' => 'field']), $typed);
$ctx  = rx_seen('rx-card')['context'] ?? [];
same('<p class="rx-card rx-card--rxtype">variant|Typed|variant|loader</p>', trim($html), 'on an rxtype post, <slug>.rxtype.twig renders instead, the variant\'s loader having run after the block\'s and seen its return');
same('variant', $ctx['twice'] ?? null, 'the variant\'s loader wins over the block\'s');
same($typed, $ctx['variant_post_id'] ?? null, 'it has the same $post_id: the post the block renders for');
same($typed, $ctx['loader_saw']['post_id'] ?? null, 'the block\'s loader has it too');

rx_forget();
$html = rx_placed(rx_markup('acf/rx-card', ['title' => 'No post']), 0);
same('<p class="rx-card">No post||page|placed|loader</p>', trim($html), 'with no post, the block renders plain');
same(0, rx_seen('rx-card')['context']['loader_saw']['post_id'] ?? null, 'its $post_id is 0');

$folder = RX_BLOCKS . '/rx-card';
same(['php' => "$folder/rx-card.rxtype.php", 'template' => "$folder/rx-card.rxtype.twig"], variant($folder, 'rx-card', $typed, 'twig'), 'variant() finds both files for an rxtype post');
same(['php' => null, 'template' => null], variant($folder, 'rx-card', $plain, 'twig'), 'none for a post type without files');
same(['php' => null, 'template' => null], variant($folder, 'rx-card', 0, 'twig'), 'none for post 0');

// A post type that sanitize_key() would change is part of a path: refused,
// even where a case-insensitive disk would find rx-card.rxtype.twig for it
$odd = rx_post('rxtype', 'RX odd');
$wpdb->update($wpdb->posts, ['post_type' => 'RxType'], ['ID' => $odd]);
clean_post_cache($odd);
same('RxType', get_post_type($odd), 'the odd post\'s type is stored as RxType');
same(['php' => null, 'template' => null], variant($folder, 'rx-card', $odd, 'twig'), 'a post type sanitize_key() would change gives no variant');

section('A post type named like the renderer\'s extension');

same(['php' => "$folder/rx-card.payload.php", 'template' => null], variant($folder, 'rx-card', $payload, 'twig'), 'with Twig, <slug>.payload.php is a `payload` post\'s loader variant');
same(['php' => null, 'template' => null], variant($folder, 'rx-card', $payload, 'payload.php'), 'with the payload renderer\'s extension, it isn\'t: it\'s the block\'s template');

$GLOBALS['rx_payload_runs'] = [];
rx_forget();
rx_placed(rx_markup('acf/rx-card', ['title' => 'P']), $payload);
same(['first'], $GLOBALS['rx_payload_runs'], 'rendered with Twig on a `payload` post, the file runs once, as a loader');
same(true, rx_seen('rx-card')['context']['from_payload_file'] ?? null, 'its return merged into the context');

$data = new PayloadRenderer();
WpGoBlocks::use_renderer($data);
$GLOBALS['rx_payload_runs'] = [];
$json = rx_placed(rx_markup('acf/rx-card', ['title' => 'P']), $payload);
WpGoBlocks::use_renderer($renderer);

same(['first'], $GLOBALS['rx_payload_runs'], 'rendered as data on a `payload` post, the file runs once, as the template, not as a loader before it');
same(['from_payload_file' => true, 'title' => 'P'], json_decode(trim($json), true), 'and what it returns is the block\'s payload');
same([], end($globals_given), 'the payload renderer\'s globals are its own: wp_go_blocks/context ran for it, handed []');

section('The slug, and a folder without a template');

rx_forget();
$html = rx_placed(rx_markup('acf/rx-named'));
same('<p class="rx-folder">acf/rx-named</p>', trim($html), 'a block named acf/rx-named in the folder rx-folder renders rx-folder.twig');
same('rx-folder', $GLOBALS['rx_seen'][0]['slug'] ?? null, 'its slug is the folder\'s');
check('it is recorded by its name', in_array('acf/rx-named', declared_blocks(), true));
check('not by its folder', !in_array('acf/rx-folder', declared_blocks(), true));

rx_forget();
$html = trim(rx_placed(rx_markup('acf/rx-notemplate')));
check('a block folder without its template renders TimberRenderer\'s notice', str_starts_with($html, '<div class="wp-go-blocks-notice">TimberRenderer: no template `'), $html);
check('naming the template it looked for', str_ends_with($html, '/rx-notemplate/rx-notemplate.twig`</div>'), $html);
check('the notice goes through wp_go_blocks/html as the block\'s markup', rx_seen('rx-notemplate') !== null);
check('the block is recorded all the same: before anything can fail', in_array('acf/rx-notemplate', declared_blocks(), true));

section('is_preview, from ACF');

// ACF's own callback tells a preview apart by is_admin() and the block
// editor's screen, which a CLI request isn't; its fetch-block handler calls
// acf_rendered_block() with true, as here
rx_forget();
$html = (string) acf_rendered_block(['name' => 'acf/rx-card', 'mode' => 'preview', 'data' => WpGoBlocks::field_data('rx-card', ['title' => 'Previewed'])], '', true, $plain);
$seen = rx_seen('rx-card');
same(true, $seen['is_preview'] ?? null, 'previewed, wp_go_blocks/html is told so');
same(true, $seen['context']['is_preview'] ?? null, 'the template has is_preview true');
check('and renders as a preview', str_contains($html, '<p class="rx-card">Previewed||preview|placed|loader</p>'), $html);
same(true, $seen['context']['loader_saw']['is_preview'] ?? null, 'the loader\'s $is_preview is true');
same(true, $seen['context']['loader_saw']['previewing'] ?? null, 'and previewing() is true while it renders');
same(false, previewing(), 'after it, previewing() is false again');

section('wp_go_blocks/html');

$wrap = function ($html, $slug, $context, $is_preview) {
    return $slug === 'rx-card' ? '<section data-preview="' . var_export($is_preview, true) . '">' . trim($html) . '</section>' : $html;
};
add_filter('wp_go_blocks/html', $wrap, 20, 4);
$html = rx_placed(rx_markup('acf/rx-card', ['title' => 'Wrapped']), $plain);
remove_filter('wp_go_blocks/html', $wrap, 20);

same('<section data-preview="false"><p class="rx-card">Wrapped||page|placed|loader</p></section>', trim($html), 'what the filter returns is what the block prints');
check('the context it is handed has the loaders\' keys', isset(rx_seen('rx-card')['context']['loader_saw']));

section('What a post\'s content can\'t claim');

rx_forget();
$html = rx_placed(rx_markup('acf/rx-card', ['title' => 'Claims'], ['drawn' => true]), $plain);
same('<p class="rx-card">Claims||page|placed|loader</p>', trim($html), 'a `drawn` attribute saved in the content doesn\'t make the block drawn');
check('`block.drawn` is set only for a block a template drew', empty(rx_seen('rx-card')['context']['block']['drawn']));

WpGoBlocks::use_renderer($theme_renderer);
