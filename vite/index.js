import fs from 'node:fs'
import path from 'node:path'
import zlib from 'node:zlib'

import { normalizePath } from 'vite'

// The build half of wp-go-blocks. It reads the same wp-go-blocks.json as the PHP, so
// the two cannot disagree on where the blocks are.
//
//   - every block's <slug>.scss and <slug>.js become Vite entries of their
//     own (a block: a folder of the blocks folder with a block.json, as the
//     PHP registers them; any other folder is skipped), alongside the theme's main and editor entries (`entries`, globs
//     allowed: `src/editor.*.js`) and any extra bundle (`bundles`), so the
//     front end loads only what a page renders;
//   - `virtual:wp-go-blocks/shared` is every `_*.js` under the blocks folder: the
//     main bundle imports it, on every page;
//   - `virtual:wp-go-blocks/styles` is every block's `_<slug>.scss`, beside its
//     block.json: the main bundle imports it, so a small block's styles cost no
//     request of their own. Only that name, there: a `_*.scss` anywhere else is
//     a partial the block's own stylesheet @uses;
//   - `virtual:wp-go-blocks/editor` is every block's own entries, every `_*.js`
//     and every bundle, for the editor, where any block can be inserted at any
//     moment; in a build, not the `_<slug>.scss`, which the canvas has in the
//     main CSS;
//   - a block stylesheet reaches the page through PHP, not through the module
//     graph, and PHP and Twig are not modules either: a change to any of them
//     reloads the page.
//
// The globs live in generated modules because import.meta.glob takes literal
// paths only: a theme cannot glob a folder whose name is configuration.
//
// After a build, the blocks whose own stylesheet is under `smallStyles` bytes
// gzipped are listed: candidates for `_<slug>.scss`.

const SHARED = 'virtual:wp-go-blocks/shared'
const STYLES = 'virtual:wp-go-blocks/styles'
const EDITOR = 'virtual:wp-go-blocks/editor'

const DEFAULTS = {
  blocks: 'blocks',
  entries: { main: 'src/main.js', editor: 'src/editor.js' },
  bundles: []
}

export function readConfig (themePath) {
  const file = path.join(themePath, 'wp-go-blocks.json')
  let config = {}

  // JSON.parse's own error names a position, not the file
  if (fs.existsSync(file)) {
    try {
      config = JSON.parse(fs.readFileSync(file, 'utf8'))
    } catch (error) {
      throw new Error(`wp-go-blocks: ${file} is not valid JSON (${error.message})`, { cause: error })
    }

    if (config === null || typeof config !== 'object' || Array.isArray(config)) {
      throw new Error(`wp-go-blocks: ${file} must hold an object`)
    }
  }

  return {
    ...DEFAULTS,
    ...config,
    entries: { ...DEFAULTS.entries, ...config.entries },
    blocks: (config.blocks ?? DEFAULTS.blocks).replace(/^\/+|\/+$/g, '')
  }
}

// The block folders' names, sorted: those holding a block.json, the ones the
// PHP registers. Any other folder there is not a block, and nothing of it is
// built or imported.
const blockSlugs = (themePath, blocks) => {
  const root = path.join(themePath, blocks)

  if (!fs.existsSync(root)) return []

  return fs.readdirSync(root, { withFileTypes: true })
    .filter(entry => entry.isDirectory() && fs.existsSync(path.join(root, entry.name, 'block.json')))
    .map(entry => entry.name)
    .sort()
}

// Theme-relative paths of every block's own entry files, sorted.
const blockEntries = (themePath, blocks) => blockSlugs(themePath, blocks)
  .flatMap(slug => [ 'scss', 'js' ]
    .map(ext => `${blocks}/${slug}/${slug}.${ext}`)
    .filter(rel => fs.existsSync(path.join(themePath, rel))))

// Theme-relative paths of every block's `_<slug>.scss`, sorted: its styles in
// the main bundle.
const blockMainStyles = (themePath, blocks) => blockSlugs(themePath, blocks)
  .map(slug => `${blocks}/${slug}/_${slug}.scss`)
  .filter(rel => fs.existsSync(path.join(themePath, rel)))

