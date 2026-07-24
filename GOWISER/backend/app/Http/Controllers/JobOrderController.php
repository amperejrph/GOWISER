<?php

namespace App\Http\Controllers;

use App\Models\JobOrder;
use App\Models\Customer;
use App\Models\TechnicalDetail;
use App\Models\BillingAccount;
use App\Models\Application;
use App\Models\ModemRouterSN;
use App\Models\ContractTemplate;
use App\Models\Port;
use App\Models\VLAN;
use App\Models\LCPNAPLocation;
use App\Models\Plan;
use App\Models\AuditTrailLog;
use App\Models\User;
use App\Models\Role;
use App\Models\OnlineStatus;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;

use App\Services\GoogleDriveService;
use App\Services\PppoeUsernameService;
use App\Services\RadiusServerResolver;
use App\Models\RadiusConfig;
use App\Models\ActivityLog;
use App\Events\JobOrderViewingUpdate;

class JobOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $page = $request->input('page', 1);
            $limit = $request->input('limit', 50); // Default 50 for faster response
            $search = $request->input('search', '');
            $fastMode = $request->input('fast', false); // Fast mode: skip heavy processing

            \Log::info('JobOrderController: Starting to fetch job orders', [
                'page' => $page,
                'limit' => $limit,
                'search' => $search,
                'fast_mode' => $fastMode
            ]);

            $query = JobOrder::with(['application', 'items', 'billingAccount.customer'])->orderBy('id', 'desc');

            // Apply organization filter
            $currentUser = auth()->user();
            if ($currentUser) {
                if ($currentUser->organization_id) {
                    $query->where('organization_id', $currentUser->organization_id);
                } else {
                    $query->whereNull('organization_id');
                }
            }
            
            if ($request->has('assigned_email')) {
                $assignedEmail = $request->query('assigned_email');
                \Log::info('Filtering job orders by assigned_email: ' . $assignedEmail);
                $query->where('assigned_email', $assignedEmail);
            }
            
            if ($request->has('user_role') && strtolower($request->query('user_role')) === 'technician') {
                $sevenDaysAgo = now()->subDays(7);
                $query->where('updated_at', '>=', $sevenDaysAgo);
                \Log::info('Filtering job orders for technician role: only showing records from last 7 days', [
                    'cutoff_date' => $sevenDaysAgo->toDateTimeString()
                ]);
            }

            if ($request->has('updated_since')) {
                $query->where('updated_at', '>', $request->input('updated_since'));
                // Increase limit for updates to ensure we get all recent changes
                $limit = $request->input('limit', 1000);
            }

            // Apply search filter
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('assigned_email', 'LIKE', "%{$search}%")
                      ->orWhere('onsite_status', 'LIKE', "%{$search}%")
                      ->orWhere('username', 'LIKE', "%{$search}%")
                      ->orWhere('modem_router_sn', 'LIKE', "%{$search}%")
                      ->orWhereHas('application', function ($appQuery) use ($search) {
                          $appQuery->where('first_name', 'LIKE', "%{$search}%")
                                   ->orWhere('last_name', 'LIKE', "%{$search}%")
                                   ->orWhere('city', 'LIKE', "%{$search}%");
                      });
                });
            }

            // Fetch total count for pagination awareness on frontend
            $totalCount = $query->count();

            // Fetch one extra record to check if there are more pages
            $jobOrders = $query->skip(($page - 1) * $limit)
                ->take($limit + 1)
                ->get();

            // Check if there are more pages
            $hasMore = $jobOrders->count() > $limit;

            // Remove the extra record if it exists
            if ($hasMore) {
                $jobOrders = $jobOrders->slice(0, $limit);
            }

            \Log::info('JobOrderController: Fetched ' . $jobOrders->count() . ' job orders');

            // Fast mode: Return minimal data immediately
            if ($fastMode) {
                $formattedJobOrders = $jobOrders->map(function ($jobOrder) {
                    $application = $jobOrder->application;
                    $customer = $jobOrder->billingAccount ? $jobOrder->billingAccount->customer : null;
                    
                    return [
                        'id' => $jobOrder->id,
                        'JobOrder_ID' => $jobOrder->id,
                        'application_id' => $jobOrder->application_id,
                        'Timestamp' => $jobOrder->timestamp ? $jobOrder->timestamp->format('Y-m-d H:i:s') : null,
                        'Onsite_Status' => $jobOrder->onsite_status,
                        'Assigned_Email' => $jobOrder->assigned_email,
                        'Username' => $jobOrder->username,
                        'First_Name' => $application ? $application->first_name : ($customer ? $customer->first_name : null),
                        'Last_Name' => $application ? $application->last_name : ($customer ? $customer->last_name : null),
                        'Status' => $jobOrder->status,
                        'status' => $jobOrder->status,
                        'commission_status' => $jobOrder->commission_status,
                        'Created_By' => $jobOrder->created_by_user_email,
                        'Created_At' => $jobOrder->created_at ? $jobOrder->created_at->format('Y-m-d H:i:s') : null,
                        'Updated_By' => $jobOrder->updated_by_user_email,
                        'Updated_At' => $jobOrder->updated_at ? $jobOrder->updated_at->format('Y-m-d H:i:s') : null,
                        'updated_at' => $jobOrder->updated_at ? $jobOrder->updated_at->format('Y-m-d H:i:s') : null,
                        'created_at' => $jobOrder->created_at ? $jobOrder->created_at->format('Y-m-d H:i:s') : null,
                        'start_time' => $jobOrder->start_time,
                        'end_time' => $jobOrder->end_time,
                        'technicians' => $jobOrder->technicians,
                    ];
                });

                return response()->json([
                    'success' => true,
                    'data' => $formattedJobOrders->values(),
                    'pagination' => [
                        'current_page' => (int) $page,
                        'per_page' => (int) $limit,
                        'total_count' => (int) $totalCount,
                        'has_more' => $hasMore
                    ]
                ]);
            }

            // Normal mode: Return full data
            $formattedJobOrders = $jobOrders->map(function ($jobOrder) {
                $application = $jobOrder->application;
                $customer = $jobOrder->billingAccount ? $jobOrder->billingAccount->customer : null;
                
                return [
                    'id' => $jobOrder->id,
                    'JobOrder_ID' => $jobOrder->id,
                    'application_id' => $jobOrder->application_id,
                    'Timestamp' => $jobOrder->timestamp ? $jobOrder->timestamp->format('Y-m-d H:i:s') : null,
                    'Installation_Fee' => $jobOrder->installation_fee,
                    'Billing_Day' => $jobOrder->billing_day,
                    'Onsite_Status' => $jobOrder->onsite_status,
                    'Status' => $jobOrder->status,
                    'status' => $jobOrder->status,
                    'billing_status' => $jobOrder->billing_status,
                    'Status_Remarks' => $jobOrder->status_remarks,
                    'Assigned_Email' => $jobOrder->assigned_email,
                    'Contract_Template' => $jobOrder->contract_link,
                    'contract_link' => $jobOrder->contract_link,
                    'Created_By' => $jobOrder->created_by_user_email,
                    'Created_At' => $jobOrder->created_at ? $jobOrder->created_at->format('Y-m-d H:i:s') : null,
                    'Updated_By' => $jobOrder->updated_by_user_email,
                    'Updated_At' => $jobOrder->updated_at ? $jobOrder->updated_at->format('Y-m-d H:i:s') : null,
                    'Modified_By' => $jobOrder->created_by_user_email, // Keep for compatibility
                    'Modified_Date' => $jobOrder->updated_at ? $jobOrder->updated_at->format('Y-m-d H:i:s') : null, // Keep for compatibility
                    'Username' => $jobOrder->username,
                    'group_name' => $jobOrder->group_name,
                    'pppoe_username' => $jobOrder->pppoe_username,
                    'pppoe_password' => $jobOrder->pppoe_password,
                    
                    'date_installed' => $jobOrder->date_installed,
                    'start_time' => $jobOrder->start_time,
                    'end_time' => $jobOrder->end_time,
                    'technicians' => $jobOrder->technicians,
                    'usage_type' => $jobOrder->usage_type,
                    'connection_type' => $jobOrder->connection_type,
                    'router_model' => $jobOrder->router_model,
                    'modem_router_sn' => $jobOrder->modem_router_sn,
                    'Modem_SN' => $jobOrder->modem_router_sn,
                    'modem_sn' => $jobOrder->modem_router_sn,
                    'lcpnap' => $jobOrder->lcpnap,
                    'port' => $jobOrder->port,
                    'vlan' => $jobOrder->vlan,
                    'visit_by' => $jobOrder->visit_by,
                    'visit_with' => $jobOrder->visit_with,
                    'visit_with_other' => $jobOrder->visit_with_other,
                    'ip_address' => $jobOrder->ip_address,
                    'address_coordinates' => $jobOrder->address_coordinates,
                    'onsite_remarks' => $jobOrder->onsite_remarks,
                    'username_status' => $jobOrder->username_status,
                    
                    'client_signature_url' => $jobOrder->client_signature_url,
                    'setup_image_url' => $jobOrder->setup_image_url,
                    'speedtest_image_url' => $jobOrder->speedtest_image_url,
                    'signed_contract_image_url' => $jobOrder->signed_contract_image_url,
                    'box_reading_image_url' => $jobOrder->box_reading_image_url,
                    'router_reading_image_url' => $jobOrder->router_reading_image_url,
                    'port_label_image_url' => $jobOrder->port_label_image_url,
                    'house_front_picture_url' => $jobOrder->house_front_picture_url,
                    'client_tagging_url' => $jobOrder->client_tagging_url,
                    'proof_image_url' => $jobOrder->proof_image_url,
                    'installation_landmark' => $jobOrder->installation_landmark,
                    
                    'created_at' => $jobOrder->created_at ? $jobOrder->created_at->format('Y-m-d H:i:s') : null,
                    'updated_at' => $jobOrder->updated_at ? $jobOrder->updated_at->format('Y-m-d H:i:s') : null,
                    'created_by_user_email' => $jobOrder->created_by_user_email,
                    'updated_by_user_email' => $jobOrder->updated_by_user_email,
                    
                    'First_Name' => $application ? $application->first_name : ($customer ? $customer->first_name : null),
                    'Middle_Initial' => $application ? $application->middle_initial : ($customer ? $customer->middle_initial : null),
                    'Last_Name' => $application ? $application->last_name : ($customer ? $customer->last_name : null),
                    'Address' => ($application && !empty(trim($application->installation_address ?? ''))) 
                        ? $application->installation_address 
                        : ($customer ? $customer->address : null),
                    'Installation_Address' => ($application && !empty(trim($application->installation_address ?? ''))) 
                        ? $application->installation_address 
                        : ($customer ? $customer->address : null),
                    'Location' => ($application && !empty(trim($application->location ?? ''))) 
                        ? $application->location 
                        : ($customer ? $customer->location : null),
                    'City' => ($application && !empty(trim($application->city ?? ''))) 
                        ? $application->city 
                        : ($customer ? $customer->city : null),
                    'Region' => ($application && !empty(trim($application->region ?? ''))) 
                        ? $application->region 
                        : ($customer ? $customer->region : null),
                    'Barangay' => ($application && !empty(trim($application->barangay ?? ''))) 
                        ? $application->barangay 
                        : ($customer ? $customer->barangay : null),
                    'Email_Address' => $application ? $application->email_address : ($customer ? $customer->email_address : null),
                    'Mobile_Number' => $application ? $application->mobile_number : ($customer ? $customer->contact_number_primary : null),
                    'Secondary_Mobile_Number' => $application ? $application->secondary_mobile_number : ($customer ? $customer->contact_number_secondary : null),
                    'Desired_Plan' => $application ? $application->desired_plan : ($customer ? $customer->desired_plan : null),
                    'Referred_By' => $application ? $application->referred_by : ($customer ? $customer->referred_by : null),
                    'Billing_Status' => $jobOrder->billing_status,
                    'commission_status' => $jobOrder->commission_status,
                    'job_order_items' => $jobOrder->items,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedJobOrders->values(),
                'pagination' => [
                    'current_page' => (int) $page,
                    'per_page' => (int) $limit,
                    'total_count' => (int) $totalCount,
                    'has_more' => $hasMore
                ]
            ]);
        } catch (\Exception $e) {
            \Log::error('Error fetching job orders: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch job orders',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
    public function store(Request $request): JsonResponse
    {
        try {
            \Log::info('JobOrder Store Request', [
                'request_data' => $request->all()
            ]);

            $validator = Validator::make($request->all(), [
                'application_id' => 'required|integer|exists:applications,id',
                'status' => 'nullable|string|max:100',
                'timestamp' => 'nullable|date',
                'installation_fee' => 'nullable|numeric|min:0',
                'billing_day' => 'nullable|integer|min:0|max:31',
                'billing_status' => 'nullable|string|max:255',
                'onsite_status' => 'nullable|string|max:255',
                'assigned_email' => 'nullable|email|max:255',
                'onsite_remarks' => 'nullable|string',
                'status_remarks' => 'nullable|string|max:255',
                'modem_router_sn' => 'nullable|string|max:255',
                'username' => 'nullable|string|max:255',
                'group_name' => 'nullable|string|max:255',
                'installation_landmark' => 'nullable|string|max:255',
                'created_by_user_email' => 'nullable|email|max:255',
                'updated_by_user_email' => 'nullable|email|max:255',
                'contract_link' => 'nullable|string|max:500',
                'client_tagging_url' => 'nullable|string|max:500',
                'organization_id' => 'nullable|integer',
            ]);

            if ($validator->fails()) {
                \Log::error('JobOrder Store Validation Failed', [
                    'errors' => $validator->errors()->toArray()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $data = $request->all();

            // Sync pppoe_username to username if present
            if (isset($data['pppoe_username']) && !empty($data['pppoe_username'])) {
                $data['username'] = $data['pppoe_username'];
            }

            // Ensure timestamp is in Asia/Manila
            if (isset($data['timestamp'])) {
                try {
                    $data['timestamp'] = \Carbon\Carbon::parse($data['timestamp'], 'Asia/Manila')->format('Y-m-d H:i:s');
                } catch (\Exception $e) {
                    $data['timestamp'] = now('Asia/Manila')->format('Y-m-d H:i:s');
                }
            } else {
                $data['timestamp'] = now('Asia/Manila')->format('Y-m-d H:i:s');
            }

            
            // Set default values if not provided
            if (!isset($data['billing_status'])) {
                $data['billing_status'] = 'Pending';
            }
            
            // Auto-assign organization_id from current user if not provided
            if (!isset($data['organization_id']) && auth()->user()?->organization_id) {
                $data['organization_id'] = auth()->user()->organization_id;
            }
            if (!isset($data['billing_status'])) {
                $data['billing_status'] = 'Pending';
            }
            
            if (!isset($data['onsite_status'])) {
                $data['onsite_status'] = 'Pending';
            }
            
            \Log::info('JobOrder Creating with data', [
                'data' => $data
            ]);
            
            $jobOrder = JobOrder::create($data);
            $jobOrder->load('application');

            // Audit Trail Log
            AuditTrailLog::create([
                'old_details' => null,
                'new_details' => [
                    'type' => 'joborders',
                    'id' => $jobOrder->id,
                    'data' => $jobOrder->fresh()->toArray()
                ],
                'created_by_user' => $data['created_by_user_email'] ?? 'System',
                'updated_by_user' => $data['created_by_user_email'] ?? 'System'
            ]);

            // Create Activity Log using helper
            $customerName = trim(($jobOrder->application->first_name ?? '') . ' ' . ($jobOrder->application->last_name ?? ''));
            ActivityLog::log(
                'Job Order Assigned',
                "New Job Order assigned for {$customerName} (Application #{$jobOrder->application_id}). Assigned to: " . ($jobOrder->assigned_email ?? 'Nobody'),
                'info',
                [
                    'user_email' => $data['created_by_user_email'] ?? null,
                    'target_user_email' => $jobOrder->assigned_email,
                    'resource_type' => 'JobOrder',
                    'resource_id' => $jobOrder->id,
                    'additional_data' => [
                        'customer_name' => $customerName,
                        'application_id' => $jobOrder->application_id,
                        'assigned_email' => $jobOrder->assigned_email,
                        'installation_fee' => $jobOrder->installation_fee,
                        'onsite_status' => $jobOrder->onsite_status
                    ]
                ]
            );

            $jobOrder->load('application');

            \Log::info('JobOrder Created Successfully', [
                'id' => $jobOrder->id,
                'application_id' => $jobOrder->application_id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Job order created successfully',
                'data' => $jobOrder,
            ], 201);
        } catch (\Exception $e) {
            \Log::error('JobOrder Store Failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create job order',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    public function show($id): JsonResponse
    {
        try {
            $query = JobOrder::query();
            $currentUser = auth()->user();
            if ($currentUser) {
                if ($currentUser->organization_id) {
                    $query->where('organization_id', $currentUser->organization_id);
                } else {
                    $query->whereNull('organization_id');
                }
            }
            
            $jobOrder = $query->with(['application', 'items', 'billingAccount.customer'])->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $jobOrder,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Job order not found',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        DB::beginTransaction();
        try {
            \Log::info('JobOrder Update Request', [
                'id' => $id,
                'request_data' => $request->all()
            ]);

            $query = JobOrder::query();
            $currentUser = auth()->user();
            if ($currentUser) {
                if ($currentUser->organization_id) {
                    $query->where('organization_id', $currentUser->organization_id);
                } else {
                    $query->whereNull('organization_id');
                }
            }

            $jobOrder = $query->with('lcpnapLocation')->findOrFail($id);
            
            $generateCredentials = $request->input('generate_credentials', false);
            
            // Auto-generate credentials if pppoe_username is provided but pppoe_password is empty
            $hasUsername = $request->has('pppoe_username') && !empty($request->input('pppoe_username'));
            $hasPassword = $request->has('pppoe_password') && !empty($request->input('pppoe_password'));
            
            if ($hasUsername && !$hasPassword) {
                // Case 1: Username provided, password missing - generate password
                $application = $jobOrder->application;
                
                if ($application) {
                    $pppoeService = new PppoeUsernameService();
                    
                    $lcpnapValue = $request->input('lcpnap', $jobOrder->lcpnap);
                    $portValue = $request->input('port', $jobOrder->port);
                    $lcpnapData = null;
                    
                    if ($lcpnapValue) {
                        $lcpnapData = LCPNAPLocation::where('lcpnap_name', trim($lcpnapValue))
                                                 ->orWhere('id', trim($lcpnapValue))
                                                 ->first();
                    }
                    
                    $customerData = [
                        'first_name' => $application->first_name ?? '',
                        'middle_initial' => $application->middle_initial ?? '',
                        'last_name' => $application->last_name ?? '',
                        'mobile_number' => $application->mobile_number ?? '',
                        'lcp' => trim($lcpnapData->lcp ?? ''),
                        'nap' => trim($lcpnapData->nap ?? ''),
                        'port' => trim($portValue ?? ''),
                        'date_installed' => $jobOrder->date_installed ?? now(),
                        'tech_input_username' => $request->input('pppoe_username'),
                        'custom_password' => $request->input('custom_password'),
                    ];
                    
                    $password = $pppoeService->generatePassword($customerData);
                    
                    $request->merge([
                        'pppoe_password' => $password,
                    ]);
                    
                    \Log::info('Auto-generated PPPoE password for provided username', [
                        'job_order_id' => $id,
                        'username' => $request->input('pppoe_username'),
                        'password_length' => strlen($password),
                        'password_merged' => $request->has('pppoe_password'),
                        'password_in_request' => $request->input('pppoe_password') ? 'YES' : 'NO',
                        'lcp_value' => $jobOrder->lcpnapLocation->lcp ?? 'NOT SET',
                        'nap_value' => $jobOrder->lcpnapLocation->nap ?? 'NOT SET'
                    ]);
                }
            } elseif (!$hasUsername && $hasPassword) {
                // Case 2: Password provided (from RADIUS), username missing - generate username
                $application = $jobOrder->application;
                
                if ($application) {
                    $pppoeService = new PppoeUsernameService();
                    
                    $lcpnapValue = $request->input('lcpnap', $jobOrder->lcpnap);
                    $portValue = $request->input('port', $jobOrder->port);
                    $lcpnapData = null;
                    
                    if ($lcpnapValue) {
                        $lcpnapData = LCPNAPLocation::where('lcpnap_name', trim($lcpnapValue))
                                                 ->orWhere('id', trim($lcpnapValue))
                                                 ->first();
                    }
                    
                    $customerData = [
                        'first_name' => $application->first_name ?? '',
                        'middle_initial' => $application->middle_initial ?? '',
                        'last_name' => $application->last_name ?? '',
                        'mobile_number' => $application->mobile_number ?? '',
                        'lcp' => trim($lcpnapData->lcp ?? ''),
                        'nap' => trim($lcpnapData->nap ?? ''),
                        'port' => trim($portValue ?? ''),
                        'date_installed' => $jobOrder->date_installed ?? now(),
                        'tech_input_username' => $request->input('tech_input_username'),
                        'custom_password' => $request->input('custom_password'),
                    ];
                    
                    $username = $pppoeService->generateUniqueUsername($customerData, $id);
                    
                    $request->merge([
                        'pppoe_username' => $username,
                    ]);
                    
                    \Log::info('Auto-generated PPPoE username for provided password', [
                        'job_order_id' => $id,
                        'username' => $username,
                        'username_length' => strlen($username),
                        'password_from_radius' => true,
                        'lcp_value' => $jobOrder->lcpnapLocation->lcp ?? 'NOT SET',
                        'nap_value' => $jobOrder->lcpnapLocation->nap ?? 'NOT SET'
                    ]);
                }
            } elseif ($generateCredentials && empty($jobOrder->pppoe_username)) {
                // Case 3: No credentials provided - generate both
                $application = $jobOrder->application;
                
                if ($application) {
                    $pppoeService = new PppoeUsernameService();
                    
                    $lcpnapValue = $request->input('lcpnap', $jobOrder->lcpnap);
                    $portValue = $request->input('port', $jobOrder->port);
                    $lcpnapData = null;
                    
                    if ($lcpnapValue) {
                        $lcpnapData = LCPNAPLocation::where('lcpnap_name', trim($lcpnapValue))
                                                 ->orWhere('id', trim($lcpnapValue))
                                                 ->first();
                    }
                    
                    $customerData = [
                        'first_name' => $application->first_name ?? '',
                        'middle_initial' => $application->middle_initial ?? '',
                        'last_name' => $application->last_name ?? '',
                        'mobile_number' => $application->mobile_number ?? '',
                        'lcp' => trim($lcpnapData->lcp ?? ''),
                        'nap' => trim($lcpnapData->nap ?? ''),
                        'port' => trim($portValue ?? ''),
                        'date_installed' => $jobOrder->date_installed ?? now(),
                        'tech_input_username' => $request->input('tech_input_username'),
                        'custom_password' => $request->input('custom_password'),
                    ];
                    
                    $username = $pppoeService->generateUniqueUsername($customerData, $id);
                    $password = $pppoeService->generatePassword($customerData);
                    
                    $request->merge([
                        'pppoe_username' => $username,
                        'pppoe_password' => $password,
                    ]);
                    
                    \Log::info('Auto-generated PPPoE credentials', [
                        'job_order_id' => $id,
                        'username' => $username,
                        'username_length' => strlen($username),
                        'password_length' => strlen($password),
                        'lcp_value' => $jobOrder->lcpnapLocation->lcp ?? 'NOT SET',
                        'nap_value' => $jobOrder->lcpnapLocation->nap ?? 'NOT SET'
                    ]);
                }
            }

            $validator = Validator::make($request->all(), [
                'application_id' => 'nullable|integer|exists:applications,id',
                'status' => 'nullable|string|max:100',
                'timestamp' => 'nullable|date',
                'date_installed' => 'nullable|date',
                'installation_fee' => 'nullable|numeric|min:0',
                'billing_day' => 'nullable|integer|min:0',
                'onsite_status' => 'nullable|string|max:100',
                'billing_status' => 'nullable|string|max:255',
                'assigned_email' => 'nullable|email|max:255',
                'onsite_remarks' => 'nullable|string',
                'status_remarks' => 'nullable|string|max:255',
                'modem_router_sn' => 'nullable|string|max:255',
                'router_model' => 'nullable|string|max:255',
                'connection_type' => 'nullable|string|max:100',
                'usage_type' => 'nullable|string|max:255',
                'ip_address' => 'nullable|string|max:45',
                'lcpnap' => 'nullable|string|max:255',
                'port' => 'nullable|string|max:255',
                'vlan' => 'nullable|string|max:255',
                'visit_by' => 'nullable|string|max:255',
                'visit_with' => 'nullable|string|max:255',
                'visit_with_other' => 'nullable|string|max:255',
                'address_coordinates' => 'nullable|string|max:255',
                'username' => 'nullable|string|max:255',
                'group_name' => 'nullable|string|max:255',
                'installation_landmark' => 'nullable|string|max:255',
                'pppoe_username' => 'nullable|string|max:255',
                'pppoe_password' => 'nullable|string|max:255',
                'custom_password' => 'nullable|string|max:255',
                'created_by_user_email' => 'nullable|email|max:255',
                'updated_by_user_email' => 'nullable|email|max:255',
                'start_time' => 'nullable|date',
                'end_time' => 'nullable|date',
                'organization_id' => 'nullable|integer',
                'client_signature_url' => 'nullable|string|max:500',
                'setup_image_url' => 'nullable|string|max:500',
                'speedtest_image_url' => 'nullable|string|max:500',
                'signed_contract_image_url' => 'nullable|string|max:500',
                'box_reading_image_url' => 'nullable|string|max:500',
                'router_reading_image_url' => 'nullable|string|max:500',
                'port_label_image_url' => 'nullable|string|max:500',
                'house_front_picture_url' => 'nullable|string|max:500',
                'proof_image_url' => 'nullable|string|max:500',
                'client_tagging_url' => 'nullable|string|max:500',
                'technicians' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                \Log::error('JobOrder Update Validation Failed', [
                    'id' => $id,
                    'errors' => $validator->errors()->toArray()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $data = $request->all();

            // Sync pppoe_username to username if present
            if (isset($data['pppoe_username']) && !empty($data['pppoe_username'])) {
                $data['username'] = $data['pppoe_username'];
            }
            
            \Log::info('JobOrder Updating with data', [
                'id' => $id,
                'data' => $data,
                'has_pppoe_password_in_data' => isset($data['pppoe_password']),
                'pppoe_password_value' => $data['pppoe_password'] ?? 'NOT SET',
                'pppoe_password_length' => isset($data['pppoe_password']) ? strlen($data['pppoe_password']) : 0
            ]);

            $oldStatus = $jobOrder->onsite_status;

            // ── Technician availability guard (mirrors Api\ServiceOrderApiController) ──
            // onsite_status is the Job Order equivalent of the Service Order
            // visit_status.
            //   • Reassigning the order (assigned_email changed) resets both timers
            //     so the new technician starts fresh.
            //   • Once a STARTED job leaves the "In Progress" state (any onsite_status
            //     other than In Progress — Failed, Done, Reschedule, …) it must carry
            //     an end_time, so the technician is not left flagged as busy.
            // start_time/end_time are stored as Asia/Manila wall-clock to match the
            // mobile timer, so we stamp Manila time here — bare now() is UTC here.
            $assignedEmailChanged = array_key_exists('assigned_email', $data)
                && (string) $data['assigned_email'] !== (string) ($jobOrder->assigned_email ?? '');

            if ($assignedEmailChanged) {
                $data['start_time'] = null;
                $data['end_time']   = null;
            } else {
                $effectiveOnsite = strtolower(trim((string) (array_key_exists('onsite_status', $data)
                    ? $data['onsite_status']
                    : ($jobOrder->onsite_status ?? ''))));

                $onsiteInProgress = in_array($effectiveOnsite, ['in progress', 'in-progress', 'inprogress'], true);
                $leftInProgress   = ($effectiveOnsite !== '' && !$onsiteInProgress);

                $startTimePresent = array_key_exists('start_time', $data)
                    ? !empty($data['start_time'])
                    : !empty($jobOrder->start_time);
                // A payload that mentions end_time (a real timestamp OR an explicit
                // null to (re)open the timer) is authoritative — never override it.
                $callerManagesEndTime = array_key_exists('end_time', $data);

                if ($leftInProgress && $startTimePresent && !$callerManagesEndTime && empty($jobOrder->end_time)) {
                    $data['end_time'] = \Carbon\Carbon::now('Asia/Manila')->format('Y-m-d H:i:s');
                }
            }
            // ──────────────────────────────────────────────────────────────────────

            $jobOrder->fill($data);
            $dirtyAttributes = $jobOrder->getDirty();
            
            $realChanges = array_filter(array_keys($dirtyAttributes), function($key) {
                return !in_array($key, ['updated_by_user_email', 'updated_at']);
            });
            
            if (!empty($realChanges)) {
                $oldData = [];
                $newData = [];
                
                foreach ($dirtyAttributes as $key => $newValue) {
                    if ($key === 'updated_at') continue;
                    $oldData[$key] = $jobOrder->getOriginal($key);
                    $newData[$key] = $newValue;
                }
                
                $jobOrder->save();
 
                if (!empty($newData)) {
                    AuditTrailLog::create([
                        'old_details' => [
                            'type' => 'joborders',
                            'id' => $jobOrder->id,
                            'data' => $oldData
                        ],
                        'new_details' => [
                            'type' => 'joborders',
                            'id' => $jobOrder->id,
                            'data' => $newData
                        ],
                        'created_by_user' => $data['updated_by_user_email'] ?? 'System',
                        'updated_by_user' => $data['updated_by_user_email'] ?? 'System'
                    ]);
                }

                // Create Activity Log using helper
                ActivityLog::log(
                    'Job Order Updated',
                    "Job Order #{$id} updated by " . ($data['updated_by_user_email'] ?? 'Technician') . " (Status: {$jobOrder->onsite_status})",
                    'info',
                    [
                        'user_email' => $data['updated_by_user_email'] ?? null,
                        'resource_type' => 'JobOrder',
                        'resource_id' => $jobOrder->id,
                        'additional_data' => [
                            'onsite_status' => $jobOrder->onsite_status,
                            'assigned_email' => $jobOrder->assigned_email,
                            'updated_by' => $data['updated_by_user_email'] ?? null
                        ]
                    ]
                );
            } else {
                $jobOrder->refresh();
            }
            
            if (($data['onsite_status'] ?? null) === 'Done' && $oldStatus !== 'Done') {
                $this->broadcastJobOrderDone($jobOrder);
                
                // Trigger RADIUS account creation
                $radiusResult = $this->createRadiusAccountInternal($jobOrder);
                if (!$radiusResult['success']) {
                    $detailedError = $radiusResult['error'] ?? $radiusResult['message'] ?? 'radius api error occured contact support';
                    \Log::channel('radiusrelated')->error('RADIUS Account Creation Failed during JobOrder Done', [
                        'job_order_id' => $id,
                        'radius_error' => $detailedError
                    ]);
                    throw new \Exception($detailedError);
                }
            }
            
            \Log::info('JobOrder After Update', [
                'id' => $id,
                'pppoe_password_in_model' => $jobOrder->pppoe_password ?? 'NULL',
                'pppoe_password_length' => $jobOrder->pppoe_password ? strlen($jobOrder->pppoe_password) : 0,
                'pppoe_username_in_model' => $jobOrder->pppoe_username ?? 'NULL'
            ]);

            // Update technical_details if account_id exists
            if ($jobOrder->account_id) {
                $technicalDetail = TechnicalDetail::where('account_id', $jobOrder->account_id)->first();
                
                if ($technicalDetail) {
                    $technicalUpdateData = [];
                    
                    if (isset($data['usage_type'])) {
                        $technicalUpdateData['usage_type'] = $data['usage_type'];
                    }
                    if (isset($data['connection_type'])) {
                        $technicalUpdateData['connection_type'] = $data['connection_type'];
                    }
                    if (isset($data['router_model'])) {
                        $technicalUpdateData['router_model'] = $data['router_model'];
                    }
                    if (isset($data['modem_router_sn'])) {
                        $technicalUpdateData['router_modem_sn'] = $data['modem_router_sn'];
                    }
                    if (isset($data['ip_address'])) {
                        $technicalUpdateData['ip_address'] = $data['ip_address'];
                    }
                    if (isset($data['lcpnap'])) {
                        $technicalUpdateData['lcpnap'] = $data['lcpnap'];
                        
                        $lcpnapValue = $data['lcpnap'];
                        $loc = LCPNAPLocation::where('lcpnap_name', trim($lcpnapValue))
                                           ->orWhere('id', trim($lcpnapValue))
                                           ->first();
                        
                        if ($loc) {
                            $technicalUpdateData['lcp'] = $loc->lcp;
                            $technicalUpdateData['nap'] = $loc->nap;
                        } else {
                            // Fallback if not found in lookup, but try to keep behavior safe
                             $technicalUpdateData['lcp'] = null;
                             $technicalUpdateData['nap'] = null;
                        }
                    }
                    if (isset($data['port'])) {
                        $technicalUpdateData['port'] = $data['port'];
                    }
                    if (isset($data['vlan'])) {
                        $technicalUpdateData['vlan'] = $data['vlan'];
                    }
                    if ($jobOrder->pppoe_username) {
                        $technicalUpdateData['username'] = $jobOrder->pppoe_username;
                    }
                    
                    if (!empty($technicalUpdateData)) {
                        $technicalDetail->update($technicalUpdateData);
                        
                        \Log::info('TechnicalDetail Updated', [
                            'account_id' => $jobOrder->account_id,
                            'updated_fields' => array_keys($technicalUpdateData)
                        ]);
                    }
                }
            }

            \Log::info('JobOrder Updated Successfully', [
                'id' => $id,
                'updated_fields' => array_keys($data)
            ]);

            $jobOrder->load('application');

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Job order updated successfully',
                'data' => $jobOrder,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            $errorMessage = $e->getMessage();
            
            \Log::error('JobOrder Update Failed', [
                'id' => $id,
                'error' => $errorMessage,
                'trace' => $e->getTraceAsString()
            ]);

            // Precise mapping as requested by user
            if (str_contains($errorMessage, 'Failed to connect to RADIUS server') || 
                str_contains($errorMessage, 'Connection refused') || 
                str_contains($errorMessage, 'cURL error 7')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Radius Offline',
                    'error' => $errorMessage
                ], 400);
            }

            // Check for Radius duplicate (usually HTTP 400 with "already exists" or similar)
            if (str_contains($errorMessage, 'HTTP 400') && (str_contains($errorMessage, 'already exists') || str_contains($errorMessage, 'Duplicate') || str_contains($errorMessage, 'exists'))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Radius Duplicate',
                    'error' => $errorMessage
                ], 400);
            }

            // Check for Technical Details duplicate
            if (str_contains($errorMessage, 'Duplicate entry') && str_contains($errorMessage, 'technical_details')) {
                return response()->json([
                    'success' => false,
                    'message' => 'it has a duplicate on onboarded customer',
                    'error' => $errorMessage
                ], 409);
            }

            // Fallback for other RADIUS related errors
            if (str_contains($errorMessage, 'radius') || str_contains($errorMessage, 'RADIUS') || str_contains($errorMessage, 'HTTP')) {
                return response()->json([
                    'success' => false,
                    'message' => $errorMessage,
                ], 400);
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to update job order',
                'error' => $errorMessage,
            ], 500);
        }
    }

    private function broadcastJobOrderDone($jobOrder)
    {
        try {
            $application = $jobOrder->application;
            $data = [
                'id' => $jobOrder->id,
                'type' => 'job_order_done',
                'customer_name' => trim(($application->first_name ?? '') . ' ' . ($application->last_name ?? '')),
                'plan_name' => $application->desired_plan ?? 'Unknown Plan',
                'title' => 'Job Order Completed',
                'message' => 'Onsite status marked as Done',
                'timestamp' => now()->timestamp,
                'formatted_date' => now()->format('Y-m-d h:i:s A'),
                'organization_id' => $jobOrder->organization_id
            ];

            event(new \App\Events\JobOrderDone($data));
            \Log::info('Real-time broadcast sent for Job Order Done', ['id' => $jobOrder->id]);
        } catch (\Exception $e) {
            \Log::warning('Failed to broadcast Job Order Done via Soketi', [
                'error' => $e->getMessage()
            ]);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $jobOrder = JobOrder::findOrFail($id);
            $jobOrderData = $jobOrder->toArray();
            $jobOrder->delete();

            $userEmail = request()->input('updated_by_user_email') ?? auth()->user()?->email ?? 'System';

            // Audit Trail Log
            AuditTrailLog::create([
                'old_details' => [
                    'type' => 'joborders',
                    'id' => $id,
                    'data' => $jobOrderData
                ],
                'new_details' => null,
                'created_by_user' => $userEmail,
                'updated_by_user' => $userEmail
            ]);

            // Create Activity Log
            ActivityLog::log(
                'Job Order Deleted',
                "Job Order #{$id} deleted by " . (auth()->user()->email ?? 'System'),
                'warning',
                [
                    'resource_type' => 'JobOrder',
                    'resource_id' => $id,
                    'additional_data' => [
                        'job_order_data' => $jobOrderData
                    ]
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Job order deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete job order',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function approve($id): JsonResponse
    {
        try {
            DB::beginTransaction();

            $query = JobOrder::query();
            $currentUser = auth()->user();
            if ($currentUser) {
                if ($currentUser->organization_id) {
                    $query->where('organization_id', $currentUser->organization_id);
                } else {
                    $query->whereNull('organization_id');
                }
            }

            $jobOrder = $query->with(['application', 'lcpnapLocation'])->lockForUpdate()->findOrFail($id);
            
            if (!$jobOrder->application) {
                throw new \Exception('Job order must have an associated application');
            }

            $application = $jobOrder->application;
            $actionUserEmail = request()->input('updated_by_user_email') ?? auth()->user()?->email_address ?? auth()->user()?->email ?? 'admin@amperecloud.com';
            $actionUserId = auth()->id() ?? 1;
            $organizationId = auth()->user()?->organization_id ?? null;

            \Log::info('Job Order Approval - Application Data', [
                'application_id' => $application->id,
                'mobile_number' => $application->mobile_number,
                'secondary_mobile_number' => $application->secondary_mobile_number,
                'first_name' => $application->first_name,
                'last_name' => $application->last_name,
            ]);

            if (empty($application->secondary_mobile_number)) {
                \Log::warning('Secondary mobile number is empty in application', [
                    'application_id' => $application->id,
                    'job_order_id' => $id
                ]);
            }

            if ($jobOrder->billing_status === 'Done' || $jobOrder->account_id) {
                throw new \Exception('Job order has already been approved and has an associated billing account.');
            }

            // Check if a customer with the same email and names already exists to maintain 1-to-1
            $existingCustomer = Customer::where('email_address', $application->email_address)
                ->where('first_name', $application->first_name)
                ->where('last_name', $application->last_name)
                ->first();

            if ($existingCustomer) {
                $customer = $existingCustomer;
                $houseUrlSource = $jobOrder->house_front_picture_url ?: $application->house_front_picture_url;
                if (!empty($houseUrlSource) && empty($customer->house_front_picture_url)) {
                    $customer->update(['house_front_picture_url' => $houseUrlSource]);
                }
                \Log::info('Using existing customer for approval', ['customer_id' => $customer->id]);
            } else {
                $customer = Customer::create([
                    'first_name' => $application->first_name,
                    'middle_initial' => $application->middle_initial,
                    'last_name' => $application->last_name,
                    'email_address' => $application->email_address,
                    'contact_number_primary' => $application->mobile_number,
                    'contact_number_secondary' => $application->secondary_mobile_number,
                    'address' => $application->installation_address,
                    'location' => $application->location,
                    'barangay' => $application->barangay,
                    'city' => $application->city,
                    'region' => $application->region,
                    'address_coordinates' => $jobOrder->address_coordinates,
                    'housing_status' => $application->housing_status,
                    'referred_by' => $application->referred_by,
                    'desired_plan' => $application->desired_plan,
                    'house_front_picture_url' => $jobOrder->house_front_picture_url ?? $application->house_front_picture_url,
                    'proof_of_billing_url' => $application->proof_of_billing_url,
                    'government_valid_id_url' => $application->government_valid_id_url,
                    'second_government_valid_id_url' => $application->secondary_government_valid_id_url,
                    'document_attachment_url' => $application->document_attachment_url,
                    'other_isp_bill_url' => $application->other_isp_bill_url,
                    'organization_id' => $organizationId,
                    'created_by' => $actionUserEmail,
                    'updated_by' => $actionUserEmail,
                ]);
            }

            \Log::info('Customer Ready with Contact Numbers', [
                'customer_id' => $customer->id,
                'contact_number_primary' => $customer->contact_number_primary,
                'contact_number_secondary' => $customer->contact_number_secondary,
                'from_application_secondary' => $application->secondary_mobile_number,
            ]);

            $accountNumber = $this->generateAccountNumber();
            
            // Final safety check for 1-to-1 account_no uniqueness
            if (BillingAccount::where('account_no', $accountNumber)->exists()) {
                throw new \Exception('Billing account already exists with this account number.');
            }

            \Log::info('Generated account number', [
                'generated_account_no' => $accountNumber
            ]);
            
            // Sync generated account number to customer
            $customer->update(['account_no' => $accountNumber]);

            $installationFee = $jobOrder->installation_fee ?? 0;
            
            $planId = null;
            if ($application->desired_plan) {
                $desiredPlan = $application->desired_plan;
                
                \Log::info('Parsing desired_plan', [
                    'desired_plan' => $desiredPlan
                ]);
                
                if (strpos($desiredPlan, ' - P') !== false) {
                    $parts = explode(' - P', $desiredPlan);
                    $planName = trim($parts[0]);
                    $priceString = trim($parts[1]);
                    $price = (float) str_replace(',', '', $priceString);
                    
                    \Log::info('Parsed plan components', [
                        'plan_name' => $planName,
                        'price' => $price
                    ]);
                    
                    $plan = Plan::where('plan_name', $planName)
                                ->where('price', $price)
                                ->first();
                    
                    if ($plan) {
                        $planId = $plan->id;
                        \Log::info('Plan found successfully', [
                            'plan_name' => $planName,
                            'price' => $price,
                            'plan_id' => $planId
                        ]);
                    } else {
                        \Log::warning('Plan not found with exact match', [
                            'plan_name' => $planName,
                            'price' => $price
                        ]);
                    }
                } else {
                    \Log::warning('desired_plan format unexpected', [
                        'desired_plan' => $desiredPlan,
                        'expected_format' => 'PLAN_NAME - PPRICE'
                    ]);
                }
            }
            
            $billingAccount = BillingAccount::create([
                'customer_id' => $customer->id,
                'account_no' => $accountNumber,
                'date_installed' => $jobOrder->date_installed ?? now(),
                'plan_id' => $planId,
                'account_balance' => $installationFee,
                'balance_update_date' => now(),
                'billing_day' => $jobOrder->billing_day,
                'billing_status_id' => 1,
                'organization_id' => $organizationId,
                'created_by' => $actionUserEmail,
                'updated_by' => $actionUserEmail,
            ]);
            
            \Log::info('BillingAccount created', [
                'billing_account_id' => $billingAccount->id,
                'plan_id_stored' => $billingAccount->plan_id
            ]);

            // Use username from job order if exists, otherwise fallback to account number
            $usernameValue = $jobOrder->pppoe_username ?: ($jobOrder->username ?: $accountNumber);
            $usernameForTechnical = $usernameValue;

            $modemSN = $jobOrder->modem_router_sn;
            if ($modemSN) {
                // Check if technical detail already exists for this modem SN
                if (TechnicalDetail::where('router_modem_sn', $modemSN)->exists()) {
                    throw new \Exception('Technical details already exist with this modem serial number.');
                }
            }

            // Check for technical details with same account number to ensure 1-to-1
            if (TechnicalDetail::where('account_no', $accountNumber)->exists()) {
                throw new \Exception('Technical details already exist for this account number.');
            }
            // Manual lookup for LCPNAP Location to ensure trimming
            $lcpnapValue = trim($jobOrder->lcpnap);
            $lcpnapData = LCPNAPLocation::where('lcpnap_name', $lcpnapValue)
                                      ->orWhere('id', $lcpnapValue)
                                      ->first();

            $lcpValue = trim($lcpnapData->lcp ?? '');
            $napValue = trim($lcpnapData->nap ?? '');
            $portValue = $jobOrder->port ?? '';

            $technicalDetail = TechnicalDetail::create([
                'account_id' => $billingAccount->id,
                'account_no' => $accountNumber,
                'username' => $usernameForTechnical,
                'username_status' => $jobOrder->username_status,
                'connection_type' => $jobOrder->connection_type,
                'router_model' => $jobOrder->router_model,
                'router_modem_sn' => $modemSN,
                'ip_address' => $jobOrder->ip_address,
                'lcp' => $lcpValue,
                'nap' => $napValue,
                'port' => $portValue,
                'vlan' => $jobOrder->vlan,
                'lcpnap' => $jobOrder->lcpnap,
                'usage_type' => $jobOrder->usage_type,
                'organization_id' => $organizationId,
                'created_by' => $actionUserEmail,
                'updated_by' => $actionUserEmail,
            ]);



            // Use the calculated username and contact number for credentials
            $generatedUsername = $usernameValue;
            $generatedPassword = $jobOrder->pppoe_password ?: $customer->contact_number_primary;

            // Check if online status already exists for this username
            if (OnlineStatus::where('username', $generatedUsername)->exists()) {
                throw new \Exception('there\'s already data in database');
            }

            OnlineStatus::create([
                'account_id' => $billingAccount->id,
                'account_no' => $accountNumber,
                'username' => $generatedUsername,
                'session_status' => '',
            ]);
            
            $jobOrder->update([
                'billing_status' => 'Done',
                'account_id' => $billingAccount->id,
                'pppoe_username' => $generatedUsername,
                'pppoe_password' => $generatedPassword,
                'updated_by_user_email' => request()->input('updated_by_user_email') ?? auth()->user()?->email_address ?? auth()->user()?->email ?? $jobOrder->created_by_user_email ?? 'System'
            ]);
            
            \Log::info('Credentials saved to job_orders table', [
                'job_order_id' => $id,
                'table' => 'job_orders',
                'columns_updated' => ['username', 'password', 'billing_status', 'account_id'],
                'username_saved' => $generatedUsername
            ]);

            $customerRoleId = 3;

            $existingUser = User::where('username', $accountNumber)->first();
            if ($existingUser) {
                \Log::warning('User with account number already exists', [
                    'account_number' => $accountNumber,
                    'existing_user_id' => $existingUser->id,
                ]);
            } else {
                // Create user with direct password hash assignment to avoid mutator
                $userData = [
                    'username' => $accountNumber,
                    'email_address' => $customer->email_address,
                    'first_name' => $customer->first_name,
                    'middle_initial' => $customer->middle_initial,
                    'last_name' => $customer->last_name,
                    'contact_number' => $customer->contact_number_primary,
                    'role_id' => $customerRoleId,
                    'status' => 'active',
                    'active' => 1,
                    'organization_id' => $organizationId,
                    'created_by_user_id' => $actionUserId,
                    'updated_by_user_id' => $actionUserId,
                ];
                
                // Directly insert into database to bypass mutator
                $userId = \DB::table('users')->insertGetId(array_merge($userData, [
                    'password_hash' => Hash::make($customer->contact_number_primary),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
                
                $user = User::find($userId);

                \Log::info('Customer user account created', [
                    'user_id' => $user->id,
                    'username' => $accountNumber,
                    'email_address' => $customer->email_address,
                    'role_id' => $customerRoleId,
                ]);
            }

            DB::commit();

            // Create Activity Log using helper
            ActivityLog::log(
                'Job Order Approved',
                "Job Order #{$id} approved. Created Billing Account: {$accountNumber} for {$application->first_name} {$application->last_name}",
                'info',
                [
                    'resource_type' => 'JobOrder',
                    'resource_id' => $id,
                    'additional_data' => [
                        'account_number' => $accountNumber,
                        'customer_id' => $customer->id,
                        'application_id' => $application->id,
                        'plan' => $application->desired_plan
                    ]
                ]
            );

            // Send Welcome SMS
            $this->sendWelcomeSms($customer, $accountNumber, $generatedUsername, $generatedPassword, $application->desired_plan);


            // Send Welcome Email
            try {
                if (!empty($customer->email_address)) {
                    $welcomeEmailTemplate = \App\Models\EmailTemplate::where('Template_Code', 'WELCOME')
                        ->where('Is_Active', true)
                        ->first();

                    if ($welcomeEmailTemplate) {
                        $emailBody = $welcomeEmailTemplate->email_body;

                        // Replace variables in email body
                        $customerName = preg_replace('/\s+/', ' ', trim($customer->full_name));
                        $emailBody = str_replace('{{customer_name}}', $customerName, $emailBody);
                        $emailBody = str_replace('{{customer_tag}}', $customerName, $emailBody);
                        $emailBody = str_replace('{{company_name}}', 'ATSS Fiber', $emailBody);
                        $emailBody = str_replace('{{fb_username}}', 'https://www.facebook.com/atssfiber', $emailBody);
                        $emailBody = str_replace('{{account_no}}', $accountNumber, $emailBody);
                        $emailBody = str_replace('{{username}}', $generatedUsername, $emailBody);
                        $emailBody = str_replace('{{password}}', $generatedPassword, $emailBody);

                        $displayPlan = $application->desired_plan ?? 'N/A';
                        if (strpos($displayPlan, ' - P') !== false) {
                            $displayPlan = trim(explode(' - P', $displayPlan)[0]);
                        }
                        $displayPlan = str_replace('₱', 'P', $displayPlan);
                        $emailBody = str_replace('{{plan_name}}', $displayPlan, $emailBody);

                         if (!empty($emailBody)) {
                             $emailService = app(\App\Services\EmailQueueService::class);
                             
                             $emailService->queueEmail([
                                 'account_no' => $accountNumber,
                                 'recipient_email' => $customer->email_address,
                                 'subject' => $welcomeEmailTemplate->Subject_Line ?? 'Welcome to Ampere', 
                                 'body_html' => nl2br($emailBody), 
                                 'attachment_path' => null,
                                 'email_sender' => $welcomeEmailTemplate->email_sender,
                                 'reply_to' => $welcomeEmailTemplate->reply_to,
                                 'sender_name' => $welcomeEmailTemplate->sender_name
                             ]);
                             
                             \Log::info('Welcome Email queued successfully', [
                                  'customer_id' => $customer->id,
                                  'email' => $customer->email_address
                             ]);
                         } else {
                             \Log::warning('Welcome Email Body is empty');
                         }
                    } else {
                        \Log::warning('Welcome Email template not found or inactive');
                    }
                }
            } catch (\Exception $e) {
                 \Log::error('Failed to send Welcome Email: ' . $e->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => 'Job order approved successfully',
                'data' => [
                    'customer_id' => $customer->id,
                    'billing_account_id' => $billingAccount->id,
                    'technical_detail_id' => $technicalDetail->id,
                    'account_number' => $accountNumber,
                    'plan_id' => $planId,
                    'desired_plan' => $application->desired_plan,
                    'installation_fee' => $installationFee,
                    'account_balance' => $installationFee,
                    'contact_number_primary' => $customer->contact_number_primary,
                    'contact_number_secondary' => $customer->contact_number_secondary,
                    'user_created' => !isset($existingUser),
                    'user_username' => $accountNumber,
                    'username' => $generatedUsername,
                    'password' => $generatedPassword,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            \Log::error('Error approving job order: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            
            // Map common error messages to user-friendly "duplicate" message
            $duplicateMessages = [
                'there\'s already data in database',
                'Duplicate entry',
                'Integrity constraint violation',
                'Billing account already exists',
                'Technical details already exist',
                'already been approved'
            ];
            
            $isDuplicate = false;
            foreach ($duplicateMessages as $msg) {
                if (strpos($e->getMessage(), $msg) !== false) {
                    $isDuplicate = true;
                    break;
                }
            }
            
            if ($isDuplicate) {
                return response()->json([
                    'success' => false,
                    'message' => 'there\'s already data in database',
                    'error' => $e->getMessage(),
                ], 409); // Conflict
            }
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Failed to approve job order',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    private function generateAccountNumber(): string
    {
        DB::table('billing_accounts')->lockForUpdate()->get();
        
        $customAccountNumber = DB::table('custom_account_number')->first();
        
        if (!$customAccountNumber) {
            \Log::info('No custom_account_number record found, using default generation');
            return $this->generateDefaultAccountNumber();
        }
        
        $prefix = $customAccountNumber->starting_number;
        
        if ($prefix === null) {
            $prefix = '';
        } else {
            $prefix = (string)$prefix;
        }
        
        \Log::info('Custom account number config', [
            'prefix' => $prefix,
            'prefix_length' => strlen($prefix)
        ]);
        
        $prefixLength = strlen($prefix);
        $minIncrementLength = 4;
        
        $pattern = '^' . preg_quote($prefix, '/') . '\d+$';
        
        $latestAccount = BillingAccount::where('account_no', 'REGEXP', $pattern)
            ->where('account_no', 'LIKE', $prefix . '%')
            ->orderByRaw('LENGTH(account_no) DESC, account_no DESC')
            ->lockForUpdate()
            ->first();
        
        \Log::info('Latest account search', [
            'prefix' => $prefix,
            'pattern' => $pattern,
            'found' => $latestAccount ? $latestAccount->account_no : 'none'
        ]);
        
        if ($latestAccount) {
            $numericPart = substr($latestAccount->account_no, $prefixLength);
            $lastIncrement = (int)$numericPart;
            $lastIncrementLength = strlen($numericPart);
            $nextIncrement = $lastIncrement + 1;
            
            $nextIncrementLength = max($lastIncrementLength, strlen((string)$nextIncrement));
            
            \Log::info('Incrementing from existing account', [
                'last_account' => $latestAccount->account_no,
                'last_increment' => $lastIncrement,
                'last_increment_length' => $lastIncrementLength,
                'next_increment' => $nextIncrement,
                'next_increment_length' => $nextIncrementLength
            ]);
        } else {
            $nextIncrement = 1;
            $nextIncrementLength = $minIncrementLength;
            
            \Log::info('No existing account found, starting from 1', [
                'next_increment' => $nextIncrement,
                'next_increment_length' => $nextIncrementLength
            ]);
        }
        
        $newAccountNumber = $prefix . str_pad($nextIncrement, $nextIncrementLength, '0', STR_PAD_LEFT);
        
        \Log::info('Generated account number', [
            'account_number' => $newAccountNumber,
            'prefix' => $prefix,
            'increment' => $nextIncrement,
            'increment_length' => $nextIncrementLength
        ]);
        
        return $newAccountNumber;
    }

    private function generateDefaultAccountNumber(): string
    {
        $latestAccount = BillingAccount::orderBy('account_no', 'desc')
            ->lockForUpdate()
            ->first();
        
        if ($latestAccount && is_numeric($latestAccount->account_no)) {
            $nextNumber = (int) $latestAccount->account_no + 1;
            return str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
        }
        
        return '0001';
    }

    public function getModemRouterSNs(): JsonResponse
    {
        try {
            $modems = ModemRouterSN::all();
            return response()->json([
                'success' => true,
                'data' => $modems,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch modem router SNs',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getContractTemplates(): JsonResponse
    {
        try {
            $templates = ContractTemplate::all();
            return response()->json([
                'success' => true,
                'data' => $templates,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch contract templates',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getPorts(): JsonResponse
    {
        try {
            $ports = Port::all();
            return response()->json([
                'success' => true,
                'data' => $ports,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch ports',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getVLANs(): JsonResponse
    {
        try {
            $vlans = VLAN::all();
            return response()->json([
                'success' => true,
                'data' => $vlans,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch VLANs',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function getLCPNAPs(): JsonResponse
    {
        try {
            $lcpnaps = LCPNAPLocation::all();
            return response()->json([
                'success' => true,
                'data' => $lcpnaps,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch LCPNAPs',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function uploadImages(Request $request, $id): JsonResponse
    {
        try {
            Log::info('[BACKEND] Upload images request received', [
                'job_order_id' => $id,
                'folder_name' => $request->input('folder_name'),
                'has_signed_contract' => $request->hasFile('signed_contract_image'),
                'has_setup' => $request->hasFile('setup_image'),
                'has_box_reading' => $request->hasFile('box_reading_image'),
                'has_router_reading' => $request->hasFile('router_reading_image'),
                'has_port_label' => $request->hasFile('port_label_image'),
                'has_client_signature' => $request->hasFile('client_signature_image'),
                'has_client_tagging' => $request->hasFile('client_tagging_image'),
                'has_speed_test' => $request->hasFile('speed_test_image'),
                'has_proof_image' => $request->hasFile('proof_image'),
            ]);

            $validator = Validator::make($request->all(), [
                'folder_name' => 'required|string|max:255',
                'signed_contract_image' => 'nullable|image|max:10240',
                'setup_image' => 'nullable|image|max:10240',
                'box_reading_image' => 'nullable|image|max:10240',
                'router_reading_image' => 'nullable|image|max:10240',
                'port_label_image' => 'nullable|image|max:10240',
                'client_signature_image' => 'nullable|image|max:10240',
                'client_tagging_image' => 'nullable|image|max:10240',
                'speed_test_image' => 'nullable|image|max:10240',
                'proof_image' => 'nullable|image|max:10240',
                'house_front_image' => 'nullable|image|max:10240',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $jobOrder = JobOrder::findOrFail($id);
            $applicationId = $jobOrder->application_id ?? $jobOrder->Application_ID;
            
            if (!$applicationId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Job Order does not have an associated application_id'
                ], 400);
            }

            $imageFields = [
                'signed_contract_image',
                'setup_image',
                'box_reading_image',
                'router_reading_image',
                'port_label_image',
                'client_signature_image',
                'client_tagging_image',
                'speed_test_image',
                'proof_image',
                'house_front_image'
            ];

            $queuedCount = 0;
            $oldData = [];
            $newData = [];
            $dbColumnMap = [
                'signed_contract_image' => 'signed_contract_image_url',
                'setup_image' => 'setup_image_url',
                'box_reading_image' => 'box_reading_image_url',
                'router_reading_image' => 'router_reading_image_url',
                'port_label_image' => 'port_label_image_url',
                'client_signature_image' => 'client_signature_url',
                'client_tagging_image' => 'client_tagging_url',
                'speed_test_image' => 'speedtest_image_url',
                'proof_image' => 'proof_image_url',
                'house_front_image' => 'house_front_picture_url',
            ];

            foreach ($imageFields as $field) {
                if ($request->hasFile($field)) {
                    $file = $request->file($field);
                    $fileSizeKB = round($file->getSize() / 1024, 2);
                    Log::info("[BACKEND] $field received", [
                        'size_kb' => $fileSizeKB,
                        'mime_type' => $file->getMimeType(),
                    ]);

                    $fileName = $field . '_' . time() . '.' . $file->getClientOriginalExtension();
                    $localPath = $file->storeAs('images_queue', $fileName, 'public');

                    \App\Models\JobOrderImageQueue::create([
                        'job_order_id' => $id,
                        'field_name' => $field,
                        'local_path' => $localPath,
                        'original_filename' => $file->getClientOriginalName(),
                        'status' => 'pending',
                    ]);

                    $dbColumn = $dbColumnMap[$field] ?? $field;
                    $oldData[$dbColumn] = $jobOrder->$dbColumn;
                    $newData[$dbColumn] = 'Queueing upload: ' . $file->getClientOriginalName();

                    $queuedCount++;
                }
            }

            if ($queuedCount > 0) {
                $userEmail = auth()->user()?->email ?? 'System';
                AuditTrailLog::create([
                    'old_details' => [
                        'type' => 'joborders',
                        'id' => $jobOrder->id,
                        'data' => $oldData
                    ],
                    'new_details' => [
                        'type' => 'joborders',
                        'id' => $jobOrder->id,
                        'data' => $newData
                    ],
                    'created_by_user' => $userEmail,
                    'updated_by_user' => $userEmail
                ]);
            }

            Log::info('Job order images queued successfully', [
                'job_order_id' => $id,
                'image_count' => $queuedCount,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Images queued successfully for background upload',
                'data' => [],
            ]);

        } catch (\Exception $e) {
            Log::error('Error uploading job order images', [
                'job_order_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to upload images to Google Drive',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function createRadiusAccount($id): JsonResponse
    {
        $jobOrder = JobOrder::with(['application', 'lcpnapLocation'])->findOrFail($id);
        $result = $this->createRadiusAccountInternal($jobOrder);
        
        if ($result['success']) {
            return response()->json($result);
        } else {
            return response()->json($result, 500);
        }
    }

    private function createRadiusAccountInternal(JobOrder $jobOrder): array
    {
        $id = $jobOrder->id;
        try {
            \Log::channel('radiusrelated')->info('=== CREATE RADIUS ACCOUNT INTERNAL ===', [
                'job_order_id' => $id
            ]);

            $organizationId = $jobOrder->organization_id ?? auth()->user()?->organization_id ?? null;

            // Server placement no longer depends on the barangay. We use the first
            // radius_config (#1) and, only if it fails 3 times, fall back to the
            // second radius_config (#2). The account is created on whichever succeeds.
            $configs = app(RadiusServerResolver::class)->orderedConfigs($organizationId);

            if ($configs->isEmpty()) {
                \Log::channel('radiusrelated')->error('No RADIUS configuration available for JobOrder: ' . $id, [
                    'job_order_id'    => $id,
                    'organization_id' => $organizationId,
                ]);
                return [
                    'success' => false,
                    'message' => 'No RADIUS server is configured. Please add a RADIUS Config before creating the account.',
                ];
            }

            if (!$jobOrder->application) {
                throw new \Exception('Job order must have an associated application');
            }

            $application = $jobOrder->application;

            $credentialsExist = !empty($jobOrder->pppoe_username) && !empty($jobOrder->pppoe_password);
            $pppoeUsername = $jobOrder->pppoe_username;
            $pppoePassword = $jobOrder->pppoe_password;
            $radiusSubmitted = false;
            $radiusError = null;

            // Fetch LCP/NAP details regardless of whether credentials exist
            $lcpnapValue = trim($jobOrder->lcpnap);
            $lcpnapData = LCPNAPLocation::where('lcpnap_name', $lcpnapValue)
                                      ->orWhere('id', $lcpnapValue)
                                      ->first();

            $lcpValue = trim($lcpnapData->lcp ?? '');
            $napValue = trim($lcpnapData->nap ?? '');
            $portValue = $jobOrder->port ?? '';

            if (!$credentialsExist) {
                $pppoeService = new PppoeUsernameService();
                
                $customerData = [
                    'first_name' => $application->first_name ?? '',
                    'middle_initial' => $application->middle_initial ?? '',
                    'last_name' => $application->last_name ?? '',
                    'mobile_number' => $application->mobile_number ?? '',
                    'lcp' => $lcpValue,
                    'nap' => $napValue,
                    'port' => $portValue,
                    'date_installed' => $jobOrder->date_installed ?? now(),
                ];

                $pppoeUsername = $pppoeService->generateUniqueUsername($customerData, $id);
                $pppoePassword = $pppoeService->generatePassword($customerData);
                
                if (empty($pppoeUsername) || empty($pppoePassword)) {
                    throw new \Exception('Failed to generate PPPoE credentials');
                }
                
                $jobOrder->update([
                    'pppoe_username' => $pppoeUsername,
                    'pppoe_password' => $pppoePassword,
                    'updated_by_user_email' => request()->input('updated_by_user_email') ?? auth()->user()?->email_address ?? auth()->user()?->email ?? $jobOrder->created_by_user_email ?? 'System'
                ]);
                
                $jobOrder->refresh();
            }

            $desiredPlan = $application->desired_plan;
            $plan = $desiredPlan;
            
            if ($desiredPlan) {
                // Remove price suffix (e.g., "SWIFT 1000", "STARTER - P799.00", "FLASH 1999")
                // Strips everything after a hyphen, or space followed by digits/currency
                $plan = preg_replace('/\s*-\s*(?:P|₱)?\d+.*/i', '', $desiredPlan);
                $plan = preg_replace('/\s+(?:P|₱)?\d+.*/i', '', $plan);
                $plan = trim($plan);
            }
            
            $payload = [
                'name' => $pppoeUsername,
                'group' => $plan,
                'password' => $pppoePassword
            ];

            // Try radius_config #1 up to 3 times; if it never succeeds, fall back to #2.
            $maxAttemptsPerConfig = 3;
            $positionsToTry = [1];
            if ($configs->count() >= 2) {
                $positionsToTry[] = 2;
            }

            $lastFailureWasConnection = false;

            foreach ($positionsToTry as $position) {
                $radiusConfig = $configs->get($position - 1);
                if (!$radiusConfig) {
                    continue;
                }

                $radiusUrl = $radiusConfig->ssl_type . '://' . $radiusConfig->ip . ':' . $radiusConfig->port . '/rest/user-manage/user';
                $radiusUsername = $radiusConfig->username;
                $radiusPassword = $radiusConfig->password;

                \Log::channel('radiusrelated')->info('RADIUS server selected for JobOrder account creation', [
                    'job_order_id'     => $id,
                    'position'         => $position,
                    'radius_config_id' => $radiusConfig->id,
                    'radius_ip'        => $radiusConfig->ip,
                ]);

                for ($attempt = 1; $attempt <= $maxAttemptsPerConfig; $attempt++) {
                    try {
                        $response = Http::withOptions([
                            'verify' => false
                        ])
                        ->withBasicAuth($radiusUsername, $radiusPassword)
                        ->put($radiusUrl, $payload);

                        $statusCode = $response->status();

                        if ($statusCode === 204 || $response->successful()) {
                            $radiusSubmitted = true;
                            $radiusError = null;
                            break;
                        }

                        $radiusError = 'HTTP ' . $statusCode . ': ' . $response->body();
                        $lastFailureWasConnection = false;
                        \Log::channel('radiusrelated')->error('RADIUS API Error for JobOrder: ' . $id, [
                            'status' => $statusCode,
                            'response' => $response->body(),
                            'payload' => $payload,
                            'position' => $position,
                            'attempt' => $attempt,
                            'radius_config_id' => $radiusConfig->id,
                            'radius_ip' => $radiusConfig->ip,
                        ]);
                    } catch (\Exception $mikrotikException) {
                        $radiusError = $mikrotikException->getMessage();
                        $lastFailureWasConnection = true;
                        \Log::channel('radiusrelated')->error('RADIUS Connection Exception for JobOrder: ' . $id, [
                            'error' => $radiusError,
                            'trace' => $mikrotikException->getTraceAsString(),
                            'position' => $position,
                            'attempt' => $attempt,
                            'radius_config_id' => $radiusConfig->id,
                            'radius_ip' => $radiusConfig->ip,
                        ]);
                    }
                }

                if ($radiusSubmitted) {
                    break;
                }
            }

            if (!$radiusSubmitted && !$credentialsExist) {
                return [
                    'success' => false,
                    'message' => $lastFailureWasConnection
                        ? 'Failed to connect to RADIUS server'
                        : 'Failed to create RADIUS account',
                    'error' => $radiusError,
                ];
            }

            return [
                'success' => true,
                'message' => $credentialsExist ? 'RADIUS credentials already exist' : 'RADIUS account created successfully',
                'data' => [
                    'job_order_id' => $id,
                    'username' => $pppoeUsername,
                    'password' => $pppoePassword,
                    'group' => $plan,
                    'credentials_exist' => $credentialsExist,
                    'radius_response' => [
                        'submitted' => $radiusSubmitted,
                        'status' => $radiusSubmitted ? 'success' : 'failed',
                        'error' => $radiusError
                    ]
                ]
            ];

        } catch (\Exception $e) {
            \Log::channel('radiusrelated')->error('=== RADIUS ACCOUNT CREATION INTERNAL FAILED ===', [
                'job_order_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to create RADIUS account',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send Welcome SMS notification just like in TransactionController
     */
    private function sendWelcomeSms($customer, $accountNumber, $pppoeUsername, $pppoePassword, $planName)
    {
        try {
            if ($customer && !empty($customer->contact_number_primary)) {
                $welcomeTemplate = DB::table('sms_templates')
                    ->where('template_type', 'Welcome')
                    ->where('is_active', 1)
                    ->first();
                    
                if ($welcomeTemplate) {
                    $smsService = new \App\Services\ItexmoSmsService();
                    $message = $welcomeTemplate->message_content;
                    
                    // Replace variables
                    $customerName = preg_replace('/\s+/', ' ', trim($customer->full_name));
                    $message = str_replace('{{customer_name}}', $customerName, $message);
                    $message = str_replace('{{customer_tag}}', $customerName, $message);
                    $message = str_replace('{{account_no}}', $accountNumber, $message);
                    $message = str_replace('{{username}}', $pppoeUsername, $message);
                    $message = str_replace('{{password}}', $pppoePassword, $message);
                    
                    $displayPlan = $planName ?? 'N/A';
                    if (strpos($displayPlan, ' - P') !== false) {
                        $displayPlan = trim(explode(' - P', $displayPlan)[0]);
                    }
                    $displayPlan = str_replace('₱', 'P', $displayPlan);
                    $message = str_replace('{{plan_name}}', $displayPlan, $message);
                    
                    // Support more variables like in TransactionController
                    $currentDate = date('Y-m-d');
                    $message = str_replace('{{date}}', $currentDate, $message);
                    $message = str_replace('{{payment_date}}', $currentDate, $message);
                    
                    $message = $this->replaceGlobalVariables($message);
                    
                    $result = $smsService->send([
                        'contact_no' => $customer->contact_number_primary,
                        'message' => $message
                    ]);
                    
                    if ($result['success']) {
                        \Log::info('Welcome SMS sent successfully', [
                             'customer_id' => $customer->id,
                             'account_no' => $accountNumber
                        ]);
                    } else {
                        \Log::error('Welcome SMS failed to send: ' . ($result['error'] ?? 'Unknown error'));
                    }
                } else {
                    \Log::warning('Welcome SMS template not found or inactive');
                }
            }
        } catch (\Exception $e) {
            \Log::error('Failed to send Welcome SMS: ' . $e->getMessage());
        }
    }

    /**
     * Replace global placeholders in SMS messages
     */
    private function replaceGlobalVariables(string $message): string
    {
        $portalUrl = 'sync.atssfiber.ph';
        $brandName = DB::table('form_ui')->value('brand_name') ?? 'Your ISP';

        $message = str_replace('{{portal_url}}', $portalUrl, $message);
        $message = str_replace('{{company_name}}', $brandName, $message);

        return $message;
    }

    /**
     * Record an audit trail entry when a blocked technician reassignment is attempted.
     *
     * Fired by the front-end Job Order Done form when an admin tries to reassign the
     * technician after the job has already been started (start_time is set).
     */
    public function logBlockedTransfer(Request $request, $id): JsonResponse
    {
        try {
            $performedBy = $request->input('performed_by')
                ?? optional($request->user())->email_address
                ?? optional($request->user())->email
                ?? 'System';

            $originalTechName  = $request->input('original_technician_name');
            $originalTechEmail = $request->input('original_technician_email');
            $newTechName       = $request->input('new_technician_name');
            $newTechEmail      = $request->input('new_technician_email');
            $startTime         = $request->input('start_time');

            $description = $request->input('description')
                ?? "Save blocked — Technician reassignment attempted on Job Order #{$id} by {$performedBy}. "
                 . "The original technician " . ($originalTechName ?: $originalTechEmail ?: 'Unknown')
                 . " has already started the job (start_time: " . ($startTime ?: 'N/A') . "). Transfer not allowed.";

            AuditTrailLog::create([
                'old_details' => null,
                'new_details' => [
                    'type'                   => 'joborders',
                    'id'                     => $id,
                    'action'                 => 'technician_reassignment_blocked',
                    'module'                 => 'Job Orders',
                    'description'            => $description,
                    'performed_by'           => $performedBy,
                    'job_order_id'           => $id,
                    'start_time'             => $startTime,
                    'original_technician'    => [
                        'name'  => $originalTechName,
                        'email' => $originalTechEmail,
                    ],
                    'attempted_technician'   => [
                        'name'  => $newTechName,
                        'email' => $newTechEmail,
                    ],
                ],
                'created_by_user' => $performedBy,
                'updated_by_user' => $performedBy,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Blocked transfer logged'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log blocked technician transfer', [
                'job_order_id' => $id,
                'error'        => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to log blocked transfer',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validate Modem Router SN for duplicates
     */
    public function validateModemRouterSN(Request $request): JsonResponse
    {
        try {
            $sn = $request->query('sn');
            $excludeId = $request->query('exclude_id');

            if (!$sn) {
                return response()->json([
                    'success' => false,
                    'message' => 'SN is required'
                ], 422);
            }

            // Check job_orders table
            $existsInJobOrders = JobOrder::where('modem_router_sn', $sn)
                ->when($excludeId, function ($query) use ($excludeId) {
                    return $query->where('id', '!=', $excludeId);
                })
                ->exists();

            if ($existsInJobOrders) {
                return response()->json([
                    'success' => false,
                    'is_duplicate' => true,
                    'source' => 'job_orders',
                    'message' => 'Please check on Customer Details. SN Duplicate Detected in Job Orders.'
                ]);
            }

            // Check technical_details table (modem_router_sn field mapping might be router_modem_sn or ip_address etc)
            // Let's check common field names for SN in technical_details
            $existsInTechnicalDetails = TechnicalDetail::where('router_modem_sn', $sn)->exists();

            if ($existsInTechnicalDetails) {
                return response()->json([
                    'success' => false,
                    'is_duplicate' => true,
                    'source' => 'technical_details',
                    'message' => 'Please check on Customer Details. SN Duplicate Detected in Technical Details.'
                ]);
            }

            return response()->json([
                'success' => true,
                'is_duplicate' => false,
                'message' => 'SN is available'
            ]);
        } catch (\Exception $e) {
            \Log::error('Modem SN Validation Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error validating SN'
            ], 500);
        }
    }

    public function broadcastViewing(Request $request)
    {
        try {
            $jobOrderId = $request->input('job_order_id');
            $action = $request->input('action', 'started_viewing');
            $username = auth()->user()->username ?? 'Guest';

            event(new JobOrderViewingUpdate($jobOrderId, $username, $action));

            return response()->json([
                'success' => true,
                'message' => 'Viewing update broadcasted'
            ]);
        } catch (\Throwable $e) {
            \Log::error('[Presence] broadcastViewing error: ' . $e->getMessage(), [
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
