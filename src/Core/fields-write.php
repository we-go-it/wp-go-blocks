<?php

namespace WpGo\Blocks\Core;

/**
 * Writing a block's data the way the editor stores it.
 *
 * The reverse of fields-read.php: from values by field name, the flat
 * `name => value`, `_name => key` array ACF reads a block from. block()
 * renders from it (template.php); a seeder can save it into post_content.
 *
 * The flat array is not built here. The values are turned into what the
 * editor's form posts — the same values by field key, nested as the fields
 * are (request()) — and ACF turns that into the block's data the way it does
 * when the editor saves a block: names, prefixes and pointers are all ACF's,
 * clones inside clones included, so they cannot drift from what the editor
 * writes.
 */

/**
 * A block's values, stored the way the editor stores them.
 *
 * A block's stored data is `['title' => 'x', '_title' => 'field_…_title']`: the
 * value and a pointer to the field that owns it. fields() reads a block by its
 * definitions and needs no pointer, but everything else reading the stored
 * data does: get_fields() and get_field_objects(), which find a block's fields
 * through them, and WP-Go Languages, which tells a text to translate from a
 * setting to copy by the field a pointer names. So they are the editor's, down
 * to the source key a cloned field points at.
 *
 * Values are given the way fields() hands them back: a clone or a group as its
 * array (`title: { text, tag }`), a repeater as a list of rows, flexible content
 * as rows with their `acf_fc_layout`. A clone at the top can also be given flat,
 * by the names its sub fields are stored under (`container_padding_top`). A
 * clone with no name at the top is given as fields() hands it back: its sub
 * fields beside the block's own (`image`, `alt`).
 *
 * A name the block has no field for is a typo in the caller: said, and left
 * out, as ACF could not read it back.
 *
 * @param array<string,mixed> $data
 * @return array<string,mixed>
 */
function field_data(string $slug, array $data): array
{
    $fields = block_fields('acf/' . $slug);

    // A top-level clone's sub fields given flat are its own: gathered into it
    // as if given as its group. A name that is a field of the block's own is
    // left to that field.
    $own = array_column($fields, 'name');

    foreach ($fields as $field) {
        if ($field['type'] !== 'clone') {
            continue;
        }

        foreach ((array) ($field['sub_fields'] ?? []) as $sub) {
            if (array_key_exists($sub['name'], $data) && !in_array($sub['name'], $own, true)) {
                $data[$field['name']] = (array) ($data[$field['name']] ?? []) + [$sub['name'] => $data[$sub['name']]];
                unset($data[$sub['name']]);
            }
        }
    }

    $request = request($fields, $data, $slug, '');

    // A nameless seamless clone's sub fields are the block's own to read
    // (block_fields()), but the editor posts them under the clone's key, at
    // their compound keys, and only there does ACF save them.
    $in_clone = array_column(array_filter($fields, static fn ($field) => !empty($field['_clone'])), '_clone', 'key');
    $nested = [];

    foreach ($request as $key => $value) {
        if (isset($in_clone[$key])) {
            $nested[$in_clone[$key]][$key] = $value;
        } else {
            $nested[$key] = $value;
        }
    }

    $request = $nested;

    if (!$request) {
        return [];
    }

    // What ACF does with the editor's form when a block is saved
    // (acf_parse_save_blocks_callback()): every field saved as usual, its
    // update_value() included, and what would have been written kept instead
    // (ACF_Local_Meta::capture()). The id is shaped like a block's,
    // `block_<one word>`, which ACF knows has no table: a null value is a
    // delete, and that goes nowhere. Any other underscore and ACF takes the id
    // for an option's name, and the delete for one of the site's options.
    $id = 'block_wpgoblocksfielddata';
    $stored = acf_setup_meta($request, $id);

    acf_reset_meta($id);

    return $stored;
}

/**
 * One level's values as the editor's form posts them: by field key, each nested
 * as its field is (request_value()).
 *
 * A value is found by any name it can be given under: the one fields() hands
 * it back under — a clone's sub field by its name in the source (`__name`,
 * `text` in `title`), any other by its own (`_name`) — or the one it is stored
 * under (`title_text`).
 *
 * @param list<array<string,mixed>> $fields   One level's fields: the block's,
 *                                            or a group's, clone's or row's sub fields.
 * @param array<string,mixed>       $values
 * @param string                    $path     Where the level is, for warnings: '' at the top.
 * @param bool                      $in_clone Whether they are a clone's sub fields.
 * @return array<string,mixed>
 */
function request(array $fields, array $values, string $slug, string $path, bool $in_clone = false): array
{
    $by_name = [];

    foreach ($fields as $field) {
        if (!holds_value($field)) {
            continue;
        }

        foreach ([$in_clone ? $field['__name'] ?? null : null, $field['_name'] ?? null, $field['name']] as $name) {
            if ($name !== null && $name !== '') {
                $by_name[$name] ??= $field;
            }
        }
    }

    $request = [];

    foreach ($values as $name => $value) {
        $field = $by_name[$name] ?? null;

        if (!$field) {
            trigger_error(
                $path === ''
                    ? "field_data(): `$slug` has no field `$name`"
                    : "field_data(): `$path` on `$slug` has no sub field `$name`",
                E_USER_WARNING
            );

            continue;
        }

        $at = $path === '' ? (string) $name : "$path.$name";

        // a text where rows or sub fields go: ACF would drop it quietly (or
        // warn of a foreach, for flexible content). Empty is empty, as stored
        if (!is_array($value) && $value !== null && $value !== '' && in_array($field['type'], ['group', 'clone', 'repeater', 'flexible_content'], true)) {
            trigger_error("field_data(): `$at` on `$slug` takes an array", E_USER_WARNING);

            continue;
        }

        $request[$field['key']] = request_value($field, $value, $slug, $at);
    }

    return $request;
}

/**
 * One field's value as the editor's form posts it:
 *
 *   - a **group** or a **clone**, given as its array: its sub fields' values,
 *     by key;
 *   - a **repeater**, given as a list of rows: each row's values, by key;
 *   - **flexible content**, given as rows with their `acf_fc_layout`: each
 *     row's values, by key, paired with that layout's sub fields. A row whose
 *     layout is not one of them could not be read back: said, and left out;
 *   - anything else as it is: ACF's own save turns it into what is stored (an
 *     image array into its ID, say).
 *
 * @param array<string,mixed> $field As ACF resolves it.
 * @return mixed
 */
function request_value(array $field, $value, string $slug, string $path)
{
    if (!is_array($value)) {
        return $value;
    }

    $subs = (array) ($field['sub_fields'] ?? []);

    switch ($field['type']) {
        case 'group':
        case 'clone':
            return request($subs, $value, $slug, $path, $field['type'] === 'clone');

        case 'repeater':
            $rows = [];

            foreach (array_values($value) as $i => $row) {
                $rows[] = request($subs, (array) $row, $slug, "$path.$i");
            }

            return $rows;

        case 'flexible_content':
            $layouts = array_column((array) ($field['layouts'] ?? []), 'sub_fields', 'name');
            $rows = [];

            foreach (array_values($value) as $i => $row) {
                $layout = is_array($row) ? (string) ($row['acf_fc_layout'] ?? '') : '';

                if (!isset($layouts[$layout])) {
                    trigger_error("field_data(): `$path` on `$slug` has no layout `$layout`", E_USER_WARNING);

                    continue;
                }

                unset($row['acf_fc_layout']);

                $rows[] = ['acf_fc_layout' => $layout] + request((array) $layouts[$layout], $row, $slug, "$path.$i");
            }

            return $rows;

        default:
            return $value;
    }
}
