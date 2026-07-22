import React, { useState, useEffect, useMemo, useCallback } from 'react';
import { View, Dimensions, useWindowDimensions, ActivityIndicator, Linking, Pressable, DeviceEventEmitter } from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import * as WebBrowser from 'expo-web-browser';
import MaterialCommunityIcons from '@expo/vector-icons/MaterialCommunityIcons';
import { InvoiceProvider } from '../contexts/InvoiceContext';
// import { OverdueProvider } from '../contexts/OverdueContext';
import { DCNoticeProvider } from '../contexts/DCNoticeContext';
import { StaggeredPaymentProvider } from '../contexts/StaggeredPaymentContext';
// import { DiscountProvider } from '../contexts/DiscountContext';
import { ApplicationProvider } from '../contexts/ApplicationContext';
// import { ApplicationVisitProvider } from '../contexts/ApplicationVisitContext';
import { JobOrderProvider } from '../contexts/JobOrderContext';
import { InventoryProvider } from '../contexts/InventoryContext';
import { ServiceOrderProvider } from '../contexts/ServiceOrderContext';
import DCNotice from './DCNotice';
import Discounts from './Discounts';
// import Overdue from './Overdue';
import StaggeredPayment from './StaggeredPayment';
import MassRebate from './Rebate';
// import SMSBlast from './SMSBlast';
// import SMSBlastLogs from './SMSBlastLogs';
import DisconnectionLogs from './DisconnectionLogs';
import ReconnectionLogs from './ReconnectionLogs';
import Sidebar from './Sidebar';
import DashboardContent from '../components/DashboardContent';
// import UserManagement from './UserManagement';
// import OrganizationManagement from './OrganizationManagement';
// import { BillingProvider } from '../contexts/BillingContext';
// import { TransactionProvider } from '../contexts/TransactionContext';
// import { PaymentPortalProvider } from '../contexts/PaymentPortalContext';
// import { SOAProvider } from '../contexts/SOAContext';
// import GroupManagement from './GroupManagement';
import ApplicationManagement from './ApplicationManagement';
import Customer from './Customer';
import OrganizationManagement from './organization';
import Roles from './roles';
import TeamAgent from './teamAgent';
import TechUsers from './TechUsers';
import SOcharge from './SOcharge';
import BillingListView from './BillingListView';
import TransactionList from './TransactionList';
import PaymentPortal from './PaymentPortal';
import JobOrder from './JobOrder';
import ServiceOrder from './ServiceOrder';
import ApplicationVisit from './ApplicationVisit';
// import LocationList from './LocationList';
// import PlanList from './PlanList';
// import PromoList from './PromoList';
// import RouterModelList from './RouterModelList';
// import LcpList from './LcpList';
// import NapList from './NapList';
import Inventory from './Inventory';
// import ExpensesLog from './ExpensesLog';
import Logs from './Logs';
import SOA from './SOA';
import Invoice from './Invoice';
import InventoryCategoryList from './InventoryCategoryList';
import SOAGeneration from './SOAGeneration';
// import UsageTypeList from './UsageTypeList';
// import Ports from './Ports';
// import StatusRemarksList from './StatusRemarksList';
import Settings from './Settings';
import PaymentMethodList from './PaymentMethodList';
import UsageTypeList from './UsageTypeList';
import WorkCategoryList from './WorkCategoryList';
import StatusRemarksList from './StatusRemarksList';
import RouterModelList from './RouterModelList';
import PlanList from './PlanList';
import PromoList from './PromoList';
import ConcernConfig from './ConcernConfig';
import SmartOltConfig from './SmartOltConfig';
import RadiusConfig from './RadiusConfig';
import SmsConfig from './SmsConfig';
import PPPoESetup from './PPPoESetup';
import BillingConfig from './BillingConfig';
import LcpList from './LcpList';
import NapList from './NapList';
import Ports from './Ports';
import LocationList from './LocationList';
import Overdue from './Overdue';
import EmailLogs from './EmailLogs';
import SmsLogs from './SmsLogs';
import FileLogViewer from './FileLogViewer';
import ExpensesLog from './ExpensesLog';
import DataLogs from './DataLogs';
import Reports from './Reports';
import SMSBlast from './SMSBlast';
import SMSBlastLogs from './SMSBlastLogs';
import GroupManagement from './GroupManagement';
import UserManagement from './UserManagement';
import TransactionsRevert from './TransactionsRevert';
import DatabaseSetup from './DatabaseSetup';
import DatabaseTest from './DatabaseTest';
import LcpNapLocation from './LcpNapLocation';
import WorkOrder from './WorkOrder';
// import RadiusConfig from './RadiusConfig';
// import SmsConfig from './SmsConfig';
import SMSTemplate from './SMSTemplate';
import EmailTemplates from './EmailTemplates';
// import PPPoESetup from './PPPoESetup';
import Support from './Support';
import LiveMonitor from './LiveMonitor';
// import ConcernConfig from './ConcernConfig';
import DashboardCustomer from './DashboardCustomer';
import DashboardAgent from './DashboardAgent';
import AgentHistory from './AgentHistory';
import Achievement from './Achievement';
import Commission from './Commission';
import AgentPayout from './AgentPayout';
import Bills from './Bills';
import Menu from './Menu';
import ApplicationForm from './ApplicationForm';
import ReleaseNotes from './ReleaseNotes';
import { CustomerDataProvider } from '../contexts/CustomerDataContext';
import { settingsColorPaletteService, ColorPalette } from '../services/settingsColorPaletteService';
import { usePushNotifications } from '../hooks/usePushNotifications';
import { useLocationTracking } from '../hooks/useLocationTracking';
import ErrorBoundary from '../components/ErrorBoundary';

