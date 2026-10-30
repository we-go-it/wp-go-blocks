<?php

/**
 * Rendering as data, a post's content as blocks (Payload/ContentBlocks.php):
 * an ACF block as { name, payload } with its inner blocks walked; any other
 * block as { name, html }, finished as the_content finishes it (shortcodes,
 * image attributes); one with ACF blocks inside, at any depth, as { name,
 * attrs, inner_blocks }; a synced pattern followed into its post only when
 * published without a password, and stopped where it holds itself; classic
 * content and loose HTML as core/freeform, paragraphs only for a post all
 * in the classic editor; a block no longer registered; a filter that wraps
 * a block's JSON.
 */

require __DIR__ . '/fixtures/data-blocks.php';

use WpGo\Blocks\Renderer\Payload\ContentBlocks;
use WpGo\Blocks\Renderer\PayloadRenderer;

// The ACF blocks render through the package's render_block(), with the
// renderer in place: as data only with the payload renderer.
$site = WpGoBlocks::use_renderer(new PayloadRenderer());

add_shortcode('dx_short', fn () => 'SHORT');

$of = fn (string $content) => json_decode((string) wp_json_encode(ContentBlocks::of($content)), true);

section('ACF blocks');

$blocks = $of(dx_block('dx-card', ['title' => 'Placed &amp; here', 'count' => 2]));
same(1, count($blocks), 'one block, one node');
same(['name', 'payload'], array_keys($blocks[0]), 'an ACF block is { name, payload }');
same(['acf/dx-card', 'Placed & here', 2], [$blocks[0]['name'], $blocks[0]['payload']['title'] ?? null, $blocks[0]['payload']['count'] ?? null], 'its payload is what render() gives, decoded');
same(false, $blocks[0]['payload']['is_preview'] ?? null, 'rendered as on the site, not as a preview');
same(ContentBlocks::of(dx_block('dx-card', ['title' => 'T'])), PayloadRenderer::normalize(ContentBlocks::of(dx_block('dx-card', ['title' => 'T']))), 'already plain data');

$columns = $of(dx_block('dx-columns', ['side' => 'left'], dx_paragraph('In the columns') . dx_block('dx-card', ['title' => 'Inner card'])));
same(['acf/dx-columns'], array_column($columns, 'name'), 'an ACF block with inner blocks is one node');
same('left', $columns[0]['payload']['side'] ?? null, 'with its own payload');
same(['core/paragraph', 'acf/dx-card:Inner card'], dx_names($columns[0]['inner_blocks'] ?? []), 'its inner blocks walked under inner_blocks');
check('its payload is rendered alone, without its inner blocks\' JSON in it', !str_contains((string) wp_json_encode($columns[0]['payload']), 'Inner card'));

same([['name' => 'acf/dx-gone', 'registered' => false]], $of(dx_block('dx-gone', ['title' => 'x'])), 'an ACF block no longer registered is { name, registered: false }');

add_filter('wp_go_blocks/html', $wrap = fn ($html) => '<div class="wrap">' . $html . '</div>');
$wrapped = $of(dx_block('dx-card', ['title' => 'Wrapped']));
remove_filter('wp_go_blocks/html', $wrap);
same(['name' => 'acf/dx-card'], $wrapped[0] ?? null, 'a wp_go_blocks/html filter that wraps the JSON leaves the node without a payload');

section('Other blocks: their HTML, as the_content finishes it');

$paragraph = $of(dx_paragraph('Hello [dx_short]'));
same(['name', 'html'], array_keys($paragraph[0] ?? []), 'a core block is { name, html }');
check('its HTML is WordPress\'s render, shortcodes run', str_contains($paragraph[0]['html'] ?? '', '>Hello SHORT</p>'));

