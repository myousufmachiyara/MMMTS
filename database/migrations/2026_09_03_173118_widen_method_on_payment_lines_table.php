<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Item 12: a payment can now include a "Benefit / In-Kind" line (e.g. a
     * fuel card) alongside a real cash/cheque/online-transfer line, so one
     * payment can be split between what a customer settled in kind and what
     * they paid normally — the existing multi-line Payment/PaymentLine
     * structure already supports this; it only needed a new allowed
     * `method` value.
     *
     * payment_lines.method was a native ENUM (cash/cheque/online_transfer).
     * Rather than ALTER...MODIFY an ENUM (needs doctrine/dbal for ->change()
     * on some setups, and MySQL's ENUM alteration can be finicky under
     * concurrent load), it's widened to a plain VARCHAR — app-level
     * validation already governs which values are accepted, so the DB
     * column no longer needs to duplicate that list.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite has no ALTER COLUMN for an existing CHECK constraint —
            // rebuild the column via a temporary copy instead.
            if (Schema::hasColumn('payment_lines', 'method') && !Schema::hasColumn('payment_lines', 'method_new')) {
                Schema::table('payment_lines', function (Blueprint $table) {
                    $table->string('method_new', 30)->nullable();
                });
                DB::statement('UPDATE payment_lines SET method_new = method');
                Schema::table('payment_lines', function (Blueprint $table) {
                    $table->dropColumn('method');
                });
                Schema::table('payment_lines', function (Blueprint $table) {
                    $table->renameColumn('method_new', 'method');
                });
            }
            return;
        }

        try {
            DB::statement('ALTER TABLE payment_lines MODIFY method VARCHAR(30) NOT NULL');
        } catch (\Throwable $e) {
            // Best-effort — app-level validation (PaymentController::rules())
            // still governs which method values are accepted either way.
        }
    }

    public function down(): void
    {
        // Not reversed — widening a column back to a stricter ENUM risks
        // rejecting 'benefit' rows already saved by then.
    }
};