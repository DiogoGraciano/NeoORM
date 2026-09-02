const search = document.querySelector('[data-doc-search]');
if (search) {
  search.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && search.value.trim()) {
      window.location.href = `https://github.com/DiogoGraciano/NeoORM/search?q=${encodeURIComponent(search.value.trim())}`;
    }
  });
}
