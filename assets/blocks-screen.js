// Appearance → Blocks: a row opens its block's dialog, and the dialog's
// "Choose image" the media library (src/Core/block-settings-screen.php).
// `wpGoBlocksScreen` is printed before it: the labels, the request field the
// picker sends (`param`) and the image types a preview may be (`types`).
(() => {
  const { labels, param, types } = wpGoBlocksScreen;
  const screen = document.querySelector('.wg-blocks');
  const dialog = screen.querySelector('.wg-blocks__modal');
  const form = dialog.querySelector('form');
  const title = form.elements.title;
  const chosen = form.querySelector('.wg-blocks__chosen');
  let block = null;
  let frame = null;

  // the post types ticked: these, the rest not
  const tick = (names) => {
    form.querySelectorAll('[name="post_types[]"]').forEach((box) => {
      box.checked = names.includes(box.value);
    });
  };

  // the preview as it stands: the pick, or none
  const show = (src) => {
    const image = form.querySelector('.wg-blocks__image');

    image.hidden = !src;
    image.src = src || '';
    form.querySelector('.wg-blocks__none').hidden = Boolean(src);
    form.querySelector('.wg-blocks__clear').hidden = !chosen.value;
  };

  const open = (row) => {
    block = JSON.parse(row.dataset.block);
    form.elements.block.value = block.name;
    form.querySelector('.wg-blocks__name code').textContent = block.name;
    title.value = block.title;
    title.placeholder = block.default;
    chosen.value = block.image || '';
    show(block.src);
    tick(block.types);
    dialog.showModal();
    title.focus();
  };

  // a row clicked anywhere, or its pencil: its editor
  screen.querySelector('.wg-blocks__table tbody').addEventListener('click', (event) => {
    const row = event.target.closest('.wg-blocks__row');

    if (row) {
      open(row);
    }
  });

  // closes on a click outside it, on its backdrop (the dialog itself, as
  // its content fills it): only one both pressed and released there
  let pressed = null;

  dialog.addEventListener('mousedown', (event) => {
    pressed = event.target;
  });
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog && pressed === dialog) {
      dialog.close();
    }
  });
  form.querySelector('.wg-blocks__cancel').addEventListener('click', () => dialog.close());
  form.querySelector('.wg-blocks__reset-types').addEventListener('click', () => tick(block.json));

  // The media library opens in its own modal, which a modal <dialog>
  // (the top layer) would cover: the dialog steps aside while it is open
  // and comes back as it closes, picked or not.
  //
  // It lists block previews only, raster ones (an SVG has no size for the
  // inserter's panel), and an upload is sent with the block's name, which the
  // server renames, titles and marks it by: before the frame's uploader
  // exists (its options) and after (the uploader itself).
  form.querySelector('.wg-blocks__choose').addEventListener('click', () => {
    if (!frame) {
      frame = wp.media({ title: labels.pick, button: { text: labels.button }, library: { type: types, [param]: 1 }, multiple: false });

      // A library queried with a key WordPress doesn't know stops
      // watching uploads, so one made here would neither be listed nor
      // let the button enable once done. Every upload from this frame is
      // a preview: the library watches them again.
      frame.state('library').get('library').observe(wp.Uploader.queue);

      // Bound once, beside WordPress's own handlers (its close puts
      // Thickbox back): `block` is whichever dialog opened it this time.
      frame.on('select', () => {
        const picked = frame.state().get('selection').first().toJSON();
        chosen.value = picked.id;
        show(picked.sizes?.large?.url || picked.url);
      });
      frame.on('close', () => dialog.showModal());
    }

    if (frame.uploader) {
      frame.uploader.options.uploader.params[param] = block.name;
      frame.uploader.uploader?.param(param, block.name);
    }
    dialog.close();
    frame.open();
  });

  form.querySelector('.wg-blocks__clear').addEventListener('click', () => {
    chosen.value = '';
    show('');
  });
})();
