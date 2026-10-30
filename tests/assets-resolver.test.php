<?php

/**
 * The theme's resolver, WG_Vite_Block_Assets (includes/theme/block-assets.php),
 * on the theme's ViteConfig (helpers/vite-config.php): the dev server's URLs
 * while it runs, the manifest's once built, a script's preloads and
 * stylesheets less what the main bundle already has.
 *
 * The real build isn't read: ViteConfig is pointed at a fake manifest
 * (fixtures/assets-vite/dist/.vite/manifest.json), and told whether the dev
 * server runs instead of asking it. The fake manifest, as Vite writes one:
 *
 *   src/main.js            imports vendor-alpine and shared (whose CSS is shared.css)
 *   blocks/hero/hero.js    imports vendor-alpine: the main bundle's
 *   blocks/tabs/tabs.js    imports tabs-chunk (with its CSS), which imports
 *                          vendor-alpine; dynamically imports lazy
 *   form/form.js           imports vendor-zod, which imports vendor-zod-core
 *                          (with its CSS), and shared; has its own CSS
 */

use WpGo\Blocks\Core\Config;

const ASSETS_VITE = __DIR__ . '/fixtures/assets-vite';
const ASSETS_DIST = 'https://theme.test/assets/dist/assets/';

/** ViteConfig on the fixture's manifest, the dev server running or not. */
final class AssetsFakeVite extends ViteConfig
{
    public function __construct(bool $running, string $base_path = ASSETS_VITE)
    {
        parent::__construct();

        $this->base_path         = $base_path;
        $this->base_url          = 'https://theme.test/assets';
        $this->port              = 5999;
        $this->server_is_running = $running;
    }
}

/** Built files' URLs, by their names in the out dir. */
function assets_dist(string ...$files): array
{
    return array_map(fn ($file) => ASSETS_DIST . $file, $files);
}

/** The theme's resolver, on a ViteConfig made the request's one. */
function assets_resolver(ViteConfig $vite): WG_Vite_Block_Assets
{
    (new ReflectionProperty(ViteConfig::class, 'instance'))->setValue(null, $vite);

    return new WG_Vite_Block_Assets();
}

section('The theme\'s resolver');

check('the theme boots the package with WG_Vite_Block_Assets', Config::assets() instanceof WG_Vite_Block_Assets);
check('which is an AssetResolver', Config::assets() instanceof \WpGo\Blocks\Assets\AssetResolver);
check('ViteConfig has one instance for the request', ViteConfig::instance() === ViteConfig::instance());
same('development', \Env\env('WP_ENV'), 'this site runs in development (what the dev server\'s check needs)');

$instance = (new ReflectionProperty(ViteConfig::class, 'instance'))->getValue();
$settings = (new ReflectionProperty(Config::class, 'settings'))->getValue();

