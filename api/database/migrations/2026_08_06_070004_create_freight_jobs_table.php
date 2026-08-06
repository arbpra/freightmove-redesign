<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freight_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipper_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('pickup_location');
            $table->string('delivery_location');
            $table->date('pickup_date')->nullable();
            $table->date('delivery_date')->nullable();
            $table->string('load_category', 64)->nullable();
            $table->decimal('weight_tons', 10, 2)->nullable();
            $table->string('vehicle_type_required', 64)->nullable();
            $table->string('trailer_type_required', 64)->nullable();
            $table->decimal('budget_min', 12, 2)->nullable();
            $table->decimal('budget_max', 12, 2)->nullable();
            $table->string('status', 16)->default('draft');
            $table->string('visibility', 16)->default('public');
            $table->json('images_json')->nullable();
            $table->json('documents_json')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Per docs/05-database-schema.md section 3.
            $table->index('shipper_id');
            $table->index('status');
            $table->index('pickup_location');
            $table->index('delivery_location');
            $table->index('load_category');
            $table->index(['status', 'pickup_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freight_jobs');
    }
};
