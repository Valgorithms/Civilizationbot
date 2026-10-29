/* Pure DOM renderer and injected read-only adapter. No network or action transport. */
(function (global) {
  'use strict';
  const states = {
    online: ['Online', 'Responding. Readiness is reported separately.'],
    unknown: ['Unknown', 'No current observation. This does not mean stopped.'],
    stale: ['Stale', 'Observation expired. Current metrics are unavailable.'],
    offline: ['Unreachable', 'The source could not reach this server. Process state is unknown.'],
    busy: ['Busy', 'An operation is in progress. Not ready for another action.'],
    recovery_hold: ['Recovery hold', 'Recovery needs attention. Do not start a fresh world.']
  };
  function element(document, tag, text, className) {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = String(text);
    if (className) node.className = className;
    return node;
  }
  function value(input) { return input === null || input === undefined || input === '' ? 'Unavailable' : String(input); }
  function duration(seconds) {
    if (!Number.isInteger(seconds) || seconds < 0) return 'Unavailable';
    return `${Math.floor(seconds / 3600)}h ${Math.floor(seconds % 3600 / 60)}m`;
  }
  function card(document, row, index) {
    const view = row.projection;
    const state = Object.hasOwn(states, view.state) ? view.state : 'unknown';
    const current = ['online', 'busy', 'recovery_hold'].includes(state);
    const metrics = current ? (view.metrics || {}) : {};
    const node = element(document, 'article', undefined, 'card');
    const headingId = `server-heading-${index}`;
    node.setAttribute('aria-labelledby', headingId);
    const top = element(document, 'div', undefined, 'card-top');
    const identity = element(document, 'div');
    const heading = element(document, 'h2', value(row.name)); heading.id = headingId;
    identity.append(heading, element(document, 'p', value(view.server_id), 'server-id'));
    const badge = element(document, 'span', states[state][0], 'badge'); badge.dataset.state = state;
    top.append(identity, badge); node.append(top);
    node.append(element(document, 'p', states[state][1], 'status-detail'));
    const list = element(document, 'dl', undefined, 'metrics');
    const ready = !current ? 'Unverified' : ['busy', 'recovery_hold'].includes(state) ? 'Not ready' : view.ready === true ? 'Ready (reported)' : view.ready === false ? 'Not ready' : 'Unverified';
    for (const [label, text, primary] of [
      ['Players', value(metrics.players), true], ['Readiness', ready, false],
      ['Map', value(metrics.map)], ['Mode', value(metrics.mode)],
      ['Round elapsed', duration(metrics.round_duration_seconds)], ['Save / load', value(metrics.save_load_phase)],
      ['Round ID', value(metrics.round_id)], ['Generation', value(metrics.generation)]
    ]) {
      const group = element(document, 'div', undefined, primary ? 'metric-primary' : undefined);
      group.append(element(document, 'dt', label), element(document, 'dd', text)); list.append(group);
    }
    node.append(list);
    const observed = element(document, 'p', undefined, 'observed');
    const stamp = typeof row.observed_at === 'string' ? new Date(row.observed_at) : null;
    if (stamp && Number.isFinite(stamp.getTime())) {
      observed.append(document.createTextNode('Fixture observed '));
      const time = element(document, 'time', stamp.toISOString().replace('T', ' ').replace('.000Z', ' UTC'));
      time.dateTime = stamp.toISOString(); observed.append(time);
    } else observed.textContent = 'Observation timestamp unavailable';
    if (typeof view.age_seconds === 'number' && Number.isFinite(view.age_seconds) && view.age_seconds >= 0) {
      observed.append(document.createTextNode(` · ${Math.floor(view.age_seconds)}s old at capture`));
    }
    node.append(observed);
    const actions = element(document, 'div', undefined, 'actions');
    const reasonId = `action-reason-${index}`;
    for (const label of ['Save', 'Restart', 'Run verb']) {
      const button = element(document, 'button', label); button.type = 'button'; button.disabled = true;
      button.setAttribute('aria-describedby', reasonId); actions.append(button);
    }
    const reason = element(document, 'p', 'Controls unavailable: read-only fixture demo. No commands are connected.', 'disabled-reason'); reason.id = reasonId;
    node.append(actions, reason);
    return node;
  }
  function render(document, container, rows, filter) {
    const fragment = document.createDocumentFragment();
    let count = 0, denied = 0;
    for (const row of rows) {
      if (!row || row.projection?.schema !== 'civ13.admin-status.v1' || row.projection.access !== 'allowed') { denied++; continue; }
      const state = Object.hasOwn(states, row.projection.state) ? row.projection.state : 'unknown';
      if (filter !== 'all' && state !== filter) continue;
      fragment.append(card(document, row, count++));
    }
    if (!count) fragment.append(element(document, 'p', 'No visible servers in this view.', 'empty'));
    container.replaceChildren(fragment);
    return {count, denied};
  }
  function mount(document, adapter) {
    const container = document.getElementById('servers');
    const summary = document.getElementById('summary');
    const filter = document.getElementById('state-filter');
    const reload = document.getElementById('reload');
    let rows = [], generation = 0;
    function draw() {
      const result = render(document, container, rows, filter.value);
      summary.classList.remove('error');
      summary.textContent = `${result.count} example servers · Snapshot only, not live${result.denied ? ' · Some observations are unavailable or denied' : ''}`;
    }
    async function refresh() {
      const request = ++generation;
      reload.disabled = true; summary.textContent = 'Loading fixture observations…';
      try {
        const result = await adapter.read();
        if (request !== generation) return;
        if (!Array.isArray(result)) throw new Error('Invalid fixture snapshot');
        rows = result; draw();
      } catch (_) {
        if (request !== generation) return;
        rows = []; container.replaceChildren(); summary.classList.add('error');
        summary.textContent = 'Observations unavailable. No cached values are presented as current. No action was sent.';
      } finally { if (request === generation) reload.disabled = false; }
    }
    filter.addEventListener('change', draw);
    reload.addEventListener('click', refresh);
    return {refresh};
  }
  global.Civ13AdminConsole = Object.freeze({render, mount, duration});
})(globalThis);
