<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DailyJob extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'job_no',
        'job_type',
        'date',
        'vehicle_id',
        'customer_id',
        'route_id',
        'container_no',
        'item_description',
        // Direct jobs: rent is entered PER VEHICLE (daily_job_vehicles.rent);
        // this column holds the job's TOTAL rent, for reference only.
        'rent',
        'labour_charges',
        'yard_charges',
        'kanta_charges',
        'trip_type',
        'pickup_port_id',
        'pickup_charges',
        'destination_location_id',
        'destination_charges',
        'dropoff_port_id',
        'dropoff_charges',
        'trip_plan_total',
        'per_day_first_charges',
        'per_day_next_rate',
        'per_day_extra_days',
        'per_day_total',
        'extra_port_charges_total',
        // Detention Charges (item 9's naming, renamed from "Retention" per
        // item 6) — shared across every vehicle on the job (see the
        // 2026_09_04 / 2026_09_05 migrations). Not to be confused with
        // per_day_* above, which is the OLD single-vehicle-era naming left
        // untouched for historical jobs.
        'detention_first_day_charges',
        'detention_next_day_rate',
        'detention_extra_days',
        'detention_night_rate',
        'detention_total',
        // Item 1 (round 3) — a single, optional date alongside the charge
        // fields above; shared once across the job like they are, not
        // mirrored onto vehicle-rows (see DailyJobController::persistDirect()).
        'detention_date',
        // Direct jobs: the FULL job amount (every vehicle's own rent + the
        // shared charges once per vehicle). Same figure as bill_amount.
        'job_total',
        'bill_id',
        'remarks',
        'created_by',
        'updated_by',
        // Assistant/Admin split (item 11) — 'incomplete' jobs were created
        // by an assistant with only the basic fields; an admin
        // (daily_jobs.fill_rates) later fills in the rest and flips this.
        'status',
        // ── Party-to-Party (Vendor to Customer directly) ──
        'vendor_id',
        'pty_vehicle_no',
        'pty_destination',
        'pty_size',
        'pty_cost',        // what we owe the vendor
        'pty_sale_amount', // what we bill the customer — feeds job_total
        'pty_advance',
        'pty_guarantee',
        'pty_balance',
        'pty_voucher_id', // vendor-payable voucher posted when the job's Bill was created
        'mq_advance',            // advance PER VEHICLE (× vehicle count = total advance)
        'mq_guarantee',          // guarantee PER VEHICLE (× vehicle count = total guarantee)
        'mq_advance_account_id', // cash / bank account the advance was received into
        'mq_advance_voucher_id', // receipt voucher posted for the advance

        // ── Delivery Challan (Direct jobs only — proof our vehicle delivered/
        // received the container). Snapshot fields living on the job itself
        // rather than a separate module, since a DC carries no accounting
        // weight of its own. dc_no being set marks the job as "DC issued".
        'dc_no',
        'dc_date',
        'dc_clearing_agent',
        'dc_unit',
        'dc_bl_no',
        'dc_container_no',
        'dc_quantity',
        'dc_item_description',
        'dc_truck_no',
    ];

    protected $casts = [
        'date'           => 'date',
        'dc_date'        => 'date',
        'detention_date' => 'date',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function madqamLines()
    {
        return $this->hasMany(DailyJobMadqamLine::class)->orderBy('id');
    }

    public function advanceAccount()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'mq_advance_account_id');
    }

    // Muqadum money. Rent is per vehicle (each line's amount); advance and
    // guarantee are entered ONCE per vehicle and multiplied by the number of
    // vehicles. job_total (what the Bill picks up) = total rent + total
    // guarantee; the balance still receivable from the customer = that total
    // minus the total advance already received.
    private function mqLines()
    {
        return $this->relationLoaded('madqamLines') ? $this->madqamLines : $this->madqamLines()->get();
    }

    public function getMqVehicleCountAttribute(): int
    {
        return $this->mqLines()->count();
    }

    public function getMqTotalRentAttribute(): float
    {
        return round((float) $this->mqLines()->sum('amount'), 2);
    }

    public function getMqTotalAdvanceAttribute(): float
    {
        return round((float) $this->mq_advance * $this->mq_vehicle_count, 2);
    }

    public function getMqTotalGuaranteeAttribute(): float
    {
        return round((float) $this->mq_guarantee * $this->mq_vehicle_count, 2);
    }

    public function getMqTotalAttribute(): float
    {
        return round($this->mq_total_rent + $this->mq_total_guarantee, 2);
    }

    public function getMqBalanceAttribute(): float
    {
        return round($this->mq_total - $this->mq_total_advance, 2);
    }

    // "TLR-1, TLR-2" — the vehicles on a Madqam job, for lists and the bill
    // picker. Expects madqamLines.vehicle to be loaded to avoid N+1.
    public function getMadqamVehicleListAttribute(): string
    {
        return $this->madqamLines->map(fn ($l) => $l->vehicle->name ?? null)->filter()->implode(', ');
    }

    // Item 4 — who created this job, shown on the Job Slip print.
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function customer()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'customer_id', 'id');
    }

    public function vendor()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'vendor_id', 'id');
    }

    public function route()
    {
        return $this->belongsTo(VehicleRoute::class, 'route_id', 'id');
    }

    public function pickupPort()
    {
        return $this->belongsTo(Port::class, 'pickup_port_id', 'id');
    }

    public function dropoffPort()
    {
        return $this->belongsTo(Port::class, 'dropoff_port_id', 'id');
    }

    public function destinationLocation()
    {
        return $this->belongsTo(CustomerLocation::class, 'destination_location_id', 'id');
    }

    // Legacy (pre-multi-vehicle) extra port charges — still readable for
    // jobs created before item 3's rewrite. Not used by current code paths;
    // see sharedExtraPortCharges() for the extra port charges a job created
    // today actually has.
    public function extraPortCharges()
    {
        return $this->hasMany(DailyJobExtraPortCharge::class);
    }

    // Extra Port Charges are shared across every vehicle on the job (one
    // list per job, not per vehicle-row) — see the 2026_09_04 migration.
    public function sharedExtraPortCharges()
    {
        return $this->hasMany(DailyJobSharedExtraPortCharge::class);
    }

    // One row per vehicle on this job (item 3 — a Direct job can involve
    // multiple vehicles). A vehicle row carries vehicle_id/container_no/
    // delivery_challan_id and, for Direct jobs, that vehicle's own RENT
    // (rent can differ from vehicle to vehicle). Route, trip plan and every
    // other charge (labour, yard, kanta, detention, extra ports) are shared
    // across all of a job's vehicles and live on the job header instead (see
    // this model's route_id/labour_charges/detention_* etc. and
    // sharedExtraPortCharges() above).
    // Every direct job — old or new — has at least one row: pre-rewrite
    // jobs were backfilled with exactly one (see the
    // 2026_09_03_000005 migration).
    public function vehicles()
    {
        return $this->hasMany(DailyJobVehicle::class)->orderBy('id');
    }

    // Party-to-Party counterpart of vehicles() above — one row per VENDOR
    // vehicle on the job, every field plain free text (vehicle no., route,
    // size; see the 2026_10_06_090000 migration). The job's money fields
    // (pty_cost / pty_sale_amount / ...) are per vehicle, so the job's real
    // totals are those × this relation's row count (billableVehicleCount()).
    public function ptyVehicles()
    {
        return $this->hasMany(DailyJobPtyVehicle::class)->orderBy('id');
    }

    // "TLR-820, ABC-111" — the job's vehicle numbers on one line, for lists
    // and the bill picker. Falls back to the legacy single pty_vehicle_no for
    // any job that somehow has no rows. Party-to-Party only.
    public function getPtyVehicleListAttribute(): string
    {
        $nos = ($this->relationLoaded('ptyVehicles') ? $this->ptyVehicles : $this->ptyVehicles()->get())
            ->pluck('vehicle_no')->filter()->implode(', ');

        return $nos !== '' ? $nos : (string) $this->pty_vehicle_no;
    }

    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }

    // Every DeliveryChallan whose HEADER points at this job (item 1's
    // daily_job_id column) — normally at most one per vehicle eventually,
    // but a brand-new/pending job can have exactly one (the DC that spawned
    // it) before any vehicle is assigned. See DeliveryChallan::dailyJob()
    // and the 2026_09_11_000001 migration's docblock for how this differs
    // from a vehicle-row's own delivery_challan_id link.
    public function deliveryChallans()
    {
        return $this->hasMany(DeliveryChallan::class);
    }

    // The DC (if any) that is attached to this job's header but hasn't yet
    // been assigned to one of its vehicle-rows — i.e. the "pending" DC a
    // job created via the Delivery Challan flow (item 1) starts with. Once
    // someone picks a vehicle for it, its vehicleLine appears and it stops
    // being "pending" (see DailyJobController::syncDeliveryChallanLinks()).
    // Requires deliveryChallans.vehicleLine to be loaded to avoid N+1 —
    // callers that use this should eager-load 'deliveryChallans.vehicleLine'.
    public function getPendingDeliveryChallanAttribute(): ?DeliveryChallan
    {
        return $this->deliveryChallans->first(fn ($dc) => !$dc->vehicleLine);
    }

    // True if ANY vehicle-row on this job still has no Delivery Challan
    // linked, OR (legacy) the old per-job dc_no was never issued.
    public function getHasDcAttribute(): bool
    {
        if ($this->job_type !== 'direct') {
            return false;
        }

        if (!empty($this->dc_no)) {
            return true; // legacy single-DC-per-job jobs
        }

        return $this->relationLoaded('vehicles')
            ? $this->vehicles->contains(fn ($v) => $v->delivery_challan_id)
            : $this->vehicles()->whereNotNull('delivery_challan_id')->exists();
    }

    // Party-to-Party money fields (pty_cost / pty_sale_amount / pty_advance /
    // pty_guarantee / pty_balance) are entered — and stored — PER VEHICLE; a
    // job with several vehicles totals them × billableVehicleCount(), the same
    // way a Direct job's charges do. These accessors are those totals (null
    // for Direct jobs). A one-vehicle job's totals equal its stored amounts.
    private function ptyTotal(string $field): ?float
    {
        if ($this->job_type !== 'party_to_party') {
            return null;
        }

        return round((float) $this->{$field} * $this->billableVehicleCount(), 2);
    }

    public function getPtyTotalCostAttribute(): ?float      { return $this->ptyTotal('pty_cost'); }
    public function getPtyTotalSaleAttribute(): ?float      { return $this->ptyTotal('pty_sale_amount'); }
    public function getPtyTotalAdvanceAttribute(): ?float   { return $this->ptyTotal('pty_advance'); }
    public function getPtyTotalGuaranteeAttribute(): ?float { return $this->ptyTotal('pty_guarantee'); }
    public function getPtyTotalBalanceAttribute(): ?float   { return $this->ptyTotal('pty_balance'); }

    // Profit on a Party-to-Party job = what we bill the customer minus what we
    // owe the vendor, for ALL the job's vehicles (per-vehicle profit × the
    // vehicle count). Not meaningful for Direct jobs (returns null there).
    public function getPtyProfitAttribute(): ?float
    {
        if ($this->job_type !== 'party_to_party') {
            return null;
        }

        return round($this->pty_total_sale - $this->pty_total_cost, 2);
    }

    // Item 3 (round 3) — every one of a Direct job's REAL vehicle-rows
    // (vehicle_id set — excludes any stray row without one) is billed as if
    // it independently made the same full trip, so the shared charges
    // (labour, yard, kanta, detention, extra port) are multiplied by how many
    // vehicles are on the job. Rent is the exception: it is entered per
    // vehicle and can differ, so it is summed rather than multiplied (see
    // direct_rent_total below).
    // A job with zero vehicle-rows (shouldn't normally happen — the form
    // requires at least one) is treated as ×1 rather than ×0, so it's never
    // silently billed as zero. Party-to-Party jobs work the same way but
    // count their free-text ptyVehicles() rows (pty_sale_amount is the
    // per-vehicle amount, like a Direct job's shared charges).
    //
    // Prefers the already-loaded vehicles relation (every report/controller
    // that reads this already eager-loads 'vehicles...') to avoid N+1; falls
    // back to a fresh count only when it genuinely isn't loaded.
    //
    // Public (was private) so the Bill print, container counts and reports can
    // all ask the job itself instead of each re-deriving the same number.
    public function billableVehicleCount(): int
    {
        // Party-to-Party: the vendor vehicles typed in on the job (one row
        // each, see ptyVehicles()). Floored at 1 like Direct jobs, so an old
        // job with no rows still bills as a single vehicle, never as zero.
        if ($this->job_type === 'party_to_party') {
            $count = $this->relationLoaded('ptyVehicles')
                ? $this->ptyVehicles->count()
                : $this->ptyVehicles()->count();

            return max($count, 1);
        }

        // Muqadum: the vehicles' rents, guarantee and advance are already worked
        // out into job_total — nothing here is multiplied by a vehicle count,
        // so it counts as one billing unit.
        if ($this->job_type === 'madqam') {
            return 1;
        }

        $count = $this->relationLoaded('vehicles')
            ? $this->vehicles->filter(fn ($v) => $v->vehicle_id)->count()
            : $this->vehicles()->whereNotNull('vehicle_id')->count();

        return max($count, 1);
    }

    // ── Direct jobs: Rent is entered per vehicle (daily_job_vehicles.rent) ──

    // The real vehicle rows of a Direct job (those with a vehicle picked).
    private function directLines()
    {
        $lines = $this->relationLoaded('vehicles') ? $this->vehicles : $this->vehicles()->get();

        return $lines->filter(fn ($v) => $v->vehicle_id);
    }

    // Sum of every vehicle's own rent. A job with no vehicle rows at all
    // (shouldn't happen) falls back to the job's stored rent so it never
    // silently bills as zero.
    public function getDirectRentTotalAttribute(): float
    {
        $lines = $this->directLines();

        if ($lines->isEmpty()) {
            return round((float) $this->rent, 2);
        }

        return round((float) $lines->sum(fn ($v) => (float) $v->rent), 2);
    }

    // Labour + yard + kanta + detention + extra-port charges — the shared
    // charges entered once on the job, which apply to EACH vehicle.
    public function getDirectSharedPerVehicleAttribute(): float
    {
        return round(
            (float) $this->labour_charges + (float) $this->yard_charges + (float) $this->kanta_charges
            + (float) $this->detention_total + (float) $this->extra_port_charges_total,
            2
        );
    }

    // True when every vehicle on the job has the same rent.
    public function getHasUniformRentAttribute(): bool
    {
        $rents = $this->directLines()->map(fn ($v) => round((float) $v->rent, 2))->unique();

        return $rents->count() <= 1;
    }

    // What ONE vehicle costs the customer: its own rent + the shared charges.
    public function vehicleAmount($line): float
    {
        return round((float) ($line->rent ?? 0) + $this->direct_shared_per_vehicle, 2);
    }

    // "Other charges" = everything except the Trip Plan portion. Trip Plan
    // no longer carries any charges of its own (item 2) — tax is applied to
    // the job's grand total instead (item 13).
    //   Direct:         every vehicle's own rent (summed) + the shared charges
    //                   (labour/yard/kanta/detention/extra-port) once per vehicle.
    //   Party-to-Party: the amount billed to the customer per vehicle
    //                   (pty_sale_amount — NOT pty_cost, which is what we owe
    //                   the vendor) × the number of vendor vehicles.
    //   Muqadum:        job_total, billed once.
    public function getOtherChargesTotalAttribute()
    {
        if ($this->job_type === 'direct' || $this->job_type === null) {
            return round(
                $this->direct_rent_total + $this->direct_shared_per_vehicle * $this->billableVehicleCount(),
                2
            );
        }

        return round($this->other_charges_per_vehicle * $this->billableVehicleCount(), 2);
    }

    // The same figure as other_charges_total above, but for ONE vehicle —
    // i.e. before the ×vehicle-count multiplier. This is what the Bill print
    // shows as "charges per vehicle" next to the vehicle count and the total.
    // (For a Party-to-Party job this is its per-vehicle pty_sale_amount.)
    // For a Direct job whose vehicles have different rents this is the
    // AVERAGE per vehicle — only a display figure; other_charges_total is
    // always the exact sum.
    public function getOtherChargesPerVehicleAttribute()
    {
        if ($this->job_type === 'party_to_party') {
            return round((float) $this->pty_sale_amount, 2);
        }

        // Muqadum: the whole job total (total rent + total guarantee), billed once.
        if ($this->job_type === 'madqam') {
            return round((float) $this->job_total, 2);
        }

        return round(
            $this->direct_rent_total / $this->billableVehicleCount() + $this->direct_shared_per_vehicle,
            2
        );
    }

    // Item 3 (round 3) — a clearer name than "other charges" to reach for at
    // billing call sites (BillController, FleetReportController): the
    // actual amount this job contributes to a Bill. Same figure as
    // other_charges_total above — kept as a thin alias so billing code
    // doesn't have to borrow "other charges" terminology.
    public function getBillAmountAttribute()
    {
        return $this->other_charges_total;
    }

    // Detention Charges (formerly "Per Day Charges", then "Retention
    // Charges" — item 6/9) — a single shared amount per job (item 10's Bill
    // column), multiplied the same way other_charges_total is (item 3,
    // round 3) so the Bill print's separate Detention/Other breakdown rows
    // stay consistent with each other. Not meaningful for Party-to-Party
    // jobs.
    public function getDetentionChargesTotalAttribute()
    {
        if ($this->job_type === 'party_to_party') {
            return 0;
        }

        return round((float) $this->detention_total * $this->billableVehicleCount(), 2);
    }
}