<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_tracking', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('freight_jobs')->cascadeOnDelete();
            $table->string('current_status', 32);
            $table->string('last_location')->nullable();
            $table->timestamp('eta')->nullable();
            $table->timestamps();

            $table->unique('job_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_tracking');
    }
};
