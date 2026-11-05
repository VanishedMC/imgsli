// imgsli — /mine: delete albums. (The .publicity-toggle checkboxes are bound
// by js/publicity.js, loaded first.)
// Delete answers 204 No Content, which a plain form POST can't show (the
// browser stays on the page with the album still listed), so the forms are
// sent with fetch and the row is removed on success.
(() => {
  const csrf = document.querySelector('meta[name=csrf-token]')?.content ?? '';

  document.querySelectorAll('.album-list form').forEach((form) => {
    form.addEventListener('submit', async (e) => {
      if (e.defaultPrevented) return; // the inline confirm() was cancelled
      e.preventDefault();
      const button = form.querySelector('button');
      button.disabled = true;

      let status = 0;
      try {
        const res = await fetch(form.action, {
          method: 'DELETE',
          headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
          credentials: 'same-origin',
        });
        status = res.status;
      } catch {
        // network error: status stays 0
      }

      if (status === 204) {
        const list = form.closest('.album-list');
        form.closest('li').remove();
        if (list && !list.children.length) location.reload(); // shows the "no albums yet" text
        return;
      }
      button.disabled = false;
      alert(status ? 'Delete failed (HTTP ' + status + ')' : 'Delete failed — the server could not be reached.');
    });
  });
})();
