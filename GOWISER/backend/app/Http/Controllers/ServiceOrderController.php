<?php

namespace App\Http\Controllers;

use App\Models\ServiceOrder;
use App\Models\ServiceOrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\BillingAccount;
use App\Models\ActivityLog;
use App\Services\PppoeUsernameService;
use App\Services\ManualRadiusOperationsService;
use App\Services\RadiusQueueService;
use App\Models\RadiusConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use App\Events\ServiceOrderUpdated;

class ServiceOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $page = $request->input('page', 1);
            $limit = $request->input('limit', 50);
            $search = $request->input('search', '');
            $fastMode = $request->input('fast', false);

            Log::info('ServiceOrderController: Starting to fetch service orders', [
                'page' => $page,
                'limit' => $limit,
                'search' => $search,
                'fast_mode' => $fastMode
            ]);

            $authUser = auth()->user();
            $organizationId = $authUser ? $authUser->organization_id : null;
            $roleId = $authUser ? $authUser->role_id : null;
            $isSuperAdmin = !$authUser || $roleId == 7 || !$organizationId;

            $query = "SELECT * FROM service_orders";
            $params = [];
            $whereClauses = [];

            if (!$isSuperAdmin && $organizationId) {
                $whereClauses[] = "organization_id = ?";
                $params[] = $organizationId;
            }

            if ($request->has('assigned_email')) {
                Log::info('Filtering by assigned_email: ' . $request->assigned_email);
                
                $whereClauses[] = "assigned_email = ?";
                $params[] = $request->assigned_email;

                // Exclude records where support_status is Resolved or Failed — technician should not see these
                $whereClauses[] = "LOWER(support_status) NOT IN ('resolved', 'failed')";

                // Technician specific filtering rules based on user request:
                // 1. visit status in progress or reschedule -> no date filtering
                // 2. visit status done or failed -> only 1 day
                $whereClauses[] = "(
                    LOWER(visit_status) IN ('in progress', 'in-progress', 'reschedule', 'scheduled', 'for visit')
                    OR (LOWER(visit_status) IN ('done', 'completed', 'failed') AND DATE(COALESCE(updated_at, end_time, created_at)) >= DATE(DATE_SUB(NOW(), INTERVAL 1 DAY)))
                    OR visit_status IS NULL
                )";
            }

            // Apply search filter
            if ($search) {
                $whereClauses[] = "(account_no LIKE ? OR assigned_email LIKE ? OR support_status LIKE ?)";
                $searchTerm = "%{$search}%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            if (!empty($whereClauses)) {
                $query .= " WHERE " . implode(' AND ', $whereClauses);
            }

            // Fetch total count for pagination
            $countQuery = str_replace("SELECT *", "SELECT COUNT(*) as total", $query);
            $totalCount = DB::selectOne($countQuery, $params)->total;

            $query .= " ORDER BY created_at DESC";

            // Add pagination with +1 for hasMore check
            $offset = ($page - 1) * $limit;
            $query .= " LIMIT ? OFFSET ?";
            $params[] = $limit + 1;
            $params[] = $offset;

            $serviceOrders = DB::select($query, $params);

            // Check if there are more pages
            $hasMore = count($serviceOrders) > $limit;

            // Remove the extra record if it exists
            if ($hasMore) {
                array_pop($serviceOrders);
            }

            Log::info('ServiceOrderController: Fetched ' . count($serviceOrders) . ' service orders');

            // Fast mode: Return minimal data
            if ($fastMode) {
                $enrichedOrders = [];
                foreach ($serviceOrders as $order) {
                    $customer = DB::selectOne("SELECT * FROM customers WHERE account_no = ?", [$order->account_no]);

                    $enrichedOrders[] = [
                        'id' => $order->id,
                        'ticket_id' => $order->ticket_id ?? $order->id,
                        'timestamp' => $order->timestamp,
                        'account_no' => $order->account_no,
                        'full_name' => $customer ? trim(($customer->first_name ?? '') . ' ' . ($customer->middle_initial ?? '') . ' ' . ($customer->last_name ?? '')) : null,
                        'support_status' => $order->support_status,
                        'assigned_email' => $order->assigned_email,
                        'visit_status' => $order->visit_status,
                        'updated_at' => $order->updated_at,
                        'technicians' => isset($order->technicians) ? json_decode($order->technicians) : null,
                    ];
                }

                return response()->json([
                    'success' => true,
                    'data' => $enrichedOrders,
                    'pagination' => [
                        'current_page' => (int)$page,
                        'per_page' => (int)$limit,
                        'total_count' => (int)$totalCount,
                        'has_more' => $hasMore
                    ]
                ]);
            }

            // Normal mode: Return full data with all relationships
            $enrichedOrders = [];
            foreach ($serviceOrders as $order) {
                $customer = DB::selectOne("SELECT * FROM customers WHERE account_no = ?", [$order->account_no]);
                $billingAccount = DB::selectOne("SELECT * FROM billing_accounts WHERE account_no = ?", [$order->account_no]);
                $technicalDetails = DB::selectOne("SELECT * FROM technical_details WHERE account_no = ?", [$order->account_no]);
                $supportConcern = $order->concern_id ?DB::selectOne("SELECT * FROM support_concern WHERE id = ?", [$order->concern_id]) : null;
                $repairCategory = $order->repair_category_id ?DB::selectOne("SELECT * FROM repair_category WHERE id = ?", [$order->repair_category_id]) : null;
                $createdUser = $order->created_by_user_id ?DB::selectOne("SELECT * FROM users WHERE id = ?", [$order->created_by_user_id]) : null;
                $updatedUser = $order->updated_by_user_id ?DB::selectOne("SELECT * FROM users WHERE id = ?", [$order->updated_by_user_id]) : null;
                $visitUser = $order->visit_by_user_id ?DB::selectOne("SELECT * FROM users WHERE id = ?", [$order->visit_by_user_id]) : null;

                $firstItem = DB::selectOne("SELECT * FROM service_order_items WHERE service_order_id = ? ORDER BY id ASC LIMIT 1", [$order->id]);
                $itemName1 = null;
                if ($firstItem && $firstItem->item_id) {
                    $item = DB::selectOne("SELECT * FROM inventory_items WHERE id = ?", [$firstItem->item_id]);
                    $itemName1 = $item->item_name ?? null;
                }

                $enrichedOrders[] = [
                    'id' => $order->id,
                    'ticket_id' => $order->ticket_id ?? $order->id,
                    'timestamp' => $order->timestamp,
                    'account_no' => $order->account_no,
                    'account_id' => $billingAccount->id ?? null,
                    'full_name' => $customer ? trim(($customer->first_name ?? '') . ' ' . ($customer->middle_initial ?? '') . ' ' . ($customer->last_name ?? '')) : null,
                    'contact_number' => $customer->contact_number_primary ?? null,
                    'full_address' => $customer ? trim(($customer->address ?? '') . ', ' . ($customer->barangay ?? '') . ', ' . ($customer->city ?? '') . ', ' . ($customer->region ?? '')) : null,
                    'contact_address' => $customer->address ?? null,
                    'date_installed' => $billingAccount->date_installed ?? null,
                    'email_address' => $customer->email_address ?? null,
                    'house_front_picture_url' => $customer->house_front_picture_url ?? null,
                    'plan' => $customer->desired_plan ?? null,
                    'group_name' => $customer->group_name ?? null,
                    'username' => $technicalDetails->username ?? null,
                    'connection_type' => $technicalDetails->connection_type ?? null,
                    'router_modem_sn' => $technicalDetails->router_modem_sn ?? null,
                    'lcp' => $technicalDetails->lcp ?? null,
                    'nap' => $technicalDetails->nap ?? null,
                    'port' => $technicalDetails->port ?? null,
                    'vlan' => $technicalDetails->vlan ?? null,
                    'lcpnap' => $technicalDetails->lcpnap ?? null,
                    'item_name_1' => $itemName1,
                    'concern' => $supportConcern->name ?? null,
                    'concern_remarks' => $order->concern_remarks,
                    'requested_by' => $order->requested_by,
                    'support_status' => $order->support_status,
                    'assigned_email' => $order->assigned_email,
                    'repair_category' => $repairCategory->name ?? null,
                    'visit_status' => $order->visit_status,
                    'visit_by' => $order->visit_by ?? null,
                    'visit_with' => $order->visit_with ?? null,
                    'visit_with_other' => $order->visit_with_other ?? null,
                    'priority_level' => $order->priority_level,
                    'visit_by_user' => $visitUser->name ?? null,
                    'visit_remarks' => $order->visit_remarks,
                    'support_remarks' => $order->support_remarks,
                    'service_charge' => $order->service_charge,
                    'new_router_sn' => $order->new_router_sn,
                    'new_lcpnap_id' => $order->new_lcpnap_id,
                    'new_plan_id' => $order->new_plan_id,
                    'client_signature_url' => $order->client_signature_url,
                    'image1_url' => $order->image1_url,
                    'image2_url' => $order->image2_url,
                    'image3_url' => $order->image3_url,
                    'image4' => $order->image4 ?? null,
                    'time_in_image_url' => $order->time_in_image_url ?? null,
                    'modem_setup_image_url' => $order->modem_setup_image_url ?? null,
                    'time_out_image_url' => $order->time_out_image_url ?? null,
                    'speedtest_image_url' => $order->speedtest_image_url ?? null,
                    'setup_image_url' => $order->setup_image_url ?? null,
                    'box_reading_image_url' => $order->box_reading_image_url ?? null,
                    'router_reading_image_url' => $order->router_reading_image_url ?? null,
                    'status' => $order->status ?? 'unused',
                    'start_time' => $order->start_time ?? null,
                    'end_time' => $order->end_time ?? null,
                    'technicians' => isset($order->technicians) ? json_decode($order->technicians) : null,
                    'created_at' => $order->created_at,
                    'created_by_user' => $createdUser->name ?? null,
                    'updated_at' => $order->updated_at,
                    'updated_by_user' => $updatedUser->name ?? null,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $enrichedOrders,
                'pagination' => [
                    'current_page' => (int)$page,
                    'per_page' => (int)$limit,
                    'total_count' => (int)$totalCount,
                    'has_more' => $hasMore
                ]
            ]);
        }
        catch (\Exception $e) {
            Log::error('Error fetching service orders: ' . $e->getMessage());
            Log::error('Trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch service orders',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            Log::info('Creating service order', $request->all());

            $ticketId = $this->generateTicketId();
            Log::info('Generated ticket_id: ' . $ticketId);

            $authUser = auth()->user();
            $organizationId = $authUser ? $authUser->organization_id : null;

            $insertData = [
                'ticket_id' => $ticketId,
                'account_no' => $request->account_no,
                'timestamp' => $request->timestamp ? \Carbon\Carbon::parse($request->timestamp, 'Asia/Manila')->format('Y-m-d H:i:s') : now('Asia/Manila'),
                'support_status' => $request->support_status ?? 'In Progress',
                'concern_id' => null,
                'concern_remarks' => $request->concern_remarks,
                'priority_level' => $request->priority_level,
                'requested_by' => $request->requested_by,
                'visit_status' => $request->visit_status,
                'status' => $request->status ?? 'unused',
                'start_time' => $request->start_time,
                'end_time' => $request->end_time,
                'organization_id' => $organizationId,
                'created_by_user_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
                'setup_image_url' => $request->setup_image_url,
                'router_reading_image_url' => $request->router_reading_image_url,
                'box_reading_image_url' => $request->box_reading_image_url,
                'speedtest_image_url' => $request->speedtest_image_url,
                'assigned_email' => $request->assigned_email,
                'image4' => $request->image4,
            ];

            Log::info('Insert data before concern lookup:', $insertData);

            if ($request->has('concern') && !empty($request->concern)) {
                $supportConcern = DB::selectOne("SELECT id FROM support_concern WHERE name = ?", [$request->concern]);
                if ($supportConcern) {
                    $insertData['concern_id'] = $supportConcern->id;
                }
            }

            if ($request->has('created_by_user') && !empty($request->created_by_user)) {
                $user = DB::selectOne("SELECT id FROM users WHERE email = ?", [$request->created_by_user]);
                if ($user) {
                    $insertData['created_by_user_id'] = $user->id;
                }
            }

            Log::info('Insert data before insertion:', $insertData);
            Log::info('Columns: ' . implode(', ', array_keys($insertData)));
            Log::info('Values: ' . json_encode(array_values($insertData)));

            $columns = implode(', ', array_keys($insertData));
            $placeholders = implode(', ', array_fill(0, count($insertData), '?'));
            $query = "INSERT INTO service_orders ({$columns}) VALUES ({$placeholders})";

            Log::info('SQL Query: ' . $query);

            DB::insert($query, array_values($insertData));
            $serviceOrderId = DB::getPdo()->lastInsertId();

            $insertedOrder = DB::selectOne("SELECT * FROM service_orders WHERE id = ?", [$serviceOrderId]);
            Log::info('Inserted service order:', (array)$insertedOrder);

            Log::info('Service order created successfully', ['id' => $serviceOrderId, 'ticket_id' => $ticketId]);

            $currentConcern = trim($request->input('concern'));
            $supportStatus = strtolower(trim($request->input('support_status')));

            \Log::info('Reconnection check (store) debug:', [
                'current_concern' => $currentConcern,
                'request_support_status' => $supportStatus
            ]);

            $updatedByUser = $request->input('updated_by_user') ?: ($request->input('updated_by') ?: ($request->input('created_by_user') ?: (Auth::user()->name ?? 'System')));

            $reconnectStatus = null;
            if ($currentConcern && strtolower($currentConcern) === 'reconnect' && $supportStatus === 'resolved') {
                $billingAccount = BillingAccount::where('account_no', $request->account_no)->first();
                if ($billingAccount) {
                    Log::info('Triggering auto-reconnect for NEW Service Order with Reconnect concern', [
                        'account_no' => $request->account_no
                    ]);
                    $reconnectStatus = $this->attemptReconnection($billingAccount, $serviceOrderId, $updatedByUser);
                }
            }

            // Create Activity Log
            ActivityLog::log(
                'Service Order Created',
                "New Service Order created for Account #{$request->account_no}. Ticket ID: {$ticketId}",
                'info',
            [
                'resource_type' => 'ServiceOrder',
                'resource_id' => $serviceOrderId,
                'additional_data' => [
                    'ticket_id' => $ticketId,
                    'account_no' => $request->account_no,
                    'concern' => $request->concern,
                    'support_status' => $request->support_status,
                    'requested_by' => $request->requested_by
                ]
            ]
            );

            if (!empty($request->assigned_email)) {
                try {
                    $pushService = app(\App\Services\PushNotificationService::class);
                    $pushService->sendToUserByEmail(
                        $request->assigned_email,
                        'New Service Order Assigned',
                        "You have been assigned to Service Order #{$serviceOrderId} (Ticket: {$ticketId}).",
                        [],
                        'SO'
                    );
                } catch (\Exception $pushEx) {
                    \Log::error('Failed to send push notification on ServiceOrder store: ' . $pushEx->getMessage());
                }
            }

            event(new ServiceOrderUpdated(['action' => 'created', 'service_order_id' => $serviceOrderId, 'ticket_id' => $ticketId]));

            return response()->json([
                'success' => true,
                'message' => 'Service order created successfully',
                'data' => [
                    'id' => $serviceOrderId,
                    'ticket_id' => $ticketId,
                    'reconnect_status' => $reconnectStatus
                ],
            ], 201);
        }
        catch (\Exception $e) {
            Log::error('Error creating service order: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Failed to create service order',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            Log::info("Fetching service order with ID: {$id}");

            $authUser = auth()->user();
            $organizationId = $authUser ? $authUser->organization_id : null;
            $roleId = $authUser ? $authUser->role_id : null;
            $isSuperAdmin = !$authUser || $roleId == 7 || !$organizationId;

            $order = DB::selectOne("SELECT * FROM service_orders WHERE id = ?", [$id]);

            if (!$order) {
                Log::warning("Service order not found: {$id}");
                return response()->json([
                    'success' => false,
                    'message' => 'Service order not found',
                ], 404);
            }

            if (!$isSuperAdmin && $organizationId && $order->organization_id !== $organizationId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to service order.',
                ], 403);
            }

            $customer = DB::selectOne("SELECT * FROM customers WHERE account_no = ?", [$order->account_no]);
            $billingAccount = DB::selectOne("SELECT * FROM billing_accounts WHERE account_no = ?", [$order->account_no]);
            $technicalDetails = DB::selectOne("SELECT * FROM technical_details WHERE account_no = ?", [$order->account_no]);
            $supportConcern = $order->concern_id ?DB::selectOne("SELECT * FROM support_concern WHERE id = ?", [$order->concern_id]) : null;
            $repairCategory = $order->repair_category_id ?DB::selectOne("SELECT * FROM repair_category WHERE id = ?", [$order->repair_category_id]) : null;
            $createdUser = $order->created_by_user_id ?DB::selectOne("SELECT * FROM users WHERE id = ?", [$order->created_by_user_id]) : null;
            $updatedUser = $order->updated_by_user_id ?DB::selectOne("SELECT * FROM users WHERE id = ?", [$order->updated_by_user_id]) : null;
            $visitUser = $order->visit_by_user_id ?DB::selectOne("SELECT * FROM users WHERE id = ?", [$order->visit_by_user_id]) : null;

            $firstItem = DB::selectOne("SELECT * FROM service_order_items WHERE service_order_id = ? ORDER BY id ASC LIMIT 1", [$order->id]);
            $itemName1 = null;
            if ($firstItem && $firstItem->item_id) {
                $item = DB::selectOne("SELECT * FROM inventory_items WHERE id = ?", [$firstItem->item_id]);
                $itemName1 = $item->item_name ?? null;
            }

            $enrichedOrder = [
                'id' => $order->id,
                'ticket_id' => $order->ticket_id ?? $order->id,
                'timestamp' => $order->timestamp,
                'account_no' => $order->account_no,
                'account_id' => $billingAccount->id ?? null,
                'full_name' => $customer ? trim(($customer->first_name ?? '') . ' ' . ($customer->middle_initial ?? '') . ' ' . ($customer->last_name ?? '')) : null,
                'contact_number' => $customer->contact_number_primary ?? null,
                'full_address' => $customer ? trim(($customer->address ?? '') . ', ' . ($customer->barangay ?? '') . ', ' . ($customer->city ?? '') . ', ' . ($customer->region ?? '')) : null,
                'contact_address' => $customer->address ?? null,
                'date_installed' => $billingAccount->date_installed ?? null,
                'email_address' => $customer->email_address ?? null,
                'house_front_picture_url' => $customer->house_front_picture_url ?? null,
                'plan' => $customer->desired_plan ?? null,
                'group_name' => $customer->group_name ?? null,
                'username' => $technicalDetails->username ?? null,
                'connection_type' => $technicalDetails->connection_type ?? null,
                'router_modem_sn' => $technicalDetails->router_modem_sn ?? null,
                'lcp' => $technicalDetails->lcp ?? null,
                'nap' => $technicalDetails->nap ?? null,
                'port' => $technicalDetails->port ?? null,
                'vlan' => $technicalDetails->vlan ?? null,
                'lcpnap' => $technicalDetails->lcpnap ?? null,
                'item_name_1' => $itemName1,
                'concern' => $supportConcern->name ?? null,
                'concern_remarks' => $order->concern_remarks,
                'requested_by' => $order->requested_by,
                'support_status' => $order->support_status,
                'assigned_email' => $order->assigned_email,
                'repair_category' => $repairCategory->name ?? null,
                'visit_status' => $order->visit_status,
                'visit_by' => $order->visit_by ?? null,
                'visit_with' => $order->visit_with ?? null,
                'visit_with_other' => $order->visit_with_other ?? null,
                'priority_level' => $order->priority_level,
                'visit_by_user' => $visitUser->name ?? null,
                'visit_remarks' => $order->visit_remarks,
                'support_remarks' => $order->support_remarks,
                'service_charge' => $order->service_charge,
                'new_router_sn' => $order->new_router_sn,
                'new_lcpnap_id' => $order->new_lcpnap_id,
                'new_plan_id' => $order->new_plan_id,
                'client_signature_url' => $order->client_signature_url,
                'image1_url' => $order->image1_url,
                'image2_url' => $order->image2_url,
                'image3_url' => $order->image3_url,
                'image4' => $order->image4 ?? null,
                'time_in_image_url' => $order->time_in_image_url ?? null,
                'modem_setup_image_url' => $order->modem_setup_image_url ?? null,
                'time_out_image_url' => $order->time_out_image_url ?? null,
                'speedtest_image_url' => $order->speedtest_image_url ?? null,
                'setup_image_url' => $order->setup_image_url ?? null,
                'box_reading_image_url' => $order->box_reading_image_url ?? null,
                'router_reading_image_url' => $order->router_reading_image_url ?? null,
                'status' => $order->status ?? 'unused',
                'start_time' => $order->start_time ?? null,
                'end_time' => $order->end_time ?? null,
                'technicians' => isset($order->technicians) ? json_decode($order->technicians) : null,
                'created_at' => $order->created_at,
                'created_by_user' => $createdUser->name ?? null,
                'updated_at' => $order->updated_at,
                'updated_by_user' => $updatedUser->name ?? null,
            ];

            return response()->json([
                'success' => true,
                'data' => $enrichedOrder,
            ]);
        }
        catch (\Exception $e) {
            Log::error('Error fetching service order: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return response()->json([
                'success' => false,
                'message' => 'Service order not found',
                'error' => $e->getMessage(),
            ], 404);
        }
    }
    public function update(Request $request, $id)
    {
        try {
            Log::info("Updating service order with ID: {$id}");
            Log::info('Update data:', $request->all());

            $authUser = auth()->user();
            $organizationId = $authUser ? $authUser->organization_id : null;
            $roleId = $authUser ? $authUser->role_id : null;
            $isSuperAdmin = !$authUser || $roleId == 7 || !$organizationId;

            $order = DB::selectOne("SELECT * FROM service_orders WHERE id = ?", [$id]);

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service order not found',
                ], 404);
            }

            if (!$isSuperAdmin && $organizationId && $order->organization_id !== $organizationId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You can only update service orders within your organization.',
                ], 403);
            }

            $updatedByUser = $request->input('updated_by_user') ?: ($request->input('updated_by') ?: (Auth::user()->name ?? 'System'));

            $accountRef = $order->account_no;
            // Fetch old records for change logging
            $oldCustomer = DB::selectOne("SELECT * FROM customers WHERE account_no = ?", [$accountRef]);
            $oldBilling = DB::selectOne("SELECT * FROM billing_accounts WHERE account_no = ?", [$accountRef]);
            $oldTechnical = DB::selectOne("SELECT * FROM technical_details WHERE account_no = ?", [$accountRef]);

            $updateData = [];
            $billingUpdateData = [];
            $customerUpdateData = [];
            $technicalUpdateData = [];

            if ($request->has('date_installed')) {
                $billingUpdateData['date_installed'] = $request->date_installed;
            }

            if ($request->has('full_name')) {
                $nameParts = explode(' ', $request->full_name);
                if (count($nameParts) >= 2) {
                    $customerUpdateData['first_name'] = $nameParts[0];
                    $customerUpdateData['last_name'] = end($nameParts);
                    if (count($nameParts) === 3) {
                        $customerUpdateData['middle_initial'] = $nameParts[1];
                    }
                }
            }

            if ($request->has('contact_number')) {
                $customerUpdateData['contact_number_primary'] = $request->contact_number;
            }

            if ($request->has('email_address')) {
                $customerUpdateData['email_address'] = $request->email_address;
            }

            if ($request->has('plan')) {
                $customerUpdateData['desired_plan'] = $request->plan;
            }

            if ($request->has('new_plan')) {
                $billingAccount = DB::table('billing_accounts')
                    ->where('account_no', $order->account_no)
                    ->first();

                if ($billingAccount) {
                    $oldPlan = DB::table('customers')
                        ->where('id', $billingAccount->customer_id)
                        ->value('desired_plan');
                    $updateData['old_plan'] = $oldPlan;
                }

                $customerUpdateData['desired_plan'] = $request->new_plan;
                $updateData['new_plan'] = $request->new_plan;
            }

            // Handle technical details and old/new preservation
            $technicalDetails = DB::table('technical_details')
                ->where('account_no', $order->account_no)
                ->first();

            if ($technicalDetails) {
                // Preservation and update logic
                $hasTechUpdate = $request->filled('new_lcp') || $request->filled('new_nap') ||
                    $request->filled('new_port') || $request->filled('new_vlan') ||
                    $request->filled('new_router_modem_sn') || $request->filled('router_modem_sn') ||
                    $request->filled('lcp') || $request->filled('nap') || $request->filled('port') || $request->filled('vlan');

                if ($hasTechUpdate) {
                    Log::info('Technical details update detected in ServiceOrderController');

                    // Old values
                    $updateData['old_lcp'] = $technicalDetails->lcp;
                    $updateData['old_nap'] = $technicalDetails->nap;
                    $updateData['old_port'] = $technicalDetails->port;
                    $updateData['old_vlan'] = $technicalDetails->vlan;
                    $updateData['old_router_modem_sn'] = $technicalDetails->router_modem_sn;
                    $updateData['old_lcpnap'] = $technicalDetails->lcpnap;

                    // New values (checking both 'new_lcp' and legacy 'lcp' fields)
                    $newLcp = $request->input('new_lcp') ?? $request->input('lcp') ?? $technicalDetails->lcp;
                    $newNap = $request->input('new_nap') ?? $request->input('nap') ?? $technicalDetails->nap;
                    $newPort = $request->input('new_port') ?? $request->input('port') ?? $technicalDetails->port;
                    $newVlan = $request->input('new_vlan') ?? $request->input('vlan') ?? $technicalDetails->vlan;
                    $newSN = $request->input('new_router_modem_sn') ?? $request->input('router_modem_sn') ?? $technicalDetails->router_modem_sn;

                    // Calculate LCPNAP
                    $newLcpNap = trim(($newLcp ?? '') . ' ' . ($newNap ?? ''), ' ');

                    // Store new values in service_orders
                    $updateData['new_lcp'] = $newLcp;
                    $updateData['new_nap'] = $newNap;
                    $updateData['new_port'] = $newPort;
                    $updateData['new_vlan'] = $newVlan;
                    $updateData['new_router_modem_sn'] = $newSN;
                    $updateData['new_lcpnap'] = $newLcpNap;

                    // Sync to technical_details table
                    $technicalUpdateData = [
                        'lcp' => $newLcp,
                        'nap' => $newNap,
                        'port' => $newPort,
                        'vlan' => $newVlan,
                        'router_modem_sn' => $newSN,
                        'lcpnap' => $newLcpNap,
                        'updated_at' => now(),
                        'updated_by' => $updatedByUser
                    ];

                    // Also sync job_orders table lcpnap/port/vlan
                    $billingAccountForJobOrder = DB::table('billing_accounts')
                        ->where('account_no', $order->account_no)
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

                        Log::info('[SERVICE ORDER] Synced job_orders lcpnap/port/vlan for account_id ' . $billingAccountForJobOrder->id, [
                            'rows_affected' => $joAffected,
                            'lcpnap' => $newLcpNap,
                            'port' => $newPort,
                            'vlan' => $newVlan,
                        ]);
                    }
                }
            }

            if ($request->has('username')) {
                $technicalUpdateData['username'] = $request->username;
            }

            if ($request->has('connection_type')) {
                $technicalUpdateData['connection_type'] = $request->connection_type;
            }

            if ($request->has('concern_remarks')) {
                $updateData['concern_remarks'] = $request->concern_remarks;
            }

            if ($request->has('support_status')) {
                $updateData['support_status'] = $request->support_status;
            }

            if ($request->has('assigned_email')) {
                $updateData['assigned_email'] = $request->assigned_email;
            }

            if ($request->has('start_time')) {
                $updateData['start_time'] = $request->start_time;
            }

            if ($request->has('end_time')) {
                $updateData['end_time'] = $request->end_time;
            }
            
            if ($request->has('technicians')) {
                $updateData['technicians'] = is_array($request->technicians) ? json_encode($request->technicians) : $request->technicians;
            }

            if ($request->has('visit_by')) {
                $updateData['visit_by'] = $request->visit_by;
            }

            if ($request->has('visit_with')) {
                $updateData['visit_with'] = $request->visit_with;
            }

            if ($request->has('visit_with_other')) {
                $updateData['visit_with_other'] = $request->visit_with_other;
            }

            if ($request->has('visit_status')) {
                $updateData['visit_status'] = $request->visit_status;
            }

            if ($request->has('visit_remarks')) {
                $updateData['visit_remarks'] = $request->visit_remarks;
            }

            if ($request->has('support_remarks')) {
                $updateData['support_remarks'] = $request->support_remarks;
            }

            if ($request->has('service_charge')) {
                $updateData['service_charge'] = $request->service_charge;
            }

            if ($request->has('image1_url')) {
                $updateData['image1_url'] = $request->image1_url;
            }

            if ($request->has('image2_url')) {
                $updateData['image2_url'] = $request->image2_url;
            }

            if ($request->has('image3_url')) {
                $updateData['image3_url'] = $request->image3_url;
            }

            if ($request->has('image4')) {
                $updateData['image4'] = $request->image4;
            }

            if ($request->has('client_signature_url')) {
                $updateData['client_signature_url'] = $request->client_signature_url;
            }
            
            if ($request->has('speedtest_image_url')) {
                $updateData['speedtest_image_url'] = $request->speedtest_image_url;
            }
            
            if ($request->has('setup_image_url')) {
                $updateData['setup_image_url'] = $request->setup_image_url;
            }
            
            if ($request->has('box_reading_image_url')) {
                $updateData['box_reading_image_url'] = $request->box_reading_image_url;
            }
            
            if ($request->has('router_reading_image_url')) {
                $updateData['router_reading_image_url'] = $request->router_reading_image_url;
            }

            $shouldAddServiceCharge = false;
            $statusChanged = false;

            if ($request->has('support_status') && $request->support_status === 'Resolved' && $order->support_status !== 'Resolved') {
                $shouldAddServiceCharge = true;
                $statusChanged = true;
                Log::info('Support status changed to Resolved, will add service charge to account balance');
            }

            if ($request->has('visit_status') && $request->visit_status === 'Done' && $order->visit_status !== 'Done') {
                $shouldAddServiceCharge = true;
                $statusChanged = true;
                Log::info('Visit status changed to Done, will add service charge to account balance');
            }

            if ($shouldAddServiceCharge && $statusChanged && $request->has('service_charge')) {
                $serviceCharge = floatval($request->service_charge);
                if ($serviceCharge > 0) {
                    $billingAccount = DB::selectOne("SELECT account_balance FROM billing_accounts WHERE account_no = ?", [$order->account_no]);
                    if ($billingAccount) {
                        $currentBalance = floatval($billingAccount->account_balance);
                        $newBalance = $currentBalance + $serviceCharge;
                        DB::update("UPDATE billing_accounts SET account_balance = ?, balance_update_date = ? WHERE account_no = ?",
                        [$newBalance, now(), $order->account_no]);
                        Log::info("Updated account balance from {$currentBalance} to {$newBalance} (added service charge: {$serviceCharge})");
                    }
                }
            }

            if ($request->has('updated_by')) {
                $updateData['updated_by'] = $request->updated_by;
            }

            if ($request->has('updated_by_user')) {
                $updateData['updated_by_user'] = $request->updated_by_user;
            }

            // ── Technician availability guard (mirrors Api\ServiceOrderApiController) ──
            // Reassignment resets both timers; leaving "In Progress" (support Failed,
            // or any visit_status other than In Progress) stamps an end_time on a
            // started job so the technician is not left flagged as busy.
            // start_time/end_time are Asia/Manila wall-clock (matching the mobile
            // timer), so stamp Manila time — bare now() is UTC here.
            $assignedEmailChanged = array_key_exists('assigned_email', $updateData)
                && (string) $updateData['assigned_email'] !== (string) ($order->assigned_email ?? '');

            if ($assignedEmailChanged) {
                $updateData['start_time'] = null;
                $updateData['end_time']   = null;
            } else {
                $effectiveSupport = strtolower(trim((string) ($request->has('support_status')
                    ? $request->input('support_status')
                    : ($order->support_status ?? ''))));
                $effectiveVisit = strtolower(trim((string) ($request->has('visit_status')
                    ? $request->input('visit_status')
                    : ($order->visit_status ?? ''))));

                $visitInProgress = in_array($effectiveVisit, ['in progress', 'in-progress', 'inprogress'], true);
                $leftInProgress  = ($effectiveSupport === 'failed')
                    || ($effectiveVisit !== '' && !$visitInProgress);

                $startTimePresent = array_key_exists('start_time', $updateData)
                    ? !empty($updateData['start_time'])
                    : !empty($order->start_time);
                // A payload that mentions end_time (a real timestamp OR an explicit
                // null to (re)open the timer) is authoritative — never override it.
                $callerManagesEndTime = array_key_exists('end_time', $updateData);

                if ($leftInProgress && $startTimePresent && !$callerManagesEndTime && empty($order->end_time)) {
                    $updateData['end_time'] = \Carbon\Carbon::now('Asia/Manila')->format('Y-m-d H:i:s');
                }
            }
            // ──────────────────────────────────────────────────────────────────────

            $updateData['updated_at'] = now();

            if (!empty($updateData)) {
                $sets = [];
                $params = [];
                foreach ($updateData as $key => $value) {
                    $sets[] = "{$key} = ?";
                    $params[] = $value;
                }
                $params[] = $id;
                $query = "UPDATE service_orders SET " . implode(', ', $sets) . " WHERE id = ?";
                
                Log::info('Executing update on service_orders', [
                    'id' => $id,
                    'query' => $query,
                    'params' => $params
                ]);
                
                DB::update($query, $params);
                Log::info('Updated service_orders table');

                // --- START CHANGE LOGGING ---
                try {
                    $newOrder = DB::selectOne("SELECT * FROM service_orders WHERE id = ?", [$id]);
                    // Resolve billing account for logging (may be null for orphaned service orders)
                    $billingAccountForLog = DB::selectOne("SELECT id FROM billing_accounts WHERE account_no = ?", [$order->account_no]);

                    if ($newOrder) {
                        $changedOld = [];
                        $changedNew = [];

                        // We only log fields that were actually in the updateData and changed
                        foreach ($updateData as $key => $newValue) {
                            if ($key === 'updated_at') continue;

                            $oldValue = $order->$key ?? null;

                            // Compare values
                            if ((string)$oldValue !== (string)$newValue) {
                                $changedOld[$key] = $oldValue;
                                $changedNew[$key] = $newValue;
                            }
                        }

                        if (!empty($changedOld) || !empty($changedNew)) {
                            $logUserId = auth()->id();
                            if (!$logUserId) {
                                // Try to resolve from updated_by_user
                                $user = DB::selectOne("SELECT id FROM users WHERE email = ? OR username = ?", [$updatedByUser, $updatedByUser]);
                                if ($user) $logUserId = $user->id;
                            }

                            DB::table('details_update_logs')->insert([
                                'account_id'         => $billingAccountForLog->id ?? null,
                                'old_details'        => json_encode(['type' => 'service_order_details', 'service_order_id' => $id, 'account_no' => $order->account_no, 'data' => $changedOld]),
                                'new_details'        => json_encode(['type' => 'service_order_details', 'service_order_id' => $id, 'account_no' => $order->account_no, 'data' => $changedNew]),
                                'created_at'         => now(),
                                'created_by_user_id' => $logUserId,
                                'updated_at'         => now(),
                                'updated_by_user_id' => $logUserId,
                            ]);
                        }
                    }
                } catch (\Exception $logEx) {
                    Log::warning('Failed to log service order changes in ServiceOrderController: ' . $logEx->getMessage());
                }
                // --- END CHANGE LOGGING ---
            }

            if (!empty($billingUpdateData)) {
                $billingUpdateData['updated_at'] = now();
                $sets = [];
                $params = [];
                foreach ($billingUpdateData as $key => $value) {
                    $sets[] = "{$key} = ?";
                    $params[] = $value;
                }
                $params[] = $order->account_no;
                $query = "UPDATE billing_accounts SET " . implode(', ', $sets) . " WHERE account_no = ?";
                DB::update($query, $params);
                Log::info('Updated billing_accounts table');
            }

            if (!empty($customerUpdateData)) {
                $customerUpdateData['updated_at'] = now();
                $sets = [];
                $params = [];
                foreach ($customerUpdateData as $key => $value) {
                    $sets[] = "{$key} = ?";
                    $params[] = $value;
                }
                $params[] = $order->account_no;
                $query = "UPDATE customers SET " . implode(', ', $sets) . " WHERE account_no = ?";
                DB::update($query, $params);
                Log::info('Updated customers table');
            }

            if (!empty($technicalUpdateData)) {
                $sets = [];
                $params = [];
                foreach ($technicalUpdateData as $key => $value) {
                    $sets[] = "{$key} = ?";
                    $params[] = $value;
                }
                $params[] = $order->account_no;
                $query = "UPDATE technical_details SET " . implode(', ', $sets) . " WHERE account_no = ?";
                DB::update($query, $params);
                Log::info('Updated technical_details table');
            }

            if ($request->has('item_name_1') && !empty($request->item_name_1)) {
                Log::info('Processing item_name_1: ' . $request->item_name_1);

                $inventoryItem = DB::selectOne("SELECT * FROM inventory_items WHERE item_name = ?", [$request->item_name_1]);

                if ($inventoryItem) {
                    $existingItem = DB::selectOne("SELECT * FROM service_order_items WHERE service_order_id = ? ORDER BY id ASC LIMIT 1", [$id]);

                    if ($existingItem) {
                        DB::update("UPDATE service_order_items SET item_id = ?, quantity = 1 WHERE id = ?", [$inventoryItem->id, $existingItem->id]);
                        Log::info('Updated existing service_order_item');
                    }
                    else {
                        DB::insert("INSERT INTO service_order_items (service_order_id, item_id, quantity) VALUES (?, ?, 1)", [$id, $inventoryItem->id]);
                        Log::info('Created new service_order_item');
                    }
                }
                else {
                    Log::warning('Inventory item not found for: ' . $request->item_name_1);
                }
            }

            // Trigger Reconnection if concern is 'Reconnect'
            $currentConcern = trim($request->input('concern'));
            if (!$currentConcern && isset($order->concern)) {
                $currentConcern = trim($order->concern);
            }

            $supportStatus = strtolower(trim($request->input('support_status') ?? ''));
            if (empty($supportStatus) && isset($order->support_status)) {
                $supportStatus = strtolower(trim($order->support_status));
            }

            \Log::info('Reconnection check debug:', [
                'current_concern' => $currentConcern,
                'request_support_status' => $supportStatus
            ]);

            // Check if triggers were already executed in the original service order
            $originalConcern = trim($order->concern ?? '');
            $originalSupportStatus = strtolower(trim($order->support_status ?? ''));
            $originalVisitStatus = strtolower(trim($order->visit_status ?? ''));
            $originalRepairCategory = strtolower(trim($order->repair_category ?? ''));

            $isAlreadyResolvedReconnect = (($originalConcern === 'Reconnect' || $originalConcern === 'Upgrade/Downgrade Plan') && $originalSupportStatus === 'resolved');
            $isAlreadyResolvedRestrict = (($originalConcern === 'Restrict' || $originalConcern === 'Disconnect') && $originalSupportStatus === 'resolved');
            $isAlreadyPulloutDone = ($originalRepairCategory === 'pullout' && $originalVisitStatus === 'done');
            $isAlreadyMigrationDone = (in_array($originalRepairCategory, ['migrate', 'relocate', 'relocate router', 'transfer lcp/nap/port']) && $originalVisitStatus === 'done');

            $reconnectStatus = null;
            $normalizedConcern = $currentConcern ? strtolower(trim($currentConcern)) : '';
            if ($normalizedConcern && ($normalizedConcern === 'reconnect' || $normalizedConcern === 'upgrade/downgrade plan') && $supportStatus === 'resolved' && !$isAlreadyResolvedReconnect) {
                $billingAccount = BillingAccount::where('account_no', $order->account_no)->first();
                if ($billingAccount) {
                    \Log::info("Triggering auto-reconnect for Service Order with {$currentConcern} concern", [
                        'account_no' => $order->account_no
                    ]);
                    $reconnectStatus = $this->attemptReconnection($billingAccount, $id, $updatedByUser, $organizationId);

                    if ($reconnectStatus === 'success' && $normalizedConcern === 'upgrade/downgrade plan') {
                        try {
                            $oldPlanString = $updateData['old_plan'] ?? $order->old_plan ?? null;
                            $newPlanString = $updateData['new_plan'] ?? $order->new_plan ?? null;

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
                                'remarks' => $updateData['concern_remarks'] ?? $order->concern_remarks ?? 'Upgraded/Downgraded via Service Order',
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
                $billingAccount = BillingAccount::where('account_no', $order->account_no)->first();
                if ($billingAccount && (int) $billingAccount->billing_status_id !== 1) {
                    \Log::info('Triggering reactivation for Service Order with Reactivate concern', [
                        'account_no' => $order->account_no,
                        'current_billing_status_id' => $billingAccount->billing_status_id
                    ]);
                    // attemptReconnection sets billing_status_id to 1 (Active) and re-applies the plan in RADIUS
                    $reactivateStatus = $this->attemptReconnection($billingAccount, $id, $updatedByUser, $organizationId);

                    // Re-activate the customer's user account (counterpart to pullout, which sets active = 0)
                    try {
                        \App\Models\User::where('username', $order->account_no)->update(['active' => 1]);
                        \Log::info('[SERVICE ORDER REACTIVATE DB] Updated user active status to 1 for Account: ' . $order->account_no);
                    } catch (\Exception $e) {
                        \Log::error('[SERVICE ORDER REACTIVATE DB USER EXCEPTION] ' . $e->getMessage());
                    }
                } else {
                    \Log::info('Reactivate concern skipped — billing account already Active or not found', [
                        'account_no' => $order->account_no
                    ]);
                }
            }

            // Trigger Restriction/Disconnection based on concern and support status
            $restrictedStatus = null;
            $pulloutStatus = null;

            if ($currentConcern && $supportStatus === 'resolved' && !$isAlreadyResolvedRestrict) {
                $lowerConcern = strtolower($currentConcern);
                if ($lowerConcern === 'restrict' || $lowerConcern === 'disconnect') {
                    $billingAccount = BillingAccount::where('account_no', $order->account_no)->first();
                    if ($billingAccount) {
                        \Log::info("Triggering auto-restriction for Service Order with {$currentConcern} concern", [
                            'account_no' => $order->account_no
                        ]);
                        $restrictedStatus = $this->attemptRestriction($billingAccount, $updatedByUser, $organizationId);
                    }
                }
            }







            $visitStatus = strtolower(trim($request->input('visit_status') ?? ''));
            if (empty($visitStatus) && isset($order->visit_status)) {
                $visitStatus = strtolower(trim($order->visit_status));
            }

            $repairCategory = strtolower(trim($request->input('repair_category') ?? ''));
            if (empty($repairCategory) && isset($order->repair_category)) {
                $repairCategory = strtolower(trim($order->repair_category));
            }

            if ($repairCategory === 'pullout' && $visitStatus === 'done' && !$isAlreadyPulloutDone) {
                $billingAccount = BillingAccount::where('account_no', $order->account_no)->first();
                if ($billingAccount) {
                    \Log::info('Triggering auto-pullout for Service Order with Pullout repair category', [
                        'account_no' => $order->account_no
                    ]);
                    $pulloutStatus = $this->attemptPullout($billingAccount, $updatedByUser, $organizationId);
                }
            }

            // Trigger Migration if repair category is 'Migrate', 'Relocate', 'Relocate Router', or 'Transfer LCP/NAP/PORT' and visit status is 'Done'
            $migrationStatus = null;
            $relocateCategories = ['migrate', 'relocate', 'relocate router', 'transfer lcp/nap/port'];
            if (in_array($repairCategory, $relocateCategories) && $visitStatus === 'done' && !$isAlreadyMigrationDone) {
                $billingAccount = BillingAccount::where('account_no', $order->account_no)->first();
                if ($billingAccount) {
                    \Log::info('Triggering auto-migration for Service Order', [
                        'account_no' => $order->account_no
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

                        \Log::info('[SERVICE ORDER RELOCATE] Updated job_orders for account_id ' . $billingAccount->id, [
                            'rows_affected' => $affected,
                            'new_lcpnap' => $newLcpnap,
                            'new_port' => $newPort,
                            'new_vlan' => $newVlan,
                        ]);
                    }
                }
            }

            // Create Activity Log
            ActivityLog::log(
                'Service Order Updated',
                "Service Order #{$id} updated. Ticket: {$order->ticket_id}. Status: {$request->input('support_status', $order->support_status)}",
                'info',
            [
                'resource_type' => 'ServiceOrder',
                'resource_id' => $id,
                'additional_data' => [
                    'ticket_id' => $order->ticket_id,
                    'support_status' => $request->input('support_status'),
                    'visit_status' => $request->input('visit_status'),
                    'assigned_email' => $request->input('assigned_email'),
                    'reconnect_status' => $reconnectStatus,
                    'reactivate_status' => $reactivateStatus,
                    'restricted_status' => $restrictedStatus,
                    'pullout_status' => $pulloutStatus,
                    'migration_status' => $migrationStatus
                ]
            ]
            );

            if (isset($updateData['assigned_email']) && !empty($updateData['assigned_email'])) {
                try {
                    $pushService = app(\App\Services\PushNotificationService::class);
                    $pushService->sendToUserByEmail(
                        $updateData['assigned_email'],
                        'Service Order Assigned',
                        "You have been assigned to Service Order #{$id} (Ticket: {$order->ticket_id}).",
                        [],
                        'SO'
                    );
                } catch (\Exception $pushEx) {
                    \Log::error('Failed to send push notification on ServiceOrder update: ' . $pushEx->getMessage());
                }
            }

            event(new ServiceOrderUpdated(['action' => 'updated', 'service_order_id' => $id, 'ticket_id' => $order->ticket_id]));

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
                Log::info("Logged details update for account_no: {$accountRef}");
            }

            return response()->json([
                'success' => true,
                'message' => 'Service order updated successfully',
                'reconnect_status' => $reconnectStatus,
                'restricted_status' => $restrictedStatus,
                'pullout_status' => $pulloutStatus,
                'migration_status' => $migrationStatus
            ]);
        }
        catch (\Exception $e) {
            Log::error('Error updating service order: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            $errorMessage = $e->getMessage();

            // Radius Connectivity Issues
            if (str_contains($errorMessage, 'Failed to connect to RADIUS server') || 
                str_contains($errorMessage, 'Connection refused') || 
                str_contains($errorMessage, 'cURL error 7')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Radius Offline',
                    'error' => $errorMessage
                ], 400);
            }

            // Radius Duplicate Check
            if (str_contains($errorMessage, 'HTTP 400') && (str_contains($errorMessage, 'already exists') || str_contains($errorMessage, 'Duplicate') || str_contains($errorMessage, 'exists'))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Radius Duplicate',
                    'error' => $errorMessage
                ], 400);
            }

            // Technical Details Duplicate (Onboarded Customer Duplicate)
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
                'error' => $errorMessage,
            ], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $serviceOrder = ServiceOrder::findOrFail($id);
            $ticketId = $serviceOrder->ticket_id;
            $accountNo = $serviceOrder->account_no;
            $serviceOrder->delete();

            // Create Activity Log
            ActivityLog::log(
                'Service Order Deleted',
                "Service Order #{$id} deleted. Ticket: {$ticketId}, Account: {$accountNo}",
                'warning',
            [
                'resource_type' => 'ServiceOrder',
                'resource_id' => $id,
                'additional_data' => [
                    'ticket_id' => $ticketId,
                    'account_no' => $accountNo
                ]
            ]
            );

            event(new ServiceOrderUpdated(['action' => 'deleted', 'service_order_id' => $id, 'ticket_id' => $ticketId]));

            return response()->json([
                'success' => true,
                'message' => 'Service order deleted successfully',
            ]);
        }
        catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete service order',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function attemptReconnection($billingAccount, $serviceOrderId = null, $updatedBy = 'System', ?int $organizationId = null): string
    {
        try {
            // Reload billing account
            $billingAccount = BillingAccount::find($billingAccount->id);
            $accountNo = $billingAccount->account_no;

            $isAlreadyActive = ($billingAccount->billing_status_id == 1);

            \Log::info('[SERVICE ORDER RECONNECT] Force starting for account: ' . $accountNo);

            // Step 1: Get account details (PPPoE Username and Plan)
            $accountInfo = DB::table('billing_accounts')
                ->leftJoin('customers', 'billing_accounts.customer_id', '=', 'customers.id')
                ->leftJoin('technical_details', 'billing_accounts.id', '=', 'technical_details.account_id')
                ->where('billing_accounts.id', $billingAccount->id)
                ->select('technical_details.username as pppoe_username', 'customers.desired_plan')
                ->first();

            $username = $accountInfo->pppoe_username ?? null;
            $plan = $accountInfo->desired_plan ?? null;

            if (empty($username)) {
                \Log::info('[SERVICE ORDER RECONNECT SKIP] No PPPoE username found in technical_details');
                return 'no_username';
            }

            if (empty($plan)) {
                \Log::info('[SERVICE ORDER RECONNECT SKIP] No plan found');
                return 'no_plan';
            }

            \Log::info('[SERVICE ORDER RECONNECT PROCEED] Reconnecting user for account: ' . $accountNo);

            // Step 2: Trigger RADIUS Reconnection (retry 3 times, then queue)
            $radiusParams = [
                'accountNumber' => $accountNo,
                'username' => $username,
                'plan' => $plan,
                'remarks' => 'Reconnected via Service Order',
                'updatedBy' => $updatedBy
            ];
            $radiusSuccess = false;
            $lastRadiusError = '';
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                try {
                    $manualRadiusService = app(\App\Services\ManualRadiusOperationsService::class);
                    $radiusResult = $manualRadiusService->reconnectUser($radiusParams);
                    if (($radiusResult['status'] ?? '') === 'success') {
                        $radiusSuccess = true;
                        \Log::channel('radiusrelated')->info("[SERVICE ORDER RECONNECT RADIUS] Success on attempt {$attempt}");
                        break;
                    }
                    $lastRadiusError = $radiusResult['message'] ?? 'Operation returned failure';
                    \Log::channel('radiusrelated')->warning("[SERVICE ORDER RECONNECT RADIUS] Attempt {$attempt}/3 failed: {$lastRadiusError}");
                } catch (\Exception $radEx) {
                    $lastRadiusError = $radEx->getMessage();
                    \Log::channel('radiusrelated')->warning("[SERVICE ORDER RECONNECT RADIUS] Attempt {$attempt}/3 exception: {$lastRadiusError}");
                }
                if ($attempt < 2) sleep(2);
            }
            if (!$radiusSuccess) {
                \Log::channel('radiusrelated')->error('[SERVICE ORDER RECONNECT RADIUS] All 3 attempts failed. Queuing for retry.');
                RadiusQueueService::queue([
                    'organization_id' => $organizationId ?? null,
                    'source_type' => 'service_order',
                    'source_id' => $serviceOrderId ?? 0,
                    'account_no' => $accountNo,
                    'operation' => 'reconnect_user',
                    'params' => $radiusParams,
                    'last_error' => $lastRadiusError,
                    'created_by' => $updatedBy,
                ]);
            }

            // Step 3: Update billing_status_id to 1 (Active) BEFORE reconnecting
            $billingAccount->billing_status_id = 1;
            $billingAccount->updated_at = now();
            $billingAccount->updated_by = Auth::id();
            $billingAccount->save();

            \Log::info('[SERVICE ORDER RECONNECT DB] Updated billing_status_id to 1 for Account: ' . $accountNo);

            \Log::info('[SERVICE ORDER RECONNECT SUCCESS] Reconnection (Local Status) completed successfully');

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
                            \Log::info('[SERVICE ORDER RECONNECT SMS] SMS sent');
                        }
                    }
                }
                catch (\Exception $e) {
                    \Log::error('[SERVICE ORDER RECONNECT SMS EXCEPTION] ' . $e->getMessage());
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
                        \Log::info('[SERVICE ORDER RECONNECT EMAIL] Email queued');
                    }
                }
                catch (\Exception $e) {
                    \Log::error('[SERVICE ORDER RECONNECT EMAIL EXCEPTION] ' . $e->getMessage());
                }
            }

            return 'success';

        }
        catch (\Exception $e) {
            \Log::error('[SERVICE ORDER RECONNECT EXCEPTION] ' . $e->getMessage());
            return 'exception';
        }
    }

    private function attemptRestriction($billingAccount, $updatedByUser = 'System', ?int $organizationId = null): string
    {
        try {
            // Reload billing account
            $billingAccount = BillingAccount::find($billingAccount->id);
            $accountNo = $billingAccount->account_no;

            \Log::info('[SERVICE ORDER RESTRICT] Force starting for account: ' . $accountNo);

            // Get account details (PPPoE Username)
            $accountInfo = DB::table('billing_accounts')
                ->leftJoin('technical_details', 'billing_accounts.id', '=', 'technical_details.account_id')
                ->where('billing_accounts.id', $billingAccount->id)
                ->select('technical_details.username as pppoe_username')
                ->first();

            $username = $accountInfo->pppoe_username ?? null;

            if (empty($username)) {
                \Log::info('[SERVICE ORDER RESTRICT SKIP] No PPPoE username found');
                return 'no_username';
            }

            // Step 2: Trigger RADIUS Restriction (retry 3 times, then queue)
            $radiusRestrictParams = [
                'accountNumber' => $accountNo,
                'username' => $username,
                'remarks' => 'Restricted via Service Order',
                'updatedBy' => $updatedByUser
            ];
            $radiusSuccess = false;
            $lastRadiusError = '';
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                try {
                    $radiusOps = app(\App\Services\ManualRadiusOperationsService::class);
                    $result = $radiusOps->restrictedUser($radiusRestrictParams);
                    if (($result['status'] ?? '') === 'success') {
                        $radiusSuccess = true;
                        \Log::channel('radiusrelated')->info("[SERVICE ORDER RESTRICT RADIUS] Success on attempt {$attempt}");
                        break;
                    }
                    $lastRadiusError = $result['message'] ?? 'Operation returned failure';
                    \Log::channel('radiusrelated')->warning("[SERVICE ORDER RESTRICT RADIUS] Attempt {$attempt}/3 failed: {$lastRadiusError}");
                } catch (\Exception $radEx) {
                    $lastRadiusError = $radEx->getMessage();
                    \Log::channel('radiusrelated')->warning("[SERVICE ORDER RESTRICT RADIUS] Attempt {$attempt}/3 exception: {$lastRadiusError}");
                }
                if ($attempt < 2) sleep(2);
            }
            if (!$radiusSuccess) {
                \Log::channel('radiusrelated')->error('[SERVICE ORDER RESTRICT RADIUS] All 3 attempts failed. Queuing for retry.');
                RadiusQueueService::queue([
                    'organization_id' => $organizationId ?? null,
                    'source_type' => 'service_order',
                    'source_id' => 0,
                    'account_no' => $accountNo,
                    'operation' => 'restricted_user',
                    'params' => $radiusRestrictParams,
                    'last_error' => $lastRadiusError,
                    'created_by' => $updatedByUser,
                ]);
            }

            // Update billing_status_id to 6 (Restricted) - Assuming 6 is Restricted
            // We should use the name to be safe
            $statusId = DB::table('billing_status')->where('status_name', 'Restricted')->value('id');
            if (!$statusId) {
                $statusId = 6; // Fallback to 6 if not found
            }

            $billingAccount->billing_status_id = $statusId;
            $billingAccount->updated_at = now();
            $billingAccount->updated_by = Auth::id();
            $billingAccount->save();

            \Log::info("[SERVICE ORDER RESTRICT DB] Updated billing_status_id to {$statusId} (Restricted) for Account: {$accountNo}");

            // Fetch customer info for notifications
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

                    $smsService = new \App\Services\ItexmoSmsService();
                    $smsResult = $smsService->send([
                        'contact_no' => $customerInfo->contact_number_primary,
                        'message' => $message
                    ]);

                    if ($smsResult['success']) {
                        \Log::info('[SERVICE ORDER RESTRICT SMS] SMS sent to: ' . $customerInfo->contact_number_primary);
                    } else {
                        \Log::warning('[SERVICE ORDER RESTRICT SMS] SMS send failed', $smsResult);
                    }
                }
            } catch (\Exception $smsEx) {
                \Log::error('[SERVICE ORDER RESTRICT SMS EXCEPTION] ' . $smsEx->getMessage());
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
                        'recipient_email' => $customerInfo->email_address,
                    ];
                    $emailService->queueFromTemplate('DISCONNECTED', $emailData);
                    \Log::info('[SERVICE ORDER RESTRICT EMAIL] Email queued for: ' . $customerInfo->email_address);
                }
            } catch (\Exception $emailEx) {
                \Log::error('[SERVICE ORDER RESTRICT EMAIL EXCEPTION] ' . $emailEx->getMessage());
            }

            return 'success';
        } catch (\Exception $e) {
            \Log::error('[SERVICE ORDER RESTRICT EXCEPTION] ' . $e->getMessage());
            return 'exception';
        }
    }

    private function attemptDisconnection($billingAccount, $updatedByUser = 'System'): string
    {
        try {
            // Reload billing account
            $billingAccount = BillingAccount::find($billingAccount->id);
            $accountNo = $billingAccount->account_no;

            \Log::info('[SERVICE ORDER DISCONNECT] Force starting for account: ' . $accountNo);

            // Get account details (PPPoE Username)
            $accountInfo = DB::table('billing_accounts')
                ->leftJoin('customers', 'billing_accounts.customer_id', '=', 'customers.id')
                ->leftJoin('technical_details', 'billing_accounts.id', '=', 'technical_details.account_id')
                ->where('billing_accounts.id', $billingAccount->id)
                ->select('technical_details.username as pppoe_username')
                ->first();

            $username = $accountInfo->pppoe_username ?? null;

            if (empty($username)) {
                \Log::info('[SERVICE ORDER DISCONNECT SKIP] No PPPoE username found in technical_details');
                return 'no_username';
            }

            \Log::info('[SERVICE ORDER DISCONNECT PROCEED] Disconnecting user for account: ' . $accountNo);

            // Step 2: Trigger RADIUS Disconnection (retry 3 times, then queue)
            $radiusDcParams = [
                'accountNumber' => $accountNo,
                'username' => $username,
                'remarks' => 'Disconnected via Service Order',
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
                        \Log::channel('radiusrelated')->info("[SERVICE ORDER DISCONNECT RADIUS] Success on attempt {$attempt}");
                        break;
                    }
                    $lastRadiusError = $result['message'] ?? 'Operation returned failure';
                    \Log::channel('radiusrelated')->warning("[SERVICE ORDER DISCONNECT RADIUS] Attempt {$attempt}/3 failed: {$lastRadiusError}");
                } catch (\Exception $radEx) {
                    $lastRadiusError = $radEx->getMessage();
                    \Log::channel('radiusrelated')->warning("[SERVICE ORDER DISCONNECT RADIUS] Attempt {$attempt}/3 exception: {$lastRadiusError}");
                }
                if ($attempt < 2) sleep(2);
            }
            if (!$radiusSuccess) {
                \Log::channel('radiusrelated')->error('[SERVICE ORDER DISCONNECT RADIUS] All 3 attempts failed. Queuing for retry.');
                RadiusQueueService::queue([
                    'source_type' => 'service_order',
                    'source_id' => 0,
                    'account_no' => $accountNo,
                    'operation' => 'disconnect_user',
                    'params' => $radiusDcParams,
                    'last_error' => $lastRadiusError,
                    'created_by' => $updatedByUser,
                ]);
            }

            // Step 3: Update local database status
            $billingAccount->billing_status_id = 4; // Disconnected
            $billingAccount->updated_at = now();
            $billingAccount->updated_by = Auth::id();
            $billingAccount->save();

            \Log::info('[SERVICE ORDER DISCONNECT DB] Updated billing_status_id to 4 (Disconnected) for Account: ' . $accountNo);

            // Fetch customer details for notifications
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
                        \Log::info('[SERVICE ORDER DISCONNECT SMS] SMS sent');
                    }
                }
            }
            catch (\Exception $e) {
                \Log::error('[SERVICE ORDER DISCONNECT SMS EXCEPTION] ' . $e->getMessage());
            }

            // Send Email Notification
            try {
                $emailTemplate = \App\Models\EmailTemplate::where('Template_Code', 'DISCONNECTED')->first();

                if (!empty($emailTemplate) && $customerInfo && !empty($customerInfo->email_address)) {
                    $emailService = app(\App\Services\EmailQueueService::class);
                    $emailData = [
                        'customer_name' => $customerInfo->full_name,
                        'account_no' => $accountNo,
                        'amount_due' => number_format($customerInfo->account_balance, 2),
                        'balance' => number_format($customerInfo->account_balance, 2),
                        'recipient_email' => $customerInfo->email_address,
                    ];
                    $emailService->queueFromTemplate('DISCONNECTED', $emailData);
                    \Log::info('[SERVICE ORDER DISCONNECT EMAIL] Email queued');
                }
            }
            catch (\Exception $e) {
                \Log::error('[SERVICE ORDER DISCONNECT EMAIL EXCEPTION] ' . $e->getMessage());
            }

            return 'success';

        }
        catch (\Exception $e) {
            \Log::error('[SERVICE ORDER DISCONNECT EXCEPTION] ' . $e->getMessage());
            return 'exception';
        }
    }

    private function attemptPullout($billingAccount, $updatedByUser = 'System', ?int $organizationId = null): string
    {
        try {
            // Reload billing account
            $billingAccount = BillingAccount::find($billingAccount->id);
            $accountNo = $billingAccount->account_no;

            \Log::info('[SERVICE ORDER PULLOUT] Force starting for account: ' . $accountNo);

            // Get account details (PPPoE Username)
            $accountInfo = DB::table('billing_accounts')
                ->leftJoin('customers', 'billing_accounts.customer_id', '=', 'customers.id')
                ->leftJoin('technical_details', 'billing_accounts.id', '=', 'technical_details.account_id')
                ->where('billing_accounts.id', $billingAccount->id)
                ->select('technical_details.username as pppoe_username', 'technical_details.router_modem_sn as router_modem_sn')
                ->first();

            $username = $accountInfo->pppoe_username ?? null;
            $routerModemSn = $accountInfo->router_modem_sn ?? null;

            \Log::info('[SERVICE ORDER PULLOUT PROCEED] Executing pullout for account: ' . $accountNo);

            if (empty($username)) {
                \Log::info('[SERVICE ORDER PULLOUT SKIP RADIUS] No PPPoE username found, skipping RADIUS disconnect but proceeding with local DB updates');
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
                            \Log::channel('radiusrelated')->info("[SERVICE ORDER PULLOUT RADIUS] Success on attempt {$attempt}");
                            break;
                        }
                        $lastRadiusError = $result['message'] ?? 'Operation returned failure';
                        \Log::channel('radiusrelated')->warning("[SERVICE ORDER PULLOUT RADIUS] Attempt {$attempt}/3 failed: {$lastRadiusError}");
                    } catch (\Exception $radEx) {
                        $lastRadiusError = $radEx->getMessage();
                        \Log::channel('radiusrelated')->warning("[SERVICE ORDER PULLOUT RADIUS] Attempt {$attempt}/3 exception: {$lastRadiusError}");
                    }
                    if ($attempt < 2) sleep(2);
                }
                if (!$radiusSuccess) {
                    \Log::channel('radiusrelated')->error('[SERVICE ORDER PULLOUT RADIUS] All 3 attempts failed. Queuing for retry.');
                    RadiusQueueService::queue([
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
                \Log::info('[SERVICE ORDER PULLOUT SUCCESS] Disconnection (Local Status) completed successfully');
            }

            // Update billing_status_id to 5 (Pullout)
            $billingAccount->billing_status_id = 5;
            $billingAccount->updated_at = now();
            $billingAccount->updated_by = Auth::id();
            $billingAccount->save();

            \Log::info('[SERVICE ORDER PULLOUT DB] Updated billing_status_id to 5 (Pullout) for Account: ' . $accountNo);

            // Clear the ONU name in SmartOLT before wiping the SN from technical_details (best-effort)
            if (!empty($routerModemSn)) {
                $smartOltStatus = app(\App\Services\SmartOltService::class)->clearOnuNameBySn($routerModemSn);
                \Log::info('[SERVICE ORDER PULLOUT SMARTOLT] Clear ONU name result: ' . $smartOltStatus, [
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

            \Log::info('[SERVICE ORDER PULLOUT DB] Cleared technical details for Account: ' . $accountNo);

            // Clear port in job_orders table using account_id (referencing billing_accounts id)
            DB::table('job_orders')
                ->where('account_id', $billingAccount->id)
                ->update([
                'port' => null,
                'updated_at' => now()
            ]);

            \Log::info('[SERVICE ORDER PULLOUT DB] Cleared port in job_orders for Account ID: ' . $billingAccount->id);

            // Fetch customer details for notifications
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
                        \Log::info('[SERVICE ORDER PULLOUT SMS] SMS sent');
                    }
                }
            }
            catch (\Exception $e) {
                \Log::error('[SERVICE ORDER PULLOUT SMS EXCEPTION] ' . $e->getMessage());
            }

            // Send Email Notification
            try {
                $emailTemplate = \App\Models\EmailTemplate::where('Template_Code', 'DISCONNECTED')->first();

                if (!empty($emailTemplate) && $customerInfo && !empty($customerInfo->email_address)) {
                    $emailService = app(\App\Services\EmailQueueService::class);
                    $emailData = [
                        'customer_name' => $customerInfo->full_name,
                        'account_no' => $accountNo,
                        'amount_due' => number_format($customerInfo->account_balance, 2),
                        'balance' => number_format($customerInfo->account_balance, 2),
                        'recipient_email' => $customerInfo->email_address,
                    ];
                    $emailService->queueFromTemplate('DISCONNECTED', $emailData);
                    \Log::info('[SERVICE ORDER PULLOUT EMAIL] Email queued');
                }
            }
            catch (\Exception $e) {
                \Log::error('[SERVICE ORDER PULLOUT EMAIL EXCEPTION] ' . $e->getMessage());
            }

            // Update customer's user account to inactive
            try {
                \App\Models\User::where('username', $accountNo)->update(['active' => 0]);
                \Log::info('[SERVICE ORDER PULLOUT DB] Updated user active status to 0 for Account: ' . $accountNo);
            } catch (\Exception $e) {
                \Log::error('[SERVICE ORDER PULLOUT DB USER EXCEPTION] ' . $e->getMessage());
            }

            return 'success';

        }
        catch (\Exception $e) {
            \Log::error('[SERVICE ORDER PULLOUT EXCEPTION] ' . $e->getMessage());
            return 'exception';
        }
    }

    private function attemptMigration($billingAccount, $repairCategory = null, $updatedByUser = 'System', ?int $organizationId = null): string
    {
        try {
            $accountNo = $billingAccount->account_no;

            \Log::info('[SERVICE ORDER MIGRATION] Force starting for account: ' . $accountNo);

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
                \Log::info('[SERVICE ORDER MIGRATION SKIP] No PPPoE username found');
                return 'no_username';
            }

            \Log::info('[SERVICE ORDER MIGRATION] Found old username: ' . $oldUsername);

            // SPECIAL CASE: Transfer LCP/NAP/PORT & Migrate
            $normalizedCategory = $repairCategory ? strtolower(trim($repairCategory)) : '';
            if ($normalizedCategory === 'transfer lcp/nap/port' || $normalizedCategory === 'migrate') {
                \Log::info("[SERVICE ORDER] Handling {$repairCategory} via updateCredentials (rename in place)");

                // 1. Generate new username (keep existing password)
                $pppoeService = new PppoeUsernameService();
                $customerData = (array)$fullInfo;
                $newUsername = $pppoeService->generateUniqueUsername($customerData);

                \Log::info("[SERVICE ORDER] Renaming username: '{$oldUsername}' -> '{$newUsername}'");

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
                            \Log::info("[SERVICE ORDER] Username renamed successfully on attempt {$attempt}");
                            break;
                        }
                        $lastRadiusError = $credResult['message'] ?? 'Operation returned failure';
                        \Log::channel('radiusrelated')->warning("[SERVICE ORDER MIGRATION RADIUS] Attempt {$attempt}/3 failed: {$lastRadiusError}");
                    } catch (\Exception $radEx) {
                        $lastRadiusError = $radEx->getMessage();
                        \Log::channel('radiusrelated')->warning("[SERVICE ORDER MIGRATION RADIUS] Attempt {$attempt}/3 exception: {$lastRadiusError}");
                    }
                    if ($attempt < 2) sleep(2);
                }
                if ($radiusSuccess) {
                    return 'success';
                }
                \Log::channel('radiusrelated')->error('[SERVICE ORDER MIGRATION RADIUS] All 3 attempts failed. Queuing for retry.');
                RadiusQueueService::queue([
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

            \Log::info('[SERVICE ORDER MIGRATION] Generated new username', [
                'old' => $oldUsername,
                'new' => $newUsername
            ]);

            if ($oldUsername === $newUsername) {
                \Log::info('[SERVICE ORDER MIGRATION SKIP] Username did not change');
                return 'no_change';
            }

            // RADIUS RENAME LOGIC — same approach as Transfer LCP/NAP/PORT:
            // updateCredentials does disable → kill session → PATCH name → re-enable in place.
            // No delete + recreate — that was wiping the user and breaking the connection.
            $targetCategories = ['relocate', 'relocate router', 'transfer lcp nap vlan'];

            if (in_array($normalizedCategory, $targetCategories)) {
                \Log::info("[SERVICE ORDER] Handling {$normalizedCategory} via updateCredentials (rename in place)");

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
                            \Log::info("[SERVICE ORDER MIGRATION] Username renamed on attempt {$attempt}");
                            break;
                        }
                        $lastRadiusError = $credResult['message'] ?? 'Operation returned failure';
                        \Log::channel('radiusrelated')->warning("[SERVICE ORDER MIGRATION RADIUS] Attempt {$attempt}/3 failed: {$lastRadiusError}");
                    } catch (\Exception $radEx) {
                        $lastRadiusError = $radEx->getMessage();
                        \Log::channel('radiusrelated')->warning("[SERVICE ORDER MIGRATION RADIUS] Attempt {$attempt}/3 exception: {$lastRadiusError}");
                    }
                    if ($attempt < 2) sleep(2);
                }
                if ($radiusSuccess) {
                    return 'success';
                }
                \Log::channel('radiusrelated')->error('[SERVICE ORDER MIGRATION RADIUS] All 3 attempts failed. Queuing for retry.');
                RadiusQueueService::queue([
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
                \Log::info('[SERVICE ORDER MIGRATION PROCEED] Updating database credentials (DB ONLY) for ' . $oldUsername);

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

                \Log::info('[SERVICE ORDER MIGRATION SUCCESS] DB Only migration completed');
                return 'success';
            }


        }
        catch (\Exception $e) {
            \Log::error('[SERVICE ORDER MIGRATION EXCEPTION] ' . $e->getMessage());
            return 'exception';
        }
    }

    /**
     * Generate a pro-rated invoice for a newly installed account.
     *
     * Formula:
     *   daily_rate  = plan_price / 30
     *   days        = today → user's billing_day (within the current/next month)
     *   total       = daily_rate * days
     *
     * The invoice is created with status Unpaid and the account_balance is
     * increased by the same amount. SMS and email notifications are sent.
     *
     * POST /api/service-orders/generate-installation-invoice
     * Body: { account_no: string, user_id?: int }
     */
    public function generateProRatedInstallationInvoice(Request $request): JsonResponse
    {
        try {
            \Illuminate\Support\Facades\Log::channel('prorategeneration')->info("[RUNNING] Starting pro-rate invoice generation for account: " . $request->input('account_no', 'unknown'));
            $validator = Validator::make($request->all(), [
                'account_no' => 'required|string|exists:billing_accounts,account_no',
                'user_id'    => 'nullable|integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            $accountNo = $request->input('account_no');
            $userId    = $request->input('user_id') ?? (Auth::id() ?? 1);

            // ── 1. Load account & customer ───────────────────────────────────────
            $billingAccount = BillingAccount::with('customer')->where('account_no', $accountNo)->firstOrFail();
            $customer       = $billingAccount->customer;

            if (!$customer) {
                return response()->json(['success' => false, 'message' => 'Customer not found for this account.'], 404);
            }

            // ── 2. Resolve plan price ────────────────────────────────────────────
            $desiredPlan = $customer->desired_plan ?? '';

            // Extract plan name (strip " - ₱999" or " 999" suffixes)
            $planName = trim($desiredPlan);
            if (strpos($planName, ' - ') !== false) {
                $planName = trim(explode(' - ', $planName)[0]);
            } elseif (strpos($planName, ' ') !== false) {
                $planName = trim(explode(' ', $planName)[0]);
            }

            $plan = DB::table('plan_list')->where('plan_name', $planName)->first();

            if (!$plan || empty($plan->price) || $plan->price <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Plan '{$planName}' not found or has no valid price.",
                ], 422);
            }

            $planPrice = floatval($plan->price);

            // ── 3. Calculate pro-rated amount ────────────────────────────────────
            $today      = \Carbon\Carbon::now('Asia/Manila');
            $billingDay = intval($billingAccount->billing_day ?? 0);

            // Determine the next billing date based on billing_day
            if ($billingDay === 0) {
                // End-of-month billing: use last day of this month
                $nextBillingDate = $today->copy()->endOfMonth()->startOfDay();
            } else {
                // Build a candidate date in the current month
                $candidateThisMonth = $today->copy()->day($billingDay)->startOfDay();

                if ($candidateThisMonth->greaterThan($today)) {
                    // Billing day is still ahead this month
                    $nextBillingDate = $candidateThisMonth;
                } else {
                    // Billing day already passed; use same day next month
                    $nextBillingDate = $today->copy()->addMonthNoOverflow()->day($billingDay)->startOfDay();
                }
            }

            // Total days from today UP TO (but not including) billing day
            // e.g. today = May 10, billing_day = 20 → 10 days
            $totalDays = $today->copy()->startOfDay()->diffInDays($nextBillingDate);

            if ($totalDays <= 0) {
                $totalDays = 1; // Always charge at least 1 day
            }

            $dailyRate   = round($planPrice / 30, 6);
            $totalAmount = round($dailyRate * $totalDays, 2);

            \Illuminate\Support\Facades\Log::channel('prorategeneration')->info('[SUCCESS] Pro-rate Calculation for account: ' . $accountNo, [
                'account_no'       => $accountNo,
                'plan_name'        => $planName,
                'plan_price'       => $planPrice,
                'daily_rate'       => $dailyRate,
                'today'            => $today->format('Y-m-d'),
                'next_billing_date'=> $nextBillingDate->format('Y-m-d'),
                'total_days'       => $totalDays,
                'total_amount'     => $totalAmount,
            ]);

            // ── 4. Create invoice ────────────────────────────────────────────────
            DB::beginTransaction();

            // Due date: 7 days from today (or pull from billing config)
            $dueDateOffset = intval(optional(DB::table('billing_config')->first())->due_date_day ?? 7);
            $dueDate       = $today->copy()->addDays($dueDateOffset)->format('Y-m-d');

            $invoice = \App\Models\Invoice::create([
                'account_no'             => $accountNo,
                'invoice_date'           => $today->format('Y-m-d'),
                'invoice_balance'        => $totalAmount,
                'others_and_basic_charges' => 0,
                'service_charge'         => 0,
                'rebate'                 => 0,
                'discounts'              => 0,
                'staggered'              => 0,
                'total_amount'           => $totalAmount,
                'received_payment'       => 0.00,
                'due_date'               => $dueDate,
                'status'                 => $totalAmount <= 0 ? 'Paid' : 'Unpaid',
                'created_by'             => (string) $userId,
                'updated_by'             => (string) $userId,
            ]);

            // ── 5. Update account balance ────────────────────────────────────────
            $previousBalance = floatval($billingAccount->account_balance ?? 0);
            $newBalance      = round($previousBalance + $totalAmount, 2);

            $billingAccount->account_balance    = $newBalance;
            $billingAccount->balance_update_date = $today->format('Y-m-d');
            $billingAccount->save();

            DB::commit();

            \Illuminate\Support\Facades\Log::channel('prorategeneration')->info('[SUCCESS] Invoice created and balance updated for account: ' . $accountNo, [
                'account_no'       => $accountNo,
                'invoice_id'       => $invoice->id,
                'total_amount'     => $totalAmount,
                'previous_balance' => $previousBalance,
                'new_balance'      => $newBalance,
            ]);

            // ── 6. SMS notification ──────────────────────────────────────────────
            try {
                $smsTemplate = DB::table('sms_templates')
                    ->where('template_type', 'Invoice')
                    ->where('is_active', 1)
                    ->first();

                // Fallback to generic Billing template if no Invoice template exists
                if (!$smsTemplate) {
                    $smsTemplate = DB::table('sms_templates')
                        ->where('template_type', 'Billing')
                        ->where('is_active', 1)
                        ->first();
                }

                if ($smsTemplate && !empty($customer->contact_number_primary)) {
                    $customerName      = preg_replace('/\s+/', ' ', trim($customer->full_name ?? ($customer->first_name . ' ' . $customer->last_name)));
                    $planNameFormatted = str_replace('₱', 'P', $desiredPlan);
                    $formattedAmount   = number_format($totalAmount, 2);
                    $invoiceDateStr    = $today->format('Y-m-d');

                    $message = $smsTemplate->message_content;
                    $message = str_replace('{{customer_name}}',  $customerName,      $message);
                    $message = str_replace('{{account_no}}',     $accountNo,         $message);
                    $message = str_replace('{{plan_name}}',      $planNameFormatted, $message);
                    $message = str_replace('{{plan_nam}}',       $planNameFormatted, $message);
                    $message = str_replace('{{amount_due}}',     $formattedAmount,   $message);
                    $message = str_replace('{{amount}}',         $formattedAmount,   $message);
                    $message = str_replace('{{invoice_date}}',   $invoiceDateStr,    $message);
                    $message = str_replace('{{due_date}}',       $dueDate,           $message);
                    $message = str_replace('{{date}}',           $invoiceDateStr,    $message);

                    $smsService = new \App\Services\ItexmoSmsService();
                    $smsResult  = $smsService->send([
                        'contact_no' => $customer->contact_number_primary,
                        'message'    => $message,
                    ]);

                    \Illuminate\Support\Facades\Log::channel('prorategeneration')->info('[SUCCESS] SMS result for account: ' . $accountNo, [
                        'account_no' => $accountNo,
                        'success'    => $smsResult['success'] ?? false,
                    ]);
                }
            } catch (\Exception $smsEx) {
                \Illuminate\Support\Facades\Log::channel('prorategeneration')->error('[ERROR] SMS exception for account: ' . $accountNo . ' - ' . $smsEx->getMessage());
            }

            // ── 7. Email notification ────────────────────────────────────────────
            try {
                $emailTemplate = \App\Models\EmailTemplate::where('Template_Code', 'INVOICE')
                    ->orWhere('Template_Code', 'BILLING')
                    ->first();

                if ($emailTemplate && !empty($customer->email_address)) {
                    $emailService    = app(\App\Services\EmailQueueService::class);
                    $brandName       = DB::table('form_ui')->value('brand_name') ?? 'Your ISP';
                    $customerName    = preg_replace('/\s+/', ' ', trim($customer->full_name ?? ($customer->first_name . ' ' . $customer->last_name)));

                    $emailData = [
                        'customer_name'   => $customerName,
                        'account_no'      => $accountNo,
                        'plan_name'       => $desiredPlan,
                        'amount_due'      => number_format($totalAmount, 2),
                        'Amount'          => number_format($totalAmount, 2),
                        'amount'          => number_format($totalAmount, 2),
                        'invoice_date'    => $today->format('Y-m-d'),
                        'due_date'        => $dueDate,
                        'Date'            => $today->format('Y-m-d'),
                        'Company_Name'    => $brandName,
                        'recipient_email' => $customer->email_address,
                    ];

                    $emailService->queueFromTemplate($emailTemplate->Template_Code, $emailData);

                    \Illuminate\Support\Facades\Log::channel('prorategeneration')->info('[SUCCESS] Email queued for account: ' . $accountNo, [
                        'account_no' => $accountNo,
                        'email'      => $customer->email_address,
                    ]);
                }
            } catch (\Exception $emailEx) {
                \Illuminate\Support\Facades\Log::channel('prorategeneration')->error('[ERROR] Email exception for account: ' . $accountNo . ' - ' . $emailEx->getMessage());
            }

            // ── 8. Activity log ──────────────────────────────────────────────────
            ActivityLog::log(
                'Pro-Rated Invoice Generated',
                "Pro-rated invoice #{$invoice->id} generated for {$accountNo}. "
                    . "Days: {$totalDays} (today → billing day {$billingDay}). "
                    . "Amount: {$totalAmount}.",
                'info',
                [
                    'resource_type'   => 'Invoice',
                    'resource_id'     => $invoice->id,
                    'additional_data' => [
                        'account_no'        => $accountNo,
                        'plan_name'         => $planName,
                        'plan_price'        => $planPrice,
                        'daily_rate'        => $dailyRate,
                        'total_days'        => $totalDays,
                        'total_amount'      => $totalAmount,
                        'previous_balance'  => $previousBalance,
                        'new_balance'       => $newBalance,
                        'next_billing_date' => $nextBillingDate->format('Y-m-d'),
                    ],
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Pro-rated invoice generated successfully.',
                'data'    => [
                    'invoice_id'        => $invoice->id,
                    'account_no'        => $accountNo,
                    'plan_name'         => $planName,
                    'plan_price'        => $planPrice,
                    'daily_rate'        => round($dailyRate, 4),
                    'total_days'        => $totalDays,
                    'invoice_date'      => $today->format('Y-m-d'),
                    'next_billing_date' => $nextBillingDate->format('Y-m-d'),
                    'due_date'          => $dueDate,
                    'total_amount'      => $totalAmount,
                    'previous_balance'  => $previousBalance,
                    'new_balance'       => $newBalance,
                    'invoice_status'    => $invoice->status,
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Illuminate\Support\Facades\Log::channel('prorategeneration')->error('[ERROR] Failed to generate pro-rate invoice for account: ' . ($accountNo ?? 'unknown') . ' - ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate pro-rated invoice.',
                'error'   => $e->getMessage(),
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
}