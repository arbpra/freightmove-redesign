<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carriers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('fleet_size')->default(1);
            $table->unsignedInteger('service_radius_km')->nullable();
            $table->json('preferred_regions')->nullable();
            $table->string('insurance_provider')->nullable();
            $table->string('insurance_policy_number', 128)->nullable();
            $table->year('operating_since')->nullable();
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carriers');
    }
};
