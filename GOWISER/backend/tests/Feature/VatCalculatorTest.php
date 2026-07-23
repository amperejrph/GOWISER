<?php

namespace Tests\Feature;

use App\Models\BillingConfig;
use App\Services\VatCalculator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers the VAT rate becoming configurable and the split becoming reconcilable.
 *
 * Uses the database because rate resolution reads billing_config and the fallback behaviour
 * (missing row, missing column, out-of-range value) is the part most likely to regress.
 */
class VatCalculatorTest extends TestCase
{
    use DatabaseTransactions;

    private function calculator(): VatCalculator
    {
        // A fresh instance per test: the real one is a singleton with a memoised rate, which
        // would otherwise leak a rate from one test into the next.
        return new VatCalculator();
    }

    private function setGlobalRate($rate): void
    {
        DB::table('billing_config')->whereNull('organization_id')->delete();
        DB::table('billing_config')->insert([
            'organization_id' => null,
            'vat_rate' => $rate,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /* ============================================================ Rate resolution */

    public function test_rate_is_read_from_billing_config(): void
    {
        $this->setGlobalRate(0.1500);

        $this->assertSame(0.15, $this->calculator()->rate());
    }

    /** With no configured rate at all, behaviour must match the old hard-coded constant. */
    public function test_rate_falls_back_to_the_historical_constant(): void
    {
        DB::table('billing_config')->update(['vat_rate' => null]);

        $this->assertSame(VatCalculator::FALLBACK_RATE, $this->calculator()->rate());
        $this->assertSame(0.12, VatCalculator::FALLBACK_RATE);
    }

    /**
     * A rate entered as "12" instead of "0.12" would bill 1200% VAT. It must be rejected in
     * favour of the fallback, not used.
     */
    public function test_out_of_range_rate_is_rejected(): void
    {
        $this->setGlobalRate(12);

        $this->assertSame(VatCalculator::FALLBACK_RATE, $this->calculator()->rate());
    }

    /** A zero rate is legitimate (a VAT-exempt operator) and must not be treated as missing. */
    public function test_zero_rate_is_honoured(): void
    {
        $this->setGlobalRate(0);

        $calc = $this->calculator();
        $this->assertSame(0.0, $calc->rate());

        $b = $calc->breakdown(1000);
        $this->assertSame(1000.00, $b['net']);
        $this->assertSame(0.00, $b['vat']);
    }

    /** A tenant-specific row wins over the global one. */
    public function test_organization_rate_overrides_the_global_rate(): void
    {
        $this->setGlobalRate(0.1200);

        DB::table('billing_config')->insert([
            'organization_id' => 4242,
            'vat_rate' => 0.0800,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $calc = $this->calculator();
        $this->assertSame(0.08, $calc->rate(4242));
        $this->assertSame(0.12, $calc->rate());
    }

    /* ================================================================ The split */

    /** The documented worked example: a ₱1,000 VAT-inclusive plan at 12%. */
    public function test_vat_inclusive_split_of_a_thousand_peso_plan(): void
    {
        $this->setGlobalRate(0.1200);

        $b = $this->calculator()->breakdown(1000.00);

        $this->assertSame(1000.00, $b['base']);
        $this->assertSame(892.86, $b['net']);
        $this->assertSame(107.14, $b['vat']);
    }

    /** Real plan prices from plan_list. */
    public function test_actual_plan_prices(): void
    {
        $this->setGlobalRate(0.1200);
        $calc = $this->calculator();

        $cases = [
            [1500.00, 1339.29, 160.71],  // PLAN-A
            [1300.00, 1160.71, 139.29],  // PLAN-B
            [1000.00, 892.86, 107.14],   // PLAN-C
            [900.00, 803.57, 96.43],     // PLAN-D
            [1800.00, 1607.14, 192.86],  // PREMIUM-PLAN
        ];

        foreach ($cases as [$gross, $net, $vat]) {
            $b = $calc->breakdown($gross);
            $this->assertSame($net, $b['net'], "net for {$gross}");
            $this->assertSame($vat, $b['vat'], "vat for {$gross}");
        }
    }

    /**
     * The property this refactor exists to guarantee.
     *
     * The previous implementation rounded net and VAT independently
     * (round(g/1.12) and round(g*0.12/1.12)), which drifts by a centavo for a meaningful
     * fraction of inputs. Deriving VAT by subtraction makes net + vat == gross exact for every
     * amount, which is what invoice/SOA reconciliation depends on.
     */
    public function test_net_plus_vat_always_equals_the_base(): void
    {
        $this->setGlobalRate(0.1200);
        $calc = $this->calculator();

        $mismatches = [];
        $oldFormulaMismatches = 0;

        for ($cents = 1; $cents <= 300000; $cents++) {
            $gross = round($cents / 100, 2);
            $b = $calc->breakdown($gross);

            if (round($b['net'] + $b['vat'], 2) !== $gross) {
                $mismatches[] = $gross;
            }

            // Demonstrate the old behaviour on the same input, so this test documents *why*
            // the change was made rather than just asserting the new behaviour.
            $oldNet = round($gross / 1.12, 2);
            $oldVat = round(($gross / 1.12) * 0.12, 2);
            if (round($oldNet + $oldVat, 2) !== $gross) {
                $oldFormulaMismatches++;
            }
        }

        $this->assertSame([], array_slice($mismatches, 0, 10), 'New formula must always reconcile.');
        $this->assertGreaterThan(
            0,
            $oldFormulaMismatches,
            'Sanity check: the previous formula really did drift, so this guarantee is not vacuous.'
        );
    }

    public function test_zero_and_negative_bases_produce_no_vat(): void
    {
        $this->setGlobalRate(0.1200);
        $calc = $this->calculator();

        foreach ([0, -1, -500.25] as $amount) {
            $b = $calc->breakdown($amount);
            $this->assertSame(0.0, $b['base']);
            $this->assertSame(0.0, $b['net']);
            $this->assertSame(0.0, $b['vat']);
        }
    }

    /* ============================================================ Snapshot behaviour */

    public function test_invoice_columns_shape(): void
    {
        $this->setGlobalRate(0.1200);

        $cols = $this->calculator()->invoiceColumns(1000.00);

        $this->assertSame([
            'vat_rate' => 0.12,
            'vat_base_amount' => 1000.00,
            'net_amount' => 892.86,
            'vat_amount' => 107.14,
        ], $cols);
    }

    /**
     * A snapshotted invoice must keep reporting the figures it was issued with, even after the
     * system rate changes. This is the whole point of storing the rate on the document.
     */
    public function test_snapshot_is_immune_to_a_later_rate_change(): void
    {
        $this->setGlobalRate(0.1200);
        $issued = $this->calculator()->invoiceColumns(1000.00);

        // The operator later changes the rate to 15%.
        $this->setGlobalRate(0.1500);

        $rebuilt = $this->calculator()->fromInvoice($issued);

        $this->assertNotNull($rebuilt);
        $this->assertSame(0.12, $rebuilt['rate']);
        $this->assertSame(892.86, $rebuilt['net']);
        $this->assertSame(107.14, $rebuilt['vat']);

        // ...whereas recomputing from the current rate would give a different answer, which is
        // exactly what snapshotting prevents.
        $this->assertNotSame(107.14, $this->calculator()->breakdown(1000.00)['vat']);
    }

    /** An invoice issued before the snapshot columns existed reports "unavailable", not a guess. */
    public function test_legacy_invoice_without_snapshot_returns_null(): void
    {
        $legacy = (object) [
            'vat_rate' => null,
            'vat_base_amount' => null,
            'net_amount' => null,
            'vat_amount' => null,
        ];

        $this->assertNull($this->calculator()->fromInvoice($legacy));
    }

    /** Re-rendering a historical document uses its own rate, not the current one. */
    public function test_breakdown_at_an_explicit_rate(): void
    {
        $b = $this->calculator()->breakdownAtRate(1120.00, 0.12);

        $this->assertSame(1000.00, $b['net']);
        $this->assertSame(120.00, $b['vat']);
    }

    /* ================================================ Behaviour preservation check */

    /**
     * The refactor must not change what customers are billed. The old code derived
     * amount_due from ($monthlyServiceFee + $vat), which is algebraically the VAT-inclusive
     * service charge; the new code uses that charge directly. This pins the equivalence.
     */
    public function test_net_plus_vat_reproduces_the_service_charge_used_for_totals(): void
    {
        $this->setGlobalRate(0.1200);
        $calc = $this->calculator();

        foreach ([1500.00, 1299.50, 987.65, 1.01, 33333.33] as $charge) {
            $b = $calc->breakdown($charge);

            $this->assertSame(
                round($charge, 2),
                round($b['net'] + $b['vat'], 2),
                "net + vat must equal the service charge for {$charge}"
            );
        }
    }

    /** The memo must not outlive a configuration change. */
    public function test_flush_picks_up_a_new_rate(): void
    {
        $this->setGlobalRate(0.1200);
        $calc = $this->calculator();
        $this->assertSame(0.12, $calc->rate());

        $this->setGlobalRate(0.1000);
        $this->assertSame(0.12, $calc->rate(), 'Still memoised until flushed.');

        $calc->flush();
        $this->assertSame(0.10, $calc->rate());
    }

    /** The BillingConfig model must actually persist the new column. */
    public function test_billing_config_model_persists_vat_rate(): void
    {
        $config = BillingConfig::create([
            'advance_generation_day' => 3,
            'vat_rate' => 0.0700,
            'created_by' => 'phpunit',
            'updated_by' => 'phpunit',
        ]);

        $this->assertSame('0.0700', (string) $config->fresh()->vat_rate);
    }
}
