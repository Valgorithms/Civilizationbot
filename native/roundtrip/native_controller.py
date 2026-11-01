"""Review-first synthetic native controller. Default inspect never starts tools.

--execute is limited to the proposed manual GitHub Linux binding and reviewed
manifest identity. It is not invoked during preparation/offline verification.
"""
import argparse
from datetime import datetime, timezone
import hashlib
import json
import os
import re
from pathlib import Path
import shutil
import signal
import stat
import subprocess
import sys
import time
import zipfile

ROOT = Path(__file__).resolve().parent
sys.path.insert(0, str(ROOT))
import owned_process as owned
import suite


def digest(data): return hashlib.sha256(data).hexdigest()


def closed_bytes(path, cap):
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or not 0 <= info.st_size <= cap:
        raise ValueError('Closed file size/type refused')
    with path.open('rb') as source:
        data = source.read(cap + 1)
    if len(data) != info.st_size or len(data) > cap:
        raise ValueError('Closed file changed or exceeded cap')
    return data


def verify_manifest(expected_sha):
    raw = closed_bytes(ROOT / 'manifest.json', 1048576)
    if expected_sha is not None and digest(raw) != expected_sha:
        raise ValueError('Unreviewed packet manifest')
    manifest = suite.parse_json(raw)
    paths = set()
    for name, record in manifest['files'].items():
        path = ROOT / name
        if not path.resolve().is_relative_to(ROOT.resolve()) or path.is_symlink():
            raise ValueError('Unsafe packet path')
        data = closed_bytes(path, 1048576)
        if digest(data) != record['sha256'] or len(data) != record['bytes']:
            raise ValueError('Packet differs from reviewed source')
        paths.add(name)
    actual = {p.relative_to(ROOT).as_posix() for p in ROOT.rglob('*') if p.is_file()}
    if actual != paths | {'manifest.json'}:
        raise ValueError('Unexpected packet member')
    return digest(raw)


def extract_pinned(archive, destination, pin):
    """Extract only four hash-pinned ELF members; no archive scripts are run."""
    data = closed_bytes(archive, 4887281)
    if len(data) != 4887281 or digest(data) != pin['archive_sha256']:
        raise ValueError('Toolchain archive differs')
    members = pin['members']
    if set(members) != {'byond/bin/DreamMaker', 'byond/bin/DreamDaemon', 'byond/bin/libbyond.so', 'byond/bin/libext.so'}:
        raise ValueError('Unreviewed executable/library inventory')
    with zipfile.ZipFile(archive) as z:
        infos = z.infolist()
        if len(infos) > 1000 or sum(x.file_size for x in infos) > 134217728:
            raise ValueError('Archive extraction limit')
        seen = set()
        for item in infos:
            name = Path(item.filename)
            if name.is_absolute() or '..' in name.parts or '\\' in item.filename or stat.S_ISLNK(item.external_attr >> 16) or item.filename in seen:
                raise ValueError('Unsafe archive member')
            seen.add(item.filename)
        for name, record in members.items():
            info = z.getinfo(name)
            if info.file_size != record['bytes'] or not 0 < info.file_size <= 8388608:
                raise ValueError('Pinned member size mismatch')
            with z.open(info) as source: raw = source.read(8388609)
            if len(raw) != record['bytes'] or digest(raw) != record['sha256'] or raw[:5] != b'\x7fELF\x01':
                raise ValueError('Pinned member digest/ELF mismatch')
            target = destination / name
            target.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
            with target.open('xb') as output: output.write(raw)
            target.chmod(0o700)
    return destination / 'byond/bin'


