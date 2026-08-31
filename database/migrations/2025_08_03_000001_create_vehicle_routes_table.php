<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_routes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');                 // "VEHICLE ROUTE" e.g. KICT TO EPZ TO TPX
            $table->string('dimension', 50)->nullable(); // default container size for this route, e.g. "1 X 40"

            // Default rate card for this route — pulled onto a Daily Job and editable there
            $table->decimal('union_rent', 12, 2)->default(0);
            $table->decimal('day_detention', 12, 2)->default(0);
            $table->decimal('night_detention', 12, 2)->default(0);
            $table->decimal('labour_charges', 12, 2)->default(0);
            $table->decimal('maripur_charges', 12, 2)->default(0);
            $table->decimal('h_bay_charges', 12, 2)->default(0);
            $table->decimal('extra_northern', 12, 2)->default(0);

            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_routes');
    }
};
