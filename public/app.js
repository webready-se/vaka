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
  var state = { data: null, filter: '', detailId: null, detail: null, timezone: undefined };

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
      node.appendChild(typeof child === 'string' || typeof child === 'number' ? document.createTextNode(String(child)) : child);
    });
  }

  function clear(node) {
    while (node.firstChild) node.removeChild(node.firstChild);
  }

  var ICONS = {
    up: 'M5 12.5l4.5 4.5L19 7.5',
    down: 'M7 7l10 10M17 7L7 17',
    failing: 'M12 7v6M12 16.5v.5',
    paused: 'M9 7v10M15 7v10',
    pending: 'M12 12h.01',
    quiet: 'M20 14.5A8 8 0 1 1 9.5 4a6.5 6.5 0 0 0 10.5 10.5z',
    external: 'M14 5h5v5M19 5l-8 8M17 14v4a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1h4',
    close: 'M6 6l12 12M18 6L6 18'
  };

  function icon(name) {
    return s('svg', { viewBox: '0 0 24 24', 'aria-hidden': 'true', focusable: 'false' }, [
      s('path', { d: ICONS[name], fill: 'none', stroke: 'currentColor', 'stroke-width': name === 'pending' ? 6 : 2.2, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' })
    ]);
  }

  // =================================================================
  // Words and times
  // =================================================================

  var STATUS_WORDS = { up: 'Up', down: 'Down', failing: 'Failing', paused: 'Paused', pending: 'Not checked yet' };

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

  function secondsSince(iso) {
    return Math.max(0, Math.round((Date.now() - Date.parse(iso)) / 1000));
  }

  function ago(iso) {
    var seconds = secondsSince(iso);
    return seconds < 60 ? 'just now' : duration(seconds) + ' ago';
  }

  function sameDay(a, b) {
    var f = new Intl.DateTimeFormat('en-CA', { timeZone: state.timezone, year: 'numeric', month: '2-digit', day: '2-digit' });
    return f.format(a) === f.format(b);
  }

  /** "14:02" today, "24 Oct 14:02" before that, in the server's time zone. */
  function clock(iso) {
    var date = new Date(iso);
    var parts = {};
    new Intl.DateTimeFormat('en-GB', { timeZone: state.timezone, day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' })
      .formatToParts(date)
      .forEach(function (part) { parts[part.type] = part.value; });
    var time = parts.hour + ':' + parts.minute;
    return sameDay(date, new Date()) ? time : parts.day + ' ' + parts.month.slice(0, 3) + ' ' + time;
  }

  function lowerFirst(text) {
    return text ? text.charAt(0).toLowerCase() + text.slice(1) : text;
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

  function render() {
    renderRunState();
    renderBanner();
    renderHero();
    renderAttention();
    renderGroups();
    document.getElementById('app').setAttribute('aria-busy', 'false');
  }

  function renderRunState() {
    var node = regions['run-state'];
    var last = state.data.last_run_at;
    node.textContent = last ? 'Checked ' + ago(last) : 'Not checked yet';
    node.className = 'run-state ' + (state.data.run_ok ? 'is-ok' : 'is-stale');
  }

  function renderBanner() {
    var node = regions.banner;
    clear(node);
    if (state.failed) {
      append(node, h('p', { class: 'banner', role: 'alert' }, [h('strong', {}, 'Vaka cannot be reached right now. '), 'This page tries again every 30 seconds.']));
      return;
    }
    var data = state.data;
    if (!data || data.totals.sites === 0) return;
    if (!data.last_run_at) {
      append(node, h('div', { class: 'banner', role: 'alert' }, [
        h('strong', {}, 'Vaka has not checked anything yet. '),
        'Run it every minute from cron: ', h('code', {}, '* * * * * php /path/to/vaka run')
      ]));
    } else if (!data.run_ok) {
      append(node, h('div', { class: 'banner', role: 'alert' }, [
        h('strong', {}, 'The last check was ' + ago(data.last_run_at) + '. '),
        'Is cron still running ', h('code', {}, 'php vaka run'), ' every minute? Until it does, what you see here is out of date.'
      ]));
    }
  }

  function renderHero() {
    var node = regions.hero;
    clear(node);
    var t = state.data.totals;

    if (t.sites === 0) {
      append(node, h('div', { class: 'empty' }, [
        h('h1', {}, 'Nothing to watch yet'),
        h('p', {}, 'Add your first site, and Vaka starts keeping watch within a minute.'),
        h('ol', {}, [
          h('li', {}, ['Add a site: ', h('code', {}, 'php vaka site:add https://shop.example'), ' (or a whole list: ', h('code', {}, 'php vaka site:import sites.csv'), ')']),
          h('li', {}, ['Run the checks every minute from cron: ', h('code', {}, '* * * * * php /path/to/vaka run')]),
          h('li', {}, ['Tell Vaka where to send alerts: ', h('code', {}, 'SLACK_WEBHOOK_URL'), ' in .env, then ', h('code', {}, 'php vaka alert:test')])
        ])
      ]));
      return;
    }

    var watched = t.sites - t.paused;
    var kind, title, line;
    if (t.down > 0) {
      kind = 'down';
      title = plural(t.down, 'site is down', 'sites are down');
      var quiet = allSites().filter(function (site) { return site.status === 'down' && isQuiet(site); }).length;
      var parts = [];
      if (quiet === t.down) parts.push(t.down === 1 ? 'It is snoozed or in a maintenance window.' : 'All of them are snoozed or in a maintenance window.');
      else if (quiet) parts.push((t.down - quiet) + (t.down - quiet === 1 ? ' needs' : ' need') + ' you, ' + quiet + (quiet === 1 ? ' is' : ' are') + ' snoozed or in a maintenance window.');
      if (t.failing) parts.push(plural(t.failing, 'more site is failing a check', 'more sites are failing a check') + ' and being checked again.');
      line = parts.length ? parts.join(' ') : 'Everything else is up.';
    } else if (t.failing > 0) {
      kind = 'failing';
      title = 'Everything is up, ' + plural(t.failing, 'site is', 'sites are') + ' being checked again';
      line = 'A single failed check is not an outage yet. Vaka checks again within a minute.';
    } else if (t.up === 0) {
      kind = 'empty';
      title = 'Waiting for the first checks';
      line = 'Results show up here after the next run.';
    } else {
      kind = 'up';
      title = t.up === watched ? (t.up === 1 ? 'Your site is up' : 'All ' + t.up + ' sites are up') : plural(t.up, 'site is up', 'sites are up');
      line = 'Nothing needs your attention.';
    }

    var counts = [];
    if (t.up) counts.push(h('li', {}, [statusLabel('up'), String(t.up)]));
    if (t.down) counts.push(h('li', {}, [statusLabel('down'), String(t.down)]));
    if (t.failing) counts.push(h('li', {}, [statusLabel('failing'), String(t.failing)]));
    if (t.paused) counts.push(h('li', {}, [statusLabel('paused'), String(t.paused)]));
    if (t.pending) counts.push(h('li', {}, [statusLabel('pending'), String(t.pending)]));

    append(node, h('div', { class: 'hero-card is-' + kind }, [
      h('div', { class: 'hero-icon' }, icon(kind === 'empty' ? 'pending' : kind)),
      h('div', {}, [h('h1', {}, title), h('p', {}, line), h('ul', { class: 'hero-counts', 'aria-label': 'Sites by status' }, counts)])
    ]));
  }

  // -----------------------------------------------------------------
  // Needs attention: outages first, sites on one server together
  // -----------------------------------------------------------------

  function isQuiet(site) {
    return Boolean(site.snoozed_until || site.maintenance);
  }

  function renderAttention() {
    var node = regions.attention;
    clear(node);
    var problems = allSites().filter(function (site) { return site.status === 'down' || site.status === 'failing'; });
    node.hidden = problems.length === 0;
    if (!problems.length) return;

    var rank = function (site) { return (isQuiet(site) ? 2 : 0) + (site.status === 'failing' ? 1 : 0); };
    problems.sort(function (a, b) { return rank(a) - rank(b) || String(a.since).localeCompare(String(b.since)); });

    // Several sites down on one server are one incident, as in the alerts
    var items = [];
    var byServer = {};
    problems.forEach(function (site) {
      var key = site.status === 'down' && !isQuiet(site) && site.server ? site.server : null;
      if (key && byServer[key]) {
        byServer[key].sites.push(site);
        return;
      }
      var item = { server: key, sites: [site] };
      if (key) byServer[key] = item;
      items.push(item);
    });

    append(node, h('h2', { class: 'section-title', id: 'attention-title' }, 'Needs your attention'));
    append(node, h('ul', { class: 'incidents' }, items.map(function (item) {
      return item.sites.length > 1 ? serverIncident(item) : siteIncident(item.sites[0]);
    })));
  }

  function siteIncident(site) {
    var kind = isQuiet(site) ? 'quiet' : site.status;
    var heading = site.status === 'down' ? ' is down' : ' failed a check, Vaka is checking again';
    var since = site.since ? (site.status === 'down' ? 'Down since ' : 'Since ') + clock(site.since) + ' (' + duration(secondsSince(site.since)) + ')' : '';
    var meta = [since, site.group || 'No group'].filter(Boolean).join(' · ');

    return h('li', { class: 'incident is-' + kind }, [
      h('h3', {}, [icon(kind === 'quiet' ? 'quiet' : site.status), h('span', { class: 'incident-site' }, site.label), heading]),
      h('p', { class: 'incident-meta' }, meta),
      h('p', { class: 'incident-summary' }, site.summary),
      site.advice ? h('p', { class: 'incident-advice' }, site.advice) : null,
      badges(site),
      h('div', { class: 'actions' }, siteActions(site))
    ]);
  }

  function serverIncident(item) {
    var sites = item.sites;
    var reasons = sites.map(function (site) { return site.summary; });
    var same = reasons.every(function (reason) { return reason === reasons[0]; });
    var earliest = sites.map(function (site) { return site.since; }).filter(Boolean).sort()[0];

    return h('li', { class: 'incident is-down' }, [
      h('h3', {}, [icon('down'), h('span', { class: 'incident-site' }, 'Server ' + item.server), ' · ' + plural(sites.length, 'site is', 'sites are') + ' down']),
      h('p', { class: 'incident-meta' }, earliest ? 'Since ' + clock(earliest) + ' (' + duration(secondsSince(earliest)) + ')' : ''),
      h('p', { class: 'incident-summary' }, same ? reasons[0] : 'The sites fail in different ways: open one to see why.'),
      same && sites[0].advice ? h('p', { class: 'incident-advice' }, sites[0].advice) : null,
      h('ul', { class: 'incident-sites', 'aria-label': 'Sites on this server' }, sites.map(function (site) {
        return h('li', {}, h('button', { type: 'button', class: 'chip-button', 'data-action': 'open', 'data-site': site.id }, site.label));
      })),
      h('div', { class: 'actions' }, [
        snoozeButton(sites, '1h', 'Snooze all 1 h'),
        snoozeButton(sites, '4h', '4 h'),
        snoozeButton(sites, '24h', '24 h')
      ])
    ]);
  }

  function badges(site) {
    var list = [];
    if (site.snoozed_until) list.push(h('li', { class: 'badge' }, 'Snoozed until ' + clock(site.snoozed_until)));
    if (site.maintenance) list.push(h('li', { class: 'badge' }, 'In a maintenance window: not alerted'));
    if (site.unstable) list.push(h('li', { class: 'badge is-warn' }, 'Unstable: went down several times lately'));
    return list.length ? h('ul', { class: 'badges' }, list) : null;
  }

  function snoozeButton(sites, length, label) {
    return h('button', {
      type: 'button',
      class: 'button',
      'data-action': 'snooze',
      'data-sites': sites.map(function (site) { return site.id; }).join(','),
      'data-for': length,
      'aria-label': 'Snooze ' + (sites.length > 1 ? plural(sites.length, 'site', 'sites') : sites[0].label) + ' for ' + length.replace('h', ' hours').replace('1 hours', '1 hour')
    }, label);
  }

  function siteActions(site) {
    var actions = [];
    if (site.snoozed_until) {
      actions.push(h('button', { type: 'button', class: 'button', 'data-action': 'unsnooze', 'data-sites': site.id }, 'Unsnooze'));
    } else if (site.status === 'down' || site.status === 'failing') {
      actions.push(snoozeButton([site], '1h', 'Snooze 1 h'), snoozeButton([site], '4h', '4 h'), snoozeButton([site], '24h', '24 h'));
    }
    actions.push(h('button', { type: 'button', class: 'button', 'data-action': 'open', 'data-site': site.id }, 'History'));
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
          h('p', {}, plural(group.sites.length, 'site', 'sites') + ' · alerts ' + group.alerting)
        ]),
        h('ul', { class: 'site-list' }, sites.map(siteRow))
      ]));
    });

    if (!shown && state.data.totals.sites) {
      append(node, h('p', { class: 'no-match' }, 'No sites match "' + state.filter.trim() + '".'));
    }
  }

  function siteRow(site) {
    var ms = site.ms !== null && site.ms !== undefined ? site.ms + ' ms' : '–';
    var uptime = site.status !== 'paused' && site.uptime_24h !== null && site.uptime_24h !== undefined ? site.uptime_24h + '%' : '–';
    var subtitle = [site.name, site.cdn ? 'behind ' + site.cdn : null, site.snoozed_until ? 'snoozed' : null, site.maintenance ? 'maintenance' : null].filter(Boolean).join(' · ');

    return h('li', {}, h('button', {
      type: 'button',
      class: 'site-row',
      'data-action': 'open',
      'data-site': site.id,
      'aria-label': site.label + ': ' + STATUS_WORDS[site.status] + (site.status === 'up' ? ', ' + ms + ', ' + uptime + ' uptime today' : '. ' + site.summary)
    }, [
      statusLabel(site.status),
      h('span', { class: 'site-name' }, [h('strong', {}, site.label), subtitle ? h('small', {}, subtitle) : null]),
      spark(site.hours),
      h('span', { class: 'site-figures' }, [
        h('span', { class: 'site-figure' }, [ms, h('small', {}, 'now')]),
        h('span', { class: 'site-figure' }, [uptime, h('small', {}, '24 h up')])
      ])
    ]));
  }

  /** 24 bars, one per hour: response time, red where checks failed. */
  function spark(hours) {
    var max = Math.max.apply(null, [300].concat(hours.map(function (b) { return b.ms || 0; })));
    var bars = hours.map(function (bucket, i) {
      var height = bucket.ms ? Math.max(3, Math.round((bucket.ms / max) * 26)) : 3;
      var cls = bucket.up === null ? 'is-empty' : bucket.up === 0 ? 'is-down' : bucket.up < 1 ? 'is-partial' : '';
      if (bucket.up !== null && bucket.up < 1 && !bucket.ms) height = 26;
      return s('rect', { x: i * 5, y: 28 - height, width: 4, height: height, rx: 1, class: cls || null });
    });
    return s('svg', { class: 'spark', viewBox: '0 0 120 28', preserveAspectRatio: 'none', 'aria-hidden': 'true', focusable: 'false' }, bars);
  }

  // =================================================================
  // Site detail
  // =================================================================

  function openDetail(id) {
    state.detailId = id;
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
  }

  function percent(value) {
    return value === null || value === undefined ? 'Not enough data' : value + '%';
  }

  function renderDetail() {
    var d = state.detail;
    var site = d.site;
    var node = regions.detail;
    clear(node);

    var facts = [
      ['Uptime, 24 hours', percent(d.uptime['24h'])],
      ['Uptime, 7 days', percent(d.uptime['7d'])],
      ['Uptime, 30 days', percent(d.uptime['30d'])],
      ['Response time now', site.ms !== null ? site.ms + ' ms' : '–'],
      ['Checked every', site.interval_minutes + ' min'],
      ['Group', (site.group || 'No group')],
      ['Server', site.cdn ? 'Behind ' + site.cdn : (site.server || 'Not known yet')]
    ];
    if (site.keyword) facts.push(['Looks for the text', '"' + site.keyword + '"']);

    var since = site.since ? (site.status === 'up' ? 'Up since ' : 'Since ') + clock(site.since) + ' (' + duration(secondsSince(site.since)) + ')' : '';

    append(node, h('div', { class: 'detail-inner' }, [
      h('div', { class: 'detail-head' }, [
        h('div', {}, [
          h('h2', { id: 'detail-title' }, site.label),
          h('p', {}, [statusLabel(site.status), since ? ' ' + since : '']),
        ]),
        h('button', { type: 'button', class: 'close-button', 'data-action': 'close', 'aria-label': 'Close' }, icon('close'))
      ]),
      site.summary ? h('p', { class: 'incident-summary' }, site.summary) : null,
      site.advice ? h('p', { class: 'incident-advice' }, site.advice) : null,
      badges(site),
      h('div', { class: 'actions' }, siteActions(site).filter(function (n) { return n.getAttribute('data-action') !== 'open'; })),
      h('dl', { class: 'facts' }, facts.map(function (fact) {
        return h('div', { class: 'fact' }, [h('dt', {}, fact[0]), h('dd', {}, fact[1])]);
      })),
      d.maintenance.length ? h('p', { class: 'detail-hint' }, 'Maintenance: ' + d.maintenance.map(function (w) {
        return w.when + (w.label ? ' (' + w.label + ')' : '') + (w.active ? ', now' : '');
      }).join('; ') + '.') : null,
      h('h3', {}, 'Response time, last 24 hours'),
      chart(d.samples),
      h('h3', {}, 'History'),
      historyList(d.events),
      h('p', { class: 'detail-hint' }, ['Change this site with ', h('code', {}, 'php vaka site:edit ' + site.label)])
    ]));
  }

  function chart(samples) {
    if (!samples.length) return h('p', { class: 'chart-note' }, 'No checks in the last 24 hours.');
    var width = 700, height = 160, top = 10, bottom = 140, left = 44;
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
      s('line', { class: 'chart-grid', x1: left, x2: width, y1: bottom, y2: bottom }),
      s('text', { x: 0, y: top + 4 }, max + ' ms'),
      s('text', { x: 0, y: bottom }, '0 ms'),
      s('text', { x: left, y: height - 4 }, '24 h ago'),
      s('text', { x: (left + width) / 2 - 20, y: height - 4 }, '12 h ago'),
      s('text', { x: width - 24, y: height - 4 }, 'now')
    ];
    if (area) nodes.push(s('path', { class: 'chart-area', d: area }), s('path', { class: 'chart-line', d: path }));
    fails.forEach(function (sample) {
      nodes.push(s('rect', { class: 'chart-fail', x: x(sample.checked_at).toFixed(1), y: bottom - 6, width: 3, height: 6 }));
    });

    var slowest = good.reduce(function (m, sample) { return Math.max(m, sample.ms); }, 0);
    var average = good.length ? Math.round(good.reduce(function (t, sample) { return t + sample.ms; }, 0) / good.length) : 0;
    var label = good.length
      ? 'Average ' + average + ' ms, slowest ' + slowest + ' ms' + (fails.length ? ', ' + plural(fails.length, 'failed check', 'failed checks') + ' (red)' : '')
      : 'Every check in the last 24 hours failed.';

    return h('figure', {}, [
      s('svg', { class: 'chart', viewBox: '0 0 ' + width + ' ' + height, preserveAspectRatio: 'none', role: 'img', 'aria-label': 'Response time, last 24 hours. ' + label }, nodes),
      h('figcaption', { class: 'chart-note' }, label + '.')
    ]);
  }

  function historyList(events) {
    if (!events.length) return h('p', { class: 'chart-note' }, 'Nothing has happened yet.');
    return h('ul', { class: 'history' }, events.map(function (event) {
      var cls = event.status === 'fail' ? 'is-fail' : event.status === 'ok' ? 'is-ok' : '';
      return h('li', {}, [
        h('time', { datetime: event.created_at }, clock(event.created_at)),
        h('span', { class: cls }, [event.message, event.silenced_by ? h('span', { class: 'history-tag' }, event.silenced_by === 'maintenance' ? 'during maintenance' : 'while snoozed') : null])
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
      toast(body.action === 'unsnooze'
        ? (ids.length > 1 ? plural(ids.length, 'site', 'sites') : site.label) + ' is no longer snoozed'
        : 'Snoozed ' + (ids.length > 1 ? plural(ids.length, 'site', 'sites') : site.label) + ' until ' + clock(site.snoozed_until));
      return load().then(function () { if (state.detailId) return openDetail(state.detailId); });
    }).catch(function (error) {
      if (error.message !== 'Logged out') toast(error.message);
      if (button) button.disabled = false;
    });
  }

  var THEMES = ['system', 'light', 'dark'];
  var THEME_WORDS = { system: 'System', light: 'Light', dark: 'Dark' };

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
    button.setAttribute('aria-label', 'Colour theme: ' + THEME_WORDS[theme].toLowerCase() + '. Change it');
  }

  document.addEventListener('click', function (event) {
    var target = event.target.closest('[data-action]');
    if (!target) return;
    var action = target.getAttribute('data-action');

    if (action === 'open') openDetail(target.getAttribute('data-site'));
    if (action === 'close') closeDetail();
    if (action === 'snooze') snooze(target.getAttribute('data-sites').split(','), { 'for': target.getAttribute('data-for') }, target);
    if (action === 'unsnooze') snooze(target.getAttribute('data-sites').split(','), { action: 'unsnooze' }, target);
    if (action === 'theme') {
      var next = THEMES[(THEMES.indexOf(currentTheme()) + 1) % THEMES.length];
      try { localStorage.setItem('vaka-theme', next); } catch (e) { /* storage blocked: the choice lasts for this page */ }
      applyTheme(next);
    }
  });

  // A click on the backdrop closes the detail, as Esc does
  regions.detail.addEventListener('click', function (event) {
    if (event.target === regions.detail) closeDetail();
  });
  regions.detail.addEventListener('close', function () {
    if (state.detailId) closeDetail();
  });

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
