import { jobPostingJsonLd, salaryText } from './feed.js';

/** HTML pages of the careers site. Every value from Aureus or a visitor is escaped. */

const LOCATION_LABELS = { on_site: 'On-site', hybrid: 'Hybrid', remote: 'Remote' };

export function esc(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}

/** Descriptions from Aureus are already reduced to safe tags; strip anything else again (defence in depth). */
export function safeHtml(html) {
  return String(html ?? '')
    .replace(/<\s*(script|style|iframe|object|embed)[^>]*>[\s\S]*?<\s*\/\s*\1\s*>/gi, '')
    .replace(/<(?!\/?(p|br|ul|ol|li|strong|b|em|i|u|h2|h3|h4|blockquote)\b)[^>]*>/gi, '')
    .replace(/<(\w+)\s[^>]*>/g, '<$1>');
}

export function layout({ title, body, cfg, head = '' }) {
  return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${esc(title)} · ${esc(cfg.siteName)} Careers</title>
${cfg.allowIndexing ? '' : '<meta name="robots" content="noindex, nofollow">'}
${head}
<style>
  :root { --ink:#1f2937; --muted:#6b7280; --line:#e5e7eb; --brand:#0f5fa8; --bg:#f8fafc; --ok:#15803d; --bad:#b91c1c; }
  * { box-sizing: border-box; }
  body { margin:0; font: 16px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color:var(--ink); background:var(--bg); }
  header { background:#fff; border-bottom:1px solid var(--line); }
  .wrap { max-width: 860px; margin: 0 auto; padding: 0 16px; }
  header .wrap { display:flex; align-items:center; justify-content:space-between; height:64px; }
  header a { color:var(--ink); text-decoration:none; font-weight:700; }
  .demo { background:#fef3c7; color:#92400e; text-align:center; font-size:14px; padding:6px 16px; }
  main { padding: 24px 0 48px; }
  h1 { font-size: 28px; margin: 8px 0 16px; }
  .card { background:#fff; border:1px solid var(--line); border-radius:12px; padding:20px; margin-bottom:16px; }
  .job-link { color:var(--brand); font-size:20px; font-weight:700; text-decoration:none; }
  .meta { color:var(--muted); font-size:14px; display:flex; gap:12px; flex-wrap:wrap; margin-top:4px; }
  .btn { display:inline-block; background:var(--brand); color:#fff; border:0; border-radius:8px; padding:12px 20px; font-size:16px; font-weight:600; cursor:pointer; text-decoration:none; }
  label { display:block; font-weight:600; margin:14px 0 4px; }
  input[type=text], input[type=email], input[type=tel], input[type=number], select, textarea { width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; font:inherit; background:#fff; }
  .opt { font-weight:400; display:flex; gap:8px; align-items:center; margin:4px 0; }
  .hint { color:var(--muted); font-size:13px; font-weight:400; }
  .errors { background:#fef2f2; color:var(--bad); border:1px solid #fecaca; border-radius:8px; padding:12px 16px; }
  .hp { position:absolute; left:-10000px; width:1px; height:1px; overflow:hidden; }
  footer { color:var(--muted); font-size:13px; text-align:center; padding:24px 0; }
</style>
</head>
<body>
${cfg.demo ? '<div class="demo">Demo careers site for testing Aureus. These are not real job openings.</div>' : ''}
<header><div class="wrap"><a href="/careers">${esc(cfg.siteName)} · Careers</a></div></header>
<main><div class="wrap">${body}</div></main>
<footer>${esc(cfg.siteName)}${cfg.privacyUrl ? ` · <a href="${esc(cfg.privacyUrl)}">Privacy</a>` : ''}</footer>
</body>
</html>`;
}

export function jobListPage(jobs, cfg) {
  const items = jobs.length === 0
    ? '<div class="card">No open positions right now. Please check back soon.</div>'
    : jobs.map((job) => {
      const p = job.payload;

      return `<div class="card">
  <a class="job-link" href="/careers/${esc(job.slug)}">${esc(p.title)}</a>
  <div class="meta">${metaItems(p).map((item) => `<span>${esc(item)}</span>`).join('')}</div>
</div>`;
    }).join('\n');

  return layout({ title: 'Open positions', cfg, body: `<h1>Open positions</h1>\n${items}` });
}

export function jobPage(job, url, cfg, { errors = [], old = {}, ref = '', src = '' } = {}) {
  const p = job.payload;
  const questions = (p.questions || []).map((question) => questionField(question, old)).join('\n');
  const errorBox = errors.length ? `<div class="errors" id="form-errors" tabindex="-1"><strong>Please fix the following:</strong><ul>${errors.map((e) => `<li>${esc(e)}</li>`).join('')}</ul></div>` : '';

  const body = `
<p><a href="/careers">← All positions</a></p>
<div class="card">
  <h1>${esc(p.title)}</h1>
  <div class="meta">${metaItems(p).map((item) => `<span>${esc(item)}</span>`).join('')}</div>
  ${p.description_html ? `<h2>About the job</h2>${safeHtml(p.description_html)}` : ''}
  ${p.requirements_html ? `<h2>Requirements</h2>${safeHtml(p.requirements_html)}` : ''}
  ${p.valid_through ? `<p class="hint">Apply before ${esc(p.valid_through)}.</p>` : ''}
</div>
<div class="card" id="apply">
  <h2>Apply for this job</h2>
  ${errorBox}
  <form method="post" action="/careers/${esc(job.slug)}/apply#apply" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="ref" value="${esc(ref)}">
    <input type="hidden" name="src" value="${esc(src)}">
    <div class="hp" aria-hidden="true"><label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
    <label for="name">Full name *</label>
    <input type="text" id="name" name="name" required maxlength="200" value="${esc(old.name)}">
    <label for="email">Email *</label>
    <input type="email" id="email" name="email" required maxlength="200" value="${esc(old.email)}">
    <label for="phone">Phone</label>
    <input type="tel" id="phone" name="phone" maxlength="50" value="${esc(old.phone)}">
    <label for="cv">CV * <span class="hint">PDF, DOC or DOCX, up to ${Math.floor(cfg.maxCvKb / 1024)} MB</span></label>
    <input type="file" id="cv" name="cv" required accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
    ${questions}
    <label class="opt"><input type="checkbox" name="consent" value="1" ${old.consent ? 'checked' : ''} required> I agree that ${esc(p.company || cfg.siteName)} stores my application and CV to assess it for this job. Applications are screened with automated tools; a recruiter makes every decision.</label>
    <p><button class="btn" type="submit">Send application</button></p>
  </form>
</div>
<script type="application/ld+json">${jobPostingJsonLd(job, url, cfg.siteName)}</script>${errors.length ? '<script>document.getElementById("form-errors").scrollIntoView({block:"center"});document.getElementById("form-errors").focus();</script>' : ''}`;

  return layout({
    title: p.title,
    cfg,
    body,
    head: `<link rel="canonical" href="${esc(url)}">
<meta property="og:title" content="${esc(p.title)} · ${esc(p.company || cfg.siteName)}">
<meta property="og:type" content="website">
<meta property="og:url" content="${esc(url)}">`,
  });
}

export function thanksPage(job, cfg) {
  return layout({
    title: 'Application received',
    cfg,
    body: `<div class="card"><h1>Thank you!</h1><p>We received your application for <strong>${esc(job.payload.title)}</strong>. Our recruitment team will review it and contact you if your profile matches.</p><p><a href="/careers">See other positions</a></p></div>`,
  });
}

export function notFoundPage(cfg) {
  return layout({ title: 'Not found', cfg, body: '<div class="card"><h1>This position is no longer open</h1><p><a href="/careers">See open positions</a></p></div>' });
}

function metaItems(p) {
  return [
    [p.location?.city, p.location?.country].filter(Boolean).join(', '),
    LOCATION_LABELS[p.location?.type] || '',
    (p.employment_type || '').replace('_', ' ').toLowerCase().replace(/^\w/, (c) => c.toUpperCase()),
    salaryText(p.salary),
  ].filter(Boolean);
}

function questionField(question, old) {
  const name = `q_${question.id}`;
  const required = question.required ? ' *' : '';
  const help = question.help_text ? ` <span class="hint">${esc(question.help_text)}</span>` : '';
  const value = old[name];
  const label = `<label>${esc(question.label)}${required}${help}</label>`;

  switch (question.type) {
    case 'yes_no':
      return `${label}${['yes', 'no'].map((option) => `<label class="opt"><input type="radio" name="${name}" value="${option}" ${value === option ? 'checked' : ''}> ${option === 'yes' ? 'Yes' : 'No'}</label>`).join('')}`;
    case 'number':
      return `${label}<input type="number" name="${name}" step="any" value="${esc(value)}">`;
    case 'single_choice':
      return `${label}${(question.options || []).map((option) => `<label class="opt"><input type="radio" name="${name}" value="${esc(option)}" ${value === option ? 'checked' : ''}> ${esc(option)}</label>`).join('')}`;
    case 'multi_choice':
      return `${label}${(question.options || []).map((option) => `<label class="opt"><input type="checkbox" name="${name}" value="${esc(option)}" ${Array.isArray(value) && value.includes(option) ? 'checked' : ''}> ${esc(option)}</label>`).join('')}`;
    default:
      return `${label}<textarea name="${name}" rows="3" maxlength="2000">${esc(value)}</textarea>`;
  }
}
