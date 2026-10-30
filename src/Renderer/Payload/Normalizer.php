<?php

namespace WpGo\Blocks\Renderer\Payload;

/**
 * Values as plain data, for JSON: what a payload, a page and the site API are
 * made of (PayloadRenderer). Posts, terms, users, dates and markup become
 * arrays and strings, a value that would be null is left out of an object,
 * and a string without markup is text.
 *
 * The package's own: a theme calls PayloadRenderer's statics, which hand over
 * to it.
 */
final class Normalizer
{
    /**
     * How deep objects go inside objects (a JsonSerializable's data holding
     * more of them): past it, an object is `…`. Arrays don't count, so a
     * deep field group stays whole.
     */
    private const DEPTH = 6;

    /**
     * An image attachment as a front end needs it, from WordPress alone:
     *
     *   { id, url, width, height, alt, mime, srcset }
     *
     * `url`, `width` and `height` are the full image's, `srcset` WordPress's
     * own for it: every size made with the same proportions. A hard-cropped
     * size (`thumbnail`) is not in it. `alt` is the media library's. The
     * formats are the ones uploaded: converting them (WebP, AVIF) is the front
     * end's. Null for anything that is not an image attachment.
     *
     * @return array<string,mixed>|null
     */
    public static function image(mixed $id): ?array
    {
        $id = is_numeric($id) ? (int) $id : 0;

        if ($id <= 0 || !wp_attachment_is_image($id)) {
            return null;
        }

        $full = wp_get_attachment_image_src($id, 'full');

        if (!$full) {
            return null;
        }

        $image = [
            'id'     => $id,
            'url'    => $full[0],
            'width'  => (int) $full[1],
            'height' => (int) $full[2],
            'alt'    => trim((string) get_post_meta($id, '_wp_attachment_image_alt', true)),
            'mime'   => get_post_mime_type($id) ?: null,
            'srcset' => wp_get_attachment_image_srcset($id, 'full') ?: null,
        ];

        /**
         * An image as data: for what the theme knows of its images and
         * WordPress doesn't (a blurred placeholder, a dominant colour, its own
         * format copies).
         *
         * @param array<string,mixed> $image
         * @param int                 $id    The attachment.
         */
        return (array) apply_filters('wp_go_blocks/payload_image', $image, $id);
    }

    /**
     * An object's keys without the null ones, this level alone: absent is how
     * a payload says null. A list keeps its entries, nulls too: their
     * positions are the data.
     *
     * @param array<mixed> $value
     * @return array<mixed>
     */
    public static function without_nulls(array $value): array
    {
        return array_is_list($value) ? $value : array_filter($value, fn ($item) => $item !== null);
    }

    /**
     * An array without its null values (without_nulls()), at any depth
     * (`$deep`, or this level alone), so a front end reads one contract,
     * absent. Deep, its strings are made text() too.
     *
     * @param array<mixed> $value
     * @return array<mixed>
     */
    public static function compact(array $value, bool $deep = true): array
    {
        if ($deep) {
            $value = array_map(fn ($item) => match (true) {
                is_array($item)  => self::compact($item),
                is_string($item) => self::text($item),
                default          => $item,
            }, $value);
        }

        return self::without_nulls($value);
    }

    /**
     * A string as a front end reads it: without markup it is text — a title, a
     * name, an eyebrow — and its entities are decoded (`HR &#038; payroll` is
     * `HR & payroll`), since WordPress encodes them for HTML. With markup it is
     * HTML (a rich-text field), and left as it is.
     */
    public static function text(string $value): string
    {
        return str_contains($value, '&') && !preg_match('/<[a-z!\/]/i', $value)
            ? html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : $value;
    }

    /**
     * Any value as plain data, for JSON: arrays walked, nulls left out of
     * them (compact()), strings made text(), and objects turned into what
     * identifies them — a post into its ID, type, title and link (an image
     * attachment into image()), a term into its ID, taxonomy, name and slug, a
     * user into its ID, name and author page, a Timber menu into
     * Menus::menu(), a date into ISO 8601, markup into its string, a
     * JsonSerializable into its data, a stdClass into its properties.
     *
     * Any other object is its class alone: what it holds is not known to be
     * for a page — a WP_User's password hash and email, a WP_Query's SQL —
     * so it says what was there without spilling it. A payload that wants
     * more of one picks it out itself.
     *
     * @param mixed $value
     * @param int   $depth How deep in objects `$value` is.
     * @return mixed
     */
    public static function normalize($value, int $depth = 0)
    {
        if (is_string($value)) {
            return self::text($value);
        }

        if (is_array($value)) {
            return self::without_nulls(array_map(fn ($item) => self::normalize($item, $depth), $value));
        }

        if (!is_object($value)) {
            return $value;
        }

        // only an object can lead back to itself: one inside its own data
        if ($depth > self::DEPTH) {
            return '…';
        }

        if ($value instanceof \WP_Post || $value instanceof \Timber\Post) {
            $id = (int) $value->ID;

            // an image attachment is an image, not a post: data as a payload
            // is, its null keys (no srcset, a theme's null) left out
            if (get_post_type($id) === 'attachment' && ($image = self::image($id))) {
                return self::normalize($image, $depth);
            }

            return ['id' => $id, 'type' => get_post_type($id), 'title' => self::text(get_the_title($id)), 'url' => get_permalink($id)];
        }

        if ($value instanceof \WP_Term || $value instanceof \Timber\Term) {
            $term = $value instanceof \WP_Term ? $value : get_term($value->ID);

            return ['id' => $term->term_id, 'taxonomy' => $term->taxonomy, 'name' => self::text($term->name), 'slug' => $term->slug];
        }

        // what a page may show of a user, as the author line does
        if ($value instanceof \WP_User || $value instanceof \Timber\User) {
            $id = (int) $value->ID;

            return ['id' => $id, 'name' => self::text((string) get_the_author_meta('display_name', $id)), 'url' => get_author_posts_url($id)];
        }

        // before Stringable: a Timber menu's string is its markup
        if ($value instanceof \Timber\Menu) {
            return Menus::menu((int) $value->term_id);
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if ($value instanceof \JsonSerializable) {
            return self::normalize($value->jsonSerialize(), $depth + 1);
        }

        if ($value instanceof \Stringable) {
            return self::text((string) $value);
        }

        // PHP's plain record (a decoded JSON object): data already, and still
        // an object, so an empty one is `{}`
        if ($value instanceof \stdClass) {
            return (object) self::normalize(get_object_vars($value), $depth + 1);
        }

        return ['class' => $value::class];
    }
}
