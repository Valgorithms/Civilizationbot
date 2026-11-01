"""Owned Linux startup listener observation for trusted synthetic images only.

No OS egress containment is installed or claimed. The intended effect is one
temporary loopback listener, no outbound operations. All process/cleanup
authority remains with owned_process and its exact Popen handle.
"""
import hashlib
import json
import math
import os
from pathlib import Path
import re
import secrets
import stat
import time

ADDRESS, PORT = '127.0.0.1', 25444
DAEMON_SHA256 = '0047b52e042647ea3a496b368ab829672917052a130cb67646c515a01c20188a'
SCHEMA = 'civilizationbot.synthetic-startup.v1'
IMAGES = ('save_bridge', 'fixture_generator')
TABLES = ('tcp', 'tcp6', 'udp', 'udp6')


def check(ok, message):
    if not ok:
        raise ValueError(message)


def parse_json(raw):
    check(type(raw) is bytes and len(raw) <= 1024, 'startup_json_limit')
    def pairs(items):
        result = {}
        for key, value in items:
            check(key not in result, 'startup_duplicate_key')
            result[key] = value
        return result
    def constant(_):
        raise ValueError('startup_nonfinite_json')
    return json.loads(raw.decode('utf-8'), object_pairs_hook=pairs, parse_constant=constant)


def regular_bytes(path, cap):
    """Bounded nonblocking, no-follow read; reject replacement/partial changes."""
    before = path.lstat()
    check(stat.S_ISREG(before.st_mode) and 0 <= before.st_size <= cap, 'startup_file_type_or_size')
    descriptor = os.open(path, os.O_RDONLY | getattr(os, 'O_NOFOLLOW', 0) | getattr(os, 'O_NONBLOCK', 0))
    with os.fdopen(descriptor, 'rb') as stream:
        opened = os.fstat(stream.fileno())
        check(stat.S_ISREG(opened.st_mode) and (opened.st_dev, opened.st_ino) == (before.st_dev, before.st_ino), 'startup_file_replaced')
        data = stream.read(cap + 1)
        after = os.fstat(stream.fileno())
    current = path.lstat()
    identity = lambda info: (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns)
    # Windows3.13 lstat/fstat expose different ctime meanings after a rewrite.
    # Compare ctime within each API pair, retaining the Linux change checks.
    check(identity(before) == identity(opened) == identity(after) == identity(current)
          and before.st_ctime_ns == current.st_ctime_ns and opened.st_ctime_ns == after.st_ctime_ns
          and len(data) == before.st_size and len(data) <= cap, 'startup_file_changed')
    return data


def atomic_bytes(path, raw):
    check(type(raw) is bytes and len(raw) <= 1024, 'startup_control_limit')
    try:
        path.lstat()
    except FileNotFoundError:
        pass
    else:
        raise ValueError('startup_control_already_exists')
    partial = path.with_name(path.name + '.partial')
    descriptor = os.open(partial, os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, 'O_NOFOLLOW', 0), 0o600)
    with os.fdopen(descriptor, 'wb') as stream:
        stream.write(raw)
        stream.flush()
        os.fsync(stream.fileno())
    os.replace(partial, path)


def instrument(source, image):
    """Derivative of the hash-verified original; no edit to public root bridge."""
    check(image in IMAGES and type(source) is bytes, 'startup_source_binding')
    marker = b'\n/world/New()\n\t..()\n'
    check(source.count(marker) == 1 and b'ss_startup' not in source, 'startup_source_shape_changed')
    insertion = ('\n/world/New()\n\tif(!ss_startup("' + image + '"))\n\t\tshutdown()\n\t\treturn\n\t..()\n').encode('ascii')
    return b'#include "startup_guard.dm"\n' + source.replace(marker, insertion)