$photo = dx_image(1600, 1200, ['medium' => [300, 225], 'large' => [1024, 768]]);
$url = wp_get_attachment_url($photo);
$image = $of("<!-- wp:image {\"id\":$photo,\"sizeSlug\":\"full\"} -->\n<figure class=\"wp-block-image size-full\"><img src=\"$url\" alt=\"\" class=\"wp-image-$photo\"/></figure>\n<!-- /wp:image -->");
check('an image is given its srcset, as the_content gives it', str_contains($image[0]['html'] ?? '', 'srcset="') && str_contains($image[0]['html'], '-300x225.jpg 300w'));
check('and its width, height and decoding', str_contains($image[0]['html'] ?? '', 'width="1600"') && str_contains($image[0]['html'], 'height="1200"') && str_contains($image[0]['html'], 'decoding="async"'));

$quote = $of('<!-- wp:quote --><blockquote class="wp-block-quote">' . dx_paragraph('Quoted') . '</blockquote><!-- /wp:quote -->');
same(['name', 'html'], array_keys($quote[0] ?? []), 'a core block with only core blocks inside is { name, html }');
check('its HTML has its inner blocks in it', str_contains($quote[0]['html'] ?? '', '>Quoted</p>') && str_contains($quote[0]['html'], '<blockquote'));

section('Core blocks holding ACF blocks');

$group = $of('<!-- wp:group {"className":"dx-g"} --><div class="wp-block-group dx-g">' . dx_block('dx-card', ['title' => 'In group']) . dx_paragraph('Beside') . '</div><!-- /wp:group -->');
same(['name', 'attrs', 'inner_blocks'], array_keys($group[0] ?? []), 'a group around an ACF block is { name, attrs, inner_blocks }, no html');
same(['className' => 'dx-g'], $group[0]['attrs'] ?? null, 'attrs are the block\'s');
same(['acf/dx-card:In group', 'core/paragraph'], dx_names($group[0]['inner_blocks'] ?? []), 'its inner blocks walked the same way');
check('no JSON inside any HTML', !preg_match('/"html":"[^"]*\{\\\\"title/', (string) wp_json_encode($group)));

$bare_group = $of('<!-- wp:group --><div class="wp-block-group">' . dx_block('dx-card', ['title' => 'G']) . '</div><!-- /wp:group -->');
same(['name', 'inner_blocks'], array_keys($bare_group[0] ?? []), 'without attributes, no attrs');

$deep = $of('<!-- wp:group --><div class="wp-block-group"><!-- wp:columns --><div class="wp-block-columns"><!-- wp:column --><div class="wp-block-column">' . dx_paragraph('Left') . '</div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column">' . dx_block('dx-card', ['title' => 'Deep']) . '</div><!-- /wp:column --></div><!-- /wp:columns --></div><!-- /wp:group -->');
$columns_node = $deep[0]['inner_blocks'][0] ?? [];
same(['core/columns', ['core/column', 'core/column']], [$columns_node['name'] ?? null, array_column($columns_node['inner_blocks'] ?? [], 'name')], 'an ACF block at any depth: every level above it taken apart');
same(['name', 'html'], array_keys($columns_node['inner_blocks'][0] ?? []), 'a sibling without ACF blocks inside stays HTML');
same('Deep', $columns_node['inner_blocks'][1]['inner_blocks'][0]['payload']['title'] ?? null, 'the ACF block at the bottom is its payload');

section('Synced patterns');

$pattern = dx_post(['post_type' => 'wp_block', 'post_name' => 'dx-pattern-acf', 'post_content' => dx_block('dx-card', ['title' => 'From pattern'])]);
$walked = $of("<!-- wp:block {\"ref\":$pattern} /-->");
same(['name' => 'core/block', 'attrs' => ['ref' => $pattern]], array_diff_key($walked[0] ?? [], ['inner_blocks' => 1]), 'a synced pattern holding an ACF block is { name, attrs, inner_blocks }');
same(['acf/dx-card:From pattern'], dx_names($walked[0]['inner_blocks'] ?? []), 'its inner blocks are the pattern\'s own');

$plain_pattern = dx_post(['post_type' => 'wp_block', 'post_name' => 'dx-pattern-plain', 'post_content' => dx_paragraph('Pattern text')]);
$plain = $of("<!-- wp:block {\"ref\":$plain_pattern} /-->");
same(['name', 'html'], array_keys($plain[0] ?? []), 'a synced pattern without ACF blocks is { name, html }');
check('its HTML is the pattern\'s', str_contains($plain[0]['html'] ?? '', '>Pattern text</p>'));

