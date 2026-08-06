<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('freight_jobs')->cascadeOnDelete();
            $table->foreignId('carrier_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('AUD');
            $table->date('estimated_delivery_date')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('pending');
            $table->decimal('match_score', 5, 2)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // A carrier quotes a given job once; re-quoting updates the row.
            $table->unique(['job_id', 'carrier_id']);
            $table->index('job_id');
            $table->index('carrier_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_quotes');
    }
};
