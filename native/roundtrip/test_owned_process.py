"""Zero-process supervisor controls: every Popen call returns a fake owned handle."""
import importlib.util
import io
import json
from pathlib import Path
import sys
import tempfile
import stat
from types import SimpleNamespace
import unittest
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parent))
import owned_process as own


class Clock:
    def __init__(self): self.value = 0.0
    def monotonic(self): return self.value
    def sleep(self, seconds): self.value += seconds


class Child:
    pid = 817263  # Synthetic identity; no OS process is queried or signalled.
    def __init__(self):
        self.code = None
        self.term_exits = self.kill_exits = True
        self.signals = []
    def poll(self): return self.code
    def terminate(self):
        self.signals.append(('terminate', id(self)))
        if self.term_exits: self.code = -15
    def kill(self):
        self.signals.append(('kill', id(self)))
        if self.kill_exits: self.code = -9


class OwnedProcessTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='native-owned-zero-process-')
        self.root = Path(self.temp.name).resolve()
        self.assertTrue(self.root.is_relative_to(Path(tempfile.gettempdir()).resolve()))
        self.addCleanup(self.temp.cleanup)
        self.stage = self.root / 'job'
        self.stage.mkdir()
        self.clock, self.child, self.unrelated = Clock(), Child(), Child()
        own.ACTIVE = None
        own.PENDING.clear()
        self.addCleanup(self.clear)
        for target, replacement in [('monotonic', self.clock.monotonic), ('sleep', self.clock.sleep)]:
            item = patch.object(own.time, target, side_effect=replacement)
            item.start(); self.addCleanup(item.stop)
        item = patch.object(own.subprocess, 'Popen', side_effect=self.launch)
        self.popen = item.start(); self.addCleanup(item.stop)

    def clear(self):
        own.ACTIVE = None
        own.PENDING.clear()

    def launch(self, argv, **kwargs):
        self.assertEqual(argv, ['/pinned/DreamDaemon', 'save_bridge.dmb'])
        self.assertEqual(kwargs['cwd'], self.stage)
        self.assertEqual(kwargs['stdin'], own.subprocess.DEVNULL)
        self.assertEqual(kwargs['env'], {'HOME': str(self.stage)})
        self.assertTrue(callable(kwargs['preexec_fn']))
        (self.stage / 'native.log').write_bytes(b'')
        return self.child

    def run_child(self):
        return own.bounded(['/pinned/DreamDaemon', 'save_bridge.dmb'], self.stage, 10, 4096, self.root, {'HOME': str(self.stage)}, 8)

    def record(self): return json.loads((self.stage / 'process.json').read_text())

    def assert_owned(self, signals):
        self.assertEqual(self.child.signals, [(s, id(self.child)) for s in signals])
        self.assertEqual(self.unrelated.signals, [])

    def test_success_no_signal_exact_pid_and_handle(self):
        self.child.code = 0
        result = self.run_child()
        self.assertEqual(result['record']['pid'], self.child.pid)
        self.assertEqual(result['record']['ownership'], 'reaped')
        self.assert_owned([])
        self.assertIsNone(own.ACTIVE)
        self.assertEqual(own.PENDING, [])

    def test_first_poll_exception_is_cleaned_with_shared_helper(self):
        error = OSError('injected first poll')
        original_poll = self.child.poll
        calls = 0
        def poll():
            nonlocal calls
            calls += 1
            if calls == 1: raise error
            return original_poll()
        with patch.object(self.child, 'poll', side_effect=poll), patch.object(own.shared, 'finish_owned_compiler', wraps=own.shared.finish_owned_compiler) as cleanup:
            with self.assertRaises(OSError) as caught: self.run_child()
        self.assertIs(caught.exception, error)
        self.assertIs(cleanup.call_args.args[0], self.child)
        self.assertEqual(cleanup.call_args.args[1], 10)
        self.assert_owned(['terminate'])
        self.assertEqual(self.record()['ownership'], 'reaped')

    def test_stat_failure_cleans_up(self):
        error = OSError('injected lstat')
        real = Path.lstat
        def lstat(path, *args, **kwargs):
            if path.name == 'stdout.log': raise error
            return real(path, *args, **kwargs)
        with patch.object(Path, 'lstat', lstat):
            with self.assertRaises(OSError) as caught: self.run_child()
        self.assertIs(caught.exception, error)
        self.assert_owned(['terminate'])

    def test_enumeration_failure_forces_term_resistant_owned_child(self):
        self.child.term_exits = False
        error = OSError('injected scandir')
        with patch.object(own.os, 'scandir', side_effect=error):
            with self.assertRaises(OSError) as caught: self.run_child()
        self.assertIs(caught.exception, error)
        self.assert_owned(['terminate', 'kill'])
        self.assertLessEqual(self.clock.value, 10)

    def test_keyboard_interrupt_cleans_before_reraising(self):
        error = KeyboardInterrupt('injected interrupt')
        with patch.object(own, 'check_outputs', side_effect=error):
            with self.assertRaises(KeyboardInterrupt) as caught: self.run_child()
        self.assertIs(caught.exception, error)
        self.assert_owned(['terminate'])

    def test_timeout_uses_original_deadline(self):
        self.child.term_exits = False
        with self.assertRaisesRegex(RuntimeError, 'timeout'): self.run_child()
        self.assert_owned(['terminate', 'kill'])
        self.assertLessEqual(self.clock.value, 10)
        self.assertEqual(self.record()['seconds_limit'], 10)

    def test_late_supervision_failure_does_not_extend_deadline(self):
        self.child.term_exits = False
        error = OSError('late scan')
        def late(*args):
            self.clock.value = 9.99
            raise error
        with patch.object(own, 'check_outputs', side_effect=late):
            with self.assertRaises(OSError) as caught: self.run_child()
        self.assertIs(caught.exception, error)
        self.assertLessEqual(self.clock.value, 10)
        self.assert_owned(['terminate', 'kill'])

    def test_popen_constructor_failure_no_unknown_pid_kill(self):
        error = OSError('launch failed without handle')
        with patch.object(own.subprocess, 'Popen', side_effect=error):
            with self.assertRaises(OSError) as caught: self.run_child()
        self.assertIs(caught.exception, error)
        self.assertEqual(self.record()['ownership'], 'not_acquired')
        self.assertIsNone(self.record()['pid'])
        self.assert_owned([])

    def test_second_stream_open_failure_no_child_started(self):
        error = OSError('second stream open')
        real = Path.open
        first = []
        def opened(path, *args, **kwargs):
            if path.name == 'stderr.log' and args == ('xb',): raise error
            result = real(path, *args, **kwargs)
            if path.name == 'stdout.log': first.append(result)
            return result
        with patch.object(Path, 'open', opened):
            with self.assertRaises(OSError) as caught: self.run_child()
        self.assertIs(caught.exception, error)
        self.popen.assert_not_called()
        self.assertTrue(first[0].closed)
        self.assert_owned([])

    def test_registration_failure_still_cleans_exact_returned_child(self):
        class BrokenRegistry(list):
            def append(self, _): raise MemoryError('injected registry failure')
        with patch.object(own, 'PENDING', BrokenRegistry()):
            with self.assertRaises(MemoryError): self.run_child()
        self.assert_owned(['terminate'])
        self.assertIsNone(own.ACTIVE)

    def test_pid_capture_failure_still_cleans_returned_child(self):
        class BrokenPid(Child):
            @property
            def pid(self): raise OSError('injected pid attribute failure')
        self.child = BrokenPid()
        with self.assertRaises(OSError): self.run_child()
        self.assert_owned(['terminate'])

    def test_unconfirmed_exit_retains_identity_and_never_reads_live_logs(self):
        self.child.term_exits = self.child.kill_exits = False
        def check(*args):
            self.assertEqual(self.child.signals, [], 'Read/scan after unconfirmed cleanup')
        with patch.object(own, 'check_outputs', side_effect=check), patch.object(own, 'snapshot') as snap:
            with self.assertRaisesRegex(RuntimeError, 'unconfirmed'): self.run_child()
        snap.assert_not_called()
        self.assertIs(own.ACTIVE, self.child)
        self.assertEqual(own.PENDING, [self.child])
        self.assertIsNone(self.record()['exit_code'])
        with self.assertRaisesRegex(RuntimeError, 'unconfirmed'): self.run_child()
        self.assertEqual(self.popen.call_count, 1)

    def test_cleanup_exception_retains_identity_and_original_error(self):
        original, cleanup = OSError('original scan'), RuntimeError('cleanup failed')
        with patch.object(own, 'check_outputs', side_effect=original), patch.object(own.shared, 'finish_owned_compiler', side_effect=cleanup), patch.object(own, 'snapshot') as snap:
            with self.assertRaises(OSError) as caught: self.run_child()
        self.assertIs(caught.exception, original)
        snap.assert_not_called()
        self.assertIs(own.ACTIVE, self.child)
        self.assertEqual(self.record()['cleanup_error'], 'RuntimeError')

    def test_log_read_failure_after_confirmed_exit_is_failure(self):
        self.child.code = 0
        error = OSError('closed log read')
        with patch.object(own, 'snapshot', side_effect=error):
            with self.assertRaises(OSError) as caught: self.run_child()
        self.assertIs(caught.exception, error)
        self.assertEqual(self.record()['status'], 'process_failed')
        self.assert_owned([])

    def test_record_write_failure_does_not_hide_original(self):
        original = OSError('original scan')
        with patch.object(own, 'check_outputs', side_effect=original), patch.object(Path, 'write_text', side_effect=RuntimeError('record failure')):
            with self.assertRaises(OSError) as caught: self.run_child()
        self.assertIs(caught.exception, original)
        self.assert_owned(['terminate'])

    def test_final_closed_output_check_is_required(self):
        self.child.code = 0
        error = OSError('late final output')
        with patch.object(own, 'check_outputs', side_effect=error):
            with self.assertRaises(OSError): self.run_child()
        self.assertEqual(self.record()['fault'], 'closed_output_error')
        self.assert_owned([])

    def test_capped_closed_snapshot_is_not_a_whole_file_hash(self):
        self.child.code = 0
        launch = self.launch
        def oversized(*args, **kwargs):
            result = launch(*args, **kwargs)
            kwargs['stdout'].write(b'x' * 4100); kwargs['stdout'].flush()
            return result
        with patch.object(own.subprocess, 'Popen', side_effect=oversized):
            with self.assertRaises(ValueError): self.run_child()
        self.assertEqual(self.record()['logs']['stdout.log']['kind'], 'bounded_prefix')
        self.assertEqual(self.record()['logs']['stdout.log']['captured_bytes'], 4096)

    def test_child_only_resource_limits_exact(self):
        fake = SimpleNamespace(RLIMIT_AS=1, RLIMIT_CPU=2, RLIMIT_FSIZE=3, RLIMIT_NOFILE=4, RLIMIT_CORE=5, setrlimit=Mock())
        with patch.dict(sys.modules, {'resource': fake}), patch.object(own.os, 'name', 'posix'):
            own.limits_for_child(8)
        self.assertEqual(fake.setrlimit.call_args_list, [((1, (1073741824, 1073741824)),), ((2, (8, 8)),), ((3, (8388608, 8388608)),), ((4, (64, 64)),), ((5, (0, 0)),)])

    def test_inventory_refuses_symlinks_without_following(self):
        class Entry:
            name = 'link'
            path = 'never-followed'
            def stat(self, *, follow_symlinks):
                self_follow = follow_symlinks
                if self_follow: raise AssertionError('Followed symlink')
                return SimpleNamespace(st_mode=stat.S_IFLNK, st_size=0)
        class Scan:
            def __enter__(self): return iter([Entry()])
            def __exit__(self, *_): pass
        with patch.object(own.os, 'scandir', return_value=Scan()):
            with self.assertRaisesRegex(ValueError, 'Symlink'):
                own.inventory(self.root, 67108864)

    def test_unconfirmed_safe_reports_contain_metadata_only(self):
        self.child.term_exits = self.child.kill_exits = False
        with patch.object(own, 'check_outputs'):
            with self.assertRaisesRegex(RuntimeError, 'unconfirmed'): self.run_child()
        reports = list((self.root / 'safe-reports').rglob('*'))
        self.assertEqual([p.name for p in reports if p.is_file()], ['process.json'])

    def test_clock_fault_after_launch_preserves_error_and_cleans(self):
        error = OSError('injected monitor clock fault')
        calls = 0
        def now():
            nonlocal calls
            calls += 1
            if calls == 2: raise error
            return self.clock.value
        with patch.object(own.time, 'monotonic', side_effect=now):
            with self.assertRaises(OSError) as caught: self.run_child()
        self.assertIs(caught.exception, error)
        self.assert_owned(['terminate'])


if __name__ == '__main__': unittest.main()
