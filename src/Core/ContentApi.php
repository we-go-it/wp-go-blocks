<?php

namespace WpGo\Blocks\Core;

use WpGo\Blocks\Renderer\Payload\Normalizer;
use WpGo\Blocks\Renderer\PayloadRenderer;

/**
 * A published page as data, over REST:
 *
 *   GET /wp-json/wp-go-blocks/v1/content?slug=resources/events&lang=en
 *
 * The page renders as the site renders it — its PHP template runs, with the
 * post as the main query — but with a PayloadRenderer instead of the site's
 * renderer, for this request only. So the answer is what `output()` builds:
 * the template's own context, the page's blocks as a tree of
 * payloads — the ones its view draws and the content's, where the view puts
 * them (PayloadRenderer). Plus the post, its language and translations.
 *
 *   slug  a page's path (`resources/events`; empty: the front page, on a
 *         static one), a hierarchical post type's path, or another public
 *         post type's slug
 *   lang  the language, with a languages plugin (I18n: WP-Go Languages or
 *         WPML): the lookup and the strings are that language's. One the
 *         site doesn't have is a 400
 *   type  a post type, when a slug is ambiguous: a slug that more than one
 *         type has is a 409 without it
 *
 * Published posts only, the front page and its translations too: a 404
 * otherwise. A post behind a password is a 401 unless its password was
 * given (post_password_required()). Off unless boot()'s `content_api` is
 * true.
 *
 * The block editor's preview, the same page with unsaved blocks:
 *
 *   POST /wp-json/wp-go-blocks/v1/content  { post_id, content }
 *
 * renders the post (any status) with `content` as its content instead of
 * the stored one, in its own language. Nothing is stored. For someone who
 * can edit the post.
 *
 * What is the same on every page, once per language — the footer's options,
 * say — is a call of its own, not repeated in each page:
 *
 *   GET /wp-json/wp-go-blocks/v1/site?lang=en
 *
 * The site's name, description and home URL, its language and, with a
 * languages plugin, its languages (`{ code, name, locale, url }`, `url` the
 * language's home), its menus (every registered location that has one: PayloadRenderer::menu()),
 * plus what the theme adds on `wp_go_blocks/site` — which gets all of it, the
 * menus included, and can change any of it. Built as data: the PayloadRenderer
 * is the renderer while it is, so what a theme shapes through it (images) comes
 * out as data too; normalized like a payload. Off with the content API.
 */
final class ContentApi
{
    public const NAMESPACE = 'wp-go-blocks/v1';

    /** On `rest_api_init`. */
    public static function register(): void
    {
        register_rest_route(self::NAMESPACE, '/content', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'respond'],
            'permission_callback' => '__return_true',
            'args'                => [
                'slug' => ['type' => 'string', 'default' => ''],
                'lang' => ['type' => 'string', 'default' => ''],
                'type' => ['type' => 'string', 'default' => ''],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/content', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'preview'],
            'permission_callback' => static fn (\WP_REST_Request $request) => current_user_can('edit_post', (int) $request['post_id']),
            'args'                => [
                'post_id' => ['type' => 'integer', 'required' => true],
                'content' => ['type' => 'string', 'required' => true],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/site', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'site'],
            'permission_callback' => '__return_true',
            'args'                => [
                'lang' => ['type' => 'string', 'default' => ''],
            ],
        ]);
    }

    /**
     * The site-wide data, in `lang` (with a languages plugin).
     *
     * @return \WP_REST_Response
     */
    public static function site(\WP_REST_Request $request)
    {
        $lang = sanitize_key((string) $request['lang']);

        if ($error = self::unknown($lang)) {
            return $error;
        }

        return I18n::in($lang, static function (?string $lang) {
            $renderer = Config::use_renderer(new PayloadRenderer());

            try {
                return self::site_data($lang);
            } finally {
                Config::use_renderer($renderer);
            }
        });
    }

    /**
     * The site API's answer, in `$lang` (null: the site's own), with the
     * PayloadRenderer as the renderer.
     *
     * @return \WP_REST_Response
     */
    private static function site_data(?string $lang)
    {
        $menus = PayloadRenderer::menus();
        $site  = [
            'name'        => get_bloginfo('name'),
            'description' => get_bloginfo('description'),
            'url'         => I18n::home_url($lang),
            'lang'        => $lang,
            'languages'   => I18n::languages(),
            'menus'       => $menus,
        ];

        /**
         * The site-wide data: what every page shows the same, per language
         * — options, the footer's lists. Shape it as a block's payload
         * would be; it is normalized as one. What is in it already, the
         * menus too, is the theme's to change: what it returns is the answer.
         *
         * @param array<string,mixed> $site
         * @param string|null         $lang The language asked for, with a languages plugin.
         */
        $site = (array) apply_filters('wp_go_blocks/site', $site, $lang);

        // the menus are data already: normalized again, a title's entities
        // would be decoded twice. Only what the theme put in their place is.
        $data = [];

        foreach ($site as $key => $value) {
            $data[ $key ] = $key === 'menus' && $value === $menus ? $value : PayloadRenderer::normalize($value);
        }

        $data = Normalizer::without_nulls($data);

        // an object even when there are none: `{}`, not `[]`
        if (($data['menus'] ?? null) === []) {
            $data['menus'] = new \stdClass();
        }

        return rest_ensure_response($data);
    }

