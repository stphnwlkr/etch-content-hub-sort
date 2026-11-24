/* DOM regression checks. Run with JSDOM_MODULE pointing to an installed jsdom package. */
const { JSDOM } = require(process.env.JSDOM_MODULE || 'jsdom');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
(async () => {
  const dom = new JSDOM('<div id="host"></div>', { url: 'http://localhost/', runScripts: 'outside-only', pretendToBeVisual: true });
  const w = dom.window, doc = w.document, errors = [];
  w.addEventListener('error', event => errors.push(event.message));
  w.ResizeObserver = class { observe() {} disconnect() {} };
  w.Element.prototype.getBoundingClientRect = () => ({ width: 1000, height: 700, left: 0, top: 0 });
  w.SVGElement.prototype.getBBox = () => ({ x: 0, y: 0, width: 80, height: 16 });
  w.SVGElement.prototype.getComputedTextLength = () => 80;
  w.wp = { i18n: { __: s => s, _n: (s, p, n) => n === 1 ? s : p, sprintf: (s, ...args) => { let i = 0; return s.replace(/%[ds]/g, () => args[i++]); } } };
  w.etchHubSortConfig = { connectionsEndpoint: 'http://localhost/map', nonce: 'fixture' };
  w.etchHubContentPanel = { close() {}, openList() {} };
  w.fetch = async url => ({ ok: true, json: async () => url.includes('/terms') ? { nodes: [], done: true } : {
    nodes: [1, 2].map(id => ({ id, title: id === 1 ? 'Home' : 'About', post_type: 'page', type: 'Page', type_label: 'Pages', public: true, status: 'publish', path: '/' })),
    edges: [{ kind: 'link', from: 1, to: 2 }], taxonomies: {}, site_name: 'Test site', done: true,
  } });
  for (const asset of ['delta-graph.js', 'connections.js']) w.eval(fs.readFileSync(path.join(__dirname, '../assets', asset), 'utf8'));
  w.etchHubMountConnections(doc.getElementById('host'));
  await new Promise(resolve => setTimeout(resolve, 40));
  const control = name => doc.querySelector(`[data-graph="${name}"]`);
  const scale = () => Number(control('view').getAttribute('transform').match(/scale\(([^)]+)/)[1]);
  const pass = (condition, message) => { assert.ok(condition, message); console.log('PASS: ' + message); };
  let before = scale(); control('zoom-in').click(); pass(scale() > before, 'Zoom in changes map scale');
  before = scale(); control('zoom-out').click(); pass(scale() < before, 'Zoom out changes map scale');
  pass(control('zoom-in').tagName === 'BUTTON' && control('zoom-in').type === 'button' && !control('zoom-in').disabled, 'Zoom controls are enabled native keyboard buttons');
  before = scale(); control('svg').dispatchEvent(new w.KeyboardEvent('keydown', { key: '+', bubbles: true })); pass(scale() > before, 'Plus shortcut changes map scale');
  before = scale(); control('svg').dispatchEvent(new w.KeyboardEvent('keydown', { key: '-', bubbles: true })); pass(scale() < before, 'Minus shortcut changes map scale');
  async function wheel(deltaY, deltaMode = 0) {
    const prior = scale();
    control('svg').dispatchEvent(new w.WheelEvent('wheel', { deltaY, deltaMode, clientX: 400, clientY: 300, cancelable: true }));
    await new Promise(resolve => setTimeout(resolve, 30));
    return scale() / prior;
  }
  const small = await wheel(1), notch = await wheel(100);
  pass(small < 1 && small > .999, 'Small trackpad event changes scale by less than 0.1 percent');
  pass(notch < .96 && notch > .94, '100-pixel wheel event changes scale by about 5 percent');
  const pixel = await wheel(16), line = await wheel(1, 1);
  pass(Math.abs(pixel - line) < .000001, 'Pixel and line wheel units normalize consistently');
  doc.querySelector('.delta-graph-node').dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
  pass(!!doc.querySelector('.delta-graph-node.is-selected'), 'Keyboard node selection works');
  control('search').value = 'About'; control('search').dispatchEvent(new w.Event('input'));
  doc.querySelector('.delta-graph-chip').click();
  [...doc.querySelectorAll('.delta-graph-switch')].find(n => n.textContent === 'Labels').click();
  control('reset').click();
  pass(control('search').value === '', 'Reset clears search');
  pass(!doc.querySelector('.delta-graph-node.is-selected'), 'Reset clears selection');
  pass(!doc.querySelector('.delta-graph-chip[aria-pressed="false"], .delta-graph-switch[aria-pressed="false"]'), 'Reset restores group and display defaults');
  pass([...doc.querySelectorAll('[data-graph="nodes"] > g')].every(n => n.style.display !== 'none'), 'Reset restores hidden nodes');
  pass(doc.querySelector('.etch-delta-detail-content').textContent.includes('Test site'), 'Reset restores site details');
  const resetScale = scale(); control('zoom-in').click(); control('reset').click();
  pass(Math.abs(scale() - resetScale) < .000001, 'Reset returns to the same fitted view after zooming');
  pass(errors.length === 0, 'Map operations raise no DOM errors');
  w.etchHubCloseConnections(); dom.window.close();
})().catch(error => { console.error(error); process.exitCode = 1; });
