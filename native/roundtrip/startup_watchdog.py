"""Owned Linux startup listener observation for trusted synthetic images only.

No OS egress containment is installed or claimed. The intended effect is one
temporary loopback listener, no outbound operations. All process/cleanup
authority remains with owned_process and its exact Popen handle.
"""
import hashlib
from functools import wraps
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


def error_summary(error):
    """Only fixed contract reasons; never echo arbitrary OS/parser messages."""
    reason = str(error) if type(error) is ValueError else ''
    return {'error_type': type(error).__name__,
            'reason': reason if re.fullmatch('startup_[a-z0-9_]{1,80}', reason) else None}


def diagnostic_scalar(value):
    """Small received-field observations, with explicit unsupported type tags."""
    if value is None or type(value) is bool: return value
    if type(value) is int and -(2 ** 63) <= value < 2 ** 63: return value
    if type(value) is float: return {'finite': math.isfinite(value), 'hex': value.hex()}
    if type(value) is str: return {'text_prefix': value[:128], 'characters': len(value), 'truncated': len(value) > 128}
    return {'type': type(value).__name__}


def retain_fault(attribute):
    """Freeze existing in-memory inputs on failure; no diagnostic I/O/retry."""
    def decorate(method):
        @wraps(method)
        def wrapped(self, *args, **kwargs):
            try:
                return method(self, *args, **kwargs)
            except BaseException as error:
                try:
                    if attribute is None:
                        partial = getattr(self.proc, 'last_census', None)
                        partial = partial if type(partial) is dict else {}
                        self.record['rejection'] = {'operation': method.__name__, 'point': self.point,
                                                    'phase': self.phase, **error_summary(error),
                                                    'pid': self.pid, 'owner_token': self.record.get('owner_token'),
                                                    'expected_starttime_ticks': self.starttime, 'capture_scope': 'summary_only',
                                                    'proc_census_status': partial.get('status'),
                                                    'proc_socket_count': partial.get('socket_count'),
                                                    'fd_scan_complete': partial.get('fd_scan_complete'),
                                                    'socket_count': self.decision.get('socket_count'),
                                                    'matching_rows_count': self.decision.get('matching_rows_count')}
                        snapshot = {'operation': method.__name__, 'point': self.point, 'phase': self.phase, 'capture_scope': 'bounded_decision_snapshot',
                                    'pid': self.pid, 'owner_token': self.record.get('owner_token'),
                                    'expected_starttime_ticks': self.starttime, **error_summary(error),
                                    'decision': self.decision, 'receipt': self.last_receipt,
                                    'proc_identity': getattr(self.proc, 'last_identity', None),
                                    'proc_census': getattr(self.proc, 'last_census', None),
                                    'proc_read': getattr(self.proc, 'last_read', None), 'tick_index': self.tick_index,
                                    'started_clock': diagnostic_scalar(self.started), 'original_deadline_clock': diagnostic_scalar(self.deadline)}
                        raw = json.dumps(snapshot, allow_nan=False, separators=(',', ':')).encode('utf-8')
                        check(len(raw) <= 32768, 'startup_diagnostic_limit')
                        self.record['rejection'] = json.loads(raw)  # Freeze BEFORE cleanup.
                    else:
                        getattr(self, attribute).update(status='failed', **error_summary(error))
                except BaseException as diagnostic_error:
                    if attribute is None:
                        self.record['rejection_capture_error'] = type(diagnostic_error).__name__
                raise  # Always retain the original exception identity.
        return wrapped
    return decorate


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


