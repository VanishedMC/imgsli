/**
 * <image-compare> — before/after image comparison, web component.
 *
 * Shadow DOM, no dependencies, no build step. No imports/exports — the file
 * registers the element itself, so it works as a classic script (including
 * straight from file://) or inside a module:
 *
 *   <script src="image-compare.js"></script>
 *
 *   <image-compare
 *     title="ReShade test"
 *     before="before.jpg"
 *     after="after.jpg"
 *     before-label="OFF"
 *     after-label="ON"></image-compare>
 *
 * Multi-image (3+): the captions become pickers for choosing the left and
 * right image.
 *
 *   <image-compare
 *     images='[{"url":"a.jpg","description":"week 0"},
 *              {"url":"b.jpg","description":"week 2"},
 *              {"url":"c.jpg","description":"week 4"}]'></image-compare>
 *
 * Attributes:
 *   title            caption bar text (bottom-left)
 *   before, after    image URLs (two-image mode; ignored when `images` is set)
 *   before-label,    captions for two-image mode (default "Before"/"After")
 *   after-label
 *   images           JSON array [{url, description?}]; 2 images → captions,
 *                    3+ → pickers
 *   initial-before   initial left image index (default 0)
 *   initial-after    initial right image index (default 1)
 *   mode             slider | blink | side | diff              (default slider)
 *   layout           left-right | top-bottom (divider direction in slider mode)
 *   position         divider position, 0..1, in image coordinates: the divider
 *                    stays on the same spot of the picture while zooming/panning
 *   amplify          difference amplification, 1..20          (default 5)
 *   album-url        album path (e.g. /a/01abc…). Enables "Copy link" and keeps
 *                    the address bar at {album-url}/{left}/{right}#view-state
 *                    (one element per page).
 *   no-toolbar       hide the toolbar
 *
 * Properties: images (parsed list, read-only), beforeIndex, afterIndex, mode,
 *   layout, position, amplify. Methods: enterFullscreen(), exitFullscreen(),
 *   swapImages(), copyLink(), downloadView(), downloadImage('before' | 'after').
 * Event: "image-compare-change" (bubbles) with detail {beforeIndex, afterIndex,
 *   mode, layout, position, zoom, x, y} after user changes.
 * Styling: --image-compare-max-height (default calc(100vh - 100px)),
 *   --image-compare-accent, --image-compare-background; parts: toolbar, box,
 *   divider, handle, label, title, controls.
 *
 * Rebuilt from the imgsli.com before/after slider (original belongs to its
 * author).
 */
