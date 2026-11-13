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
  var state = { data: null, filter: '', detailId: null, detail: null, headerFormat: null, form: null, timezone: undefined, failed: false, open: {}, autoOpen: null, recheck: null, watched: null };

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
    info: ['M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z', 'M12 11v5', 'M12 8h.01'],
    plus: ['M12 5v14', 'M5 12h14']
  };

  function icon(name) {
    return s('svg', { class: 'icon', viewBox: '0 0 24 24', 'aria-hidden': 'true', focusable: 'false' },
      ICONS[name].map(function (d) { return s('path', { d: d }); }));
  }

  // Vaka's owl from the Webready design kit (characters/vaka.svg, vaka-awake.svg and vaka-perched.svg, 40 x 40):
  // the body, then the eyes (watching, or wide awake while a site is down), then the beak and the feet
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
      ['path', {'d': 'M18.4 22.8L20 26L21.6 22.8Q20 23.4 18.4 22.8Z', 'fill': '#f5b942'}]
    ],
    feet: [
      ['path', {'d': 'M16.2 35.4v1.8M17.8 35.4v1.8M22.2 35.4v1.8M23.8 35.4v1.8', 'stroke': '#f5b942', 'stroke-width': '1.3', 'stroke-linecap': 'round'}]
    ],
    // Perched on the panel's bottom edge (at y 36, right under the body), from vaka-perched.svg: a pad on the edge for
    // each foot, three toes over it, and the claws, in a darker gold, curled in round it
    talons: [
      ['ellipse', {'cx': '17', 'cy': '35.5', 'rx': '2.1', 'ry': '0.8', 'fill': '#f5b942'}],
      ['ellipse', {'cx': '23', 'cy': '35.5', 'rx': '2.1', 'ry': '0.8', 'fill': '#f5b942'}],
      ['path', {'d': 'M15.4 35.5v1.6M17 35.7v1.6M18.6 35.5v1.6M21.4 35.5v1.6M23 35.7v1.6M24.6 35.5v1.6', 'fill': 'none', 'stroke': '#f5b942', 'stroke-width': '1.3', 'stroke-linecap': 'round'}],
      ['path', {'d': 'M15.4 37.1q0 .8.5 1M17 37.3q0 .8.4 1M18.6 37.1q0 .8.4.9M21.4 37.1q0 .8-.4.9M23 37.3q0 .8-.4 1M24.6 37.1q0 .8-.5 1', 'fill': 'none', 'stroke': '#c98516', 'stroke-width': '0.8', 'stroke-linecap': 'round'}]
    ]
  };

  /**
   * The owl in the night panel. Awake: wide eyes, small pupils. Its eyes blink
   * while all is calm (CSS), follow a mouse over the panel and tilt its head
   * when the mouse rests near it (lookAt()). Both kinds of feet are drawn:
   * talons over the panel's edge where it sits on it, feet where it sits in
   * the corner (CSS).
   */
  function owl(awake) {
    var draw = function (items) { return items.map(function (item) { return s(item[0], item[1]); }); };
    var eyes = awake ? OWL.awakeEyes : OWL.eyes;
    // The two whites, then each pupil with its glint in a group of its own, which can turn
    var pupils = [0, 1].map(function (i) { return s('g', { class: 'owl-pupil' }, draw([eyes[2 + i], eyes[4 + i]])); });
    // Everything but the feet in one group, which tilts while the feet keep their hold
    return s('svg', { class: 'watch-owl' + (awake ? ' is-awake' : ''), viewBox: '0 0 40 40', 'aria-hidden': 'true', focusable: 'false' }, [
      s('g', { class: 'owl-body' }, draw(OWL.body).concat([s('g', { class: 'owl-eyes' }, draw(eyes.slice(0, 2)).concat(pupils))], draw(OWL.beak))),
      s('g', { class: 'owl-feet' }, draw(OWL.feet)), s('g', { class: 'owl-talons' }, draw(OWL.talons))
    ]);
  }

  /**
   * Tonight's moon, above the owl: its phase from a known new moon (6 Jan
   * 2000, 18:14 UTC) and the length of a lunar month, lit on the right while
   * it waxes, as seen from the northern hemisphere. Drawn as the lit part
   * over a faint disc: a half circle on the lit side, and the terminator as
   * a half ellipse back.
   */
  function moon(now) {
    var phase = (((now - Date.UTC(2000, 0, 6, 18, 14)) / 86400000 / 29.530588853) % 1 + 1) % 1;
    var r = 10, cx = 12, top = 2, bottom = 22;
    var waxing = phase < 0.5;
    var bulge = phase < 0.25 || (phase >= 0.5 && phase < 0.75) ? 0 : 1;
    var rx = (Math.abs(Math.cos(phase * 2 * Math.PI)) * r).toFixed(2);
    var lit = 'M' + cx + ' ' + top + 'A' + r + ' ' + r + ' 0 0 ' + (waxing ? 1 : 0) + ' ' + cx + ' ' + bottom + 'A' + rx + ' ' + r + ' 0 0 ' + bulge + ' ' + cx + ' ' + top + 'Z';
    // A small pleasure on hover, in the tooltip beside it: "Tonight's moon: waning gibbous, 94% lit"
    var words = MOON_PHASES[Math.round(phase * 8) % 8] + ', ' + Math.round((1 - Math.cos(phase * 2 * Math.PI)) / 2 * 100) + '% lit';
    return s('svg', { class: 'watch-moon', viewBox: '0 0 24 24', 'aria-hidden': 'true', focusable: 'false', 'data-tip': "Tonight's moon: " + words, 'data-tip-side': 'moonlight' }, [
      s('circle', { class: 'moon-dark', cx: cx, cy: 12, r: r }),
      s('path', { class: 'moon-lit', d: lit })
    ]);
  }

  var MOON_PHASES = ['new moon', 'waxing crescent', 'first quarter', 'waxing gibbous', 'full moon', 'waning gibbous', 'last quarter', 'waning crescent'];

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

  /** "a, b and c", as list_words() in PHP. */
  function listWords(words) {
    return words.length > 1 ? words.slice(0, -1).join(', ') + ' and ' + words[words.length - 1] : words.join('');
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

  /**
   * A time of day as TIME_FORMAT says: "14:05", or "2:05 PM". Written out
   * rather than left to Intl, which follows the browser's language (not the
   * reader's region) and in some browsers puts a narrow space before PM.
   */
  function hourMinute(hour, minute) {
    if (state.timeFormat !== '12h') return ('0' + hour).slice(-2) + ':' + minute;
    return (hour % 12 || 12) + ':' + minute + (hour < 12 ? ' AM' : ' PM');
  }

  /** "14:02" today, "24 Oct 14:02" before that, in the server's time zone. */
  function clock(iso) {
    var p = dateParts(new Date(iso));
    var today = dateParts(new Date());
    var time = hourMinute(Number(p.hour), p.minute);
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
          if (!response.ok) {
            var error = new Error(body.error || 'Something went wrong');
            error.body = body;
            throw error;
          }
          return body;
        });
      });
  }

  function load() {
    return api('/?api=overview').then(function (data) {
      state.data = data;
      state.timezone = data.timezone;
      state.timeFormat = data.time_format;
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

  /** Sites where a bot check answers instead of the site: Vaka cannot see them. */
  function blockedIssues() {
    return allSites().filter(function (site) { return site.blocked; });
  }

  function render() {
    renderRunState();
    renderBanner();
    renderWatch();
    greet();
    renderAttention();
    renderGroups();
    // No list to show or filter before the first site
    regions.sites.hidden = state.data.totals.sites === 0;
    // Only users who see every site can change sites
    document.querySelector('[data-add-site]').hidden = !state.data.setup;
    document.querySelector('[data-add-group]').hidden = !state.data.setup;
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
    foot.textContent = 'Vaka ' + foot.getAttribute('data-version') + ' · ' + (last ? 'checks run every minute, the last at ' + clock(last) + zoneNote() : 'not checked yet');
  }

  /**
   * ", Stockholm time" (or ", UTC") when Vaka's time zone shows another time
   * than the reader's clock, so the times on the page are not taken for the
   * reader's own: an install still on its server's UTC gets noticed.
   */
  function zoneNote() {
    var tz = state.timezone;
    if (!tz) return '';
    var now = new Date();
    var at = function (zone) {
      return new Intl.DateTimeFormat('en-GB', { timeZone: zone, day: 'numeric', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(now);
    };
    if (at(tz) === at(undefined)) return '';
    return ', ' + (tz.indexOf('/') < 0 || tz.indexOf('Etc/') === 0 ? tz.replace('Etc/', '') : tz.split('/').pop().replace(/_/g, ' ') + ' time');
  }

  function renderBanner() {
    var node = regions.banner;
    clear(node);
    if (state.failed) {
      append(node, h('p', { class: 'banner', role: 'alert' }, [icon('failing'), h('span', {}, [h('strong', {}, 'Vaka cannot be reached right now. '), 'This page tries again every 30 seconds.'])]));
      return;
    }
    var data = state.data;
    // A setting Vaka could not use, for whoever can fix it (even before the first site)
    (data && data.setup && data.setup.problems || []).forEach(function (problem) {
      append(node, h('div', { class: 'banner', role: 'alert' }, [icon('failing'), h('span', {}, [h('strong', {}, 'A setting in .env needs fixing. '), problem])]));
    });
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

    // Outages that end while someone watches: the red glow fades and the owl's eyes relax, once (CSS). A snooze
    // or a maintenance window quiets an outage without ending it, so it leaves the number down as it was
    var relieved = Boolean(state.watched) && state.watched.kind === 'alert' && kind !== 'alert' && t.down < state.watched.down;
    state.watched = { kind: kind, down: t.down };

    append(node, h('div', { class: 'watch is-' + kind + (relieved ? ' is-relieved' : '') }, [
      h('div', {}, [
        h('p', { class: 'watch-kicker' }, kicker),
        h('h1', {}, title),
        h('p', { class: 'watch-line' }, line),
        h('ul', { class: 'watch-stats', 'aria-label': 'At a glance' }, stats)
      ]),
      h('div', { class: 'watch-perch' }, [moon(Date.now()), owl(kind === 'alert')])
    ]));
    // A refresh draws a new owl: it goes on looking where the pointer is
    lookAt(gaze);
  }

  var greeted = false;

  /** For whoever opens the browser's console, once a page: the owl, wide awake while a site is down, and where to read on. */
  function greet() {
    if (greeted) return;
    greeted = true;
    var owlLines = [' ,___, ', document.querySelector('.watch-owl.is-awake') ? ' (O,O) ' : ' (o,o) ', ' /)__) ', '  " "  '];
    var words = ['', 'Vaka ' + document.querySelector('[data-foot]').getAttribute('data-version') + ' keeps watch.',
      'Open source: https://github.com/webready-se/vaka',
      'Its data for a script or an AI assistant: https://github.com/webready-se/vaka/blob/main/docs/api.md'];
    var styles = [];
    var text = owlLines.map(function (part, i) {
      styles.push('color: #19acca; font-weight: bold', 'color: inherit');
      return '%c' + part + '%c  ' + words[i];
    }).join('\n');
    console.log.apply(console, [text].concat(styles));
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
          h('button', { type: 'button', class: 'button is-primary', 'data-action': 'add-site' }, [icon('plus'), 'Add a site']),
          h('small', {}, 'Or on the server:'),
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
    var blocked = blockedIssues();
    node.hidden = problems.length + certs.length + domains.length + exposed.length + blocked.length === 0;
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

    // Certificates and exposed files after the outages: what is broken now before what is about to be.
    // A site Vaka cannot see leads the warnings: an outage there would go unnoticed.
    var checks = blocked.map(function (site) { return { rank: 1, row: blockedIncident(site) }; })
      .concat(certs.map(function (site) { return { rank: site.ssl.status === 'fail' ? 0 : 1, row: certIncident(site) }; }))
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
    var shown = domain.label || domain.domain;
    var others = sites.map(function (site) { return site.label; }).filter(function (label) { return label !== shown; });
    var when = domain.expires ? (domain.status === 'fail' ? 'Ran out ' : 'Runs out ') + date(domain.expires) + (domain.registrar ? ', with ' + domain.registrar : '') : domain.summary;
    return incident('domain:' + domain.domain, domain.status === 'fail' ? 'down' : 'warn', 'globe', shown, domainWords(domain), when,
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

  /** A bot check answers instead of the site: what that means, and the address to let through. */
  function blockedIncident(site) {
    var blocked = site.blocked;
    return incident('blocked:' + site.id, 'warn', 'failing', site.label, 'is hidden behind a bot check', blocked.by + '\'s bot check answers instead of the site',
      timeBox(blocked.since ? shortDuration(secondsSince(blocked.since)) : '–', 'Hidden for'), [
        h('p', { class: 'incident-meta' }, [site.group || 'No group', blocked.since ? ' · since ' + clock(blocked.since) : null]),
        advice(site.advice),
        blocked.from_ip ? h('p', { class: 'incident-meta' }, ['Vaka\'s server asks from ', h('code', {}, blocked.from_ip), ': the address to let through.']) : null,
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
    if (site.blocked && !inCard) list.push(h('li', { class: 'badge is-warn' }, [icon('failing'), 'Behind a bot check: Vaka cannot see the site']));
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
    // The bar it pointed at is gone
    hideTip();
    var filter = state.filter.trim().toLowerCase();
    var shown = 0;

    var editable = Boolean(state.data.setup);
    state.data.groups.forEach(function (group) {
      var sites = group.sites.filter(function (site) { return matches(site, group, filter); });
      var empty = !group.sites.length && editable && !filter;
      if (!sites.length && !empty) return;
      shown += sites.length;
      var title = group.name || 'No group';
      var head = h('div', { class: 'group-head' }, [
        h('h3', {}, title),
        h('ul', { class: 'group-meta' }, [
          h('li', {}, group.sites.length ? plural(group.sites.length, 'site', 'sites') : 'No sites yet'),
          h('li', {}, [icon('bell'), 'Alerts ' + group.alerting]),
          editable && group.id !== null ? h('li', {}, h('button', { type: 'button', class: 'button', 'data-action': 'edit-group', 'data-group': group.id, 'aria-label': 'Change the group ' + title }, 'Change')) : null
        ])
      ]);
      if (empty) {
        append(node, h('section', { class: 'group', 'aria-label': title }, [head, h('p', { class: 'group-empty' }, [
          'No sites in this group yet.',
          h('button', { type: 'button', class: 'button', 'data-action': 'add-site', 'data-group': title }, [icon('plus'), 'Add a site to it'])
        ])]));
        return;
      }
      append(node, h('section', { class: 'group', 'aria-label': title }, [
        head,
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

  /**
   * A day bar's colour: green without an outage, amber when down for less
   * than an hour, red for an hour or more, the quiet colour when it was only
   * down in maintenance, grey without data.
   */
  function dayClass(d, paused) {
    if (paused || d.up === null) return 'is-none';
    if (d.down_seconds >= 3600) return 'is-down';
    if (d.down_seconds > 0) return 'is-partial';
    return d.planned_seconds > 0 ? 'is-planned' : null;
  }

  var WEEKDAY = new Intl.DateTimeFormat('en-GB', { weekday: 'short', timeZone: 'UTC' });

  /** "Today", "Yesterday" or "Tue 27 Oct": the last day in the list is today. */
  function dayName(list, i) {
    if (i === list.length - 1) return 'Today';
    if (i === list.length - 2) return 'Yesterday';
    return WEEKDAY.format(new Date(list[i].date + 'T12:00:00Z')) + ' ' + day(list[i].date);
  }

  function times(count) {
    return count === 1 ? 'once' : count === 2 ? 'twice' : count + ' times';
  }

  /** What a day bar says: "Yesterday: down twice, 1 h 20 min in all". */
  function dayWords(d, paused, name) {
    if (paused) return name + ': paused';
    if (d.up === null) return name + ': not watched yet';
    var words;
    if (d.down_seconds > 0) {
      words = 'down ' + times(d.outages) + ', ' + duration(d.down_seconds) + (d.outages > 1 ? ' in all' : '');
      if (d.planned_seconds > 0) words += ', and ' + duration(d.planned_seconds) + ' in maintenance (not counted)';
    } else if (d.planned_seconds > 0) {
      words = 'down ' + duration(d.planned_seconds) + ' in maintenance (planned, not counted)';
    } else {
      words = name === 'Today' ? 'up so far' : 'up all day';
    }
    return name + ': ' + words;
  }

  /** One bar per day in the site list; the tooltip tells the day on hover (see the listeners). */
  function days(list, paused) {
    return h('span', { class: 'days', 'aria-hidden': 'true' }, list.map(function (d, i) {
      return h('i', { class: dayClass(d, paused), 'data-tip': dayWords(d, paused, dayName(list, i)) });
    }));
  }

  /**
   * The detail's bars: buttons that tell their day in the line under them,
   * on hover, tap or focus. Only today is in the tab order; the arrow keys
   * move along.
   */
  function dayStrip(list, paused) {
    return h('div', { class: 'days is-large', role: 'group', 'aria-label': 'One bar per day', 'data-days': '' }, list.map(function (d, i) {
      var words = dayWords(d, paused, dayName(list, i));
      return h('button', { type: 'button', class: dayClass(d, paused), 'data-action': 'day', 'data-tip': words, 'aria-label': words, tabindex: i === list.length - 1 ? '0' : '-1' });
    }));
  }

  /** The 30 days in a sentence, in the line under the bars until a day is picked. */
  function daysSummary(list, paused) {
    if (paused) return 'Paused: no checks until it is resumed.';
    var watched = list.filter(function (d) { return d.up !== null; });
    if (!watched.length) return 'Not watched yet.';
    var down = watched.filter(function (d) { return d.down_seconds > 0; });
    var planned = watched.filter(function (d) { return d.planned_seconds > 0; });
    var span = plural(watched.length, 'day', 'days');
    var text = down.length
      ? 'Down on ' + down.length + ' of ' + span + ', ' + duration(down.reduce(function (sum, d) { return sum + d.down_seconds; }, 0)) + ' in all.'
      : 'No outages in ' + span + '.';
    return text + (planned.length ? ' Planned maintenance on ' + plural(planned.length, 'day', 'days') + ', not counted.' : '');
  }

  /** What the colours mean: the three that always matter, and maintenance and no data when there are such days. */
  function dayKey(list, paused) {
    var classes = list.map(function (d) { return dayClass(d, paused); });
    return h('ul', { class: 'day-key', 'aria-hidden': 'true' }, [
      [null, 'No outage'], ['is-partial', 'Down less than an hour'], ['is-down', 'An hour or more'], ['is-planned', 'Maintenance'], ['is-none', 'Not watched']
    ].filter(function (item) {
      return item[0] === null || item[0] === 'is-partial' || item[0] === 'is-down' || classes.indexOf(item[0]) !== -1;
    }).map(function (item) {
      return h('li', {}, [h('i', { class: item[0] }), item[1]]);
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
    var hidden = site.blocked ? h('span', { class: 'is-warn' }, 'hidden behind a bot check') : null;
    var small = [subtitle, hidden, cert, registration, files].filter(Boolean).reduce(function (list, part) { return list.length ? list.concat([' · ', part]) : [part]; }, []);
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
      days(site.days, site.status === 'paused'),
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
      settleRecheck(detail.site);
      renderDetail();
      markLanded();
      if (!regions.detail.open) regions.detail.showModal();
    }).catch(function (error) {
      if (error.message !== 'Logged out') toast(error.message);
      closeDetail();
    });
  }

  function closeDetail() {
    clearTimeout(state.recheck && state.recheck.timer);
    state.recheck = null;
    state.detailId = null;
    state.detail = null;
    state.form = null;
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

    var kind = isQuiet(site) && site.status === 'down' ? 'quiet' : site.status;
    var paused = site.status === 'paused';
    var summary = daysSummary(d.days, paused);
    var since = site.since ? (site.status === 'up' ? 'Up since ' : site.status === 'down' ? 'Down since ' : 'Since ') + clock(site.since) + ' (' + duration(secondsSince(site.since)) + ')' : STATUS_WORDS[site.status];
    var editable = Boolean(state.data.setup);

    var fresh = h('div', { class: 'drawer-inner is-' + kind, 'data-site': site.id }, [
      h('div', { class: 'drawer-head' }, [
        h('div', { class: 'medallion' }, icon(kind === 'quiet' ? 'quiet' : site.status)),
        // The address leads, the name goes with it, as in the site list
        h('div', {}, [h('h2', { id: 'detail-title' }, site.label), h('p', {}, site.name ? site.name + ' · ' + since : since)]),
        h('button', { type: 'button', class: 'close-button', 'data-action': 'close', 'aria-label': 'Close' }, icon('close'))
      ]),
      site.summary ? h('p', { class: 'incident-summary' }, site.summary) : null,
      advice(site.advice),
      site.ssl && site.ssl.advice ? advice(site.ssl.summary + '. ' + site.ssl.advice) : null,
      site.domain && site.domain.advice ? advice(site.domain.summary + '. ' + site.domain.advice) : null,
      site.exposed && site.exposed.advice ? advice(site.exposed.summary + '. ' + site.exposed.advice) : null,
      badges(site),
      h('div', { class: 'actions' }, siteActions(site, true).filter(function (n) { return n.getAttribute('data-action') !== 'open'; }).concat(recheckButton(site))),
      recheckNote(site),
      h('dl', { class: 'facts' }, [
        ['Up, 24 hours', percent(d.uptime['24h'])],
        ['Up, 7 days', percent(d.uptime['7d'])],
        ['Up, 30 days', percent(d.uptime['30d'])],
        // When, like the tiles below, so a response time from a while ago is not taken for one from just now
        ['Response now', site.ms !== null ? site.ms + ' ms' : '–', site.checked_at ? checkedWords(site, 'uptime', site.checked_at) : null, 'uptime']
      ].map(function (fact) {
        return h('div', { class: fact[3] && pending(site, fact[3]) ? 'fact is-checking' : 'fact', 'data-check': fact[3] || null }, [h('dt', {}, fact[0]), h('dd', {}, fact[1]), fact[2] ? h('dd', { class: 'fact-when' }, fact[2]) : null]);
      })),
      h('h3', {}, 'Last 30 days'),
      dayStrip(d.days, paused),
      h('p', { class: 'days-legend', 'aria-hidden': 'true' }, [h('span', {}, '30 days ago'), h('span', {}, 'Today')]),
      h('p', { class: 'day-note', 'data-day-note': summary }, summary),
      dayKey(d.days, paused),
      // Uptime first, all of it (Vaka's main job), then what the other checks see, then their detail
      h('h3', {}, 'Response time, last 24 hours'),
      chart(d.samples),
      h('h3', { id: 'seen-title' }, 'What Vaka sees'),
      seenTiles(site, editable),
      h('h3', {}, 'Security headers'),
      headerList(site.headers),
      h('h3', {}, 'Mail'),
      mailList(site.dns),
      settingsCard(site, d.maintenance, editable),
      h('h3', {}, 'History'),
      timeline(d.events)
    ]);
    // The same site again (a refresh, "Check again"): only what changed is replaced
    var old = node.firstElementChild;
    if (old && old.getAttribute('data-site') === String(site.id) && old.className === fresh.className) patch(old, fresh);
    else { clear(node); append(node, fresh); }
  }

  /**
   * Bring a part of the page to a freshly built copy, replacing only what
   * differs, as Puls's islands do: the rest keeps its DOM, so focus, hover
   * and open sections survive a refresh, and what changed is what moves.
   * The highlight of a check that just ran is left to finish (markLanded()).
   */
  function patch(old, fresh) {
    if (old.isEqualNode(fresh)) return;
    if (old.nodeType !== 1 || old.tagName !== fresh.tagName || old.childNodes.length !== fresh.childNodes.length || !sameAttributes(old, fresh)) {
      old.replaceWith(fresh);
      return;
    }
    var freshChildren = Array.prototype.slice.call(fresh.childNodes);
    Array.prototype.slice.call(old.childNodes).forEach(function (child, i) { patch(child, freshChildren[i]); });
  }

  function sameAttributes(a, b) {
    var value = function (node, name) { return name === 'class' ? (node.getAttribute('class') || '').replace(/(^| )is-fresh\b/, '') : node.getAttribute(name); };
    var names = function (node) { return Array.prototype.map.call(node.attributes, function (attr) { return attr.name; }).sort().join(' '); };
    return names(a) === names(b) && Array.prototype.every.call(a.attributes, function (attr) { return value(a, attr.name) === value(b, attr.name); });
  }

  /**
   * "Check again" after a fix: every check of the site runs on Vaka's next
   * run, within a minute. While it waits, how many have run again; once all
   * have, when (see settleRecheck()).
   */
  function recheckButton(site) {
    if (site.status === 'paused') return [];
    var current = state.recheck && state.recheck.id === site.id ? state.recheck : null;
    if (current && current.done) {
      return [h('button', { type: 'button', class: 'button is-done', 'data-action': 'recheck', 'data-site': site.id, 'aria-label': 'Checked ' + site.label + ' again at ' + clock(current.done) + '. Check it again now' }, [icon('up'), 'Checked again ' + clock(current.done)])];
    }
    var busy = current && !current.left;
    var total = current ? Object.keys(current.before).length : 0;
    var ran = busy && total ? total - recheckLeft(current, site).length : 0;
    var words = !busy ? 'Check again' : ran ? 'Checking… ' + ran + ' of ' + total + ' done' : 'Checking…';
    var button = h('button', { type: 'button', class: busy ? 'button is-busy' : 'button', 'data-action': 'recheck', 'data-site': site.id, disabled: busy ? '' : null, 'aria-label': busy ? 'Checking ' + site.label + ' again' : 'Check ' + site.label + ' again now' }, [busy ? h('span', { class: 'spinner', 'aria-hidden': 'true' }) : icon('pending'), words]);
    // While it works, the button fills as the checks come back (through the CSSOM, which the CSP allows)
    if (busy) button.style.setProperty('--progress', String(total ? ran / total : 0));
    return [button];
  }

  /**
   * Under the button: after three minutes of waiting, the checks that have
   * not run again yet, and why they may wait; and mail records that came back
   * the same while something is missing, since one just added may not show yet.
   */
  function recheckNote(site) {
    var current = recheckOf(site);
    if (!current) return null;
    var notes = [];
    if (current.left && current.left.length) notes.push('Not checked again yet: ' + listWords(current.left) + '. ' + (site.status === 'down' || site.status === 'failing' ? 'They wait while the site does not answer.' : 'They are still due, so one of the next runs takes them.'));
    if (site.dns && site.dns.status !== 'ok' && landed(current, site, 'dns') && !changed(current, site, 'dns')) notes.push('Mail is the same as before: a record just added can take up to 30 minutes to show, as DNS answers are remembered for a while.');
    return notes.length ? h('p', { class: 'recheck-note' }, notes.join(' ')) : null;
  }

  /** The "Check again" under way for this site, or null. */
  function recheckOf(site) {
    return state.recheck && state.recheck.id === Number(site.id) ? state.recheck : null;
  }

  /** What a check said, to tell whether it changed: uptime by its status, the others by their summary. */
  function checkSummary(site, key) {
    return key === 'uptime' ? site.status : site[key] ? site[key].summary : null;
  }

  /** Whether a check has run since "Check again" was pressed. */
  function landed(current, site, key) {
    return current.before[key] !== undefined && checkTimes(site)[key] !== current.before[key];
  }

  function changed(current, site, key) {
    return landed(current, site, key) && checkSummary(site, key) !== current.summaries[key];
  }

  /** Whether "Check again" still waits for a check (not after three minutes, when the note says which are left). */
  function pending(site, key) {
    var current = recheckOf(site);
    return Boolean(current) && !current.done && !current.left && current.before[key] !== undefined && !landed(current, site, key);
  }

  /** When a check ran, in the words of its tile: "Checking…" while "Check again" waits for it, "Checked just now" once it has run. */
  function checkedWords(site, key, at) {
    var current = recheckOf(site);
    if (pending(site, key)) return [h('span', { class: 'checking-dot', 'aria-hidden': 'true' }), 'Checking…'];
    return current && landed(current, site, key) ? 'Checked just now' : 'Checked ' + clock(at);
  }

  /** A tile's value as read, without the words only a screen reader gets. */
  function tileValue(node) {
    var value = node.querySelector('.tile-value');
    if (!value) return null;
    var copy = value.cloneNode(true);
    Array.prototype.forEach.call(copy.querySelectorAll('.visually-hidden'), function (hidden) { hidden.remove(); });
    return copy.textContent;
  }

  /** Light up each check that has just run again, once: the class goes at animationend, so a refresh never plays it again. */
  function markLanded() {
    var current = state.detail && recheckOf(state.detail.site);
    if (!current || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    Object.keys(current.before).forEach(function (key) {
      if (current.shown[key] || !landed(current, state.detail.site, key)) return;
      current.shown[key] = true;
      var node = regions.detail.querySelector('[data-check="' + key + '"]');
      if (node) node.classList.add('is-fresh');
    });
  }

  // The checks "Check again" waits for, by their key in the site and in plain words
  var RECHECKED = [['uptime', 'uptime'], ['ssl', 'certificate'], ['domain', 'domain'], ['exposed', 'exposed files'], ['headers', 'security headers'], ['dns', 'mail']];

  /** When each check of a site last ran, for those that are on and have run. */
  function checkTimes(site) {
    var times = {};
    RECHECKED.forEach(function (check) {
      if ((site.checks_off || []).indexOf(check[0]) !== -1) return;
      var at = check[0] === 'uptime' ? site.checked_at : site[check[0]] && site[check[0]].checked_at;
      if (at) times[check[0]] = at;
    });
    return times;
  }

  /** The checks whose time has not moved since "Check again" was pressed, in plain words. */
  function recheckLeft(current, site) {
    var now = checkTimes(site);
    return RECHECKED.filter(function (check) {
      return current.before[check[0]] !== undefined && now[check[0]] === current.before[check[0]];
    }).map(function (check) { return check[1]; });
  }

  /** With a fresh detail: once every check has run again, say so, with what changed, and stop looking. */
  function settleRecheck(site) {
    var current = state.recheck;
    if (!current || current.id !== Number(site.id) || current.done || current.left || !Object.keys(current.before).length) return;
    if (recheckLeft(current, site).length) return;
    var times = checkTimes(site);
    current.done = Object.keys(times).map(function (key) { return times[key]; }).sort().pop();
    clearTimeout(current.timer);
    var moved = RECHECKED.filter(function (check) { return changed(current, site, check[0]); }).map(function (check) { return check[1]; });
    toast('Every check of ' + site.label + ' ran again: ' + (moved.length ? listWords(moved) + ' changed' : 'nothing changed'));
  }

  var TILE_ICONS = { ok: 'up', warn: 'failing', down: 'down', none: 'info' };
  var TILE_WORDS = { warn: 'Needs a look: ', down: 'Problem: ' };

  /** A check's status as a tile's tone: ok, warn, down, or none for pending and unknown. */
  function tone(status) {
    return status === 'ok' ? 'ok' : status === 'warn' ? 'warn' : status === 'fail' ? 'down' : 'none';
  }

  function tile(label, kind, value, sub, extra) {
    return h('li', { class: 'tile is-' + kind.tone }, [
      h('p', { class: 'tile-label' }, label),
      h('p', { class: 'tile-value' }, [icon(kind.icon || TILE_ICONS[kind.tone]), h('span', {}, [TILE_WORDS[kind.tone] ? h('span', { class: 'visually-hidden' }, TILE_WORDS[kind.tone]) : null, value])]),
      sub ? h('p', { class: 'tile-sub' }, sub) : null,
      extra || null
    ]);
  }

  function daysLeft(days) {
    return days === 0 ? 'Expires today' : days === 1 ? 'Expires tomorrow' : plural(days, 'day', 'days') + ' left';
  }

  /**
   * What Vaka sees besides uptime, one tile per check, so the state of each
   * reads at a glance: the words lead, the colour and the mark say the same.
   * The detail of the headers and mail follows further down.
   */
  function seenTiles(site, editable) {
    var off = function (key) { return (site.checks_off || []).indexOf(key) !== -1; };
    var quiet = function (iconName) { return { tone: 'none', icon: iconName }; };
    var waiting = function (label) { return tile(label, quiet('pending'), 'Not checked yet'); };
    var turnedOff = function (label) { return tile(label, quiet('paused'), 'Turned off', 'In the settings below'); };
    // When the check ran, so a result from hours ago is not taken for one from just now; while "Check again" waits, that it is
    // checking, and once it has run, what it said before if that changed
    var current = recheckOf(site);
    var stamped = function (node, check, key) {
      if (!check || !check.checked_at) return node;
      node.setAttribute('data-check', key);
      if (pending(site, key)) node.classList.add('is-checking');
      var was = current && landed(current, site, key) ? current.values[key] : undefined;
      if (was !== undefined && was !== null && was !== tileValue(node)) node.appendChild(h('p', { class: 'tile-was' }, 'Was: ' + was));
      node.appendChild(h('p', { class: 'tile-when' }, checkedWords(site, key, check.checked_at)));
      return node;
    };
    var tiles = [];

    var name = site.server ? site.server.replace(/ \(.*\)$/, '') : null;
    var serverButton = editable && site.server_ip
      ? h('button', { type: 'button', class: 'link-button', 'data-action': 'edit-server' }, name === site.server_ip ? 'Name it' : 'Server settings')
      : null;
    if (site.cdn) tiles.push(tile('Server', quiet('server'), 'Behind ' + site.cdn, 'Its addresses are shared, so it is alerted on its own'));
    else if (site.server) tiles.push(tile('Server', quiet('server'), name, site.server_ip ? (name === site.server_ip ? 'No name yet' : site.server_ip) : 'Set by hand', serverButton));
    else tiles.push(tile('Server', quiet('server'), 'Not known yet', 'Known after the first check'));

    var ssl = site.ssl;
    var issuer = ssl && /issued by (.+)$/.exec(ssl.summary);
    if (off('ssl')) tiles.push(turnedOff('Certificate'));
    else if (!/^https:/.test(site.url)) tiles.push(tile('Certificate', quiet('info'), 'No https', 'The address starts with http://'));
    else if (!ssl) tiles.push(waiting('Certificate'));
    else if (ssl.status === 'fail' || ssl.days_left === null) tiles.push(stamped(tile('Certificate', { tone: tone(ssl.status) }, ssl.status === 'fail' ? 'Not valid' : 'Unknown', ssl.summary), ssl, 'ssl'));
    else tiles.push(stamped(tile('Certificate', { tone: tone(ssl.status) }, daysLeft(ssl.days_left), 'Until ' + date(ssl.valid_to) + (ssl.renewed_by ? ', renewed by ' + ssl.renewed_by : issuer ? ', ' + issuer[1] : '')), ssl, 'ssl'));

    var domain = site.domain;
    if (off('domain')) tiles.push(turnedOff('Domain'));
    else if (!domain) tiles.push(waiting('Domain'));
    else if (domain.status === 'fail') tiles.push(stamped(tile('Domain', { tone: 'down' }, 'Expired', domain.summary), domain, 'domain'));
    else if (domain.days_left === null) tiles.push(stamped(tile('Domain', { tone: 'none' }, 'Unknown', domain.summary), domain, 'domain'));
    else tiles.push(stamped(tile('Domain', { tone: tone(domain.status) }, daysLeft(domain.days_left), 'Until ' + date(domain.expires) + (domain.registrar ? ', ' + domain.registrar : '')), domain, 'domain'));

    var exposed = site.exposed;
    var checked = exposed && /\((\d+ files? checked)\)/.exec(exposed.summary);
    if (off('exposed')) tiles.push(turnedOff('Exposed files'));
    else if (!exposed) tiles.push(waiting('Exposed files'));
    else if (exposed.found.length) tiles.push(stamped(tile('Exposed files', { tone: tone(exposed.status) }, plural(exposed.found.length, 'file', 'files') + ' public', exposed.found.map(function (file) { return file.label; }).join(', ')), exposed, 'exposed'));
    else tiles.push(stamped(tile('Exposed files', { tone: tone(exposed.status) }, exposed.status === 'ok' ? 'None public' : exposed.summary, checked ? capitalize(checked[1]) : null), exposed, 'exposed'));

    var headers = site.headers;
    if (off('headers')) tiles.push(turnedOff('Security headers'));
    else if (!headers) tiles.push(waiting('Security headers'));
    else if (!headers.grade) tiles.push(stamped(tile('Security headers', { tone: tone(headers.status) }, headers.summary), headers, 'headers'));
    // "Grade E: 2 of 6 in place, missing ...": the first part, the rest is further down
    else tiles.push(stamped(tile('Security headers', { tone: tone(headers.status) }, 'Grade ' + headers.grade, capitalize(headers.summary.replace(/^Grade [A-F]: /, '').split(', ')[0])), headers, 'headers'));

    var dns = site.dns;
    var mail = dns && /^Mail for ([^:]+): (.+)$/.exec(dns.summary);
    if (off('dns')) tiles.push(turnedOff('Mail'));
    else if (!dns) tiles.push(waiting('Mail'));
    else tiles.push(stamped(tile('Mail', { tone: tone(dns.status) }, mail ? capitalize(mail[2]) : dns.summary, mail ? 'For ' + mail[1] : null), dns, 'dns'));

    return h('ul', { class: 'tiles', 'aria-labelledby': 'seen-title' }, tiles);
  }

  /**
   * What the site is set to do, apart from what Vaka finds: a card of its
   * own, with Change in its head and the maintenance windows at the end.
   */
  function settingsCard(site, windows, editable) {
    var setup = state.data.setup;
    var rows = [];
    if (site.name) rows.push(['Name', site.name]);
    rows.push(['Group', site.group || 'No group']);
    rows.push(['Checked every', site.interval_minutes + ' min']);
    if (site.keyword) rows.push(['Looks for the text', '"' + site.keyword + '"']);
    if (setup) {
      rows.push(['Checks', h('ul', { class: 'check-chips' }, setup.checks.map(function (check) {
        var on = (site.checks_off || []).indexOf(check.key) === -1;
        return h('li', { class: on ? null : 'is-off' }, [icon(on ? 'up' : 'down'), check.label, h('span', { class: 'visually-hidden' }, on ? ': on' : ': off')]);
      }))]);
    }
    windows.forEach(function (w) {
      var text = w.when + (w.label ? ' (' + w.label + ')' : '') + (w.for === 'group' ? ', for the group' : '') + (w.active ? ', now' : '');
      rows.push(['Maintenance', editable ? [text, ' ', h('button', { type: 'button', class: 'link-button', 'data-action': 'window-remove', 'data-id': w.id, 'aria-label': 'Remove the maintenance window ' + w.when }, 'Remove')] : text]);
    });
    if (!windows.length) rows.push(['Maintenance', 'None']);

    return h('section', { class: 'settings-card', 'aria-labelledby': 'settings-title' }, [
      h('div', { class: 'settings-head' }, [
        h('h3', { id: 'settings-title' }, 'Settings'),
        editable ? h('button', { type: 'button', class: 'button', 'data-action': 'edit-site', 'aria-label': 'Change the settings of ' + site.label }, 'Change') : null
      ]),
      h('dl', { class: 'settings' }, rows.reduce(function (list, row) {
        return list.concat([h('dt', {}, row[0]), h('dd', {}, row[1])]);
      }, [])),
      editable ? h('div', { class: 'actions' }, h('button', { type: 'button', class: 'button', 'data-action': 'add-window' }, [icon('quiet'), 'Add a maintenance window'])) : null
    ]);
  }

  // =================================================================
  // Adding and changing a site, in the drawer (users who see every site)
  // =================================================================

  /** Open the form: for a new site (detail null, optionally in $group) or for the site in the drawer. */
  function openSiteForm(detail, opener, group) {
    var setup = state.data.setup;
    var site = detail ? detail.site : null;
    state.form = {
      id: site ? site.id : null,
      label: site ? site.label : null,
      // The group it is in, for what moving it changes
      fromGroup: site ? site.group || '' : null,
      errors: {},
      confirmRemove: false,
      values: site ? {
        url: site.url, group: site.group || '', name: site.name, keyword: site.keyword,
        interval: site.interval_custom === null ? '' : String(site.interval_custom), paused: site.status === 'paused',
        checks: setup.checks.map(function (c) { return c.key; }).filter(function (key) { return site.checks_off.indexOf(key) === -1; })
      } : {
        // A good start: every check on, the only group if there is one
        url: '', group: group || (setup.groups.length === 1 ? setup.groups[0] : ''), name: '', keyword: '', interval: '', paused: false,
        checks: setup.checks.map(function (c) { return c.key; })
      }
    };
    if (opener) state.opener = opener;
    renderSiteForm();
    if (!regions.detail.open) regions.detail.showModal();
    var first = regions.detail.querySelector('[aria-invalid="true"]') || regions.detail.querySelector('#f-url');
    if (first) first.focus();
  }

  /**
   * What moving sites from their groups to another changes for who sees them,
   * in a sentence: the users limited to a group and the read tokens for it
   * follow its sites (setup.readers). Empty when it changes nothing for anyone.
   */
  function moveWords(from, to, many) {
    var readers = state.data.setup.readers || {};
    var of = function (group) { return Object.prototype.hasOwnProperty.call(readers, group) ? readers[group] : []; };
    var now = of(to);
    var gain = [];
    var lose = [];
    from.forEach(function (group) {
      var before = of(group);
      now.forEach(function (r) { if (before.indexOf(r) === -1 && gain.indexOf(r) === -1) gain.push(r); });
      before.forEach(function (r) { if (now.indexOf(r) === -1 && lose.indexOf(r) === -1) lose.push(r); });
    });
    var it = many ? 'them' : 'it';
    var parts = [];
    if (gain.length) parts.push('shows ' + it + ' to ' + listWords(gain));
    if (lose.length) parts.push('hides ' + it + ' from ' + listWords(lose));
    return parts.length ? 'Moving ' + it + ' ' + parts.join(', and ') + '.' : '';
  }

  /** The site form's group hint: where alerts go, and for a site moved to another group, who that shows it to. */
  function groupHint(group) {
    var form = state.form;
    var base = state.data.setup.groups.length ? 'Alerts go where the group says.' : 'No groups yet: alerts go to the defaults in .env.';
    var move = form.id && group !== form.fromGroup ? moveWords([form.fromGroup], group, false) : '';
    return move ? base + ' ' + move : base;
  }

  /** A labelled field with its hint and, after a failed save, what was wrong. */
  function field(name, label, control, hint) {
    var id = 'f-' + name;
    var error = state.form.errors[name];
    control.id = id;
    var describedBy = [hint ? id + '-hint' : null, error ? id + '-error' : null].filter(Boolean).join(' ');
    if (describedBy) control.setAttribute('aria-describedby', describedBy);
    if (error) control.setAttribute('aria-invalid', 'true');
    return h('div', { class: 'field' + (error ? ' is-invalid' : '') }, [
      h('label', { for: id }, label),
      control,
      error ? h('p', { class: 'field-error', id: id + '-error' }, error) : null,
      hint ? h('p', { class: 'field-hint', id: id + '-hint' }, hint) : null
    ]);
  }

  function textInput(name, value, attrs) {
    var input = h('input', Object.assign({ type: 'text', name: name, autocomplete: 'off', spellcheck: 'false' }, attrs || {}));
    input.value = value || '';
    return input;
  }

  function renderSiteForm() {
    var form = state.form;
    var setup = state.data.setup;
    var v = form.values;
    var node = regions.detail;
    clear(node);

    var group = h('select', { name: 'group' }, [h('option', { value: '' }, 'No group')].concat(setup.groups.map(function (name) {
      return h('option', { value: name }, name);
    })));
    group.value = v.group;

    var minutes = [1, 2, 5, 10, 15, 30, 60].filter(function (m) { return m !== setup.interval; });
    if (v.interval !== '' && minutes.indexOf(Number(v.interval)) === -1) minutes.push(Number(v.interval));
    var interval = h('select', { name: 'interval' }, [h('option', { value: '' }, 'Every ' + setup.interval + ' min (the default)')].concat(minutes.sort(function (a, b) { return a - b; }).map(function (m) {
      return h('option', { value: String(m) }, m === 1 ? 'Every minute' : 'Every ' + m + ' min');
    })));
    interval.value = v.interval;

    var checks = h('fieldset', { class: 'field' + (form.errors.checks ? ' is-invalid' : '') }, [
      h('legend', {}, 'Checks'),
      h('ul', { class: 'check-list' }, setup.checks.map(function (check) {
        var box = h('input', { type: 'checkbox', name: 'checks', value: check.key });
        box.checked = v.checks.indexOf(check.key) !== -1;
        return h('li', {}, h('label', {}, [box, check.label]));
      })),
      form.errors.checks ? h('p', { class: 'field-error' }, form.errors.checks) : null
    ]);

    var paused = null;
    if (form.id) {
      var box = h('input', { type: 'checkbox', name: 'paused', id: 'f-paused' });
      box.checked = v.paused;
      paused = h('div', { class: 'field' }, h('label', { class: 'check' }, [box, 'Paused: no checks and no alerts until it is resumed']));
    }

    var remove = null;
    if (form.id) {
      remove = form.confirmRemove
        ? h('div', { class: 'confirm', role: 'alert' }, [
          h('p', {}, ['Remove ', h('b', {}, form.label), ' and its whole history? This cannot be undone.']),
          h('div', { class: 'actions' }, [
            h('button', { type: 'button', class: 'button is-danger', 'data-action': 'remove-site' }, 'Remove site'),
            h('button', { type: 'button', class: 'button', 'data-action': 'remove-cancel' }, 'Keep it')
          ])
        ])
        : h('div', { class: 'danger-zone' }, h('button', { type: 'button', class: 'button is-quiet-danger', 'data-action': 'remove-ask' }, 'Remove this site…'));
    }

    append(node, h('div', { class: 'drawer-inner is-form' }, [
      h('div', { class: 'drawer-head' }, [
        h('div', { class: 'medallion' }, icon(form.id ? 'pending' : 'plus')),
        h('div', {}, [
          h('h2', { id: 'detail-title' }, form.id ? 'Change ' + form.label : 'Add a site'),
          h('p', {}, form.id ? 'The change counts from the next check.' : 'Vaka checks it within a minute of saving.')
        ]),
        h('button', { type: 'button', class: 'close-button', 'data-action': 'close', 'aria-label': 'Close' }, icon('close'))
      ]),
      h('form', { class: 'site-form', 'data-form': 'site', novalidate: true }, [
        field('url', 'Address', textInput('url', v.url, { inputmode: 'url', placeholder: 'https://shop.example' }), 'The page Vaka checks. A domain alone means https.'),
        field('group', 'Group', group, groupHint(v.group)),
        field('name', 'Name', textInput('name', v.name, { spellcheck: 'true' }), 'Optional, shown with the address.'),
        field('keyword', 'Text that must be on the page', textInput('keyword', v.keyword), 'Optional. Catches an error page that still answers 200 OK.'),
        field('interval', 'Check uptime', interval, 'A site that fails is checked again every minute regardless.'),
        checks,
        paused,
        h('div', { class: 'actions' }, [
          // After "does not resolve": the same address again means it is meant
          h('button', { type: 'submit', class: 'button is-primary' }, form.unresolvedUrl ? (form.id ? 'Save anyway' : 'Add anyway') : (form.id ? 'Save changes' : 'Add site')),
          h('button', { type: 'button', class: 'button', 'data-action': 'form-cancel' }, 'Cancel')
        ]),
        remove
      ])
    ]));
    // Picking another group changes the hint; said, not only shown
    var moveHint = node.querySelector('#f-group-hint');
    if (moveHint) moveHint.setAttribute('aria-live', 'polite');
  }

  /** The form's values, as the API takes them. */
  function formValues(formNode) {
    var data = new FormData(formNode);
    return {
      url: data.get('url') || '', group: data.get('group') || '', name: data.get('name') || '', keyword: data.get('keyword') || '',
      interval: data.get('interval') || '', paused: data.get('paused') === 'on', checks: data.getAll('checks')
    };
  }

  function saveSite(formNode) {
    var form = state.form;
    var body = formValues(formNode);
    form.values = body;
    if (form.unresolvedUrl && body.url === form.unresolvedUrl) body = Object.assign({ unresolved_ok: true }, body);
    if (form.id) body = Object.assign({ id: form.id }, body);
    var submit = formNode.querySelector('[type="submit"]');
    var done = busy(submit, form.id ? 'Saving…' : 'Adding…');
    return api('/?api=site-save', {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body)
    }).then(function (result) {
      toast(form.id ? (result.changes.length ? 'Saved ' + siteTitle(result.site) : 'Nothing changed') : 'Added ' + siteTitle(result.site) + ': the first check runs within a minute');
      state.form = null;
      // The site first, the list alongside: the detail need not wait for the whole overview
      load();
      return openDetail(result.site.id);
    }).catch(function (error) {
      if (error.message === 'Logged out') return;
      done();
      if (!error.body || !error.body.errors) return toast(error.message);
      form.errors = error.body.errors;
      form.unresolvedUrl = error.body.unresolved ? body.url : null;
      renderSiteForm();
      var invalid = regions.detail.querySelector('[aria-invalid="true"]');
      if (invalid) invalid.focus();
    });
  }

  // -----------------------------------------------------------------
  // Groups: when alerts go out and where. The webhooks are secrets: the
  // form shows them masked, keeps them unless a new one is typed, and
  // removes one only when asked to.
  // -----------------------------------------------------------------

  function openGroupForm(id, opener) {
    if (opener) state.opener = opener;
    var show = function (group) {
      state.form = {
        kind: 'group', id: group ? group.id : null, label: group ? group.name : null, sites: group ? group.sites : 0, windows: group ? group.maintenance : [],
        current: group ? { slack: group.slack, webhook: group.webhook } : { slack: '', webhook: '' },
        errors: {}, confirmRemove: false,
        values: group
          ? { name: group.name, policy: group.policy, delay: group.delay_minutes ? String(group.delay_minutes) : '10', slack: '', webhook: '', slack_clear: false, webhook_clear: false, email: group.email }
          : { name: '', policy: 'immediate', delay: '10', slack: '', webhook: '', slack_clear: false, webhook_clear: false, email: '' }
      };
      renderGroupForm();
      if (!regions.detail.open) regions.detail.showModal();
      var first = regions.detail.querySelector('#g-name');
      if (first) first.focus();
    };
    if (id === null) return show(null);
    return api('/?api=group&id=' + encodeURIComponent(id)).then(function (result) { show(result.group); }).catch(function (error) {
      if (error.message !== 'Logged out') toast(error.message);
    });
  }

  function groupField(name, label, control, hint) {
    var id = 'g-' + name;
    var error = state.form.errors[name];
    control.id = id;
    var describedBy = [error ? id + '-error' : null, hint ? id + '-hint' : null].filter(Boolean).join(' ');
    if (describedBy) control.setAttribute('aria-describedby', describedBy);
    if (error) control.setAttribute('aria-invalid', 'true');
    return h('div', { class: 'field' + (error ? ' is-invalid' : '') }, [
      h('label', { for: id }, label),
      control,
      error ? h('p', { class: 'field-error', id: id + '-error' }, error) : null,
      hint ? h('p', { class: 'field-hint', id: id + '-hint' }, hint) : null
    ]);
  }

  /** A webhook field: empty keeps the one that is set, shown masked; a box removes it. */
  function secretField(name, label, current, example) {
    var v = state.form.values;
    var input = textInput(name, v[name], { inputmode: 'url', placeholder: current ? 'Set: ' + current + ' (type a new one to replace it)' : example });
    var node = groupField(name, label, input, null);
    if (current) {
      var box = h('input', { type: 'checkbox', name: name + '_clear' });
      box.checked = v[name + '_clear'];
      append(node, h('label', { class: 'check is-small' }, [box, 'Remove it']));
    }
    return node;
  }

  function renderGroupForm() {
    var form = state.form;
    var v = form.values;
    var setup = state.data.setup;
    var node = regions.detail;
    clear(node);

    var delay = textInput('delay', v.delay, { inputmode: 'numeric', 'aria-label': 'Minutes down before an alert' });
    delay.id = 'g-delay';
    if (form.errors.delay) delay.setAttribute('aria-invalid', 'true');
    var policies = [
      ['immediate', 'Right away', 'When a site goes down, and when it is back.'],
      ['delayed', 'After a while down', null],
      ['digest', 'Once a day', 'A summary at ' + setup.digest_time + ' of what happened and what is still down.'],
      ['silent', 'Never', 'The dashboard only.']
    ];
    var policy = h('fieldset', { class: 'field' + (form.errors.delay || form.errors.policy ? ' is-invalid' : '') }, [
      h('legend', {}, 'When alerts go out'),
      h('ul', { class: 'choice-list' }, policies.map(function (p) {
        var radio = h('input', { type: 'radio', name: 'policy', value: p[0] });
        radio.checked = v.policy === p[0];
        var small = p[0] === 'delayed'
          ? h('small', { class: 'inline-minutes' }, ['Only when a site has been down for', delay, 'min; shorter outages send nothing.'])
          : h('small', {}, p[2]);
        return h('li', {}, h('label', {}, [radio, h('b', {}, p[1]), small]));
      })),
      form.errors.delay ? h('p', { class: 'field-error', id: 'g-delay-error' }, form.errors.delay) : null
    ]);

    var remove = null;
    if (form.id) {
      remove = form.confirmRemove
        ? h('div', { class: 'confirm', role: 'alert' }, form.removeError ? [
          // Users are limited to the group: it stays until that changes
          h('p', {}, form.removeError),
          h('div', { class: 'actions' }, h('button', { type: 'button', class: 'button', 'data-action': 'group-remove-cancel' }, 'OK'))
        ] : [
          h('p', {}, ['Remove the group ', h('b', {}, form.label), '? ' + (form.sites ? capitalize(plural(form.sites, 'site stays', 'sites stay')) + ', without a group: their alerts go to the defaults in .env.' : 'It has no sites.')]),
          h('div', { class: 'actions' }, [
            h('button', { type: 'button', class: 'button is-danger', 'data-action': 'remove-group' }, 'Remove group'),
            h('button', { type: 'button', class: 'button', 'data-action': 'group-remove-cancel' }, 'Keep it')
          ])
        ])
        : h('div', { class: 'danger-zone' }, h('button', { type: 'button', class: 'button is-quiet-danger', 'data-action': 'group-remove-ask' }, 'Remove this group…'));
    }

    append(node, h('div', { class: 'drawer-inner is-form' }, [
      h('div', { class: 'drawer-head' }, [
        h('div', { class: 'medallion' }, icon('bell')),
        h('div', {}, [
          h('h2', { id: 'detail-title' }, form.id ? 'Change ' + form.label : 'Add a group'),
          h('p', {}, form.id ? plural(form.sites, 'site', 'sites') + ' in it.' : 'Each group has its own rules for alerts.')
        ]),
        h('button', { type: 'button', class: 'close-button', 'data-action': 'close', 'aria-label': 'Close' }, icon('close'))
      ]),
      h('form', { class: 'site-form', 'data-form': 'group', novalidate: true }, [
        groupField('name', 'Name', textInput('name', v.name, { spellcheck: 'true', placeholder: 'Customers' }), null),
        policy,
        h('fieldset', { class: 'field' }, [
          h('legend', {}, 'Where alerts go'),
          h('p', { class: 'field-hint' }, 'Leave all three empty and they go to the defaults in .env.')
        ]),
        secretField('slack', 'Slack incoming webhook', form.current.slack, 'https://hooks.slack.com/services/…'),
        secretField('webhook', 'JSON webhook', form.current.webhook, 'https://example.com/vaka-alerts'),
        groupField('email', 'Email', textInput('email', v.email, { inputmode: 'email', placeholder: 'ops@example.com' }), 'Several, separated by commas. Needs a mail server where Vaka runs.'),
        form.id ? h('div', { class: 'actions' }, [
          h('button', { type: 'button', class: 'button', 'data-action': 'group-test' }, [icon('bell'), 'Send a test alert']),
          h('small', { class: 'field-hint' }, 'With the saved settings.')
        ]) : null,
        form.id ? h('fieldset', { class: 'field' }, [
          h('legend', {}, 'Maintenance windows'),
          form.windows.length ? h('ul', { class: 'window-list' }, form.windows.map(function (w) {
            return h('li', {}, [w.when + (w.label ? ' (' + w.label + ')' : ''), ' ', h('button', { type: 'button', class: 'link-button', 'data-action': 'window-remove', 'data-id': w.id, 'aria-label': 'Remove the maintenance window ' + w.when }, 'Remove')]);
          })) : h('p', { class: 'field-hint' }, 'None. Outages inside a window are not alerted.'),
          h('div', {}, h('button', { type: 'button', class: 'button', 'data-action': 'add-window-group' }, [icon('quiet'), 'Add a maintenance window']))
        ]) : null,
        h('div', { class: 'actions' }, [
          h('button', { type: 'submit', class: 'button is-primary' }, form.id ? 'Save changes' : 'Add group'),
          h('button', { type: 'button', class: 'button', 'data-action': 'form-cancel' }, 'Cancel')
        ]),
        remove
      ])
    ]));
  }

  function groupValues(formNode) {
    var data = new FormData(formNode);
    return {
      name: data.get('name') || '', policy: data.get('policy') || 'immediate', delay: data.get('delay') || '',
      slack: data.get('slack') || '', webhook: data.get('webhook') || '', email: data.get('email') || '',
      slack_clear: data.get('slack_clear') === 'on', webhook_clear: data.get('webhook_clear') === 'on'
    };
  }

  function saveGroup(formNode) {
    var form = state.form;
    var body = groupValues(formNode);
    form.values = body;
    if (form.id) body = Object.assign({ id: form.id }, body);
    var submit = formNode.querySelector('[type="submit"]');
    var done = busy(submit, form.id ? 'Saving…' : 'Adding…');
    return api('/?api=group-save', {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body)
    }).then(function (result) {
      toast(form.id ? (result.changes.length ? 'Saved the group ' + result.group.name : 'Nothing changed') : 'Added the group ' + result.group.name);
      closeDetail();
      return load();
    }).catch(function (error) {
      if (error.message === 'Logged out') return;
      done();
      if (!error.body || !error.body.errors) return toast(error.message);
      form.errors = error.body.errors;
      renderGroupForm();
      var invalid = regions.detail.querySelector('[aria-invalid="true"]');
      if (invalid) invalid.focus();
    });
  }

  function testGroupAlert(button) {
    button.disabled = true;
    return api('/?api=alert-test', {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({ group: state.form.id })
    }).then(function (result) {
      var failed = result.sent.filter(function (s) { return !s.ok; });
      toast(failed.length
        ? 'Could not reach ' + failed.map(function (s) { return s.to; }).join(' and ')
        : 'Sent a test alert to ' + result.sent.map(function (s) { return s.to; }).join(' and '));
    }).catch(function (error) {
      if (error.message !== 'Logged out') toast(error.message);
    }).then(function () { button.disabled = false; });
  }

  function removeGroup(button) {
    button.disabled = true;
    return api('/?api=group-remove', {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({ id: state.form.id })
    }).then(function (result) {
      toast('Removed the group ' + result.removed + (result.sites ? ': its ' + plural(result.sites, 'site has', 'sites have') + ' no group now' : ''));
      closeDetail();
      return load();
    }).catch(function (error) {
      if (error.message === 'Logged out') return;
      if (!error.body) return toast(error.message);
      state.form.values = groupValues(regions.detail.querySelector('[data-form="group"]'));
      state.form.removeError = error.message;
      renderGroupForm();
      var ok = regions.detail.querySelector('[data-action="group-remove-cancel"]');
      if (ok) ok.focus();
    });
  }

  // -----------------------------------------------------------------
  // Maintenance windows: for a site or a whole group, every day, some
  // days or once, in Vaka's time zone. The form returns to where it came from.
  // -----------------------------------------------------------------

  var DAYS = [['mon', 'Mon'], ['tue', 'Tue'], ['wed', 'Wed'], ['thu', 'Thu'], ['fri', 'Fri'], ['sat', 'Sat'], ['sun', 'Sun']];

  function openWindowForm(from) {
    state.form = Object.assign({
      kind: 'window', errors: {},
      values: { target: from.groupId && !from.siteId ? 'group' : 'site', kind: 'daily', start: '00:00', end: '07:00', days: ['mon', 'tue', 'wed', 'thu', 'fri'], from: '', to: '', label: '' }
    }, from);
    renderWindowForm();
    var first = regions.detail.querySelector('[name="kind"]:checked');
    if (first) first.focus();
  }

  function renderWindowForm() {
    var form = state.form;
    var v = form.values;
    var node = regions.detail;
    clear(node);
    var radio = function (name, value, label, small) {
      var input = h('input', { type: 'radio', name: name, value: value });
      input.checked = v[name] === value;
      return h('li', {}, h('label', {}, [input, h('b', {}, label), small ? h('small', {}, small) : null]));
    };
    var time = function (name, label) {
      var input = h('input', { type: 'time', name: name, id: 'w-' + name });
      input.value = v[name];
      return h('label', { class: 'time-field', for: 'w-' + name }, [label, input]);
    };
    var moment = function (name, label) {
      var input = h('input', { type: 'datetime-local', name: name, id: 'w-' + name });
      input.value = v[name];
      return h('label', { class: 'time-field', for: 'w-' + name }, [label, input]);
    };

    var target = form.siteId && form.groupName
      ? h('fieldset', { class: 'field' }, [
        h('legend', {}, 'For'),
        h('ul', { class: 'choice-list' }, [
          radio('target', 'site', 'Only ' + form.siteLabel, null),
          radio('target', 'group', 'Every site in ' + form.groupName, 'Sites added to the group later too.')
        ])
      ])
      : null;
    var when = h('fieldset', { class: 'field' + (form.errors.when ? ' is-invalid' : '') }, [
      h('legend', {}, 'When'),
      h('ul', { class: 'choice-list' }, [
        radio('kind', 'daily', 'Every day', null),
        radio('kind', 'days', 'Some days of the week', null),
        radio('kind', 'once', 'Once', null)
      ]),
      v.kind === 'once'
        ? h('div', { class: 'time-row' }, [moment('from', 'From'), moment('to', 'To')])
        : h('div', { class: 'time-row' }, [time('start', 'From'), time('end', 'To')]),
      v.kind === 'days' ? h('ul', { class: 'day-list' }, DAYS.map(function (d) {
        var box = h('input', { type: 'checkbox', name: 'days', value: d[0] });
        box.checked = v.days.indexOf(d[0]) !== -1;
        return h('li', {}, h('label', {}, [box, d[1]]));
      })) : null,
      form.errors.when ? h('p', { class: 'field-error', id: 'w-when-error', role: 'alert' }, form.errors.when) : null,
      h('p', { class: 'field-hint' }, 'In ' + state.data.timezone + '. An end before the start runs past midnight.')
    ]);
    var label = textInput('label', v.label, { spellcheck: 'true', placeholder: 'Nightly imports' });
    label.id = 'w-label';
    if (form.errors.label) label.setAttribute('aria-invalid', 'true');

    append(node, h('div', { class: 'drawer-inner is-form' }, [
      h('div', { class: 'drawer-head' }, [
        h('div', { class: 'medallion' }, icon('quiet')),
        h('div', {}, [
          h('h2', { id: 'detail-title' }, 'Add a maintenance window'),
          h('p', {}, form.siteId ? form.siteLabel : 'Group ' + form.groupName)
        ]),
        h('button', { type: 'button', class: 'close-button', 'data-action': 'close', 'aria-label': 'Close' }, icon('close'))
      ]),
      h('p', { class: 'form-intro' }, 'Checks keep running. Outages that start and end inside the window are not alerted; a site still down when it ends is.'),
      h('form', { class: 'site-form', 'data-form': 'window', novalidate: true }, [
        target,
        when,
        h('div', { class: 'field' + (form.errors.label ? ' is-invalid' : '') }, [
          h('label', { for: 'w-label' }, 'Label'),
          label,
          form.errors.label ? h('p', { class: 'field-error' }, form.errors.label) : null,
          h('p', { class: 'field-hint' }, 'Optional: what happens then.')
        ]),
        h('div', { class: 'actions' }, [
          h('button', { type: 'submit', class: 'button is-primary' }, 'Add window'),
          h('button', { type: 'button', class: 'button', 'data-action': 'form-cancel' }, 'Cancel')
        ])
      ])
    ]));
  }

  /** The form's values. A field the chosen kind does not show keeps what it had, so switching back loses nothing. */
  function windowValues(formNode) {
    var data = new FormData(formNode);
    var was = state.form.values;
    var shown = function (name) { return Boolean(formNode.querySelector('[name="' + name + '"]')); };
    return {
      target: data.get('target') || was.target, kind: data.get('kind') || 'daily',
      start: shown('start') ? data.get('start') || '' : was.start, end: shown('end') ? data.get('end') || '' : was.end,
      days: shown('days') ? data.getAll('days') : was.days,
      from: shown('from') ? data.get('from') || '' : was.from, to: shown('to') ? data.get('to') || '' : was.to,
      label: data.get('label') || ''
    };
  }

  /** Back to where a form for a window or a server was opened: the site's detail or the group's form. */
  function backFromForm(form) {
    if (form.back === 'group') return openGroupForm(form.groupId);
    state.form = null;
    return openDetail(form.siteId);
  }

  function saveWindow(formNode) {
    var form = state.form;
    var v = windowValues(formNode);
    form.values = v;
    var body = Object.assign({}, v, v.target === 'group' ? { group_id: form.groupId } : { site_id: form.siteId });
    var submit = formNode.querySelector('[type="submit"]');
    submit.disabled = true;
    return post('/?api=maintenance-add', body).then(function (result) {
      toast('Maintenance window added: ' + result.when);
      return load().then(function () { return backFromForm(form); });
    }).catch(function (error) {
      if (error.message === 'Logged out') return;
      submit.disabled = false;
      if (!error.body || !error.body.errors) return toast(error.message);
      form.errors = error.body.errors;
      renderWindowForm();
      var focus = regions.detail.querySelector(form.errors.label ? '#w-label' : '[name="kind"]:checked');
      if (focus) focus.focus();
    });
  }

  function removeWindow(button) {
    button.disabled = true;
    return post('/?api=maintenance-remove', { id: Number(button.getAttribute('data-id')) }).then(function (result) {
      toast('Maintenance window removed: ' + result.removed);
      return load().then(function () {
        if (state.form && state.form.kind === 'group') return openGroupForm(state.form.id);
        if (state.detailId) return openDetail(state.detailId);
      });
    }).catch(function (error) {
      if (error.message !== 'Logged out') toast(error.message);
      button.disabled = false;
    });
  }

  // -----------------------------------------------------------------
  // A server: its name in alerts and the dashboard, and every site on it
  // moved to a group at once
  // -----------------------------------------------------------------

  function openServerForm(siteId, siteLabel) {
    return api('/?api=server&site=' + encodeURIComponent(siteId)).then(function (result) {
      var server = result.server;
      state.form = { kind: 'server', back: 'site', siteId: siteId, siteLabel: siteLabel, ip: server.ip, label: server.label, sites: server.sites, groups: server.groups || [], errors: {}, values: { label: server.label, group: '__keep' } };
      renderServerForm();
      var first = regions.detail.querySelector('#s-label');
      if (first) first.focus();
    }).catch(function (error) {
      if (error.message !== 'Logged out') toast(error.message);
    });
  }

  function renderServerForm() {
    var form = state.form;
    var v = form.values;
    var node = regions.detail;
    clear(node);
    var name = textInput('label', v.label, { placeholder: 'web-1' });
    name.id = 's-label';
    if (form.errors.label) name.setAttribute('aria-invalid', 'true');
    var group = h('select', { name: 'group', id: 's-group' }, [h('option', { value: '__keep' }, 'Keep their groups'), h('option', { value: '' }, 'No group')].concat(state.data.setup.groups.map(function (g) {
      return h('option', { value: g }, 'Move them to ' + g);
    })));
    group.value = v.group;

    append(node, h('div', { class: 'drawer-inner is-form' }, [
      h('div', { class: 'drawer-head' }, [
        h('div', { class: 'medallion' }, icon('server')),
        h('div', {}, [
          h('h2', { id: 'detail-title' }, 'Server ' + (form.label ? form.label + ' (' + form.ip + ')' : form.ip)),
          h('p', {}, plural(form.sites.length, 'site answers', 'sites answer') + ' on ' + form.ip + '.')
        ]),
        h('button', { type: 'button', class: 'close-button', 'data-action': 'close', 'aria-label': 'Close' }, icon('close'))
      ]),
      h('form', { class: 'site-form', 'data-form': 'server', novalidate: true }, [
        h('div', { class: 'field' + (form.errors.label ? ' is-invalid' : '') }, [
          h('label', { for: 's-label' }, 'Name'),
          name,
          form.errors.label ? h('p', { class: 'field-error' }, form.errors.label) : null,
          h('p', { class: 'field-hint' }, 'Alerts then say "' + (v.label || 'web-1') + ' (' + form.ip + ')". Empty removes the name.')
        ]),
        h('div', { class: 'field' + (form.errors.group ? ' is-invalid' : '') }, [
          h('label', { for: 's-group' }, 'Every site on it'),
          group,
          form.errors.group ? h('p', { class: 'field-error' }, form.errors.group) : null,
          h('p', { class: 'field-hint' }, form.sites.join(', ')),
          // Who moving them shows them to, said as the group is picked
          h('p', { class: 'field-hint', id: 's-group-move', 'aria-live': 'polite' }, serverMoveWords(v.group))
        ]),
        h('div', { class: 'actions' }, [
          h('button', { type: 'submit', class: 'button is-primary' }, 'Save'),
          h('button', { type: 'button', class: 'button', 'data-action': 'form-cancel' }, 'Cancel')
        ])
      ])
    ]));
  }

  /** What moving every site on the server to a group changes for who sees them; nothing while they keep their groups. */
  function serverMoveWords(group) {
    var form = state.form;
    return group === '__keep' ? '' : moveWords(form.groups, group, form.sites.length > 1);
  }

  function saveServer(formNode) {
    var form = state.form;
    var data = new FormData(formNode);
    form.values = { label: data.get('label') || '', group: data.get('group') || '' };
    var body = { ip: form.ip, label: form.values.label };
    if (form.values.group !== '__keep') body.group = form.values.group;
    var submit = formNode.querySelector('[type="submit"]');
    submit.disabled = true;
    return post('/?api=server-save', body).then(function (result) {
      toast('Saved the server' + (result.moved ? ', and moved ' + plural(result.moved, 'site', 'sites') : ''));
      return load().then(function () { return backFromForm(form); });
    }).catch(function (error) {
      if (error.message === 'Logged out') return;
      submit.disabled = false;
      if (!error.body || !error.body.errors) return toast(error.message);
      form.errors = error.body.errors;
      renderServerForm();
    });
  }

  /** A JSON POST with the CSRF token. */
  function post(path, body) {
    return api(path, {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(body)
    });
  }

  function removeSite(button) {
    var form = state.form;
    button.disabled = true;
    return api('/?api=site-remove', {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({ id: form.id })
    }).then(function (result) {
      toast('Removed ' + result.removed);
      closeDetail();
      return load();
    }).catch(function (error) {
      if (error.message !== 'Logged out') toast(error.message);
      button.disabled = false;
    });
  }

  function capitalize(text) {
    return text.charAt(0).toUpperCase() + text.slice(1);
  }

  /** The forms a header line is offered in: as sent, or as the line that adds it in nginx or Apache. */
  var HEADER_FORMATS = { header: 'Header', nginx: 'nginx', apache: 'Apache' };

  /**
   * Which form to show the lines in: the one picked this session, else the web
   * server the site answered with, else the last choice saved, else the header
   * as sent. Saved in this browser only, as the theme is.
   */
  function headerFormat(headers) {
    if (state.headerFormat) return state.headerFormat;
    if (headers.server && HEADER_FORMATS[headers.server]) return headers.server;
    try {
      var saved = localStorage.getItem('vaka-header-format');
      if (HEADER_FORMATS[saved]) return saved;
    } catch (e) { /* no storage: the header as sent */ }
    return 'header';
  }

  /**
   * A header ("Name: value") as the line that adds it, so it pastes in as it
   * is: nginx takes the name and the value apart, with always so error pages
   * get it too, and Apache the same. A header copied as sent into nginx is a
   * line nginx refuses, and the whole config with it.
   */
  function headerLine(fix, format) {
    var at = fix.indexOf(': ');
    if (at < 0 || format === 'header') return fix;
    var name = fix.slice(0, at);
    var value = fix.slice(at + 2).replace(/"/g, '\\"');
    if (format === 'nginx') return 'add_header ' + name + ' "' + value + '" always;';
    return 'Header always set ' + name + ' "' + value + '"';
  }

  /** A line to paste in, with a button that copies it. */
  function pasteLine(line) {
    return h('div', { class: 'header-fix' }, [
      h('code', {}, line),
      h('button', { type: 'button', class: 'button is-small', 'data-action': 'copy', 'data-copy': line, 'aria-label': 'Copy ' + line }, [icon('copy'), 'Copy'])
    ]);
  }

  /** A tip with its takeaway marked **like this** (as Markdown does): text nodes and <strong>, never markup from the text. */
  function rich(text) {
    var parts = String(text).split(/\*\*(.+?)\*\*/);
    return parts.map(function (part, i) { return i % 2 ? h('strong', {}, part) : part; });
  }

  /** Scripts named by a CSP item: the start of each inline one, or the address of each external one. */
  function scriptList(lead, scripts) {
    return h('div', { class: 'header-scripts' }, [
      h('p', {}, lead),
      h('ul', {}, scripts.map(function (script) { return h('li', {}, h('code', {}, script)); }))
    ]);
  }

  /**
   * A CSP item's note with the scripts it names ("2 inline scripts and a script
   * from cdn.example") as a button that opens the list of them below. The note
   * is Vaka's own words around hosts from the site, all set as text. The phrase
   * ends where the page is named: "on the start page." or "on example.com/guide/."
   */
  function scriptsNote(note) {
    var match = /((?:\d+|an?) (?:inline scripts?|inline styles?|scripts?|stylesheets?|images?|frames?)\b.*?)(?= on (?:the (?:start )?page|\S+)\.|, which)/.exec(note);
    if (!match) return [note];
    return [
      note.slice(0, match.index),
      // A link, not a button: a button cannot wrap inside, so a long phrase sat on lines of its own
      h('a', { href: '#', class: 'link-button', 'data-action': 'show-scripts' }, match[1]),
      note.slice(match.index + match[1].length)
    ];
  }

  /**
   * The six protections, in the order to add them (the safest first, CSP last):
   * what is missing or weak, with why, what the site sends and the line that
   * adds it, labelled with what to do, in the form the site's web server
   * takes; what each does and how to add it one click away. What is in place
   * is one line that opens to show what the site sends. A CSP in place that
   * blocks parts of the page is something to do, not in place.
   */
  function headerList(headers) {
    if (!headers) return h('p', { class: 'chart-note' }, 'Not checked yet.');
    if (!headers.items || !headers.items.length) return h('p', { class: 'chart-note' }, headers.summary);
    var words = { weak: 'Weak', missing: 'Missing' };
    // A policy that may stop a script sending back is something to look at, though in place
    var todo = headers.items.filter(function (item) { return item.level !== 'good' || item.blocks || item.unsent; });
    var done = headers.items.filter(function (item) { return item.level === 'good' && !item.blocks && !item.unsent; });
    var format = headerFormat(headers);
    var where = format === 'apache' ? 'Apache' : 'nginx';
    var tip = format === 'apache' ? headers.tip_apache : headers.tip;
    return h('div', { class: 'headers-block' }, [
      h('p', { class: 'headers-summary' }, headers.summary),
      todo.length > 1 ? h('p', { class: 'headers-lead' }, 'Start at the top: the safest come first.') : null,
      todo.some(function (item) { return item.fix; }) ? h('div', { class: 'segmented header-format', role: 'group', 'aria-label': 'Show the lines as' }, [
        h('span', {}, 'Lines for'),
        Object.keys(HEADER_FORMATS).map(function (key) {
          return h('button', { type: 'button', 'data-action': 'header-format', 'data-format': key, 'aria-pressed': key === format ? 'true' : 'false' }, HEADER_FORMATS[key]);
        })
      ]) : null,
      todo.length ? h('ul', { class: 'header-list' }, todo.map(function (item) {
        // The one that can break a site, said where it is not the only thing to do
        var last = item.key === 'csp' && item.level === 'missing' && todo.length > 1;
        var word = item.blocks ? (item.level === 'weak' ? 'Weak, and it blocks parts of the page' : 'In place, but it blocks parts of the page') : item.report_only ? 'Sent as Report-Only, not in force' : item.level === 'good' ? 'In place' : words[item.level];
        // What the item names: what the CSP blocks, what a Report-Only one would, or what the page has that a first one would
        var would = item.blocks ? 'it blocks' : item.blocked || /would block/.test(item.note || '') ? 'it would block' : null;
        var remark = item.caution || (last ? 'do it last, it can break the site' : '');
        var named = Boolean(item.inline || item.blocked || item.blocked_assets);
        var note = item.note ? capitalize(item.note) + '.' : '';
        var look = item.blocks || item.level === 'good';
        return h('li', { class: 'is-' + (look ? 'weak' : item.level) }, [
          icon(item.level === 'weak' || look ? 'failing' : 'down'),
          h('div', {}, [
            // The state in the level's weight; a remark after it lighter, so the two read apart
            h('p', {}, [h('b', {}, capitalize(item.label)), h('span', { class: 'header-level' }, [' · ' + word, remark ? h('span', { class: 'header-remark' }, ' · ' + remark) : null])]),
            note ? h('p', { class: 'header-why' }, named ? scriptsNote(note) : note) : null,
            // A weak one: what the site sends, so the change below can be read against it
            item.level === 'weak' && item.value ? h('p', { class: 'header-sent' }, ['The site sends: ', h('code', {}, item.value)]) : null,
            item.fix ? h('p', { class: 'fix-label' }, item.fix_label || 'Add this line:') : null,
            item.fix ? pasteLine(headerLine(item.fix, format)) : null,
            // Sent twice: no line to add, so where to look is what to do
            item.twice ? h('p', { class: 'header-tip' }, rich(item.tip)) : null,
            h('details', { class: 'header-more' }, [
              h('summary', {}, [icon('chevron'), item.twice ? 'What it does' : item.blocks ? 'What it does, what it blocks, what to do' : named ? 'What it does, what it would block, how to add it' : 'What it does and how to add it']),
              h('p', { class: 'header-what' }, item.what),
              item.tip && !item.twice ? h('p', { class: 'header-tip' }, rich(item.tip)) : null,
              item.inline ? scriptList(would ? 'The inline scripts ' + would + ' start:' : 'The inline scripts on the page start:', item.inline) : null,
              item.blocked ? scriptList('The scripts ' + would + ':', item.blocked) : null,
              item.blocked_assets ? scriptList('The styles, images and frames ' + would + ':', item.blocked_assets.map(function (asset) { return asset.kind + ' ' + asset.url; })) : null
            ])
          ])
        ]);
      })) : null,
      // What is in place, one line, and what the site sends for each one click away
      done.length ? h('details', { class: 'headers-done' }, [
        h('summary', {}, [icon('up'), h('span', {}, 'In place: ' + done.map(function (item) { return item.label + (item.twice ? ' (sent twice)' : item.meta ? ' (in the page)' : ''); }).join(', ')), icon('chevron')]),
        h('ul', { class: 'header-values' }, done.map(function (item) {
          return h('li', {}, [h('b', {}, capitalize(item.label)), item.value ? h('code', {}, item.value) : h('span', { class: 'header-sent' }, item.note ? capitalize(item.note) : 'In place'),
            // Sent twice, or set in the page: said, with where to look or why to leave it there
            item.twice || item.meta ? h('p', { class: 'header-why' }, capitalize(item.note) + '.') : null,
            item.twice || item.meta ? h('p', { class: 'header-tip' }, rich(item.tip)) : null]);
        }))
      ]) : null,
      // Once under the list, while there is something to add, for the server the lines are for
      tip && todo.some(function (item) { return item.fix; }) ? h('details', { class: 'headers-tip' }, [
        h('summary', {}, [icon('chevron'), 'Adding them in ' + where]),
        h('p', {}, rich(tip.replace(/^Adding them in (nginx|Apache)\? /, '')))
      ]) : null
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
    // Only lines and shapes in the SVG, which stretches to the width; the labels are HTML, so text is never squeezed
    var width = 500, height = 150, top = 8, bottom = 142, left = 0;
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
      s('line', { class: 'chart-grid', x1: left, x2: width, y1: bottom, y2: bottom })
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
      h('div', { class: 'chart-plot' }, [
        h('div', { class: 'chart-y', 'aria-hidden': 'true' }, [h('span', {}, max + ' ms'), h('span', {}, max / 2 + ' ms'), h('span', {}, '0')]),
        s('svg', { class: 'chart', viewBox: '0 0 ' + width + ' ' + height, preserveAspectRatio: 'none', role: 'img', 'aria-label': 'Response time, last 24 hours. ' + label }, nodes)
      ]),
      h('p', { class: 'chart-x', 'aria-hidden': 'true' }, [h('span', {}, '24 h ago'), h('span', {}, '12 h ago'), h('span', {}, 'now')]),
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

  /** A site by its name and address when it has a name ("Shop (shop.example)"), else by its address. */
  function siteTitle(site) {
    return site.name ? site.name + ' (' + site.label + ')' : site.label;
  }

  /** A button that says what it is doing until the answer comes; returns how to put it back. */
  function busy(button, text) {
    var idle = button.textContent;
    button.disabled = true;
    button.textContent = text;
    return function () {
      button.disabled = false;
      button.textContent = idle;
    };
  }

  function toast(message) {
    var node = regions.toast;
    // A popover shown after the open site detail lies above it: the detail is a modal dialog, in the top layer
    if (node.showPopover) {
      if (node.matches(':popover-open')) node.hidePopover();
      node.showPopover();
    }
    // The text after it shows, so a screen reader hears the change
    node.textContent = message;
    node.classList.add('is-visible');
    clearTimeout(toast.timer);
    toast.timer = setTimeout(function () {
      node.classList.remove('is-visible');
      toast.timer = setTimeout(function () { if (node.hidePopover && node.matches(':popover-open')) node.hidePopover(); }, 200);
    }, 4000);
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

  /**
   * Queue every check of a site for the next run, then look at the detail
   * again a few times while the results come in (a run each minute; a change
   * in the headers or mail records counts after its second look).
   */
  function recheck(id, button) {
    if (button) button.disabled = true;
    // What to compare with: a check has run again once its time has moved; what each said, to tell what changed
    var site = state.detail && Number(state.detail.site.id) === Number(id) ? state.detail.site : null;
    var before = site ? checkTimes(site) : {};
    var summaries = {};
    var values = {};
    Object.keys(before).forEach(function (key) {
      summaries[key] = checkSummary(site, key);
      var node = regions.detail.querySelector('.tile[data-check="' + key + '"]');
      if (node) values[key] = tileValue(node);
    });
    return api('/?api=site-recheck', {
      method: 'POST',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({ site_id: Number(id) })
    }).then(function (result) {
      toast('Checking ' + result.site.label + ' again: the results come within a minute');
      clearTimeout(state.recheck && state.recheck.timer);
      var current = state.recheck = { id: Number(id), before: before, summaries: summaries, values: values, seen: before, shown: {}, polls: 0 };
      // Every 5 seconds for three minutes: when each check last ran, a few bytes, and the site itself once one has run again,
      // so each tile lands within seconds of its check; nothing while the tab is hidden
      var look = function () {
        if (state.recheck !== current || current.done) return;
        if (state.detailId === null || Number(state.detailId) !== Number(id)) { state.recheck = null; return; }
        current.polls++;
        var asked = document.hidden ? Promise.resolve() : api('/?api=site-times&id=' + encodeURIComponent(id)).then(function (result) {
          var times = result.times || {};
          var moved = Object.keys(current.before).some(function (key) { return times[key] !== current.seen[key]; });
          current.seen = times;
          return moved ? openDetail(id) : null;
        }).catch(function () {});
        asked.then(function () {
          if (state.recheck !== current || current.done) return;
          if (current.polls < 36) { current.timer = setTimeout(look, 5000); return; }
          current.left = state.detail ? recheckLeft(current, state.detail.site) : [];
          renderDetail();
        });
      };
      if (state.detailId !== null) openDetail(id);
      current.timer = setTimeout(look, 5000);
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
    if (action === 'day') pickDay(target);
    if (action === 'add-site') openSiteForm(null, target, target.getAttribute('data-group'));
    if (action === 'add-group') openGroupForm(null, target);
    if (action === 'edit-group') openGroupForm(target.getAttribute('data-group'), target);
    if (action === 'group-test') testGroupAlert(target);
    if (action === 'group-remove-ask' || action === 'group-remove-cancel') {
      state.form.values = groupValues(regions.detail.querySelector('[data-form="group"]'));
      state.form.confirmRemove = action === 'group-remove-ask';
      state.form.removeError = null;
      renderGroupForm();
      var again = regions.detail.querySelector(action === 'group-remove-ask' ? '[data-action="remove-group"]' : '[data-action="group-remove-ask"]');
      if (again) again.focus();
    }
    if (action === 'remove-group') removeGroup(target);
    if (action === 'add-window' && state.detail) {
      var site = state.detail.site;
      openWindowForm({ back: 'site', siteId: site.id, siteLabel: site.label, groupName: site.group, groupId: site.group_id });
    }
    if (action === 'add-window-group' && state.form) openWindowForm({ back: 'group', groupId: state.form.id, groupName: state.form.label });
    if (action === 'window-remove') removeWindow(target);
    if (action === 'edit-server' && state.detail) openServerForm(state.detail.site.id, state.detail.site.label);
    if (action === 'edit-site' && state.detail) openSiteForm(state.detail);
    if (action === 'form-cancel' && state.form && (state.form.kind === 'window' || state.form.kind === 'server')) {
      backFromForm(state.form);
    } else if (action === 'form-cancel') {
      if (state.form && state.form.kind !== 'group' && state.form.id && state.detail) {
        state.form = null;
        renderDetail();
      } else {
        closeDetail();
      }
    }
    if (action === 'remove-ask' || action === 'remove-cancel') {
      state.form.values = formValues(regions.detail.querySelector('[data-form="site"]'));
      state.form.confirmRemove = action === 'remove-ask';
      renderSiteForm();
      var next = regions.detail.querySelector(action === 'remove-ask' ? '[data-action="remove-site"]' : '[data-action="remove-ask"]');
      if (next) next.focus();
    }
    if (action === 'remove-site') removeSite(target);
    if (action === 'snooze') snooze(target.getAttribute('data-sites').split(','), { 'for': target.getAttribute('data-for') }, target);
    if (action === 'unsnooze') snooze(target.getAttribute('data-sites').split(','), { action: 'unsnooze' }, target);
    if (action === 'recheck') recheck(target.getAttribute('data-site'), target);
    if (action === 'show-scripts') {
      // "2 inline scripts" opens the list of them, under "What it does"; the link goes nowhere by itself
      event.preventDefault();
      var more = target.closest('li').querySelector('.header-more');
      more.open = true;
      var list = more.querySelector('.header-scripts');
      if (list) { list.setAttribute('tabindex', '-1'); list.focus(); list.scrollIntoView({ block: 'nearest' }); }
      return;
    }
    if (action === 'header-format' && state.detail) {
      // Only the list is drawn again, and the choice follows to the next site
      state.headerFormat = target.getAttribute('data-format');
      try { localStorage.setItem('vaka-header-format', state.headerFormat); } catch (e) { /* this session only */ }
      var block = target.closest('.headers-block');
      var fresh = headerList(state.detail.site.headers);
      block.replaceWith(fresh);
      var picked = fresh.querySelector('[data-format="' + state.headerFormat + '"]');
      if (picked) picked.focus();
      return;
    }
    if (action === 'copy') {
      navigator.clipboard.writeText(target.getAttribute('data-copy')).then(function () { toast('Copied'); }, function () { toast('Select the text and copy it'); });
    }
    if (action === 'theme') {
      var next = THEMES[(THEMES.indexOf(currentTheme()) + 1) % THEMES.length];
      try { localStorage.setItem('vaka-theme', next); } catch (e) { /* storage blocked: the choice lasts for this page */ }
      applyTheme(next);
    }
  });

  // The forms save through the API, not as a page load
  document.addEventListener('submit', function (event) {
    var formNode = event.target.closest && event.target.closest('[data-form]');
    if (!formNode) return;
    event.preventDefault();
    if (formNode.getAttribute('data-form') === 'site') saveSite(formNode);
    if (formNode.getAttribute('data-form') === 'group') saveGroup(formNode);
    if (formNode.getAttribute('data-form') === 'window') saveWindow(formNode);
    if (formNode.getAttribute('data-form') === 'server') saveServer(formNode);
  });

  // Picking another group says who that shows the site to, in the hint, without redrawing the form
  document.addEventListener('change', function (event) {
    if (event.target.name !== 'group' || !state.form) return;
    var formNode = event.target.closest && event.target.closest('[data-form="site"], [data-form="server"]');
    if (!formNode) return;
    var site = formNode.getAttribute('data-form') === 'site';
    var hint = regions.detail.querySelector(site ? '#f-group-hint' : '#s-group-move');
    if (hint) hint.textContent = site ? groupHint(event.target.value) : serverMoveWords(event.target.value);
  });

  // The window form shows the fields for the kind of window chosen
  document.addEventListener('change', function (event) {
    var formNode = event.target.closest && event.target.closest('[data-form="window"]');
    if (!formNode || event.target.name !== 'kind') return;
    state.form.values = windowValues(formNode);
    renderWindowForm();
    var chosen = regions.detail.querySelector('[name="kind"]:checked');
    if (chosen) chosen.focus();
  });

  // Cmd+K (Ctrl+K elsewhere) jumps to the filter, as in the other Webready tools: "/" needs Shift on many
  // keyboard layouts (Shift+7 on a Swedish one). Ctrl+Shift+K is left to the browser, and a keydown
  // without a key (autofill sends them) is ignored. The field shows the keys for this computer.
  var MAC = /Mac|iPhone|iPad/.test(navigator.userAgent);
  filterInput.setAttribute('aria-keyshortcuts', MAC ? 'Meta+K' : 'Control+K');
  document.querySelector('.filter kbd').textContent = MAC ? '\u2318K' : 'Ctrl K';
  document.addEventListener('keydown', function (event) {
    if (typeof event.key !== 'string' || event.key.toLowerCase() !== 'k' || event.shiftKey || event.altKey) return;
    if (!(MAC ? event.metaKey : event.ctrlKey) || regions.detail.open) return;
    event.preventDefault();
    filterInput.focus();
    filterInput.select();
  });

  // A day bar tells its day: in a tooltip in the site list, at once (a title
  // waits a second and never shows on a phone), and in the line under the
  // bars in the detail, where a tap or the keyboard picks a day too. The
  // moon names itself in the same tooltip, beside it in its own light.
  var tip = h('div', { class: 'hover-tip', 'aria-hidden': 'true' });
  var tipFor = null;
  document.body.appendChild(tip);

  function hideTip() {
    tip.classList.remove('is-visible');
    tipFor = null;
  }

  /** Above the node it is for, or below where there is no room; to the left of the moon, in moonlight. */
  function showTip(node) {
    if (node === tipFor) return;
    tipFor = node;
    var moonlight = node.getAttribute('data-tip-side') === 'moonlight';
    tip.textContent = node.getAttribute('data-tip');
    tip.classList.toggle('is-moonlight', moonlight);
    tip.classList.add('is-visible');
    var box = node.getBoundingClientRect();
    var left = moonlight ? box.left - tip.offsetWidth - 10 : box.left + box.width / 2 - tip.offsetWidth / 2;
    var top = moonlight ? box.top + box.height / 2 - tip.offsetHeight / 2 : box.top - tip.offsetHeight - 6;
    // Set through the CSSOM, which the CSP allows (it blocks style attributes in markup)
    tip.style.left = Math.max(8, Math.min(window.innerWidth - tip.offsetWidth - 8, left)) + 'px';
    tip.style.top = (top < 8 ? box.bottom + 6 : top) + 'px';
  }

  function pickDay(bar) {
    if (bar.classList.contains('is-picked')) return;
    var note = regions.detail.querySelector('[data-day-note]');
    bar.parentNode.querySelectorAll('.is-picked').forEach(function (other) { other.classList.remove('is-picked'); });
    bar.classList.add('is-picked');
    if (note) note.textContent = bar.getAttribute('data-tip');
  }

  function unpickDays() {
    var note = regions.detail.querySelector('[data-day-note]');
    regions.detail.querySelectorAll('[data-days] .is-picked').forEach(function (bar) { bar.classList.remove('is-picked'); });
    if (note) note.textContent = note.getAttribute('data-day-note');
  }

  // The bar under the pointer, by where it is along the row: the gaps count too,
  // and a finger can tap or slide along the detail's bars, which are thin on a phone
  function pointAtDay(event) {
    var moon = event.target.closest && event.target.closest('.watch-moon');
    if (moon && event.pointerType !== 'touch') return showTip(moon);
    var bars = event.target.closest && event.target.closest('.days');
    if (!bars || !bars.children.length) return;
    var box = bars.getBoundingClientRect();
    var i = Math.floor((event.clientX - box.left) / box.width * bars.children.length);
    var bar = bars.children[Math.max(0, Math.min(bars.children.length - 1, i))];
    if (bars.hasAttribute('data-days')) pickDay(bar);
    else if (event.pointerType !== 'touch') showTip(bar);
  }
  document.addEventListener('pointermove', pointAtDay);
  document.addEventListener('pointerdown', pointAtDay);
  document.addEventListener('pointerout', function (event) {
    var moon = event.target.closest && event.target.closest('.watch-moon');
    if (moon && !moon.contains(event.relatedTarget)) return hideTip();
    var bars = event.target.closest && event.target.closest('.days');
    // Onto the next bar, or the gap between two: nothing changes yet
    if (!bars || bars.contains(event.relatedTarget)) return;
    hideTip();
    // A finger lifts as it taps, so only a mouse that moves away changes the line: back to a clicked day, or the summary
    if (!bars.hasAttribute('data-days') || event.pointerType !== 'mouse') return;
    if (bars.contains(document.activeElement)) pickDay(document.activeElement);
    else unpickDays();
  });
  window.addEventListener('scroll', hideTip, { passive: true });
  document.addEventListener('focusin', function (event) {
    var bar = event.target.closest && event.target.closest('[data-days] [data-tip]');
    if (bar) pickDay(bar);
  });
  document.addEventListener('focusout', function (event) {
    var strip = event.target.closest && event.target.closest('[data-days]');
    if (strip && !strip.contains(event.relatedTarget)) unpickDays();
  });
  // The arrow keys move from day to day, Home and End to the first and last
  document.addEventListener('keydown', function (event) {
    var bar = event.target.closest && event.target.closest('[data-days] button');
    if (!bar || !/^(ArrowLeft|ArrowRight|Home|End)$/.test(event.key)) return;
    var bars = Array.prototype.slice.call(bar.parentNode.children);
    var i = bars.indexOf(bar);
    var next = bars[event.key === 'Home' ? 0 : event.key === 'End' ? bars.length - 1 : Math.max(0, Math.min(bars.length - 1, i + (event.key === 'ArrowLeft' ? -1 : 1)))];
    event.preventDefault();
    bar.setAttribute('tabindex', '-1');
    next.setAttribute('tabindex', '0');
    next.focus();
  });

  // The owl's eyes follow a mouse over the night panel: both look the same way, from between the eyes, and go all
  // the way when it is far (1.5 of the owl's 40 units, the white still showing round them); only near the beak do
  // they cross. A mouse that rests near it for a moment makes it curious: it tilts its head towards it until the
  // mouse moves on. Never for a finger, never while the owl is wide awake, and never for people who prefer reduced
  // motion (a CSS reset does not reach this)
  var stillness = window.matchMedia('(prefers-reduced-motion: reduce)');
  var gaze = null;
  var gazeFrame = 0;
  var curiosity = 0;

  function lookAt(point) {
    var looking = Boolean(point) && !stillness.matches;
    document.querySelectorAll('.watch-owl:not(.is-awake)').forEach(function (svg) {
      var box = svg.getBoundingClientRect();
      var unit = box.width / 40;
      var body = svg.querySelector('.owl-body');
      var pupils = svg.querySelectorAll('.owl-pupil');
      svg.classList.toggle('is-following', looking);
      if (!looking) {
        svg.classList.remove('is-curious');
        body.style.transform = '';
        pupils.forEach(function (pupil) { pupil.style.transform = ''; });
        return;
      }
      // In the owl's units. Curious about a pointer within 60 of them, it tilts 10 degrees round its feet (20, 35),
      // and its eyes see the pointer turned back as much
      var x = (point.x - box.left) / unit;
      var y = (point.y - box.top) / unit;
      var tilt = point.curious && Math.hypot(x - 20, y - 20) < 60 ? (x < 20 ? -10 : 10) : 0;
      var turn = -tilt * Math.PI / 180;
      var tx = x - 20;
      var ty = y - 35;
      x = 20 + tx * Math.cos(turn) - ty * Math.sin(turn);
      y = 35 + tx * Math.sin(turn) + ty * Math.cos(turn);
      svg.classList.toggle('is-curious', Boolean(tilt));
      body.style.transform = tilt ? 'rotate(' + tilt + 'deg)' : '';
      // The pointer from between the eyes (20, 19), as if 8 of them in front of the face, and how close it is to
      // the beak (20, 24.5), which pulls each pupil in
      var reach = 1.5 / Math.hypot(x - 20, y - 19, 8);
      var cross = 1.2 * Math.max(0, 1 - Math.hypot(x - 20, y - 24.5) / 10);
      pupils.forEach(function (pupil) {
        var dx = (x - 20) * reach + (Number(pupil.firstChild.getAttribute('cx')) < 20 ? cross : -cross);
        var dy = (y - 19) * reach;
        var keep = Math.min(1, 1.5 / Math.hypot(dx, dy));
        // Through the CSSOM, which the CSP allows; px are the owl's own units inside its SVG
        pupil.style.transform = 'translate(' + (dx * keep).toFixed(2) + 'px, ' + (dy * keep).toFixed(2) + 'px)';
      });
    });
  }

  document.addEventListener('pointermove', function (event) {
    if (event.pointerType !== 'mouse' && event.pointerType !== 'pen') return;
    if (!(event.target.closest && event.target.closest('.watch'))) return;
    // A few pixels of a resting hand are not a move: the owl stays curious, and the moment it waits for goes on
    if (!gaze || Math.hypot(event.clientX - gaze.restX, event.clientY - gaze.restY) > 6) {
      gaze = { restX: event.clientX, restY: event.clientY, curious: false };
      clearTimeout(curiosity);
      curiosity = setTimeout(function () {
        if (!gaze) return;
        gaze.curious = true;
        lookAt(gaze);
      }, 2000);
    }
    gaze.x = event.clientX;
    gaze.y = event.clientY;
    // Once a frame, however often the mouse reports
    if (!gazeFrame) gazeFrame = requestAnimationFrame(function () { gazeFrame = 0; lookAt(gaze); });
  });
  document.addEventListener('pointerout', function (event) {
    var panel = event.target.closest && event.target.closest('.watch');
    if (!panel || panel.contains(event.relatedTarget)) return;
    clearTimeout(curiosity);
    gaze = null;
    lookAt(null);
  });
  stillness.addEventListener('change', function () { lookAt(gaze); });

  // A click on the backdrop closes the detail, as Esc does
  regions.detail.addEventListener('click', function (event) {
    if (event.target === regions.detail) closeDetail();
  });
  // Esc closes it too. The event comes a moment later, so one from an earlier close must not shut a drawer opened since
  regions.detail.addEventListener('close', function () {
    if (!regions.detail.open && (state.detailId || state.form)) closeDetail();
  });

  // A check's highlight plays once: gone at its end, so a refresh never plays it again
  regions.detail.addEventListener('animationend', function (event) {
    if (event.animationName === 'fresh') event.target.classList.remove('is-fresh');
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
  // The loading panel gets its moon and owl at once, before the overview comes
  var perch = document.querySelector('[data-perch]');
  if (perch) append(perch, [moon(Date.now()), owl(false)]);
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
