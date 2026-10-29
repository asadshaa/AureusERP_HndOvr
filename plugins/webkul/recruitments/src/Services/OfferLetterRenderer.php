<?php

namespace Webkul\Recruitment\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Throwable;
use Webkul\Recruitment\Models\JobOffer;
use Webkul\Recruitment\Models\OfferLetterTemplate;
use Webkul\Security\Models\User;
use Webkul\Support\Models\Company;

/**
 * Merges an offer into its company's letter template and renders the
 * branded PDF (company name and logo) on the private disk (HR parity 3.6).
 * Templates are HR-written rich text: the HTML is sanitised and only the
 * placeholders below are replaced (values escaped), so a template cannot
 * inject markup or reach other data.
 */
class OfferLetterRenderer
{
    /** Placeholder => what it is, for the template editor. */
    public const PLACEHOLDERS = [
        'candidate_name'   => 'Candidate name',
        'position'         => 'Position (designation, else the job)',
        'designation'      => 'Designation',
        'department'       => 'Department',
        'company_name'     => 'Company name',
        'manager_name'     => 'Reporting manager',
        'joining_date'     => 'Joining date',
        'expiry_date'      => 'Offer valid until',
        'offer_date'       => 'Date of the letter',
        'salary'           => 'Monthly gross salary',
        'salary_breakdown' => 'Salary breakdown (one line per component)',
        'probation_months' => 'Probation (months)',
        'working_hours'    => 'Working hours',
        'benefits'         => 'Benefits',
        'reference'        => 'Offer reference',
        'signatory_name'   => 'Signed by (HR)',
    ];

    public function template(JobOffer $offer): ?OfferLetterTemplate
    {
        $offer->loadMissing('template');

        if ($offer->template && $offer->template->is_active && (int) $offer->template->company_id === (int) $offer->company_id) {
            return $offer->template;
        }

        return OfferLetterTemplate::preferredFor((int) $offer->company_id);
    }

    /**
     * @return array<string, string|Htmlable>
     */
    public function values(JobOffer $offer, ?User $signatory = null): array
    {
        $offer->loadMissing(['company.currency', 'candidate', 'applicant.candidate', 'job.manager', 'department', 'reportingManager', 'currency']);
        $currency = $offer->currencyCode();

        $breakdown = collect($offer->breakdown())
            ->map(fn ($amount, string $key): string => e(__(JobOffer::BREAKDOWN_COMPONENTS[$key])).': '.e(static::money($amount, $currency)))
            ->values();

        $benefits = trim((string) $offer->benefits);

        return [
            'candidate_name'   => $offer->candidateName(),
            'position'         => $offer->positionLabel(),
            'designation'      => $offer->positionLabel(),
            'department'       => (string) ($offer->department?->name ?? '—'),
            'company_name'     => (string) ($offer->company?->name ?? ''),
            'manager_name'     => (string) ($offer->reportingManager?->name ?? $offer->job?->manager?->name ?? __('your line manager')),
            'joining_date'     => static::date($offer->joining_date),
            'expiry_date'      => static::date($offer->expiry_date),
            'offer_date'       => static::date(now(static::timezone())),
            'salary'           => static::money($offer->salary_gross, $currency),
            'salary_breakdown' => new HtmlString($breakdown->isEmpty() ? '' : $breakdown->implode('<br>').'<br>'.e(__('Monthly gross')).': <strong>'.e(static::money($offer->salary_gross, $currency)).'</strong>'),
            'probation_months' => (string) ((int) $offer->probation_months),
            'working_hours'    => (string) ($offer->working_hours ?: config('recruitment.offers.default_working_hours')),
            'benefits'         => new HtmlString($benefits === '' ? e(__('As per company policy.')) : nl2br(e($benefits))),
            'reference'        => (string) ($offer->reference ?? ''),
            'signatory_name'   => (string) ($signatory?->name ?? $offer->sender?->name ?? __('Human Resources')),
        ];
    }

    /** The template body with every placeholder replaced (sanitised HTML). */
    public function renderBody(JobOffer $offer, ?User $signatory = null): string
    {
        return $this->merge((string) ($this->template($offer)?->body ?: static::defaultTemplateBody()), $this->values($offer, $signatory));
    }

