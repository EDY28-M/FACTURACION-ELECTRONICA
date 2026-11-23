<template>
    <div class="card mb-0 pt-2 pt-md-0">
        <div class="card-header bg-info d-flex align-items-center justify-content-between flex-wrap">
            <h3 class="my-0">Tesorería — Cobranzas y pagos</h3>
            <div>
                <el-button size="small" :loading="loading" @click="loadRecords">
                    <i class="fa fa-refresh"></i> Actualizar
                </el-button>
                <a class="btn btn-custom btn-sm ml-2" href="/treasury/summary">
                    Resumen de saldos
                </a>
            </div>
        </div>

        <div class="card-body">
            <!-- Filters -->
            <form class="row mb-3" @submit.prevent="loadRecords">
                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="rp-filter-establishment" class="control-label">Sede</label>
                    <el-select
                        id="rp-filter-establishment"
                        v-model="filters.establishment_id"
                        :clearable="tables.is_admin"
                        :disabled="!tables.is_admin && tables.establishments.length <= 1"
                        placeholder="Todas"
                        class="w-100"
                        @change="loadRecords"
                    >
                        <el-option
                            v-for="item in tables.establishments"
                            :key="item.id"
                            :label="item.description"
                            :value="item.id"
                        />
                    </el-select>
                </div>

                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="rp-filter-currency" class="control-label">Moneda</label>
                    <el-select
                        id="rp-filter-currency"
                        v-model="filters.currency_type_id"
                        clearable
                        placeholder="Todas"
                        class="w-100"
                        @change="loadRecords"
                    >
                        <el-option
                            v-for="item in tables.currency_types"
                            :key="item.id"
                            :label="item.description"
                            :value="item.id"
                        />
                    </el-select>
                </div>

                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="rp-filter-status" class="control-label">Estado</label>
                    <el-select
                        id="rp-filter-status"
                        v-model="filters.status"
                        placeholder="Todos"
                        class="w-100"
                        @change="loadRecords"
                    >
                        <el-option label="Todos" value="all" />
                        <el-option label="Vencidos" value="overdue" />
                        <el-option label="Por vencer (7 días)" value="due_soon" />
                        <el-option label="Vigentes" value="pending" />
                    </el-select>
                </div>

                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="rp-filter-date-start" class="control-label">Emisión desde</label>
                    <el-date-picker
                        id="rp-filter-date-start"
                        v-model="filters.date_start"
                        type="date"
                        value-format="yyyy-MM-dd"
                        placeholder="YYYY-MM-DD"
                        class="w-100"
                        @change="loadRecords"
                    />
                </div>

                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="rp-filter-date-end" class="control-label">Emisión hasta</label>
                    <el-date-picker
                        id="rp-filter-date-end"
                        v-model="filters.date_end"
                        type="date"
                        value-format="yyyy-MM-dd"
                        placeholder="YYYY-MM-DD"
                        class="w-100"
                        @change="loadRecords"
                    />
                </div>

                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="rp-filter-search" class="control-label">Buscar</label>
                    <el-input
                        id="rp-filter-search"
                        v-model="filters.search"
                        clearable
                        placeholder="Serie, cliente o RUC"
                        @keyup.enter.native="loadRecords"
                        @clear="loadRecords"
                    />
                </div>
            </form>

            <!-- Error state -->
            <div v-if="errorMessage" class="alert alert-danger d-flex justify-content-between align-items-center" role="alert">
                <span>{{ errorMessage }}</span>
                <el-button size="mini" type="danger" plain @click="loadRecords">Reintentar</el-button>
            </div>

            <!-- Loading state -->
            <div v-if="loading" class="text-center py-5 text-muted" role="status" aria-live="polite">
                <i class="el-icon-loading mr-2"></i> Cargando cronograma de cobranzas y pagos...
            </div>

            <template v-else-if="!errorMessage">
                <!-- Summary KPI strip -->
                <div class="row mb-3">
                    <div class="col-lg-3 col-md-6 mb-2">
                        <div class="card border h-100 mb-0">
                            <div class="card-body py-2 px-3">
                                <div class="text-muted small">Total por cobrar ({{ receivables.totals.count }})</div>
                                <div class="h5 mt-1 mb-1 text-primary">
                                    {{ formatCurrency(receivables.totals.consolidated_pen, 'PEN') }}
                                </div>
                                <div class="small text-muted">
                                    <span
                                        v-for="(cur, code) in receivables.totals.by_currency"
                                        :key="'ar-' + code"
                                        class="mr-2"
                                    >
                                        {{ formatCurrency(cur.balance, code) }}
                                    </span>
                                    <span v-if="Object.keys(receivables.totals.by_currency || {}).length === 0">
                                        Sin saldo pendiente
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-3 col-md-6 mb-2">
                        <div class="card border h-100 mb-0">
                            <div class="card-body py-2 px-3">
                                <div class="text-muted small">Total por pagar ({{ payables.totals.count }})</div>
                                <div class="h5 mt-1 mb-1 text-danger">
                                    {{ formatCurrency(payables.totals.consolidated_pen, 'PEN') }}
                                </div>
                                <div class="small text-muted">
                                    <span
                                        v-for="(cur, code) in payables.totals.by_currency"
                                        :key="'ap-' + code"
                                        class="mr-2"
                                    >
                                        {{ formatCurrency(cur.balance, code) }}
                                    </span>
                                    <span v-if="Object.keys(payables.totals.by_currency || {}).length === 0">
                                        Sin saldo pendiente
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-2 col-sm-4 mb-2">
                        <div class="card border h-100 mb-0">
                            <div class="card-body py-2 px-3">
                                <div class="text-muted small">Cuotas vencidas</div>
                                <div
                                    class="h4 mt-1 mb-0"
                                    :class="alerts.summary.overdue_count > 0 ? 'text-danger' : 'text-muted'"
                                >
                                    {{ alerts.summary.overdue_count }}
                                </div>
                                <div class="small text-muted">Corte: {{ asOfDate }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-2 col-sm-4 mb-2">
                        <div class="card border h-100 mb-0">
                            <div class="card-body py-2 px-3">
                                <div class="text-muted small">Por vencer (7 días)</div>
                                <div
                                    class="h4 mt-1 mb-0"
                                    :class="alerts.summary.due_soon_count > 0 ? 'text-warning' : 'text-muted'"
                                >
                                    {{ alerts.summary.due_soon_count }}
                                </div>
                                <div class="small text-muted">Próximos 7 días</div>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-2 col-sm-4 mb-2">
                        <div class="card border h-100 mb-0">
                            <div class="card-body py-2 px-3">
                                <div class="text-muted small">Alertas bancarización</div>
                                <div
                                    class="h4 mt-1 mb-0"
                                    :class="alerts.summary.unbancarized_payments_count > 0 ? 'text-danger' : (alerts.summary.unbancarized_count > 0 ? 'text-info' : 'text-muted')"
                                >
                                    {{ alerts.summary.unbancarized_count }}
                                </div>
                                <div class="small text-muted">
                                    {{ alerts.summary.unbancarized_payments_count || 0 }} pago(s) sin evidencia
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Missing exchange rate notice -->
                <div v-if="hasMissingExchangeRate" class="alert alert-info mb-3" role="alert">
                    <strong>Tipo de cambio no registrado:</strong> Algunos saldos en moneda extranjera no tienen tipo de cambio de venta registrado y no se incluyen en el total consolidado en PEN.
                </div>

                <!-- Main Tabs -->
                <el-tabs v-model="activeTab" type="card">
                    <!-- Tab 1: Receivables -->
                    <el-tab-pane name="receivables">
                        <span slot="label">
                            Por cobrar ({{ receivables.rows.length }})
                        </span>

                        <div v-if="receivables.rows.length === 0" class="alert alert-light border text-muted mb-0">
                            No hay cuentas por cobrar pendientes para los filtros seleccionados.
                        </div>

                        <div v-else class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Comprobante</th>
                                        <th>Cliente</th>
                                        <th>Emisión / Venc.</th>
                                        <th class="text-right">Total / Neto crédito</th>
                                        <th class="text-right">Pagado</th>
                                        <th class="text-right">Saldo pendiente</th>
                                        <th class="text-center">Estado</th>
                                        <th class="text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template v-for="row in receivables.rows">
                                        <tr :key="'ar-' + row.type + '-' + row.id">
                                            <td>
                                                <div class="tn-doc-meta">
                                                    <strong>{{ row.number_full }}</strong>
                                                    <el-popover
                                                        v-if="row.has_installments"
                                                        placement="right"
                                                        trigger="hover"
                                                        popper-class="tn-installments-popover"
                                                    >
                                                        <div class="small font-weight-bold mb-2">Cronograma de cuotas</div>
                                                        <table class="table table-xs table-bordered mb-0">
                                                            <thead>
                                                                <tr>
                                                                    <th>Cuota</th>
                                                                    <th>Vencimiento</th>
                                                                    <th class="text-right">Importe</th>
                                                                    <th class="text-right">Pagado</th>
                                                                    <th class="text-right">Pendiente</th>
                                                                    <th class="text-center">Estado</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <tr v-for="(inst, idx) in row.installments" :key="idx">
                                                                    <td>{{ installmentLabel(inst, idx) }}</td>
                                                                    <td>{{ inst.date }}</td>
                                                                    <td class="text-right">{{ formatCurrency(inst.amount, row.currency_type_id) }}</td>
                                                                    <td class="text-right">{{ formatCurrency(inst.paid_amount, row.currency_type_id) }}</td>
                                                                    <td class="text-right font-weight-bold">{{ formatCurrency(inst.pending_amount, row.currency_type_id) }}</td>
                                                                    <td class="text-center">
                                                                        <span class="badge" :class="statusBadgeClass(inst.status)">
                                                                            {{ statusLabel(inst.status, inst.days_overdue, inst.days_until_due) }}
                                                                        </span>
                                                                    </td>
                                                                </tr>
                                                            </tbody>
                                                            
                                                        </table>
                                                        <span slot="reference" class="tn-installments-pill" tabindex="0">{{ installmentsLabel(row.installments_count) }}</span>
                                                    </el-popover>
                                                </div>
                                                <div class="small text-muted">{{ row.type === 'sale_note' ? 'Nota de venta' : 'Comprobante' }}</div>
                                            </td>
                                            <td>
                                                <div>{{ row.customer_name }}</div>
                                                <div v-if="row.customer_number" class="small text-muted">{{ row.customer_number }}</div>
                                            </td>
                                            <td>
                                                <div class="small">Emisión: {{ row.date_of_issue }}</div>
                                                <div class="small font-weight-bold">Venc.: {{ row.date_of_due }}</div>
                                            </td>
                                            <td class="text-right">
                                                <div>{{ formatCurrency(row.total, row.currency_type_id) }}</div>
                                                <div v-if="row.detraction_amount > 0" class="small text-muted">
                                                    Detracción: {{ formatCurrency(row.detraction_amount, row.currency_type_id) }}
                                                </div>
                                                <div v-if="row.retention_amount > 0" class="small text-muted">
                                                    Retención: {{ formatCurrency(row.retention_amount, row.currency_type_id) }}
                                                </div>
                                                <div
                                                    v-if="row.detraction_amount > 0 || row.retention_amount > 0"
                                                    class="small text-info"
                                                >
                                                    Neto crédito: {{ formatCurrency(row.net_credit_amount, row.currency_type_id) }}
                                                </div>
                                                <div v-if="row.retention_registered_amount > 0" class="small text-muted">
                                                    CRE recibido: {{ formatCurrency(row.retention_registered_amount, row.currency_type_id) }}
                                                </div>
                                            </td>
                                            <td class="text-right">
                                                {{ formatCurrency(row.total_paid, row.currency_type_id) }}
                                            </td>
                                            <td class="text-right font-weight-bold">
                                                {{ formatCurrency(row.balance, row.currency_type_id) }}
                                                <div
                                                    v-if="row.detraction_amount > 0 || row.retention_registered_amount > 0 || row.retention_amount > 0"
                                                    class="small text-info font-weight-normal"
                                                    title="Saldo menos detracción y retención: lo que su cliente aún le debe en sus cuentas normales"
                                                >
                                                    Neto por cobrar: {{ formatCurrency(row.net_receivable_amount, row.currency_type_id) }}
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge" :class="statusBadgeClass(row.status)">
                                                    {{ statusLabel(row.status, row.max_days_overdue, row.min_days_until_due) }}
                                                </span>
                                                <div v-if="row.missing_payment_evidence" class="mt-1">
                                                    <span
                                                        class="badge badge-danger"
                                                        title="Tiene pagos registrados sin evidencia válida de bancarización (Ley 28194)"
                                                    >
                                                        Sin evidencia
                                                    </span>
                                                </div>
                                                <div v-else-if="row.pending_requires_bancarization" class="mt-1">
                                                    <span
                                                        class="badge badge-info"
                                                        title="Supera el umbral de bancarización vigente; adjunte evidencia al registrar el cobro"
                                                    >
                                                        Requiere bancarización
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="text-right text-nowrap">
                                                <div class="tn-row-actions">
                                                    <el-button
                                                        v-if="row.type === 'document' && row.document_type_id === '01'"
                                                        size="mini"
                                                        plain
                                                        title="Registrar el comprobante de retención que le entregó su cliente"
                                                        @click="openWithholding('received_retention', row.id)"
                                                    >
                                                        CRE recibido
                                                    </el-button>
                                                    <el-button
                                                        v-if="row.type === 'document' && withholding.flags.is_perception_agent"
                                                        size="mini"
                                                        plain
                                                        @click="openWithholding('perception_offers', row.id)"
                                                    >
                                                        Percepción
                                                    </el-button>
                                                    <el-button
                                                        size="mini"
                                                        type="primary"
                                                        @click="openPaymentModal(row.payment_endpoint_type, row.id)"
                                                    >
                                                        Cobrar
                                                    </el-button>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </el-tab-pane>

                    <!-- Tab 2: Payables -->
                    <el-tab-pane name="payables">
                        <span slot="label">
                            Por pagar ({{ payables.rows.length }})
                        </span>

                        <div v-if="payables.rows.length === 0" class="alert alert-light border text-muted mb-0">
                            No hay cuentas por pagar pendientes para los filtros seleccionados.
                        </div>

                        <div v-else class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Compra / Gasto</th>
                                        <th>Proveedor</th>
                                        <th>Emisión / Venc.</th>
                                        <th class="text-right">Total</th>
                                        <th class="text-right">Pagado</th>
                                        <th class="text-right">Saldo pendiente</th>
                                        <th class="text-center">Estado</th>
                                        <th class="text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template v-for="row in payables.rows">
                                        <tr :key="'ap-' + row.type + '-' + row.id">
                                            <td>
                                                <div class="tn-doc-meta">
                                                    <strong>{{ row.number_full }}</strong>
                                                    <el-popover
                                                        v-if="row.has_installments"
                                                        placement="right"
                                                        trigger="hover"
                                                        popper-class="tn-installments-popover"
                                                    >
                                                        <div class="small font-weight-bold mb-2">Cronograma de cuotas</div>
                                                        <table class="table table-xs table-bordered mb-0">
                                                            <thead>
                                                                <tr>
                                                                    <th>Cuota</th>
                                                                    <th>Vencimiento</th>
                                                                    <th class="text-right">Importe</th>
                                                                    <th class="text-right">Pagado</th>
                                                                    <th class="text-right">Pendiente</th>
                                                                    <th class="text-center">Estado</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <tr v-for="(inst, idx) in row.installments" :key="idx">
                                                                    <td>{{ installmentLabel(inst, idx) }}</td>
                                                                    <td>{{ inst.date }}</td>
                                                                    <td class="text-right">{{ formatCurrency(inst.amount, row.currency_type_id) }}</td>
                                                                    <td class="text-right">{{ formatCurrency(inst.paid_amount, row.currency_type_id) }}</td>
                                                                    <td class="text-right font-weight-bold">{{ formatCurrency(inst.pending_amount, row.currency_type_id) }}</td>
                                                                    <td class="text-center">
                                                                        <span class="badge" :class="statusBadgeClass(inst.status)">
                                                                            {{ statusLabel(inst.status, inst.days_overdue, inst.days_until_due) }}
                                                                        </span>
                                                                    </td>
                                                                </tr>
                                                            </tbody>
                                                            
                                                        </table>
                                                        <span slot="reference" class="tn-installments-pill" tabindex="0">{{ installmentsLabel(row.installments_count) }}</span>
                                                    </el-popover>
                                                </div>
                                                <div class="small text-muted">{{ row.type === 'expense' ? 'Gasto' : 'Compra' }}</div>
                                            </td>
                                            <td>
                                                <div>{{ row.supplier_name }}</div>
                                                <div v-if="row.supplier_number" class="small text-muted">{{ row.supplier_number }}</div>
                                            </td>
                                            <td>
                                                <div class="small">Emisión: {{ row.date_of_issue }}</div>
                                                <div class="small font-weight-bold">Venc.: {{ row.date_of_due }}</div>
                                            </td>
                                            <td class="text-right">
                                                {{ formatCurrency(row.total, row.currency_type_id) }}
                                            </td>
                                            <td class="text-right">
                                                {{ formatCurrency(row.total_paid, row.currency_type_id) }}
                                            </td>
                                            <td class="text-right font-weight-bold">
                                                {{ formatCurrency(row.balance, row.currency_type_id) }}
                                                <div
                                                    v-if="row.detraction_amount > 0"
                                                    class="small text-info font-weight-normal"
                                                    title="Saldo menos la detracción que usted deposita en la cuenta del proveedor"
                                                >
                                                    Neto por pagar: {{ formatCurrency(row.net_payable_amount, row.currency_type_id) }}
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge" :class="statusBadgeClass(row.status)">
                                                    {{ statusLabel(row.status, row.max_days_overdue, row.min_days_until_due) }}
                                                </span>
                                                <div v-if="row.missing_payment_evidence" class="mt-1">
                                                    <span
                                                        class="badge badge-danger"
                                                        title="Tiene pagos registrados sin evidencia válida de bancarización (Ley 28194)"
                                                    >
                                                        Sin evidencia
                                                    </span>
                                                </div>
                                                <div v-else-if="row.pending_requires_bancarization" class="mt-1">
                                                    <span
                                                        class="badge badge-info"
                                                        title="Supera el umbral de bancarización vigente; adjunte evidencia al registrar el pago"
                                                    >
                                                        Requiere bancarización
                                                    </span>
                                                </div>
                                            </td>
                                            <td class="text-right text-nowrap">
                                                <div class="tn-row-actions">
                                                    <el-button
                                                        v-if="row.type === 'purchase'"
                                                        size="mini"
                                                        plain
                                                        @click="openPurchaseFeesDialog(row)"
                                                    >
                                                        Cuotas
                                                    </el-button>
                                                    <el-button
                                                        v-if="row.type === 'purchase' && withholding.flags.is_retention_agent"
                                                        size="mini"
                                                        plain
                                                        @click="openWithholding('retention_offers', row.id)"
                                                    >
                                                        Retención
                                                    </el-button>
                                                    <el-button
                                                        size="mini"
                                                        type="primary"
                                                        @click="openPaymentModal(row.payment_endpoint_type, row.id)"
                                                    >
                                                        Pagar
                                                    </el-button>
                                                </div>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </el-tab-pane>

                    <!-- Tab 3: Aging Analysis -->
                    <el-tab-pane name="aging" label="Antigüedad de saldos">
                        <div class="mb-3">
                            <el-radio-group v-model="agingSide" size="small">
                                <el-radio-button label="receivables">Por cobrar (Clientes)</el-radio-button>
                                <el-radio-button label="payables">Por pagar (Proveedores)</el-radio-button>
                            </el-radio-group>
                        </div>

                        <div v-if="currentAging.rows.length === 0" class="alert alert-light border text-muted mb-0">
                            No hay saldos pendientes para el análisis de antigüedad.
                        </div>

                        <div v-else class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>{{ agingSide === 'receivables' ? 'Cliente' : 'Proveedor' }}</th>
                                        <th class="text-center">Moneda</th>
                                        <th class="text-right">Por vencer</th>
                                        <th class="text-right">1-30 días</th>
                                        <th class="text-right">31-60 días</th>
                                        <th class="text-right">61-90 días</th>
                                        <th class="text-right">&gt; 90 días</th>
                                        <th class="text-right">Total</th>
                                        <th class="text-right">Total PEN</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in currentAging.rows"
                                        :key="row.person_id + '-' + row.currency_type_id"
                                    >
                                        <td>
                                            <strong>{{ row.person_name }}</strong>
                                            <div v-if="row.person_number" class="small text-muted">
                                                {{ row.person_number }} ({{ row.documents_count }} doc.)
                                            </div>
                                        </td>
                                        <td class="text-center">{{ row.currency_type_id }}</td>
                                        <td class="text-right">{{ formatCurrency(row.current, row.currency_type_id) }}</td>
                                        <td class="text-right">{{ formatCurrency(row.days_1_30, row.currency_type_id) }}</td>
                                        <td class="text-right">{{ formatCurrency(row.days_31_60, row.currency_type_id) }}</td>
                                        <td class="text-right">{{ formatCurrency(row.days_61_90, row.currency_type_id) }}</td>
                                        <td class="text-right" :class="row.days_over_90 > 0 ? 'text-danger font-weight-bold' : ''">
                                            {{ formatCurrency(row.days_over_90, row.currency_type_id) }}
                                        </td>
                                        <td class="text-right font-weight-bold">
                                            {{ formatCurrency(row.total, row.currency_type_id) }}
                                        </td>
                                        <td class="text-right">
                                            <span v-if="row.total_pen !== null">
                                                {{ formatCurrency(row.total_pen, 'PEN') }}
                                            </span>
                                            <span v-else class="text-muted">Sin TC</span>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr
                                        v-for="tot in currentAging.totals_by_currency"
                                        :key="'tot-' + tot.currency_type_id"
                                        class="table-light font-weight-bold"
                                    >
                                        <td>Total {{ tot.currency_type_id }}</td>
                                        <td class="text-center">{{ tot.currency_type_id }}</td>
                                        <td class="text-right">{{ formatCurrency(tot.current, tot.currency_type_id) }}</td>
                                        <td class="text-right">{{ formatCurrency(tot.days_1_30, tot.currency_type_id) }}</td>
                                        <td class="text-right">{{ formatCurrency(tot.days_31_60, tot.currency_type_id) }}</td>
                                        <td class="text-right">{{ formatCurrency(tot.days_61_90, tot.currency_type_id) }}</td>
                                        <td class="text-right">{{ formatCurrency(tot.days_over_90, tot.currency_type_id) }}</td>
                                        <td class="text-right">{{ formatCurrency(tot.total, tot.currency_type_id) }}</td>
                                        <td class="text-right">—</td>
                                    </tr>
                                    <tr class="table-secondary font-weight-bold">
                                        <td colspan="2">Total consolidado (PEN)</td>
                                        <td class="text-right">{{ formatCurrency(currentAging.totals_pen.current, 'PEN') }}</td>
                                        <td class="text-right">{{ formatCurrency(currentAging.totals_pen.days_1_30, 'PEN') }}</td>
                                        <td class="text-right">{{ formatCurrency(currentAging.totals_pen.days_31_60, 'PEN') }}</td>
                                        <td class="text-right">{{ formatCurrency(currentAging.totals_pen.days_61_90, 'PEN') }}</td>
                                        <td class="text-right">{{ formatCurrency(currentAging.totals_pen.days_over_90, 'PEN') }}</td>
                                        <td class="text-right">—</td>
                                        <td class="text-right">{{ formatCurrency(currentAging.totals_pen.total, 'PEN') }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </el-tab-pane>

                    <!-- Tab 4: Alerts -->
                    <el-tab-pane name="alerts">
                        <span slot="label">
                            Alertas ({{ totalAlertsCount }})
                        </span>

                        <!-- Overdue installments -->
                        <h5 class="mb-2 text-danger">
                            Cuotas vencidas ({{ alerts.overdue_installments.count }})
                        </h5>
                        <div v-if="alerts.overdue_installments.items.length === 0" class="alert alert-light border text-muted mb-3">
                            No hay cuotas vencidas.
                        </div>
                        <div v-else class="table-responsive mb-4">
                            <table class="table table-sm table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Tipo</th>
                                        <th>Comprobante</th>
                                        <th>Contraparte</th>
                                        <th>Vencimiento</th>
                                        <th class="text-center">Días vencido</th>
                                        <th class="text-right">Pendiente</th>
                                        <th class="text-right">Acción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(item, idx) in alerts.overdue_installments.items" :key="'ov-' + idx">
                                        <td>
                                            <span class="badge" :class="item.side === 'receivable' ? 'badge-primary' : 'badge-secondary'">
                                                {{ item.side === 'receivable' ? 'Por cobrar' : 'Por pagar' }}
                                            </span>
                                        </td>
                                        <td>
                                            <strong>{{ item.number_full }}</strong>
                                            <span class="small text-muted ml-1">(Cuota {{ item.installment_index }})</span>
                                        </td>
                                        <td>{{ item.counterparty_name }}</td>
                                        <td>{{ item.due_date }}</td>
                                        <td class="text-center">
                                            <span class="badge badge-danger">{{ item.days_overdue }} día(s)</span>
                                        </td>
                                        <td class="text-right font-weight-bold">
                                            {{ formatCurrency(item.pending_amount, item.currency_type_id) }}
                                        </td>
                                        <td class="text-right">
                                            <el-button
                                                size="mini"
                                                type="primary"
                                                @click="openPaymentModal(item.payment_endpoint_type, item.record_id)"
                                            >
                                                {{ item.side === 'receivable' ? 'Cobrar' : 'Pagar' }}
                                            </el-button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Due soon installments -->
                        <h5 class="mb-2 text-warning">
                            Cuotas por vencer en los próximos 7 días ({{ alerts.due_soon_installments.count }})
                        </h5>
                        <div v-if="alerts.due_soon_installments.items.length === 0" class="alert alert-light border text-muted mb-3">
                            No hay cuotas por vencer en los próximos 7 días.
                        </div>
                        <div v-else class="table-responsive mb-4">
                            <table class="table table-sm table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Tipo</th>
                                        <th>Comprobante</th>
                                        <th>Contraparte</th>
                                        <th>Vencimiento</th>
                                        <th class="text-center">Vence en</th>
                                        <th class="text-right">Pendiente</th>
                                        <th class="text-right">Acción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(item, idx) in alerts.due_soon_installments.items" :key="'ds-' + idx">
                                        <td>
                                            <span class="badge" :class="item.side === 'receivable' ? 'badge-primary' : 'badge-secondary'">
                                                {{ item.side === 'receivable' ? 'Por cobrar' : 'Por pagar' }}
                                            </span>
                                        </td>
                                        <td>
                                            <strong>{{ item.number_full }}</strong>
                                            <span class="small text-muted ml-1">(Cuota {{ item.installment_index }})</span>
                                        </td>
                                        <td>{{ item.counterparty_name }}</td>
                                        <td>{{ item.due_date }}</td>
                                        <td class="text-center">
                                            <span class="badge badge-warning">
                                                {{ item.days_until_due === 0 ? 'Vence hoy' : `${item.days_until_due} día(s)` }}
                                            </span>
                                        </td>
                                        <td class="text-right font-weight-bold">
                                            {{ formatCurrency(item.pending_amount, item.currency_type_id) }}
                                        </td>
                                        <td class="text-right">
                                            <el-button
                                                size="mini"
                                                type="primary"
                                                @click="openPaymentModal(item.payment_endpoint_type, item.record_id)"
                                            >
                                                {{ item.side === 'receivable' ? 'Cobrar' : 'Pagar' }}
                                            </el-button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Bancarization alerts -->
                        <h5 class="mb-2 text-info">
                            Obligaciones sobre el umbral de bancarización sin evidencia ({{ alerts.unbancarized_obligations.count }})
                        </h5>
                        <div v-if="alerts.unbancarized_obligations.items.length === 0" class="alert alert-light border text-muted mb-0">
                            No hay obligaciones pendientes de evidencia de bancarización.
                        </div>
                        <div v-else class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Tipo</th>
                                        <th>Comprobante</th>
                                        <th>Contraparte</th>
                                        <th class="text-right">Total</th>
                                        <th class="text-right">Umbral</th>
                                        <th>Detalle</th>
                                        <th class="text-right">Acción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(item, idx) in alerts.unbancarized_obligations.items" :key="'bn-' + idx">
                                        <td>
                                            <span class="badge" :class="item.side === 'receivable' ? 'badge-primary' : 'badge-secondary'">
                                                {{ item.side === 'receivable' ? 'Por cobrar' : 'Por pagar' }}
                                            </span>
                                        </td>
                                        <td><strong>{{ item.number_full }}</strong></td>
                                        <td>{{ item.counterparty_name }}</td>
                                        <td class="text-right">{{ formatCurrency(item.total, item.currency_type_id) }}</td>
                                        <td class="text-right text-muted">
                                            {{ formatCurrency(item.bancarization_threshold, item.currency_type_id) }}
                                        </td>
                                        <td class="small">
                                            <span v-if="item.reason === 'payments_missing_evidence'" class="text-danger">
                                                {{ item.payments_without_evidence_count }} pago(s) sin evidencia válida
                                            </span>
                                            <span v-else class="text-warning">
                                                Pendiente de pago — requerirá evidencia al cobrar/pagar
                                            </span>
                                        </td>
                                        <td class="text-right">
                                            <el-button
                                                size="mini"
                                                type="warning"
                                                plain
                                                @click="openPaymentModal(item.payment_endpoint_type, item.record_id)"
                                            >
                                                Pagos y evidencia
                                            </el-button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </el-tab-pane>
                </el-tabs>
            </template>
        </div>

        <!-- Existing Payment Dialogs (Decision D4: reused, lazy-mounted) -->
        <document-payments
            v-if="mountedDocumentPayments"
            :showDialog.sync="showDocumentPayments"
            :documentId="activePaymentRecordId"
        />

        <sale-note-payments
            v-if="mountedSaleNotePayments"
            :showDialog.sync="showSaleNotePayments"
            :documentId="activePaymentRecordId"
        />

        <purchase-payments
            v-if="mountedPurchasePayments"
            :showDialog.sync="showPurchasePayments"
            :purchaseId="activePaymentRecordId"
        />

        <expense-payments
            v-if="mountedExpensePayments"
            :showDialog.sync="showExpensePayments"
            :expenseId="activePaymentRecordId"
        />

        <!-- Retention (CRE) / perception (CPE) dialog: computes, offers the existing emission flow and links -->
        <withholding-dialog
            :show-dialog.sync="showWithholdingDialog"
            :mode="withholdingMode"
            :record-id="withholdingRecordId"
            :perception-rates="withholding.perception_rates"
            @saved="loadRecords(true)"
        />

        <!-- Manual Purchase Installments Dialog -->
        <purchase-fees-dialog
            :show-dialog.sync="showPurchaseFeesDialog"
            :purchase="selectedPurchase"
            @saved="loadRecords"
        />
    </div>
</template>

<script>
import DocumentPayments from '@views/documents/partials/payments.vue'
import SaleNotePayments from '@views/sale_notes/partials/payments.vue'
import PurchasePayments from '@viewsModulePurchase/purchase_payments/payments.vue'
import ExpensePayments from '@viewsModuleExpense/expense_payments/payments.vue'
import PurchaseFeesDialog from './PurchaseFeesDialog.vue'
import WithholdingDialog from '../partials/WithholdingDialog.vue'

export default {
    components: {
        DocumentPayments,
        SaleNotePayments,
        PurchasePayments,
        ExpensePayments,
        PurchaseFeesDialog,
        WithholdingDialog,
    },
    data() {
        return {
            loading: false,
            errorMessage: null,
            requestSeq: 0,
            activeTab: 'receivables',
            agingSide: 'receivables',
            asOfDate: '',
            showWithholdingDialog: false,
            withholdingMode: 'received_retention',
            withholdingRecordId: null,
            withholding: {
                flags: { is_retention_agent: false, is_perception_agent: false },
                perception_rates: {},
            },
            tables: {
                establishments: [],
                currency_types: [],
                is_admin: true,
            },
            filters: {
                establishment_id: null,
                currency_type_id: null,
                status: 'all',
                date_start: null,
                date_end: null,
                search: '',
            },
            receivables: {
                rows: [],
                totals: { count: 0, by_currency: {}, consolidated_pen: 0 },
            },
            payables: {
                rows: [],
                totals: { count: 0, by_currency: {}, consolidated_pen: 0 },
            },
            agingReceivables: {
                rows: [],
                totals_by_currency: [],
                totals_pen: { current: 0, days_1_30: 0, days_31_60: 0, days_61_90: 0, days_over_90: 0, total: 0 },
            },
            agingPayables: {
                rows: [],
                totals_by_currency: [],
                totals_pen: { current: 0, days_1_30: 0, days_31_60: 0, days_61_90: 0, days_over_90: 0, total: 0 },
            },
            alerts: {
                overdue_installments: { count: 0, items: [] },
                due_soon_installments: { count: 0, items: [] },
                unbancarized_obligations: { count: 0, items: [] },
                summary: { overdue_count: 0, due_soon_count: 0, unbancarized_count: 0 },
            },
            activePaymentRecordId: null,
            mountedDocumentPayments: false,
            showDocumentPayments: false,
            mountedSaleNotePayments: false,
            showSaleNotePayments: false,
            mountedPurchasePayments: false,
            showPurchasePayments: false,
            mountedExpensePayments: false,
            showExpensePayments: false,
            showPurchaseFeesDialog: false,
            selectedPurchase: null,
        }
    },
    computed: {
        currentAging() {
            return this.agingSide === 'payables' ? this.agingPayables : this.agingReceivables
        },
        totalAlertsCount() {
            const s = this.alerts.summary || {}
            return Number(s.overdue_count || 0) + Number(s.due_soon_count || 0) + Number(s.unbancarized_count || 0)
        },
        hasMissingExchangeRate() {
            return Boolean(
                (this.receivables.totals && this.receivables.totals.missing_exchange_rate) ||
                (this.payables.totals && this.payables.totals.missing_exchange_rate) ||
                (this.currentAging && this.currentAging.missing_exchange_rate)
            )
        },
    },
    watch: {
        showDocumentPayments(val, oldVal) {
            if (oldVal && !val) {
                this.loadRecords(true)
            }
        },
        showSaleNotePayments(val, oldVal) {
            if (oldVal && !val) {
                this.loadRecords(true)
            }
        },
        showPurchasePayments(val, oldVal) {
            if (oldVal && !val) {
                this.loadRecords(true)
            }
        },
        showExpensePayments(val, oldVal) {
            if (oldVal && !val) {
                this.loadRecords(true)
            }
        },
    },
    created() {
        this.onExternalReload = () => {
            this.loadRecords(true)
        }
        this.$eventHub.$on('reloadDataUnpaid', this.onExternalReload)
        this.$eventHub.$on('reloadDataToPay', this.onExternalReload)
        this.$eventHub.$on('reloadData', this.onExternalReload)

        this.loadTables()
        this.loadWithholdingTables()
        this.loadRecords()
    },
    beforeDestroy() {
        this.$eventHub.$off('reloadDataUnpaid', this.onExternalReload)
        this.$eventHub.$off('reloadDataToPay', this.onExternalReload)
        this.$eventHub.$off('reloadData', this.onExternalReload)
    },
    methods: {
        installmentsLabel(count) {
            const n = Number(count) || 0;
            return n === 1 ? '1 cuota' : `${n} cuotas`;
        },
        loadTables() {
            this.$http
                .get('/treasury/receivables-payables/tables')
                .then(response => {
                    const data = response.data || {}
                    this.tables.establishments = data.establishments || []
                    this.tables.currency_types = data.currency_types || []
                    this.tables.is_admin = data.is_admin !== false
                    if (!this.tables.is_admin && data.default_establishment_id) {
                        this.filters.establishment_id = data.default_establishment_id
                    }
                })
                .catch(() => {})
        },
        loadWithholdingTables() {
            this.$http
                .get('/treasury/withholdings/tables')
                .then(response => {
                    const data = response.data || {}
                    this.withholding.flags = data.flags || this.withholding.flags
                    this.withholding.perception_rates = (data.perception_rates && !Array.isArray(data.perception_rates)) ? data.perception_rates : {}
                })
                .catch(() => {})
        },
        openWithholding(mode, recordId) {
            this.withholdingMode = mode
            this.withholdingRecordId = recordId
            this.$nextTick(() => {
                this.showWithholdingDialog = true
            })
        },
        loadRecords(silent = false) {
            const isSilent = silent === true
            const seq = ++this.requestSeq
            if (!isSilent) {
                this.loading = true
            }
            this.errorMessage = null

            this.$http
                .get('/treasury/receivables-payables/records', { params: this.filters })
                .then(response => {
                    if (seq !== this.requestSeq) {
                        return
                    }
                    const data = response.data.data || {}
                    this.asOfDate = data.as_of_date || ''
                    this.receivables = data.receivables || { rows: [], totals: { count: 0, by_currency: {}, consolidated_pen: 0 } }
                    this.payables = data.payables || { rows: [], totals: { count: 0, by_currency: {}, consolidated_pen: 0 } }
                    this.agingReceivables = data.aging_receivables || this.agingReceivables
                    this.agingPayables = data.aging_payables || this.agingPayables
                    this.alerts = data.alerts || this.alerts
                })
                .catch(() => {
                    if (seq !== this.requestSeq) {
                        return
                    }
                    this.errorMessage = 'No se pudo cargar el cronograma de cobranzas y pagos. Intente nuevamente.'
                })
                .then(() => {
                    if (seq === this.requestSeq) {
                        this.loading = false
                    }
                })
        },
        installmentLabel(inst, idx) {
            if (inst && inst.source === 'detraction_retention') {
                return 'Detracción / retención'
            }
            if (inst && inst.source === 'unscheduled_balance') {
                return 'Saldo sin cuota'
            }
            return `Cuota ${idx + 1}`
        },
        openPaymentModal(endpointType, recordId) {
            this.activePaymentRecordId = recordId
            if (endpointType === 'document') {
                this.mountedDocumentPayments = true
                this.$nextTick(() => {
                    this.showDocumentPayments = true
                })
            } else if (endpointType === 'sale_note') {
                this.mountedSaleNotePayments = true
                this.$nextTick(() => {
                    this.showSaleNotePayments = true
                })
            } else if (endpointType === 'purchase') {
                this.mountedPurchasePayments = true
                this.$nextTick(() => {
                    this.showPurchasePayments = true
                })
            } else if (endpointType === 'expense') {
                this.mountedExpensePayments = true
                this.$nextTick(() => {
                    this.showExpensePayments = true
                })
            }
        },
        openPurchaseFeesDialog(purchaseRow) {
            this.selectedPurchase = purchaseRow
            this.showPurchaseFeesDialog = true
        },
        statusBadgeClass(status) {
            switch (status) {
                case 'paid':
                    return 'badge-success'
                case 'overdue':
                    return 'badge-danger'
                case 'due_soon':
                    return 'badge-warning'
                case 'partial':
                    return 'badge-info'
                default:
                    return 'badge-secondary'
            }
        },
        statusLabel(status, daysOverdue = 0, daysUntilDue = null) {
            switch (status) {
                case 'paid':
                    return 'Pagada'
                case 'overdue':
                    return daysOverdue > 0 ? `Vencida (${daysOverdue} d)` : 'Vencida'
                case 'due_soon':
                    if (daysUntilDue === null || daysUntilDue === undefined) {
                        return 'Por vencer'
                    }
                    return daysUntilDue === 0 ? 'Vence hoy' : `Por vencer (${daysUntilDue} d)`
                case 'partial':
                    return 'Parcial'
                default:
                    return 'Vigente'
            }
        },
        formatCurrency(raw, currencyTypeId) {
            const value = parseFloat(raw || 0)
            const symbol = currencyTypeId === 'USD' ? 'US$' : (currencyTypeId === 'PEN' ? 'S/' : (currencyTypeId || 'PEN'))
            const sign = value < 0 ? '- ' : ''
            return `${sign}${symbol} ${Math.abs(value).toLocaleString('es-PE', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            })}`
        },
    },
}
</script>

<style>
.tn-doc-meta {
    display: grid;
    grid-template-columns: minmax(max-content, 1fr) 1fr;
    align-items: center;
    column-gap: 12px;
    white-space: nowrap;
}

.tn-doc-meta > .el-popover__reference-wrapper,
.tn-doc-meta > span {
    justify-self: start;
}

.tn-installments-pill {
    display: inline-flex;
    align-items: center;
    padding: 1px 8px;
    border-radius: 999px;
    background: rgba(37, 99, 235, 0.08);
    color: #1d4ed8;
    font-size: 11px;
    font-weight: 600;
    line-height: 18px;
    white-space: nowrap;
    cursor: help;
}

.tn-installments-popover {
    width: auto !important;
    min-width: 480px;
    max-width: 640px;
}

.tn-installments-popover .table th,
.tn-installments-popover .table td {
    white-space: nowrap;
    padding: 6px 10px;
}
</style>
