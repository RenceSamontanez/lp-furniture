<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('task_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')
                  ->constrained('employees')
                  ->onDelete('cascade');
            $table->foreignId('order_id')
                  ->constrained('orders')
                  ->onDelete('cascade');
            $table->foreignId('assigned_by')
                  ->constrained('users')
                  ->onDelete('cascade');
            $table->text('task_description');
            $table->enum('priority', ['normal','high','urgent'])
                  ->default('normal');
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->enum('status', ['pending','in_progress','done'])
                  ->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('task_assignments');
    }
};