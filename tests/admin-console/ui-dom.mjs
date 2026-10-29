// Minimal DOM contract double, NOT browser/layout/accessibility acceptance.
// Executes the same renderer/interaction assertions as ui.html without packages.
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
class Node {
  constructor(tag = '', text = '') { this.tagName = tag; this.data = text; this.children = []; this.attributes = {}; this.dataset = {}; this.listeners = {}; this.className = ''; this.value = ''; this.disabled = false; }
  get textContent() { return this.data + this.children.map(node => node.textContent).join(''); }
  set textContent(value) { this.data = String(value); this.children = []; }
  append(...nodes) { for (const node of nodes) { if (node.tagName === '#fragment') this.append(...node.children); else this.children.push(node); } }
  replaceChildren(...nodes) { this.data = ''; this.children = []; this.append(...nodes); }
  setAttribute(key, value) { this.attributes[key] = value; }
  getAttribute(key) { return this.attributes[key]; }
  get classList() { return {add: name => { this.className += ' '+name; }, remove: name => { this.className = this.className.split(' ').filter(v => v !== name).join(' '); }}; }
  addEventListener(name, callback) { (this.listeners[name] ||= []).push(callback); }
  dispatchEvent(event) { for (const callback of this.listeners[event.type] || []) callback(event); }
  matches(selector) {
    if (selector.startsWith('.')) return this.className.split(' ').includes(selector.slice(1));
    if (selector.startsWith('[data-state=')) return this.dataset.state === selector.slice(13, -2);
    return this.tagName === selector;
  }
  querySelectorAll(selector) {
    if (selector.includes(',')) return selector.split(',').flatMap(part => this.querySelectorAll(part));
    const parts = selector.split(' ');
    const result = [];
    const visit = node => { for (const child of node.children) { if (child.matches(parts[0])) { if (parts.length > 1) result.push(...child.querySelectorAll(parts.slice(1).join(' '))); else result.push(child); } visit(child); } };
    visit(this); return [...new Set(result)];
  }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
}
const body = new Node('body');
const document = {body, createElement: tag => new Node(tag), createTextNode: text => new Node('#text', text),
  createDocumentFragment: () => new Node('#fragment'),
  getElementById: id => { const find = node => node.id === id ? node : node.children.map(find).find(Boolean); return find(body) || null; }};
for (const [tag, id] of [['select', 'state-filter'], ['button', 'reload'], ['p', 'summary'], ['section', 'servers'], ['pre', 'results']]) {
  const node = new Node(tag); node.id = id; if (tag === 'select') node.value = 'all'; body.append(node);
}
const context = vm.createContext({document, console, structuredClone, Event: class {constructor(type) {this.type = type;}}});
for (const file of ['../../web/admin-console/console.js', '../../web/admin-console/fixtures.js', './ui.js']) {
  vm.runInContext(readFileSync(new URL(file, import.meta.url), 'utf8'), context, {filename: file});
}
await new Promise(resolve => setImmediate(resolve));
const result = document.getElementById('results').textContent;
console.log(result+' (DOM double; browser/layout unverified)');
if (body.dataset.result !== 'pass') process.exitCode = 1;
