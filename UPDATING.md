# Updating wp-go-blocks

What wp-go-blocks relies on that its dependencies don't promise to keep, and the versions it was last
checked against. After upgrading any of them, go through the list: most of these fail silently.

## Last checked against

<!-- #region versions -->
Checked on 2026-10-01.

| Dependency | Version |
|---|---|
| ACF Pro | 6.8.10 |
| Timber (for `TimberRenderer`, `TimberEntities`) | 2.5.1 |
| Twig (for `TimberRenderer`) | 3.29.0 |
| WordPress | 7.1.2 |
| PHP | 8.5.9 (requires 8.1) |
| Vite | 8.3.1 |
| rolldown (Vite's bundler) | 1.2.12 |
| Node (the Vite plugin) | 22.23.2 (requires 22) |
<!-- #endregion versions -->

## What it relies on

By part of the package, as the docs are: each part's Updating page shows its own list.

### Blocks

Setup, registration and the Blocks screen (`src/boot.php`, `src/Core/register.php`,
`block-settings.php`, `block-settings-screen.php`, `preview-uploads.php` and `assets/`).

<!-- #region blocks -->
- **Blocks register from their folder**, `register_block_type(<dir>)` on `init`.
  `block_type_metadata` gives them the default icon, and tells the theme's blocks apart by
  `$metadata['file']`, which WordPress resolves with `realpath()`. If that changes, the blocks
  lose the shared icon.
- **`boot()`'s warning for a boot too late for `init`** reads `$wp_filter['init']` as a `WP_Hook`
  and its `current_priority()`, and relies on a callback added to a running hook at a later
  priority still running (`WP_Hook` re-sorts its iterations), one at the running priority not.
  If that changes, the warning is wrong on `init` itself: given when the blocks do register, or
  not when they don't.
- **`block_categories_all`** puts the category first, moving it there when a core category has
  the same slug.
- **ACF reads `acf.postTypes` after `apply_settings()`**: it maps it to `post_types` on
  `block_type_metadata_settings` (at 99), which WordPress runs after `block_type_metadata`, where
  the screen's list replaces `block.json`'s. If ACF read `block.json` some other way, the screen's
  post types would do nothing. ACF's editor script then registers the block only in those post
  types, adding `wp_block` itself, which is why the screen doesn't offer patterns. An empty list
  means every post type to ACF, so "none ticked" is WordPress's `supports.inserter: false`
  instead.
- **The inserter's preview image is painted on WordPress's markup**: `assets/inserter-previews.js`
  looks for `.block-editor-inserter__preview-content-missing` (the "No preview available" panel)
  and reads the block's title from `.block-editor-block-card__title`, both inside
  `.block-editor-inserter__preview-container`. Hovering from one block to the next, React keeps
  that container and only sets the title's text node (`.block-editor-block-card__name`, a lone
  string), so text changes are watched inside the container, and the page only for it coming and
  going. If the editor renames those classes, the inserter goes back to "No preview available";
  if it moves the title out of the container, the image stays on the first block hovered.
  Nothing else breaks.
- **`wp_ajax_query_attachments` keeps only the query keys it knows**, but leaves
  `$_REQUEST['query']` as sent: `only_previews()` reads the picker's flag from there, on
  `ajax_query_attachments_args`. If WordPress cleans the request too, the picker lists every image
  and the save refuses those that aren't previews.