class NativeTransport:
    def __init__(self, work, executable_dir, stop_at):
        self.work, self.bin, self.stop_at = work, executable_dir, stop_at
        self.images, self.processes = {}, []
        self.image_hashes = {}

    def env(self, stage):
        for name in ['home', 'tmp']: (stage / name).mkdir(mode=0o700)
        return {'PATH': os.defpath, 'HOME': str(stage / 'home'), 'TMPDIR': str(stage / 'tmp'), 'LD_LIBRARY_PATH': str(self.bin)}

    def process(self, argv, stage, seconds, cap, cpu):
        if time.monotonic() + seconds + 1 > self.stop_at:
            raise RuntimeError('Controller budget cannot cover next child')
        result = owned.bounded(argv, stage, seconds, cap, self.work, self.env(stage), cpu)
        self.processes.append(result['record'])
        return result

    def compile(self, name):
        source = ROOT / 'generator' if name == 'fixture_generator' else ROOT / 'merged'
        if name == 'save_bridge' and not source.is_dir(): source = ROOT.parent
        names = ['fixture_generator.dm', 'fixture_generator.dme', 'production_procedures.dm', 'fixture-resource.txt'] if name == 'fixture_generator' else ['save_bridge.dm', 'save_bridge.dme']
        stage = self.work / ('compile-' + name)
        stage.mkdir(mode=0o700)
        for item in names:
            raw = closed_bytes(source / item, 1048576)
            if name == 'save_bridge':
                binding = suite.parse_json(closed_bytes(ROOT / 'source-bindings.json', 1048576))
                if digest(raw) != binding['bridge'][item]: raise ValueError('Merged bridge source pin mismatch')
            with (stage / item).open('xb') as file: file.write(raw)
        observed = self.process([str(self.bin / 'DreamMaker'), '-max_errors', '0', name + '.dme'], stage, 60, 1048576, 55)
        text = (observed['captured_logs']['stdout.log'] + observed['captured_logs']['stderr.log']).decode('utf-8', errors='strict')
        if '516.1688' not in text or '0 errors, 0 warnings' not in text:
            raise ValueError('Compiler version/zero-warning gate failed')
        files = {}
        for suffix in ['.dmb', '.rsc']:
            file = stage / (name + suffix)
            if suffix == '.dmb' or file.exists():
                raw = closed_bytes(file, 8388608)
                if not raw: raise ValueError('Empty compiled image')
                files[file.name] = raw
        self.images[name] = files
        self.image_hashes[name] = {file: digest(raw) for file, raw in files.items()}
        report = Path(observed['record']['safe_report_directory'])
        (report / 'compiled-image-hashes.json').write_text(json.dumps(self.image_hashes[name], indent=2) + '\n')

    def __call__(self, name, image, request, inputs):
        if len([p for p in self.processes if p['seconds_limit'] == 10]) >= 25:
            raise ValueError('Finite native process count exceeded')
        stage = self.work / ('case-' + name)
        stage.mkdir(mode=0o700)
        for file, raw in self.images[image].items():
            with (stage / file).open('xb') as output: output.write(raw)
        if set(inputs) - {'input.sav', 'candidate.sav'}:
            raise ValueError('Unreviewed native input name')
        for file, raw in inputs.items():
            if not isinstance(raw, bytes) or len(raw) > 8388608: raise ValueError('Native input limit')
            with (stage / file).open('xb') as output: output.write(raw)
        request_name = 'request.json' if image == 'save_bridge' else 'fixture-request.json'
        raw_request = json.dumps(request, separators=(',', ':'), ensure_ascii=True).encode()
        if len(raw_request) > 1048576: raise ValueError('Native request byte limit')
        with (stage / request_name).open('xb') as output: output.write(raw_request)
        argv = [str(self.bin / 'DreamDaemon'), image + '.dmb', '-ip', '127.0.0.1', '-nowebclient', '-invisible', '-close', '-safe', '-nologdates', '-log', 'native.log']
        observed = self.process(argv, stage, 10, 4096, 8)
        # These reads happen only after the shared supervisor confirmed exit.
        unchanged = all(closed_bytes(stage / file, 8388608) == raw for file, raw in inputs.items())
        if any(closed_bytes(stage / file, 8388608) != raw for file, raw in self.images[image].items()):
            raise ValueError('Native image modified')
        receipt_name = 'response.json' if image == 'save_bridge' else 'fixture-receipt.json'
        receipt_raw = closed_bytes(stage / receipt_name, 1048576)
        report = Path(observed['record']['safe_report_directory'])
        with (report / receipt_name).open('xb') as output: output.write(receipt_raw)
        receipt = suite.parse_json(receipt_raw)
        logs = {('stdout' if key == 'stdout.log' else 'stderr' if key == 'stderr.log' else key):
                {'text': raw.decode('utf-8', errors='strict'), 'truncated': False} for key, raw in observed['captured_logs'].items()}
        files = {}
        if name == 'generate':
            names = ['v2', 'v3', 'wrong-version', 'unknown-field', 'extra-buffer', 'resource', 'instance', 'missing-type', 'bad-ref', 'unknown-kind']
            for stem in names:
                file = 'fixture-' + stem + '.sav'
                files[file] = closed_bytes(stage / file, 8388608)
            actual = {p.name for p in stage.glob('*.sav')}
            if actual != set(files): raise ValueError('Unexpected generated save inventory')
        elif (stage / 'output.sav').exists():
            files['output.sav'] = closed_bytes(stage / 'output.sav', 8388608)
        for file, raw in files.items():
            with (report / file).open('xb') as output: output.write(raw)
        (report / 'input-output-hashes.json').write_text(json.dumps({'inputs': {n: digest(v) for n, v in inputs.items()},
                                                                  'outputs': {n: digest(v) for n, v in files.items()},
                                                                  'inputs_unchanged': unchanged}, indent=2) + '\n')
        return {'exit_confirmed': True, 'exit_code': observed['record']['exit_code'], 'input_unchanged': unchanged,
                'logs': logs, 'receipt': receipt, 'files': files}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--execute', action='store_true')
    parser.add_argument('--approved-revision')
    parser.add_argument('--approved-manifest-sha256')
    parser.add_argument('--work-root', type=Path)
    args = parser.parse_args()
    if not args.execute:
        print(json.dumps({'state': 'inspect_only', 'native_processes': 0, 'runtime': '516.1688'}))
        return
    # This is the concrete proposed GitHub-hosted binding, never local fallback.
    if sys.platform != 'linux' or os.environ.get('GITHUB_ACTIONS') != 'true' or os.environ.get('GITHUB_REPOSITORY') != 'Valgorithms/Civilizationbot':
        raise ValueError('Reviewed GitHub Linux binding required')
    if os.environ.get('GITHUB_EVENT_NAME') != 'workflow_dispatch' or os.environ.get('GITHUB_RUN_ATTEMPT') != '1':
        raise ValueError('Manual first-attempt dispatch required')
    if not args.approved_revision or not re.fullmatch('[a-f0-9]{40}', args.approved_revision) or args.approved_revision != os.environ.get('GITHUB_SHA') or not args.approved_manifest_sha256:
        raise ValueError('Exact approved source/manifest required')
    checked_out = subprocess.check_output(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'], text=True, timeout=5).strip()
    if checked_out != args.approved_revision:
        raise ValueError('Hosted checkout revision mismatch')
    if datetime.now(timezone.utc).timestamp() + 900 > datetime(2026, 11, 1, 23, 46, 26, tzinfo=timezone.utc).timestamp():
        raise ValueError('Insufficient remaining approved window')
    packet_sha = verify_manifest(args.approved_manifest_sha256)
    def interrupted(_number, _frame):
        raise InterruptedError('Hosted controller interrupted')
    signal.signal(signal.SIGTERM, interrupted)
    if not args.work_root or args.work_root.exists() or args.work_root.resolve().is_relative_to(ROOT):
        raise ValueError('Fresh external runner staging required')
    work = args.work_root.absolute()
    work.mkdir(mode=0o700)
    (work / 'safe-reports').mkdir(mode=0o700)
    pin = suite.parse_json(closed_bytes(ROOT / 'toolchain-members.json', 1048576))
    if pin['archive_sha256'] != owned.shared.ARCHIVE_SHA256:
        raise ValueError('Toolchain pin differs from reviewed supervisor')
    stop_at = time.monotonic() + 600
    summary = {'state': 'failed', 'source_revision': args.approved_revision, 'packet_manifest_sha256': packet_sha,
               'runtime': '516.1688', 'php_part': 'private/unmerged; not exercised', 'owned_exit_unconfirmed': False,
               'archive_sha256': pin['archive_sha256'], 'toolchain_members': pin['members'], 'toolchain_verified': False}
    primary = None
    try:
        archive = work / 'pinned-byond.zip'
        owned.shared.fetch_archive(archive)
        binary_dir = extract_pinned(archive, work / 'toolchain', pin)
        summary['toolchain_verified'] = True
        transport = NativeTransport(work, binary_dir, stop_at)
        for image in ['save_bridge', 'fixture_generator']: transport.compile(image)
        result = suite.run_suite(transport)
        summary.update(state='synthetic_native_roundtrip_passed', case_result=result,
                       compiler_processes=2, native_processes=25, processes=transport.processes,
                       compiled_image_sha256=transport.image_hashes, toolchain_members=pin['members'])
    except BaseException as error:
        primary = error
        raise
    finally:
        summary['owned_exit_unconfirmed'] = owned.ACTIVE is not None or bool(owned.PENDING)
        summary['owned_launch_attempts'] = owned.SERIAL
        try:
            raw = json.dumps(summary, indent=2) + '\n'
            (work / 'summary.json').write_text(raw)
            (work / 'safe-reports/controller-summary.json').write_text(raw)
            pack_safe_reports(work)
        except BaseException:
            if primary is None: raise
    print(json.dumps({'state': summary['state'], 'native_processes': summary.get('native_processes'), 'php_part': summary['php_part']}))


def pack_safe_reports(work):
    """Never enumerate raw job dirs or read live/unconfirmed process logs."""
    report_root = work / 'safe-reports'
    owned.inventory(report_root, 67108864)
    files = []
    total = 0
    for path in sorted(report_root.rglob('*')):
        if path.is_file():
            raw = closed_bytes(path, 8388608)
            total += len(raw)
            if total > 67108864: raise ValueError('Safe report byte limit')
            files.append((path.relative_to(report_root).as_posix(), raw))
    partial = work / 'safe-evidence.zip.partial'
    with zipfile.ZipFile(partial, 'x', compression=zipfile.ZIP_DEFLATED) as archive:
        for name, raw in files: archive.writestr(name, raw)
    if partial.stat().st_size > 67108864: raise ValueError('Safe archive byte limit')
    with zipfile.ZipFile(partial) as archive:
        if archive.testzip() is not None: raise ValueError('Safe archive CRC failed')
    partial.rename(work / 'safe-evidence.zip')


if __name__ == '__main__': main()
