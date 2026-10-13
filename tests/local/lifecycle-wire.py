"""Actual Python journal to PHP adapter, with synthetic effects and no listeners."""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile

parser = argparse.ArgumentParser()
parser.add_argument('--controller', required=True, type=Path)
parser.add_argument('--php', required=True, type=Path)
parser.add_argument('--autoload', required=True, type=Path)
args = parser.parse_args()
for path in (args.controller, args.php, args.autoload):
    if not path.is_absolute() or not path.is_file():
        parser.error('Explicit existing absolute paths required')
spec = importlib.util.spec_from_file_location('actual_control', args.controller)
control = importlib.util.module_from_spec(spec)
spec.loader.exec_module(control)
with tempfile.TemporaryDirectory(prefix='lifecycle-wire-') as directory:
    root = Path(directory)
    (root / 'map.dmm').write_text('synthetic map - never compiled', encoding='utf-8')
    profile = {'engine': 'opendream', 'runtime_dir': str(root), 'game_port': 18131,
               'topic_port': 18141, 'maps': {'TEST': 'map.dmm'}}
    path = root / 'profile.json'
    path.write_text(json.dumps(profile, separators=(',', ':')), encoding='utf-8')
    digest = hashlib.sha256(path.read_bytes()).hexdigest()
    store = control.OperationStore(root, digest)
    request = {'id': 'wire-1', 'action': 'restart', 'map': None}
    effects = []
    process = {'ok': True, 'engine': 'opendream', 'running': False, 'game_port': 18131,
               'topic_port': 18141, 'runtime_dir': str(root), 'server_pid': None}
    def effect():
        assert store.inspect('wire-1')['outcome'] == 'pending'
        effects.append('effect')
        return dict(process, launch_identity={'schema': 'civ13.launch.v1',
            'instance_id': 'synthetic-boot', 'profile_digest': digest, 'build_id': None,
            'map_id': None, 'ready': False, 'server_pid': None})
    committed = store.perform(request, 'cli', effect)
    assert store.perform(request, 'cli', effect) == committed and effects == ['effect']
    try:
        store.perform(dict(request, action='stop'), 'cli', effect)
        raise AssertionError('Changed-body replay accepted')
    except ValueError:
        pass
    def interrupted():
        raise RuntimeError('synthetic interruption before effect completion')
    try:
        store.perform(dict(request, id='wire-pending'), 'cli', interrupted)
    except RuntimeError:
        pass
    vectors = {'committed': committed, 'recovery': {'ok': True,
        'schema': control.OPERATION_SCHEMA, 'operation_status': 'committed',
        'receipt': store.inspect('wire-1')}, 'pending': {'ok': True,
        'schema': control.OPERATION_SCHEMA, 'operation_status': 'pending',
        'receipt': store.inspect('wire-pending')}, 'not_found': {'ok': True,
        'schema': control.OPERATION_SCHEMA, 'operation_status': 'not_found', 'receipt': None}}
    fixture = root / 'vectors.json'
    fixture.write_text(json.dumps(vectors, separators=(',', ':')), encoding='utf-8')
    result = subprocess.run([str(args.php), '-d', 'error_reporting=24575',
        str(Path(__file__).with_suffix('.php')), str(args.autoload), str(path),
        str(args.controller), str(args.php), str(fixture)], capture_output=True,
        text=True, encoding='utf-8', timeout=15)
    if result.returncode or result.stderr:
        raise RuntimeError(f'PHP verifier failed: {result.stdout}\n{result.stderr}')
    print(result.stdout.strip())
    assert effects == ['effect'], 'Recovery/replay executed another effect'
    print('PASS actual Python OperationStore immutable replay/conflict/pending -> PHP strict receipt DTO')
