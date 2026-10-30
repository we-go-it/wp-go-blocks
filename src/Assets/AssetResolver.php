<?php

namespace WpGo\Blocks\Assets;

/**
 * Where a block's compiled CSS and JS are. The package knows which source
 * files a page needs; the theme knows how they were built (Vite, a dev server,
 * a manifest) and answers with URLs.
 */
interface AssetResolver
{
    /**
     * The URLs for one source file, theme-relative (`blocks/numbers/numbers.scss`,
     * `blocks/numbers/numbers.js`, a bundle's `form/form.js`). Called only for a
     * file that exists, once per request. Empty lists when it was not built.
     *
     * - `css`: stylesheets, printed after the main stylesheet: every one the
     *   file needs, those of the chunks a script imports included, less what
     *   the main stylesheet already carries;
     * - `js`: module scripts, printed before the main bundle;
     * - `preload`: chunks the script imports that the main bundle does not
     *   already pull in, printed as modulepreload.
     *
     * @return array{css: string[], js: string[], preload: string[]}
     */
    public function urls(string $source): array;
}
