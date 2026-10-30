<?php

namespace WpGo\Blocks\Core;

/**
 * Reading a block's fields: what a block hands its template.
 *
 * A field its own conditional logic hides is not part of the block, and
 * neither is an empty one, so neither is sent (fields()).
 *
 * Filters let something else shape the result without this file knowing what:
 *
 *   - `wp_go_blocks/field_value` — every field's value as it is read, before the
 *     empty check (a dev tool filling empty images with a placeholder, say);
 *   - `wp_go_blocks/group_value` — each group and clone once resolved: the place
 *     to give a shared group (an image, a link, a heading) one shape;
 *   - `wp_go_blocks/fields` — a block's fields as a whole (render_block()).
 *
 * The reverse — values stored the way the editor stores them — is
 * fields-write.php.
 */

/**
 * The block's fields, with everything that is not actually there removed.
 *
 * Every field the block defines is read, stored or not: a field missing from
 * the block's data (the block was saved before the field existed, or written
 * without it) is read as ACF reads any missing value, at its `default_value`
 * (acf_get_value()). That is what the editor shows too, because ACF's block
 * form writes every field into the block's data as it loads. get_field_objects()
 * would only list the fields the data has, so a missing field never got its
 * default on the site.
 *
 * Two things get dropped:
 *
 *  - **fields their own conditional logic hides.** If `media.type` is `image`,
 *    the video fields are not part of this block — even when a value is still
 *    sitting in the database from before the editor switched the type. Reading
 *    the condition is what makes that stale value invisible rather than
 *    something the template has to know to ignore.
 *  - **empty values** — null, '' and [].
 *
 * `false` and `0` survive: a toggle that is off is an answer, not an absence.
 *
 * @param array<string,mixed> $block ACF's block, with its `name`.
 * @return array<string,mixed>
 */
function fields(array $block): array
{
    return visible_fields(field_objects($block));
}

/**
 * ACF's field objects for the block whose meta ACF has set up
 * (acf_setup_meta()), by name: get_field_objects()'s shape, but for every field
 * the block defines (block_fields()), in their order, not only the ones its
 * data has.
 *
 * @param array<string,mixed> $block ACF's block, with its `name`.
 * @return array<string,array<string,mixed>>
 */
function field_objects(array $block): array
{
    $post_id = acf_get_valid_post_id();
    $objects = [];

    foreach (block_fields((string) ($block['name'] ?? '')) as $field) {
        $field['value'] = acf_format_value(acf_get_value($post_id, $field), $post_id, $field);
        $objects[$field['name']] = $field;
    }

    return $objects;
}

/**
 * The fields a block defines at its top level (acf_get_block_fields()), in
 * their order, as a template receives them. Fields that hold no value (a tab, a
 * message) are left out.
 *
 * A seamless clone comes spread into its sub fields, each marked with the
 * clone's key (`_clone`): it is put back as the clone itself, once — `container`,
 * not `container_padding_top`… — which is how ACF stores it (an entry of its
 * own, its sub fields beside it) and how the editor posts it. Shared by both
 * directions: what fields() reads is what field_data() writes.
 *
 * A seamless clone with no name has no entry to put back: its sub fields are
 * stored beside the block's own (`image`, `alt`), and stay top-level fields,
 * as get_fields() returns them and as hydrate() keeps one below the top. They
 * are the only fields here still marked `_clone`: the editor posts them under
 * that clone's key, and field_data() writes them there.
 *
 * @param string $name The block's name, `acf/<slug>`.
 * @return list<array<string,mixed>>
 */
function block_fields(string $name): array
{
    $fields = [];

    foreach (acf_get_block_fields(['name' => $name]) as $field) {
        if (!empty($field['_clone'])) {
            $clone = acf_get_field($field['_clone']);

            if (!$clone) {
                continue;
            }

            if (($clone['name'] ?? '') !== '') {
                if (isset($fields[$clone['key']])) {
                    continue;
                }

                $field = $clone;
            }
        }

        if (holds_value($field)) {
            $fields[$field['key']] = $field;
        }
    }

    return array_values($fields);
}

/**
 * Whether a field holds a value, as opposed to arranging the form: a tab, a
 * message or an accordion holds none. Inside a prefixed clone one of those even
 * gets a name (`container_`), so the type is what tells.
 *
 * @param array<string,mixed> $field
 */
function holds_value(array $field): bool
{
    return !in_array($field['type'] ?? '', ['tab', 'message', 'accordion'], true) && ($field['name'] ?? '') !== '';
}

/**
 * @param array<string,array<string,mixed>> $objects ACF field objects, by name.
 * @return array<string,mixed>
 */
