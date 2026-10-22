#!/usr/bin/env python3
"""Stage only the directory and locked dependencies. Never deploy or execute bot code."""
import gzip
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import tarfile

ROOT = Path(__file__).resolve().parents[2]
OUTPUT = ROOT / 'build/directory'
STAGE = OUTPUT / 'civ13-directory'
SOURCE_FILES = [
    'src/Civ13/ServerDirectory/' + name + '.php'
    for name in ['Directory', 'Endpoint', 'ByondStatusProbe', 'PublicResolver', 'Standalone']
] + ['scripts/directory/serve.php', 'scripts/directory/inventory.example.json',
     'docs/launcher-directory.md', 'docs/launcher-directory-standalone.md', 'LICENSE']

def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()

def main():
    revision = subprocess.check_output(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'], text=True).strip()
    committed_lock = subprocess.check_output(['git', '-C', str(ROOT), 'show', 'HEAD:packaging/directory/composer.lock'])
    lock = ROOT / 'packaging/directory/composer.lock'
    if committed_lock != lock.read_bytes():
        raise ValueError('Dependency lock must match the committed revision')
    if OUTPUT.exists():
        raise ValueError('Build output already exists; use a clean CI checkout')
    STAGE.mkdir(parents=True)
    for name in SOURCE_FILES:
        source = ROOT / name
        if source.is_symlink() or not source.is_file():
            raise ValueError('Unexpected source file type')
        destination = STAGE / name
        destination.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(source, destination)
    for name in ['composer.json', 'composer.lock']:
        shutil.copyfile(ROOT / 'packaging/directory' / name, STAGE / name)
    vendor = ROOT / 'packaging/directory/vendor'
    if any(path.is_symlink() for path in vendor.rglob('*')):
        raise ValueError('Dependency tree must not contain symlinks')
    shutil.copytree(vendor, STAGE / 'vendor')
    packages = json.loads(lock.read_text())['packages']
    (STAGE / 'SOURCE.json').write_text(json.dumps({
        'repository': 'https://github.com/Valgorithms/Civilizationbot',
        'commit': revision, 'composer_lock_sha256': sha(lock),
        'packages': {row['name']: row['version'] for row in packages},
    }, indent=2, sort_keys=True) + '\n')
    files = sorted(path for path in STAGE.rglob('*') if path.is_file())
    if len(files) > 5000 or sum(path.stat().st_size for path in files) > 32 * 1024 * 1024:
        raise ValueError('Unexpected bundle size')
    (STAGE / 'SHA256SUMS').write_text(''.join(sha(path) + '  ' + path.relative_to(STAGE).as_posix() + '\n' for path in files))
    archive = OUTPUT / ('civ13-directory-' + revision + '.tar.gz')
    with archive.open('wb') as output, gzip.GzipFile(filename='', mode='wb', fileobj=output, mtime=0) as compressed, tarfile.open(fileobj=compressed, mode='w') as tar:
        for path in [STAGE] + sorted(STAGE.rglob('*')):
            info = tar.gettarinfo(str(path), arcname=path.relative_to(OUTPUT).as_posix())
            info.uid = info.gid = info.mtime = 0
            info.uname = info.gname = ''
            info.mode = 0o755 if path.is_dir() else 0o644
            if path.is_file():
                with path.open('rb') as data:
                    tar.addfile(info, data)
            else:
                tar.addfile(info)
    (OUTPUT / (archive.name + '.sha256')).write_text(sha(archive) + '  ' + archive.name + '\n')
    shutil.copyfile(ROOT / 'packaging/directory/verify_bundle.py', OUTPUT / 'verify_bundle.py')
    print(archive)
    print('SHA256 ' + sha(archive))

if __name__ == '__main__':
    main()
