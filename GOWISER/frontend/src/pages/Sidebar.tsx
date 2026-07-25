import React, { useState, useEffect, useRef } from 'react';
import { LayoutDashboard, Users, FileText, LogOut, ChevronRight, User, FileCheck, Wrench, MapPinned, MapPin, Package, CreditCard, List, Router, DollarSign, Receipt, FileBarChart, Clock, Calendar, AlertTriangle, Tag, MessageSquare, Settings, Network, Activity, AlertCircle, RefreshCw, Building, Shield, UserCheck } from 'lucide-react';
import { settingsColorPaletteService, ColorPalette } from '../services/settingsColorPaletteService';
import { roleService } from '../services/userService';

// Locked role IDs (1-8) use hardcoded allowedRoles; custom roles (9+) use permissions array
const LOCKED_ROLE_IDS = [1, 2, 3, 4, 5, 6, 7, 8];

interface SidebarProps {
  activeSection: string;
  onSectionChange: (section: string) => void;
  onLogout: () => void;
  isCollapsed?: boolean;
  userRole: string;
  roleId?: number | string | null;
  organizationId?: number | string | null;
  userEmail?: string;
  permissions?: string[] | null;
}

interface MenuItem {
  id: string;
  label: string;
  icon: React.ElementType;
  children?: MenuItem[];
  allowedRoles?: string[];
}

