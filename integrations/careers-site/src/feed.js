/**
 * Free job-board XML feeds (Indeed organic listings, Jooble, talent.com,
 * recruit.net, Expertini, TipTopJob and a generic feed for any other
 * aggregator) and Google for Jobs structured data, all built from the job
 * payload Aureus sends (API contract aureus/v1).
 *
 * Formats: Indeed's <source>/<job> schema; talent.com's published variant of
 * it (dateposted, isremote, structured salary); Jooble's <jobs>/<job id>
 * schema (link, name, region, pubdate dd.mm.yyyy). recruit.net, Expertini,
 * TipTopJob and the generic feed use the Indeed schema, which these
 * aggregators accept.
 */

/** Board key => feed format. The key is also the feed file name and the job URL's ?src= value. */
export const FEED_BOARDS = {
  indeed: 'indeed',
  jooble: 'jooble',
  talent: 'talent',
  recruitnet: 'indeed',
  expertini: 'indeed',
  tiptopjob: 'indeed',
  generic: 'indeed',
};

/** Boards a job pushed before Aureus sent "boards" stays in (the feeds that existed then). */
export const LEGACY_BOARDS = ['indeed', 'generic'];

/** Whether the job's payload enables this board's feed. */
export function inFeed(job, board) {
  const boards = job?.payload?.boards;

  return Array.isArray(boards) ? boards.includes(board) : LEGACY_BOARDS.includes(board);
}

/** The feed XML for a board; jobs are [{ job, url }] with url already carrying ?src={board}. */
export function boardFeed(board, jobs, options) {
  switch (FEED_BOARDS[board]) {
    case 'jooble':
      return joobleFeed(jobs, options);
    case 'talent':
      return talentFeed(jobs, options);
    default:
      return indeedFeed(jobs, options);
  }
}

const JOB_TYPES = {
  FULL_TIME: 'fulltime',
  PART_TIME: 'parttime',
  CONTRACTOR: 'contract',
  TEMPORARY: 'temporary',
  INTERN: 'internship',
  VOLUNTEER: 'volunteer',
};

export function indeedFeed(jobs, { siteName, siteUrl }) {
  const items = jobs.map(({ job, url }) => {
    const p = job.payload;
    const salary = salaryText(p.salary);

    return `  <job>
    <title>${cdata(p.title)}</title>
    <date>${cdata(httpDate(p.date_posted || job.updated_at))}</date>
    <referencenumber>${cdata(job.token)}</referencenumber>
    <url>${cdata(url)}</url>
    <company>${cdata(p.company || siteName)}</company>
    <city>${cdata(p.location?.city || '')}</city>
    <country>${cdata(p.location?.country || '')}</country>
    <description>${cdata((p.description_html || '') + (p.requirements_html || ''))}</description>${salary ? `
    <salary>${cdata(salary)}</salary>` : ''}${p.employment_type ? `
    <jobtype>${cdata(JOB_TYPES[p.employment_type] || 'other')}</jobtype>` : ''}${p.location?.type === 'remote' ? `
    <remotetype>${cdata('Fully remote')}</remotetype>` : ''}${p.valid_through ? `
    <expirationdate>${cdata(p.valid_through)}</expirationdate>` : ''}
  </job>`;
  });

  return `<?xml version="1.0" encoding="utf-8"?>
<source>
  <publisher>${cdata(siteName)}</publisher>
  <publisherurl>${cdata(siteUrl)}</publisherurl>
  <lastBuildDate>${cdata(httpDate(new Date().toISOString()))}</lastBuildDate>
${items.join('\n')}
</source>
`;
}

const TALENT_JOB_TYPES = {
  FULL_TIME: 'Full time',
  PART_TIME: 'Part time',
  CONTRACTOR: 'Contract',
  TEMPORARY: 'Temporary',
  INTERN: 'Internship',
  VOLUNTEER: 'Volunteer',
};

export function talentFeed(jobs, { siteName, siteUrl }) {
  const items = jobs.map(({ job, url }) => {
    const p = job.payload;
    const salary = p.salary?.visible && (p.salary.min != null || p.salary.max != null) ? p.salary : null;

    return `  <job>
    <referencenumber>${cdata(job.token)}</referencenumber>
    <title>${cdata(p.title)}</title>
    <company>${cdata(p.company || siteName)}</company>
    <city>${cdata(p.location?.city || '')}</city>
    <state>${cdata('')}</state>
    <country>${cdata(p.location?.country || '')}</country>
    <dateposted>${cdata(isoDateTime(p.date_posted || job.updated_at))}</dateposted>
    <url>${cdata(url)}</url>
    <description>${cdata((p.description_html || '') + (p.requirements_html || ''))}</description>${p.valid_through ? `
    <expirationdate>${cdata(talentExpiry(p.valid_through))}</expirationdate>` : ''}${p.employment_type ? `
    <jobtype>${cdata(TALENT_JOB_TYPES[p.employment_type] || 'Other')}</jobtype>` : ''}
    <isremote>${cdata(p.location?.type === 'remote' ? 'yes' : 'no')}</isremote>${p.experience_years ? `
    <experience>
      <experience_min>${cdata(p.experience_years)}</experience_min>
      <period>${cdata('year')}</period>
    </experience>` : ''}${salary ? `
    <salary>${salary.min != null ? `
      <salary_min>${cdata(salary.min)}</salary_min>` : ''}${salary.max != null ? `
      <salary_max>${cdata(salary.max)}</salary_max>` : ''}
      <salary_currency>${cdata(salary.currency || '')}</salary_currency>
      <period>${cdata((salary.period || 'MONTH').toLowerCase())}</period>
      <type>${cdata('BASE_SALARY')}</type>
    </salary>` : ''}
  </job>`;
  });

  return `<?xml version="1.0" encoding="utf-8"?>
<source>
  <publisher>${cdata(siteName)}</publisher>
  <publisherurl>${cdata(siteUrl)}</publisherurl>
  <lastbuilddate>${cdata(isoDateTime(new Date().toISOString()))}</lastbuilddate>
${items.join('\n')}
</source>
`;
}

