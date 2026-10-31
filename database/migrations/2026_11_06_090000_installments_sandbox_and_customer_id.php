<?php

use App\Support\SchemaGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Installments, sandbox driver: a ledger method for simulated card charges, a flag for a simulated saved card, and the
 * PayMongo customer id kept on the user so it is reused. Guarded, safe to re-run.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!SchemaGuard::enumHasValue('payment_records', 'method', 'card_sandbox')) {
            Schema::table('payment_records', function (Blueprint $t) {
                $t->enum('method', ['cash', 'gcash_direct', 'bank_transfer', 'stripe', 'gcash_paymongo', 'card_paymongo', 'card_sandbox'])->change();
            });
        }

        if (!Schema::hasColumn('payments', 'card_simulated')) {
            Schema::table('payments', function (Blueprint $t) {
                $t->boolean('card_simulated')->default(false);
            });
        }

        if (!Schema::hasColumn('users', 'paymongo_customer_id')) {
            Schema::table('users', function (Blueprint $t) {
                $t->string('paymongo_customer_id', 80)->nullable()->index();
            });
        }

        // Plans that already know their PayMongo customer lend it to the user (the newest one wins).
        DB::table('payments')->whereNotNull('paymongo_customer_id')->orderBy('id')->get(['user_id', 'paymongo_customer_id'])
            ->each(fn ($p) => DB::table('users')->where('id', $p->user_id)->whereNull('paymongo_customer_id')->update(['paymongo_customer_id' => $p->paymongo_customer_id]));
    }

    public function down(): void
    {
        DB::table('payment_records')->where('method', 'card_sandbox')->update(['method' => 'card_paymongo']);
        Schema::table('payment_records', function (Blueprint $t) {
            $t->enum('method', ['cash', 'gcash_direct', 'bank_transfer', 'stripe', 'gcash_paymongo', 'card_paymongo'])->change();
        });
        Schema::table('payments', function (Blueprint $t) {
            $t->dropColumn('card_simulated');
        });
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('paymongo_customer_id');
        });
    }
};
