// The inserter's preview images (assets/inserter-previews.js), in jsdom: the
// image painted into WordPress's "No preview available" box, found by the
// block card's title; the panel followed as React swaps its title text from
// one hovered block to the next, and as it comes and goes; the style cleared
// for a block with no image; and text changed elsewhere on the page left
// unwatched. wp.blocks.getBlockTypes() is faked: the titles as registered,
// the Blocks screen's names included.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { afterEach, describe, test } from 'node:test'

import { JSDOM } from 'jsdom'

const script = readFileSync(new URL('../../assets/inserter-previews.js', import.meta.url), 'utf8')

const IMAGES = {
  'acf/hero': { src: 'https://site.test/hero-1024x576.png', ratio: '1024/576' },
  'acf/numbers': { src: 'https://site.test/numbers-600x800.png', ratio: '600/800' }
}

const TYPES = [
  { name: 'core/paragraph', title: 'Paragraph' },
  { name: 'acf/hero', title: 'Hero' },
  // the Blocks screen's name, as the block registered with it
  { name: 'acf/numbers', title: 'Cifre' },
  { name: 'acf/plain', title: 'Plain' }
]

const windows = []

afterEach(() => windows.splice(0).forEach(w => w.close()))

// MutationObserver's callbacks run as microtasks: a macrotask later they have
const settle = () => new Promise(resolve => setTimeout(resolve, 0))

// The inserter's preview panel, as the block editor renders it for a block
// with no `example`: the "No preview available" box, then the block's card.
const panelHtml = (title, { missing = true } = {}) => `
  <div class="block-editor-inserter__preview-container">
    <div class="block-editor-inserter__preview">
      ${missing
        ? '<div class="block-editor-inserter__preview-content-missing">No preview available.</div>'
        : '<div class="block-editor-inserter__preview-content"><iframe></iframe></div>'}
    </div>
    <div class="block-editor-block-card"><div class="block-editor-block-card__content"><h2 class="block-editor-block-card__title">${title}</h2></div></div>
  </div>`

const page = ({ images = IMAGES, types = TYPES } = {}) => {
  const { window: w } = new JSDOM('<!doctype html><body><div class="interface-interface-skeleton"><p class="elsewhere">Typing</p><div class="popover-slot"></div></div></body>', { runScripts: 'outside-only' })
  windows.push(w)

  let lookups = 0

  w.wp = {
    blocks: {
      getBlockTypes () {
        lookups++

        return types
      }
    }
  }
  w.wpGoBlocksPreviews = images
  w.eval(script)

  const $ = selector => w.document.querySelector(selector)

  return {
    w,
    $,
    lookups: () => lookups,
    // the panel shown, for a hovered block
    show (title, options) {
      $('.popover-slot').insertAdjacentHTML('beforeend', panelHtml(title, options))

      return settle()
    },
    hide () {
      $('.block-editor-inserter__preview-container')?.remove()

      return settle()
    },
    // the next block hovered: React sets the title's text node, nothing else
    hover (title) {
      $('.block-editor-block-card__title').firstChild.data = title

      return settle()
    },
    missing: () => $('.block-editor-inserter__preview-content-missing')
  }
}

const painted = (el, image) => {
  assert.equal(el.style.backgroundImage, `url("${image.src}")`)
  assert.equal(el.style.backgroundSize, 'cover')
  assert.equal(el.style.aspectRatio, image.ratio)
  assert.ok(['0', '0px'].includes(el.style.minHeight), `min-height 0, got ${el.style.minHeight}`)
  assert.equal(el.style.color, 'transparent')
}

const bare = el => {
  assert.equal(el.style.backgroundImage, '')
  assert.equal(el.style.backgroundSize, '')
  assert.equal(el.style.aspectRatio || '', '')
  assert.equal(el.style.minHeight, '')
  assert.equal(el.style.color, '')
}

describe('the image in the "No preview available" box', () => {
  test('a block with an image: painted by its title as the panel appears', async () => {
    const p = page()

    await p.show('Hero')

    painted(p.missing(), IMAGES['acf/hero'])
    assert.equal(p.missing().textContent, 'No preview available.', 'the text React put there is left, only made transparent')
  })

  test('found by the title the block registered with, the Blocks screen\'s name', async () => {
    const p = page()

    await p.show('Cifre')

    painted(p.missing(), IMAGES['acf/numbers'])
  })

  test('the title as the card shows it, whitespace around it ignored', async () => {
    const p = page()

    await p.show('\n   Hero  \n')

    painted(p.missing(), IMAGES['acf/hero'])
  })

  test('a block without an image keeps the message as it is', async () => {
    const p = page()

    await p.show('Plain')

    bare(p.missing())
  })

  test('a block with a live preview (no missing box): nothing is touched, nothing breaks', async () => {
    const p = page()

    await p.show('Hero', { missing: false })

    assert.equal(p.missing(), null)
    assert.equal(p.$('.block-editor-inserter__preview-content').getAttribute('style'), null)
  })
})

describe('the panel followed', () => {
  test('hovering the next block (only the title\'s text changes) paints its image', async () => {
    const p = page()

    await p.show('Plain')
    bare(p.missing())

    await p.hover('Hero')
    painted(p.missing(), IMAGES['acf/hero'])

    await p.hover('Cifre')
    painted(p.missing(), IMAGES['acf/numbers'])
  })

  test('from a block with an image to one without, the style is cleared, not left as the last one\'s', async () => {
    const p = page()

    await p.show('Hero')
    await p.hover('Paragraph')

    bare(p.missing())
  })

  test('the panel gone and back (the inserter closed and opened): the new one is followed', async () => {
    const p = page()

    await p.show('Hero')
    await p.hide()
    await p.show('Plain')
    bare(p.missing())

    await p.hover('Cifre')
    painted(p.missing(), IMAGES['acf/numbers'])
  })

  test('text changed elsewhere on the page doesn\'t repaint: text is watched in the panel alone', async () => {
    const p = page()

    await p.show('Hero')
    // wiped by hand: a repaint would put it back
    p.missing().style.backgroundImage = ''

    p.$('.elsewhere').firstChild.data = 'Typing in the canvas'
    await settle()
    assert.equal(p.missing().style.backgroundImage, '', 'not repainted for text outside the panel')

    await p.hover('Hero')
    assert.equal(p.missing().style.backgroundImage, `url("${IMAGES['acf/hero'].src}")`, 'repainted for text inside it')
  })

  test('the block types are read once, on the first paint, not before', async () => {
    const p = page()

    assert.equal(p.lookups(), 0, 'nothing read before a panel shows')

    await p.show('Hero')
    await p.hover('Plain')
    await p.hover('Cifre')

    assert.equal(p.lookups(), 1)
  })

  test('two blocks with one title and an image each: the one registered last wins, for both', async () => {
    const p = page({
      images: { 'acf/a': { src: 'https://site.test/a.png', ratio: '1/1' }, 'acf/b': { src: 'https://site.test/b.png', ratio: '2/1' } },
      types: [{ name: 'acf/a', title: 'Twin' }, { name: 'acf/b', title: 'Twin' }]
    })

    await p.show('Twin')

    assert.equal(p.missing().style.backgroundImage, 'url("https://site.test/b.png")')
  })
})
