<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Models\BillingAccount;
use App\Services\PppoeUsernameService;
use App\Services\ManualRadiusOperationsService;
use App\Services\RadiusQueueService;
use App\Models\RadiusConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;

class ServiceOrderApiController extends Controller
{
    /** True when at least one failed RADIUS operation was successfully queued for retry. */
    private bool $radiusQueued = false;

    /** True when a RADIUS operation failed AND the fallback queue insert also failed. */
    private bool $radiusQueueFailed = false;

    /** Tracks step-by-step RADIUS operation progress for frontend loading feedback. */
    private array $radiusSteps = [];

    /**
     * Queue a failed RADIUS operation and track whether the insert succeeded.
     * Wraps RadiusQueueService::queue so the controller can tell the client the
     * operation was safely queued (or, in the rare case the insert fails too, warn).
     */
    private function trackRadiusQueue(array $data): ?int
    {
        $id = RadiusQueueService::queue($data);

        if ($id) {
            $this->radiusQueued = true;
        } else {
            $this->radiusQueueFailed = true;
            Log::channel('radiusrelated')->error('[SERVICE ORDER] RADIUS operation failed AND queue insert failed', [
                'operation'  => $data['operation'] ?? null,
                'account_no' => $data['account_no'] ?? null,
            ]);
        }

        return $id;
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $page = $request->input('page', 1);
            $limit = $request->input('limit', 50); // Default 50 for faster response
            $search = $request->input('search', '');
            // Fast mode variable kept for compatibility but new logic is inherently faster
            $fastMode = $request->input('fast', false);

            $authUser = auth()->user();
            $organizationId = $authUser ? $authUser->organization_id : null;
            $roleId = $authUser ? $authUser->role_id : null;
            $isSuperAdmin = !$authUser || $roleId == 7 || !$organizationId;

            // Base query on service_orders
            $query = DB::table('service_orders as so')
                ->select('so.*', 'so.id as ticket_id');

            if (!$isSuperAdmin && $organizationId) {
                $query->where('so.organization_id', $organizationId);
            }

            $query->orderBy('so.timestamp', 'desc');

            // Apply filters
            if ($request->has('assigned_email')) {
                $query->where('so.assigned_email', $request->input('assigned_email'));

                // Exclude records where support_status is Resolved or Failed — technician should not see these
                $query->whereRaw("LOWER(so.support_status) NOT IN ('resolved', 'failed')");

                // Technician specific filtering rules based on user request:
                // 1. visit status in progress or reschedule -> no date filtering
                // 2. visit status done or failed -> only 1 day
                $query->where(function ($q) {
                    $q->whereIn(DB::raw('LOWER(so.visit_status)'), ['in progress', 'in-progress', 'reschedule', 'scheduled', 'for visit'])
                      ->orWhere(function ($q2) {
                          $q2->whereIn(DB::raw('LOWER(so.visit_status)'), ['done', 'completed', 'failed'])
                             ->whereRaw("DATE(COALESCE(so.updated_at, so.end_time, so.created_at)) >= DATE(DATE_SUB(NOW(), INTERVAL 1 DAY))");
                      })
                      ->orWhereNull('so.visit_status');
                });
            }

            if ($request->has('account_no')) {
                $query->where('so.account_no', 'LIKE', "%" . $request->input('account_no') . "%");
            }

            if ($request->has('support_status')) {
                $query->where('so.support_status', $request->input('support_status'));
            }

            if ($request->has('has_charge')) {
                $query->where('so.service_charge', '>', 0);
            }

            if ($request->has('updated_since')) {
                $query->where('so.updated_at', '>', $request->input('updated_since'));
                // When fetching only updates, we typically want everything since the last sync
                // instead of paged chunks, if limit isn't explicitly set low.
                $limit = $request->input('limit', 1000);
            }

            $userRole = strtolower($request->query('user_role', ''));
            $userEmail = $request->query('user_email', '');

            if ($userRole === 'agent' && $userEmail) {
                $user = DB::table('users')->where('email_address', $userEmail)->first();
                if ($user) {
                    $agentName = trim($user->first_name . ' ' . ($user->middle_initial ? $user->middle_initial . ' ' : '') . $user->last_name);

                    $query->where(function ($q) use ($agentName) {
                        $q->where('so.referred_by', 'LIKE', '%' . $agentName . '%');
                    });

                    \Log::info('Filtering service orders for agent role using referred_by column', [
                        'agent_name' => $agentName,
                        'agent_email' => $userEmail
                    ]);
                }
            }

            // Handle Search
            if ($search) {
                // Only join when strictly necessary for search
                $query->leftJoin('billing_accounts as ba', 'so.account_no', '=', 'ba.account_no')
                    ->leftJoin('customers as c', 'ba.customer_id', '=', 'c.id');

                $query->where(function ($q) use ($search) {
                    $q->where('so.account_no', 'LIKE', "%{$search}%")
                        ->orWhere('so.id', 'LIKE', "%{$search}%")
                        ->orWhere('c.first_name', 'LIKE', "%{$search}%")
                        ->orWhere('c.last_name', 'LIKE', "%{$search}%");
                });
            }

            // Get total count of filtered records before pagination
            $totalCount = $query->count();

            // Fetch one extra record to check if there are more pages
            $serviceOrders = $query->skip(($page - 1) * $limit)
                ->take($limit)
                ->get();

            // Check if there are more pages
            $hasMore = ($page * $limit) < $totalCount;

            // Extract Account Numbers for eager loading
            $accountNos = $serviceOrders->pluck('account_no')->filter()->unique()->values();

            // Eager load related data efficiently
            if ($accountNos->isNotEmpty()) {
                $billingAccounts = \App\Models\BillingAccount::with('customer')
                    ->whereIn('account_no', $accountNos)
                    ->get()
                    ->keyBy('account_no');

                $technicalDetails = \App\Models\TechnicalDetail::whereIn('account_no', $accountNos)
                    ->get()
                    ->keyBy('account_no');
            }
            else {
                $billingAccounts = collect();
                $technicalDetails = collect();
            }

            // Map related data to service orders
            $mappedOrders = $serviceOrders->map(function ($so) use ($billingAccounts, $technicalDetails) {
                $ba = $billingAccounts->get($so->account_no);
                $c = $ba ? $ba->customer : null;
                $td = $technicalDetails->get($so->account_no);

                // Manually populate fields that were previously joined
                $so->account_id = $ba ? $ba->id : null;
                $so->date_installed = $ba ? $ba->date_installed : null;

                // Customer details
                $so->full_name = $c ? trim(($c->first_name ?? '') . ' ' . ($c->middle_initial ?? '') . ' ' . ($c->last_name ?? '')) : null;
                $so->contact_number = $c ? $c->contact_number_primary : null;
                $so->full_address = $c ? trim(($c->address ?? '') . ', ' . ($c->barangay ?? '') . ', ' . ($c->city ?? '') . ', ' . ($c->region ?? '')) : null;
                $so->contact_address = $c ? $c->address : null;
                $so->email_address = $c ? $c->email_address : null;
                $so->house_front_picture_url = $c ? $c->house_front_picture_url : null;
                $so->plan = $c ? $c->desired_plan : null;

                // Technical details
                $so->username = $td ? $td->username : null;
                $so->connection_type = $td ? $td->connection_type : null;
                $so->router_modem_sn = $td ? $td->router_modem_sn : null;
                $so->lcp = $td ? $td->lcp : null;
                $so->nap = $td ? $td->nap : null;
                $so->port = $td ? $td->port : null;
                $so->vlan = $td ? $td->vlan : null;
                $so->technicians = isset($so->technicians) ? json_decode($so->technicians) : null;

                return $so;
            });

            return response()->json([
                'success' => true,
                'data' => $mappedOrders->values(),
                'pagination' => [
                    'current_page' => (int)$page,
                    'per_page' => (int)$limit,
                    'has_more' => $hasMore,
                    'count' => $mappedOrders->count(),
                    'total_count' => $totalCount
                ]
            ]);
        }
        catch (\Exception $e) {
            Log::error('Error fetching service orders: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch service orders',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            Log::info('Service order creation request', ['data' => $request->all()]);

            $validated = $request->validate([
                'account_no' => 'required|string|max:255',
                'timestamp' => 'nullable|date',
                'support_status' => 'nullable|string|max:100',
                'concern' => 'required|string|max:255',
                'concern_remarks' => 'nullable|string',
                'priority_level' => 'nullable|string|max:50',
                'requested_by' => 'nullable|string|max:255',
                'assigned_email' => 'nullable|string|max:255',
                'visit_status' => 'nullable|string|max:100',
                'visit_by_user' => 'nullable|string|max:255',
                'visit_with' => 'nullable|string|max:255',
                'visit_remarks' => 'nullable|string',
                'repair_category' => 'nullable|string|max:255',
                'support_remarks' => 'nullable|string',
                'service_charge' => 'nullable|numeric',
                'new_router_modem_sn' => 'nullable|string|max:255',
                'new_lcpnap' => 'nullable|string|max:255',
                'new_plan' => 'nullable|string|max:255',
                'status' => 'nullable|string|max:50',
                'start_time' => 'nullable|date',
                'end_time' => 'nullable|date',
                'created_by_user' => 'nullable|string|max:255',
                'updated_by_user' => 'nullable|string|max:255',
                'proof_image_url' => 'nullable|string|max:255',
                'image4' => 'sometimes|nullable|string|max:255'
            ]);

            // Rate limit and cooldown logic has been removed as per request to allow unlimited ticket submissions.

            $ticketId = $this->generateTicketId();
            Log::info('Generated ticket_id: ' . $ticketId);

            $timestamp = null;
            if (isset($validated['timestamp'])) {
                try {
                    $timestamp = \Carbon\Carbon::parse($validated['timestamp'], 'Asia/Manila')->format('Y-m-d H:i:s');
                }
                catch (\Exception $e) {
                    Log::warning('Invalid timestamp format, using current time', ['timestamp' => $validated['timestamp']]);
                    $timestamp = now('Asia/Manila')->format('Y-m-d H:i:s');
                }

            }
            else {
                $timestamp = now('Asia/Manila')->format('Y-m-d H:i:s');
            }

            $data = [
                'ticket_id' => $ticketId,
                'account_no' => $validated['account_no'],
                'timestamp' => $timestamp,
                'support_status' => $validated['support_status'] ?? 'In Progress',
                'concern' => $validated['concern'],
                'concern_remarks' => $validated['concern_remarks'] ?? null,
                'priority_level' => $validated['priority_level'] ?? 'Medium',
                'requested_by' => $validated['requested_by'] ?? null,
                'assigned_email' => $validated['assigned_email'] ?? null,
                'visit_status' => $validated['visit_status'] ?? null,
                'visit_by_user' => $validated['visit_by_user'] ?? null,
                'visit_with' => $validated['visit_with'] ?? null,
                'visit_remarks' => $validated['visit_remarks'] ?? null,
                'repair_category' => $validated['repair_category'] ?? null,
                'support_remarks' => $validated['support_remarks'] ?? null,
                'service_charge' => $validated['service_charge'] ?? null,
                'new_router_modem_sn' => $validated['new_router_modem_sn'] ?? null,
                'new_lcpnap' => $validated['new_lcpnap'] ?? null,
                'new_plan' => $validated['new_plan'] ?? null,
                'status' => $validated['status'] ?? 'unused',
                'start_time' => $validated['start_time'] ?? null,
                'end_time' => $validated['end_time'] ?? null,
                'created_by_user' => $validated['created_by_user'] ?? null,
                'updated_by_user' => $validated['updated_by_user'] ?? null,
                'proof_image_url' => $request->input('proof_image_url'),
                'image4' => $request->input('image4') ?? ($validated['image4'] ?? null),
                'organization_id' => auth()->user() ? auth()->user()->organization_id : null,
                'created_at' => now(),
                'updated_at' => now()
            ];

            Log::info('Insert data: ', $data);

            $id = DB::table('service_orders')->insertGetId($data);

            $serviceOrder = DB::table('service_orders')->where('id', $id)->first();

            Log::info('Service order created successfully', [
                'id' => $id,
                'ticket_id' => $ticketId,
                'inserted_data' => (array)$serviceOrder
            ]);

            // Trigger Reconnection if concern is 'Reconnect'
            $currentConcern = trim($request->input('concern'));
            $supportStatus = strtolower(trim($request->input('support_status') ?? ''));

            \Log::info('Reconnection check (store) debug:', [
                'current_concern' => $currentConcern,
                'request_support_status' => $supportStatus
            ]);

            $reconnectStatus = null;
            if ($currentConcern && strtolower($currentConcern) === 'reconnect' && $supportStatus === 'resolved') {
                $billingAccount = BillingAccount::where('account_no', $validated['account_no'])->first();
                if ($billingAccount) {
                    Log::info('Triggering auto-reconnect for NEW Service Order with Reconnect concern', [
                        'account_no' => $validated['account_no']
                    ]);
                    $serviceOrderUpdatedByUser = $request->input('updated_by_user') ?: ($request->input('updated_by') ?: (Auth::user()->name ?? 'System'));
                    $reconnectStatus = $this->attemptReconnection($billingAccount, $id, $serviceOrderUpdatedByUser);
                }
            }

            if (!empty($data['assigned_email'])) {
                try {
                    $pushService = app(\App\Services\PushNotificationService::class);
                    $pushService->sendToUserByEmail(
                        $data['assigned_email'],
                        'New Service Order Assigned',
                        "You have been assigned to Service Order #{$ticketId}.",
                        [],
                        'SO'
                    );
                } catch (\Exception $pushEx) {
                    Log::error('Failed to send push notification on ServiceOrder store: ' . $pushEx->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Service order created successfully',
                'data' => $serviceOrder,
                'reconnect_status' => $reconnectStatus,
                'radius_queued' => $this->radiusQueued,
                'radius_queue_failed' => $this->radiusQueueFailed
            ], 201);
        }
        catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation error creating service order', [
                'errors' => $e->errors(),
                'input' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        }
        catch (\Exception $e) {
            Log::error('Error creating service order', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create service order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function generateTicketId(): string
    {
        $currentYear = date('Y');

        $lastTicket = DB::selectOne(
            "SELECT ticket_id FROM service_orders WHERE ticket_id LIKE ? ORDER BY ticket_id DESC LIMIT 1",
        [$currentYear . '%']
        );

        if ($lastTicket && $lastTicket->ticket_id) {
            $lastNumber = (int)substr($lastTicket->ticket_id, 4);
            $newNumber = $lastNumber + 1;
        }
        else {
            $newNumber = 1;
        }

        $ticketId = $currentYear . str_pad($newNumber, 6, '0', STR_PAD_LEFT);

        Log::info('Generated ticket ID: ' . $ticketId);

        return $ticketId;
    }

    public function show($id): JsonResponse
    {
        try {
            $authUser = auth()->user();
            $organizationId = $authUser ? $authUser->organization_id : null;
            $roleId = $authUser ? $authUser->role_id : null;
            $isSuperAdmin = !$authUser || $roleId == 7 || !$organizationId;

            $query = DB::table('service_orders as so')
                ->leftJoin('billing_accounts as ba', 'so.account_no', '=', 'ba.account_no')
                ->leftJoin('customers as c', 'ba.customer_id', '=', 'c.id')
                ->leftJoin('technical_details as td', 'so.account_no', '=', 'td.account_no')
                ->select(
                    'so.*',
                    'so.id as ticket_id',
                    'ba.id as account_id',
                    'ba.date_installed',
                    DB::raw("CONCAT(IFNULL(c.first_name, ''), ' ', IFNULL(c.middle_initial, ''), ' ', IFNULL(c.last_name, '')) as full_name"),
                    'c.contact_number_primary as contact_number',
                    DB::raw("CONCAT(IFNULL(c.address, ''), ', ', IFNULL(c.barangay, ''), ', ', IFNULL(c.city, ''), ', ', IFNULL(c.region, '')) as full_address"),
                    'c.address as contact_address',
                    'c.email_address',
                    'c.house_front_picture_url',
                    'c.desired_plan as plan',
                    'td.username',
                    'td.connection_type',
                    'td.router_modem_sn',
                    'td.lcp',
                    'td.nap',
                    'td.port',
                    'td.vlan'
                )
                ->where('so.id', $id);

            if (!$isSuperAdmin && $organizationId) {
                $query->where('so.organization_id', $organizationId);
            }

            $serviceOrder = $query->first();

            if (!$serviceOrder) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service order not found or unauthorized access'
                ], 404);
            }

            if (!$serviceOrder) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service order not found'
                ], 404);
            }

            if ($serviceOrder) {
                $serviceOrder->technicians = isset($serviceOrder->technicians) ? json_decode($serviceOrder->technicians) : null;
            }

            return response()->json([
                'success' => true,
                'data' => $serviceOrder
            ]);
        }
        catch (\Exception $e) {
            Log::error('Error fetching service order details: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch service order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            Log::info('Service order update request', [
                'id' => $id,
                'data' => $request->all()
            ]);

            $authUser = auth()->user();
            $organizationId = $authUser ? $authUser->organization_id : null;
            $roleId = $authUser ? $authUser->role_id : null;
            $isSuperAdmin = !$authUser || $roleId == 7 || !$organizationId;

            $serviceOrder = DB::table('service_orders')->where('id', $id)->first();

            if (!$serviceOrder) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service order not found'
                ], 404);
            }

            if (!$isSuperAdmin && $organizationId && $serviceOrder->organization_id !== $organizationId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to service order'
                ], 403);
            }

            $updatedByUser = $request->input('updated_by_user') ?: ($request->input('updated_by') ?: (Auth::user()->name ?? 'System'));

            $accountRef = $serviceOrder->account_no;
            // Fetch old records for change logging
            $oldCustomer = DB::selectOne("SELECT * FROM customers WHERE account_no = ?", [$accountRef]);
            $oldBilling = DB::selectOne("SELECT * FROM billing_accounts WHERE account_no = ?", [$accountRef]);
            $oldTechnical = DB::selectOne("SELECT * FROM technical_details WHERE account_no = ?", [$accountRef]);

            $allowedFields = [
                'account_no',
                'timestamp',
                'support_status',
                'concern',
                'concern_remarks',
                'priority_level',
                'requested_by',
                'assigned_email',
                'visit_status',
                'visit_by_user',
                'visit_with',
                'visit_with_other',
                'visit_remarks',
                'repair_category',
                'support_remarks',
                'service_charge',
                'new_router_modem_sn',
                'new_lcp',
                'new_nap',
                'new_port',
                'new_vlan',
                'router_model',
                'old_lcp',
                'old_nap',
                'old_port',
                'old_vlan',
                'old_router_modem_sn',
                'old_lcpnap',
                'old_plan',
                'new_lcpnap',
                'new_plan',
                'client_signature_url',
                'image1_url',
                'image2_url',
                'image3_url',
                'image4',
                'proof_image_url',
                'status',
                'start_time',
                'end_time',
                'technicians',
                'updated_by_user',
                'speedtest_image_url',
                'setup_image_url',
                'box_reading_image_url',
                'router_reading_image_url'
            ];

            $data = [];
            foreach ($allowedFields as $field) {
                if ($request->has($field)) {
                    $value = $request->input($field);
                    if ($field === 'technicians' && is_array($value)) {
                        $value = json_encode($value);
                    }
                    $data[$field] = $value;
                }
            }

            $data['updated_at'] = now();

            Log::info('Filtered data for update', ['data' => $data]);

            // Handle technical details update if new values are provided
            $hasNewTechnicalDetails =
                $request->filled('new_lcp') ||
                $request->filled('new_nap') ||
                $request->filled('new_lcpnap') ||
                $request->filled('new_port') ||
                $request->filled('new_vlan') ||
                $request->filled('new_router_modem_sn');

            if ($hasNewTechnicalDetails) {
                Log::info('New technical details detected, updating technical_details table');

                // Get current technical details
                $technicalDetails = DB::table('technical_details')
                    ->where('account_no', $serviceOrder->account_no)
                    ->first();

                if ($technicalDetails) {
                    // Store old values in service_orders
                    $data['old_lcp'] = $technicalDetails->lcp;
                    $data['old_nap'] = $technicalDetails->nap;
                    $data['old_port'] = $technicalDetails->port;
                    $data['old_vlan'] = $technicalDetails->vlan;
                    $data['old_router_modem_sn'] = $technicalDetails->router_modem_sn;
                    $data['old_lcpnap'] = $technicalDetails->lcpnap;

                    // Prepare updates for technical_details
                    $newLcp = $request->input('new_lcp');
                    $newNap = $request->input('new_nap');

                    if ($request->filled('new_lcpnap')) {
                        $lcpnapValue = $request->input('new_lcpnap');
                        $parts = explode(' - ', $lcpnapValue);
                        if (count($parts) === 2) {
                            $newLcp = $parts[0];
                            $newNap = $parts[1];
                        }
                        else {
                            $parts = explode('-', $lcpnapValue);
                            if (count($parts) === 2) {
                                $newLcp = $parts[0];
                                $newNap = $parts[1];
                            }
                        }
                    }

                    if (!$newLcp)
                        $newLcp = $technicalDetails->lcp;
                    if (!$newNap)
                        $newNap = $technicalDetails->nap;

                    $repairCategory = $request->input('repair_category') ?: ($serviceOrder->repair_category ?? '');
                    $isMigration = in_array(strtolower(trim($repairCategory)), ['migrate', 'reactivation']);

                    $newPort = $request->filled('new_port') ? $request->input('new_port') : $technicalDetails->port;
                    $newVlan = $request->filled('new_vlan') ? $request->input('new_vlan') : $technicalDetails->vlan;
                    $newSN = $request->filled('new_router_modem_sn') ? $request->input('new_router_modem_sn') : $technicalDetails->router_modem_sn;

                    // Calculate LCPNAP (LCP + NAP)
                    $newLcpNap = trim(($newLcp ?? '') . ' ' . ($newNap ?? ''), ' ');

                    // Add new values to $data for service_orders
                    $data['new_lcp'] = $newLcp;
                    $data['new_nap'] = $newNap;
                    $data['new_port'] = $newPort;
                    $data['new_vlan'] = $newVlan;
                    $data['new_router_modem_sn'] = $newSN;
                    $data['new_lcpnap'] = $newLcpNap;

                    // Prepare update array for technical_details
                    $techUpdateData = [
                        'lcp' => $newLcp,
                        'nap' => $newNap,
                        'port' => $newPort,
                        'vlan' => $newVlan,
                        'router_modem_sn' => $newSN,
                        'lcpnap' => $newLcpNap,
                        'updated_at' => now(),
                        'updated_by' => $updatedByUser
                    ];

                    // If it's a migration, ensure connection_type is set to Fiber
                    if ($isMigration) {
                        $techUpdateData['connection_type'] = 'Fiber';
                    }

                    // Update technical_details table
                    DB::table('technical_details')
                        ->where('account_no', $serviceOrder->account_no)
                        ->update($techUpdateData);

                    // Also update job_orders table to keep lcpnap/port/vlan in sync
                    $billingAccountForJobOrder = DB::table('billing_accounts')
                        ->where('account_no', $serviceOrder->account_no)
                        ->first();

                    if ($billingAccountForJobOrder) {
                        $jobOrderSyncData = array_filter([
                            'lcpnap' => $newLcpNap ?: null,
                            'port' => $newPort ?: null,
                            'vlan' => $newVlan ?: null,
                            'updated_at' => now(),
                        ], fn($v) => !is_null($v));

                        $joAffected = DB::table('job_orders')
                            ->where('account_id', $billingAccountForJobOrder->id)
                            ->update($jobOrderSyncData);

                        Log::info('[API SERVICE ORDER] Synced job_orders lcpnap/port/vlan for account_id ' . $billingAccountForJobOrder->id, [
                            'rows_affected' => $joAffected,
                            'lcpnap' => $newLcpNap,
                            'port' => $newPort,
                            'vlan' => $newVlan,
                        ]);
                    }
                }
            }

            // Handle plan update if provided
            if ($request->filled('new_plan')) {
                $billingAccount = DB::table('billing_accounts')
                    ->where('account_no', $serviceOrder->account_no)
                    ->first();

                if ($billingAccount) {
                    $oldPlan = DB::table('customers')
                        ->where('id', $billingAccount->customer_id)
                        ->value('desired_plan');

                    $data['old_plan'] = $oldPlan;

                    DB::table('customers')
                        ->where('id', $billingAccount->customer_id)
                        ->update([
                        'desired_plan' => $request->input('new_plan'),
                        'updated_at' => now()
                    ]);
                    Log::info('Updated customer desired_plan to ' . $request->input('new_plan'), [
                        'account_no' => $serviceOrder->account_no,
                        'customer_id' => $billingAccount->customer_id
                    ]);
                }
            }

            $shouldAddServiceCharge = false;
            $statusChanged = false;

            if ($request->has('support_status') && $request->input('support_status') === 'Resolved' && $serviceOrder->support_status !== 'Resolved') {
                $shouldAddServiceCharge = true;
                $statusChanged = true;
                Log::info('Support status changed to Resolved, will add service charge to account balance');
            }

            if ($request->has('visit_status') && $request->input('visit_status') === 'Done' && $serviceOrder->visit_status !== 'Done') {
                $shouldAddServiceCharge = true;
                $statusChanged = true;
                Log::info('Visit status changed to Done, will add service charge to account balance');
            }

            if ($shouldAddServiceCharge && $statusChanged && $request->has('service_charge')) {
                $serviceCharge = floatval($request->input('service_charge'));
                if ($serviceCharge > 0) {
                    $billingAccount = DB::table('billing_accounts')
                        ->where('account_no', $serviceOrder->account_no)
                        ->first();

                    if ($billingAccount) {
                        $currentBalance = floatval($billingAccount->account_balance);
                        $newBalance = $currentBalance + $serviceCharge;

                        DB::table('billing_accounts')
                            ->where('account_no', $serviceOrder->account_no)
                            ->update([
                            'account_balance' => $newBalance,
                            'balance_update_date' => now(),
                            'updated_at' => now()
                        ]);

                        try {
                            event(new \App\Events\CustomerUpdated([
                                'account_no' => $serviceOrder->account_no,
                                'type' => 'customer_updated',
                                'edit_type' => 'billing_details',
                                'title' => 'Customer Updated',
                                'message' => "Customer balance updated for account {$serviceOrder->account_no}",
                                'timestamp' => now()->timestamp,
                                'formatted_date' => now()->format('Y-m-d h:i:s A')
                            ]));
                        } catch (\Exception $e) {
                            Log::warning('Failed to broadcast customer update via Soketi from ServiceOrderApiController', [
                                'account_no' => $serviceOrder->account_no,
                                'error' => $e->getMessage()
                            ]);
                        }

                        $data['status'] = 'used';

                        Log::info("Updated account balance from {$currentBalance} to {$newBalance} (added service charge: {$serviceCharge}). Status changed to 'used'.");
                    }
                    else {
                        Log::warning('Billing account not found for account_no: ' . $serviceOrder->account_no);
                    }
                }
            }

            // ── Technician availability guard ─────────────────────────────────
            // Keep start_time/end_time consistent so a technician is never left
            // flagged as "busy" on a job that has effectively ended.
            //   • Reassigning the order (assigned_email changed) resets both timers
            //     — the new technician starts fresh.
            //   • Once a STARTED job leaves the "In Progress" state (support_status
            //     Failed, or any visit_status other than In Progress) it must carry
            //     an end_time.
            // start_time/end_time are stored as Asia/Manila wall-clock to match the
            // mobile timer, so we stamp Manila time here — bare now() is UTC here.
            $assignedEmailChanged = array_key_exists('assigned_email', $data)
                && (string) $data['assigned_email'] !== (string) ($serviceOrder->assigned_email ?? '');

            if ($assignedEmailChanged) {
                $data['start_time'] = null;
                $data['end_time']   = null;
            } else {
                $effectiveSupport = strtolower(trim((string) ($request->has('support_status')
                    ? $request->input('support_status')
                    : ($serviceOrder->support_status ?? ''))));
                $effectiveVisit = strtolower(trim((string) ($request->has('visit_status')
                    ? $request->input('visit_status')
                    : ($serviceOrder->visit_status ?? ''))));

                $visitInProgress = in_array($effectiveVisit, ['in progress', 'in-progress', 'inprogress'], true);
                $leftInProgress  = ($effectiveSupport === 'failed')
                    || ($effectiveVisit !== '' && !$visitInProgress);

                $startTimePresent = array_key_exists('start_time', $data)
                    ? !empty($data['start_time'])
                    : !empty($serviceOrder->start_time);
                // A payload that mentions end_time (a real timestamp OR an explicit
                // null to (re)open the timer, as the mobile start/restart does) is
                // authoritative — never override it.
                $callerManagesEndTime = array_key_exists('end_time', $data);

                if ($leftInProgress && $startTimePresent && !$callerManagesEndTime && empty($serviceOrder->end_time)) {
                    $data['end_time'] = \Carbon\Carbon::now('Asia/Manila')->format('Y-m-d H:i:s');
                }
            }
            // ──────────────────────────────────────────────────────────────────

            DB::table('service_orders')->where('id', $id)->update($data);

            if (isset($data['assigned_email']) && $data['assigned_email'] !== ($serviceOrder->assigned_email ?? null)) {
                try {
                    $pushService = app(\App\Services\PushNotificationService::class);
                    $pushService->sendToUserByEmail(
                        $data['assigned_email'],
                        'Service Order Assignment Updated',
                        "You have been assigned to Service Order #{$serviceOrder->ticket_id}.",
                        [],
                        'SO'
                    );
                } catch (\Exception $pushEx) {
                    Log::error('Failed to send push notification on ServiceOrder update: ' . $pushEx->getMessage());
                }
            }

            // --- START CHANGE LOGGING ---
            try {
                $newServiceOrder = DB::table('service_orders')->where('id', $id)->first();
                // Resolve billing account for logging (may be null for orphaned service orders)
                $billingAccountForLog = DB::table('billing_accounts')->where('account_no', $serviceOrder->account_no)->first();

                if ($newServiceOrder) {
                    $changedOld = [];
                    $changedNew = [];

                    // We only log fields that were actually in the request and changed
                    foreach ($data as $key => $newValue) {
                        if ($key === 'updated_at') continue;

                        $oldValue = $serviceOrder->$key ?? null;

                        // Compare values (handling potential type differences)
                        if ((string)$oldValue !== (string)$newValue) {
                            $changedOld[$key] = $oldValue;
                            $changedNew[$key] = $newValue;
                        }
                    }

                    if (!empty($changedOld) || !empty($changedNew)) {
                        $logUserId = null;
                        if ($authUser) {
                            $logUserId = $authUser->id;
                        } else {
                            // Fallback: try to find user by email/name if provided in updated_by_user
                            $user = DB::table('users')
                                ->where('email_address', $updatedByUser)
                                ->orWhere('username', $updatedByUser)
                                ->first();
                            if ($user) $logUserId = $user->id;
                        }

                        DB::table('details_update_logs')->insert([
                            'account_id'          => $billingAccountForLog->id ?? null,
                            'old_details'         => json_encode(['type' => 'service_order_details', 'service_order_id' => $id, 'account_no' => $serviceOrder->account_no, 'data' => $changedOld]),
                            'new_details'         => json_encode(['type' => 'service_order_details', 'service_order_id' => $id, 'account_no' => $serviceOrder->account_no, 'data' => $changedNew]),
                            'created_at'          => now(),
                            'created_by_user_id'  => $logUserId,
                            'updated_at'          => now(),
                            'updated_by_user_id'  => $logUserId,
                        ]);
                    }
                }
            } catch (\Exception $logEx) {
                Log::warning('Failed to log service order changes: ' . $logEx->getMessage());
            }
            // --- END CHANGE LOGGING ---

            $updatedByUser = $request->input('updated_by_user') ?: ($request->input('updated_by') ?: (Auth::user()->name ?? 'System'));

            // Trigger Reconnection if concern is 'Reconnect'
            $currentConcern = trim($request->input('concern'));
            if (!$currentConcern && isset($serviceOrder->concern)) {
                $currentConcern = trim($serviceOrder->concern);
            }

            $supportStatus = strtolower(trim($request->input('support_status') ?? ''));
            if (empty($supportStatus) && isset($serviceOrder->support_status)) {
                $supportStatus = strtolower(trim($serviceOrder->support_status));
            }

            \Log::info('Reconnection check debug:', [
                'current_concern' => $currentConcern,
                'request_support_status' => $supportStatus
            ]);

            // Check if triggers were already executed in the original service order
            $originalConcern = trim($serviceOrder->concern ?? '');
            $originalSupportStatus = strtolower(trim($serviceOrder->support_status ?? ''));
            $originalVisitStatus = strtolower(trim($serviceOrder->visit_status ?? ''));
            $originalRepairCategory = strtolower(trim($serviceOrder->repair_category ?? ''));

            $isAlreadyResolvedReconnect = (($originalConcern === 'Reconnect' || $originalConcern === 'Upgrade/Downgrade Plan') && $originalSupportStatus === 'resolved');
            $isAlreadyResolvedRestrict = (($originalConcern === 'Restrict' || $originalConcern === 'Disconnect') && $originalSupportStatus === 'resolved');
            $isAlreadyPulloutDone = ($originalRepairCategory === 'pullout' && $originalVisitStatus === 'done');
            $isAlreadyMigrationDone = (in_array($originalRepairCategory, ['migrate', 'relocate', 'relocate router', 'transfer lcp/nap/port']) && $originalVisitStatus === 'done');

            $reconnectStatus = null;
            $normalizedConcern = $currentConcern ? strtolower(trim($currentConcern)) : '';
            if ($normalizedConcern && ($normalizedConcern === 'reconnect' || $normalizedConcern === 'upgrade/downgrade plan') && $supportStatus === 'resolved' && !$isAlreadyResolvedReconnect) {
                $billingAccount = BillingAccount::where('account_no', $serviceOrder->account_no)->first();
                if ($billingAccount) {
                    \Log::info("Triggering auto-reconnect for Service Order with {$currentConcern} concern", [
                        'account_no' => $serviceOrder->account_no
                    ]);
                    $reconnectStatus = $this->attemptReconnection($billingAccount, $id, $updatedByUser, $organizationId);

                    if ($reconnectStatus === 'success' && $normalizedConcern === 'upgrade/downgrade plan') {
                        try {
                            $oldPlanString = $data['old_plan'] ?? $serviceOrder->old_plan ?? null;
                            $newPlanString = $data['new_plan'] ?? $serviceOrder->new_plan ?? null;

                            $oldPlanName = trim(explode(' - ', (string)$oldPlanString)[0] ?: (string)$oldPlanString);
                            $newPlanName = trim(explode(' - ', (string)$newPlanString)[0] ?: (string)$newPlanString);

                            $oldPlanId = DB::table('plan_list')->where('plan_name', 'LIKE', $oldPlanName)->value('id');
                            $newPlanId = DB::table('plan_list')->where('plan_name', 'LIKE', $newPlanName)->value('id');

                            \App\Models\PlanChangeLog::create([
                                'account_id' => $billingAccount->id,
                                'old_plan_id' => $oldPlanId,
                                'new_plan_id' => $newPlanId,
                                'status' => 'success',
                                'date_changed' => now(),
                                'date_used' => now(),
                                'remarks' => $data['concern_remarks'] ?? $serviceOrder->concern_remarks ?? 'Upgraded/Downgraded via Service Order',
                                'created_by_user' => $updatedByUser,
                                'updated_by_user' => $updatedByUser,
                            ]);
                            \Log::info("PlanChangeLog created successfully for account {$billingAccount->account_no}");
                        }
                        catch (\Exception $e) {
                            \Log::error("Failed to create PlanChangeLog: " . $e->getMessage());
                        }
                    }
                }
            }



            // Trigger Reactivation if concern is 'Reactivate' — set the account back to Active
            // and re-apply the customer's plan in RADIUS (reuses the reconnection routine).
            $reactivateStatus = null;
            $isAlreadyResolvedReactivate = ($originalConcern === 'Reactivate' && $originalSupportStatus === 'resolved');
            if ($normalizedConcern === 'reactivate' && $supportStatus === 'resolved' && !$isAlreadyResolvedReactivate) {
                $billingAccount = BillingAccount::where('account_no', $serviceOrder->account_no)->first();
                if ($billingAccount && (int) $billingAccount->billing_status_id !== 1) {
                    \Log::info('Triggering reactivation for Service Order with Reactivate concern', [
                        'account_no' => $serviceOrder->account_no,
                        'current_billing_status_id' => $billingAccount->billing_status_id
                    ]);
                    // attemptReconnection sets billing_status_id to 1 (Active) and re-applies the plan in RADIUS
                    $reactivateStatus = $this->attemptReconnection($billingAccount, $id, $updatedByUser, $organizationId);

                    // Re-activate the customer's user account (counterpart to pullout, which sets active = 0)
                    try {
                        \App\Models\User::where('username', $serviceOrder->account_no)->update(['active' => 1]);
                        \Log::info('[API SERVICE ORDER REACTIVATE DB] Updated user active status to 1 for Account: ' . $serviceOrder->account_no);
                    } catch (\Exception $e) {
                        \Log::error('[API SERVICE ORDER REACTIVATE DB USER EXCEPTION] ' . $e->getMessage());
                    }
                } else {
                    \Log::info('Reactivate concern skipped — billing account already Active or not found', [
                        'account_no' => $serviceOrder->account_no
                    ]);
                }
            }

            // Trigger Restriction/Disconnection based on concern and support status
            $restrictedStatus = null;
            if ($currentConcern && $supportStatus === 'resolved' && !$isAlreadyResolvedRestrict) {
                $lowerConcern = strtolower($currentConcern);
                if ($lowerConcern === 'restrict' || $lowerConcern === 'disconnect') {
                    $billingAccount = BillingAccount::where('account_no', $serviceOrder->account_no)->first();
                    if ($billingAccount) {
                        \Log::info("Triggering auto-restriction for Service Order with {$currentConcern} concern", [
                            'account_no' => $serviceOrder->account_no
                        ]);
                        $restrictedStatus = $this->attemptRestriction($billingAccount, $updatedByUser, $organizationId);
                    }
                }
            }



            // Trigger Pullout if repair category is 'Pullout' and visit status is 'Done'
            $pulloutStatus = null;

            $visitStatus = strtolower(trim($request->input('visit_status') ?? ''));
            if (empty($visitStatus) && isset($serviceOrder->visit_status)) {
                $visitStatus = strtolower(trim($serviceOrder->visit_status));
            }

            $repairCategory = strtolower(trim($request->input('repair_category') ?? ''));
            if (empty($repairCategory) && isset($serviceOrder->repair_category)) {
                $repairCategory = strtolower(trim($serviceOrder->repair_category));
            }

            if ($repairCategory === 'pullout' && $visitStatus === 'done' && !$isAlreadyPulloutDone) {
                $billingAccount = BillingAccount::where('account_no', $serviceOrder->account_no)->first();
                if ($billingAccount) {
                    \Log::info('Triggering auto-pullout for Service Order with Pullout repair category', [
                        'account_no' => $serviceOrder->account_no
                    ]);
                    $pulloutStatus = $this->attemptPullout($billingAccount, $updatedByUser, $organizationId);
                }
            }

            // Trigger Migration if repair category is 'Migrate', 'Relocate', or 'Transfer LCP/NAP/PORT' and visit status is 'Done'
            $migrationStatus = null;
            $relocateCategories = ['migrate', 'relocate', 'relocate router', 'transfer lcp/nap/port'];
            if (in_array($repairCategory, $relocateCategories) && $visitStatus === 'done' && !$isAlreadyMigrationDone) {
                $billingAccount = BillingAccount::where('account_no', $serviceOrder->account_no)->first();
                if ($billingAccount) {
                    \Log::info('Triggering auto-migration for Service Order', [
                        'account_no' => $serviceOrder->account_no
                    ]);
                    $migrationStatus = $this->attemptMigration($billingAccount, $repairCategory, $updatedByUser, $organizationId);

                    // Update job_orders table with new LCPNAP, port, and vlan for relocation categories
                    $newLcpnap = $request->input('new_lcpnap');
                    $newPort = $request->input('new_port');
                    $newVlan = $request->input('new_vlan');

                    if ($newLcpnap || $newPort || $newVlan) {
                        $jobOrderUpdateData = array_filter([
                            'lcpnap' => $newLcpnap ?: null,
                            'port' => $newPort ?: null,
                            'vlan' => $newVlan ?: null,
                            'updated_at' => now(),
                        ], fn($v) => !is_null($v));

                        $affected = DB::table('job_orders')
                            ->where('account_id', $billingAccount->id)
                            ->update($jobOrderUpdateData);

                        \Log::info('[API SERVICE ORDER RELOCATE] Updated job_orders for account_id ' . $billingAccount->id, [
                            'rows_affected' => $affected,
                            'new_lcpnap' => $newLcpnap,
                            'new_port' => $newPort,
                            'new_vlan' => $newVlan,
                        ]);
                    }
                }
            }

            $updatedServiceOrder = DB::table('service_orders')->where('id', $id)->first();

            // Compare and log changes to customers, billing_accounts, and technical_details
            $newCustomer = DB::selectOne("SELECT * FROM customers WHERE account_no = ?", [$accountRef]);
            $newBilling = DB::selectOne("SELECT * FROM billing_accounts WHERE account_no = ?", [$accountRef]);
            $newTechnical = DB::selectOne("SELECT * FROM technical_details WHERE account_no = ?", [$accountRef]);

            $oldDataToLog = [];
            $newDataToLog = [];

            $tablesToCompare = [
                ['name' => 'customers', 'old' => $oldCustomer, 'new' => $newCustomer],
                ['name' => 'billing_accounts', 'old' => $oldBilling, 'new' => $newBilling],
                ['name' => 'technical_details', 'old' => $oldTechnical, 'new' => $newTechnical],
            ];

            foreach ($tablesToCompare as $table) {
                if ($table['old'] && $table['new']) {
                    foreach ((array)$table['old'] as $key => $oldVal) {
                        // Skip internal and timestamp fields
                        if (in_array($key, ['id', 'created_at', 'updated_at', 'balance_update_date'])) continue;
                        
                        $newVal = $table['new']->$key ?? null;
                        if ($oldVal != $newVal) {
                            $oldDataToLog[$table['name']][$key] = $oldVal;
                            $newDataToLog[$table['name']][$key] = $newVal;
                        }
                    }
                }
            }

            if (!empty($oldDataToLog)) {
                $currentUserId = Auth::id() ?? null;
                // Resolve account_id from billing account for this log entry
                $logAccountId = $oldBilling->id ?? ($newBilling->id ?? null);
                DB::table('details_update_logs')->insert([
                    'account_id'         => $logAccountId,
                    'old_details'        => json_encode(['type' => 'service_order_related_tables', 'service_order_id' => $id, 'account_no' => $accountRef, 'data' => $oldDataToLog]),
                    'new_details'        => json_encode(['type' => 'service_order_related_tables', 'service_order_id' => $id, 'account_no' => $accountRef, 'data' => $newDataToLog]),
                    'created_at'         => now(),
                    'updated_at'         => now(),
                    'created_by_user_id' => $currentUserId,
                    'updated_by_user_id' => $currentUserId,
                ]);
                Log::info("Logged details update (API) for account_no: {$accountRef}");
            }

            return response()->json([
                'success' => true,
                'message' => 'Service order updated successfully',
                'data' => $updatedServiceOrder,
                'reconnect_status' => $reconnectStatus,
                'reactivate_status' => $reactivateStatus,
                'restricted_status' => $restrictedStatus,
                'pullout_status' => $pulloutStatus,
                'migration_status' => $migrationStatus,
                'radius_queued' => $this->radiusQueued,
                'radius_queue_failed' => $this->radiusQueueFailed,
                'radius_steps' => $this->radiusSteps
            ]);
        }
        catch (\Exception $e) {
            Log::error('Failed to update service order', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $errorMessage = $e->getMessage();

            // Precise mapping for technicians
            if (str_contains($errorMessage, 'Failed to connect to RADIUS server') || 
                str_contains($errorMessage, 'Connection refused') || 
                str_contains($errorMessage, 'cURL error 7')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Radius Offline',
                    'error' => $errorMessage
                ], 400);
            }

            if (str_contains($errorMessage, 'HTTP 400') && (str_contains($errorMessage, 'already exists') || str_contains($errorMessage, 'Duplicate') || str_contains($errorMessage, 'exists'))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Radius Duplicate',
                    'error' => $errorMessage
                ], 400);
            }

            if (str_contains($errorMessage, 'Duplicate entry') && str_contains($errorMessage, 'technical_details')) {
                return response()->json([
                    'success' => false,
                    'message' => 'it has a duplicate on onboarded customer',
                    'error' => $errorMessage
                ], 409);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to update service order',
                'error' => $errorMessage
            ], 500);
        }
    }

    /**
     * Record a details_update_logs entry when a blocked technician reassignment is attempted.
     *
     * Fired by the front-end Service Order Edit modal when an admin tries to reassign the
     * technician after the job has already been started (start_time is set).
     */
    public function logBlockedTransfer(Request $request, $id): JsonResponse
    {
        try {
            $performedBy = $request->input('performed_by')
                ?? optional($request->user())->email_address
                ?? optional($request->user())->email
                ?? 'System';

            $originalTechName = $request->input('original_technician_name');
            $newTechName      = $request->input('new_technician_name');
            $startTime        = $request->input('start_time');

            // Resolve account context for the log entry
            $serviceOrder = DB::table('service_orders')->where('id', $id)->first();
            $accountNo = $serviceOrder->account_no ?? $request->input('account_no');
            $billingAccount = $accountNo
                ? DB::table('billing_accounts')->where('account_no', $accountNo)->first()
                : null;

            $logUserId = optional($request->user())->id;
            if (!$logUserId && $performedBy) {
                $user = DB::table('users')
                    ->where('email_address', $performedBy)
                    ->orWhere('username', $performedBy)
                    ->first();
                if ($user) $logUserId = $user->id;
            }

            $description = $request->input('description')
                ?? "Save blocked — Technician reassignment attempted on Service Order #{$id} by {$performedBy}. "
                 . "The original technician " . ($originalTechName ?: 'Unknown')
                 . " has already started the job (start_time: " . ($startTime ?: 'N/A') . "). Transfer not allowed.";

            $payload = [
                'type'             => 'service_order_technician_block',
                'service_order_id' => $id,
                'reference_id'     => $id,
                'account_no'       => $accountNo,
                'action'           => 'technician_reassignment_blocked',
                'description'      => $description,
                'performed_by'     => $performedBy,
                'start_time'       => $startTime,
                'data'             => [
                    'assigned_technician' => $originalTechName,
                ],
            ];

            $newPayload = $payload;
            $newPayload['data'] = [
                'assigned_technician' => $newTechName,
            ];

            DB::table('details_update_logs')->insert([
                'account_id'         => $billingAccount->id ?? null,
                'old_details'        => json_encode(array_merge($payload, ['old_value' => $originalTechName, 'new_value' => $newTechName])),
                'new_details'        => json_encode(array_merge($newPayload, ['old_value' => $originalTechName, 'new_value' => $newTechName])),
                'created_at'         => now(),
                'updated_at'         => now(),
                'created_by_user_id' => $logUserId,
                'updated_by_user_id' => $logUserId,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Blocked transfer logged'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log blocked technician transfer for service order', [
                'service_order_id' => $id,
                'error'            => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to log blocked transfer',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $serviceOrder = DB::table('service_orders')->where('id', $id)->first();

            if (!$serviceOrder) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service order not found'
                ], 404);
            }

            DB::table('service_orders')->where('id', $id)->delete();

            return response()->json([
                'success' => true,
                'message' => 'Service order deleted successfully'
            ]);
        }
        catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete service order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function attemptReconnection($billingAccount, $serviceOrderId = null, $updatedByUser = 'System', ?int $organizationId = null): string
    {
        try {
            // Reload billing account
            $billingAccount = BillingAccount::find($billingAccount->id);
            $accountNo = $billingAccount->account_no;

            \Log::info('[API SERVICE ORDER RECONNECT] Force starting for account: ' . $accountNo);

            // Step 2: Get account details (PPPoE Username and Plan)
            $accountInfo = DB::table('billing_accounts')
                ->leftJoin('customers', 'billing_accounts.customer_id', '=', 'customers.id')
                ->leftJoin('technical_details', 'billing_accounts.id', '=', 'technical_details.account_id')
                ->where('billing_accounts.id', $billingAccount->id)
                ->select('technical_details.username as pppoe_username', 'customers.desired_plan')
                ->first();

            $username = $accountInfo->pppoe_username ?? null;
            $plan = $accountInfo->desired_plan ?? null;

            // If there's a new_plan in the service order (e.g. Upgrade/Downgrade), use that instead
            if ($serviceOrderId) {
                $soPlan = DB::table('service_orders')->where('id', $serviceOrderId)->value('new_plan');
                if ($soPlan && trim($soPlan) !== '') {
                    $plan = $soPlan;
                    \Log::info('[API SERVICE ORDER RECONNECT] Using new_plan from Service Order: ' . $plan);
                }
            }

            if (empty($username)) {
                \Log::info('[API SERVICE ORDER RECONNECT SKIP] No PPPoE username found for ' . $accountNo);
                return 'no_username';
            }

            if (empty($plan)) {
                \Log::info('[API SERVICE ORDER RECONNECT SKIP] No plan found for ' . $accountNo);
                return 'no_plan';
            }

            // Step 3: Trigger RADIUS Reconnection (retry 3 times, then queue)
            $radiusParams = [
                'accountNumber' => $accountNo,
                'username' => $username,
                'plan' => $plan,
                'serviceOrderId' => $serviceOrderId,
                'remarks' => 'Reconnected via Service Order API',
                'updatedBy' => $updatedByUser
            ];
            $radiusSuccess = false;
            $lastRadiusError = '';
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $this->radiusSteps[] = ['step' => 'attempt_' . $attempt, 'operation' => 'reconnect', 'status' => 'trying'];
                try {
                    $manualRadiusService = app(\App\Services\ManualRadiusOperationsService::class);
                    $radiusResult = $manualRadiusService->reconnectUser($radiusParams);
                    if (($radiusResult['status'] ?? '') === 'success') {
                        $radiusSuccess = true;
                        $this->radiusSteps[count($this->radiusSteps) - 1]['status'] = 'success';
                        \Log::channel('radiusrelated')->info("[API SERVICE ORDER RECONNECT RADIUS] Success on attempt {$attempt}");
                        break;
                    }
                    $lastRadiusError = $radiusResult['message'] ?? 'Operation returned failure';
                    $this->radiusSteps[count($this->radiusSteps) - 1]['status'] = 'failed';
                    \Log::channel('radiusrelated')->warning("[API SERVICE ORDER RECONNECT RADIUS] Attempt {$attempt}/2 failed: {$lastRadiusError}");
                } catch (\Exception $radEx) {
                    $lastRadiusError = $radEx->getMessage();
                    $this->radiusSteps[count($this->radiusSteps) - 1]['status'] = 'failed';
                    \Log::channel('radiusrelated')->warning("[API SERVICE ORDER RECONNECT RADIUS] Attempt {$attempt}/2 exception: {$lastRadiusError}");
                }
                if ($attempt < 2) sleep(1);
            }
            if (!$radiusSuccess) {
                \Log::channel('radiusrelated')->error('[API SERVICE ORDER RECONNECT RADIUS] All attempts failed. Queuing for retry.');
                $this->radiusSteps[] = ['step' => 'queued', 'operation' => 'reconnect', 'status' => 'trying'];
                $this->trackRadiusQueue([
                    'organization_id' => $organizationId ?? null,
                    'source_type' => 'service_order',
                    'source_id' => $serviceOrderId ?? 0,
                    'account_no' => $accountNo,
                    'operation' => 'reconnect_user',
                    'params' => $radiusParams,
                    'last_error' => $lastRadiusError,
                    'created_by' => $updatedByUser,
                ]);
                $this->radiusSteps[count($this->radiusSteps) - 1]['status'] = $this->radiusQueued ? 'success' : 'failed';
            }

            \Log::info('[API SERVICE ORDER RECONNECT PROCEED] Reconnecting user for account: ' . $accountNo);

            $isAlreadyActive = ($billingAccount->billing_status_id == 1);

            // Step 4: Update billing_status_id to 1 (Active) BEFORE reconnecting
            $billingAccount->billing_status_id = 1;
            $billingAccount->updated_at = now();
            $billingAccount->updated_by = Auth::id();
            $billingAccount->save();

            \Log::info('[API SERVICE ORDER RECONNECT DB] Updated billing_status_id to 1 for Account: ' . $accountNo);

            \Log::info('[API SERVICE ORDER RECONNECT SUCCESS] Reconnection (Local Status) completed successfully');

            // Fetch customer details for notifications
            $customerInfo = DB::table('billing_accounts')
                ->join('customers', 'billing_accounts.customer_id', '=', 'customers.id')
                ->where('billing_accounts.account_no', $accountNo)
                ->select(
                    'customers.contact_number_primary',
                    'customers.email_address',
                    'customers.desired_plan as plan_name',
                    DB::raw("CONCAT(customers.first_name, ' ', IFNULL(customers.middle_initial, ''), ' ', customers.last_name) as full_name")
                )
                ->first();

            // Send SMS Notification
            if (!$isAlreadyActive) {
                try {
                    $smsTemplate = DB::table('sms_templates')
                        ->where('template_type', 'Reconnect')
                        ->where('is_active', 1)
                        ->first();

                    if ($smsTemplate && $customerInfo && !empty($customerInfo->contact_number_primary)) {
                        $message = $smsTemplate->message_content;
                        $planNameFormatted = str_replace('₱', 'P', $customerInfo->plan_name ?? '');
                        $customerName = preg_replace('/\s+/', ' ', trim($customerInfo->full_name));
                        $message = str_replace('{{customer_name}}', $customerName, $message);
                        $message = str_replace('{{account_no}}', $accountNo, $message);
                        $message = str_replace('{{plan_name}}', $planNameFormatted, $message);
                        $message = str_replace('{{plan_nam}}', $planNameFormatted, $message);

                        $smsService = new \App\Services\ItexmoSmsService();
                        $smsResult = $smsService->send([
                            'contact_no' => $customerInfo->contact_number_primary,
                            'message' => $message
                        ]);

                        if ($smsResult['success']) {
                            \Log::info('[API SERVICE ORDER RECONNECT SMS] SMS sent');
                        }
                    }
                }
                catch (\Exception $e) {
                    \Log::error('[API SERVICE ORDER RECONNECT SMS EXCEPTION] ' . $e->getMessage());
                }
            }

            // Email Notification
            if (!$isAlreadyActive) {
                try {
                    $emailTemplate = \App\Models\EmailTemplate::where('Template_Code', 'RECONNECT')->first();

                    if (!empty($emailTemplate) && $customerInfo && !empty($customerInfo->email_address)) {
                        $emailService = app(\App\Services\EmailQueueService::class);
                        $emailData = [
                            'customer_name' => $customerInfo->full_name,
                            'account_no' => $accountNo,
                            'plan_name' => $customerInfo->plan_name,
                            'recipient_email' => $customerInfo->email_address,
                        ];
                        $emailService->queueFromTemplate('RECONNECT', $emailData);
                        \Log::info('[API SERVICE ORDER RECONNECT EMAIL] Email queued');
                    }
                }
                catch (\Exception $e) {
                    \Log::error('[API SERVICE ORDER RECONNECT EMAIL EXCEPTION] ' . $e->getMessage());
                }
            }

            return 'success';

        }
        catch (\Exception $e) {
            \Log::error('[API SERVICE ORDER RECONNECT EXCEPTION] ' . $e->getMessage());
            return 'exception';
        }
    }

    private function attemptRestriction($billingAccount, $updatedByUser = 'System', ?int $organizationId = null): string
    {
        try {
            // Reload billing account
            $billingAccount = BillingAccount::find($billingAccount->id);
            $accountNo = $billingAccount->account_no;

            \Log::info('[API SERVICE ORDER RESTRICT] Force starting for account: ' . $accountNo);

            // Get account details (PPPoE Username)
            $accountInfo = DB::table('billing_accounts')
                ->leftJoin('technical_details', 'billing_accounts.id', '=', 'technical_details.account_id')
                ->where('billing_accounts.id', $billingAccount->id)
                ->select('technical_details.username as pppoe_username')
                ->first();

            $username = $accountInfo->pppoe_username ?? null;

            if (empty($username)) {
                \Log::info('[API SERVICE ORDER RESTRICT SKIP] No PPPoE username found');
                return 'no_username';
            }

            // Step 2: Trigger RADIUS Restriction (retry 3 times, then queue)
            $radiusRestrictParams = [
                'accountNumber' => $accountNo,
                'username' => $username,
                'remarks' => 'Restricted via Service Order API',
                'updatedBy' => $updatedByUser
            ];
            $radiusSuccess = false;
            $lastRadiusError = '';
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $this->radiusSteps[] = ['step' => 'attempt_' . $attempt, 'operation' => 'restrict', 'status' => 'trying'];
                try {
                    $radiusOps = app(\App\Services\ManualRadiusOperationsService::class);
                    $result = $radiusOps->restrictedUser($radiusRestrictParams);
                    if (($result['status'] ?? '') === 'success') {
                        $radiusSuccess = true;
                        $this->radiusSteps[count($this->radiusSteps) - 1]['status'] = 'success';
                        \Log::channel('radiusrelated')->info("[API SERVICE ORDER RESTRICT RADIUS] Success on attempt {$attempt}");
                        break;
                    }
                    $lastRadiusError = $result['message'] ?? 'Operation returned failure';
                    $this->radiusSteps[count($this->radiusSteps) - 1]['status'] = 'failed';
                    \Log::channel('radiusrelated')->warning("[API SERVICE ORDER RESTRICT RADIUS] Attempt {$attempt}/3 failed: {$lastRadiusError}");
                } catch (\Exception $radEx) {
                    $lastRadiusError = $radEx->getMessage();
                    $this->radiusSteps[count($this->radiusSteps) - 1]['status'] = 'failed';
                    \Log::channel('radiusrelated')->warning("[API SERVICE ORDER RESTRICT RADIUS] Attempt {$attempt}/3 exception: {$lastRadiusError}");
                }
                if ($attempt < 2) sleep(1);
            }
            if (!$radiusSuccess) {
                \Log::channel('radiusrelated')->error('[API SERVICE ORDER RESTRICT RADIUS] All 3 attempts failed. Queuing for retry.');
                $this->radiusSteps[] = ['step' => 'queued', 'operation' => 'restrict', 'status' => 'trying'];
                $this->trackRadiusQueue([
                    'organization_id' => $organizationId ?? null,
                    'source_type' => 'service_order',
                    'source_id' => 0,
                    'account_no' => $accountNo,
                    'operation' => 'restricted_user',
                    'params' => $radiusRestrictParams,
                    'last_error' => $lastRadiusError,
                    'created_by' => $updatedByUser,
                ]);
                $this->radiusSteps[count($this->radiusSteps) - 1]['status'] = $this->radiusQueued ? 'success' : 'failed';
            }

            // Update billing_status_id to 4 (Inactive)
            $statusId = DB::table('billing_status')->where('status_name', 'Inactive')->value('id');
            if (!$statusId) {
                $statusId = 4; // Fallback
            }

            $billingAccount->billing_status_id = $statusId;
            $billingAccount->updated_at = now();
            // Use current user if authenticated
            $billingAccount->updated_by = Auth::id() ?: 1;
            $billingAccount->save();

            \Log::info("[API SERVICE ORDER RESTRICT DB] Updated billing_status_id to {$statusId} (Inactive) for Account: {$accountNo}");

            // Fetch customer info for notifications
            $customerInfo = DB::table('billing_accounts')
                ->join('customers', 'billing_accounts.customer_id', '=', 'customers.id')
                ->where('billing_accounts.account_no', $accountNo)
                ->select(
                    'customers.contact_number_primary',
                    'customers.email_address',
                    'customers.desired_plan as plan_name',
                    DB::raw("CONCAT(customers.first_name, ' ', IFNULL(customers.middle_initial, ''), ' ', customers.last_name) as full_name"),
                    'billing_accounts.account_balance'
                )
                ->first();

            // Send SMS Notification
            try {
                $smsTemplate = DB::table('sms_templates')
                    ->where('template_type', 'Disconnected')
                    ->where('is_active', 1)
                    ->first();

                if ($smsTemplate && $customerInfo && !empty($customerInfo->contact_number_primary)) {
                    $message = $smsTemplate->message_content;
                    $planNameFormatted = str_replace('₱', 'P', $customerInfo->plan_name ?? '');
                    $customerName = preg_replace('/\s+/', ' ', trim($customerInfo->full_name));
                    $message = str_replace('{{customer_name}}', $customerName, $message);
                    $message = str_replace('{{account_no}}', $accountNo, $message);
                    $message = str_replace('{{plan_name}}', $planNameFormatted, $message);
                    $message = str_replace('{{plan_nam}}', $planNameFormatted, $message);
                    $message = str_replace('{{amount_due}}', number_format($customerInfo->account_balance, 2), $message);
                    $message = str_replace('{{balance}}', number_format($customerInfo->account_balance, 2), $message);

                    $smsService = new \App\Services\ItexmoSmsService();
                    $smsResult = $smsService->send([
                        'contact_no' => $customerInfo->contact_number_primary,
                        'message' => $message
                    ]);

                    if ($smsResult['success']) {
                        \Log::info('[API SERVICE ORDER RESTRICT SMS] SMS sent to: ' . $customerInfo->contact_number_primary);
                    } else {
                        \Log::warning('[API SERVICE ORDER RESTRICT SMS] SMS send failed', $smsResult);
                    }
                }
            } catch (\Exception $smsEx) {
                \Log::error('[API SERVICE ORDER RESTRICT SMS EXCEPTION] ' . $smsEx->getMessage());
            }

            // Send Email Notification
            try {
                $emailTemplate = \App\Models\EmailTemplate::where('Template_Code', 'DISCONNECTED')->first();

                if (!empty($emailTemplate) && $customerInfo && !empty($customerInfo->email_address)) {
                    $emailService = app(\App\Services\EmailQueueService::class);
                    $emailData = [
                        'customer_name' => preg_replace('/\s+/', ' ', trim($customerInfo->full_name)),
                        'account_no' => $accountNo,
                        'plan_name' => $customerInfo->plan_name,
                        'amount_due' => number_format($customerInfo->account_balance, 2),
                        'balance' => number_format($customerInfo->account_balance, 2),
                        'recipient_email' => $customerInfo->email_address,
                    ];
                    $emailService->queueFromTemplate('DISCONNECTED', $emailData);
                    \Log::info('[API SERVICE ORDER RESTRICT EMAIL] Email queued for: ' . $customerInfo->email_address);
                }
            } catch (\Exception $emailEx) {
                \Log::error('[API SERVICE ORDER RESTRICT EMAIL EXCEPTION] ' . $emailEx->getMessage());
            }

            return 'success';
        } catch (\Exception $e) {
            \Log::error('[API SERVICE ORDER RESTRICT EXCEPTION] ' . $e->getMessage());
            return 'exception';
        }
    }

    private function attemptDisconnection($billingAccount, $updatedByUser = 'System'): string
    {
        try {
            // Reload billing account
            $billingAccount = BillingAccount::find($billingAccount->id);
            $accountNo = $billingAccount->account_no;

            \Log::info('[API SERVICE ORDER DISCONNECT] Force starting for account: ' . $accountNo);

            // Get account details (PPPoE Username)
            $accountInfo = DB::table('billing_accounts')
                ->leftJoin('customers', 'billing_accounts.customer_id', '=', 'customers.id')
                ->leftJoin('technical_details', 'billing_accounts.id', '=', 'technical_details.account_id')
                ->where('billing_accounts.id', $billingAccount->id)
                ->select('technical_details.username as pppoe_username')
                ->first();

            $username = $accountInfo->pppoe_username ?? null;

            if (empty($username)) {
                \Log::info('[API SERVICE ORDER DISCONNECT SKIP] No PPPoE username found in technical_details');
                return 'no_username';
            }

            \Log::info('[API SERVICE ORDER DISCONNECT PROCEED] Disconnecting user for account: ' . $accountNo);
            // Step 2: Trigger RADIUS Disconnection (retry 3 times, then queue)
            $radiusDcParams = [
                'accountNumber' => $accountNo,
                'username' => $username,
                'remarks' => 'Disconnected via Service Order API',
                'updatedBy' => $updatedByUser
            ];
            $radiusSuccess = false;
            $lastRadiusError = '';
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                try {
                    $radiusOps = app(\App\Services\ManualRadiusOperationsService::class);
                    $result = $radiusOps->disconnectUser($radiusDcParams);
                    if (($result['status'] ?? '') === 'success') {
                        $radiusSuccess = true;
                        \Log::channel('radiusrelated')->info("[API SERVICE ORDER DISCONNECT RADIUS] Success on attempt {$attempt}");
                        break;
                    }
                    $lastRadiusError = $result['message'] ?? 'Operation returned failure';
                    \Log::channel('radiusrelated')->warning("[API SERVICE ORDER DISCONNECT RADIUS] Attempt {$attempt}/3 failed: {$lastRadiusError}");
                } catch (\Exception $radEx) {
                    $lastRadiusError = $radEx->getMessage();
                    \Log::channel('radiusrelated')->warning("[API SERVICE ORDER DISCONNECT RADIUS] Attempt {$attempt}/3 exception: {$lastRadiusError}");
                }
                if ($attempt < 2) sleep(1);
            }
            if (!$radiusSuccess) {
                \Log::channel('radiusrelated')->error('[API SERVICE ORDER DISCONNECT RADIUS] All 3 attempts failed. Queuing for retry.');
                $this->trackRadiusQueue([
                    'source_type' => 'service_order',
                    'source_id' => 0,
                    'account_no' => $accountNo,
                    'operation' => 'disconnect_user',
                    'params' => $radiusDcParams,
                    'last_error' => $lastRadiusError,
                    'created_by' => $updatedByUser,
                ]);
            }

            // Step 3: Update local database status (ID 4 = Disconnected)
            $billingAccount->billing_status_id = 4;
            $billingAccount->updated_at = now();
            $billingAccount->updated_by = Auth::id() ?: 1;
            $billingAccount->save();

            \Log::info('[API SERVICE ORDER DISCONNECT DB] Updated billing_status_id to 4 (Disconnected) for Account: ' . $accountNo);

            // Send SMS Notification
            try {
                $smsTemplate = DB::table('sms_templates')
                    ->where('template_type', 'Disconnected')
                    ->where('is_active', 1)
                    ->first();

                if ($smsTemplate) {
                    $customerInfo = DB::table('billing_accounts')
                        ->join('customers', 'billing_accounts.customer_id', '=', 'customers.id')
                        ->where('billing_accounts.account_no', $accountNo)
                        ->select(
                        'customers.contact_number_primary',
                        'customers.email_address',
                        'customers.desired_plan as plan_name',
                        DB::raw("CONCAT(customers.first_name, ' ', IFNULL(customers.middle_initial, ''), ' ', customers.last_name) as full_name"),
                        'billing_accounts.account_balance'
                    )
                        ->first();

                    if ($customerInfo && !empty($customerInfo->contact_number_primary)) {
                        $message = $smsTemplate->message_content;
                        $planNameFormatted = str_replace('₱', 'P', $customerInfo->plan_name ?? '');
                        $customerName = preg_replace('/\s+/', ' ', trim($customerInfo->full_name));
                        $message = str_replace('{{customer_name}}', $customerName, $message);
                        $message = str_replace('{{account_no}}', $accountNo, $message);
                        $message = str_replace('{{plan_name}}', $planNameFormatted, $message);
                        $message = str_replace('{{plan_nam}}', $planNameFormatted, $message);
                        $message = str_replace('{{amount_due}}', number_format($customerInfo->account_balance, 2), $message);
                        $message = str_replace('{{balance}}', number_format($customerInfo->account_balance, 2), $message);

                        $smsService = new \App\Services\ItexmoSmsService();
                        $smsResult = $smsService->send([
                            'contact_no' => $customerInfo->contact_number_primary,
                            'message' => $message
                        ]);

                        if ($smsResult['success']) {
                            \Log::info('[API SERVICE ORDER DISCONNECT SMS] SMS sent');
                        }
                    }
                }
            }
            catch (\Exception $e) {
                \Log::error('[API SERVICE ORDER DISCONNECT SMS EXCEPTION] ' . $e->getMessage());
            }

            // Send Email Notification
            try {
                $emailTemplate = \App\Models\EmailTemplate::where('Template_Code', 'DISCONNECTED')->first();

                if (!empty($emailTemplate) && !empty($customerInfo->email_address)) {
                    $emailService = app(\App\Services\EmailQueueService::class);
                    $emailData = [
                        'customer_name' => $customerInfo->full_name,
                        'account_no' => $accountNo,
                        'amount_due' => number_format($customerInfo->account_balance, 2),
                        'balance' => number_format($customerInfo->account_balance, 2),
                        'recipient_email' => $customerInfo->email_address,
                    ];
                    $emailService->queueFromTemplate('DISCONNECTED', $emailData);
                    \Log::info('[API SERVICE ORDER DISCONNECT EMAIL] Email queued');
                }
            }
            catch (\Exception $e) {
                \Log::error('[API SERVICE ORDER DISCONNECT EMAIL EXCEPTION] ' . $e->getMessage());
            }

            return 'success';

        }
        catch (\Exception $e) {
            \Log::error('[API SERVICE ORDER DISCONNECT EXCEPTION] ' . $e->getMessage());
            return 'exception';
        }
    }

    private function attemptPullout($billingAccount, $updatedByUser = 'System', ?int $organizationId = null): string
    {
        try {
            // Reload billing account
            $billingAccount = BillingAccount::find($billingAccount->id);
            $accountNo = $billingAccount->account_no;

            \Log::info('[API SERVICE ORDER PULLOUT] Force starting for account: ' . $accountNo);

            // Get account details (PPPoE Username)
            $accountInfo = DB::table('billing_accounts')
                ->leftJoin('customers', 'billing_accounts.customer_id', '=', 'customers.id')
                ->leftJoin('technical_details', 'billing_accounts.id', '=', 'technical_details.account_id')
                ->where('billing_accounts.id', $billingAccount->id)
                ->select('technical_details.username as pppoe_username', 'technical_details.router_modem_sn as router_modem_sn')
                ->first();

            $username = $accountInfo->pppoe_username ?? null;
            $routerModemSn = $accountInfo->router_modem_sn ?? null;

            \Log::info('[API SERVICE ORDER PULLOUT PROCEED] Executing pullout for account: ' . $accountNo);

            if (empty($username)) {
                \Log::info('[API SERVICE ORDER PULLOUT SKIP RADIUS] No PPPoE username found, skipping RADIUS disconnect but proceeding with local DB updates');
            } else {
                // Step 2: Trigger RADIUS Disconnection/Pullout (retry 3 times, then queue)
                $radiusPulloutParams = [
                    'accountNumber' => $accountNo,
                    'username' => $username,
                    'remarks' => 'Pullout',
                    'updatedBy' => $updatedByUser
                ];
                $radiusSuccess = false;
                $lastRadiusError = '';
                for ($attempt = 1; $attempt <= 2; $attempt++) {
                    try {
                        $radiusOps = app(\App\Services\ManualRadiusOperationsService::class);
                        $result = $radiusOps->disconnectUser($radiusPulloutParams);
                        if (($result['status'] ?? '') === 'success') {
                            $radiusSuccess = true;
                            \Log::channel('radiusrelated')->info("[API SERVICE ORDER PULLOUT RADIUS] Success on attempt {$attempt}");
                            break;
                        }
                        $lastRadiusError = $result['message'] ?? 'Operation returned failure';
                        \Log::channel('radiusrelated')->warning("[API SERVICE ORDER PULLOUT RADIUS] Attempt {$attempt}/3 failed: {$lastRadiusError}");
                    } catch (\Exception $radEx) {
                        $lastRadiusError = $radEx->getMessage();
                        \Log::channel('radiusrelated')->warning("[API SERVICE ORDER PULLOUT RADIUS] Attempt {$attempt}/3 exception: {$lastRadiusError}");
                    }
                    if ($attempt < 2) sleep(1);
                }
                if (!$radiusSuccess) {
                    \Log::channel('radiusrelated')->error('[API SERVICE ORDER PULLOUT RADIUS] All 3 attempts failed. Queuing for retry.');
                    $this->trackRadiusQueue([
                        'organization_id' => $organizationId ?? null,
                        'source_type' => 'service_order',
                        'source_id' => 0,
                        'account_no' => $accountNo,
                        'operation' => 'disconnect_user',
                        'params' => $radiusPulloutParams,
                        'last_error' => $lastRadiusError,
                        'created_by' => $updatedByUser,
                    ]);
                }
                \Log::info('[API SERVICE ORDER PULLOUT SUCCESS] Disconnection (Local Status) completed successfully');
            }

            // Update billing_status_id to 5 (Pullout)
            $billingAccount->billing_status_id = 5;
            $billingAccount->updated_at = now();
            $billingAccount->updated_by = Auth::id();
            $billingAccount->save();

            \Log::info('[API SERVICE ORDER PULLOUT DB] Updated billing_status_id to 5 (Pullout) for Account: ' . $accountNo);

            // Clear the ONU name in SmartOLT before wiping the SN from technical_details (best-effort)
            if (!empty($routerModemSn)) {
                $smartOltStatus = app(\App\Services\SmartOltService::class)->clearOnuNameBySn($routerModemSn);
                \Log::info('[API SERVICE ORDER PULLOUT SMARTOLT] Clear ONU name result: ' . $smartOltStatus, [
                    'account_no' => $accountNo,
                    'router_modem_sn' => $routerModemSn,
                ]);
            }

            // Clear technical details
            DB::table('technical_details')
                ->where('account_no', $accountNo)
                ->update([
                'connection_type' => null,
                'router_model' => null,
                'router_modem_sn' => null,
                'ip_address' => null,
                'lcp' => null,
                'nap' => null,
                'port' => null,
                'vlan' => null,
                'lcpnap' => null,
                'usage_type' => null,
                'updated_at' => now()
            ]);

            \Log::info('[API SERVICE ORDER PULLOUT DB] Cleared technical details for Account: ' . $accountNo);

            // Clear port in job_orders table using account_id (referencing billing_accounts id)
            DB::table('job_orders')
                ->where('account_id', $billingAccount->id)
                ->update([
                'port' => null,
                'updated_at' => now()
            ]);

            \Log::info('[API SERVICE ORDER PULLOUT DB] Cleared port in job_orders for Account ID: ' . $billingAccount->id);

            // Send SMS Notification
            try {
                $smsTemplate = DB::table('sms_templates')
                    ->where('template_type', 'Disconnected')
                    ->where('is_active', 1)
                    ->first();

                if ($smsTemplate) {
                    $customerInfo = DB::table('billing_accounts')
                        ->join('customers', 'billing_accounts.customer_id', '=', 'customers.id')
                        ->where('billing_accounts.account_no', $accountNo)
                        ->select(
                        'customers.contact_number_primary',
                        'customers.email_address',
                        'customers.desired_plan as plan_name',
                        DB::raw("CONCAT(customers.first_name, ' ', IFNULL(customers.middle_initial, ''), ' ', customers.last_name) as full_name"),
                        'billing_accounts.account_balance'
                    )
                        ->first();

                    if ($customerInfo && !empty($customerInfo->contact_number_primary)) {
                        $message = $smsTemplate->message_content;
                        $planNameFormatted = str_replace('₱', 'P', $customerInfo->plan_name ?? '');
                        $customerName = preg_replace('/\s+/', ' ', trim($customerInfo->full_name));
                        $message = str_replace('{{customer_name}}', $customerName, $message);
                        $message = str_replace('{{account_no}}', $accountNo, $message);
                        $message = str_replace('{{plan_name}}', $planNameFormatted, $message);
                        $message = str_replace('{{plan_nam}}', $planNameFormatted, $message);
                        $message = str_replace('{{amount_due}}', number_format($customerInfo->account_balance, 2), $message);
                        $message = str_replace('{{balance}}', number_format($customerInfo->account_balance, 2), $message);

                        $smsService = new \App\Services\ItexmoSmsService();
                        $smsResult = $smsService->send([
                            'contact_no' => $customerInfo->contact_number_primary,
                            'message' => $message
                        ]);

                        if ($smsResult['success']) {
                            \Log::info('[API SERVICE ORDER PULLOUT SMS] SMS sent');
                        }
                    }
                }
            }
            catch (\Exception $e) {
                \Log::error('[API SERVICE ORDER PULLOUT SMS EXCEPTION] ' . $e->getMessage());
            }

            // Send Email Notification
            try {
                $emailTemplate = \App\Models\EmailTemplate::where('Template_Code', 'DISCONNECTED')->first();

                if (!empty($emailTemplate) && !empty($customerInfo->email_address)) {
                    $emailService = app(\App\Services\EmailQueueService::class);
                    $emailData = [
                        'customer_name' => $customerInfo->full_name,
                        'account_no' => $accountNo,
                        'amount_due' => number_format($customerInfo->account_balance, 2),
                        'balance' => number_format($customerInfo->account_balance, 2),
                        'recipient_email' => $customerInfo->email_address,
                    ];
                    $emailService->queueFromTemplate('DISCONNECTED', $emailData);
                    \Log::info('[API SERVICE ORDER PULLOUT EMAIL] Email queued');
                }
            }
            catch (\Exception $e) {
                \Log::error('[API SERVICE ORDER PULLOUT EMAIL EXCEPTION] ' . $e->getMessage());
            }

            // Update customer's user account to inactive
            try {
                \App\Models\User::where('username', $accountNo)->update(['active' => 0]);
                \Log::info('[API SERVICE ORDER PULLOUT DB] Updated user active status to 0 for Account: ' . $accountNo);
            } catch (\Exception $e) {
                \Log::error('[API SERVICE ORDER PULLOUT DB USER EXCEPTION] ' . $e->getMessage());
            }

            return 'success';

        }
        catch (\Exception $e) {
            \Log::error('[API SERVICE ORDER PULLOUT EXCEPTION] ' . $e->getMessage());
            return 'exception';
        }
    }

    private function attemptMigration($billingAccount, $repairCategory = null, $updatedByUser = 'System', ?int $organizationId = null): string
    {
        try {
            $accountNo = $billingAccount->account_no;

            \Log::info('[API SERVICE ORDER MIGRATION] Force starting for account: ' . $accountNo);

            // Get data for username generation
            $fullInfo = DB::table('billing_accounts')
                ->join('customers', 'billing_accounts.customer_id', '=', 'customers.id')
                ->leftJoin('technical_details', 'billing_accounts.id', '=', 'technical_details.account_id')
                ->where('billing_accounts.account_no', $accountNo)
                ->select(
                'customers.first_name',
                'customers.middle_initial',
                'customers.last_name',
                'customers.contact_number_primary as mobile_number',
                'customers.desired_plan',
                'technical_details.lcp',
                'technical_details.nap',
                'technical_details.port',
                'technical_details.username as pppoe_username'
            )
                ->first();

            $oldUsername = $fullInfo->pppoe_username ?? null;

            if (empty($oldUsername)) {
                \Log::info('[API SERVICE ORDER MIGRATION SKIP] No PPPoE username found');
                return 'no_username';
            }

            \Log::info('[API SERVICE ORDER MIGRATION] Found old username: ' . $oldUsername);

            // SPECIAL CASE: Transfer LCP/NAP/PORT, Migrate & Reactivation
            $normalizedCategory = $repairCategory ? strtolower(trim($repairCategory)) : '';
            if ($normalizedCategory === 'transfer lcp/nap/port' || $normalizedCategory === 'migrate') {
                \Log::info("[API SERVICE ORDER] Handling {$repairCategory} via updateCredentials (rename in place)");

                // 1. Generate new username (keep existing password)
                $pppoeService = new PppoeUsernameService();
                $customerData = (array)$fullInfo;
                $newUsername = $pppoeService->generateUniqueUsername($customerData);

                \Log::info("[API SERVICE ORDER] Renaming username: '{$oldUsername}' -> '{$newUsername}'");

                // 2. Update Credentials with retry (3 attempts, then queue)
                $credParams = [
                    'accountNumber' => $accountNo,
                    'username' => $oldUsername,
                    'newUsername' => $newUsername,
                    'newPassword' => null,
                    'updatedBy' => $updatedByUser
                ];
                $radiusSuccess = false;
                $lastRadiusError = '';
                for ($attempt = 1; $attempt <= 2; $attempt++) {
                    try {
                        $radiusOps = app(ManualRadiusOperationsService::class);
                        $credResult = $radiusOps->updateCredentials($credParams);
                        if (($credResult['status'] ?? '') === 'success') {
                            $radiusSuccess = true;
                            \Log::info("[API SERVICE ORDER] Username renamed successfully on attempt {$attempt}");
                            break;
                        }
                        $lastRadiusError = $credResult['message'] ?? 'Operation returned failure';
                        \Log::channel('radiusrelated')->warning("[API SERVICE ORDER MIGRATION RADIUS] Attempt {$attempt}/3 failed: {$lastRadiusError}");
                    } catch (\Exception $radEx) {
                        $lastRadiusError = $radEx->getMessage();
                        \Log::channel('radiusrelated')->warning("[API SERVICE ORDER MIGRATION RADIUS] Attempt {$attempt}/3 exception: {$lastRadiusError}");
                    }
                    if ($attempt < 2) sleep(1);
                }
                if ($radiusSuccess) {
                    return 'success';
                }
                \Log::channel('radiusrelated')->error('[API SERVICE ORDER MIGRATION RADIUS] All 3 attempts failed. Queuing for retry.');
                $this->trackRadiusQueue([
                    'organization_id' => $organizationId ?? null,
                    'source_type' => 'service_order',
                    'source_id' => 0,
                    'account_no' => $accountNo,
                    'operation' => 'update_credentials',
                    'params' => $credParams,
                    'last_error' => $lastRadiusError,
                    'created_by' => $updatedByUser,
                ]);
                return 'radius_failed';
            }

            // Generate new username using the same logic as JobOrderController
            $pppoeService = new PppoeUsernameService();
            $customerData = (array)$fullInfo;
            $newUsername = $pppoeService->generateUniqueUsername($customerData);

            \Log::info('[API SERVICE ORDER MIGRATION] Generated new username', [
                'old' => $oldUsername,
                'new' => $newUsername
            ]);

            if ($oldUsername === $newUsername) {
                \Log::info('[API SERVICE ORDER MIGRATION SKIP] Username did not change');
                return 'no_change';
            }

            // RADIUS RENAME LOGIC — same approach as Transfer LCP/NAP/PORT:
            // updateCredentials does disable → kill session → PATCH name → re-enable in place.
            // No delete + recreate — that was wiping the user and breaking the connection.
            $targetCategories = ['relocate', 'relocate router', 'transfer lcp nap vlan'];

            if (in_array($normalizedCategory, $targetCategories)) {
                \Log::info("[API SERVICE ORDER] Handling {$normalizedCategory} via updateCredentials (rename in place)");

                $credParams = [
                    'accountNumber' => $accountNo,
                    'username' => $oldUsername,
                    'newUsername' => $newUsername,
                    'newPassword' => null,
                    'updatedBy' => $updatedByUser
                ];
                $radiusSuccess = false;
                $lastRadiusError = '';
                for ($attempt = 1; $attempt <= 2; $attempt++) {
                    try {
                        $radiusOps = app(ManualRadiusOperationsService::class);
                        $credResult = $radiusOps->updateCredentials($credParams);
                        if (($credResult['status'] ?? '') === 'success') {
                            $radiusSuccess = true;
                            \Log::info("[API SERVICE ORDER MIGRATION] Username renamed on attempt {$attempt}");
                            break;
                        }
                        $lastRadiusError = $credResult['message'] ?? 'Operation returned failure';
                        \Log::channel('radiusrelated')->warning("[API SERVICE ORDER MIGRATION RADIUS] Attempt {$attempt}/3 failed: {$lastRadiusError}");
                    } catch (\Exception $radEx) {
                        $lastRadiusError = $radEx->getMessage();
                        \Log::channel('radiusrelated')->warning("[API SERVICE ORDER MIGRATION RADIUS] Attempt {$attempt}/3 exception: {$lastRadiusError}");
                    }
                    if ($attempt < 2) sleep(1);
                }
                if ($radiusSuccess) {
                    return 'success';
                }
                \Log::channel('radiusrelated')->error('[API SERVICE ORDER MIGRATION RADIUS] All 3 attempts failed. Queuing for retry.');
                $this->trackRadiusQueue([
                    'organization_id' => $organizationId ?? null,
                    'source_type' => 'service_order',
                    'source_id' => 0,
                    'account_no' => $accountNo,
                    'operation' => 'update_credentials',
                    'params' => $credParams,
                    'last_error' => $lastRadiusError,
                    'created_by' => $updatedByUser,
                ]);
                return 'radius_failed';
            }
            else {
                // For other categories, DB-only update (no RADIUS change needed)
                \Log::info('[API SERVICE ORDER MIGRATION PROCEED] Updating database credentials (DB ONLY) for ' . $oldUsername);

                DB::table('technical_details')
                    ->where('account_id', $billingAccount->id)
                    ->update([
                    'username' => $newUsername,
                    'updated_at' => now(),
                    'updated_by' => $updatedByUser
                ]);

                DB::table('job_orders')
                    ->where('account_id', $billingAccount->id)
                    ->update([
                    'pppoe_username' => $newUsername,
                    'username' => $newUsername,
                    'updated_at' => now()
                ]);

                \Log::info('[API SERVICE ORDER MIGRATION SUCCESS] DB Only migration completed');
                return 'success';
            }


        }
        catch (\Exception $e) {
            \Log::error('[API SERVICE ORDER MIGRATION EXCEPTION] ' . $e->getMessage());
            return 'exception';
        }
    }

    public function broadcastViewing(Request $request)
    {
        try {
            $serviceOrderId = $request->input('service_order_id');
            $action = $request->input('action', 'started_viewing');
            $username = auth()->user()->username ?? 'Guest';

            event(new \App\Events\ServiceOrderViewingUpdate($serviceOrderId, $username, $action));

            return response()->json([
                'success' => true,
                'message' => 'Viewing update broadcasted'
            ]);
        } catch (\Throwable $e) {
            \Log::error('[Presence] service order broadcastViewing error: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to broadcast viewing update',
                'error' => $e->getMessage(),
                'type' => get_class($e)
            ], 500);
        }
    }
}