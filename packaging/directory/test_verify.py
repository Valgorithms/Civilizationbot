#!/usr/bin/env python3
import hashlib
import io
import json
from pathlib import Path
import tarfile
import tempfile
from verify_bundle import verify, REQUIRED

lock = json.dumps({'packages': [{'name': 'react/http', 'version': 'v1.11.1'}]}).encode()
files = {'composer.lock': lock, 'scripts/directory/inventory.example.json': b'{"targets":[]}',
         'SOURCE.json': json.dumps({'repository': 'https://github.com/Valgorithms/Civilizationbot', 'commit': '1' * 40,
             'composer_lock_sha256': hashlib.sha256(lock).hexdigest(), 'packages': {'react/http': 'v1.11.1'}}).encode()}
for required in REQUIRED:
    files.setdefault(required, b'fixture')
files['SHA256SUMS'] = ''.join(hashlib.sha256(value).hexdigest() + '  ' + name + '\n' for name, value in files.items()).encode()
with tempfile.TemporaryDirectory() as root:
    for case in ['valid', 'traversal', 'absolute', 'symlink', 'duplicate', 'mismatch', 'executable']:
        path = Path(root) / (case + '.tar.gz')
        with tarfile.open(path, 'w:gz') as archive:
            for name, data in files.items():
                info = tarfile.TarInfo('civ13-directory/' + name); info.size = len(data); info.mode = 0o644
                if case == 'mismatch' and name == 'composer.lock': data = b'X' * len(data)
                if case == 'executable' and name == 'composer.lock': info.mode = 0o755
                archive.addfile(info, io.BytesIO(data))
            if case in {'traversal', 'absolute', 'symlink', 'duplicate'}:
                name = {'traversal': 'civ13-directory/../escape', 'absolute': '/escape', 'symlink': 'civ13-directory/link', 'duplicate': 'civ13-directory/composer.lock'}[case]
                info = tarfile.TarInfo(name); info.mode = 0o644
                if case == 'symlink': info.type = tarfile.SYMTYPE; info.linkname = '/etc/passwd'
                archive.addfile(info, io.BytesIO(b''))
        try:
            verify(path)
        except (ValueError, KeyError):
            if case == 'valid': raise
        else:
            if case != 'valid': raise RuntimeError('Unsafe bundle accepted: ' + case)
print('PASS bundle verifier: valid, traversal, absolute path, symlink, duplicate, corruption and mode controls')