const JOOBLE_JOB_TYPES = {
  FULL_TIME: 'full-time',
  PART_TIME: 'part-time',
  CONTRACTOR: 'contract',
  TEMPORARY: 'temporary',
  INTERN: 'internship',
  VOLUNTEER: 'volunteer',
};

export function joobleFeed(jobs, { siteName }) {
  const items = jobs.map(({ job, url }) => {
    const p = job.payload;
    const region = [p.location?.city, p.location?.country].filter(Boolean).join(', ');

    return `  <job id="${xmlAttr(job.token)}">
    <link>${cdata(url)}</link>
    <name>${cdata(p.title)}</name>
    <region>${cdata(p.location?.type === 'remote' ? (region ? `${region} (remote)` : 'Remote') : region)}</region>
    <salary>${cdata(salaryText(p.salary))}</salary>
    <description>${cdata((p.description_html || '') + (p.requirements_html || ''))}</description>
    <company>${cdata(p.company || siteName)}</company>
    <pubdate>${cdata(dottedDate(p.date_posted || job.updated_at))}</pubdate>
    <updated>${cdata(dottedDate(job.updated_at || p.date_posted))}</updated>${p.valid_through ? `
    <expire>${cdata(dottedDate(p.valid_through))}</expire>` : ''}${p.employment_type ? `
    <jobtype>${cdata(JOOBLE_JOB_TYPES[p.employment_type] || 'other')}</jobtype>` : ''}
  </job>`;
  });

  return `<?xml version="1.0" encoding="utf-8"?>
<jobs>
${items.join('\n')}
</jobs>
`;
}

export function jobPostingJsonLd(job, url, siteName) {
  const p = job.payload;
  const data = {
    '@context': 'https://schema.org/',
    '@type': 'JobPosting',
    title: p.title,
    description: (p.description_html || '') + (p.requirements_html || ''),
    identifier: { '@type': 'PropertyValue', name: p.company || siteName, value: job.token },
    datePosted: p.date_posted || job.updated_at?.slice(0, 10),
    validThrough: p.valid_through || undefined,
    employmentType: p.employment_type || undefined,
    hiringOrganization: { '@type': 'Organization', name: p.company || siteName },
    url,
  };

  if (p.location?.type === 'remote') {
    data.jobLocationType = 'TELECOMMUTE';
    data.applicantLocationRequirements = { '@type': 'Country', name: p.location?.country || undefined };
  } else {
    data.jobLocation = {
      '@type': 'Place',
      address: { '@type': 'PostalAddress', addressLocality: p.location?.city || undefined, addressCountry: p.location?.country || undefined },
    };
  }

  if (p.salary?.visible && (p.salary.min !== null || p.salary.max !== null)) {
    data.baseSalary = {
      '@type': 'MonetaryAmount',
      currency: p.salary.currency,
      value: { '@type': 'QuantitativeValue', minValue: p.salary.min ?? undefined, maxValue: p.salary.max ?? undefined, unitText: p.salary.period || 'MONTH' },
    };
  }

  if (p.experience_years) {
    data.experienceRequirements = { '@type': 'OccupationalExperienceRequirements', monthsOfExperience: p.experience_years * 12 };
  }

  // "</script>" inside a value must not end the tag.
  return JSON.stringify(data).replace(/</g, '\\u003c');
}

export function salaryText(salary) {
  if (!salary?.visible || (salary.min == null && salary.max == null)) {
    return '';
  }

  const amount = [salary.min, salary.max].filter((value) => value != null).map((value) => Number(value).toLocaleString('en-US')).join(' - ');

  return `${salary.currency || ''} ${amount} per ${(salary.period || 'MONTH').toLowerCase()}`.trim();
}

function cdata(value) {
  return `<![CDATA[${String(value ?? '').replaceAll(']]>', ']]]]><![CDATA[>')}]]>`;
}

function xmlAttr(value) {
  return String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
}

function validDate(value) {
  const date = new Date(value || Date.now());

  return Number.isNaN(date.getTime()) ? new Date() : date;
}

/** 2026-10-29T00:00:00Z */
function isoDateTime(value) {
  return validDate(value).toISOString().replace(/\.\d{3}Z$/, 'Z');
}

/** talent.com's expiry format: MM-dd-yyyyTHH:mm:ssZ (end of the last day to apply). */
function talentExpiry(value) {
  const date = validDate(value);

  return `${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}-${date.getUTCFullYear()}T23:59:59Z`;
}

/** Jooble's date format: dd.mm.yyyy */
function dottedDate(value) {
  const date = validDate(value);

  return `${pad(date.getUTCDate())}.${pad(date.getUTCMonth() + 1)}.${date.getUTCFullYear()}`;
}

function pad(number) {
  return String(number).padStart(2, '0');
}

function httpDate(value) {
  const date = new Date(value || Date.now());

  return Number.isNaN(date.getTime()) ? new Date().toUTCString() : date.toUTCString();
}
