<?php

use App\Support\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M6 — link an order to the agreement it was created from.
 *
 * design_agreement_id is UNIQUE: one order per agreement, enforced by
 * the database. current_agreement_id is for a future amendment flow
 * and is not unique. needs_review/review_reason are flags a later
 * stage sets (e.g. a payment arrived for an outdated agreement).
 */
return new class extends Migration {
    private const FK_AGREEMENT = 'orders_design_agreement_id_foreign';
    private const FK_CURRENT   = 'orders_current_agreement_id_foreign';
    private const UNIQUE       = 'orders_design_agreement_id_unique';

    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'design_agreement_id')) {
                $table->unsignedBigInteger('design_agreement_id')->nullable();
            }
            if (!Schema::hasColumn('orders', 'current_agreement_id')) {
                $table->unsignedBigInteger('current_agreement_id')->nullable();
            }
            if (!Schema::hasColumn('orders', 'amount_paid')) {
                $table->decimal('amount_paid', 10, 2)->default(0);
            }
            if (!Schema::hasColumn('orders', 'expected_ready_date')) {
                $table->date('expected_ready_date')->nullable();
            }
            if (!Schema::hasColumn('orders', 'needs_review')) {
                $table->boolean('needs_review')->default(false);
            }
            if (!Schema::hasColumn('orders', 'review_reason')) {
                $table->string('review_reason', 255)->nullable();
            }
        });

        if (!SchemaGuard::indexExists('orders', self::UNIQUE)) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unique('design_agreement_id', self::UNIQUE);
            });
        }

        if (!SchemaGuard::foreignKeyExists('orders', self::FK_AGREEMENT)) {
            Schema::table('orders', function (Blueprint $table) {
                $table->foreign('design_agreement_id', self::FK_AGREEMENT)
                      ->references('id')->on('design_agreements')
                      ->restrictOnDelete();
            });
        }

        if (!SchemaGuard::foreignKeyExists('orders', self::FK_CURRENT)) {
            Schema::table('orders', function (Blueprint $table) {
                $table->foreign('current_agreement_id', self::FK_CURRENT)
                      ->references('id')->on('design_agreements')
                      ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        foreach ([self::FK_AGREEMENT, self::FK_CURRENT] as $fk) {
            if (SchemaGuard::foreignKeyExists('orders', $fk)) {
                Schema::table('orders', function (Blueprint $table) use ($fk) {
                    $table->dropForeign($fk);
                });
            }
        }

        if (SchemaGuard::indexExists('orders', self::UNIQUE)) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropUnique(self::UNIQUE);
            });
        }

        $columns = array_values(array_filter([
            'design_agreement_id', 'current_agreement_id', 'amount_paid',
            'expected_ready_date', 'needs_review', 'review_reason',
        ], fn ($c) => Schema::hasColumn('orders', $c)));

        if ($columns) {
            Schema::table('orders', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};
