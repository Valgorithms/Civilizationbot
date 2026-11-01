"""Offline controls only. Every transport is an in-memory fake, never BYOND."""
import copy
import ast
import json
from pathlib import Path
import sys
import unittest
from unittest.mock import Mock
from types import SimpleNamespace
import tempfile
import time
sys.path.insert(0, str(Path(__file__).resolve().parent))
import suite
import negative_oracles


class FakeTransport:
    def __init__(self, change=None):
        self.change = change
        self.jobs = []

    def __call__(self, name, image, request, inputs):
        self.jobs.append((name, image, copy.deepcopy(request), inputs))
        result = {'exit_confirmed': True, 'exit_code': 0, 'input_unchanged': True, 'files': {},
                  'logs': {n: {'text': '', 'truncated': False} for n in ['stdout', 'stderr', 'native.log']}}
        fixture = {'schema': 'civilizationbot.fixture-receipt.v1', 'status': 'ok', 'runtime': suite.RUNTIME}
        if name == 'generate':
            result['receipt'] = fixture
            result['files'] = {f'fixture-{n}.sav': b'FAKE-ONLY-' + n.encode() for n in ['v2', 'v3', 'wrong-version', 'unknown-field', 'extra-buffer', 'resource', 'instance', 'missing-type', 'bad-ref', 'unknown-kind']}
        elif name.endswith('-native-oracle'):
            result['receipt'] = fixture
        else:
            operation = request['operation'] if request['operation'] in ['decode', 'encode'] else 'unknown'
            rejected = name in ['unknown-field', 'extra-buffer', 'resource', 'instance', *[n for n, _, _ in suite.bad_requests()]]
            graph = []
            if not rejected:
                version = 2 if name.startswith('v2-') else 3
                graph = suite.expected(version, 6 if name.endswith(('-encode', '-fresh-decode')) else 5)
                if name in negative_oracles.REASONS:
                    graph = negative_oracles.graph(name)
            result['receipt'] = {'protocol': suite.PROTOCOL, 'profile': suite.PROFILE, 'source': suite.SOURCE,
                                 'runtime': suite.RUNTIME, 'operation': operation, 'status': 'rejected' if rejected else 'ok', 'graph': graph}
            if name.endswith('-encode'):
                result['files'] = {'output.sav': b'FAKE-CANDIDATE'}
        if self.change:
            self.change(name, result)
        return result


