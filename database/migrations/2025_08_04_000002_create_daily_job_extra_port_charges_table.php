<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_job_extra_port_charges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('daily_job_id');
            $table->unsignedBigInteger('from_port_id');
            $table->unsignedBigInteger('to_port_id');
            $table->decimal('charges', 12, 2)->default(0);
            $table->timestamps();

            $table->foreign('daily_job_id')->references('id')->on('daily_jobs')->onDelete('cascade');
            $table->foreign('from_port_id')->references('id')->on('ports')->onDelete('restrict');
            $table->foreign('to_port_id')->references('id')->on('ports')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_job_extra_port_charges');
    }
};