def stat_starttime(raw, pid):
    text = raw.decode('ascii')
    close = text.rfind(') ')
    check(text.startswith(str(pid) + ' (') and close > 0, 'startup_proc_stat_shape')
    fields = text[close + 2:].split()
    check(len(fields) >= 20 and fields[19].isdigit() and int(fields[19]) > 0, 'startup_proc_starttime')
    return int(fields[19])


def parse_table(raw, table):
    """Namespace-wide rows. Caller joins ONLY owned/current-or-prior inodes."""
    check(table in TABLES and type(raw) is bytes and len(raw) <= 131072, 'startup_socket_table_limit')
    lines = raw.decode('ascii').splitlines()
    check(0 < len(lines) <= 4097 and 'local_address' in lines[0], 'startup_socket_table_shape')
    rows = []
    width = 8 if table in ('tcp', 'udp') else 32
    for line in lines[1:]:
        fields = line.split()
        check(len(fields) >= 10 and fields[9].isdigit(), 'startup_socket_row_shape')
        for endpoint in fields[1:3]:
            check(re.fullmatch('[0-9A-Fa-f]{' + str(width) + '}:[0-9A-Fa-f]{4}', endpoint) is not None, 'startup_socket_address_shape')
        check(re.fullmatch('[0-9A-Fa-f]{2}', fields[3]) is not None, 'startup_socket_state_shape')
        rows.append({'table': table, 'inode': int(fields[9]), 'local': fields[1].upper(),
                     'remote': fields[2].upper(), 'state': fields[3].upper()})
    return rows


class ProcReader:
    """No process enumeration, port-owner search, signals or socket connections."""
    def __init__(self, executable):
        self.executable = Path(executable).absolute()
        check(self.executable.resolve() == self.executable, 'startup_executable_symlink')
        info = self.executable.lstat()
        check(stat.S_ISREG(info.st_mode), 'startup_executable_type')
        check(hashlib.sha256(regular_bytes(self.executable, 8388608)).hexdigest() == DAEMON_SHA256, 'startup_executable_pin')
        self.executable_identity = (info.st_dev, info.st_ino)

    def read(self, path, cap):
        # procfs virtual files report size0. Paths originate only from an exact
        # owned numeric PID and the fixed table/stat names below.
        descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        with os.fdopen(descriptor, 'rb') as stream:
            check(stat.S_ISREG(os.fstat(stream.fileno()).st_mode), 'startup_proc_file_type')
            data = stream.read(cap + 1)
        check(len(data) <= cap, 'startup_proc_read_limit')
        return data

    def identity(self, pid):
        base = Path('/proc') / str(pid)
        starttime = stat_starttime(self.read(base / 'stat', 8192), pid)
        link = os.readlink(base / 'exe')  # Intentional kernel exe magic link.
        check(len(link) <= 4096 and link == str(self.executable), 'startup_owned_executable_changed')
        info = (base / 'exe').stat()
        check((info.st_dev, info.st_ino) == self.executable_identity and stat.S_ISREG(info.st_mode), 'startup_owned_executable_changed')
        return starttime

    def census(self, pid):
        base = Path('/proc') / str(pid)
        inodes = set()
        count = 0
        with os.scandir(base / 'fd') as entries:
            for entry in entries:
                count += 1
                check(count <= 64 and entry.name.isdigit(), 'startup_fd_inventory_limit')
                link = os.readlink(entry.path)
                check(len(link) <= 4096, 'startup_fd_link_limit')
                if link.startswith('socket:'):
                    match = re.fullmatch(r'socket:\[([1-9][0-9]*)\]', link)
                    check(match is not None, 'startup_socket_inode_shape')
                    inodes.add(int(match.group(1)))
        rows = []
        for name in TABLES:
            rows.extend(parse_table(self.read(base / 'net' / name, 131072), name))
        return inodes, rows


