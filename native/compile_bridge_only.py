"""Manual, compile-only check for the standalone synthetic save bridge.

Default inspection performs no network or native execution. --execute is for
the separately approved hosted workflow. No daemon is extracted or launched.
"""
import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import time
import urllib.request
import zipfile

VERSION = '516.1688'
ARCHIVE_SHA256 = '16278f318cdeb4b77c57868dd7135032fde717ea6226269d22b2a1a50968e7f9'
ARCHIVE_BYTES = 4887281
ARCHIVE_URL = 'https://github.com/Civ13/Civ13/releases/download/byond-toolchain-cache-516.1688-linux-16278f318cde/516.1688_byond_linux.zip'
SOURCES = ('save_bridge.dm', 'save_bridge.dme')
LOG_CAP = 1048576
IMAGE_CAP = 8388608
PENDING = []


def sha(data):
    return hashlib.sha256(data).hexdigest()


def inspect_sources(root):
    result = {}
    for name in SOURCES:
        file = root/'native'/name
        if not file.is_file() or file.is_symlink() or file.stat().st_size > 1048576:
            raise ValueError('Invalid compile source')
        result['native/'+name] = sha(file.read_bytes())
    dme = (root/'native/save_bridge.dme').read_text()
    includes = [line.strip() for line in dme.splitlines() if line.lstrip().startswith('#')]
    if includes != ['#include "save_bridge.dm"'] or re.search(r'^\s*#include\b', (root/'native/save_bridge.dm').read_text(), re.MULTILINE):
        raise ValueError('Compile source closure changed')
    return result


def fetch_archive(destination):
    deadline = time.monotonic()+60
    total = 0
    with urllib.request.urlopen(ARCHIVE_URL, timeout=5) as response, destination.open('xb') as output:
        while block := response.read(65536):
            total += len(block)
            if total > ARCHIVE_BYTES or time.monotonic() > deadline:
                raise ValueError('Archive transfer limit')
            output.write(block)
    if total != ARCHIVE_BYTES or sha(destination.read_bytes()) != ARCHIVE_SHA256:
        raise ValueError('Pinned archive mismatch')


def compiler_files(archive, destination):
    destination.mkdir(mode=0o700)
    with zipfile.ZipFile(archive) as source:
        entries = source.infolist()
        if len(entries) > 1000 or sum(x.file_size for x in entries) > 134217728:
            raise ValueError('Archive extraction limit')
        seen = set()
        for entry in entries:
            path = destination/entry.filename
            if not path.resolve().is_relative_to(destination.resolve()) or '\\' in entry.filename or stat.S_ISLNK(entry.external_attr >> 16) or entry.filename in seen:
                raise ValueError('Unsafe archive entry')
            seen.add(entry.filename)
        for entry in entries:
            name = Path(entry.filename)
            # Keep only the compiler and its shared libraries. The server
            # executable, setup scripts and all other programs are excluded.
            allowed = entry.filename == 'byond/bin/DreamMaker' or (name.parent.as_posix() == 'byond/bin' and '.so' in name.name)
            if allowed and not entry.is_dir():
                if entry.file_size > IMAGE_CAP:
                    raise ValueError('Compiler member byte limit')
                path = destination/entry.filename
                path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
                with source.open(entry) as original, path.open('xb') as output:
                    while block := original.read(65536):
                        output.write(block)
    compiler = destination/'byond/bin/DreamMaker'
    if not compiler.is_file():
        raise ValueError('Pinned compiler missing')
    compiler.chmod(0o700)
    return compiler


def snapshot(path):
    with path.open('rb') as stream:
        raw = stream.read(LOG_CAP+1)
    data = raw[:LOG_CAP]
    return {'sha256_snapshot': sha(data), 'captured_bytes': len(data),
            'kind': 'bounded_prefix' if len(raw) > LOG_CAP else 'complete_snapshot'}, data


