<?php

namespace Webkul\Accounting\Data;

/**
 * Immutable execution context for a report run.
 *
 * Holds the company scope (one or many company IDs) and whether only posted
 * moves are considered. Passing this through every service keeps multi-company
 * behaviour explicit and avoids any service reaching for the authenticated
 * user's default company on its own.
 *
 * A context built for a signed-in user also carries the companies that user
 * may see ($authorizedCompanyIds). Entity-scoped columns and lines can only
 * narrow the scope to companies inside that set (see narrowTo()); null means
 * unrestricted, which is only used by internal callers such as the CLI,
 * seeders and tests.
 */
final class ReportContext
{
    /**
     * @param  array<int, int>  $companyIds
     * @param  array<int, int>|null  $authorizedCompanyIds  companies the viewer may see (null = unrestricted)
     */
    public function __construct(
        public readonly array $companyIds,
        public readonly bool $postedOnly = true,
        public readonly ?int $originalCurrencyId = null,
        public readonly ?array $authorizedCompanyIds = null,
    ) {}

    /**
     * @param  array<int, int>  $companyIds
     */
    public static function forCompanies(array $companyIds, bool $postedOnly = true, ?int $originalCurrencyId = null): self
    {
        return new self(self::normalize($companyIds), $postedOnly, $originalCurrencyId);
    }

    public static function forCompany(int $companyId, bool $postedOnly = true): self
    {
        return new self([$companyId], $postedOnly);
    }

    public static function forCompanyOriginalCurrency(int $companyId, int $currencyId, bool $postedOnly = true): self
    {
        return new self([$companyId], $postedOnly, $currencyId);
    }

    /**
     * Limit this context to the companies a viewer is authorised for. The run
     * scope is intersected with that set; an unscoped run becomes the whole
     * authorised set (never "every company").
     *
     * @param  array<int, int>  $authorizedCompanyIds
     */
    public function restrictedTo(array $authorizedCompanyIds): self
    {
        $authorized = self::normalize($authorizedCompanyIds);

        $scope = $this->companyIds === []
            ? $authorized
            : array_values(array_intersect($this->companyIds, $authorized));

        return new self($scope, $this->postedOnly, $this->originalCurrencyId, $authorized);
    }

    /**
     * The context for an entity override (a column's or line's company): the
     * requested companies intersected with the authorised set. Returns null
     * when none of them may be seen, i.e. the value is "not available".
     *
     * @param  array<int, int>  $companyIds
     */
    public function narrowTo(array $companyIds): ?self
    {
        $requested = self::normalize($companyIds);

        if ($this->authorizedCompanyIds !== null) {
            $requested = array_values(array_intersect($requested, $this->authorizedCompanyIds));
        }

        if ($requested === []) {
            return null;
        }

        return new self($requested, $this->postedOnly, $this->originalCurrencyId, $this->authorizedCompanyIds);
    }

    public function isRestricted(): bool
    {
        return $this->authorizedCompanyIds !== null;
    }

    /**
     * Whether ledger reads must filter by company. A restricted context always
     * filters (an empty restricted scope matches nothing), so an empty scope
     * can never widen to every company for a signed-in viewer.
     */
    public function hasCompanyScope(): bool
    {
        return $this->companyIds !== [] || $this->isRestricted();
    }

    /**
     * @param  array<int, int|string>  $companyIds
     * @return array<int, int>
     */
    private static function normalize(array $companyIds): array
    {
        return array_values(array_unique(array_map('intval', $companyIds)));
    }
}
