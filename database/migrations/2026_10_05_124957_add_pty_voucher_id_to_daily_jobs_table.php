<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A Party-to-Party job now posts a vendor-payable voucher (Dr cost account /
// Cr Vendor) when its Bill is created, alongside the customer-receivable
// voucher the Bill already posted. This column remembers which voucher that
// was, so deleting the Bill can reverse exactly it. Null for Direct jobs and
// for jobs billed before this change (no vendor voucher was ever posted).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_jobs', function (Blueprint $table) {
            $table->unsignedBigInteger('pty_voucher_id')->nullable()->after('pty_balance');
        });
    }

    public function down(): void
    {
        Schema::table('daily_jobs', function (Blueprint $table) {
            $table->dropColumn('pty_voucher_id');
        });
    }
};