def compile_once(compiler, stage):
    deadline = time.monotonic()+60
    reserve = .75
    fault = None
    env = {'PATH': os.defpath, 'HOME': str(stage), 'TMPDIR': str(stage),
           'LD_LIBRARY_PATH': str(compiler.parent)}
    argv = [str(compiler), '-max_errors', '0', 'save_bridge.dme']
    with (stage/'compiler.stdout').open('xb') as out, (stage/'compiler.stderr').open('xb') as err:
        child = subprocess.Popen(argv, cwd=stage, stdin=subprocess.DEVNULL, stdout=out, stderr=err, env=env)
        while child.poll() is None:
            if any((stage/name).stat().st_size > LOG_CAP for name in ('compiler.stdout', 'compiler.stderr')) or any(p.stat().st_size > IMAGE_CAP for p in stage.glob('save_bridge.*') if p.suffix in ('.dmb', '.rsc')) or sum(p.stat().st_size for p in stage.rglob('*') if p.is_file() and not p.is_symlink()) > 33554432:
                fault = 'output_limit'
                break
            if time.monotonic() >= deadline-reserve:
                fault = 'timeout'
                break
            time.sleep(.005)
        if child.poll() is None:
            child.terminate()
            grace = min(deadline, time.monotonic()+.25)
            while child.poll() is None and time.monotonic() < grace:
                time.sleep(.005)
            if child.poll() is None:
                child.kill()
            while child.poll() is None and time.monotonic() < deadline:
                time.sleep(.005)
        code = child.poll()
        record = {'compiler_processes': 1, 'daemon_processes': 0, 'exit_code': code,
                  'exit_confirmed': code is not None, 'fault': fault, 'logs': {}}
        captured = {}
        if code is None:
            PENDING.append(child)
            record['fault'] = 'owned_compiler_exit_unconfirmed'
            for name in ('compiler.stdout', 'compiler.stderr'):
                record['logs'][name] = {'kind': 'unavailable_unconfirmed_exit', 'sha256_snapshot': None, 'path': name}
        else:
            for name in ('compiler.stdout', 'compiler.stderr'):
                record['logs'][name], captured[name] = snapshot(stage/name)
                if record['logs'][name]['kind'] == 'bounded_prefix':
                    record['fault'] = record['fault'] or 'output_limit'
    log = b''.join(captured.values()).decode(errors='replace')
    image = stage/'save_bridge.dmb'
    passed = record['fault'] is None and code == 0 and VERSION in log and '0 errors, 0 warnings' in log and image.is_file() and not image.is_symlink() and 0 < image.stat().st_size <= IMAGE_CAP
    record['status'] = 'compile_passed' if passed else 'compile_failed'
    if passed:
        record['dmb_sha256'] = sha(image.read_bytes())
    (stage/'compile-result.json').write_text(json.dumps(record, indent=2))
    if not passed:
        for data in captured.values():
            print(data[:4096].decode(errors='replace'))
    return record


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source-root', type=Path, required=True)
    parser.add_argument('--revision', required=True)
    parser.add_argument('--work-root', type=Path)
    parser.add_argument('--execute', action='store_true')
    args = parser.parse_args()
    root = args.source_root.resolve()
    if not re.fullmatch('[a-f0-9]{40}', args.revision):
        raise ValueError('Exact source SHA required')
    sources = inspect_sources(root)
    summary = {'requested_revision': args.revision, 'source_revision': None, 'revision_verified': False,
               'sources': sources, 'runtime': VERSION,
               'archive_sha256': ARCHIVE_SHA256, 'archive_bytes': ARCHIVE_BYTES,
               'compiler_seconds': 60, 'daemon_processes': 0, 'status': 'inspect_only'}
    if not args.execute:
        print(json.dumps(summary))
        return
    if os.name == 'nt' or not args.work_root:
        raise ValueError('Explicit Linux staging directory required')
    actual = subprocess.check_output(['git', '-C', str(root), 'rev-parse', 'HEAD'], text=True, timeout=5).strip()
    if actual != args.revision:
        raise ValueError('Checked-out revision mismatch')
    for name, expected in sources.items():
        committed = subprocess.check_output(['git', '-C', str(root), 'show', actual+':'+name], timeout=5)
        if sha(committed) != expected:
            raise ValueError('Source differs from approved commit')
    summary.update(source_revision=actual, revision_verified=True)
    work = args.work_root.absolute()
    if work.exists() or work.resolve().is_relative_to(root):
        raise ValueError('Fresh staging outside checkout required')
    work.mkdir(mode=0o700)
    archive = work/'pinned-byond.zip'
    fetch_archive(archive)
    compiler = compiler_files(archive, work/'compiler')
    stage = work/'source'
    stage.mkdir(mode=0o700)
    for name in SOURCES:
        source = root/'native'/name
        data = source.read_bytes()
        if sha(data) != sources['native/'+name]:
            raise ValueError('Source changed during staging')
        (stage/name).write_bytes(data)
    result = compile_once(compiler, stage)
    summary.update(result)
    summary['compiler_sha256'] = sha(compiler.read_bytes())
    summary['savefile_compatibility'] = 'not_tested'
    print(json.dumps(summary))
    if result['status'] != 'compile_passed':
        raise SystemExit(1)


if __name__ == '__main__':
    main()
