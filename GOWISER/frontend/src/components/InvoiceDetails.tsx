import React, { useState, useEffect, useRef } from 'react';
import { ExternalLink, X, Info, ChevronDown, ChevronRight, ChevronLeft, CircleArrowRight, Loader } from 'lucide-react';
import { settingsColorPaletteService, ColorPalette } from '../services/settingsColorPaletteService';
import { relatedDataService } from '../services/relatedDataService';
import RelatedDataTable from './RelatedDataTable';
import { relatedDataColumns } from '../config/relatedDataColumns';
import { planService, Plan } from '../services/planService';
import { getCustomerDetail, CustomerDetailData } from '../services/customerDetailService';
import { BillingDetailRecord } from '../types/billing';

const PlanListDetails = React.lazy(() => import('./PlanListDetails'));
const CustomerDetails = React.lazy(() => import('./CustomerDetails'));
const NotFoundModal = React.lazy(() => import('../modals/NotFoundModal'));

const formatDate = (dateString: string | null | undefined): string => {
  if (!dateString) return '-';
  try {
    const date = new Date(dateString);
    if (isNaN(date.getTime())) return dateString;
    const mm = String(date.getMonth() + 1).padStart(2, '0');
    const dd = String(date.getDate()).padStart(2, '0');
    const yyyy = date.getFullYear();
    return `${mm}/${dd}/${yyyy}`;
  } catch (e) {
    return dateString;
  }
};

const convertCustomerDataToBillingDetail = (customerData: CustomerDetailData): BillingDetailRecord => {
  return {
    id: customerData.billingAccount?.accountNo || '',
    applicationId: customerData.billingAccount?.accountNo || '',
    customerName: customerData.fullName,
    address: customerData.address,
    status: customerData.billingAccount?.billingStatusId === 2 ? 'Active' : 'Inactive',
    balance: customerData.billingAccount?.accountBalance || 0,
    onlineStatus: customerData.billingAccount?.billingStatusId === 2 ? 'Online' : 'Offline',
    cityId: null,
    regionId: null,
    timestamp: customerData.updatedAt || '',
    billingStatus: customerData.billingAccount?.billingStatusId ? ({ 1: 'In Progress', 2: 'Active', 3: 'Suspended', 4: 'Cancelled', 5: 'Overdue', 6: 'Service Account' }[customerData.billingAccount.billingStatusId] || `Status ${customerData.billingAccount.billingStatusId}`) : '',
    dateInstalled: customerData.billingAccount?.dateInstalled || '',
    contactNumber: customerData.contactNumberPrimary,
    secondContactNumber: customerData.contactNumberSecondary || '',
    emailAddress: customerData.emailAddress || '',
    plan: customerData.desiredPlan || '',
    username: customerData.technicalDetails?.username || '',
    connectionType: customerData.technicalDetails?.connectionType || '',
    routerModel: customerData.technicalDetails?.routerModel || '',
    routerModemSN: customerData.technicalDetails?.routerModemSn || '',
    lcpnap: customerData.technicalDetails?.lcpnap || '',
    port: customerData.technicalDetails?.port || '',
    vlan: customerData.technicalDetails?.vlan || '',
    billingDay: customerData.billingAccount?.billingDay || 0,
    totalPaid: 0,
    provider: '',
    lcp: customerData.technicalDetails?.lcp || '',
    nap: customerData.technicalDetails?.nap || '',
    modifiedBy: '',
    modifiedDate: customerData.updatedAt || '',
    barangay: customerData.barangay || '',
    city: customerData.city || '',
    region: customerData.region || '',
    usageType: customerData.technicalDetails?.usageTypeId ? `Type ${customerData.technicalDetails.usageTypeId}` : '',
    referredBy: customerData.referredBy || '',
    referralContactNo: '',
    groupName: customerData.groupName || '',
    mikrotikId: '',
    sessionIp: customerData.technicalDetails?.ipAddress || '',
    houseFrontPicture: customerData.houseFrontPictureUrl || '',
    accountBalance: customerData.billingAccount?.accountBalance || 0,
    housingStatus: customerData.housingStatus || '',
    addressCoordinates: customerData.addressCoordinates || '',
    vip_expiration: customerData.billingAccount?.vip_expiration || '',
    vip_remarks: customerData.billingAccount?.vip_remarks || '',
  };
};


