require('./bootstrap');

import Vue from 'vue'
import ElementUI from 'element-ui'
import VueApexCharts from 'vue-apexcharts'

import lang from 'element-ui/lib/locale/lang/es'
import locale from 'element-ui/lib/locale'

locale.use(lang)

ElementUI.Select.computed.readonly = function () {
    const isIE = !this.$isServer && !Number.isNaN(Number(document.documentMode));
    return !(this.filterable || this.multiple || !isIE) && !this.visible;
};

export default ElementUI;

Vue.use(ElementUI, { size: 'small' })
Vue.component('apexchart', VueApexCharts)
Vue.prototype.$eventHub = new Vue()
Vue.prototype.$icon = name => (window.SYSTEM_ICONS_URL || '/images/system-icons.svg') + '#' + name

// Page components below are async (require.ensure: no extra Babel syntax plugin needed): each page downloads only the chunk it mounts.
// The dashboard stays eager because it is the landing page (no extra round trip before first paint).
Vue.component('tenant-dashboard-index', require('../../modules/Dashboard/Resources/assets/js/views/index.vue'));

Vue.component('x-graph', require('./components/graph/src/Graph.vue'));
Vue.component('x-graph-line', require('./components/graph/src/GraphLine.vue'));

Vue.component('tenant-companies-form', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/companies/form.vue')), reject, 'tenant-companies-form'));
Vue.component('tenant-companies-logo', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/companies/logo.vue')), reject, 'tenant-companies-logo'));
Vue.component('tenant-certificates-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/certificates/index.vue')), reject, 'tenant-certificates-index'));
Vue.component('tenant-certificates-form', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/certificates/form.vue')), reject, 'tenant-certificates-form'));
Vue.component('tenant-configurations-form', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/configurations/form.vue')), reject, 'tenant-configurations-form'));
Vue.component('tenant-configurations-visual', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/configurations/visual.vue')), reject, 'tenant-configurations-visual'));
Vue.component('tenant-configurations-pdf', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/configurations/pdf_templates.vue')), reject, 'tenant-configurations-pdf'));
Vue.component('tenant-configurations-pdf-guide', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/configurations/pdf_guide_templates.vue')), reject, 'tenant-configurations-pdf-guide'));
Vue.component('tenant-configurations-preprinted-pdf', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/configurations/pdf_preprinted_templates.vue')), reject, 'tenant-configurations-preprinted-pdf'));
// Vue.component('tenant-establishments-form', require('./views/tenant/establishments/form.vue'));
// Vue.component('tenant-series-form', require('./views/tenant/series/form.vue'));
Vue.component('tenant-bank_accounts-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/bank_accounts/index.vue')), reject, 'tenant-bank_accounts-index'));
Vue.component('tenant-items-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/items/index.vue')), reject, 'tenant-items-index'));
Vue.component('tenant-persons-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/persons/index.vue')), reject, 'tenant-persons-index'));
// Vue.component('tenant-customers-index', require('./views/tenant/customers/index.vue'));
// Vue.component('tenant-suppliers-index', require('./views/tenant/suppliers/index.vue'));
Vue.component('tenant-users-form', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/users/form.vue')), reject, 'tenant-users-form'));
Vue.component('tenant-documents-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/documents/index.vue')), reject, 'tenant-documents-index'));
Vue.component('tenant-documents-invoice', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/documents/invoice.vue')), reject, 'tenant-documents-invoice'));
Vue.component('tenant-documents-invoicetensu', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/documents/invoicetensu.vue')), reject, 'tenant-documents-invoicetensu'));
Vue.component('tenant-documents-note', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/documents/note.vue')), reject, 'tenant-documents-note'));
Vue.component('tenant-summaries-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/summaries/index.vue')), reject, 'tenant-summaries-index'));
Vue.component('tenant-voided-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/voided/index.vue')), reject, 'tenant-voided-index'));
Vue.component('tenant-search-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/search/index.vue')), reject, 'tenant-search-index'));
Vue.component('tenant-options-form', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/options/form.vue')), reject, 'tenant-options-form'));
Vue.component('tenant-unit_types-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/unit_types/index.vue')), reject, 'tenant-unit_types-index'));
Vue.component('tenant-detraction_types-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/detraction_types/index.vue')), reject, 'tenant-detraction_types-index'));
Vue.component('tenant-users-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/users/index.vue')), reject, 'tenant-users-index'));
Vue.component('tenant-establishments-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/establishments/index.vue')), reject, 'tenant-establishments-index'));
Vue.component('tenant-charge_discounts-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/charge_discounts/index.vue')), reject, 'tenant-charge_discounts-index'));
Vue.component('tenant-banks-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/banks/index.vue')), reject, 'tenant-banks-index'));
Vue.component('tenant-exchange_rates-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/exchange_rates/index.vue')), reject, 'tenant-exchange_rates-index'));
Vue.component('tenant-currency-types-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/currency_types/index.vue')), reject, 'tenant-currency-types-index'));
Vue.component('tenant-retentions-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/retentions/index.vue')), reject, 'tenant-retentions-index'));
Vue.component('tenant-retentions-form', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/retentions/form.vue')), reject, 'tenant-retentions-form'));
Vue.component('tenant-perceptions-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/perceptions/index.vue')), reject, 'tenant-perceptions-index'));
Vue.component('tenant-perceptions-form', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/perceptions/form.vue')), reject, 'tenant-perceptions-form'));
Vue.component('tenant-dispatches-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/dispatches/index.vue')), reject, 'tenant-dispatches-index'));
Vue.component('tenant-dispatches-form', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/dispatches/form.vue')), reject, 'tenant-dispatches-form'));
Vue.component('tenant-dispatches-create', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/dispatches/create.vue')), reject, 'tenant-dispatches-create'));
Vue.component('tenant-purchases-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/purchases/index.vue')), reject, 'tenant-purchases-index'));
Vue.component('tenant-purchases-form', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/purchases/form.vue')), reject, 'tenant-purchases-form'));
Vue.component('tenant-purchases-edit', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/purchases/form_edit.vue')), reject, 'tenant-purchases-edit'));

Vue.component('tenant-purchases-items', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/dispatches/items.vue')), reject, 'tenant-purchases-items'));
Vue.component('tenant-attribute_types-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/attribute_types/index.vue')), reject, 'tenant-attribute_types-index'));
Vue.component('tenant-calendar', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/components/calendar.vue')), reject, 'tenant-calendar'));
Vue.component('tenant-warehouses', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/components/warehouses.vue')), reject, 'tenant-warehouses'));
Vue.component('tenant-calendar-quotation', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/components/calendarquotations.vue')), reject, 'tenant-calendar-quotation'));

//Vue.component('tenant-calendar', require('./views/tenant/components/calendar.vue'));
Vue.component('tenant-product', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/components/products.vue')), reject, 'tenant-product'));


Vue.component('tenant-tasks-lists', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/tasks/lists.vue')), reject, 'tenant-tasks-lists'));
Vue.component('tenant-tasks-form', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/tasks/form.vue')), reject, 'tenant-tasks-form'));
Vue.component('tenant-reports-consistency-documents-lists', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/reports/consistency-documents/lists.vue')), reject, 'tenant-reports-consistency-documents-lists'));
Vue.component('tenant-contingencies-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/contingencies/index.vue')), reject, 'tenant-contingencies-index'));

Vue.component('tenant-quotations-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/quotations/index.vue')), reject, 'tenant-quotations-index'));
Vue.component('tenant-quotations-form', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/quotations/form.vue')), reject, 'tenant-quotations-form'));
Vue.component('tenant-quotations-edit', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/quotations/form_edit.vue')), reject, 'tenant-quotations-edit'));


Vue.component('tenant-sale-notes-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/sale_notes/index.vue')), reject, 'tenant-sale-notes-index'));
Vue.component('tenant-sale-notes-form', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/sale_notes/form.vue')), reject, 'tenant-sale-notes-form'));
Vue.component('tenant-pos-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/pos/index.vue')), reject, 'tenant-pos-index'));
Vue.component('cash-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/cash/index.vue')), reject, 'cash-index'));
Vue.component('tenant-card-brands-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/card_brands/index.vue')), reject, 'tenant-card-brands-index'));

