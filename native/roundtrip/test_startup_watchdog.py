"""Deterministic fake procfs/DM/owned-child controls; never queries real PIDs."""
import copy
import hashlib
import json
import math
from pathlib import Path
import stat
import re
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch
sys.path.insert(0, str(Path(__file__).resolve().parent))
import startup_watchdog as startup
import owned_process as own


class Clock:
    value = 0.0
    def monotonic(self): return self.value
    def sleep(self, seconds): self.value += seconds


def runtime_logs(port=25444):
    return {name: {'text': (f'World opened on network port {port} for address 127.0.0.1.\n'
                          'Welcome BYOND! (5.0 Public Version 516.1688)\n'
                          'World no longer opened to new users.\n') if name == 'native.log' else '', 'truncated': False}
            for name in ['stdout', 'stderr', 'native.log']}


def verified_evidence(image='fixture_generator'):
    # Explicit fake data used only by injected offline adapters.
    return {'schema': startup.SCHEMA, 'status': 'verified', 'image': image, 'image_sha256': 'a' * 64,
            'executable_sha256': startup.DAEMON_SHA256, 'address': '127.0.0.1', 'port': 25444, 'protocol': 'TCP',
            'os_egress_containment': False, 'network_intent': 'temporary loopback listener; no intended outbound activity',
            'startup_close_limit_ms': 1000, 'child_limit_ms': 10000, 'phase': 'released',
            'owner_token': 'owned-child-3', 'pid': 817263, 'starttime_ticks': 900,
            'listener_inode': 1234, 'ready_ms': 10, 'closed_ms': 20, 'release_ms': 21, 'exit_ms': 40,
            'same_inode_absent_before_release': True, 'exit_confirmed': True, 'exit_code': 0}


def publish(stage, phase, *, nonce='a' * 32, image='fixture_generator', **changes):
    receipt = {'schema': startup.SCHEMA, 'nonce': nonce, 'image': image, 'phase': phase, 'runtime': '516.1688',
               'world_port': 25444 if phase == 'ready' else 0}
    receipt.update(changes)
    (stage / ('startup-' + phase + '.json')).write_text(json.dumps(receipt), encoding='utf-8')
    (stage / ('startup-' + phase + '.done')).write_bytes(nonce.encode('ascii'))


class FakeProc:
    def __init__(self):
        self.starttime = 900
        self.calls = []
        self.closed = False
        self.inodes = {1234}
        self.rows = [{'table': 'tcp', 'inode': 1234, 'local': '0100007F:6364', 'remote': '00000000:0000', 'state': '0A'}]
    def identity(self, pid):
        self.calls.append(('identity', pid))
        return self.starttime
    def census(self, pid):
        self.calls.append(('census', pid))
        return (set(), []) if self.closed else (self.inodes, self.rows)


class WatchdogTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='startup-zero-process-')
        self.addCleanup(self.tmp.cleanup)
        self.stage = Path(self.tmp.name)
        self.proc, self.clock = FakeProc(), Clock()
        guard = patch.object(startup.time, 'monotonic', side_effect=self.clock.monotonic)
        guard.start(); self.addCleanup(guard.stop)
        for name in ['subprocess.Popen', 'socket.socket']:
            guard = patch(name, side_effect=AssertionError('No native process or connection'))
            guard.start(); self.addCleanup(guard.stop)
        self.observer = startup.StartupObserver(self.stage, '/unqueried/mock/DreamDaemon', 'fixture_generator', 'a' * 64, proc=self.proc, nonce='a' * 32)
        self.observer.attach(SimpleNamespace(pid=817263), 817263, 0, 10, 'owned-child-3')

    def ready(self):
        publish(self.stage, 'ready')
        self.clock.value = .010
        self.observer.tick()

    def close(self):
        self.proc.closed = True
        publish(self.stage, 'closed')
        self.clock.value = .020
        self.observer.tick()

    def test_complete_handshake_verifies_parent_observation_without_egress_claim(self):
        self.ready(); self.close()
        calls = list(self.proc.calls)
        self.observer.tick()  # No proc read after release; child may exit now.
        self.assertEqual(self.proc.calls, calls)
        publish(self.stage, 'started')
        self.clock.value = .040
        self.observer.finish(0)
        result = self.observer.evidence()
        self.assertTrue(startup.validate_evidence(result, 'fixture_generator'))
        self.assertFalse(result['os_egress_containment'])
        self.assertEqual(set(pid for _, pid in self.proc.calls), {817263})
        self.assertEqual(json.loads((self.stage / 'startup-close.ack').read_text())['nonce'], 'a' * 32)

    def test_wrong_address_port_family_or_connection_cannot_release(self):
        for key, value in [('local', '00000000:6364'), ('local', '0100007F:C7E9'),
                           ('table', 'tcp6'), ('table', 'udp'), ('remote', '0100007F:1234'), ('state', '01')]:
            with self.subTest(key=key, value=value):
                self.proc.rows = [dict(FakeProc().rows[0], **{key: value})]
                publish(self.stage, 'ready')
                with self.assertRaisesRegex(ValueError, 'endpoint_or_connection'): self.observer.tick()
                self.assertFalse((self.stage / 'startup-close.ack').exists())

    def test_extra_missing_or_unmapped_owned_socket_cannot_release(self):
        publish(self.stage, 'ready')
        for inodes, rows in [({1234, 1235}, self.proc.rows), (set(), []), ({1234}, []), ({1234}, self.proc.rows * 2)]:
            with self.subTest(inodes=inodes, rows=rows):
                self.proc.inodes, self.proc.rows = inodes, rows
                with self.assertRaises(ValueError): self.observer.tick()
                self.assertFalse((self.stage / 'startup-close.ack').exists())

    def test_unrelated_namespace_rows_do_not_become_owned_listeners(self):
        self.proc.rows.append({'table': 'tcp', 'inode': 9876, 'local': '00000000:0050', 'remote': '00000000:0000', 'state': '0A'})
        self.ready()
        self.assertEqual(self.observer.inode, 1234)

    def test_changed_starttime_stops_without_pid_search(self):
        self.proc.starttime = 901
        with self.assertRaisesRegex(ValueError, 'identity_changed'): self.observer.tick()
        self.assertEqual(self.proc.calls, [('identity', 817263), ('identity', 817263)])

    def test_deadline_includes_both_startup_phases(self):
        self.ready()
        self.clock.value = 1.0
        with self.assertRaisesRegex(ValueError, 'close_timeout'): self.observer.tick()
        self.assertFalse((self.stage / 'startup-release.ack').exists())

    def test_retained_listener_inode_or_new_socket_prevents_fixture_release(self):
        self.ready(); publish(self.stage, 'closed')
        for inodes, rows in [(set(), self.proc.rows), ({1235}, []), ({1234}, self.proc.rows)]:
            with self.subTest(inodes=inodes):
                self.proc.inodes, self.proc.rows = inodes, rows
                with self.assertRaisesRegex(ValueError, 'closure_unconfirmed'): self.observer.tick()
                self.assertFalse((self.stage / 'startup-release.ack').exists())

    def test_missing_ready_marker_waits_but_child_exit_cannot_pass(self):
        self.observer.tick()
        with self.assertRaisesRegex(ValueError, 'before_verified_release'): self.observer.finish(0)

    def test_missing_started_receipt_or_nonzero_exit_is_failure(self):
        self.ready(); self.close()
        with self.assertRaisesRegex(ValueError, 'fixture_start_unconfirmed'): self.observer.finish(0)
        publish(self.stage, 'started')
        with self.assertRaisesRegex(ValueError, 'before_verified_release'): self.observer.finish(-15)

    def test_stale_nonce_wrong_image_port_and_partial_receipt_refused(self):
        for changes in [{'nonce': 'b' * 32}, {'image': 'save_bridge'}, {'world_port': 0}, {'runtime': '516.1689'}, {'extra': 1}]:
            with self.subTest(changes=changes):
                publish(self.stage, 'ready', **changes)
                with self.assertRaises(ValueError): self.observer.tick()
        publish(self.stage, 'ready')
        (self.stage / 'startup-ready.json').write_bytes(b'{')
        with self.assertRaises(ValueError): self.observer.tick()

    def test_duplicate_nonfinite_oversized_json_refused(self):
        for raw in [b'{"a":1,"a":2}', b'{"a":NaN}', b'x' * 1025]:
            with self.subTest(raw=raw[:20]), self.assertRaises(ValueError): startup.parse_json(raw)

    def test_regular_receipt_rejects_symlink_and_oversize_without_following(self):
        path = self.stage / 'example'
        path.write_bytes(b'12345')
        with self.assertRaisesRegex(ValueError, 'size'): startup.regular_bytes(path, 4)
        with patch.object(Path, 'lstat', return_value=SimpleNamespace(st_mode=stat.S_IFLNK, st_size=0)), patch.object(startup.os, 'open') as opened:
            with self.assertRaisesRegex(ValueError, 'type'): startup.regular_bytes(path, 4)
        opened.assert_not_called()

    def test_receipt_replacement_is_refused(self):
        path = self.stage / 'example'
        path.write_bytes(b'{}')
        original = startup.os.fstat
        def replaced(fd):
            value = original(fd)
            return SimpleNamespace(st_mode=value.st_mode, st_dev=value.st_dev, st_ino=value.st_ino + 1)
        with patch.object(startup.os, 'fstat', side_effect=replaced):
            with self.assertRaisesRegex(ValueError, 'replaced'): startup.regular_bytes(path, 32)

    def test_metadata_change_during_read_is_refused(self):
        path = self.stage / 'example'; path.write_bytes(b'{}')
        original = startup.os.fstat
        calls = 0
        def changed(fd):
            nonlocal calls
            calls += 1
            value = original(fd)
            return SimpleNamespace(st_mode=value.st_mode, st_dev=value.st_dev, st_ino=value.st_ino,
                                   st_size=value.st_size, st_mtime_ns=value.st_mtime_ns,
                                   st_ctime_ns=value.st_ctime_ns + (calls > 1))
        with patch.object(startup.os, 'fstat', side_effect=changed):
            with self.assertRaisesRegex(ValueError, 'changed'): startup.regular_bytes(path, 32)

    def test_control_publication_refuses_existing_files_and_preserves_partial_failure(self):
        path = self.stage / 'ack'
        startup.atomic_bytes(path, b'{}')
        with self.assertRaisesRegex(ValueError, 'already_exists'): startup.atomic_bytes(path, b'{}')
        other = self.stage / 'failed'
        with patch.object(startup.os, 'replace', side_effect=OSError('injected replace')):
            with self.assertRaises(OSError): startup.atomic_bytes(other, b'{}')
        self.assertFalse(other.exists())
        self.assertEqual(other.with_name('failed.partial').read_bytes(), b'{}')

    def test_proc_parsers_are_bounded_and_preserve_correct_starttime_field(self):
        raw = b'817263 (odd ) command) S ' + b'0 ' * 18 + b'900 0\n'
        self.assertEqual(startup.stat_starttime(raw, 817263), 900)
        row = b'0: 0100007F:6364 00000000:0000 0A 00000000:00000000 00:00000000 00000000 1001 0 1234 1\n'
        parsed = startup.parse_table(b' sl local_address rem_address st tx rx tr uid timeout inode\n' + row, 'tcp')
        self.assertEqual(parsed[0]['inode'], 1234)
        for data in [b'', b'x' * 131073, b'local_address\nmalformed\n']:
            with self.assertRaises(ValueError): startup.parse_table(data, 'tcp')

    def test_proc_reader_fd_and_table_faults_propagate_without_pid_enumeration(self):
        # Construct without __init__: executable verification is separately
        # controlled, and no real /proc operation is permitted in this test.
        proc = object.__new__(startup.ProcReader)
        error = OSError('injected proc FD scandir')
        with patch.object(startup.os, 'scandir', side_effect=error) as scan:
            with self.assertRaises(OSError) as caught: proc.census(817263)
        self.assertIs(caught.exception, error)
        self.assertEqual(str(scan.call_args.args[0]).replace('\\', '/'), '/proc/817263/fd')

    def test_proc_reader_pins_executable_before_any_pid_read(self):
        executable = self.stage / 'mock-DreamDaemon'; executable.write_bytes(b'not a native executable')
        with self.assertRaisesRegex(ValueError, 'executable_pin'): startup.ProcReader(executable)
        with patch.object(startup, 'DAEMON_SHA256', hashlib.sha256(executable.read_bytes()).hexdigest()):
            proc = startup.ProcReader(executable)
        info = executable.lstat()
        raw_stat = b'817263 (DreamDaemon) S ' + b'0 ' * 18 + b'900 0\n'
        with patch.object(proc, 'read', return_value=raw_stat), patch.object(startup.os, 'readlink', return_value=str(executable)), patch.object(Path, 'stat', return_value=info):
            self.assertEqual(proc.identity(817263), 900)
        with patch.object(proc, 'read', return_value=raw_stat), patch.object(startup.os, 'readlink', return_value=str(executable) + ' (deleted)'):
            with self.assertRaisesRegex(ValueError, 'executable_changed'): proc.identity(817263)

    def test_proc_reader_maps_only_kernel_socket_links_and_bounds_tables(self):
        proc = object.__new__(startup.ProcReader)
        entries = [SimpleNamespace(name='1', path='/mock/fd/1'), SimpleNamespace(name='3', path='/mock/fd/3')]
        class Scan:
            def __enter__(self): return iter(entries)
            def __exit__(self, *_): pass
        row = b'0: 0100007F:6364 00000000:0000 0A 00000000:00000000 00:00000000 00000000 1001 0 1234 1\n'
        def read(path, cap):
            self.assertEqual(cap, 131072)
            return b'local_address header\n' + (row if path.name == 'tcp' else b'')
        with patch.object(startup.os, 'scandir', return_value=Scan()), patch.object(startup.os, 'readlink', side_effect=['/mock/stdout', 'socket:[1234]']), patch.object(proc, 'read', side_effect=read):
            inodes, rows = proc.census(817263)
        self.assertEqual(inodes, {1234})
        self.assertEqual(len(rows), 1)
        with patch.object(startup.os, 'scandir', return_value=Scan()), patch.object(startup.os, 'readlink', side_effect=['/mock/stdout', 'socket:[malformed]']):
            with self.assertRaisesRegex(ValueError, 'inode_shape'): proc.census(817263)

    def test_unconfirmed_scope_or_forged_identity_cannot_validate(self):
        for key, value in [('status', 'incomplete'), ('pid', True), ('listener_inode', None),
                           ('image_sha256', None), ('same_inode_absent_before_release', False),
                           ('exit_confirmed', False), ('exit_code', 1), ('release_ms', 1000)]:
            with self.subTest(key=key):
                record = verified_evidence(); record[key] = value
                with self.assertRaises(ValueError): startup.validate_evidence(record, 'fixture_generator')

    def test_derivative_inserts_guard_before_existing_body_and_rejects_source_drift(self):
        original = b'world\n\tvisibility = 0\n\n/world/New()\n\t..()\n\tfixture()\n'
        for image in startup.IMAGES:
            derivative = startup.instrument(original, image)
            self.assertTrue(derivative.startswith(b'#include "startup_guard.dm"\n'))
            self.assertLess(derivative.index(b'ss_startup'), derivative.index(b'\t..()'))
            self.assertEqual(derivative.count(b'\tfixture()'), 1)
            with self.assertRaises(ValueError): startup.instrument(derivative, image)
        with self.assertRaisesRegex(ValueError, 'source_shape'): startup.instrument(original + original, 'save_bridge')

    def test_nonce_regex_is_a_literal_raw_dm_pattern_with_closed_language(self):
        source = Path(__file__).with_name('startup_guard.dm').read_text()
        match = re.findall(r'var/regex/R = regex\(@"([^"\n]*)"\)', source)
        self.assertEqual(match, ['^[a-f0-9]{32}$'])
        self.assertNotIn('regex("^[a-f0-9]', source)  # Historical broken expression.
        for nonce, valid in [('a' * 32, True), ('0123456789abcdef' * 2, True), ('A' * 32, False),
                             ('a' * 31, False), ('a' * 33, False), ('g' * 32, False), ('a' * 31 + '\n', False)]:
            with self.subTest(nonce=repr(nonce)):
                self.assertEqual(re.fullmatch(match[0], nonce) is not None, valid)

    def test_original_deadline_accepts_power_of_two_rounding_without_extension(self):
        started = math.nextafter(1024.0, 0.0)
        deadline = started + 10
        self.assertNotEqual(deadline - started, 10)  # Previous check rejected.
        stage = self.stage / 'power-two'; stage.mkdir()
        observer = startup.StartupObserver(stage, '/unqueried/mock/DreamDaemon', 'fixture_generator', 'a' * 64, proc=FakeProc(), nonce='a' * 32)
        observer.attach(SimpleNamespace(pid=817263), 817263, started, deadline, 'owned-child-3')
        self.assertEqual(observer.deadline, deadline)
        self.assertEqual(observer.close_deadline, min(deadline - .75, started + 1))

    def test_marker_writer_explicitly_suppresses_implicit_output_newline(self):
        source = Path(__file__).with_name('startup_guard.dm').read_text()
        self.assertIn('return text2file("[nonce]\\...", "[name].done")', source)
        self.assertNotIn('return text2file(nonce,', source)

    def test_marker_admission_remains_exact_32_bytes_without_trimming(self):
        publish(self.stage, 'ready')
        marker = self.stage / 'startup-ready.done'
        for raw in [b'a' * 32 + b'\n', b'a' * 32 + b'\r\n', b'a' * 32 + b' ',
                    b' ' + b'a' * 32, b'a' * 31, b'A' * 32, b'a' * 31 + b'\0',
                    b'a' * 32 + b'x', b'\xef\xbb\xbf' + b'a' * 32, b'a' * 32 * 2]:
            with self.subTest(raw=raw):
                marker.write_bytes(raw)
                with self.assertRaises(ValueError): self.observer.receipt('ready')
                self.assertFalse((self.stage / 'startup-close.ack').exists())
        marker.write_bytes(b'a' * 32)
        self.assertEqual(self.observer.receipt('ready')['nonce'], 'a' * 32)

    def test_failed_marker_capture_records_exact_synthetic_newline_bytes(self):
        publish(self.stage, 'ready')
        raw = b'a' * 32 + b'\n'  # Explicit offline counterexample, not run41354035997 bytes.
        (self.stage / 'startup-ready.done').write_bytes(raw)
        with self.assertRaisesRegex(ValueError, 'file_type_or_size'): self.observer.receipt('ready')
        self.observer.capture_failed_marker()
        record = self.observer.evidence()['failed_marker']
        self.assertEqual(record['kind'], 'complete_snapshot')
        self.assertEqual((record['size'], record['captured_bytes'], record['bytes_hex']), (33, 33, raw.hex()))
        self.assertEqual(record['sha256_snapshot'], hashlib.sha256(raw).hexdigest())
        self.assertEqual(self.observer.phase, 'waiting_ready')

    def test_failed_marker_capture_is_bounded_and_hash_is_only_prefix(self):
        path = self.stage / 'startup-ready.done'
        path.write_bytes(b'x' * 4096)
        record = startup.closed_marker_snapshot(path)
        self.assertEqual((record['kind'], record['size'], record['captured_bytes']), ('bounded_prefix', 4096, 64))
        self.assertEqual(record['bytes_hex'], (b'x' * 64).hex())
        self.assertEqual(record['sha256_snapshot'], hashlib.sha256(b'x' * 64).hexdigest())
        self.assertNotIn('sha256_file', record)

    def test_failed_marker_nonregular_is_never_opened(self):
        path = self.stage / 'startup-ready.done'; path.mkdir()
        with patch.object(startup.os, 'open', side_effect=AssertionError('No nonregular open')):
            self.assertEqual(startup.closed_marker_snapshot(path)['kind'], 'nonregular_not_read')
            info = path.lstat()
            for mode in [stat.S_IFLNK, stat.S_IFIFO, stat.S_IFSOCK]:
                fields = dict(st_mode=mode, st_size=33, st_dev=info.st_dev, st_ino=info.st_ino,
                              st_mtime_ns=info.st_mtime_ns, st_ctime_ns=info.st_ctime_ns)
                with patch.object(Path, 'lstat', return_value=SimpleNamespace(**fields)):
                    self.assertEqual(startup.closed_marker_snapshot(path)['kind'], 'nonregular_not_read')

    def test_failed_marker_changed_or_replaced_snapshot_is_rejected(self):
        path = self.stage / 'startup-ready.done'; path.write_bytes(b'a' * 33)
        original = path.lstat()
        fields = {key: getattr(original, key) for key in ['st_mode', 'st_size', 'st_dev', 'st_ino', 'st_mtime_ns', 'st_ctime_ns']}
        changed = SimpleNamespace(**dict(fields, st_size=34))
        with patch.object(Path, 'lstat', side_effect=[original, changed]):
            with self.assertRaisesRegex(ValueError, 'diagnostic_changed'): startup.closed_marker_snapshot(path)
        replaced = SimpleNamespace(**dict(fields, st_ino=original.st_ino + 1))
        with patch.object(startup.os, 'fstat', return_value=replaced):
            with self.assertRaisesRegex(ValueError, 'diagnostic_replaced'): startup.closed_marker_snapshot(path)

    def test_failed_marker_capture_errors_are_typed_and_do_not_escape(self):
        self.observer.marker_phase = 'ready'
        for error in [PermissionError('secret path omitted'), FileNotFoundError(), ValueError('changed')]:
            with self.subTest(error=type(error).__name__), patch.object(startup, 'closed_marker_snapshot', side_effect=error):
                self.observer.capture_failed_marker()
                self.assertEqual(self.observer.evidence()['failed_marker'], {'kind': 'unavailable_capture_failed', 'error': type(error).__name__})

    def test_failed_marker_no_receipt_attempt_never_reads(self):
        with patch.object(startup, 'closed_marker_snapshot', side_effect=AssertionError('No unattempted read')):
            self.observer.capture_failed_marker()
        self.assertEqual(self.observer.evidence()['failed_marker']['kind'], 'unavailable_no_receipt_attempt')

    def test_original_deadline_rejects_any_change_and_nonfinite_or_boolean_inputs(self):
        started = math.nextafter(1024.0, 0.0)
        deadline = started + 10
        cases = [(started, math.nextafter(deadline, math.inf)), (started, math.nextafter(deadline, 0)),
                 (started, deadline + 1e-6), (0, 9), (0, 11), (float('nan'), 10),
                 (0, float('inf')), (float('inf'), float('inf')), (False, 10), (0, True)]
        for index, (begin, end) in enumerate(cases):
            with self.subTest(begin=begin, end=end):
                stage = self.stage / ('bad-time-' + str(index)); stage.mkdir()
                proc = FakeProc()
                observer = startup.StartupObserver(stage, '/unqueried/mock/DreamDaemon', 'fixture_generator', 'a' * 64, proc=proc, nonce='a' * 32)
                with self.assertRaisesRegex(ValueError, 'original_deadline'):
                    observer.attach(SimpleNamespace(pid=817263), 817263, begin, end, 'owned-child-3')
                self.assertEqual(proc.calls, [])

    def test_scope_cannot_claim_os_egress_containment(self):
        record = verified_evidence()
        record['os_egress_containment'] = True
        with self.assertRaisesRegex(ValueError, 'scope'): startup.validate_evidence(record, 'fixture_generator')

    def test_exact_transcript_requires_complete_correct_startup_proof(self):
        from diagnostics import classify_logs
        policy = json.loads(Path(__file__).with_name('diagnostics.json').read_text())
        self.assertEqual(classify_logs(runtime_logs(), policy, startup_evidence=verified_evidence(), image='fixture_generator')['status'], 'accepted')
        for logs in [runtime_logs(51177), runtime_logs(0), {'stdout': {'text': '', 'truncated': False}}]:
            with self.subTest(logs=logs), self.assertRaises(ValueError):
                classify_logs(logs, policy, startup_evidence=verified_evidence(), image='fixture_generator')
        with self.assertRaises(ValueError): classify_logs(runtime_logs(), policy)
        logs = runtime_logs(); logs['native.log']['text'] += 'runtime error: control\n'
        with self.assertRaisesRegex(ValueError, 'diagnostic_error'):
            classify_logs(logs, policy, startup_evidence=verified_evidence(), image='fixture_generator')