    /**
     * The block editor's preview: the post with the editor's unsaved content,
     * in the post's own language.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    public static function preview(\WP_REST_Request $request)
    {
        $post = get_post((int) $request['post_id']);

        if (!$post || !is_post_type_viewable($post->post_type)) {
            return new \WP_Error('wp_go_blocks_not_found', 'No viewable post with that ID.', ['status' => 404]);
        }

        return self::answer($post, I18n::language_of($post), (string) $request['content']);
    }

    /**
     * @return \WP_REST_Response|\WP_Error
     */
    public static function respond(\WP_REST_Request $request)
    {
        $slug = trim((string) $request['slug'], '/');
        $lang = sanitize_key((string) $request['lang']);
        $type = sanitize_key((string) $request['type']);

        if ($type !== '' && !is_post_type_viewable($type)) {
            return new \WP_Error('wp_go_blocks_bad_type', "No public post type `$type`.", ['status' => 400]);
        }

        if ($error = self::unknown($lang)) {
            return $error;
        }

        $post = I18n::in($lang, static fn () => self::find($slug, $type));

        if (!$post || $post instanceof \WP_Error) {
            return $post ?: new \WP_Error('wp_go_blocks_not_found', 'No published content at that slug.', ['status' => 404]);
        }

        // as WordPress's own pages: not without its password
        if (post_password_required($post)) {
            return new \WP_Error('wp_go_blocks_password', 'The content is password protected.', ['status' => 401]);
        }

        return self::answer($post, $lang);
    }

    /**
     * A 400 for a language the site doesn't have, with a languages plugin:
     * rather than its default language's page, under the wrong `lang`.
     * Without one, `lang` means nothing and is let be.
     */
    private static function unknown(string $lang): ?\WP_Error
    {
        if ($lang === '' || I18n::plugin() === null || I18n::is_language($lang)) {
            return null;
        }

        return new \WP_Error('wp_go_blocks_bad_lang', "No language `$lang`.", ['status' => 400]);
    }

    /**
     * The post's page and what goes with it, in `$lang` (with a languages plugin), with
     * `$content` instead of its stored content when given.
     *
     * @return \WP_REST_Response|\WP_Error
     */
    private static function answer(\WP_Post $post, string $lang, ?string $content = null)
    {
        return I18n::in($lang, static function (?string $lang) use ($post, $content) {
            $page = self::render($post, $content);

            if ($page === null) {
                return new \WP_Error('wp_go_blocks_no_page', "The post's template rendered no page.", ['status' => 500]);
            }

            // no nulls, as in the payloads: absent
            return rest_ensure_response(Normalizer::without_nulls([
                'post'         => PayloadRenderer::normalize($post),
                'lang'         => $lang,
                'translations' => I18n::translations($post) ?: new \stdClass(),
            ] + $page));
        });
    }

    /**
     * The published post at a slug, in the current language, among the
     * public post types — or only `$type`. An empty slug is the front page
     * (the languages plugin gives its translation), when the site has a
     * static one (`show_on_front` is `page`) and it is a published page. A
     * hierarchical type (a page) is matched on its whole path, any other on
     * its slug alone, so a path is never one of its posts. A slug more than
     * one type has is a 409, to be asked again with `type`.
     *
     * @return \WP_Post|\WP_Error|null
     */
    private static function find(string $slug, string $type)
    {
        if ($slug === '') {
            // on "Your latest posts" there is none, whatever page_on_front still holds
            $static = get_option('show_on_front') === 'page' && ($type === '' || $type === 'page');
            $id     = $static ? (int) get_option('page_on_front') : 0;
            $front  = $id ? get_post($id) : null;

            // the translation's own status: the plugin gives it whatever it is
            return $front && $front->post_type === 'page' && $front->post_status === 'publish' ? $front : null;
        }

        $types = $type !== ''
            ? [$type]
            : array_values(array_diff(get_post_types(['public' => true]), ['attachment']));

        $found = [];

        foreach ($types as $candidate) {
            $hierarchical = is_post_type_hierarchical($candidate);

            if (!$hierarchical && str_contains($slug, '/')) {
                continue;
            }

            $post = $hierarchical ? self::by_path($slug, $candidate) : (get_posts([
                'name'             => $slug,
                'post_type'        => $candidate,
                'post_status'      => 'publish',
                'posts_per_page'   => 1,
                'no_found_rows'    => true,
                'suppress_filters' => false,
            ])[0] ?? null);

            if ($post) {
                $found[$candidate] = $post;
            }
        }

        if (count($found) > 1) {
            $which = implode('`, `', array_keys($found));

            return new \WP_Error('wp_go_blocks_ambiguous', "More than one post type has that slug (`$which`): ask with `type`.", ['status' => 409]);
        }

        return $found ? reset($found) : null;
    }

