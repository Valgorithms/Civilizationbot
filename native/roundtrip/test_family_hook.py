"""Actual controller hook with injected identity, procfs, clock and Popen."""
import copy
import json
from pathlib import Path
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch
sys.path.insert(0,str(Path(__file__).resolve().parent))
import startup_watchdog as startup
import socket_family_diagnostics as families
import owned_process as own
import native_controller as controller
import test_startup_watchdog as base

class Proc(base.FakeProc):
    def __init__(self):
        super().__init__(); self.inodes={1234,1235}; self.reads=[]
    def census(self,pid):
        result=super().census(pid)
        self.last_census={'pid':pid,'status':'complete','fd_scan_complete':True,
            'observer_context':dict(self.trace_context),
            'socket_fds':[{'fd':str(i+3),'link_prefix':'socket:['+str(inode)+']',
                          'link_bytes':len('socket:['+str(inode)+']'),'link_truncated':False}
                         for i,inode in enumerate(sorted(result[0]))]}
        return result
    def read(self,path,cap):
        self.reads.append((str(path),cap))
        if path.name=='unix':
            return b'Num RefCount Protocol Flags Type St Inode Path\n0: 00000002 00000000 00000000 0001 03 1234 @private-name\n'
        if path.name=='netlink':
            return b'sk Eth Pid Groups Rmem Wmem Dump Locks Drops Inode\n0 0 123 00000000 0 0 0 2 0 1235\n'
        raise AssertionError('Unexpected procfs file')

class HookTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(prefix='family-hook-zero-native-')
        self.addCleanup(self.temp.cleanup)
        self.root=Path(self.temp.name)
        self.stage=self.root/'case';self.stage.mkdir()
        self.clock,self.proc=base.Clock(),Proc()
        self.calls=[]
        for target,replacement in [('monotonic',self.clock.monotonic),('sleep',self.clock.sleep)]:
            guard=patch.object(startup.time,target,side_effect=replacement);guard.start();self.addCleanup(guard.stop)
        for target in ['subprocess.Popen','socket.socket','os.scandir','os.readlink']:
            guard=patch(target,side_effect=AssertionError('No live process/network/procfs'));guard.start();self.addCleanup(guard.stop)
        self.observer=startup.StartupObserver(self.stage,'/mock/DreamDaemon','fixture_generator','a'*64,
                                             proc=self.proc,nonce='a'*32,family_sampler=self.sampler)
        self.observer.attach(SimpleNamespace(pid=817263),817263,0,10,'owned-child-3')
    def sampler(self,proc,pid,starttime,fds,limit,**kwargs):
        self.calls.append((pid,starttime,copy.deepcopy(fds),limit))
        self.assertEqual(self.observer.record['rejection']['decision']['socket_count'],2)
        return families.diagnose(proc,pid,starttime,fds,limit,clock=kwargs['clock'],
                                 readlink=lambda path:'socket:['+str(1234 if path.name=='3' else 1235)+']')
    def reject(self):
        base.publish(self.stage,'ready')
        with self.assertRaisesRegex(ValueError,'startup_owned_socket_count') as caught:self.observer.tick()
        return caught.exception
    def test_actual_sampler_is_after_frozen_rejection_and_cannot_admit(self):
        self.reject()
        diag=self.observer.record['family_diagnostic']
        self.assertEqual(diag['status'],'captured')
        self.assertEqual(diag['sample']['status'],'stable_sample')
        self.assertEqual([r['family'] for r in diag['sample']['classifications']],['AF_UNIX','AF_NETLINK'])
        self.assertEqual(self.observer.record['rejection']['point'],'ready_socket_cardinality')
        self.assertFalse((self.stage/'startup-close.ack').exists())
        self.assertEqual(self.calls[0][3],.05)
        self.assertTrue(all(str(817263) in p for p,_ in self.proc.reads))
    def test_hook_attempts_once(self):
        primary=self.reject()
        self.observer.capture_family_diagnostic(primary)
        self.assertEqual(len(self.calls),1)
    def test_primary_exception_identity_survives_sampler_failure(self):
        def fail(*args,**kwargs):raise PermissionError('secret OS path')
        self.observer.family_sampler=fail
        primary=ValueError('startup_owned_socket_count')
        original_check=startup.check
        def contract(ok,reason):
            if not ok and reason=='startup_owned_socket_count':raise primary
            return original_check(ok,reason)
        with patch.object(startup,'check',side_effect=contract):caught=self.reject()
        self.assertIs(caught,primary)
        diag=self.observer.record['family_diagnostic']
        self.assertEqual(diag['status'],'unavailable')
        self.assertEqual(diag['error_type'],'PermissionError')
        self.assertNotIn('secret',json.dumps(diag))
    def test_stale_or_incomplete_census_does_not_sample(self):
        old=self.proc.census
        def census(pid):
            result=old(pid);self.proc.last_census['observer_context']['tick_index']=-1;return result
        self.proc.census=census
        self.reject()
        self.assertEqual(self.calls,[])
        self.assertEqual(self.observer.record['family_diagnostic']['reason'],'no_current_complete_census')
    def test_original_close_deadline_caps_sample(self):
        self.clock.value=.99
        self.reject()
        self.assertEqual(self.calls[0][3],1)
        self.assertLessEqual(self.calls[0][3],self.observer.close_deadline)
    def test_exhausted_original_deadline_does_not_start_family_io(self):
        old=self.proc.census
        def census(pid):
            value=old(pid);self.clock.value=1;return value
        self.proc.census=census
        self.reject()
        self.assertEqual(self.calls,[])
        self.assertEqual(self.observer.record['family_diagnostic']['reason'],'original_budget_unavailable')
    def test_zero_sockets_remains_failure_without_family_io(self):
        self.proc.inodes=set();self.proc.rows=[]
        self.reject()
        self.assertEqual(self.calls,[])
        self.assertEqual(self.observer.record['family_diagnostic']['reason'],'no_owned_socket_fds')
    def test_oversize_sampler_output_is_not_saved(self):
        self.observer.family_sampler=lambda *a,**kw:{'schema':'civilizationbot.socket-family-diagnostic.v1','pid':817263,'padding':'x'*40000}
        self.reject()
        diag=self.observer.record['family_diagnostic']
        self.assertEqual(diag['reason'],'family_report_bound')
        self.assertNotIn('padding',json.dumps(diag))
    def test_late_sampler_report_cannot_publish_family(self):
        def slow(*args,**kwargs):
            result=self.sampler(*args,**kwargs);self.clock.value=.06;return result
        self.observer.family_sampler=slow
        self.reject()
        self.assertEqual(self.observer.record['family_diagnostic']['reason'],'family_hook_budget_exhausted')
        self.assertNotIn('sample',self.observer.record['family_diagnostic'])
    def test_success_has_no_family_io(self):
        self.proc.inodes={1234}
        base.publish(self.stage,'ready');self.observer.tick()
        self.proc.closed=True;base.publish(self.stage,'closed');self.observer.tick()
        base.publish(self.stage,'started');self.observer.finish(0)
        self.assertEqual(self.calls,[])
        self.assertNotIn('family_diagnostic',self.observer.record)
    def test_owned_cleanup_keeps_original_deadline_and_primary_after_hook(self):
        self.stage=self.root/'owned';self.stage.mkdir()
        self.observer=startup.StartupObserver(self.stage,'/mock/DreamDaemon','fixture_generator','a'*64,
                                             proc=self.proc,nonce='a'*32,family_sampler=self.sampler)
        child=base.FakeChild(self.stage,self.proc)
        own.ACTIVE=None;own.PENDING.clear()
        self.addCleanup(lambda:(setattr(own,'ACTIVE',None),own.PENDING.clear()))
        def launch(*args,**kwargs):
            base.publish(self.stage,'ready')
            (self.stage/'native.log').write_text(base.runtime_logs()['native.log']['text'])
            return child
        with patch.object(own.subprocess,'Popen',side_effect=launch),patch.object(own.shared,'finish_owned_compiler',wraps=own.shared.finish_owned_compiler) as finish:
            with self.assertRaisesRegex(ValueError,'startup_owned_socket_count'):
                own.bounded(['/mock/DreamDaemon'],self.stage,10,4096,self.root,{},8,observer=self.observer)
        self.assertEqual(finish.call_args.args[1],10)
        record=json.loads((self.stage/'process.json').read_text())
        self.assertEqual(record['ownership'],'reaped')
        self.assertEqual(record['startup']['family_diagnostic']['status'],'captured')
    def test_controller_explicitly_enables_real_sampler_without_launch(self):
        transport=controller.NativeTransport(self.root,self.root/'bin',100000)
        transport.images={'fixture_generator':{'fixture_generator.dmb':b'FAKE','fixture_generator.rsc':b'FAKE'}}
        transport.image_hashes={'fixture_generator':{'fixture_generator.dmb':'a'*64,'fixture_generator.rsc':'b'*64}}
        class Stop(Exception):pass
        def process(argv,stage,seconds,cap,cpu,observer=None):
            self.assertIs(observer.family_sampler,families.diagnose)
            raise Stop()
        transport.process=process
        with patch.object(startup,'ProcReader',return_value=self.proc),self.assertRaises(Stop):
            transport('wiring','fixture_generator',{'schema':'civilizationbot.fixture-request.v1','operation':'generate'},{})

    def test_default_observer_does_not_enable_family_reads(self):
        self.observer.family_sampler=None
        self.reject()
        self.assertEqual(self.proc.reads,[])
        self.assertNotIn('family_diagnostic',self.observer.record)
    def test_unknown_cleanup_does_not_repeat_diagnostic_or_read_final_files(self):
        self.stage=self.root/'unknown';self.stage.mkdir()
        self.observer=startup.StartupObserver(self.stage,'/mock/DreamDaemon','fixture_generator','a'*64,
                                             proc=self.proc,nonce='a'*32,family_sampler=self.sampler)
        child=base.FakeChild(self.stage,self.proc);child.term_exits=child.kill_exits=False
        own.ACTIVE=None;own.PENDING.clear()
        self.addCleanup(lambda:(setattr(own,'ACTIVE',None),own.PENDING.clear()))
        def launch(*args,**kwargs):base.publish(self.stage,'ready');return child
        with patch.object(own.subprocess,'Popen',side_effect=launch),patch.object(own,'snapshot',side_effect=AssertionError('No live log read')),patch.object(startup,'closed_marker_snapshot',side_effect=AssertionError('No final marker read')):
            with self.assertRaisesRegex(ValueError,'startup_owned_socket_count'):
                own.bounded(['/mock/DreamDaemon'],self.stage,10,4096,self.root,{},8,observer=self.observer)
        record=json.loads((self.stage/'process.json').read_text())
        self.assertEqual(record['ownership'],'retained')
        self.assertEqual(len(self.calls),1)
        self.assertEqual(record['startup']['family_diagnostic']['status'],'captured')
        self.assertIs(own.ACTIVE,child)

if __name__=='__main__':unittest.main()
