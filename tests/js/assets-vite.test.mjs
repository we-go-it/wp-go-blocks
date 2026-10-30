// The Vite plugin (vite/index.js) against a fake theme made in a temp
// folder: readConfig(), the build's inputs, the three virtual modules' code,
// the reloads in development and the watcher keeping the lists fresh, and
// the small stylesheets' report. Its hooks are called as Vite would call
// them, with fake servers: no Vite runs here (assets-vite-build.test.mjs
// builds the fake theme for real).

import assert from 'node:assert/strict'
import { EventEmitter } from 'node:events'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { after, describe, test } from 'node:test'

import wpGoBlocks, { readConfig } from '../../vite/index.js'

const made = []

// A theme folder: { 'relative/path': content }. Removed after the tests.
const theme = files => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'wp-go-blocks-assets-'))

  made.push(root)

  for (const [ rel, content ] of Object.entries(files)) {
    fs.mkdirSync(path.dirname(path.join(root, rel)), { recursive: true })
    fs.writeFileSync(path.join(root, rel), content)
  }

  return root
}

after(() => made.forEach(root => fs.rmSync(root, { recursive: true, force: true })))

// The theme most tests use:
//   alpha     a block: its stylesheet and script, its _alpha.scss, a partial, a helper
//   beta      a block: its stylesheet and its _beta.scss
//   gamma     a block: its script only
//   loose     no block.json: not a block, though named like one
//   _root.js  a shared script at the top of the blocks folder
// The blocks folder written with slashes around it, the editor entry a
// glob, an extra entry and an empty one, two bundles (one only a script).
const FAKE = {
  'wp-go-blocks.json': JSON.stringify({
    blocks: '/blocks/',
    entries: { editor: 'src/editor.*.js', extra: 'src/extra.js', none: '' },
    bundles: [ 'form/form', 'only-js/only-js' ]
  }),
  'src/main.js': '',
  'src/editor.b.js': '',
  'src/editor.a.js': '',
  'src/extra.js': '',
  'blocks/_root.js': 'export default () => ({})',
  'blocks/alpha/block.json': '{}',
  'blocks/alpha/alpha.scss': '.alpha { color: red }',
  'blocks/alpha/alpha.js': '',
  'blocks/alpha/_alpha.scss': '.alpha-main { color: red }',
  'blocks/alpha/_partial.scss': '',
  'blocks/alpha/_helper.js': '',
  'blocks/beta/block.json': '{}',
  'blocks/beta/beta.scss': '.beta { color: red }',
  'blocks/beta/_beta.scss': '',
  'blocks/gamma/block.json': '{}',
  'blocks/gamma/gamma.js': '',
  'blocks/loose/loose.scss': '',
  'blocks/loose/loose.js': '',
  'blocks/loose/_loose.scss': '',
  'form/form.js': '',
  'form/form.scss': '',
  'only-js/only-js.js': ''
}

const SHARED = 'virtual:wp-go-blocks/shared'
const STYLES = 'virtual:wp-go-blocks/styles'
const EDITOR = 'virtual:wp-go-blocks/editor'

const GLOB = 'import.meta.glob("/blocks/**/_*.js", { eager: true })\n'
const imports = (...files) => files.map(file => `import "/${file}"\n`).join('')

// A server as configureServer() and handleHotUpdate() use it: what it
// sends, which modules it invalidated, and its watcher
const fakeServer = (modules = [ STYLES, EDITOR ]) => {
  const server = {
    sent: [],
    invalidated: [],
    watcher: new EventEmitter(),
    ws: { send: message => server.sent.push(message) },
    moduleGraph: {
      getModuleById: id => modules.includes(id.slice(1)) ? { id } : undefined,
      invalidateModule: module => server.invalidated.push(module.id)
    }
  }

  return server
}

const RELOAD = { type: 'full-reload', path: '*' }

