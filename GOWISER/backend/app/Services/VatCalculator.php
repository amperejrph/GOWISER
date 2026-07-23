<?php

namespace App\Services;

use App\Models\BillingConfig;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The single place VAT is computed.
 *
 * Replaces `protected const VAT_RATE = 0.12` duplicated across BillingGenerationService,
 * EnhancedBillingGenerationService and EnhancedBillingGenerationServiceWithNotifications.
 *
 * The platform treats plan prices as VAT-INCLUSIVE: plan_list.price is the gross amount the
 * customer pays, and the net is extracted from it. That is what the existing generators have
 * always done ($gross / 1.12), so this class preserves the behaviour exactly - it changes only
 * where the rate comes from and guarantees the split reconciles.
 *
 *     net = round(gross / (1 + rate))
 *     vat = gross - net              <-- by subtraction, never rounded independently
 *
 * Deriving vat by subtraction is what makes `net + vat == gross` hold exactly at two decimal
 * places. Computing both independently (round(gross/1.12) and round(gross*0.12/1.12)) drifts by
 * a centavo for a large fraction of inputs, which is enough to break invoice reconciliation.
 *
 * Registered as a singleton so the billing run reads billing_config once per organization
 * rather than once per account.
 */
class VatCalculator
{
    /**
     * Used when billing_config has no usable rate.
     *
     * Identical to the constant that was previously compiled into the generators, so an
     * unconfigured system bills exactly as it did before this class existed.
     */
    public const FALLBACK_RATE = 0.12;

    /** Rate must be a fraction; anything outside this range is a data-entry error. */
    private const MIN_RATE = 0.0;
    private const MAX_RATE = 1.0;

    /** @var array<string, float> Memoised rates keyed by organization. */
    private $rateCache = [];

    /** @var bool|null Memoised column-existence check. */
    private $columnExists = null;

    /**
     * Resolved VAT rate as a fraction (0.12 = 12%).
     *
     * Resolution order: the billing_config row for this organization, then the global row
     * (organization_id IS NULL), then FALLBACK_RATE.
     *
     * A stored value outside 0..1 is rejected in favour of the fallback and logged as a
     * warning: someone entering "12" meaning 12% would otherwise produce a 1200% charge, and
     * silently billing that is far worse than ignoring the bad value.
     *
     * @param int|string|null $organizationId
     */
    public function rate($organizationId = null): float
    {
        $key = $organizationId === null ? 'global' : (string) $organizationId;

        if (array_key_exists($key, $this->rateCache)) {
            return $this->rateCache[$key];
        }

        $rate = self::FALLBACK_RATE;
        $stored = $this->storedRate($organizationId);

        if ($stored !== null) {
            $value = (float) $stored;

            if ($value >= self::MIN_RATE && $value <= self::MAX_RATE) {
                $rate = $value;
            } else {
                Log::warning('[vat] Configured vat_rate is outside the 0..1 range; using fallback', [
                    'organization_id' => $organizationId,
                    'configured_rate' => $stored,
                    'fallback_rate' => self::FALLBACK_RATE,
                ]);
            }
        }

        return $this->rateCache[$key] = $rate;
    }

    /**
     * Split a VAT-inclusive amount into its net and VAT components.
     *
     * @param float|string|null $grossAmount    The VAT-inclusive service charge.
     * @param int|string|null   $organizationId Tenant whose configured rate applies.
     *
     * @return array{rate: float, base: float, net: float, vat: float}
     */
    public function breakdown($grossAmount, $organizationId = null): array
    {
        return $this->breakdownAtRate($grossAmount, $this->rate($organizationId));
    }

