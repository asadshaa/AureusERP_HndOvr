<?php

use Illuminate\Support\Facades\Log;
use Webkul\Support\Models\Company;
use Webkul\Support\Models\Currency;
use Webkul\Support\Models\CurrencyRate;

function conversionCurrencyPair(): array
{
    $company = Company::factory()->create();
    $from = Currency::query()->where('code', 'EUR')->firstOrFail();
    $to = Currency::query()->where('code', 'USD')->firstOrFail();

    // A stray pre-existing rate for this exact pair/company from earlier
    // seed data or another test would silently defeat these assertions --
    // make sure the slate is genuinely clean for this pair and company.
    CurrencyRate::query()
        ->where('currency_id', $to->id)
        ->where(fn ($query) => $query->whereNull('company_id')->orWhere('company_id', $company->id))
        ->delete();

    return compact('company', 'from', 'to');
}

it('still falls back to a 1:1 rate by default when none is configured, unchanged from before', function () {
    $pair = conversionCurrencyPair();

    $rate = $pair['to']->getConversionRate($pair['from'], $pair['to'], $pair['company'], '2026-01-01');

    expect((float) $rate)->toBe(1.0);
});

it('logs a warning instead of staying completely silent when falling back', function () {
    Log::spy();

    $pair = conversionCurrencyPair();
    $pair['to']->getConversionRate($pair['from'], $pair['to'], $pair['company'], '2026-01-01');

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, 'EUR') && str_contains($message, 'USD'));
});

it('throws a plain-language error instead of a silent 1:1 fallback in strict mode', function () {
    $pair = conversionCurrencyPair();

    expect(fn () => $pair['to']->getConversionRate($pair['from'], $pair['to'], $pair['company'], '2026-01-01', strict: true))
        ->toThrow(RuntimeException::class, "no exchange rate set up for converting EUR to USD");
});

it('uses the real configured rate in strict mode when one exists, same as non-strict', function () {
    $pair = conversionCurrencyPair();
    CurrencyRate::factory()->create([
        'currency_id' => $pair['to']->id,
        'company_id'  => $pair['company']->id,
        'rate'        => 1.1,
        'name'        => '2025-12-01',
    ]);

    $strict = $pair['to']->getConversionRate($pair['from'], $pair['to'], $pair['company'], '2026-01-01', strict: true);
    $lenient = $pair['to']->getConversionRate($pair['from'], $pair['to'], $pair['company'], '2026-01-01');

    expect((float) $strict)->toBe(1.1)
        ->and((float) $lenient)->toBe(1.1);
});

it('never throws for the same currency on either side, in strict or lenient mode', function () {
    $company = Company::factory()->create();
    $currency = Currency::factory()->create();

    expect($currency->getConversionRate($currency, $currency, $company, '2026-01-01', strict: true))->toBe(1)
        ->and($currency->getConversionRate($currency, $currency, $company, '2026-01-01'))->toBe(1);
});

it('convert() propagates strict mode through to getConversionRate()', function () {
    $pair = conversionCurrencyPair();

    expect(fn () => $pair['from']->convert(100, $pair['to'], $pair['company'], '2026-01-01', strict: true))
        ->toThrow(RuntimeException::class, 'no exchange rate set up');

    // Non-strict conversion still quietly succeeds using the 1:1 fallback,
    // exactly as it always has.
    expect($pair['from']->convert(100, $pair['to'], $pair['company'], '2026-01-01'))->toBe(100.0);
});