describe('readConfig()', () => {
  test('a theme without wp-go-blocks.json gets the defaults', () => {
    assert.deepEqual(readConfig(theme({})), {
      blocks: 'blocks',
      entries: { main: 'src/main.js', editor: 'src/editor.js' },
      bundles: []
    })
  })

  test('trims the blocks folder\'s slashes and merges entries with the defaults key by key', () => {
    const config = readConfig(theme({ 'wp-go-blocks.json': '{"blocks":"/parts/blocks/","entries":{"editor":"src/canvas.js"},"category":"x"}' }))

    assert.equal(config.blocks, 'parts/blocks')
    assert.deepEqual(config.entries, { main: 'src/main.js', editor: 'src/canvas.js' })
    assert.deepEqual(config.bundles, [])
    assert.equal(config.category, 'x', 'the PHP\'s own keys are kept, unread')
  })

  test('a file that isn\'t valid JSON throws, naming the file', () => {
    const root = theme({ 'wp-go-blocks.json': '{ "blocks": "blocks", // a comment\n }' })
    const file = path.join(root, 'wp-go-blocks.json')

    assert.throws(() => readConfig(root), error => {
      assert.match(error.message, /^wp-go-blocks: /)
      assert.ok(error.message.includes(`${file} is not valid JSON`), error.message)
      assert.ok(error.cause instanceof SyntaxError, 'JSON.parse\'s own error as the cause')

      return true
    })
  })

  test('the plugin reads it as it is made: a broken file stops the config', () => {
    assert.throws(() => wpGoBlocks({ themePath: theme({ 'wp-go-blocks.json': 'nope' }) }), /wp-go-blocks\.json is not valid JSON/)
  })
})

describe('the build\'s inputs', () => {
  const input = root => wpGoBlocks({ themePath: root }).config().build.rolldownOptions.input

  test('the entries (a glob\'s files sorted, an empty one skipped), the block folders\' own files, the bundles', () => {
    assert.deepEqual(input(theme(FAKE)), [
      'src/main.js',
      'src/editor.a.js',
      'src/editor.b.js',
      'src/extra.js',
      'blocks/alpha/alpha.scss',
      'blocks/alpha/alpha.js',
      'blocks/beta/beta.scss',
      'blocks/gamma/gamma.js',
      'form/form.js',
      'form/form.scss',
      'only-js/only-js.js'
    ])
  })

  test('a folder without block.json has no entries, nor does a block\'s partial or helper', () => {
    const inputs = input(theme(FAKE))

    assert.ok(!inputs.some(rel => rel.includes('loose')))
    assert.ok(!inputs.some(rel => rel.includes('/_')))
  })

  test('the defaults: src/main.js and src/editor.js as given, whether they exist or not; no blocks folder, no blocks', () => {
    assert.deepEqual(input(theme({})), [ 'src/main.js', 'src/editor.js' ])
  })

  test('a block folder is found in the configured folder', () => {
    const root = theme({
      'wp-go-blocks.json': '{"blocks":"parts/blocks"}',
      'parts/blocks/one/block.json': '{}',
      'parts/blocks/one/one.js': '',
      'blocks/two/block.json': '{}',
      'blocks/two/two.js': ''
    })

    assert.deepEqual(input(root).slice(2), [ 'parts/blocks/one/one.js' ])
  })
})

