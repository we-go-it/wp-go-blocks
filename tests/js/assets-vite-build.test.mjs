// The Vite plugin in a real build, of a tiny fake theme made in a temp
// folder, into a temp out dir: the manifest has the inputs it added, keyed by
// source as the PHP resolver looks them up; the virtual modules resolve and
// put what they list where the docs say; the small stylesheets' report runs
// on the manifest the build wrote. Never the theme's own build: slow, and it
// writes the theme's dist.

import assert from 'node:assert/strict'
import fs from 'node:fs'
import os from 'node:os'
import path from 'node:path'
import { after, before, describe, test } from 'node:test'

import { build } from 'vite'

import wpGoBlocks from '../../vite/index.js'

const root = fs.mkdtempSync(path.join(os.tmpdir(), 'wp-go-blocks-assets-build-'))
const outDir = path.join(root, 'dist')

const files = {
  'wp-go-blocks.json': JSON.stringify({ blocks: 'blocks', bundles: [ 'form/form' ] }),
  'src/main.scss': '.main-marker { color: red }',
  'src/main.js': [
    'import "./main.scss"',
    'import "virtual:wp-go-blocks/styles"',
    'import shared from "virtual:wp-go-blocks/shared"',
    'window.sharedPaths = Object.keys(shared)',
    ''
  ].join('\n'),
  'src/editor.js': 'import "virtual:wp-go-blocks/editor"\n',
  'blocks/_root.js': 'export default () => "root-shared-marker"\n',
  'blocks/alpha/block.json': '{}',
  'blocks/alpha/alpha.scss': '.alpha-own-marker { color: red }',
  'blocks/alpha/alpha.js': 'window.alphaMarker = "alpha-js-marker"\n',
  'blocks/alpha/_alpha.scss': '.alpha-main-marker { color: red }',
  'blocks/alpha/_helper.js': 'export default () => "alpha-helper-marker"\n',
  'blocks/beta/block.json': '{}',
  'blocks/beta/beta.scss': `.beta-own-marker { content: "${'b'.repeat(64)}" }`,
  'blocks/loose/loose.scss': '.loose-marker { color: red }',
  'form/form.js': 'window.formMarker = "form-js-marker"\n',
  'form/form.scss': '.form-marker { color: red }'
}

for (const [ rel, content ] of Object.entries(files)) {
  fs.mkdirSync(path.dirname(path.join(root, rel)), { recursive: true })
  fs.writeFileSync(path.join(root, rel), content)
}

after(() => fs.rmSync(root, { recursive: true, force: true }))

let manifest
const warnings = []
const built = file => fs.readFileSync(path.join(outDir, file), 'utf8')

before(async () => {
  await build({
    root,
    configFile: false,
    logLevel: 'warn',
    customLogger: {
      info () {},
      warn: message => warnings.push(message),
      warnOnce: message => warnings.push(message),
      error: message => warnings.push(message),
      clearScreen () {},
      hasErrorLogged: () => false,
      hasWarned: false
    },
    build: { outDir, emptyOutDir: true, manifest: true, minify: false },
    plugins: [ wpGoBlocks({ themePath: root }) ]
  })

  manifest = JSON.parse(fs.readFileSync(path.join(outDir, '.vite/manifest.json'), 'utf8'))
})

describe('a build of the fake theme', () => {
  test('every input the plugin added is an entry in the manifest, keyed by its source', () => {
    for (const source of [ 'src/main.js', 'src/editor.js', 'blocks/alpha/alpha.scss', 'blocks/alpha/alpha.js', 'blocks/beta/beta.scss', 'form/form.js', 'form/form.scss' ]) {
      assert.ok(manifest[source]?.isEntry, `${source} is an entry`)
    }
  })

  test('nothing of a folder without block.json, nor a partial, is an entry', () => {
    const entries = Object.keys(manifest).filter(key => manifest[key].isEntry)

    assert.ok(!entries.some(key => key.includes('loose') || key.includes('/_')), entries.join(', '))
  })

  test('a block\'s own stylesheet is a file of its own, not in the main CSS', () => {
    assert.match(built(manifest['blocks/alpha/alpha.scss'].file), /alpha-own-marker/)

    const mainCss = (manifest['src/main.js'].css ?? []).map(built).join('')

    assert.match(mainCss, /main-marker/)
    assert.ok(!mainCss.includes('alpha-own-marker'))
  })

  test('styles: the block\'s _<slug>.scss lands in the main CSS, after the main stylesheet', () => {
    const mainCss = (manifest['src/main.js'].css ?? []).map(built).join('')

    assert.ok(mainCss.indexOf('alpha-main-marker') > mainCss.indexOf('main-marker'), mainCss)
  })

  test('shared: every _*.js under the blocks folder is in the main bundle, by root-relative path', () => {
    const mainJs = built(manifest['src/main.js'].file) + (manifest['src/main.js'].imports ?? []).map(key => built(manifest[key].file)).join('')

    assert.match(mainJs, /root-shared-marker/)
    assert.match(mainJs, /alpha-helper-marker/)
    assert.match(mainJs, /\/blocks\/_root\.js/)
    assert.match(mainJs, /\/blocks\/alpha\/_helper\.js/)
  })

  test('editor: the editor entry pulls in every block\'s files and the bundles\', but not the _<slug>.scss in a build', () => {
    const chunks = new Set()
    const walk = key => {
      if (chunks.has(key)) return
      chunks.add(key)
      for (const next of manifest[key]?.imports ?? []) walk(next)
    }
    walk('src/editor.js')

    const js = [ ...chunks ].map(key => built(manifest[key].file)).join('')
    const css = [ ...chunks ].flatMap(key => manifest[key].css ?? []).map(built).join('')

    assert.match(js, /alpha-js-marker/)
    assert.match(js, /form-js-marker/)
    assert.match(css, /alpha-own-marker/)
    assert.match(css, /beta-own-marker/)
    assert.match(css, /form-marker/)
    assert.ok(!css.includes('alpha-main-marker'), 'the _<slug>.scss stays the main CSS\'s')
    assert.ok(!css.includes('loose-marker'))
  })

  test('the report lists the small block stylesheets from the manifest the build wrote', () => {
    const report = warnings.find(message => message.includes('wp-go-blocks:'))

    assert.ok(report, warnings.join('\n'))
    assert.match(report, /2 block stylesheets under 1024 B gzipped/)
    assert.match(report, /\n {2}alpha {2}\d+ B/)
    assert.match(report, /\n {2}beta {3}\d+ B/)
  })
})
