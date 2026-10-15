<?php

use App\Support\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M5 — the immutable agreement record created when a customer accepts
 * a quote.
 *
 * No triggers. Immutability comes from: every foreign key RESTRICT
 * (deleting a user, request, quote or policy can never cascade into an
 * agreement — and a SET NULL action would itself "update" the row),
 * model guards that throw on update/delete (DesignAgreement), no
 * update or delete routes, and the stored SHA-256 of the snapshot.
 *
 * There is intentionally no updated_at column.
 *
 * Also adds the foreign key custom_quotes.amends_agreement_id → here,
 * which M3 could not add because this table didn't exist yet.
 */
return new class extends Migration {
    private const AMENDS_FK = 'custom_quotes_amends_agreement_id_foreign';

    public function up(): void
    {
        if (!Schema::hasTable('design_agreements')) {
            Schema::create('design_agreements', function (Blueprint $table) {
                $table->id();

                $table->string('agreement_number', 40)->unique();

                $table->foreignId('request_id')->constrained('custom_design_requests')->restrictOnDelete();
                $table->foreignId('quote_id')->unique()->constrained('custom_quotes')->restrictOnDelete(); // one agreement per quote
                $table->unsignedInteger('quote_version');
                $table->unsignedBigInteger('amends_agreement_id')->nullable();

                // People and time
                $table->foreignId('user_id')->constrained('users')->restrictOnDelete();       // the accepting customer
                $table->foreignId('finalized_by')->constrained('users')->restrictOnDelete();  // the admin who sent the quote
                // dateTime, not timestamp: MariaDB rejects a second NOT NULL TIMESTAMP
                // without a default, and gives the first an implicit ON UPDATE.
                $table->dateTime('sent_at');
                $table->dateTime('accepted_at');
                $table->string('accepted_at_local', 40);   // Asia/Manila, as displayed
                $table->string('ip_address', 45);
                $table->string('user_agent', 512)->nullable();

                // Contents
                $table->unsignedInteger('policy_version');
                $table->json('terms_snapshot');
                $table->char('snapshot_sha256', 64);
                $table->decimal('total_amount', 10, 2);
                $table->decimal('upfront_amount', 10, 2);

                $table->timestamp('created_at')->useCurrent();

                $table->foreign('amends_agreement_id')
                      ->references('id')->on('design_agreements')
                      ->restrictOnDelete();
                $table->foreign('policy_version')
                      ->references('version')->on('agreement_policies')
                      ->restrictOnDelete();

                $table->index('request_id');
            });
        }

        if (Schema::hasTable('custom_quotes')
            && Schema::hasColumn('custom_quotes', 'amends_agreement_id')
            && !SchemaGuard::foreignKeyExists('custom_quotes', self::AMENDS_FK)) {
            Schema::table('custom_quotes', function (Blueprint $table) {
                $table->foreign('amends_agreement_id', self::AMENDS_FK)
                      ->references('id')->on('design_agreements')
                      ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('custom_quotes') && SchemaGuard::foreignKeyExists('custom_quotes', self::AMENDS_FK)) {
            Schema::table('custom_quotes', function (Blueprint $table) {
                $table->dropForeign(self::AMENDS_FK);
            });
        }

        Schema::dropIfExists('design_agreements');
    }
};
