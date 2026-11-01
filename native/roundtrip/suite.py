"""Finite semantic/receipt harness. Injected transport only; never starts a process.

The actual Linux native executor is a held prerequisite, not supplied by mocks.
"""
import copy
import json
from pathlib import Path
from diagnostics import classify_logs
import negative_oracles
import startup_watchdog as startup

PROTOCOL = 'civilizationbot.native-save-bridge.v1'
PROFILE = 'civ13-synthetic-level.v1'
SOURCE = '75e381e0d72211e923bffd8893bdd786c0f6d392'
RUNTIME = '516.1688'
ROOT = Path(__file__).resolve().parent


def check(ok, message='native_roundtrip_contract_failed'):
    if not ok:
        raise ValueError(message)


def parse_json(raw):
    check(isinstance(raw, bytes) and len(raw) <= 1048576)
    def pairs(items):
        result = {}
        for key, value in items:
            check(key not in result, 'duplicate_json_key')
            result[key] = value
        return result
    def constant(_):
        raise ValueError('nonfinite_json')
    return json.loads(raw.decode('utf-8'), object_pairs_hook=pairs, parse_constant=constant)


def canonical(graph):
    """Compare typed rooted graphs modulo list IDs; preserve alias/cycle topology."""
    check(isinstance(graph, dict) and set(graph) == {'schema', 'roots', 'objects', 'lists'})
    check(graph['schema'] == 'civilizationbot.save-graph.v1' and graph['objects'] == [])
    check(isinstance(graph['roots'], list) and len(graph['roots']) <= 4096)
    check(isinstance(graph['lists'], list) and len(graph['lists']) <= 4096)
    records = {}
    for record in graph['lists']:
        check(isinstance(record, dict) and set(record) == {'id', 'kind', 'items'})
        check(isinstance(record['id'], str) and 0 < len(record['id']) <= 128 and record['id'] not in records)
        check(record['kind'] == 'list' and isinstance(record['items'], list) and len(record['items']) <= 65536)
        records[record['id']] = record['items']
    names = {}
    for root in graph['roots']:
        check(isinstance(root, dict) and set(root) == {'name', 'value'})
        check(isinstance(root['name'], str) and 0 < len(root['name']) <= 128 and root['name'] not in names)
        names[root['name']] = root['value']
    ids, nodes = {}, []
    work = 0
    def value(raw, depth=0):
        nonlocal work
        work += 1
        check(work <= 65536 and depth <= 32)
        check(isinstance(raw, dict) and isinstance(raw.get('tag'), str))
        tag = raw['tag']
        if tag == 'null':
            check(set(raw) == {'tag'})
            return ['null']
        if tag in ['number', 'text', 'type']:
            check(set(raw) == {'tag', 'value'})
            val = raw['value']
            if tag == 'number':
                check(type(val) is int and abs(val) <= 16777216)
            elif tag == 'text':
                check(isinstance(val, str) and len(val.encode('utf-8')) <= 65536)
            else:
                check(val in ['/datum/save_bridge_fixture', '/turf/save_bridge_fixture', '/obj/save_bridge_fixture'])
            return [tag, val]
        check(tag == 'list' and set(raw) == {'tag', 'id'} and isinstance(raw['id'], str) and raw['id'] in records)
        identity = raw['id']
        if identity not in ids:
            ids[identity] = len(nodes)
            items = []
            nodes.append(items)
            for item in records[identity]:
                check(isinstance(item, dict) and set(item) == {'key', 'associated', 'value'})
                associated = item['associated']
                check(type(associated) in [bool, int] and associated in [0, 1])
                key = value(item['key'], depth + 1)
                val = value(item['value'], depth + 1)
                check(bool(associated) or val == ['null'])
                check(not associated or (key[0] not in ['number', 'null'] and val != ['null']))
                items.append([key, bool(associated), val])
        return ['list', ids[identity]]
    roots = [[name, value(raw)] for name, raw in sorted(names.items())]
    check(set(ids) == set(records), 'unreachable_list_record')
    return json.dumps([roots, nodes], ensure_ascii=True, separators=(',', ':'))


def expected(version, score):
    return parse_json((ROOT / 'oracles' / f'expected-v{version}-score{score}.json').read_bytes())


def edit_score(graph):
    edited = copy.deepcopy(graph)
    matches = [r for r in edited['roots'] if r['name'] == '/entries/0/1/score']
    check(len(matches) == 1 and matches[0]['value']['tag'] == 'list')
    identity = matches[0]['value']['id']
    lists = [r for r in edited['lists'] if r['id'] == identity]
    check(len(lists) == 1)
    slots = [i for i in lists[0]['items'] if i['key'] == {'tag': 'text', 'value': 'value'}]
    check(len(slots) == 1 and slots[0]['value'] == {'tag': 'number', 'value': 5})
    slots[0]['value'] = {'tag': 'number', 'value': 6}
    return edited


def bridge_request(operation, graph=None):
    request = {'protocol': PROTOCOL, 'profile': PROFILE, 'source': SOURCE, 'operation': operation}
    if graph is not None:
        request['graph'] = graph
    return request


def validate_receipt(receipt, operation):
    check(isinstance(receipt, dict) and set(receipt) == {'protocol', 'profile', 'source', 'operation', 'runtime', 'status', 'graph'})
    check(receipt['protocol'] == PROTOCOL and receipt['profile'] == PROFILE and receipt['source'] == SOURCE)
    check(receipt['runtime'] == RUNTIME and receipt['operation'] == operation)
    check(receipt['status'] in ['ok', 'rejected'])
    if receipt['status'] == 'rejected':
        check(receipt['graph'] == [])
    return receipt['status']