class SuiteTests(unittest.TestCase):
    def test_finite_selection_and_fresh_readers(self):
        transport = FakeTransport()
        receipt = suite.run_suite(transport)
        self.assertEqual(len(receipt['jobs']), 25)
        self.assertEqual([j[0] for j in transport.jobs[:9]], ['generate', 'v2-decode', 'v2-encode', 'v2-fresh-decode', 'v2-native-oracle', 'v3-decode', 'v3-encode', 'v3-fresh-decode', 'v3-native-oracle'])
        self.assertIn('not exercised', receipt['php_part_acceptance'])

    def fail_change(self, target, field, value):
        def change(name, result):
            if name == target:
                result[field] = value
        with self.assertRaises(ValueError):
            suite.run_suite(FakeTransport(change))

    def test_unconfirmed_exit_not_accepted(self):
        self.fail_change('v2-decode', 'exit_confirmed', False)

    def test_nonzero_exit_not_accepted(self):
        self.fail_change('v2-decode', 'exit_code', 2)

    def test_modified_input_not_accepted(self):
        self.fail_change('v2-decode', 'input_unchanged', False)

    def test_success_receipt_with_runtime_error_refused(self):
        logs = {n: {'text': '', 'truncated': False} for n in ['stdout', 'stderr', 'native.log']}
        logs['native.log']['text'] = 'runtime error: synthetic control'
        self.fail_change('v2-decode', 'logs', logs)

    def test_unknown_and_missing_diagnostics_refused(self):
        self.fail_change('v2-decode', 'logs', {})
        logs = {n: {'text': '', 'truncated': False} for n in ['stdout', 'stderr', 'native.log']}
        logs['stdout']['text'] = 'unreviewed startup output'
        self.fail_change('v2-decode', 'logs', logs)

    def test_export_metadata_not_trusted(self):
        def change(name, result):
            if name == 'v2-encode':
                result['receipt']['source'] = 'wrong-source'
        with self.assertRaises(ValueError):
            suite.run_suite(FakeTransport(change))

    def test_export_fresh_reader_score_loss_refused(self):
        def change(name, result):
            if name == 'v2-fresh-decode':
                result['receipt']['graph'] = suite.expected(2, 5)
        with self.assertRaisesRegex(ValueError, 'fresh_graph_mismatch'):
            suite.run_suite(FakeTransport(change))

    def test_independent_native_oracle_refusal_stops(self):
        def change(name, result):
            if name == 'v2-native-oracle':
                result['receipt']['status'] = 'rejected'
        with self.assertRaises(ValueError):
            suite.run_suite(FakeTransport(change))

    def test_strict_json_duplicate_nonfinite_and_size(self):
        for raw in [b'{"a":1,"a":2}', b'{"a":NaN}', b'x' * 1048577]:
            with self.assertRaises(ValueError):
                suite.parse_json(raw)

    def test_negative_cases_require_exact_wrong_semantics(self):
        for name in negative_oracles.REASONS:
            def change(job, result):
                if job == name:
                    # Still differs from both positives, but it is not the
                    # declared malformed case; the old inequality gate passed.
                    result['receipt']['graph'] = suite.expected(3, 6)
            with self.subTest(name=name), self.assertRaisesRegex(ValueError, 'negative_fixture_semantics_mismatch'):
                suite.run_suite(FakeTransport(change))

    def test_startup_image_catalog_matches_exact_command(self):
        from diagnostics import classify_logs
        policy = suite.parse_json((suite.ROOT / 'diagnostics.json').read_bytes())
        def logs(line):
            return {'stdout': {'text': line, 'truncated': False}, 'stderr': {'text': '', 'truncated': False}, 'native.log': {'text': '', 'truncated': False}}
        self.assertEqual(classify_logs(logs('Loading save_bridge.dmb...'), policy)['status'], 'accepted')
        with self.assertRaisesRegex(ValueError, 'unclassified'):
            classify_logs(logs('Loading bridge.dmb...'), policy)

    def test_null_records_items_roots_rejected(self):
        for field in ['roots', 'lists']:
            graph = suite.expected(2, 5)
            graph[field].append(None)
            with self.assertRaises(ValueError):
                suite.canonical(graph)
        graph = suite.expected(2, 5)
        graph['lists'][0]['items'].append(None)
        with self.assertRaises(ValueError):
            suite.canonical(graph)

    def test_old_supervisor_hold_reproduced_without_process(self):
        source = (Path(__file__).resolve().parent / 'historical_poll_failure.py.txt').read_text()
        tree = ast.parse(source)
        function = next(n for n in tree.body if isinstance(n, ast.FunctionDef) and n.name == 'bounded')
        child = Mock()
        child.poll.side_effect = OSError('injected poll failure; no real child')
        namespace = {'PENDING': [], 'time': time, 'subprocess': SimpleNamespace(Popen=Mock(return_value=child), DEVNULL=-3)}
        exec(compile(ast.Module(body=[function], type_ignores=[]), '<held-supervisor-control>', 'exec'), namespace)
        with tempfile.TemporaryDirectory(prefix='sav-supervisor-zero-process-') as folder:
            root = Path(folder).resolve()
            self.assertTrue(root.is_relative_to(Path(tempfile.gettempdir()).resolve()))
            with self.assertRaisesRegex(OSError, 'injected poll failure'):
                namespace['bounded'](['not-executed'], root, 10, 4096, root, {})
        namespace['subprocess'].Popen.assert_called_once()
        child.terminate.assert_not_called()
        child.kill.assert_not_called()

    def test_aliases_cycles_ids_order_and_types(self):
        graph = {'schema': 'civilizationbot.save-graph.v1', 'objects': [],
                 'roots': [{'name': '/x', 'value': {'tag': 'list', 'id': 'a'}}, {'name': '/y', 'value': {'tag': 'list', 'id': 'a'}}],
                 'lists': [{'id': 'a', 'kind': 'list', 'items': [{'key': {'tag': 'text', 'value': 'self'}, 'associated': True, 'value': {'tag': 'list', 'id': 'a'}}]}]}
        renamed = json.loads(json.dumps(graph).replace('"a"', '"other"'))
        renamed['roots'].reverse()
        self.assertEqual(suite.canonical(graph), suite.canonical(renamed))
        changed = copy.deepcopy(graph)
        changed['lists'].append({'id': 'b', 'kind': 'list', 'items': []})
        changed['roots'][1]['value']['id'] = 'b'
        self.assertNotEqual(suite.canonical(graph), suite.canonical(changed))
        typed = suite.expected(3, 5)
        node = next(n for n in typed['lists'] if any(i['value']['tag'] == 'type' for i in n['items']))
        next(i for i in node['items'] if i['value']['tag'] == 'type')['value']['tag'] = 'text'
        self.assertNotEqual(suite.canonical(typed), suite.canonical(suite.expected(3, 5)))


if __name__ == '__main__':
    unittest.main()
