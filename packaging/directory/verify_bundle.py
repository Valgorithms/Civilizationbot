#!/usr/bin/env python3
"""Verify a directory bundle without extraction or execution. Compare the outer
SHA256 with the value obtained through the reviewed CI/artifact channel first.
"""
import hashlib
import json
from pathlib import Path, PurePosixPath
import re
import sys
import tarfile

MAX_BYTES = 32 * 1024 * 1024
SOURCE_FILES = {'src/Civ13/ServerDirectory/' + name + '.php' for name in ['Directory', 'Endpoint', 'ByondStatusProbe', 'PublicResolver', 'Standalone']}
REQUIRED = SOURCE_FILES | {'scripts/directory/serve.php', 'scripts/directory/inventory.example.json', 'vendor/autoload.php', 'composer.json', 'composer.lock', 'SOURCE.json', 'LICENSE'}
ALLOWED_ROOT = REQUIRED | {'docs/launcher-directory.md', 'docs/launcher-directory-standalone.md'}

def verify(path):
    if path.stat().st_size > MAX_BYTES:
        raise ValueError('Compressed size exceeds bound')
    files = {}
    total = 0
    with tarfile.open(path, 'r:gz') as archive:
        for number, member in enumerate(archive):
            name = PurePosixPath(member.name)
            if number >= 6000 or name.is_absolute() or '..' in name.parts or not name.parts or name.parts[0] != 'civ13-directory' or member.name != name.as_posix():
                raise ValueError('Unsafe archive path/count')
            if not member.isdir() and not member.isfile():
                raise ValueError('Links/devices are prohibited')
            if member.mode != (0o755 if member.isdir() else 0o644):
                raise ValueError('Unexpected archive permissions')
            if member.isdir():
                continue
            relative = name.relative_to('civ13-directory').as_posix()
            if relative in files or any(ord(char) < 32 or ord(char) == 127 for char in relative):
                raise ValueError('Duplicate/control-character file path')
            total += member.size
            if member.size < 0 or total > MAX_BYTES:
                raise ValueError('Uncompressed size exceeds bound')
            files[relative] = archive.extractfile(member).read()
    manifest = files.pop('SHA256SUMS').decode('utf-8')
    expected = {}
    for line in manifest.splitlines():
        match = re.fullmatch(r'([a-f0-9]{64})  (.+)', line)
        if not match or match[2] in expected:
            raise ValueError('Invalid checksum manifest')
        expected[match[2]] = match[1]
    if files.keys() != expected.keys() or any(hashlib.sha256(data).hexdigest() != expected[name] for name, data in files.items()):
        raise ValueError('Bundle contents do not match manifest')
    if not REQUIRED.issubset(files) or any(name not in ALLOWED_ROOT and not name.startswith('vendor/') for name in files):
        raise ValueError('Missing or unexpected runtime file')
    source = json.loads(files['SOURCE.json'])
    if source.get('repository') != 'https://github.com/Valgorithms/Civilizationbot' or not re.fullmatch(r'[a-f0-9]{40}', source.get('commit', '')):
        raise ValueError('Invalid source identity')
    if source['composer_lock_sha256'] != hashlib.sha256(files['composer.lock']).hexdigest():
        raise ValueError('Lock hash mismatch')
    lock = json.loads(files['composer.lock'])
    if source['packages'] != {row['name']: row['version'] for row in lock['packages']}:
        raise ValueError('Package inventory mismatch')
    if source['packages'].get('react/http') != 'v1.11.1' or any('discord' in name.lower() for name in source['packages']):
        raise ValueError('Unexpected HTTP/bot dependency')
    if json.loads(files['scripts/directory/inventory.example.json'])['targets'] != []:
        raise ValueError('Example inventory must remain disabled')
    if any(name.endswith(('.env', 'bot.php')) or name.startswith('src/Civ13/GameServer') for name in files):
        raise ValueError('Application/config files in directory-only bundle')
    return source, len(files), total

if __name__ == '__main__':
    try:
        if len(sys.argv) != 2:
            raise ValueError('Usage: python3 verify_bundle.py directory.tar.gz')
        path = Path(sys.argv[1])
        source, count, total = verify(path)
        print('PASS bundle: commit=' + source['commit'] + ' files=' + str(count) + ' bytes=' + str(total))
        print('SHA256 ' + hashlib.sha256(path.read_bytes()).hexdigest())
    except (OSError, ValueError, KeyError, TypeError, tarfile.TarError) as error:
        print('Bundle rejected: ' + str(error), file=sys.stderr)
        sys.exit(1)
