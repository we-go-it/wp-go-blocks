<?php

namespace WpGo\Blocks\Core;

/**
 * The site's languages, for the content API, whichever plugin gives them:
 * WP-Go Languages (`WpGoLanguages`), WPML, or neither (one language).
 *
 * The package's own: it asks the plugins directly, so it needs nothing else
 * installed. WPML first, as WP-Go Languages stands down while WPML is active.
 */
final class I18n
{
    /** @var list<mixed> WPML's languages switched from, last first out. */
    private static array $previous = [];

    /** `wp-go-languages`, `wpml`, or null. */
    public static function plugin(): ?string
    {
        if (defined('ICL_SITEPRESS_VERSION')) {
            return 'wpml';
        }

        return class_exists('WpGoLanguages', false) ? 'wp-go-languages' : null;
    }

    /** Whether `$lang` is one of the site's active languages: never with neither plugin. */
    public static function is_language(string $lang): bool
    {
        return match (self::plugin()) {
            'wp-go-languages' => \WpGoLanguages::is_language($lang),
            'wpml'            => isset(self::wpml_languages()[$lang]),
            default           => false,
        };
    }

    /**
     * Make `$lang` current, until restore(): false when there is nothing to
     * switch to (no language asked, none of the site's, or no plugin).
     */
    public static function switch(string $lang): bool
    {
        if ($lang === '' || !self::is_language($lang)) {
            return false;
        }

        if (self::plugin() === 'wpml') {
            self::$previous[] = apply_filters('wpml_current_language', null);
            do_action('wpml_switch_language', $lang);
        } else {
            \WpGoLanguages::switch($lang);
        }

        return true;
    }

    /**
     * `$fn` with `$lang` current (switch()), and the language before it back
     * after, however `$fn` ends. `$fn` is given the language it runs in, or
     * null when there was none to switch to.
     *
     * @template T
     * @param callable(string|null): T $fn
     * @return T
     */
    public static function in(string $lang, callable $fn): mixed
    {
        $switched = self::switch($lang);

        try {
            return $fn($switched ? $lang : null);
        } finally {
            if ($switched) {
                self::restore();
            }
        }
    }

    /** Back to the language before the last switch() that switched. */
    public static function restore(): void
    {
        match (self::plugin()) {
            'wp-go-languages' => \WpGoLanguages::restore(),
            'wpml'            => do_action('wpml_switch_language', array_pop(self::$previous)),
            default           => null,
        };
    }

    /** A language's home URL; the current language's without one. */
    public static function home_url(?string $lang = null): string
    {
        return match (self::plugin()) {
            'wp-go-languages' => \WpGoLanguages::home_url($lang ?? \WpGoLanguages::current()),
            'wpml'            => $lang !== null
                ? (string) apply_filters('wpml_permalink', home_url('/'), $lang)
                : (string) apply_filters('wpml_home_url', home_url('/')),
            default           => home_url('/'),
        };
    }

    /**
     * The active languages, in order: `{ code, name, locale, url }` each,
     * `url` the language's home. Null with neither plugin.
     *
     * @return list<array{code:string,name:string,locale:string,url:string}>|null
     */
    public static function languages(): ?array
    {
        $languages = match (self::plugin()) {
            'wp-go-languages' => array_map(fn (array $language) => [
                'code'   => $language['code'],
                'name'   => $language['name'],
                'locale' => $language['locale'],
            ], \WpGoLanguages::languages()),
            'wpml'            => array_map(fn (array $language) => [
                'code'   => (string) $language['code'],
                'name'   => (string) ($language['native_name'] ?? $language['code']),
                'locale' => (string) ($language['default_locale'] ?? ''),
            ], self::wpml_languages()),
            default           => [],
        };

        // the home's, not WPML's `url`, which is the current page's in that language
        return $languages
            ? array_values(array_map(fn (array $language) => $language + ['url' => self::home_url($language['code'])], $languages))
            : null;
    }

    /**
     * WPML's active languages, by code.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function wpml_languages(): array
    {
        $languages = apply_filters('wpml_active_languages', null, 'skip_missing=0&orderby=id&order=asc');

        return is_array($languages) ? $languages : [];
    }

    /** A post's language, or '' without one. */
    public static function language_of(\WP_Post $post): string
    {
        return match (self::plugin()) {
            'wp-go-languages' => (string) \WpGoLanguages::language_of($post->ID),
            'wpml'            => (string) apply_filters('wpml_element_language_code', null, [
                'element_id'   => $post->ID,
                'element_type' => 'post_' . $post->post_type,
            ]),
            default           => '',
        };
    }

    /**
     * A post's published translations, itself included, by language: `{ id,
     * slug, url }`, `url` in the translation's own language. Empty with
     * neither plugin.
     *
     * @return array<string,array{id:int,slug:string,url:string}>
     */
    public static function translations(\WP_Post $post): array
    {
        $ids = [];

        switch (self::plugin()) {
            case 'wp-go-languages':
                $ids = \WpGoLanguages::translations($post->ID);

                break;

            case 'wpml':
                $element = 'post_' . $post->post_type;
                $trid    = apply_filters('wpml_element_trid', null, $post->ID, $element);

                foreach ($trid ? (array) apply_filters('wpml_get_element_translations', [], $trid, $element) : [] as $code => $translation) {
                    if ((int) ($translation->element_id ?? 0)) {
                        $ids[(string) $code] = (int) $translation->element_id;
                    }
                }

                break;
        }

        $out = [];

        foreach ($ids as $code => $id) {
            if (get_post_status($id) !== 'publish') {
                continue;
            }

            $out[$code] = [
                'id'   => $id,
                'slug' => (string) get_post_field('post_name', $id),
                // WP-Go Languages gives a post's link in its own language;
                // WPML's "adjust IDs" may give the current one's, hence wpml_permalink
                'url'  => self::plugin() === 'wpml'
                    ? (string) apply_filters('wpml_permalink', get_permalink($id), $code)
                    : (string) get_permalink($id),
            ];
        }

        return $out;
    }
}
