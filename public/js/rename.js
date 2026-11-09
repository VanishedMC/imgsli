// imgsli — album rename, shared by /mine and /a/{album}.
// A .rename-title button replaces the .album-title with the same data-id by a
// text input placed next to it; Enter or blur saves via PATCH
// /api/albums/{id} with {title} (trimmed, null when emptied), Escape or an
// unchanged value cancels. On success the displayed title, and on the album
// page also <title> and the <image-compare> title attribute (data-page-title
// marker), are updated.
//
// The input is inserted as a SIBLING, never inside the title element: on /mine
// that element is the album <a href> itself, and a click inside an anchor
// navigates (which would abandon the edit).
(() => {
  const csrf = document.querySelector('meta[name=csrf-token]')?.content ?? '';

  document.querySelectorAll('.rename-title').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = btn.dataset.id;
      const titleEl = document.querySelector(`.album-title[data-id="${id}"]`);
      if (!titleEl || btn.disabled) return;

      const raw = titleEl.dataset.value ?? ''; // stored title ('' = untitled)
      const shown = titleEl.textContent;

      btn.disabled = true;
      const input = document.createElement('input');
      input.type = 'text';
      input.className = 'rename-input';
      input.value = raw;
      input.maxLength = 200;
      input.setAttribute('aria-label', 'Album title');
      // Sibling, not child: the title may be an <a>, and clicks inside an
      // anchor navigate away.
      titleEl.hidden = true;
      titleEl.after(input);
      input.focus();
      input.select();

      let finished = false, pending = false;

      const finish = (display, stored) => {
        finished = true;
        btn.disabled = false;
        input.remove();
        titleEl.hidden = false;
        titleEl.textContent = display;
        titleEl.dataset.value = stored;
      };

      const restore = () => {
        if (!finished) finish(shown, raw);
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

          finish(value || 'Untitled', value ?? '');

          if (titleEl.hasAttribute('data-page-title')) {
            document.title = value ? `${value} — Imgsli` : 'Untitled — Imgsli';
            const compare = document.querySelector('image-compare');
            if (compare) compare.setAttribute('title', value ?? '');
          }
        } catch (e) {
          alert('Rename failed (' + e.message + ')');
          pending = false;
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