    /** The email subject from the template (placeholders as plain text). */
    public function emailSubject(JobOffer $offer): string
    {
        $subject = (string) ($this->template($offer)?->email_subject ?: static::defaultEmailSubject());
        $values = $this->values($offer);

        return trim(preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', function (array $match) use ($values): string {
            $value = $values[strtolower($match[1])] ?? null;

            return $value === null ? $match[0] : trim(strip_tags($value instanceof Htmlable ? $value->toHtml() : (string) $value));
        }, $subject));
    }

    /**
     * @param  array<string, string|Htmlable>  $values
     */
    public function merge(string $body, array $values): string
    {
        $tags = array_keys(static::PLACEHOLDERS);

        // Placeholders inserted with the editor's merge-tag button become
        // "{{tag}}" text again; the HTML is sanitised before any value goes in.
        try {
            $html = RichContentRenderer::make($body)
                ->mergeTags(array_combine($tags, array_map(fn (string $tag): string => '{{'.$tag.'}}', $tags)))
                ->toHtml();
        } catch (Throwable $e) {
            report($e);
            $html = nl2br(e(strip_tags($body)));
        }

        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', function (array $match) use ($values): string {
            $key = strtolower($match[1]);

            if (! array_key_exists($key, $values)) {
                return $match[0];
            }

            return $values[$key] instanceof Htmlable ? $values[$key]->toHtml() : e((string) $values[$key]);
        }, $html);
    }

    /** The branded PDF (binary). */
    public function pdf(JobOffer $offer, ?User $signatory = null): string
    {
        $offer->loadMissing(['company.country', 'company.partner']);

        return Pdf::loadView('recruitments::offers.letter-pdf', [
            'offer'     => $offer,
            'company'   => $offer->company,
            'logo'      => $this->companyLogoDataUri($offer->company),
            'body'      => new HtmlString($this->renderBody($offer, $signatory)),
            'issuedOn'  => now(static::timezone()),
            'reference' => $offer->reference,
        ])->setPaper('a4')->output();
    }

    /** Renders and stores the PDF on the private disk; returns its path. */
    public function store(JobOffer $offer, ?User $signatory = null): string
    {
        $path = 'recruitments/offers/'.$offer->company_id.'/'.$offer->id.'-'.now()->format('YmdHis').'.pdf';

        Storage::disk(static::disk())->put($path, $this->pdf($offer, $signatory));

        return $path;
    }

    public static function disk(): string
    {
        return (string) config('recruitment.offers.disk', 'local');
    }

    public static function timezone(): string
    {
        return (string) config('recruitment.offers.timezone', 'Asia/Karachi');
    }

    public static function money(mixed $amount, string $currency): string
    {
        if ($amount === null || $amount === '') {
            return '—';
        }

        $value = (float) $amount;
        $decimals = floor($value) === $value ? 0 : 2;

        return $currency.' '.number_format($value, $decimals);
    }

    public static function date(mixed $date): string
    {
        return $date ? Carbon::parse($date)->format('d F Y') : '—';
    }

    public static function defaultEmailSubject(): string
    {
        return 'Offer of employment: {{position}} at {{company_name}}';
    }

    /** A professional default offer letter for Pakistan (company-editable copy is seeded). */
    public static function defaultTemplateBody(): string
    {
        return <<<'HTML'
<p>Date: {{offer_date}}<br>Ref: {{reference}}</p>
<p><strong>Subject: Offer of employment as {{position}}</strong></p>
<p>Dear {{candidate_name}},</p>
<p>Further to your recent interviews, we are pleased to offer you employment with <strong>{{company_name}}</strong> as <strong>{{designation}}</strong> in the {{department}} department, on the terms and conditions set out below.</p>
<h3>1. Joining and reporting</h3>
<p>Your date of joining will be <strong>{{joining_date}}</strong>. You will report to {{manager_name}}.</p>
<h3>2. Compensation</h3>
<p>Your monthly gross salary will be <strong>{{salary}}</strong>.</p>
<p>{{salary_breakdown}}</p>
<p>Salary is paid monthly in arrears by bank transfer. Income tax is deducted at source under the Income Tax Ordinance, 2001, and EOBI and provincial social security contributions are made as applicable under the law.</p>
<h3>3. Probation and confirmation</h3>
<p>You will be on probation for {{probation_months}} month(s) from your date of joining. During probation either party may end the employment with seven (7) days' written notice. On confirmation the notice period is one (1) month, or one month's salary in lieu of notice.</p>
<h3>4. Working hours</h3>
<p>{{working_hours}}. You may be asked to work additional hours when the business requires it.</p>
<h3>5. Benefits</h3>
<p>{{benefits}}</p>
<h3>6. Conditions of this offer</h3>
<p>This offer is subject to the verification of your CNIC, educational and experience documents and to satisfactory references. You will keep the company's business, client and employee information confidential during and after your employment, and follow the company's policies and code of conduct as amended from time to time.</p>
<h3>7. Acceptance</h3>
<p>This offer is valid until <strong>{{expiry_date}}</strong>. Please accept or decline it with the link in the email that came with this letter. On your first day, please bring your original CNIC, educational certificates, experience letters and two passport-size photographs.</p>
<p>We look forward to welcoming you to {{company_name}}.</p>
<p>Yours sincerely,</p>
<p><strong>{{signatory_name}}</strong><br>Human Resources<br>{{company_name}}</p>
HTML;
    }

    /** The company logo (its contact's picture) as a data URI dompdf can embed without remote access. */
    protected function companyLogoDataUri(?Company $company): ?string
    {
        $logo = $company?->getAttribute('logo') ?: $company?->partner?->avatar;

        if (blank($logo)) {
            return null;
        }

        foreach (['public', 'local'] as $disk) {
            try {
                if (Storage::disk($disk)->exists($logo)) {
                    $mime = Storage::disk($disk)->mimeType($logo) ?: 'image/png';

                    return str_starts_with($mime, 'image/') ? 'data:'.$mime.';base64,'.base64_encode(Storage::disk($disk)->get($logo)) : null;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }
}
