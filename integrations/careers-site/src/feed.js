/**
 * Indeed XML job feed (organic, free) and Google for Jobs structured data,
 * both built from the job payload Aureus sends (API contract aureus/v1).
 */

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

function httpDate(value) {
  const date = new Date(value || Date.now());

  return Number.isNaN(date.getTime()) ? new Date().toUTCString() : date.toUTCString();
}
