<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_jobs', function (Blueprint $table) {
            $table->renameColumn('pty_amount', 'pty_cost');
        });

        Schema::table('daily_jobs', function (Blueprint $table) {
            $table->decimal('pty_sale_amount', 12, 2)->default(0)->after('pty_cost');
        });
    }

    public function down(): void
    {
        Schema::table('daily_jobs', function (Blueprint $table) {
            $table->dropColumn('pty_sale_amount');
        });

        Schema::table('daily_jobs', function (Blueprint $table) {
            $table->renameColumn('pty_cost', 'pty_amount');
        });
    }
};