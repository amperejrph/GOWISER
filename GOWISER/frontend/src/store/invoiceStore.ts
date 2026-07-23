import { create } from 'zustand';
import { invoiceService, InvoiceRecord } from '../services/invoiceService';

// Define the UI interface that components use
export interface InvoiceRecordUI {
    id: string;
    accountNo: string;
    invoiceDate: string;
    invoiceDateRaw?: string;
    invoiceBalance: number;
    serviceCharge: number;
    /**
     * VAT snapshot taken when the invoice was issued, so a later change to the configured rate
     * cannot reinterpret it. null on invoices predating the snapshot columns — distinct from a
     * genuine zero, which is why these are nullable rather than defaulted to 0.
     */
    vatRate?: number | null;
    vatBaseAmount?: number | null;
    netAmount?: number | null;
    vatAmount?: number | null;
    rebate: number;
    discounts: number;
    staggered: number;
    totalAmount: number;
    receivedPayment: number;
    dueDate: string;
    dueDateRaw?: string;
    status: string;
    paymentPortalLogRef?: string;
    transactionId?: string;
    createdAt?: string;
    createdBy?: string;
    updatedAt?: string;
    updatedBy?: string;
    fullName: string;
    contactNumber: string;
    emailAddress: string;
    address: string;
    plan: string;
    dateInstalled?: string;
    barangay?: string;
    city?: string;
    region?: string;
    provider?: string;
    invoiceNo?: string;
    totalAmountDue?: number;
    invoicePayment?: number;
    paymentMethod?: string;
    dateProcessed?: string;
    dateProcessedRaw?: string;
    processedBy?: string;
    remarks?: string;
    vat?: number;
    amountDue?: number;
    balanceFromPreviousBill?: number;
    paymentReceived?: number;
    remainingBalance?: number;
    monthlyServiceFee?: number;
    staggeredPaymentsCount?: number;
    invoiceStatus: string;
    billingDay?: number;
    organization_id?: number | null;
}

const CHUNK_SIZE = 2000;

interface InvoiceStore {
    invoiceRecords: InvoiceRecordUI[];
    totalCount: number;
    isLoading: boolean;
    error: string | null;
    fetchInvoiceRecords: (force?: boolean) => Promise<void>;
    refreshInvoiceRecords: () => Promise<void>;
    silentRefresh: () => Promise<void>;
    pollLatestUpdates: () => Promise<void>;
    lastUpdated: Date | null;
}

