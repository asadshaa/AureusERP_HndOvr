import { randomInt, timingSafeEqual } from 'node:crypto';
import { config } from './config.js';
import { indeedFeed } from './feed.js';
import { jobListPage, jobPage, notFoundPage, thanksPage } from './views.js';

/**
 * The careers site as one request handler (Web Request → Response), used by
 * the Vercel function (api/index.js) and the local server (server.js).
 *
 * Public:  /careers, /careers/{slug}, POST /careers/{slug}/apply,
 *          /careers/feed/indeed.xml, /robots.txt, /sitemap.xml
 * Aureus:  /wp-json/aureus/v1/... (HTTP Basic auth with the app password),
 *          the same API the WordPress plugin will offer (contract v1).
 */
export function createApp({ store, env = process.env, now = () => new Date() }) {
  const cfg = config(env);

  return async function handle(request) {
    const url = new URL(request.url);
    const path = (url.searchParams.get('__path') || url.pathname).replace(/\/+$/, '') || '/';
    const origin = cfg.siteUrl || url.origin;
    const method = request.method.toUpperCase();

    try {
      if (path.startsWith('/wp-json/aureus/v1')) {
        return await api(request, method, path.slice('/wp-json/aureus/v1'.length) || '/', url, origin);
      }

      return await site(request, method, path, url, origin);
    } catch (error) {
      console.error(error);

      return path.startsWith('/wp-json/')
        ? json({ message: 'Server error.' }, 500)
        : html('<h1>Something went wrong</h1>', 500);
    }
  };

  // ------------------------------------------------------------------
  // Public site
  // ------------------------------------------------------------------

  async function site(request, method, path, url, origin) {
    if (path === '/' || path === '/careers/index.html') {
      return Response.redirect(`${origin}/careers`, 302);
    }

    if (path === '/robots.txt') {
      return text(cfg.allowIndexing ? `User-agent: *\nAllow: /\nSitemap: ${origin}/sitemap.xml\n` : 'User-agent: *\nDisallow: /\n');
    }

    if (path === '/careers/feed/indeed.xml') {
      const jobs = (await publishedJobs()).map((job) => ({ job, url: jobUrl(origin, job) }));

      return new Response(indeedFeed(jobs, { siteName: cfg.siteName, siteUrl: `${origin}/careers` }), {
        headers: { 'content-type': 'application/xml; charset=utf-8', 'cache-control': 'public, max-age=300' },
      });
    }

    if (path === '/sitemap.xml') {
      const urls = [`${origin}/careers`, ...(await publishedJobs()).map((job) => jobUrl(origin, job))];

      return new Response(`<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${urls.map((u) => `  <url><loc>${xmlEscape(u)}</loc></url>`).join('\n')}\n</urlset>\n`, {
        headers: { 'content-type': 'application/xml; charset=utf-8' },
      });
    }

    if (path === '/careers') {
      return html(jobListPage(await publishedJobs(), cfg));
    }

    const match = path.match(/^\/careers\/([a-z0-9-]+)(\/apply|\/thanks)?$/);

    if (!match) {
      return html(notFoundPage(cfg), 404);
    }

    const job = (await publishedJobs()).find((item) => item.slug === match[1]);

    if (!job) {
      return html(notFoundPage(cfg), 404);
    }

    if (match[2] === '/thanks') {
      return html(thanksPage(job, cfg));
    }

    if (match[2] === '/apply') {
      if (method !== 'POST') {
        return Response.redirect(`${jobUrl(origin, job)}#apply`, 303);
      }

      return apply(request, job, origin);
    }

    return html(jobPage(job, jobUrl(origin, job), cfg, {
      ref: tokenOrEmpty(url.searchParams.get('ref')),
      src: (url.searchParams.get('src') || url.searchParams.get('utm_source') || '').slice(0, 50),
    }));
  }

  async function apply(request, job, origin) {
    const contentLength = Number(request.headers.get('content-length') || 0);

    if (contentLength > (cfg.maxCvKb + 256) * 1024) {
      return html(jobPage(job, jobUrl(origin, job), cfg, { errors: [`The CV is too large (at most ${Math.floor(cfg.maxCvKb / 1024)} MB).`] }), 413);
    }

    const form = await request.formData();

    // Honeypot: bots fill every field; people never see this one.
    if (String(form.get('website') || '') !== '') {
      return Response.redirect(`${jobUrl(origin, job)}/thanks`, 303);
    }

    const old = {
      name: String(form.get('name') || '').trim().slice(0, 200),
      email: String(form.get('email') || '').trim().toLowerCase().slice(0, 200),
      phone: String(form.get('phone') || '').trim().slice(0, 50),
      consent: form.get('consent') === '1',
    };
    const errors = [];
    const answers = [];

    if (!old.name) errors.push('Enter your full name.');
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(old.email)) errors.push('Enter a valid email address.');
    if (!old.consent) errors.push('Tick the box to agree to your application being stored.');

    for (const question of job.payload.questions || []) {
      const name = `q_${question.id}`;
      const value = question.type === 'multi_choice'
        ? form.getAll(name).map(String).filter((option) => (question.options || []).includes(option))
        : String(form.get(name) ?? '').trim().slice(0, 2000);
      old[name] = value;

      const empty = Array.isArray(value) ? value.length === 0 : value === '';

      if (empty) {
        if (question.required) errors.push(`Answer: ${question.label}`);
        continue;
      }

      if (question.type === 'yes_no' && !['yes', 'no'].includes(value)) {
        errors.push(`Answer yes or no: ${question.label}`);
        continue;
      }

      if (question.type === 'number' && !Number.isFinite(Number(value))) {
        errors.push(`Enter a number: ${question.label}`);
        continue;
      }

      if (question.type === 'single_choice' && !(question.options || []).includes(value)) {
        errors.push(`Choose an option: ${question.label}`);
        continue;
      }

      answers.push({ question_id: question.id, answer: question.type === 'number' ? Number(value) : value });
    }

    const file = form.get('cv');
    let cv = null;

    if (!file || typeof file === 'string' || file.size === 0) {
      errors.push('Attach your CV.');
    } else if (file.size > cfg.maxCvKb * 1024) {
      errors.push(`The CV is too large (at most ${Math.floor(cfg.maxCvKb / 1024)} MB).`);
    } else {
      const bytes = Buffer.from(await file.arrayBuffer());
      const type = cvType(bytes, file.name);

      if (!type) {
        errors.push('The CV must be a PDF, DOC or DOCX file.');
      } else {
        cv = { bytes, ...type, filename: safeFilename(file.name, type.ext) };
      }
    }

    if (errors.length) {
      return html(jobPage(job, jobUrl(origin, job), cfg, { errors, old, ref: tokenOrEmpty(String(form.get('ref') || '')), src: String(form.get('src') || '').slice(0, 50) }), 422);
    }

    const id = Number(`${now().getTime()}${String(randomInt(0, 1000)).padStart(3, '0')}`);
    const cvKey = `cvs/${id}.${cv.ext}`;

    await store.putFile(cvKey, cv.bytes, cv.mime);
    await store.putJson(`applications/${id}.json`, {
      id,
      status: 'new',
      job_token: job.token,
      ref: tokenOrEmpty(String(form.get('ref') || '')) || null,
      src: String(form.get('src') || '').slice(0, 50) || null,
      submitted_at: now().toISOString(),
      name: old.name,
      email: old.email,
      phone: old.phone || null,
      answers,
      consent: { privacy: true, ai_screening: true },
      cv: { filename: cv.filename, size: cv.bytes.length, mime: cv.mime, key: cvKey },
    });

    return Response.redirect(`${jobUrl(origin, job)}/thanks`, 303);
  }

  // ------------------------------------------------------------------
  // Aureus API (contract aureus/v1)
  // ------------------------------------------------------------------

  async function api(request, method, path, url, origin) {
    if (!cfg.appPassword) {
      return json({ message: 'The Aureus connection is not configured on this site (AUREUS_APP_PASSWORD).' }, 503);
    }

    if (!authorised(request.headers.get('authorization'))) {
      return json({ message: 'Wrong user or application password.' }, 401, { 'www-authenticate': 'Basic realm="aureus"' });
    }

    if (method === 'GET' && path === '/ping') {
      return json({ ok: true, version: cfg.version, site: cfg.siteName });
    }

    const jobMatch = path.match(/^\/jobs\/([0-9a-f]{32})$/);

    if (jobMatch && method === 'PUT') {
      const payload = await request.json().catch(() => null);

      if (!payload || typeof payload !== 'object' || !String(payload.title || '').trim()) {
        return json({ message: 'A job needs at least a title.' }, 422);
      }

      const token = jobMatch[1];
      const existing = await store.getJson(`jobs/${token}.json`);
      const slug = await uniqueSlug(slugify(payload.slug || payload.title), token);
      const job = {
        token,
        slug,
        status: payload.status === 'published' ? 'published' : 'unlisted',
        payload,
        created_at: existing?.created_at || now().toISOString(),
        updated_at: now().toISOString(),
      };

      await store.putJson(`jobs/${token}.json`, job);

      return json({ url: jobUrl(origin, job), status: job.status });
    }

    if (jobMatch && method === 'DELETE') {
      const job = await store.getJson(`jobs/${jobMatch[1]}.json`);

      if (job) {
        await store.putJson(`jobs/${jobMatch[1]}.json`, { ...job, status: 'unlisted', updated_at: now().toISOString() });
      }

      return json({ ok: true });
    }

    if (method === 'GET' && path === '/applications') {
      const status = url.searchParams.get('status') || 'new';
      const limit = Math.max(1, Math.min(100, Number.parseInt(url.searchParams.get('limit') || '50', 10) || 50));
      const applications = (await allApplications())
        .filter((application) => application.status === status)
        .sort((a, b) => a.id - b.id)
        .slice(0, limit)
        .map(({ cv, ...application }) => ({ ...application, cv: cv ? { filename: cv.filename, size: cv.size, mime: cv.mime } : null }));

      return json(applications);
    }

    const cvMatch = path.match(/^\/applications\/(\d+)\/cv$/);

    if (cvMatch && method === 'GET') {
      const application = await store.getJson(`applications/${cvMatch[1]}.json`);
      const file = application?.cv?.key ? await store.getFile(application.cv.key) : null;

      if (!file) {
        return json({ message: 'No CV for this application.' }, 404);
      }

      return new Response(file.bytes, {
        headers: {
          'content-type': file.contentType || application.cv.mime || 'application/octet-stream',
          'content-length': String(file.size),
          'content-disposition': `attachment; filename="${application.cv.filename.replace(/"/g, '')}"`,
          'cache-control': 'no-store',
        },
      });
    }

    const ackMatch = path.match(/^\/applications\/(\d+)\/ack$/);

    if (ackMatch && method === 'POST') {
      const key = `applications/${ackMatch[1]}.json`;
      const application = await store.getJson(key);

      if (!application) {
        return json({ message: 'Unknown application.' }, 404);
      }

      const body = await request.json().catch(() => ({}));
      const applicantId = Number.isInteger(body?.aureus_applicant_id) ? body.aureus_applicant_id : null;

      // Imported (or dismissed in Aureus): the CV is no longer needed on the website.
      if (application.cv?.key) {
        await store.delete(application.cv.key);
      }

      await store.putJson(key, {
        ...application,
        status: applicantId ? 'imported' : 'dismissed',
        aureus_applicant_id: applicantId,
        acknowledged_at: now().toISOString(),
        cv: application.cv ? { ...application.cv, key: null, deleted: true } : null,
      });

      return json({ ok: true });
    }

    return json({ message: 'Not found.' }, 404);
  }

  // ------------------------------------------------------------------
  // Helpers
  // ------------------------------------------------------------------

  function authorised(header) {
    const match = /^Basic\s+(.+)$/i.exec(header || '');

    if (!match) {
      return false;
    }

    const [user, ...rest] = Buffer.from(match[1], 'base64').toString('utf8').split(':');
    const given = Buffer.from(`${user}:${rest.join(':').replace(/\s+/g, '')}`);
    const expected = Buffer.from(`${cfg.username}:${cfg.appPassword.replace(/\s+/g, '')}`);

    return given.length === expected.length && timingSafeEqual(given, expected);
  }

  async function allJobs() {
    const keys = await store.list('jobs');

    return (await Promise.all(keys.filter((key) => key.endsWith('.json')).map((key) => store.getJson(key)))).filter(Boolean);
  }

  async function publishedJobs() {
    const today = now().toISOString().slice(0, 10);

    return (await allJobs())
      .filter((job) => job.status === 'published' && (!job.payload.valid_through || job.payload.valid_through >= today))
      .sort((a, b) => String(b.payload.date_posted || b.created_at).localeCompare(String(a.payload.date_posted || a.created_at)));
  }

  async function allApplications() {
    const keys = await store.list('applications');

    return (await Promise.all(keys.filter((key) => key.endsWith('.json')).map((key) => store.getJson(key)))).filter(Boolean);
  }

  async function uniqueSlug(base, token) {
    const taken = new Set((await allJobs()).filter((job) => job.token !== token).map((job) => job.slug));
    let slug = base || `job-${token.slice(0, 8)}`;

    if (taken.has(slug)) {
      slug = `${slug}-${token.slice(0, 6)}`;
    }

    return slug;
  }

  function jobUrl(origin, job) {
    return `${origin}/careers/${job.slug}`;
  }
}

