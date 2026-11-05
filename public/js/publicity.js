// imgsli — album visibility toggle, shared by /mine and /a/{album}.
// Each .publicity-toggle checkbox posts PATCH /api/albums/{id} with
// {is_public}; the new state is kept on success, reverted on failure.
// Loaded (at the end of the body) before the page script.
(() => {
  const csrf = document.querySelector('meta[name=csrf-token]')?.content ?? '';

  document.querySelectorAll('.publicity-toggle').forEach((input) => {
    input.addEventListener('change', async () => {
      const wanted = input.checked;
      input.disabled = true;

      const res = await fetch(`/api/albums/${input.dataset.id}`, {
        method: 'PATCH',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': csrf,
        },
        credentials: 'same-origin',
        body: JSON.stringify({ is_public: wanted }),
      });

      if (res.ok) {
        input.disabled = false;
        return;
      }
      input.checked = !wanted; // revert
      input.disabled = false;
      alert('Visibility change failed (HTTP ' + res.status + ')');
    });
  });
})();
