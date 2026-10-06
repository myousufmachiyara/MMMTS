<?php

namespace App\Support;

use Carbon\Carbon;

// Generates Bill / Invoice numbers of the form  PREFIX-000572/2026.
// See config/numbering.php for the format, the yearly restart and the
// starting point carried over from the previous system.
class DocumentNumber
{
    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass  Bill::class / Invoice::class
     * @param  string  $column  'bill_no' / 'invoice_no'
     * @param  string  $kind    key in config/numbering.php ('bill' / 'invoice')
     * @param  mixed   $date    the document's own date (string/Carbon) — its year goes in the number
     */
    public static function next(string $modelClass, string $column, string $kind, $date): string
    {
        $cfg    = config("numbering.{$kind}");
        $prefix = $cfg['prefix'];
        $year   = Carbon::parse($date)->year;

        // Highest sequence already used for this prefix + year. Soft-deleted
        // records are included so a deleted document's number is never reused.
        $pattern = '/^' . preg_quote($prefix, '/') . '-(\d+)\/' . $year . '$/';
        $last = $modelClass::withTrashed()
            ->where($column, 'like', "{$prefix}-%/{$year}")
            ->pluck($column)
            ->map(fn ($no) => preg_match($pattern, (string) $no, $m) ? (int) $m[1] : 0)
            ->max() ?? 0;

        // The carried-over starting point only applies to its own year;
        // every other year starts at 1.
        $floor = $year === (int) $cfg['start_year'] ? (int) $cfg['start_number'] : 1;

        return sprintf('%s-%06d/%d', $prefix, max($last + 1, $floor), $year);
    }
}