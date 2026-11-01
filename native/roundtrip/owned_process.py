"""Bounded owned-child adapter, reusing the exact merged cleanup implementation.

No process is started on import. Linux runtime limits are child-only. All native
execution remains a separately reviewed action; offline tests always mock Popen.
"""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import stat
import subprocess
import time

ROOT = Path(__file__).resolve().parent
SHARED_SHA = 'd223a6f11987d9cfec22c941df8b8f099aae2f8d0facd3155f63c82dd3bbe6e3'
shared_path = ROOT / 'merged/compile_bridge_only.py'
if not shared_path.is_file():
    shared_path = ROOT.parent / 'compile_bridge_only.py'  # proposed public native/roundtrip layout
if hashlib.sha256(shared_path.read_bytes()).hexdigest() != SHARED_SHA:
    raise ValueError('Reviewed supervisor identity mismatch')
spec = importlib.util.spec_from_file_location('reviewed_compile_supervisor', shared_path)
shared = importlib.util.module_from_spec(spec)
spec.loader.exec_module(shared)
ACTIVE = None
SERIAL = 0
PENDING = shared.PENDING


def limits_for_child(cpu_seconds):
    """Called in the Linux child before exec; does not change controller limits."""
    if os.name != 'posix':
        raise ValueError('Linux child limits required')
    import resource
    for kind, limit in [(resource.RLIMIT_AS, 1073741824), (resource.RLIMIT_CPU, cpu_seconds),
                        (resource.RLIMIT_FSIZE, 8388608), (resource.RLIMIT_NOFILE, 64), (resource.RLIMIT_CORE, 0)]:
        resource.setrlimit(kind, (limit, limit))


def inventory(root, byte_cap, entry_cap=4096):
    """Bounded enumeration, no symlink following; errors propagate to cleanup."""
    root = Path(root)
    if not stat.S_ISDIR(root.lstat().st_mode):
        raise ValueError('Inventory root must be an owned directory')
    pending = [(root, 0)]
    total = entries = 0
    while pending:
        parent, depth = pending.pop()
        if depth > 16:
            raise ValueError('Inventory depth limit')
        with os.scandir(parent) as stream:
            for entry in stream:
                entries += 1
                if entries > entry_cap:
                    raise ValueError('Inventory entry limit')
                info = entry.stat(follow_symlinks=False)
                if stat.S_ISDIR(info.st_mode):
                    pending.append((Path(entry.path), depth + 1))
                elif stat.S_ISREG(info.st_mode):
                    total += info.st_size
                    if total > byte_cap or info.st_size > 8388608:
                        raise ValueError('Inventory byte limit')
                    if entry.name.endswith('.json') and info.st_size > 1048576:
                        raise ValueError('JSON byte limit')
                else:
                    raise ValueError('Symlink or nonregular inventory entry')
    return total


def check_outputs(stage, evidence_root, log_cap):
    for name, cap in [('stdout.log', log_cap), ('stderr.log', log_cap), ('native.log', 4096)]:
        path = stage / name
        try:
            info = path.lstat()
        except FileNotFoundError:
            if name == 'native.log':
                continue
            raise
        if not stat.S_ISREG(info.st_mode) or info.st_size > cap:
            raise ValueError('Log output limit')
    inventory(stage, 33554432)
    inventory(evidence_root, 67108864)


def snapshot(path, cap):
    if not stat.S_ISREG(path.lstat().st_mode):
        raise ValueError('Nonregular closed log')
    with path.open('rb') as source:
        raw = source.read(cap + 1)
    data = raw[:cap]
    return {'kind': 'bounded_prefix' if len(raw) > cap else 'complete_snapshot',
            'sha256_snapshot': hashlib.sha256(data).hexdigest(), 'captured_bytes': len(data)}, data


