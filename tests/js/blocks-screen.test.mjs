// Appearance → Blocks' script (assets/blocks-screen.js), in jsdom: a row
// opening its block's dialog filled from its data-block, the backdrop, Cancel,
// "As in block.json", "Remove image", and "Choose image" with the media
// library, which is faked (wp.media, wp.Uploader): the frame made once, its
// options, the block's name sent with each upload, the dialog stepping aside
// while the frame is open, and the pick written into the form.
//
// The markup is settings_screen()'s (src/Core/block-settings-screen.php),
// cut to what the script reads.

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { afterEach, describe, test } from 'node:test'

import { JSDOM } from 'jsdom'

const script = readFileSync(new URL('../../assets/blocks-screen.js', import.meta.url), 'utf8')

const PLAIN = { name: 'acf/plain', title: 'Cifre', default: 'Plain', image: 12, src: 'https://site.test/plain-1024x576.png', types: ['page'], json: ['post', 'page'] }
const BARE = { name: 'acf/bare', title: '', default: 'Bare', image: 0, src: '', types: ['post', 'page', 'event'], json: ['post', 'page', 'event'] }

const row = block => `
  <tr class="wg-blocks__row" data-block='${JSON.stringify(block)}'>
    <td><strong>${block.title || block.default}</strong></td>
    <td class="wg-blocks__actions"><button type="button" class="button-link wg-blocks__edit"><span class="dashicons dashicons-edit"></span></button></td>
  </tr>`

const markup = `
  <div class="wrap wg-blocks">
    <table class="widefat striped wg-blocks__table">
      <thead><tr><th>Name</th></tr></thead>
      <tbody>${row(PLAIN)}${row(BARE)}</tbody>
    </table>
    <dialog class="wg-blocks__modal">
      <form method="post" action="/wp/wp-admin/admin-post.php">
        <input type="hidden" name="action" value="wp_go_blocks_settings_save">
        <input type="hidden" name="block" value="">
        <input type="hidden" name="image" value="" class="wg-blocks__chosen">
        <p class="description wg-blocks__name"><code></code></p>
        <input type="text" id="wg-blocks-title" name="title">
        <img class="wg-blocks__image" alt="" hidden>
        <span class="wg-blocks__none">No preview</span>
        <button type="button" class="button wg-blocks__choose">Choose image</button>
        <button type="button" class="button-link wg-blocks__clear">Remove image</button>
        <div class="wg-blocks__types">
          <label><input type="checkbox" name="post_types[]" value="post"> Posts</label>
          <label><input type="checkbox" name="post_types[]" value="page"> Pages</label>
          <label><input type="checkbox" name="post_types[]" value="event"> Events</label>
        </div>
        <button type="button" class="button-link wg-blocks__reset-types">As in block.json</button>
        <button type="button" class="button wg-blocks__cancel">Cancel</button>
        <button type="submit" class="button button-primary">Save</button>
      </form>
    </dialog>
  </div>`

const windows = []

afterEach(() => windows.splice(0).forEach(w => w.close()))

// The screen, its script run, the media library faked. `uploader`: whether
// the frame has its uploader yet (WordPress makes it on the frame's first
// render), so both ways of sending the block's name are seen.
const page = ({ uploader = true } = {}) => {
  const { window: w } = new JSDOM(`<!doctype html><body>${markup}</body>`, { runScripts: 'outside-only', url: 'https://site.test/wp/wp-admin/themes.php?page=wp-go-blocks' })
  windows.push(w)

  // jsdom has <dialog> but neither showModal() nor close()
  const shown = []
  w.HTMLDialogElement.prototype.showModal = function () {
    this.setAttribute('open', '')
    shown.push(this)
  }
  w.HTMLDialogElement.prototype.close = function () {
    this.removeAttribute('open')
  }

  const media = { calls: [], frames: [] }

  w.wp = {
    Uploader: { queue: { the: 'upload queue' } },
    media (options) {
      const handlers = {}
      const frame = {
        options,
        opened: 0,
        observed: [],
        picked: null,
        on (event, fn) {
          (handlers[event] ??= []).push(fn)
        },
        trigger (event) {
          (handlers[event] ?? []).forEach(fn => fn())
        },
        open () {
          frame.opened++
        },
        state (name) {
          if (name === 'library') {
            return { get: key => (key === 'library' ? { observe: queue => frame.observed.push(queue) } : null) }
          }

          return { get: key => (key === 'selection' ? { first: () => ({ toJSON: () => frame.picked }) } : null) }
        },
        uploader: uploader
          ? { options: { uploader: { params: {} } }, uploader: { sent: {}, param (key, value) { this.sent[key] = value } } }
          : null
      }

      media.calls.push(options)
      media.frames.push(frame)

      return frame
    }
  }
  w.wpGoBlocksScreen = {
    param: 'wp_go_block_preview',
    types: ['image/jpeg', 'image/png'],
    labels: { pick: 'Block preview', button: 'Use this image' }
  }

  w.eval(script)

  const $ = selector => w.document.querySelector(selector)
  const dialog = $('.wg-blocks__modal')
  const form = dialog.querySelector('form')
  const click = el => el.dispatchEvent(new w.MouseEvent('click', { bubbles: true }))

  return {
    w,
    $,
    dialog,
    form,
    media,
    shown,
    click,
    rows: [...w.document.querySelectorAll('.wg-blocks__row')],
    isOpen: () => dialog.hasAttribute('open'),
    ticked: () => [...form.querySelectorAll('[name="post_types[]"]')].filter(box => box.checked).map(box => box.value)
  }
}

