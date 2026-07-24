<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\AutoDisconnectService;
use Carbon\Carbon;

class ProcessAutoDisconnectPullout extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cron:auto-disconnect-pullout 
                            {--dc-only : Process only disconnections}
                            {--pullout-only : Process only pullout requests}
                            {--dry-run : Run without making changes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically disconnect overdue accounts and create pullout requests';

    /**
     * Auto disconnect service instance
     *
     * @var AutoDisconnectService
     */
    protected $autoDisconnectService;

    /**
     * Create a new command instance.
     *
     * @param AutoDisconnectService $autoDisconnectService
     */
    public function __construct(AutoDisconnectService $autoDisconnectService)
    {
        parent::__construct();
        $this->autoDisconnectService = $autoDisconnectService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $startTime = Carbon::now();
        $this->info("╔════════════════════════════════════════════════════════╗");
        $this->info("║   STARTING AUTO DISCONNECT & PULLOUT PROCESS          ║");
        $this->info("╚════════════════════════════════════════════════════════╝");
        $this->info("Start Time: " . $startTime->format('Y-m-d H:i:s'));
        $this->newLine();

        $dcOnly = $this->option('dc-only');
        $pulloutOnly = $this->option('pullout-only');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn("[DRY RUN MODE] No changes will be made");
            $this->newLine();
        }

        try {
            $dcResult = null;
            $pulloutResult = null;

            // Process Auto Disconnection
            if (!$pulloutOnly) {
                $this->info("─────────────────────────────────────────────────────────");
                $this->info("[PROCESS] Processing Auto Disconnection...");
                $this->info("─────────────────────────────────────────────────────────");
                
                $dcResult = $this->autoDisconnectService->processAutoDisconnect();
                
                if ($dcResult['success']) {
                    $this->newLine();
                    $this->info("[SUCCESS] Auto Disconnection Complete:");
                    $this->table(
                        ['Metric', 'Count'],
                        [
                            ['Processed', $dcResult['processed']],
                            ['Skipped', $dcResult['skipped']],
                            ['Duration', $dcResult['duration'] . 's']
                        ]
                    );

                    if (!empty($dcResult['errors'])) {
                        $this->newLine();
                        $this->warn("[WARNING] Errors encountered:");
                        foreach ($dcResult['errors'] as $error) {
                            $this->line("   - " . $error);
                        }
                    }
                } else {
                    $this->error("[FAILED] Auto Disconnection Failed: " . ($dcResult['error'] ?? 'Unknown error'));
                    return 1;
                }

                $this->newLine();
            }

            // Process Auto Pullout
            if (!$dcOnly) {
                $this->info("─────────────────────────────────────────────────────────");
                $this->info("[PROCESS] Processing Auto Pullout...");
                $this->info("─────────────────────────────────────────────────────────");
                
                $pulloutResult = $this->autoDisconnectService->processAutoPullout();
                
                if ($pulloutResult['success']) {
                    $this->newLine();
                    $this->info("[SUCCESS] Auto Pullout Complete:");
                    $this->table(
                        ['Metric', 'Count'],
                        [
                            ['Created', $pulloutResult['created']],
                            ['Skipped', $pulloutResult['skipped']],
                            ['Duration', $pulloutResult['duration'] . 's']
                        ]
                    );

                    if (!empty($pulloutResult['errors'])) {
                        $this->newLine();
                        $this->warn("[WARNING] Errors encountered:");
                        foreach ($pulloutResult['errors'] as $error) {
                            $this->line("   - " . $error);
                        }
                    }
                } else {
                    $this->error("[FAILED] Auto Pullout Failed: " . ($pulloutResult['error'] ?? 'Unknown error'));
                    return 1;
                }
                
                $this->newLine();
            }

            // Final Summary
            $endTime = Carbon::now();
            $totalDuration = $endTime->diffInSeconds($startTime);
            
            $this->info("╔════════════════════════════════════════════════════════╗");
            $this->info("║   PROCESS COMPLETED SUCCESSFULLY                      ║");
            $this->info("╚════════════════════════════════════════════════════════╝");
            $this->info("End Time: " . $endTime->format('Y-m-d H:i:s'));
            $this->info("Total Duration: {$totalDuration} seconds");
            
            if ($dcResult && $pulloutResult) {
                $this->newLine();
                $this->info("[SUMMARY] Overall Results:");
                $this->table(
                    ['Process', 'Success', 'Failed/Skipped'],
                    [
                        ['Disconnections', $dcResult['processed'], $dcResult['skipped']],
                        ['Pullout Requests', $pulloutResult['created'], $pulloutResult['skipped']]
                    ]
                );
            }
            
            $this->newLine();
            return 0;

        } catch (\Exception $e) {
            $this->newLine();
            $this->error("╔════════════════════════════════════════════════════════╗");
            $this->error("║   CRITICAL ERROR                                       ║");
            $this->error("╚════════════════════════════════════════════════════════╝");
            $this->error("Error: " . $e->getMessage());
            $this->error("Trace: " . $e->getTraceAsString());
            $this->newLine();
            return 1;
        }
    }
}

