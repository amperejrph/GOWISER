<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the VAT rate out of code and into billing_config.
 *
 * The rate was previously `protected const VAT_RATE = 0.12` duplicated across three billing
 * generation services, so changing it meant editing three files and redeploying.
 *
 * Stored as a fraction (0.1200 = 12%), not a percentage, because that is the form every
 * calculation uses - keeping the stored unit the same as the computed unit removes a whole
 * class of "is this 12 or 0.12" mistakes. The admin UI presents it as a percentage.
 *
 * The default is 0.1200 precisely so behaviour is unchanged on the day this ships:
 * VatCalculator falls back to the same 0.12 when the column is NULL or the row is missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_config', function (Blueprint $table) {
            if (!Schema::hasColumn('billing_config', 'vat_rate')) {
                $table->decimal('vat_rate', 6, 4)
                    ->nullable()
                    ->default(0.1200)
                    ->after('disconnection_fee')
                    ->comment('System VAT rate as a fraction, e.g. 0.1200 for 12%');
            }
        });

        // Backfill existing rows so the resolver never has to fall back for a configured tenant.
        DB::table('billing_config')->whereNull('vat_rate')->update(['vat_rate' => 0.1200]);
    }

    public function down(): void
    {
        Schema::table('billing_config', function (Blueprint $table) {
            if (Schema::hasColumn('billing_config', 'vat_rate')) {
                $table->dropColumn('vat_rate');
            }
        });
    }
};
