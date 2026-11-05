// imgsli compare tool — 2–8 image boxes (pick/drag/paste or URL) with captions,
// reordering, live <image-compare> rendering, and save-as-album (XHR with progress).
(() => {
  const MIN = 2;
  const MAX = 8;
  // Per-image size cap (bytes), set by the server in a meta tag — higher for
  // admin users, who bypass the regular cap. Fallback: the default regular
  // cap (10 MB).
  const MAX_BYTES = Number(
    document.querySelector('meta[name="max-image-bytes"]')?.content ||
    10 * 1024 * 1024
  );
  const TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
  const BOX_DRAG_TYPE = 'application/x-imgsli-box';
  const WARN_MAX_SIDE = 12000; // px; a soft warning, the server has no dimension limit
  // The first two boxes start with these demo images so the slider is live on
  // load; they behave like any URL source (editable, clearable) but a page
  // holding only untouched defaults is not "unsaved work" (no leave prompt).
  const DEFAULT_IMAGES = ['/imgs/demo-before.webp', '/imgs/demo-after.webp'];

  const boxes = document.getElementById('boxes');
  const template = document.getElementById('box-template');
  const sliderRoot = document.getElementById('slider-root');
  const addBoxBtn = document.getElementById('add-box');
  const removeBoxBtn = document.getElementById('remove-box');
  const saveBtn = document.getElementById('save-btn');
  const warningsList = document.getElementById('box-warnings');
  if (!boxes || !sliderRoot) return;

  const progress = document.getElementById('progress');
  const progressText = document.getElementById('progress-text');
  const progressRow = progress?.closest('.progress-row');
  const errorsList = document.getElementById('save-errors');
  const titleInput = document.getElementById('album-title');
  const publicCheck = document.getElementById('album-public');
  const csrf = document.querySelector('meta[name=csrf-token]')?.content ?? '';

  let saving = false;
  let leaving = false; // set once the page navigates to the saved album

  const allBoxes = () => [...boxes.querySelectorAll('.box')];

  const fileInput = (box) => box.querySelector('input[type=file]');
  const urlInput = (box) => box.querySelector('input.url-input');
  const captionInput = (box) => box.querySelector('input.caption-input');

  function boxState(box) {
    const file = fileInput(box)?.files[0] ?? null;
    const url = urlInput(box).value.trim();
    const description = captionInput(box).value.trim();
    return { file, url, description };
  }

  const hasSource = (box) => {
    const { file, url } = boxState(box);
    return Boolean(file || url);
  };

  const imageFiles = (list) => [...(list ?? [])].filter((f) => TYPES.includes(f.type) || f.type.startsWith('image/'));

  /** "my_photo_v2.jpg" → "my photo v2" (the original site prefilled captions this way). */
  function captionFromName(name) {
    const dot = name.lastIndexOf('.');
    return (dot > 0 ? name.slice(0, dot) : name).replace(/_/g, ' ').trim();
  }

  function formatBytes(n) {
    if (n < 1024 * 1024) return `${Math.max(1, Math.round(n / 1024))} KB`;
    return `${(n / (1024 * 1024)).toFixed(1)} MB`;
  }

  // ---- per-box info and warnings (they warn; saving still checks the hard limits) ----
  function updateWarnings() {
    const items = [];
    const sized = [];
    allBoxes().forEach((box, n) => {
      const { file, url, description } = boxState(box);
      const info = box.querySelector('.box-info');
      const preview = box.querySelector('.dropzone img');
      if (!file && !url) {
        info.textContent = '';
        return;
      }
      const name = file?.name || description || `Image ${n + 1}`;
      const loaded = preview.getAttribute('src') && preview.complete && preview.naturalWidth > 0;
      const failed = preview.getAttribute('src') && preview.complete && !preview.naturalWidth;
      const { naturalWidth: w, naturalHeight: h } = preview;
      const parts = [];
      if (loaded) parts.push(`${w} × ${h}`);
      if (failed) parts.push(file ? 'no preview' : 'could not load this URL');
      if (file) parts.push(formatBytes(file.size));
      info.textContent = parts.join(' · ');

      if (file && file.size > MAX_BYTES) {
        items.push(`${name} is ${formatBytes(file.size)}; saving will be refused (limit ${formatBytes(MAX_BYTES)}).`);
      }
      if (loaded) {
        if (Math.max(w, h) > WARN_MAX_SIDE) items.push(`${name} is ${w} × ${h} px; very large images are slow to view.`);
        sized.push({ name, w, h });
      }
    });
    if (new Set(sized.map((s) => `${s.w}×${s.h}`)).size > 1) {
      const list = sized.map((s) => `${s.name} (${s.w} × ${s.h})`).join(', ');
      items.push(`The images have different sizes: ${list}. The viewer scales each pair to the right image's frame, so a different aspect ratio gets stretched.`);
    }
    if (!warningsList) return;
    warningsList.replaceChildren(...items.map((t) => Object.assign(document.createElement('li'), { textContent: t })));
  }

  // ---- previews (blob URLs for picked files, direct src for URLs) ----
  function updatePreview(box) {
    const preview = box.querySelector('.dropzone img');
    const zone = box.querySelector('.dropzone');
    const current = preview.dataset.blobUrl || preview.dataset.srcUrl || '';
    const { file, url } = boxState(box);

    if (file) {
      const blob = URL.createObjectURL(file);
      if (current && preview.dataset.blobUrl === current) URL.revokeObjectURL(current);
      preview.dataset.blobUrl = blob;
      preview.dataset.srcUrl = '';
      preview.src = blob;
      preview.hidden = false;
      zone.classList.add('has-source');
    } else if (url) {
      if (current && preview.dataset.blobUrl === current) URL.revokeObjectURL(current);
      preview.dataset.blobUrl = '';
      preview.dataset.srcUrl = url;
      preview.src = url;
      preview.hidden = false;
      zone.classList.add('has-source');
    } else {
      if (current) {
        if (preview.dataset.blobUrl) URL.revokeObjectURL(current);
      }
      preview.dataset.blobUrl = '';
      preview.dataset.srcUrl = '';
      preview.removeAttribute('src');
      preview.hidden = true;
      zone.classList.remove('has-source');
    }
  }

  /**
   * Put a file into a box. The caption follows the file name until the user
   * types one (`data-auto`); `caption: false` (screenshots) leaves it empty.
   */
  function setFile(box, file, caption = true) {
    const dt = new DataTransfer();
    dt.items.add(file);
    fileInput(box).files = dt.files;
    urlInput(box).value = '';
    const input = captionInput(box);
    if (!input.value.trim() || input.dataset.auto === 'true') {
      input.value = caption && file.name ? captionFromName(file.name) : '';
      if (input.value) input.dataset.auto = 'true';
      else delete input.dataset.auto;
    }
    box.classList.remove('invalid');
    updatePreview(box);
  }

  // Preview loads (or failures) bring the dimensions for the warnings; load/error don't bubble.
  boxes.addEventListener('load', (e) => { if (e.target.matches?.('.dropzone img')) updateWarnings(); }, true);
  boxes.addEventListener('error', (e) => { if (e.target.matches?.('.dropzone img')) updateWarnings(); }, true);

  // ---- slider rendering ----
  // One element for as long as there are 2+ images: attribute updates keep the
  // user's mode, zoom, divider and picks while boxes and captions change.

  function collectItems() {
    const items = [];
    for (const box of allBoxes()) {
      const { file, url, description } = boxState(box);
      const preview = box.querySelector('.dropzone img');
      if (file && preview.dataset.blobUrl) {
        items.push({ url: preview.dataset.blobUrl, description });
      } else if (url) {
        items.push({ url, description });
      }
    }
    return items;
  }

  function applyAttributes(slider, items) {
    if (items.length === 2) {
      slider.removeAttribute('images');
      slider.setAttribute('before', items[0].url);
      slider.setAttribute('after', items[1].url);
      slider.setAttribute('before-label', items[0].description || 'Before');
      slider.setAttribute('after-label', items[1].description || 'After');
    } else {
      slider.removeAttribute('before');
      slider.removeAttribute('after');
      slider.removeAttribute('before-label');
      slider.removeAttribute('after-label');
      slider.setAttribute('images', JSON.stringify(items));
    }
  }

  /** Box numbers, labels and the add/remove buttons follow the box order and count. */
  function renumber() {
    const list = allBoxes();
    list.forEach((box, n) => {
      box.querySelector('.placeholder').textContent = `Image ${n + 1}`;
      box.querySelector('.dropzone').setAttribute('aria-label', `Choose image ${n + 1}`);
      box.querySelector('.box-grip')?.setAttribute('aria-label', `Move image ${n + 1}`);
    });
    if (addBoxBtn) addBoxBtn.disabled = saving || list.length >= MAX;
    if (removeBoxBtn) removeBoxBtn.disabled = saving || list.length <= MIN;
  }

  // Last selection reported by the component (picker change or slider
  // interaction); null until the user picks something. Structural changes
  // (clear/remove/reorder) remap it by item identity; fresh lists keep
  // their defaults.
  let userSel = null;
  sliderRoot.addEventListener('image-compare-change', (e) => {
    const d = e.detail;
    if (Number.isInteger(d?.beforeIndex) && Number.isInteger(d?.afterIndex)) {
      userSel = { a: d.beforeIndex, b: d.afterIndex };
    }
  });
  let lastItems = []; // item list at the last render

  function renderSlider() {
    renumber();
    updateWarnings();
    const items = collectItems();
    let slider = sliderRoot.querySelector('image-compare');

    if (items.length < MIN) {
      lastItems = [];
      userSel = null;
      if (slider) slider.remove();
      if (!sliderRoot.querySelector('.slider-hint')) {
        sliderRoot.innerHTML = `<p class="slider-hint">Add at least ${MIN} images to compare.</p>`;
      }
      return;
    }

    if (!slider) {
      sliderRoot.innerHTML = '';
      slider = document.createElement('image-compare');
      slider.setAttribute('title', '');
      applyAttributes(slider, items);
      sliderRoot.appendChild(slider);
      lastItems = items;
      return;
    }

    // When a box is cleared, removed or reordered, the same images move to
    // new indexes. Remap the user's selection by item identity and write it
    // to initial-* (which the component treats as a selection reset) instead
    // of letting the old indexes clamp onto the wrong images.
    const structural = lastItems.length !== items.length
      || lastItems.some((it, i) => items[i]?.url !== it.url);
    if (userSel && structural && lastItems.length >= MIN) {
      const remap = (i) => {
        const item = lastItems[i];
        const j = item ? items.findIndex((x) => x.url === item.url) : -1;
        return j >= 0 ? j : Math.min(i, items.length - 1);
      };
      const a = remap(userSel.a);
      const b = remap(userSel.b);
      if (a !== userSel.a || b !== userSel.b) {
        slider.setAttribute('initial-before', String(a));
        slider.setAttribute('initial-after', String(b));
        userSel = { a, b };
      }
    }
    applyAttributes(slider, items);
    lastItems = items;
  }

  // ---- box management ----
  function addBox({ scroll = false } = {}) {
    if (allBoxes().length >= MAX) return null;
    const node = template.content.firstElementChild.cloneNode(true);
    boxes.appendChild(node);
    renderSlider();
    if (scroll) node.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    return node;
  }

  function removeLastBox() {
    const boxes_ = allBoxes();
    if (boxes_.length <= MIN) return;
    const last = boxes_[boxes_.length - 1];
    const { file } = boxState(last);
    const preview = last.querySelector('.dropzone img');
    if (file && preview.dataset.blobUrl) URL.revokeObjectURL(preview.dataset.blobUrl);
    last.remove();
    renderSlider();
  }

  function clearBox(box) {
    const preview = box.querySelector('.dropzone img');
    if (preview.dataset.blobUrl) URL.revokeObjectURL(preview.dataset.blobUrl);
    fileInput(box).value = '';
    urlInput(box).value = '';
    captionInput(box).value = '';
    delete captionInput(box).dataset.auto;
    updatePreview(box);
    renderSlider();
    box.querySelector('.dropzone').focus();
  }

  /** A box still holding only its unmodified demo default counts as empty. */
  const isUntouchedDefault = (box, n) =>
    n < DEFAULT_IMAGES.length
    && !fileInput(box).files.length
    && urlInput(box).value === DEFAULT_IMAGES[n]
    && !captionInput(box).value.trim();

  /** Fill empty boxes in order with the files, adding boxes as needed (up to MAX). */
  function fillBoxes(files, captionFor = () => true) {
    const empties = allBoxes().filter((b, n) => !hasSource(b) || isUntouchedDefault(b, n));
    for (const file of files) {
      const box = empties.shift() ?? addBox();
      if (!box) break;
      setFile(box, file, captionFor(file));
    }
    renderSlider();
  }

  addBoxBtn?.addEventListener('click', () => addBox({ scroll: true }));
  removeBoxBtn?.addEventListener('click', removeLastBox);

  // dropzone acts as the file-picker target (click, Enter or Space)
  boxes.addEventListener('click', (e) => {
    const zone = e.target.closest('.dropzone');
    if (zone) {
      fileInput(zone.closest('.box')).click();
      return;
    }
    if (e.target.classList.contains('clear')) {
      clearBox(e.target.closest('.box'));
    }
  });

  boxes.addEventListener('keydown', (e) => {
    const zone = e.target.closest('.dropzone');
    if (zone && (e.key === 'Enter' || e.key === ' ')) {
      e.preventDefault();
      fileInput(zone.closest('.box')).click();
      return;
    }
    // grip: arrow keys move the box
    const grip = e.target.closest('.box-grip');
    const delta = grip && { ArrowLeft: -1, ArrowUp: -1, ArrowRight: 1, ArrowDown: 1 }[e.key];
    if (!delta) return;
    e.preventDefault();
    moveBox(grip.closest('.box'), delta);
    grip.focus();
  });

  // per-box input events (file change / url / caption)
  boxes.addEventListener('change', (e) => {
    const box = e.target.closest('.box');
    if (!box) return;
    if (e.target.type === 'file') {
      // a file pick replaces any URL in the same box
      const file = fileInput(box).files[0];
      if (file) setFile(box, file);
      else updatePreview(box);
    }
    renderSlider();
  });

  boxes.addEventListener('input', (e) => {
    const box = e.target.closest('.box');
    if (!box) return;
    const caption = captionInput(box);
    if (e.target === caption) delete caption.dataset.auto; // typed: stop following the file name
    if (e.target === urlInput(box)) {
      // a pasted URL replaces any file in the same box (and its file-name caption)
      if (e.target.value.trim()) {
        fileInput(box).value = '';
        if (caption.dataset.auto === 'true') {
          caption.value = '';
          delete caption.dataset.auto;
        }
        box.classList.remove('invalid');
      }
      updatePreview(box);
    }
    renderSlider();
  });

  // ---- reordering: drag a box by its grip, or arrow keys on the grip ----
  function moveBox(box, delta) {
    const list = allBoxes();
    const to = list.indexOf(box) + delta;
    if (to < 0 || to >= list.length) return;
    if (delta < 0) list[to].before(box);
    else list[to].after(box);
    renderSlider();
  }

  const clearDropMarks = () => {
    for (const b of allBoxes()) b.classList.remove('drop-before', 'drop-after');
  };

  // Only the grip starts a drag, so text in the inputs stays selectable.
  boxes.addEventListener('pointerdown', (e) => {
    const grip = e.target.closest('.box-grip');
    if (grip) grip.closest('.box').draggable = true;
  });
  boxes.addEventListener('pointerup', () => {
    for (const b of allBoxes()) b.draggable = false;
  });

  boxes.addEventListener('dragstart', (e) => {
    const box = e.target.closest?.('.box');
    if (!box?.draggable) return;
    e.dataTransfer.setData(BOX_DRAG_TYPE, String(allBoxes().indexOf(box)));
    e.dataTransfer.effectAllowed = 'move';
    box.classList.add('dragging');
  });

  boxes.addEventListener('dragend', (e) => {
    const box = e.target.closest?.('.box');
    if (box) {
      box.draggable = false;
      box.classList.remove('dragging');
    }
    clearDropMarks();
  });

  // Files: highlight the box under the pointer. Boxes: show where the dragged box lands.
  const clearFileMarks = () => {
    for (const b of allBoxes()) b.classList.remove('file-over');
  };

  boxes.addEventListener('dragover', (e) => {
    e.preventDefault();
    const box = e.target.closest('.box');
    if (e.dataTransfer.types.includes('Files')) {
      clearFileMarks();
      box?.classList.add('file-over');
      return;
    }
    if (!e.dataTransfer.types.includes(BOX_DRAG_TYPE)) return;
    clearDropMarks();
    if (!box) return;
    const rect = box.getBoundingClientRect();
    box.classList.add(e.clientX > rect.left + rect.width / 2 ? 'drop-after' : 'drop-before');
  });

  boxes.addEventListener('dragleave', (e) => {
    if (!boxes.contains(e.relatedTarget)) clearFileMarks();
  });

  boxes.addEventListener('drop', (e) => {
    e.preventDefault();
    if (saving) return;

    if (e.dataTransfer.types.includes(BOX_DRAG_TYPE)) {
      const target = e.target.closest('.box');
      const from = allBoxes()[Number(e.dataTransfer.getData(BOX_DRAG_TYPE))];
      const after = target?.classList.contains('drop-after');
      clearDropMarks();
      if (!target || !from || from === target) return;
      if (after) target.after(from);
      else target.before(from);
      renderSlider();
      return;
    }

    // Files dropped on a box: the first goes into that box (replacing its image),
    // the rest fill the empty boxes in order. Dropped beside the boxes: all fill empties.
    clearFileMarks();
    const files = imageFiles(e.dataTransfer?.files);
    if (!files.length) return;
    const target = e.target.closest('.box');
    if (target) {
      setFile(target, files[0]);
      fillBoxes(files.slice(1));
    } else {
      fillBoxes(files);
    }
  });

  // Ctrl+V with images on the clipboard fills the next empty boxes; pasting
  // text (e.g. a URL into a field) is left alone.
  document.addEventListener('paste', (e) => {
    const files = imageFiles(e.clipboardData?.files);
    if (!files.length || saving) return;
    e.preventDefault();
    // Screenshots arrive as "image.png": give them a dated name and no caption.
    const stamp = new Date().toISOString().slice(0, 19).replace(/[T:]/g, '-');
    const pasted = new Set();
    const named = files.map((f, i) => {
      if (f.name && f.name !== 'image.png') return f;
      const file = new File([f], `pasted-${stamp}${i ? `-${i + 1}` : ''}.${f.type.split('/')[1] || 'png'}`, { type: f.type });
      pasted.add(file);
      return file;
    });
    fillBoxes(named, (f) => !pasted.has(f));
  });

  // Local images (or a non-default URL) live only in this tab: confirm before
  // leaving with any. Untouched demo defaults don't count.
  window.addEventListener('beforeunload', (e) => {
    if (leaving) return;
    const unsaved = allBoxes().some((box) => {
      const { file, url } = boxState(box);
      return Boolean(file || (url && !DEFAULT_IMAGES.includes(url)));
    });
    if (!unsaved) return;
    e.preventDefault();
    e.returnValue = '';
  });

  // ---- save as album ----
  function showErrors(errors) {
    errorsList.innerHTML = '';
    for (const message of errors) {
      const li = document.createElement('li');
      li.textContent = message;
      errorsList.appendChild(li);
    }
  }

  function setLoading(on) {
    saving = on;
    if (saveBtn) saveBtn.disabled = on;
    for (const el of boxes.querySelectorAll('input, button')) el.disabled = on;
    renumber();
    if (progressRow) progressRow.hidden = !on;
  }

  function parseJson(text) {
    try {
      return JSON.parse(text);
    } catch {
      return null;
    }
  }

  function clientErrors(sources) {
    const errors = [];
    if (sources.length < MIN) errors.push(`Add at least ${MIN} images.`);
    if (sources.length > MAX) errors.push(`Add at most ${MAX} images.`);
    for (const s of sources) {
      if (s.file) {
        if (!TYPES.includes(s.file.type)) {
          errors.push(`${s.file.name}: not a PNG, JPEG, GIF or WebP.`);
        }
        if (s.file.size > MAX_BYTES) {
          errors.push(`${s.file.name}: over ${formatBytes(MAX_BYTES)}.`);
        }
      }
    }
    return errors;
  }

  function saveAlbum() {
    const sources = allBoxes()
      .map(boxState)
      .filter((s) => s.file || s.url);

    const errors = clientErrors(sources);
    showErrors(errors);
    if (sources.length < MIN) {
      for (const box of allBoxes()) box.classList.toggle('invalid', !hasSource(box));
    }
    if (errors.length) return;

    const data = new FormData();
    data.append('title', titleInput?.value.trim() ?? '');
    data.append('is_public', publicCheck?.checked ? '1' : '0');
    let n = 0;
    for (const s of sources) {
      if (s.file) {
        data.append(`images[${n}][file]`, s.file, s.file.name);
      } else {
        data.append(`images[${n}][url]`, s.url);
      }
      if (s.description) data.append(`images[${n}][description]`, s.description);
      n += 1;
    }

    setLoading(true);
    if (progress) progress.value = 0;

    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/api/albums');
    xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
    xhr.setRequestHeader('Accept', 'application/json');

    xhr.upload.addEventListener('progress', (e) => {
      if (!e.lengthComputable) return;
      progress.value = e.loaded / e.total;
      progressText.textContent = `Uploading… ${Math.round((e.loaded / e.total) * 100)}%`;
    });
    // Sent: the server now stores the files and fetches the URL images.
    xhr.upload.addEventListener('load', () => { progressText.textContent = 'Processing…'; });

    xhr.addEventListener('load', () => {
      setLoading(false);
      const json = parseJson(xhr.responseText);
      if (xhr.status >= 200 && xhr.status < 300 && json?.url) {
        leaving = true;
        location.assign(json.url);
        return;
      }
      const serverErrors = json?.errors
        ? Object.values(json.errors).flat()
        : [xhr.status === 422 ? 'Check the images and try again.' : `Upload failed (HTTP ${xhr.status}).`];
      showErrors(serverErrors);
    });

    xhr.addEventListener('error', () => {
      setLoading(false);
      showErrors(['Network error — the upload did not reach the server.']);
    });

    xhr.send(data);
  }

  saveBtn?.addEventListener('click', saveAlbum);

  // ---- init ----
  // Prefill the first boxes with the demo images (the static boxes are always
  // empty on load); the slider renders below immediately.
  allBoxes().slice(0, DEFAULT_IMAGES.length).forEach((box, n) => {
    urlInput(box).value = DEFAULT_IMAGES[n];
    updatePreview(box);
  });
  renderSlider();
})();
