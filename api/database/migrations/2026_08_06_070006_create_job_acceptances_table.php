<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('freight_jobs')->cascadeOnDelete();
            $table->foreignId('quote_id')->constrained('job_quotes')->cascadeOnDelete();
            $table->foreignId('carrier_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shipper_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('accepted_at');
            $table->timestamps();

            // Exactly one accepted quote per job.
            $table->unique('job_id');
            $table->index('carrier_id');
            $table->index('shipper_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_acceptances');
    }
};
