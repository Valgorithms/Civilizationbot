// Evaluated inside the actual index.html document through the owned browser pipe.
// No network, credentials or live endpoint access. Not a full accessibility audit.
export async function checkLayout(expectedWidth) {
  let checks = 0;
  function check(value, message) { checks++; if (!value) throw new Error(message); }
  for (let i = 0; i < 50 && document.querySelectorAll('#servers article').length !== 6; i++) {
    await new Promise(resolve => setTimeout(resolve, 20));
  }
  check(window.innerWidth === expectedWidth, `Viewport ${window.innerWidth} differs from ${expectedWidth}`);
  check(document.documentElement.lang === 'en', 'Document language');
  check(document.title.includes('demo'), 'Fixture-only document title');
  check(document.querySelector('meta[name="viewport"]') !== null, 'Mobile viewport metadata');
  check(document.querySelectorAll('main').length === 1 && document.querySelectorAll('h1').length === 1, 'Main landmark and heading');
  check(document.querySelector('.notice').textContent.includes('No live connection'), 'No misleading live claim');
  check(document.querySelectorAll('#servers article').length === 6, 'Actual page CSP permits local demo assets');
  check(getComputedStyle(document.querySelector('.servers')).display === 'grid', 'Actual stylesheet loaded');
  check(document.documentElement.scrollWidth <= window.innerWidth + 1, 'No horizontal page overflow');
  const cards = [...document.querySelectorAll('#servers article')];
  for (const card of cards) {
    const rect = card.getBoundingClientRect();
    check(rect.width > 0 && rect.left >= -1 && rect.right <= innerWidth + 1, 'Card inside viewport');
    check(card.scrollWidth <= card.clientWidth + 1, 'No horizontal card overflow');
    check(document.getElementById(card.getAttribute('aria-labelledby'))?.tagName === 'H2', 'Card heading label');
  }
  const a = cards[0].getBoundingClientRect(), b = cards[1].getBoundingClientRect();
  check(expectedWidth <= 650 ? b.top > a.top && Math.abs(b.left - a.left) <= 1 : Math.abs(b.top - a.top) <= 1 && b.left > a.left,
    'Expected one-column phone/two-column desktop layout');
  check(cards[0].querySelector('.metric-primary dd').textContent === '0' && cards[0].querySelector('.badge').textContent === 'Online', 'Zero-player world stays online');
  check(document.querySelectorAll('time[datetime]').length === 5, 'Fixture observation timestamps');
  const actions = [...document.querySelectorAll('.actions button')];
  check(actions.length === 18 && actions.every(button => button.disabled), 'All real DOM action buttons disabled');
  check(actions.every(button => document.getElementById(button.getAttribute('aria-describedby'))), 'Unavailable action reasons linked');
  for (const control of [document.getElementById('reload'), document.getElementById('state-filter'), ...actions]) {
    check(control.getBoundingClientRect().height >= 44 && control.getBoundingClientRect().width >= 44, '44px control target dimensions');
  }
  const filter = document.getElementById('state-filter');
  check(document.querySelector('label[for="state-filter"]') !== null, 'Select has an explicit label');
  check(document.getElementById('summary').getAttribute('aria-live') === 'polite', 'Updates have polite live region');
  filter.focus();
  check(document.activeElement === filter, 'Filter is focusable');
  const skip = document.querySelector('.skip'); skip.focus();
  check(document.activeElement === skip && skip.getBoundingClientRect().top >= 0, 'Skip link visible on focus');
  filter.value = 'online'; filter.dispatchEvent(new Event('change'));
  check(document.querySelectorAll('#servers article').length === 1, 'Real page filter event');
  filter.value = 'all'; filter.dispatchEvent(new Event('change'));
  document.getElementById('reload').click();
  for (let i = 0; i < 50 && document.getElementById('reload').disabled; i++) await new Promise(resolve => setTimeout(resolve, 20));
  check(document.querySelectorAll('#servers article').length === 6 && !document.getElementById('reload').disabled, 'Real page reload restores six fixtures');
  check(!performance.getEntriesByType('resource').some(entry => /^https?:/i.test(entry.name)), 'Page requested no HTTP(S) resources');
  window.scrollTo(0, 0); document.activeElement?.blur();
  return {width: innerWidth, height: innerHeight, checks, cards: cards.length, browserLayout: 'passed'};
}
