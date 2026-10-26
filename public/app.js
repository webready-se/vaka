// Vaka dashboard.
//
// Strict CSP: no inline script, no inline styles, no inline handlers. Every
// value from the API goes into the page through textContent or setAttribute,
// never innerHTML, and every click goes through one listener (data-action).
(function () {
  'use strict';

  var REFRESH_MS = 30000;
  var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  var regions = {};
  document.querySelectorAll('[data-region]').forEach(function (node) {
    regions[node.getAttribute('data-region')] = node;
  });
  var filterInput = document.querySelector('[data-filter]');
  var state = { data: null, filter: '', detailId: null, detail: null, timezone: undefined, failed: false, open: {}, autoOpen: null };

  // =================================================================
  // DOM helpers
  // =================================================================

  /** An element: h('a', {class: 'x', href: url}, ['text', node]). Strings become text nodes. */
  function h(tag, attrs, children) {
    var node = document.createElement(tag);
    setAttrs(node, attrs);
    append(node, children);
    return node;
  }

  var SVG = 'http://www.w3.org/2000/svg';
  function s(tag, attrs, children) {
    var node = document.createElementNS(SVG, tag);
    setAttrs(node, attrs);
    append(node, children);
    return node;
  }

  function setAttrs(node, attrs) {
    Object.keys(attrs || {}).forEach(function (name) {
      var value = attrs[name];
      if (value === null || value === undefined || value === false) return;
      node.setAttribute(name, value === true ? '' : String(value));
    });
  }

  function append(node, children) {
    [].concat(children === undefined ? [] : children).forEach(function (child) {
      if (child === null || child === undefined || child === false) return;
      if (Array.isArray(child)) return append(node, child);
      node.appendChild(typeof child === 'string' || typeof child === 'number' ? document.createTextNode(String(child)) : child);
    });
  }

  function clear(node) {
    while (node.firstChild) node.removeChild(node.firstChild);
  }

  // Icons on a 24 px grid, stroke 2, round caps and joins: the design kit's set (icons/), plus Vaka's own in its grammar
  var ICONS = {
    up: ['M5 12.5l4.5 4.5L19 7.5'],
    down: ['M18 6 6 18', 'm6 6 12 12'],
    failing: ['M10.3 4.6 3 17.5a2 2 0 0 0 1.7 3h14.6a2 2 0 0 0 1.7-3L13.7 4.6a2 2 0 0 0-3.4 0Z', 'M12 9.5v4', 'M12 17h.01'],
    paused: ['M9 6v12', 'M15 6v12'],
    pending: ['M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z', 'M12 7v5l3 2'],
    quiet: ['M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z'],
    shield: ['M12 3l7 3v6c0 4.5-3 7.6-7 9-4-1.4-7-4.5-7-9V6z', 'M12 8v4.5', 'M12 15.8v.1'],
    globe: ['M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z', 'M3 12h18', 'M12 3c2.4 2.6 3.7 5.6 3.7 9s-1.3 6.4-3.7 9c-2.4-2.6-3.7-5.6-3.7-9s1.3-6.4 3.7-9z'],
    file: ['M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z', 'M14 3v5h5', 'M12 11.5v3.5', 'M12 18.3v.1'],
    bell: ['M18 16H6l1.5-2.2V10a4.5 4.5 0 0 1 9 0v3.8z', 'M10 19a2 2 0 0 0 4 0'],
    server: ['M4 4.5h16v6H4zM4 13.5h16v6H4z', 'M8 7.5h.01M8 16.5h.01'],
    bulb: ['M9 18h6M10 21h4', 'M12 3a6 6 0 0 0-3.6 10.8c.6.5.6 1.1.6 1.7v.5h6v-.5c0-.6 0-1.2.6-1.7A6 6 0 0 0 12 3z'],
    external: ['M14 4h6v6', 'M20 4 11 13', 'M18 14v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4'],
    close: ['M6 6l12 12M18 6 6 18'],
    copy: ['M11 8.5h7a2.5 2.5 0 0 1 2.5 2.5v7a2.5 2.5 0 0 1-2.5 2.5h-7a2.5 2.5 0 0 1-2.5-2.5v-7A2.5 2.5 0 0 1 11 8.5z', 'M15.5 8.5V6A2.5 2.5 0 0 0 13 3.5H6A2.5 2.5 0 0 0 3.5 6v7A2.5 2.5 0 0 0 6 15.5h2.5'],
    sun: ['M12 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8z', 'M12 2.5v2M12 19.5v2M4.6 4.6 6 6M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4'],
    moon: ['M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z'],
    monitor: ['M4 5h16v11H4z', 'M9 20h6M12 16v4'],
    chevron: ['m6 9 6 6 6-6'],
    info: ['M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z', 'M12 11v5', 'M12 8h.01']
  };

  function icon(name) {
    return s('svg', { class: 'icon', viewBox: '0 0 24 24', 'aria-hidden': 'true', focusable: 'false' },
      ICONS[name].map(function (d) { return s('path', { d: d }); }));
  }

  // Vaka's owl from the Webready design kit (characters/vaka.svg and vaka-awake.svg, 40 x 40):
  // the body, then the eyes (watching, or wide awake while a site is down), then the beak and feet
  var OWL = {
    body: [
      ['path', {'d': 'M10 12.6L7.4 6.2L10.9 8.3L11.5 5.2L14.6 10Z', 'fill': '#19acca'}],
      ['path', {'d': 'M30 12.6L32.6 6.2L29.1 8.3L28.5 5.2L25.4 10Z', 'fill': '#19acca'}],
      ['path', {'d': 'M20 8C27.6 8 33 13.2 33 20.8C33 29.2 27.6 35.6 20 35.6S7 29.2 7 20.8C7 13.2 12.4 8 20 8Z', 'fill': '#19acca'}],
      ['path', {'d': 'M8.4 18.6C5.6 22.6 5.9 29.2 10 33C10.9 28.6 11.2 23.4 8.4 18.6Z', 'fill': '#0e8fab'}],
      ['path', {'d': 'M31.6 18.6C34.4 22.6 34.1 29.2 30 33C29.1 28.6 28.8 23.4 31.6 18.6Z', 'fill': '#0e8fab'}],
      ['path', {'d': 'M13.4 27.2C14 32 16.8 34.4 20 34.4S26 32 26.6 27.2C24.6 28.5 22.3 29.1 20 29.1S15.4 28.5 13.4 27.2Z', 'fill': '#bfeaf3'}],
      ['path', {'d': 'M15.8 30.4l1.4 1 1.4-1M21.4 30.4l1.4 1 1.4-1', 'fill': 'none', 'stroke': '#19acca', 'stroke-width': '1.1', 'stroke-linecap': 'round', 'stroke-linejoin': 'round'}],
      ['path', {'d': 'M20 14.8C17.8 12.4 12.8 11.8 10.6 14.4C8.2 17.2 8.8 22.2 12.2 24.2C15 25.8 18 25.2 20 23.4C22 25.2 25 25.8 27.8 24.2C31.2 22.2 31.8 17.2 29.4 14.4C27.2 11.8 22.2 12.4 20 14.8Z', 'fill': '#8fdcec'}]
    ],
    eyes: [
      ['circle', {'cx': '14.9', 'cy': '18.9', 'r': '4.6', 'fill': '#fff'}],
      ['circle', {'cx': '25.1', 'cy': '18.9', 'r': '4.6', 'fill': '#fff'}],
      ['circle', {'cx': '14.9', 'cy': '19.1', 'r': '2.4', 'fill': '#0f172a'}],
      ['circle', {'cx': '25.1', 'cy': '19.1', 'r': '2.4', 'fill': '#0f172a'}],
      ['circle', {'cx': '15.8', 'cy': '18.2', 'r': '0.8', 'fill': '#fff'}],
      ['circle', {'cx': '26', 'cy': '18.2', 'r': '0.8', 'fill': '#fff'}]
    ],
    awakeEyes: [
      ['circle', {'cx': '14.9', 'cy': '18.9', 'r': '5.3', 'fill': '#fff'}],
      ['circle', {'cx': '25.1', 'cy': '18.9', 'r': '5.3', 'fill': '#fff'}],
      ['circle', {'cx': '14.9', 'cy': '19.1', 'r': '1.4', 'fill': '#0f172a'}],
      ['circle', {'cx': '25.1', 'cy': '19.1', 'r': '1.4', 'fill': '#0f172a'}],
      ['circle', {'cx': '15.5', 'cy': '18.5', 'r': '0.8', 'fill': '#fff'}],
      ['circle', {'cx': '25.7', 'cy': '18.5', 'r': '0.8', 'fill': '#fff'}]
    ],
    beak: [
      ['path', {'d': 'M18.4 22.8L20 26L21.6 22.8Q20 23.4 18.4 22.8Z', 'fill': '#f5b942'}],
      ['path', {'d': 'M16.2 35.4v1.8M17.8 35.4v1.8M22.2 35.4v1.8M23.8 35.4v1.8', 'stroke': '#f5b942', 'stroke-width': '1.3', 'stroke-linecap': 'round'}]
    ]
  };

  /** The owl in the night panel. Awake: wide eyes, small pupils. Its eyes blink while all is calm (CSS). */
  function owl(awake) {
    var draw = function (items) { return items.map(function (item) { return s(item[0], item[1]); }); };
    return s('svg', { class: 'watch-owl' + (awake ? ' is-awake' : ''), viewBox: '0 0 40 40', 'aria-hidden': 'true', focusable: 'false' },
      draw(OWL.body).concat([s('g', { class: 'owl-eyes' }, draw(awake ? OWL.awakeEyes : OWL.eyes))], draw(OWL.beak)));
  }

  // =================================================================
  // Words and times
  // =================================================================

  var STATUS_WORDS = { up: 'Up', down: 'Down', failing: 'Failing', paused: 'Paused', pending: 'Waiting' };

  function statusLabel(status) {
    return h('span', { class: 'status status-' + status }, [icon(status), STATUS_WORDS[status]]);
  }

  function plural(count, one, many) {
    return count + ' ' + (count === 1 ? one : many);
  }

  /** A length of time the way a person says it (as human_duration() in PHP). */
  function duration(seconds) {
    if (seconds < 60) return 'less than a minute';
    var minutes = Math.floor(seconds / 60);
    if (minutes < 60) return minutes + ' min';
    var hours = Math.floor(minutes / 60);
    if (hours < 24) return hours + ' h' + (minutes % 60 ? ' ' + (minutes % 60) + ' min' : '');
    var days = Math.floor(hours / 24);
    return plural(days, 'day', 'days') + (hours % 24 ? ' ' + (hours % 24) + ' h' : '');
  }

  /** The short form for big numbers: "14 min", "2 h", "3 days". */
  function shortDuration(seconds) {
    if (seconds < 60) return '<1 min';
    var minutes = Math.floor(seconds / 60);
    if (minutes < 60) return minutes + ' min';
    var hours = Math.floor(minutes / 60);
    if (hours < 48) return hours + ' h';
    return Math.floor(hours / 24) + ' days';
  }

  function secondsSince(iso) {
    return Math.max(0, Math.round((Date.now() - Date.parse(iso)) / 1000));
  }

  function ago(iso) {
    var seconds = secondsSince(iso);
    return seconds < 60 ? 'just now' : duration(seconds) + ' ago';
  }

  function dateParts(date) {
    var parts = {};
    new Intl.DateTimeFormat('en-GB', { timeZone: state.timezone, year: 'numeric', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
      .formatToParts(date)
      .forEach(function (part) { parts[part.type] = part.value; });
    parts.month = parts.month.slice(0, 3);
    return parts;
  }

  /** "14:02" today, "24 Oct 14:02" before that, in the server's time zone. */
  function clock(iso) {
    var p = dateParts(new Date(iso));
    var today = dateParts(new Date());
    var time = p.hour + ':' + p.minute;
    return p.day === today.day && p.month === today.month && p.year === today.year ? time : p.day + ' ' + p.month + ' ' + time;
  }

  /** "7 Nov 2026" */
  function date(iso) {
    var p = dateParts(new Date(iso));
    return p.day + ' ' + p.month + ' ' + p.year;
  }

  function day(isoDate) {
    var p = dateParts(new Date(isoDate + 'T12:00:00Z'));
    return p.day + ' ' + p.month;
  }

  function median(values) {
    if (!values.length) return null;
    var sorted = values.slice().sort(function (a, b) { return a - b; });
    var mid = Math.floor(sorted.length / 2);
    return sorted.length % 2 ? sorted[mid] : Math.round((sorted[mid - 1] + sorted[mid]) / 2);
  }

  // =================================================================
  // Loading
  // =================================================================

  function api(path, options) {
    return fetch(path, Object.assign({ credentials: 'same-origin', headers: { Accept: 'application/json' } }, options || {}))
      .then(function (response) {
        if (response.status === 401) {
          window.location.assign('/?login');
          throw new Error('Logged out');
        }
        return response.json().then(function (body) {
          if (!response.ok) throw new Error(body.error || 'Something went wrong');
          return body;
        });
      });
  }

  function load() {
    return api('/?api=overview').then(function (data) {
      state.data = data;
      state.timezone = data.timezone;
      state.failed = false;
      render();
    }).catch(function (error) {
      if (error.message === 'Logged out') return;
      state.failed = true;
      renderBanner();
    });
  }

  // =================================================================
  // Rendering
  // =================================================================

  function allSites() {
    return state.data.groups.reduce(function (sites, group) { return sites.concat(group.sites); }, []);
  }

  function isQuiet(site) {
    return Boolean(site.snoozed_until || site.maintenance);
  }

  /** Certificates that need attention. A site that is down already says why, certificate or not. */
  function certIssues() {
    return allSites().filter(function (site) {
      return site.ssl && (site.ssl.status === 'warn' || (site.ssl.status === 'fail' && site.status !== 'down'));
    });
  }

  /** Domains that expire soon or have expired: one entry per domain, with the sites that use it. */
  function domainIssues() {
    var byName = {};
    var list = [];
    allSites().forEach(function (site) {
      var d = site.domain;
      if (!d || (d.status !== 'warn' && d.status !== 'fail')) return;
      if (!byName[d.domain]) list.push(byName[d.domain] = { domain: d, sites: [] });
      byName[d.domain].sites.push(site);
    });
    return list.sort(function (a, b) { return (a.domain.days_left || 0) - (b.domain.days_left || 0); });
  }

  /** Sites with files that anyone can download but that should be private. */
  function exposedIssues() {
    return allSites().filter(function (site) {
      return site.exposed && (site.exposed.status === 'fail' || site.exposed.status === 'warn');
    });
  }

  function render() {
    renderRunState();
    renderBanner();
    renderWatch();
    renderAttention();
    renderGroups();
    // No list to show or filter before the first site
    regions.sites.hidden = state.data.totals.sites === 0;
    renderTab();
    document.getElementById('app').setAttribute('aria-busy', 'false');
  }

  function renderRunState() {
    var node = regions['run-state'];
    var last = state.data.last_run_at;
    var watched = state.data.totals.sites - state.data.totals.paused;
    var text = node.querySelector('[data-run-text]');
    clear(text);
    // On a phone only the freshness shows: "Checked just now"
    append(text, state.data.totals.sites === 0 ? 'No sites yet' : last
      ? [h('span', { class: 'run-count' }, 'Watching ' + plural(watched, 'site', 'sites') + ' · '), 'checked ' + ago(last)]
      : 'Not checked yet');
    // Nothing to watch is not an alarm: no red before the first site
    node.className = 'run-pill' + (state.data.run_ok ? ' is-ok' : state.data.totals.sites === 0 ? '' : ' is-stale');
    // The footer's own half: the tool, its version, where the results come from and how fresh they are
    var foot = document.querySelector('[data-foot]');
    foot.textContent = 'Vaka ' + foot.getAttribute('data-version') + ' · ' + (last ? 'checks run every minute, the last at ' + clock(last) : 'not checked yet');
  }

  function renderBanner() {
    var node = regions.banner;
    clear(node);
    if (state.failed) {
      append(node, h('p', { class: 'banner', role: 'alert' }, [icon('failing'), h('span', {}, [h('strong', {}, 'Vaka cannot be reached right now. '), 'This page tries again every 30 seconds.'])]));
      return;
    }
    var data = state.data;
    if (!data || data.totals.sites === 0) return;
    if (!data.last_run_at) {
      append(node, h('div', { class: 'banner', role: 'alert' }, [icon('pending'), h('span', {}, [
        h('strong', {}, 'Vaka has not checked anything yet. '),
        data.setup ? ['Run it every minute from cron: ', h('code', {}, data.setup.cron)] : 'Whoever runs Vaka needs to start it from cron.'
      ])]));
    } else if (!data.run_ok) {
      append(node, h('div', { class: 'banner', role: 'alert' }, [icon('failing'), h('span', {}, [
        h('strong', {}, 'The last check was ' + ago(data.last_run_at) + '. '),
        'Is cron still running ', h('code', {}, 'php vaka run'), ' every minute? Until it does, what you see here is out of date.'
      ])]));
    }
  }

  // -----------------------------------------------------------------
  // The watch: how everything is, in one sentence
  // -----------------------------------------------------------------

  function renderWatch() {
    var node = regions.watch;
    clear(node);
    var t = state.data.totals;
    if (t.sites === 0) {
      append(node, emptyState());
      return;
    }

    var sites = allSites();
    var downNow = sites.filter(function (site) { return site.status === 'down' && !isQuiet(site); }).length;
    var downQuiet = t.down - downNow;
    var certs = certIssues().length;
    var domains = domainIssues();
    var exposed = exposedIssues();
    var watched = t.sites - t.paused;
    var kind, kicker, title, line;

    if (downNow > 0) {
      kind = 'alert';
      kicker = 'Needs you now';
      title = plural(downNow, 'site is down', 'sites are down');
      // The numbers beside it say how many are down or checking; the line says what they do not
      line = downQuiet ? downQuiet + ' more ' + (downQuiet === 1 ? 'is' : 'are') + ' down but snoozed or in a maintenance window.'
        : t.failing ? 'Everything else is up or being checked again.' : 'Everything else is up.';
    } else if (downQuiet > 0) {
      kind = 'watch';
      kicker = 'Keeping watch';
      title = 'Nothing needs you right now';
      line = plural(downQuiet, 'site is', 'sites are') + ' down, but snoozed or in a maintenance window.';
    } else if (t.failing > 0) {
      kind = 'watch';
      kicker = 'Checking again';
      title = 'Something looks off';
      line = 'Vaka checks again within a minute: one failure is not an outage yet.';
    } else if (t.up === 0) {
      kind = 'empty';
      kicker = 'Getting ready';
      title = 'Waiting for the first checks';
      line = 'Results show up here after the next run.';
    } else {
      // Up, but not all quiet while a file is public or a certificate is about to run out
      var notes = [];
      if (exposed.length) notes.push(plural(exposed.length, 'site has', 'sites have') + ' private files that anyone can download.');
      if (domains.length) notes.push(plural(domains.length, 'domain needs', 'domains need') + ' renewing.');
      if (certs) notes.push(plural(certs, 'certificate needs', 'certificates need') + ' renewing soon.');
      kind = notes.length ? 'watch' : 'calm';
      kicker = 'Keeping watch';
      title = !notes.length ? 'All quiet.' : t.up === watched ? (watched === 1 ? 'Your site is up' : 'All sites are up') : plural(t.up, 'site is up', 'sites are up');
      var last = state.data.last_incident;
      line = notes.length ? notes.join(' ')
        : last ? 'The last outage was ' + ago(last.at) + ', on ' + last.label + '.' : 'Nothing has gone down since Vaka started watching.';
    }

    var ms = median(sites.filter(function (site) { return site.ms !== null && site.ms !== undefined; }).map(function (site) { return site.ms; }));
    var month = sites.filter(function (site) { return site.status !== 'paused' && site.uptime_30d !== null && site.uptime_30d !== undefined; }).map(function (site) { return site.uptime_30d; });
    var average = month.length ? Math.floor(month.reduce(function (a, b) { return a + b; }, 0) / month.length * 100) / 100 : null;

    var stats = [h('li', { class: 'is-up' }, [h('b', {}, String(t.up)), h('span', {}, 'Up')])];
    if (t.down) stats.push(h('li', { class: 'is-down' }, [h('b', {}, String(t.down)), h('span', {}, 'Down')]));
    if (t.failing) stats.push(h('li', { class: 'is-warn' }, [h('b', {}, String(t.failing)), h('span', {}, 'Checking')]));
    // Certificates, domains and exposed files are listed right below; the panel says how the sites are
    if (ms !== null) stats.push(h('li', {}, [h('b', {}, ms + ' ms'), h('span', {}, 'Typical response')]));
    if (average !== null) stats.push(h('li', {}, [h('b', {}, average + '%'), h('span', {}, 'Up, 30 days')]));
    if (t.paused) stats.push(h('li', {}, [h('b', {}, String(t.paused)), h('span', {}, 'Paused')]));

    append(node, h('div', { class: 'watch is-' + kind }, [
      h('div', {}, [
        h('p', { class: 'watch-kicker' }, kicker),
        h('h1', {}, title),
        h('p', { class: 'watch-line' }, line),
        h('ul', { class: 'watch-stats', 'aria-label': 'At a glance' }, stats)
      ]),
      owl(kind === 'alert')
    ]));
  }

  function command(text) {
    return h('div', { class: 'command' }, [
      h('code', {}, text),
      h('button', { type: 'button', class: 'button', 'data-action': 'copy', 'data-copy': text, 'aria-label': 'Copy ' + text }, [icon('copy'), 'Copy'])
    ]);
  }

  /** The first steps, ticked off as they get done: cron runs, alerts have somewhere to go. */
  function emptyState() {
    var data = state.data;
    var setup = data.setup;
    if (!setup) {
      return h('div', { class: 'empty' }, [
        h('h2', {}, 'No sites here yet'),
        h('p', {}, 'Nobody has added sites to your groups yet. Ask whoever runs Vaka for your sites.')
      ]);
    }
    var step = function (done, title, body) {
      return h('li', { class: done ? 'is-done' : null }, h('div', {}, [
        h('p', {}, [title, done ? h('span', { class: 'visually-hidden' }, ' (done)') : null])
      ].concat(body)));
    };
    var running = data.run_ok;
    return h('div', { class: 'empty' }, [
      h('h2', {}, 'Nothing to watch yet'),
      h('p', {}, ['Three steps, and Vaka keeps watch from the next minute on. Run the commands on the server, in ', h('code', {}, setup.dir), '.']),
      h('ol', { class: 'steps' }, [
        step(false, 'Add a site, or import a whole list', [
          command(setup.php + ' vaka site:add https://shop.example'),
          command(setup.php + ' vaka site:import sites.csv'),
          h('small', {}, 'A list is a CSV file with a url column, and optionally group and enabled.')
        ]),
        step(running, running ? 'Cron runs the checks every minute' : 'Run the checks every minute from cron', running
          ? [h('small', {}, 'Last run ' + ago(data.last_run_at) + '.')]
          : [command(setup.cron), data.last_run_at ? h('small', {}, 'The last run was ' + ago(data.last_run_at) + ': is cron still running it?') : null]),
        step(setup.alerts, setup.alerts ? 'Alerts have somewhere to go: send a test to be sure' : 'Tell Vaka where alerts go (SLACK_WEBHOOK_URL in .env), then send a test', [
          command(setup.php + ' vaka alert:test')
        ])
      ])
    ]);
  }

  /** The tab says it too: an alarmed owl and a count when something is down. */
  function renderTab() {
    var down = allSites().filter(function (site) { return site.status === 'down' && !isQuiet(site); }).length;
    document.title = (down ? '(' + down + ') ' : '') + 'Vaka';
    var link = document.querySelector('[data-favicon]');
    var href = down ? 'favicon-alert.svg' : 'favicon.svg';
    if (link.getAttribute('href') !== href) link.setAttribute('href', href);
  }

  // -----------------------------------------------------------------
  // Needs your attention: one compact row per problem, details on demand.
  // Outages first, sites on one server together, the quiet ones last.
  // -----------------------------------------------------------------

  function renderAttention() {
    var node = regions.attention;
    clear(node);
    var problems = allSites().filter(function (site) { return site.status === 'down' || site.status === 'failing'; });
    var certs = certIssues().sort(function (a, b) { return (a.ssl.status === 'fail' ? 0 : 1) - (b.ssl.status === 'fail' ? 0 : 1) || (a.ssl.days_left || 0) - (b.ssl.days_left || 0); });
    var domains = domainIssues();
    var exposed = exposedIssues();
    node.hidden = problems.length + certs.length + domains.length + exposed.length === 0;
    if (node.hidden) return;

    var rank = function (site) { return site.status === 'failing' ? 1 : 0; };
    problems.sort(function (a, b) { return rank(a) - rank(b) || String(a.since).localeCompare(String(b.since)); });

    // Several sites down on one server are one incident, as in the alerts
    var items = [];
    var quiet = [];
    var byServer = {};
    problems.forEach(function (site) {
      if (isQuiet(site)) return quiet.push(site);
      var key = site.status === 'down' && site.server ? site.server : null;
      if (key && byServer[key]) return byServer[key].sites.push(site);
      var item = { server: key, sites: [site] };
      if (key) byServer[key] = item;
      items.push(item);
    });

    // A single outage opens by itself: it is the one thing to look at
    var outages = items.filter(function (item) { return item.sites[0].status === 'down'; });
    state.autoOpen = outages.length === 1 ? itemKey(outages[0]) : null;

    // Certificates and exposed files after the outages: what is broken now before what is about to be
    var checks = certs.map(function (site) { return { rank: site.ssl.status === 'fail' ? 0 : 1, row: certIncident(site) }; })
      .concat(domains.map(function (item) { return { rank: item.domain.status === 'fail' ? 0 : 1, row: domainIncident(item) }; }))
      .concat(exposed.map(function (site) { return { rank: site.exposed.status === 'fail' ? 0 : 1, row: exposedIncident(site) }; }))
      .sort(function (a, b) { return a.rank - b.rank; });

    var rows = items.map(function (item) {
      return item.sites.length > 1 ? serverIncident(item) : siteIncident(item.sites[0]);
    }).concat(checks.map(function (check) { return check.row; }));
    if (quiet.length) rows.push(quietGroup(quiet));

    append(node, h('h2', { class: 'section-title', id: 'attention-title' }, 'Needs your attention'));
    append(node, h('ul', { class: 'incidents' }, rows));
  }

  function itemKey(item) {
    return item.sites.length > 1 ? 'server:' + item.server : 'site:' + item.sites[0].id;
  }

  /** A row that opens: what is wrong at a glance, the rest one click away. Open rows stay open across refreshes. */
  function incident(key, kind, iconName, title, what, reason, time, body) {
    var open = key in state.open ? state.open[key] : state.autoOpen === key;
    return h('li', { class: 'incident is-' + kind }, h('details', { 'data-key': key, open: open }, [
      h('summary', {}, [
        h('span', { class: 'medallion' }, icon(iconName)),
        h('span', { class: 'incident-title' }, [h('b', {}, title), ' ', h('span', { class: 'incident-what' }, what), reason ? h('small', {}, reason) : null]),
        time,
        icon('chevron')
      ]),
      h('div', { class: 'incident-body' }, body)
    ]));
  }

  function advice(text) {
    return text ? h('p', { class: 'advice' }, [icon('bulb'), h('span', {}, text)]) : null;
  }

  function timeBox(big, small) {
    return h('span', { class: 'incident-time' }, [h('span', {}, small), h('b', {}, big)]);
  }

  function siteIncident(site) {
    var quiet = isQuiet(site);
    var kind = quiet ? 'quiet' : site.status;
    var what = site.status === 'down' ? 'is down' : 'failed a check';
    var time = site.snoozed_until
      ? timeBox(clock(site.snoozed_until), 'Snoozed until')
      : timeBox(site.since ? shortDuration(secondsSince(site.since)) : '–', site.status === 'down' ? (site.maintenance ? 'In maintenance' : 'Down for') : 'Failing for');
    var meta = [site.group || 'No group', site.since ? 'since ' + clock(site.since) : null].filter(Boolean).join(' · ');

    return incident('site:' + site.id, kind, quiet ? 'quiet' : site.status, site.label, what, site.summary, time, [
      h('p', { class: 'incident-meta' }, meta),
      advice(site.advice),
      badges(site, true),
      h('div', { class: 'actions' }, siteActions(site, true))
    ]);
  }

  function serverIncident(item) {
    var sites = item.sites;
    var reasons = sites.map(function (site) { return site.summary; });
    var same = reasons.every(function (reason) { return reason === reasons[0]; });
    var earliest = sites.map(function (site) { return site.since; }).filter(Boolean).sort()[0];

    return incident('server:' + item.server, 'down', 'server', 'Server ' + item.server, plural(sites.length, 'site is', 'sites are') + ' down',
      same ? reasons[0] : 'The sites fail in different ways',
      timeBox(earliest ? shortDuration(secondsSince(earliest)) : '–', 'Down for'), [
        h('p', { class: 'incident-meta' }, earliest ? 'Since ' + clock(earliest) + '. Open a site to see its history.' : ''),
        same ? advice(sites[0].advice) : null,
        h('ul', { class: 'incident-sites', 'aria-label': 'Sites on this server' }, sites.map(function (site) {
          return h('li', {}, h('button', { type: 'button', class: 'chip', 'data-action': 'open', 'data-site': site.id }, site.label));
        })),
        h('div', { class: 'actions' }, [snoozeGroup(sites, 'Snooze all')])
      ]);
  }

  function certificateWords(ssl) {
    if (ssl.status === 'fail') return 'certificate is not valid';
    var days = ssl.days_left;
    return 'certificate expires ' + (days === 0 ? 'today' : days === 1 ? 'tomorrow' : 'in ' + days + ' days');
  }

  function certIncident(site) {
    var kind = site.ssl.status === 'fail' ? 'down' : 'warn';
    return incident('cert:' + site.id, kind, 'shield', site.label, certificateWords(site.ssl), site.ssl.summary,
      site.ssl.status === 'fail' ? timeBox('Invalid', 'Certificate') : timeBox(plural(site.ssl.days_left, 'day', 'days'), 'Expires in'), [
        h('p', { class: 'incident-meta' }, site.group || 'No group'),
        advice(site.ssl.advice),
        h('div', { class: 'actions' }, siteActions(site, false))
      ]);
  }

  function domainWords(domain) {
    if (domain.status === 'fail') return 'domain has expired';
    var days = domain.days_left;
    return 'domain expires ' + (days === 0 ? 'today' : days === 1 ? 'tomorrow' : 'in ' + days + ' days');
  }

  /** One row per domain, however many sites use it: renewing it is one job. */
  function domainIncident(item) {
    var domain = item.domain;
    var sites = item.sites;
    var others = sites.map(function (site) { return site.label; }).filter(function (label) { return label !== domain.domain; });
    var when = domain.expires ? (domain.status === 'fail' ? 'Ran out ' : 'Runs out ') + date(domain.expires) + (domain.registrar ? ', with ' + domain.registrar : '') : domain.summary;
    return incident('domain:' + domain.domain, domain.status === 'fail' ? 'down' : 'warn', 'globe', domain.domain, domainWords(domain), when,
      domain.status === 'fail' ? timeBox('Expired', 'Domain') : timeBox(plural(domain.days_left, 'day', 'days'), 'Expires in'), [
        h('p', { class: 'incident-meta' }, [sites[0].group || 'No group', others.length ? ' · used by ' + others.join(', ') : null]),
        advice(domain.advice),
        h('div', { class: 'actions' }, siteActions(sites[0], false))
      ]);
  }

  function exposedWords(exposed) {
    return exposed.found.length === 1 ? exposed.found[0].label + ' is public' : exposed.found.length + ' private files are public';
  }

  function exposedIncident(site) {
    var exposed = site.exposed;
    var found = exposed.found;
    return incident('exposed:' + site.id, exposed.status === 'fail' ? 'down' : 'warn', 'file', site.label, exposedWords(exposed),
      found.length === 1 ? found[0].what : found.map(function (file) { return file.label; }).join(', '),
      timeBox(exposed.since ? shortDuration(secondsSince(exposed.since)) : '–', 'Public for'), [
        h('p', { class: 'incident-meta' }, site.group || 'No group'),
        h('ul', { class: 'incident-files', 'aria-label': 'Public files' }, found.map(function (file) {
          return h('li', {}, [h('code', {}, file.label), h('span', {}, file.what)]);
        })),
        advice(exposed.advice),
        h('div', { class: 'actions' }, siteActions(site, false))
      ]);
  }

  /** Snoozed and maintenance outages, folded into one row: known, and quiet for now. */
  function quietGroup(sites) {
    var snoozed = sites.filter(function (site) { return site.snoozed_until; }).length;
    var words = snoozed === sites.length ? 'snoozed' : snoozed ? 'snoozed or in maintenance' : 'in maintenance';
    var again = sites.length === 1 ? 'Vaka alerts again if it is still down when that ends' : 'Vaka alerts again for each that is still down when that ends';
    return incident('quiet', 'quiet', 'quiet', plural(sites.length, 'site is', 'sites are') + ' down, ' + words, '', again, null, [
      h('ul', { class: 'incidents is-nested' }, sites.map(siteIncident))
    ]);
  }

  /** Snoozed, in maintenance, unstable. In an incident card the snooze is in the time box already. */
  function badges(site, inCard) {
    var list = [];
    if (site.snoozed_until && !inCard) list.push(h('li', { class: 'badge' }, [icon('quiet'), 'Snoozed until ' + clock(site.snoozed_until)]));
    if (site.maintenance) list.push(h('li', { class: 'badge' }, [icon('quiet'), 'In a maintenance window: not alerted']));
    if (site.unstable) list.push(h('li', { class: 'badge is-warn' }, [icon('failing'), 'Unstable: went down several times lately']));
    return list.length ? h('ul', { class: 'badges' }, list) : null;
  }

  function snoozeGroup(sites, label) {
    var ids = sites.map(function (site) { return site.id; }).join(',');
    var who = sites.length > 1 ? plural(sites.length, 'site', 'sites') : sites[0].label;
    var words = { '1h': '1 hour', '4h': '4 hours', '24h': '24 hours' };
    return h('div', { class: 'segmented', role: 'group', 'aria-label': label + ' ' + who }, [
      h('span', {}, [icon('quiet'), label]),
      ['1h', '4h', '24h'].map(function (length) {
        return h('button', { type: 'button', 'data-action': 'snooze', 'data-sites': ids, 'data-for': length, 'aria-label': 'Snooze ' + who + ' for ' + words[length] }, length.replace('h', ' h'));
      })
    ]);
  }

  function siteActions(site, withSnooze) {
    var actions = [];
    if (withSnooze && site.snoozed_until) {
      actions.push(h('button', { type: 'button', class: 'button', 'data-action': 'unsnooze', 'data-sites': site.id }, [icon('bell'), 'Unsnooze']));
    } else if (withSnooze && (site.status === 'down' || site.status === 'failing')) {
      actions.push(snoozeGroup([site], 'Snooze'));
    }
    actions.push(h('button', { type: 'button', class: 'button', 'data-action': 'open', 'data-site': site.id }, [icon('pending'), 'History']));
    if (/^https?:\/\//.test(site.url)) {
      actions.push(h('a', { class: 'button', href: site.url, target: '_blank', rel: 'noopener noreferrer' }, ['Open site', icon('external'), h('span', { class: 'visually-hidden' }, ' (opens in a new tab)')]));
    }
    return actions;
  }

  // -----------------------------------------------------------------
  // All sites, by group
  // -----------------------------------------------------------------

  function matches(site, group, filter) {
    if (!filter) return true;
    return [site.label, site.name, group.name || 'No group'].join(' ').toLowerCase().indexOf(filter) !== -1;
  }

  function renderGroups() {
    var node = regions.groups;
    clear(node);
    var filter = state.filter.trim().toLowerCase();
    var shown = 0;

    state.data.groups.forEach(function (group) {
      var sites = group.sites.filter(function (site) { return matches(site, group, filter); });
      if (!sites.length) return;
      shown += sites.length;
      var title = group.name || 'No group';
      append(node, h('section', { class: 'group', 'aria-label': title }, [
        h('div', { class: 'group-head' }, [
          h('h3', {}, title),
          h('ul', { class: 'group-meta' }, [
            h('li', {}, plural(group.sites.length, 'site', 'sites')),
            h('li', {}, [icon('bell'), 'Alerts ' + group.alerting])
          ])
        ]),
        h('div', { class: 'columns', 'aria-hidden': 'true' }, [
          h('span', {}, 'Status'), h('span', {}, 'Site'), h('span', {}, 'Last 30 days'), h('span', { class: 'right' }, 'Response'), h('span', { class: 'right' }, 'Uptime'),
          h('span', { class: 'right' }, 'Headers')
        ]),
        h('ul', { class: 'site-list' }, sites.map(siteRow))
      ]));
    });

    if (!shown && state.data.totals.sites) {
      append(node, h('p', { class: 'no-match' }, 'No sites match "' + state.filter.trim() + '".'));
    }
  }

  /** One bar per day: green when up all day, amber with some downtime, red with a lot, grey without data. */
  function days(list, large, paused) {
    return h('span', { class: 'days' + (large ? ' is-large' : ''), 'aria-hidden': 'true' }, list.map(function (d) {
      if (paused) return h('i', { class: 'is-none', title: day(d.date) + ': paused' });
      var cls = d.up === null ? 'is-none' : d.up >= 99.95 ? null : d.up >= 95 ? 'is-partial' : 'is-down';
      return h('i', { class: cls, title: day(d.date) + ': ' + (d.up === null ? 'not watched yet' : d.up + '% up') });
    }));
  }

  function siteRow(site) {
    var ms = site.ms !== null && site.ms !== undefined ? site.ms + ' ms' : '–';
    var month = site.status !== 'paused' && site.uptime_30d !== null && site.uptime_30d !== undefined ? site.uptime_30d + '%' : '–';
    var notes = [];
    if (site.name) notes.push(site.name);
    if (site.cdn) notes.push('behind ' + site.cdn);
    if (site.snoozed_until) notes.push('snoozed');
    if (site.maintenance) notes.push('in maintenance');
    var subtitle = notes.join(' · ');
    var cert = site.ssl && site.ssl.status !== 'ok' ? h('span', { class: site.ssl.status === 'fail' ? 'is-down' : 'is-warn' }, certificateWords(site.ssl)) : null;
    var registration = site.domain && (site.domain.status === 'warn' || site.domain.status === 'fail') ? h('span', { class: site.domain.status === 'fail' ? 'is-down' : 'is-warn' }, domainWords(site.domain)) : null;
    var files = site.exposed && site.exposed.status !== 'ok' ? h('span', { class: site.exposed.status === 'fail' ? 'is-down' : 'is-warn' }, exposedWords(site.exposed)) : null;
    var small = [subtitle, cert, registration, files].filter(Boolean).reduce(function (list, part) { return list.length ? list.concat([' · ', part]) : [part]; }, []);
    var grade = site.headers && site.headers.grade ? site.headers.grade : null;

    return h('li', {}, h('button', {
      type: 'button',
      class: 'site-row',
      'data-action': 'open',
      'data-site': site.id,
      'aria-label': site.label + ': ' + STATUS_WORDS[site.status] + (site.status === 'up' ? ', ' + ms + ', ' + month + ' up over 30 days' : '. ' + site.summary) +
        (grade ? '. Security headers: grade ' + grade : '')
    }, [
      statusLabel(site.status),
      h('span', { class: 'site-name' }, [h('strong', {}, site.label), small.length ? h('small', {}, small) : null]),
      days(site.days, false, site.status === 'paused'),
      h('span', { class: 'site-figures' }, [
        h('span', { class: 'site-figure' }, [ms, h('small', {}, 'now')]),
        h('span', { class: 'site-figure' }, [month, h('small', {}, '30 days')]),
        h('span', { class: 'site-figure' }, [grade || '–', h('small', {}, 'headers')])
      ])
    ]));
  }

  // =================================================================
  // Site detail: a drawer from the right
  // =================================================================

  function openDetail(id, opener) {
    state.detailId = id;
    if (opener) state.opener = opener;
    if (window.location.hash !== '#site-' + id) history.replaceState(null, '', '#site-' + id);
    return api('/?api=site&id=' + encodeURIComponent(id)).then(function (detail) {
      state.detail = detail;
      renderDetail();
      if (!regions.detail.open) regions.detail.showModal();
    }).catch(function (error) {
      if (error.message !== 'Logged out') toast(error.message);
      closeDetail();
    });
  }

  function closeDetail() {
    state.detailId = null;
    state.detail = null;
    if (regions.detail.open) regions.detail.close();
    if (window.location.hash) history.replaceState(null, '', window.location.pathname + window.location.search);
    if (state.opener && document.body.contains(state.opener)) state.opener.focus();
    state.opener = null;
  }

  function percent(value) {
    return value === null || value === undefined ? '–' : value + '%';
  }

  function renderDetail() {
    var d = state.detail;
    var site = d.site;
    var node = regions.detail;
    clear(node);

    var kind = isQuiet(site) && site.status === 'down' ? 'quiet' : site.status;
    var since = site.since ? (site.status === 'up' ? 'Up since ' : site.status === 'down' ? 'Down since ' : 'Since ') + clock(site.since) + ' (' + duration(secondsSince(site.since)) + ')' : STATUS_WORDS[site.status];
    var settings = [
      ['Group', (site.group || 'No group')],
      ['Checked every', site.interval_minutes + ' min'],
      ['Server', site.cdn ? 'Behind ' + site.cdn : (site.server || 'Not known yet')],
      ['Certificate', site.ssl ? site.ssl.summary : (/^https:/.test(site.url) ? 'Not checked yet' : 'No https')],
      ['Domain', site.domain ? site.domain.summary : 'Not checked yet'],
      ['Exposed files', site.exposed ? site.exposed.summary : 'Not checked yet']
    ];
    if (site.keyword) settings.splice(2, 0, ['Looks for the text', '"' + site.keyword + '"']);
    d.maintenance.forEach(function (w) {
      settings.push(['Maintenance', w.when + (w.label ? ' (' + w.label + ')' : '') + (w.active ? ', now' : '')]);
    });

    append(node, h('div', { class: 'drawer-inner is-' + kind }, [
      h('div', { class: 'drawer-head' }, [
        h('div', { class: 'medallion' }, icon(kind === 'quiet' ? 'quiet' : site.status)),
        h('div', {}, [h('h2', { id: 'detail-title' }, site.label), h('p', {}, since)]),
        h('button', { type: 'button', class: 'close-button', 'data-action': 'close', 'aria-label': 'Close' }, icon('close'))
      ]),
      site.summary ? h('p', { class: 'incident-summary' }, site.summary) : null,
      advice(site.advice),
      site.ssl && site.ssl.advice ? advice(site.ssl.summary + '. ' + site.ssl.advice) : null,
      site.domain && site.domain.advice ? advice(site.domain.summary + '. ' + site.domain.advice) : null,
      site.exposed && site.exposed.advice ? advice(site.exposed.summary + '. ' + site.exposed.advice) : null,
      badges(site),
      h('div', { class: 'actions' }, siteActions(site, true).filter(function (n) { return n.getAttribute('data-action') !== 'open'; })),
      h('dl', { class: 'facts' }, [
        ['Up, 24 hours', percent(d.uptime['24h'])],
        ['Up, 7 days', percent(d.uptime['7d'])],
        ['Up, 30 days', percent(d.uptime['30d'])],
        ['Response now', site.ms !== null ? site.ms + ' ms' : '–']
      ].map(function (fact) {
        return h('div', { class: 'fact' }, [h('dt', {}, fact[0]), h('dd', {}, fact[1])]);
      })),
      h('h3', {}, 'Last 30 days'),
      days(d.days, true, site.status === 'paused'),
      h('p', { class: 'days-legend', 'aria-hidden': 'true' }, [h('span', {}, '30 days ago'), h('span', {}, 'Today')]),
      h('h3', {}, 'Response time, last 24 hours'),
      chart(d.samples),
      h('h3', {}, 'Security headers'),
      headerList(site.headers),
      h('h3', {}, 'Mail'),
      mailList(site.dns),
      h('h3', {}, 'History'),
      timeline(d.events),
      h('h3', {}, 'Settings'),
      h('dl', { class: 'settings' }, settings.reduce(function (list, row) {
        return list.concat([h('dt', {}, row[0]), h('dd', {}, row[1])]);
      }, [])),
      h('p', { class: 'drawer-hint' }, ['Change this site with ', h('code', {}, 'php vaka site:edit ' + site.label)])
    ]));
  }

  function capitalize(text) {
    return text.charAt(0).toUpperCase() + text.slice(1);
  }

  /** The six protections: in place, weak or missing, what each does, and the line that adds it. */
  function headerList(headers) {
    if (!headers) return h('p', { class: 'chart-note' }, 'Not checked yet.');
    if (!headers.items || !headers.items.length) return h('p', { class: 'chart-note' }, headers.summary);
    var words = { good: 'In place', weak: 'Weak', missing: 'Missing' };
    var icons = { good: 'up', weak: 'failing', missing: 'down' };
    return h('div', {}, [
      h('p', { class: 'headers-summary' }, headers.summary),
      h('ul', { class: 'header-list' }, headers.items.map(function (item) {
        var good = item.level === 'good';
        return h('li', { class: 'is-' + item.level }, [
          icon(icons[item.level]),
          h('div', {}, [
            h('p', {}, [
              h('b', {}, capitalize(item.label)),
              good ? h('span', { class: 'visually-hidden' }, ': ' + words.good) : h('span', { class: 'header-level' }, ' · ' + words[item.level])
            ]),
            h('p', { class: 'header-what' }, item.what),
            item.note ? h('p', { class: 'header-why' }, capitalize(item.note) + '.') : null,
            good ? null : h('code', {}, item.fix),
            !good && item.tip ? h('p', { class: 'header-tip' }, item.tip) : null
          ])
        ]);
      }))
    ]);
  }

  /** MX, SPF and DMARC: what each does, what the domain has, and the record to add when it is missing or weak. */
  function mailList(dns) {
    if (!dns) return h('p', { class: 'chart-note' }, 'Not checked yet.');
    if (!dns.items || !dns.items.length) return h('p', { class: 'chart-note' }, dns.summary);
    var words = { good: 'In place', weak: 'Weak', missing: 'Missing', none: 'None' };
    var icons = { good: 'up', weak: 'failing', missing: 'down', none: 'info' };
    return h('div', {}, [
      h('p', { class: 'headers-summary' }, dns.summary),
      h('ul', { class: 'header-list' }, dns.items.map(function (item) {
        var good = item.level === 'good';
        var fix = item.level === 'missing' || item.level === 'weak';
        return h('li', { class: 'is-' + item.level }, [
          icon(icons[item.level]),
          h('div', {}, [
            h('p', {}, [
              h('b', {}, item.label),
              good ? h('span', { class: 'visually-hidden' }, ': ' + words.good) : h('span', { class: 'header-level' }, ' · ' + words[item.level])
            ]),
            h('p', { class: 'header-what' }, item.what),
            item.records.length ? h('p', { class: 'records' }, item.records.map(function (record) { return h('code', {}, record); })) : null,
            item.note ? h('p', { class: 'header-why' }, item.note + (/[.:]$/.test(item.note) ? '' : '.')) : null,
            fix && item.fix ? h('p', { class: 'fix-label' }, item.fix_label) : null,
            fix && item.fix ? h('code', {}, item.fix) : null,
            fix && item.tip ? h('p', { class: 'header-tip' }, item.tip) : null
          ])
        ]);
      }))
    ]);
  }

  function chart(samples) {
    if (!samples.length) return h('p', { class: 'chart-note' }, 'No checks in the last 24 hours.');
    var width = 700, height = 170, top = 12, bottom = 146, left = 48;
    var end = Date.now(), start = end - 86400000;
    var good = samples.filter(function (sample) { return sample.ok; });
    var max = Math.max.apply(null, [100].concat(good.map(function (sample) { return sample.ms; })));
    max = Math.ceil(max / 100) * 100;
    var x = function (iso) { return left + ((Date.parse(iso) - start) / (end - start)) * (width - left); };
    var y = function (ms) { return bottom - (ms / max) * (bottom - top); };

    var path = good.map(function (sample, i) { return (i ? 'L' : 'M') + x(sample.checked_at).toFixed(1) + ' ' + y(sample.ms).toFixed(1); }).join(' ');
    var area = good.length ? path + ' L' + x(good[good.length - 1].checked_at).toFixed(1) + ' ' + bottom + ' L' + x(good[0].checked_at).toFixed(1) + ' ' + bottom + ' Z' : '';
    var fails = samples.filter(function (sample) { return !sample.ok; });

    var nodes = [
      s('line', { class: 'chart-grid', x1: left, x2: width, y1: top, y2: top }),
      s('line', { class: 'chart-grid', x1: left, x2: width, y1: (top + bottom) / 2, y2: (top + bottom) / 2 }),
      s('line', { class: 'chart-grid', x1: left, x2: width, y1: bottom, y2: bottom }),
      s('text', { x: 0, y: top + 4 }, max + ' ms'),
      s('text', { x: 0, y: (top + bottom) / 2 + 4 }, max / 2 + ' ms'),
      s('text', { x: 0, y: bottom + 4 }, '0'),
      s('text', { x: left, y: height - 2 }, '24 h ago'),
      s('text', { x: (left + width) / 2 - 22, y: height - 2 }, '12 h ago'),
      s('text', { x: width - 26, y: height - 2 }, 'now')
    ];
    if (area) nodes.push(s('path', { class: 'chart-area', d: area }), s('path', { class: 'chart-line', d: path }));
    fails.forEach(function (sample) {
      nodes.push(s('rect', { class: 'chart-fail', x: x(sample.checked_at).toFixed(1), y: bottom - 7, width: 3, height: 7, rx: 1 }));
    });

    var slowest = good.reduce(function (m, sample) { return Math.max(m, sample.ms); }, 0);
    var typical = median(good.map(function (sample) { return sample.ms; }));
    var label = good.length
      ? 'Typically ' + typical + ' ms, slowest ' + slowest + ' ms' + (fails.length ? ', ' + plural(fails.length, 'failed check', 'failed checks') + ' (red)' : '')
      : 'Every check in the last 24 hours failed.';

    return h('figure', {}, [
      s('svg', { class: 'chart', viewBox: '0 0 ' + width + ' ' + height, preserveAspectRatio: 'none', role: 'img', 'aria-label': 'Response time, last 24 hours. ' + label }, nodes),
      h('figcaption', { class: 'chart-note' }, label + '.')
    ]);
  }

  function timeline(events) {
    if (!events.length) return h('p', { class: 'chart-note' }, 'Nothing has happened yet.');
    return h('ol', { class: 'timeline' }, events.map(function (event) {
      var cls = { fail: 'is-fail', ok: 'is-ok', warn: 'is-warn', snoozed: 'is-quiet' }[event.status] || null;
      return h('li', { class: cls }, [
        h('time', { datetime: event.created_at }, clock(event.created_at)),
        h('span', {}, [event.message, event.silenced_by ? h('span', { class: 'history-tag' }, event.silenced_by === 'maintenance' ? 'during maintenance' : 'while snoozed') : null])
      ]);
    }));
  }

  // =================================================================
  // Actions
  // =================================================================

  function toast(message) {
    var node = regions.toast;
    node.textContent = message;
    node.classList.add('is-visible');
    clearTimeout(toast.timer);
    toast.timer = setTimeout(function () { node.classList.remove('is-visible'); }, 4000);
  }

  function snooze(ids, body, button) {
    if (button) button.disabled = true;
    var requests = ids.map(function (id) {
      return api('/?api=snooze', {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify(Object.assign({ site_id: Number(id) }, body))
      });
    });
    return Promise.all(requests).then(function (results) {
      var site = results[0].site;
      var who = ids.length > 1 ? plural(ids.length, 'site', 'sites') : site.label;
      toast(body.action === 'unsnooze' ? who + ' is no longer snoozed' : 'Snoozed ' + who + ' until ' + clock(site.snoozed_until));
      return load().then(function () { if (state.detailId) return openDetail(state.detailId); });
    }).catch(function (error) {
      if (error.message !== 'Logged out') toast(error.message);
      if (button) button.disabled = false;
    });
  }

  var THEMES = ['system', 'light', 'dark'];
  var THEME_WORDS = { system: 'System', light: 'Light', dark: 'Dark' };
  var THEME_ICONS = { system: 'monitor', light: 'sun', dark: 'moon' };

  function currentTheme() {
    try {
      return localStorage.getItem('vaka-theme') || 'system';
    } catch (e) {
      return 'system';
    }
  }

  function applyTheme(theme) {
    if (theme === 'system') document.documentElement.removeAttribute('data-theme');
    else document.documentElement.setAttribute('data-theme', theme);
    var button = document.querySelector('[data-action="theme"]');
    button.querySelector('[data-theme-label]').textContent = THEME_WORDS[theme];
    button.replaceChild(icon(THEME_ICONS[theme]), button.querySelector('svg'));
    button.setAttribute('aria-label', 'Colour theme: ' + THEME_WORDS[theme].toLowerCase() + '. Change it');
  }

  document.addEventListener('click', function (event) {
    var target = event.target.closest('[data-action]');
    if (!target) return;
    var action = target.getAttribute('data-action');

    if (action === 'open') openDetail(target.getAttribute('data-site'), target);
    if (action === 'close') closeDetail();
    if (action === 'snooze') snooze(target.getAttribute('data-sites').split(','), { 'for': target.getAttribute('data-for') }, target);
    if (action === 'unsnooze') snooze(target.getAttribute('data-sites').split(','), { action: 'unsnooze' }, target);
    if (action === 'copy') {
      navigator.clipboard.writeText(target.getAttribute('data-copy')).then(function () { toast('Copied'); }, function () { toast('Select the text and copy it'); });
    }
    if (action === 'theme') {
      var next = THEMES[(THEMES.indexOf(currentTheme()) + 1) % THEMES.length];
      try { localStorage.setItem('vaka-theme', next); } catch (e) { /* storage blocked: the choice lasts for this page */ }
      applyTheme(next);
    }
  });

  // "/" jumps to the filter, as in most tools
  document.addEventListener('keydown', function (event) {
    var typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName);
    if (event.key === '/' && !typing && !regions.detail.open) {
      event.preventDefault();
      filterInput.focus();
    }
  });

  // A click on the backdrop closes the detail, as Esc does
  regions.detail.addEventListener('click', function (event) {
    if (event.target === regions.detail) closeDetail();
  });
  regions.detail.addEventListener('close', function () {
    if (state.detailId) closeDetail();
  });

  // Remember which rows people opened or closed, so a refresh does not undo it
  document.addEventListener('toggle', function (event) {
    var key = event.target.getAttribute && event.target.getAttribute('data-key');
    if (key) state.open[key] = event.target.open;
  }, true);

  filterInput.addEventListener('input', function () {
    state.filter = filterInput.value;
    if (state.data) renderGroups();
  });

  // =================================================================
  // Start
  // =================================================================

  applyTheme(currentTheme());
  load().then(function () {
    var match = /^#site-(\d+)$/.exec(window.location.hash);
    if (match) openDetail(match[1]);
  });
  setInterval(function () {
    if (!document.hidden) load();
  }, REFRESH_MS);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) load();
  });
})();
