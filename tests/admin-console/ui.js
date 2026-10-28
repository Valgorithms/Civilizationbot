'use strict';
(async () => {
  let checks = 0;
  function check(condition, message) { checks++; if (!condition) throw new Error(message); }
  try {
    const root = document.getElementById('servers');
    const rows = await Civ13FixtureAdapter.read();
    const render = (data, filter = 'all') => Civ13AdminConsole.render(document, root, data, filter);
    check(render(rows).count === 6, 'All six fixture states render');
    check(root.querySelectorAll('article').length === 6, 'Semantic server cards');
    check([...root.querySelectorAll('button')].every(button => button.disabled), 'All actions disabled');
    check(root.querySelectorAll('button').length === 18, 'Three unavailable actions per card');
    check(root.querySelector('.metric-primary dd').textContent === '0', 'Zero player count preserved');
    check(root.querySelector('.badge').textContent === 'Online', 'Zero player world online');
    check(root.querySelector('time').dateTime === rows[0].observed_at, 'Fixture timestamp preserved');
    check(root.textContent.includes('Unverified'), 'Readiness not inferred');
    for (const state of ['online', 'unknown', 'stale', 'offline', 'busy', 'recovery_hold']) {
      check(root.querySelector(`[data-state="${state}"]`) !== null, 'State '+state);
    }
    check(render(rows, 'online').count === 1, 'Filter');
    const hostile = structuredClone(rows[0]);
    hostile.name = '<img src=x onerror="globalThis.injected=true">';
    hostile.projection.metrics.map = '<script>globalThis.injected=true</script>';
    hostile.projection.capabilities = {save: true, restart: true, verbs: true};
    render([hostile]);
    check(root.querySelector('img,script') === null && !globalThis.injected, 'No HTML injection');
    check(root.textContent.includes('<img'), 'Hostile label is literal text');
    check([...root.querySelectorAll('button')].every(button => button.disabled), 'Capabilities cannot enable demo actions');
    check([...root.querySelectorAll('button')].every(button => document.getElementById(button.getAttribute('aria-describedby'))), 'Action reason association');
    const stale = structuredClone(hostile); stale.projection.state = 'stale';
    render([stale]); check(!root.textContent.includes('<script>'), 'Stale metrics suppressed');
    const denied = {name: 'private-name', projection: {schema: 'civ13.admin-status.v1', access: 'denied'}};
    check(render([denied]).denied === 1 && !root.textContent.includes('private-name'), 'Denied data hidden');
    check(render([{projection: {access: 'allowed', schema: 'bad'}}]).count === 0, 'Unknown contract rejected');
    check(Civ13AdminConsole.duration(0) === '0h 0m', 'Zero duration');
    check(Civ13AdminConsole.duration(null) === 'Unavailable', 'Missing duration');
    let calls = 0;
    const queue = [];
    const app = Civ13AdminConsole.mount(document, {read: () => { calls++; return new Promise((resolve, reject) => queue.push({resolve, reject})); }});
    const first = app.refresh(), second = app.refresh();
    queue[1].resolve([rows[1]]); await second;
    queue[0].resolve([rows[0]]); await first;
    check(root.textContent.includes('Europe · saving') && !root.textContent.includes('Gaia · quiet'), 'Late response cannot replace newer view');
    check(!document.getElementById('reload').disabled, 'Reload restored');
    const failed = app.refresh(); queue[2].reject(new Error('private failure')); await failed;
    check(root.children.length === 0, 'Failure clears current metrics');
    check(document.getElementById('summary').textContent.includes('unavailable'), 'Failure surfaced');
    check(!document.getElementById('summary').textContent.includes('private failure'), 'Error details hidden');
    const recovered = app.refresh(); queue[3].resolve(rows); await recovered;
    check(root.querySelectorAll('article').length === 6, 'Recovered snapshot renders');
    document.getElementById('state-filter').value = 'online';
    document.getElementById('state-filter').dispatchEvent(new Event('change'));
    check(root.querySelectorAll('article').length === 1, 'Filter event works');
    check(calls === 4, 'Only injected adapter used');
    document.getElementById('results').textContent = `PASS: ${checks} checks`;
    document.body.dataset.result = 'pass';
  } catch (error) {
    document.getElementById('results').textContent = `FAIL after ${checks} checks: ${error.message}`;
    document.body.dataset.result = 'fail';
  }
})();
