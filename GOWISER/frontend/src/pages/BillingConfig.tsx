import React, { useState, useEffect } from 'react';
import { Receipt, Hash, Edit2, Trash2, Save, X, Calendar } from 'lucide-react';
import { customAccountNumberService, CustomAccountNumber } from '../services/customAccountNumberService';
import apiClient from '../config/api';
import { settingsColorPaletteService, ColorPalette } from '../services/settingsColorPaletteService';

interface BillingConfigData {
  advance_generation_day: number;
  due_date_day: number;
  disconnection_day: number;
  overdue_day: number;
  disconnection_notice: number;
  disconnection_fee: number;
  pullout_day: number;
  /**
   * VAT rate as a fraction (0.12 = 12%), matching how it is stored and how every calculation
   * uses it. The UI converts to and from a percentage purely for display, so the number an
   * operator types ("12") is never what reaches the API.
   */
  vat_rate: number;
  created_at?: string;
  updated_at?: string;
  updated_by?: string;
  created_by?: string;
}

interface BillingConfigResponse {
  success: boolean;
  data: BillingConfigData | null;
  message?: string;
}

interface ModalConfig {
  isOpen: boolean;
  type: 'success' | 'error' | 'warning' | 'confirm';
  title: string;
  message: string;
  onConfirm?: () => void;
  onCancel?: () => void;
}

