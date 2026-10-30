# wp-go-blocks

ACF `block.json` blocks, rendered with the template engine the theme names (Timber, say): one folder per
block, CSS and JS loaded only where a block renders, per-post-type variants, and blocks a template
can draw itself. Composer for the PHP
(`we-go/wp-go-blocks`, namespace `WpGo\Blocks`), a Vite plugin for the build
(`@we-go/wp-go-blocks/vite`). Both read the theme's `wp-go-blocks.json`.

Requires PHP 8.1, ACF Pro 6 and Vite 8, plus Timber 2 for `TimberRenderer` and `TimberEntities`.

Its version is in `composer.json` and `package.json`, kept the same: bump both, then
`composer update we-go/wp-go-blocks`.

## Setup

**`wp-go-blocks.json`**, at the theme root. Every path in it is relative to the theme:

```json
{
  "category": "mysite",
  "blocks": "blocks",
  "entries": { "main": "src/main.js", "editor": "src/editor.js" },
  "bundles": ["form/form"],
  "icon": "static/icons/block.svg"
}
```

| Key | |
|---|---|
| `category` | the theme's block category, first in the inserter, which a `block.json` names (`"category": "mysite"`). Without it none is added; one that is already there (WordPress's `text`, say) is moved first, its title kept unless `category_title` is set |
| `blocks` | the blocks folder (default `blocks`) |
| `entries` | the theme's main and editor entries, added to the Vite inputs. A value can be a glob (`src/editor.*.js`, one entry per file), read when Vite starts; `main` stays one file, as the PHP reads it |
| `bundles` | entry bases that are not blocks: `<base>.js` / `<base>.scss` are built, and loaded once `declare_bundle()` asks. A base not listed is refused there, with a warning, in dev too: it would never be built |
| `icon` | an SVG every block gets unless its `block.json` sets one |
| `category_title` | the category's label (default: the category, capitalised) |
| `preview_name` | the file name a block's inserter preview is uploaded under, `{slug}` the block's folder (default `block-preview-{slug}`): [the Blocks screen](#the-blocks-screen) |

**PHP**, in the theme's `functions.php`:

```php
\WpGo\Blocks\boot(__DIR__ . '/wp-go-blocks.json', [
    'renderer' => new \WpGo\Blocks\Renderer\TimberRenderer(),  // required: what blocks render with
    'entities' => new \WpGo\Blocks\Entities\TimberEntities(),  // required: what posts and menus are fetched with
    'assets'   => new MyAssetResolver(),   // optional: without one, no per-block CSS/JS
    // 'head_priorities' => ['scripts' => 15, 'styles' => 25],
    // 'buffer'          => true,        // fill the late slots (blocks drawn after wp_head)
]);
```

The theme is the folder `wp-go-blocks.json` is in. Block templates render from there; with
`TimberRenderer`, it must be one of Timber's locations. A blocks folder that isn't there, like a
`wp-go-blocks.json` that can't be read or parsed, gets a warning, and so does a `boot()` after
`init`, which registers the blocks (at 10): call it as the theme loads, from `functions.php`. The Blocks screen's strings are
for the theme to register for translation ([its strings](#the-blocks-screen)). **`renderer` and `entities` have no
default:** without either, `boot()` throws an `InvalidArgumentException`, and leaves the package
unbooted.

**Vite**, in `vite.config.js` (the theme is the Vite root):

```js
import wpGoBlocks from '@we-go/wp-go-blocks/vite'

export default defineConfig({
  root: themePath,
  plugins: [ wpGoBlocks({ themePath }) ]
})
```

The plugin adds the inputs (main, editor, every block's `<slug>.scss` / `<slug>.js`, every
bundle). A block is a folder in the blocks folder with a `block.json`, as the PHP registers: any
other folder there is skipped. It also serves two virtual modules for the theme's own entries:

- `virtual:wp-go-blocks/shared`: every `_*.js` under the blocks folder, as an
  `import.meta.glob` map. The main entry imports it, so those files are in the main bundle on
  every page.
- `virtual:wp-go-blocks/styles`: every block's `_<slug>.scss`, beside its `block.json`. The main
  entry imports it after its own stylesheet, so a small block's styles are in the main CSS and
  cost no request of their own. Only that name, there: any other `_*.scss` is a partial the
  block's stylesheet `@use`s.
- `virtual:wp-go-blocks/editor`: every block's own `<slug>.scss` / `<slug>.js`, every `_*.js`
  under the blocks folder and every bundle: what the front end loads, not every file under the
  folder, so a partial isn't compiled on its own. The editor entry imports it, because any block
  can be inserted there. In a build, not the `_<slug>.scss`, which the canvas has in the main
  CSS; in dev, they are in, as the canvas's main CSS is then the main stylesheet alone.

In dev, a change to a block's stylesheet, a `.php` or a `.twig` file reloads the page: none of
them is a module the page imports.

## Asset resolver

The package decides which source files a page needs. The theme says where they were built:

```php
interface AssetResolver {   // WpGo\Blocks\Assets\AssetResolver
    /** @return array{css: string[], js: string[], preload: string[]} */
    public function urls(string $source): array;   // 'blocks/x/x.js', 'blocks/x/x.scss', 'form/form.js'
}
```

`urls()` is called only for a file that exists, and once per file per request:
- `js` is printed as module scripts before the main bundle;
- `css` after the main stylesheet: every stylesheet the file needs, a script's included, and
  those of the chunks it imports (Vite lists them on the chunk, not on the entry). Leave out what
  the main stylesheet already carries;
- `preload` as `modulepreload`. Leave out what the main bundle already preloads.

What reaches the head: on a singular page, the blocks parsed out of its content (unless
`wp_go_blocks/parse_content` says the page doesn't render it), with any bundle declared by then;
then, through the late slots, every block that rendered after `wp_head` and every bundle declared
after it. A list (an archive, a search) parses nothing: its template decides what it draws, and
the slots carry exactly that. With `buffer` off there are no slots, so a list parses each of its
posts, and what renders after `wp_head` gets only its CSS, at the foot of the page
(`wp_footer`): its JS would run after the main bundle starts Alpine, too late for its
`Alpine.data()`, so it is left out.

## Renderer

The package decides which template a block renders and with what context. A **renderer** renders
it. The theme names it, as `boot()`'s required `renderer`. The package ships `TimberRenderer`
(Twig, through Timber) and `PayloadRenderer` (JSON), in `src/Renderer/` under the
`WpGo\Blocks\Renderer` namespace; another template engine is a class of its own:

```php
interface Renderer {
    public function extension(): string;                       // 'twig', 'blade.php', 'latte'
    public function globals(): array;                          // under every block's context, or []
    public function render(string $file, array $context): string;  // $file is absolute
    public function output(mixed ...$args): void;              // a page, with the engine's own arguments
    public function expose(string $name, callable $fn): void;  // `wp_go_render_block` in templates, unescaped
}
```

A renderer only renders. **Posts and menus come from the entities** (below), which a renderer
reads too when it needs them (`WpGoBlocks::entities()`).

| | `TimberRenderer` |
|---|---|
| `extension()` | `twig` |
| `globals()` | `Timber::context()`: `site`, `theme`, `user`, `post`… |
| `render()` | `Timber::compile()`, on the path made relative to the theme, which must be one of Timber's locations. A file that isn't there is a `notice()` (shown with `WP_DEBUG`), where Timber would render nothing |
| `output()` | `Timber::render()`, with whatever arguments it was given |
| `expose()` | a Twig function on `timber/twig/functions`, marked `is_safe: html` |

That's all of the package's Timber. Everything else (registration, the fields, variants,
loaders, `wp_go_context`, the assets) is the same whatever renders. The theme's templates are what
change: a block's `<slug>.<extension>`, and its variants' `<slug>.<post type>.<extension>`, are
written for that engine. The extension can't be plain `php`: `<slug>.php` is the block's loader.

**Pages too, through `WpGoBlocks`.** A theme's PHP calls the package the way it would call
Timber: a static facade, aliased to the global `WpGoBlocks` when the package loads, so a template
needs no `use`. Each method goes through the renderer or the entities, so the templates don't
name the engine either:

| `WpGoBlocks::` | | instead of |
|---|---|---|
| `context($extra = [])` | the renderer's `globals()`, `$extra` merged over it | `Timber::context()` |
| `render(...$args)` | print a page: the arguments go to the renderer's `output()` as given, however many | `Timber::render()` |
| `compile($template, $context)` | a template to a string. Theme-relative, **without** extension: the renderer's is added | `Timber::compile()` |
| `get_post(...)`, `get_posts(...)`, `get_menu(...)` | a post, posts, a menu, from the entities | `Timber::get_post()`, `get_posts()`, `get_menu()` |
| `block($slug, $data, $options)` | a block drawn from PHP, as `wp_go_render_block` is in a template | |
| `content()` | the post's content where a template puts it (a block's `content`): its HTML, or as data the place of its blocks | `the_content()` |
| `renderer()` | the renderer in use, or `null` before `boot()` | |
| `entities()` | the entities in use, or `null` before `boot()` | |

```php
// single-event.php
$context = WpGoBlocks::context();
$context['post'] = WpGoBlocks::get_post();
WpGoBlocks::render(['single-event.twig', 'base.twig'], $context);   // Timber::render()'s arguments

// a REST listing's rows: blocks/event-grid/event-grid-items.twig
$html = WpGoBlocks::compile('blocks/event-grid/event-grid-items', ['items' => $items]);
```

With that, a theme's PHP names Timber only to set it up (`Timber::init()`, given to `boot()` as `init`, and its `$dirname`). Code that
reads a post should use what every engine's post has (`$post->ID`, `get_the_title($post->ID)`)
rather than one engine's methods (`$post->title()`), or it works with one kind of entities only.

**`boot()` and `WpGoBlocks` are the whole entry point**, with the interfaces a theme implements
(`Renderer`, `Entities`, `AssetResolver`). What is under `WpGo\Blocks\Core` (`src/Core/`: `Config`,
`ContentApi`, the functions behind the facade) is the package's own; a theme or a plugin doesn't
call it.

### `PayloadRenderer` (an experiment)

A renderer that renders **data, not markup**: what a block would hand a front end that renders it
itself. Each block comes out as the JSON of its context, and a page as its context plus its
content's blocks.

- **Its templates are `<slug>.payload.php`** (variants: `<slug>.<post type>.payload.php`), PHP files
  with `$context` in scope that return the block's payload as an array. A block without one gives
  its whole context. A theme's Form block can have one: it swaps the CF7 object the block
  stores for the whole form as data (`wp_go_form_data()`, WP-Go's cf7-acf-form feature's).
- **`globals()` is `[]`**, so a payload holds only the block's own values: its fields, a template's
  `context`, its loaders' (the variants' too). Posts are whatever the entities fetch. ACF's
  `block` is cut down to `name`, `id`, `anchor`,
  `align` and `className`.
- **Everything is made plain data** (`PayloadRenderer::normalize()`): a post becomes
  `{ id, type, title, url }` (a `WP_Post` or a `Timber\Post`), a term `{ id, taxonomy, name, slug }`,
  a user `{ id, name, url }` (its display name and author page), a `Timber\Menu` the menu below, a
  date ISO 8601, Twig markup its string, a `JsonSerializable` its data and a `stdClass` its
  properties. Any other object is `{ class }` alone: what it holds isn't known to be for a page (a
  `WP_User`'s password hash, a `WP_Query`'s SQL), so a payload that wants something of it picks
  it out. Arrays are walked whole; objects inside objects stop six deep.
- **An image** is `PayloadRenderer::image($id)`, from WordPress alone:

  ```json
  { "id": 375, "url": "…/811-1920x1080-1.jpg", "width": 1920, "height": 1080, "alt": "", "mime": "image/jpeg",
    "srcset": "…-1920x1080-1.jpg 1920w, …-300x169.jpg 300w, …-480x270.jpg 480w, …" }
  ```

  It goes through `wp_go_blocks/payload_image` (`$image, $id`) for what the theme knows of its images
  and WordPress doesn't. A theme can add, say, `lqip`, a blurred placeholder as a data URI,
  the same one its HTML uses, or `null`.

  `srcset` is WordPress's own: every size made with the image's proportions. A hard-cropped size
  (`thumbnail`) isn't in it; the theme's are all proportional. The formats are the uploaded ones:
  converting to WebP/AVIF is the front end's
  (Nuxt Image), as the theme's own copies are only made when Twig renders a size. `normalize()`
  turns any image attachment it meets into this, without its `null` keys, as in any payload (no
  `srcset` for an image with no sizes, no `lqip` for a theme's `null`). Image **IDs** (an ACF image field) are only
  numbers, so it can't tell them apart: the theme expands them where it gives image groups their
  shape, when the blocks render as data (in the theme's own code). Its media groups' `image` / `image_mobile`, and `wg_image()`'s
  `{ id, alt, mobile }`, then go out as images, with the authored `alt`.
- **A menu** (`PayloadRenderer::menu()`, by location, ID, slug or name) is a tree:

  ```json
  { "id": 119, "name": "Main", "slug": "main", "location": "main",
    "items": [ { "id": 352, "title": "Resources", "kind": "container", "classes": [],
                 "fields": { "layout": "images" },
                 "children": [ { "id": 354, "title": "Events", "kind": "post",
                                 "object": { "type": "page", "id": 348 },
                                 "url": "…/resources/events/", "classes": [], "children": [] } ] } ] }
  ```

  Items are nested by parent and in menu order. `kind` is `post`, `term`, `archive`, `custom`, or
  the item's own type otherwise, which a theme names on the filter below (the base theme makes
  its container item, `wg_nav_container`, `container`), and `object` is what it links to. `classes` holds what an editor typed, and `fields` the item's ACF
  fields (a container item's `layout`). A menu is the same on every page: which item is the current
  page is the front end's to tell, from its route. **`wp_go_blocks/payload_menu_item`** filters each
  item, for what a theme's own items hold that WordPress doesn't (a theme can fill a
  container item with the posts it lists), or what its own item type is called.
- **A page** (`WpGoBlocks::render()`) is `{ template, context, blocks, content_at }`. `template`
  is the first template the theme names for it (`page-about-us.twig`), whether that one exists
  or not. `blocks` is the page's body as a tree: an ACF block's payload, inner blocks under
  `inner_blocks`, a core block's HTML, and `registered: false` for a block that no longer exists.
  A core block with ACF blocks inside, at any depth (a group around a hero, a synced pattern
  holding one), is `{ name, attrs, inner_blocks }` instead, walked the same way, a synced
  pattern's inner blocks its pattern's; classic content, and the HTML between blocks, is
  `core/freeform` with its `html`. HTML is as `the_content` finishes it: shortcodes run, images
  given their `srcset`, and a classic post's paragraphs. A post behind a password has no content
  blocks unless its password was given or the content is the editor's (`use_content()`). Given the site's
  renderer (`new PayloadRenderer($site)`, as the content API does) and one that can render a
  page's body (`PageBody`: `TimberRenderer` renders the view's `{% block content %}`), the blocks
  the view draws itself (`wp_go_render_block()`) are in it too, where it draws them, marked
  `template: true`, a drawn block's `content` option its `inner_blocks`; the content's blocks are
  where the view prints the content (the page's post's `the_content`: a list of other posts keeps
  theirs), the first place if it prints it twice, and `content_at` says where that is among the
  blocks of its level, on the page or on the drawn block holding them (absent when the view
  doesn't print it, a fixed layout). The page's content is told from another post's by both the
  global post and the content itself (Timber's `post.content` outside a loop doesn't set its post
  up), and is its marker whatever `the_content`'s later filters wrap it in. The view's markup
  itself is thrown away. **Without a view** (no Twig: the
  payload renderer is the site's), they are the blocks the page's PHP template draws with
  `WpGoBlocks::block()`, in the order it calls it, one given as another's `content` inside it;
  the content goes where `WpGoBlocks::content()` is given as a block's `content`, else after
  them. With no call, `blocks` is the content's. A page's `wp_go_render_block()` calls are collected while
  it is built (`collecting()`): inside `capture()`, while the body renders, and, as the site's
  renderer, on a page request from its template until it is printed. Anywhere else (a REST or
  ajax request: the editor's block previews) `wp_go_render_block()` returns the block's payload as JSON,
  unless another block is rendering.
- **A block drawn for a block**, by its loader or its `.payload.php` while it renders, or by the
  page and handed to it in its `context` option, is in that block's payload, not in the page's
  `blocks`: where the value was, its node, `{ name, payload, template: true }` (several drawn
  blocks and nothing else: a list of them), its `content` option its `inner_blocks`, the blocks
  the same loader drew into it too. Page or not, the editor's previews too. A drawn block
  inside markup the loader composed stays that markup, the block in it as its JSON. The post's
  content isn't placed in a payload: a value that is it (`WpGoBlocks::content()`, `post.content`)
  is left out.
- **No nulls**: a value that would be null is left out of an object (`compact()`; a list keeps
  its entries), and a string without markup is text, its HTML entities decoded (`text()`:
  `HR &#038; payroll` is `HR & payroll`). Rich text, with its tags, is left as HTML.

Inside `capture()`, `output()` prints nothing: it keeps the page for `capture()` to hand back.
The content API swaps it in that way for its request (below), and a theme can for anything else
that wants blocks as data (the editor's block previews, say). As the site's own renderer (`boot()`'s `renderer`), it prints
the page, as JSON.

`PayloadRenderer` assembles the page; the rest is in `src/Renderer/Payload/`: the content's blocks
(`ContentBlocks`), menus (`Menus`) and plain data (`Normalizer`). They are the package's own: a
theme calls `PayloadRenderer`'s statics (`normalize()`, `compact()`, `text()`, `image()`,
`menu()`, `menus()`), which hand over to them.

### The content API

With `boot()`'s `content_api` on (off by default), a published page answers as data over REST:

```
GET /wp-json/wp-go-blocks/v1/content?slug=resources/events&lang=en
```

| Parameter | |
|---|---|
| `slug` | a page's path (`resources/events`, the posts page's too), empty for the front page (with a static one: Settings → Reading), a hierarchical post type's path, or another public post type's slug (no `/` in it) |
| `lang` | the language, with a languages plugin, WP-Go Languages or WPML (`Core\I18n`, WPML first), as it codes it (`en`, `de`): the lookup and the strings are that language's |
| `type` | a post type, when a slug is ambiguous |

The page renders **as the site renders it**: the PHP template WordPress would pick runs
(`template-loader.php`'s order: `front-page.php`, `home.php` for the posts page, the page's or the
single post's, `singular.php`, `index.php`; then `template_include`), through `load_template()`,
with the post as the main query (a page by `page_id`, so it is a page to the conditional tags;
the posts page as the site queries it, the blog's posts), but with a
`PayloadRenderer` for that request only (`WpGoBlocks::use_renderer()`), which hands the page back
(`capture()`). So the answer is the page as above, plus the post:

```json
{ "post": { "id": 338, "type": "page", "title": "About us", "url": "…/about-us/" },
  "lang": "en",
  "translations": { "en": { "id": 338, "slug": "about-us", "url": "…" }, "de": { "id": 385, "slug": "ueber-uns", "url": "…/de/ueber-uns/" } },
  "template": "page-about-us.twig", "context": { … }, "blocks": [ … ], "content_at": 0 }
```

Published posts only, the front page and its translation too: `404` for none (an empty slug with
a `type` other than `page` too), `400` for a type that isn't public or a language the site doesn't
have, `409` for a slug more than one post type has (ask again with `type`), `401` for a post
behind a password, unless the request carries its password's cookie, as WordPress's own page
would. The global context is kept per renderer, so a request that swaps renderers never mixes
the two. A template that exits (`exit`, `wp_die()`, a redirect) ends the request there.

**The site API** is what every page shows the same, once per language rather than in each page:

```
GET /wp-json/wp-go-blocks/v1/site?lang=en
```

```json
{ "name": "…", "description": "…", "url": "…/", "lang": "en",
  "languages": [ { "code": "en", "name": "English", "locale": "en_US", "url": "…/" }, … ],
  …, "menus": { "main": { … } } }
```

`url` is the home in that language, `languages` the languages plugin's (each `url` its home;
`400` for a `lang` it doesn't have), `menus` every
registered location that has a menu, by location (a menu, above; a location given none is left
out, even when a menu has its name for a slug), all of it handed to the filter
below, which can change any of it, the menus too: what it returns is the answer. A menu item that links to
content gets its URL from that content (`get_permalink()`…), not from the item's stored URL: a
multilingual plugin pointing it at a translation then gets the translation's link. The rest is
the theme's, on **`wp_go_blocks/site`** (`$site`, `$lang`): options, the footer's lists. It is built
with a `PayloadRenderer` as the renderer, so what a theme shapes through it (images) is data, and
normalized like a payload. On with the content API.

**The block editor's preview** is the same page with unsaved blocks:

```
POST /wp-json/wp-go-blocks/v1/content   { "post_id": 338, "content": "<!-- wp:acf/… -->…" }
```

It renders the post, whatever its status (a draft, an auto-draft too), with `content` as its content instead of
the stored one (`PayloadRenderer::use_content()`), in the post's own language, and answers like
the GET. Nothing is stored. Only for someone who can edit the post (`edit_post`): the editor sends
its REST nonce. `content` is what the editor would save, `getEditedPostContent()`: ACF keeps a
block's field values in its `data` attribute as they change, so they are in it before a save.

`timber/timber` is a `suggest`, not a `require`: a theme on another engine doesn't install it.

## Entities

What a template reads as a post or a menu: the theme names it, as `boot()`'s required `entities`.
Fetching is kept apart from rendering, so the same posts can go to any renderer (a Timber
theme's are Timber's, whether Twig or the `PayloadRenderer` draws them). The package ships two, in
`src/Entities/` under the `WpGo\Blocks\Entities` namespace:

```php
interface Entities {
    public function get_post(mixed ...$args): mixed;      // a post
    public function get_posts(mixed ...$args): iterable;  // posts
    public function get_menu(mixed ...$args): mixed;      // a menu
}
```

| | `WordPressEntities` | `TimberEntities` |
|---|---|---|
| `get_post()` | a `WP_Post`: the queried post with no argument, else by ID, `WP_Post` or query args | `Timber::get_post()`: a `Timber\Post` |
| `get_posts()` | `WP_Query`'s posts: the main query's with no argument, else by query args or a `WP_Query` | `Timber::get_posts()`: a `Timber\PostCollectionInterface`. Always iterable: the one `Timber\Post` it returns for an ID (`$id`, `['ID' => $id]`) comes in a list, and its `null` as `[]` |
| `get_menu()` | a menu's items (`WP_Post`s), by location, ID, slug or name | `Timber::get_menu()`: a `Timber\Menu` |

Their arguments are the implementation's own, passed through as given, as `output()`'s are, but
for one option of the package's own: `'global' => true`, in any array argument (a query's args,
`get_menu()`'s or `get_post()`'s options), taken out before they're passed on. It says the fetch is
the same on every page, the site's rather than the page's (a footer's offices, the main menu), and
announces it: `do_action('wp_go_blocks/global_start')` before, `do_action('wp_go_blocks/global_end',
$result)` after. A page cache that tags pages by what they show (WP-Go's cache-tags feature) files
what it fetched as global: a change to it empties the cache, instead of every page being filed
under it. With no one listening, it changes nothing.

```php
WpGoBlocks::get_posts(['post_type' => 'office', 'global' => true]);
WpGoBlocks::get_menu('main', ['global' => true]);
WpGoBlocks::get_post($id, ['global' => true]);
```

## Conventions

Templates are shown as `.twig`, `TimberRenderer`'s extension; with another renderer they use
its extension ([Renderer](#renderer)).

- **One folder per block:** `<blocks>/<slug>/block.json`, `<slug>.twig`, and optionally
  `<slug>.scss` / `<slug>.js` (its own entries, loaded only where the block renders) and
  `<slug>.php` (a loader). The block is `acf/<slug>`. A `_*.js` under the blocks folder goes in
  the main bundle instead, and so does a block's `_<slug>.scss` in place of its `<slug>.scss`.
- **`block.json`:** `"acf": { "mode": "preview", "renderCallback": "WpGoBlocks::render_block" }`.
  The icon is `wp-go-blocks.json`'s `icon`, unless the `block.json` sets its own.
- **ACF keys** follow no convention: a block's field groups are the ones whose location is the
  block, as ACF finds them, and every key is looked up there. Cloned fields keep their source
  keys and are resolved through the group, at any depth, seamless or not.
- **The context:** a field hidden by its own conditional logic is not sent, and neither is an
  empty value (`null`, `''`, `[]`, or `false` except for a `true_false`). A loader's return value
  follows the same rule.
- **The loader** (`<slug>.php`) has `$fields`, `$block`, `$is_preview`, `$post_id` and `$context`
  in scope. It returns an array that is merged over the context, and declares nothing at the top
  level.
- **Variants:** `<slug>.<post type>.php` runs after the loader, and `<slug>.<post type>.twig` is
  rendered instead of `<slug>.twig`, wherever the block renders for a post of that type
  ([Variants](#variants-what-a-block-takes-from-its-post-type)).
- **Assets:** every tag carries `data-block-asset`, for a front end that swaps pages itself.
  Blocks rendered after `wp_head` reach the head through `<!--wg-block-js-->` /
  `<!--wg-block-css-->`, filled from a whole-response buffer: the first of each, before
  `</head>` ([Asset resolver](#asset-resolver)).

## The Blocks screen

**Appearance → Blocks** (`edit_theme_options`) lists every block but the `dev-*` ones. A row, or
its pencil, opens a dialog for that block:

- **Name**: the title the editor shows (inserter, toolbar, list view), over `block.json`'s. Empty
  keeps `block.json`'s, shown as the placeholder.
- **Inserter preview**: an image shown when the block is hovered in the inserter, from the media
  library: a JPEG, PNG, GIF, WebP, AVIF or HEIC, about 1200px wide. Not an SVG, which WordPress
  knows no size of.
- **Post types**: where the inserter offers it, over `block.json`'s `acf.postTypes` (all when it
  names none), among the post types edited in the block editor but WordPress's own (`wp_*`). "As
  in block.json" ticks those again. None ticked turns `supports.inserter` off. Posts already using
  the block keep it, but the editor may show it as unsupported there; `wp_go_render_block()` and
  [variants](#variants-what-a-block-takes-from-its-post-type) are unaffected. Patterns need no
  ticking: ACF adds `wp_block` to any `acf.postTypes`. A post type `block.json` names that the
  list doesn't offer is kept as long as one is ticked.

The save stores only what differs from `block.json`, and an image, in one autoloaded option,
`wp_go_blocks_settings` (`apply_settings()` on `block_type_metadata`):

```php
['acf/numbers' => ['title' => 'Cifre', 'image' => 123, 'post_types' => ['page', 'event']]]
```

The dialog's media library lists block previews only: images uploaded from it, renamed to
`preview_name` (`block-preview-numbers.png`), titled "Block preview: Numbers" and marked with the
`_wp_go_block_preview` meta. An upload of another type is turned away, and the save takes no other
image: it saves nothing and says so.

The screen's CSS and JS, and the inserter's script, are files in the package's `assets/`, printed
inline: the package is in `vendor/`, which has no URL.

**Its strings** are in the `wp-go-blocks` domain, written as the theme's are
(`_x('…', '<its file, from the package>', 'wp-go-blocks')`), and the package loads no `.mo` for
it: the consumer registers the domain with its own admin strings, the package's folder as the root
(`Composer\InstalledVersions::getInstallPath('we-go/wp-go-blocks')`) and its `src` read. On
WP-Go Languages' `wp_go_languages/admin_strings` filter, and in whatever registers strings in WPML's
String Translation. This theme does both: `includes/theme/admin-strings.php` and
`includes/cli/strings.php` (`wp base strings`).

The preview is not a `block.json` `example`, which would render the block live in an iframe on
every hover (every block's JS and CSS, and an ACF request). The panel keeps its "No preview
available" and a small editor script paints the image over it, finding the block by its title:
two blocks with an image need different names.

## How a block renders

A block reaches the page in one of two ways. Either an editor placed it in `post_content`, or a
template drew it with `wp_go_render_block()`. These are the package's two main functions: `render_block()`
renders every block, and `block()` is the template's way in. `block()` doesn't render anything
itself. It turns its values into what the editor would have stored and hands them to ACF, which
calls `render_block()` like it would for a placed block. So the two paths differ only in where
the values come from, and in what the template can hand the block besides (`context`):

```
 placed in post_content                     drawn by a template
 ──────────────────────                     ───────────────────
 WordPress renders the content              {{ wp_go_render_block('slug', data, options) }}
          │                                          │
          │                                  block()          template.php
          │                                   ├ registered?        no → notice()
          │                                   ├ drawing itself?    yes → notice()
          │                                   ├ field_data()       fields-write.php
          │                                   ├ hand_offs()        its context, under a token
          │                                   └ acf_rendered_block()
          │                                          │
          └──────────── ACF: acf_setup_meta(), ──────┘
                        then the renderCallback
                                 │
                        render_block()             render.php
                         ├ declared_blocks()       (for the head)
                         ├ hand_offs()             block()'s context, by the token
                         ├ fields()                fields-read.php
                         ├ variant()               <slug>.<post type>.*
                         ├ the context
                         ├ run_loader()            <slug>.php, then <slug>.<post type>.php
                         ├ the renderer            <slug>.<post type>.twig, else <slug>.twig
                         └ wp_go_blocks/html → echo
```

### `render_block($block, $content, $is_preview, $post_id)`

The `renderCallback` every `block.json` names, as `WpGoBlocks::render_block`. ACF calls it after `acf_setup_meta()`, which
makes this block's stored values the ones ACF's field functions read. `$block['path']` is the
block's folder.

1. **The slug.** It's the folder's name (`blocks/numbers` → `numbers`), and the template is
   `blocks/numbers/numbers.<extension>`, the renderer's (`numbers.twig` with Timber).
2. **The post, and its variant.** The post the block renders for is ACF's `$post_id`: in the
   editor, the post being edited, which Timber's `post` is not in a preview request. Without
   one, it's the current post. `variant()` looks for that post type's files in the block's
   folder, `<slug>.<post type>.php` and `.<extension>`
   ([Variants](#variants-what-a-block-takes-from-its-post-type)).
3. **The fields** (`fields()`): ACF's field objects, cut down to what is actually there. Fields
   hidden by their conditional logic and empty values are dropped, groups and clones are keyed
   by their bare sub-names, and the theme reshapes shared groups on `wp_go_blocks/group_value`. The
   whole set then goes through `wp_go_blocks/fields`. Step by step, with a worked example, in
   [Reading the fields](#reading-the-fields).
4. **The context** is the renderer's `globals()` (with Timber, its global context: `site`,
   `post`…) with these merged over it, each winning over the one before:

   | | from |
   |---|---|
   | the fields | step 3 |
   | `block()`'s context | `wp_go_render_block()`'s `context` option, found by the token in `$block['wp_go_context']`. Empty for a placed block |
   | `block`, `is_preview` | ACF's block array (`drawn` true for a block `wp_go_render_block()` drew), and whether this is the editor: a block drawn inside a block the editor previews is in the preview too |

   The globals are built once per request and kept, so their `post` (Timber's) is the
   request's: the queried post, or none in an editor's preview. A block rendering for another
   post (in the editor, in another post's content) doesn't get that post as `post`. The post it
   renders for is the loaders' `$post_id` (step 5); a loader that needs it as an entity fetches it,
   `'post' => WpGoBlocks::get_post($post_id)`. It isn't swapped in for every block: the globals are
   the renderer's, whose `post` may not be a post, and a block would pay a fetch for a value it
   most likely never reads.

5. **The loaders.** `<slug>.php`, then the variant's `<slug>.<post type>.php`, if they exist.
   Each is required in a scope of its own, with `$fields`, `$block`, `$is_preview`, `$post_id`
   and `$context` (the variant's includes what the first one returned), on every render. Each
   returns an array, which is emptied the same way as the fields (except that `false` is kept)
   and merged over the context. Anything else it returns is ignored.
6. **The template.** The renderer renders the variant's `<slug>.<post type>.<extension>` if
   there is one, else `<slug>.<extension>`, then the HTML goes through `wp_go_blocks/html` and is
   echoed. ACF captures the output. A template that isn't there is the renderer's to report:
   `TimberRenderer` prints a `notice()` with `WP_DEBUG` on, while `PayloadRenderer` renders the
   context instead, as it means to.

`render_block()` doesn't print the block's CSS or JS, but it **records the block**, by its name
(`declared_blocks()`), first thing, placed or drawn. For a placed block the head usually has them
already: before the content renders, `wp_head` parses `post_content` (`parse_blocks()`, inner
blocks included) and prints every block's tags. Any block the head missed (drawn by a template,
or in content other than the queried post's) reaches it afterwards, through the late slots
(`block()`'s step 5).

### `block($slug, $data = [], $options = [])`

What `{{ wp_go_render_block() }}` calls. It renders a block that is in no one's `post_content`, with values
given in code.

| Argument | |
|---|---|
| `$slug` | `numbers` or `acf/numbers` |
| `$data` | field values by field name, as the template would read them back: `{ title: 'x', cards: [ { text: 'a' } ] }` |
| `$options['context']` | values handed to the block's template untouched: markup the calling template composed, not field values |
| `$options['content']` | the inner blocks' HTML, for a block with `<InnerBlocks />`; `WpGoBlocks::content()` for the post's content |
| `$options['post_id']` | the post it renders for, when it isn't the current one: the one its variant is picked by, its loaders' `$post_id`, and the one a block that keeps its values in post meta reads them from. Its fields are still `$data`: nothing is read from that post |

1. **Is it registered?** If not, it returns `notice()`: a message on the page with `WP_DEBUG`,
   nothing without.
2. **Is it drawing itself?** A block whose template draws itself, directly or through another
   (`a` → `a`, `a` → `b` → `a`), would never return. `block()` keeps the blocks it is drawing, and
   one already among them is a `notice()` (`` `acf/a` draws itself (acf/a → acf/b → acf/a) ``)
   instead.
3. **The values, stored the way the editor stores them** (`field_data()`). ACF reads a block from
   a flat array where each value has a `_name` pointer to its field key beside it. `fields()`
   reads by the block's definitions and needs no pointer, but `get_fields()`, the editor and
   WP-Go Languages do, so the data is the editor's own. Step by step, with a worked example, in
   [Writing the fields](#writing-the-fields).
4. **ACF renders it**: `acf_rendered_block()` with `mode: preview`, the data, and a token in
   `wp_go_context` (below). ACF sets up the values and calls `render_block()`, which runs every
   step above, the post type's variant included. `$is_preview` is false, unless the block is
   drawn inside one the editor is previewing: then it is in the preview too, and true. (ACF itself
   is told false either way: previewing, it would leave `<InnerBlocks />` where the `content`
   goes.) The HTML comes back as a string, and the calling template prints it unescaped (the
   renderer exposed `wp_go_render_block` that way).

   **`wp_go_context`** is how the `context` option gets through ACF. ACF calls `render_block()`
   itself, with its own arguments (`$block, $content, $is_preview, $post_id, $wp_block,
   $context`, the last ACF's block context, not this one), so there is no argument to hand
   anything extra in. Only the attributes ride through, inside `$block`, and an attribute is
   anyone's to write: a post's content, or an editor's preview request, can set `wp_go_context`
   too. So the values don't ride in it. `block()` keeps them for the length of the render
   (`hand_offs()`), under a token it makes up, and the attribute carries only the token:

   ```php
   $token = wp_generate_uuid4();
   $hand_offs[$token] = ['context' => $context, 'is_preview' => previewing()];

   acf_rendered_block([
       'name'          => 'acf/' . $slug,
       'mode'          => 'preview',
       'data'          => field_data($slug, $data),
       'id'            => …,       // what ACF would make of the attributes with the context in them
       'wp_go_context' => $token,
   ], …);
   // then the entry is dropped, however the render ended
   ```

   ACF doesn't know the `wp_go_context` attribute and passes it through to `$block` untouched.
   `render_block()` looks the token up and merges what it finds into the context (its step 4).
   Anything else in the attribute (an array a post's content wrote, a token that has gone) is
   ignored, and the attribute is taken out of `block`. The `id` is given so that the token, new
   on every render, doesn't make a new block id each time: the same block drawn with the same
   values keeps one id. So the context:
   - it is **not a field value**. Nothing in [Reading the fields](#reading-the-fields) touches
     it: no conditions, no dropping of empty values, no filters. It's for what the template
     composed, usually markup;
   - it **wins over the fields**, being merged after them. `block`, `is_preview` and the
     loaders win over it;
   - it **reaches only this block**, and only where this template draws it. What a block needs
     from its post type wherever it renders, placed or drawn, belongs in a
     [variant](#variants-what-a-block-takes-from-its-post-type) instead;
   - it is **never stored**, and never in the block: it is kept in memory for this one render,
     and the attribute holds only its token. A block whose `wp_go_context` is saved in a post's
     content (by hand, or by a contributor) gets nothing from it.

   That ACF passes an unknown attribute through is not documented. If it stops,
   `context` silently stops arriving (`UPDATING.md`).
5. **The assets reach the head afterwards.** `wp_head` left two markers,
   `<!--wg-block-js-->` and `<!--wg-block-css-->`, and the whole response is buffered. Once the
   page has rendered, the markers are replaced with the tags of every block `render_block()`
   recorded (and every `declare_bundle()`), minus what the head already printed. The tags stay in the head
   and in order, so a block's `Alpine.data()` still runs before the main bundle starts Alpine.

### Variants: what a block takes from its post type

Some of what a block shows isn't authored in the block and isn't the same everywhere: it comes
from the post the block is on. Sticky columns, on an event, might show the event's card
(speakers, date, location, contacts) and its opening statement; on a service, the service's card
and its description. Those belong to the post type, not to the block's fields, and not to one page
template either, because the block can be placed in the content, drawn by a template, or shown
in the editor.

A block's **variants** are files in its folder named after a post type:

| | |
|---|---|
| `<slug>.<post type>.php` | runs after `<slug>.php`, with the same scope, and is merged over it |
| `<slug>.<post type>.twig` (the renderer's extension) | rendered instead of `<slug>.twig` |

Either is optional. `render_block()` picks them from the post it renders for (ACF's `$post_id`,
else the current post), so they apply **wherever the block renders for that post type**. A post
type named like the first part of the renderer's extension (`payload`, with `PayloadRenderer`'s
`payload.php`) has no `.php` variant: `<slug>.payload.php` is the block's own template there, not
a loader.

| Where | The post |
|---|---|
| placed in the content, on the site | the page's post |
| drawn by a template with `wp_go_render_block()` | the page's post |
| the editor's preview (an AJAX request) | ACF's `$post_id`, the post being edited |
| ACF's preload, when the editor opens | ACF's `$post_id` |

There's no hand-off from the page template, and nothing to fetch or carry: every render computes
what it needs from the post it is given. That's why a loader has `$post_id`. In an AJAX preview,
Timber's `post` is not the post being edited.

Keyed by post type, not by template file, because every one of those renders knows the post
type for certain, while the template file depends on the hierarchy (`single-event.php`,
`single.php`, a page template).

**Example:** Sticky columns on an event. Hypothetical: the theme has no `event` post type, and
its sticky columns read their own fields only.

```php
<?php // blocks/sticky-columns/sticky-columns.event.php

$contacts = theme_office_contacts($post_id);

return [
    'card' => [
        'speakers' => …,                              // from the event's `speakers`
        'date'     => get_field('date', $post_id),
        'location' => theme_event_location($post_id),
        'email'    => $contacts['email'],
        'phone'    => $contacts['phone'],
    ],
    'statement' => [
        'title' => esc_html((string) get_field('excerpt', $post_id)),
        'text'  => get_field('content', $post_id),
    ],
];
```

```twig
{# blocks/sticky-columns/sticky-columns.event.twig #}
{% include 'blocks/sticky-columns/sticky-columns.twig' with {
  sticky_content: include('components/sticky-event-card/sticky-event-card.twig', card ?? {}, with_context = false),
  main_content: include('components/event-statement/event-statement.twig', statement ?? {}, with_context = false),
} %}
```

The `.twig` variant includes the block's own template with what it adds, so the block's markup
stays in one place, and `sticky-columns.twig` would read `sticky_content` and `main_content` like
any other value. A service has its own pair, `sticky-columns.service.*`. On any other post type the
block renders plain.

The block's folder holds its per-post-type files, so it shows which post types customise it,
next to the block, by name rather than by branches in code.

## The fields, in detail

ACF stores a block's values one way and a template wants them another. Between the two, the
package does two jobs: reading (`fields-read.php`, every render) and writing (`fields-write.php`,
for `block()` and seeders). The examples below are the theme's own blocks, `text-image` and
`accordion`, run through the real functions.

### Reading the fields

`fields()` starts from ACF's field objects (`field_objects()`): one per top-level field **the
block defines** (`block_fields()`, from `acf_get_block_fields()`), in the field group's order,
its `value` read with `acf_get_value()` and formatted by ACF. A field the block's data doesn't
have (the block was saved before the field existed, or written without it) is read as ACF reads
any missing value, at its `default_value`: what the editor shows, since ACF's block form writes
every field into the data as it loads. `get_field_objects()` lists only the stored fields, so
such a field would be missing on the site while the editor shows its default. A seamless clone,
which ACF spreads into its prefixed sub fields, is read as the clone itself (`container`); one
with no name has no entry of its own, so its sub fields stay the block's (`image`, `alt`), as
`get_fields()` returns them. Fields that hold no value (tab, message, accordion) are skipped. Return formats are applied (an
image as its ID or an array), a link is `{ title, url, target }` with the url made absolute, a
`true_false` is a bool, a number or range a number (ACF gives back a string when its form saved
it), a group or a clone is an array of its sub values, and a repeater is a list of rows.

`visible_fields()` then takes each field through these steps, in order:

1. **Conditional logic.** The field's rules are evaluated against its siblings, meaning the
   fields at the same level: the block's top-level fields, or the sub fields of one group, clone
   or row. OR between rule groups, AND within one.

   | Operator | Passes when the sibling's value… |
   |---|---|
   | `==` / `!=` | equals / doesn't equal the rule's value, as strings. An array value is searched instead |
   | `!==` | the same as `!=`: ACF's "is not equal to" for a post, a page link, a relationship, a term or a user |
   | `==empty` / `!=empty` | is / isn't `null`, `''`, `[]` or `false` (a `true_false` that is off counts as empty here) |
   | `==contains` / `!=contains` | contains / doesn't contain it, as a substring or an array item |
   | `==pattern` | matches it as a regular expression, ignoring case, as ACF's form does. A pattern PHP can't compile passes |
   | `>` / `<` | is numeric and greater / smaller |

   The comparison is against the **formatted** value: a post, a term or a user, one or a list of
   them (a relationship, a multiple post object), is compared by its ID, and a select by what it
   returns. A select returning its label won't match a rule written against its value. A rule
   pointing at a field outside this level passes, and so does an operator this doesn't know.
   Leaving a field visible is the safer mistake.

   A seamless clone gives the fields it brings compound keys (`<clone key>_<source key>`), while
   their rules still name the source's keys: `container`'s `gradient` shows when
   `field_wg_container_background` is `none`, though that field is
   `field_base_rich_text_container_field_wg_container_background` in the block. So a rule is
   matched first against the sibling from the same clone, then by the source key (`__key`).

   A hidden field is dropped even when it still has a stored value, from before the editor
   changed the condition. The template never needs to know the value is stale.
2. **`wp_go_blocks/field_value`** (`$value, $field`) gets the value before anything is dropped. The
   theme's dev tools fill empty image fields with a placeholder here.
3. **Into the sub fields**, by type. Each level down goes through these same steps, after
   `hydrate()` has rebuilt its field objects (see [`hydrate()`](#hydrate) below):
   - **repeater**: each row is hydrated with the repeater's sub fields. A row with nothing left
     is removed;
   - **group, clone**: the same, as one row. A clone with `prefix_name` names its sub fields
     `title_text`, `title_tag`, but its value, and so the template, uses the names they have in
     their source, `title.text`, at any depth. The resolved array then goes through
     **`wp_go_blocks/group_value`** (`$value, $field`), where a shared group can be given one
     shape (see the theme's below). Returning `[]` drops it;
   - **flexible content**: like a repeater, but each row is paired with its own layout's sub
     fields (ACF keeps them under `layouts`, by the row's `acf_fc_layout`). A row keeps
     `acf_fc_layout` even when none of its fields is left, because the layout is itself the
     content (a divider);
   - **anything else** (a link, a gallery, a relationship) is ACF's value as it is. Keys inside
     it are not checked: a link's empty `target` stays.
4. **Empty values are dropped**: `null`, `''`, `[]`, and `false` except on a `true_false`, where
   it's the answer "off". It runs after the sub fields, so a group whose sub fields were all
   dropped is `[]` and goes too, and after the filters, so a filter can drop a value by emptying
   it.

The result then goes through `wp_go_blocks/fields` (`$fields, $slug`), for anything that needs the
block's fields together.

#### `hydrate()`

`visible_fields()` works on **field objects**: a field's definition with its `value` set, the
shape `get_field_objects()` returns. Every step needs more than the value. Conditional logic
reads the fields' `key`s and `conditional_logic`, the empty check needs the `type` (`false` on a
`true_false`), and the filters receive the whole `$field`.

`field_objects()` gives field objects for the **top level** only. Below it, ACF gives plain
values: a repeater row is `['title' => 'Quanto costa?', 'text' => '<p>…</p>']`, with no
definitions in it. The definitions are on the parent, in its `sub_fields` (in `layouts` for
flexible content).

`hydrate($sub_fields, $values, $in_clone)` puts the two back together. It finds each sub field's
value and sets it on the definition as `value`. The result has `get_field_objects()`'s shape, so
`visible_fields()` runs on it unchanged, at any depth. It is called for each flexible content row
(with that row's layout's sub fields), each repeater row, and each group or clone. Fields that
hold no value are skipped, as at the top: inside a prefixed clone a tab even has a name
(`container_`).

**The names.** Each value is found, and the result keyed, by the name ACF keys it by. A group's
or a row's sub fields by their own (`_name`). A clone's by the names they have in their source
(`__name`, `$in_clone`): a clone with `prefix_name` names its sub fields' definitions `cta_url`,
`cta_label`, but its value is `{ url, label, … }`, and the result is keyed `url`, `label`, the
names the template reads. No prefix is stripped to get there, because no prefix would work at
every depth: a clone inside a prefixed clone is named `cc_h`, while its own sub fields stay
`h_text`, yet its value is `{ text, tag }` like any other.

**A seamless clone below the top level** is not put back together, as a named one is at the top: its sub
fields stay where ACF puts them, beside their siblings, as `get_field()` returns them. The theme's
media group (`group_wg_media`) clones the image group seamlessly, so a block's `media` is
`{ type, image, alt, video_url, … }`, flat.

In `text-image`, `title` is a top-level field object, a clone of the heading. Its value is
hydrated with the heading's sub fields, pairing `title_text` with `text`. That is where the
heading's `rich` toggle can hide `text_rich`: the toggle's rule needs `rich` and `text_rich` as
field objects, side by side.

**Example:** `text-image`, written with a stale value in three places: the heading's
`text_rich` (its toggle says plain text), the cta's outside `url` (it links a page), and the
container's `gradient` (shown on no background only). This is what `field_objects()` returns:

```
eyebrow         text     "Lo studio"
title           clone    { rich: false, text: "Chi siamo", text_rich: "<p>stale</p>\n", tag: "h2", typography: "" }
text            wysiwyg  ""
media           clone    { image: 18, image_mobile: false, alt: "" }
image_position  select   "left"
cta             clone    { link_type: "internal", page: 20, anchor: "", url: "https://example.test/stale",
                           file: false, label: "", target: false, type: "primary", icon: "" }
container       clone    { padding_top: "none", padding_bottom: "none", background: "dark", gradient: ["top"] }
```

After the package's own steps (without the theme's filters):

```json
{
  "eyebrow": "Lo studio",
  "title": { "rich": false, "text": "Chi siamo", "tag": "h2" },
  "media": { "image": 18 },
  "image_position": "left",
  "cta": { "link_type": "internal", "page": 20, "target": false, "type": "primary" },
  "container": { "padding_top": "none", "padding_bottom": "none", "background": "dark" }
}
```

- `text` is gone: empty.
- `title.text_rich` is gone, hidden by the clone's own `rich` toggle. `typography` is `''`.
- `media.image_mobile` is `false` on an image field, which means none chosen, so it's gone, and
  so is the empty `alt`.
- `cta.url` is gone: it shows only when `link_type` is `external`, so its stored value never
  does. So is `file` (another type's), and so are the empty `anchor`, `label` and `icon`.
  `cta.target` stays, because `false` is an answer on a `true_false`.
- `container.gradient` is gone, hidden on a `dark` background, though the rule names the
  source's key and the field has the clone's.

The theme then reshapes its shared groups on `wp_go_blocks/group_value`
(`includes/theme/field-shapes.php`): a heading becomes `{ text, tag, typography }`, with `text`
from whichever field the toggle picked; a cta becomes `{ url, label, target, rel, type, icon }`,
the page's permalink and title in place of `page`; a media group with no asset is dropped; a
container gets back an empty `gradient` to loop over. So the template gets:

```json
{
  "eyebrow": "Lo studio",
  "title": { "text": "Chi siamo", "tag": "h2" },
  "media": { "image": 18 },
  "image_position": "left",
  "cta": { "url": "http://base.test/contatti/", "label": "Contatti", "target": "_self", "type": "primary" },
  "container": { "padding_top": "none", "padding_bottom": "none", "background": "dark", "gradient": [] }
}
```

### Writing the fields

`field_data($slug, $data)` goes the other way: from values by name, shaped the way `fields()`
hands them back, to the flat array the editor would have saved in the block comment. `block()`
renders from it, and a seeder can save it into `post_content`.

Each value is stored beside a `_name` pointer to its field's key. `fields()` reads a block by its
definitions and needs no pointer, but everything else reading the stored data does:
`get_fields()` and `get_field_objects()` find a block's fields through them (and reach a clone's
sub fields only through the clone's own entry), the editor's form, and WP-Go Languages, which
tells a text to translate from a setting to copy by the field a pointer names. So they are written
as the editor writes them. `field_data()`:

1. **Gathers a top-level clone given flat.** A clone can be given as its group
   (`title: { text, tag }`), the way `fields()` hands it back, or flat, by the names its sub
   fields are stored under (`title_text`, `container_padding_top`). Flat ones are gathered into
   the clone. A name that is a field of the block's own stays that field's.
2. **Builds what the editor's form would post** (`request()`, `request_value()`): every value
   under its field's key, nested as the fields are, from the block's definitions
   (`block_fields()`, the same list `fields()` reads). A group or a clone is given as its array,
   a repeater as a list of rows, flexible content as rows with their `acf_fc_layout`
   (`[ { acf_fc_layout: 'text', body: '…' }, { acf_fc_layout: 'divider' } ]`); a nameless
   top-level clone's sub fields flat (`image`), each posted under the clone's key at its compound
   key, as the form names its inputs (`acf[<clone key>][<clone key>_<source key>]`). A value is
   matched by the name `fields()` hands it back under (a clone's sub field by its name in the
   source, `text` in `title`; any other by its own), or by the one it is stored under. A name the
   block has no field for, a cell with no matching sub field, or a row whose layout doesn't exist
   raises a warning (`E_USER_WARNING`) and is left out, instead of being stored under a key
   nothing reads.
3. **Lets ACF store it**, the way ACF stores the editor's form when a block is saved
   (`acf_parse_save_blocks_callback()`): `acf_setup_meta()` runs ACF's own save on it, each
   field's `update_value()` included, and keeps what would have been written instead of writing
   it. So the flat names (`items_0_title_text`), the pointers, the empty entry of a group or a
   clone, a repeater's row count and flexible content's list of layouts are all ACF's, and can't
   drift from what the editor writes: a cloned sub field points at its **source group's key**
   (`field_wg_heading_text`), at any depth. The values come out as the editor's too: an image
   given as an array is stored as its ID. The id the save runs for is shaped like a block's
   (`block_wpgoblocksfielddata`), which has no table, so nothing reaches the database, and the
   meta is let go at once.

**Example:** `accordion`, a heading clone and a repeater of questions:

```php
WpGoBlocks::field_data('accordion', [
    'title' => ['text' => 'Domande', 'tag' => 'h2'],
    'items' => [
        ['title' => 'Quanto costa?', 'text' => '<p>Dipende.</p>'],
        ['title' => 'Quanto dura?'],
    ],
]);
```

```php
[
    'title_text'      => 'Domande',
    '_title_text'     => 'field_wg_heading_text',           // the source group's key
    'title_tag'       => 'h2',
    '_title_tag'      => 'field_wg_heading_tag',
    'title'           => '',                                // the clone's own entry
    '_title'          => 'field_base_accordion_title',
    'items_0_title'   => 'Quanto costa?',
    '_items_0_title'  => 'field_base_accordion_item_title',
    'items_0_text'    => '<p>Dipende.</p>',
    '_items_0_text'   => 'field_base_accordion_item_text',
    'items_1_title'   => 'Quanto dura?',
    '_items_1_title'  => 'field_base_accordion_item_title',
    'items'           => 2,                                 // the row count
    '_items'          => 'field_base_accordion_items',
]
```

Only what was given is stored. A field left out is read back at its default, like one a block
saved before the field existed, so through `fields()` this is the shape it was given plus the
defaults: `{ title: { rich: false, text, tag }, items: [ { title, text }, { title } ],
container: { padding_top: 'none', padding_bottom: 'none', background: 'none' } }`.

## Templates

```twig
{{ wp_go_render_block('slug', { field: value }, { context: { markup: html }, content: inner, post_id: 1 }) }}
```

`wp_go_render_block` renders a block that is not in `post_content`. Its values are stored the way the
editor would store them, and `context` goes to the block's template untouched. `post_id` is the
post it renders for (its variant, its loaders' `$post_id`), not where its fields come from: those
are the values given. It's a function
the renderer exposes (`expose()`), here as Twig's. What a block takes from its
post type, wherever it renders, is its [variants](#variants-what-a-block-takes-from-its-post-type)'.

## PHP API

What a theme or a plugin calls. Everything else is under `WpGo\Blocks\Core`, the package's own.

| | |
|---|---|
| `\WpGo\Blocks\boot($config_file, $options)` | set up, once ([Setup](#setup)) |
| `WpGoBlocks::context($extra)`, `::render(...$args)`, `::compile($template, $context)` | a page's context, a page, a template to a string ([Renderer](#renderer)) |
| `WpGoBlocks::get_post()`, `::get_posts()`, `::get_menu()` | posts and menus, from the entities ([Entities](#entities)) |
| `WpGoBlocks::block($slug, $data, $options)` | a block drawn from PHP, as `wp_go_render_block` is in a template |
| `WpGoBlocks::content()` | the post's content where a template puts it: HTML, or as data the place of its blocks |
| `WpGoBlocks::field_data($slug, $data)` | values by field name → block data as the editor stores it (for a seeder) |
| `WpGoBlocks::registered()` | block name → folder |
| `WpGoBlocks::declare_bundle($base)` | load a bundle's CSS/JS on this page |
| `WpGoBlocks::notice($message)` | a template bug, shown with `WP_DEBUG`: the message as given, so it names what reports it (`wp_go_render_block(): …`) |
| `WpGoBlocks::config($key)` | a resolved setting: `wp-go-blocks.json`'s, `boot()`'s, or the default |
| `WpGoBlocks::renderer()`, `::use_renderer($renderer)`, `::entities()` | the renderer in use, another one for a while (returns the previous), the entities |
| `Renderer\Renderer`, `Renderer\TimberRenderer`, `Renderer\PayloadRenderer` (and `PayloadRenderer::image($id)`, `::menu($which)`) | what blocks render with: Twig through Timber, and the JSON experiment ([Renderer](#renderer)) |
| `Entities\Entities`, `Entities\WordPressEntities`, `Entities\TimberEntities` | what posts and menus are fetched with: WordPress's objects, or Timber's ([Entities](#entities)) |
| `Assets\AssetResolver` | where a block's CSS/JS was built ([Asset resolver](#asset-resolver)) |

The content API (`Core\ContentApi`) is switched on with `boot()`'s `content_api`
([The content API](#the-content-api)).

## Hooks

| Filter | Arguments | |
|---|---|---|
| `wp_go_blocks/context` | `$context` | the global context, whatever renders: under every page's (`WpGoBlocks::context()`) and every block's context. Starts from the renderer's `globals()`; built once per request and kept, as Timber does with `timber/context` |
| `wp_go_blocks/payload_image` | `$image, $id` | an image as data (`PayloadRenderer::image()`), for what the theme adds: a placeholder, a colour |
| `wp_go_blocks/payload_menu_item` | `$row, $item` | a menu item as data (`PayloadRenderer::menu()`), for what a theme's own items hold: a container item's children from elsewhere, its own type's `kind` |
| `wp_go_blocks/site` | `$site, $lang` | the site API's answer (`GET …/site`), its `menus` included, for what every page shows the same: options, the footer's lists |
| `wp_go_blocks/field_value` | `$value, $field` | every field as it is read, before the empty check |
| `wp_go_blocks/group_value` | `$value, $field` | each group/clone once resolved; `[]` drops it |
| `wp_go_blocks/fields` | `$fields, $slug` | a block's fields as a whole, before the context is built |
| `wp_go_blocks/html` | `$html, $slug, $context, $is_preview` | a block's markup before it is printed; `$context['block']['drawn']` for a drawn block, whose markup goes to what drew it, even in a preview |
| `wp_go_blocks/parse_content` | `$parse, $post` | false when a page does not render its post's content. Asked on a singular page, and with `buffer` off for each post of a list |
| `wp_go_blocks/notice` | `$html, $message` | the notice's markup |

| Action | Arguments | |
|---|---|---|
| `wp_go_blocks/global_start` | | a fetch with `'global' => true` begins ([Entities](#entities)) |
| `wp_go_blocks/global_end` | `$result` | it ended, with what it fetched |

## Upgrading

What it relies on, and the versions it was last checked against, are in `UPDATING.md`.
