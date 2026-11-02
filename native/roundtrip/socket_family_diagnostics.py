"""Unintegrated diagnostic prototype: injected owned-PID readers only.
No policy decision, process launch, connection, or default live backend.
"""
import hashlib
import json
import math
from pathlib import Path
import re

TABLES = ('unix', 'netlink')
CAP = 131072
MAX_ROWS = 4096
MAX_RECORD = 32768

def require(ok, reason):
    if not ok:
        raise ValueError(reason)

def number(raw, base=10, digits=20):
    pattern = rb'[0-9]{1,' + str(digits).encode() + rb'}' if base == 10 else rb'[0-9a-fA-F]{1,' + str(digits).encode() + rb'}'
    require(re.fullmatch(pattern, raw) is not None, 'family_numeric_shape')
    result = int(raw, base)
    require(result < 2 ** 64, 'family_numeric_range')
    return result

def parse_table(raw, table, selected, report, budget):
    """Strict known headers; never retain kernel pointers or UNIX paths."""
    require(table in TABLES and type(raw) is bytes and len(raw) <= CAP, 'family_table_bound')
    report.update(bytes=len(raw), sha256_input=hashlib.sha256(raw).hexdigest(), rows_seen=0, matches=[])
    lines = raw.splitlines()
    require(0 < len(lines) <= MAX_ROWS + 1, 'family_row_bound')
    expected = (b'Num RefCount Protocol Flags Type St Inode Path' if table == 'unix'
                else b'sk Eth Pid Groups Rmem Wmem Dump Locks Drops Inode')
    require(lines[0].split() == expected.split(), 'family_header_unsupported')
    for index, line in enumerate(lines[1:], 1):
        budget()
        report['rows_seen'] = index
        fields = line.split(maxsplit=7) if table == 'unix' else line.split()
        if table == 'unix':
            require(len(fields) in (7, 8) and fields[0].endswith(b':'), 'family_unix_shape')
            number(fields[0][:-1], 16, 16)  # Pointer validated, not retained.
            values = [number(fields[i], 16, width) for i, width in [(1,8),(2,8),(3,8),(4,4),(5,2)]]
            inode = number(fields[6])
            row = {'family':'AF_UNIX', 'inode':inode, 'refcount':values[0], 'protocol':values[1],
                   'flags':values[2], 'socket_type':values[3], 'state':values[4]}
        else:
            require(len(fields) == 10, 'family_netlink_shape')
            number(fields[0], 16, 16)  # Pointer validated, not retained.
            protocol, port_id = number(fields[1]), number(fields[2])
            groups = number(fields[3], 16, 8)
            for value in fields[4:9]:
                number(value)
            inode = number(fields[9])
            row = {'family':'AF_NETLINK', 'inode':inode, 'protocol':protocol,
                   'netlink_port_id':port_id, 'groups':groups}
        if inode in selected:
            report['match_count'] = report.get('match_count', 0) + 1
            if len(report['matches']) < 16:
                report['matches'].append(row)
            else:
                report['matches_truncated'] = True
    report['status'] = 'complete'

def diagnose(proc, pid, expected_starttime, socket_fds, deadline, *, clock, readlink):
    """Later bracketed sample, not retroactive classification of prior ticks.
    Caller supplies min(original close deadline, original child deadline-.75,
    current clock+.05). Cooperative admission checks cannot preempt a stuck
    injected/kernel read; no hard50ms deadline is claimed.
    """
    require(type(pid) is int and 0 < pid < 2**31, 'family_owned_pid')
    require(type(expected_starttime) is int and expected_starttime > 0, 'family_owned_starttime')
    require(type(socket_fds) is list and len(socket_fds) <= 64, 'family_fd_bound')
    initial = clock()
    require(type(deadline) in (int,float) and type(initial) in (int,float) and math.isfinite(deadline) and math.isfinite(initial)
            and deadline <= initial + .05, 'family_original_budget')
    expected = {}
    for item in socket_fds:
        require(type(item) is dict, 'family_fd_record')
        fd, link = item.get('fd'), item.get('link_prefix')
        require(type(fd) is str and fd.isascii() and fd.isdigit() and str(int(fd)) == fd and int(fd) < 64, 'family_fd_name')
        require(fd not in expected and type(link) is str and item.get('link_truncated') is False
                and item.get('link_bytes') == len(link), 'family_fd_snapshot')
        match = re.fullmatch(r'socket:\[([1-9][0-9]{0,19})\]', link)
        require(match is not None and int(match[1]) < 2**64, 'family_inode')
        expected[fd] = link
    selected = {int(re.fullmatch(r'socket:\[([1-9][0-9]{0,19})\]', link)[1]) for link in expected.values()}
    report = {'schema':'civilizationbot.socket-family-diagnostic.v1', 'pid':pid,
              'expected_starttime_ticks':expected_starttime, 'status':'incomplete',
              'socket_count':len(selected), 'tables':{}, 'classifications':[],
              'policy_effect':'none; strict startup failure remains failure',
              'sampling':'later bracketed owned-FD sample; not continuous tracing'}
    def budget():
        require(clock() < deadline, 'family_budget_exhausted')
    def identity(label):
        budget()
        actual = proc.identity(pid)
        report[label] = actual
        budget()  # A slow final identity read must never establish stability.
        require(actual == expected_starttime, 'family_identity_changed')
    def fds(label):
        report[label] = {'checked':0,'status':'reading'}
        for fd, wanted in sorted(expected.items(), key=lambda item:int(item[0])):
            budget()
            actual = readlink(Path('/proc') / str(pid) / 'fd' / fd)
            require(actual == wanted, 'family_fd_changed')
            report[label]['checked'] += 1
        report[label]['status'] = 'unchanged'
    try:
        identity('identity_before')
        fds('fds_before')
        for table in TABLES:
            budget()
            item = report['tables'][table] = {'status':'reading'}
            raw = proc.read(Path('/proc') / str(pid) / 'net' / table, CAP)
            budget()
            parse_table(raw, table, selected, item, budget)
        fds('fds_after')
        identity('identity_after')
        report['status'] = 'stable_sample'
    except Exception as error:
        reason = str(error) if type(error) is ValueError else ''
        report.update(error_type=type(error).__name__,
                      reason=reason if re.fullmatch('family_[a-z_]{1,64}', reason) else None)
    candidates = [row for item in report['tables'].values() for row in item.get('matches',[])]
    incomplete = any(item.get('matches_truncated') for item in report['tables'].values())
    for inode in sorted(selected):
        matches = [row for row in candidates if row['inode'] == inode]
        known = report['status'] == 'stable_sample' and not incomplete and len(matches) == 1
        report['classifications'].append({'inode':inode, 'family':matches[0]['family'] if known else None,
                                         'status':'observed_family' if known else ('ambiguous' if len(matches)>1 else 'unknown'),
                                         'candidate_count':len(matches)})
    encoded = json.dumps(report,allow_nan=False,separators=(',',':')).encode()
    if len(encoded) > MAX_RECORD:
        return {'schema':report['schema'],'pid':pid,'status':'capture_limit',
                'socket_count':len(selected),'classifications':[],
                'policy_effect':'none; no family claim or startup admission'}
    return report
