// Vaka dashboard. Strict CSP: no inline script, so everything is rendered
// here from the JSON API, building DOM nodes with textContent (never innerHTML).
(function () {
  'use strict';

  var app = document.getElementById('app');

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;
    return node;
  }

  function render(data) {
    app.textContent = '';
    data.groups.forEach(function (group) {
      var section = el('section', 'group');
      section.appendChild(el('h2', null, group.name || 'No group'));
      var list = el('ul', 'sites');
      group.sites.forEach(function (site) {
        var item = el('li', 'site status-' + site.status);
        item.appendChild(el('span', 'site-label', site.label));
        item.appendChild(el('span', 'site-status', site.status));
        item.appendChild(el('span', 'site-summary', site.summary));
        list.appendChild(item);
      });
      section.appendChild(list);
      app.appendChild(section);
    });
    app.setAttribute('aria-busy', 'false');
  }

  function load() {
    fetch('/?api=overview', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (response) {
        if (response.status === 401) {
          window.location.assign('/?login');
          return null;
        }
        return response.json();
      })
      .then(function (data) { if (data) render(data); });
  }

  load();
  setInterval(load, 60000);
})();