def regular_bytes(path, cap, *, observation=None):
    """Bounded nonblocking, no-follow read; reject replacement/partial changes."""
    before = path.lstat()
    if observation is not None:
        observation.update(point='type_size_validate', mode=before.st_mode, size=before.st_size,
                           device=before.st_dev, inode=before.st_ino, cap=cap)
    check(stat.S_ISREG(before.st_mode) and 0 <= before.st_size <= cap, 'startup_file_type_or_size')
    descriptor = os.open(path, os.O_RDONLY | getattr(os, 'O_NOFOLLOW', 0) | getattr(os, 'O_NONBLOCK', 0))
    with os.fdopen(descriptor, 'rb') as stream:
        opened = os.fstat(stream.fileno())
        if observation is not None:
            observation.update(opened_mode=opened.st_mode, opened_device=opened.st_dev, opened_inode=opened.st_ino)
        check(stat.S_ISREG(opened.st_mode) and (opened.st_dev, opened.st_ino) == (before.st_dev, before.st_ino), 'startup_file_replaced')
        data = stream.read(cap + 1)
        if observation is not None:
            observation.update(point='stability_validate', captured_bytes=len(data), prefix_hex=data[:64].hex(), prefix_truncated=len(data) > 64)
        after = os.fstat(stream.fileno())
    current = path.lstat()
    if observation is not None:
        observation.update(after_size=after.st_size, current_size=current.st_size,
                           current_device=current.st_dev, current_inode=current.st_ino,
                           before_mtime_ns=before.st_mtime_ns, after_mtime_ns=after.st_mtime_ns, current_mtime_ns=current.st_mtime_ns,
                           before_ctime_ns=before.st_ctime_ns, current_ctime_ns=current.st_ctime_ns,
                           opened_ctime_ns=opened.st_ctime_ns, after_ctime_ns=after.st_ctime_ns)
    identity = lambda info: (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns)
    # Windows3.13 lstat/fstat expose different ctime meanings after a rewrite.
    # Compare ctime within each API pair, retaining the Linux change checks.
    check(identity(before) == identity(opened) == identity(after) == identity(current)
          and before.st_ctime_ns == current.st_ctime_ns and opened.st_ctime_ns == after.st_ctime_ns
          and len(data) == before.st_size and len(data) <= cap, 'startup_file_changed')
    if observation is not None:
        observation['point'] = 'complete'
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


def closed_marker_snapshot(path):
    """After confirmed owned exit only: fixed marker stat and at most64 bytes.

    This diagnostic never admits a receipt or follows a nonregular file.
    Truncated prefixes have explicitly scoped hashes, never full-file hashes.
    """
    cap = 64
    before = path.lstat()
    result = {'file': path.name, 'mode': before.st_mode, 'size': before.st_size,
              'device': before.st_dev, 'inode': before.st_ino,
              'mtime_ns': before.st_mtime_ns, 'ctime_ns': before.st_ctime_ns}
    if not stat.S_ISREG(before.st_mode):
        return dict(result, kind='nonregular_not_read')
    descriptor = os.open(path, os.O_RDONLY | getattr(os, 'O_NOFOLLOW', 0) | getattr(os, 'O_NONBLOCK', 0))
    with os.fdopen(descriptor, 'rb') as stream:
        opened = os.fstat(stream.fileno())
        check(stat.S_ISREG(opened.st_mode) and (opened.st_dev, opened.st_ino) == (before.st_dev, before.st_ino), 'startup_diagnostic_replaced')
        raw = stream.read(cap)
        after = os.fstat(stream.fileno())
    current = path.lstat()
    identity = lambda info: (info.st_dev, info.st_ino, info.st_size, info.st_mtime_ns)
    check(identity(before) == identity(opened) == identity(after) == identity(current)
          and before.st_ctime_ns == current.st_ctime_ns and opened.st_ctime_ns == after.st_ctime_ns
          and len(raw) == min(before.st_size, cap), 'startup_diagnostic_changed')
    return dict(result, kind='complete_snapshot' if before.st_size <= cap else 'bounded_prefix',
                captured_bytes=len(raw), bytes_hex=raw.hex(), sha256_snapshot=hashlib.sha256(raw).hexdigest())


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