const BillingConfig: React.FC = () => {
  const [isDarkMode, setIsDarkMode] = useState<boolean>(true);
  const [customAccountNumber, setCustomAccountNumber] = useState<CustomAccountNumber | null>(null);
  const [isEditingAccountNumber, setIsEditingAccountNumber] = useState<boolean>(false);
  const [accountNumberInput, setAccountNumberInput] = useState<string>('');
  const [loadingAccountNumber, setLoadingAccountNumber] = useState<boolean>(false);

  const [billingConfig, setBillingConfig] = useState<BillingConfigData | null>(null);
  const [isEditingBillingConfig, setIsEditingBillingConfig] = useState<boolean>(false);
  const [billingConfigInput, setBillingConfigInput] = useState<BillingConfigData>({
    advance_generation_day: 0,
    due_date_day: 0,
    disconnection_day: 0,
    overdue_day: 0,
    disconnection_notice: 0,
    disconnection_fee: 0,
    pullout_day: 0,
    vat_rate: 0.12
  });
  const [loadingBillingConfig, setLoadingBillingConfig] = useState<boolean>(false);

  const [modal, setModal] = useState<ModalConfig>({
    isOpen: false,
    type: 'success',
    title: '',
    message: ''
  });

  const [colorPalette, setColorPalette] = useState<ColorPalette | null>(null);

  const fetchCustomAccountNumber = async () => {
    try {
      setLoadingAccountNumber(true);
      const response = await customAccountNumberService.get();
      if (response.success && response.data) {
        setCustomAccountNumber(response.data);
        setAccountNumberInput(response.data.starting_number);
      } else {
        setCustomAccountNumber(null);
        setAccountNumberInput('');
      }
    } catch (error) {
      console.error('Error fetching custom account number:', error);
    } finally {
      setLoadingAccountNumber(false);
    }
  };

  const fetchBillingConfig = async () => {
    try {
      setLoadingBillingConfig(true);
      const response = await apiClient.get<BillingConfigResponse>('/billing-config');
      if (response.data.success && response.data.data) {
        setBillingConfig(response.data.data);
        setBillingConfigInput(response.data.data);
      } else {
        setBillingConfig(null);
      }
    } catch (error) {
      console.error('Error fetching billing config:', error);
    } finally {
      setLoadingBillingConfig(false);
    }
  };

  useEffect(() => {
    const checkDarkMode = () => {
      const theme = localStorage.getItem('theme');
      setIsDarkMode(theme === 'dark' || theme === null);
    };

    checkDarkMode();

    const observer = new MutationObserver(() => {
      checkDarkMode();
    });

    observer.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ['class']
    });

    return () => observer.disconnect();
  }, []);

  useEffect(() => {
    fetchCustomAccountNumber();
    fetchBillingConfig();
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

  const handleSaveAccountNumber = async () => {
    try {
      setLoadingAccountNumber(true);
      const trimmedInput = accountNumberInput.trim();

      if (trimmedInput !== '' && trimmedInput.length > 7) {
        setModal({
          isOpen: true,
          type: 'error',
          title: 'Validation Error',
          message: 'Starting number must not exceed 7 characters'
        });
        setLoadingAccountNumber(false);
        return;
      }

      if (customAccountNumber) {
        await customAccountNumberService.update(trimmedInput);
        setModal({
          isOpen: true,
          type: 'success',
          title: 'Success',
          message: 'Starting account number updated successfully'
        });
      } else {
        await customAccountNumberService.create(trimmedInput);
        setModal({
          isOpen: true,
          type: 'success',
          title: 'Success',
          message: 'Starting account number created successfully'
        });
      }
      await fetchCustomAccountNumber();
      setIsEditingAccountNumber(false);
    } catch (error: any) {
      console.error('Error saving custom account number:', error);
      const errorMessage = error.response?.data?.error || error.response?.data?.message || error.message || 'Unknown error occurred';
      setModal({
        isOpen: true,
        type: 'error',
        title: 'Error',
        message: `Failed to save: ${errorMessage}`
      });
    } finally {
      setLoadingAccountNumber(false);
    }
  };

  const handleDeleteAccountNumber = async () => {
    if (!customAccountNumber) return;

    setModal({
      isOpen: true,
      type: 'confirm',
      title: 'Confirm Deletion',
      message: 'Are you sure you want to delete the starting account number?',
      onConfirm: async () => {
        try {
          setLoadingAccountNumber(true);
          await customAccountNumberService.delete();
          setModal({
            isOpen: true,
            type: 'success',
            title: 'Success',
            message: 'Starting account number deleted successfully'
          });
          setCustomAccountNumber(null);
          setAccountNumberInput('');
          setIsEditingAccountNumber(false);
        } catch (error: any) {
          console.error('Error deleting custom account number:', error);
          setModal({
            isOpen: true,
            type: 'error',
            title: 'Error',
            message: `Failed to delete: ${error.response?.data?.message || error.message}`
          });
        } finally {
          setLoadingAccountNumber(false);
        }
      },
      onCancel: () => {
        setModal({ ...modal, isOpen: false });
      }
    });
  };

  const handleCancelEdit = () => {
    if (customAccountNumber) {
      setAccountNumberInput(customAccountNumber.starting_number);
    } else {
      setAccountNumberInput('');
    }
    setIsEditingAccountNumber(false);
  };

  const handleSaveBillingConfig = async () => {
    try {
      setLoadingBillingConfig(true);

      const authData = localStorage.getItem('authData');
      let userEmail = '';

      if (authData) {
        try {
          const userData = JSON.parse(authData);
          userEmail = userData.email || userData.user?.email || '';
        } catch (error) {
          console.error('Error parsing auth data:', error);
        }
      }

      const payload: any = {
        user_email: userEmail
      };

      if (billingConfigInput.advance_generation_day !== undefined && billingConfigInput.advance_generation_day !== null) {
        payload.advance_generation_day = billingConfigInput.advance_generation_day;
      }
      if (billingConfigInput.due_date_day !== undefined && billingConfigInput.due_date_day !== null) {
        payload.due_date_day = billingConfigInput.due_date_day;
      }
      if (billingConfigInput.disconnection_day !== undefined && billingConfigInput.disconnection_day !== null) {
        payload.disconnection_day = billingConfigInput.disconnection_day;
      }
      if (billingConfigInput.overdue_day !== undefined && billingConfigInput.overdue_day !== null) {
        payload.overdue_day = billingConfigInput.overdue_day;
      }
      if (billingConfigInput.disconnection_notice !== undefined && billingConfigInput.disconnection_notice !== null) {
        payload.disconnection_notice = billingConfigInput.disconnection_notice;
      }
      if (billingConfigInput.disconnection_fee !== undefined && billingConfigInput.disconnection_fee !== null) {
        payload.disconnection_fee = billingConfigInput.disconnection_fee;
      }
      // Sent as the stored fraction. The input below captures a percentage and divides by 100,
      // so the API only ever receives the unit it stores.
      if (billingConfigInput.vat_rate !== undefined && billingConfigInput.vat_rate !== null) {
        payload.vat_rate = billingConfigInput.vat_rate;
      }
      if (billingConfigInput.pullout_day !== undefined && billingConfigInput.pullout_day !== null) {
        payload.pullout_day = billingConfigInput.pullout_day;
      }

      if (billingConfig) {
        await apiClient.put('/billing-config', payload);
        setModal({
          isOpen: true,
          type: 'success',
          title: 'Success',
          message: 'Billing configuration updated successfully'
        });
      } else {
        await apiClient.post('/billing-config', payload);
        setModal({
          isOpen: true,
          type: 'success',
          title: 'Success',
          message: 'Billing configuration created successfully'
        });
      }
      await fetchBillingConfig();
      setIsEditingBillingConfig(false);
    } catch (error: any) {
      console.error('Error saving billing config:', error);
      const errorMessage = error.response?.data?.error || error.response?.data?.message || error.message || 'Unknown error occurred';
      setModal({
        isOpen: true,
        type: 'error',
        title: 'Error',
        message: `Failed to save: ${errorMessage}`
      });
    } finally {
      setLoadingBillingConfig(false);
    }
  };

  const handleDeleteBillingConfig = async () => {
    if (!billingConfig) return;

    setModal({
      isOpen: true,
      type: 'confirm',
      title: 'Confirm Deletion',
      message: 'Are you sure you want to delete the billing configuration?',
      onConfirm: async () => {
        try {
          setLoadingBillingConfig(true);
          await apiClient.delete('/billing-config');
          setModal({
            isOpen: true,
            type: 'success',
            title: 'Success',
            message: 'Billing configuration deleted successfully'
          });
          setBillingConfig(null);
          setBillingConfigInput({
            advance_generation_day: 0,
            due_date_day: 0,
            disconnection_day: 0,
            overdue_day: 0,
            disconnection_notice: 0,
            disconnection_fee: 0,
            pullout_day: 0,
            vat_rate: 0.12
          });
          setIsEditingBillingConfig(false);
        } catch (error: any) {
          console.error('Error deleting billing config:', error);
          setModal({
            isOpen: true,
            type: 'error',
            title: 'Error',
            message: `Failed to delete: ${error.response?.data?.message || error.message}`
          });
        } finally {
          setLoadingBillingConfig(false);
        }
      },
      onCancel: () => {
        setModal({ ...modal, isOpen: false });
      }
    });
  };

  const handleCancelBillingConfigEdit = () => {
    if (billingConfig) {
      setBillingConfigInput(billingConfig);
    } else {
      setBillingConfigInput({
        advance_generation_day: 0,
        due_date_day: 0,
        disconnection_day: 0,
        overdue_day: 0,
        disconnection_notice: 0,
        disconnection_fee: 0,
        pullout_day: 0,
        vat_rate: 0.12
      });
    }
    setIsEditingBillingConfig(false);
  };

  const handleBillingConfigInputChange = (field: keyof BillingConfigData, value: string) => {
    if (value === '') {
      setBillingConfigInput(prev => ({
        ...prev,
        [field]: '' as any
      }));
      return;
    }

    if (field === 'disconnection_fee') {
      const floatValue = parseFloat(value);
      if (!isNaN(floatValue) && floatValue >= 0) {
        setBillingConfigInput(prev => ({
          ...prev,
          [field]: floatValue
        }));
      }
      return;
    }

    // The operator types a percentage; we store a fraction. Capped at 100% because the API
    // rejects anything above 1 and a higher value is always a typo rather than an intent.
    if (field === 'vat_rate') {
      const percent = parseFloat(value);
      if (!isNaN(percent) && percent >= 0 && percent <= 100) {
        setBillingConfigInput(prev => ({
          ...prev,
          vat_rate: Math.round((percent / 100) * 10000) / 10000
        }));
      }
      return;
    }

    const numValue = parseInt(value, 10);
    if (!isNaN(numValue) && numValue >= 0 && numValue <= 31) {
      setBillingConfigInput(prev => ({
        ...prev,
        [field]: numValue
      }));
    }
  };

  return (
    <div className={`p-6 min-h-full ${isDarkMode ? 'bg-gray-950' : 'bg-gray-50'
      }`}>
      <div className={`mb-6 pb-6 border-b ${isDarkMode ? 'border-gray-700' : 'border-gray-200'
        }`}>
        <div className="flex items-center justify-between">
          <div>
            <h2 className={`text-2xl font-semibold mb-2 flex items-center gap-3 ${isDarkMode ? 'text-white' : 'text-gray-900'
              }`}>
              Billing Configurations
            </h2>
          </div>
        </div>
      </div>

      <div className="space-y-6">
        <div className={`space-y-4 pb-6 border-b ${isDarkMode ? 'border-gray-700' : 'border-gray-200'
          }`}>
          <div className="flex items-center gap-3">
            <h3 className={`text-lg font-semibold ${isDarkMode ? 'text-white' : 'text-gray-900'
              }`}>
              Starting Account Number
            </h3>
          </div>

          <div className="space-y-4">
            <p className={`text-sm ${isDarkMode ? 'text-gray-400' : 'text-gray-600'
              }`}>
              Set a custom starting number for new billing accounts. This can only be created once. You can edit or delete it after creation.
            </p>

            {loadingAccountNumber ? (
              <div className="flex items-center justify-center py-8">
                <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-orange-500"></div>
              </div>
            ) : customAccountNumber && !isEditingAccountNumber ? (
              <div className="flex items-center justify-between py-2">
                <div className="flex items-center gap-3">
                  <div className="h-10 w-10 rounded-full flex items-center justify-center"
                    style={{
                      backgroundColor: colorPalette?.primary ? `${colorPalette.primary}33` : 'rgba(249, 115, 22, 0.2)'
                    }}>
                    <Hash className="h-5 w-5" style={{
                      color: colorPalette?.primary || '#7c3aed'
                    }} />
                  </div>
                  <div>
                    <p className={`font-medium text-lg ${isDarkMode ? 'text-white' : 'text-gray-900'
                      }`}>{customAccountNumber.starting_number}</p>
                    <p className={`text-xs ${isDarkMode ? 'text-gray-400' : 'text-gray-600'
                      }`}>Current starting number</p>
                  </div>
                </div>

                  <div className="flex flex-col gap-1 items-end mr-4">
                    {customAccountNumber.updated_by && (
                      <p className={`text-[10px] ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                        Updated by: {customAccountNumber.updated_by}
                      </p>
                    )}
                    {customAccountNumber.created_at && (
                      <p className={`text-[10px] ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                        Created: {new Date(customAccountNumber.created_at).toLocaleString('en-US', {
                          month: '2-digit',
                          day: '2-digit',
                          year: 'numeric',
                          hour: 'numeric',
                          minute: '2-digit',
                          second: '2-digit',
                          hour12: true
                        }).replace(',', '')}
                      </p>
                    )}
                    {customAccountNumber.updated_at && (
                      <p className={`text-[10px] ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                        Updated: {new Date(customAccountNumber.updated_at).toLocaleString('en-US', {
                          month: '2-digit',
                          day: '2-digit',
                          year: 'numeric',
                          hour: 'numeric',
                          minute: '2-digit',
                          second: '2-digit',
                          hour12: true
                        }).replace(',', '')}
                      </p>
                    )}
                  </div>

                <div className="flex items-center gap-2">
                  <button
                    onClick={() => setIsEditingAccountNumber(true)}
                    className="p-2 text-blue-400 hover:text-blue-300 hover:bg-blue-900 rounded transition-colors"
                    title="Edit"
                  >
                    <Edit2 size={18} />
                  </button>
                  <button
                    onClick={handleDeleteAccountNumber}
                    className="p-2 text-red-400 hover:text-red-300 hover:bg-red-900 rounded transition-colors"
                    title="Delete"
                  >
                    <Trash2 size={18} />
                  </button>
                </div>
              </div>
            ) : (
              <div className="space-y-4">
                <div>
                  <label className={`block text-sm font-medium mb-2 ${isDarkMode ? 'text-gray-300' : 'text-gray-700'
                    }`}>
                    Starting Number
                  </label>
                  <input
                    type="text"
                    value={accountNumberInput}
                    onChange={(e) => setAccountNumberInput(e.target.value)}
                    placeholder="e.g., ABC1234 (optional, max 7 characters)"
                    maxLength={7}
                    className={`w-full px-4 py-2 border rounded focus:outline-none focus:border-orange-500 uppercase ${isDarkMode
                      ? 'bg-gray-800 border-gray-700 text-white'
                      : 'bg-white border-gray-300 text-gray-900'
                      }`}
                    disabled={loadingAccountNumber}
                  />
                  <p className={`text-xs mt-2 ${isDarkMode ? 'text-gray-500' : 'text-gray-600'
                    }`}>
                    Enter any combination of letters and numbers (max 7 characters). Leave blank to generate without prefix.
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  <button
                    onClick={handleSaveAccountNumber}
                    disabled={loadingAccountNumber}
                    className="flex items-center gap-2 px-4 py-2 disabled:opacity-50 text-white rounded transition-colors"
                    style={{
                      backgroundColor: loadingAccountNumber ? '#4b5563' : (colorPalette?.primary || '#7c3aed')
                    }}
                    onMouseEnter={(e) => {
                      if (!loadingAccountNumber && colorPalette?.accent) {
                        e.currentTarget.style.backgroundColor = colorPalette.accent;
                      }
                    }}
                    onMouseLeave={(e) => {
                      if (!loadingAccountNumber && colorPalette?.primary) {
                        e.currentTarget.style.backgroundColor = colorPalette.primary;
                      }
                    }}
                  >
                    <Save size={18} />
                    <span>{customAccountNumber ? 'Update' : 'Create'}</span>
                  </button>
                  {customAccountNumber && (
                    <button
                      onClick={handleCancelEdit}
                      disabled={loadingAccountNumber}
                      className={`flex items-center gap-2 px-4 py-2 disabled:opacity-50 text-white rounded transition-colors ${isDarkMode
                        ? 'bg-gray-700 hover:bg-gray-600'
                        : 'bg-gray-400 hover:bg-gray-500'
                        }`}
                    >
                      <X size={18} />
                      <span>Cancel</span>
                    </button>
                  )}
                </div>
              </div>
            )}
          </div>
        </div>

        <div className={`space-y-4 pb-6 border-b ${isDarkMode ? 'border-gray-700' : 'border-gray-200'
          }`}>
          <div className="flex items-center gap-3">
            <h3 className={`text-lg font-semibold ${isDarkMode ? 'text-white' : 'text-gray-900'
              }`}>
              Billing Day Configuration
            </h3>
          </div>

          <div className="space-y-4">
            <p className={`text-sm ${isDarkMode ? 'text-gray-400' : 'text-gray-600'
              }`}>
              Configure the day intervals for billing operations. This can only be created once. You can edit or delete it after creation.
            </p>

            {loadingBillingConfig ? (
              <div className="flex items-center justify-center py-8">
                <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-orange-500"></div>
              </div>
            ) : billingConfig && !isEditingBillingConfig ? (
              <div className="space-y-4">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div className={`p-4 rounded ${isDarkMode ? 'bg-gray-800' : 'bg-gray-100'
                    }`}>
                    <p className={`text-xs mb-1 ${isDarkMode ? 'text-gray-400' : 'text-gray-600'
                      }`}>Advance Generation Day</p>
                    <p className={`font-medium text-lg ${isDarkMode ? 'text-white' : 'text-gray-900'
                      }`}>{billingConfig.advance_generation_day}</p>
                  </div>
                  <div className={`p-4 rounded ${isDarkMode ? 'bg-gray-800' : 'bg-gray-100'
                    }`}>
                    <p className={`text-xs mb-1 ${isDarkMode ? 'text-gray-400' : 'text-gray-600'
                      }`}>Due Date Day</p>
                    <p className={`font-medium text-lg ${isDarkMode ? 'text-white' : 'text-gray-900'
                      }`}>{billingConfig.due_date_day}</p>
                  </div>
                  <div className={`p-4 rounded ${isDarkMode ? 'bg-gray-800' : 'bg-gray-100'
                    }`}>
                    <p className={`text-xs mb-1 ${isDarkMode ? 'text-gray-400' : 'text-gray-600'
                      }`}>Disconnection Day</p>
                    <p className={`font-medium text-lg ${isDarkMode ? 'text-white' : 'text-gray-900'
                      }`}>{billingConfig.disconnection_day}</p>
                  </div>
                  <div className={`p-4 rounded ${isDarkMode ? 'bg-gray-800' : 'bg-gray-100'
                    }`}>
                    <p className={`text-xs mb-1 ${isDarkMode ? 'text-gray-400' : 'text-gray-600'
                      }`}>Overdue Day</p>
                    <p className={`font-medium text-lg ${isDarkMode ? 'text-white' : 'text-gray-900'
                      }`}>{billingConfig.overdue_day}</p>
                  </div>
                  <div className={`p-4 rounded ${isDarkMode ? 'bg-gray-800' : 'bg-gray-100'
                    }`}>
                    <p className={`text-xs mb-1 ${isDarkMode ? 'text-gray-400' : 'text-gray-600'
                      }`}>Disconnection Notice</p>
                    <p className={`font-medium text-lg ${isDarkMode ? 'text-white' : 'text-gray-900'
                      }`}>{billingConfig.disconnection_notice}</p>
                  </div>
                  <div className={`p-4 rounded ${isDarkMode ? 'bg-gray-800' : 'bg-gray-100'
                    }`}>
                    <p className={`text-xs mb-1 ${isDarkMode ? 'text-gray-400' : 'text-gray-600'
                      }`}>Disconnection Fee</p>
                    <p className={`font-medium text-lg ${isDarkMode ? 'text-white' : 'text-gray-900'
                      }`}>₱{Number(billingConfig.disconnection_fee).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</p>
                  </div>
                  <div className={`p-4 rounded ${isDarkMode ? 'bg-gray-800' : 'bg-gray-100'
                    }`}>
                    <p className={`text-xs mb-1 ${isDarkMode ? 'text-gray-400' : 'text-gray-600'
                      }`}>Pullout Day</p>
                    <p className={`font-medium text-lg ${isDarkMode ? 'text-white' : 'text-gray-900'
                      }`}>{billingConfig.pullout_day}</p>
                  </div>
                  <div className={`p-4 rounded ${isDarkMode ? 'bg-gray-800' : 'bg-gray-100'
                    }`}>
                    <p className={`text-xs mb-1 ${isDarkMode ? 'text-gray-400' : 'text-gray-600'
                      }`}>VAT Rate</p>
                    <p className={`font-medium text-lg ${isDarkMode ? 'text-white' : 'text-gray-900'
                      }`}>
                      {(Number(billingConfig.vat_rate ?? 0.12) * 100).toLocaleString(undefined, { maximumFractionDigits: 2 })}%
                    </p>
                  </div>
                </div>

                <div className="mt-4 pt-4 border-t border-gray-700/30 flex flex-wrap gap-x-6 gap-y-1">
                  {billingConfig.created_by && (
                    <p className={`text-[10px] ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                      Created by: {billingConfig.created_by}
                    </p>
                  )}
                  {billingConfig.updated_by && (
                    <p className={`text-[10px] ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                      Updated by: {billingConfig.updated_by}
                    </p>
                  )}
                  {billingConfig.created_at && (
                    <p className={`text-[10px] ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                      Created: {new Date(billingConfig.created_at).toLocaleString('en-US', {
                        month: '2-digit',
                        day: '2-digit',
                        year: 'numeric',
                        hour: 'numeric',
                        minute: '2-digit',
                        second: '2-digit',
                        hour12: true
                      }).replace(',', '')}
                    </p>
                  )}
                  {billingConfig.updated_at && (
                    <p className={`text-[10px] ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                      Updated: {new Date(billingConfig.updated_at).toLocaleString('en-US', {
                        month: '2-digit',
                        day: '2-digit',
                        year: 'numeric',
                        hour: 'numeric',
                        minute: '2-digit',
                        second: '2-digit',
                        hour12: true
                      }).replace(',', '')}
                    </p>
                  )}
                </div>

                <div className="flex items-center gap-2 pt-2">
                  <button
                    onClick={() => setIsEditingBillingConfig(true)}
                    className="flex items-center gap-2 px-4 py-2 text-blue-400 hover:text-blue-300 hover:bg-blue-900 rounded transition-colors"
                  >
                    <Edit2 size={18} />
                    <span>Edit</span>
                  </button>
                  <button
                    onClick={handleDeleteBillingConfig}
                    className="flex items-center gap-2 px-4 py-2 text-red-400 hover:text-red-300 hover:bg-red-900 rounded transition-colors"
                  >
                    <Trash2 size={18} />
                    <span>Delete</span>
                  </button>
                </div>
              </div>
            ) : (
              <div className="space-y-4">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-300 mb-2">
                      Advance Generation Day
                    </label>
                    <input
                      type="number"
                      value={billingConfigInput.advance_generation_day}
                      onChange={(e) => handleBillingConfigInputChange('advance_generation_day', e.target.value)}
                      onFocus={(e) => e.target.select()}
                      className={`w-full px-4 py-2 border rounded focus:outline-none focus:border-orange-500 ${isDarkMode
                        ? 'bg-gray-800 border-gray-700 text-white'
                        : 'bg-white border-gray-300 text-gray-900'
                        }`}
                      min="0"
                      max="31"
                      disabled={loadingBillingConfig}
                    />
                    <p className={`text-xs mt-2 ${isDarkMode ? 'text-gray-500' : 'text-gray-600'
                      }`}>
                      Days before billing day to generate bills (0-31, 0 = disabled)
                    </p>
                  </div>

                  <div>
                    <label className={`block text-sm font-medium mb-2 ${isDarkMode ? 'text-gray-300' : 'text-gray-700'
                      }`}>
                      Due Date Day
                    </label>
                    <input
                      type="number"
                      value={billingConfigInput.due_date_day}
                      onChange={(e) => handleBillingConfigInputChange('due_date_day', e.target.value)}
                      onFocus={(e) => e.target.select()}
                      className={`w-full px-4 py-2 border rounded focus:outline-none focus:border-orange-500 ${isDarkMode
                        ? 'bg-gray-800 border-gray-700 text-white'
                        : 'bg-white border-gray-300 text-gray-900'
                        }`}
                      min="1"
                      max="31"
                      disabled={loadingBillingConfig}
                    />
                    <p className={`text-xs mt-2 ${isDarkMode ? 'text-gray-500' : 'text-gray-600'
                      }`}>
                      Days after billing day for payment due date (0-31, 0 = same day)
                    </p>
                  </div>

                  <div>
                    <label className={`block text-sm font-medium mb-2 ${isDarkMode ? 'text-gray-300' : 'text-gray-700'
                      }`}>
                      Disconnection Day
                    </label>
                    <input
                      type="number"
                      value={billingConfigInput.disconnection_day}
                      onChange={(e) => handleBillingConfigInputChange('disconnection_day', e.target.value)}
                      onFocus={(e) => e.target.select()}
                      className={`w-full px-4 py-2 border rounded focus:outline-none focus:border-orange-500 ${isDarkMode
                        ? 'bg-gray-800 border-gray-700 text-white'
                        : 'bg-white border-gray-300 text-gray-900'
                        }`}
                      min="1"
                      max="31"
                      disabled={loadingBillingConfig}
                    />
                    <p className={`text-xs mt-2 ${isDarkMode ? 'text-gray-500' : 'text-gray-600'
                      }`}>
                      Days after due date to disconnect service (0-31, 0 = disabled)
                    </p>
                  </div>

                  <div>
                    <label className={`block text-sm font-medium mb-2 ${isDarkMode ? 'text-gray-300' : 'text-gray-700'
                      }`}>
                      Overdue Day
                    </label>
                    <input
                      type="number"
                      value={billingConfigInput.overdue_day}
                      onChange={(e) => handleBillingConfigInputChange('overdue_day', e.target.value)}
                      onFocus={(e) => e.target.select()}
                      className={`w-full px-4 py-2 border rounded focus:outline-none focus:border-orange-500 ${isDarkMode
                        ? 'bg-gray-800 border-gray-700 text-white'
                        : 'bg-white border-gray-300 text-gray-900'
                        }`}
                      min="1"
                      max="31"
                      disabled={loadingBillingConfig}
                    />
                    <p className={`text-xs mt-2 ${isDarkMode ? 'text-gray-500' : 'text-gray-600'
                      }`}>
                      Days after due date to mark as overdue (0-31, 0 = same day)
                    </p>
                  </div>

                  <div>
                    <label className={`block text-sm font-medium mb-2 ${isDarkMode ? 'text-gray-300' : 'text-gray-700'
                      }`}>
                      Disconnection Notice
                    </label>
                    <input
                      type="number"
                      value={billingConfigInput.disconnection_notice}
                      onChange={(e) => handleBillingConfigInputChange('disconnection_notice', e.target.value)}
                      onFocus={(e) => e.target.select()}
                      className={`w-full px-4 py-2 border rounded focus:outline-none focus:border-orange-500 ${isDarkMode
                        ? 'bg-gray-800 border-gray-700 text-white'
                        : 'bg-white border-gray-300 text-gray-900'
                        }`}
                      min="1"
                      max="31"
                      disabled={loadingBillingConfig}
                    />
                    <p className={`text-xs mt-2 ${isDarkMode ? 'text-gray-500' : 'text-gray-600'
                      }`}>
                      Days before disconnection to send notice (0-31, 0 = disabled)
                    </p>
                  </div>

                  <div>
                    <label className={`block text-sm font-medium mb-2 ${isDarkMode ? 'text-gray-300' : 'text-gray-700'
                      }`}>
                      Disconnection Fee
                    </label>
                    <div className="relative">
                      <span className={`absolute left-3 top-2 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>₱</span>
                      <input
                        type="number"
                        step="0.01"
                        value={billingConfigInput.disconnection_fee}
                        onChange={(e) => handleBillingConfigInputChange('disconnection_fee', e.target.value)}
                        onFocus={(e) => e.target.select()}
                        className={`w-full pl-8 pr-4 py-2 border rounded focus:outline-none focus:border-orange-500 ${isDarkMode
                          ? 'bg-gray-800 border-gray-700 text-white'
                          : 'bg-white border-gray-300 text-gray-900'
                          }`}
                        min="0"
                        disabled={loadingBillingConfig}
                      />
                    </div>
                    <p className={`text-xs mt-2 ${isDarkMode ? 'text-gray-500' : 'text-gray-600'
                      }`}>
                      Fee to be charged upon service disconnection
                    </p>
                  </div>

                  <div>
                    <label className={`block text-sm font-medium mb-2 ${isDarkMode ? 'text-gray-300' : 'text-gray-700'
                      }`}>
                      VAT Rate
                    </label>
                    <div className="relative">
                      <input
                        type="number"
                        step="0.01"
                        // Displayed as a percentage; stored as a fraction.
                        value={Number(((billingConfigInput.vat_rate ?? 0) * 100).toFixed(2))}
                        onChange={(e) => handleBillingConfigInputChange('vat_rate', e.target.value)}
                        onFocus={(e) => e.target.select()}
                        className={`w-full pl-4 pr-8 py-2 border rounded focus:outline-none focus:border-orange-500 ${isDarkMode
                          ? 'bg-gray-800 border-gray-700 text-white'
                          : 'bg-white border-gray-300 text-gray-900'
                          }`}
                        min="0"
                        max="100"
                        disabled={loadingBillingConfig}
                      />
                      <span className={`absolute right-3 top-2 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>%</span>
                    </div>
                    <p className={`text-xs mt-2 ${isDarkMode ? 'text-gray-500' : 'text-gray-600'
                      }`}>
                      Plan prices are VAT-inclusive, so this is the rate extracted from the plan
                      amount &mdash; not added on top. Changing it affects future bills only;
                      invoices already issued keep the rate they were charged at.
                    </p>
                  </div>

                  <div>
                    <label className={`block text-sm font-medium mb-2 ${isDarkMode ? 'text-gray-300' : 'text-gray-700'
                      }`}>
                      Pullout Day
                    </label>
                    <input
                      type="number"
                      value={billingConfigInput.pullout_day}
                      onChange={(e) => handleBillingConfigInputChange('pullout_day', e.target.value)}
                      onFocus={(e) => e.target.select()}
                      className={`w-full px-4 py-2 border rounded focus:outline-none focus:border-orange-500 ${isDarkMode
                        ? 'bg-gray-800 border-gray-700 text-white'
                        : 'bg-white border-gray-300 text-gray-900'
                        }`}
                      min="0"
                      max="31"
                      disabled={loadingBillingConfig}
                    />
                    <p className={`text-xs mt-2 ${isDarkMode ? 'text-gray-500' : 'text-gray-600'
                      }`}>
                      Days after disconnection to pull out equipment (0-31, 0 = disabled)
                    </p>
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  <button
                    onClick={handleSaveBillingConfig}
                    disabled={loadingBillingConfig}
                    className="flex items-center gap-2 px-4 py-2 disabled:opacity-50 text-white rounded transition-colors"
                    style={{
                      backgroundColor: loadingBillingConfig ? '#4b5563' : (colorPalette?.primary || '#7c3aed')
                    }}
                    onMouseEnter={(e) => {
                      if (!loadingBillingConfig && colorPalette?.accent) {
                        e.currentTarget.style.backgroundColor = colorPalette.accent;
                      }
                    }}
                    onMouseLeave={(e) => {
                      if (!loadingBillingConfig && colorPalette?.primary) {
                        e.currentTarget.style.backgroundColor = colorPalette.primary;
                      }
                    }}
                  >
                    <Save size={18} />
                    <span>{billingConfig ? 'Update' : 'Create'}</span>
                  </button>
                  {billingConfig && (
                    <button
                      onClick={handleCancelBillingConfigEdit}
                      disabled={loadingBillingConfig}
                      className={`flex items-center gap-2 px-4 py-2 disabled:opacity-50 text-white rounded transition-colors ${isDarkMode
                        ? 'bg-gray-700 hover:bg-gray-600'
                        : 'bg-gray-400 hover:bg-gray-500'
                        }`}
                    >
                      <X size={18} />
                      <span>Cancel</span>
                    </button>
                  )}
                </div>
              </div>
            )}
          </div>
        </div>

      </div>

      {modal.isOpen && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className={`rounded-lg p-6 max-w-md w-full mx-4 ${isDarkMode
            ? 'bg-gray-900 border border-gray-700'
            : 'bg-white border border-gray-200'
            }`}>
            <h3 className={`text-lg font-semibold mb-4 ${isDarkMode ? 'text-white' : 'text-gray-900'
              }`}>{modal.title}</h3>
            <p className={`mb-6 ${isDarkMode ? 'text-gray-300' : 'text-gray-700'
              }`}>{modal.message}</p>
            <div className="flex items-center justify-end gap-3">
              {modal.type === 'confirm' ? (
                <>
                  <button
                    onClick={modal.onCancel}
                    className={`px-4 py-2 text-white rounded transition-colors ${isDarkMode
                      ? 'bg-gray-700 hover:bg-gray-600'
                      : 'bg-gray-400 hover:bg-gray-500'
                      }`}
                  >
                    Cancel
                  </button>
                  <button
                    onClick={modal.onConfirm}
                    className="px-4 py-2 text-white rounded transition-colors"
                    style={{
                      backgroundColor: colorPalette?.primary || '#7c3aed'
                    }}
                    onMouseEnter={(e) => {
                      if (colorPalette?.accent) {
                        e.currentTarget.style.backgroundColor = colorPalette.accent;
                      }
                    }}
                    onMouseLeave={(e) => {
                      if (colorPalette?.primary) {
                        e.currentTarget.style.backgroundColor = colorPalette.primary;
                      }
                    }}
                  >
                    Confirm
                  </button>
                </>
              ) : (
                <button
                  onClick={() => setModal({ ...modal, isOpen: false })}
                  className="px-4 py-2 text-white rounded transition-colors"
                  style={{
                    backgroundColor: colorPalette?.primary || '#7c3aed'
                  }}
                  onMouseEnter={(e) => {
                    if (colorPalette?.accent) {
                      e.currentTarget.style.backgroundColor = colorPalette.accent;
                    }
                  }}
                  onMouseLeave={(e) => {
                    if (colorPalette?.primary) {
                      e.currentTarget.style.backgroundColor = colorPalette.primary;
                    }
                  }}
                >
                  OK
                </button>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

export default BillingConfig;
