/* Synthetic examples only. This file has no credentials or live destinations. */
(function (global) {
  'use strict';
  const entries = [
    ['gaia-fixture', 'Gaia · quiet world', 'online', 0, 'Gaia', 'Nomads', 'idle', 12],
    ['europe-fixture', 'Europe · saving', 'busy', 8, 'Europe', 'Nomads', 'saving', 7],
    ['recovery-fixture', 'Gaia · recovery', 'recovery_hold', 0, 'Gaia', 'Nomads', 'loading', 5],
    ['stale-fixture', 'Europe · old heartbeat', 'stale', null, null, null, null, 180],
    ['offline-fixture', 'TDM · unreachable', 'offline', null, null, null, null, 9],
    ['unknown-fixture', 'New instance', 'unknown', null, null, null, null, null]
  ];
  const rows = entries.map(([id, name, state, players, map, mode, phase, age]) => ({
    name, observed_at: age === null ? null : new Date(Date.UTC(2026, 9, 28, 3, 0, 0) - age * 1000).toISOString(),
    projection: {schema: 'civ13.admin-status.v1', access: 'allowed', server_id: id, state,
      age_seconds: age, ready: state === 'online' ? null : false,
      metrics: {players, map, mode, round_id: map ? 'fixture-round-01' : null,
        round_duration_seconds: map ? 7392 : null, generation: map ? 'round-v2:demo' : null, save_load_phase: phase},
      capabilities: {view_status: true, save: false, restart: false, verbs: false, control_reason: 'read_only'}}
  }));
  global.Civ13FixtureAdapter = Object.freeze({read: async () => structuredClone(rows)});
})(globalThis);