Vue.component('tenant-payment-method-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/payment_method/index.vue')), reject, 'tenant-payment-method-index'));
Vue.component('tenant-payment-method-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/payment_method/index.vue')), reject, 'tenant-payment-method-index'));



// Modules
Vue.component('inventory-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Inventory/Resources/assets/js/inventory/index.vue')), reject, 'inventory-index'));
Vue.component('inventory-transfers-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Inventory/Resources/assets/js/transfers/index.vue')), reject, 'inventory-transfers-index'));
Vue.component('warehouses-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Inventory/Resources/assets/js/warehouses/index.vue')), reject, 'warehouses-index'));
Vue.component('tenant-report-kardex-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Inventory/Resources/assets/js/kardex/index.vue')), reject, 'tenant-report-kardex-index'));
Vue.component('tenant-inventories-form', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Inventory/Resources/assets/js/config/form.vue')), reject, 'tenant-inventories-form'));
Vue.component('tenant-expenses-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Expense/Resources/assets/js/views/expenses/index.vue')), reject, 'tenant-expenses-index'));
Vue.component('tenant-expenses-form', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Expense/Resources/assets/js/views/expenses/form.vue')), reject, 'tenant-expenses-form'));
Vue.component('tenant-account-export', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Account/Resources/assets/js/views/account/export.vue')), reject, 'tenant-account-export'));
Vue.component('tenant-account-summary-report', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Account/Resources/assets/js/views/summary_report/index.vue')), reject, 'tenant-account-summary-report'));
Vue.component('tenant-account-format', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Account/Resources/assets/js/views/account/format.vue')), reject, 'tenant-account-format'));
Vue.component('tenant-company-accounts', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Account/Resources/assets/js/views/company_accounts/form.vue')), reject, 'tenant-company-accounts'));

Vue.component('tenant-inventory-devolutions-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Inventory/Resources/assets/js/devolutions/index.vue')), reject, 'tenant-inventory-devolutions-index'));
Vue.component('tenant-inventory-devolutions-form', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Inventory/Resources/assets/js/devolutions/form.vue')), reject, 'tenant-inventory-devolutions-form'));

Vue.component('tenant-documents-not-sent', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Document/Resources/assets/js/views/documents/not_sent.vue')), reject, 'tenant-documents-not-sent'));
Vue.component('tenant-report-purchases-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Report/Resources/assets/js/views/purchases/index.vue')), reject, 'tenant-report-purchases-index'));
Vue.component('tenant-report-documents-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Report/Resources/assets/js/views/documents/index.vue')), reject, 'tenant-report-documents-index'));
Vue.component('tenant-report-customers-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Report/Resources/assets/js/views/customers/index.vue')), reject, 'tenant-report-customers-index'));
Vue.component('tenant-report-items-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Report/Resources/assets/js/views/items/index.vue')), reject, 'tenant-report-items-index'));
Vue.component('tenant-report-sale_notes-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Report/Resources/assets/js/views/sale_notes/index.vue')), reject, 'tenant-report-sale_notes-index'));
Vue.component('tenant-report-quotations-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Report/Resources/assets/js/views/quotations/index.vue')), reject, 'tenant-report-quotations-index'));
Vue.component('tenant-report-cash-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Report/Resources/assets/js/views/cash/index.vue')), reject, 'tenant-report-cash-index'));
Vue.component('tenant-index-configuration', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/BusinessTurn/Resources/assets/js/views/configurations/index.vue')), reject, 'tenant-index-configuration'));
Vue.component('tenant-report-document_hotels-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Report/Resources/assets/js/views/document_hotels/index.vue')), reject, 'tenant-report-document_hotels-index'));
Vue.component('tenant-report-commercial_analysis-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Report/Resources/assets/js/views/commercial_analysis/index.vue')), reject, 'tenant-report-commercial_analysis-index'));
Vue.component('tenant-offline-configurations-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Offline/Resources/assets/js/views/offline_configurations/index.vue')), reject, 'tenant-offline-configurations-index'));
Vue.component('tenant-series-configurations-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Document/Resources/assets/js/views/series_configurations/index.vue')), reject, 'tenant-series-configurations-index'));
Vue.component('tenant-validate-documents-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Document/Resources/assets/js/views/validate_documents/index.vue')), reject, 'tenant-validate-documents-index'));
Vue.component('tenant-report-document-detractions-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Report/Resources/assets/js/views/document-detractions/index.vue')), reject, 'tenant-report-document-detractions-index'));
Vue.component('tenant-report-commissions-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Report/Resources/assets/js/views/commissions/index.vue')), reject, 'tenant-report-commissions-index'));
Vue.component('tenant-report-order-notes-consolidated-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Report/Resources/assets/js/views/order_notes_consolidated/index.vue')), reject, 'tenant-report-order-notes-consolidated-index'));
Vue.component('tenant-report-general-items-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Report/Resources/assets/js/views/general_items/index.vue')), reject, 'tenant-report-general-items-index'));
Vue.component('tenant-report-order-notes-general-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Report/Resources/assets/js/views/order_notes_general/index.vue')), reject, 'tenant-report-order-notes-general-index'));
Vue.component('tenant-report-sales-consolidated-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Report/Resources/assets/js/views/sales_consolidated/index.vue')), reject, 'tenant-report-sales-consolidated-index'));
Vue.component('tenant-report-user-commissions-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Report/Resources/assets/js/views/user_commissions/index.vue')), reject, 'tenant-report-user-commissions-index'));
Vue.component('tenant-report-fixed-asset-purchases-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Report/Resources/assets/js/views/fixed-asset-purchases/index.vue')), reject, 'tenant-report-fixed-asset-purchases-index'));
Vue.component('tenant-report-massive-downloads-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Report/Resources/assets/js/views/massive-downloads/index.vue')), reject, 'tenant-report-massive-downloads-index'));
Vue.component('tenant-documents-regularize-shipping', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Document/Resources/assets/js/views/documents/regularize_shipping.vue')), reject, 'tenant-documents-regularize-shipping'));


Vue.component('tenant-categories-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Item/Resources/assets/js/views/categories/index.vue')), reject, 'tenant-categories-index'));
Vue.component('tenant-brands-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Item/Resources/assets/js/views/brands/index.vue')), reject, 'tenant-brands-index'));
Vue.component('tenant-incentives-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Item/Resources/assets/js/views/incentives/index.vue')), reject, 'tenant-incentives-index'));
Vue.component('tenant-item-lots-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Item/Resources/assets/js/views/item-lots/index.vue')), reject, 'tenant-item-lots-index'));