- **`wp.media` internals**, in `assets/blocks-screen.js`: `library.type` as a list of MIME types;
  a library queried with a key WordPress doesn't know no longer watching uploads, made to again with
  `frame.state('library').get('library').observe(wp.Uploader.queue)`; and the upload's extra field
  set in `frame.uploader.options.uploader.params` (before the frame's uploader is made) and with
  `frame.uploader.uploader.param()` (after). If those change, an upload from the dialog isn't
  renamed or marked: it isn't listed, and the save refuses it. Its `select` and `close` handlers
  are bound once, beside WordPress's own (`close` puts Thickbox back).
- **One upload runs `wp_handle_upload_prefilter`, then `add_attachment`, in one request** that
  carries the picker's field: the first renames the file (or refuses it, by setting `error`), the
  second marks and titles the attachment. Both read the block's name off `$_REQUEST`.
- **`wp_attachment_is_image()` says yes to an SVG** (by its `image/…` type), though WordPress
  knows no size for it, so the save checks the MIME type against `PREVIEW_TYPES` itself.
- **The screen's assets print inline on handles with no `src`** (`wp_register_style()` /
  `wp_register_script()` with `false`, then `wp_add_inline_style()` / `wp_add_inline_script()`),
  on `admin_enqueue_scripts` for the hook suffix `get_plugin_page_hookname()` gives the page.
- **The inserter is on the editor's own page, not in the canvas iframe**: `print_preview_images()`
  enqueues on `enqueue_block_editor_assets` and `assets/inserter-previews.js` watches that
  document's `body`. If the inserter moves into the canvas, the images stop showing; nothing else
  breaks.
- **The post types offered are `get_post_types(['show_in_rest' => true, 'show_ui' => true])` kept
  by `use_block_editor_for_post_type()`, without `attachment` and WordPress's own `wp_*`**
  (`editor_post_types()`). A new core post type without the `wp_` prefix would appear on the
  screen.
<!-- #endregion blocks -->

### Rendering

How a block renders and is drawn from a template, and the renderers (`src/Core/render.php`,
`template.php`, `src/Renderer/TimberRenderer.php`, `src/Entities/TimberEntities.php`). The Timber
bullets apply only with `TimberRenderer` and `TimberEntities`: with another renderer and other
entities, the versions above for Timber and Twig don't matter.

<!-- #region rendering -->
- **ACF calls the `renderCallback` (`WpGoBlocks::render_block`, a `Class::method` string it
  checks with `is_callable()`) as `(block, content, is_preview, post_id, wp_block, context)`**
  (`acf_render_block()`; `render_block()` reads the first four), after `acf_setup_meta()`, with
  `$block['path']` set to the block's folder (where its `block.json` is) and `$block['name']` the
  name it is registered under. `render_block()` takes the slug from that folder's name
  (`TimberRenderer` then makes the template's path theme-relative), and records the block by its
  name for the late asset slots. If ACF stops setting `path`, no block renders.
- **A `blockVersion: 3` block with `autoInlineEditing` on, in a preview, is rendered through
  `apply_inline_editing_attributes_to_render_callback()`** instead (`pro/blocks-auto-inline-editing.php`):
  ACF output-buffers the callback, then reparses its HTML with `DOMDocument` to mark the
  elements that print a field as editable. No block of the theme turns it on. One that does gets
  in its preview what `DOMDocument` makes of its markup; the site is unaffected.
- **ACF decides `is_preview` itself for a placed block**: `acf_render_block_callback()` sets it
  when `is_admin()` and the current screen is the block editor (`acf_is_block_editor()`): the
  editor's REST preload. A REST request (the editor's save response, the content API's
  `render_block()`) renders with it false. ACF's `fetch-block` passes true. If ACF changes how it
  tells the editor apart, previews render as on the site, or the site as a preview.
- **`wp_go_render_block()` draws through `acf_rendered_block()`** (`acf_rendered_block_v3()` for
  a `blockVersion: 3` block), with positional args `(attrs, content, is_preview, post_id)`, and
  passes an attribute ACF doesn't know (`wp_go_context`, which holds a token) through to `$block`
  untouched. If it is renamed, or the attribute is dropped, template-drawn blocks are empty or
  lose their `context`.
  - `is_preview` is always false there, even for a block drawn inside a preview: previewing, ACF
    renders the block's form as well, and leaves `<InnerBlocks />` in place of the `content`
    (it replaces it only when not previewing, or inside `acf_block_render_template`).
    `render_block()` gets the preview from `block()` instead. If ACF's rule changes, so may
    this.
  - The attributes carry an `id`, which ACF keeps as given (`acf_get_block_id()` hashes the
    attributes only when there is none). `block()` makes it with `acf_get_block_id()` itself,
    from the attributes with the context in place of the token: the id ACF would have made. If
    ACF stops keeping a given id, a drawn block's id changes on every render. Nothing in the theme
    reads it, but `PayloadRenderer`'s `block.id` would.
  - `render_block()` sets `drawn` on the block array it was handed, for a block `block()` drew,
    and nothing else: ACF has no key of that name. If ACF adds one, a placed block's preview could
    read as drawn, and WP-Go's data modes would leave it as markup instead of replacing it.