function visible_fields(array $objects): array
{
    // Conditional logic refers to fields by key, and only to siblings. A
    // seamless clone gives the fields it brings compound keys,
    // `<clone key>_<source key>`, while their rules still name the source's
    // keys: so each field is found by its source key too (`__key`). Where one
    // source is cloned twice at the same level, the compound key finds the
    // sibling from the same clone first (clone_scope()).
    $by_key = [];

    foreach ($objects as $field) {
        $by_key[$field['key']] = $field['value'] ?? null;
    }

    foreach ($objects as $field) {
        if (isset($field['__key']) && !array_key_exists($field['__key'], $by_key)) {
            $by_key[$field['__key']] = $field['value'] ?? null;
        }
    }

    $out = [];

    foreach ($objects as $name => $field) {
        if (!conditions_pass($field['conditional_logic'] ?? 0, $by_key, clone_scope($field))) {
            continue;
        }

        $value = $field['value'] ?? null;
        $subs = $field['sub_fields'] ?? [];

        // A number is a number: ACF hands a number or range field back as it
        // was stored, a number written by code, a string saved by its form
        if (in_array($field['type'], ['number', 'range'], true) && is_numeric($value)) {
            $value += 0;
        }

        /**
         * One field's value as it is read, before anything is dropped.
         *
         * @param mixed               $value
         * @param array<string,mixed> $field The ACF field.
         */
        $value = apply_filters('wp_go_blocks/field_value', $value, $field);

        if ($field['type'] === 'flexible_content' && is_array($value)) {
            // Its sub fields are per layout, under `layouts`, not
            // `sub_fields`. A row keeps its layout's name even when none of
            // its fields is left: the layout is itself the content (a divider).
            $layouts = array_column((array) ($field['layouts'] ?? []), 'sub_fields', 'name');
            $rows = [];

            foreach ($value as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $layout = (string) ($row['acf_fc_layout'] ?? '');

                $rows[] = ['acf_fc_layout' => $layout] + visible_fields(hydrate((array) ($layouts[$layout] ?? []), $row));
            }

            $value = $rows;
        } elseif ($subs && is_array($value)) {
            if ($field['type'] === 'repeater') {
                $rows = [];

                foreach ($value as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $visible = visible_fields(hydrate($subs, $row));

                    if ($visible) {
                        $rows[] = $visible;
                    }
                }

                $value = $rows;
            } else {
                // group / clone. A clone keys its value by the names its sub
                // fields have in their source, not the ones it gave them.
                $value = visible_fields(hydrate($subs, $value, $field['type'] === 'clone'));

                /**
                 * A shared shape can be worth normalising before a template
                 * sees it. An empty array drops the group.
                 *
                 * @param array<string,mixed> $value The resolved group value.
                 * @param array<string,mixed> $field The clone/group field.
                 */
                $value = apply_filters('wp_go_blocks/group_value', $value, $field);
            }
        }

        if (is_absent($value, $field['type'])) {
            continue;
        }

        $out[$name] = $value;
    }

    return $out;
}

/**
 * Pair sub-field definitions with one row's values, in the shape
 * visible_fields() expects.
 *
 * Each value is found, and the result keyed, by the name ACF keys it by: a
 * group's or a row's sub field by its own (`_name`), a clone's by the one it
 * has in its source (`__name`). A clone with `prefix_name` names its sub fields
 * `title_text`; a clone inside it is named `cc_h` while its own sub fields stay
 * `h_text`, a name no prefix can be stripped from to find the value. Yet the
 * value is `{ text, tag }` at any depth, and so is the result: a template
 * reading `media.image` does not write `media.media_image`.
 *
 * A seamless clone *below* the top level is not put back together, as a named
 * one is at the top (block_fields()): its sub fields stay where ACF puts them, beside
 * their siblings (`media: { type, image, alt }`, not `media: { …, '': { image,
 * alt } }`), as get_field() returns them.
 *
 * @param array<int,array<string,mixed>> $sub_fields
 * @param array<string,mixed>            $values
 * @param bool                           $in_clone Whether they are a clone's.
 * @return array<string,array<string,mixed>>
 */
function hydrate(array $sub_fields, array $values, bool $in_clone = false): array
{
    $out = [];

    foreach ($sub_fields as $field) {
        if (!holds_value($field)) {
            continue;
        }

        $name = $in_clone ? ($field['__name'] ?? $field['_name'] ?? $field['name']) : ($field['_name'] ?? $field['name']);

        $field['value'] = $values[$name] ?? $values[$field['name']] ?? null;
        $out[$name] = $field;
    }

    return $out;
}

