<?php

namespace WpGo\Blocks\Core;

/**
 * A block's inserter preview, as the media library has it: uploaded from the
 * Blocks screen's dialog (block-settings-screen.php), which sends the block's
 * name with the upload (PREVIEW_PARAM). WordPress handles the upload as any
 * other; these hooks rename it to wp-go-blocks.json's `preview_name` pattern,
 * title it, and mark it with PREVIEW_META, the block's name, which is how the
 * dialog's picker lists previews only and the save takes nothing else
 * (block-settings.php).
 */

/**
 * A block preview's file name, without its extension: wp-go-blocks.json's
 * `preview_name`, `{slug}` replaced by the block's folder name.
 */
function preview_filename(string $name): string
{
    $pattern = (string) Config::get('preview_name');

    return sanitize_file_name(str_replace('{slug}', basename(listed_blocks()[ $name ] ?? $name), $pattern));
}

/**
 * The block an upload is a preview of: the dialog's picker sends its name.
 * Only a listed block's, and only from someone who may edit the screen.
 */
function preview_upload_block(): ?string
{
    $name = sanitize_text_field((string) wp_unslash($_REQUEST[ PREVIEW_PARAM ] ?? ''));

    return $name !== '' && isset(listed_blocks()[ $name ]) && current_user_can('edit_theme_options') ? $name : null;
}

/**
 * A preview's file, renamed to the pattern before WordPress stores it (which
 * still adds -1, -2… to a name already taken). One that isn't a raster image
 * is turned away here, where the dialog's uploader shows why, rather than
 * stored and then left out of the picker.
 *
 * On `wp_handle_upload_prefilter`.
 *
 * @param array<string,mixed> $file
 * @return array<string,mixed>
 */
function rename_preview_upload($file)
{
    $name = preview_upload_block();

    if (!$name) {
        return $file;
    }

    // by its extension, as WordPress will type it: the browser's own word for
    // it can be anything
    if (!in_array(wp_check_filetype((string) $file['name'])['type'], PREVIEW_TYPES, true)) {
        $file['error'] = _x('A block preview is a JPEG, PNG, GIF, WebP, AVIF or HEIC image.', 'src/Core/preview-uploads.php', 'wp-go-blocks');

        return $file;
    }

    $extension    = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $file['name'] = preview_filename($name) . ($extension !== '' ? '.' . $extension : '');

    return $file;
}

/**
 * A preview's attachment, once created: marked with its block, and titled so
 * the media library says what it is.
 *
 * On `add_attachment`.
 */
function mark_preview_upload(int $attachment_id): void
{
    $name = preview_upload_block();

    if (!$name) {
        return;
    }

    $type = \WP_Block_Type_Registry::get_instance()->get_registered($name);

    update_post_meta($attachment_id, PREVIEW_META, $name);
    wp_update_post([
        'ID'         => $attachment_id,
        /* the block's name, as the editor shows it */
        'post_title' => sprintf(_x('Block preview: %s', 'src/Core/preview-uploads.php', 'wp-go-blocks'), $type->title ?? $name),
    ]);
}

/**
 * The dialog's picker lists previews only. WordPress keeps only the query keys
 * it knows, so the flag is read off the request.
 *
 * On `ajax_query_attachments_args`.
 *
 * @param array<string,mixed> $query
 * @return array<string,mixed>
 */
function only_previews($query)
{
    if (!empty($_REQUEST['query'][ PREVIEW_PARAM ])) {
        $query['meta_query']   = (array) ($query['meta_query'] ?? []);
        $query['meta_query'][] = ['key' => PREVIEW_META, 'compare' => 'EXISTS'];
    }

    return $query;
}
