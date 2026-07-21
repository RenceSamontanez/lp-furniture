<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('ai_design_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('design_id')
                  ->constrained('custom_design_requests')
                  ->onDelete('cascade');
            $table->string('image_path', 255);
            $table->string('ai_model_used', 100)->nullable();
            $table->text('prompt_used')->nullable();
            $table->boolean('is_selected')->default(false);
            $table->text('customer_notes')->nullable();
            $table->timestamp('generated_at')->useCurrent();
        });
    }

    public function down(): void {
        Schema::dropIfExists('ai_design_suggestions');
    }
};