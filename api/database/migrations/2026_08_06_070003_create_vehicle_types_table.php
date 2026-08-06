<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('trailer_type', 64)->nullable();
            $table->decimal('max_weight_tons', 8, 2)->nullable();
            $table->string('dimensions', 128)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['carrier_id', 'is_active']);
            $table->index('trailer_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_types');
    }
};
