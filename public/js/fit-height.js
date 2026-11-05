// imgsli fit-height — make <image-compare> fill the height of its fixed-height
// container: sets --image-compare-max-height (container content height minus the
// component toolbar) so a large picture uses the full available height and a
// small one stays at its natural size. Loaded after image-compare.js on every
// page (the compare page creates the element dynamically; the album page's is
// server-rendered).
(() => {
  const sync = (host) => {
    const parent = host.parentElement;
    if (!parent || !parent.clientHeight) return;
    const cs = getComputedStyle(parent);
    const toolbar = !host.hasAttribute('no-toolbar') && host.shadowRoot
      ? host.shadowRoot.querySelector('.toolbar')
      : null;
    const h = parent.clientHeight
      - parseFloat(cs.paddingTop || '0')
      - parseFloat(cs.paddingBottom || '0')
      - (toolbar ? toolbar.offsetHeight : 0);
    if (h > 0) host.style.setProperty('--image-compare-max-height', `${h}px`);
  };

  const watch = (host) => {
    if (host.dataset.fitHeight) return;
    host.dataset.fitHeight = '1';
    // Re-sync when the container or the toolbar (which can wrap) resizes.
    const ro = new ResizeObserver(() => sync(host));
    ro.observe(host.parentElement ?? document.body);
    const toolbar = host.shadowRoot?.querySelector('.toolbar');
    if (toolbar) ro.observe(toolbar);
    sync(host);
  };

  const scan = (root) => {
    root.querySelectorAll('image-compare').forEach(watch);
  };

  scan(document);
  new MutationObserver((muts) => {
    for (const m of muts) {
      for (const node of m.addedNodes) {
        if (!(node instanceof Element)) continue;
        if (node.tagName === 'IMAGE-COMPARE') watch(node);
        else scan(node);
      }
    }
  }).observe(document.body, { childList: true, subtree: true });
})();
