"""Offline process-contract tests: Popen is always mocked; no SDK is used.

The JSON cases describe future native refusal tests. These tests validate their
construction, not DM behavior or genuine savefile compatibility.
"""
import copy
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

NATIVE = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('compile_bridge_only', NATIVE/'compile_bridge_only.py')
bridge = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(bridge)


class Clock:
    def __init__(self):
        self.value = 0.0

    def monotonic(self):
        return self.value

    def sleep(self, seconds):
        self.value += seconds


class OwnedChild:
    def __init__(self, clock):
        self.clock = clock
        self.code = None
        self.signals = []
        self.term_exits = True
        self.kill_exits = True
        self.poll_error = None
        self.signal_error = None

    def poll(self):
        if self.poll_error:
            raise self.poll_error
        return self.code

    def terminate(self):
        self.signals.append(('terminate', self.clock.value))
        if self.signal_error:
            raise self.signal_error
        if self.term_exits:
            self.code = -15

    def kill(self):
        self.signals.append(('kill', self.clock.value))
        if self.signal_error:
            raise self.signal_error
        if self.kill_exits:
            self.code = -9


class CompilerContract(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='save-bridge-mocked-')
        self.addCleanup(self.temp.cleanup)
        self.stage = Path(self.temp.name)
        self.clock = Clock()
        self.child = OwnedChild(self.clock)
        self.calls = 0
        silent_print = patch('builtins.print')
        silent_print.start()
        self.addCleanup(silent_print.stop)
        bridge.PENDING.clear()
        self.addCleanup(bridge.PENDING.clear)
        for target, value in [('time.monotonic', self.clock.monotonic),
                              ('time.sleep', self.clock.sleep),
                              ('subprocess.Popen', self.launch)]:
            mock = patch.object(bridge.time if target.startswith('time.') else bridge.subprocess,
                                target.split('.')[1], side_effect=value)
            mock.start()
            self.addCleanup(mock.stop)

    def launch(self, argv, **kwargs):
        self.calls += 1
        self.assertEqual(argv[-1], 'save_bridge.dme')
        self.assertEqual(kwargs['cwd'], self.stage)
        self.assertEqual(kwargs['stdin'], bridge.subprocess.DEVNULL)
        kwargs['stdout'].write(b'516.1688\n0 errors, 0 warnings\n')
        kwargs['stdout'].flush()
        # Fake bytes, never a compiler output or a genuine DMB fixture.
        (self.stage/'save_bridge.dmb').write_bytes(b'mocked-image')
        return self.child

    def run_compile(self):
        return bridge.compile_once(self.stage/'compiler/DreamMaker', self.stage)

    def record(self):
        return json.loads((self.stage/'compile-result.json').read_text())

    def test_successful_exit_snapshots_only_after_confirmation(self):
        self.child.code = 0
        result = self.run_compile()
        self.assertEqual(result['status'], 'compile_passed')
        self.assertTrue(result['exit_confirmed'])
        self.assertEqual(self.child.signals, [])
        self.assertEqual(result['logs']['compiler.stdout']['kind'], 'complete_snapshot')

    def test_stat_failure_terminates_and_preserves_original_error(self):
        error = FileNotFoundError('synthetic stat fault')
        original_stat = Path.stat

        def failing_stat(path, *args, **kwargs):
            if path.name == 'compiler.stdout':
                raise error
            return original_stat(path, *args, **kwargs)

        with patch.object(Path, 'stat', failing_stat):
            with self.assertRaises(FileNotFoundError) as caught:
                self.run_compile()
        self.assertIs(caught.exception, error)
        self.assertEqual(self.child.signals[0][0], 'terminate')
        self.assertTrue(self.record()['exit_confirmed'])

    def test_enumeration_failure_force_kills_term_resistant_child(self):
        error = OSError('synthetic enumeration fault')
        self.child.term_exits = False
        with patch.object(Path, 'rglob', side_effect=error):
            with self.assertRaises(OSError) as caught:
                self.run_compile()
        self.assertIs(caught.exception, error)
        self.assertEqual([x[0] for x in self.child.signals], ['terminate', 'kill'])
        self.assertLessEqual(self.clock.value, 60)
        self.assertEqual(self.record()['exit_code'], -9)

    def test_late_fault_cannot_restart_cleanup_deadline(self):
        error = OSError('synthetic late fault')
        self.child.term_exits = False

        def late_fault(*args):
            self.clock.value = 59.99
            raise error

        with patch.object(Path, 'rglob', side_effect=late_fault):
            with self.assertRaises(OSError):
                self.run_compile()
        self.assertLessEqual(self.clock.value, 60)
        self.assertEqual(self.child.signals[-1][0], 'kill')

    def test_delayed_term_exit_is_confirmed_within_existing_grace(self):
        self.child.term_exits = False
        original_poll = self.child.poll

        def delayed_poll():
            if self.child.signals and self.clock.value >= .1:
                self.child.code = -15
            return original_poll()

        with patch.object(self.child, 'poll', side_effect=delayed_poll):
            code, error = bridge.finish_owned_compiler(self.child, 60)
        self.assertEqual(code, -15)
        self.assertIsNone(error)
        self.assertEqual([x[0] for x in self.child.signals], ['terminate'])
        self.assertLess(self.clock.value, .25)

    def test_unconfirmed_exit_retains_child_and_never_reads_live_logs(self):
        self.child.term_exits = self.child.kill_exits = False

        def active_size_check(stage):
            self.assertEqual(self.child.signals, [], 'Final size scan attempted after unconfirmed cleanup')
            return False

        with patch.object(bridge, 'snapshot') as snapshot, \
             patch.object(bridge, 'output_limit_exceeded', side_effect=active_size_check):
            result = self.run_compile()
        snapshot.assert_not_called()
        self.assertEqual(result['fault'], 'owned_compiler_exit_unconfirmed')
        self.assertIsNone(result['exit_code'])
        self.assertNotIn('dmb_sha256', result)
        self.assertEqual(bridge.PENDING, [self.child])
        self.assertTrue(all(x['sha256_snapshot'] is None for x in result['logs'].values()))
        self.assertLessEqual(self.clock.value, 60)
        with self.assertRaisesRegex(RuntimeError, 'unconfirmed'):
            self.run_compile()
        self.assertEqual(self.calls, 1)

    def test_poll_and_signal_failures_keep_original_error_and_ownership(self):
        error = OSError('synthetic poll fault')
        self.child.poll_error = error
        self.child.signal_error = OSError('synthetic signal fault')
        with patch.object(bridge, 'snapshot') as snapshot:
            with self.assertRaises(OSError) as caught:
                self.run_compile()
        self.assertIs(caught.exception, error)
        snapshot.assert_not_called()
        self.assertEqual(bridge.PENDING, [self.child])
        self.assertFalse(self.record()['exit_confirmed'])
        self.assertLessEqual(self.clock.value, 60)

    def test_interrupt_also_cleans_up_before_reraising(self):
        error = KeyboardInterrupt('synthetic interruption')
        with patch.object(Path, 'rglob', side_effect=error):
            with self.assertRaises(KeyboardInterrupt) as caught:
                self.run_compile()
        self.assertIs(caught.exception, error)
        self.assertTrue(self.record()['exit_confirmed'])

    def test_cleanup_clock_and_sleep_errors_still_attempt_kill(self):
        self.child.term_exits = False
        error = OSError('synthetic clock fault')
        with patch.object(bridge.time, 'monotonic', side_effect=error):
            code, observed = bridge.finish_owned_compiler(self.child, 60)
        self.assertIs(observed, error)
        self.assertEqual(code, -9)
        self.child.code = None
        self.child.signals.clear()
        with patch.object(bridge.time, 'sleep', side_effect=error):
            code, observed = bridge.finish_owned_compiler(self.child, 60)
        self.assertIs(observed, error)
        self.assertEqual(code, -9)

    def test_original_error_survives_snapshot_and_record_write_failures(self):
        error = OSError('synthetic original error')
        with patch.object(Path, 'rglob', side_effect=error), \
             patch.object(bridge, 'snapshot', side_effect=OSError('snapshot error')), \
             patch.object(Path, 'write_text', side_effect=OSError('record error')):
            with self.assertRaises(OSError) as caught:
                self.run_compile()
        self.assertIs(caught.exception, error)
        self.assertEqual(self.child.code, -15)

    def test_oversized_closed_log_has_capped_snapshot(self):
        self.child.code = 0
        launch = self.launch

        def oversized(*args, **kwargs):
            result = launch(*args, **kwargs)
            kwargs['stdout'].write(b'x' * (bridge.LOG_CAP + 64))
            kwargs['stdout'].flush()
            return result

        with patch.object(bridge.subprocess, 'Popen', side_effect=oversized):
            result = self.run_compile()
        self.assertEqual(result['status'], 'compile_failed')
        self.assertEqual(result['fault'], 'output_limit')
        self.assertEqual(result['logs']['compiler.stdout']['captured_bytes'], bridge.LOG_CAP)
        self.assertEqual(result['logs']['compiler.stdout']['kind'], 'bounded_prefix')

    def test_active_output_limit_cleans_up_owned_child(self):
        launch = self.launch

        def oversized(*args, **kwargs):
            result = launch(*args, **kwargs)
            kwargs['stderr'].write(b'x' * (bridge.LOG_CAP + 1))
            kwargs['stderr'].flush()
            return result

        with patch.object(bridge.subprocess, 'Popen', side_effect=oversized):
            result = self.run_compile()
        self.assertEqual(result['fault'], 'output_limit')
        self.assertTrue(result['exit_confirmed'])
        self.assertEqual(self.child.signals[0][0], 'terminate')

    def test_completed_child_over_limit_resource_is_rejected(self):
        self.child.code = 0
        launch = self.launch

        def final_resource(*args, **kwargs):
            result = launch(*args, **kwargs)
            (self.stage/'save_bridge.rsc').write_bytes(b'x' * 33)
            return result

        with patch.object(bridge, 'IMAGE_CAP', 32), \
             patch.object(bridge.subprocess, 'Popen', side_effect=final_resource):
            result = self.run_compile()
        self.assertEqual(result['status'], 'compile_failed')
        self.assertEqual(result['fault'], 'output_limit')
        self.assertEqual(result['exit_code'], 0)
        self.assertNotIn('dmb_sha256', result)
        self.assertEqual(self.child.signals, [])

    def test_completed_child_over_limit_stage_is_rejected(self):
        self.child.code = 0
        launch = self.launch
        original_stat = Path.stat

        def final_stage(*args, **kwargs):
            result = launch(*args, **kwargs)
            (self.stage/'oversize-placeholder').write_bytes(b'x')
            return result

        def final_stat(path, *args, **kwargs):
            value = original_stat(path, *args, **kwargs)
            if path.name == 'oversize-placeholder':
                values = list(value)
                values[6] = 33554433  # Simulated size; only one actual byte.
                return type(value)(values)
            return value

        with patch.object(Path, 'stat', final_stat), \
             patch.object(bridge.subprocess, 'Popen', side_effect=final_stage):
            result = self.run_compile()
        self.assertEqual(result['status'], 'compile_failed')
        self.assertEqual(result['fault'], 'output_limit')
        self.assertEqual(result['exit_code'], 0)
        self.assertNotIn('dmb_sha256', result)
        self.assertEqual(self.child.signals, [])

    def test_completed_child_within_limits_resource_and_stage_pass(self):
        self.child.code = 0
        launch = self.launch

        def final_resource(*args, **kwargs):
            result = launch(*args, **kwargs)
            (self.stage/'save_bridge.rsc').write_bytes(b'x' * 32)
            return result

        with patch.object(bridge, 'IMAGE_CAP', 32), \
             patch.object(bridge.subprocess, 'Popen', side_effect=final_resource):
            result = self.run_compile()
        self.assertEqual(result['status'], 'compile_passed')
        self.assertIsNone(result['fault'])
        self.assertIn('dmb_sha256', result)
        self.assertEqual(self.child.signals, [])

    def test_final_size_scan_error_is_fail_closed(self):
        self.child.code = 0
        error = OSError('synthetic final stage scan error')
        with patch.object(Path, 'rglob', side_effect=error):
            with self.assertRaises(OSError) as caught:
                self.run_compile()
        self.assertIs(caught.exception, error)
        self.assertEqual(self.record()['status'], 'compile_failed')
        self.assertEqual(self.record()['fault'], 'output_check_error')
        self.assertTrue(self.record()['exit_confirmed'])
        self.assertNotIn('dmb_sha256', self.record())
        self.assertEqual(self.child.signals, [])


