<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\BillingAccount;
use App\Models\ServiceOrder;
use App\Models\BillingConfig;
use App\Models\SMSTemplate;
use App\Models\EmailTemplate;
use App\Services\EmailQueueService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Throwable;
use Exception;

class AutoDisconnectService
{
    private $logName = 'Auto_DC';
    private $radiusService;
    private $smsService;
    private $emailQueueService;
    private $lockName = 'auto_disconnect_worker';
    private $lockTimeout = 300; // 5 minutes max execution time
    private $hasLock = false;

    /**
     * Cached RADIUS reachability for the lifetime of this run.
     *
     * Every customer targets the same RADIUS server set, so the connectivity probe is
     * performed once and reused — this avoids hammering the server (and re-querying the
     * config) once per customer. null = not yet checked.
     */
    private ?bool $radiusReachable = null;

    /** Emit extra-detailed [VERBOSE] lines to the log/CLI. */
    private $verbose = true;

    /** Mirror every log line to stdout when running from the CLI. */
    private $cliEcho = true;

    /** Whether the current process is running under the CLI SAPI (set in constructor). */
    private $isCli = false;

    /**
     * Fixed 30-day billing-cycle configuration.
     *
     * The billing cycle behaves as a fixed 30-day calendar:
     *   - Day 31 never exists in billing-cycle computation.
     *   - Billing-cycle day 30 always exists logically (even in February).
     *   - Computed billing-cycle days are normalized into valid real calendar dates.
     *   - Proration always divides by 30 (never 28, 29 or 31).
     */
    private const BILLING_CYCLE_DAYS = 30;
    private const DC_OFFSET_DAYS = 10;
    private const ADDITIONAL_INVOICE_OFFSET_DAYS = 7;
    private const PRORATE_DIVISOR_DAYS = 30;

    // Due-date offset applied to a generated additional invoice (mirrors the billing generator's DAYS_UNTIL_DUE).
    private const ADDITIONAL_INVOICE_DUE_OFFSET_DAYS = 7;

    // Marker used in service_charge_logs to identify (and dedupe) auto-generated additional invoices.
    private const ADDITIONAL_INVOICE_CHARGE_TYPE = 'Prorated Additional Invoice';

    public function __construct(
        ManualRadiusOperationsService $radiusService,
        ?ItexmoSmsService $smsService = null,
        ?EmailQueueService $emailQueueService = null
    ) {
        $this->radiusService = $radiusService;
        $this->smsService = $smsService;
        $this->emailQueueService = $emailQueueService;
    }

