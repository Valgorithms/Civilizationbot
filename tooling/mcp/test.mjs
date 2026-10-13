import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';
import { createSource, result } from './source.mjs';

async function fixture() {
  const root = await fs.mkdtemp(path.join(os.tmpdir(),'bot-mcp-'));
  await fs.mkdir(path.join(root,'src')); await fs.mkdir(path.join(root,'docs'));
  await fs.writeFile(path.join(root,'src','Server.php'),'<?php\nfinal class Server {\n public function restart() {}\n}\n');
  await fs.writeFile(path.join(root,'docs','contract.md'),'# Controller\nRead-only status differs from readiness.\n');
  await fs.writeFile(path.join(root,'.env'),'FORBIDDEN_SECRET');
  return root;
}
test('actual stdio discovery, source provenance and negative boundaries',async()=>{
  const root = await fixture();
  const client = new Client({name:'isolated-bot-test',version:'1.0.0'});
  const transport = new StdioClientTransport({command:process.execPath,args:[path.join(import.meta.dirname,'server.mjs'),root],env:{}});
  try {
    await client.connect(transport);
    const tools = (await client.listTools()).tools;
    assert.deepEqual(tools.map(t=>t.name).sort(),['docs_search','source_read','source_search','symbol_search']);
    assert.ok(tools.every(t=>t.annotations.readOnlyHint && !t.annotations.openWorldHint));
    const call=(name,args)=>client.callTool({name,arguments:args});
    const read=await call('source_read',{path:'src/Server.php',start:2,lines:2});
    assert.match(read.structuredContent.sha256,/^[a-f0-9]{64}$/); assert.match(read.structuredContent.text,/class Server/);
    assert.equal((await call('symbol_search',{query:'restart',limit:1})).structuredContent.matches[0].line,3);
    assert.equal((await call('docs_search',{query:'readiness'})).structuredContent.matches[0].path,'docs/contract.md');
    for(const p of ['.env','../src/Server.php','src/../../.env','C:/secret.php','src\\Server.php','vendor/a.php','data/player.php']) {
      assert.equal((await call('source_read',{path:p})).isError,true,p);
    }
    assert.equal((await call('source_read',{path:'src/Server.php',lines:101})).isError,true);
    assert.equal((await call('source_search',{query:' '})).isError,true);
    await fs.writeFile(path.join(root,'src','Huge.php'),'x'.repeat(1000001));
    assert.equal((await call('source_read',{path:'src/Huge.php'})).isError,true);
    await client.ping(); // a failed tool must not poison the transport
  } finally { await client.close(); await fs.rm(root,{recursive:true,force:true}); }
});
test('cancellation, UTF8 byte output bound and junction escape',async()=>{
  const root=await fixture(); const outside=await fs.mkdtemp(path.join(os.tmpdir(),'bot-mcp-outside-'));
  try {
    await fs.writeFile(path.join(outside,'escape.php'),'OUTSIDE');
    await fs.symlink(outside,path.join(root,'src','linked'),process.platform==='win32'?'junction':'dir');
    const source=await createSource(root);
    await assert.rejects(source.read('src/linked/escape.php'),/Symlink/);
    assert.ok(!(await source.inventory()).some(p=>p.includes('linked')));
    const controller=new AbortController(); controller.abort();
    await assert.rejects(source.read('src/Server.php',controller.signal),/Cancelled/);
    await assert.rejects(source.search('Server',20,controller.signal),/Cancelled/);
    assert.throws(()=>result({text:'界'.repeat(11000)}),/32 KB/);
  } finally { await fs.rm(root,{recursive:true,force:true}); await fs.rm(outside,{recursive:true,force:true}); }
});