def bad_requests():
    empty = {'schema': 'civilizationbot.save-graph.v1', 'roots': [], 'objects': [], 'lists': []}
    a = copy.deepcopy(empty); a['lists'] = [None]
    b = copy.deepcopy(empty); b['roots'] = [None]
    c = copy.deepcopy(empty); c['lists'] = [{'id': 'broken', 'kind': 'list', 'items': [None]}]
    out = [(name, bridge_request('encode', g), 'encode') for name, g in [('null-record', a), ('null-root', b), ('null-item', c)]]
    for name, operation in [('numeric-operation', 7), ('list-operation', ['decode']), ('null-operation', None), ('unknown-operation', 'other'), ('oversized-operation', 'x' * 65536)]:
        out.append((name, bridge_request(operation), 'unknown'))
    return out


def run_suite(transport):
    """transport(name,image,request,inputs) must provide CLOSED bounded evidence.

    A reviewed native adapter must enforce cases.json and diagnostic policy;
    injecting this interface alone never proves native execution or cleanup.
    """
    jobs, results = [], {}
    def call(name, image, request, inputs=None):
        check(name not in jobs and len(jobs) < 25)
        jobs.append(name)
        result = transport(name, image, request, inputs or {})
        check(isinstance(result, dict) and result.get('exit_confirmed') is True and result.get('exit_code') == 0)
        logs = result.get('logs')
        check(isinstance(logs, dict) and set(logs) == {'stdout', 'stderr', 'native.log'}, 'native_diagnostic_evidence_missing')
        startup.validate_evidence(result.get('startup'), image)
        classify_logs(logs, parse_json((ROOT / 'diagnostics.json').read_bytes()), startup_evidence=result['startup'], image=image)
        check(result.get('input_unchanged') is True, 'native_input_modified')
        return result
    generated = call('generate', 'fixture_generator', {'schema': 'civilizationbot.fixture-request.v1', 'operation': 'generate'})
    fixture_receipt = {'schema': 'civilizationbot.fixture-receipt.v1', 'status': 'ok', 'runtime': RUNTIME}
    check(generated['receipt'] == fixture_receipt)
    refused = ['wrong-version', 'unknown-field', 'extra-buffer', 'resource', 'instance', 'missing-type', 'bad-ref', 'unknown-kind']
    check(set(generated['files']) == {f'fixture-{name}.sav' for name in ['v2', 'v3', *refused]})
    check(all(isinstance(raw, bytes) and 0 < len(raw) <= 8388608 for raw in generated['files'].values()), 'fixture_file_limit')
    for version in [2, 3]:
        raw = generated['files'][f'fixture-v{version}.sav']
        decoded = call(f'v{version}-decode', 'save_bridge', bridge_request('decode'), {'input.sav': raw})
        check(validate_receipt(decoded['receipt'], 'decode') == 'ok')
        graph = decoded['receipt']['graph']
        check(canonical(graph) == canonical(expected(version, 5)), 'initial_graph_mismatch')
        edited = edit_score(graph)
        check(canonical(edited) == canonical(expected(version, 6)), 'edit_changed_other_semantics')
        written = call(f'v{version}-encode', 'save_bridge', bridge_request('encode', edited))
        check(validate_receipt(written['receipt'], 'encode') == 'ok')
        check(canonical(written['receipt']['graph']) == canonical(expected(version, 6)))
        check(set(written['files']) == {'output.sav'} and isinstance(written['files']['output.sav'], bytes) and 0 < len(written['files']['output.sav']) <= 8388608)
        candidate = written['files']['output.sav']
        reread = call(f'v{version}-fresh-decode', 'save_bridge', bridge_request('decode'), {'input.sav': candidate})
        check(validate_receipt(reread['receipt'], 'decode') == 'ok')
        check(canonical(reread['receipt']['graph']) == canonical(expected(version, 6)), 'fresh_graph_mismatch')
        oracle = call(f'v{version}-native-oracle', 'fixture_generator', {'schema': 'civilizationbot.fixture-request.v1', 'operation': 'verify', 'version': version}, {'candidate.sav': candidate})
        check(oracle['receipt'] == fixture_receipt)
        results[f'v{version}'] = 'bridge round-trip + independent native oracle passed'
    for name in refused:
        decoded = call(name, 'save_bridge', bridge_request('decode'), {'input.sav': generated['files'][f'fixture-{name}.sav']})
        status = validate_receipt(decoded['receipt'], 'decode')
        if name in ['unknown-field', 'extra-buffer', 'resource', 'instance']:
            check(status == 'rejected', 'native_structural_refusal_missing')
            results[name] = 'native bridge refusal'
        else:
            check(status == 'ok', 'unexpected_raw_bridge_refusal')
            value = canonical(decoded['receipt']['graph'])
            check(value == canonical(negative_oracles.graph(name)), 'negative_fixture_semantics_mismatch')
            results[name] = 'exact malformed graph matched: ' + negative_oracles.REASONS[name] + '; no PHP admission claim'
    for name, request, operation in bad_requests():
        rejected = call(name, 'save_bridge', request)
        check(validate_receipt(rejected['receipt'], operation) == 'rejected', 'request_refusal_missing')
        results[name] = 'request refused with fixed receipt'
    check(len(jobs) == 25)
    return {'status': 'case_harness_passed', 'jobs': jobs, 'results': results,
            'native_execution_claim': 'transport evidence must establish actual native execution separately',
            'php_part_acceptance': 'not exercised; Part/codec is not merged'}
