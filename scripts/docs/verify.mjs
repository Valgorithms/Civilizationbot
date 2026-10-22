import fs from 'node:fs/promises';
import path from 'node:path';
import { createHash } from 'node:crypto';

const root=path.resolve(import.meta.dirname,'../..');
const output=path.resolve(root,'build/v5');
const sha=process.argv[2];
if(!/^[a-f0-9]{40}$/.test(sha||'')) throw Error('Pass exact source commit SHA');
// Read the marker as text: documentation never imports or bootstraps the bot.
const source=await fs.readFile(path.join(root,'src/Civ13/Civ13.php'),'utf8');
const version=source.match(/public\s+const\s+string\s+VERSION\s*=\s*'([^']+)'\s*;/)?.[1];
if(!/^\d+\.\d+\.\d+(?:-[a-z0-9.-]+)?$/.test(version||'')) throw Error('Missing or invalid source version');
const config=await fs.readFile(path.join(root,'phpdoc.dist.xml'),'utf8');
if(config.match(/<version\s+number="([^"]+)"/)?.[1]!==version) throw Error('phpDocumentor version differs from source');
const releaseStatus=version.endsWith('-dev')?'development':version.includes('-')?'prerelease':'stable';
const files=[];
async function walk(dir){
  for(const entry of await fs.readdir(dir,{withFileTypes:true})){
    if(entry.isSymbolicLink()) throw Error('Published symlinks forbidden');
    const absolute=path.join(dir,entry.name);
    if(entry.isDirectory()) await walk(absolute); else files.push(absolute);
    if(files.length>10000) throw Error('Documentation inventory exceeded');
  }
}
await walk(output);
let links=0; const broken=[];
for(const file of files){
  if(!file.endsWith('.html')) continue;
  const html=await fs.readFile(file,'utf8');
  const base=html.match(/<base\s+href=["']([^"']+)["']/)?.[1] || './';
  const baseDirectory=path.resolve(path.dirname(file),base);
  for(const match of html.replace(/<base\s+[^>]*>/g,'').matchAll(/(?:href|src)=["']([^"']+)["']/g)){
    const url=match[1];
    if(/^(?:https?:|mailto:|data:|javascript:|#)/.test(url)) continue;
    if(url.startsWith('/')) { broken.push(`${path.relative(output,file)}: absolute link ${url}`); continue; }
    const relative=url.split(/[?#]/)[0]; if(!relative) continue;
    const target=path.resolve(baseDirectory,decodeURIComponent(relative));
    if(target!==output && !target.startsWith(output+path.sep)) throw Error(`Published link escapes version root: ${file}: ${url}`);
    links++;
    if(!(await fs.stat(target).catch(()=>null))) broken.push(`${path.relative(output,file)}: ${url}`);
  }
}
if(broken.length) throw Error(`Broken local links (${broken.length}): ${broken.slice(0,12).join('; ')}`);
const index=await fs.readFile(path.join(output,'js/searchIndex.js'),'utf8');
for(const symbol of ['GameServer','AsyncProcess','OpenDreamControl','FileRelay']) if(!index.includes(symbol)) throw Error(`Search index missing ${symbol}`);
for(const file of files) if(/(?:^|\/)(?:vendor|data|SQL|\.env|token\.php)(?:\/|$)/.test(path.relative(output,file).replaceAll('\\','/'))) throw Error('Forbidden published path');
const manifest={schema:'civilizationbot.api-docs.v1',source_commit:sha,phpdocumentor:'3.10.0',version,release_status:releaseStatus,publication_path:'v5/',files:files.length,local_links_checked:links,search_sha256:createHash('sha256').update(index).digest('hex')};
await fs.writeFile(path.join(output,'source.json'),JSON.stringify(manifest,null,2)+'\n');
console.log(`PASS phpDocumentor ${version} (${releaseStatus}) files=${files.length} links=${links} search symbols/provenance ${sha}`);