export function cvType(bytes, name = '') {
  const ext = String(name).toLowerCase().split('.').pop();

  if (bytes.subarray(0, 5).toString('latin1') === '%PDF-') {
    return { ext: 'pdf', mime: 'application/pdf' };
  }

  if (bytes.subarray(0, 4).equals(Buffer.from([0xd0, 0xcf, 0x11, 0xe0]))) {
    return { ext: 'doc', mime: 'application/msword' };
  }

  if (bytes.subarray(0, 4).equals(Buffer.from([0x50, 0x4b, 0x03, 0x04])) && bytes.includes(Buffer.from('word/'))) {
    return { ext: 'docx', mime: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' };
  }

  return ext === 'docx' && bytes.subarray(0, 2).toString('latin1') === 'PK'
    ? { ext: 'docx', mime: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' }
    : null;
}

function safeFilename(name, ext) {
  const base = String(name || 'cv').replace(/\.[^.]*$/, '').replace(/[^A-Za-z0-9 _.-]+/g, '').trim().slice(0, 80) || 'cv';

  return `${base}.${ext}`;
}

function slugify(value) {
  return String(value || '')
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 120);
}

function tokenOrEmpty(value) {
  return /^[0-9a-f]{32}$/.test(String(value || '').toLowerCase()) ? String(value).toLowerCase() : '';
}

function xmlEscape(value) {
  return String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
}

function json(data, status = 200, headers = {}) {
  return new Response(JSON.stringify(data), { status, headers: { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store', ...headers } });
}

function html(body, status = 200) {
  return new Response(body, { status, headers: { 'content-type': 'text/html; charset=utf-8' } });
}

function text(body) {
  return new Response(body, { headers: { 'content-type': 'text/plain; charset=utf-8' } });
}