- **ACF hands the render callback the post** as `$post_id`: on the site the page's post, and in
  the editor (an AJAX preview, or the preload when the editor opens) the post being edited (or the block context's `postId`, in a Query Loop).
  [Variants](/wp-go-blocks/rendering/usage#variants) are picked by that post's
  type, and their loaders read it. If ACF stops passing it in previews, `render_block()` falls back
  to `get_the_ID()`. ACF's AJAX preview (`acf_ajax_fetch_block()`) sets up the global `$post` as
  the post being edited, so that would still find it; a preview with no global post (a widget,
  the site editor) would render without its post type's variant. The site is unaffected.
- **`WP_Block_Type_Registry` tells `wp_go_render_block()` whether a block exists.**
- **`Timber::context()`** for every block's base context, **`Timber::compile()`** for its
  template, **`Timber::render()`** for `render_page()`, and **`Timber::get_post()`, `get_posts()`,
  `get_menu()`** for the entities' posts and menus, their arguments passed through as given.
- **Timber adds the calling script's folder to its locations** (`get_calling_script_dir()`). Called
  from `TimberRenderer`, that's the package's `src/Renderer/`, not the theme template. Pages and blocks
  still find their templates because the theme and its `views/` are Timber's locations anyway.
  A template found only relative to the calling PHP file would stop being found.
- **A block's template path is made theme-relative** and resolved by Timber's loader, so the theme
  must be one of its locations. Whether the file is there is checked first, on the absolute path
  (a missing one is a `notice()`, where `Timber::compile()` would return nothing).
- **`Timber::get_posts()` returns a collection for a query, but one `Timber\Post` for an ID**
  (`$id`, `['ID' => $id]`, `PostFactory::from()`) **and `null` for what it can't query**.
  `TimberEntities::get_posts()` puts the post in a list and makes `null` `[]`, keeping to its
  `iterable`. If Timber returns another shape, it passes through as is when iterable, else in a
  list.
- **`wp_go_render_block` is a Twig function on `timber/twig/functions`**, in Timber 2's
  `['callable' => …, 'options' => ['is_safe' => ['html']]]` form, registered when `boot()` runs,
  before Timber builds its first Twig environment.
- **`TimberRenderer::body()` (PageBody)** picks the view with `Timber\Loader::choose_template()`,
  gets Twig through `$loader->get_twig()`, filters the context on `timber/render/data` as
  `Timber::render()` does, and renders one Twig block with `hasBlock()` / `renderBlock()`. If any
  of these change, the payload renderer loses the blocks a view draws, and falls back to the PHP
  template's calls, with no error.
<!-- #endregion rendering -->

### Fields

A block's fields read into its context, and values written in the editor's shape
(`src/Core/fields-read.php`, `fields-write.php`).

<!-- #region fields -->
- **The context is read from the block's field definitions** (`acf_get_block_fields()`, by the
  block's name), each value with `acf_get_value()`, which falls back to the field's
  `default_value` when the block's data doesn't have it, and `acf_format_value()`. A seamless
  clone comes spread into its sub fields, each with `_clone` (the clone's key), and is read as the
  clone (`acf_get_field()`): if ACF stops marking them, `container` and the like arrive flattened
  (`container_padding_top`…). A clone with no `name` is not: its sub fields stay the block's own,
  and `field_data()` posts them under the clone's key, where ACF's form puts them and the only
  place ACF's save reads them from. Each object's `key`, `__key`, `name`, `_name`, `__name`, `_clone`,
  `type`, `value`, `sub_fields`, `layouts` (each layout's `name` and `sub_fields`) and
  `conditional_logic` are read; the theme's filters read a clone's `clone`. The values are read
  for `acf_get_valid_post_id()`, which ACF's local meta answers with the block's id while
  `acf_render_block()` has its data set up as the main meta (`acf_setup_meta(…, true)`): if that
  changes, every block reads the current post's meta instead.
  - **Conditional logic is evaluated again here**, with ACF 6.8's operators: `==`, `!=`, `!==`
    (its "not equal" for a post, page link, relationship, term or user), `==empty`, `!=empty`,
    `==contains`, `!=contains`, `==pattern` (a JavaScript regular expression, matched ignoring
    case) and `>`, `<`. An operator it doesn't know passes, so one ACF adds leaves fields
    visible that it would hide. A post, a term or a user is compared by its ID (`ID`,
    `term_id`), one or a list.
  - **A seamless clone's sub fields are keyed `<clone key>_<source key>`, and keep the source key
    in `__key`**, while their rules name the source's keys: a rule is matched against the
    compound key first, then `__key`. If ACF keys them otherwise, conditions inside seamless
    clones (the container's `gradient`) stop hiding anything.
  - **The value's keys**: a group and a repeater or flexible content row key their sub values by
    `_name`, a clone by its sub fields' `__name` (the source's name, prefixed or not, at any
    depth: `class-acf-field-clone.php`'s `format_value()`). `hydrate()` looks each value up by
    that, then by `name`; keyed by anything else, a clone's values arrive empty.
- **The stored block-data shape is ACF's own** (`fields-write.php`): `field_data()` builds what
  the editor's form posts (values by field key, nested as the fields are, a seamless clone's
  under the clone's key by its sub fields' compound keys, as the form names its inputs) and has
  ACF store it with `acf_setup_meta()`, as `acf_parse_save_blocks_callback()` does when a block
  is saved. That relies on `ACF_Local_Meta` capturing a request (its first key a field key,
  `acf_is_field_key()`) through `acf_update_values()` and `acf/pre_update_metadata` instead of
  writing it, and on a `block_<one word>` id decoding as a block (`acf_decode_post_id()`), which
  has no meta table: a null value is a delete there, and an id ACF took for an option's name
  would delete one of the site's options. ACF's pointers matter to `get_fields()`, the editor and
  WP-Go Languages, not to `fields()`, which reads by the definitions.
- **`acf_get_field()` / `acf_get_fields()` return clones resolved**: names prefixed, nesting
  included, the source's name and key kept in `__name` and `__key`. `acf_get_field_group()` finds
  the group by key.
<!-- #endregion fields -->

### Assets

A block's CSS and JS, on the pages that render it, and the Vite plugin (`src/Core/assets.php`,
`vite/index.js`).

<!-- #region assets -->
- **A page's blocks are found by parsing**: `parse_blocks()` on the singular post's content
  (`get_queried_object()`), walking `blockName` and `innerBlocks`; on a list, only with `buffer`
  off, on each of `$GLOBALS['wp_query']->posts`. Everything else comes from what renders:
  `render_block()` records each block, and the late slots below carry them.
- **The order in the head**: block scripts on `wp_head` at 15 and styles at 25, either side of the
  theme's main bundle at 20. Module scripts run in document order, which is what runs a block's
  `Alpine.data()` before the main bundle's `Alpine.start()`.
- **A whole-response buffer** (`ob_start()` with a callback) on `template_redirect` at 0, for
  every response that gets there: one without the markers (a feed, robots.txt) comes back as it
  went in, and the REST API answers before `template_redirect`. The late tags replace the first
  `<!--wg-block-js-->` / `<!--wg-block-css-->` before `</head>`. A plugin that strips comments,
  or closes the buffer early, leaves template-drawn blocks unstyled and their Alpine components
  unregistered. The buffer isn't flushable (`PHP_OUTPUT_HANDLER_STDFLAGS ^
  PHP_OUTPUT_HANDLER_FLUSHABLE`, as WordPress's own template enhancement buffer): an `ob_flush()`
  during the page fails with a notice instead of filling the slots from the head alone. A buffer
  opened after ours (WordPress's template enhancement buffer, from 6.9, when something filters
  `wp_template_enhancement_output_buffer`) sees the markers unfilled.
- **With `buffer` off, the late stylesheets go on `wp_footer`**, hooked from the head's own
  print. A theme that doesn't call `wp_footer()` gets none.
- **The inputs go in `build.rolldownOptions.input`**, from the plugin's `config()` hook: Vite 8's
  name for it, `rollupOptions` before. If Vite renames it again, no block entry is built: in
  production no page gets block CSS or JS.
- **The virtual modules** (`resolveId` returning `\0…`, `load`) are `import.meta.glob` calls with
  root-relative patterns and `eager: true`, or lists of root-relative `import`s. Globs take
  literal paths only, which is why they are generated.
- **`virtual:wp-go-blocks/styles` and most of `/editor` are lists of root-relative `import`s**,
  not globs: a glob can't ask for the folder's own name. `styles`' CSS reaches the main CSS
  file because the main entry imports it: Vite gathers an entry's imported CSS into one file, in
  import order. In dev, a `_<slug>.scss` or a block's own `<slug>.scss` / `<slug>.js` added or
  removed invalidates them through `server.watcher` and `server.moduleGraph`, Vite's mixed module
  graph, kept for compatibility beside the per-environment ones. When it goes, a file added or
  removed needs a restart of Vite.
- **A block's `.scss`, a `.php` and a `.twig` reload the page through `handleHotUpdate`** and
  `server.ws.send({ type: 'full-reload' })`. Its `file` comes normalised (`normalizePath`,
  forward slashes), so the blocks folder is compared normalised too. Vite prefers the per-environment `hotUpdate` hook
  now. When `handleHotUpdate` goes, an edit to any of them needs a manual reload.
- **The manifest is read once, after a build**, only for the report of small stylesheets
  (`closeBundle`): `<slug>.scss` keys, their `file`, at `build.manifest`'s path or
  `.vite/manifest.json`. If its shape changes, the report is empty; nothing else depends on it.
  Where a file was built is otherwise the theme's `AssetResolver`'s answer, so the manifest's
  shape is the theme's concern.
- **Entry globs are expanded with Node's `fs.globSync`** (Node 22), once, as Vite starts
  (`entryFiles()`): on an older Node the plugin throws when Vite loads the config, and a new file
  matching a glob needs a restart. Plain paths don't need it.
<!-- #endregion assets -->

### Rendering as data

`PayloadRenderer` and the content API, with `content_api` on (`src/Renderer/PayloadRenderer.php`,
`src/Renderer/Payload/`, `src/Core/ContentApi.php`, `I18n.php`).

<!-- #region rendering-as-data -->
- **The page's template runs inside a REST request**, with the main query, `$post` and the
  renderer swapped and put back. A template that relies on something only a front-end request
  sets up (`template_redirect`'s work, a redirect) behaves differently there.
- **The template is picked as `wp-includes/template-loader.php` picks it**: `ContentApi::template()`
  copies its order for the conditions a post can meet (`is_front_page`, `is_home`,
  `is_privacy_policy`, `is_attachment`, `is_single`, `is_page`, `is_singular`, then
  `get_index_template()`). Compare the list when WordPress changes that file.
- **The languages plugin, through `Core\I18n`, WPML checked first** (`ICL_SITEPRESS_VERSION`), as
  WP-Go Languages stands down while WPML is active.
- **WP-Go Languages, through its facade** (`WpGoLanguages`, the class's presence): `is_language`,
  `switch` and `restore` around the request, `current` and `home_url` for a language's home,
  `languages` (`code`, `name`, `locale`), `language_of` for the preview's language, `translations`
  for `translations`. The front page relies on it filtering `page_on_front` to the current
  language's translation, and a lookup by slug on it keeping `get_posts()` to the current language
  (`suppress_filters` off), a page's path too: `ContentApi::by_path()` queries the path's last
  part (`post_name__in`) and compares `get_page_uri()`, as `pagename` loses the posts page's path
  and `get_page_by_path()` doesn't know languages.
- **WPML, through its documented hooks:** `wpml_active_languages` for the languages (and which
  `lang` is one), `wpml_switch_language` and `wpml_current_language` around the request,
  `wpml_element_language_code` for the preview's language, `wpml_element_trid` and
  `wpml_get_element_translations` for `translations`, `wpml_permalink` and `wpml_home_url` for
  their URLs. A lookup by slug relies on WPML filtering `get_posts()` by the current language
  (`suppress_filters` off), and the front page on it translating `page_on_front`.
- **A menu item's URL is rebuilt from its object** (`get_permalink()`, `get_term_link()`,
  `get_post_type_archive_link()`), because WPML points the item at the translation (`object_id`)
  but leaves its stored `url` alone. Checked on the EN site: `About us` → `/en/about-us/`.
- **The content's HTML is finished by hand** (`ContentBlocks::html()`): each block is rendered
  alone, so `the_content` never runs on it, and its steps that still apply are repeated:
  `do_shortcode()`, then `wp_filter_content_tags()`, plus `wpautop()` and `shortcode_unautop()`
  for a post with no blocks (`has_blocks()`). A step WordPress adds to `the_content` is missing
  from the content's HTML until it's added there.
- **The content is read in `parse_blocks()`'s shape** (`blockName`, `attrs`, `innerBlocks`,
  `innerHTML`, `innerContent`). An ACF block is rendered alone by emptying its `innerBlocks`,
  `innerContent` and `innerHTML` before WordPress's `render_block()`; a synced pattern is
  `core/block` with `attrs.ref`, a `wp_block` post, followed only when published without a
  password, as WordPress renders it. If WordPress renders an ACF block's inner blocks another way,
  they show up twice or not at all.
- **The content's place in a view is found through `the_content`**: while the body renders, a
  filter at `PHP_INT_MIN` returns a marker for the page's post, told by `get_the_ID()` and by the
  content being that post's own (`is_content_of()`: its `post_content`, a part of it, or
  `get_the_content()`), and one at `PHP_INT_MAX` makes it the marker again after `wpautop()`.
  That relies on Timber's `post.content` applying `the_content` to the post's raw `post_content`
  (or a part of it) with, in a loop, that post set up as the global post. If either stops, a page
  with a body has no content blocks at all, and no `content_at`.
- **The posts page is queried as WordPress queries it** (`ContentApi::is_posts_page()`: by
  `page_id` alone, so `WP_Query::parse_query()` makes it `is_home()` and lists the blog's
  posts). Check it when WordPress changes how it treats `page_for_posts`.
- **`collecting()` tells a REST request with `wp_is_serving_rest_request()`** (falling back to
  `REST_REQUEST`) and an AJAX one with `wp_doing_ajax()`. A request they miss gets a marker,
  `<!--wg-drawn:0-->`, where it expected a block's JSON: the editor's previews would show it.
- **Timber's classes are recognised by name** in `Normalizer::normalize()`: `Timber\Post`,
  `Timber\Term`, `Timber\User`, `Timber\Menu` (by its `term_id`, checked before `Stringable`, as
  its string is its markup). Renamed, they come out as `{ class }`.
- **A menu item's properties** as `wp_setup_nav_menu_item()` sets them: `menu_item_parent`,
  `menu_order`, `type` (`post_type`, `taxonomy`, `post_type_archive`, `custom`), `object`,
  `object_id`, `url`, `target`, `xfn`, `description`, `classes`, `title`. One renamed empties
  that key in every menu.
<!-- #endregion rendering-as-data -->