def bounded(argv, stage, seconds, log_cap, evidence_root, env, cpu_seconds):
    """One owned Popen handle; original deadline governs cleanup and observation.

    Popen constructor failure acquires no handle here; no PID search is attempted.
    If a handle was returned, even failure during registration/first poll enters
    the same reviewed TERM/force/reap helper. Unconfirmed ownership blocks reuse.
    """
    global ACTIVE, SERIAL
    if ACTIVE is not None or PENDING:
        raise RuntimeError('Owned child exit remains unconfirmed')
    if seconds not in [10, 60] or log_cap not in [4096, 1048576] or cpu_seconds not in [8, 55]:
        raise ValueError('Unreviewed process budget')
    if not isinstance(argv, list) or not argv or any(not isinstance(x, str) for x in argv):
        raise ValueError('Explicit argv required')
    stage, evidence_root = Path(stage), Path(evidence_root)
    SERIAL += 1
    owner_token = f'owned-child-{SERIAL}'
    report_dir = evidence_root / 'safe-reports' / owner_token
    report_dir.mkdir(mode=0o700, parents=True)
    started = time.monotonic()
    deadline = started + seconds
    child = None
    pid = None
    code = None
    primary = cleanup_error = None
    fault = None
    record = {'owner_token': owner_token, 'safe_report_directory': str(report_dir), 'argv': list(argv), 'pid': None, 'seconds_limit': seconds,
              'exit_confirmed': False, 'exit_code': None, 'fault': None, 'logs': {}, 'cleanup_error': None}
    captured = {}
    try:
        # File-open and Popen failures occur inside the error-recording scope.
        # A returned child is put in the existing ACTIVE slot before any poll,
        # PID access or list allocation can fail.
        with (stage / 'stdout.log').open('xb') as out, (stage / 'stderr.log').open('xb') as err:
            try:
                ACTIVE = child = subprocess.Popen(argv, cwd=stage, stdin=subprocess.DEVNULL, stdout=out, stderr=err,
                                                  env=env, preexec_fn=lambda: limits_for_child(cpu_seconds))
                PENDING.append(child)
                pid = child.pid
                while child.poll() is None:
                    check_outputs(stage, evidence_root, log_cap)
                    if time.monotonic() >= deadline - .75:
                        fault = 'timeout'
                        break
                    time.sleep(.005)
            except BaseException as error:
                primary = error
                fault = 'launch_error' if child is None else 'supervision_error'
            finally:
                if child is not None:
                    try:
                        code, cleanup_error = shared.finish_owned_compiler(child, deadline)
                    except BaseException as error:
                        cleanup_error = error
                    if cleanup_error is not None and primary is None:
                        primary = cleanup_error
                        fault = fault or 'cleanup_error'
                    if code is not None:
                        if child in PENDING:
                            PENDING.remove(child)
                        ACTIVE = None
    except BaseException as error:
        if primary is None:
            primary = error
            fault = 'prelaunch_io_error' if child is None else 'stream_close_error'
    record.update(pid=pid, exit_confirmed=code is not None, exit_code=code, fault=fault,
                  original_error=type(primary).__name__ if primary is not None else None,
                  cleanup_error=type(cleanup_error).__name__ if cleanup_error is not None else None,
                  ownership='not_acquired' if child is None else ('reaped' if code is not None else 'retained'))
    if child is not None and code is None:
        record['fault'] = 'owned_child_exit_unconfirmed'
        # No stat/scan/open/read/hash of any possible live child output.
        for name in ['stdout.log', 'stderr.log', 'native.log']:
            record['logs'][name] = {'kind': 'unavailable_unconfirmed_exit', 'sha256_snapshot': None}
    elif child is not None:
        try:
            # Final complete-output checks close the last-poll race.
            check_outputs(stage, evidence_root, log_cap)
        except BaseException as error:
            record['fault'] = record['fault'] or 'closed_output_error'
            if primary is None:
                primary = error
        for name, cap in [('stdout.log', log_cap), ('stderr.log', log_cap), ('native.log', 4096)]:
            try:
                record['logs'][name], captured[name] = snapshot(stage / name, cap)
                # Separate owned report directory: a native safe-home child
                # never writes here. Unconfirmed exits never reach this branch.
                with (report_dir / (name + '.snapshot')).open('xb') as output:
                    output.write(captured[name])
                if record['logs'][name]['kind'] != 'complete_snapshot':
                    record['fault'] = record['fault'] or 'output_limit'
            except BaseException as error:
                record['logs'][name] = {'kind': 'unavailable_snapshot_failed', 'sha256_snapshot': None}
                # native.log is not required for compiler processes.
                if name != 'native.log' or seconds == 10:
                    record['fault'] = record['fault'] or 'log_snapshot_error'
                    if primary is None:
                        primary = error
    record['original_error'] = type(primary).__name__ if primary is not None else None
    record['status'] = 'process_passed' if child is not None and code == 0 and record['fault'] is None else 'process_failed'
    try:
        serialized = json.dumps(record, indent=2) + '\n'
        (stage / 'process.json').write_text(serialized)
        (report_dir / 'process.json').write_text(serialized)
    except BaseException as error:
        if primary is None:
            primary = error
    if primary is not None:
        raise primary
    if record['status'] != 'process_passed':
        raise RuntimeError(record['fault'] or 'owned_child_failed')
    return {'record': record, 'captured_logs': captured}
