import json
from pathlib import Path
import sys
import unittest
from unittest.mock import patch
sys.path.insert(0,str(Path(__file__).resolve().parent))
import socket_family_diagnostics as family

UNIX = b'Num RefCount Protocol Flags Type St Inode Path\n'
NETLINK = b'sk Eth Pid Groups Rmem Wmem Dump Locks Drops Inode\n'
def unix(inode=101,path=b'/do-not-retain'):
    return b'0000000000000000: 00000002 00000000 00000000 0001 03 '+str(inode).encode()+b' '+path+b'\n'
def netlink(inode=102):
    return b'0000000000000000 0 123 00000000 0 0 0 2 0 '+str(inode).encode()+b'\n'

class Proc:
    def __init__(self):
        self.calls=[]; self.tables={'unix':UNIX+unix()+unix(999), 'netlink':NETLINK+netlink()}
    def identity(self,pid):
        self.calls.append(('identity',pid)); return 900
    def read(self,path,cap):
        self.calls.append(('read',str(path),cap)); return self.tables[path.name]

class Tests(unittest.TestCase):
    def setUp(self):
        self.proc=Proc(); self.now=0
        self.fds=[{'fd':'3','link_prefix':'socket:[101]','link_bytes':12,'link_truncated':False},
                  {'fd':'4','link_prefix':'socket:[102]','link_bytes':12,'link_truncated':False}]
        self.links={'3':'socket:[101]','4':'socket:[102]'}
        for target in ['subprocess.Popen','socket.socket','os.scandir']:
            guard=patch(target,side_effect=AssertionError('No process, connection, or scan'))
            guard.start(); self.addCleanup(guard.stop)
    def run_sample(self):
        return family.diagnose(self.proc,817263,900,self.fds,.05,clock=lambda:self.now,
                               readlink=lambda path:self.links[path.name])
    def test_unix_netlink_are_observed_not_allowed(self):
        result=self.run_sample()
        self.assertEqual(result['status'],'stable_sample')
        self.assertEqual([r['family'] for r in result['classifications']],['AF_UNIX','AF_NETLINK'])
        self.assertIn('strict startup failure remains failure',result['policy_effect'])
        self.assertNotIn('do-not-retain',json.dumps(result))
        self.assertNotIn('999',json.dumps(result))
    def test_unsupported_family_stays_unknown(self):
        self.proc.tables={'unix':UNIX,'netlink':NETLINK}
        self.assertTrue(all(r['family'] is None for r in self.run_sample()['classifications']))
    def test_duplicate_match_is_ambiguous(self):
        self.proc.tables['unix']+=unix()
        result=self.run_sample()
        self.assertEqual(result['classifications'][0]['status'],'ambiguous')
        self.assertIsNone(result['classifications'][0]['family'])
    def test_unreadable_table_cannot_classify_partial_candidates(self):
        original=self.proc.read
        def read(path,cap):
            if path.name=='netlink': raise PermissionError('secret path')
            return original(path,cap)
        self.proc.read=read
        result=self.run_sample()
        self.assertEqual(result['error_type'],'PermissionError')
        self.assertTrue(all(r['family'] is None for r in result['classifications']))
        self.assertNotIn('secret',json.dumps(result))
    def test_changed_identity_is_unknown(self):
        self.proc.identity=lambda pid:901
        result=self.run_sample()
        self.assertEqual(result['reason'],'family_identity_changed')
        self.assertFalse(any(call[0]=='read' for call in self.proc.calls))
    def test_changed_fd_before_tables_is_unknown(self):
        self.links['3']='socket:[103]'
        result=self.run_sample()
        self.assertEqual(result['reason'],'family_fd_changed')
        self.assertFalse(any(call[0]=='read' for call in self.proc.calls))
    def test_changed_fd_after_tables_invalidates_family(self):
        calls=0
        def readlink(path):
            nonlocal calls
            calls+=1
            return 'socket:[103]' if calls>2 else self.links[path.name]
        result=family.diagnose(self.proc,817263,900,self.fds,.05,clock=lambda:0,readlink=readlink)
        self.assertEqual(result['reason'],'family_fd_changed')
        self.assertTrue(all(r['family'] is None for r in result['classifications']))
    def test_budget_expired_reads_nothing(self):
        self.now=.05
        result=self.run_sample()
        self.assertEqual(result['reason'],'family_budget_exhausted')
        self.assertEqual(self.proc.calls,[])
    def test_budget_consumed_in_read_stops_before_next_table(self):
        original=self.proc.read
        def read(path,cap):
            result=original(path,cap); self.now=.06; return result
        self.proc.read=read
        result=self.run_sample()
        self.assertEqual(result['reason'],'family_budget_exhausted')
        self.assertEqual(len([c for c in self.proc.calls if c[0]=='read']),1)
    def test_oversize_and_bad_headers_remain_incomplete(self):
        for raw in [b'x'*131073,b'wrong header\n',UNIX+b'broken\n']:
            with self.subTest(raw=raw[:10]):
                self.proc.tables['unix']=raw
                self.assertNotEqual(self.run_sample()['status'],'stable_sample')
    def test_nonascii_unix_path_is_omitted(self):
        self.proc.tables['unix']=UNIX+unix(path=b'@private\xffdata')
        result=self.run_sample()
        self.assertEqual(result['classifications'][0]['family'],'AF_UNIX')
        self.assertNotIn('private',json.dumps(result))
    def test_invalid_input_no_io(self):
        for fd in ['../3','03','64','-1']:
            self.fds[0]['fd']=fd
            with self.subTest(fd=fd),self.assertRaises(ValueError):self.run_sample()
        self.assertEqual(self.proc.calls,[])
    def test_excess_budget_rejected(self):
        with self.assertRaises(ValueError):
            family.diagnose(self.proc,817263,900,self.fds,1,clock=lambda:0,readlink=lambda p:'')
        self.assertEqual(self.proc.calls,[])
    def test_output_is_bounded(self):
        self.assertLessEqual(len(json.dumps(self.run_sample()).encode()),32768)
    def test_current_artifact_not_reclassified(self):
        # No saved UNIX/netlink tables exist for the real failed run.
        self.proc.tables={'unix':UNIX,'netlink':NETLINK}
        self.assertEqual([r['status'] for r in self.run_sample()['classifications']],['unknown','unknown'])

    def test_changed_identity_after_tables_invalidates_family(self):
        ticks=iter([900,901])
        self.proc.identity=lambda pid:next(ticks)
        result=self.run_sample()
        self.assertEqual(result['reason'],'family_identity_changed')
        self.assertTrue(all(r['family'] is None for r in result['classifications']))
    def test_truncated_candidate_rows_never_prove_a_family(self):
        self.proc.tables['unix']=UNIX+unix()*17
        result=self.run_sample()
        self.assertTrue(result['tables']['unix']['matches_truncated'])
        self.assertTrue(all(r['family'] is None for r in result['classifications']))

    def test_final_identity_overrun_is_incomplete_and_unknown(self):
        calls=0
        def identity(pid):
            nonlocal calls
            calls+=1
            if calls==2:self.now=.06
            return 900
        self.proc.identity=identity
        result=self.run_sample()
        self.assertEqual(result['status'],'incomplete')
        self.assertEqual(result['reason'],'family_budget_exhausted')
        self.assertTrue(all(row['family'] is None for row in result['classifications']))

if __name__=='__main__':unittest.main()
