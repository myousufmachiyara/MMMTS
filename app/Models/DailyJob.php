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


    public function ptyVehicles()
    {
        return $this->hasMany(DailyJobPtyVehicle::class)->orderBy('id');
    }

    public function getPtyVehicleListAttribute(): string
    {
        $nos = ($this->relationLoaded('ptyVehicles') ? $this->ptyVehicles : $this->ptyVehicles()->get())
            ->pluck('vehicle_no')->filter()->implode(', ');

        return $nos !== '' ? $nos : (string) $this->pty_vehicle_no;
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
    // multiple vehicles). Since the multi-vehicle-form change, a vehicle
    // row carries only vehicle_id/container_no/delivery_challan_id — route,
    // trip plan, and every rate/charge field are shared across all of a
    // job's vehicles and live on the job header instead (see this model's
    // route_id/rent/detention_* etc. and sharedExtraPortCharges() above).
    // Every direct job — old or new — has at least one row: pre-rewrite
    // jobs were backfilled with exactly one (see the
    // 2026_09_03_000005 migration).
    public function vehicles()
    {
        return $this->hasMany(DailyJobVehicle::class)->orderBy('id');
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

    // Item 3 (round 3) — "job total amount * no of vehicles on the job =
    // total bill amount of that job". A Direct job's charges (rent, labour,
    // yard, kanta, detention, extra port) are still entered ONCE on the job
    // header, same as before — but every one of this job's REAL vehicle-rows
    // (vehicle_id set — excludes any stray row without one) is now billed as
    // if it independently made the same full trip, so the amount that
    // actually gets billed multiplies by how many vehicles are on the job.
    // A job with zero vehicle-rows (shouldn't normally happen — the form
    // requires at least one) is treated as ×1 rather than ×0, so it's never
    // silently billed as zero. Party-to-Party jobs have no vehicles() rows
    // at all (job_total already equals the single pty_sale_amount), so they
    // pass through unmultiplied.
    //
    // Prefers the already-loaded vehicles relation (every report/controller
    // that reads this already eager-loads 'vehicles...') to avoid N+1; falls
    // back to a fresh count only when it genuinely isn't loaded.


    // "Other charges" = everything except the Trip Plan portion. Trip Plan
    // no longer carries any charges of its own (item 2) — tax is applied to
    // the job's grand total instead (item 13). Rent/labour/yard/kanta/
    // detention/extra-port-charges are shared once across the whole job
    // (not per vehicle any more — see this model's vehicles() docblock), so
    // for Direct jobs this is those job-header fields added up once, THEN
    // multiplied by billableVehicleCount() (item 3, round 3 — see above).
    // Party-to-Party jobs have no trip-plan/vehicle-row concept, so the
    // amount billed to the customer (pty_sale_amount — NOT pty_cost, which
    // is what we owe the vendor) is carried entirely as "other charges" here,
    // unmultiplied (billableVehicleCount() is always 1 for these).


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

        $count = $this->relationLoaded('vehicles')
            ? $this->vehicles->filter(fn ($v) => $v->vehicle_id)->count()
            : $this->vehicles()->whereNotNull('vehicle_id')->count();

        return max($count, 1);
    }

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

    public function getOtherChargesTotalAttribute()
    {
        return round($this->other_charges_per_vehicle * $this->billableVehicleCount(), 2);
    }

    // The same figure as other_charges_total above, but for ONE vehicle —
    // i.e. before the ×vehicle-count multiplier. This is what the Bill print
    // shows as "charges per vehicle" next to the vehicle count and the total.
    // (Party-to-Party jobs have a single sale amount and are never multiplied.)
    public function getOtherChargesPerVehicleAttribute()
    {
        if ($this->job_type === 'party_to_party') {
            return round((float) $this->pty_sale_amount, 2);
        }

        return round(
            (float) $this->rent + (float) $this->labour_charges + (float) $this->yard_charges
            + (float) $this->kanta_charges + (float) $this->detention_total + (float) $this->extra_port_charges_total,
            2
        );
    }
}