export const useInvoiceStore = create<InvoiceStore>((set, get) => ({
    invoiceRecords: [],
    totalCount: 0,
    isLoading: false,
    error: null,
    lastUpdated: null,

    fetchInvoiceRecords: async (force = false) => {
        const { invoiceRecords, isLoading } = get();

        // If already loading or data already exists (and not forcing), skip
        if (isLoading || (invoiceRecords.length > 0 && !force)) return;

        set({ isLoading: true, error: null });

        try {
            // Transform helper
            const transform = (record: InvoiceRecord): InvoiceRecordUI => ({
                id: record.id.toString(),
                accountNo: record.account_no || record.account?.account_no || '',
                invoiceDate: record.invoice_date ? new Date(record.invoice_date).toLocaleDateString() : 'N/A',
                invoiceDateRaw: record.invoice_date,
                invoiceBalance: Number(record.invoice_balance) || 0,
                serviceCharge: Number(record.service_charge) || 0,
                rebate: Number(record.rebate) || 0,
                discounts: Number(record.discounts) || 0,
                staggered: Number(record.staggered) || 0,
                totalAmount: Number(record.total_amount) || 0,
                receivedPayment: Number(record.received_payment) || 0,
                dueDate: record.due_date ? new Date(record.due_date).toLocaleDateString() : 'N/A',
                dueDateRaw: record.due_date,
                status: record.status,
                paymentPortalLogRef: record.payment_portal_log_ref,
                transactionId: record.transaction_id,
                createdAt: record.created_at ? new Date(record.created_at).toLocaleString() : '',
                createdBy: record.created_by,
                updatedAt: record.updated_at ? new Date(record.updated_at).toLocaleString() : '',
                updatedBy: record.updated_by,
                fullName: record.account?.customer?.full_name || (record.account ? 'Loading...' : 'Unknown'),
                contactNumber: record.account?.customer?.contact_number_primary || 'N/A',
                emailAddress: record.account?.customer?.email_address || 'N/A',
                address: record.account?.customer?.address || 'N/A',
                plan: record.account?.customer?.desired_plan || 'No Plan',
                dateInstalled: record.account?.date_installed ? new Date(record.account.date_installed).toLocaleDateString() : '',
                barangay: record.account?.customer?.barangay || '',
                city: record.account?.customer?.city || '',
                region: record.account?.customer?.region || '',
                provider: 'SWITCH',
                invoiceNo: record.id.toString(),
                totalAmountDue: Number(record.total_amount) || 0,
                invoicePayment: Number(record.received_payment) || 0,
                paymentMethod: record.received_payment > 0 ? 'Payment Received' : 'N/A',
                dateProcessed: record.received_payment > 0 && record.updated_at ? new Date(record.updated_at).toLocaleDateString() : undefined,
                dateProcessedRaw: record.received_payment > 0 ? record.updated_at : undefined,
                processedBy: record.received_payment > 0 ? record.updated_by : undefined,
                remarks: 'System Generated',
                // Real VAT snapshot from the invoice. null (not 0) when the invoice
                // predates the snapshot columns, so the UI can hide the breakdown
                // instead of showing a misleading zero.
                vat: Number(record.vat_amount) || 0,
                vatRate: record.vat_rate === null || record.vat_rate === undefined ? null : Number(record.vat_rate),
                vatBaseAmount: record.vat_base_amount === null || record.vat_base_amount === undefined ? null : Number(record.vat_base_amount),
                netAmount: record.net_amount === null || record.net_amount === undefined ? null : Number(record.net_amount),
                vatAmount: record.vat_amount === null || record.vat_amount === undefined ? null : Number(record.vat_amount),
                amountDue: (Number(record.total_amount) || 0) - (Number(record.received_payment) || 0),
                balanceFromPreviousBill: 0,
                paymentReceived: Number(record.received_payment) || 0,
                remainingBalance: (Number(record.total_amount) || 0) - (Number(record.received_payment) || 0),
                monthlyServiceFee: Number(record.invoice_balance) || 0,
                staggeredPaymentsCount: 0,
                invoiceStatus: record.status,
                billingDay: record.account?.billing_day || 0,
                organization_id: record.organization_id,
            });

            // Initial fetch
            const result = await invoiceService.getAllInvoices(false, 1, CHUNK_SIZE);

            if (!result.success) {
                throw new Error(result.message || 'Failed to fetch invoices');
            }

            const dbTotal = result.total || 0;
            let allFetchedRecords = (result.data as InvoiceRecord[]).map(transform);

            set({
                invoiceRecords: allFetchedRecords,
                totalCount: dbTotal,
                isLoading: false,
                lastUpdated: new Date()
            });

            // Progressive background loading
            let currentPage = 2;
            let hasMore = allFetchedRecords.length < dbTotal;

            while (hasMore) {
                try {
                    const nextResult = await invoiceService.getAllInvoices(false, currentPage, CHUNK_SIZE);

                    if (nextResult && nextResult.success && nextResult.data && nextResult.data.length > 0) {
                        const nextBatch = (nextResult.data as InvoiceRecord[]).map(transform);
                        allFetchedRecords = [...allFetchedRecords, ...nextBatch];
                        set({
                            invoiceRecords: [...allFetchedRecords],
                            totalCount: nextResult.total || dbTotal
                        });

                        currentPage++;
                        hasMore = allFetchedRecords.length < dbTotal;
                    } else {
                        hasMore = false;
                    }
                } catch (chunkErr) {
                    console.error('Error in progressive invoice fetch:', chunkErr);
                    hasMore = false;
                }
            }
        } catch (err: any) {
            console.error('Error fetching invoice records:', err);
            set({
                error: err.message || 'Failed to load records',
                isLoading: false
            });
        }
    },

    refreshInvoiceRecords: async () => {
        set({ invoiceRecords: [] });
        await get().fetchInvoiceRecords(true);
    },

    silentRefresh: async () => {
        const { lastUpdated, invoiceRecords } = get();
        if (!lastUpdated || invoiceRecords.length === 0) {
            await get().fetchInvoiceRecords();
            return;
        }
        await get().pollLatestUpdates();
    },

    pollLatestUpdates: async () => {
        const { lastUpdated, invoiceRecords, totalCount } = get();
        if (!lastUpdated || invoiceRecords.length === 0) return;

        try {
            const isoString = lastUpdated.toISOString();
            const result = await invoiceService.getAllInvoices(false, 1, 1000, isoString);

            if (result && result.success && result.data && result.data.length > 0) {
                // Transform helper (same as in fetchInvoiceRecords)
                const transform = (record: InvoiceRecord): InvoiceRecordUI => ({
                    id: record.id.toString(),
                    accountNo: record.account_no || record.account?.account_no || '',
                    invoiceDate: record.invoice_date ? new Date(record.invoice_date).toLocaleDateString() : 'N/A',
                    invoiceDateRaw: record.invoice_date,
                    invoiceBalance: Number(record.invoice_balance) || 0,
                    serviceCharge: Number(record.service_charge) || 0,
                    rebate: Number(record.rebate) || 0,
                    discounts: Number(record.discounts) || 0,
                    staggered: Number(record.staggered) || 0,
                    totalAmount: Number(record.total_amount) || 0,
                    receivedPayment: Number(record.received_payment) || 0,
                    dueDate: record.due_date ? new Date(record.due_date).toLocaleDateString() : 'N/A',
                    dueDateRaw: record.due_date,
                    status: record.status,
                    paymentPortalLogRef: record.payment_portal_log_ref,
                    transactionId: record.transaction_id,
                    createdAt: record.created_at ? new Date(record.created_at).toLocaleString() : '',
                    createdBy: record.created_by,
                    updatedAt: record.updated_at ? new Date(record.updated_at).toLocaleString() : '',
                    updatedBy: record.updated_by,
                    fullName: record.account?.customer?.full_name || (record.account ? 'Loading...' : 'Unknown'),
                    contactNumber: record.account?.customer?.contact_number_primary || 'N/A',
                    emailAddress: record.account?.customer?.email_address || 'N/A',
                    address: record.account?.customer?.address || 'N/A',
                    plan: record.account?.customer?.desired_plan || 'No Plan',
                    dateInstalled: record.account?.date_installed ? new Date(record.account.date_installed).toLocaleDateString() : '',
                    barangay: record.account?.customer?.barangay || '',
                    city: record.account?.customer?.city || '',
                    region: record.account?.customer?.region || '',
                    provider: 'SWITCH',
                    invoiceNo: record.id.toString(),
                    totalAmountDue: Number(record.total_amount) || 0,
                    invoicePayment: Number(record.received_payment) || 0,
                    paymentMethod: record.received_payment > 0 ? 'Payment Received' : 'N/A',
                    dateProcessed: record.received_payment > 0 && record.updated_at ? new Date(record.updated_at).toLocaleDateString() : undefined,
                    dateProcessedRaw: record.received_payment > 0 ? record.updated_at : undefined,
                    processedBy: record.received_payment > 0 ? record.updated_by : undefined,
                    remarks: 'System Generated',
                    // Real VAT snapshot from the invoice. null (not 0) when the invoice
                // predates the snapshot columns, so the UI can hide the breakdown
                // instead of showing a misleading zero.
                vat: Number(record.vat_amount) || 0,
                vatRate: record.vat_rate === null || record.vat_rate === undefined ? null : Number(record.vat_rate),
                vatBaseAmount: record.vat_base_amount === null || record.vat_base_amount === undefined ? null : Number(record.vat_base_amount),
                netAmount: record.net_amount === null || record.net_amount === undefined ? null : Number(record.net_amount),
                vatAmount: record.vat_amount === null || record.vat_amount === undefined ? null : Number(record.vat_amount),
                    amountDue: (Number(record.total_amount) || 0) - (Number(record.received_payment) || 0),
                    balanceFromPreviousBill: 0,
                    paymentReceived: Number(record.received_payment) || 0,
                    remainingBalance: (Number(record.total_amount) || 0) - (Number(record.received_payment) || 0),
                    monthlyServiceFee: Number(record.invoice_balance) || 0,
                    staggeredPaymentsCount: 0,
                    invoiceStatus: record.status,
                    billingDay: record.account?.billing_day || 0,
                    organization_id: record.organization_id,
                });

                const newTransformed = (result.data as InvoiceRecord[]).map(transform);

                const updateMap = new Map();
                invoiceRecords.forEach(r => updateMap.set(r.id, r));
                newTransformed.forEach(r => updateMap.set(r.id, r));

                const allFetchedRecords = Array.from(updateMap.values());

                set({
                    invoiceRecords: [...allFetchedRecords].sort((a, b) => parseInt(b.id) - parseInt(a.id)),
                    totalCount: result.total || totalCount,
                    lastUpdated: new Date()
                });
            } else {
                set({ lastUpdated: new Date() });
            }
        } catch (err) {
            console.error('[Invoice Store] Poll failed:', err);
        }
    }
}));
