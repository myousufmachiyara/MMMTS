<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// One vehicle on a Muqadum job. rent (amount), guarantee and kanta are entered
// PER DAY; total = (rent + guarantee + kanta) x days. Advance is the money
// received for this vehicle (not multiplied). Size comes from the vehicle's remarks.
class DailyJobMadqamLine extends Model
{
    protected $fillable = [
        'daily_job_id', 'vehicle_id', 'start_date', 'end_date', 'destination',
        'amount', 'advance', 'guarantee', 'kanta',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date'   => 'date',
        'amount'     => 'float',
        'advance'    => 'float',
        'guarantee'  => 'float',
        'kanta'      => 'float',
    ];

    public function dailyJob()
    {
        return $this->belongsTo(DailyJob::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    // end - start, never less than 1 (and 1 for old lines with no dates).
    public function getDaysAttribute(): int
    {
        if (!$this->start_date || !$this->end_date) {
            return 1;
        }

        return max(1, abs((int) $this->start_date->diffInDays($this->end_date)));
    }

    public function getSizeAttribute(): string
    {
        return trim((string) ($this->vehicle->remarks ?? ''));
    }

    public function getDayTotalAttribute(): float
    {
        return round($this->amount + $this->guarantee + $this->kanta, 2);
    }

    public function getRentTotalAttribute(): float      { return round($this->amount * $this->days, 2); }
    public function getGuaranteeTotalAttribute(): float { return round($this->guarantee * $this->days, 2); }
    public function getKantaTotalAttribute(): float     { return round($this->kanta * $this->days, 2); }

    public function getLineTotalAttribute(): float
    {
        return round($this->day_total * $this->days, 2);
    }

    public function getRemainingAttribute(): float
    {
        return round($this->line_total - $this->advance, 2);
    }
}