$draft_pattern = dx_post(['post_type' => 'wp_block', 'post_status' => 'draft', 'post_name' => 'dx-pattern-draft', 'post_content' => dx_block('dx-card', ['title' => 'Draft pattern'])]);
$draft = $of("<!-- wp:block {\"ref\":$draft_pattern} /-->");
same([['name' => 'core/block', 'html' => '']], $draft, 'a pattern that isn\'t published isn\'t followed: nothing of it, as WordPress renders nothing');

$locked_pattern = dx_post(['post_type' => 'wp_block', 'post_name' => 'dx-pattern-locked', 'post_password' => 'pw', 'post_content' => dx_block('dx-card', ['title' => 'Locked pattern'])]);
$locked = $of("<!-- wp:block {\"ref\":$locked_pattern} /-->");
same([['name' => 'core/block', 'html' => '']], $locked, 'a pattern behind a password isn\'t followed either');

$self = dx_post(['post_type' => 'wp_block', 'post_name' => 'dx-pattern-self', 'post_content' => '']);
wp_update_post(['ID' => $self, 'post_content' => wp_slash("<!-- wp:block {\"ref\":$self} /-->" . dx_block('dx-card', ['title' => 'Self']))]);
$selfish = $of("<!-- wp:block {\"ref\":$self} /-->");
same(['acf/dx-card:Self'], dx_names($selfish[0]['inner_blocks'] ?? []), 'a pattern inside itself stops there: its own blocks once, the inner reference left out');

$a = dx_post(['post_type' => 'wp_block', 'post_name' => 'dx-pattern-a', 'post_content' => '']);
$b = dx_post(['post_type' => 'wp_block', 'post_name' => 'dx-pattern-b', 'post_content' => "<!-- wp:block {\"ref\":$a} /-->" . dx_block('dx-card', ['title' => 'B'])]);
wp_update_post(['ID' => $a, 'post_content' => wp_slash("<!-- wp:block {\"ref\":$b} /-->" . dx_block('dx-card', ['title' => 'A']))]);
$cycle = $of("<!-- wp:block {\"ref\":$a} /-->");
$inner_b = $cycle[0]['inner_blocks'][0] ?? [];
same(['core/block', ['acf/dx-card:B']], [$inner_b['name'] ?? null, dx_names($inner_b['inner_blocks'] ?? [])], 'two patterns holding each other stop where the first comes back');
same('acf/dx-card:A', dx_names($cycle[0]['inner_blocks'] ?? [])[1] ?? null, 'after the other pattern, the first one\'s own block');
same(['acf/dx-card:From pattern'], dx_names($of("<!-- wp:block {\"ref\":$pattern} /-->")[0]['inner_blocks'] ?? []), 'a pattern walked once can be walked again: the guard is let go');

section('Classic content, loose HTML');

$classic = $of("Line one\n\nLine two [dx_short]\n\n[dx_short]");
same(['core/freeform'], array_column($classic, 'name'), 'a post all in the classic editor is one core/freeform');
same("<p>Line one</p>\n<p>Line two SHORT</p>\nSHORT", $classic[0]['html'] ?? null, 'with its paragraphs, as on the site, shortcodes run, a shortcode alone on its line not wrapped');

$mixed = $of(dx_paragraph('First') . "\n\nloose <em>html</em>\n\n" . dx_block('dx-card', ['title' => 'After']) . "\n\n\n");
same(['core/paragraph', 'core/freeform', 'acf/dx-card:After'], dx_names($mixed), 'loose HTML between blocks is core/freeform, in its place; the blank lines between blocks are nothing');
same('loose <em>html</em>', $mixed[1]['html'] ?? null, 'without paragraphs added: the post has blocks');

same([], $of(''), 'empty content has no blocks');

WpGoBlocks::use_renderer($site);
