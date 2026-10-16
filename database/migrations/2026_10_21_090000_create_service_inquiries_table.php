<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 21: "Other Services" inquiry tickets. A short ticket (concern plus contact
 * details) for work the owner prices by phone, such as labor only with the customer's own
 * wood. It never creates an order. Guests may submit, so user_id is nullable.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('service_inquiries')) {
            return;
        }

        Schema::create('service_inquiries', function (Blueprint $table) {
            $table->id();

            // Kept (as NULL) if the customer's account is later removed.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference_number', 20)->unique();      // SI-YYYY-NNNN

            $table->string('service_type', 30);                    // labor_only_own_wood, repair, other
            $table->string('furniture_type', 60)->nullable();
            $table->text('concern');

            $table->string('name', 120);
            $table->string('phone', 30);
            $table->string('email', 150)->nullable();

            // Calls are made Monday to Friday, 8:00 AM to 5:00 PM (Asia/Manila).
            $table->string('preferred_call_window', 20)->nullable(); // morning_8_12, afternoon_12_5, anytime
            $table->date('preferred_call_date')->nullable();

            $table->string('status', 20)->default('new');          // new, contacting, unreachable, called, closed
            $table->text('admin_notes')->nullable();                // running, timestamped log
            $table->unsignedSmallInteger('attempts')->default(0);
            // dateTime, not timestamp: avoids MariaDB's implicit ON UPDATE CURRENT_TIMESTAMP.
            $table->dateTime('last_contacted_at')->nullable();
            $table->string('closed_reason', 1000)->nullable();

            // Set when staff entered the ticket for a walk-in or phone customer.
            $table->foreignId('created_by_admin_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_inquiries');
    }
};