class RefusalFixtureContract(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.packet = json.loads((NATIVE/'tests/bridge_refusals.json').read_text())

    def test_all_malformed_elements_and_operation_types_have_explicit_oracles(self):
        cases = self.packet['element_cases']
        self.assertEqual({(x['target'], x['name']) for x in cases},
                         {(target, name) for target in ('lists', 'roots', 'items')
                          for name in ('null', 'number', 'text')})
        for case in cases:
            graph = copy.deepcopy(self.packet['valid_graph'])
            target = graph['lists'][0]['items'] if case['target'] == 'items' else graph[case['target']]
            target.append(case['append'])
            self.assertNotIsInstance(target[-1], dict)
            self.assertGreater(len(target), 1)
            self.assertEqual(case['expected_status'], 'rejected')
        operations = self.packet['operation_cases']
        self.assertEqual({x['name'] for x in operations},
                         {'null', 'number', 'list', 'object', 'unknown', 'uppercase', 'long_text', 'oversized_request'})
        for case in operations:
            self.assertEqual(case['expected_operation'], 'unknown')
            self.assertEqual(case['expected_status'], 'rejected')

    def test_escape_expansion_recipe_exceeds_receipt_cap_with_bounded_leaves(self):
        recipe = self.packet['oversized_decode']
        leaves = [{'name': name, 'value': {'tag': 'text', 'value': '\x01' * recipe['text_characters']}}
                  for name in recipe['native_leaf_paths']]
        graph = {'schema': self.packet['valid_graph']['schema'], 'roots': leaves,
                 'objects': [], 'lists': []}
        self.assertGreater(len(json.dumps(graph, ensure_ascii=True).encode()), bridge.LOG_CAP)
        self.assertLessEqual(recipe['text_characters'], 65536)
        self.assertEqual(recipe['expected_operation'], 'decode')
        self.assertEqual(recipe['expected_status'], 'rejected')


if __name__ == '__main__':
    unittest.main()
