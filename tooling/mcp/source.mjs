import fs from 'node:fs/promises';
import path from 'node:path';
import { createHash } from 'node:crypto';

const allowed = p => /^(src\/[\w/.-]+\.php|docs\/[\w/.-]+\.md|\.agents\/skills\/[\w/.-]+\.md|README\.md|AGENTS\.md)$/.test(p)
  && !p.split('/').some(p => p === '..' || p === '.') && !p.includes('\\');
const check = signal => { if (signal?.aborted) throw Error('Cancelled'); };
export async function createSource(rootPath) {
  const root = await fs.realpath(rootPath);
  const inside = p => p.startsWith(root + path.sep);
  async function read(relative, signal) {
    check(signal);
    if (!allowed(relative)) throw Error('Only repository PHP source and Markdown guides are allowed');
    const absolute = path.resolve(root, relative);
    // Reject symlinks at every component, including links targeting this repository.
    let current = root;
    for (const part of relative.split('/')) {
      current = path.join(current, part);
      if ((await fs.lstat(current)).isSymbolicLink()) throw Error('Symlink paths are forbidden');
    }
    const real = await fs.realpath(absolute);
    if (!inside(real)) throw Error('Repository boundary violation');
    const file = await fs.open(real, 'r');
    try {
      const stat = await file.stat();
      if (!stat.isFile() || stat.size > 1_000_000) throw Error('Source exceeds 1 MB limit');
      const data = Buffer.alloc(1_000_001);
      const { bytesRead } = await file.read(data, 0, data.length, 0);
      check(signal);
      if (bytesRead > 1_000_000) throw Error('Source exceeds 1 MB limit');
      const bytes = data.subarray(0, bytesRead);
      return { text: bytes.toString('utf8'), sha256: createHash('sha256').update(bytes).digest('hex') };
    } finally { await file.close(); }
  }
  async function inventory(signal, markdown = false) {
    const found = []; let visited = 0;
    const deadline = Date.now() + 3000;
    async function walk(relative, depth = 0) {
      check(signal);
      if (depth > 12 || ++visited > 3000 || Date.now() > deadline) throw Error('Inventory bound reached; narrow repository');
      const directory = path.join(root, relative);
      const stat = await fs.lstat(directory).catch(() => null);
      if (!stat || stat.isSymbolicLink() || !stat.isDirectory()) return;
      const dir = await fs.opendir(directory);
      try {
        for await (const entry of dir) {
          check(signal);
          if (++visited > 3000 || Date.now() > deadline) throw Error('Inventory bound reached');
          if (entry.isSymbolicLink()) continue;
          const name = relative + '/' + entry.name;
          if (entry.isDirectory()) await walk(name, depth + 1);
          else if (entry.isFile() && allowed(name) && (markdown ? name.endsWith('.md') : name.endsWith('.php'))) found.push(name);
        }
      } finally { /* for-await closes the directory on completion and error */ }
    }
    if (markdown) {
      await walk('docs'); await walk('.agents/skills');
      for (const file of ['AGENTS.md','README.md']) if (await fs.stat(path.join(root,file)).catch(() => null)) found.push(file);
    } else await walk('src');
    return found.sort();
  }
  async function search(query, limit, signal, declarations = false, markdown = false) {
    const matches = []; let scanned = 0;
    const deadline = Date.now() + 5000;
    for (const file of await inventory(signal, markdown)) {
      check(signal);
      if (Date.now() > deadline) return { matches, scanned, truncated: true };
      const source = await read(file, signal); scanned++;
      const lines = source.text.split(/\r?\n/);
      for (let i = 0; i < lines.length; i++) {
        if (i % 100 === 0) check(signal);
        if (lines[i].includes(query) && (!declarations || /^\s*(?:(?:final|abstract|public|protected|private|static|readonly)\s+)*(?:class|interface|trait|enum|function|namespace)\b/.test(lines[i]))) {
          matches.push({ path:file, line:i+1, text:lines[i].slice(0,240), sha256:source.sha256 });
          if (matches.length >= limit) return { matches, scanned, truncated: true };
        }
      }
    }
    return { matches, scanned, truncated:false };
  }
  return { read, search, inventory };
}

export function result(data) {
  const text = JSON.stringify(data);
  if (Buffer.byteLength(text,'utf8') > 32000) throw Error('Output exceeds 32 KB; narrow query');
  return { content:[{type:'text',text}], structuredContent:data };
}