    /**
     * Split using an explicit rate.
     *
     * This is the entry point for re-rendering an already-issued document: pass the rate that
     * was snapshotted on the invoice and the original figures are reproduced even if the system
     * rate has since changed.
     *
     * @param float|string|null $grossAmount
     *
     * @return array{rate: float, base: float, net: float, vat: float}
     */
    public function breakdownAtRate($grossAmount, float $rate): array
    {
        $gross = round((float) $grossAmount, 2);

        // A negative base has no meaningful VAT split (it would be a credit note, which this
        // system does not issue). Treat it as zero rather than inventing a negative tax figure.
        if ($gross <= 0) {
            return ['rate' => $rate, 'base' => 0.0, 'net' => 0.0, 'vat' => 0.0];
        }

        if ($rate < self::MIN_RATE || $rate > self::MAX_RATE) {
            $rate = self::FALLBACK_RATE;
        }

        $net = round($gross / (1 + $rate), 2);
        // Subtraction, not an independent round(), so the three figures always reconcile.
        $vat = round($gross - $net, 2);

        return ['rate' => $rate, 'base' => $gross, 'net' => $net, 'vat' => $vat];
    }

    /**
     * Column payload for persisting the snapshot on an invoice.
     *
     * @param float|string|null $grossAmount
     * @param int|string|null   $organizationId
     */
    public function invoiceColumns($grossAmount, $organizationId = null): array
    {
        $b = $this->breakdown($grossAmount, $organizationId);

        return [
            'vat_rate' => $b['rate'],
            'vat_base_amount' => $b['base'],
            'net_amount' => $b['net'],
            'vat_amount' => $b['vat'],
        ];
    }

    /**
     * Rebuild the split stored on an invoice.
     *
     * Reads the snapshot and never consults current configuration, so a historical document
     * always renders the figures it was issued with. Returns null when the invoice predates the
     * snapshot columns, which the caller should treat as "VAT breakdown unavailable" rather
     * than recomputing one that was never charged.
     *
     * @param object|array $invoice
     *
     * @return array{rate: float, base: float, net: float, vat: float}|null
     */
    public function fromInvoice($invoice): ?array
    {
        $get = static function (string $key) use ($invoice) {
            if (is_array($invoice)) {
                return $invoice[$key] ?? null;
            }

            return $invoice->{$key} ?? null;
        };

        $base = $get('vat_base_amount');
        $net = $get('net_amount');
        $vat = $get('vat_amount');
        $rate = $get('vat_rate');

        if ($base === null || $net === null || $vat === null) {
            return null;
        }

        return [
            'rate' => (float) ($rate ?? self::FALLBACK_RATE),
            'base' => (float) $base,
            'net' => (float) $net,
            'vat' => (float) $vat,
        ];
    }

    /** Clear the memo - used by tests and after the Billing Config screen saves a new rate. */
    public function flush(): void
    {
        $this->rateCache = [];
    }

    /**
     * Read vat_rate from billing_config, preferring the tenant row over the global one.
     *
     * @param int|string|null $organizationId
     *
     * @return mixed|null
     */
    private function storedRate($organizationId)
    {
        if (!$this->hasVatRateColumn()) {
            return null;
        }

        try {
            if ($organizationId !== null) {
                $tenant = BillingConfig::where('organization_id', $organizationId)
                    ->whereNotNull('vat_rate')
                    ->orderBy('id')
                    ->value('vat_rate');

                if ($tenant !== null) {
                    return $tenant;
                }
            }

            return BillingConfig::whereNull('organization_id')
                ->whereNotNull('vat_rate')
                ->orderBy('id')
                ->value('vat_rate')
                // Some installs have a single config row with organization_id populated; fall
                // back to whatever row exists rather than silently using the hard-coded rate.
                ?? BillingConfig::whereNotNull('vat_rate')->orderBy('id')->value('vat_rate');
        } catch (Throwable $e) {
            Log::error('[vat] Could not read vat_rate from billing_config; using fallback', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Guard so the calculator keeps working if the code is deployed ahead of the migration.
     */
    private function hasVatRateColumn(): bool
    {
        if ($this->columnExists === null) {
            try {
                $this->columnExists = Schema::hasColumn('billing_config', 'vat_rate');
            } catch (Throwable $e) {
                $this->columnExists = false;
            }
        }

        return $this->columnExists;
    }
}
