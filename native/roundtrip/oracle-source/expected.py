"""Independent, pre-execution field oracle. Never consumes native output."""
import json

GENERATION = 'round-v1:synthetic-fixture-v1'
TURF = '/turf/save_bridge_fixture'
OBJ = '/obj/save_bridge_fixture'
DATUM = '/datum/save_bridge_fixture'


def envelope(kind, value):
    return {'kind': kind, 'value' if kind == 'value' else 'ref': value}


def fields(version, score=5):
    if version not in (2, 3) or score not in (5, 6):
        raise ValueError('Unsupported oracle variant')
    out = {'/format_version': version, '/generation': GENERATION,
           '/maxx': 1, '/maxy': 1, '/maxz': 1, '/map/1/1/1': 1}
    for entry in range(1, 514):
        path = f'/entries/{(entry-1)//512}/{entry}'
        out[path+'/type'] = ('type', TURF if entry == 1 else OBJ)
    root = '/entries/0/1/'
    for name, kind, value in [('label', 'value', '**null'), ('score', 'value', score),
                              ('peer', 'entry', 513), ('shared', 'list', 1),
                              ('also_shared', 'list', 1)]:
        out[root+name] = envelope(kind, value)
    out['/entries/0/512/peer'] = envelope('entry', 513)
    out['/entries/1/513/peer'] = envelope('entry', 512)
    out[root+'content'] = '513' if version == 2 else [
        '&'.join(map(str, range(2, 502))), '&'.join(map(str, range(502, 514)))]
    out['/lists/1/length'] = 8
    out['/lists/2/length'] = 0
    slots = [
        ('self', 'list', 1), ('peer', 'entry', 513), ('empty', 'list', 2),
        ('nil', 'value', None), ('typed', 'value', ('type', DATUM)),
        ('literal_path', 'value', DATUM), (7, 'value', None), (7, 'value', None),
    ]
    for i, (key, kind, value) in enumerate(slots, 1):
        out[f'/lists/1/{i}/key'] = envelope('value', key)
        out[f'/lists/1/{i}/value'] = envelope(kind, value)
    return out


def graph(version, score=5):
    """Graph is authored from the declared fields, not from a codec or save."""
    nodes = []

    def value(raw):
        if raw is None:
            return {'tag': 'null'}
        if isinstance(raw, tuple):
            return {'tag': 'type', 'value': raw[1]}
        if isinstance(raw, (str, int)):
            return {'tag': 'text' if isinstance(raw, str) else 'number', 'value': raw}
        identity = f'expected:{len(nodes)}'
        node = {'id': identity, 'kind': 'list', 'items': []}
        nodes.append(node)
        pairs = raw.items() if isinstance(raw, dict) else ((item, None) for item in raw)
        for key, associated in pairs:
            node['items'].append({'key': value(key), 'associated': associated is not None,
                                  'value': value(associated)})
        return {'tag': 'list', 'id': identity}

    roots = [{'name': name, 'value': value(raw)} for name, raw in sorted(fields(version, score).items())]
    return {'schema': 'civilizationbot.save-graph.v1', 'roots': roots, 'objects': [], 'lists': nodes}


def write_oracles(directory):
    for version in (2, 3):
        for score in (5, 6):
            name = f'expected-v{version}-score{score}.json'
            (directory/name).write_text(json.dumps(graph(version, score), separators=(',', ':')), encoding='utf-8')
