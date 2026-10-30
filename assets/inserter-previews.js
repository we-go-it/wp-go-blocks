// A block's preview image, painted into the inserter's "No preview available"
// panel (src/Core/block-settings-screen.php). `wpGoBlocksPreviews` is printed
// before it: block name => { src, ratio }.
(() => {
  const images = wpGoBlocksPreviews;
  let byTitle = null;
  let panel = null;

  // React owns the panel and reuses it from one hovered block to the next, so
  // only its style is touched (React sets none there), and it is set again,
  // or cleared, on every change.
  const paint = () => {
    const missing = panel?.querySelector('.block-editor-inserter__preview-content-missing');

    if (!missing) {
      return;
    }

    byTitle ??= Object.fromEntries(
      wp.blocks.getBlockTypes().filter((type) => images[type.name]).map((type) => [type.title, images[type.name]])
    );

    const title = panel.querySelector('.block-editor-block-card__title')?.textContent.trim();
    const image = byTitle[title];

    Object.assign(missing.style, image
      ? { backgroundImage: `url("${image.src}")`, backgroundSize: 'cover', aspectRatio: image.ratio, minHeight: '0', color: 'transparent' }
      : { backgroundImage: '', backgroundSize: '', aspectRatio: '', minHeight: '', color: '' });
  };

  // Hovering from one block to the next changes only the panel's text (React
  // sets a lone text node's value): text changes are watched there alone,
  // and the page only for the panel coming and going.
  const inPanel = new MutationObserver(paint);

  new MutationObserver(() => {
    const current = document.querySelector('.block-editor-inserter__preview-container');

    if (current === panel) {
      return;
    }

    inPanel.disconnect();
    panel = current;

    if (panel) {
      inPanel.observe(panel, { childList: true, subtree: true, characterData: true });
      paint();
    }
  }).observe(document.body, { childList: true, subtree: true });
})();