// An entry is a theme-relative path or a glob of them (fs.globSync, Node 22),
// sorted. A glob is read once, when Vite starts: a new file needs a restart.
const entryFiles = (themePath, entries) => Object.values(entries)
  .filter(Boolean)
  .flatMap(entry => /[*?[{]/.test(entry)
    ? fs.globSync(entry, { cwd: themePath }).map(rel => rel.split(path.sep).join('/')).sort()
    : [ entry ])

const bundleEntries = (themePath, bundles) => bundles
  .flatMap(base => [ `${base}.js`, `${base}.scss` ])
  .filter(rel => fs.existsSync(path.join(themePath, rel)))

// `themePath`: the theme, which is the Vite root. `smallStyles`: the gzipped
// size, in bytes, under which a block's own stylesheet is reported after a
// build as better in the main bundle; 0 for no report. `keepStyles`: blocks
// whose stylesheet stays their own whatever its size, by slug or RegExp (a
// block only development renders, say), left out of the report.
export default function wpGoBlocks ({
  themePath, smallStyles = 1024, keepStyles = []
}) {
  const config = readConfig(themePath)
  const blocksDir = path.join(themePath, config.blocks)
  let resolved

  // A block folder's file, by its absolute path: [ slug, file name ], or
  // null for anything else
  const blockFile = file => {
    const rel = path.relative(blocksDir, file).split(path.sep)

    return rel.length === 2 && rel[0] !== '..' ? rel : null
  }

  // a block's `_<slug>.scss`
  const isMainStyle = file => {
    const [ slug, name ] = blockFile(file) ?? []

    return name === `_${slug}.scss`
  }

  // a block's own `<slug>.scss` or `<slug>.js`
  const isBlockEntry = file => {
    const [ slug, name ] = blockFile(file) ?? []

    return name === `${slug}.scss` || name === `${slug}.js`
  }

  // a block's block.json: what makes its folder a block
  const isBlockJson = file => blockFile(file)?.[1] === 'block.json'

  return {
    name: 'wp-go-blocks',

    config () {
      return {
        build: {
          rolldownOptions: {
            input: [
              ...entryFiles(themePath, config.entries),
              ...blockEntries(themePath, config.blocks),
              ...bundleEntries(themePath, config.bundles)
            ]
          }
        }
      }
    },

    configResolved (resolvedConfig) {
      resolved = resolvedConfig
    },

    resolveId (id) {
      if (id === SHARED || id === STYLES || id === EDITOR) return `\0${id}`
    },

    // Root-relative patterns: Vite resolves them from the theme, and keeps
    // the globs live in dev (a new file is picked up without a restart).
    load (id) {
      if (id === `\0${SHARED}`) {
        return `export default import.meta.glob(${JSON.stringify(`/${config.blocks}/**/_*.js`)}, { eager: true })\n`
      }

      // a list, not a glob: a glob can't ask for the folder's own name
      if (id === `\0${STYLES}`) {
        return blockMainStyles(themePath, config.blocks).map(rel => `import ${JSON.stringify(`/${rel}`)}\n`).join('')
      }

      // What the front end loads, and nothing else: each block's own entries,
      // the blocks folder's `_*.js` (globbed, as the main bundle has them),
      // and the bundles. Not every file under the folder: a partial or a
      // helper there is its block's to import, and compiles on its own to
      // nothing, or to a duplicate.
      //
      // In a build, not the blocks' `_<slug>.scss`: the canvas has the main
      // CSS, and a stylesheet both entries import would leave the main CSS for
      // a chunk of its own, which the theme doesn't link. In dev they are in:
      // the canvas's main CSS is then the main stylesheet alone, as the dev
      // server serves it, without what the main entry imports.
      if (id === `\0${EDITOR}`) {
        const mainStyles = resolved?.command === 'build' ? [] : blockMainStyles(themePath, config.blocks)
        const files = [ ...blockEntries(themePath, config.blocks), ...mainStyles ].sort()
        const imports = [ ...files, ...bundleEntries(themePath, config.bundles) ]
          .map(rel => `import ${JSON.stringify(`/${rel}`)}\n`)
          .join('')

        return `import.meta.glob(${JSON.stringify(`/${config.blocks}/**/_*.js`)}, { eager: true })\n${imports}`
      }
    },

    // The lists, read again when a file on them comes or goes: the styles'
    // for a `_<slug>.scss`, the editor's for that or a block's own entry,
    // both for a block.json, which makes a folder a block or not
    configureServer (server) {
      const changed = file => {
        const ids = isMainStyle(file) || isBlockJson(file) ? [ STYLES, EDITOR ] : isBlockEntry(file) ? [ EDITOR ] : []

        if (!ids.length) return

        for (const id of ids) {
          const module = server.moduleGraph.getModuleById(`\0${id}`)

          if (module) server.moduleGraph.invalidateModule(module)
        }

        server.ws.send({ type: 'full-reload', path: '*' })
      }

      server.watcher.on('add', changed)
      server.watcher.on('unlink', changed)
    },

    // Vite hands `file` over normalised, with forward slashes on every OS
    handleHotUpdate ({ file, server }) {
      const blockStylesheet = file.startsWith(`${normalizePath(blocksDir)}/`) && file.endsWith('.scss')

      if (blockStylesheet || file.endsWith('.php') || file.endsWith('.twig')) {
        server.ws.send({ type: 'full-reload', path: '*' })
      }
    },

    // The blocks whose own stylesheet is small enough for the main bundle,
    // from the manifest the build just wrote
    closeBundle () {
      if (resolved?.command !== 'build' || !smallStyles || !resolved.build.manifest) return

      const outDir = path.resolve(resolved.root, resolved.build.outDir)
      const manifestFile = path.join(outDir, typeof resolved.build.manifest === 'string' ? resolved.build.manifest : '.vite/manifest.json')

      if (!fs.existsSync(manifestFile)) return

      const manifest = JSON.parse(fs.readFileSync(manifestFile, 'utf8'))
      const small = blockSlugs(themePath, config.blocks)
        .filter(slug => !keepStyles.some(keep => keep instanceof RegExp ? keep.test(slug) : keep === slug))
        .map(slug => ({ slug, chunk: manifest[`${config.blocks}/${slug}/${slug}.scss`] }))
        .filter(({ chunk }) => chunk?.file && fs.existsSync(path.join(outDir, chunk.file)))
        .map(({ slug, chunk }) => ({ slug, size: zlib.gzipSync(fs.readFileSync(path.join(outDir, chunk.file))).length }))
        .filter(({ size }) => size < smallStyles)

      if (!small.length) return

      resolved.logger.warn([
        `\nwp-go-blocks: ${small.length} block stylesheet${small.length === 1 ? '' : 's'} under ${smallStyles} B gzipped, a request each where they render.`,
        `Rename ${config.blocks}/<slug>/<slug>.scss to _<slug>.scss to put one in the main bundle:`,
        ...small.map(({ slug, size }) => `  ${slug.padEnd(Math.max(...small.map(item => item.slug.length)))}  ${size} B`)
      ].join('\n'))
    }
  }
}
