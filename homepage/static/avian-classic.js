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

  document.addEventListener('DOMContentLoaded', function () {
    var nav = document.getElementById('myTopnav');
    if (!nav) return;
    var views = nav.querySelectorAll('button[name="view"]');
    var icon = nav.querySelector('button.icon');

    // The stock script marks the current view by matching the last query
    // value, which misses Tools sub-pages and Recordings by date/species.
    // Fall back to the view= parameter, and treat any view that isn't in
    // the list (Settings, Services, System Controls...) as part of Tools.
    var current = nav.querySelector('.button-hover');
    if (!current) {
      var view = new URLSearchParams(location.search).get('view');
      var match = null, tools = null;
      views.forEach(function (b) {
        if (b.value === view) match = b;
        if (b.value === 'Tools') tools = b;
      });
      current = match || (view ? tools : views[0]);
      if (current) current.classList.add('button-hover');
    }
    if (current) current.setAttribute('aria-current', 'page');

    // Narrow screens: the hamburger becomes "menu · <current view>" and
    // opens the list as a side drawer (see avian-classic.css).
    if (icon) {
      var label = current ? current.textContent.replace(/\s+\d+\s*$/, '').trim() : '';
      icon.setAttribute('data-label', label ? 'menu \u00b7 ' + label : 'menu');
      icon.setAttribute('aria-label', 'views menu');
      icon.setAttribute('aria-expanded', 'false');
      var img = icon.querySelector('img');
      if (img) img.alt = '';
      var isOpen = function () { return nav.classList.contains('responsive'); };
      var close = function () {
        if (!isOpen()) return;
        nav.classList.remove('responsive');
        icon.setAttribute('aria-expanded', 'false');
        icon.focus();
      };
      icon.addEventListener('click', function () {
        // The stock onclick has already toggled .responsive.
        var open = isOpen();
        icon.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) (nav.querySelector('.button-hover') || views[0]).focus();
      });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
      document.addEventListener('click', function (e) {
        if (isOpen() && !nav.contains(e.target)) close();
      });
    }

    // Wide screens: if the row ever overflows, keep the current view in sight.
    if (current && nav.scrollWidth > nav.clientWidth) {
      nav.scrollLeft = current.offsetLeft - (nav.clientWidth - current.offsetWidth) / 2;
    }
  });
})();
