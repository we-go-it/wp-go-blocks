<?php

/**
 * A stubbed WPML for data-i18n-wpml.test.php, loaded by wp-cli before
 * WordPress (`// @require fixtures/data-wpml-stub.php`): ICL_SITEPRESS_VERSION
 * defined, so Core\I18n and WP-Go see WPML and WP-Go Languages stands down,
 * and the filters and actions Core\I18n asks answered from
 * $GLOBALS['dx_wpml'], which the test fills:
 *
 *   current    WPML's current language
 *   switches   every wpml_switch_language asked, in order
 *   elements   ["post_<type>:<id>" => ['trid' => int, 'lang' => code]]
 *
 * Italian is the default and first, English the other language, its
 * addresses under /en/ (wpml_permalink, wpml_home_url). A $sitepress
 * stand-in answers ACF, which includes its WPML support when the constant
 * is there.
 */

define('ICL_SITEPRESS_VERSION', 'stub');

$GLOBALS['dx_wpml'] = ['current' => 'it', 'switches' => [], 'elements' => []];

$GLOBALS['sitepress'] = new class {
    public function get_default_language()
    {
        return 'it';
    }

    public function get_current_language()
    {
        return $GLOBALS['dx_wpml']['current'];
    }

    public function __call($name, $arguments)
    {
        return null;
    }
};

$dx_wpml_url = static function (string $url, ?string $code): string {
    $home = rtrim(get_option('home'), '/');

    return $code === 'it' || $code === null || !str_starts_with($url, $home) ? $url : $home . '/' . $code . substr($url, strlen($home));
};

WP_CLI::add_wp_hook('wpml_active_languages', fn () => [
    'it' => ['code' => 'it', 'native_name' => 'Italiano', 'default_locale' => 'it_IT', 'url' => 'not-the-home-it'],
    'en' => ['code' => 'en', 'native_name' => 'English', 'default_locale' => 'en_US', 'url' => 'not-the-home-en'],
]);
WP_CLI::add_wp_hook('wpml_default_language', fn () => 'it');
WP_CLI::add_wp_hook('wpml_current_language', fn () => $GLOBALS['dx_wpml']['current']);
WP_CLI::add_wp_hook('wpml_switch_language', function ($code) {
    $GLOBALS['dx_wpml']['switches'][] = $code;
    $GLOBALS['dx_wpml']['current'] = $code ?? 'it';
});
WP_CLI::add_wp_hook('wpml_element_language_code', fn ($answer, $args) => $GLOBALS['dx_wpml']['elements'][ $args['element_type'] . ':' . $args['element_id'] ]['lang'] ?? null, 10, 2);
WP_CLI::add_wp_hook('wpml_element_trid', fn ($answer, $id, $type) => $GLOBALS['dx_wpml']['elements']["$type:$id"]['trid'] ?? null, 10, 3);
WP_CLI::add_wp_hook('wpml_get_element_translations', function ($answer, $trid, $type) {
    $out = [];

    foreach ($GLOBALS['dx_wpml']['elements'] as $key => $element) {
        [$element_type, $id] = explode(':', $key);

        if ($element_type === $type && $element['trid'] === $trid) {
            $out[ $element['lang'] ] = (object) ['element_id' => $id, 'language_code' => $element['lang']];
        }
    }

    return $out;
}, 10, 3);
WP_CLI::add_wp_hook('wpml_permalink', fn ($url, $code = null) => $dx_wpml_url((string) $url, $code), 10, 2);
WP_CLI::add_wp_hook('wpml_home_url', fn ($url) => $dx_wpml_url((string) $url, $GLOBALS['dx_wpml']['current']));