    /**
     * The published post of a hierarchical type at a path, in the current
     * language: the posts whose slug is the path's last part, through the
     * query, so the languages plugin keeps to the current language (the
     * same slug in another language is another post); the one whose whole
     * path (get_page_uri()) is the path. Not through `pagename`: for the
     * posts page's path WordPress's query lists the blog and drops the path.
     * Its parts are read as get_page_by_path() reads them.
     */
    private static function by_path(string $slug, string $type): ?\WP_Post
    {
        $path  = str_replace(['%2F', '%20'], ['/', ' '], rawurlencode(urldecode($slug)));
        $parts = array_map('sanitize_title_for_query', explode('/', trim($path, '/')));
        $path  = implode('/', $parts);

        $posts = get_posts([
            'post_name__in'    => [end($parts)],
            'post_type'        => $type,
            'post_status'      => 'publish',
            'posts_per_page'   => -1,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'no_found_rows'    => true,
            'suppress_filters' => false,
        ]);

        foreach ($posts as $post) {
            if (get_page_uri($post) === $path) {
                return $post;
            }
        }

        return null;
    }

    /**
     * The post's page, as data: its template runs with the post as the main
     * query, rendered with a PayloadRenderer that hands the page back.
     * Everything swapped is put back, whatever the template throws. A
     * template that exits (`exit`, wp_die(), a redirect) ends the request
     * there, as it would the page's.
     *
     * @return array<string,mixed>|null
     */
    private static function render(\WP_Post $post, ?string $content = null): ?array
    {
        global $wp_query, $wp_the_query;

        // the site's renderer, to draw the page's body with (PageBody)
        $renderer = new PayloadRenderer(Config::renderer());

        if ($content !== null) {
            $renderer->use_content($post->ID, $content);
        }

        $saved = [$wp_query, $wp_the_query, $GLOBALS['post'] ?? null, Config::renderer()];

        try {
            Config::use_renderer($renderer);

            // A page by `page_id`: by `p` it would not be is_page(), and its
            // template would be picked as a single post's. In its own status,
            // which the editor's preview may have it in (a draft, an
            // auto-draft): without it, the query drops the ones it keeps out
            // of lists. The posts page by its `page_id` alone, as the site
            // queries it: is_home(), the blog's posts in the main query.
            $wp_query = $wp_the_query = new \WP_Query(self::is_posts_page($post) ? ['page_id' => $post->ID] : [
                $post->post_type === 'page' ? 'page_id' : 'p' => $post->ID,
                'post_type'   => $post->post_type,
                'post_status' => $post->post_status,
            ]);
            $GLOBALS['post'] = $post;
            setup_postdata($post);

            $template = (string) apply_filters('template_include', self::template());

            return $renderer->capture(static function () use ($template) {
                if ($template === '' || !is_readable($template)) {
                    return;
                }

                // anything a template prints outside its render is not the
                // page, buffers it leaves open too
                $level = ob_get_level();
                ob_start();

                try {
                    // with the globals a template has (`$post`, `$wp_query`…)
                    load_template($template, false);
                } finally {
                    while (ob_get_level() > $level) {
                        ob_end_clean();
                    }
                }
            });
        } finally {
            [$wp_query, $wp_the_query, $GLOBALS['post']] = $saved;
            Config::use_renderer($saved[3]);
            wp_reset_postdata();
        }
    }

    /** Whether the post is the posts page ("Your homepage displays" a static page). */
    private static function is_posts_page(\WP_Post $post): bool
    {
        return $post->post_type === 'page'
            && get_option('show_on_front') === 'page'
            && (int) get_option('page_for_posts') === $post->ID;
    }

    /**
     * The template WordPress picks for the main query, a post's
     * (template-loader.php: the conditions a post can meet, in its order):
     * the first that has one, else index.php.
     */
    private static function template(): string
    {
        $tags = [
            'is_front_page'     => 'get_front_page_template',
            'is_home'           => 'get_home_template',
            'is_privacy_policy' => 'get_privacy_policy_template',
            'is_attachment'     => 'get_attachment_template',
            'is_single'         => 'get_single_template',
            'is_page'           => 'get_page_template',
            'is_singular'       => 'get_singular_template',
        ];

        foreach ($tags as $tag => $getter) {
            if ($tag() && ($template = $getter())) {
                return $template;
            }
        }

        return get_index_template();
    }
}
