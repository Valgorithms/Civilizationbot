"""Exact pre-execution negative graphs, authored from declared native fields."""
import importlib.util
from pathlib import Path

spec = importlib.util.spec_from_file_location('fixture_fields', Path(__file__).resolve().parent / 'oracle-source/expected.py')
source = importlib.util.module_from_spec(spec)
spec.loader.exec_module(source)

REASONS = {'wrong-version': 'format_version is 4 rather than supported 2/3',
           'missing-type': 'entry1 type field is absent',
           'bad-ref': 'entry1 peer points to nonexistent entry9999',
           'unknown-kind': 'entry1 peer is an unsupported zone envelope with id=unsupported'}

def graph(name):
    fields = source.fields(3, 5)
    if name == 'wrong-version': fields['/format_version'] = 4
    elif name == 'missing-type': del fields['/entries/0/1/type']
    elif name == 'bad-ref': fields['/entries/0/1/peer'] = {'kind': 'entry', 'ref': 9999}
    elif name == 'unknown-kind': fields['/entries/0/1/peer'] = {'kind': 'zone', 'id': 'unsupported'}
    else: raise ValueError('Unknown negative oracle')
    nodes = []
    def value(raw):
        if raw is None: return {'tag': 'null'}
        if isinstance(raw, tuple): return {'tag': 'type', 'value': raw[1]}
        if isinstance(raw, (str, int)): return {'tag': 'text' if isinstance(raw, str) else 'number', 'value': raw}
        identity = f'negative:{len(nodes)}'
        record = {'id': identity, 'kind': 'list', 'items': []}
        nodes.append(record)
        for key, associated in (raw.items() if isinstance(raw, dict) else ((x, None) for x in raw)):
            record['items'].append({'key': value(key), 'associated': associated is not None, 'value': value(associated)})
        return {'tag': 'list', 'id': identity}
    return {'schema': 'civilizationbot.save-graph.v1', 'objects': [], 'lists': nodes,
            'roots': [{'name': name, 'value': value(raw)} for name, raw in sorted(fields.items())]}
