"""Decision-point diagnostics with fake procfs and owned children only."""
import json
from pathlib import Path
import stat
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parent))
import startup_watchdog as startup
import owned_process as own
import test_startup_watchdog as base


HEADER = b'local_address header\n'
def row(inode=1234, local='0100007F:6364'):
    return f'0: {local} 00000000:0000 0A 00000000:00000000 00:00000000 00000000 1001 0 {inode} 1\n'.encode('ascii')


class Scan:
    def __init__(self, entries): self.entries = entries
    def __enter__(self): return iter(self.entries)
    def __exit__(self, *_): pass


class DiagnosticTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='startup-diagnostic-zero-process-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.stage = self.root / 'stage'; self.stage.mkdir()
        self.clock, self.proc = base.Clock(), base.FakeProc()
        for target, replacement in [('monotonic', self.clock.monotonic), ('sleep', self.clock.sleep)]:
            guard = patch.object(startup.time, target, side_effect=replacement)
            guard.start(); self.addCleanup(guard.stop)
        for target in ['subprocess.Popen', 'socket.socket']:
            guard = patch(target, side_effect=AssertionError('No actual process or connection'))
            guard.start(); self.addCleanup(guard.stop)
        self.observer = startup.StartupObserver(self.stage, '/unqueried/mock/DreamDaemon', 'fixture_generator', 'a' * 64, proc=self.proc, nonce='a' * 32)
        self.observer.attach(SimpleNamespace(pid=817263), 817263, 0, 10, 'owned-child-3')

    def reject(self, reason):
        with self.assertRaisesRegex(ValueError, reason): self.observer.tick()
        record = self.observer.evidence()['rejection']
        self.assertEqual(record['reason'], 'startup_' + reason)
        self.assertEqual(record['pid'], 817263)
        self.assertEqual(record['owner_token'], 'owned-child-3')
        self.assertLessEqual(len(json.dumps(record).encode()), 32768)
        return record

    def test_zero_socket_records_zero_before_cleanup(self):
        base.publish(self.stage, 'ready'); self.proc.inodes, self.proc.rows = set(), []
        record = self.reject('owned_socket_count')
        self.assertEqual(record['point'], 'ready_socket_cardinality')
        self.assertEqual(record['decision']['socket_count'], 0)
        self.assertEqual(record['decision']['cardinality'], 'zero')
        self.assertEqual(record['decision']['socket_inodes'], [])

    def test_multiple_sockets_record_exact_inventory_and_rows(self):
        base.publish(self.stage, 'ready'); self.proc.inodes.add(1235)
        self.proc.rows.append(dict(self.proc.rows[0], inode=1235, local='0100007F:6365'))
        record = self.reject('owned_socket_count')
        self.assertEqual(record['decision']['socket_count'], 2)
        self.assertEqual(record['decision']['cardinality'], 'multiple')
        self.assertEqual(record['decision']['socket_inodes'], [1234, 1235])
        self.assertEqual(len(record['decision']['matching_rows']), 2)
        self.assertFalse((self.stage / 'startup-close.ack').exists())

    def test_unmapped_and_duplicate_rows_are_distinct_from_cardinality(self):
        base.publish(self.stage, 'ready')
        for rows, count in [([], 0), (self.proc.rows * 2, 2)]:
            self.proc.rows = rows
            record = self.reject('owned_socket_unmapped_or_duplicate')
            self.assertEqual(record['point'], 'ready_socket_mapping')
            self.assertEqual((record['decision']['socket_count'], record['decision']['matching_rows_count']), (1, count))

    def test_endpoint_mismatch_retains_rejected_address(self):
        base.publish(self.stage, 'ready'); self.proc.rows[0]['local'] = '00000000:6364'
        record = self.reject('unexpected_endpoint_or_connection')
        self.assertEqual(record['point'], 'ready_socket_endpoint')
        self.assertEqual(record['decision']['matching_rows'][0]['local'], '00000000:6364')

    def test_identity_change_retains_expected_and_observed_ticks(self):
        self.proc.starttime = 901
        record = self.reject('owned_identity_changed')
        self.assertEqual((record['expected_starttime_ticks'], record['decision']['observed_starttime_ticks']), (900, 901))
        self.assertEqual(record['point'], 'tick_identity_before')

    def test_expired_deadline_retains_actual_sample_and_fixed_bound(self):
        self.clock.value = 1
        record = self.reject('close_timeout')
        self.assertEqual(record['point'], 'tick_close_deadline')
        self.assertEqual((record['decision']['sampled_monotonic'], record['decision']['close_deadline']), (1, 1))

    def test_marker_oversize_retains_live_decision_stat_without_extra_read(self):
        base.publish(self.stage, 'ready'); (self.stage / 'startup-ready.done').write_bytes(b'a' * 32 + b'\n')
        with patch.object(startup, 'closed_marker_snapshot', side_effect=AssertionError('No postexit capture now')):
            record = self.reject('file_type_or_size')
        self.assertEqual(record['point'], 'receipt_marker_read')
        self.assertEqual(record['receipt']['marker_read']['size'], 33)
        self.assertEqual(record['receipt']['marker_read']['cap'], 32)
        self.assertNotIn('captured_bytes', record['receipt']['marker_read'])

    def test_bad_json_and_binding_keep_bounded_received_inputs(self):
        base.publish(self.stage, 'ready'); (self.stage / 'startup-ready.json').write_bytes(b'{')
        with self.assertRaises(ValueError): self.observer.tick()
        record = self.observer.evidence()['rejection']
        self.assertEqual(record['point'], 'receipt_json_parse')
        self.assertEqual(record['receipt']['json_prefix_hex'], b'{'.hex())
        self.assertIsNone(record['reason'])  # No arbitrary parser message.
        base.publish(self.stage, 'ready', runtime='516.1689')
        record = self.reject('receipt_binding')
        self.assertFalse(record['receipt']['binding_matches']['runtime'])

    def test_ack_publication_failure_keeps_stage_and_primary_error(self):
        base.publish(self.stage, 'ready')
        error = PermissionError('do not echo secret path')
        with patch.object(startup, 'atomic_bytes', side_effect=error):
            with self.assertRaises(PermissionError) as caught: self.observer.tick()
        self.assertIs(caught.exception, error)
        record = self.observer.evidence()['rejection']
        self.assertEqual(record['point'], 'ack_close_publish')
        self.assertEqual(record['error_type'], 'PermissionError')
        self.assertNotIn('secret', json.dumps(record))

    def test_closure_failure_retains_prior_inode_rows(self):
        base.publish(self.stage, 'ready'); self.observer.tick()
        base.publish(self.stage, 'closed'); self.proc.inodes = set()
        record = self.reject('listener_closure_unconfirmed')
        self.assertEqual(record['point'], 'closed_socket_absence')
        self.assertEqual(record['decision']['prior_listener_inode'], 1234)
        self.assertEqual(record['decision']['matching_rows'][0]['inode'], 1234)

    def test_finish_failure_keeps_observed_code_and_phase(self):
        with self.assertRaisesRegex(ValueError, 'exit_before'): self.observer.finish(-9)
        record = self.observer.evidence()['rejection']
        self.assertEqual(record['point'], 'finish_phase_and_exit')
        self.assertEqual(record['decision']['exit_code'], -9)

    def kernel_proc(self):
        proc = object.__new__(startup.ProcReader)
        self.observer.proc = proc
        proc.identity = lambda pid: 900  # Exact owned identity stub; never /proc.
        return proc

    def test_unreadable_fd_inventory_records_error_at_decision_point(self):
        base.publish(self.stage, 'ready'); proc = self.kernel_proc()
        error = PermissionError('sensitive OS path')
        with patch.object(startup.os, 'scandir', side_effect=error):
            with self.assertRaises(PermissionError) as caught: self.observer.tick()
        self.assertIs(caught.exception, error)
        record = self.observer.evidence()['rejection']
        self.assertEqual(record['point'], 'tick_census')
        self.assertEqual(record['proc_census']['point'], 'fd_scan')
        self.assertFalse(record['proc_census']['fd_scan_complete'])
        self.assertEqual(record['proc_census']['error_type'], 'PermissionError')
        self.assertNotIn('sensitive', json.dumps(record))

    def test_unreadable_table_retains_completed_owned_fd_inventory(self):
        base.publish(self.stage, 'ready'); proc = self.kernel_proc()
        entries = [SimpleNamespace(name='3', path='/mock/fd/3')]
        error = OSError('unreadable table')
        with patch.object(startup.os, 'scandir', return_value=Scan(entries)), patch.object(startup.os, 'readlink', return_value='socket:[1234]'), patch.object(proc, 'read', side_effect=error):
            with self.assertRaises(OSError): self.observer.tick()
        record = self.observer.evidence()['rejection']['proc_census']
        self.assertEqual((record['point'], record['current_table']), ('table_read', 'tcp'))
        self.assertTrue(record['fd_scan_complete'])
        self.assertEqual(record['socket_inodes'], [1234])
        self.assertEqual(record['socket_fds'][0]['link_prefix'], 'socket:[1234]')

    def test_kernel_join_inputs_filter_unrelated_rows_and_retain_prior_inode(self):
        proc = object.__new__(startup.ProcReader); proc.prior_inode = 1234
        entries = [SimpleNamespace(name='3', path='/mock/fd/3')]
        def read(path, cap): return HEADER + (row(1235) + row(1234) + row(9999) if path.name == 'tcp' else b'')
        with patch.object(startup.os, 'scandir', return_value=Scan(entries)), patch.object(startup.os, 'readlink', return_value='socket:[1235]'), patch.object(proc, 'read', side_effect=read):
            inodes, rows = proc.census(817263)
        record = proc.last_census
        self.assertEqual(inodes, {1235}); self.assertEqual(len(rows), 3)
        retained = record['tables']['tcp']['matching_rows']
        self.assertEqual([r['parsed']['inode'] for r in retained], [1235, 1234])
        self.assertNotIn('9999', ''.join(bytes.fromhex(r['raw_prefix_hex']).decode() for r in retained))

    def test_malformed_owned_table_row_retains_input_and_stage(self):
        base.publish(self.stage, 'ready'); proc = self.kernel_proc()
        entries = [SimpleNamespace(name='3', path='/mock/fd/3')]
        bad = row(local='BAD:6364')
        with patch.object(startup.os, 'scandir', return_value=Scan(entries)), patch.object(startup.os, 'readlink', return_value='socket:[1234]'), patch.object(proc, 'read', return_value=HEADER + bad):
            record = self.reject('socket_address_shape')
        self.assertEqual(record['proc_census']['point'], 'table_parse')
        self.assertEqual(record['proc_census']['tables']['tcp']['matching_rows'][0]['raw_prefix_hex'], bad.rstrip(b'\n').hex())

    def test_partial_fd_read_error_retains_already_observed_socket(self):
        proc = object.__new__(startup.ProcReader)
        entries = [SimpleNamespace(name=str(i), path='/mock/fd/' + str(i)) for i in [3, 4]]
        with patch.object(startup.os, 'scandir', return_value=Scan(entries)), patch.object(startup.os, 'readlink', side_effect=['socket:[1234]', PermissionError('no')]):
            with self.assertRaises(PermissionError): proc.census(817263)
        self.assertEqual(proc.last_census['socket_count'], 1)
        self.assertFalse(proc.last_census['fd_scan_complete'])
        self.assertEqual(proc.last_census['current_fd'], '4')

    def test_kernel_identity_rejection_retains_observed_executable(self):
        proc = object.__new__(startup.ProcReader)
        proc.executable = Path('/mock/DreamDaemon'); proc.executable_identity = (1, 2)
        raw = b'817263 (DreamDaemon) S ' + b'0 ' * 18 + b'900 0\n'
        with patch.object(proc, 'read', return_value=raw), patch.object(startup.os, 'readlink', return_value='/mock/wrong'):
            with self.assertRaisesRegex(ValueError, 'executable_changed'): proc.identity(817263)
        self.assertEqual(proc.last_identity['starttime_ticks'], 900)
        self.assertFalse(proc.last_identity['executable_link_matches'])
        self.assertEqual(proc.last_identity['point'], 'exe_link_validate')

    def test_diagnostics_are_frozen_before_owned_cleanup_and_need_no_reread(self):
        own.ACTIVE = None; own.PENDING.clear()
        self.addCleanup(lambda: (setattr(own, 'ACTIVE', None), own.PENDING.clear()))
        self.stage = self.root / 'owned-case'; self.stage.mkdir()
        self.observer = startup.StartupObserver(self.stage, '/unqueried/mock/DreamDaemon', 'fixture_generator', 'a' * 64, proc=self.proc, nonce='a' * 32)
        child = base.FakeChild(self.stage, self.proc)
        def launch(*args, **kwargs):
            (self.stage / 'native.log').write_text(base.runtime_logs()['native.log']['text'])
            base.publish(self.stage, 'ready'); self.proc.inodes.add(1235)
            return child
        original_terminate = child.terminate
        def terminate():
            self.assertEqual(self.observer.record['rejection']['decision']['socket_inodes'], [1234, 1235])
            self.proc.inodes.clear()  # Cleanup cannot change the frozen report.
            original_terminate()
        with patch.object(own.subprocess, 'Popen', side_effect=launch), patch.object(child, 'terminate', side_effect=terminate):
            with self.assertRaisesRegex(ValueError, 'socket_count'):
                own.bounded(['/mock/DreamDaemon'], self.stage, 10, 4096, self.root, {}, 8, observer=self.observer)
        report = json.loads((self.stage / 'process.json').read_text())
        self.assertEqual(report['startup']['rejection']['decision']['socket_count'], 2)
        self.assertEqual(report['ownership'], 'reaped')
        self.assertEqual(child.signals, [('terminate', id(child))])

    def test_oversize_diagnostic_falls_back_without_masking_rejection(self):
        base.publish(self.stage, 'ready'); self.proc.inodes.add(1235)
        self.proc.last_census = {'padding': 'x' * 40000}  # Fault injection only.
        record = self.reject('owned_socket_count')
        self.assertEqual(record['socket_count'], 2)
        self.assertEqual(self.observer.record['rejection_capture_error'], 'ValueError')
        self.assertLess(len(json.dumps(self.observer.evidence())), 4096)

    def test_wrong_port_records_exact_observed_value_without_admission(self):
        base.publish(self.stage, 'ready', world_port=0)
        record = self.reject('world_port')
        self.assertEqual(record['receipt']['observed_fields']['world_port'], 0)
        self.assertFalse(record['receipt']['world_port_matches'])
        self.assertFalse((self.stage / 'startup-close.ack').exists())

    def test_duplicate_join_evidence_is_explicitly_truncated_and_bounded(self):
        base.publish(self.stage, 'ready'); proc = self.kernel_proc()
        entries = [SimpleNamespace(name='3', path='/mock/fd/3')]
        def read(path, cap): return HEADER + (row() * 500 if path.name == 'tcp' else b'')
        with patch.object(startup.os, 'scandir', return_value=Scan(entries)), patch.object(startup.os, 'readlink', return_value='socket:[1234]'), patch.object(proc, 'read', side_effect=read):
            record = self.reject('owned_socket_unmapped_or_duplicate')
        self.assertEqual(record['decision']['matching_rows_count'], 500)
        self.assertEqual(len(record['decision']['matching_rows']), 16)
        self.assertTrue(record['decision']['matching_rows_truncated'])
        table = record['proc_census']['tables']['tcp']
        self.assertEqual(len(table['matching_rows']), 4)
        self.assertTrue(table['matching_rows_truncated'])

    def test_identity_change_after_census_preserves_observed_join(self):
        base.publish(self.stage, 'ready')
        with patch.object(self.proc, 'identity', side_effect=[900, 901]): record = self.reject('owned_identity_changed')
        self.assertEqual(record['point'], 'tick_identity_after')
        self.assertEqual((record['decision']['socket_count'], record['decision']['observed_starttime_ticks']), (1, 901))

    def test_unconfirmed_cleanup_retains_decision_without_final_io(self):
        own.ACTIVE = None; own.PENDING.clear()
        self.addCleanup(lambda: (setattr(own, 'ACTIVE', None), own.PENDING.clear()))
        self.stage = self.root / 'unconfirmed-case'; self.stage.mkdir()
        self.observer = startup.StartupObserver(self.stage, '/unqueried/mock/DreamDaemon', 'fixture_generator', 'a' * 64, proc=self.proc, nonce='a' * 32)
        child = base.FakeChild(self.stage, self.proc); child.term_exits = child.kill_exits = False
        def launch(*args, **kwargs):
            base.publish(self.stage, 'ready'); self.proc.inodes.clear(); self.proc.rows.clear()
            return child
        with patch.object(own.subprocess, 'Popen', side_effect=launch), patch.object(startup, 'closed_marker_snapshot', side_effect=AssertionError('No final marker read')), patch.object(own, 'snapshot', side_effect=AssertionError('No live log read')):
            with self.assertRaisesRegex(ValueError, 'socket_count'):
                own.bounded(['/mock/DreamDaemon'], self.stage, 10, 4096, self.root, {}, 8, observer=self.observer)
        report = json.loads((self.stage / 'process.json').read_text())
        self.assertEqual(report['ownership'], 'retained')
        self.assertEqual(report['startup']['rejection']['decision']['socket_count'], 0)
        self.assertIs(own.ACTIVE, child)


if __name__ == '__main__': unittest.main()
