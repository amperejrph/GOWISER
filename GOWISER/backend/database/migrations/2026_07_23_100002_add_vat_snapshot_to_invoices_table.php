<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a per-invoice VAT snapshot.
 *
 * Until now VAT existed only on statement_of_accounts, and only as a figure recomputed from a
 * hard-coded constant. An invoice carried nothing but total_amount, so its VAT split could be
 * reconstructed only by finding the matching SOA and assuming the rate had never changed.
 *
 * Snapshotting the rate and the derived amounts onto the invoice itself makes the document
 * self-contained and immutable: changing the system rate later cannot silently reinterpret what
 * an already-issued invoice charged.
 *
 * The four columns satisfy, by construction:
 *
 *     net_amount + vat_amount == vat_base_amount
 *
 * vat_base_amount is the VAT-inclusive figure the split was derived from - the recurring
 * service charge for the period (prorate + reconnection prorate), which is the same base the
 * SOA uses. It is stored explicitly rather than inferred from invoice_balance so the snapshot
 * can be audited on its own terms and stays correct if invoice_balance's meaning ever shifts.
 *
 * Deliberately NOT applied to the whole invoice total: charges like staggered installation
 * fees, service fees, rebates and discounts are added and subtracted after VAT is derived, and
 * the existing SOA logic has always treated VAT as applying to the service charge only. Widening
 * that would change what customers are billed, which is not what this change is for.
 *
 * All columns are nullable. Invoices issued before this migration keep NULL snapshots and
 * continue to render exactly as they do today - nothing recomputes history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'vat_rate')) {
                $table->decimal('vat_rate', 6, 4)
                    ->nullable()
                    ->after('pro_rate_start')
                    ->comment('VAT rate applied when this invoice was issued, as a fraction');
            }

            if (!Schema::hasColumn('invoices', 'vat_base_amount')) {
                $table->decimal('vat_base_amount', 12, 2)
                    ->nullable()
                    ->after('vat_rate')
                    ->comment('VAT-inclusive service charge the split was derived from');
            }

            if (!Schema::hasColumn('invoices', 'net_amount')) {
                $table->decimal('net_amount', 12, 2)
                    ->nullable()
                    ->after('vat_base_amount')
                    ->comment('Service charge excluding VAT');
            }

            if (!Schema::hasColumn('invoices', 'vat_amount')) {
                $table->decimal('vat_amount', 12, 2)
                    ->nullable()
                    ->after('net_amount')
                    ->comment('VAT component; net_amount + vat_amount == vat_base_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            foreach (['vat_amount', 'net_amount', 'vat_base_amount', 'vat_rate'] as $column) {
                if (Schema::hasColumn('invoices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
