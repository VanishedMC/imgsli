// imgsli — album image editor (owner/admin): add and remove images, then
// save the resulting list with PUT /api/albums/{id}/images (multipart).
// Existing images are referenced by their storage key, so keeping one does
// not re-upload it; new ones go as files or URLs. The server enforces the
// per-album count and per-image size limits.
(() => {
  const panel = document.getElementById('album-edit');
  if (!panel) return;

  const toggle = document.getElementById('edit-images');
  const list = document.getElementById('edit-list');
  const fileInput = document.getElementById('edit-file');
  const urlInput = document.getElementById('edit-url');
  const urlAdd = document.getElementById('edit-url-add');
  const saveBtn = document.getElementById('edit-save');
  const cancelBtn = document.getElementById('edit-cancel');
  const errorsList = document.getElementById('edit-errors');
  const countLabel = document.getElementById('edit-count');
  const csrf = document.querySelector('meta[name=csrf-token]')?.content ?? '';
  const TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

  const MIN = Number(panel.dataset.minImages || 2);
  const MAX = Number(panel.dataset.maxImages || 8);
  const initial = JSON.parse(panel.dataset.images || '[]');

  // Items keep their order: {key, url, description} for an image already in
  // the album, {file, url, description} for a newly picked file, or
  // {url, description} for a new remote URL.
  let items = [];

  const reset = () => {
    items = initial.map((item) => ({ ...item }));
  };

  function showErrors(errors) {
    errorsList.innerHTML = '';
    for (const error of errors) {
      const li = document.createElement('li');
      li.textContent = error;
      errorsList.append(li);
    }
  }

  function render() {
    list.innerHTML = '';

    items.forEach((item, index) => {
      const li = document.createElement('li');
      li.className = 'edit-item';

      const img = document.createElement('img');
      img.src = item.url;
      img.alt = item.description || `Image ${index + 1}`;
      img.loading = 'lazy';

      const caption = document.createElement('input');
      caption.type = 'text';
      caption.className = 'edit-caption';
      caption.maxLength = 200;
      caption.placeholder = 'Caption (optional)';
      caption.value = item.description ?? '';
      caption.setAttribute('aria-label', `Caption for image ${index + 1}`);
      // Kept on the item so adding/removing another image does not lose it;
      // an empty value is sent as '' and clears the stored caption.
      caption.addEventListener('input', () => { item.description = caption.value; });

      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'btn clear';
      remove.title = 'Remove image';
      remove.setAttribute('aria-label', `Remove image ${index + 1}`);
      remove.textContent = '×';
      remove.addEventListener('click', () => {
        items.splice(index, 1);
        render();
      });

      li.append(img, caption, remove);
      list.append(li);
    });

    countLabel.textContent = String(items.length);
  }

  function addFiles(files) {
    for (const file of files) {
      if (items.length >= MAX) break;
      if (!TYPES.includes(file.type)) continue;
      items.push({
        file,
        url: URL.createObjectURL(file),
        description: file.name.replace(/\.[^.]+$/, ''),
      });
    }
    fileInput.value = '';
    render();
  }

  function addUrl() {
    const url = urlInput.value.trim();
    if (!url) return;
    if (items.length >= MAX) return;
    items.push({ url, description: '' });
    urlInput.value = '';
    render();
  }

  async function save() {
    const errors = [];
    if (items.length < MIN) errors.push(`Keep at least ${MIN} images.`);
    if (items.length > MAX) errors.push(`Keep at most ${MAX} images.`);
    showErrors(errors);
    if (errors.length) return;

    const data = new FormData();
    items.forEach((item, index) => {
      if (item.key) {
        data.append(`images[${index}][key]`, item.key);
      } else if (item.file) {
        data.append(`images[${index}][file]`, item.file, item.file.name);
      } else {
        data.append(`images[${index}][url]`, item.url);
      }
      // Always sent, empty included: the server treats a missing field as
      // "keep the stored caption", so omitting it would make clearing a
      // caption impossible.
      data.append(`images[${index}][description]`, item.description ?? '');
    });

    // PHP only parses multipart/form-data for POST, so a real PUT arrives with
    // no fields at all. Send POST plus Laravel's method override; the route
    // stays PUT /api/albums/{album}/images.
    data.append('_method', 'PUT');

    saveBtn.disabled = true;
    saveBtn.textContent = 'Saving…';

    try {
      const res = await fetch(`/api/albums/${panel.dataset.id}/images`, {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrf,
        },
        credentials: 'same-origin',
        body: data,
      });

      const json = await res.json().catch(() => null);
      if (res.ok) {
        location.reload();
        return;
      }

      showErrors(
        json?.errors
          ? Object.values(json.errors).flat()
          : [`Save failed (HTTP ${res.status}).`]
      );
    } catch {
      showErrors(['Network error — the images were not saved.']);
    }

    saveBtn.disabled = false;
    saveBtn.textContent = 'Save images';
  }

  function open() {
    reset();
    showErrors([]);
    render();
    panel.hidden = false;
    toggle.hidden = true;
  }

  function close() {
    panel.hidden = true;
    toggle.hidden = false;
  }

  toggle.addEventListener('click', open);
  cancelBtn.addEventListener('click', close);
  saveBtn.addEventListener('click', save);
  fileInput.addEventListener('change', () => addFiles(fileInput.files ?? []));
  urlAdd.addEventListener('click', addUrl);
  urlInput.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') {
      event.preventDefault();
      addUrl();
    }
  });
})();