try {
    section('Built: the manifest');

    $vite     = new AssetsFakeVite(false);
    $resolver = assets_resolver($vite);

    check('the dev server isn\'t running', !$vite->viteServerRunning());
    same('src/main.js', $vite->entry(), 'entry() is wp-go-blocks.json\'s entries.main');

    same(['css' => assets_dist('hero-H1.css'), 'js' => [], 'preload' => []], $resolver->urls('blocks/hero/hero.scss'), 'a stylesheet: its built file, nothing else');
    same(['css' => [], 'js' => assets_dist('hero-H1.js'), 'preload' => []], $resolver->urls('blocks/hero/hero.js'), 'a script importing only what the main bundle imports: its file, no preload');
    same(
        ['css' => assets_dist('tabs-chunk-C1.css'), 'js' => assets_dist('tabs-T1.js'), 'preload' => assets_dist('tabs-chunk-C1.js')],
        $resolver->urls('blocks/tabs/tabs.js'),
        'a script\'s chunk: preloaded, and its CSS through the import; the dynamic import neither, nor the main bundle\'s'
    );
    same(
        ['css' => assets_dist('zod-core-K1.css', 'form-F1.css'), 'js' => assets_dist('form-F1.js'), 'preload' => assets_dist('vendor-zod-Z1.js', 'vendor-zod-core-K1.js')],
        $resolver->urls('form/form.js'),
        'imports walked transitively, less the main bundle\'s: the chunks\' CSS then the script\'s own, less the main stylesheet\'s'
    );
    same(['css' => [], 'js' => [], 'preload' => []], $resolver->urls('blocks/none/none.js'), 'a source the build doesn\'t have: empty lists, no error');

    section('ViteConfig');

    same(assets_dist('shared-S1.css', 'main-M1.css'), $vite->cssUrls(), 'cssUrls(): the main entry\'s, its chunks\' stylesheets first, then its own');
    same(assets_dist('zod-core-K1.css', 'shared-S1.css', 'form-F1.css'), $vite->cssUrls('form/form.js'), 'cssUrls($entry): another entry\'s, the same way, through the imports\' imports');
    same(assets_dist('vendor-alpine-A1.js', 'shared-S1.js'), $vite->importsUrls($vite->entry()), 'importsUrls(): each chunk once, though two import it');
    same(ASSETS_DIST . 'form-F1.js', $vite->assetUrl('form/form.js'), 'assetUrl(): the manifest\'s file');
    same('', $vite->assetUrl('blocks/none/none.js'), 'and nothing for a source it doesn\'t have');

    section('entry(), read when asked');

    // the theme's main entry changed after ViteConfig was made: what boot()
    // reading wp-go-blocks.json after the instance exists amounts to
    Config::set(Config::root(), array_replace($settings, ['entries' => ['main' => 'src/other.js']]));

    $vite     = new AssetsFakeVite(false);
    $resolver = assets_resolver($vite);

    same('src/other.js', $vite->entry(), 'entry() follows entries.main as it is when asked');
    same(assets_dist('zod-core-K1.css', 'other-O1.css'), $vite->cssUrls(), 'and so does cssUrls()');
    same(
        ['css' => assets_dist('shared-S1.css', 'form-F1.css'), 'js' => assets_dist('form-F1.js'), 'preload' => assets_dist('shared-S1.js', 'vendor-alpine-A1.js')],
        $resolver->urls('form/form.js'),
        'what the resolver leaves out is that entry\'s: shared and the chunk it imports are preloaded now'
    );

    Config::set(Config::root(), $settings);

    section('Not built at all');

    // a folder with no dist/ in it: a site deployed without its build
    $warnings = [];
    set_error_handler(function ($level, $message) use (&$warnings) {
        $warnings[] = $message;

        return true;
    });

    try {
        $resolver = assets_resolver(new AssetsFakeVite(false, __DIR__ . '/fixtures/assets-theme'));
        $none     = $resolver->urls('blocks/hero/hero.js');
    } finally {
        restore_error_handler();
    }

    same(['css' => [], 'js' => [], 'preload' => []], $none, 'without a manifest, a script has empty lists: the block renders unstyled, the page still does');

    section('In development: the dev server');

    $vite     = new AssetsFakeVite(true);
    $resolver = assets_resolver($vite);

    check('the dev server is running', $vite->viteServerRunning());
    same(['css' => ['http://localhost:5999/blocks/hero/hero.scss?direct'], 'js' => [], 'preload' => []], $resolver->urls('blocks/hero/hero.scss'), 'a stylesheet: the server\'s URL with ?direct, for CSS rather than a module');
    same(['css' => [], 'js' => ['http://localhost:5999/form/form.js'], 'preload' => []], $resolver->urls('form/form.js'), 'a script: the server\'s URL, nothing to preload or add');

    same([], $vite->cssUrls('form/form.js'), 'cssUrls() has no manifest while the server runs');
    same(assets_dist('zod-core-K1.css', 'shared-S1.css', 'form-F1.css'), $vite->cssUrls('form/form.js', true), 'cssUrls($entry, true): the build\'s, from the manifest, all the same');
    same(assets_dist('shared-S1.css', 'main-M1.css'), $vite->cssUrls(null, true), 'cssUrls(null, true): the main entry\'s');
    same([], $vite->cssUrls('form/form.js'), 'and forcing it once doesn\'t make the server\'s answer the manifest\'s');
} finally {
    (new ReflectionProperty(ViteConfig::class, 'instance'))->setValue(null, $instance);
    Config::set(Config::root(), $settings);
}