describe('a row opens its dialog', () => {
  test('filled from its data-block: name, title, placeholder, image, ticks', () => {
    const p = page()

    p.click(p.rows[0].querySelector('strong'))

    assert.equal(p.isOpen(), true, 'a click anywhere on the row opens the dialog, as a modal')
    assert.equal(p.form.elements.block.value, 'acf/plain')
    assert.equal(p.$('.wg-blocks__name code').textContent, 'acf/plain')
    assert.equal(p.form.elements.title.value, 'Cifre')
    assert.equal(p.form.elements.title.placeholder, 'Plain', 'block.json\'s title, greyed out')
    assert.equal(p.$('.wg-blocks__chosen').value, '12')
    assert.equal(p.$('.wg-blocks__image').hidden, false)
    assert.equal(p.$('.wg-blocks__image').src, PLAIN.src)
    assert.equal(p.$('.wg-blocks__none').hidden, true)
    assert.equal(p.$('.wg-blocks__clear').hidden, false, 'with an image, Remove image is offered')
    assert.deepEqual(p.ticked(), ['page'])
    assert.equal(p.w.document.activeElement, p.form.elements.title, 'the name field has the focus')
  })

  test('its pencil opens it too; a block with no image shows none, and no Remove image', () => {
    const p = page()

    p.click(p.rows[1].querySelector('.dashicons-edit'))

    assert.equal(p.isOpen(), true)
    assert.equal(p.form.elements.block.value, 'acf/bare')
    assert.equal(p.form.elements.title.value, '')
    assert.equal(p.$('.wg-blocks__chosen').value, '')
    assert.equal(p.$('.wg-blocks__image').hidden, true)
    assert.equal(p.$('.wg-blocks__none').hidden, false)
    assert.equal(p.$('.wg-blocks__clear').hidden, true)
    assert.deepEqual(p.ticked(), ['post', 'page', 'event'])
  })

  test('one row after another: the second\'s values replace the first\'s', () => {
    const p = page()

    p.click(p.rows[0])
    p.click(p.$('.wg-blocks__cancel'))
    p.click(p.rows[1])

    assert.equal(p.form.elements.title.value, '')
    assert.equal(p.$('.wg-blocks__chosen').value, '')
    assert.deepEqual(p.ticked(), ['post', 'page', 'event'])
  })

  test('a click in the table outside any row opens nothing', () => {
    const p = page()

    p.click(p.$('thead th'))

    assert.equal(p.isOpen(), false)
  })
})

describe('in the dialog', () => {
  test('As in block.json ticks block.json\'s post types again', () => {
    const p = page()

    p.click(p.rows[0])
    p.click(p.$('.wg-blocks__reset-types'))

    assert.deepEqual(p.ticked(), ['post', 'page'])
  })

  test('Remove image empties the image field and shows none', () => {
    const p = page()

    p.click(p.rows[0])
    p.click(p.$('.wg-blocks__clear'))

    assert.equal(p.$('.wg-blocks__chosen').value, '')
    assert.equal(p.$('.wg-blocks__image').hidden, true)
    assert.equal(p.$('.wg-blocks__none').hidden, false)
    assert.equal(p.$('.wg-blocks__clear').hidden, true)
  })

  test('Cancel closes it', () => {
    const p = page()

    p.click(p.rows[0])
    p.click(p.$('.wg-blocks__cancel'))

    assert.equal(p.isOpen(), false)
  })

  test('the backdrop closes it: pressed and released on the dialog itself', () => {
    const p = page()

    p.click(p.rows[0])
    p.dialog.dispatchEvent(new p.w.MouseEvent('mousedown', { bubbles: true }))
    p.click(p.dialog)

    assert.equal(p.isOpen(), false)
  })

  test('but not a press in a field released outside it (selecting text)', () => {
    const p = page()

    p.click(p.rows[0])
    p.form.elements.title.dispatchEvent(new p.w.MouseEvent('mousedown', { bubbles: true }))
    p.click(p.dialog)

    assert.equal(p.isOpen(), true)
  })

  test('nor a click inside it', () => {
    const p = page()

    p.click(p.rows[0])
    p.form.elements.title.dispatchEvent(new p.w.MouseEvent('mousedown', { bubbles: true }))
    p.click(p.form.elements.title)

    assert.equal(p.isOpen(), true)
  })
})

