import assert from 'node:assert/strict';
import { mkdtemp } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { beforeEach, test } from 'node:test';
import { createApp, cvType } from '../src/app.js';
import { FileStore } from '../src/store.js';

const TOKEN = 'a'.repeat(32);
const AD = 'b'.repeat(32);
const ENV = { AUREUS_USERNAME: 'aureus-integration', AUREUS_APP_PASSWORD: 'abcd EFGH 1234 ijkl', SITE_NAME: 'Truck It In', DEMO_MODE: 'true' };
const AUTH = 'Basic ' + Buffer.from('aureus-integration:abcd EFGH 1234 ijkl').toString('base64');
const PDF = Buffer.from('%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF');

const PAYLOAD = {
  company: 'Truck It In',
  status: 'published',
  title: 'Fleet Dispatcher <b>Karachi</b>',
  slug: 'fleet-dispatcher',
  location: { city: 'Karachi', country: 'PK', type: 'on_site' },
  employment_type: 'FULL_TIME',
  salary: { min: 120000, max: 160000, currency: 'PKR', period: 'MONTH', visible: true },
  description_html: '<p>Plan the fleet.</p><script>alert(1)</script>',
  requirements_html: '<ul><li>2 years dispatching</li></ul>',
  experience_years: 2,
  date_posted: '2026-10-29',
  valid_through: '2099-12-31',
  questions: [
    { id: 11, type: 'yes_no', label: 'Can you work on-site in Karachi?', help_text: null, options: [], required: true },
    { id: 12, type: 'number', label: 'Years of experience', help_text: null, options: [], required: false },
  ],
};

let app;

beforeEach(async () => {
  const store = new FileStore(await mkdtemp(join(tmpdir(), 'careers-test-')));
  app = createApp({ store, env: ENV });
});

const call = (path, init = {}) => app(new Request(`https://careers.example.test${path}`, { redirect: 'manual', ...init }));
const apiCall = (path, init = {}) => call(`/wp-json/aureus/v1${path}`, { ...init, headers: { authorization: AUTH, ...(init.headers || {}) } });
const putJob = (payload = PAYLOAD, token = TOKEN) => apiCall(`/jobs/${token}`, { method: 'PUT', body: JSON.stringify(payload), headers: { 'content-type': 'application/json' } });

function applyForm(fields = {}, file = new File([PDF], 'Ali Raza CV.pdf', { type: 'application/pdf' })) {
  const form = new FormData();
  const values = { name: 'Ali Raza', email: 'ALI@example.test', phone: '+92 300', consent: '1', q_11: 'yes', q_12: '3', ref: AD, src: 'rozee', ...fields };

  for (const [key, value] of Object.entries(values)) {
    if (value !== null) form.set(key, value);
  }

  if (file) form.set('cv', file);

  return form;
}

test('the Aureus API needs the right user and application password', async () => {
  assert.equal((await call('/wp-json/aureus/v1/ping')).status, 401);
  assert.equal((await call('/wp-json/aureus/v1/ping', { headers: { authorization: 'Basic ' + Buffer.from('aureus-integration:wrong').toString('base64') } })).status, 401);

  const ping = await apiCall('/ping');
  assert.equal(ping.status, 200);
  assert.deepEqual(await ping.json(), { ok: true, version: 'demo-1.0.0', site: 'Truck It In' });

  const unconfigured = createApp({ store: new FileStore(await mkdtemp(join(tmpdir(), 'careers-test-'))), env: {} });
  assert.equal((await unconfigured(new Request('https://x.test/wp-json/aureus/v1/ping', { headers: { authorization: AUTH } }))).status, 503);
});

