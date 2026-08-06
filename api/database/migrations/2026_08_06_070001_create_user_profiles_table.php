<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('company_name')->nullable();
            $table->string('abn_acn', 32)->nullable();
            $table->string('business_type', 64)->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city', 128)->nullable();
            $table->string('state', 64)->nullable();
            $table->string('postal_code', 16)->nullable();
            $table->string('country', 64)->default('Australia');
            $table->text('bio')->nullable();
            $table->string('verification_status', 16)->default('unverified');
            $table->decimal('rating', 3, 2)->nullable();
            $table->unsignedInteger('completed_jobs_count')->default(0);
            $table->timestamps();

            $table->unique('user_id');
            $table->index('verification_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
