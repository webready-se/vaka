// Applies a saved light or dark choice before the page paints, so it does
// not flash. Loaded in <head> without defer; the system setting is the default.
(function () {
  try {
    var theme = localStorage.getItem('vaka-theme');
    if (theme === 'dark' || theme === 'light') document.documentElement.setAttribute('data-theme', theme);
  } catch (e) {
    // Storage can be blocked; the system setting applies then
  }
})();