test('a job pushed by Aureus is listed with Google job data, in the Indeed feed and the sitemap', async () => {
  const put = await putJob();
  assert.equal(put.status, 200);
  assert.deepEqual(await put.json(), { url: 'https://careers.example.test/careers/fleet-dispatcher', status: 'published' });

  const list = await (await call('/careers')).text();
  assert.match(list, /Fleet Dispatcher &lt;b&gt;Karachi&lt;\/b&gt;/);
  assert.match(list, /PKR 120,000 - 160,000 per month/);
  assert.match(list, /Demo careers site/);

  const page = await (await call('/careers/fleet-dispatcher?ref=' + AD + '&src=linkedin')).text();
  assert.match(page, /<p>Plan the fleet.<\/p>/);
  assert.doesNotMatch(page, /<script>alert/);
  assert.match(page, /"@type":"JobPosting"/);
  assert.match(page, /name="ref" value="bbbbbbbb/);
  assert.match(page, /name="src" value="linkedin"/);
  assert.match(page, /noindex/);

  const feed = await (await call('/careers/feed/indeed.xml')).text();
  assert.match(feed, /<referencenumber><!\[CDATA\[a{32}\]\]><\/referencenumber>/);
  assert.match(feed, /<url><!\[CDATA\[https:\/\/careers.example.test\/careers\/fleet-dispatcher\?src=indeed\]\]><\/url>/);
  assert.match(feed, /<jobtype><!\[CDATA\[fulltime\]\]><\/jobtype>/);

  assert.match(await (await call('/sitemap.xml')).text(), /careers\/fleet-dispatcher/);
  assert.equal(await (await call('/robots.txt')).text(), 'User-agent: *\nDisallow: /\n');
});

const xmlOf = async (board) => {
  const response = await call(`/careers/feed/${board}.xml`);
  assert.equal(response.status, 200);
  assert.equal(response.headers.get('content-type'), 'application/xml; charset=utf-8');

  return response.text();
};

test('each board feed lists only the jobs whose payload enables that board, with ?src={board}', async () => {
  await putJob({ ...PAYLOAD, boards: ['jooble', 'talent', 'recruitnet', 'expertini', 'tiptopjob', 'generic'] });
  await putJob({ ...PAYLOAD, title: 'Indeed only', slug: 'indeed-only', boards: ['indeed'] }, 'c'.repeat(32));
  await putJob({ ...PAYLOAD, title: 'No boards', slug: 'no-boards', boards: [] }, 'd'.repeat(32));

  const indeed = await xmlOf('indeed');
  assert.match(indeed, /careers\/indeed-only\?src=indeed/);
  assert.doesNotMatch(indeed, /fleet-dispatcher|no-boards/);

  for (const board of ['recruitnet', 'expertini', 'tiptopjob', 'generic']) {
    const feed = await xmlOf(board);
    assert.match(feed, /^<\?xml version="1.0" encoding="utf-8"\?>\n<source>/);
    assert.match(feed, new RegExp(`<url><!\\[CDATA\\[https://careers.example.test/careers/fleet-dispatcher\\?src=${board}\\]\\]></url>`));
    assert.match(feed, /<referencenumber><!\[CDATA\[a{32}\]\]><\/referencenumber>/);
    assert.doesNotMatch(feed, /indeed-only|no-boards/);
  }

  const talent = await xmlOf('talent');
  assert.match(talent, /<source>\n  <publisher><!\[CDATA\[Truck It In\]\]><\/publisher>/);
  assert.match(talent, /<lastbuilddate><!\[CDATA\[\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ\]\]><\/lastbuilddate>/);
  assert.match(talent, /<dateposted><!\[CDATA\[2026-10-29T00:00:00Z\]\]><\/dateposted>/);
  assert.match(talent, /<expirationdate><!\[CDATA\[12-31-2099T23:59:59Z\]\]><\/expirationdate>/);
  assert.match(talent, /<url><!\[CDATA\[https:\/\/careers.example.test\/careers\/fleet-dispatcher\?src=talent\]\]><\/url>/);
  assert.match(talent, /<jobtype><!\[CDATA\[Full time\]\]><\/jobtype>/);
  assert.match(talent, /<isremote><!\[CDATA\[no\]\]><\/isremote>/);
  assert.match(talent, /<salary_min><!\[CDATA\[120000\]\]><\/salary_min>/);
  assert.match(talent, /<salary_currency><!\[CDATA\[PKR\]\]><\/salary_currency>/);
  assert.match(talent, /<experience_min><!\[CDATA\[2\]\]><\/experience_min>/);
  assert.doesNotMatch(talent, /indeed-only|no-boards/);

  const jooble = await xmlOf('jooble');
  assert.match(jooble, /^<\?xml version="1.0" encoding="utf-8"\?>\n<jobs>\n  <job id="a{32}">/);
  assert.match(jooble, /<link><!\[CDATA\[https:\/\/careers.example.test\/careers\/fleet-dispatcher\?src=jooble\]\]><\/link>/);
  assert.match(jooble, /<name><!\[CDATA\[Fleet Dispatcher <b>Karachi<\/b>\]\]><\/name>/);
  assert.match(jooble, /<region><!\[CDATA\[Karachi, PK\]\]><\/region>/);
  assert.match(jooble, /<pubdate><!\[CDATA\[29.10.2026\]\]><\/pubdate>/);
  assert.match(jooble, /<expire><!\[CDATA\[31.12.2099\]\]><\/expire>/);
  assert.match(jooble, /<salary><!\[CDATA\[PKR 120,000 - 160,000 per month\]\]><\/salary>/);
  assert.match(jooble, /<jobtype><!\[CDATA\[full-time\]\]><\/jobtype>/);
  assert.doesNotMatch(jooble, /indeed-only|no-boards/);

  assert.equal((await call('/careers/feed/monster.xml')).status, 404);
});

test('a job pushed without "boards" stays in the Indeed and generic feeds only', async () => {
  await putJob();

  assert.match(await xmlOf('indeed'), /fleet-dispatcher\?src=indeed/);
  assert.match(await xmlOf('generic'), /fleet-dispatcher\?src=generic/);

  for (const board of ['jooble', 'talent', 'recruitnet', 'expertini', 'tiptopjob']) {
    assert.doesNotMatch(await xmlOf(board), /fleet-dispatcher/);
  }
});

test('an application through a board feed link carries that board as src to Aureus', async () => {
  await putJob({ ...PAYLOAD, boards: ['jooble'] });

  const page = await (await call('/careers/fleet-dispatcher?src=jooble')).text();
  assert.match(page, /name="src" value="jooble"/);

  await call('/careers/fleet-dispatcher/apply', { method: 'POST', body: applyForm({ ref: null, src: 'jooble' }) });
  const [application] = await (await apiCall('/applications')).json();
  assert.equal(application.src, 'jooble');
  assert.equal(application.ref, null);
});

test('an expired job leaves every board feed', async () => {
  await putJob({ ...PAYLOAD, valid_through: '2000-01-01', boards: ['indeed', 'jooble', 'talent', 'generic'] });

  for (const board of ['indeed', 'jooble', 'talent', 'generic']) {
    assert.doesNotMatch(await xmlOf(board), /fleet-dispatcher/);
  }
});

test('an unlisted or removed job disappears from the site and the feed', async () => {
  await putJob();
  assert.equal((await apiCall(`/jobs/${TOKEN}`, { method: 'DELETE' })).status, 200);

  assert.doesNotMatch(await (await call('/careers')).text(), /Fleet Dispatcher/);
  assert.doesNotMatch(await (await call('/careers/feed/indeed.xml')).text(), /<job>/);
  assert.equal((await call('/careers/fleet-dispatcher')).status, 404);

  await putJob({ ...PAYLOAD, status: 'unlisted' });
  assert.equal((await call('/careers/fleet-dispatcher')).status, 404);

  await putJob({ ...PAYLOAD, valid_through: '2000-01-01' });
  assert.equal((await call('/careers/fleet-dispatcher')).status, 404);
});

test('an application with a CV is handed to Aureus, then acknowledged and its CV deleted', async () => {
  await putJob();

  const posted = await call('/careers/fleet-dispatcher/apply', { method: 'POST', body: applyForm() });
  assert.equal(posted.status, 303);
  assert.equal(posted.headers.get('location'), 'https://careers.example.test/careers/fleet-dispatcher/thanks');

  const list = await (await apiCall('/applications?status=new&limit=50')).json();
  assert.equal(list.length, 1);
  const [application] = list;
  assert.equal(application.job_token, TOKEN);
  assert.equal(application.ref, AD);
  assert.equal(application.src, 'rozee');
  assert.equal(application.email, 'ali@example.test');
  assert.deepEqual(application.answers, [{ question_id: 11, answer: 'yes' }, { question_id: 12, answer: 3 }]);
  assert.deepEqual(application.cv, { filename: 'Ali Raza CV.pdf', size: PDF.length, mime: 'application/pdf' });
  assert.ok(Number.isSafeInteger(application.id));

  const cv = await apiCall(`/applications/${application.id}/cv`);
  assert.equal(cv.status, 200);
  assert.equal(cv.headers.get('content-type'), 'application/pdf');
  assert.equal(cv.headers.get('content-disposition'), 'attachment; filename="Ali Raza CV.pdf"');
  assert.deepEqual(Buffer.from(await cv.arrayBuffer()), PDF);

  const ack = await apiCall(`/applications/${application.id}/ack`, { method: 'POST', body: JSON.stringify({ aureus_applicant_id: 77 }), headers: { 'content-type': 'application/json' } });
  assert.equal(ack.status, 200);
  assert.deepEqual(await (await apiCall('/applications?status=new')).json(), []);
  assert.equal((await apiCall(`/applications/${application.id}/cv`)).status, 404);
  assert.equal((await (await apiCall('/applications?status=imported')).json())[0].aureus_applicant_id, 77);
});

test('dismissing in Aureus (ack with null) marks the application dismissed', async () => {
  await putJob();
  await call('/careers/fleet-dispatcher/apply', { method: 'POST', body: applyForm() });
  const [application] = await (await apiCall('/applications')).json();

  await apiCall(`/applications/${application.id}/ack`, { method: 'POST', body: JSON.stringify({ aureus_applicant_id: null }), headers: { 'content-type': 'application/json' } });

  assert.equal((await (await apiCall('/applications?status=dismissed')).json()).length, 1);
});

test('the form refuses missing answers, a bad CV and no consent, and keeps what was typed', async () => {
  await putJob();

  const response = await call('/careers/fleet-dispatcher/apply', {
    method: 'POST',
    body: applyForm({ q_11: null, consent: null, email: 'not-an-email' }, new File([Buffer.from('MZ fake exe')], 'cv.pdf')),
  });
  const page = await response.text();

  assert.equal(response.status, 422);
  assert.match(page, /Answer: Can you work on-site in Karachi\?/);
  assert.match(page, /Enter a valid email address/);
  assert.match(page, /must be a PDF, DOC or DOCX/);
  assert.match(page, /Tick the box/);
  assert.match(page, /value="Ali Raza"/);
  assert.deepEqual(await (await apiCall('/applications')).json(), []);
});

test('bots filling the hidden field are thanked but nothing is stored', async () => {
  await putJob();
  const response = await call('/careers/fleet-dispatcher/apply', { method: 'POST', body: applyForm({ website: 'http://spam.example' }) });

  assert.equal(response.status, 303);
  assert.deepEqual(await (await apiCall('/applications')).json(), []);
});

test('job slugs stay unique and a token or slug from the outside cannot reach other files', async () => {
  await putJob();
  const second = await (await putJob({ ...PAYLOAD, title: 'Other' }, 'c'.repeat(32))).json();
  assert.equal(second.url, 'https://careers.example.test/careers/fleet-dispatcher-cccccc');

  assert.equal((await apiCall('/jobs/../../etc', { method: 'PUT', body: '{}' })).status, 404);
  assert.equal((await call('/careers/..%2F..%2Fapplications')).status, 404);
});

test('recognises CV files by their content, not their name', () => {
  assert.equal(cvType(PDF, 'x.exe').ext, 'pdf');
  assert.equal(cvType(Buffer.from([0xd0, 0xcf, 0x11, 0xe0, 0, 0]), 'cv.doc').ext, 'doc');
  assert.equal(cvType(Buffer.concat([Buffer.from([0x50, 0x4b, 0x03, 0x04]), Buffer.from('....word/document.xml')]), 'cv.docx').ext, 'docx');
  assert.equal(cvType(Buffer.from('MZ'), 'cv.pdf'), null);
});