interface InvoiceRecord {
  id: string;
  invoiceDate: string;
  invoiceStatus: string;
  accountNo: string;
  fullName: string;
  contactNumber: string;
  emailAddress: string;
  address: string;
  plan: string;
  dateInstalled?: string;
  provider?: string;
  invoiceNo?: string;
  invoiceBalance?: number;
  serviceCharge?: number;
  rebate?: number;
  discounts?: number;
  staggered?: number;
  totalAmountDue?: number;
  dueDate?: string;
  invoicePayment?: number;
  paymentMethod?: string;
  dateProcessed?: string;
  processedBy?: string;
  remarks?: string;
  vat?: number;
  /**
   * VAT snapshot taken when the invoice was issued. Absent on invoices predating the snapshot
   * columns, which is why the UI treats "no netAmount" as "breakdown unavailable" rather than
   * recomputing a figure that was never charged.
   */
  vatRate?: number | null;
  vatBaseAmount?: number | null;
  netAmount?: number | null;
  vatAmount?: number | null;
  amountDue?: number;
  balanceFromPreviousBill?: number;
  paymentReceived?: number;
  remainingBalance?: number;
  monthlyServiceFee?: number;
  staggeredPaymentsCount?: number;
}

interface InvoiceDetailsProps {
  invoiceRecord: InvoiceRecord;
  onViewCustomer?: (accountNo: string) => void;
  onClose?: () => void;
  onPrevious?: () => void;
  onNext?: () => void;
}