describe('the virtual modules', () => {
  const plugin = (root, command) => {
    const made = wpGoBlocks({ themePath: root })

    if (command) made.configResolved({ command })

    return made
  }

  test('are resolved to \\0-prefixed ids, and nothing else is', () => {
    const p = plugin(theme(FAKE), 'serve')

    assert.equal(p.resolveId(SHARED), `\0${SHARED}`)
    assert.equal(p.resolveId(STYLES), `\0${STYLES}`)
    assert.equal(p.resolveId(EDITOR), `\0${EDITOR}`)
    assert.equal(p.resolveId('virtual:wp-go-blocks/other'), undefined)
    assert.equal(p.resolveId('/blocks/alpha/alpha.js'), undefined)
    assert.equal(p.load(SHARED), undefined, 'loaded by their resolved id only')
  })

  test('shared: an eager glob of every _*.js under the blocks folder, at any depth', () => {
    assert.equal(plugin(theme(FAKE), 'build').load(`\0${SHARED}`), `export default ${GLOB}`)
  })

  test('shared: the glob of the configured folder', () => {
    const p = plugin(theme({ 'wp-go-blocks.json': '{"blocks":"/parts/blocks/"}' }), 'build')

    assert.equal(p.load(`\0${SHARED}`), 'export default import.meta.glob("/parts/blocks/**/_*.js", { eager: true })\n')
  })

  test('styles: each block\'s _<slug>.scss, sorted; not a partial, not a folder without block.json', () => {
    const code = plugin(theme(FAKE), 'build').load(`\0${STYLES}`)

    assert.equal(code, imports('blocks/alpha/_alpha.scss', 'blocks/beta/_beta.scss'))
  })

  test('editor, in development: the _*.js glob, every block\'s own files and _<slug>.scss sorted, then the bundles', () => {
    assert.equal(plugin(theme(FAKE), 'serve').load(`\0${EDITOR}`), GLOB + imports(
      'blocks/alpha/_alpha.scss',
      'blocks/alpha/alpha.js',
      'blocks/alpha/alpha.scss',
      'blocks/beta/_beta.scss',
      'blocks/beta/beta.scss',
      'blocks/gamma/gamma.js',
      'form/form.js',
      'form/form.scss',
      'only-js/only-js.js'
    ))
  })

  test('editor, in a build: the same without the _<slug>.scss, which the main CSS carries', () => {
    assert.equal(plugin(theme(FAKE), 'build').load(`\0${EDITOR}`), GLOB + imports(
      'blocks/alpha/alpha.js',
      'blocks/alpha/alpha.scss',
      'blocks/beta/beta.scss',
      'blocks/gamma/gamma.js',
      'form/form.js',
      'form/form.scss',
      'only-js/only-js.js'
    ))
  })

  test('editor: nothing of a partial, a helper, or a folder without block.json', () => {
    const code = plugin(theme(FAKE), 'serve').load(`\0${EDITOR}`)

    assert.ok(!code.includes('_partial') && !code.includes('_helper') && !code.includes('loose'), code)
  })

  test('the lists are read when loaded: a file added since is in them', () => {
    const root = theme(FAKE)
    const p = plugin(root, 'serve')

    fs.writeFileSync(path.join(root, 'blocks/gamma/_gamma.scss'), '')

    assert.ok(p.load(`\0${STYLES}`).includes('/blocks/gamma/_gamma.scss'))
    assert.ok(p.load(`\0${EDITOR}`).includes('/blocks/gamma/_gamma.scss'))
  })
})

describe('in development: handleHotUpdate()', () => {
  const reloads = (root, file) => {
    const server = fakeServer()

    wpGoBlocks({ themePath: root }).handleHotUpdate({ file, server })

    return server.sent
  }

  const root = theme(FAKE)

  test('a .scss in the blocks folder, any .php, any .twig reload the page', () => {
    assert.deepEqual(reloads(root, `${root}/blocks/alpha/alpha.scss`), [ RELOAD ])
    assert.deepEqual(reloads(root, `${root}/blocks/alpha/_partial.scss`), [ RELOAD ], 'a partial too')
    assert.deepEqual(reloads(root, `${root}/functions.php`), [ RELOAD ])
    assert.deepEqual(reloads(root, `${root}/views/page.twig`), [ RELOAD ])
  })

  test('a module (a block\'s .js), or a .scss outside the blocks folder, is left to Vite', () => {
    assert.deepEqual(reloads(root, `${root}/blocks/alpha/alpha.js`), [])
    assert.deepEqual(reloads(root, `${root}/src/main.scss`), [])
    assert.deepEqual(reloads(root, `${root}/blocks-old/x.scss`), [], 'a folder whose name only starts like the blocks folder\'s')
  })

  test('the blocks folder is compared normalised: a theme path given with ./, .. or a trailing slash still matches', () => {
    assert.deepEqual(reloads(`${root}/src/../`, `${root}/blocks/beta/beta.scss`), [ RELOAD ])
    assert.deepEqual(reloads(`${root}/./`, `${root}/blocks/beta/beta.scss`), [ RELOAD ])
  })

  test('returns nothing: Vite\'s own handling goes on', () => {
    const server = fakeServer()

    assert.equal(wpGoBlocks({ themePath: root }).handleHotUpdate({ file: `${root}/a.php`, server }), undefined)
  })
})

