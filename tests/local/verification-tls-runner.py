"""PROPOSAL ONLY. Linux synthetic TLS runner; no BYOND tools or real endpoint."""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import sys

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--repo', type=Path, required=True)
    parser.add_argument('--evidence', type=Path, required=True)
    parser.add_argument('--php')
    args = parser.parse_args()
    if sys.platform != 'linux':
        raise ValueError('Reviewed Linux controller only')
    repo = args.repo.resolve(strict=True)
    root = args.evidence.absolute()
    if root.exists():
        raise ValueError('Fresh owned evidence directory required')
    root.mkdir(mode=0o700, parents=True)
    stage = root / 'job'; stage.mkdir(mode=0o700)
    for name in ['home', 'tmp', 'empty-trust', 'empty-ini']:
        (stage / name).mkdir(mode=0o700)
    php = Path(args.php or shutil.which('php')).resolve(strict=True)
    loader = repo / 'vendor/autoload.php'
    if not loader.is_file():
        raise ValueError('Existing installed dependencies required')
    harness = Path(__file__).with_name('verification-tls.php').resolve(strict=True)
    # Reuse the merged bounded supervisor, including final-output validation and
    # owned-handle TERM/kill/reap. Import starts no process.
    own_path = repo / 'native/roundtrip/owned_process.py'
    spec = importlib.util.spec_from_file_location('mapping_tls_owned_process', own_path)
    own = importlib.util.module_from_spec(spec); spec.loader.exec_module(own)
    disabled = 'exec,shell_exec,system,passthru,proc_open,popen,curl_exec,curl_multi_exec'
    argv = [str(php), '-d', 'memory_limit=128M', '-d', 'auto_prepend_file=',
            '-d', 'auto_append_file=', '-d', 'allow_url_fopen=0',
            '-d', 'disable_functions='+disabled,
            '-d', 'openssl.cafile='+str(stage/'root-ca.pem'),
            '-d', 'openssl.capath='+str(stage/'empty-trust'),
            str(harness), str(loader), str(repo), str(stage)]
    env = {'PATH': os.defpath, 'HOME': str(stage/'home'), 'TMP': str(stage/'tmp'),
           'TMPDIR': str(stage/'tmp'), 'TEMP': str(stage/'tmp'),
           'PHP_INI_SCAN_DIR': str(stage/'empty-ini'), 'LANG': 'C.UTF-8'}
    value = own.bounded(argv, stage, 60, 4096, root, env, 55)
    if own.ACTIVE is not None or own.PENDING:
        raise ValueError('Owned child exit unconfirmed')
    receipt_path = stage / 'tls-receipt.json'
    if not receipt_path.is_file() or receipt_path.is_symlink() or receipt_path.stat().st_size > 8192:
        raise ValueError('Bounded closed receipt missing')
    with receipt_path.open('rb') as source:
        raw = source.read(8193)
    if len(raw) > 8192:
        raise ValueError('Receipt grew beyond cap')
    result = json.loads(raw)
    expected = [('success',True,1,1),('wrong-hostname',False,2,0),
                ('untrusted-chain',False,2,0),('https-redirect',False,1,1),
                ('http-downgrade',False,1,1)]
    if len(result['cases']) != len(expected) or result['connections'] != 7:
        raise ValueError('Independent finite-case oracle mismatch')
    for actual, (name, allowed, connections, requests) in zip(result['cases'], expected):
        if (actual['case'] != name or actual['expected_authority'] is not allowed
                or actual['actual_authority'] is not allowed or actual['connections'] != connections
                or actual['client_attempts'] != connections or actual['http_requests'] != requests
                or actual['resolver_names'] != ['civ13.com']):
            raise ValueError('Independent case admission/transport oracle mismatch')
    if not result['listeners_closed'] or not result['owned_connections_closed']:
        raise ValueError('Fixture cleanup unconfirmed')
    source_hash = hashlib.sha256((repo/'src/Civ13/HostedVerificationSource.php').read_bytes()).hexdigest()
    if result['source_sha256'] != source_hash or result['real_endpoint_contacted'] is not False:
        raise ValueError('Source/endpoint binding mismatch')
    public = {'schema':'civ13.synthetic-mapping-tls-controller.v1',
              'status':'passed','cases':result['cases'],'source_sha256':source_hash,
              'owned_pid':value['record']['pid'],'exit_confirmed':value['record']['exit_confirmed'],
              'limits':{'child_seconds':60,'cpu_seconds':55,'php_memory_bytes':134217728,
                        'kernel_address_space_bytes':1073741824,'max_open_files':64,
                        'stdout_bytes':4096,'stderr_bytes':4096,'connections':7,
                        'listeners_simultaneous':1,'case_seconds':5},
              'fixture_pem_artifacts':'not_uploaded','real_endpoint_contacted':False}
    (root/'public-receipt.json').write_text(json.dumps(public,indent=2)+'\n')
    print(json.dumps(public))
if __name__ == '__main__':
    main()
