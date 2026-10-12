import fs from 'node:fs/promises';
import path from 'node:path';
const output=path.resolve(import.meta.dirname,'../../build/v5');
// phpDocumentor 3.10 defaults graphs.enabled=false but its template still emits
// a diagram page/link. Explicitly omit this unavailable optional feature rather
// than ship a broken asset or send source-derived UML to an external renderer.
if (!(await fs.stat(path.join(output,'graphs/classes.svg')).catch(()=>null))) {
  let changed=0;
  async function walk(directory) {
    for(const entry of await fs.readdir(directory,{withFileTypes:true})) {
      if(entry.isSymbolicLink()) throw Error('Published symlinks forbidden');
      const file=path.join(directory,entry.name);
      if(entry.isDirectory()) await walk(file);
      else if(entry.name.endsWith('.html')) {
        const original=await fs.readFile(file,'utf8');
        const normalized=original.replace(/<a\b[^>]*href=["']graphs\/classes\.html(?:#[^"']*)?["'][^>]*>[\s\S]*?<\/a>/g,'');
        if(normalized!==original) { await fs.writeFile(file,normalized); changed++; }
      }
    }
  }
  await walk(output);
  await fs.rm(path.join(output,'graphs/classes.html'),{force:true});
  console.log(`Omitted disabled optional diagram page and ${changed} template links`);
}
