<?php

namespace App\Services;

use App\Models\BillingAccount;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\StatementOfAccount;
use App\Models\AppPlan;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BillingGenerationService
{
    protected VatCalculator $vatCalculator;

    protected const DAYS_IN_MONTH = 30;
    protected const DAYS_UNTIL_DUE = 7;
    protected const DAYS_UNTIL_DC_NOTICE = 4;

    public function __construct(VatCalculator $vatCalculator)
    {
        $this->vatCalculator = $vatCalculator;
    }

    public function generateInvoicesForBillingDay(int $billingDay, int $userId): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => [],
            'invoices' => []
        ];

        try {
            $accounts = $this->getActiveAccountsForBillingDay($billingDay);

            foreach ($accounts as $account) {
                try {
                    $invoice = $this->createInvoiceForAccount($account, $userId);
                    $results['invoices'][] = $invoice;
                    $results['success']++;
                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'account_id' => $account->id,
                        'error' => $e->getMessage()
                    ];
                    Log::error("Failed to generate invoice for account {$account->id}: " . $e->getMessage());
                }
            }

            return $results;
        } catch (\Exception $e) {
            Log::error("Error in generateInvoicesForBillingDay: " . $e->getMessage());
            throw $e;
        }
    }

    public function generateStatementsForBillingDay(int $billingDay, int $userId): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => [],
            'statements' => []
        ];

        try {
            $accounts = $this->getActiveAccountsForBillingDay($billingDay);

            foreach ($accounts as $account) {
                try {
                    $statement = $this->createStatementForAccount($account, $userId);
                    $results['statements'][] = $statement;
                    $results['success']++;
                } catch (\Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'account_id' => $account->id,
                        'error' => $e->getMessage()
                    ];
                    Log::error("Failed to generate statement for account {$account->id}: " . $e->getMessage());
                }
            }

            return $results;
        } catch (\Exception $e) {
            Log::error("Error in generateStatementsForBillingDay: " . $e->getMessage());
            throw $e;
        }
    }

    protected function getActiveAccountsForBillingDay(int $billingDay)
    {
        return BillingAccount::with(['customer'])
            ->where('billing_day', $billingDay)
            ->where('billing_status_id', 1) // Active status
            ->whereNotNull('date_installed')
            ->get();
    }

    protected function createInvoiceForAccount(BillingAccount $account, int $userId): Invoice
    {
        DB::beginTransaction();

        try {
            $customer = $account->customer;
            if (!$customer) {
                throw new \Exception("Customer not found for account {$account->id}");
            }

            $plan = AppPlan::where('Plan_Name', $customer->desired_plan)->first();
            if (!$plan) {
                throw new \Exception("Plan not found for account {$account->id}");
            }

            $invoiceDate = Carbon::now();
            $dueDate = $invoiceDate->copy()->addDays(self::DAYS_UNTIL_DUE);

            $prorateAmount = $this->calculateProrateAmount(
                $account,
                $plan->Plan_Price,
                $invoiceDate
            );

            $othersAndBasicCharges = $this->calculateOthersAndBasicCharges($account);

            $totalAmount = $prorateAmount + $othersAndBasicCharges;

            if ($account->account_balance < 0) {
                $totalAmount += $account->account_balance;
            }

            $invoice = Invoice::create([
                'account_id' => $account->id,
                'invoice_date' => $invoiceDate,
                'invoice_balance' => $prorateAmount,
                'others_and_basic_charges' => $othersAndBasicCharges,
                'total_amount' => $totalAmount,
                'received_payment' => 0,
                'due_date' => $dueDate,
                'status' => $totalAmount <= 0 ? 'Paid' : 'Unpaid',
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId
            ]);

            $newBalance = $account->account_balance > 0
                ? $totalAmount + $account->account_balance
                : $totalAmount;

            $account->update([
                'account_balance' => $newBalance,
                'balance_update_date' => $invoiceDate
            ]);

            DB::commit();

            return $invoice;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    protected function createStatementForAccount(BillingAccount $account, int $userId): StatementOfAccount
    {
        DB::beginTransaction();

        try {
            $customer = $account->customer;
            if (!$customer) {
                throw new \Exception("Customer not found for account {$account->id}");
            }

            $plan = AppPlan::where('Plan_Name', $customer->desired_plan)->first();
            if (!$plan) {
                throw new \Exception("Plan not found for account {$account->id}");
            }

            $statementDate = Carbon::now();
            $dueDate = $statementDate->copy()->addDays(self::DAYS_UNTIL_DUE);

            $prorateAmount = $this->calculateProrateAmount(
                $account,
                $plan->Plan_Price,
                $statementDate
            );

            // Plan prices are VAT-inclusive: the service charge is split into net + VAT rather
            // than having VAT added on top. Rate comes from billing_config.
            $vatBreakdown = $this->vatCalculator->breakdown($prorateAmount, $account->organization_id);
            $monthlyServiceFee = $vatBreakdown['net'];
            $vat = $vatBreakdown['vat'];

            $othersAndBasicCharges = $this->calculateOthersAndBasicCharges($account);

            // net + VAT is by definition the VAT-inclusive service charge.
            $amountDue = $prorateAmount + $othersAndBasicCharges;
            $totalAmountDue = $account->account_balance + $amountDue;

            $statement = StatementOfAccount::create([
                'account_id' => $account->id,
                'statement_date' => $statementDate,
                'balance_from_previous_bill' => $account->account_balance,
                'payment_received_previous' => 0,
                'remaining_balance_previous' => $account->account_balance,
                'monthly_service_fee' => $monthlyServiceFee,
                'others_and_basic_charges' => $othersAndBasicCharges,
                'vat' => $vat,
                'due_date' => $dueDate,
                'amount_due' => $amountDue,
                'total_amount_due' => $totalAmountDue,
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId
            ]);

            DB::commit();

            return $statement;
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
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
        $daysToCalculate = $this->getDaysBetweenDates($dateInstalled, $currentDate);

        $dailyRate = $monthlyFee / self::DAYS_IN_MONTH;
        $prorateAmount = $dailyRate * $daysToCalculate;

        return round($prorateAmount, 2);
    }

    protected function calculateOthersAndBasicCharges(BillingAccount $account): float
    {
        $staggeredFees = 0;
        $discounts = 0;
        $advancedPayments = 0;
        $rebates = 0;
        $serviceFees = 0;

        $totalDeductions = $advancedPayments + $discounts + $rebates;
        $othersBasicCharges = $staggeredFees + $serviceFees - $totalDeductions;

        return round($othersBasicCharges, 2);
    }

    protected function getDaysBetweenDates(Carbon $startDate, Carbon $endDate): int
    {
        $endDateWithBuffer = $endDate->copy()->addDays(self::DAYS_UNTIL_DUE);
        return $startDate->diffInDays($endDateWithBuffer) + 1;
    }

    public function generateAllBillingsForToday(int $userId): array
    {
        $today = Carbon::now();
        $billingDay = $today->day;

        $invoiceResults = $this->generateInvoicesForBillingDay($billingDay, $userId);
        $statementResults = $this->generateStatementsForBillingDay($billingDay, $userId);

        return [
            'date' => $today->format('Y-m-d'),
            'billing_day' => $billingDay,
            'invoices' => $invoiceResults,
            'statements' => $statementResults
        ];
    }
}

