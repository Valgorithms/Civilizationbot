// Offline real-browser contracts: no server, npm packages or external services.
import {spawnSync} from 'node:child_process';
import {mkdtempSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
const browser = process.env.CHROMIUM_BIN || 'chromium';
const profile = mkdtempSync(join(tmpdir(), 'civ13-admin-ui-'));
try {
  const result = spawnSync(browser, ['--headless', '--disable-gpu', '--disable-background-networking',
    '--no-first-run', '--disable-default-apps', '--disable-sync', '--metrics-recording-only',
    `--user-data-dir=${profile}`, '--dump-dom', '--virtual-time-budget=2000',
    new URL('./ui.html', import.meta.url).href], {encoding: 'utf8', timeout: 20000, maxBuffer: 2 * 1024 * 1024});
  if (result.error || result.status !== 0 || !result.stdout.includes('data-result="pass"')) {
    console.error(result.error?.message || result.stdout || result.stderr); process.exitCode = 1;
  } else console.log(result.stdout.match(/PASS: \d+ checks/)[0]);
} finally { rmSync(profile, {recursive: true, force: true}); }