class StartupObserver:
    def __init__(self, stage, executable, image, image_sha256, *, proc=None, nonce=None):
        check(image in IMAGES and re.fullmatch('[a-f0-9]{64}', image_sha256) is not None, 'startup_image_binding')
        self.stage, self.image = Path(stage), image
        self.nonce = nonce if nonce is not None else secrets.token_hex(16)
        check(re.fullmatch('[a-f0-9]{32}', self.nonce) is not None, 'startup_nonce')
        self.proc = proc if proc is not None else ProcReader(executable)
        self.phase = 'prepared'
        self.pid = self.starttime = self.started = self.deadline = self.close_deadline = None
        self.inode = None
        self.record = {'schema': SCHEMA, 'status': 'incomplete', 'image': image, 'image_sha256': image_sha256,
                       'executable_sha256': DAEMON_SHA256, 'address': ADDRESS, 'port': PORT, 'protocol': 'TCP',
                       'os_egress_containment': False, 'network_intent': 'temporary loopback listener; no intended outbound activity',
                       'verification_scope': 'owned-PID startup handshake samples, not continuous network tracing',
                       'startup_close_limit_ms': 1000, 'child_limit_ms': 10000, 'phase': self.phase,
                       'owner_token': None, 'pid': None, 'starttime_ticks': None, 'listener_inode': None,
                       'ready_ms': None, 'closed_ms': None, 'release_ms': None, 'exit_ms': None,
                       'same_inode_absent_before_release': False, 'exit_confirmed': False, 'exit_code': None}
        config = {'schema': SCHEMA, 'nonce': self.nonce, 'image': self.image, 'port': PORT}
        atomic_bytes(self.stage / 'startup-config.json', json.dumps(config, separators=(',', ':')).encode('ascii'))

    def elapsed_ms(self):
        return max(0, int((time.monotonic() - self.started) * 1000))

    def attach(self, child, pid, started, deadline, owner_token):
        check(self.phase == 'prepared' and child.pid == pid and type(pid) is int and pid > 0, 'startup_owned_handle')
        # Use the same addition that created the original deadline. Subtraction
        # can round differently near powers of two; never widen the budget.
        check(type(started) in (int, float) and type(deadline) in (int, float)
              and math.isfinite(started) and math.isfinite(deadline)
              and deadline == started + 10 and deadline > started, 'startup_original_deadline')
        self.pid, self.started, self.deadline = pid, started, deadline
        self.close_deadline = min(deadline - .75, started + 1)
        self.record.update(owner_token=owner_token, pid=pid)
        self.starttime = self.proc.identity(pid)
        self.record['starttime_ticks'] = self.starttime
        self.phase = 'waiting_ready'
        self.record['phase'] = self.phase

    def identity(self):
        check(self.proc.identity(self.pid) == self.starttime, 'startup_owned_identity_changed')

    def receipt(self, phase):
        marker = self.stage / ('startup-' + phase + '.done')
        try:
            data = regular_bytes(marker, 32)
        except FileNotFoundError:
            return None
        check(data == self.nonce.encode('ascii'), 'startup_marker_nonce')
        value = parse_json(regular_bytes(self.stage / ('startup-' + phase + '.json'), 1024))
        check(type(value) is dict and set(value) == {'schema', 'nonce', 'image', 'phase', 'runtime', 'world_port'}, 'startup_receipt_shape')
        check(value['schema'] == SCHEMA and value['nonce'] == self.nonce and value['image'] == self.image and value['phase'] == phase and value['runtime'] == '516.1688', 'startup_receipt_binding')
        check(type(value['world_port']) is int and value['world_port'] == (PORT if phase == 'ready' else 0), 'startup_world_port')
        return value

    def acknowledge(self, phase):
        payload = {'schema': SCHEMA, 'nonce': self.nonce, 'image': self.image, 'phase': phase}
        atomic_bytes(self.stage / ('startup-' + phase + '.ack'), json.dumps(payload, separators=(',', ':')).encode('ascii'))

    def tick(self):
        if self.phase == 'released':
            return
        check(self.phase in ('waiting_ready', 'waiting_closed'), 'startup_observer_phase')
        check(time.monotonic() < self.close_deadline, 'startup_close_timeout')
        self.identity()
        phase = 'ready' if self.phase == 'waiting_ready' else 'closed'
        if self.receipt(phase) is None:
            return
        inodes, rows = self.proc.census(self.pid)
        self.identity()
        if phase == 'ready':
            check(len(inodes) == 1, 'startup_owned_socket_count')
            self.inode = next(iter(inodes))
            owned = [r for r in rows if r['inode'] == self.inode]
            check(len(owned) == 1, 'startup_owned_socket_unmapped_or_duplicate')
            row = owned[0]
            check(row['table'] == 'tcp' and row['local'] == '0100007F:6364' and row['remote'] == '00000000:0000' and row['state'] == '0A', 'startup_unexpected_endpoint_or_connection')
            check(time.monotonic() < self.close_deadline, 'startup_close_timeout')
            self.record.update(listener_inode=self.inode, ready_ms=self.elapsed_ms())
            self.acknowledge('close')
            self.phase = 'waiting_closed'
        else:
            check(not inodes and not any(r['inode'] == self.inode for r in rows), 'startup_listener_closure_unconfirmed')
            check(time.monotonic() < self.close_deadline, 'startup_close_timeout')
            self.record.update(closed_ms=self.elapsed_ms(), same_inode_absent_before_release=True)
            self.acknowledge('release')
            check(time.monotonic() < self.close_deadline, 'startup_close_timeout')
            self.record['release_ms'] = self.elapsed_ms()
            self.phase = 'released'
        self.record['phase'] = self.phase

    def finish(self, code):
        check(self.phase == 'released' and code == 0, 'startup_exit_before_verified_release')
        check(time.monotonic() <= self.deadline, 'startup_original_deadline_exceeded')
        check(self.receipt('started') is not None, 'startup_fixture_start_unconfirmed')
        self.record.update(exit_confirmed=True, exit_code=code, exit_ms=self.elapsed_ms(), status='verified')
        validate_evidence(self.record, self.image)

    def evidence(self):
        # Pure in-memory snapshot: safe even if child exit is unconfirmed.
        return dict(self.record)


