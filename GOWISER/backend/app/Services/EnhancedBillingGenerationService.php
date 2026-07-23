<?php

namespace App\Services;

use App\Models\BillingAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\StatementOfAccount;
use App\Models\AppPlan;
use App\Models\Discount;
use App\Models\StaggeredInstallation;
use App\Models\AdvancedPayment;
use App\Models\MassRebate;
use App\Models\RebateUsage;
use App\Models\Barangay;
use App\Models\BillingConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Enhanced Billing Generation Service
 * 
 * CRITICAL ORDER OF OPERATIONS:
 * ================================
 * 1. Generate SOA FIRST (before invoice)
 * 2. Generate Invoice SECOND (after SOA)
 * 
 * Why this order matters:
 * - SOA needs to show the previous balance from the last billing period
 * - Invoice generation updates the account_balance to the new ending balance
 * - If invoice is generated first, SOA will incorrectly use the new ending balance
 *   instead of the actual previous balance
 * 
 * Example Flow:
 * =============
 * Previous Period:
 *   - Total Amount Due: ₱6,694.00 (this is the ending balance)
 * 
 * Current Period (CORRECT ORDER):
 *   Step 1 - Generate SOA:
 *     - getPreviousBalance() returns ₱6,694.00 (from last SOA)
 *     - Balance from Previous Bill: ₱6,694.00 ✓
 *     - New charges: ₱1,299.00
 *     - Total Amount Due: ₱7,993.00 (new ending balance)
 *   
 *   Step 2 - Generate Invoice:
 *     - Updates account_balance to ₱7,993.00
 *     - Invoice created with correct amounts
 * 
 * Next Period:
 *   - getPreviousBalance() will return ₱7,993.00 (from current SOA's total_amount_due)
 *   - Cycle continues correctly
 * 
 * All generation methods follow this order:
 * - generateAllBillingsForToday()
 * - generateBillingsForSpecificDay()
 * - forceGenerateAll() (in BillingGenerationController)
 */
class EnhancedBillingGenerationService
{
    protected VatCalculator $vatCalculator;

    protected const DAYS_IN_MONTH = 30;
    protected const DAYS_UNTIL_DUE = 7;
    protected const DAYS_UNTIL_DC_NOTICE = 4;
    protected const END_OF_MONTH_BILLING = 0;

    public function __construct(VatCalculator $vatCalculator)
    {
        $this->vatCalculator = $vatCalculator;
    }

    public function generateSOAForBillingDay(int $billingDay, Carbon $generationDate, int $userId): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => [],
            'statements' => []
        ];

        try {
            $accounts = $this->getActiveAccountsForBillingDay($billingDay, $generationDate);

            foreach ($accounts as $account) {
                try {
                    $statement = $this->createEnhancedStatement($account, $generationDate, $userId);
                    $results['statements'][] = $statement;
                    $results['success']++;
                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'account_id' => $account->id,
                        'account_no' => $account->account_no,
                        'error' => $e->getMessage()
                    ];
                    Log::error("Failed to generate SOA for account {$account->account_no}: " . $e->getMessage());
                }
            }

            return $results;
        } catch (\Exception $e) {
            Log::error("Error in generateSOAForBillingDay: " . $e->getMessage());
            throw $e;
        }
    }

    public function generateInvoicesForBillingDay(int $billingDay, Carbon $generationDate, int $userId): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => [],
            'invoices' => []
        ];

        try {
            $accounts = $this->getActiveAccountsForBillingDay($billingDay, $generationDate);

            foreach ($accounts as $account) {
                try {
                    $invoice = $this->createEnhancedInvoice($account, $generationDate, $userId);
                    $results['invoices'][] = $invoice;
                    $results['success']++;
                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'account_id' => $account->id,
                        'account_no' => $account->account_no,
                        'error' => $e->getMessage()
                    ];
                    Log::error("Failed to generate invoice for account {$account->account_no}: " . $e->getMessage());
                }
            }

            return $results;
        } catch (\Exception $e) {
            Log::error("Error in generateInvoicesForBillingDay: " . $e->getMessage());
            throw $e;
        }
    }

    protected function getActiveAccountsForBillingDay(int $billingDay, Carbon $generationDate)
    {
        $targetDay = $this->adjustBillingDayForMonth($billingDay, $generationDate);

        $query = BillingAccount::with([
            'customer',
            'technicalDetails',
            'plan'
        ])
            ->where('billing_status_id', 1)
            ->whereNotNull('date_installed')
            ->whereNotNull('account_no');

        if ($billingDay === self::END_OF_MONTH_BILLING) {
            $query->where('billing_day', self::END_OF_MONTH_BILLING);
        } else {
            $query->where('billing_day', $targetDay);
        }

        $accounts = $query->get();

        Log::info('Loaded accounts with complete data', [
            'billing_day' => $billingDay,
            'generation_date' => $generationDate->format('Y-m-d'),
            'accounts_count' => $accounts->count(),
            'sample_account' => $accounts->first() ? [
                'account_no' => $accounts->first()->account_no,
                'has_customer' => $accounts->first()->customer ? true : false,
                'has_technical_details' => $accounts->first()->technicalDetails->count() > 0,
                'customer_name' => $accounts->first()->customer?->full_name ?? 'N/A',
                'desired_plan' => $accounts->first()->customer?->desired_plan ?? 'N/A'
            ] : null
        ]);

        return $accounts;
    }

    protected function getAdvanceGenerationDay(): int
    {
        $billingConfig = BillingConfig::first();
        
        if (!$billingConfig || $billingConfig->advance_generation_day === null) {
            Log::info('No advance_generation_day configured, using default 0');
            return 0;
        }
        
        return $billingConfig->advance_generation_day;
    }

    protected function calculateTargetBillingDays(Carbon $generationDate): array
    {
        $advanceGenerationDay = $this->getAdvanceGenerationDay();
        $currentDay = $generationDate->day;
        $targetBillingDay = $currentDay + $advanceGenerationDay;
        
        $billingDays = [];
        
        if ($generationDate->isLastOfMonth()) {
            $billingDays[] = self::END_OF_MONTH_BILLING;
            
            $lastDayOfMonth = $generationDate->day;
            $targetDay = $lastDayOfMonth + $advanceGenerationDay;
            
            if ($targetDay <= 31) {
                $billingDays[] = $targetDay;
            }
        } else {
            if ($targetBillingDay <= 31) {
                $billingDays[] = $targetBillingDay;
            }
            
            $lastDayOfMonth = $generationDate->copy()->endOfMonth()->day;
            if ($targetBillingDay > $lastDayOfMonth) {
                $billingDays[] = self::END_OF_MONTH_BILLING;
            }
        }
        
        Log::info('Calculated target billing days', [
            'generation_date' => $generationDate->format('Y-m-d'),
            'current_day' => $currentDay,
            'advance_generation_day' => $advanceGenerationDay,
            'target_billing_day' => $targetBillingDay,
            'billing_days_to_process' => $billingDays
        ]);
        
        return $billingDays;
    }

    protected function adjustBillingDayForMonth(int $billingDay, Carbon $date): int
    {
        if ($billingDay === self::END_OF_MONTH_BILLING) {
            return self::END_OF_MONTH_BILLING;
        }

        if ($date->format('M') === 'Feb') {
            if ($billingDay === 29) {
                return 1;
            } elseif ($billingDay === 30) {
                return 2;
            } elseif ($billingDay === 31) {
                return 3;
            }
        }
        return $billingDay;
    }

    public function createEnhancedStatement(BillingAccount $account, Carbon $statementDate, int $userId): StatementOfAccount
    {
        $statementDate = $statementDate->copy()->setTimezone('Asia/Manila')->startOfDay();
        DB::beginTransaction();

        try {
            $customer = $account->customer;
            if (!$customer) {
                throw new \Exception("Customer not found for account {$account->account_no}");
            }

            $desiredPlan = $customer->desired_plan;
            if (!$desiredPlan) {
                throw new \Exception("No desired_plan found for customer {$customer->full_name}");
            }

            $planName = $this->extractPlanName($desiredPlan);
            
            $plan = AppPlan::where('plan_name', $planName)->first();
                
            if (!$plan) {
                $allPlans = AppPlan::select('id', 'plan_name', 'price')->get();
                throw new \Exception("Plan '{$planName}' not found in plan_list table (extracted from '{$desiredPlan}'). Available plans: " . $allPlans->pluck('plan_name')->implode(', '));
            }

            if (!$plan->price || $plan->price <= 0) {
                throw new \Exception("Plan '{$planName}' has invalid price: " . ($plan->price ?? 'NULL'));
            }

            $adjustedDate = $this->calculateAdjustedBillingDate($account, $statementDate);
            $dueDate = $adjustedDate->copy()->addDays(self::DAYS_UNTIL_DUE);

            $prorateAmount = $this->calculateProrateAmount($account, $plan->price, $adjustedDate);
            // Plan prices are VAT-inclusive: the service charge is split into net + VAT rather
            // than having VAT added on top. Rate comes from billing_config.
            $vatBreakdown = $this->vatCalculator->breakdown($prorateAmount, $account->organization_id);
            $monthlyServiceFee = $vatBreakdown['net'];
            $vat = $vatBreakdown['vat'];

            $invoiceId = $this->generateInvoiceId($statementDate);
            
            $charges = $this->calculateChargesAndDeductions(
                $account, 
                $statementDate, 
                $userId, 
                $invoiceId,
                $plan->price,
                false,
                false
            );
            
            $othersAndBasicCharges = 0;

            // net + VAT is by definition the VAT-inclusive service charge; using the charge
            // directly is arithmetically identical and independent of display rounding.
            $amountDue = $prorateAmount + $charges['staggered_install_fees'] + $charges['service_fees'] - $charges['rebates'] - $charges['discounts'] - $charges['advanced_payments'];
            
            $previousBalance = $this->getPreviousBalance($account, $statementDate);
            $paymentReceived = $charges['payment_received_previous'];
            $remainingBalance = $previousBalance - $paymentReceived;
            $totalAmountDue = $remainingBalance + $amountDue;

            $statement = StatementOfAccount::create([
                'account_no' => $account->account_no,
                'statement_date' => $statementDate,
                'balance_from_previous_bill' => round($previousBalance, 2),
                'payment_received_previous' => round($paymentReceived, 2),
                'remaining_balance_previous' => round($remainingBalance, 2),
                'monthly_service_fee' => round($monthlyServiceFee, 2),
                'others_and_basic_charges' => round($othersAndBasicCharges, 2),
                'service_charge' => round($charges['service_fees'], 2),
                'rebate' => round($charges['rebates'], 2),
                'discounts' => round($charges['discounts'], 2),
                'staggered' => round($charges['staggered_install_fees'], 2),
                'vat' => round($vat, 2),
                'due_date' => $dueDate,
                'amount_due' => round($amountDue, 2),
                'total_amount_due' => round($totalAmountDue, 2),
                'print_link' => null,
                'created_by' => (string) $userId,
                'updated_by' => (string) $userId
            ]);

            DB::commit();
            return $statement;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    public function createEnhancedInvoice(BillingAccount $account, Carbon $invoiceDate, int $userId): Invoice
    {
        $invoiceDate = $invoiceDate->copy()->setTimezone('Asia/Manila')->startOfDay();
        DB::beginTransaction();

        try {
            $customer = $account->customer;
            if (!$customer) {
                throw new \Exception("Customer not found for account {$account->account_no}");
            }

            $desiredPlan = $customer->desired_plan;
            if (!$desiredPlan) {
                throw new \Exception("No desired_plan found for customer {$customer->full_name}");
            }

            $planName = $this->extractPlanName($desiredPlan);
            
            $plan = AppPlan::where('plan_name', $planName)->first();
            if (!$plan) {
                throw new \Exception("Plan '{$planName}' not found in plan_list table (extracted from '{$desiredPlan}')");
            }

            if (!$plan->price || $plan->price <= 0) {
                throw new \Exception("Plan '{$planName}' has invalid price: " . ($plan->price ?? 'NULL'));
            }

            $adjustedDate = $this->calculateAdjustedBillingDate($account, $invoiceDate);
            $dueDate = $adjustedDate->copy()->addDays(self::DAYS_UNTIL_DUE);

            $invoiceId = $this->generateInvoiceId($invoiceDate);
            
            $prorateAmount = $this->calculateProrateAmount($account, $plan->price, $adjustedDate);
            $charges = $this->calculateChargesAndDeductions(
                $account, 
                $invoiceDate, 
                $userId, 
                $invoiceId,
                $plan->price,
                true,
                true
            );
            
            $othersBasicCharges = 0;

            $totalAmount = $prorateAmount + $charges['staggered_install_fees'] + $charges['service_fees'] - $charges['rebates'] - $charges['discounts'] - $charges['advanced_payments'];
            
            if ($account->account_balance < 0) {
                $totalAmount += $account->account_balance;
            }

            $invoice = Invoice::create([
                'account_no' => $account->account_no,
                'invoice_date' => $invoiceDate,
                'invoice_balance' => round($prorateAmount, 2),
                'others_and_basic_charges' => round($othersBasicCharges, 2),
                'service_charge' => round($charges['service_fees'], 2),
                'rebate' => round($charges['rebates'], 2),
                'discounts' => round($charges['discounts'], 2),
                'staggered' => round($charges['staggered_install_fees'], 2),
                'total_amount' => round($totalAmount, 2),
                'received_payment' => 0.00,
                'due_date' => $dueDate,
                'status' => $totalAmount <= 0 ? 'Paid' : 'Unpaid',
                'payment_portal_log_ref' => null,
                'transaction_id' => null,
                'created_by' => (string) $userId,
                'updated_by' => (string) $userId
            ]);

            $appliedDiscounts = $charges['discounts'];
            
            $newBalance = $account->account_balance > 0 
                ? $totalAmount + $account->account_balance 
                : $totalAmount;

            $account->update([
                'account_balance' => round($newBalance, 2),
                'balance_update_date' => $invoiceDate
            ]);
            
            Log::info('Invoice created with discount applied to balance', [
                'account_no' => $account->account_no,
                'invoice_balance' => $prorateAmount,
                'others_basic_charges' => $othersBasicCharges,
                'total_amount' => $totalAmount,
                'discounts_applied' => $appliedDiscounts,
                'previous_balance' => $account->account_balance,
                'new_balance' => $newBalance,
                'note' => 'Discount already included in others_basic_charges calculation'
            ]);
            
            $this->markDiscountsAsUsed($account, $userId, $invoiceId);
            $this->markRebatesAsUsed($account, $userId, $invoiceId);

            // Track invoice association for staggered installations (without applying payment)
            $this->trackStaggeredInvoiceAssociation($account->account_no, $invoice->id);

            DB::commit();
            return $invoice;

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function generateInvoiceId(Carbon $date): string
    {
        $year = $date->format('y');
        $month = $date->format('m');
        $day = $date->format('d');
        $hour = $date->format('H');
        $randomId = '0000';
        
        return $year . $month . $day . $hour . $randomId;
    }

    protected function calculateAdjustedBillingDate(BillingAccount $account, Carbon $baseDate): Carbon
    {
        if ($account->billing_day === self::END_OF_MONTH_BILLING) {
            return $baseDate->copy()->endOfMonth();
        }
        
        // Normalize time to start of day to avoid time propagation issues
        $baseDate = $baseDate->copy()->startOfDay();
        $adjustedDate = $baseDate->copy()->day($account->billing_day);
        
        // If the calculated billing day is in the past relative to the generation date,
        // it means we are generating the bill in advance for the next month.
        if ($adjustedDate->format('Y-m-d') < $baseDate->format('Y-m-d')) {
            $adjustedDate->addMonth();
        }
        
        return $adjustedDate;
    }

    protected function calculateProrateAmount(BillingAccount $account, float $monthlyFee, Carbon $currentDate): float
    {
        if ($account->balance_update_date) {
            return $monthlyFee;
        }

        if (!$account->date_installed) {
            return $monthlyFee;
        }

        $dateInstalled = Carbon::parse($account->date_installed);
        $daysToCalculate = $this->getDaysBetweenDatesIncludingDueDate($dateInstalled, $currentDate);
        $dailyRate = $monthlyFee / self::DAYS_IN_MONTH;
        
        return round($dailyRate * $daysToCalculate, 2);
    }

    protected function calculateChargesAndDeductions(
        BillingAccount $account, 
        Carbon $date, 
        int $userId, 
        string $invoiceId,
        float $monthlyFee,
        bool $updateDiscountStatus = false,
        bool $includeDiscounts = true
    ): array {
        $staggeredInstallFees = $this->calculateStaggeredInstallFees($account, $userId, $invoiceId, $updateDiscountStatus);
        $discounts = $includeDiscounts ? $this->calculateDiscounts($account, $userId, $invoiceId, $updateDiscountStatus) : 0;
        $advancedPayments = $this->calculateAdvancedPayments($account, $date, $userId, $invoiceId);
        $rebates = $this->calculateRebates($account, $date, $monthlyFee);
        $serviceFees = $this->calculateServiceFees($account, $date, $userId);
        $paymentReceived = $this->calculatePaymentReceived($account, $date);

        return [
            'staggered_install_fees' => $staggeredInstallFees,
            'discounts' => $discounts,
            'advanced_payments' => $advancedPayments,
            'rebates' => $rebates,
            'service_fees' => $serviceFees,
            'total_deductions' => $advancedPayments + $discounts + $rebates,
            'payment_received_previous' => $paymentReceived
        ];
    }

    protected function calculateStaggeredInstallFees(BillingAccount $account, int $userId, string $invoiceId, bool $updateStatus = false): float
    {
        $total = 0;

        $staggeredInstallations = StaggeredInstallation::where('account_no', $account->account_no)
            ->where('status', 'Active')
            ->where('months_to_pay', '>', 0)
            ->get();

        Log::info('Processing staggered installations', [
            'account_no' => $account->account_no,
            'staggered_installations_count' => $staggeredInstallations->count()
        ]);

        foreach ($staggeredInstallations as $installation) {
            $total += $installation->monthly_payment;
        }

        Log::info('Staggered installations total', [
            'account_no' => $account->account_no,
            'total_staggered_fees' => $total
        ]);

        return round($total, 2);
    }

    protected function calculateDiscounts(BillingAccount $account, int $userId, string $invoiceId, bool $updateStatus = false): float
    {
        $total = 0;

        $discounts = Discount::where('account_no', $account->account_no)
            ->whereIn('status', ['Unused', 'Permanent', 'Monthly'])
            ->get();

        Log::info('Calculating discounts', [
            'account_no' => $account->account_no,
            'discounts_count' => $discounts->count()
        ]);

        foreach ($discounts as $discount) {
            if ($discount->status === 'Unused') {
                $total += $discount->discount_amount;
                
                Log::info('Found Unused discount', [
                    'discount_id' => $discount->id,
                    'account_no' => $account->account_no,
                    'discount_amount' => $discount->discount_amount
                ]);
            } elseif ($discount->status === 'Permanent') {
                $total += $discount->discount_amount;
                
                Log::info('Found Permanent discount', [
                    'discount_id' => $discount->id,
                    'account_no' => $account->account_no,
                    'discount_amount' => $discount->discount_amount
                ]);
            } elseif ($discount->status === 'Monthly' && $discount->remaining > 0) {
                $total += $discount->discount_amount;
                
                Log::info('Found Monthly discount', [
                    'discount_id' => $discount->id,
                    'account_no' => $account->account_no,
                    'discount_amount' => $discount->discount_amount,
                    'remaining' => $discount->remaining
                ]);
            }
        }

        Log::info('Total discounts calculated', [
            'account_no' => $account->account_no,
            'total_discount' => $total
        ]);

        return round($total, 2);
    }

    protected function calculateAdvancedPayments(
        BillingAccount $account, 
        Carbon $date, 
        int $userId, 
        string $invoiceId
    ): float {
        $total = 0;
        $currentMonth = $date->format('F');

        $advancedPayments = AdvancedPayment::where('account_no', $account->account_no)
            ->where('payment_month', $currentMonth)
            ->where('status', 'Unused')
            ->get();

        Log::info('Calculating advanced payments', [
            'account_no' => $account->account_no,
            'current_month' => $currentMonth,
            'advanced_payments_count' => $advancedPayments->count()
        ]);

        foreach ($advancedPayments as $payment) {
            $total += $payment->payment_amount;
            $payment->update([
                'status' => 'Used',
                'invoice_used_id' => $invoiceId,
                'updated_by' => $userId
            ]);
        }

        return round($total, 2);
    }

    protected function calculateRebates(BillingAccount $account, Carbon $date, float $monthlyFee): float
    {
        $total = 0;
        $currentMonth = $date->format('F');
        
        $customer = $account->customer;
        if (!$customer) {
            Log::info('No customer found for rebate calculation', ['account_no' => $account->account_no]);
            return 0;
        }

        $technicalDetails = $account->technicalDetails->first();
        if (!$technicalDetails) {
            Log::info('No technical details found for rebate calculation', ['account_no' => $account->account_no]);
            return 0;
        }

        $rebates = MassRebate::where('status', 'Unused')
            ->where('month', $currentMonth)
            ->get();

        Log::info('Calculating rebates - only Unused status', [
            'account_no' => $account->account_no,
            'current_month' => $currentMonth,
            'total_unused_rebates_available' => $rebates->count(),
            'customer_lcpnap' => $technicalDetails->lcpnap,
            'customer_lcp' => $technicalDetails->lcp,
            'customer_location' => $customer->location,
            'customer_barangay' => $customer->barangay,
            'note' => 'Only rebates with status=Unused will be processed (not Pending or Used)'
        ]);

        $daysInCurrentMonth = $date->daysInMonth;
        $dailyRate = $monthlyFee / $daysInCurrentMonth;

        foreach ($rebates as $rebate) {
            $matchFound = false;

            if ($rebate->rebate_type === 'lcpnap') {
                if ($technicalDetails->lcpnap && $technicalDetails->lcpnap === $rebate->selected_rebate) {
                    $matchFound = true;
                }
            } elseif ($rebate->rebate_type === 'lcp') {
                if ($technicalDetails->lcp && $technicalDetails->lcp === $rebate->selected_rebate) {
                    $matchFound = true;
                }
            } elseif ($rebate->rebate_type === 'location') {
                if (($customer->location && $customer->location === $rebate->selected_rebate) ||
                    ($customer->barangay && $customer->barangay === $rebate->selected_rebate)) {
                    $matchFound = true;
                }
            }

            if ($matchFound) {
                $rebateUsage = \App\Models\RebateUsage::where('rebates_id', $rebate->id)
                    ->where('account_no', $account->account_no)
                    ->where('status', 'Unused')
                    ->first();

                if ($rebateUsage) {
                    $rebateDays = $rebate->number_of_dates ?? 0;
                    $rebateValue = $dailyRate * $rebateDays;
                    $total += $rebateValue;

                    Log::info('Rebate matched and applied - status is Unused', [
                        'rebate_id' => $rebate->id,
                        'rebate_usage_id' => $rebateUsage->id,
                        'account_no' => $account->account_no,
                        'rebate_type' => $rebate->rebate_type,
                        'selected_rebate' => $rebate->selected_rebate,
                        'rebate_status' => $rebate->status,
                        'rebate_days' => $rebateDays,
                        'daily_rate' => $dailyRate,
                        'rebate_value' => $rebateValue
                    ]);
                } else {
                    Log::info('Rebate matched but no unused rebate_usage found', [
                        'rebate_id' => $rebate->id,
                        'account_no' => $account->account_no
                    ]);
                }
            }
        }

        Log::info('Total rebates calculated', [
            'account_no' => $account->account_no,
            'total_rebate_amount' => $total
        ]);

        return round($total, 2);
    }

    protected function calculateServiceFees(BillingAccount $account, Carbon $date, int $userId): float
    {
        $total = 0;

        $serviceFees = DB::table('service_charge_logs')
            ->where('account_no', $account->account_no)
            ->where('status', 'Unused')
            ->get();

        foreach ($serviceFees as $fee) {
            $total += $fee->service_charge;
            
            DB::table('service_charge_logs')
                ->where('id', $fee->id)
                ->update([
                    'status' => 'Used',
                    'date_used' => now(),
                    'updated_at' => now()
                ]);
        }

        return round($total, 2);
    }

    protected function calculatePaymentReceived(BillingAccount $account, Carbon $date): float
    {
        // Get the most recent SOA to check for payment history
        $latestSOA = StatementOfAccount::where('account_no', $account->account_no)
            ->orderBy('statement_date', 'desc')
            ->orderBy('id', 'desc')
            ->first();
        
        if ($latestSOA) {
            // Calculate payment as the difference between previous total and remaining balance
            $payment = floatval($latestSOA->balance_from_previous_bill) - floatval($latestSOA->remaining_balance_previous);
            
            Log::info('Calculating payment received from latest SOA', [
                'account_no' => $account->account_no,
                'latest_soa_id' => $latestSOA->id,
                'balance_from_previous' => $latestSOA->balance_from_previous_bill,
                'remaining_balance' => $latestSOA->remaining_balance_previous,
                'payment_received' => $payment
            ]);
            
            return $payment;
        }
        
        // Fallback to transaction-based calculation if no SOA exists
        $lastMonth = $date->copy()->subMonth();
        
        $transactions = DB::table('transactions')
            ->where('account_no', $account->account_no)
            ->where('status', 'Done')
            ->whereNotIn('transaction_type', ['Security Deposit', 'Installation Fee'])
            ->whereMonth('payment_date', $lastMonth->month)
            ->whereYear('payment_date', $lastMonth->year)
            ->sum('received_payment');

        Log::info('No previous SOA found, calculating from transactions', [
            'account_no' => $account->account_no,
            'last_month' => $lastMonth->format('F Y'),
            'transactions_total' => $transactions
        ]);

        return floatval($transactions);
    }

    protected function getDaysBetweenDatesIncludingDueDate(Carbon $startDate, Carbon $endDate): int
    {
        $endDateWithBuffer = $endDate->copy()->addDays(self::DAYS_UNTIL_DUE);
        return $startDate->diffInDays($endDateWithBuffer) + 1;
    }

    public function generateAllBillingsForToday(int $userId): array
    {
        $today = Carbon::now();
        $targetBillingDays = $this->calculateTargetBillingDays($today);
        $advanceGenerationDay = $this->getAdvanceGenerationDay();

        $results = [
            'date' => $today->format('Y-m-d'),
            'advance_generation_day' => $advanceGenerationDay,
            'billing_days_processed' => [],
            'invoices' => ['success' => 0, 'failed' => 0, 'errors' => []],
            'statements' => ['success' => 0, 'failed' => 0, 'errors' => []]
        ];

        foreach ($targetBillingDays as $billingDay) {
            $billingDayLabel = $billingDay === self::END_OF_MONTH_BILLING ? 'End of Month (0)' : "Day {$billingDay}";
            
            Log::info("Processing billing day: {$billingDayLabel}");
            
            $soaResults = $this->generateSOAForBillingDay($billingDay, $today, $userId);
            $invoiceResults = $this->generateInvoicesForBillingDay($billingDay, $today, $userId);
            
            $results['billing_days_processed'][] = $billingDayLabel;
            $results['invoices']['success'] += $invoiceResults['success'];
            $results['invoices']['failed'] += $invoiceResults['failed'];
            $results['invoices']['errors'] = array_merge($results['invoices']['errors'], $invoiceResults['errors']);
            
            $results['statements']['success'] += $soaResults['success'];
            $results['statements']['failed'] += $soaResults['failed'];
            $results['statements']['errors'] = array_merge($results['statements']['errors'], $soaResults['errors']);
        }

        return $results;
    }

    public function generateBillingsForSpecificDay(int $billingDay, int $userId): array
    {
        $today = Carbon::now();

        $soaResults = $this->generateSOAForBillingDay($billingDay, $today, $userId);
        $invoiceResults = $this->generateInvoicesForBillingDay($billingDay, $today, $userId);

        return [
            'date' => $today->format('Y-m-d'),
            'billing_day' => $billingDay === self::END_OF_MONTH_BILLING ? 'End of Month (0)' : $billingDay,
            'invoices' => $invoiceResults,
            'statements' => $soaResults
        ];
    }

    protected function extractPlanName(string $desiredPlan): string
    {
        // First handle " - " separator
        if (strpos($desiredPlan, ' - ') !== false) {
            $parts = explode(' - ', $desiredPlan);
            $desiredPlan = trim($parts[0]);
        }
        
        // Then handle space separator (e.g., "SWIFT 1000" -> "SWIFT")
        if (strpos($desiredPlan, ' ') !== false) {
            $parts = explode(' ', $desiredPlan);
            return trim($parts[0]);
        }
        
        return trim($desiredPlan);
    }

    protected function getPreviousBalance(BillingAccount $account, Carbon $currentDate): float
    {
        // Get the most recent SOA to get accurate previous balance
        $latestSOA = StatementOfAccount::where('account_no', $account->account_no)
            ->orderBy('statement_date', 'desc')
            ->orderBy('id', 'desc')
            ->first();
        
        if ($latestSOA) {
            // Use total_amount_due from the latest SOA as the previous balance
            $previousBalance = floatval($latestSOA->total_amount_due);
            
            Log::info('Getting previous balance from latest SOA', [
                'account_no' => $account->account_no,
                'latest_soa_id' => $latestSOA->id,
                'latest_soa_date' => $latestSOA->statement_date->format('Y-m-d'),
                'total_amount_due' => $latestSOA->total_amount_due,
                'previous_balance' => $previousBalance,
                'current_date' => $currentDate->format('Y-m-d')
            ]);
            
            return $previousBalance;
        }
        
        // Fallback to account_balance if no previous SOA exists
        $accountBalance = floatval($account->account_balance);
        
        Log::info('No previous SOA found, using account_balance', [
            'account_no' => $account->account_no,
            'account_balance' => $accountBalance,
            'current_date' => $currentDate->format('Y-m-d')
        ]);
        
        return $accountBalance;
    }

    protected function markDiscountsAsUsed(BillingAccount $account, int $userId, string $invoiceId): void
    {
        $discounts = Discount::where('account_no', $account->account_no)
            ->whereIn('status', ['Unused', 'Permanent', 'Monthly'])
            ->get();

        Log::info('Marking discounts as used after invoice generation', [
            'account_no' => $account->account_no,
            'discounts_count' => $discounts->count(),
            'invoice_id' => $invoiceId
        ]);

        foreach ($discounts as $discount) {
            if ($discount->status === 'Unused') {
                Log::info('Marking Unused discount as Used', [
                    'discount_id' => $discount->id,
                    'account_no' => $account->account_no,
                    'discount_amount' => $discount->discount_amount
                ]);
                
                $discount->update([
                    'status' => 'Used',
                    'invoice_used_id' => $invoiceId,
                    'used_date' => now(),
                    'updated_by_user_id' => $userId
                ]);
            } elseif ($discount->status === 'Permanent') {
                Log::info('Updating Permanent discount with invoice reference', [
                    'discount_id' => $discount->id,
                    'account_no' => $account->account_no,
                    'discount_amount' => $discount->discount_amount
                ]);
                
                $discount->update([
                    'invoice_used_id' => $invoiceId,
                    'updated_by_user_id' => $userId
                ]);
            } elseif ($discount->status === 'Monthly' && $discount->remaining > 0) {
                Log::info('Decrementing Monthly discount remaining', [
                    'discount_id' => $discount->id,
                    'account_no' => $account->account_no,
                    'discount_amount' => $discount->discount_amount,
                    'remaining_before' => $discount->remaining,
                    'remaining_after' => $discount->remaining - 1
                ]);
                
                $discount->update([
                    'invoice_used_id' => $invoiceId,
                    'remaining' => $discount->remaining - 1,
                    'updated_by_user_id' => $userId
                ]);
            }
        }
    }

    protected function markRebatesAsUsed(BillingAccount $account, int $userId, string $invoiceId): void
    {
        $currentMonth = Carbon::now()->format('F');
        $customer = $account->customer;
        
        if (!$customer) {
            return;
        }

        $technicalDetails = $account->technicalDetails->first();
        if (!$technicalDetails) {
            return;
        }

        $rebates = MassRebate::where('status', 'Unused')
            ->where('month', $currentMonth)
            ->get();

        Log::info('Marking rebates as used after invoice generation - only Unused status', [
            'account_no' => $account->account_no,
            'current_month' => $currentMonth,
            'rebates_count' => $rebates->count(),
            'invoice_id' => $invoiceId,
            'note' => 'Only rebates with status=Unused will be marked as used (not Pending or Used)'
        ]);

        foreach ($rebates as $rebate) {
            $matchFound = false;

            if ($rebate->rebate_type === 'lcpnap') {
                if ($technicalDetails->lcpnap && $technicalDetails->lcpnap === $rebate->selected_rebate) {
                    $matchFound = true;
                }
            } elseif ($rebate->rebate_type === 'lcp') {
                if ($technicalDetails->lcp && $technicalDetails->lcp === $rebate->selected_rebate) {
                    $matchFound = true;
                }
            } elseif ($rebate->rebate_type === 'location') {
                if (($customer->location && $customer->location === $rebate->selected_rebate) ||
                    ($customer->barangay && $customer->barangay === $rebate->selected_rebate)) {
                    $matchFound = true;
                }
            }

            if ($matchFound) {
                $rebateUsage = \App\Models\RebateUsage::where('rebates_id', $rebate->id)
                    ->where('account_no', $account->account_no)
                    ->where('status', 'Unused')
                    ->first();

                if ($rebateUsage) {
                    Log::info('Marking rebate_usage as Used - rebate status was Unused', [
                        'rebate_id' => $rebate->id,
                        'rebate_usage_id' => $rebateUsage->id,
                        'account_no' => $account->account_no,
                        'rebate_type' => $rebate->rebate_type,
                        'selected_rebate' => $rebate->selected_rebate,
                        'rebate_status' => $rebate->status
                    ]);

                    $rebateUsage->update([
                        'status' => 'Used'
                    ]);

                    $this->checkAndUpdateRebateStatus($rebate->id, $userId);
                }
            }
        }
    }

    protected function checkAndUpdateRebateStatus(int $rebateId, int $userId): void
    {
        $unusedCount = \App\Models\RebateUsage::where('rebates_id', $rebateId)
            ->where('status', 'Unused')
            ->count();

        if ($unusedCount === 0) {
            $rebate = MassRebate::find($rebateId);
            if ($rebate) {
                Log::info('All rebate_usage entries are used, marking rebate as Used', [
                    'rebate_id' => $rebateId
                ]);

                $rebate->update([
                    'status' => 'Used',
                    'modified_by' => (string) $userId,
                    'modified_date' => now()
                ]);
            }
        } else {
            Log::info('Rebate still has unused entries', [
                'rebate_id' => $rebateId,
                'unused_count' => $unusedCount
            ]);
        }
    }

    /**
     * Track invoice association for staggered installations
     * This method records which invoice corresponds to which month
     * WITHOUT automatically applying any payment
     */
    protected function trackStaggeredInvoiceAssociation(string $accountNo, int $invoiceId): void
    {
        try {
            $staggeredInstallations = StaggeredInstallation::where('account_no', $accountNo)
                ->where('status', 'Active')
                ->where('months_to_pay', '>', 0)
                ->get();

            foreach ($staggeredInstallations as $staggered) {
                // Find the next empty month column
                $monthColumn = null;
                for ($i = 1; $i <= 12; $i++) {
                    $col = 'month' . $i;
                    if (empty($staggered->$col)) {
                        $monthColumn = $col;
                        break;
                    }
                }

                if (!$monthColumn) {
                    Log::warning('No available month column for staggered installation', [
                        'staggered_id' => $staggered->id,
                        'account_no' => $accountNo
                    ]);
                    continue;
                }

                // Store invoice ID in the month column
                $staggered->$monthColumn = (string)$invoiceId;

                // Decrement months_to_pay
                $staggered->months_to_pay = $staggered->months_to_pay - 1;

                // Mark as completed if no months remaining
                if ($staggered->months_to_pay <= 0) {
                    $staggered->status = 'Completed';
                }

                $staggered->modified_by = 'system';
                $staggered->modified_date = now();
                $staggered->timestamps = false;
                $staggered->save();

                Log::info('Invoice association tracked for staggered installation', [
                    'staggered_id' => $staggered->id,
                    'account_no' => $accountNo,
                    'invoice_id' => $invoiceId,
                    'month_column' => $monthColumn,
                    'months_remaining' => $staggered->months_to_pay,
                    'note' => 'Invoice tracked WITHOUT automatic payment application'
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error tracking staggered invoice association: ' . $e->getMessage());
        }
    }
}


