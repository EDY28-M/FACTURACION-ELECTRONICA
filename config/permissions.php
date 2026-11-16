<?php

/*
|--------------------------------------------------------------------------
| Tenant access map (single source of truth)
|--------------------------------------------------------------------------
|
| Maps the URL path (first segments) to the module (modules.value) and, when
| applicable, to the level (module_levels.value) a user needs to open it. The
| rules mirror the gates of resources/views/tenant/layouts/partials/sidebar.blade.php.
|
| Rule keys:
|   path   segments prefix the rule matches (most specific rule wins)
|   module required module value; null = explicitly open to any user
|   level  required level value (optional)
|   exact  true = only matches when the path has exactly these segments
|   ajax   true = also enforced for AJAX/JSON requests. Endpoints that other
|          modules call to fill their own forms (items, persons, cash, ...)
|          stay page-level only.
|
| Paths without any rule are allowed (and logged at debug level).
| The tenant owner (first user) is never restricted.
|
*/

return [

    'rules' => [

        // Dashboard
        ['path' => 'dashboard', 'module' => 'dashboard', 'ajax' => true],

        // Ventas (module "documents") and its levels
        ['path' => 'documents/create', 'module' => 'documents', 'level' => 'new_document'],
        ['path' => 'documents/create_tensu', 'module' => 'documents', 'level' => 'new_document'],
        ['path' => 'documents/not-sent', 'module' => 'documents', 'level' => 'document_not_sent', 'ajax' => true],
        ['path' => 'documents/regularize-shipping', 'module' => 'documents', 'level' => 'regularize_shipping', 'ajax' => true],
        ['path' => 'documents', 'module' => 'documents', 'level' => 'list_document', 'exact' => true],
        ['path' => 'documents', 'module' => 'documents'],
        ['path' => 'summaries', 'module' => 'documents', 'level' => 'summary_voided', 'ajax' => true],
        ['path' => 'voided', 'module' => 'documents', 'level' => 'summary_voided', 'ajax' => true],
        ['path' => 'contingencies', 'module' => 'documents', 'level' => 'document_contingengy', 'ajax' => true],
        ['path' => 'quotations', 'module' => 'documents', 'level' => 'quotations'],
        ['path' => 'sale-notes', 'module' => 'documents', 'level' => 'sale_notes'],
        ['path' => 'sale-opportunities', 'module' => 'documents', 'level' => 'sale-opportunity', 'ajax' => true],
        ['path' => 'contracts', 'module' => 'documents', 'level' => 'contracts', 'ajax' => true],
        ['path' => 'production-orders', 'module' => 'documents', 'level' => 'contracts', 'ajax' => true],
        ['path' => 'order-notes', 'module' => 'documents', 'level' => 'order-note'],
        ['path' => 'technical-services', 'module' => 'documents', 'level' => 'technical-service', 'ajax' => true],
        ['path' => 'user-commissions', 'module' => 'documents', 'level' => 'incentives', 'ajax' => true],
        ['path' => 'incentives', 'module' => 'documents', 'level' => 'incentives'],

        // Punto de venta
        ['path' => 'pos', 'module' => 'pos', 'ajax' => true],
        ['path' => 'pos_full', 'module' => 'pos'],
        ['path' => 'cash/cash_document', 'module' => null],
        ['path' => 'cash', 'module' => 'pos'],

        // Tienda virtual
        ['path' => 'orders', 'module' => 'ecommerce'],
        ['path' => 'items_ecommerce', 'module' => 'ecommerce'],
        ['path' => 'tags', 'module' => 'ecommerce'],
        ['path' => 'promotions', 'module' => 'ecommerce'],
        ['path' => 'ecommerce/configuration', 'module' => 'ecommerce', 'ajax' => true],
        ['path' => 'ecommerce/configuration_culqui', 'module' => 'ecommerce', 'ajax' => true],
        ['path' => 'ecommerce/configuration_paypal', 'module' => 'ecommerce', 'ajax' => true],
        ['path' => 'ecommerce/configuration_social', 'module' => 'ecommerce', 'ajax' => true],
        ['path' => 'ecommerce/configuration_tags', 'module' => 'ecommerce', 'ajax' => true],
        ['path' => 'ecommerce/record', 'module' => 'ecommerce', 'ajax' => true],
        ['path' => 'ecommerce/uploads', 'module' => 'ecommerce', 'ajax' => true],
        ['path' => 'statusOrder', 'module' => 'ecommerce', 'ajax' => true],

        // Clientes
        ['path' => 'persons/customers', 'module' => 'persons'],
        ['path' => 'person-types', 'module' => 'persons'],

        // Productos y servicios (includes the SUNAT product code catalogue)
        ['path' => 'items', 'module' => 'items'],
        ['path' => 'item-sets', 'module' => 'items'],
        ['path' => 'item-lots', 'module' => 'items'],
        ['path' => 'services', 'module' => 'items', 'exact' => true],
        ['path' => 'services/ruc', 'module' => null],
        ['path' => 'services/dni', 'module' => null],
        ['path' => 'services/exchange_rate', 'module' => null],
        ['path' => 'services/search_exchange_rate', 'module' => null],
        ['path' => 'categories', 'module' => 'items'],
        ['path' => 'brands', 'module' => 'items'],

        // Compras (includes purchases/validate-sunat*)
        ['path' => 'purchases', 'module' => 'purchases', 'ajax' => true],
        ['path' => 'purchase-orders', 'module' => 'purchases', 'ajax' => true],
        ['path' => 'purchase-quotations', 'module' => 'purchases', 'ajax' => true],
        ['path' => 'purchase-payments', 'module' => 'purchases'],
        ['path' => 'fixed-asset', 'module' => 'purchases', 'ajax' => true],
        ['path' => 'expenses', 'module' => 'purchases'],
        ['path' => 'expense-payments', 'module' => 'purchases'],
        ['path' => 'expense-types', 'module' => 'purchases'],
        ['path' => 'expense-reasons', 'module' => 'purchases'],
        ['path' => 'expense-method-types', 'module' => 'purchases'],
        ['path' => 'persons/suppliers', 'module' => 'purchases'],

        // Inventario
        ['path' => 'warehouses', 'module' => 'inventory'],
        ['path' => 'inventory', 'module' => 'inventory'],
        ['path' => 'moves', 'module' => 'inventory'],
        ['path' => 'transfers', 'module' => 'inventory'],
        ['path' => 'devolutions', 'module' => 'inventory'],
        ['path' => 'reports/kardex', 'module' => 'inventory', 'ajax' => true],
        ['path' => 'reports/kardex_lots', 'module' => 'inventory', 'ajax' => true],
        ['path' => 'reports/kardex_series', 'module' => 'inventory', 'ajax' => true],
        ['path' => 'reports/inventory', 'module' => 'inventory', 'ajax' => true],
        ['path' => 'reports/valued-kardex', 'module' => 'inventory', 'ajax' => true],

        // Usuarios, locales y series
        ['path' => 'users', 'module' => 'establishments', 'ajax' => true],
        ['path' => 'establishments', 'module' => 'establishments', 'ajax' => true],

        // Comprobantes avanzados (dispatches send/status included)
        ['path' => 'retentions', 'module' => 'advanced', 'ajax' => true],
        ['path' => 'perceptions', 'module' => 'advanced', 'ajax' => true],
        ['path' => 'dispatches', 'module' => 'advanced'],
        ['path' => 'order-forms', 'module' => 'advanced', 'ajax' => true],
        ['path' => 'drivers', 'module' => 'advanced'],
        ['path' => 'dispatchers', 'module' => 'advanced'],

        // Shared helpers called across modules (document lookup from Ecommerce, mass voided check from Configuration)
        ['path' => 'documents/search/externalId', 'module' => null],
        ['path' => 'voided/status_masive', 'module' => null],

        // Reportes (includes reports/sire/*)
        ['path' => 'list-reports', 'module' => 'reports'],
        ['path' => 'reports/data-table', 'module' => null],
        ['path' => 'reports', 'module' => 'reports', 'ajax' => true],

        // Contabilidad
        ['path' => 'account', 'module' => 'accounting', 'ajax' => true],

        // Finanzas
        ['path' => 'finances/payment-file', 'module' => null],
        ['path' => 'finances', 'module' => 'finance', 'ajax' => true],
        ['path' => 'income-types', 'module' => 'finance'],
        ['path' => 'income-reasons', 'module' => 'finance'],

        // Configuracion (company credentials included)
        ['path' => 'list-settings', 'module' => 'configuration'],
        ['path' => 'list-banks', 'module' => 'configuration'],
        ['path' => 'list-bank-accounts', 'module' => 'configuration'],
        ['path' => 'list-currencies', 'module' => 'configuration'],
        ['path' => 'list-cards', 'module' => 'configuration'],
        ['path' => 'list-platforms', 'module' => 'configuration'],
        ['path' => 'list-attributes', 'module' => 'configuration'],
        ['path' => 'list-detractions', 'module' => 'configuration'],
        ['path' => 'list-units', 'module' => 'configuration'],
        ['path' => 'list-payment-methods', 'module' => 'configuration'],
        ['path' => 'list-incomes', 'module' => 'configuration'],
        ['path' => 'list-payments', 'module' => 'configuration'],
        ['path' => 'list-vouchers-type', 'module' => 'configuration'],
        ['path' => 'companies', 'module' => 'configuration', 'ajax' => true],
        ['path' => 'companies/record', 'module' => null],
        ['path' => 'companies/uploads', 'module' => null],
        ['path' => 'catalogs', 'module' => 'configuration'],
        ['path' => 'advanced', 'module' => 'configuration'],
        ['path' => 'tasks', 'module' => 'configuration'],
        ['path' => 'bussiness_turns', 'module' => 'configuration'],
        ['path' => 'offline-configurations', 'module' => 'configuration'],
        ['path' => 'series-configurations', 'module' => 'configuration'],
        ['path' => 'company_accounts', 'module' => 'configuration'],
        ['path' => 'inventories', 'module' => 'configuration'],
        ['path' => 'login-page', 'module' => 'configuration'],
        ['path' => 'configurations/record', 'module' => null],
        ['path' => 'configurations/change-mode', 'module' => null],
        ['path' => 'configurations/create', 'module' => 'configuration'],
        ['path' => 'configurations/pdf_templates', 'module' => 'configuration'],
        ['path' => 'configurations/pdf_guide_templates', 'module' => 'configuration'],
        ['path' => 'configurations/pdf_preprinted_templates', 'module' => 'configuration'],
        ['path' => 'options', 'module' => 'configuration', 'ajax' => true],
        ['path' => 'certificates', 'module' => 'configuration', 'ajax' => true],

        // Cuenta (Mis pagos)
        ['path' => 'cuenta', 'module' => 'cuenta'],

        // Hoteles / Tramite documentario
        ['path' => 'hotels', 'module' => 'hotels', 'ajax' => true],
        ['path' => 'documentary-procedure', 'module' => 'documentary-procedure', 'ajax' => true],
    ],

    /*
    | Landing pages in sidebar order. When a user opens something forbidden the
    | first entry (in this order) that the user can open is used as redirect.
    */
    'menu' => [
        'dashboard',
        'documents/create',
        'documents',
        'sale-notes',
        'documents/not-sent',
        'documents/regularize-shipping',
        'contingencies',
        'summaries',
        'sale-opportunities',
        'quotations',
        'contracts',
        'order-notes',
        'technical-services',
        'user-commissions',
        'pos',
        'orders',
        'persons/customers',
        'items',
        'purchases',
        'inventory',
        'users',
        'retentions',
        'list-reports',
        'account/format',
        'finances/movements',
        'list-settings',
        'cuenta/configuration',
        'hotels/reception',
        'documentary-procedure/offices',
    ],

    'denied_message' => 'No tiene permiso para este módulo.',
];
