<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

// Item 12 — a single reusable Excel export for any report table in the app:
// AccountsReportController and FleetReportController each already build
// their reports as plain [headers, rows] pairs (see their exportExcel()
// methods) — this class just turns that pair into a downloadable .xlsx
// with a bold header row, rather than every report needing its own
// bespoke Export class.
class GenericTableExport implements FromArray, WithHeadings, WithStyles, WithTitle, ShouldAutoSize
{
    private array $headers;
    private array $rows;
    private string $title;

    public function __construct(array $headers, array $rows, string $title = 'Report')
    {
        $this->headers = $headers;
        $this->rows    = $rows;
        // Excel sheet titles are capped at 31 chars and can't contain
        // \ / ? * [ ]
        $this->title = substr(preg_replace('/[\\\\\/\?\*\[\]]/', '-', $title), 0, 31) ?: 'Report';
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headers;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}