def validate_evidence(value, image):
    check(type(value) is dict and value.get('schema') == SCHEMA and value.get('status') == 'verified', 'startup_evidence_missing')
    check(value.get('image') == image and image in IMAGES and type(value.get('image_sha256')) is str and re.fullmatch('[a-f0-9]{64}', value['image_sha256']) is not None, 'startup_evidence_image')
    check(value.get('executable_sha256') == DAEMON_SHA256 and value.get('os_egress_containment') is False, 'startup_evidence_scope')
    check(value.get('address') == ADDRESS and type(value.get('port')) is int and value['port'] == PORT and value.get('protocol') == 'TCP', 'startup_evidence_endpoint')
    check(type(value.get('owner_token')) is str and re.fullmatch('owned-child-[1-9][0-9]*', value['owner_token']) is not None, 'startup_evidence_owner')
    for key in ['pid', 'starttime_ticks', 'listener_inode']:
        check(type(value.get(key)) is int and value[key] > 0, 'startup_evidence_identity')
    check(value.get('phase') == 'released' and value.get('same_inode_absent_before_release') is True and value.get('exit_confirmed') is True and type(value.get('exit_code')) is int and value['exit_code'] == 0, 'startup_evidence_closure_or_exit')
    check(value.get('startup_close_limit_ms') == 1000 and value.get('child_limit_ms') == 10000, 'startup_evidence_budget')
    times = [value.get(key) for key in ['ready_ms', 'closed_ms', 'release_ms', 'exit_ms']]
    check(all(type(v) is int and v >= 0 for v in times) and times[0] <= times[1] <= times[2] < 1000 and times[2] <= times[3] <= 10000, 'startup_evidence_time_order')
    return True
