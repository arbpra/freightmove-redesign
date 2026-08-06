<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('freight_jobs')->cascadeOnDelete();
            $table->foreignId('participant_one_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('participant_two_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['job_id', 'participant_one_id', 'participant_two_id'], 'conversations_job_participants_unique');
            $table->index('participant_one_id');
            $table->index('participant_two_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
