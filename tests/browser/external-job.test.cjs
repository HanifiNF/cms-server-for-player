const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// Exercise the production submit handler with browser controls and transport
// replaced by test doubles. Include a selected file even when its field is hidden.
const source = fs.readFileSync(path.join(__dirname, '../../public/assets/cms-library.js'), 'utf8');
const start = source.indexOf("    form.addEventListener('submit', async function (event) {");
const end = source.indexOf("      if (!fileInput.files.length &&", start);
const handlerSource = source.slice(start, end) + '\n});';

async function submit(responses) {
  let handler;
  let prevented = 0;
  let recovered = 0;
  const sent = [];
  const destinations = [];
  const context = {
    form: {
      dataset: { uploadBase: '/control/assets/uploads' },
      action: '/control/assets/external-encryption',
      querySelector: () => ({ value: 'sideload' }),
      reportValidity: () => true,
      addEventListener: (_, fn) => { handler = fn; },
    },
    FormData: class {
      constructor() { this.values = [
        ['title', 'Film A'], ['csrf_test_name', 'fresh-token'],
        ['genre_ids[]', '1'], ['genre_ids[]', '2'],
        ['media', { size: 4590000000, name: 'film.mp4' }],
        ['poster', { size: 10000, name: 'poster.jpg' }],
      ]; }
      delete(key) { this.values = this.values.filter(item => item[0] !== key); }
      has(key) { return this.values.some(item => item[0] === key); }
      get(key) { const item = this.values.find(value => value[0] === key); return item && item[1]; }
      getAll(key) { return this.values.filter(item => item[0] === key).map(item => item[1]); }
    },
    recoverCsrf: async () => { recovered += 1; },
    uploadUrl: value => value,
    localRedirect: value => value,
    restoreCsrf: () => {},
    fetch: async (_, options) => {
      sent.push(options);
      const status = responses.shift();
      return { status, ok: status === 200, json: async () => status === 200
        ? { data: { redirect: '/control/library' } }
        : { error: { message: 'Request rejected' } } };
    },
    window: { location: { assign: value => destinations.push(value) } },
    submitButton: {}, panel: {}, status: {}, error: {}, cancel: {},
  };
  vm.runInNewContext(handlerSource, context);
  await handler({ preventDefault: () => { prevented += 1; } });
  return { context, sent, destinations, recovered, prevented };
}

test('job request excludes film and preserves poster, metadata and CSRF', async () => {
  const result = await submit([200]);
  const body = result.sent[0].body;
  assert.equal(body.has('media'), false);
  assert.equal(body.has('poster'), true);
  assert.equal(body.get('title'), 'Film A');
  assert.equal(body.get('csrf_test_name'), 'fresh-token');
  assert.deepEqual(body.getAll('genre_ids[]'), ['1', '2']);
  assert.deepEqual(result.destinations, ['/control/library']);
  assert.equal(result.prevented, 1);
});

test('CSRF rejection refreshes the token once before retry', async () => {
  const result = await submit([403, 200]);
  assert.equal(result.recovered, 2);
  assert.equal(result.sent.length, 2);
});

test('server failure stays in modal and does not retry creating a job', async () => {
  const result = await submit([500]);
  assert.equal(result.sent.length, 1);
  assert.deepEqual(result.destinations, []);
  assert.equal(result.context.error.textContent, 'Request rejected');
  assert.equal(result.context.submitButton.disabled, false);
});