interface DashboardProps {
    onLogout: () => void;
}

const Dashboard: React.FC<DashboardProps> = ({ onLogout }) => {
    usePushNotifications();
    useLocationTracking();
    const [userData, setUserData] = useState<any>(null);
    const [activeSection, setActiveSection] = useState('dashboard');
    const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
    const [isLoading, setIsLoading] = useState(true);
    const [isMobileMenuOpen, setIsMobileMenuOpen] = useState(false);
    const { width } = useWindowDimensions();
    const [searchQuery, setSearchQuery] = useState('');
    const [isTechModalOpen, setIsTechModalOpen] = useState(false);
    const [colorPalette, setColorPalette] = useState<ColorPalette | null>(null);
    const [billsInitialTab, setBillsInitialTab] = useState<'soa' | 'invoices' | 'payments'>('soa');
    // const [customerInitialSearch, setCustomerInitialSearch] = useState('');
    // const [customerAutoOpenAccountNo, setCustomerAutoOpenAccountNo] = useState('');
    const isDarkMode = false; // Forced light mode as per user request
    useEffect(() => {
        const sub = DeviceEventEmitter.addListener('techModalStateChange', (isOpen) => {
            console.log('[Dashboard] techModalStateChange:', isOpen);
            setIsTechModalOpen(isOpen);
        });
        return () => sub.remove();
    }, []);

    useEffect(() => {
        const initializeUserData = async () => {
            try {
                const authData = await AsyncStorage.getItem('authData');
                if (authData) {
                    const user = JSON.parse(authData);
                    setUserData(user);

                    // Use the initialized user data if available
                    if (user.role === 'customer') {
                        setActiveSection('customer-dashboard');
                    } else if (user.role?.toLowerCase() === 'agent') {
                        setActiveSection('agent-dashboard');
                    } else if (String(user.role_id) === '6' || user.role?.toLowerCase() === 'osp') {
                        setActiveSection('work-order');
                    } else if (user.role === 'technician' || String(user.role_id) === '4') {
                        setActiveSection('job-order');
                    } else if (user.role?.toLowerCase() === 'inventorystaff' || String(user.role_id) === '5') {
                        setActiveSection('inventory');
                    } else if (user.role?.toLowerCase() === 'headtech' || String(user.role_id) === '7' || String(user.role_id) === '8') {
                        setActiveSection('applicationManagement');
                    }


                }
            } catch (error) {
                console.error('Error parsing user data:', error);
            } finally {
                setIsLoading(false);
            }
        };

        initializeUserData();
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

    // Add effect to log the active section when it changes
    useEffect(() => {
        console.log('Active section changed to:', activeSection);
    }, [activeSection]);

    // Add effect to log the active section when it changes
    const handleSectionChange = useCallback((section: string, extra?: string) => {
        console.log('[Dashboard] handleSectionChange:', section);
        setActiveSection(section);
        if (section === 'customer-bills') {
            setBillsInitialTab((extra as any) || 'soa');
        } else if (section === 'customer') {
            // setCustomerInitialSearch(extra || '');
            // setCustomerAutoOpenAccountNo(extra || '');
        }

        if (width < 768) {
            closeMobileMenu();
        }
    }, [width]);

    const content = useMemo(() => {
        switch (activeSection) {
            // Customer Routes
            case 'customer-dashboard':
                return <DashboardCustomer onNavigate={(section, tab) => handleSectionChange(section, tab)} />;
            case 'agent-dashboard':
                return <DashboardAgent onNavigate={(section, tab) => handleSectionChange(section, tab)} />;
            case 'commission':
                return <Commission />;
            case 'agent-payout':
                return <AgentPayout />;
            case 'agent-history':
                return <AgentHistory />;
            case 'achievement':
                return <Achievement />;
            case 'customer-bills':
                return <Bills initialTab={billsInitialTab} />;
            case 'customer-support':
                return <Support forceLightMode={true} />;
            case 'support':
                return <Support />;
            case 'job-order':
                return <JobOrder onLogout={onLogout} />;
            case 'service-order':
                return <ServiceOrder />;
            case 'work-order':
                return <WorkOrder />;
            case 'lcp-nap-location':
                return <LcpNapLocation />;
            case 'inventory':
                return <Inventory />;
            case 'inventory-category-list':
                return <InventoryCategoryList />;
            case 'payment-method-list':
                return <PaymentMethodList />;
            case 'usage-type-list':
                return <UsageTypeList />;
            case 'work-category-list':
                return <WorkCategoryList />;
            case 'status-remarks-list':
                return <StatusRemarksList />;
            case 'router-model-list':
                return <RouterModelList />;
            case 'plan-list':
                return <PlanList onNavigate={handleSectionChange} />;
            case 'promo-list':
                return <PromoList />;
            case 'concern-config':
                return <ConcernConfig />;
            case 'smart-olt-config':
                return <SmartOltConfig />;
            case 'radius-config':
                return <RadiusConfig />;
            case 'sms-config':
                return <SmsConfig />;
            case 'pppoe-setup':
                return <PPPoESetup />;
            case 'billing-config':
                return <BillingConfig />;
            case 'lcp-list':
                return <LcpList />;
            case 'nap-list':
                return <NapList />;
            case 'ports':
                return <Ports />;
            case 'location-list':
                return <LocationList onNavigate={handleSectionChange} />;
            case 'overdue':
                return <Overdue />;
            case 'dc-notice':
                return <DCNoticeProvider><DCNotice /></DCNoticeProvider>;
            case 'discounts':
                return <Discounts />;
            case 'billing':
                return <BillingListView />;
            case 'rebate':
                return <MassRebate />;
            case 'staggered-payment':
                return <StaggeredPaymentProvider><StaggeredPayment /></StaggeredPaymentProvider>;
            case 'sms-template':
                return <SMSTemplate />;
            case 'email-templates':
                return <EmailTemplates />;
            case 'live-monitor':
                return <LiveMonitor />;
            case 'applicationManagement':
                return <ApplicationManagement />;
            case 'applicationVisit':
                return <ApplicationVisit />;
            case 'activity-logs':
                return <Logs />;
            case 'soa':
                return <SOA />;
            case 'invoice':
                return <InvoiceProvider><Invoice /></InvoiceProvider>;
            case 'customer':
                return <Customer />;
            case 'organizations':
                return <OrganizationManagement />;
            case 'roles':
                return <Roles />;
            case 'team-agent':
                return <TeamAgent />;
            case 'tech-users':
                return <TechUsers />;
            case 'so-charges':
                return <SOcharge />;
            case 'disconnection-logs':
                return <DisconnectionLogs />;
            case 'reconnection-logs':
                return <ReconnectionLogs />;
            case 'settings':
                return <Settings />;
            case 'soa-generation':
                return <SOAGeneration />;
            case 'payment-portal':
                return <PaymentPortal />;
            case 'transaction-list':
                return <TransactionList />;
            case 'email-logs':
                return <EmailLogs />;
            case 'sms-logs':
                return <SmsLogs />;
            case 'file-log-viewer':
                return <FileLogViewer type="smartolt" title="SmartOLT Logs" />;
            case 'radius-logs':
                return <FileLogViewer type="radius" title="Radius Logs" />;
            case 'expenses-log':
                return <ExpensesLog />;
            case 'data-logs':
                return <DataLogs />;
            case 'reports':
                return <Reports />;
            case 'sms-blast':
                return <SMSBlast />;
            case 'sms-blast-logs':
                return <SMSBlastLogs />;
            case 'group-management':
                return <GroupManagement />;
            case 'user-management':
                return <UserManagement />;
            case 'agent-management':
                return <UserManagement agentOnly />;
            case 'transactions-revert':
                return <TransactionsRevert />;
            case 'database-setup':
                return <DatabaseSetup />;
            case 'database-test':
                return <DatabaseTest />;
            case 'Application':
                return <ApplicationForm onClose={() => handleSectionChange('agent-dashboard')} />;
            case 'menu':
                return <Menu onLogout={onLogout} onSectionChange={handleSectionChange} />;
            case 'release-notes':
                return <ReleaseNotes onBack={() => handleSectionChange('menu')} />;
            case 'dashboard':
            default:
                if (userData && String(userData.role_id) === '3') {
                    return <DashboardCustomer onNavigate={(section, tab) => handleSectionChange(section, tab)} />;
                }
                if (userData && userData.role?.toLowerCase() === 'agent') {
                    return <DashboardAgent onNavigate={(section, tab) => handleSectionChange(section, tab)} />;
                }
                if (userData && (userData.role?.toLowerCase() === 'inventorystaff' || String(userData.role_id) === '5')) {
                    return <Inventory />;
                }
                return <DashboardContent />;
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [activeSection, billsInitialTab, userData?.role_id, onLogout, handleSectionChange]);

    const handleSearch = (query: string) => {
        setSearchQuery(query);
    };

    const toggleSidebar = () => {
        const isMobile = width < 768;
        if (isMobile) {
            setIsMobileMenuOpen(!isMobileMenuOpen);
        } else {
            setSidebarCollapsed(!sidebarCollapsed);
        }
    };

    const closeMobileMenu = () => {
        setIsMobileMenuOpen(false);
    };

    const handleOpenChat = async () => {
        const webUrl = 'https://m.me/gowiserzc';
        const messengerAppUrl = 'fb-messenger://user-thread/';
        try {
            const canOpenMessenger = await Linking.canOpenURL(messengerAppUrl);
            if (canOpenMessenger) {
                await Linking.openURL(webUrl);
            } else {
                await WebBrowser.openBrowserAsync(webUrl);
            }
        } catch (error) {
            await WebBrowser.openBrowserAsync(webUrl);
        }
    };


    // Helper to determine if we should show sidebar
    const showSidebar = userData !== null && activeSection !== 'release-notes' && activeSection !== 'Application' && !isTechModalOpen;

    if (isLoading) {
        return (
            <View style={{ flex: 1, justifyContent: 'center', alignItems: 'center', backgroundColor: isDarkMode ? '#030712' : '#f9fafb' }}>
                <ActivityIndicator size="large" color="#7c3aed" />
            </View>
        );
    }

    return (
        // <BillingProvider>
        //     <TransactionProvider>
        //         <PaymentPortalProvider>
        //             <SOAProvider>
        //                 <InvoiceProvider>
        //                     <OverdueProvider>
        //                         <DCNoticeProvider>
        //                             <StaggeredPaymentProvider>
        //                                 <DiscountProvider>
        <ApplicationProvider>
            <CustomerDataProvider>
                {/* <ApplicationVisitProvider> */}
                <JobOrderProvider>
                    <ServiceOrderProvider>
                        <InventoryProvider>
                            <View style={{
                                height: '100%',
                                flexDirection: 'column',
                                overflow: 'hidden',
                                backgroundColor: isDarkMode ? '#030712' : '#f9fafb'
                            }}>
                                {/* Main Content Area */}
                                <View style={{
                                    flex: 1,
                                    overflow: 'hidden',
                                    backgroundColor: isDarkMode ? '#030712' : '#f9fafb'
                                }}>
                                    <View style={{ height: '100%', overflow: 'scroll' }}>
                                        <ErrorBoundary resetKey={activeSection} sectionName={activeSection}>
                                            {content}
                                        </ErrorBoundary>
                                    </View>
                                </View>

                                {/* Bottom Navigation Bar */}
                                {showSidebar && (
                                    <Sidebar
                                        activeSection={activeSection}
                                        onSectionChange={handleSectionChange}
                                        userRole={userData?.role || ''}
                                        userEmail={userData?.email || ''}
                                        roleId={userData?.role_id}
                                    />
                                )}

                                {/* Persistent Floating Messenger Button for Customers (role_id 3) */}
                                {String(userData?.role_id) === '3' && activeSection !== 'menu' && (
                                    <View style={{
                                        position: 'absolute',
                                        bottom: 110,
                                        left: 20,
                                        zIndex: 99999,
                                        elevation: 10,
                                    }}>
                                        <Pressable
                                            onPress={handleOpenChat}
                                            style={({ pressed }) => ({
                                                transform: [{ scale: pressed ? 0.9 : 1 }],
                                            })}
                                        >
                                            <View style={{
                                                width: 56,
                                                height: 56,
                                                borderRadius: 28,
                                                backgroundColor: colorPalette?.primary || '#ef4444',
                                                justifyContent: 'center',
                                                alignItems: 'center',
                                                shadowColor: '#000',
                                                shadowOffset: { width: 0, height: 4 },
                                                shadowOpacity: 0.3,
                                                shadowRadius: 5,
                                                elevation: 8,
                                            }}>
                                                <MaterialCommunityIcons name="facebook-messenger" size={30} color="#fff" />
                                            </View>
                                        </Pressable>
                                    </View>
                                )}

                            </View>
                        </InventoryProvider>
                    </ServiceOrderProvider>
                </JobOrderProvider>
            </CustomerDataProvider>
            {/* </ApplicationVisitProvider> */}
        </ApplicationProvider>
        //                                 </DiscountProvider>
        //                             </StaggeredPaymentProvider>
        //                         </DCNoticeProvider>
        //                     </OverdueProvider>
        //                 </InvoiceProvider>
        //             </SOAProvider>
        //         </PaymentPortalProvider>
        //     </TransactionProvider>
        // </BillingProvider>
    );
};

export default Dashboard;
