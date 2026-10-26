<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What `php artisan data:clear-test` removes and keeps, and the removal itself.
 *
 * Tables are listed children first so every DELETE runs with foreign keys ON; the few circular links are nulled
 * first. All row deletes happen in ONE transaction: if any statement fails nothing is removed. Files are only
 * deleted after the transaction committed, and only from the folders listed in UploadFolders.
 */
final class TestDataCleaner
{
    /**
     * Cleared tables, in delete order (children before parents). A value is an extra condition, null = every row.
     * Customer accounts only: admins and staff accounts stay.
     */
    public const CLEAR = [
        // custom orders and payments
        'payment_records'              => null,
        'installment_schedules'        => null,
        'stripe_webhook_events'        => null,
        'custom_order_events'          => null,
        'custom_order_call_logs'       => null,
        'design_agreements'            => null,
        'custom_quotes'                => null,
        // Other Services
        'service_refund_attempts'      => null,
        'service_refund_messages'      => null,
        'service_refund_requests'      => null,
        'service_invoice_payments'     => null,
        'service_invoice_refund_notes' => null,
        'service_invoice_lines'        => null,
        'service_invoices'             => null,
        'service_inquiries'            => null,
        // orders
        'material_usage'               => null,
        'task_assignments'             => null,
        'delivery'                     => null,
        'feedback'                     => null,
        'order_items'                  => null,
        'payments'                     => null,
        'orders'                       => null,
        // AI designs (history, gallery, favourites)
        'ai_design_suggestions'        => null,
        'custom_design_requests'       => null,
        // customers' other data
        'cart_items'                   => null,
        'notifications'                => null,
        'user_validations'             => null,
        'user_name_changes'            => null,
        'chatbot_logs'                 => null,
        'unanswered_ideas'             => null,
        // framework tables
        'password_reset_tokens'        => null,
        'sessions'                     => null,
        'jobs'                         => null,
        'job_batches'                  => null,
        'failed_jobs'                  => null,
        'cache'                        => null,
        'cache_locks'                  => null,
        // the accounts themselves (saved addresses are columns on users, so they go with the row)
        'users'                        => ['role', '=', 'customer'],
    ];

    /** Kept tables and why (shown on the dry run). */
    public const KEPT = [
        'users'                  => 'admin and staff accounts (customers are cleared)',
        'products'               => 'products',
        'product_images'         => 'product photos and variants',
        'business_settings'      => 'Contact Settings',
        'chatbot_intents'        => "Luma's approved answers",
        'design_ideas'           => 'Quick ideas',
        'extra_furniture_types'  => 'Quick ideas: extra "Something else" types',
        'agreement_policies'     => 'agreement policy texts (insert-only records)',
        'provinces'              => 'places',
        'cities_municipalities'  => 'places',
        'barangays'              => 'places',
        'employees'              => 'staff list (not customer data)',
        'inventory'              => 'materials inventory',
        'migrations'             => 'migration history',
    ];

    /** Links that point both ways between cleared tables; set to NULL before the deletes. */
    private const BREAK_LINKS = [
        ['custom_design_requests', 'selected_suggestion_id'],
        ['orders', 'design_request_id'],
        ['orders', 'design_agreement_id'],
        ['orders', 'current_agreement_id'],
        ['payments', 'design_agreement_id'],
        ['design_agreements', 'amends_agreement_id'],
        ['custom_quotes', 'amends_agreement_id'],
    ];

    /** @return array<string, int> table => rows that would be removed (only tables that exist) */
    public static function tableCounts(): array
    {
        $out = [];
        foreach (self::CLEAR as $table => $where) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $q = DB::table($table);
            if ($where) {
                $q->where(...$where);
            }
            $out[$table] = $q->count();
        }

        return $out;
    }

    /** @return array<string, int> kept table => rows kept */
    public static function keptCounts(): array
    {
        $out = [];
        foreach (self::KEPT as $table => $why) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $q = DB::table($table);
            if ($table === 'users') {
                $q->where('role', '<>', 'customer');
            }
            $out[$table] = $q->count();
        }

        return $out;
    }

    /** Tables in the database that are neither cleared nor kept by name: left alone, listed so nothing is a surprise. */
    public static function untouchedTables(): array
    {
        $all = array_map(fn ($t) => array_values((array) $t)[0], DB::select('SHOW TABLES'));

        return array_values(array_diff($all, array_keys(self::CLEAR), array_keys(self::KEPT)));
    }

    /**
     * Deletes the rows in one transaction. Returns table => rows removed.
     *
     * @return array<string, int>
     */
    public static function clearRows(): array
    {
        $removed = [];

        DB::transaction(function () use (&$removed) {
            foreach (self::BREAK_LINKS as [$table, $column]) {
                if (Schema::hasColumn($table, $column)) {
                    DB::table($table)->whereNotNull($column)->update([$column => null]);
                }
            }
            // a kept extra type remembers which (now deleted) idea it came from
            if (Schema::hasTable('extra_furniture_types') && Schema::hasColumn('extra_furniture_types', 'source_idea_id')) {
                DB::table('extra_furniture_types')->whereNotNull('source_idea_id')->update(['source_idea_id' => null]);
            }

            foreach (self::CLEAR as $table => $where) {
                if (!Schema::hasTable($table)) {
                    continue;
                }
                $q = DB::table($table);
                if ($where) {
                    $q->where(...$where);
                }
                $removed[$table] = $q->delete();
            }
        });

        return $removed;
    }

    /**
     * Starts the id counters of the cleared tables over. MySQL never lets a counter go below the highest id still
     * in the table, so users (admins kept) continues after the admins' ids and cannot clash with them.
     * ALTER TABLE commits implicitly, so this runs after the transaction.
     *
     * @return list<string> tables whose counter was reset
     */
    public static function resetCounters(): array
    {
        // ALTER TABLE would silently commit an open transaction (only ever the case inside a test).
        if (DB::getDriverName() !== 'mysql' || DB::transactionLevel() > 0) {
            return [];
        }

        $withCounter = DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->whereNotNull('AUTO_INCREMENT')
            ->pluck('TABLE_NAME')
            ->map(fn ($n) => (string) $n)
            ->all();

        $done = [];
        foreach (array_keys(self::CLEAR) as $table) {
            if (in_array($table, $withCounter, true)) {
                DB::statement('ALTER TABLE `' . str_replace('`', '', $table) . '` AUTO_INCREMENT = 1');
                $done[] = $table;
            }
        }

        return $done;
    }
}
