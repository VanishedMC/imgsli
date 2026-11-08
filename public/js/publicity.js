// imgsli — album visibility picker, shared by /mine and /a/{album}.
// Each .publicity-toggle <select> posts PATCH /api/albums/{id} with
// {visibility}; the new state is kept on success, reverted on failure.
// Loaded (at the end of the body) before the page script.
(() => {
  const csrf = document.querySelector('meta[name=csrf-token]')?.content ?? '';

  document.querySelectorAll('.publicity-toggle').forEach((select) => {
    let previous = select.value;

    select.addEventListener('change', async () => {
      const wanted = select.value;
      select.disabled = true;

      let ok = false;
      try {
        const res = await fetch(`/api/albums/${select.dataset.id}`, {
          method: 'PATCH',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
          },
          credentials: 'same-origin',
          body: JSON.stringify({ visibility: wanted }),
        });
        ok = res.ok;
        if (!ok) alert('Visibility change failed (HTTP ' + res.status + ')');
      } catch {
        alert('Visibility change failed (network error)');
      }

      if (ok) previous = wanted;
      else select.value = previous; // revert
      select.disabled = false;
    });
  });
})();
