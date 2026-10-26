<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * My Account rebuild: E.164 phone, one structured delivery address per customer, the weekly password limit.
 * The old single-line users.address column is NOT touched: it stays as the read-only "Previous address" note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('phone_e164', 20)->nullable()->after('phone_number');
            $t->string('phone_country', 2)->nullable()->after('phone_e164');
            $t->timestamp('password_changed_at')->nullable();
            $t->foreignId('address_province_id')->nullable()->constrained('provinces')->nullOnDelete();
            $t->foreignId('address_city_id')->nullable()->constrained('cities_municipalities')->nullOnDelete();
            $t->foreignId('address_barangay_id')->nullable()->constrained('barangays')->nullOnDelete();
            $t->string('address_street', 120)->nullable();
            $t->string('address_postal_code', 4)->nullable();
            $t->string('address_landmark', 120)->nullable();
        });

        // Existing Philippine mobile numbers (09XXXXXXXXX) get their E.164 form; anything else is left for the customer to fix.
        DB::table('users')->whereNotNull('phone_number')->orderBy('id')->each(function ($u) {
            if (preg_match('/^(?:09|\+639|639)(\d{9})$/', preg_replace('/[\s\-]/', '', (string) $u->phone_number), $m)) {
                DB::table('users')->where('id', $u->id)->update(['phone_e164' => '+639' . $m[1], 'phone_country' => 'PH']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropForeign(['address_province_id']);
            $t->dropForeign(['address_city_id']);
            $t->dropForeign(['address_barangay_id']);
            $t->dropColumn([
                'phone_e164', 'phone_country', 'password_changed_at',
                'address_province_id', 'address_city_id', 'address_barangay_id',
                'address_street', 'address_postal_code', 'address_landmark',
            ]);
        });
    }
};