const InvoiceDetails: React.FC<InvoiceDetailsProps> = ({ invoiceRecord, onViewCustomer, onClose, onPrevious, onNext }) => {
  const [isDarkMode, setIsDarkMode] = useState(false);
  const [detailsWidth, setDetailsWidth] = useState<number>(600);
  const [isResizing, setIsResizing] = useState<boolean>(false);
  const [colorPalette, setColorPalette] = useState<ColorPalette | null>(null);
  const [isMobile, setIsMobile] = useState<boolean>(false);
  const startXRef = useRef<number>(0);
  const startWidthRef = useRef<number>(0);

  useEffect(() => {
    const handleResize = () => {
      setIsMobile(window.innerWidth < 768);
    };
    handleResize();
    window.addEventListener('resize', handleResize);
    return () => window.removeEventListener('resize', handleResize);
  }, []);

  // Related staggered payments state
  const [expandedStaggered, setExpandedStaggered] = useState(true);
  const [relatedStaggered, setRelatedStaggered] = useState<any[]>([]);
  const [fullRelatedStaggered, setFullRelatedStaggered] = useState<any[]>([]);
  const [staggeredCount, setStaggeredCount] = useState(0);
  const [expandedModalSection, setExpandedModalSection] = useState<string | null>(null);

  // Overlay states
  const [loadingPlanOverlay, setLoadingPlanOverlay] = useState(false);
  const [selectedPlanForOverlay, setSelectedPlanForOverlay] = useState<Plan | null>(null);

  const [loadingCustomerOverlay, setLoadingCustomerOverlay] = useState(false);
  const [selectedCustomerForOverlay, setSelectedCustomerForOverlay] = useState<BillingDetailRecord | null>(null);
  const [notFoundMessage, setNotFoundMessage] = useState<string | null>(null);

  const hasActiveOverlay = selectedPlanForOverlay || selectedCustomerForOverlay || loadingPlanOverlay || loadingCustomerOverlay;

  useEffect(() => {
    const checkDarkMode = () => {
      const theme = localStorage.getItem('theme');
      setIsDarkMode(theme === 'dark');
    };

    checkDarkMode();

    const observer = new MutationObserver(checkDarkMode);
    observer.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ['class']
    });

    return () => observer.disconnect();
  }, []);

  useEffect(() => {
    const fetchColorPalette = async () => {
      try {
        const activePalette = await settingsColorPaletteService.getActive();
        setColorPalette(activePalette);
      } catch (err) {
        console.error('Failed to fetch color palette:', err);
      }
    };

    fetchColorPalette();
  }, []);

  // Fetch related staggered payments when account number changes
  useEffect(() => {
    const fetchRelatedStaggered = async () => {
      if (!invoiceRecord.accountNo) {
        return;
      }

      const accountNo = invoiceRecord.accountNo;

      try {
        const result = await relatedDataService.getRelatedStaggered(accountNo);
        // Store full data for modal view
        setFullRelatedStaggered(result.data || []);
        // Limit to 5 latest items for dropdown display
        setRelatedStaggered((result.data || []).slice(0, 5));
        setStaggeredCount(result.count || 0);
      } catch (error) {
        console.error('❌ Error fetching staggered payments:', error);
        setRelatedStaggered([]);
        setFullRelatedStaggered([]);
        setStaggeredCount(0);
      }
    };

    fetchRelatedStaggered();
  }, [invoiceRecord.accountNo]);

  useEffect(() => {
    if (!isResizing) return;

    const handleMouseMove = (e: MouseEvent) => {
      if (!isResizing) return;

      const diff = startXRef.current - e.clientX;
      const newWidth = Math.max(600, Math.min(1200, startWidthRef.current + diff));

      setDetailsWidth(newWidth);
    };

    const handleMouseUp = () => {
      setIsResizing(false);
    };

    document.addEventListener('mousemove', handleMouseMove);
    document.addEventListener('mouseup', handleMouseUp);

    return () => {
      document.removeEventListener('mousemove', handleMouseMove);
      document.removeEventListener('mouseup', handleMouseUp);
    };
  }, [isResizing]);

  const handleMouseDownResize = (e: React.MouseEvent) => {
    e.preventDefault();
    setIsResizing(true);
    startXRef.current = e.clientX;
    startWidthRef.current = detailsWidth;
  };

  const handleExpandModalOpen = (sectionKey: string) => {
    setExpandedModalSection(sectionKey);
  };

  const handleExpandModalClose = () => {
    setExpandedModalSection(null);
  };

  return (
    <div className={`flex flex-col relative md:border-l overflow-hidden ${
      isMobile ? 'fixed inset-0 z-[9999] w-screen h-[100dvh] max-h-[100dvh]' : 'h-full'
    } ${isDarkMode
      ? 'bg-gray-900 text-white border-white border-opacity-30'
      : 'bg-white text-gray-900 border-gray-300'
      }`} style={{ width: isMobile ? '100%' : `${detailsWidth}px` }}>
      {!isMobile && (
        <div
          className="absolute left-0 top-0 bottom-0 w-1 cursor-col-resize transition-colors z-50"
          style={{
            backgroundColor: isResizing ? (colorPalette?.primary || '#7c3aed') : 'transparent'
          }}
          onMouseEnter={(e) => {
            if (!isResizing) {
              e.currentTarget.style.backgroundColor = colorPalette?.accent || '#7c3aed';
            }
          }}
          onMouseLeave={(e) => {
            if (!isResizing) {
              e.currentTarget.style.backgroundColor = 'transparent';
            }
          }}
          onMouseDown={handleMouseDownResize}
        />
      )}
      {/* Header with Invoice No and Actions */}
      <div className={`px-4 py-3 flex items-center justify-between border-b ${isDarkMode
        ? 'bg-gray-800 border-gray-700'
        : 'bg-gray-100 border-gray-200'
        }`}>
        {invoiceRecord.invoiceNo || invoiceRecord.id}
        <div className="flex items-center space-x-2 flex-shrink-0">
          <div className="flex items-center overflow-hidden mr-1">
            <button
              onClick={(e) => {
                e.stopPropagation();
                onPrevious?.();
              }}
              disabled={!onPrevious}
              className={`p-2 transition-colors ${!onPrevious
                ? (isDarkMode ? 'text-gray-600 bg-gray-800' : 'text-gray-300 bg-gray-50')
                : (isDarkMode ? 'text-gray-300 hover:bg-gray-700 hover:text-white' : 'text-gray-600 hover:bg-gray-200 hover:text-gray-900')
                }`}
              title="Previous Record"
            >
              <ChevronLeft size={18} />
            </button>
            <button
              onClick={(e) => {
                e.stopPropagation();
                onNext?.();
              }}
              disabled={!onNext}
              className={`p-2 transition-colors ${!onNext
                ? (isDarkMode ? 'text-gray-600 bg-gray-800' : 'text-gray-300 bg-gray-50')
                : (isDarkMode ? 'text-gray-300 hover:bg-gray-700 hover:text-white' : 'text-gray-600 hover:bg-gray-200 hover:text-gray-900')
                }`}
              title="Next Record"
            >

              <ChevronRight size={18} />
            </button>
          </div>
          <button
            onClick={onClose}
            className={`p-2 rounded transition-colors ${isDarkMode
              ? 'text-gray-400 hover:text-white hover:bg-gray-700'
              : 'text-gray-600 hover:text-gray-900 hover:bg-gray-200'
              }`}>
            <X size={18} />
          </button>
        </div>
      </div>

      {/* Content */}
      <div className={`flex-1 overflow-y-auto ${isMobile ? 'pb-24' : ''}`}>
        <div className={`divide-y ${isDarkMode ? 'divide-gray-800' : 'divide-gray-200'
          }`}>
          {/* Invoice Info */}
          <div className="px-5 py-4">
            <div className="flex justify-between items-center py-2">
              <span className={isDarkMode ? 'text-gray-400' : 'text-gray-600'}>Invoice No.</span>
              <span className={isDarkMode ? 'text-white' : 'text-gray-900'}>{invoiceRecord.invoiceNo || invoiceRecord.id}</span>
            </div>

            <div className="flex justify-between items-center py-2">
              <span className={isDarkMode ? 'text-gray-400' : 'text-gray-600'}>Account No.</span>
              <div className="flex items-center gap-2">
                <span className="text-red-500">
                  {invoiceRecord.accountNo}
                </span>
                <button
                  onClick={async () => {
                    try {
                      setLoadingCustomerOverlay(true);
                      const details = await getCustomerDetail(invoiceRecord.accountNo);
                      if (details) {
                        setSelectedCustomerForOverlay(convertCustomerDataToBillingDetail(details));
                      } else {
                        setNotFoundMessage('Customer details not found.');
                      }
                    } catch (err) {
                      console.error('Error finding customer', err);
                    } finally {
                      setLoadingCustomerOverlay(false);
                    }
                  }}
                  className={`p-1 rounded transition-colors ${loadingCustomerOverlay ? 'opacity-50 cursor-not-allowed' : isDarkMode ? 'hover:bg-gray-700 text-white' : 'hover:bg-gray-100 text-gray-900'}`}
                  title="View Customer Details"
                  disabled={loadingCustomerOverlay}
                >
                  {loadingCustomerOverlay ? <Loader className="w-4 h-4 animate-spin" /> : <CircleArrowRight size={16} />}
                </button>
              </div>
            </div>

            <div className="flex justify-between items-center py-2 gap-4">
              <span className={`flex-shrink-0 ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}`}>Full Name</span>
              <span className={`text-right truncate max-w-[60%] ${isDarkMode ? 'text-white' : 'text-gray-900'}`} title={invoiceRecord.fullName}>{invoiceRecord.fullName}</span>
            </div>

            <div className="flex justify-between items-center py-2">
              <span className={isDarkMode ? 'text-gray-400' : 'text-gray-600'}>Invoice Date</span>
              <span className={isDarkMode ? 'text-white' : 'text-gray-900'}>{formatDate(invoiceRecord.invoiceDate)}</span>
            </div>

            <div className="flex justify-between items-center py-2">
              <span className={isDarkMode ? 'text-gray-400' : 'text-gray-600'}>Contact Number</span>
              <span className={isDarkMode ? 'text-white' : 'text-gray-900'}>{invoiceRecord.contactNumber}</span>
            </div>

            <div className="flex justify-between items-center py-2 gap-4">
              <span className={`flex-shrink-0 ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}`}>Email Address</span>
              <span className={`text-right truncate max-w-[60%] ${isDarkMode ? 'text-white' : 'text-gray-900'}`} title={invoiceRecord.emailAddress}>{invoiceRecord.emailAddress}</span>
            </div>

            <div className="flex justify-between items-center py-2">
              <span className={isDarkMode ? 'text-gray-400' : 'text-gray-600'}>Plan</span>
              <div className="flex items-center gap-2">
                <span className={isDarkMode ? 'text-white' : 'text-gray-900'}>{invoiceRecord.plan}</span>
                <button
                  onClick={async () => {
                    if (!invoiceRecord.plan || invoiceRecord.plan === '-') return;
                    try {
                      setLoadingPlanOverlay(true);
                      const allPlans = (await planService.getAllPlans()) || [];
                      const match = allPlans.find((p: Plan) =>
                        p.name.toLowerCase() === invoiceRecord.plan.toLowerCase() ||
                        p.name.toLowerCase().includes(invoiceRecord.plan.toLowerCase()) ||
                        invoiceRecord.plan.toLowerCase().includes(p.name.toLowerCase())
                      );
                      if (match) {
                        setSelectedPlanForOverlay(match);
                      } else {
                        setNotFoundMessage('Plan details not found.');
                      }
                    } catch (err) {
                      console.error('Error finding plan', err);
                    } finally {
                      setLoadingPlanOverlay(false);
                    }
                  }}
                  className={`p-1 rounded transition-colors ${loadingPlanOverlay ? 'opacity-50 cursor-not-allowed' : isDarkMode ? 'hover:bg-gray-700 text-white' : 'hover:bg-gray-100 text-gray-900'}`}
                  title="View Plan Details"
                  disabled={loadingPlanOverlay || !invoiceRecord.plan || invoiceRecord.plan === '-'}
                >
                  {loadingPlanOverlay ? <Loader className="w-4 h-4 animate-spin" /> : <CircleArrowRight size={16} />}
                </button>
              </div>
            </div>

            <div className="flex justify-between items-center py-2">
              <span className={isDarkMode ? 'text-gray-400' : 'text-gray-600'}>Remarks</span>
              <span className={isDarkMode ? 'text-white' : 'text-gray-900'}>{invoiceRecord.remarks || 'System Generated'}</span>
            </div>

            <div className="flex justify-between items-center py-2">
              <span className={isDarkMode ? 'text-gray-400' : 'text-gray-600'}>Invoice Balance</span>
              <span className={isDarkMode ? 'text-white' : 'text-gray-900'}>
                ₱{Number(invoiceRecord.invoiceBalance || 0).toFixed(2)}
              </span>
            </div>

            <div className="flex justify-between items-center py-2">
              <span className={isDarkMode ? 'text-gray-400' : 'text-gray-600'}>Invoice Status</span>
              <span className={`${invoiceRecord.invoiceStatus === 'Unpaid' ? 'text-red-500' : 'text-green-500'}`}>
                {invoiceRecord.invoiceStatus || 'Unpaid'}
              </span>
            </div>

            {/*
              VAT breakdown of the recurring service charge, as snapshotted at issue time.
              Rendered only when the invoice actually carries a snapshot: invoices issued before
              the snapshot columns existed have no recorded VAT split, and showing a recomputed
              one would misrepresent what the customer was charged.
            */}
            {invoiceRecord.netAmount !== null && invoiceRecord.netAmount !== undefined && (
              <>
                <div className="flex justify-between items-center py-2">
                  <span className={isDarkMode ? 'text-gray-400' : 'text-gray-600'}>Service Charge (Net of VAT)</span>
                  <span className={isDarkMode ? 'text-white' : 'text-gray-900'}>
                    ₱{Number(invoiceRecord.netAmount || 0).toFixed(2)}
                  </span>
                </div>

                <div className="flex justify-between items-center py-2">
                  <span className={isDarkMode ? 'text-gray-400' : 'text-gray-600'}>
                    VAT
                    {invoiceRecord.vatRate !== null && invoiceRecord.vatRate !== undefined && (
                      <span className={`ml-1 text-xs ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                        ({(Number(invoiceRecord.vatRate) * 100).toFixed(2).replace(/\.00$/, '')}%)
                      </span>
                    )}
                  </span>
                  <span className={isDarkMode ? 'text-white' : 'text-gray-900'}>
                    ₱{Number(invoiceRecord.vatAmount || 0).toFixed(2)}
                  </span>
                </div>
              </>
            )}

            <div className="flex justify-between items-center py-2">
              <span className={isDarkMode ? 'text-gray-400' : 'text-gray-600'}>Service Charge</span>
              <span className={isDarkMode ? 'text-white' : 'text-gray-900'}>
                ₱{Number(invoiceRecord.serviceCharge || 0).toFixed(2)}
              </span>
            </div>

            <div className="flex justify-between items-center py-2">
              <span className={isDarkMode ? 'text-gray-400' : 'text-gray-600'}>Rebate</span>
              <span className={isDarkMode ? 'text-white' : 'text-gray-900'}>
                ₱{Number(invoiceRecord.rebate || 0).toFixed(2)}
              </span>
            </div>

            <div className="flex justify-between items-center py-2">
              <span className={isDarkMode ? 'text-gray-400' : 'text-gray-600'}>Discounts</span>
              <span className={isDarkMode ? 'text-white' : 'text-gray-900'}>
                ₱{Number(invoiceRecord.discounts || 0).toFixed(2)}
              </span>
            </div>

            <div className="flex justify-between items-center py-2">
              <span className={isDarkMode ? 'text-gray-400' : 'text-gray-600'}>Staggered</span>
              <span className={isDarkMode ? 'text-white' : 'text-gray-900'}>
                ₱{Number(invoiceRecord.staggered || 0).toFixed(2)}
              </span>
            </div>

            <div className="flex justify-between items-center py-2">
              <span className={isDarkMode ? 'text-gray-400' : 'text-gray-600'}>Total Amount</span>
              <span className={isDarkMode ? 'text-white' : 'text-gray-900'}>
                ₱{Number(invoiceRecord.totalAmountDue || 0).toFixed(2)}
              </span>
            </div>

            <div className="flex justify-between items-center py-2">
              <span className={isDarkMode ? 'text-gray-400' : 'text-gray-600'}>Invoice Payment</span>
              <span className={isDarkMode ? 'text-white' : 'text-gray-900'}>
                ₱{Number(invoiceRecord.invoicePayment || 0).toFixed(2)}
              </span>
            </div>

            <div className="flex justify-between items-center py-2">
              <span className={isDarkMode ? 'text-gray-400' : 'text-gray-600'}>Due Date</span>
              <span className={isDarkMode ? 'text-white' : 'text-gray-900'}>{formatDate(invoiceRecord.dueDate)}</span>
            </div>
          </div>
        </div>
      </div>

      {/* Related Staggered Payments Section */}
      <div className={`mt-auto border-t ${isDarkMode ? 'border-gray-800' : 'border-gray-200'
        }`}>
        <div className={`border-b ${isDarkMode ? 'border-gray-700' : 'border-gray-200'
          }`}>
          <div className={`w-full px-5 py-3 flex items-center justify-between ${isDarkMode ? 'hover:bg-gray-800' : 'hover:bg-gray-100'
            }`}>
            <div className="flex items-center space-x-2">
              <span className={`font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'
                }`}>Related Staggered Payments</span>
              <span className={`text-xs px-2 py-1 rounded ${isDarkMode
                ? 'bg-gray-600 text-white'
                : 'bg-gray-300 text-gray-900'
                }`}>{staggeredCount}</span>
            </div>
            <div className="flex items-center space-x-2">
              <button
                onClick={(e) => {
                  e.stopPropagation();
                  handleExpandModalOpen('staggered');
                }}
                className={`text-sm transition-colors hover:underline ${isDarkMode ? 'text-gray-400 hover:text-gray-300' : 'text-gray-600 hover:text-gray-500'
                  }`}
              >
                Expand
              </button>
              <div className="flex items-center">
                <ChevronDown size={20} className={isDarkMode ? 'text-gray-400' : 'text-gray-600'} />
              </div>
            </div>
          </div>

          {expandedStaggered && (
            <div className="px-5 pb-4">
              <RelatedDataTable
                data={relatedStaggered}
                columns={relatedDataColumns.staggered}
                isDarkMode={isDarkMode}
              />
            </div>
          )}
        </div>
      </div>

      {/* Expanded Modal for Related Data */}
      {expandedModalSection && (
        <div className="absolute inset-0 flex flex-col" style={{ backgroundColor: isDarkMode ? '#111827' : '#ffffff', zIndex: 9999 }}>
          {/* Modal Header */}
          <div className={`px-6 py-4 flex items-center justify-between border-b ${isDarkMode ? 'bg-gray-800 border-gray-700' : 'bg-gray-100 border-gray-200'
            }`}>
            <div className="flex items-center space-x-3">
              <h2 className={`text-lg font-semibold ${isDarkMode ? 'text-white' : 'text-gray-900'
                }`}>
                All Related Staggered Payments
              </h2>
              <span className={`text-xs px-2 py-1 rounded ${isDarkMode
                ? 'bg-gray-600 text-white'
                : 'bg-gray-300 text-gray-900'
                }`}>
                {staggeredCount} items
              </span>
            </div>
            <button
              onClick={handleExpandModalClose}
              className={`p-2 rounded transition-colors ${isDarkMode
                ? 'text-gray-400 hover:text-white hover:bg-gray-700'
                : 'text-gray-600 hover:text-gray-900 hover:bg-gray-200'
                }`}
            >
              <X size={20} />
            </button>
          </div>

          {/* Modal Content */}
          <div className="flex-1 overflow-y-auto p-6">
            <RelatedDataTable
              data={fullRelatedStaggered}
              columns={relatedDataColumns.staggered}
              isDarkMode={isDarkMode}
            />
          </div>
        </div>
      )}

      {/* Embedded Overlays */}
      {hasActiveOverlay && (
        <div className="absolute inset-0 z-50 bg-white dark:bg-gray-900 overflow-hidden flex flex-col h-full w-full">
          {loadingPlanOverlay && (
            <div className={`h-full w-full flex items-center justify-center ${isDarkMode ? 'bg-gray-900 text-gray-400' : 'bg-white text-gray-500'}`}>
              <div className="flex flex-col items-center gap-3">
                <p className="loading-dots pt-4">Loading Plan Details</p>
              </div>
            </div>
          )}
          {loadingCustomerOverlay && (
            <div className={`h-full w-full flex items-center justify-center ${isDarkMode ? 'bg-gray-900 text-gray-400' : 'bg-white text-gray-500'}`}>
              <div className="flex flex-col items-center gap-3">
                <p className="loading-dots pt-4">Loading Customer Details</p>
              </div>
            </div>
          )}
          {selectedPlanForOverlay && (
            <React.Suspense fallback={
              <div className={`h-full w-full flex items-center justify-center ${isDarkMode ? 'bg-gray-900 text-gray-400' : 'bg-white text-gray-500'}`}>
                <div className="flex flex-col items-center gap-3">
                  <p className="loading-dots pt-4">Loading Plan Overlay</p>
                </div>
              </div>
            }>
              <div className="w-full h-full relative border-0">
                <PlanListDetails
                  plan={selectedPlanForOverlay}
                  onClose={() => setSelectedPlanForOverlay(null)}
                  isMobile={isMobile}
                />
              </div>
            </React.Suspense>
          )}
          {selectedCustomerForOverlay && (
            <React.Suspense fallback={
              <div className={`h-full w-full flex items-center justify-center ${isDarkMode ? 'bg-gray-900 text-gray-400' : 'bg-white text-gray-500'}`}>
                <div className="flex flex-col items-center gap-3">
                  <p className="loading-dots pt-4">Loading Customer Overlay</p>
                </div>
              </div>
            }>
              <div className="w-full h-full relative border-0">
                <CustomerDetails
                  billingRecord={selectedCustomerForOverlay}
                  onClose={() => setSelectedCustomerForOverlay(null)}
                />
              </div>
            </React.Suspense>
          )}
        </div>
      )}

      {/* Not Found Modal */}
      <React.Suspense fallback={null}>
        <NotFoundModal
          isOpen={!!notFoundMessage}
          onClose={() => setNotFoundMessage(null)}
          message={notFoundMessage || ''}
        />
      </React.Suspense>
    </div>
  );
};

export default InvoiceDetails;