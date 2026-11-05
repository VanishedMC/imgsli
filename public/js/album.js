// imgsli compare tool — album page: owner delete button. (The
// .publicity-toggle checkbox is bound by js/publicity.js, loaded first.)
// The <image-compare> element self-initializes from its attributes.
(() => {
  const csrf = document.querySelector('meta[name=csrf-token]')?.content ?? '';

  const btn = document.getElementById('delete-album');
  if (!btn) return;

  btn.addEventListener('click', async () => {
    if (!confirm('Delete this album? This cannot be undone.')) return;
    btn.disabled = true;

    const res = await fetch(`/api/albums/${btn.dataset.id}`, {
      method: 'DELETE',
      headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
      credentials: 'same-origin',
    });

    if (res.status === 204 || res.status === 302) {
      location.assign('/');
      return;
    }

    btn.disabled = false;
    alert('Delete failed (HTTP ' + res.status + ')');
  });
})();