describe('in development: the watcher', () => {
  const watch = (root, modules) => {
    const server = fakeServer(modules)

    wpGoBlocks({ themePath: root }).configureServer(server)

    return server
  }

  const root = theme(FAKE)
  const at = rel => path.join(root, rel)

  test('a _<slug>.scss added: styles and editor read again, the page reloaded', () => {
    const server = watch(root)

    server.watcher.emit('add', at('blocks/gamma/_gamma.scss'))

    assert.deepEqual(server.invalidated, [ `\0${STYLES}`, `\0${EDITOR}` ])
    assert.deepEqual(server.sent, [ RELOAD ])
  })

  test('a block.json removed: both, as the folder is no longer a block', () => {
    const server = watch(root)

    server.watcher.emit('unlink', at('blocks/alpha/block.json'))

    assert.deepEqual(server.invalidated, [ `\0${STYLES}`, `\0${EDITOR}` ])
    assert.deepEqual(server.sent, [ RELOAD ])
  })

  test('a block\'s own .scss or .js, added or removed: editor only', () => {
    const server = watch(root)

    server.watcher.emit('add', at('blocks/gamma/gamma.scss'))
    server.watcher.emit('unlink', at('blocks/alpha/alpha.js'))

    assert.deepEqual(server.invalidated, [ `\0${EDITOR}`, `\0${EDITOR}` ])
    assert.deepEqual(server.sent, [ RELOAD, RELOAD ])
  })

  test('anything else: nothing (a partial, a file deeper in a block, one outside the blocks folder, a file named after another folder)', () => {
    const server = watch(root)

    server.watcher.emit('add', at('blocks/alpha/_other.scss'))
    server.watcher.emit('add', at('blocks/alpha/sub/_sub.scss'))
    server.watcher.emit('add', at('blocks/_root.scss'))
    server.watcher.emit('add', at('src/main.scss'))
    server.watcher.emit('add', at('blocks/alpha/beta.scss'))
    server.watcher.emit('unlink', at('src/block.json'))

    assert.deepEqual(server.invalidated, [])
    assert.deepEqual(server.sent, [])
  })

  test('a module not loaded yet isn\'t invalidated, and the page still reloads', () => {
    const server = watch(root, [])

    server.watcher.emit('add', at('blocks/gamma/_gamma.scss'))

    assert.deepEqual(server.invalidated, [])
    assert.deepEqual(server.sent, [ RELOAD ])
  })

  test('a change to a file is not the watcher\'s', () => {
    const server = watch(root)

    server.watcher.emit('change', at('blocks/gamma/_gamma.scss'))

    assert.deepEqual(server.sent, [])
  })
})

describe('the small stylesheets\' report: closeBundle()', () => {
  // a built stylesheet of about `size` bytes gzipped: random hex barely compresses
  const css = size => `.x{content:"${Array.from({ length: size }, () => Math.floor(Math.random() * 16).toString(16)).join('')}"}`

  const report = (options, manifest = true, command = 'build') => {
    const root = theme({
      'blocks/alpha/block.json': '{}',
      'blocks/beta/block.json': '{}',
      'blocks/dev-tool/block.json': '{}',
      'blocks/huge/block.json': '{}',
      'blocks/unbuilt/block.json': '{}',
      'dist/a.css': css(100),
      'dist/b.css': css(200),
      'dist/d.css': css(100),
      'dist/h.css': css(4000),
      'dist/.vite/manifest.json': JSON.stringify({
        'blocks/alpha/alpha.scss': { file: 'a.css' },
        'blocks/beta/beta.scss': { file: 'b.css' },
        'blocks/dev-tool/dev-tool.scss': { file: 'd.css' },
        'blocks/huge/huge.scss': { file: 'h.css' }
      })
    })
    const warned = []
    const p = wpGoBlocks({ themePath: root, ...options })

    p.configResolved({ command, root, build: { outDir: 'dist', manifest }, logger: { warn: message => warned.push(message) } })
    p.closeBundle()

    return warned
  }

  test('lists the block stylesheets under smallStyles gzipped, leaving out keepStyles and what the manifest lacks', () => {
    const [ message, ...more ] = report({ keepStyles: [ /^dev-/ ] })

    assert.equal(more.length, 0)
    assert.match(message, /wp-go-blocks: 2 block stylesheets under 1024 B gzipped/)
    assert.match(message, /Rename blocks\/<slug>\/<slug>\.scss to _<slug>\.scss/)
    assert.match(message, /\n {2}alpha {2}\d+ B\n {2}beta {3}\d+ B$/)
    assert.ok(!message.includes('dev-tool') && !message.includes('huge') && !message.includes('unbuilt'))
  })

  test('keepStyles by slug as well', () => {
    const [ message ] = report({ keepStyles: [ 'alpha', /^dev-/ ] })

    assert.match(message, /1 block stylesheet under/)
    assert.ok(!message.includes('alpha'))
  })

  test('nothing with smallStyles 0, in development, or without a manifest', () => {
    assert.deepEqual(report({ smallStyles: 0 }), [])
    assert.deepEqual(report({}, true, 'serve'), [])
    assert.deepEqual(report({}, false), [])
  })
})