(() => {

const MODES = [
  { id: 'slider', label: 'Slider' },
  { id: 'blink', label: 'Blink' },
  { id: 'side', label: 'Side by side' },
  { id: 'diff', label: 'Difference' },
];
const BLINK_INTERVALS = [
  { ms: 0, label: 'Manual' },
  { ms: 250, label: '0.25 s' },
  { ms: 500, label: '0.5 s' },
  { ms: 1000, label: '1 s' },
  { ms: 2000, label: '2 s' },
];
const MAGNIFIER_ZOOMS = [2, 4, 8];
const ZOOM_MAX = 100;
const TOGGLE_ZOOM = 3;
const TAP_PAN_THRESHOLD = 5;
const WHEEL_ZOOM_SPEED = 0.005;
const KEY_STEP = 0.02;
const DIFF_MAX_PIXELS = 2.5e6;
const EXPORT_MAX_SIDE = 8192;
const EXPORT_MAX_AREA = 40e6;
const EXPORT_MAX_DIFF_SIDE = 4096;
const CROSS_ORIGIN_MESSAGE = 'The images are on another site that does not allow reading their pixels (CORS).';
const DEFAULT_VIEW = { z: 1, cx: 0.5, cy: 0.5 };

const ICON_PATHS = {
  zoomIn: 'M0 14.304q0-5.92 4.192-10.112t10.112-4.192 10.176 4.192 4.192 10.112q0 4.512-2.624 8.192l5.952 5.952-3.552 3.552-5.952-5.952q-3.68 2.624-8.192 2.624-5.92 0-10.112-4.192t-4.192-10.176zM4.064 14.304q0 4.256 3.008 7.264t7.232 2.976 7.264-2.976 3.008-7.264-3.008-7.2-7.264-2.976-7.232 2.976-3.008 7.2zM8.192 16.384v-4.064h4.128v-4.128h4.064v4.128h4.128v4.064h-4.128v4.128h-4.064v-4.128h-4.128z',
  zoomOut: 'M0 14.304q0-5.92 4.192-10.112t10.112-4.192 10.176 4.192 4.192 10.112q0 4.512-2.624 8.192l5.952 5.952-3.552 3.552-5.952-5.952q-3.68 2.624-8.192 2.624-5.92 0-10.112-4.192t-4.192-10.176zM4.064 14.304q0 4.256 3.008 7.264t7.232 2.976 7.264-2.976 3.008-7.264-3.008-7.2-7.264-2.976-7.232 2.976-3.008 7.2zM8.192 16.384v-4.064h12.32v4.064h-12.32z',
  fullscreen: 'M4.576 20.576H0V32h11.424v-4.576H4.576v-6.848zM0 11.424h4.576V4.576h6.848V0H0v11.424zm27.424 16h-6.848V32H32V20.576h-4.576v6.848zM20.576 0v4.576h6.848v6.848H32V0H20.576z',
  fullscreenExit: 'M0 25.152h6.848V32h4.576V20.576H0v4.576zM6.848 6.848H0v4.576h11.424V0H6.848v6.848zM20.576 32h4.576v-6.848H32v-4.576H20.576V32zm4.576-25.152V0h-4.576v11.424H32V6.848h-6.848z',
};
const TOOL_ICONS = {
  layout: 'M3 11h18v2H3zM11 3h2v6h-2zM11 15h2v6h-2z',
  magnifier: 'M10 3a7 7 0 0 1 5.6 11.2l5.6 5.6-1.4 1.4-5.6-5.6A7 7 0 1 1 10 3zm0 2a5 5 0 1 0 0 10 5 5 0 0 0 0-10z',
  labels: 'M4 5h16v3h-2V7h-5v10h2v2H9v-2h2V7H6v1H4z',
  link: 'M10.6 13.4a1 1 0 0 1 0-1.4l3.5-3.5a3 3 0 1 1 4.2 4.2l-2 2-1.4-1.4 2-2a1 1 0 1 0-1.4-1.4L12 13.4a1 1 0 0 1-1.4 0zM13.4 10.6a1 1 0 0 1 0 1.4l-3.5 3.5a3 3 0 1 1-4.2-4.2l2-2 1.4 1.4-2 2a1 1 0 1 0 1.4 1.4L12 10.6a1 1 0 0 1 1.4 0z',
  download: 'M11 3h2v9.2l3.3-3.3 1.4 1.4L12 16l-5.7-5.7 1.4-1.4 3.3 3.3zM4 18h16v2H4z',
  swap: 'M7 7h11l-3-3 1.4-1.4L21.8 8l-5.4 5.4L15 12l3-3H7zM17 17H6l3 3-1.4 1.4L2.2 16l5.4-5.4L9 12l-3 3h11z',
};

const STYLE = `
:host {
  --ic-max-h: var(--image-compare-max-height, calc(100vh - 100px));
  --ic-accent: var(--image-compare-accent, #008a00);
  --ic-bg: var(--image-compare-background, #202428);
  display: block;
  position: relative;
  color: #fff;
  font-family: sans-serif;
  text-align: center;
  user-select: none;
  -webkit-user-select: none;
}
:host([hidden]) { display: none; }
* { box-sizing: border-box; }
[hidden] { display: none !important; }

/* ---------- toolbar ---------- */
.toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 8px; margin: 0 auto 10px; padding: 0 10px; font-size: 14px; }
:host([no-toolbar]) .toolbar { display: none; }
.toolbar :focus-visible { outline: 2px solid #19c219; outline-offset: 1px; }
.group { display: inline-flex; align-items: center; gap: 4px; }
.modes { gap: 0; }
.btn {
  display: inline-flex; align-items: center; gap: 6px; height: 32px; padding: 0 12px;
  border: 0; border-radius: 3px; background: #3d4247; color: #fff; fill: #fff;
  font: inherit; cursor: pointer; white-space: nowrap; list-style: none;
}
.btn::-webkit-details-marker { display: none; }
.btn svg { width: 18px; height: 18px; flex: 0 0 auto; }
.btn:hover { background: #4b5157; }
.btn[aria-pressed="true"] { background: var(--ic-accent); }
.modes .btn { border-radius: 0; }
.modes .btn:first-child { border-radius: 3px 0 0 3px; }
.modes .btn:last-child { border-radius: 0 3px 3px 0; }
.select { height: 32px; padding: 0 6px; border: 0; border-radius: 3px; background: #3d4247; color: #fff; font: inherit; cursor: pointer; }
.range { color: #bbb; gap: 8px; }
.range input { accent-color: var(--ic-accent); width: 120px; }
.range output { color: #fff; min-width: 32px; text-align: left; }
.menu { position: relative; }
.menu-list {
  position: absolute; z-index: 30; right: 0; top: calc(100% + 4px); display: flex; flex-direction: column;
  min-width: 190px; padding: 4px; background: var(--ic-bg); box-shadow: 0 4px 16px rgba(0,0,0,.5); border-radius: 3px;
}
.menu-list button { padding: 8px 12px; border: 0; background: transparent; color: #fff; font: inherit; text-align: left; cursor: pointer; }
.menu-list button:hover { background: #3d4247; }
@media (max-width: 700px) {
  .btn span { display: none; }
  .modes .btn span { display: inline; }
  .modes .btn { font-size: 13px; padding: 0 8px; }
}

/* ---------- comparison box ---------- */
.box {
  --split: .5;
  position: relative; display: block; margin: 0 auto; overflow: hidden;
  aspect-ratio: var(--ar, 1.7778);
  width: min(100%, calc(var(--ic-max-h) * var(--ar, 1.7778)), var(--natural-w, 100%));
  font-size: 20px; line-height: 100%; background-color: var(--ic-bg); touch-action: pan-y;
}
.box img {
  position: absolute; top: 0; left: 0; display: block; width: 100%; height: 100%;
  max-width: none; object-fit: fill; transform-origin: 0 0; -webkit-user-drag: none;
}
.box img.broken { visibility: hidden; }
:host(.zoomed) .box { cursor: grab; }
:host(.panning) .box { cursor: grabbing; }
.before { position: absolute; z-index: 8; inset: 0; overflow: hidden; clip-path: inset(0 calc((1 - var(--split)) * 100%) 0 0); }

.title { position: absolute; z-index: 10; bottom: 10px; left: 20px; white-space: nowrap; text-shadow: -1px -1px 2px #000, 1px 1px 2px #000; }
.controls { position: absolute; z-index: 12; bottom: 10px; right: 15px; display: flex; gap: 4px; }
.controls button { display: inline-block; padding: 10px 11px; border: 0; fill: #333; box-shadow: 1px 1px 4px #000; background: #fff; border-radius: 9999px; cursor: pointer; }
.controls button:hover { background-color: var(--ic-accent); fill: #fff; }
.controls svg { width: 24px; height: 24px; display: block; }
.zoom-out, .fullscreen-exit { display: none !important; }
:host(.zoomed) .zoom-in { display: none !important; }
:host(.zoomed) .zoom-out { display: inline-block !important; }
:host([fullscreen]) .fullscreen { display: none !important; }
:host([fullscreen]) .fullscreen-exit { display: inline-block !important; }
@media (max-width: 1024px) { .controls button { padding: 6px 7px; } }

.divider {
  position: absolute; z-index: 9; top: 0; bottom: 0; left: calc(var(--split) * 100% - 1px); width: 2px;
  background-color: rgba(255,255,255,.5); box-shadow: 0 0 2px rgba(0,0,0,.5);
}
.handle {
  position: absolute; top: calc(50% - 40px); left: -4px; width: 10px; height: 80px; padding: 0;
  background-color: #ddd; border: 4px solid #fff; outline: 0; cursor: ew-resize;
  box-shadow: 0 0 2px rgba(0,0,0,.5); touch-action: none;
}
.handle:hover, .handle:focus-visible { background-color: #777; }
.cap { position: absolute; white-space: nowrap; text-shadow: -1px -1px 2px #000, 1px 1px 2px #000; text-align: left; transition: opacity .15s; }
.cap-a { top: 10px; right: 20px; text-align: right; }
.cap-b { top: 10px; right: -520px; width: 500px; }
.cap select { padding: 5px 10px; border: 0; border-radius: 0; font-size: 16px; max-width: 300px; text-shadow: none; cursor: pointer; }

:host([layout="top-bottom"][mode="slider"]) .divider { top: calc(var(--split) * 100% - 1px); bottom: auto; left: 0; right: 0; width: auto; height: 2px; }
:host([layout="top-bottom"][mode="slider"]) .handle { top: -4px; left: calc(50% - 40px); width: 80px; height: 10px; cursor: ns-resize; }
:host([layout="top-bottom"][mode="slider"]) .cap-a { top: auto; bottom: 10px; left: 20px; right: auto; text-align: left; }
:host([layout="top-bottom"][mode="slider"]) .cap-b { top: 10px; left: 20px; right: auto; width: auto; }
:host([layout="top-bottom"][mode="slider"]) .before { clip-path: inset(0 0 calc((1 - var(--split)) * 100%) 0); }

:host(:not([mode="slider"])) .divider { left: calc(50% - 1px); background: transparent; box-shadow: none; pointer-events: none; }
:host(:not([mode="slider"])) .handle { display: none; }
:host(:not([mode="slider"])) .cap select { pointer-events: auto; }
:host(:not([mode="slider"])) .before { clip-path: none; }
:host([mode="slider"].divider-offscreen) .divider { visibility: hidden; }
:host([mode="side"]) .before { right: 50%; }
:host([mode="side"]) .img-b { left: 50%; width: 50%; }
:host([mode="side"]) .divider { background: rgba(0,0,0,.6); }
:host([mode="blink"]) .box { cursor: pointer; }
:host([mode="blink"].zoomed) .box { cursor: grab; }
:host(.blink-b) .before { visibility: hidden; }
:host([mode="blink"]:not(.blink-b)) .cap-b, :host(.blink-b) .cap-a { opacity: .35; }

.diff { position: absolute; z-index: 8; inset: 0; width: 100%; height: 100%; pointer-events: none; }
.lens { position: absolute; z-index: 11; pointer-events: none; border-radius: 50%; box-shadow: 0 2px 12px rgba(0,0,0,.6); }

:host(.labels-hidden) .title, :host(.labels-hidden) .cap { visibility: hidden; }
:host(.sliding) .cap, :host(.sliding) .controls, :host(.sliding) .title { opacity: .5; }
:host(.sliding) .handle { opacity: 0; }

.msg {
  position: absolute; z-index: 12; top: 50%; left: 50%; transform: translate(-50%, -50%); max-width: 90%;
  padding: 10px 16px; font-size: 16px; line-height: 1.4; background: rgba(32,36,40,.9); border: 1px solid #e51300; pointer-events: none;
}
.toast {
  position: absolute; z-index: 20; left: 50%; bottom: 70px; transform: translateX(-50%); padding: 8px 16px; font-size: 15px;
  background: var(--ic-accent); border-radius: 3px; opacity: 0; pointer-events: none; transition: opacity .2s;
}
.toast.show { opacity: 1; }

/* ---------- fullscreen (native, or the page-level fallback) ---------- */
:host([fullscreen]) {
  --ic-max-h: calc(100vh - 56px);
  display: flex; flex-direction: column; justify-content: center; width: 100vw; height: 100vh; background: #282d32;
}
:host([fullscreen]) .toolbar { margin: 8px auto; flex: 0 0 auto; }
:host([pseudo-fullscreen]) { position: fixed; inset: 0; z-index: 2147483000; }
`;

// ---------- view math (shared by display, difference, magnifier, export) ----------

const isLoaded = (img) => !!img && img.complete && img.naturalWidth > 0;

function viewOffset(view, paneW, paneH) {
  return { tx: paneW / 2 - view.cx * paneW * view.z, ty: paneH / 2 - view.cy * paneH * view.z };
}

function clampView(view) {
  const z = Math.min(ZOOM_MAX, Math.max(1, view.z));
  const half = 1 / (2 * z);
  return { z, cx: Math.min(1 - half, Math.max(half, view.cx)), cy: Math.min(1 - half, Math.max(half, view.cy)) };
}

function viewPixels(img, w, h, view) {
  const c = document.createElement('canvas');
  c.width = w;
  c.height = h;
  const ctx = c.getContext('2d', { willReadFrequently: true });
  const { tx, ty } = viewOffset(view, w, h);
  ctx.setTransform(view.z, 0, 0, view.z, tx, ty);
  ctx.imageSmoothingEnabled = view.z < 2;
  ctx.drawImage(img, 0, 0, w, h);
  return ctx.getImageData(0, 0, w, h);
}

function differenceImage(a, b, amp) {
  const out = new ImageData(a.width, a.height);
  const da = a.data;
  const db = b.data;
  const d = out.data;
  for (let i = 0; i < d.length; i += 4) {
    d[i] = Math.min(255, Math.abs(da[i] - db[i]) * amp);
    d[i + 1] = Math.min(255, Math.abs(da[i + 1] - db[i + 1]) * amp);
    d[i + 2] = Math.min(255, Math.abs(da[i + 2] - db[i + 2]) * amp);
    d[i + 3] = 255;
  }
  return out;
}

// ---------- downloads ----------

const safeName = (s) => (s || 'image-compare').replace(/[\\/:*?"<>|]+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 120) || 'image-compare';

function saveBlob(blob, filename) {
  const href = URL.createObjectURL(blob);
  const a = Object.assign(document.createElement('a'), { href, download: filename });
  document.body.append(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(href), 30_000);
}

function fitSize(w, h, maxSide) {
  const s = Math.min(1, maxSide / Math.max(w, h), Math.sqrt(EXPORT_MAX_AREA / (w * h)));
  return { w: Math.max(1, Math.round(w * s)), h: Math.max(1, Math.round(h * s)) };
}

function drawCaption(ctx, text, x, y, align, size) {
  if (!text) return;
  ctx.save();
  ctx.font = `${size}px sans-serif`;
  ctx.textAlign = align;
  ctx.textBaseline = 'top';
  ctx.lineWidth = Math.max(2, size / 8);
  ctx.lineJoin = 'round';
  ctx.strokeStyle = 'rgba(0,0,0,.85)';
  ctx.strokeText(text, x, y);
  ctx.fillStyle = '#fff';
  ctx.fillText(text, x, y);
  ctx.restore();
}

/** Full-resolution PNG of the current comparison (mode, divider, labels). */
function composeComparison(s) {
  const ref = isLoaded(s.imgB) ? s.imgB : s.imgA;
  if (!isLoaded(ref)) throw new Error('The images have not loaded yet.');
  const side = s.mode === 'side';
  const maxSide = s.mode === 'diff' ? EXPORT_MAX_DIFF_SIDE : side ? EXPORT_MAX_SIDE / 2 : EXPORT_MAX_SIDE;
  const { w, h } = fitSize(ref.naturalWidth, ref.naturalHeight, maxSide);
  const canvas = document.createElement('canvas');
  canvas.width = side ? w * 2 : w;
  canvas.height = h;
  const ctx = canvas.getContext('2d');
  ctx.fillStyle = '#202428';
  ctx.fillRect(0, 0, canvas.width, canvas.height);
  const font = Math.max(14, Math.round(h / 32));
  const pad = Math.round(font * 0.8);
  const draw = (img, x = 0) => { if (isLoaded(img)) ctx.drawImage(img, x, 0, w, h); };
  const { labels } = s;

  if (s.mode === 'side') {
    draw(s.imgA, 0);
    draw(s.imgB, w);
    if (labels.show) {
      drawCaption(ctx, labels.a, pad, pad, 'left', font);
      drawCaption(ctx, labels.b, w + pad, pad, 'left', font);
    }
  } else if (s.mode === 'blink') {
    draw(s.blinkShowsA ? s.imgA : s.imgB);
    if (labels.show) drawCaption(ctx, s.blinkShowsA ? labels.a : labels.b, pad, pad, 'left', font);
  } else if (s.mode === 'diff') {
    if (!isLoaded(s.imgA) || !isLoaded(s.imgB)) throw new Error('Both images must be loaded.');
    try {
      ctx.putImageData(differenceImage(viewPixels(s.imgA, w, h, DEFAULT_VIEW), viewPixels(s.imgB, w, h, DEFAULT_VIEW), s.amp), 0, 0);
    } catch (err) {
      throw err?.name === 'SecurityError' ? new Error(CROSS_ORIGIN_MESSAGE) : err;
    }
    if (labels.show) drawCaption(ctx, `${labels.a || 'Left'} vs ${labels.b || 'Right'} (×${s.amp})`, pad, pad, 'left', font);
  } else {
    const y = s.layout === 'top-bottom';
    draw(s.imgB);
    ctx.save();
    ctx.beginPath();
    if (y) ctx.rect(0, 0, w, h * s.position);
    else ctx.rect(0, 0, w * s.position, h);
    ctx.clip();
    draw(s.imgA);
    ctx.restore();
    ctx.fillStyle = 'rgba(255,255,255,.6)';
    const line = Math.max(2, Math.round(h / 500));
    if (y) ctx.fillRect(0, Math.round(h * s.position - line / 2), w, line);
    else ctx.fillRect(Math.round(w * s.position - line / 2), 0, line, h);
    if (labels.show) {
      if (y) {
        drawCaption(ctx, labels.a, pad, Math.max(pad, h * s.position - font - pad), 'left', font);
        drawCaption(ctx, labels.b, pad, h * s.position + pad, 'left', font);
      } else {
        drawCaption(ctx, labels.a, w * s.position - pad, pad, 'right', font);
        drawCaption(ctx, labels.b, w * s.position + pad, pad, 'left', font);
      }
    }
  }
  if (labels.show && labels.title) drawCaption(ctx, labels.title, pad, h - font - pad, 'left', font);
  return canvas;
}

// ---------- element ----------

const svg = (path, viewBox = '0 0 24 24') => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="${viewBox}" aria-hidden="true"><path d="${path}"/></svg>`;
const isTyping = (t) => t?.closest?.('input, textarea, select, [contenteditable="true"]');
const clampIndex = (i, n) => (n ? Math.min(Math.max(0, Math.trunc(Number(i)) || 0), n - 1) : 0);

function template() {
  const t = document.createElement('template');
  t.innerHTML = `
<style>${STYLE}</style>
<div class="toolbar" part="toolbar" role="toolbar" aria-label="Comparison tools" title="">
  <div class="group modes">${MODES.map((m) => `<button type="button" class="btn" data-mode="${m.id}">${m.label}</button>`).join('')}</div>
  <button type="button" class="btn layout-btn" title="Switch between left/right and top/bottom">${svg(TOOL_ICONS.layout)}<span>Divider</span></button>
  <button type="button" class="btn swap-btn" title="Swap the left and right images">${svg(TOOL_ICONS.swap)}<span>Swap</span></button>
  <div class="group blink-group">
    <button type="button" class="btn blink-btn" title="Show the other image (click or Space)">${svg(TOOL_ICONS.swap)}<span>Switch</span></button>
    <select class="select blink-select" title="Switch automatically">${BLINK_INTERVALS.map((o) => `<option value="${o.ms}">${o.label}</option>`).join('')}</select>
  </div>
  <label class="group range diff-group" title="Amplify differences"><span>Amplify</span><input class="amp" type="range" min="1" max="20" step="1"><output class="amp-out"></output></label>
  <div class="group">
    <button type="button" class="btn mag-btn" aria-pressed="false" title="Show a magnifier lens under the pointer">${svg(TOOL_ICONS.magnifier)}<span>Magnifier</span></button>
    <select class="select mag-select" title="Magnification">${MAGNIFIER_ZOOMS.map((z) => `<option value="${z}"${z === 4 ? ' selected' : ''}>${z}×</option>`).join('')}</select>
  </div>
  <button type="button" class="btn labels-btn" aria-pressed="true" title="Show or hide the title and captions">${svg(TOOL_ICONS.labels)}<span>Labels</span></button>
  <button type="button" class="btn link-btn" title="Copy a link to this view" hidden>${svg(TOOL_ICONS.link)}<span>Copy link</span></button>
  <details class="menu">
    <summary class="btn" title="Download">${svg(TOOL_ICONS.download)}<span>Download</span></summary>
    <div class="menu-list">
      <button type="button" data-download="before">Left image</button>
      <button type="button" data-download="after">Right image</button>
      <button type="button" data-download="view">Current view as PNG</button>
    </div>
  </details>
</div>
<div class="box" part="box" title=""><!-- title="": the host's title attribute is the caption, not a tooltip -->
  <img class="img-b" alt="" decoding="async" fetchpriority="high" draggable="false">
  <div class="before"><img class="img-a" alt="" decoding="async" draggable="false"></div>
  <canvas class="diff" hidden aria-hidden="true"></canvas>
  <canvas class="lens" hidden aria-hidden="true"></canvas>
  <div class="divider" part="divider">
    <button type="button" class="handle" part="handle" role="slider" aria-label="Divider" aria-valuemin="0" aria-valuemax="100"></button>
    <div class="cap cap-a" part="label"></div>
    <div class="cap cap-b" part="label"></div>
  </div>
  <div class="title" part="title"></div>
  <div class="controls" part="controls">
    <button type="button" class="zoom-in" title="Zoom in">${svg(ICON_PATHS.zoomIn, '0 0 32 32')}</button>
    <button type="button" class="zoom-out" title="Zoom out">${svg(ICON_PATHS.zoomOut, '0 0 32 32')}</button>
    <button type="button" class="fullscreen" title="Fullscreen">${svg(ICON_PATHS.fullscreen, '0 0 32 32')}</button>
    <button type="button" class="fullscreen-exit" title="Exit fullscreen">${svg(ICON_PATHS.fullscreenExit, '0 0 32 32')}</button>
  </div>
  <div class="msg" role="status" hidden></div>
</div>
<div class="toast" role="status"></div>`;
  return t;
}

let TEMPLATE = null;

class ImageCompare extends HTMLElement {
  static observedAttributes = [
    'title', 'before', 'after', 'before-label', 'after-label', 'images', 'initial-before', 'initial-after',
    'mode', 'layout', 'position', 'amplify', 'album-url',
  ];

  #sel = null; // {a, b}: indexes picked by the user (or the link); null → initial-before/initial-after
  #parsed = { key: null, list: [] };
  #view = { ...DEFAULT_VIEW };
  #blinkShowsA = true;
  #blinkTimer = null;
  #magnifierOn = false;
  #magZoom = 4;
  #labels = true;
  #errors = { a: false, b: false };
  #diffMessage = null;
  #diffQueued = false;
  #drag = null;
  #hover = false;
  #pointer = null;
  #naturalWidth = 0;
  #aspect = 0;
  #urlTimer = null;
  #toastTimer = null;
  #preloaded = '';
  #syncing = false;
  #connected = false;
  #hashRead = false;
  #cleanup = [];

  constructor() {
    super();
    TEMPLATE ??= template();
    const root = this.attachShadow({ mode: 'open' });
    root.append(TEMPLATE.content.cloneNode(true));
    const $ = (s) => root.querySelector(s);
    this.$ = {
      box: $('.box'),
      imgA: $('.img-a'),
      imgB: $('.img-b'),
      before: $('.before'),
      handle: $('.handle'),
      capA: $('.cap-a'),
      capB: $('.cap-b'),
      title: $('.title'),
      msg: $('.msg'),
      toast: $('.toast'),
      diff: $('.diff'),
      lens: $('.lens'),
      modeButtons: [...root.querySelectorAll('[data-mode]')],
      layoutBtn: $('.layout-btn'),
      swapBtn: $('.swap-btn'),
      blinkGroup: $('.blink-group'),
      blinkSelect: $('.blink-select'),
      diffGroup: $('.diff-group'),
      amp: $('.amp'),
      ampOut: $('.amp-out'),
      magBtn: $('.mag-btn'),
      magSelect: $('.mag-select'),
      labelsBtn: $('.labels-btn'),
      linkBtn: $('.link-btn'),
      menu: $('.menu'),
    };
    this.#wireStatic();
  }

  // ---------- attributes / properties ----------

  /** The image list: the `images` JSON attribute, else the `before`/`after` pair. */
  get images() {
    const raw = this.getAttribute('images');
    const key = raw ?? `${this.getAttribute('before')}\n${this.getAttribute('after')}\n${this.getAttribute('before-label')}\n${this.getAttribute('after-label')}`;
    if (this.#parsed.key === key) return this.#parsed.list;
    let list = null;
    if (raw) {
      try {
        const parsed = JSON.parse(raw);
        if (Array.isArray(parsed) && parsed.length >= 2 && parsed.every((i) => typeof i?.url === 'string')) {
          list = parsed.map((i) => ({ url: i.url, description: String(i.description ?? '') }));
        }
      } catch {
        // reported below
      }
      if (!list) console.warn('<image-compare>: the "images" attribute is not a JSON array of 2+ {url, description}:', raw);
    }
    list ??= [
      { url: this.getAttribute('before') ?? '', description: this.getAttribute('before-label') ?? 'Before' },
      { url: this.getAttribute('after') ?? '', description: this.getAttribute('after-label') ?? 'After' },
    ];
    this.#parsed = { key, list };
    return list;
  }

  get mode() { const m = this.getAttribute('mode'); return MODES.some((x) => x.id === m) ? m : 'slider'; }
  set mode(v) { this.setAttribute('mode', v); }
  get layout() { return this.getAttribute('layout') === 'top-bottom' ? 'top-bottom' : 'left-right'; }
  set layout(v) { this.setAttribute('layout', v); }
  get position() { const v = Number(this.getAttribute('position')); return this.hasAttribute('position') && Number.isFinite(v) ? Math.min(1, Math.max(0, v)) : 0.5; }
  set position(v) { this.setAttribute('position', String(Math.min(1, Math.max(0, Number(v) || 0)))); }
  get amplify() { const v = Number(this.getAttribute('amplify')); return Number.isFinite(v) && v >= 1 ? Math.min(20, Math.round(v)) : 5; }
  set amplify(v) { this.setAttribute('amplify', String(v)); }
  get beforeIndex() { return clampIndex(this.#sel?.a ?? this.getAttribute('initial-before') ?? 0, this.images.length); }
  set beforeIndex(v) { this.#sel = { a: Number(v), b: this.afterIndex }; this.#render(); }
  get afterIndex() { return clampIndex(this.#sel?.b ?? this.getAttribute('initial-after') ?? 1, this.images.length); }
  set afterIndex(v) { this.#sel = { a: this.beforeIndex, b: Number(v) }; this.#render(); }
  get albumUrl() { return (this.getAttribute('album-url') || '').replace(/\/+$/, ''); }

  connectedCallback() {
    this.#connected = true;
    if (!this.hasAttribute('mode')) this.#silently(() => this.setAttribute('mode', 'slider'));
    this.#wireGlobal();
    this.#render();
  }

  disconnectedCallback() {
    this.#connected = false;
    for (const off of this.#cleanup) off();
    this.#cleanup = [];
    clearInterval(this.#blinkTimer);
  }

  attributeChangedCallback(name) {
    // New initial indexes replace the user's pick; other changes (new images,
    // edited captions) keep it, clamped to the list.
    if (name === 'initial-before' || name === 'initial-after') this.#sel = null;
    if (name === 'images' || name === 'before' || name === 'after') this.#preloaded = '';
    if (this.#connected && !this.#syncing) this.#render();
  }

  /** Change attributes without re-entering render (render runs once afterwards). */
  #silently(fn) {
    this.#syncing = true;
    try { fn(); } finally { this.#syncing = false; }
  }

  // ---------- rendering ----------

  #render() {
    if (!this.#connected) return;
    // The view state in the address bar applies once the element knows its album.
    if (this.albumUrl && !this.#hashRead) {
      this.#hashRead = true;
      this.#readHash();
    }
    const { imgA, imgB, capA, capB, title } = this.$;
    const images = this.images;
    const a = this.beforeIndex;
    const b = this.afterIndex;
    const setSrc = (img, item) => {
      const url = item?.url ?? '';
      if (img.getAttribute('src') !== url) {
        if (url) img.setAttribute('src', url);
        else img.removeAttribute('src');
      }
      img.alt = item?.description ?? '';
    };
    setSrc(imgA, images[a]);
    setSrc(imgB, images[b]);
    title.textContent = this.getAttribute('title') ?? '';

    if (images.length > 2) {
      this.#picker(capA, 'Left image', a, 'a');
      this.#picker(capB, 'Right image', b, 'b');
    } else {
      capA.textContent = this.#caption(a);
      capB.textContent = this.#caption(b);
    }

    const mode = this.mode;
    for (const btn of this.$.modeButtons) btn.setAttribute('aria-pressed', String(btn.dataset.mode === mode));
    this.$.layoutBtn.hidden = mode !== 'slider';
    this.$.layoutBtn.setAttribute('aria-pressed', String(this.layout === 'top-bottom'));
    this.$.blinkGroup.hidden = mode !== 'blink';
    this.$.diffGroup.hidden = mode !== 'diff';
    this.$.diff.hidden = mode !== 'diff';
    this.$.amp.value = String(this.amplify);
    this.$.ampOut.textContent = `×${this.amplify}`;
    this.$.linkBtn.hidden = !this.albumUrl;
    this.classList.toggle('blink-b', mode === 'blink' && !this.#blinkShowsA);
    this.#applyBlinkTimer();

    this.#applySplit();
    this.$.handle.setAttribute('aria-orientation', this.layout === 'top-bottom' ? 'vertical' : 'horizontal');

    this.#applyLayout();
    this.#applyView();
    this.#updateMessage();
  }

  #caption(i) {
    return this.images[i]?.description || `Image ${i + 1}`;
  }

  /** which: 'a' (left) or 'b' (right) pane. The change listener outlives renders
   *  (recreated only when the image list changes), so it reads the current
   *  indices at event time instead of capturing them at render time. */
  #picker(container, label, selected, which) {
    let select = container.querySelector('select');
    const images = this.images;
    const signature = images.map((img) => `${img.url}\n${img.description}`).join('\n\n');
    if (!select || select.dataset.signature !== signature) {
      select = document.createElement('select');
      select.setAttribute('aria-label', label);
      select.dataset.signature = signature;
      images.forEach((_, i) => select.add(new Option(this.#caption(i), String(i))));
      select.addEventListener('change', () => {
        const i = Number(select.value);
        const cur = { a: this.beforeIndex, b: this.afterIndex };
        this.#userChange(() => {
          this.#sel = which === 'a' ? { a: i, b: cur.b } : { a: cur.a, b: i };
        });
      });
      container.replaceChildren(select);
    }
    select.value = String(selected);
  }

  /** Frame aspect and natural-size cap come from the right image (left one if it failed). */
  #applyLayout() {
    const { imgA, imgB } = this.$;
    const ref = isLoaded(imgB) ? imgB : isLoaded(imgA) ? imgA : null;
    if (ref) {
      this.#aspect = ref.naturalWidth / ref.naturalHeight;
      this.#naturalWidth = ref.naturalWidth;
    }
    const panes = this.mode === 'side' ? 2 : 1;
    const box = this.$.box;
    box.style.setProperty('--ar', ((this.#aspect || 16 / 9) * panes).toFixed(5));
    box.style.setProperty('--natural-w', this.hasAttribute('fullscreen') || !this.#naturalWidth ? '100%' : `${this.#naturalWidth * panes}px`);
  }

  #paneSize() {
    return { w: this.$.box.clientWidth / (this.mode === 'side' ? 2 : 1), h: this.$.box.clientHeight };
  }

  #locate(clientX, clientY) {
    const rect = this.$.box.getBoundingClientRect();
    const { w, h } = this.#paneSize();
    const boxX = clientX - rect.left;
    const boxY = clientY - rect.top;
    const x = this.mode === 'side' && boxX > w ? boxX - w : boxX;
    const { tx, ty } = viewOffset(this.#view, w, h);
    return { boxX, boxY, x, y: boxY, w, h, u: (x - tx) / (w * this.#view.z), v: (boxY - ty) / (h * this.#view.z) };
  }

  #applyView() {
    this.#view = clampView(this.#view);
    const { w, h } = this.#paneSize();
    const { tx, ty } = viewOffset(this.#view, w, h);
    const zoomed = this.#view.z > 1;
    for (const img of [this.$.imgA, this.$.imgB]) {
      img.style.transform = zoomed ? `translate(${tx}px, ${ty}px) scale(${this.#view.z})` : '';
      img.style.imageRendering = this.#view.z >= 2 ? 'pixelated' : '';
    }
    this.classList.toggle('zoomed', zoomed);
    this.$.box.style.touchAction = zoomed ? 'none' : '';
    this.#applySplit();
    this.#scheduleDiff();
    this.#updateLens();
  }

  #updateMessage() {
    const parts = [];
    if (this.#errors.a) parts.push('Left image unavailable');
    if (this.#errors.b) parts.push('Right image unavailable');
    if (this.mode === 'diff' && this.#diffMessage) parts.push(this.#diffMessage);
    this.$.msg.textContent = parts.join(' · ');
    this.$.msg.hidden = parts.length === 0;
  }

  // ---------- user changes ----------

  /** Apply a change made by the user, then sync the URL and notify listeners. */
  #userChange(fn) {
    fn();
    this.#render();
    this.#updateUrl();
    this.dispatchEvent(new CustomEvent('image-compare-change', {
      bubbles: true,
      composed: true,
      detail: { beforeIndex: this.beforeIndex, afterIndex: this.afterIndex, mode: this.mode, layout: this.layout, position: this.position, zoom: this.#view.z, x: this.#view.cx, y: this.#view.cy },
    }));
  }

  #setPosition(v) {
    this.#silently(() => { this.position = v; });
    this.#applySplit();
  }

  /**
   * The divider is pinned to the picture: `position` is an image coordinate, drawn
   * through the current zoom/pan, so zooming or panning carries the divider along
   * (possibly off-screen).
   */
  #applySplit() {
    const vertical = this.layout === 'top-bottom';
    const { w, h } = this.#paneSize();
    const size = vertical ? h : w;
    const { tx, ty } = viewOffset(this.#view, w, h);
    const screen = size ? this.position * this.#view.z + (vertical ? ty : tx) / size : this.position;
    this.$.box.style.setProperty('--split', Math.min(1, Math.max(0, screen)).toFixed(4));
    this.classList.toggle('divider-offscreen', screen < 0 || screen > 1);
    this.$.handle.setAttribute('aria-valuenow', String(Math.round(this.position * 100)));
  }

  /** Pointer → image coordinate under it (the inverse of #applySplit). */
  #setPositionFromPointer(clientX, clientY) {
    const vertical = this.layout === 'top-bottom';
    const rect = this.$.box.getBoundingClientRect();
    const { w, h } = this.#paneSize();
    const { tx, ty } = viewOffset(this.#view, w, h);
    const size = vertical ? h : w;
    const local = vertical ? clientY - rect.top : clientX - rect.left;
    const v = (local - (vertical ? ty : tx)) / (size * this.#view.z);
    if (Number.isFinite(v)) this.#setPosition(v);
  }

  #toggleBlink() {
    this.#blinkShowsA = !this.#blinkShowsA;
    this.classList.toggle('blink-b', this.mode === 'blink' && !this.#blinkShowsA);
  }

  #applyBlinkTimer() {
    const ms = Number(this.$.blinkSelect.value);
    const active = this.mode === 'blink' && ms > 0;
    if (active && this.#blinkTimer?.ms === ms) return;
    clearInterval(this.#blinkTimer?.id);
    this.#blinkTimer = active ? { ms, id: setInterval(() => this.#toggleBlink(), ms) } : null;
  }

  #zoomAt(clientX, clientY, z) {
    const p = this.#locate(clientX, clientY);
    const nz = Math.min(ZOOM_MAX, Math.max(1, z));
    const tx = p.x - p.u * p.w * nz;
    const ty = p.y - p.v * p.h * nz;
    this.#view = { z: nz, cx: (p.w / 2 - tx) / (p.w * nz), cy: (p.h / 2 - ty) / (p.h * nz) };
    this.#applyView();
    this.#updateUrl();
  }

  #setZoom(z) {
    this.#view = z > 1 ? { z, cx: 0.5, cy: 0.5 } : { ...DEFAULT_VIEW };
    this.#applyView();
    this.#updateUrl();
  }

  /** Swap the left and right images. */
  swapImages() {
    this.#userChange(() => {
      const { a, b } = { a: this.beforeIndex, b: this.afterIndex };
      this.#sel = { a: b, b: a };
    });
  }

  // ---------- difference and magnifier ----------

  #scheduleDiff() {
    if (this.mode !== 'diff' || this.#diffQueued) return;
    this.#diffQueued = true;
    requestAnimationFrame(() => {
      this.#diffQueued = false;
      if (this.mode !== 'diff') return;
      this.#diffMessage = this.#renderDiff();
      this.#updateMessage();
    });
  }

  #renderDiff() {
    const { imgA, imgB, diff } = this.$;
    const { w: paneW, h: paneH } = this.#paneSize();
    if (!paneW || !paneH) return null;
    const ctx = diff.getContext('2d');
    if (!isLoaded(imgA) || !isLoaded(imgB)) {
      ctx.clearRect(0, 0, diff.width, diff.height);
      return 'Waiting for both images…';
    }
    const scale = Math.min(window.devicePixelRatio || 1, Math.sqrt(DIFF_MAX_PIXELS / (paneW * paneH)));
    const w = Math.max(1, Math.round(paneW * scale));
    const h = Math.max(1, Math.round(paneH * scale));
    try {
      const data = differenceImage(viewPixels(imgA, w, h, this.#view), viewPixels(imgB, w, h, this.#view), this.amplify);
      if (diff.width !== w || diff.height !== h) { diff.width = w; diff.height = h; }
      ctx.putImageData(data, 0, 0);
      return null;
    } catch (err) {
      if (err?.name === 'SecurityError') return CROSS_ORIGIN_MESSAGE;
      throw err;
    }
  }

  #updateLens() {
    const lens = this.$.lens;
    if (!this.#magnifierOn || !this.#hover || !this.#pointer) { lens.hidden = true; return; }
    const p = this.#locate(this.#pointer.x, this.#pointer.y);
    const { imgA, imgB } = this.$;
    if (p.u < 0 || p.u > 1 || p.v < 0 || p.v > 1 || (!isLoaded(imgA) && !isLoaded(imgB))) { lens.hidden = true; return; }
    const box = this.$.box;
    const size = Math.round(Math.min(240, Math.max(140, Math.min(box.clientWidth, box.clientHeight) / 3)));
    const scale = this.#view.z * this.#magZoom;
    const spanU = size / (p.w * scale);
    const spanV = size / (p.h * scale);
    const dpr = window.devicePixelRatio || 1;
    const px = Math.round(size * dpr);
    if (lens.width !== px) {
      lens.width = px;
      lens.height = px;
      lens.style.width = `${size}px`;
      lens.style.height = `${size}px`;
    }
    lens.style.left = `${p.boxX - size / 2}px`;
    lens.style.top = `${p.boxY - size / 2}px`;
    lens.hidden = false;

    const ctx = lens.getContext('2d');
    const r = px / 2;
    ctx.clearRect(0, 0, px, px);
    ctx.save();
    ctx.beginPath();
    ctx.arc(r, r, r - dpr, 0, Math.PI * 2);
    ctx.clip();
    ctx.fillStyle = '#202428';
    ctx.fillRect(0, 0, px, px);
    ctx.imageSmoothingEnabled = false;
    for (const [img, left] of [[imgA, 0], [imgB, r]]) {
      if (!isLoaded(img)) continue;
      ctx.save();
      ctx.beginPath();
      ctx.rect(left, 0, r, px);
      ctx.clip();
      const nw = img.naturalWidth;
      const nh = img.naturalHeight;
      ctx.drawImage(img, (p.u - spanU / 2) * nw, (p.v - spanV / 2) * nh, spanU * nw, spanV * nh, 0, 0, px, px);
      ctx.restore();
    }
    ctx.fillStyle = 'rgba(255,255,255,.8)';
    ctx.fillRect(r - dpr, 0, 2 * dpr, px);
    ctx.restore();
    ctx.beginPath();
    ctx.arc(r, r, r - dpr, 0, Math.PI * 2);
    ctx.lineWidth = 2 * dpr;
    ctx.strokeStyle = '#fff';
    ctx.stroke();
  }

  // ---------- fullscreen ----------

  async enterFullscreen() {
    const request = this.requestFullscreen ?? this.webkitRequestFullscreen;
    if (request) {
      try {
        await request.call(this);
        return;
      } catch { /* refused (e.g. iframe without allowfullscreen): use the page-level fallback */ }
    }
    this.setAttribute('pseudo-fullscreen', '');
    document.documentElement.style.overflow = 'hidden';
    this.#onFullscreenChange();
  }

  exitFullscreen() {
    if (this.hasAttribute('pseudo-fullscreen')) {
      this.removeAttribute('pseudo-fullscreen');
      document.documentElement.style.overflow = '';
      this.#onFullscreenChange();
      return;
    }
    const exit = document.exitFullscreen ?? document.webkitExitFullscreen;
    if (document.fullscreenElement ?? document.webkitFullscreenElement) exit?.call(document);
  }

  #onFullscreenChange() {
    const native = (document.fullscreenElement ?? document.webkitFullscreenElement) === this;
    this.toggleAttribute('fullscreen', native || this.hasAttribute('pseudo-fullscreen'));
    for (const ms of [0, 100, 500]) setTimeout(() => { this.#applyLayout(); this.#applyView(); }, ms);
  }

  // ---------- links ----------

  #readHash() {
    const p = new URLSearchParams(location.hash.slice(1));
    const num = (k, lo, hi) => {
      const v = Number(p.get(k));
      return p.has(k) && Number.isFinite(v) ? Math.min(hi, Math.max(lo, v)) : null;
    };
    this.#silently(() => {
      if (MODES.some((m) => m.id === p.get('m'))) this.setAttribute('mode', p.get('m'));
      if (p.has('s')) this.position = num('s', 0, 1) ?? 0.5;
      if (p.get('o') === 'y') this.setAttribute('layout', 'top-bottom');
      if (p.has('a')) this.amplify = Math.round(num('a', 1, 20) ?? 5);
    });
    const z = num('z', 1, ZOOM_MAX);
    if (z) this.#view = clampView({ z, cx: num('x', 0, 1) ?? 0.5, cy: num('y', 0, 1) ?? 0.5 });
  }

  #hashString() {
    const p = new URLSearchParams();
    if (this.mode !== 'slider') p.set('m', this.mode);
    if (Math.abs(this.position - 0.5) > 0.0005) p.set('s', this.position.toFixed(3));
    if (this.layout === 'top-bottom') p.set('o', 'y');
    if (this.mode === 'diff' && this.amplify !== 5) p.set('a', String(this.amplify));
    if (this.#view.z > 1) {
      p.set('z', this.#view.z.toFixed(2));
      p.set('x', this.#view.cx.toFixed(5)); // enough precision to land on the same pixel at 100×
      p.set('y', this.#view.cy.toFixed(5));
    }
    const s = p.toString();
    return s ? `#${s}` : '';
  }

  #pathString() {
    return this.albumUrl ? `${this.albumUrl}/${this.beforeIndex}/${this.afterIndex}` : location.pathname;
  }

  /** Keep the address bar shareable while the user explores (album-url only). */
  #updateUrl() {
    if (!this.albumUrl) return;
    clearTimeout(this.#urlTimer);
    this.#urlTimer = setTimeout(() => {
      history.replaceState(history.state, '', `${this.#pathString()}${this.#hashString()}`);
    }, 250);
  }

  /** Absolute link to the current pair and view. */
  get viewUrl() {
    return new URL(`${this.#pathString()}${this.#hashString()}`, location.href).href;
  }

  async copyLink() {
    const url = this.viewUrl;
    let ok = false;
    try {
      await navigator.clipboard.writeText(url);
      ok = true;
    } catch {
      const ta = Object.assign(document.createElement('textarea'), { value: url, readOnly: true });
      ta.style.cssText = 'position:fixed;opacity:0;top:0;left:0';
      document.body.append(ta);
      ta.select();
      try { ok = document.execCommand('copy'); } catch { ok = false; }
      ta.remove();
    }
    if (ok) this.#notify('Link copied');
    else window.prompt('Copy this link:', url);
    return ok;
  }

  // ---------- downloads ----------

  async downloadImage(which) {
    const index = which === 'before' ? this.beforeIndex : this.afterIndex;
    const item = this.images[index];
    if (!item?.url) return;
    const url = new URL(item.url, location.href).href;
    const ext = /\.([a-z0-9]{2,5})(?:$|[?#])/i.exec(url)?.[1]?.toLowerCase() ?? 'jpg';
    try {
      const res = await fetch(url);
      if (!res.ok) throw new Error(`HTTP ${res.status}`);
      saveBlob(await res.blob(), `${safeName(this.#caption(index))}.${ext}`);
    } catch {
      window.open(url, '_blank', 'noopener');
    }
  }

  async downloadView() {
    const label = this.getAttribute('title') ?? '';
    const mode = MODES.find((m) => m.id === this.mode).label.toLowerCase();
    try {
      const canvas = composeComparison({
        mode: this.mode,
        imgA: this.$.imgA,
        imgB: this.$.imgB,
        position: this.position,
        layout: this.layout,
        amp: this.amplify,
        blinkShowsA: this.#blinkShowsA,
        labels: { show: this.#labels, title: label, a: this.#caption(this.beforeIndex), b: this.#caption(this.afterIndex) },
      });
      const blob = await new Promise((resolve, reject) => {
        try {
          canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('Could not encode the PNG.'))), 'image/png');
        } catch (err) {
          reject(err?.name === 'SecurityError' ? new Error(CROSS_ORIGIN_MESSAGE) : err);
        }
      });
      saveBlob(blob, `${safeName(`${label || 'image-compare'} - ${mode}`)}.png`);
    } catch (err) {
      this.#notify(err.message || String(err), 5000);
    }
  }

  #notify(text, ms = 2000) {
    const t = this.$.toast;
    t.textContent = text;
    t.classList.add('show');
    clearTimeout(this.#toastTimer);
    this.#toastTimer = setTimeout(() => t.classList.remove('show'), ms);
  }

  // ---------- events ----------

  /** Listeners on shadow DOM nodes (live as long as the element). */
  #wireStatic() {
    const $ = this.$;
    const click = (el, fn) => el.addEventListener('click', (e) => { e.preventDefault(); e.stopPropagation(); fn(); });
    click(this.shadowRoot.querySelector('.zoom-in'), () => this.#setZoom(TOGGLE_ZOOM));
    click(this.shadowRoot.querySelector('.zoom-out'), () => this.#setZoom(1));
    click(this.shadowRoot.querySelector('.fullscreen'), () => this.enterFullscreen());
    click(this.shadowRoot.querySelector('.fullscreen-exit'), () => this.exitFullscreen());

    for (const btn of $.modeButtons) btn.addEventListener('click', () => this.#userChange(() => { this.mode = btn.dataset.mode; }));
    $.layoutBtn.addEventListener('click', () => this.#userChange(() => { this.layout = this.layout === 'top-bottom' ? 'left-right' : 'top-bottom'; }));
    $.swapBtn.addEventListener('click', () => this.swapImages());
    this.shadowRoot.querySelector('.blink-btn').addEventListener('click', () => this.#toggleBlink());
    $.blinkSelect.addEventListener('change', () => this.#applyBlinkTimer());
    $.amp.addEventListener('input', () => this.#userChange(() => { this.amplify = Number($.amp.value); }));
    $.magBtn.addEventListener('click', () => {
      this.#magnifierOn = !this.#magnifierOn;
      $.magBtn.setAttribute('aria-pressed', String(this.#magnifierOn));
      this.#updateLens();
    });
    $.magSelect.addEventListener('change', () => { this.#magZoom = Number($.magSelect.value); this.#updateLens(); });
    $.labelsBtn.addEventListener('click', () => {
      this.#labels = !this.#labels;
      this.classList.toggle('labels-hidden', !this.#labels);
      $.labelsBtn.setAttribute('aria-pressed', String(this.#labels));
    });
    $.linkBtn.addEventListener('click', () => this.copyLink());
    for (const btn of this.shadowRoot.querySelectorAll('[data-download]')) {
      btn.addEventListener('click', () => {
        $.menu.open = false;
        const what = btn.dataset.download;
        if (what === 'view') this.downloadView();
        else this.downloadImage(what);
      });
    }

    for (const [img, key] of [[$.imgA, 'a'], [$.imgB, 'b']]) {
      img.addEventListener('load', () => {
        img.classList.remove('broken');
        this.#errors[key] = false;
        this.#updateMessage();
        this.#applyLayout();
        this.#applyView();
        this.#preloadOthers();
      });
      img.addEventListener('error', () => {
        if (!img.getAttribute('src')) return;
        img.classList.add('broken'); // the message says so; don't draw the browser's alt text
        this.#errors[key] = true;
        this.#updateMessage();
      });
    }

    $.handle.addEventListener('keydown', (e) => {
      const y = this.layout === 'top-bottom';
      const steps = y ? { ArrowUp: -KEY_STEP, ArrowDown: KEY_STEP } : { ArrowLeft: -KEY_STEP, ArrowDown: -KEY_STEP, ArrowRight: KEY_STEP, ArrowUp: KEY_STEP };
      let v;
      if (e.key in steps) v = this.position + steps[e.key] / this.#view.z; // same on-screen step at any zoom
      else if (e.key === 'Home') v = 0;
      else if (e.key === 'End') v = 1;
      else return;
      e.preventDefault();
      this.#userChange(() => this.#setPosition(v));
    });

    const box = $.box;
    box.addEventListener('pointerdown', (e) => this.#onPointerDown(e));
    box.addEventListener('pointermove', (e) => this.#onPointerMove(e), { passive: false });
    box.addEventListener('pointerup', () => this.#onPointerUp());
    box.addEventListener('pointercancel', () => this.#onPointerUp());
    box.addEventListener('lostpointercapture', () => this.#onPointerUp());
    box.addEventListener('pointerenter', () => { this.#hover = true; });
    box.addEventListener('pointerleave', () => { this.#hover = false; $.lens.hidden = true; });
    box.addEventListener('wheel', (e) => {
      e.preventDefault();
      this.#zoomAt(e.clientX, e.clientY, this.#view.z - e.deltaY * WHEEL_ZOOM_SPEED * this.#view.z);
    }, { passive: false });
    new ResizeObserver(() => this.#applyView()).observe(box);
  }

  /** Document-level listeners, added on connect and removed on disconnect. */
  #wireGlobal() {
    const on = (target, type, fn) => {
      target.addEventListener(type, fn);
      this.#cleanup.push(() => target.removeEventListener(type, fn));
    };
    on(document, 'keydown', (e) => {
      if (isTyping(e.composedPath()[0]) || e.ctrlKey || e.metaKey || e.altKey) return;
      if (e.key === ' ' && this.mode === 'blink' && this.#isActive() && e.composedPath()[0] !== this.$.handle) {
        e.preventDefault();
        this.#toggleBlink();
      } else if (e.key === 'Escape' && this.hasAttribute('pseudo-fullscreen')) {
        this.exitFullscreen();
      }
    });
    on(document, 'fullscreenchange', () => this.#onFullscreenChange());
    on(document, 'webkitfullscreenchange', () => this.#onFullscreenChange());
    on(document, 'pointerdown', (e) => {
      if (this.$.menu.open && !e.composedPath().includes(this.$.menu)) this.$.menu.open = false;
    });
  }

  #isActive() {
    return this.hasAttribute('fullscreen') || this.#hover || this.matches(':focus-within') || document.querySelectorAll('image-compare').length === 1;
  }

  #onPointerDown(e) {
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    const target = e.composedPath()[0];
    if (!e.isPrimary || target.closest?.('.controls, select')) return;
    const onHandle = target === this.$.handle;
    if (this.mode === 'slider' && (onHandle || this.#view.z === 1)) {
      this.#drag = { kind: 'split' };
      this.classList.add('sliding');
      this.#setPositionFromPointer(e.clientX, e.clientY);
    } else if (this.mode === 'blink' && this.#view.z === 1) {
      this.#toggleBlink();
      return;
    } else if (this.#view.z > 1) {
      // In blink mode a click without movement still switches the image:
      // start as a pending toggle, commit to a pan once it moves.
      this.#drag = { kind: 'pan', x: e.clientX, y: e.clientY, tap: this.mode === 'blink' };
      if (!this.#drag.tap) this.classList.add('panning');
    } else {
      return;
    }
    e.preventDefault();
    this.$.box.setPointerCapture?.(e.pointerId);
  }

  #onPointerMove(e) {
    if (!e.isPrimary) return;
    this.#pointer = { x: e.clientX, y: e.clientY };
    if (this.#drag?.kind === 'split') {
      e.preventDefault();
      this.#setPositionFromPointer(e.clientX, e.clientY);
    } else if (this.#drag?.kind === 'pan') {
      if (this.#drag.tap) {
        if (Math.hypot(e.clientX - this.#drag.x, e.clientY - this.#drag.y) < TAP_PAN_THRESHOLD) {
          this.#updateLens();
          return;
        }
        // The blink click moved far enough: it is a pan, not a click.
        this.#drag.tap = false;
        this.#drag.x = e.clientX;
        this.#drag.y = e.clientY;
        this.classList.add('panning');
      }
      e.preventDefault();
      const { w, h } = this.#paneSize();
      this.#view.cx -= (e.clientX - this.#drag.x) / (w * this.#view.z);
      this.#view.cy -= (e.clientY - this.#drag.y) / (h * this.#view.z);
      this.#drag.x = e.clientX;
      this.#drag.y = e.clientY;
      this.#applyView();
    }
    this.#updateLens();
  }

  #onPointerUp() {
    if (!this.#drag) return;
    const tap = this.#drag.tap;
    this.#drag = null;
    this.classList.remove('sliding', 'panning');
    if (tap) this.#toggleBlink();
    else this.#userChange(() => {});
  }

  /** Warm the cache with the images a picker is likely to switch to next. */
  #preloadOthers() {
    const images = this.images;
    const a = this.beforeIndex;
    const b = this.afterIndex;
    const key = `${a}/${b}/${images.length}`;
    if (images.length <= 2 || this.#preloaded === key || !isLoaded(this.$.imgA) || !isLoaded(this.$.imgB)) return;
    this.#preloaded = key;
    const near = new Set([a - 1, a + 1, b - 1, b + 1]);
    const indexes = images.length <= 10 ? images.map((_, i) => i) : [...near];
    const idle = window.requestIdleCallback ?? ((fn) => setTimeout(fn, 200));
    idle(() => {
      for (const i of indexes) {
        if (i < 0 || i >= images.length || i === a || i === b) continue;
        const img = new Image();
        img.decoding = 'async';
        img.src = images[i].url;
      }
    });
  }
}

if (!customElements.get('image-compare')) customElements.define('image-compare', ImageCompare);

})();