class FakeChild:
    pid = 817263
    def __init__(self, stage, proc):
        self.stage, self.proc, self.code = stage, proc, None
        self.signals = []
        self.term_exits = self.kill_exits = True
    def poll(self):
        if self.code is not None: return self.code
        if (self.stage / 'startup-release.ack').exists():
            publish(self.stage, 'started'); self.code = 0
        elif (self.stage / 'startup-close.ack').exists():
            publish(self.stage, 'closed'); self.proc.closed = True
        return self.code
    def terminate(self):
        self.signals.append(('terminate', id(self)))
        if self.term_exits: self.code = -15
    def kill(self):
        self.signals.append(('kill', id(self)))
        if self.kill_exits: self.code = -9


class ObserverCleanupTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='startup-cleanup-zero-process-')
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.stage = self.root / 'job'; self.stage.mkdir()
        self.clock, self.proc = Clock(), FakeProc()
        self.child = FakeChild(self.stage, self.proc)
        self.observer = startup.StartupObserver(self.stage, '/unqueried/mock/DreamDaemon', 'fixture_generator', 'a' * 64, proc=self.proc, nonce='a' * 32)
        own.ACTIVE = None; own.PENDING.clear()
        self.addCleanup(self.clear)
        for target, replacement in [('monotonic', self.clock.monotonic), ('sleep', self.clock.sleep)]:
            guard = patch.object(own.time, target, side_effect=replacement)
            guard.start(); self.addCleanup(guard.stop)
        guard = patch.object(own.subprocess, 'Popen', side_effect=self.launch)
        self.popen = guard.start(); self.addCleanup(guard.stop)
        guard = patch('socket.socket', side_effect=AssertionError('No connections'))
        guard.start(); self.addCleanup(guard.stop)

    def clear(self): own.ACTIVE = None; own.PENDING.clear()

    def launch(self, argv, **kwargs):
        self.assertEqual(argv, ['/unqueried/mock/DreamDaemon', 'fixture_generator.dmb'])
        self.assertTrue(callable(kwargs['preexec_fn']))
        (self.stage / 'native.log').write_text(runtime_logs()['native.log']['text'])
        publish(self.stage, 'ready')
        return self.child

    def run_child(self):
        return own.bounded(['/unqueried/mock/DreamDaemon', 'fixture_generator.dmb'], self.stage, 10, 4096,
                           self.root, {'HOME': str(self.stage)}, 8, observer=self.observer)

    def test_actual_adapter_completes_with_fake_listener_handshake(self):
        with patch.object(self.observer, 'capture_failed_marker', side_effect=AssertionError('No success capture')) as capture:
            result = self.run_child()
        capture.assert_not_called()
        self.assertEqual(result['record']['startup']['status'], 'verified')
        self.assertEqual(result['record']['ownership'], 'reaped')
        self.assertEqual(self.child.signals, [])
        self.assertIsNone(own.ACTIVE)

    def assert_cleans_error(self, target, error):
        self.child.term_exits = False
        with patch.object(self.proc, target, side_effect=error), patch.object(own.shared, 'finish_owned_compiler', wraps=own.shared.finish_owned_compiler) as finish:
            with self.assertRaises(type(error)) as caught: self.run_child()
        self.assertIs(caught.exception, error)
        self.assertEqual(self.child.signals, [('terminate', id(self.child)), ('kill', id(self.child))])
        self.assertIs(finish.call_args.args[0], self.child)
        self.assertEqual(finish.call_args.args[1], 10)
        self.assertLessEqual(self.clock.value, 10)

    def test_attach_proc_stat_or_executable_read_error_cleans_owned_handle(self):
        self.assert_cleans_error('identity', OSError('injected proc stat/exe read'))

    def test_fd_enumeration_error_cleans_owned_handle(self):
        self.assert_cleans_error('census', OSError('injected FD enumeration'))

    def test_proc_table_parse_error_cleans_owned_handle(self):
        self.assert_cleans_error('census', ValueError('injected malformed socket table'))

    def test_receipt_read_failure_cleans_owned_handle(self):
        error = OSError('injected receipt read')
        with patch.object(self.observer, 'receipt', side_effect=error):
            with self.assertRaises(OSError) as caught: self.run_child()
        self.assertIs(caught.exception, error)
        self.assertEqual(self.child.signals, [('terminate', id(self.child))])

    def test_ack_write_failure_cleans_owned_handle(self):
        error = OSError('injected ack publication')
        with patch.object(self.observer, 'acknowledge', side_effect=error):
            with self.assertRaises(OSError) as caught: self.run_child()
        self.assertIs(caught.exception, error)
        self.assertEqual(self.child.signals, [('terminate', id(self.child))])

    def test_wrong_or_extra_listener_stops_and_cleans(self):
        self.proc.inodes.add(1235)
        with self.assertRaisesRegex(ValueError, 'socket_count'): self.run_child()
        self.assertEqual(self.child.signals, [('terminate', id(self.child))])

    def test_startup_timeout_uses_original_child_cleanup_deadline(self):
        self.child.term_exits = False
        with patch.object(self.observer, 'receipt', return_value=None), patch.object(own.shared, 'finish_owned_compiler', wraps=own.shared.finish_owned_compiler) as finish:
            with self.assertRaisesRegex(ValueError, 'close_timeout'): self.run_child()
        self.assertEqual(finish.call_args.args[1], 10)
        self.assertEqual(self.child.signals, [('terminate', id(self.child)), ('kill', id(self.child))])
        self.assertLessEqual(self.clock.value, 10)

    def test_poll_fault_still_uses_owned_cleanup_and_retains_primary(self):
        error = OSError('injected first poll')
        original = self.child.poll
        calls = 0
        def poll():
            nonlocal calls
            calls += 1
            if calls == 1: raise error
            return original()
        with patch.object(self.child, 'poll', side_effect=poll):
            with self.assertRaises(OSError) as caught: self.run_child()
        self.assertIs(caught.exception, error)
        self.assertEqual(self.child.signals, [('terminate', id(self.child))])

    def test_unconfirmed_cleanup_keeps_identity_and_reads_no_final_receipt_or_log(self):
        self.child.term_exits = self.child.kill_exits = False
        primary = OSError('injected proc error')
        with patch.object(self.proc, 'census', side_effect=primary), patch.object(self.observer, 'finish') as finish, patch.object(own, 'snapshot') as snap, patch.object(self.observer, 'capture_failed_marker') as capture:
            with self.assertRaises(OSError) as caught: self.run_child()
        self.assertIs(caught.exception, primary)
        finish.assert_not_called(); snap.assert_not_called(); capture.assert_not_called()
        self.assertIs(own.ACTIVE, self.child)
        record = json.loads((self.stage / 'process.json').read_text())
        self.assertEqual(record['ownership'], 'retained')
        self.assertEqual(record['startup']['status'], 'incomplete')
        self.assertEqual(record['logs']['native.log']['sha256_snapshot'], None)

    def test_cleanup_exception_preserves_observer_primary_error(self):
        primary = OSError('injected proc error')
        with patch.object(self.proc, 'census', side_effect=primary), patch.object(own.shared, 'finish_owned_compiler', side_effect=RuntimeError('injected cleanup')):
            with self.assertRaises(OSError) as caught: self.run_child()
        self.assertIs(caught.exception, primary)
        self.assertIs(own.ACTIVE, self.child)

    def test_observer_finish_failure_after_exit_is_not_process_success(self):
        with patch.object(self.observer, 'finish', side_effect=OSError('injected final receipt')):
            with self.assertRaises(OSError): self.run_child()
        record = json.loads((self.stage / 'process.json').read_text())
        self.assertEqual(record['ownership'], 'reaped')
        self.assertEqual(record['fault'], 'startup_evidence_error')
        self.assertEqual(record['status'], 'process_failed')

    def test_bad_marker_is_captured_only_after_confirmed_owned_cleanup(self):
        original_launch = self.launch
        raw = b'a' * 32 + b'\n'
        def launch(*args, **kwargs):
            child = original_launch(*args, **kwargs)
            (self.stage / 'startup-ready.done').write_bytes(raw)
            return child
        original_snapshot = startup.closed_marker_snapshot
        def closed_snapshot(path):
            self.assertIsNotNone(self.child.code)
            self.assertIsNone(own.ACTIVE)
            self.assertNotIn(self.child, own.PENDING)
            return original_snapshot(path)
        self.popen.side_effect = launch
        with patch.object(startup, 'closed_marker_snapshot', side_effect=closed_snapshot) as capture:
            with self.assertRaisesRegex(ValueError, 'file_type_or_size'): self.run_child()
        capture.assert_called_once()
        record = json.loads((self.stage / 'process.json').read_text())
        self.assertEqual(record['startup']['failed_marker']['bytes_hex'], raw.hex())
        self.assertEqual((record['ownership'], record['exit_code'], record['fault']), ('reaped', -15, 'supervision_error'))
        self.assertFalse((self.stage / 'startup-close.ack').exists())

    def test_capture_failure_preserves_original_error_and_reaped_state(self):
        primary = OSError('injected proc error')
        with patch.object(self.proc, 'census', side_effect=primary), patch.object(self.observer, 'capture_failed_marker', side_effect=RuntimeError('capture failed')):
            with self.assertRaises(OSError) as caught: self.run_child()
        self.assertIs(caught.exception, primary)
        record = json.loads((self.stage / 'process.json').read_text())
        self.assertEqual(record['failed_marker_capture_error'], 'RuntimeError')
        self.assertEqual(record['ownership'], 'reaped')


if __name__ == '__main__': unittest.main()