Vue.component('tenant-ecommerce-configuration-info', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Ecommerce/Resources/assets/js/views/configuration/index.vue')), reject, 'tenant-ecommerce-configuration-info'));
Vue.component('tenant-ecommerce-configuration-culqi', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Ecommerce/Resources/assets/js/views/configuration_culqi/index.vue')), reject, 'tenant-ecommerce-configuration-culqi'));
Vue.component('tenant-ecommerce-configuration-paypal', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Ecommerce/Resources/assets/js/views/configuration_paypal/index.vue')), reject, 'tenant-ecommerce-configuration-paypal'));
Vue.component('tenant-ecommerce-configuration-logo', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Ecommerce/Resources/assets/js/views/configuration_logo/index.vue')), reject, 'tenant-ecommerce-configuration-logo'));
Vue.component('tenant-ecommerce-configuration-social', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Ecommerce/Resources/assets/js/views/configuration_social/index.vue')), reject, 'tenant-ecommerce-configuration-social'));
Vue.component('tenant-ecommerce-configuration-tag', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Ecommerce/Resources/assets/js/views/configuration_tags/index.vue')), reject, 'tenant-ecommerce-configuration-tag'));

Vue.component('tenant-purchase-quotations-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Purchase/Resources/assets/js/views/purchase-quotations/index.vue')), reject, 'tenant-purchase-quotations-index'));
Vue.component('tenant-purchase-quotations-form', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Purchase/Resources/assets/js/views/purchase-quotations/form.vue')), reject, 'tenant-purchase-quotations-form'));

Vue.component('tenant-purchase-orders-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Purchase/Resources/assets/js/views/purchase-orders/index.vue')), reject, 'tenant-purchase-orders-index'));
Vue.component('tenant-purchase-orders-form', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Purchase/Resources/assets/js/views/purchase-orders/form.vue')), reject, 'tenant-purchase-orders-form'));
Vue.component('tenant-purchase-orders-generate', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Purchase/Resources/assets/js/views/purchase-orders/generate.vue')), reject, 'tenant-purchase-orders-generate'));

Vue.component('moves-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Inventory/Resources/assets/js/moves/index.vue')), reject, 'moves-index'));
Vue.component('inventory-form-masive', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Inventory/Resources/assets/js/transfers/form_masive.vue')), reject, 'inventory-form-masive'));

Vue.component('tenant-report-kardex-master', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Inventory/Resources/assets/js/kardex_master/index.vue')), reject, 'tenant-report-kardex-master'));
Vue.component('tenant-report-kardex-lots', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Inventory/Resources/assets/js/kardex/lots.vue')), reject, 'tenant-report-kardex-lots'));
Vue.component('tenant-report-kardex-series', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Inventory/Resources/assets/js/kardex/series.vue')), reject, 'tenant-report-kardex-series'));

Vue.component('tenant-order-notes-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Order/Resources/assets/js/views/order_notes/index.vue')), reject, 'tenant-order-notes-index'));
Vue.component('tenant-order-notes-form', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Order/Resources/assets/js/views/order_notes/form.vue')), reject, 'tenant-order-notes-form'));
Vue.component('tenant-order-notes-edit', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Order/Resources/assets/js/views/order_notes/form_edit.vue')), reject, 'tenant-order-notes-edit'));
Vue.component('tenant-report-valued-kardex', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Inventory/Resources/assets/js/valued_kardex/index.vue')), reject, 'tenant-report-valued-kardex'));

//Finance
Vue.component('tenant-finance-global-payments-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Finance/Resources/assets/js/views/global_payments/index.vue')), reject, 'tenant-finance-global-payments-index'));
Vue.component('tenant-finance-balance-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Finance/Resources/assets/js/views/balance/index.vue')), reject, 'tenant-finance-balance-index'));
Vue.component('tenant-finance-payment-method-types-index', (resolve, reject) => require.ensure([], require => resolve(require('../../modules/Finance/Resources/assets/js/views/payment_method_types/index.vue')), reject, 'tenant-finance-payment-method-types-index'));
Vue.component('tenant-finance-unpaid-index', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleFinance/unpaid/index.vue')), reject, 'tenant-finance-unpaid-index'));
Vue.component('tenant-finance-to-pay-index', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleFinance/to_pay/index.vue')), reject, 'tenant-finance-to-pay-index'));
Vue.component('tenant-finance-income-index', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleFinance/income/index.vue')), reject, 'tenant-finance-income-index'));
Vue.component('tenant-finance-income-form', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleFinance/income/form.vue')), reject, 'tenant-finance-income-form'));
Vue.component('tenant-income-types-index', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleFinance/income_types/index.vue')), reject, 'tenant-income-types-index'));
Vue.component('tenant-income-reasons-index', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleFinance/income_reasons/index.vue')), reject, 'tenant-income-reasons-index'));
Vue.component('tenant-finance-movements-index', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleFinance/movements/index.vue')), reject, 'tenant-finance-movements-index'));


//Sale
Vue.component('tenant-sale-opportunities-index', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleSale/sale_opportunities/index.vue')), reject, 'tenant-sale-opportunities-index'));
Vue.component('tenant-sale-opportunities-form', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleSale/sale_opportunities/form.vue')), reject, 'tenant-sale-opportunities-form'));
Vue.component('tenant-payment-method-types-index', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleSale/payment_method_types/index.vue')), reject, 'tenant-payment-method-types-index'));
Vue.component('tenant-contracts-index', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleSale/contracts/index.vue')), reject, 'tenant-contracts-index'));
Vue.component('tenant-contracts-form', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleSale/contracts/form.vue')), reject, 'tenant-contracts-form'));
Vue.component('tenant-contracts-recurrent', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleSale/contracts/recurrent.vue')), reject, 'tenant-contracts-recurrent'));
Vue.component('tenant-production-orders-index', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleSale/production_orders/index.vue')), reject, 'tenant-production-orders-index'));

//Item
Vue.component('tenant-web-platforms-index', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleItem/web-platforms/index.vue')), reject, 'tenant-web-platforms-index'));

//technical Services
Vue.component('tenant-technical-services-index', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleSale/technical-services/index.vue')), reject, 'tenant-technical-services-index'));
Vue.component('tenant-user-commissions-index', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleSale/user-commissions/index.vue')), reject, 'tenant-user-commissions-index'));

//Purchase

Vue.component('tenant-fixed-asset-items-index', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModulePurchase/fixed_asset_items/index.vue')), reject, 'tenant-fixed-asset-items-index'));
Vue.component('tenant-fixed-asset-purchases-index', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModulePurchase/fixed_asset_purchases/index.vue')), reject, 'tenant-fixed-asset-purchases-index'));
Vue.component('tenant-fixed-asset-purchases-form', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModulePurchase/fixed_asset_purchases/form.vue')), reject, 'tenant-fixed-asset-purchases-form'));

//Expense

Vue.component('tenant-expense-types-index', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleExpense/expense_types/index.vue')), reject, 'tenant-expense-types-index'));
Vue.component('tenant-expense-reasons-index', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleExpense/expense_reasons/index.vue')), reject, 'tenant-expense-reasons-index'));
Vue.component('tenant-expense-method-types-index', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleExpense/expense_method_types/index.vue')), reject, 'tenant-expense-method-types-index'));

//Order
Vue.component('tenant-drivers-index', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleOrder/drivers/index.vue')), reject, 'tenant-drivers-index'));
Vue.component('tenant-dispatchers-index', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleOrder/dispatchers/index.vue')), reject, 'tenant-dispatchers-index'));
Vue.component('tenant-order-forms-index', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleOrder/order_forms/index.vue')), reject, 'tenant-order-forms-index'));
Vue.component('tenant-order-forms-form', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleOrder/order_forms/form.vue')), reject, 'tenant-order-forms-form'));


// System
Vue.component('system-clients-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/system/clients/index.vue')), reject, 'system-clients-index'));
Vue.component('system-clients-form', (resolve, reject) => require.ensure([], require => resolve(require('./views/system/clients/form.vue')), reject, 'system-clients-form'));
Vue.component('system-users-form', (resolve, reject) => require.ensure([], require => resolve(require('./views/system/users/form.vue')), reject, 'system-users-form'));

Vue.component('system-configuration', (resolve, reject) => require.ensure([], require => resolve(require('./views/system/configuration/index.vue')), reject, 'system-configuration'));
Vue.component('system-certificate-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/system/certificate/index.vue')), reject, 'system-certificate-index'));
Vue.component('system-companies-form', (resolve, reject) => require.ensure([], require => resolve(require('./views/system/companies/form.vue')), reject, 'system-companies-form'));

Vue.component('system-accounting-index', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleAccount/system/accounting/index.vue')), reject, 'system-accounting-index'));

// Hoteles :: Tarifas
Vue.component('tenant-hotel-rates', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleHotel/rates/List.vue')), reject, 'tenant-hotel-rates'));
// Hoteles :: Categorías
Vue.component('tenant-hotel-categories', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleHotel/categories/List.vue')), reject, 'tenant-hotel-categories'));
// Hoteles :: Pisos
Vue.component('tenant-hotel-floors', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleHotel/floors/List.vue')), reject, 'tenant-hotel-floors'));
// Hoteles :: Habitaciones
Vue.component('tenant-hotel-rooms', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleHotel/rooms/List.vue')), reject, 'tenant-hotel-rooms'));
// Hoteles :: Recepción
Vue.component('tenant-hotel-reception', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleHotel/rooms/Reception.vue')), reject, 'tenant-hotel-reception'));
// Hoteles :: Rentar habitación
Vue.component('tenant-hotel-rent', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleHotel/rooms/Rent.vue')), reject, 'tenant-hotel-rent'));
// Hoteles :: Agregar producto a la habitación rentada
Vue.component('tenant-hotel-rent-add-product', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleHotel/rooms/AddProductToRoom.vue')), reject, 'tenant-hotel-rent-add-product'));
// Hoteles :: Checkout
Vue.component('tenant-hotel-rent-checkout', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleHotel/rooms/Checkout.vue')), reject, 'tenant-hotel-rent-checkout'));

// Trámite documentario
Vue.component('tenant-documentary-offices', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleDocumentary/offices/Offices.vue')), reject, 'tenant-documentary-offices'));
Vue.component('tenant-documentary-processes', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleDocumentary/processes/Processes.vue')), reject, 'tenant-documentary-processes'));
Vue.component('tenant-documentary-documents', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleDocumentary/documents/Documents.vue')), reject, 'tenant-documentary-documents'));
Vue.component('tenant-documentary-actions', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleDocumentary/actions/Actions.vue')), reject, 'tenant-documentary-actions'));
Vue.component('tenant-documentary-files', (resolve, reject) => require.ensure([], require => resolve(require('@viewsModuleDocumentary/files/Files.vue')), reject, 'tenant-documentary-files'));

Vue.component('system-plans-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/system/plans/index.vue')), reject, 'system-plans-index'));
Vue.component('system-plans-form', (resolve, reject) => require.ensure([], require => resolve(require('./views/system/plans/form.vue')), reject, 'system-plans-form'));

Vue.component('x-input-service', require('./components/InputService.vue'));

Vue.component('tenant-items-ecommerce-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/items_ecommerce/index.vue')), reject, 'tenant-items-ecommerce-index'));
Vue.component('tenant-ecommerce-cart', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/ecommerce/cart_dropdown.vue')), reject, 'tenant-ecommerce-cart'));
Vue.component('tenant-tags-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/tags/index.vue')), reject, 'tenant-tags-index'));
Vue.component('tenant-promotions-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/promotions/index.vue')), reject, 'tenant-promotions-index'));

Vue.component('tenant-item-sets-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/item_sets/index.vue')), reject, 'tenant-item-sets-index'));
Vue.component('tenant-person-types-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/person_types/index.vue')), reject, 'tenant-person-types-index'));

Vue.component('tenant-orders-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/orders/index.vue')), reject, 'tenant-orders-index'));

//Cuenta
Vue.component('tenant-account-payment-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/account/payment_index.vue')), reject, 'tenant-account-payment-index'));
Vue.component('tenant-account-configuration-index', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/account/configuration.vue')), reject, 'tenant-account-configuration-index'));

//auto update
Vue.component('system-update', (resolve, reject) => require.ensure([], require => resolve(require('./views/system/update/index.vue')), reject, 'system-update'));

//auto update
Vue.component('system-backup', (resolve, reject) => require.ensure([], require => resolve(require('./views/system/backup/index.vue')), reject, 'system-backup'));

//culqi
Vue.component('system-configuration-culqi', (resolve, reject) => require.ensure([], require => resolve(require('./views/system/configuration/culqi.vue')), reject, 'system-configuration-culqi'));

//token
Vue.component('system-configuration-token', (resolve, reject) => require.ensure([], require => resolve(require('./views/system/configuration/token_ruc_dni.vue')), reject, 'system-configuration-token'));

//Configuración global del login
Vue.component('system-login-settings', (resolve, reject) => require.ensure([], require => resolve(require('./views/system/configuration/login.vue')), reject, 'system-login-settings'));

// Configuración del login
Vue.component('tenant-login-page', (resolve, reject) => require.ensure([], require => resolve(require('./views/tenant/login/index.vue')), reject, 'tenant-login-page'));

import moment from 'moment';

Vue.mixin({
    filters: {
        toDecimals(number, decimal = 2) {
            return Number(number).toFixed(decimal);
        },
        toDate(date) {
            if (date) {
                return moment(date).format('DD/MM/YYYY');
            }
            return '';
        },
        toTime(time) {
            if (time) {
                if (time.length === 5) {
                    return moment(time + ':00', 'HH:mm:ss').format('HH:mm:ss');
                }
                return moment(time, 'HH:mm:ss').format('HH:mm:ss');
            }
            return '';
        },
        pad(value, fill = '', length = 3) {
            if (value) {
                return String(value).padStart(length, fill);
            }
            return value;
        }
    },
    methods: {
        axiosError(error) {
            const response = error.response;
            const status = response.status;
            if (status === 422) {
                this.errors = response.data
            }
            if (status === 500) {
                this.$message({
                    type: 'info',
                    message: response.data.message
                  });
            }
        }
    }
})
let systemVueInstance = null;

window.unmountSystemVue = function unmountSystemVue() {
    if (typeof ElementUI !== 'undefined' && ElementUI.MessageBox && typeof ElementUI.MessageBox.close === 'function') {
        try {
            ElementUI.MessageBox.close();
        } catch (e) {}
    }
    if (Vue.prototype.$eventHub && typeof Vue.prototype.$eventHub.$off === 'function') {
        Vue.prototype.$eventHub.$off();
    }
    if (systemVueInstance && typeof systemVueInstance.$destroy === 'function') {
        try {
            systemVueInstance.$destroy();
        } catch (e) {
            console.warn('Error destroying previous Vue instance:', e);
        }
    }
    systemVueInstance = null;
};

window.mountSystemVue = function mountSystemVue(selector = '#main-wrapper') {
    window.unmountSystemVue();
    const target = document.querySelector(selector);
    if (!target) return null;

    if (target.firstElementChild && target.firstElementChild.hasAttribute('v-pre') && target.children.length === 1) {
        return null;
    }

    systemVueInstance = new Vue({
        el: selector
    });
    return systemVueInstance;
};

const app = window.mountSystemVue('#main-wrapper');