const Sidebar: React.FC<SidebarProps> = ({ activeSection, onSectionChange, onLogout, isCollapsed, userRole, roleId, organizationId, userEmail, permissions }) => {
  const [expandedItems, setExpandedItems] = useState<string[]>([]);
  const [isDarkMode, setIsDarkMode] = useState<boolean>(true);
  const [colorPalette, setColorPalette] = useState<ColorPalette | null>(null);
  const [currentDateTime, setCurrentDateTime] = useState('');
  const [tooltipItem, setTooltipItem] = useState<{ id: string; label: string; y: number } | null>(null);
  const [fetchedPermissions, setFetchedPermissions] = useState<string[] | null>(null);
  const mountedRef = useRef(true);

  useEffect(() => {
    mountedRef.current = true;
    return () => { mountedRef.current = false; };
  }, []);

  useEffect(() => {
    const updateDateTime = () => {
      const now = new Date();
      const dateStr = now.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: '2-digit', year: 'numeric' });
      const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: true });
      setCurrentDateTime(`${dateStr} ${timeStr}`);
    };
    updateDateTime();
    const interval = setInterval(updateDateTime, 60000);
    return () => clearInterval(interval);
  }, []);

  useEffect(() => {
    const checkDarkMode = () => {
      const theme = localStorage.getItem('theme');
      setIsDarkMode(theme === 'dark' || theme === null);
    };
    checkDarkMode();
    const observer = new MutationObserver(checkDarkMode);
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    return () => observer.disconnect();
  }, []);

  useEffect(() => {
    const fetchColorPalette = async () => {
      if (!mountedRef.current) return;
      try {
        const activePalette = await settingsColorPaletteService.getActive();
        if (mountedRef.current) setColorPalette(activePalette);
      } catch (err) {
        console.error('Failed to fetch color palette:', err);
      }
    };
    fetchColorPalette();
    const handlePaletteUpdate = () => fetchColorPalette();
    window.addEventListener('palette-updated', handlePaletteUpdate);
    window.addEventListener('storage', handlePaletteUpdate);
    return () => {
      window.removeEventListener('palette-updated', handlePaletteUpdate);
      window.removeEventListener('storage', handlePaletteUpdate);
    };
  }, []);

  const menuItems: MenuItem[] = [
    { id: 'dashboard', label: 'Dashboard', icon: LayoutDashboard, allowedRoles: ['administrator', 'superadmin'] },
    { id: 'live-monitor', label: 'Monitoring', icon: Activity, allowedRoles: ['superadmin', 'administrator'] },
    {
      id: 'billing',
      label: 'Billing',
      icon: CreditCard,
      allowedRoles: ['administrator', 'customer'],
      children: [
        { id: 'customer', label: 'Customer', icon: User, allowedRoles: ['administrator', 'headtech'] },
        { id: 'transaction-list', label: 'Transaction List', icon: Receipt, allowedRoles: ['administrator'] },
        { id: 'transactions-revert', label: 'Revert Requests', icon: RefreshCw, allowedRoles: ['superadmin', 'administrator'] },
        { id: 'payment-portal', label: 'Payment Portal', icon: DollarSign, allowedRoles: ['administrator'] },
        { id: 'soa', label: 'Statements', icon: FileText, allowedRoles: ['administrator'] },
        { id: 'invoice', label: 'Invoice', icon: Receipt, allowedRoles: ['administrator'] },
        { id: 'overdue', label: 'Overdue', icon: Clock, allowedRoles: ['administrator'] },
        { id: 'so-charge', label: 'SO Charge', icon: DollarSign, allowedRoles: ['administrator'] },
        { id: 'dc-notice', label: 'DC Notice', icon: AlertTriangle, allowedRoles: ['administrator'] },
        { id: 'mass-rebate', label: 'Rebates', icon: DollarSign, allowedRoles: ['administrator'] },
        // { id: 'staggered-payment', label: 'Staggered', icon: Calendar, allowedRoles: ['administrator'] },
        { id: 'discounts', label: 'Discounts', icon: Tag, allowedRoles: ['administrator'] }
      ]
    },
    { id: 'application-management', label: 'Application', icon: FileCheck, allowedRoles: ['administrator', 'headtech'] },
    { id: 'job-order', label: 'Job Order', icon: Wrench, allowedRoles: ['administrator', 'technician', 'agent', 'headtech'] },
    { id: 'service-order', label: 'Service Order', icon: Wrench, allowedRoles: ['administrator', 'technician', 'headtech'] },
    { id: 'work-order', label: 'Work Order', icon: Wrench, allowedRoles: ['administrator', 'agent', 'Osp', 'headtech'] },
    { id: 'lcp-nap-location', label: 'LCP/NAP Location', icon: MapPinned, allowedRoles: ['administrator', 'technician', 'Osp', 'headtech'] },
    { id: 'sms-blast', label: 'SMS Blast', icon: MessageSquare, allowedRoles: ['administrator'] },
    { id: 'reports', label: 'Reports', icon: FileText, allowedRoles: ['administrator', 'superadmin'] },
    {
      id: 'agent-group',
      label: 'Agent',
      icon: UserCheck,
      allowedRoles: ['administrator', 'superadmin'],
      children: [
        { id: 'commission', label: 'Pay Out/In', icon: DollarSign, allowedRoles: ['administrator', 'superadmin'] },
        { id: 'team-agent', label: 'Team Agents', icon: Users, allowedRoles: ['administrator', 'superadmin'] },
        { id: 'agent-management', label: 'Agent Management', icon: User, allowedRoles: ['administrator', 'superadmin'] },
        { id: 'agent-payout', label: 'Agent Payout', icon: DollarSign, allowedRoles: ['administrator', 'superadmin'] }
      ]
    },
    {
      id: 'inventory-group',
      label: 'Inventory',
      icon: Package,
      allowedRoles: ['administrator', 'inventorystaff'],
      children: [
        { id: 'inventory', label: 'Inventory', icon: Package, allowedRoles: ['administrator', 'inventorystaff'] },
        { id: 'inventory-category-list', label: 'Inventory Category List', icon: List, allowedRoles: ['administrator', 'inventorystaff'] }
      ]
    },
    {
      id: 'technical',
      label: 'Configurations',
      icon: Network,
      allowedRoles: ['superadmin', 'headtech'],
      children: [
        { id: 'promo-list', label: 'Promo', icon: Tag, allowedRoles: ['superadmin'] },
        { id: 'plan-list', label: 'Plan', icon: List, allowedRoles: ['superadmin'] },
        { id: 'location-list', label: 'Location', icon: MapPin, allowedRoles: ['superadmin', 'headtech'] },
        { id: 'lcp', label: 'LCP', icon: Network, allowedRoles: ['superadmin', 'headtech'] },
        { id: 'nap', label: 'NAP', icon: Network, allowedRoles: ['superadmin', 'headtech'] },
        { id: 'usage-type', label: 'Usage Type', icon: Activity, allowedRoles: ['superadmin'] },
        { id: 'vlan-config', label: 'VLAN Config', icon: Network, allowedRoles: ['superadmin'] },
        { id: 'payment-method', label: 'Payment Method', icon: CreditCard, allowedRoles: ['superadmin'] },
        { id: 'work-category', label: 'Work Category', icon: Wrench, allowedRoles: ['superadmin'] },
        { id: 'radius-config', label: 'Radius Config', icon: MapPin, allowedRoles: ['superadmin'] },
        { id: 'smart-olt', label: 'SmartOLT Config', icon: Network, allowedRoles: ['superadmin'] },
        { id: 'sms-config', label: 'SMS Config', icon: MessageSquare, allowedRoles: ['superadmin'] },
        { id: 'sms-template', label: 'SMS Template', icon: MessageSquare, allowedRoles: ['superadmin'] },
        { id: 'email-templates', label: 'Email Templates', icon: FileText, allowedRoles: ['superadmin'] },
        { id: 'pppoe-setup', label: 'PPPoE Setup', icon: Router, allowedRoles: ['superadmin'] },
        { id: 'concern-config', label: 'Concern Config', icon: AlertCircle, allowedRoles: ['superadmin'] },
        { id: 'billing-config', label: 'Billing Configurations', icon: Receipt, allowedRoles: ['superadmin'] }
      ]
    },
    {
      id: 'users',
      label: 'Users',
      icon: Users,
      allowedRoles: ['superadmin'],
      children: [
        { id: 'user-management', label: 'Users Management', icon: User, allowedRoles: ['superadmin'] },
        { id: 'tech-users', label: 'Tech Users', icon: Wrench, allowedRoles: ['superadmin'] }
        // { id: 'organization', label: 'Organization', icon: Building, allowedRoles: ['superadmin'] },
        // { id: 'roles', label: 'Roles', icon: Shield, allowedRoles: ['superadmin'] }
      ]
    },
    {
      id: 'logs-category',
      label: 'Logs',
      icon: FileBarChart,
      allowedRoles: ['administrator'],
      children: [
        { id: 'disconnected-logs', label: 'Disconnected Logs', icon: AlertTriangle, allowedRoles: ['administrator'] },
        { id: 'reconnection-logs', label: 'Reconnection Logs', icon: FileBarChart, allowedRoles: ['administrator'] },
        { id: 'sms-logs', label: 'SMS Logs', icon: MessageSquare, allowedRoles: ['administrator'] },
        { id: 'email-logs', label: 'Email Logs', icon: FileText, allowedRoles: ['administrator'] },
        { id: 'data-logs', label: 'Data Logs', icon: FileText, allowedRoles: ['administrator', 'superadmin'] },
        { id: 'smart-olt-logs', label: 'Smart OLT Logs', icon: Network, allowedRoles: ['superadmin'] },
        { id: 'radius-logs', label: 'Radius Logs', icon: Activity, allowedRoles: ['superadmin'] },
        { id: 'system-logs', label: 'System Logs', icon: FileText, allowedRoles: ['superadmin'] }
      ]
    },
    { id: 'settings', label: 'Settings', icon: Settings, allowedRoles: ['superadmin'] },
  ];

  // Auto-expand the parent of the active section
  useEffect(() => {
    if (activeSection) {
      menuItems.forEach(item => {
        if (item.children && item.children.some(child => child.id === activeSection)) {
          setExpandedItems(prev => prev.includes(item.id) ? prev : [...prev, item.id]);
        }
      });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeSection]);

  // Determine if this is a custom role (not one of the 8 locked roles)
  const numericRoleId = roleId ? Number(roleId) : 0;
  const isCustomRole = numericRoleId > 0 && !LOCKED_ROLE_IDS.includes(numericRoleId);

  // Fetch permissions from API for custom roles if not available from props/localStorage
  useEffect(() => {
    if (!isCustomRole || !numericRoleId) return;

    // Check if we already have permissions from props or localStorage
    if (permissions && Array.isArray(permissions) && permissions.length > 0) return;
    try {
      const authData = JSON.parse(localStorage.getItem('authData') || '{}');
      if (authData.permissions && Array.isArray(authData.permissions) && authData.permissions.length > 0) return;
    } catch (e) { /* ignore */ }

    // Fetch the role to get permissions
    const fetchRolePermissions = async () => {
      try {
        const response = await roleService.getRoleById(numericRoleId);
        if (response.success && response.data) {
          let perms: string[] = [];
          const rawPerms = response.data.permissions;
          if (Array.isArray(rawPerms)) {
            perms = rawPerms;
          } else if (typeof rawPerms === 'string') {
            try {
              const parsed = JSON.parse(rawPerms);
              perms = Array.isArray(parsed) ? parsed : [];
            } catch (e) {
              perms = rawPerms.split(',').map(p => p.trim()).filter(Boolean);
            }
          }

          if (perms.length > 0) {
            setFetchedPermissions(perms);
            // Also update localStorage so we don't need to fetch again
            try {
              const authData = JSON.parse(localStorage.getItem('authData') || '{}');
              authData.permissions = perms;
              localStorage.setItem('authData', JSON.stringify(authData));
            } catch (e) { /* ignore */ }
          }
        }
      } catch (err) {
        console.error('Failed to fetch role permissions:', err);
      }
    };

    fetchRolePermissions();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isCustomRole, numericRoleId]);

  if (userRole?.toLowerCase() === 'customer') return null;

  // Get permissions: from prop first, fallback to localStorage authData, then fetched
  const effectivePermissions: string[] = (() => {
    if (permissions && Array.isArray(permissions) && permissions.length > 0) return permissions;
    try {
      const authData = JSON.parse(localStorage.getItem('authData') || '{}');
      if (authData.permissions && Array.isArray(authData.permissions) && authData.permissions.length > 0) return authData.permissions;
    } catch (e) { /* ignore */ }
    if (fetchedPermissions && fetchedPermissions.length > 0) return fetchedPermissions;
    return [];
  })();

  // Filter for custom roles: check if item.id is in the permissions array
  const filterMenuByPermissions = (items: MenuItem[]): MenuItem[] => {
    if (effectivePermissions.length === 0) return [];

    return items.reduce<MenuItem[]>((acc, item) => {
      const effectiveUserData = JSON.parse(localStorage.getItem('authData') || '{}');
      const effectiveOrgId = organizationId || effectiveUserData.organization?.id || effectiveUserData.organization_id;
      if (item.id === 'organization' && effectiveOrgId && effectiveOrgId !== '0' && effectiveOrgId !== 0) return acc;

      if (item.children && item.children.length > 0) {
        // For parent groups, filter children by permissions
        const filteredChildren = filterMenuByPermissions(item.children);
        if (filteredChildren.length > 0) {
          acc.push({ ...item, children: filteredChildren });
        }
      } else {
        // Leaf item: check if its id is in the permissions
        if (effectivePermissions.includes(item.id)) {
          acc.push(item);
        }
      }
      return acc;
    }, []);
  };

  // Filter for locked roles: use the hardcoded allowedRoles
  const filterMenuByRole = (items: MenuItem[]): MenuItem[] => {
    const normalizedUserRole = userRole ? userRole.toLowerCase().trim() : '';
    const isTechnician = normalizedUserRole === 'technician' || String(roleId) === '2';
    const isInventoryStaff = normalizedUserRole === 'inventorystaff' || String(roleId) === '5';

    if (normalizedUserRole === 'customer' || String(roleId) === '3') return [];

    return items.filter(item => {
      const effectiveUserData = JSON.parse(localStorage.getItem('authData') || '{}');
      const effectiveOrgId = organizationId || effectiveUserData.organization?.id || effectiveUserData.organization_id;

      if (item.id === 'organization' && effectiveOrgId && effectiveOrgId !== '0' && effectiveOrgId !== 0) return false;
      if (!item.allowedRoles || item.allowedRoles.length === 0) return true;

      const hasAccess = item.allowedRoles.some(role => {
        const normalizedRole = role.toLowerCase().trim();
        if (normalizedRole === 'technician') return isTechnician;
        if (normalizedRole === 'administrator') return normalizedUserRole === 'administrator' || String(roleId) === '1' || String(roleId) === '7';
        if (normalizedRole === 'admin-only') return normalizedUserRole === 'administrator' || String(roleId) === '1';
        if (normalizedRole === 'superadmin') return normalizedUserRole === 'superadmin' || String(roleId) === '7';
        if (normalizedRole === 'headtech') return normalizedUserRole === 'headtech' || String(roleId) === '8';
        if (normalizedRole === 'osp') return normalizedRole === 'Osp'.toLowerCase() || String(roleId) === '6';
        if (normalizedRole === 'agent') return normalizedUserRole === 'agent' || String(roleId) === '4';
        if (normalizedRole === 'inventorystaff') return isInventoryStaff;
        return normalizedRole === normalizedUserRole;
      });

      return hasAccess;
    }).map(item => {
      if (item.children && item.children.length > 0) {
        const filteredChildren = filterMenuByRole(item.children);
        return { ...item, children: filteredChildren };
      }
      return item;
    });
  };

  const filteredMenuItems = isCustomRole ? filterMenuByPermissions(menuItems) : filterMenuByRole(menuItems);

  const toggleExpanded = (itemId: string) => {
    setExpandedItems(prev =>
      prev.includes(itemId) ? prev.filter(id => id !== itemId) : [...prev, itemId]
    );
  };

  // Flatten menu items for collapsed icon-only view
  const flattenForCollapsed = (items: MenuItem[]): MenuItem[] => {
    const result: MenuItem[] = [];
    const seenIds = new Set<string>();

    items.forEach(item => {
      if (item.children && item.children.length > 0) {
        // Push children directly (skip the parent group header)
        item.children.forEach(child => {
          if (!seenIds.has(child.id)) {
            seenIds.add(child.id);
            result.push(child);
          }
        });
      } else {
        if (!seenIds.has(item.id)) {
          seenIds.add(item.id);
          result.push(item);
        }
      }
    });
    return result;
  };

  const collapsedItems = flattenForCollapsed(filteredMenuItems);

  // Shared active style
  const activeStyle = {
    backgroundColor: colorPalette?.primary ? `${colorPalette.primary}33` : isDarkMode ? 'rgba(249, 115, 22, 0.2)' : 'rgba(249, 115, 22, 0.1)',
    color: colorPalette?.primary || '#7c3aed',
    borderRightWidth: '2px',
    borderRightStyle: 'solid' as const,
    borderRightColor: colorPalette?.primary || '#7c3aed'
  };

  // ---- COLLAPSED MODE ----
  if (isCollapsed) {
    return (
      <div
        className={`w-14 h-full flex flex-col border-r transition-all duration-300 ease-in-out overflow-visible ${isDarkMode ? 'bg-gray-800 border-gray-600' : 'bg-white border-gray-300'
          }`}
        style={{ position: 'relative' }}
      >
        <nav className="flex-1 py-4 overflow-y-auto overflow-x-visible scrollbar-none">
          {collapsedItems.map(item => {
            const IconComponent = item.icon;
            const isActive = activeSection === item.id;
            return (
              <div key={item.id} className="relative group">
                <button
                  onClick={() => onSectionChange(item.id)}
                  onMouseEnter={e => {
                    const rect = (e.currentTarget as HTMLElement).getBoundingClientRect();
                    const parentRect = (e.currentTarget as HTMLElement).closest('.h-full')?.getBoundingClientRect();
                    setTooltipItem({ id: item.id, label: item.label, y: rect.top - (parentRect?.top ?? 0) });
                  }}
                  onMouseLeave={() => setTooltipItem(null)}
                  className={`w-full flex items-center justify-center py-3 transition-colors ${isActive
                    ? ''
                    : isDarkMode
                      ? 'text-gray-300 hover:text-white hover:bg-gray-700'
                      : 'text-gray-700 hover:text-black hover:bg-gray-100'
                    }`}
                  style={isActive ? activeStyle : {}}
                  title=""
                >
                  <IconComponent
                    className={`h-5 w-5 ${isActive ? '' : isDarkMode ? 'text-gray-400' : 'text-gray-600'}`}
                    style={isActive ? { color: colorPalette?.primary || '#7c3aed' } : {}}
                  />
                </button>

                {/* Floating tooltip */}
                {tooltipItem?.id === item.id && (
                  <div
                    className={`fixed z-50 left-16 px-3 py-1.5 rounded-md text-xs font-medium shadow-lg whitespace-nowrap pointer-events-none ${isDarkMode ? 'bg-gray-900 text-white border border-gray-700' : 'bg-white text-gray-900 border border-gray-200'
                      }`}
                    style={{ top: `${tooltipItem.y}px`, transform: 'translateY(8px)' }}
                  >
                    {item.label}
                    {/* Arrow */}
                    <div
                      className={`absolute left-0 top-1/2 -translate-x-1 -translate-y-1/2 w-2 h-2 rotate-45 ${isDarkMode ? 'bg-gray-900 border-l border-b border-gray-700' : 'bg-white border-l border-b border-gray-200'
                        }`}
                    />
                  </div>
                )}
              </div>
            );
          })}
        </nav>

        {/* Logout icon only */}
        <div className={`px-0 py-3 border-t flex-shrink-0 flex justify-center ${isDarkMode ? 'border-gray-600' : 'border-gray-300'}`}>
          <button
            onClick={onLogout}
            onMouseEnter={e => {
              const rect = (e.currentTarget as HTMLElement).getBoundingClientRect();
              const parentRect = (e.currentTarget as HTMLElement).closest('.h-full')?.getBoundingClientRect();
              setTooltipItem({ id: '__logout__', label: 'Logout', y: rect.top - (parentRect?.top ?? 0) });
            }}
            onMouseLeave={() => setTooltipItem(null)}
            className={`p-2 rounded transition-colors ${isDarkMode ? 'text-gray-400 hover:text-white hover:bg-gray-700' : 'text-gray-600 hover:text-black hover:bg-gray-100'}`}
          >
            <LogOut className="h-5 w-5" />
          </button>
          {tooltipItem?.id === '__logout__' && (
            <div
              className={`fixed z-50 left-16 px-3 py-1.5 rounded-md text-xs font-medium shadow-lg whitespace-nowrap pointer-events-none ${isDarkMode ? 'bg-gray-900 text-white border border-gray-700' : 'bg-white text-gray-900 border border-gray-200'
                }`}
              style={{ top: `${tooltipItem.y}px`, transform: 'translateY(8px)' }}
            >
              Logout
            </div>
          )}
        </div>
      </div>
    );
  }

  // ---- EXPANDED MODE (unchanged) ----
  const renderMenuItem = (item: MenuItem, level = 0) => {
    const hasChildren = item.children && item.children.length > 0;
    const isExpanded = expandedItems.includes(item.id);
    const isCurrentItemActive = activeSection === item.id;
    const IconComponent = item.icon;

    return (
      <div key={item.id}>
        <button
          onClick={() => {
            if (hasChildren) {
              toggleExpanded(item.id);
            } else {
              if (level === 0) setExpandedItems([]);
              onSectionChange(item.id);
            }
          }}
          className={`w-full flex items-center justify-between px-4 py-3 text-sm transition-colors ${level > 0 ? 'pl-8' : 'pl-4'
            } ${isCurrentItemActive
              ? ''
              : isDarkMode
                ? 'text-gray-300 hover:text-white hover:bg-gray-700'
                : 'text-gray-700 hover:text-black hover:bg-gray-100'
            }`}
          style={isCurrentItemActive ? activeStyle : {}}
        >
          <div className="flex items-center">
            <IconComponent className={`h-5 w-5 mr-3 ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}`} />
            <span>{item.label}</span>
          </div>
          {hasChildren && (
            <ChevronRight
              className={`h-4 w-4 ${isDarkMode ? 'text-gray-400' : 'text-gray-600'} transition-transform ${isExpanded ? 'rotate-90' : ''}`}
            />
          )}
        </button>

        {hasChildren && isExpanded && (
          <div>
            {item.children!.map(child => renderMenuItem(child, level + 1))}
          </div>
        )}
      </div>
    );
  };

  return (
    <div className={`w-64 border-r h-full ${isDarkMode ? 'bg-gray-800 border-gray-600' : 'bg-white border-gray-300'} flex flex-col transition-all duration-300 ease-in-out overflow-hidden`}>
      <nav className="flex-1 py-4 overflow-y-auto overflow-x-hidden scrollbar-none">
        {filteredMenuItems.map(item => renderMenuItem(item))}
      </nav>

      <div className={`px-3 py-3 ${isDarkMode ? 'border-gray-600' : 'border-gray-300'} border-t flex-shrink-0`}>
        <div className="mb-3">
          <div className={`text-xs mb-2 text-center ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}`}>
            {currentDateTime}
          </div>
          <div className="flex items-center mb-2">
            <div className={`w-10 h-10 rounded-full flex items-center justify-center ${isDarkMode ? 'bg-gray-700 border-gray-600' : 'bg-gray-200 border-gray-300'} border-2`}>
              <User className={`h-5 w-5 ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}`} />
            </div>
            <div className="ml-3 flex-1 min-w-0">
              <div className={`text-sm font-medium truncate ${isDarkMode ? 'text-gray-200' : 'text-gray-800'}`}>
                {userEmail || 'user@example.com'}
              </div>
              <div className={`text-xs truncate ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}`}>
                {userRole}
              </div>
            </div>
          </div>
          <div className={`h-px ${isDarkMode ? 'bg-gray-700' : 'bg-gray-300'} mb-2`} />
        </div>

        <button
          onClick={onLogout}
          className={`w-full px-3 py-2 ${isDarkMode
            ? 'text-gray-300 hover:text-white hover:bg-gray-700'
            : 'text-gray-700 hover:text-black hover:bg-gray-100'
            } rounded transition-colors text-sm flex items-center`}
        >
          <LogOut className="h-4 w-4 mr-2" />
          <span>Logout</span>
        </button>
      </div>
    </div>
  );
};

export default Sidebar;
