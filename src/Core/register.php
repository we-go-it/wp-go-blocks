<?php

namespace WpGo\Blocks\Core;

/**
 * block.json blocks: finding them, registering them, and the category they
 * are filed under.
 *
 * One folder per block in the blocks folder (wp-go-blocks.json `blocks`):
 *
 *   blocks/numbers/block.json    <- registration + ACF field config
 *   blocks/numbers/numbers.twig  <- markup
 *   blocks/numbers/numbers.scss  <- optional styles, a Vite entry of its own
 *   blocks/numbers/numbers.js    <- optional Alpine component, likewise
 *   blocks/numbers/numbers.php   <- optional data loader
 *
 * The .scss and .js load only on pages that render the block (assets.php).
 * How a block renders is render.php.
 *
 * Every folder holding a block.json is registered automatically, so adding a
 * block means adding a folder.
 */

/**
 * Block name => absolute folder path, collected at registration time.
 * assets.php uses it to find a rendered block's compiled CSS and JS without
 * re-reading every block.json. With both arguments, register_blocks() adds
 * to it.
 *
 * @return array<string,string>
 */
function registered(?string $name = null, ?string $dir = null): array
{
    static $blocks = [];

    if ($name !== null && $dir !== null) {
        $blocks[$name] = $dir;
    }

    return $blocks;
}

/**
 * On `init`.
 */
function register_blocks(): void
{
    foreach (glob(Config::blocks_dir() . '/*/block.json') ?: [] as $metadata_file) {
        $block_type = register_block_type(dirname($metadata_file));

        if ($block_type) {
            registered($block_type->name, dirname($metadata_file));
        }
    }
}

/**
 * One icon for every block (wp-go-blocks.json `icon`), so the SVG is not repeated
 * in each block.json. A block.json that sets its own "icon" wins.
 *
 * On `block_type_metadata`.
 *
 * @param array<string,mixed> $metadata
 * @return array<string,mixed>
 */
function default_icon($metadata)
{
    static $dir = null;

    // the theme's own blocks, told apart by where their block.json lives —
    // WordPress resolves `file` with realpath(), so the folder is too
    $dir ??= realpath(Config::blocks_dir());

    // No blocks folder (boot() warns), so no block of the theme's: not `/`,
    // which every block's file starts with
    if ($dir === false) {
        return $metadata;
    }

    if (empty($metadata['icon']) && str_starts_with((string) ($metadata['file'] ?? ''), wp_normalize_path($dir) . '/') && block_icon()) {
        $metadata['icon'] = block_icon();
    }

    return $metadata;
}

function block_icon(): string
{
    static $svg = null;

    if ($svg === null) {
        $icon = (string) Config::get('icon');
        $file = Config::root() . '/' . ltrim($icon, '/');
        $svg  = $icon !== '' && is_readable($file) ? trim((string) file_get_contents($file)) : '';
    }

    return $svg;
}

/**
 * The theme's block category (wp-go-blocks.json `category`), first in the
 * inserter: a block.json files itself under it with `"category": "<category>"`.
 * Without one, none is added, and a block.json names any category there is.
 *
 * A category that is already there (one of WordPress's, `text`, say) is moved
 * first rather than added twice, keeping its title unless `category_title`
 * says otherwise.
 *
 * On `block_categories_all`.
 *
 * @param array<int,array<string,mixed>> $categories
 * @return array<int,array<string,mixed>>
 */
function add_category($categories)
{
    $category = (string) Config::get('category');

    if ($category === '') {
        return $categories;
    }

    $entry = ['slug' => $category, 'title' => ucfirst($category)];

    foreach ($categories as $i => $existing) {
        if (($existing['slug'] ?? null) === $category) {
            $entry = $existing;
            unset($categories[ $i ]);
        }
    }

    if (Config::get('category_title') !== null) {
        $entry['title'] = (string) Config::get('category_title');
    }

    array_unshift($categories, $entry);

    return $categories;
}
