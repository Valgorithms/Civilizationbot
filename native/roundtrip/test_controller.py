"""Controller integration controls with fake native outputs, no binary execution."""
import json
import hashlib
import io
from contextlib import nullcontext
from types import SimpleNamespace
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parent))
import native_controller as controller
import suite
from test_suite import FakeTransport


class ControllerTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='native-controller-zero-process-')
        self.root = Path(self.temp.name).resolve()
        self.assertTrue(self.root.is_relative_to(Path(tempfile.gettempdir()).resolve()))
        self.addCleanup(self.temp.cleanup)
        self.transport = controller.NativeTransport(self.root, self.root / 'pinned-bin', 1000000)
        self.transport.images = {'save_bridge': {'save_bridge.dmb': b'FAKE IMAGE'}, 'fixture_generator': {'fixture_generator.dmb': b'FAKE IMAGE', 'fixture_generator.rsc': b'FAKE RESOURCE'}}
        self.transport.image_hashes = {image: {name: hashlib.sha256(raw).hexdigest() for name, raw in files.items()} for image, files in self.transport.images.items()}
        self.fake = FakeTransport()
        guard = patch.object(controller.owned.subprocess, 'Popen', side_effect=AssertionError('Popen must remain mocked at transport boundary'))
        guard.start(); self.addCleanup(guard.stop)

    def observed(self, argv, stage, seconds, cap, cpu, observer=None):
        self.assertEqual((seconds, cap, cpu), (10, 4096, 8))
        self.assertIn('-safe', argv); self.assertNotIn('-trusted', argv)
        self.assertEqual(argv[0], str(self.root / 'pinned-bin/DreamDaemon'))
        self.assertEqual(argv[-2:], ['-log', 'native.log'])
        self.assertEqual(argv[2:8], ['-port', '25444', '-ports', '25444', '-ip', '127.0.0.1'])
        self.assertIsInstance(observer, controller.startup.StartupObserver)
        image = 'save_bridge' if argv[1] == 'save_bridge.dmb' else 'fixture_generator'
        request = json.loads((stage / ('request.json' if image == 'save_bridge' else 'fixture-request.json')).read_text())
        inputs = {name: (stage / name).read_bytes() for name in ['input.sav', 'candidate.sav'] if (stage / name).exists()}
        result = self.fake(stage.name.removeprefix('case-'), image, request, inputs)
        result['startup']['image_sha256'] = self.transport.image_hashes[image][image + '.dmb']
        for name, raw in result['files'].items(): (stage / name).write_bytes(raw)
        (stage / ('response.json' if image == 'save_bridge' else 'fixture-receipt.json')).write_text(json.dumps(result['receipt']))
        report = self.root / 'safe-reports' / stage.name
        report.mkdir(parents=True)
        record = {'exit_code': 0, 'exit_confirmed': True, 'seconds_limit': 10, 'pid': 817263, 'identity': 'mock-only', 'safe_report_directory': str(report), 'startup': result['startup']}
        self.transport.processes.append(record)
        return {'record': record, 'captured_logs': {'stdout.log': b'', 'stderr.log': b'', 'native.log': result['logs']['native.log']['text'].encode()}}

    def test_case_harness_uses_adapter_twenty_five_exact_safe_jobs(self):
        with patch.object(self.transport, 'process', side_effect=self.observed), patch.object(controller.startup, 'ProcReader'):
            result = suite.run_suite(self.transport)
        self.assertEqual(len(result['jobs']), 25)
        self.assertEqual(len(self.transport.processes), 25)
        self.assertEqual(len(self.fake.jobs), 25)

    def test_compiler_arguments_pins_and_budgets(self):
        self.transport.images = {}
        calls = []
        def compile_fake(argv, stage, seconds, cap, cpu):
            calls.append((seconds, cap, cpu))
            self.assertEqual(argv[1:3], ['-max_errors', '0'])
            (stage / Path(argv[-1]).with_suffix('.dmb')).write_bytes(b'FAKE IMAGE')
            report = self.root / 'safe-reports' / stage.name
            report.mkdir(parents=True)
            return {'record': {'exit_code': 0, 'safe_report_directory': str(report)}, 'captured_logs': {'stdout.log': b'516.1688\n0 errors, 0 warnings\n', 'stderr.log': b''}}
        with patch.object(self.transport, 'process', side_effect=compile_fake):
            self.transport.compile('save_bridge')
            self.transport.compile('fixture_generator')
        self.assertEqual(calls, [(60, 1048576, 55)] * 2)
        self.assertEqual(set(self.transport.images), {'save_bridge', 'fixture_generator'})

    def test_unconfirmed_failure_never_reads_receipt_or_native_output(self):
        with patch.object(self.transport, 'process', side_effect=RuntimeError('owned_child_exit_unconfirmed')), patch.object(controller, 'closed_bytes') as read, patch.object(controller.startup, 'ProcReader'):
            with self.assertRaisesRegex(RuntimeError, 'unconfirmed'):
                self.transport('v2-decode', 'save_bridge', suite.bridge_request('decode'), {'input.sav': b'FAKE ONLY'})
        read.assert_not_called()

    def test_fresh_home_tmp_and_allowlisted_environment(self):
        env = self.transport.env(self.root)
        self.assertEqual(set(env), {'PATH', 'HOME', 'TMPDIR', 'LD_LIBRARY_PATH'})
        self.assertEqual(env['HOME'], str(self.root / 'home'))
        self.assertEqual(env['TMPDIR'], str(self.root / 'tmp'))
        self.assertTrue((self.root / 'home').is_dir() and (self.root / 'tmp').is_dir())

    def test_closed_file_oversize_checks(self):
        file = self.root / 'oversized'
        file.write_bytes(b'12345')
        with self.assertRaises(ValueError): controller.closed_bytes(file, 4)
        self.assertEqual(controller.closed_bytes(file, 5), b'12345')

    def test_archive_pin_rejects_wrong_bytes_before_extraction(self):
        archive = self.root / 'not-archive'
        archive.write_bytes(b'fake archive, no program')
        pin = json.loads((controller.ROOT / 'toolchain-members.json').read_text())
        with self.assertRaises(ValueError): controller.extract_pinned(archive, self.root / 'never-extracted', pin)
        self.assertFalse((self.root / 'never-extracted').exists())

    def test_member_pin_inventory_is_exact_both_executables_and_both_libraries(self):
        pin = json.loads((controller.ROOT / 'toolchain-members.json').read_text())
        self.assertEqual(set(pin['members']), {'byond/bin/DreamMaker', 'byond/bin/DreamDaemon', 'byond/bin/libbyond.so', 'byond/bin/libext.so'})
        self.assertEqual(pin['members']['byond/bin/DreamDaemon']['sha256'], '0047b52e042647ea3a496b368ab829672917052a130cb67646c515a01c20188a')
        self.assertTrue(all(len(x['sha256']) == 64 and x['elf_class'] == 32 for x in pin['members'].values()))

    def test_tampered_executable_member_rejected_before_any_extraction(self):
        pin = json.loads((controller.ROOT / 'toolchain-members.json').read_text())
        raw_archive = b'x' * 4887281  # Fake archive stream; ZipFile is mocked.
        infos = [SimpleNamespace(filename=n, file_size=r['bytes'], external_attr=0) for n, r in pin['members'].items()]
        fake_zip = SimpleNamespace(infolist=lambda: infos, getinfo=lambda n: next(i for i in infos if i.filename == n),
                                   open=lambda i: io.BytesIO(b'\x7fELF\x01' + b'\0' * (i.file_size - 5)))
        original_digest = controller.digest
        with patch.object(controller, 'closed_bytes', return_value=raw_archive), \
             patch.object(controller, 'digest', side_effect=lambda b: pin['archive_sha256'] if b is raw_archive else original_digest(b)), \
             patch.object(controller.zipfile, 'ZipFile', return_value=nullcontext(fake_zip)):
            with self.assertRaisesRegex(ValueError, 'digest/ELF'):
                controller.extract_pinned(self.root / 'mock.zip', self.root / 'no-extraction', pin)
        self.assertFalse((self.root / 'no-extraction').exists())

    def test_safe_archive_never_scans_unconfirmed_raw_job_logs(self):
        safe = self.root / 'safe-reports'
        safe.mkdir()
        (safe / 'unknown-process.json').write_text('{"exit_confirmed":false,"log_hash":null}')
        raw = self.root / 'case-unconfirmed'
        raw.mkdir()
        (raw / 'stdout.log').write_bytes(b'DO NOT READ LIVE LOG')
        original = controller.closed_bytes
        def only_safe(path, cap):
            self.assertTrue(path.resolve().is_relative_to(safe))
            return original(path, cap)
        with patch.object(controller, 'closed_bytes', side_effect=only_safe):
            controller.pack_safe_reports(self.root)
        with controller.zipfile.ZipFile(self.root / 'safe-evidence.zip') as archive:
            self.assertEqual(archive.namelist(), ['unknown-process.json'])


if __name__ == '__main__': unittest.main()
