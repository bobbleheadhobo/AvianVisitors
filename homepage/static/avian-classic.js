/* Classic BirdNET-Pi pages, Avian Visitors look.
   Mirrors the theme resolver in avian/frontend/index.html so the classic
   pages follow whatever light/dark choice was made on the collage (same
   origin, same localStorage key). Loaded synchronously in <head> so a dark
   choice never flashes light. */
(function () {
  var root = document.documentElement;
  var query = '(prefers-color-scheme: dark)';

  function systemTheme() {
    try { return window.matchMedia && window.matchMedia(query).matches ? 'dark' : 'light'; }
    catch (e) { return 'light'; }
  }
  function preference() {
    try {
      var v = localStorage.getItem('bird:theme:v2');
      if (v === 'auto' || v === 'light' || v === 'dark') return v;
      var old = localStorage.getItem('bird:theme');
      return old === 'light' || old === 'dark' ? old : 'auto';
    } catch (e) { return 'auto'; }
  }
  function apply() {
    var p = preference();
    root.setAttribute('data-theme', p === 'light' || p === 'dark' ? p : systemTheme());
  }

  apply();
  window.addEventListener('storage', function (e) {
    if (!e.key || e.key.indexOf('bird:theme') === 0) apply();
  });
  try { window.matchMedia(query).addEventListener('change', apply); } catch (e) {}

  // On narrow screens the view list is one scrolling row; bring the
  // current view into sight instead of leaving it off the right edge.
  document.addEventListener('DOMContentLoaded', function () {
    var nav = document.getElementById('myTopnav');
    var current = nav && nav.querySelector('.button-hover');
    if (!current || nav.scrollWidth <= nav.clientWidth) return;
    nav.scrollLeft = current.offsetLeft - (nav.clientWidth - current.offsetWidth) / 2;
  });
})();