/**
 * The start of a seamless clone's compound keys on one of the fields it
 * brings (`field_<clone>_` on `field_<clone>_field_<source>`), so its rules can
 * be read against the siblings from the same clone. '' on any other field.
 *
 * @param array<string,mixed> $field
 */
function clone_scope(array $field): string
{
    $key = (string) $field['key'];
    $source = (string) ($field['__key'] ?? $key);

    return $source !== $key && str_ends_with($key, '_' . $source) ? substr($key, 0, -strlen($source)) : '';
}

/**
 * ACF's conditional logic: OR between groups, AND within one.
 *
 * Unknown operators pass. A rule this cannot read should leave the field
 * visible — hiding data because the check was not understood is the worse
 * failure of the two.
 *
 * @param mixed                $logic
 * @param array<string,mixed>  $by_key
 * @param string               $scope  clone_scope() of the field the rules are on.
 */
function conditions_pass($logic, array $by_key, string $scope = ''): bool
{
    if (empty($logic) || !is_array($logic)) {
        return true;
    }

    foreach ($logic as $group) {
        if (!is_array($group)) {
            continue;
        }

        $all = true;

        foreach ($group as $rule) {
            if (!rule_passes($rule, $by_key, $scope)) {
                $all = false;
                break;
            }
        }

        if ($all) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string,mixed> $rule
 * @param array<string,mixed> $by_key
 * @param string              $scope  clone_scope() of the field the rule is on.
 */
function rule_passes(array $rule, array $by_key, string $scope = ''): bool
{
    $key = (string) ($rule['field'] ?? '');
    $key = $scope !== '' && array_key_exists($scope . $key, $by_key) ? $scope . $key : $key;

    if (!array_key_exists($key, $by_key)) {
        // The rule points at a field outside this level — not readable here.
        return true;
    }

    $expected = $rule['value'] ?? '';

    // A post, a term or a user resolves to an object, one or a list of them
    // (a relationship, a multiple post object); the rule compares against IDs.
    $id = static fn ($value) => match (true) {
        $value instanceof \WP_Post, $value instanceof \WP_User => $value->ID,
        $value instanceof \WP_Term => $value->term_id,
        default => $value,
    };

    $actual = is_array($by_key[$key]) ? array_map($id, $by_key[$key]) : $id($by_key[$key]);

    $contains = static fn ($needle) => is_array($actual)
        ? in_array($needle, $actual)
        : str_contains((string) $actual, (string) $needle);

    switch ($rule['operator'] ?? '==') {
        case '==':
            return is_array($actual) ? in_array($expected, $actual) : (string) $actual === (string) $expected;
        // `!==` is ACF's "not equal to" for a post, a page link, a
        // relationship, a term or a user: the same test as `!=`.
        case '!=':
        case '!==':
            return is_array($actual) ? !in_array($expected, $actual) : (string) $actual !== (string) $expected;
        case '==pattern':
            // A JavaScript regular expression, matched case-insensitively,
            // as ACF's form matches it. One PHP cannot compile passes, as an
            // unknown operator does.
            $match = @preg_match("\x01" . $expected . "\x01i", is_scalar($actual) ? (string) $actual : '');

            return $match !== 0;
        case '==empty':
            return is_absent($actual);
        case '!=empty':
            return !is_absent($actual);
        case '==contains':
            return $contains($expected);
        case '!=contains':
            return !$contains($expected);
        case '>':
            return is_numeric($actual) && $actual > $expected;
        case '<':
            return is_numeric($actual) && $actual < $expected;
        default:
            return true;
    }
}

/**
 * null, '' and [] are absent everywhere.
 *
 * `false` depends on the field: ACF returns it for an empty image, file or
 * relationship, where it means "nothing chosen" — but a `true_false` that is
 * off returns false too, and that is an answer rather than an absence.
 */
function is_absent($value, ?string $type = null): bool
{
    if ($value === null || $value === '' || (is_array($value) && $value === [])) {
        return true;
    }

    return $value === false && $type !== 'true_false';
}

/**
 * Drop absent values from an array a block's <slug>.php returned.
 *
 * The field filter above cannot see these — a loader builds its own array — but
 * the contract should read the same either way: a key that is present holds
 * something. A list stays a list, numbered again where an item was dropped: a
 * hole would turn it into an object for anything reading it as JSON.
 *
 * @param array<string,mixed> $data
 * @return array<string,mixed>
 */
function strip_absent(array $data): array
{
    $list = array_is_list($data);
    $out = [];

    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $value = strip_absent($value);
        }

        // No field type to consult here, so `false` is kept: a loader that
        // returns one means it (is_past, is_visible, show_form).
        if (is_absent($value, 'true_false')) {
            continue;
        }

        $out[$key] = $value;
    }

    return $list ? array_values($out) : $out;
}
