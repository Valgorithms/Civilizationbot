// Existing vendor Chrome only; real DOM + layout over local file URLs, no listener.
// Linux runner process-group ownership permits complete bounded cleanup.
import {spawn} from 'node:child_process';
import {accessSync, constants, mkdirSync, mkdtempSync, rmSync, writeFileSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join, resolve} from 'node:path';
import {checkLayout} from './layout-checks.mjs';

const browser = process.env.CHROMIUM_BIN || process.env.CHROME_BIN || '/usr/bin/google-chrome';
const artifacts = resolve('artifacts/admin-console-browser');
mkdirSync(artifacts, {recursive: true});
const evidence = {browser, sandbox: 'enabled (no bypass flags)', transport: 'DevTools pipe', results: []};
let child, profile, timeout, stderr = '', protocolError = null, buffer = '', nextId = 0, timedOut = false;
const loaded = new Set(), loadWaiters = new Map();
const pending = new Map();
function rejectAll(error) {
  protocolError ??= error;
  for (const request of pending.values()) request.reject(error);
  pending.clear();
  for (const waiter of loadWaiters.values()) waiter.reject(error);
  loadWaiters.clear();
}
function stopOwnedBrowser() {
  if (!child?.pid) return;
  try { process.kill(-child.pid, 'SIGKILL'); } catch (error) { if (error.code !== 'ESRCH') throw error; }
}
function send(method, params = {}, sessionId) {
  if (protocolError) return Promise.reject(protocolError);
  return new Promise((resolve, reject) => {
    const id = ++nextId; pending.set(id, {resolve, reject});
    child.stdio[3].write(JSON.stringify({id, method, params, ...(sessionId ? {sessionId} : {})})+'\0');
  });
}
async function navigate(session, url) {
  const response = await send('Page.navigate', {url}, session);
  if (response.errorText) throw new Error(response.errorText);
  if (!response.loaderId) throw new Error('Navigation has no loader identity');
  const key = `${session}:${response.loaderId}`;
  if (protocolError) throw protocolError;
  if (!loaded.has(key)) await new Promise((resolve, reject) => loadWaiters.set(key, {resolve, reject}));
}
async function evaluate(session, expression) {
  const response = await send('Runtime.evaluate', {expression, awaitPromise: true, returnByValue: true}, session);
  if (response.exceptionDetails) throw new Error(response.exceptionDetails.exception?.description || response.exceptionDetails.text);
  return response.result.value;
}
try {
  if (process.platform !== 'linux') throw new Error('This bounded browser runner requires Linux process-group ownership');
  accessSync(browser, constants.X_OK); // Missing browser fails; never downloads or substitutes.
  profile = mkdtempSync(join(tmpdir(), 'civ13-admin-browser-'));
  child = spawn(browser, ['--headless=new', '--remote-debugging-pipe', '--disable-background-networking',
    '--no-first-run', '--disable-default-apps', '--disable-sync', '--metrics-recording-only',
    `--user-data-dir=${profile}`, 'about:blank'], {detached: true, stdio: ['ignore', 'ignore', 'pipe', 'pipe', 'pipe']});
  const exited = new Promise(resolve => {
    child.once('exit', (code, signal) => resolve({code, signal}));
    child.once('error', error => resolve({error: error.message}));
  });
  child.on('error', error => rejectAll(error));
  child.on('exit', (code, signal) => rejectAll(new Error(`Owned browser exited: ${code ?? signal}`)));
  child.stderr.on('data', data => { stderr = (stderr + data.toString()).slice(-256000); });
  child.stdio[3].on('error', error => rejectAll(error));
  child.stdio[4].on('data', data => {
    buffer += data.toString();
    if (buffer.length > 8 * 1024 * 1024) { rejectAll(new Error('Browser protocol output bound exceeded')); stopOwnedBrowser(); return; }
    let boundary;
    while ((boundary = buffer.indexOf('\0')) !== -1) {
      const chunk = buffer.slice(0, boundary); buffer = buffer.slice(boundary + 1);
      if (!chunk) continue;
      try {
        const message = JSON.parse(chunk);
        if (message.method === 'Page.lifecycleEvent' && message.params.name === 'load') {
          const key = `${message.sessionId}:${message.params.loaderId}`;
          loaded.add(key); loadWaiters.get(key)?.resolve(); loadWaiters.delete(key);
        }
        const request = pending.get(message.id);
        if (!request) continue;
        pending.delete(message.id);
        message.error ? request.reject(new Error(message.error.message)) : request.resolve(message.result);
      } catch (error) { rejectAll(error); }
    }
  });
  timeout = setTimeout(() => { timedOut = true; rejectAll(new Error('Owned browser exceeded the 20-second deadline')); stopOwnedBrowser(); }, 20000);
  evidence.version = await send('Browser.getVersion');
  const {targetId} = await send('Target.createTarget', {url: 'about:blank'});
  const {sessionId} = await send('Target.attachToTarget', {targetId, flatten: true});
  await send('Page.enable', {}, sessionId);
  await send('Page.setLifecycleEventsEnabled', {enabled: true}, sessionId);
  await send('Network.enable', {}, sessionId);
  await send('Network.setBlockedURLs', {urls: ['http://*', 'https://*', 'ws://*', 'wss://*']}, sessionId);
  // Preserve the reviewed renderer/interaction assertions, now on a real browser DOM.
  await navigate(sessionId, new URL('./ui.html', import.meta.url).href);
  const contract = await evaluate(sessionId, `(async()=>{for(let i=0;i<75;i++){if(document.body?.dataset.result)return {status:document.body.dataset.result,text:document.getElementById('results').textContent};await new Promise(r=>setTimeout(r,20));}throw new Error('Real DOM contracts did not finish');})()`);
  if (contract.status !== 'pass') throw new Error(contract.text);
  evidence.results.push({suite: 'existing-ui-dom', ...contract});
  // Navigate the actual reviewed index, with its own CSP/assets unchanged.
  for (const [width, height] of [[320, 844], [390, 844], [1280, 900]]) {
    await send('Emulation.setDeviceMetricsOverride', {width, height, deviceScaleFactor: 1, mobile: false}, sessionId);
    await navigate(sessionId, new URL('../../web/admin-console/index.html', import.meta.url).href);
    const result = await evaluate(sessionId, `(${checkLayout.toString()})(${width})`);
    evidence.results.push({suite: 'actual-index-layout', ...result});
    if (width !== 320) {
      const screenshot = await send('Page.captureScreenshot', {format: 'png', captureBeyondViewport: false}, sessionId);
      writeFileSync(join(artifacts, `fixture-${width}.png`), Buffer.from(screenshot.data, 'base64'));
    }
  }
  await send('Browser.close').catch(() => {});
  const exit = await exited;
  if (timedOut) throw new Error('Owned browser exceeded the 20-second deadline during cleanup');
  if (exit.code !== 0) throw new Error(`Browser did not close cleanly: ${exit.error ?? exit.signal ?? exit.code}`);
  evidence.status = 'passed';
  console.log(JSON.stringify(evidence, null, 2));
} catch (error) {
  evidence.status = 'failed'; evidence.error = error.message;
  console.error(error.message); process.exitCode = 1;
} finally {
  clearTimeout(timeout);
  stopOwnedBrowser();
  if (profile) rmSync(profile, {recursive: true, force: true});
  writeFileSync(join(artifacts, 'results.json'), JSON.stringify(evidence, null, 2)+'\n');
  writeFileSync(join(artifacts, 'browser-stderr.txt'), stderr);
}
