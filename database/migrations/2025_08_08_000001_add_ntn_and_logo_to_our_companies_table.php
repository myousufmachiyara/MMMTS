<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('our_companies', function (Blueprint $table) {
            $table->string('ntn', 50)->nullable()->after('name');
            $table->string('logo')->nullable()->after('ntn'); // storage path (public disk)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('our_companies', function (Blueprint $table) {
            $table->dropColumn(['ntn', 'logo']);
        });
    }
};
