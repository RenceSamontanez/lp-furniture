<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('custom_design_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                  ->constrained('users')
                  ->onDelete('cascade');
            $table->string('title', 255)->nullable();
            $table->text('description')->nullable();
            $table->decimal('budget_min', 10, 2)->nullable();
            $table->decimal('budget_max', 10, 2)->nullable();
            $table->string('preferred_material', 100)->nullable();
            $table->string('preferred_finish', 100)->nullable();
            $table->string('dimensions', 150)->nullable();
            $table->string('reference_image', 255)->nullable();
            $table->text('ai_prompt')->nullable();
            $table->enum('status', [
                'pending',
                'ai_generated',
                'under_review',
                'approved',
                'rejected',
                'converted_to_order'
            ])->default('pending');
            $table->text('admin_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('custom_design_requests');
    }
};