    /**
     * Process automatic disconnections based on overdue invoices
     */
    public function processAutoDisconnect(): array
    {
        $this->writeLog("╔════════════════════════════════════════════════════════════════╗");
        $this->writeLog("║         STARTING AUTO DISCONNECTION PROCESS                    ║");
        $this->writeLog("╚════════════════════════════════════════════════════════════════╝");
        $startTime = Carbon::now();
        $this->writeLog("Start Time: " . $startTime->format('Y-m-d H:i:s'));
        $this->writeLog("");

        if (!$this->acquireLock()) {
            $this->writeLog("[LOCK] Process is locked by another worker. Exiting.");
            return [
                'success' => false,
                'error' => 'Process is locked by another worker'
            ];
        }

        try {
            $config = BillingConfig::first();
            
            if (!$config) {
                $this->writeLog("[ERROR] Billing configuration not found");
                throw new Exception("Billing configuration not found");
            }

            $dcActualOffset = $config->disconnection_day ?? 4;
            $dcFee = $config->disconnection_fee ?? 0.00;
            $targetDate = Carbon::today()->subDays($dcActualOffset)->format('Y-m-d');
            
            $this->writeLog("[CONFIG] Disconnection Day Offset: {$dcActualOffset} days");
            $this->writeLog("[CONFIG] Disconnection Fee: ₱" . number_format($dcFee, 2));
            $this->writeLog("[CONFIG] Target Due Date: {$targetDate}");
            $this->writeLog("");

            // Fetch ONLY the latest invoice for each account and check if IT is overdue
            $this->writeLog("[QUERY] Searching for latest overdue invoices...");
            
            // 1. Get the IDs of the absolute latest invoice for every account
            $latestInvoiceIds = DB::table('invoices')
                ->select(DB::raw('MAX(id) as id'))
                ->groupBy('account_no')
                ->pluck('id');

            // 2. Fetch those specific latest invoices and filter by EXACT disconnection day
            // Logic: Due Date + Offset == Today (calculated as Due Date == Today - Offset)
            $invoices = Invoice::with(['billingAccount.customer', 'billingAccount.technicalDetails'])
                ->whereIn('id', $latestInvoiceIds)
                ->whereIn('status', ['Unpaid', 'Partial'])
                ->whereDate('due_date', $targetDate) 
                ->get();

            $totalCount = $invoices->count();
            $this->writeLog("[RESULT] Found {$totalCount} account(s) where (Due Date: {$targetDate} + Offset: {$dcActualOffset}) matches Today");
            $this->writeLog("");

            if ($totalCount === 0) {
                $this->writeLog("[INFO] No invoices to process for disconnection today.");
                $this->writeLog("[INFO] Criteria: Status IN ('Unpaid', 'Partial') AND Due Date = {$targetDate}");
                $endTime = Carbon::now();
                $duration = $endTime->diffInSeconds($startTime);
                $this->writeLog("");
                $this->writeLog("╔════════════════════════════════════════════════════════════════╗");
                $this->writeLog("║         AUTO DISCONNECTION COMPLETE (No Actions)               ║");
                $this->writeLog("╚════════════════════════════════════════════════════════════════╝");
                $this->writeLog("End Time: " . $endTime->format('Y-m-d H:i:s'));
                $this->writeLog("Duration: {$duration} second(s)");
                $this->writeLog("");
                $this->writeLog("");
                
                $this->releaseLock();
                return [
                    'success' => true,
                    'processed' => 0,
                    'skipped' => 0,
                    'queued' => 0,
                    'errors' => [],
                    'duration' => $duration
                ];
            }

            $this->writeLog("[PROCESS] Starting disconnection process...");
            $this->writeLog("─────────────────────────────────────────────────────────────────");

            $processedCount = 0;
            $skippedCount = 0;
            $queuedCount = 0;
            $errors = [];
            $counter = 0;

            foreach ($invoices as $invoice) {
                $counter++;
                $this->writeLog("");
                $this->writeLog("[{$counter}/{$totalCount}] ══════════════════════════════════════════════");

                // Isolate each customer: a single unexpected failure must never abort the run.
                try {
                    $result = $this->processDisconnection($invoice, $dcActualOffset);
                } catch (Throwable $e) {
                    $skippedCount++;
                    $errors[] = "Account {$invoice->account_no}: " . $e->getMessage();
                    $this->writeLog("[{$counter}/{$totalCount}] ✗ ERROR (isolated, continuing): " . $e->getMessage());
                    \Log::channel('radiusrelated')->error('[AUTO DC LOOP EXCEPTION] Account: ' . $invoice->account_no . ' - ' . $e->getMessage());
                    continue;
                }

                if ($result['success']) {
                    $processedCount++;
                    $this->writeLog("[{$counter}/{$totalCount}] ✓ SUCCESS - Transaction Committed");
                } elseif (!empty($result['queued'])) {
                    $queuedCount++;
                    $this->writeLog("[{$counter}/{$totalCount}] ⧗ QUEUED for retry: " . ($result['reason'] ?? 'RADIUS unavailable'));
                } else {
                    $skippedCount++;
                    $this->writeLog("[{$counter}/{$totalCount}] ⊘ SKIPPED: {$result['reason']}");
                    if (isset($result['reason'])) {
                        $errors[] = "Account {$invoice->account_no}: {$result['reason']}";
                    }
                }
            }

            $endTime = Carbon::now();
            $duration = $endTime->diffInSeconds($startTime);
            
            $this->writeLog("");
            $this->writeLog("╔════════════════════════════════════════════════════════════════╗");
            $this->writeLog("║         AUTO DISCONNECTION COMPLETE                            ║");
            $this->writeLog("╚════════════════════════════════════════════════════════════════╝");
            $this->writeLog("Summary:");
            $this->writeLog("  • Total Found: {$totalCount}");
            $this->writeLog("  • Successfully Processed: {$processedCount}");
            $this->writeLog("  • Queued for Retry: {$queuedCount}");
            $this->writeLog("  • Skipped: {$skippedCount}");
            $this->writeLog("  • Errors: " . count($errors));
            $this->writeLog("  • Duration: {$duration} second(s)");
            $this->writeLog("End Time: " . $endTime->format('Y-m-d H:i:s'));
            $this->writeLog("");

            if (!empty($errors)) {
                $this->writeLog("[ERROR DETAILS]");
                foreach ($errors as $error) {
                    $this->writeLog("  × {$error}");
                }
                $this->writeLog("");
            }

            $this->releaseLock();
            return [
                'success' => true,
                'processed' => $processedCount,
                'skipped' => $skippedCount,
                'queued' => $queuedCount,
                'errors' => $errors,
                'duration' => $duration
            ];

        } catch (Throwable $e) {
            $endTime = Carbon::now();
            $duration = $endTime->diffInSeconds($startTime);
            
            $this->writeLog("");
            $this->writeLog("╔════════════════════════════════════════════════════════════════╗");
            $this->writeLog("║         CRITICAL ERROR                                         ║");
            $this->writeLog("╚════════════════════════════════════════════════════════════════╝");
            $this->writeLog("[CRITICAL] " . $e->getMessage());
            $this->writeLog("[TRACE] " . $e->getTraceAsString());
            $this->writeLog("End Time: " . $endTime->format('Y-m-d H:i:s'));
            $this->writeLog("Duration: {$duration} second(s)");
            $this->writeLog("");
            
            $this->releaseLock();
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Process a single disconnection
     */
    private function processDisconnection(Invoice $invoice, int $dcActualOffset): array
    {
        $accountNo = $invoice->account_no;
        $this->writeLog("[ACCOUNT] {$accountNo}");

        $billingAccount = $invoice->billingAccount;

        if (!$billingAccount) {
            $this->writeLog("  [SKIP] Billing account not found");
            return ['success' => false, 'reason' => 'Billing account not found'];
        }

        // Check if already disconnected today
        $alreadyDisconnected = DB::table('disconnected_logs')
            ->where('account_id', $billingAccount->id)
            ->whereDate('created_at', Carbon::today())
            ->exists();

        if ($alreadyDisconnected) {
            $this->writeLog("  [SKIP] Already disconnected today");
            return ['success' => false, 'reason' => 'Already disconnected today'];
        }

        // Validate account balance
        $currentBalance = floatval($billingAccount->account_balance);
        $this->writeLog("  [INFO] Current Balance: ₱" . number_format($currentBalance, 2));

        if ($currentBalance <= 0.00) {
            $this->writeLog("  [SKIP] Balance is zero or negative (already paid)");
            return ['success' => false, 'reason' => 'Balance already paid'];
        }

        // Check if already inactive or pullout
        $billingStatus = $billingAccount->billingStatus ? $billingAccount->billingStatus->status_name : '';
        $this->writeLog("  [INFO] Current Status: {$billingStatus}");
        
        if (in_array($billingStatus, ['Inactive', 'Pullout', 'Disconnected', 'Offline', 'Restricted', 'Pullout Restricted'])) {
            $this->writeLog("  [SKIP] Status is already {$billingStatus}");
            return ['success' => false, 'reason' => "Already {$billingStatus}"];
        }

        // Get technical details for username
        $technicalDetail = $billingAccount->technicalDetails->first();
        if (!$technicalDetail || empty($technicalDetail->username)) {
            $this->writeLog("  [SKIP] PPPoE username not found");
            return ['success' => false, 'reason' => 'PPPoE username not found'];
        }

        $username = $technicalDetail->username;
        $this->writeLog("  [INFO] Username: {$username}");

        // Pre-flight: make sure the RADIUS server is reachable BEFORE we mutate anything.
        // If it is not (connection timeout, network error, or auth failure), queue the
        // restrict operation for the ProcessRadiusQueue cron to retry later and move on —
        // one unreachable server must never abort the whole run.
        if (!$this->isRadiusReachable()) {
            $this->writeLog("  [RADIUS] Server unreachable — queueing restrict for retry instead of disconnecting now");
            $this->queueRadiusOperation(
                $billingAccount,
                $username,
                $accountNo,
                'restricted_user',
                'Auto DC',
                'RADIUS server unreachable during auto-disconnect'
            );
            return ['success' => false, 'queued' => true, 'reason' => 'RADIUS unreachable — queued restrict for retry'];
        }

        // Create transaction to ensure atomicity
        DB::beginTransaction();
        try {
            // 1. Restrict via RADIUS first
            $this->writeLog("  [RADIUS] Initiating restriction...");
            $restrictResult = $this->radiusService->restrictedUser([
                'username' => $username,
                'accountNumber' => $accountNo,
                'remarks' => 'Auto DC',
                'updatedBy' => 'System'
            ]);

            if ($restrictResult['status'] !== 'success') {
                $reason = $restrictResult['message'] ?? 'Unknown RADIUS error';
                $this->writeLog("  [RADIUS] Restriction failed: {$reason}. Rolling back and queueing for retry.");
                \Log::channel('radiusrelated')->error('[AUTO DC RADIUS FAILURE] Account: ' . $accountNo . ' - Reason: ' . $reason);
                DB::rollBack();
                // Do NOT throw — queue the operation and let the run continue with the next customer.
                $this->queueRadiusOperation(
                    $billingAccount,
                    $username,
                    $accountNo,
                    'restricted_user',
                    'Auto DC',
                    'RADIUS restrict failed: ' . $reason
                );
                return ['success' => false, 'queued' => true, 'reason' => 'RADIUS restrict failed — queued for retry: ' . $reason];
            }
            $this->writeLog("  [RADIUS] ✓ Successfully restricted");

            // 2. Apply disconnection fee if configured
            $config = BillingConfig::first();
            $dcFee = floatval($config->disconnection_fee ?? 0);

            if ($dcFee > 0) {
                $this->writeLog("  [FEE] Applying disconnection fee: ₱" . number_format($dcFee, 2));

                // Update invoice
                // Use DB::table to ensure it's part of the raw transaction and avoid model events
                $currentServiceCharge = floatval($invoice->service_charge ?? 0);
                $currentTotalAmount = floatval($invoice->total_amount ?? 0);
                $currentInvoiceBalance = floatval($invoice->invoice_balance ?? 0);
                $newServiceCharge = $currentServiceCharge + $dcFee;
                $newTotalAmount = $currentTotalAmount + $dcFee;
                $newInvoiceBalance = $currentInvoiceBalance + $dcFee;

                DB::table('invoices')
                    ->where('id', $invoice->id)
                    ->update([
                        'service_charge' => $newServiceCharge,
                        'total_amount' => $newTotalAmount,
                        'invoice_balance' => $newInvoiceBalance,
                        'updated_by' => 'System',
                        'updated_at' => Carbon::now()
                    ]);

                // Update account balance
                $newBalance = $currentBalance + $dcFee;
                
                // Direct update to billing_accounts to ensure it persists
                DB::table('billing_accounts')
                    ->where('id', $billingAccount->id)
                    ->update([
                        'account_balance' => $newBalance,
                        'updated_by' => 'System',
                        'updated_at' => Carbon::now()
                    ]);
                
                // Update the local instance for logging & SMS
                $billingAccount->account_balance = $newBalance;

                $this->writeLog("  [FEE] New Balance: ₱" . number_format($newBalance, 2));

                // Log service charge
                DB::table('service_charge_logs')->insert([
                    'account_no' => $accountNo,
                    'invoice_id' => $invoice->id,
                    'service_charge_type' => 'Disconnection Fee',
                    'service_charge' => $dcFee,
                    'date_used' => Carbon::now(),
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                    'created_by' => 'System',
                    'updated_by' => 'System'
                ]);

            } else {
                $this->writeLog("  [FEE] No disconnection fee (set to 0)");
            }

            // 3. Override billing account status to Inactive (RADIUS service sets Restricted; we want Inactive here)
            $inactiveStatusId = DB::table('billing_status')->where('status_name', 'Inactive')->value('id') ?? 4;
            DB::table('billing_accounts')
                ->where('id', $billingAccount->id)
                ->update([
                    'billing_status_id' => $inactiveStatusId,
                    'updated_by' => 'System',
                    'updated_at' => Carbon::now()
                ]);

            $this->writeLog("  [LOG] Status overridden to Inactive (ID: {$inactiveStatusId}) after RADIUS restriction");

            $this->writeLog("  [DB] STARTING DB COMMIT for Account {$accountNo}...");
            DB::commit();
            $this->writeLog("  [DB] ✓ COMMIT SUCCESSFUL");
            
            // Send SMS notification - AFTER commit to prevent duplicates on rollback
            if ($this->smsService && $billingAccount->customer && $billingAccount->customer->contact_number_primary) {
                $this->writeLog("  [SMS] Attempting to trigger triggerSMS function...");
                $this->triggerSMS($billingAccount, 'Disconnected');
                $this->writeLog("  [SMS] triggerSMS function finished.");
            } else {
                $this->writeLog("  [SMS] Skipping SMS (Service null or no primary contact)");
            }

            // Send Email notification - AFTER commit
            if ($this->emailQueueService && $billingAccount->customer && $billingAccount->customer->email_address) {
                $this->writeLog("  [EMAIL] Attempting to trigger triggerEmail function...");
                $this->triggerEmail($billingAccount);
                $this->writeLog("  [EMAIL] triggerEmail function finished.");
            } else {
                $this->writeLog("  [EMAIL] Skipping Email (Service null or no email address)");
            }

            $this->writeLog("  [COMPLETE] Account {$accountNo} successfully restricted and set to Inactive");

            return ['success' => true];

        } catch (Throwable $e) {
            DB::rollBack();
            $this->writeLog("  [ERROR] Transaction rolled back for Account {$accountNo}: " . $e->getMessage());
            $this->writeLog("  [TRACE] " . $e->getTraceAsString());

            // A connection-related failure that surfaced as an exception is queued for retry.
            // Either way we return a failure result rather than throwing, so the remaining
            // customers are still processed instead of aborting the whole run.
            if (str_contains($e->getMessage(), 'RADIUS')) {
                \Log::channel('radiusrelated')->error('[AUTO DC EXCEPTION] Account: ' . $accountNo . ' - Error: ' . $e->getMessage());
                $this->queueRadiusOperation(
                    $billingAccount,
                    $username,
                    $accountNo,
                    'restricted_user',
                    'Auto DC',
                    'RADIUS exception during auto-disconnect: ' . $e->getMessage()
                );
                return ['success' => false, 'queued' => true, 'reason' => 'RADIUS exception — queued for retry: ' . $e->getMessage()];
            }

            return ['success' => false, 'reason' => $e->getMessage()];
        }
    }

    /**
     * Process automatic pullout requests
     */
    public function processAutoPullout(): array
    {
        $this->writeLog("");
        $this->writeLog("╔════════════════════════════════════════════════════════════════╗");
        $this->writeLog("║         STARTING AUTO PULLOUT PROCESS                          ║");
        $this->writeLog("╚════════════════════════════════════════════════════════════════╝");
        $startTime = Carbon::now();
        $this->writeLog("Start Time: " . $startTime->format('Y-m-d H:i:s'));
        $this->writeLog("");

        try {
            $config = BillingConfig::first();
            
            if (!$config) {
                $this->writeLog("[ERROR] Billing configuration not found");
                throw new Exception("Billing configuration not found");
            }

            $pulloutOffset = $config->pullout_day ?? $config->pullout_offset ?? 30;
            
            if ($pulloutOffset <= 0) {
                $this->writeLog("[INFO] Auto Pullout is disabled (pullout_day = 0)");
                return [
                    'success' => true,
                    'created' => 0,
                    'skipped' => 0,
                    'errors' => [],
                    'duration' => 0
                ];
            }

            $targetDate = Carbon::today()->subDays($pulloutOffset)->format('Y-m-d');
            
            $this->writeLog("[CONFIG] Pullout Day Offset: {$pulloutOffset} days");
            $this->writeLog("[CONFIG] Target Due Date: {$targetDate}");
            $this->writeLog("");

            // Fetch overdue invoices for pullout
            $this->writeLog("[QUERY] Searching for pullout candidates...");
            $invoices = Invoice::with(['billingAccount.customer', 'billingAccount.technicalDetails'])
                ->whereIn('status', ['Unpaid', 'Partial'])
                ->whereDate('due_date', $targetDate)
                ->get();

            $totalCount = $invoices->count();
            $this->writeLog("[RESULT] Found {$totalCount} invoice(s) with due date = {$targetDate}");
            $this->writeLog("");

            if ($totalCount === 0) {
                $this->writeLog("[INFO] No invoices to process for pullout today.");
                $this->writeLog("[INFO] Criteria: Status IN ('Unpaid', 'Partial') AND Due Date = {$targetDate}");
                $endTime = Carbon::now();
                $duration = $endTime->diffInSeconds($startTime);
                $this->writeLog("");
                $this->writeLog("╔════════════════════════════════════════════════════════════════╗");
                $this->writeLog("║         AUTO PULLOUT COMPLETE (No Actions)                     ║");
                $this->writeLog("╚════════════════════════════════════════════════════════════════╝");
                $this->writeLog("End Time: " . $endTime->format('Y-m-d H:i:s'));
                $this->writeLog("Duration: {$duration} second(s)");
                $this->writeLog("");
                
                return [
                    'success' => true,
                    'created' => 0,
                    'skipped' => 0,
                    'errors' => [],
                    'duration' => $duration
                ];
            }

            $this->writeLog("[PROCESS] Starting pullout request creation...");
            $this->writeLog("─────────────────────────────────────────────────────────────────");

            $createdCount = 0;
            $skippedCount = 0;
            $errors = [];
            $counter = 0;

            foreach ($invoices as $invoice) {
                $counter++;
                $accountNo = $invoice->account_no;
                
                $this->writeLog("");
                $this->writeLog("[{$counter}/{$totalCount}] ══════════════════════════════════════════════");
                $this->writeLog("[ACCOUNT] {$accountNo}");
                
                try {
                    // Check if pullout request already exists for this month
                    $existingPullout = ServiceOrder::where('account_no', $accountNo)
                        ->whereIn('concern', ['Pullout', 'For Pullout', 'for pullout'])
                        ->whereNotIn('support_status', ['Closed', 'Cancelled'])
                        ->whereMonth('created_at', Carbon::now()->month)
                        ->whereYear('created_at', Carbon::now()->year)
                        ->exists();

                    if ($existingPullout) {
                        $this->writeLog("  [SKIP] Pullout request already exists for this month");
                        $this->writeLog("[{$counter}/{$totalCount}] ⊘ SKIPPED");
                        $skippedCount++;
                        continue;
                    }

                    $billingAccount = $invoice->billingAccount;
                    if (!$billingAccount) {
                        $this->writeLog("  [SKIP] Billing account not found");
                        $this->writeLog("[{$counter}/{$totalCount}] ⊘ SKIPPED");
                        $skippedCount++;
                        continue;
                    }

                    // Check if account is already Pullout or Disconnected - skip entirely
                    $statusName = $billingAccount->billingStatus ? $billingAccount->billingStatus->status_name : null;
                    if (in_array($statusName, ['Pullout', 'Disconnected', 'Pullout Restricted'])) {
                        $this->writeLog("  [SKIP] Account status is already {$statusName} - no action needed");
                        $this->writeLog("[{$counter}/{$totalCount}] ⊘ SKIPPED");
                        $skippedCount++;
                        continue;
                    }

                    // Get technical details for RADIUS username
                    $technicalDetail = $billingAccount->technicalDetails->first();
                    if (!$technicalDetail || empty($technicalDetail->username)) {
                        $this->writeLog("  [SKIP] PPPoE username not found");
                        $this->writeLog("[{$counter}/{$totalCount}] ⊘ SKIPPED");
                        $skippedCount++;
                        continue;
                    }

                    $username = $technicalDetail->username;
                    $this->writeLog("  [INFO] Username: {$username}");

                    // 1. Create pullout service order
                    $this->writeLog("  [CREATE] Creating pullout service order...");
                    $this->createPulloutRequest($billingAccount, $pulloutOffset);
                    $this->writeLog("  [CREATE] ✓ Pullout service order created");

                    // 2. Restrict user via RADIUS (also creates disconnected_logs entry)
                    $this->writeLog("  [RADIUS] Restricting user via RADIUS...");
                    $restrictResult = $this->radiusService->restrictedUser([
                        'username' => $username,
                        'accountNumber' => $accountNo,
                        'remarks' => 'Pullout',
                        'updatedBy' => 'System'
                    ]);

                    if ($restrictResult['status'] === 'success') {
                        $this->writeLog("  [RADIUS] ✓ Successfully restricted");
                    } else {
                        $reason = $restrictResult['message'] ?? 'Unknown';
                        $this->writeLog("  [RADIUS] ✗ Restrict failed: " . $reason);
                        \Log::channel('radiusrelated')->error('[AUTO PULLOUT RADIUS FAILURE] Account: ' . $accountNo . ' - Reason: ' . $reason);
                        // Queue the restriction so the RADIUS side is retried once the server recovers.
                        $this->queueRadiusOperation(
                            $billingAccount,
                            $username,
                            $accountNo,
                            'restricted_user',
                            'Pullout',
                            'RADIUS restrict failed during auto-pullout: ' . $reason
                        );
                    }

                    // 3. Update billing status to Inactive
                    $inactiveStatusId = DB::table('billing_status')->where('status_name', 'Inactive')->value('id') ?? 4;
                    DB::table('billing_accounts')
                        ->where('id', $billingAccount->id)
                        ->update([
                            'billing_status_id' => $inactiveStatusId,
                            'updated_by' => 'System',
                            'updated_at' => Carbon::now()
                        ]);
                    $this->writeLog("  [DB] ✓ Billing status updated to Inactive (ID: {$inactiveStatusId})");

                    // 4. Send SMS notification
                    if ($this->smsService && $billingAccount->customer && $billingAccount->customer->contact_number_primary) {
                        $this->writeLog("  [SMS] Sending pullout notification...");
                        $this->triggerSMS($billingAccount, 'Disconnected');
                        $this->writeLog("  [SMS] ✓ SMS sent");
                    } else {
                        $this->writeLog("  [SMS] Skipping (no SMS service or no contact number)");
                    }

                    // 5. Send Email notification
                    if ($this->emailQueueService && $billingAccount->customer && $billingAccount->customer->email_address) {
                        $this->writeLog("  [EMAIL] Sending pullout notification...");
                        $this->triggerEmail($billingAccount);
                        $this->writeLog("  [EMAIL] ✓ Email queued");
                    } else {
                        $this->writeLog("  [EMAIL] Skipping (no email service or no email address)");
                    }

                    $createdCount++;
                    $this->writeLog("  [COMPLETE] Pullout fully processed for {$accountNo}");
                    $this->writeLog("[{$counter}/{$totalCount}] ✓ SUCCESS");

                } catch (Exception $e) {
                    $this->writeLog("  [ERROR] " . $e->getMessage());
                    $this->writeLog("  [TRACE] " . $e->getTraceAsString());
                    $this->writeLog("[{$counter}/{$totalCount}] ✗ ERROR");
                    $errors[] = "Account {$accountNo}: " . $e->getMessage();
                    $skippedCount++;
                }
            }

            $endTime = Carbon::now();
            $duration = $endTime->diffInSeconds($startTime);
            
            $this->writeLog("");
            $this->writeLog("╔════════════════════════════════════════════════════════════════╗");
            $this->writeLog("║         AUTO PULLOUT COMPLETE                                  ║");
            $this->writeLog("╚════════════════════════════════════════════════════════════════╝");
            $this->writeLog("Summary:");
            $this->writeLog("  • Total Found: {$totalCount}");
            $this->writeLog("  • Service Orders Created: {$createdCount}");
            $this->writeLog("  • Skipped: {$skippedCount}");
            $this->writeLog("  • Errors: " . count($errors));
            $this->writeLog("  • Duration: {$duration} second(s)");
            $this->writeLog("End Time: " . $endTime->format('Y-m-d H:i:s'));
            $this->writeLog("");

            if (!empty($errors)) {
                $this->writeLog("[ERROR DETAILS]");
                foreach ($errors as $error) {
                    $this->writeLog("  × {$error}");
                }
                $this->writeLog("");
            }

            return [
                'success' => true,
                'created' => $createdCount,
                'skipped' => $skippedCount,
                'errors' => $errors,
                'duration' => $duration
            ];

        } catch (Exception $e) {
            $endTime = Carbon::now();
            $duration = $endTime->diffInSeconds($startTime);
            
            $this->writeLog("");
            $this->writeLog("╔════════════════════════════════════════════════════════════════╗");
            $this->writeLog("║         CRITICAL ERROR                                         ║");
            $this->writeLog("╚════════════════════════════════════════════════════════════════╝");
            $this->writeLog("[CRITICAL] " . $e->getMessage());
            $this->writeLog("[TRACE] " . $e->getTraceAsString());
            $this->writeLog("End Time: " . $endTime->format('Y-m-d H:i:s'));
            $this->writeLog("Duration: {$duration} second(s)");
            $this->writeLog("");
            
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Create a pullout service order
     */
    private function createPulloutRequest(BillingAccount $billingAccount, int $pulloutOffset): void
    {
        $serviceOrder = new ServiceOrder();
        $serviceOrder->Timestamp = Carbon::now();
        $serviceOrder->account_no = $billingAccount->account_no;
        $serviceOrder->support_status = 'For Visit';
        $serviceOrder->concern = 'for pullout';
        $serviceOrder->concern_remarks = "System Auto Generated (Overdue {$pulloutOffset} Days)";
        $serviceOrder->requested_by = 'System';
        $serviceOrder->created_by_user = 'System';
        $serviceOrder->updated_by_user = 'System';
        $serviceOrder->save();
    }

    /**
     * Trigger SMS notification
     */
    private function triggerSMS(BillingAccount $billingAccount, string $type): void
    {
        $this->writeLog("    [DEBUG] triggerSMS: Starting for Account {$billingAccount->account_no}");
        try {
            if (!$this->smsService) {
                $this->writeLog("    [DEBUG] triggerSMS: smsService is null");
                return;
            }

            $customer = $billingAccount->customer;
            if (!$customer || empty($customer->contact_number_primary)) {
                $this->writeLog("    [DEBUG] triggerSMS: Customer or primary contact missing");
                return;
            }
            $this->writeLog("    [DEBUG] triggerSMS: Target number: {$customer->contact_number_primary}");

            $planNameRaw = $billingAccount->plan->name ?? $customer->desired_plan ?? 'N/A';
            $message = $this->buildSmsMessage(
                $type, 
                $customer->full_name, 
                $billingAccount->account_no, 
                [
                    'balance' => number_format($billingAccount->account_balance, 2),
                    'plan_name' => $planNameRaw
                ]
            );
            $this->writeLog("    [DEBUG] triggerSMS: Message built: " . (empty($message) ? 'EMPTY' : 'OK'));

            if (!empty($message)) {
                $this->writeLog("    [DEBUG] triggerSMS: Calling send...");
                $result = $this->smsService->send([
                    'contact_no' => $customer->contact_number_primary,
                    'message' => $message
                ]);
                
                $success = $result['success'] ?? false;
                $this->writeLog("    [DEBUG] triggerSMS: send call completed. Success: " . ($success ? 'YES' : 'NO'));
                if (!$success) {
                    $this->writeLog("    [DEBUG] triggerSMS Error Details: " . ($result['error'] ?? 'Unknown error'));
                }
            }

        } catch (Throwable $e) {
            $this->writeLog("    [DEBUG] triggerSMS Error: " . $e->getMessage());
            $this->writeLog("    [DEBUG] triggerSMS Error Trace: " . $e->getTraceAsString());
            // Don't throw - SMS failure shouldn't stop the process
        }
    }

    /**
     * Trigger Email notification
     */
    private function triggerEmail(BillingAccount $billingAccount): void
    {
        $this->writeLog("    [DEBUG] triggerEmail: Starting for Account {$billingAccount->account_no}");
        try {
            if (!$this->emailQueueService) {
                $this->writeLog("    [DEBUG] triggerEmail: emailQueueService is null");
                return;
            }

            $customer = $billingAccount->customer;
            if (!$customer || empty($customer->email_address)) {
                $this->writeLog("    [DEBUG] triggerEmail: Customer or email address missing");
                return;
            }
            $this->writeLog("    [DEBUG] triggerEmail: Target email: {$customer->email_address}");

            // Find template
            $template = EmailTemplate::where('Template_Code', 'DISCONNECTED')->first();
            
            if (!$template) {
                 $this->writeLog("    [DEBUG] triggerEmail: DISCONNECTED template not found");
                 return;
            }
            
            // Use email_body as requested
            $body = $template->email_body;
            if (empty($body)) {
                 $this->writeLog("    [DEBUG] triggerEmail: email_body is empty in template");
                 return;
            }

            $this->writeLog("    [DEBUG] triggerEmail: Queueing email via template...");
            
            $customerName = preg_replace('/\s+/', ' ', trim($customer->full_name ?? ''));
            $planNameRaw = $billingAccount->plan->name ?? $customer->desired_plan ?? 'N/A';
            $planNameFormatted = str_replace('₱', 'P', $planNameRaw);

            $emailData = [
                'customer_name' => $customerName,
                'account_no' => $billingAccount->account_no,
                'amount_due' => number_format($billingAccount->account_balance, 2),
                'balance' => number_format($billingAccount->account_balance, 2),
                'plan_name' => $planNameFormatted,
                'recipient_email' => $customer->email_address,
            ];

            $emailQueued = $this->emailQueueService->queueFromTemplate('DISCONNECTED', $emailData);
            
            if ($emailQueued) {
                $this->writeLog("    [DEBUG] triggerEmail: Email queued successfully via template.");
            } else {
                $this->writeLog("    [DEBUG] triggerEmail: Email failed to queue via template");
            }

        } catch (Throwable $e) {
            $this->writeLog("    [DEBUG] triggerEmail Error: " . $e->getMessage());
            $this->writeLog("    [DEBUG] triggerEmail Error Trace: " . $e->getTraceAsString());
        }
    }

    /**
     * Build SMS message based on type from database templates
     */
    private function buildSmsMessage(string $type, string $name, string $accountNo, array $data): string
    {
        try {
            // Find active template for this type
            $template = SMSTemplate::where('template_type', $type)
                ->where('is_active', true)
                ->first();

            if ($template) {
                $message = $template->message_content;
                
                // Common variable replacements
                $customerName = preg_replace('/\s+/', ' ', trim($name));
                $planNameFormatted = str_replace('₱', 'P', $data['plan_name'] ?? '');

                $message = str_replace('{{customer_name}}', $customerName, $message);
                $message = str_replace('{{account_no}}', $accountNo, $message);
                $message = str_replace('{{plan_name}}', $planNameFormatted, $message);
                $message = str_replace('{{plan_nam}}', $planNameFormatted, $message);
                
                // Add balance if present in data
                if (isset($data['balance'])) {
                    $message = str_replace('{{amount_due}}', $data['balance'], $message);
                    $message = str_replace('{{balance}}', $data['balance'], $message);
                }

                return $this->replaceGlobalVariables($message);
            }

            $this->writeLog("    [DEBUG] buildSmsMessage: Template type '{$type}' not found or inactive. Falling back to default.");

            // Fallback hardcoded messages if template not found
            switch ($type) {
                case 'Disconnected':
                case 'dcTxt':
                    $balance = $data['balance'] ?? '0.00';
                    return $this->replaceGlobalVariables("DISCONNECTION NOTICE: Dear {{customer_name}}, your account ({{account_no}}) has been disconnected due to non-payment. Outstanding balance: PHP {{balance}}. Please settle immediately to restore service. Thank you!", $name, $accountNo, $balance);
                    
                default:
                    return '';
            }
        } catch (Throwable $e) {
            $this->writeLog("    [DEBUG] buildSmsMessage Error: " . $e->getMessage());
            return '';
        }
    }

    private function replaceGlobalVariables(string $message, string $name = '', string $accountNo = '', string $balance = ''): string
    {
        $portalUrl = 'sync.atssfiber.ph';
        $brandName = \DB::table('form_ui')->value('brand_name') ?? 'Your ISP';

        $message = str_replace('{{portal_url}}', $portalUrl, $message);
        $message = str_replace('{{company_name}}', $brandName, $message);
        
        // Handle fallbacks if needed
        $name = preg_replace('/\s+/', ' ', trim($name));
        if ($name) $message = str_replace('{{customer_name}}', $name, $message);
        if ($accountNo) $message = str_replace('{{account_no}}', $accountNo, $message);
        if ($balance) $message = str_replace('{{balance}}', $balance, $message);

        return $message;
    }

    /**
     * Whether the RADIUS server is reachable right now (probe result cached for the run).
     *
     * A probe that throws is treated as "unreachable" so callers fall back to queueing
     * the operation rather than aborting.
     */
    private function isRadiusReachable(): bool
    {
        if ($this->radiusReachable !== null) {
            return $this->radiusReachable;
        }

        try {
            $this->radiusReachable = $this->radiusService->isRadiusReachable();
        } catch (Throwable $e) {
            $this->writeLog("  [RADIUS] Connectivity probe threw: " . $e->getMessage() . " — treating RADIUS as unreachable");
            $this->radiusReachable = false;
        }

        return $this->radiusReachable;
    }

    /**
     * Queue a RADIUS restrict/disconnect operation for the ProcessRadiusQueue cron to retry
     * when the RADIUS server can't be reached or the operation fails for a connection-related
     * reason.
     *
     * De-duplicates by (account_no, operation): a customer that already has a pending or
     * in-flight operation of the same type is never queued twice, so the whole job stays
     * safe to run repeatedly. The queued payload carries everything the retry needs
     * (customer, operation, username/remarks payload) plus the failure reason and timestamps.
     *
     * @return bool True when a row was queued (or an equivalent one already exists); false on failure.
     */
    private function queueRadiusOperation(
        BillingAccount $billingAccount,
        string $username,
        string $accountNo,
        string $operation,
        string $remarks,
        string $reason
    ): bool {
        try {
            // Dedup guard: skip if an identical operation is already waiting to run.
            $duplicate = DB::table('radius_operation_queue')
                ->where('account_no', $accountNo)
                ->where('operation', $operation)
                ->whereIn('status', ['pending', 'processing'])
                ->exists();

            if ($duplicate) {
                $this->writeLog("  [QUEUE] Skipped — a pending '{$operation}' operation already exists for {$accountNo}");
                return true;
            }

            $queued = RadiusQueueService::queue([
                'organization_id' => $billingAccount->organization_id ?? null,
                'source_type'     => 'auto_disconnect',
                'source_id'       => $billingAccount->id,
                'account_no'      => $accountNo,
                'operation'       => $operation,
                'params'          => [
                    'username'      => $username,
                    'accountNumber' => $accountNo,
                    'remarks'       => $remarks,
                    'updatedBy'     => 'System',
                ],
                'last_error'      => $reason,
                'created_by'      => 'System',
            ]);

            if ($queued) {
                $this->writeLog("  [QUEUE] ✓ Queued '{$operation}' for {$accountNo} (will retry later). Reason: {$reason}");
                \Log::channel('radiusrelated')->warning("[AUTO DC QUEUED] Account: {$accountNo} - '{$operation}' queued for retry. Reason: {$reason}");
                return true;
            }

            $this->writeLog("  [QUEUE] ✗ Failed to queue '{$operation}' for {$accountNo}");
            \Log::channel('radiusrelated')->error("[AUTO DC QUEUE FAILURE] Account: {$accountNo} - Could not queue '{$operation}'. Reason: {$reason}");
            return false;
        } catch (Throwable $e) {
            $this->writeLog("  [QUEUE] ✗ Exception while queueing '{$operation}' for {$accountNo}: " . $e->getMessage());
            \Log::channel('radiusrelated')->error("[AUTO DC QUEUE EXCEPTION] Account: {$accountNo} - " . $e->getMessage());
            return false;
        }
    }

    /**
     * Write to log file
     */
    private function writeLog(string $message): void
    {
        $timestamp = Carbon::now()->format('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$this->logName}] {$message}";
        
        // Define directory and file path
        $logDir = storage_path('logs/autodisconnect');
        $logFile = $logDir . '/auto_disconnect_pullout.log';

        // Check/Create Directory
        if (!file_exists($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // Write to custom log file
        file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
        
        // Also log to Laravel default log
        Log::channel('single')->info("[{$this->logName}] {$message}");
    }

    /**
     * Toggle verbose file logging and CLI echo at runtime.
     *
     * @param bool $verbose Emit [VERBOSE] detail lines.
     * @param bool $cliEcho Mirror log lines to stdout when running in the CLI.
     */
    public function setVerbose(bool $verbose = true, bool $cliEcho = true): self
    {
        $this->verbose = $verbose;
        $this->cliEcho = $cliEcho;
        return $this;
    }

    /**
     * Normalize a fixed billing-cycle day into a valid real calendar date.
     *
     * normalizedDate = first day of target cycle month + (cycleDay - 1) days
     *
     * This lets billing-cycle day 30 exist even in months with fewer than 30 real
     * calendar days (e.g. February) by rolling the surplus into the next real month:
     *   - normalizeBillingCycleDate(2026, 2, 30) => 2026-03-02 (Feb has 28 real days)
     *   - normalizeBillingCycleDate(2028, 2, 30) => 2028-03-01 (Feb has 29 real days)
     */
    private function normalizeBillingCycleDate(int $year, int $month, int $cycleDay): Carbon
    {
        return Carbon::create($year, $month, 1, 0, 0, 0)->addDays($cycleDay - 1);
    }

    /**
     * Add a fixed number of billing-cycle days to a billing-cycle coordinate.
     *
     * Uses fixed 30-day billing-cycle arithmetic (day 31 never exists, day 30 always
     * exists) and then normalizes the result into a real calendar date. Month overflow
     * (including December → January of the next year) is handled correctly.
     *
     * @return array{date: Carbon, year: int, month: int, day: int}
     */
    private function addFixedBillingCycleDays(int $cycleYear, int $cycleMonth, int $cycleDay, int $offset): array
    {
        $totalDay = $cycleDay + $offset;
        $targetYear = $cycleYear;
        $targetMonth = $cycleMonth;

        while ($totalDay > self::BILLING_CYCLE_DAYS) {
            $totalDay -= self::BILLING_CYCLE_DAYS;
            $targetMonth++;
            if ($targetMonth > 12) {
                $targetMonth = 1;
                $targetYear++;
            }
        }

        $normalizedDate = $this->normalizeBillingCycleDate($targetYear, $targetMonth, $totalDay);

        return [
            'date' => $normalizedDate,
            'year' => $targetYear,
            'month' => $targetMonth,
            'day' => $totalDay,
        ];
    }

    /**
     * Resolve which billing-cycle days have their (billing day + $offset) fixed-cycle
     * date landing on $today.
     *
     * A given offset (<= 30) wraps at most one billing-cycle month, so a target that
     * lands in $today's month can only originate from this month's or the previous
     * month's billing cycle. Each qualifying billing-cycle day maps to exactly one
     * originating cycle.
     *
     * @return array<int, array{cycle_year:int, cycle_month:int, target: array}>
     *         Keyed by billing-cycle day.
     */
    private function resolveCycleDaysForTarget(Carbon $today, int $offset): array
    {
        $result = [];

        // A target lands at most floor(offset / 30) + 1 billing-cycle months after its
        // originating billing cycle, so we look back that many months (plus the current
        // one) to find every billing-cycle day whose target equals today. This keeps the
        // resolver correct even when config-driven offsets exceed 30 cycle days.
        $monthsBack = intdiv(max(0, $offset), self::BILLING_CYCLE_DAYS) + 1;

        for ($back = 0; $back <= $monthsBack; $back++) {
            $ref = $today->copy()->startOfMonth()->subMonths($back);
            $cycleYear = (int) $ref->year;
            $cycleMonth = (int) $ref->month;

            for ($day = 1; $day <= self::BILLING_CYCLE_DAYS; $day++) {
                $computed = $this->addFixedBillingCycleDays($cycleYear, $cycleMonth, $day, $offset);
                if ($computed['date']->isSameDay($today)) {
                    $result[$day] = [
                        'cycle_year' => $cycleYear,
                        'cycle_month' => $cycleMonth,
                        'target' => $computed,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Resolve the fixed billing-cycle schedule offsets from BillingConfig.
     *
     * All offsets are expressed in fixed billing-cycle days measured from the billing day:
     *   due date         = billing day + due_date_day
     *   DC / restriction = due date    + disconnection_day
     *                    = billing day + (due_date_day + disconnection_day)
     *   coverage (proration window) = disconnection_day   (days from due date to DC)
     *   grace / additional invoice  = DC + ADDITIONAL_INVOICE_OFFSET_DAYS
     *
     * Falls back to the documented constants when a value is missing. A disconnection_day
     * of 0 disables auto-DC (and therefore the grace charge), matching the config UI where
     * "0 = disabled".
     *
     * NOTE: the grace/additional-invoice offset (days after DC) has no dedicated field in
     * billing_config, so it stays the ADDITIONAL_INVOICE_OFFSET_DAYS constant.
     *
     * The DC-notice date is informational only (the service has no notice channel) and is
     * measured as due date + disconnection_notice cycle days.
     *
     * @return array{due_offset:int, dc_after_due:int, dc_offset:int, notice_after_due:int, notice_offset:int, coverage:int, grace_after_dc:int, grace_offset:int, dc_enabled:bool}
     */
    private function getScheduleOffsets(?BillingConfig $config): array
    {
        $dueOffset      = (int) ($config->due_date_day ?? 0);
        $dcAfterDue     = (int) ($config->disconnection_day ?? self::DC_OFFSET_DAYS);
        $noticeAfterDue = (int) ($config->disconnection_notice ?? 0);
        $graceAfterDc   = self::ADDITIONAL_INVOICE_OFFSET_DAYS;
        $pulloutAfterDc = (int) ($config->pullout_day ?? $config->pullout_offset ?? 30);

        return [
            'due_offset'       => $dueOffset,
            'dc_after_due'     => $dcAfterDue,
            'dc_offset'        => $dueOffset + $dcAfterDue,
            'notice_after_due' => $noticeAfterDue,
            'notice_offset'    => $dueOffset + $noticeAfterDue,
            'coverage'         => $dcAfterDue,
            'grace_after_dc'   => $graceAfterDc,
            'grace_offset'     => $dueOffset + $dcAfterDue + $graceAfterDc,
            'pullout_after_dc' => $pulloutAfterDc,
            'pullout_offset'   => $dueOffset + $dcAfterDue + $pulloutAfterDc,
            'dc_enabled'       => $dcAfterDue > 0,
        ];
    }

    /**
     * Write an extra-detailed line that is only emitted when verbose mode is on.
     * Verbose lines are tagged [VERBOSE] and follow the same file + CLI echo path.
     */
    private function writeVerbose(string $message): void
    {
        if (!$this->verbose) {
            return;
        }
        $this->writeLog("[VERBOSE] {$message}");
    }

    /**
     * Echo a single line to stdout (CLI) and flush so output streams live.
     */
    private function echoCli(string $line): void
    {
        if (defined('STDOUT')) {
            @fwrite(STDOUT, $line . PHP_EOL);
        } else {
            echo $line . PHP_EOL;
        }
        @flush();
    }

    /**
     * Human-readable byte formatting for verbose memory logging.
     */
    private function formatBytes($bytes): string
    {
        $bytes = (float) $bytes;
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Acquire lock to prevent concurrent execution using database
     */
    private function acquireLock()
    {
        try {
            // Check if lock exists and is not expired
            $existingLock = DB::table('worker_locks')
                ->where('lock_name', $this->lockName)
                ->first();

            if ($existingLock) {
                $lockedAt = \Carbon\Carbon::parse($existingLock->locked_at);
                $expiresAt = $lockedAt->addSeconds($this->lockTimeout);

                // If lock is still valid (not expired)
                if (Carbon::now()->lessThan($expiresAt)) {
                    $this->writeLog("[LOCK] Lock is held by another process. Expires at: " . $expiresAt->format('Y-m-d H:i:s'));
                    return false;
                }

                // Lock expired, clean it up
                $this->writeLog("[LOCK] Found expired lock. Cleaning up and acquiring new lock.");
                DB::table('worker_locks')
                    ->where('lock_name', $this->lockName)
                    ->delete();
            }

            // Try to acquire lock
            DB::table('worker_locks')->insert([
                'lock_name' => $this->lockName,
                'locked_at' => Carbon::now(),
                'locked_by' => gethostname() . ':' . getmypid(),
                'created_at' => Carbon::now()
            ]);

            $this->hasLock = true;
            $this->writeLog("[LOCK] Lock acquired successfully");
            return true;

        } catch (Exception $e) {
            // Unique constraint violation means another process got the lock first
            $this->writeLog("[LOCK] Failed to acquire lock: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Release lock
     */
    private function releaseLock()
    {
        if ($this->hasLock) {
            try {
                DB::table('worker_locks')
                    ->where('lock_name', $this->lockName)
                    ->delete();
                
                $this->writeLog("[LOCK] Lock released successfully");
                $this->hasLock = false;
            } catch (Exception $e) {
                $this->writeLog("[LOCK] Failed to release lock: " . $e->getMessage());
            }
        }
    }
}

