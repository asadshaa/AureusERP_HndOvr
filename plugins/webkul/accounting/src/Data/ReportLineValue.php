<?php

namespace Webkul\Accounting\Data;

use Webkul\Accounting\Enums\LineType;
use Webkul\Accounting\Models\ReportLine;

/**
 * The computed result for one report line: its presentation metadata plus the
 * value in each period (keyed by ReportPeriod::$key).
 *
 * section_header and spacer lines carry no values (empty array); detail and
 * subtotal lines carry one value per period. A null value means "not
 * available": the column or line is scoped to a company outside the viewer's
 * authorised companies, so no figure may be shown.
 */
final class ReportLineValue
{
    /**
     * @param  array<string, float|null>  $values  period_key => value (null = not available)
     */
    public function __construct(
        public readonly int $lineId,
        public readonly ?int $parentId,
        public readonly LineType $lineType,
        public readonly ?string $caption,
        public readonly ?string $code,
        public readonly bool $isVisible,
        public readonly bool $isBold,
        public readonly int $indentLevel,
        public readonly int $sort,
        public readonly array $values,
        public readonly bool $isCheck = false,
    ) {}

    public static function fromLine(ReportLine $line, array $values): self
    {
        $lineType = $line->line_type instanceof LineType
            ? $line->line_type
            : LineType::from((string) $line->line_type);

        return new self(
            lineId: (int) $line->id,
            parentId: $line->parent_id !== null ? (int) $line->parent_id : null,
            lineType: $lineType,
            caption: $line->caption,
            code: $line->code,
            isVisible: (bool) $line->is_visible,
            isBold: (bool) $line->is_bold,
            indentLevel: (int) $line->indent_level,
            sort: (int) $line->sort,
            values: $values,
            isCheck: (bool) $line->is_check,
        );
    }

    public function valueFor(string $periodKey): ?float
    {
        return $this->values[$periodKey] ?? null;
    }

    public function isAvailable(string $periodKey): bool
    {
        return ! array_key_exists($periodKey, $this->values) || $this->values[$periodKey] !== null;
    }

    public function carriesValues(): bool
    {
        return ! in_array($this->lineType, [LineType::SECTION_HEADER, LineType::SPACER], true);
    }

    public function toArray(): array
    {
        return [
            'line_id'      => $this->lineId,
            'parent_id'    => $this->parentId,
            'line_type'    => $this->lineType->value,
            'caption'      => $this->caption,
            'code'         => $this->code,
            'is_visible'   => $this->isVisible,
            'is_bold'      => $this->isBold,
            'is_check'     => $this->isCheck,
            'indent_level' => $this->indentLevel,
            'sort'         => $this->sort,
            'values'       => $this->values,
        ];
    }
}
