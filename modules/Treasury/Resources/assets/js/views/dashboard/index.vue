<template>
    <div class="card mb-0 pt-2 pt-md-0">
        <div class="card-header bg-info d-flex align-items-center justify-content-between flex-wrap">
            <h3 class="my-0">Tesorería — Resumen de saldos</h3>
            <div>
                <el-button size="small" :loading="loading" @click="loadSummary">
                    <i class="fa fa-refresh"></i> Actualizar
                </el-button>
                <a class="btn btn-custom btn-sm ml-2" href="/treasury/accounts">
                    Administrar cuentas
                </a>
            </div>
        </div>

        <div class="card-body">
            <!-- Filters -->
            <form class="row mb-3" @submit.prevent="loadSummary">
                <div class="col-md-3 col-sm-6 mb-2">
                    <label for="treasury-filter-establishment" class="control-label">Sede</label>
                    <el-select
                        id="treasury-filter-establishment"
                        v-model="filters.establishment_id"
                        clearable
                        placeholder="Todas las sedes"
                        class="w-100"
                        @change="loadSummary"
                    >
                        <el-option
                            v-for="item in establishments"
                            :key="item.id"
                            :label="item.description"
                            :value="item.id"
                        />
                    </el-select>
                </div>

                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="treasury-filter-currency" class="control-label">Moneda</label>
                    <el-select
                        id="treasury-filter-currency"
                        v-model="filters.currency_type_id"
                        clearable
                        placeholder="Todas"
                        class="w-100"
                        @change="loadSummary"
                    >
                        <el-option
                            v-for="item in currency_types"
                            :key="item.id"
                            :label="item.description"
                            :value="item.id"
                        />
                    </el-select>
                </div>

                <div class="col-md-3 col-sm-6 mb-2">
                    <label for="treasury-filter-period" class="control-label">Periodo</label>
                    <el-select
                        id="treasury-filter-period"
                        v-model="filters.period"
                        clearable
                        placeholder="Acumulado total"
                        class="w-100"
                        @change="onPeriodChange"
                    >
                        <el-option label="Acumulado total" value="" />
                        <el-option label="Por mes" value="month" />
                        <el-option label="Por fecha" value="date" />
                        <el-option label="Entre fechas" value="between_dates" />
                    </el-select>
                </div>

                <div v-if="filters.period === 'month'" class="col-md-2 col-sm-6 mb-2">
                    <label for="treasury-filter-month" class="control-label">Mes</label>
                    <el-date-picker
                        id="treasury-filter-month"
                        v-model="filters.month_start"
                        type="month"
                        value-format="yyyy-MM"
                        placeholder="Seleccione mes"
                        class="w-100"
                        @change="loadSummary"
                    />
                </div>

                <div v-if="filters.period === 'date' || filters.period === 'between_dates'" class="col-md-2 col-sm-6 mb-2">
                    <label for="treasury-filter-date-start" class="control-label">
                        {{ filters.period === 'date' ? 'Fecha' : 'Desde' }}
                    </label>
                    <el-date-picker
                        id="treasury-filter-date-start"
                        v-model="filters.date_start"
                        type="date"
                        value-format="yyyy-MM-dd"
                        placeholder="YYYY-MM-DD"
                        class="w-100"
                        @change="loadSummary"
                    />
                </div>

                <div v-if="filters.period === 'between_dates'" class="col-md-2 col-sm-6 mb-2">
                    <label for="treasury-filter-date-end" class="control-label">Hasta</label>
                    <el-date-picker
                        id="treasury-filter-date-end"
                        v-model="filters.date_end"
                        type="date"
                        value-format="yyyy-MM-dd"
                        placeholder="YYYY-MM-DD"
                        class="w-100"
                        @change="loadSummary"
                    />
                </div>
            </form>

            <!-- Error state -->
            <div v-if="errorMessage" class="alert alert-danger d-flex justify-content-between align-items-center" role="alert">
                <span>{{ errorMessage }}</span>
                <el-button size="mini" type="danger" plain @click="loadSummary">Reintentar</el-button>
            </div>

            <!-- Loading state -->
            <div v-if="loading" class="text-center py-5 text-muted" role="status" aria-live="polite">
                <i class="el-icon-loading mr-2"></i> Cargando saldos de tesorería...
            </div>

            <template v-else-if="!errorMessage">
                <!-- KPI Cards -->
                <div class="row mb-4">
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="card border h-100 mb-0">
                            <div class="card-body">
                                <div class="text-muted small">Total consolidado (PEN)</div>
                                <div class="h4 mt-1 mb-1" :class="amountClass(totals.consolidated_pen)">
                                    {{ formatSignedCurrency(totals.consolidated_pen, 'PEN') }}
                                </div>
                                <div class="small text-muted">
                                    <span v-if="totals.exchange_rate_purchase">
                                        TC compra SBS: S/ {{ totals.exchange_rate_purchase }}
                                    </span>
                                    <span v-else>Sin tipo de cambio registrado</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="card border h-100 mb-0">
                            <div class="card-body">
                                <div class="text-muted small">Cuentas bancarias</div>
                                <div class="h5 mt-1 mb-1" :class="amountClass(totals.banks_pen)">
                                    {{ formatSignedCurrency(totals.banks_pen, 'PEN') }}
                                </div>
                                <div class="small" :class="amountClass(totals.banks_usd)">
                                    {{ formatSignedCurrency(totals.banks_usd, 'USD') }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="card border h-100 mb-0">
                            <div class="card-body">
                                <div class="text-muted small">
                                    Caja general ({{ totals.open_cash_count }} abierta(s))
                                </div>
                                <div class="h4 mt-1 mb-1" :class="amountClass(totals.cash_pen)">
                                    {{ formatSignedCurrency(totals.cash_pen, 'PEN') }}
                                </div>
                                <div class="small text-muted">Saldo acumulado en efectivo</div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="card border h-100 mb-0">
                            <div class="card-body">
                                <div class="text-muted small">Cuenta de detracciones BN</div>
                                <div class="h4 mt-1 mb-1" :class="amountClass(totals.detraction_bn_balance)">
                                    {{ formatSignedCurrency(totals.detraction_bn_balance, 'PEN') }}
                                </div>
                                <div class="small text-muted">
                                    <span v-if="totals.detraction_bn_count > 0">
                                        {{ totals.detraction_bn_count }} cuenta(s) configurada(s)
                                    </span>
                                    <span v-else>Sin cuenta BN asignada</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Missing exchange rate notice -->
                <div v-if="totals.missing_exchange_rate" class="alert alert-info mb-3" role="alert">
                    <strong>Tipo de cambio no registrado:</strong> Las cuentas en dólares (USD) no se incluyen en el total consolidado en PEN hasta registrar un tipo de cambio SBS.
                </div>

                <!-- Negative balance alerts -->
                <div v-if="alerts.negative_accounts.length > 0" class="alert alert-warning mb-3" role="alert">
                    <strong>Alertas de saldo negativo ({{ alerts.negative_accounts.length }}):</strong>
                    <ul class="mb-0 mt-1 pl-3">
                        <li v-for="alert in alerts.negative_accounts" :key="alert.type + '-' + alert.id">
                            {{ alert.description }}:
                            <strong>{{ formatSignedCurrency(alert.balance, alert.currency_type_id) }} (saldo negativo)</strong>
                        </li>
                    </ul>
                </div>

                <!-- Phase 3 Receivables & Payables Alerts -->
                <div
                    v-if="hasReceivablePayableAlerts"
                    class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap mb-4"
                    role="alert"
                >
                    <div class="mr-3">
                        <strong>Alertas de cobranzas y pagos:</strong>
                        <span v-if="alerts.overdue_installments_count > 0" class="badge badge-danger ml-2">
                            {{ alerts.overdue_installments_count }} cuota(s) vencida(s)
                        </span>
                        <span v-if="alerts.due_soon_installments_count > 0" class="badge badge-warning ml-2">
                            {{ alerts.due_soon_installments_count }} por vencer (7 días)
                        </span>
                        <span v-if="alerts.unbancarized_payments_count > 0" class="badge badge-danger ml-2">
                            {{ alerts.unbancarized_payments_count }} con pago(s) sin evidencia
                        </span>
                        <span v-else-if="alerts.unbancarized_obligations_count > 0" class="badge badge-info ml-2">
                            {{ alerts.unbancarized_obligations_count }} requieren bancarización
                        </span>
                    </div>
                    <a class="btn btn-sm btn-outline-dark mt-2 mt-md-0" href="/treasury/receivables-payables">
                        Ver cobranzas y pagos
                    </a>
                </div>

                <!-- Bank Accounts Table -->
                <h5 class="mb-2">Saldo por cuenta bancaria</h5>
                <div v-if="bankAccounts.length === 0" class="alert alert-light border text-muted mb-4">
                    No hay cuentas bancarias registradas para los filtros seleccionados.
                </div>
                <div v-else class="table-responsive mb-4">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>Cuenta</th>
                                <th>Tipo</th>
                                <th>PCGE</th>
                                <th>Sede</th>
                                <th class="text-center">ITF</th>
                                <th class="text-right">Saldo en moneda</th>
                                <th class="text-right">Equivalente PEN</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in bankAccounts" :key="row.id">
                                <td>
                                    <strong>{{ row.bank_description }}</strong> — {{ row.description }}
                                    <div class="small text-muted">{{ row.number }}</div>
                                </td>
                                <td>{{ row.account_type_label }}</td>
                                <td>{{ row.pcge_account || '—' }}</td>
                                <td>{{ row.establishment_description }}</td>
                                <td class="text-center">
                                    <span v-if="row.itf_exempt" class="badge badge-secondary">Exenta</span>
                                    <span v-else class="text-muted small">Afecta</span>
                                </td>
                                <td class="text-right" :class="amountClass(row.balance_currency)">
                                    <strong>{{ formatSignedCurrency(row.balance_currency, row.currency_type_id) }}</strong>
                                    <span v-if="row.is_negative" class="badge badge-danger ml-1">Negativo</span>
                                </td>
                                <td class="text-right" :class="row.balance_pen !== null ? amountClass(row.balance_pen) : 'text-muted'">
                                    <span v-if="row.balance_pen !== null">
                                        {{ formatSignedCurrency(row.balance_pen, 'PEN') }}
                                    </span>
                                    <span v-else>Sin TC</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Open Cash Boxes Table -->
                <h5 class="mb-2">Cajas abiertas</h5>
                <div v-if="openCashes.length === 0" class="alert alert-light border text-muted mb-0">
                    No hay cajas POS abiertas en este momento.
                </div>
                <div v-else class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Caja</th>
                                <th>Usuario</th>
                                <th>Apertura</th>
                                <th class="text-right">Saldo inicial</th>
                                <th class="text-right">Saldo actual</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="cash in openCashes" :key="cash.id">
                                <td>{{ cash.description }}</td>
                                <td>{{ cash.user_name }}</td>
                                <td>{{ cash.date_opening }} {{ cash.time_opening }}</td>
                                <td class="text-right">{{ formatSignedCurrency(cash.beginning_balance, 'PEN') }}</td>
                                <td class="text-right" :class="amountClass(cash.balance)">
                                    <strong>{{ formatSignedCurrency(cash.balance, 'PEN') }}</strong>
                                    <span v-if="cash.is_negative" class="badge badge-danger ml-1">Negativo</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>
    </div>
</template>

<script>
export default {
    data() {
        return {
            loading: false,
            errorMessage: null,
            requestSeq: 0,
            establishments: [],
            currency_types: [],
            filters: {
                establishment_id: null,
                currency_type_id: null,
                period: '',
                month_start: null,
                date_start: null,
                date_end: null,
            },
            bankAccounts: [],
            openCashes: [],
            totals: {
                consolidated_pen: '0.00',
                banks_pen: '0.00',
                banks_usd: '0.00',
                banks_usd_in_pen: '0.00',
                cash_pen: '0.00',
                open_cash_count: 0,
                detraction_bn_balance: '0.00',
                detraction_bn_count: 0,
                exchange_rate_purchase: null,
                missing_exchange_rate: false,
                unconverted_currencies: [],
            },
            alerts: {
                negative_accounts: [],
                overdue_detractions: [],
                missing_exchange_rate: false,
                overdue_installments_count: 0,
                due_soon_installments_count: 0,
                unbancarized_payments_count: 0,
                unbancarized_obligations_count: 0,
            },
        }
    },
    computed: {
        hasReceivablePayableAlerts() {
            return (
                Number(this.alerts.overdue_installments_count || 0) > 0 ||
                Number(this.alerts.due_soon_installments_count || 0) > 0 ||
                Number(this.alerts.unbancarized_payments_count || 0) > 0 ||
                Number(this.alerts.unbancarized_obligations_count || 0) > 0
            )
        },
    },
    created() {
        this.loadTables()
        this.loadSummary()
    },
    methods: {
        loadTables() {
            this.$http.get('/treasury/summary/tables')
                .then(response => {
                    this.establishments = response.data.establishments || []
                    this.currency_types = response.data.currency_types || []
                })
                .catch(() => {})
        },
        onPeriodChange() {
            this.filters.month_start = null
            this.filters.date_start = null
            this.filters.date_end = null
            if (!this.filters.period) {
                this.loadSummary()
            }
        },
        loadSummary() {
            const seq = ++this.requestSeq
            this.loading = true
            this.errorMessage = null

            this.$http.get('/treasury/summary/records', { params: this.filters })
                .then(response => {
                    if (seq !== this.requestSeq) {
                        return
                    }
                    const data = response.data.data || {}
                    this.bankAccounts = data.bank_accounts || []
                    this.openCashes = data.open_cashes || []
                    this.totals = Object.assign({}, this.totals, data.totals || {})
                    this.alerts = Object.assign(
                        {
                            negative_accounts: [],
                            overdue_detractions: [],
                            missing_exchange_rate: false,
                            overdue_installments_count: 0,
                            due_soon_installments_count: 0,
                            unbancarized_payments_count: 0,
                            unbancarized_obligations_count: 0,
                        },
                        data.alerts || {}
                    )
                })
                .catch(() => {
                    if (seq !== this.requestSeq) {
                        return
                    }
                    this.errorMessage = 'No se pudieron cargar los saldos de tesorería. Intente nuevamente.'
                })
                .then(() => {
                    if (seq === this.requestSeq) {
                        this.loading = false
                    }
                })
        },
        formatSignedCurrency(rawAmount, currencyTypeId) {
            const value = parseFloat(rawAmount || 0)
            let symbol = currencyTypeId || 'PEN'
            if (currencyTypeId === 'PEN') {
                symbol = 'S/'
            } else if (currencyTypeId === 'USD') {
                symbol = 'US$'
            }
            const sign = value < 0 ? '- ' : ''
            const formatted = Math.abs(value).toLocaleString('es-PE', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            })

            return `${sign}${symbol} ${formatted}`
        },
        amountClass(rawAmount) {
            const value = parseFloat(rawAmount || 0)
            if (value < 0) {
                return 'text-danger'
            }
            return ''
        },
    },
}
</script>