def parse_table(raw, table, *, observation=None, relevant=frozenset()):
    """Namespace-wide rows. Caller joins ONLY owned/current-or-prior inodes."""
    check(table in TABLES and type(raw) is bytes and len(raw) <= 131072, 'startup_socket_table_limit')
    if observation is not None:
        observation.update(bytes=len(raw), sha256_input=hashlib.sha256(raw).hexdigest(), rows_seen=0, matching_rows=[])
    lines = raw.decode('ascii').splitlines()
    check(0 < len(lines) <= 4097 and 'local_address' in lines[0], 'startup_socket_table_shape')
    rows = []
    width = 8 if table in ('tcp', 'udp') else 32
    for index, line in enumerate(lines[1:], 1):
        fields = line.split()
        selected = len(fields) >= 10 and fields[9].isdigit() and len(fields[9]) <= 20 and int(fields[9]) in relevant
        if observation is not None:
            observation['rows_seen'] = index
            observation['current_row'] = {'index': index, 'bytes': len(line), 'sha256_input': hashlib.sha256(line.encode('ascii')).hexdigest(), 'owned_or_prior_inode': selected}
            if selected and len(observation['matching_rows']) < 4:
                observation['matching_rows'].append({'raw_prefix_hex': line[:256].encode('ascii').hex(), 'raw_bytes': len(line), 'prefix_truncated': len(line) > 256})
            elif selected:
                observation['matching_rows_truncated'] = True
        check(len(fields) >= 10 and fields[9].isdigit(), 'startup_socket_row_shape')
        for endpoint in fields[1:3]:
            check(re.fullmatch('[0-9A-Fa-f]{' + str(width) + '}:[0-9A-Fa-f]{4}', endpoint) is not None, 'startup_socket_address_shape')
        check(re.fullmatch('[0-9A-Fa-f]{2}', fields[3]) is not None, 'startup_socket_state_shape')
        rows.append({'table': table, 'inode': int(fields[9]), 'local': fields[1].upper(),
                     'remote': fields[2].upper(), 'state': fields[3].upper()})
        if observation is not None and selected and len(observation['matching_rows']) <= 4 and not observation.get('matching_rows_truncated'):
            observation['matching_rows'][-1]['parsed'] = dict(rows[-1])
    if observation is not None:
        observation['status'] = 'complete'
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

    @retain_fault('last_read')
    def read(self, path, cap):
        # procfs virtual files report size0. Paths originate only from an exact
        # owned numeric PID and the fixed table/stat names below.
        self.last_read = {'file': path.name, 'cap': cap, 'point': 'open', 'status': 'reading'}
        descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        with os.fdopen(descriptor, 'rb') as stream:
            mode = os.fstat(stream.fileno()).st_mode
            self.last_read.update(mode=mode, point='type_validate')
            check(stat.S_ISREG(mode), 'startup_proc_file_type')
            self.last_read['point'] = 'read'
            data = stream.read(cap + 1)
        # No table row payload here: the census separately retains owned joins.
        self.last_read.update(captured_bytes=len(data), point='size_validate')
        check(len(data) <= cap, 'startup_proc_read_limit')
        self.last_read.update(status='complete', point='read_complete')
        return data

    @retain_fault('last_identity')
    def identity(self, pid):
        self.last_identity = {'pid': pid, 'status': 'reading', 'point': 'stat_read',
                              'expected_executable_sha256': DAEMON_SHA256,
                              'expected_device_inode': list(self.executable_identity),
                              'observer_context': dict(getattr(self, 'trace_context', {}))}
        base = Path('/proc') / str(pid)
        raw = self.read(base / 'stat', 8192)
        self.last_identity.update(point='stat_parse', stat_bytes=len(raw), stat_prefix_hex=raw[:128].hex(), stat_sha256_input=hashlib.sha256(raw).hexdigest())
        starttime = stat_starttime(raw, pid)
        self.last_identity.update(starttime_ticks=starttime, point='exe_readlink')
        link = os.readlink(base / 'exe')  # Intentional kernel exe magic link.
        self.last_identity.update(executable_link_bytes=len(link), executable_link_prefix=link[:128], executable_link_truncated=len(link) > 128, executable_link_matches=link == str(self.executable), point='exe_link_validate')
        check(len(link) <= 4096 and link == str(self.executable), 'startup_owned_executable_changed')
        self.last_identity['point'] = 'exe_stat'
        info = (base / 'exe').stat()
        self.last_identity.update(observed_device_inode=[info.st_dev, info.st_ino], observed_mode=info.st_mode, point='exe_identity_validate')
        check((info.st_dev, info.st_ino) == self.executable_identity and stat.S_ISREG(info.st_mode), 'startup_owned_executable_changed')
        self.last_identity.update(status='complete', point='identity_complete')
        return starttime

    @retain_fault('last_census')
    def census(self, pid):
        self.last_census = {'pid': pid, 'status': 'reading', 'point': 'fd_scan', 'fd_entries_seen': 0,
                            'socket_fds': [], 'socket_inodes': [], 'tables': {},
                            'socket_count': 0, 'fd_scan_complete': False,
                            'prior_listener_inode': getattr(self, 'prior_inode', None),
                            'observer_context': dict(getattr(self, 'trace_context', {}))}
        base = Path('/proc') / str(pid)
        inodes = set()
        count = 0
        with os.scandir(base / 'fd') as entries:
            for entry in entries:
                count += 1
                self.last_census.update(fd_entries_seen=count, current_fd=entry.name[:16], point='fd_name_validate')
                check(count <= 64 and entry.name.isdigit(), 'startup_fd_inventory_limit')
                self.last_census['point'] = 'fd_readlink'
                link = os.readlink(entry.path)
                self.last_census.update(point='fd_link_validate', current_link_bytes=len(link))
                check(len(link) <= 4096, 'startup_fd_link_limit')
                if link.startswith('socket:'):
                    self.last_census['socket_fds'].append({'fd': entry.name[:16], 'link_prefix': link[:64], 'link_bytes': len(link), 'link_truncated': len(link) > 64})
                    match = re.fullmatch(r'socket:\[([1-9][0-9]*)\]', link)
                    check(match is not None, 'startup_socket_inode_shape')
                    inodes.add(int(match.group(1)))
                    self.last_census.update(socket_inodes=sorted(inodes), socket_count=len(inodes))
        rows = []
        self.last_census.update(socket_count=len(inodes), socket_inodes=sorted(inodes), fd_scan_complete=True)
        relevant = inodes | ({self.prior_inode} if getattr(self, 'prior_inode', None) is not None else set())
        for name in TABLES:
            self.last_census.update(point='table_read', current_table=name)
            observation = self.last_census['tables'][name] = {'status': 'reading'}
            raw = self.read(base / 'net' / name, 131072)
            self.last_census['point'] = 'table_parse'
            rows.extend(parse_table(raw, name, observation=observation, relevant=relevant))
        self.last_census.update(status='complete', point='census_complete')
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
        self.marker_phase = None
        self.point, self.decision, self.last_receipt = 'prepared', {}, None
        self.tick_index = 0
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

    def close_time_check(self):
        now = time.monotonic()
        self.decision.update(sampled_monotonic=now, close_deadline=self.close_deadline)
        check(now < self.close_deadline, 'startup_close_timeout')

    @retain_fault(None)
    def attach(self, child, pid, started, deadline, owner_token):
        self.point = 'attach_handle_validate'
        check(self.phase == 'prepared', 'startup_owned_handle')
        observed_pid = child.pid
        self.decision.update(attach_pid=diagnostic_scalar(pid), handle_pid=diagnostic_scalar(observed_pid))
        check(observed_pid == pid and type(pid) is int and pid > 0, 'startup_owned_handle')
        # Use the same addition that created the original deadline. Subtraction
        # can round differently near powers of two; never widen the budget.
        self.point = 'attach_original_deadline_validate'
        self.decision.update(attach_started=diagnostic_scalar(started), attach_deadline=diagnostic_scalar(deadline))
        check(type(started) in (int, float) and type(deadline) in (int, float)
              and math.isfinite(started) and math.isfinite(deadline)
              and deadline == started + 10 and deadline > started, 'startup_original_deadline')
        self.pid, self.started, self.deadline = pid, started, deadline
        self.close_deadline = min(deadline - .75, started + 1)
        self.record.update(owner_token=owner_token, pid=pid)
        self.point = 'attach_identity'
        self.proc.trace_context = {'tick_index': self.tick_index, 'phase': self.phase, 'point': self.point}
        self.starttime = self.proc.identity(pid)
        self.record['starttime_ticks'] = self.starttime
        self.phase = 'waiting_ready'
        self.record['phase'] = self.phase

    def identity(self):
        self.proc.trace_context = {'tick_index': self.tick_index, 'phase': self.phase, 'point': self.point}
        actual = self.proc.identity(self.pid)
        self.decision['observed_starttime_ticks'] = actual
        check(actual == self.starttime, 'startup_owned_identity_changed')

    @retain_fault(None)
    def receipt(self, phase):
        self.point = 'receipt_phase_validate'
        check(phase in ('ready', 'closed', 'started'), 'startup_receipt_phase')
        self.marker_phase = phase
        marker = self.stage / ('startup-' + phase + '.done')
        self.last_receipt = {'phase': phase, 'point': 'marker_read', 'marker': marker.name, 'tick_index': self.tick_index}
        self.point = 'receipt_marker_read'
        try:
            self.last_receipt['marker_read'] = {}
            data = regular_bytes(marker, 32, observation=self.last_receipt['marker_read'])
        except FileNotFoundError:
            self.last_receipt['marker_missing'] = True
            return None
        self.last_receipt.update(marker_bytes=len(data), marker_hex=data.hex(), marker_matches=data == self.nonce.encode('ascii'))
        self.point = 'receipt_marker_validate'
        check(data == self.nonce.encode('ascii'), 'startup_marker_nonce')
        self.point = 'receipt_json_read'
        self.last_receipt['json_read'] = {}
        raw = regular_bytes(self.stage / ('startup-' + phase + '.json'), 1024, observation=self.last_receipt['json_read'])
        self.last_receipt.update(json_bytes=len(raw), json_prefix_hex=raw[:128].hex(), json_prefix_truncated=len(raw) > 128, json_sha256_input=hashlib.sha256(raw).hexdigest())
        self.point = 'receipt_json_parse'
        value = parse_json(raw)
        self.last_receipt.update(json_type=type(value).__name__, json_keys=[key[:64] for key in list(value)[:16]] if type(value) is dict else None)
        self.point = 'receipt_shape_validate'
        check(type(value) is dict and set(value) == {'schema', 'nonce', 'image', 'phase', 'runtime', 'world_port'}, 'startup_receipt_shape')
        self.last_receipt['observed_fields'] = {key: diagnostic_scalar(value[key]) for key in ['schema', 'nonce', 'image', 'phase', 'runtime', 'world_port']}
        self.last_receipt['binding_matches'] = {key: value[key] == expected for key, expected in [('schema', SCHEMA), ('nonce', self.nonce), ('image', self.image), ('phase', phase), ('runtime', '516.1688')]}
        self.point = 'receipt_binding_validate'
        check(value['schema'] == SCHEMA and value['nonce'] == self.nonce and value['image'] == self.image and value['phase'] == phase and value['runtime'] == '516.1688', 'startup_receipt_binding')
        self.last_receipt['world_port_matches'] = type(value['world_port']) is int and value['world_port'] == (PORT if phase == 'ready' else 0)
        self.point = 'receipt_world_port_validate'
        check(type(value['world_port']) is int and value['world_port'] == (PORT if phase == 'ready' else 0), 'startup_world_port')
        return value

    @retain_fault(None)
    def acknowledge(self, phase):
        self.point = 'ack_' + phase + '_publish'
        payload = {'schema': SCHEMA, 'nonce': self.nonce, 'image': self.image, 'phase': phase}
        atomic_bytes(self.stage / ('startup-' + phase + '.ack'), json.dumps(payload, separators=(',', ':')).encode('ascii'))

    @retain_fault(None)
    def tick(self):
        if self.phase == 'released':
            return
        self.tick_index += 1
        self.point = 'tick_phase_validate'
        check(self.phase in ('waiting_ready', 'waiting_closed'), 'startup_observer_phase')
        self.point = 'tick_close_deadline'
        self.close_time_check()
        self.point = 'tick_identity_before'
        self.identity()
        phase = 'ready' if self.phase == 'waiting_ready' else 'closed'
        if self.receipt(phase) is None:
            return
        self.point = 'tick_census'
        self.proc.prior_inode = self.inode  # Diagnostic join selector only.
        self.proc.trace_context = {'tick_index': self.tick_index, 'phase': self.phase, 'point': self.point}
        inodes, rows = self.proc.census(self.pid)
        selected = inodes | ({self.inode} if self.inode is not None else set())
        relevant_rows = [r for r in rows if r['inode'] in selected]
        self.decision = {'phase': phase, 'pid': self.pid, 'expected_starttime_ticks': self.starttime, 'tick_index': self.tick_index,
                         'socket_count': len(inodes), 'socket_inodes': sorted(inodes),
                         'cardinality': 'zero' if not inodes else ('one' if len(inodes) == 1 else 'multiple'),
                         'prior_listener_inode': self.inode, 'matching_rows': relevant_rows[:16],
                         'matching_rows_count': len(relevant_rows), 'matching_rows_truncated': len(relevant_rows) > 16}
        self.point = 'tick_identity_after'
        self.identity()
        if phase == 'ready':
            self.point = 'ready_socket_cardinality'
            check(len(inodes) == 1, 'startup_owned_socket_count')
            self.inode = next(iter(inodes))
            owned = [r for r in rows if r['inode'] == self.inode]
            self.point = 'ready_socket_mapping'
            check(len(owned) == 1, 'startup_owned_socket_unmapped_or_duplicate')
            row = owned[0]
            self.point = 'ready_socket_endpoint'
            check(row['table'] == 'tcp' and row['local'] == '0100007F:6364' and row['remote'] == '00000000:0000' and row['state'] == '0A', 'startup_unexpected_endpoint_or_connection')
            self.point = 'ready_close_deadline'
            self.close_time_check()
            self.record.update(listener_inode=self.inode, ready_ms=self.elapsed_ms())
            self.acknowledge('close')
            self.phase = 'waiting_closed'
        else:
            self.point = 'closed_socket_absence'
            check(not inodes and not any(r['inode'] == self.inode for r in rows), 'startup_listener_closure_unconfirmed')
            self.point = 'closed_close_deadline'
            self.close_time_check()
            self.record.update(closed_ms=self.elapsed_ms(), same_inode_absent_before_release=True)
            self.acknowledge('release')
            self.point = 'release_close_deadline'
            self.close_time_check()
            self.record['release_ms'] = self.elapsed_ms()
            self.phase = 'released'
        self.record['phase'] = self.phase

    @retain_fault(None)
    def finish(self, code):
        self.point = 'finish_phase_and_exit'
        self.decision['exit_code'] = code
        check(self.phase == 'released' and code == 0, 'startup_exit_before_verified_release')
        self.point = 'finish_original_deadline'
        now = time.monotonic()
        self.decision.update(sampled_monotonic=now, original_deadline=self.deadline)
        check(now <= self.deadline, 'startup_original_deadline_exceeded')
        check(self.receipt('started') is not None, 'startup_fixture_start_unconfirmed')
        self.record.update(exit_confirmed=True, exit_code=code, exit_ms=self.elapsed_ms(), status='verified')
        self.point = 'finish_evidence_validate'
        validate_evidence(self.record, self.image)

    def evidence(self):
        # Pure in-memory snapshot: safe even if child exit is unconfirmed.
        return dict(self.record)

    def capture_failed_marker(self):
        """Called by owned_process only after confirmed exit on failed runs.

        Preserve primary errors, do not retry reads or inspect arbitrary paths.
        Successful and unconfirmed children never enter this diagnostic path.
        """
        if self.marker_phase is None:
            self.record['failed_marker'] = {'kind': 'unavailable_no_receipt_attempt'}
            return
        try:
            self.record['failed_marker'] = closed_marker_snapshot(self.stage / ('startup-' + self.marker_phase + '.done'))
        except Exception as error:
            self.record['failed_marker'] = {'kind': 'unavailable_capture_failed', 'error': type(error).__name__}


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