describe('Choose image', () => {
  test('opens the media library: previews only, raster types, one image', () => {
    const p = page()

    p.click(p.rows[0])
    p.click(p.$('.wg-blocks__choose'))

    assert.equal(p.media.calls.length, 1)
    assert.deepEqual(JSON.parse(JSON.stringify(p.media.calls[0])), {
      title: 'Block preview',
      button: { text: 'Use this image' },
      library: { type: ['image/jpeg', 'image/png'], wp_go_block_preview: 1 },
      multiple: false
    })
    assert.deepEqual(p.media.frames[0].observed, [p.w.wp.Uploader.queue], 'the library watches the uploads again')
  })

  test('the dialog steps aside while the frame is open, and comes back as it closes', () => {
    const p = page()

    p.click(p.rows[0])
    p.click(p.$('.wg-blocks__choose'))

    const frame = p.media.frames[0]

    assert.equal(p.isOpen(), false, 'closed, under the media library\'s modal')
    assert.equal(frame.opened, 1)

    frame.trigger('close')

    assert.equal(p.isOpen(), true, 'shown again, picked or not')
  })

  test('the frame is made once, and each upload carries the block that opened it this time', () => {
    const p = page()

    p.click(p.rows[0])
    p.click(p.$('.wg-blocks__choose'))

    const frame = p.media.frames[0]

    assert.equal(frame.uploader.options.uploader.params.wp_go_block_preview, 'acf/plain', 'in the options, for the uploader not made yet')
    assert.equal(frame.uploader.uploader.sent.wp_go_block_preview, 'acf/plain', 'and on the uploader already made')

    frame.trigger('close')
    p.click(p.$('.wg-blocks__cancel'))
    p.click(p.rows[1])
    p.click(p.$('.wg-blocks__choose'))

    assert.equal(p.media.calls.length, 1, 'the same frame, not a second')
    assert.equal(frame.opened, 2)
    assert.equal(frame.uploader.options.uploader.params.wp_go_block_preview, 'acf/bare')
    assert.equal(frame.uploader.uploader.sent.wp_go_block_preview, 'acf/bare')
  })

  test('a frame with no uploader yet opens all the same', () => {
    const p = page({ uploader: false })

    p.click(p.rows[0])
    p.click(p.$('.wg-blocks__choose'))

    assert.equal(p.media.frames[0].opened, 1)
  })

  test('the pick goes into the form: its ID, its large size shown', () => {
    const p = page()

    p.click(p.rows[1])
    p.click(p.$('.wg-blocks__choose'))

    const frame = p.media.frames[0]

    frame.picked = { id: 77, url: 'https://site.test/full.png', sizes: { large: { url: 'https://site.test/large.png' } } }
    frame.trigger('select')
    frame.trigger('close')

    assert.equal(p.$('.wg-blocks__chosen').value, '77')
    assert.equal(p.$('.wg-blocks__image').src, 'https://site.test/large.png')
    assert.equal(p.$('.wg-blocks__image').hidden, false)
    assert.equal(p.$('.wg-blocks__none').hidden, true)
    assert.equal(p.$('.wg-blocks__clear').hidden, false, 'Remove image offered now')
    assert.equal(p.isOpen(), true)
  })

  test('an image with no large size is shown at its own URL', () => {
    const p = page()

    p.click(p.rows[1])
    p.click(p.$('.wg-blocks__choose'))

    const frame = p.media.frames[0]

    frame.picked = { id: 78, url: 'https://site.test/small.png', sizes: {} }
    frame.trigger('select')

    assert.equal(p.$('.wg-blocks__chosen').value, '78')
    assert.equal(p.$('.wg-blocks__image').src, 'https://site.test/small.png')
  })
})
