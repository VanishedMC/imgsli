// imgsli — album rename, shared by /mine and /a/{album}.
// A .rename-title button swaps the .album-title with the same data-id for an
// input; Enter or blur saves via PATCH /api/albums/{id} with {title}
// (trimmed, null when emptied), Escape or an unchanged value cancels. On
// success the displayed title, and on the album page also <title> and the
// <image-compare> title attribute (data-page-title marker), are updated.
(() => {
  const csrf = document.querySelector('meta[name=csrf-token]')?.content ?? '';

  document.querySelectorAll('.rename-title').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = btn.dataset.id;
      const titleEl = document.querySelector(`.album-title[data-id="${id}"]`);
      if (!titleEl || titleEl.querySelector('.rename-input')) return;

      const raw = titleEl.dataset.value ?? ''; // stored title ('' = untitled)
      const shown = titleEl.textContent;

      btn.disabled = true;
      const input = document.createElement('input');
      input.type = 'text';
      input.className = 'rename-input';
      input.value = raw;
      input.maxLength = 200;
      input.setAttribute('aria-label', 'Album title');
      titleEl.replaceChildren(input);
      input.focus();
      input.select();

      let finished = false, pending = false;
      const restore = () => {
        if (finished) return;
        finished = true;
        btn.disabled = false;
        titleEl.replaceChildren(document.createTextNode(shown));
      };

      const commit = async (save) => {
        if (finished || pending) return;
        const value = input.value.trim() || null;
        if (!save || value === (raw || null)) {
          restore(); // Escape, or nothing changed
          return;
        }

        pending = true;
        try {
          const res = await fetch(`/api/albums/${id}`, {
            method: 'PATCH',
            headers: {
              Accept: 'application/json',
              'Content-Type': 'application/json',
              'X-CSRF-TOKEN': csrf,
            },
            credentials: 'same-origin',
            body: JSON.stringify({ title: value }),
          });
          if (!res.ok) throw new Error('HTTP ' + res.status);

          finished = true;
          btn.disabled = false;
          titleEl.dataset.value = value ?? '';
          titleEl.replaceChildren(document.createTextNode(value || 'Untitled'));

          if (titleEl.hasAttribute('data-page-title')) {
            document.title = value || 'Untitled';
            const compare = document.querySelector('image-compare');
            if (compare) compare.setAttribute('title', value ?? '');
          }
        } catch (e) {
          alert('Rename failed (' + e.message + ')');
          restore();
        }
      };

      input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          commit(true);
        } else if (e.key === 'Escape') {
          commit(false);
        }
      });
      input.addEventListener('blur', () => commit(true));
    });
  });
})();
