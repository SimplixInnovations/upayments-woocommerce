// A helper to safely retrieve data passed from the PHP class
const { wcSettings } = window;
const settings = wcSettings.default.paymentMethods || {};
const upayments_data = settings.upayments || {}; // Use your exact gateway ID here

export const getPaymentMethodData = () => upayments_data;