<?php

namespace Webkul\Accounting\Filament\Clusters\Reporting\Pages\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Webkul\Accounting\Data\ReportColumnSpec;
use Webkul\Accounting\Data\ReportLineValue;
use Webkul\Accounting\Enums\LineType;
use Webkul\Accounting\Models\ReportTemplate;

/**
 * Excel export mirroring the workbook layout: caption column, entity/period
 * header rows, spacer columns and blank rows, bold subtotals, accounting
 * number formats and workbook-like column widths. Values are written as
 * numbers so the exported file needs no manual cleanup.
 */
class ReportSpreadsheetExport implements FromArray, WithColumnWidths, WithEvents, WithStyles, WithTitle
{
    protected const HEADER_ROWS = 2;

    /** @var array<int, bool> 1-based sheet rows that render bold */
    protected array $boldRows = [];

    /** @var array<int, bool> 1-based sheet rows that are check rows */
    protected array $checkRows = [];

    /**
     * @param  array<int, ReportColumnSpec>  $columns
     * @param  Collection<int, ReportLineValue>  $rows
     */
    public function __construct(
        protected ReportTemplate $template,
        protected array $columns,
        protected Collection $rows,
        protected bool $usd,
        protected int $year,
    ) {}

    public function title(): string
    {
        return mb_substr($this->template->name, 0, 31);
    }

    public function array(): array
    {
        $grid = [];

        $labels = [$this->template->name];
        $subLabels = [''];

        foreach ($this->columns as $column) {
            $labels[] = $column->isSpacer() ? '' : $column->label;
            $subLabels[] = $this->subLabel($column);
        }

        $grid[] = $labels;
        $grid[] = $subLabels;

        $this->boldRows[1] = true;
        $this->boldRows[2] = true;

        $sheetRow = self::HEADER_ROWS;

        foreach ($this->rows as $row) {
            if (! $row->isVisible) {
                continue;
            }

            $sheetRow++;

            if ($row->lineType === LineType::SPACER) {
                $grid[] = [''];

                continue;
            }

            $cells = [$row->caption];

            foreach ($this->columns as $column) {
                if ($column->isSpacer() || ! $row->carriesValues()) {
                    $cells[] = '';

                    continue;
                }

                $value = $row->valueFor($column->key);

                // null = an entity the viewer cannot access: never a figure.
                $cells[] = match (true) {
                    $value === null        => __('accounting::filament/clusters/reporting/pages/financial-reports.content.not-available'),
                    abs($value) >= 0.005   => round($value, 2),
                    default                => 0,
                };
            }

            $grid[] = $cells;

            if ($row->isBold) {
                $this->boldRows[$sheetRow] = true;
            }

            if ($row->isCheck) {
                $this->checkRows[$sheetRow] = true;
            }
        }

        return $grid;
    }

    public function columnWidths(): array
    {
        // Workbook column A widths run 16-32 characters depending on the sheet;
        // 32 fits every caption in the workbook.
        $widths = ['A' => 32];

        foreach ($this->columns as $index => $column) {
            $letter = Coordinate::stringFromColumnIndex($index + 2);

            $widths[$letter] = $column->isSpacer() ? 1.5 : 13;
        }

        return $widths;
    }

    public function styles(Worksheet $sheet): array
    {
        $styles = [];

        foreach (array_keys($this->boldRows) as $row) {
            $styles[$row] = ['font' => ['bold' => true]];
        }

        return $styles;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $lastColumn = Coordinate::stringFromColumnIndex(count($this->columns) + 1);
                $lastRow = $sheet->getHighestRow();

                $numberFormat = $this->usd
                    ? '"$"#,##0;("$"#,##0);"-"'
                    : '#,##0;(#,##0);"-"';

                if ($lastRow > self::HEADER_ROWS) {
                    $sheet->getStyle('B'.(self::HEADER_ROWS + 1).":{$lastColumn}{$lastRow}")
                        ->getNumberFormat()
                        ->setFormatCode($numberFormat);

                    $sheet->getStyle('B'.(self::HEADER_ROWS + 1).":{$lastColumn}{$lastRow}")
                        ->getAlignment()
                        ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }

                $sheet->getStyle("B1:{$lastColumn}2")
                    ->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                foreach (array_keys($this->checkRows) as $row) {
                    $range = "B{$row}:{$lastColumn}{$row}";

                    $sheet->getStyle($range)->getFont()->setColor(new Color(Color::COLOR_DARKRED));
                }

                $sheet->freezePane('B'.(self::HEADER_ROWS + 1));
            },
        ];
    }

    protected function subLabel(ReportColumnSpec $column): string
    {
        $period = $column->period;

        if ($period === null) {
            return '';
        }

        if ($period->startDate->isSameDay($period->startDate->copy()->startOfYear())
            && $period->endDate->isSameDay($period->endDate->copy()->endOfYear())) {
            return (string) $period->endDate->year;
        }

        if ($period->startDate->isSameMonth($period->endDate)) {
            return $period->endDate->format("M'y");
        }

        return $period->startDate->format('M').'-'.$period->endDate->format("M'y");
    }
}
