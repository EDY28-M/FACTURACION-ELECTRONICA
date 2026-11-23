<template>
    <div class="card mb-0 pt-2 pt-md-0">
        <div class="card-header bg-info d-flex align-items-center justify-content-between flex-wrap">
            <h3 class="my-0">Tesorería — Flujo de caja</h3>
            <div class="d-flex align-items-center flex-wrap">
                <el-button size="small" :loading="loading" @click="loadReport">
                    <i class="fa fa-refresh"></i> Actualizar
                </el-button>
                <a
                    class="btn btn-custom btn-sm ml-2"
                    :href="exportUrl('excel')"
                    target="_blank"
                    rel="noopener"
                >
                    <i class="fa fa-file-excel"></i> Excel
                </a>
                <a
                    class="btn btn-custom btn-sm ml-2"
                    :href="exportUrl('pdf')"
                    target="_blank"
                    rel="noopener"
                >
                    <i class="fa fa-file-pdf"></i> PDF
                </a>
            </div>
        </div>

        <div class="card-body">
            <el-tabs v-model="activeMode" @tab-click="onModeChange">
                <el-tab-pane label="Flujo de caja real" name="real" />
                <el-tab-pane label="Flujo de caja proyectado" name="projected" />
            </el-tabs>

            <!-- Filters -->
            <form class="row mb-3" @submit.prevent="loadReport">
                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="cf-date-start" class="control-label">Desde</label>
                    <el-date-picker
                        id="cf-date-start"
                        v-model="filters.date_start"
                        type="date"
                        value-format="yyyy-MM-dd"
                        placeholder="AAAA-MM-DD"
                        :clearable="false"
                        class="w-100"
                        @change="loadReport"
                    />
                </div>

                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="cf-date-end" class="control-label">Hasta</label>
                    <el-date-picker
                        id="cf-date-end"
                        v-model="filters.date_end"
                        type="date"
                        value-format="yyyy-MM-dd"
                        placeholder="AAAA-MM-DD"
                        :clearable="false"
                        class="w-100"
                        @change="loadReport"
                    />
                </div>

                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="cf-granularity" class="control-label">Granularidad</label>
                    <el-select
                        id="cf-granularity"
                        v-model="filters.granularity"
                        class="w-100"
                        @change="loadReport"
                    >
                        <el-option
                            v-for="item in tables.granularities"
                            :key="item.value"
                            :label="item.label"
                            :value="item.value"
                        />
                    </el-select>
                </div>

                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="cf-currency" class="control-label">Moneda</label>
                    <el-select
                        id="cf-currency"
                        v-model="filters.currency_type_id"
                        class="w-100"
                        @change="loadReport"
                    >
                        <el-option
                            v-for="item in tables.currencies"
                            :key="item.id"
                            :label="item.description"
                            :value="item.id"
                        />
                    </el-select>
                </div>

                <div v-if="tables.establishments.length > 0" class="col-md-2 col-sm-6 mb-2">
                    <label for="cf-establishment" class="control-label">Sede</label>
                    <el-select
                        id="cf-establishment"
                        v-model="filters.establishment_id"
                        :clearable="tables.is_admin"
                        :disabled="!tables.is_admin"
                        placeholder="Todas las sedes"
                        class="w-100"
                        @change="loadReport"
                    >
                        <el-option
                            v-for="est in tables.establishments"
                            :key="est.id"
                            :label="est.description"
                            :value="est.id"
                        />
                    </el-select>
                </div>

                <div v-if="activeMode === 'real'" class="col-md-2 col-sm-6 mb-2">
                    <label for="cf-destination" class="control-label">Destino</label>
                    <el-select
                        id="cf-destination"
                        v-model="filters.destination_type"
                        class="w-100"
                        @change="loadReport"
                    >
                        <el-option
                            v-for="item in tables.destination_types"
                            :key="item.value"
                            :label="item.label"
                            :value="item.value"
                        />
                    </el-select>
                </div>

                <div v-else class="col-md-2 col-sm-6 mb-2 d-flex align-items-end">
                    <div class="pb-1">
                        <el-checkbox
                            id="cf-include-overdue"
                            v-model="filters.include_overdue"
                            @change="loadReport"
                        >
                            Incluir vencidos
                        </el-checkbox>
                    </div>
                </div>
            </form>

            <!-- Error state -->
            <div v-if="errorMessage" class="alert alert-danger d-flex justify-content-between align-items-center" role="alert">
                <span>{{ errorMessage }}</span>
                <el-button size="mini" type="danger" plain @click="loadReport">Reintentar</el-button>
            </div>

            <!-- Loading state -->
            <div v-if="loading" class="text-center py-5 text-muted" role="status" aria-live="polite">
                <i class="el-icon-loading mr-2"></i> Calculando flujo de caja...
            </div>

            <template v-else-if="!errorMessage && report">
                <!-- Missing FX notice -->
                <div v-if="report.missing_exchange_rate" class="alert alert-info mb-3" role="alert">
                    <strong>Tipo de cambio incompleto:</strong> Algunas operaciones en dólares (USD) no pudieron convertirse a soles (PEN) por falta de tipo de cambio registrado.
                </div>

                <!-- Negative projection alert -->
                <div v-if="activeMode === 'projected' && report.has_negative_projection" class="alert alert-danger mb-3" role="alert">
                    <strong>Alerta de liquidez negativa proyectada:</strong>
                    Se proyecta un saldo negativo en {{ report.negative_periods.length }} período(s):
                    <ul class="mb-0 mt-1 pl-3">
                        <li v-for="neg in report.negative_periods" :key="neg.key">
                            {{ neg.label }}:
                            <strong>{{ signedMoney(neg.closing_balance, report.currency_type_id) }} (déficit proyectado)</strong>
                        </li>
                    </ul>
                </div>

                <!-- KPI cards -->
                <div class="row mb-4">
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="card border h-100 mb-0">
                            <div class="card-body">
                                <div class="text-muted small">Saldo inicial</div>
                                <div class="h4 mt-1 mb-0" :class="amountClass(report.totals.opening_balance)">
                                    {{ signedMoney(report.totals.opening_balance, report.currency_type_id) }}
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="card border h-100 mb-0">
                            <div class="card-body">
                                <div class="text-muted small">Total entradas</div>
                                <div class="h4 mt-1 mb-0 text-success">
                                    {{ signedMoney(report.totals.inflows, report.currency_type_id) }}
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="card border h-100 mb-0">
                            <div class="card-body">
                                <div class="text-muted small">Total salidas</div>
                                <div class="h4 mt-1 mb-0 text-danger">
                                    {{ signedMoney(-Math.abs(report.totals.outflows || 0), report.currency_type_id) }}
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="card border h-100 mb-0">
                            <div class="card-body">
                                <div class="text-muted small">
                                    Saldo final {{ activeMode === 'projected' ? 'proyectado' : '' }}
                                </div>
                                <div class="h4 mt-1 mb-0" :class="amountClass(report.totals.closing_balance)">
                                    {{ signedMoney(report.totals.closing_balance, report.currency_type_id) }}
                                </div>
                                <div class="small text-muted mt-1">
                                    Flujo neto: {{ signedMoney(report.totals.net, report.currency_type_id) }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Matrix table -->
                <div v-if="report.periods.length === 0" class="alert alert-light border text-muted mb-0">
                    No hay períodos disponibles para el rango seleccionado.
                </div>

                <div v-else class="table-responsive">
                    <table class="table table-sm table-bordered table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th class="text-nowrap">Concepto / Categoría</th>
                                <th v-for="p in report.periods" :key="p.key" class="text-right text-nowrap">
                                    {{ p.label }}
                                    <span v-if="p.is_overdue" class="badge badge-danger ml-1">Vencido</span>
                                </th>
                                <th class="text-right text-nowrap">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Opening balance row -->
                            <tr class="table-secondary font-weight-bold">
                                <td>Saldo inicial</td>
                                <td v-for="ps in report.period_summary" :key="'open-' + ps.key" class="text-right text-nowrap" :class="amountClass(ps.opening_balance)">
                                    {{ signedMoney(ps.opening_balance, report.currency_type_id) }}
                                </td>
                                <td class="text-right text-nowrap" :class="amountClass(report.totals.opening_balance)">
                                    {{ signedMoney(report.totals.opening_balance, report.currency_type_id) }}
                                </td>
                            </tr>

                            <!-- Inflows section -->
                            <tr class="bg-light font-weight-bold">
                                <td :colspan="report.periods.length + 2">ENTRADAS</td>
                            </tr>
                            <tr v-if="report.inflow_rows.length === 0">
                                <td :colspan="report.periods.length + 2" class="text-muted small">
                                    Sin entradas registradas en el rango seleccionado.
                                </td>
                            </tr>
                            <tr v-for="row in report.inflow_rows" :key="'in-' + row.category">
                                <td>{{ row.category }}</td>
                                <td v-for="p in report.periods" :key="'in-' + row.category + '-' + p.key" class="text-right text-nowrap">
                                    {{ money(row.values[p.key], report.currency_type_id) }}
                                </td>
                                <td class="text-right text-nowrap font-weight-bold">
                                    {{ money(row.total, report.currency_type_id) }}
                                </td>
                            </tr>
                            <tr class="font-weight-bold">
                                <td>Total entradas</td>
                                <td v-for="ps in report.period_summary" :key="'tin-' + ps.key" class="text-right text-nowrap text-success">
                                    {{ signedMoney(ps.inflows, report.currency_type_id) }}
                                </td>
                                <td class="text-right text-nowrap text-success">
                                    {{ signedMoney(report.totals.inflows, report.currency_type_id) }}
                                </td>
                            </tr>

                            <!-- Outflows section -->
                            <tr class="bg-light font-weight-bold">
                                <td :colspan="report.periods.length + 2">SALIDAS</td>
                            </tr>
                            <tr v-if="report.outflow_rows.length === 0">
                                <td :colspan="report.periods.length + 2" class="text-muted small">
                                    Sin salidas registradas en el rango seleccionado.
                                </td>
                            </tr>
                            <tr v-for="row in report.outflow_rows" :key="'out-' + row.category">
                                <td>{{ row.category }}</td>
                                <td v-for="p in report.periods" :key="'out-' + row.category + '-' + p.key" class="text-right text-nowrap">
                                    {{ money(row.values[p.key], report.currency_type_id) }}
                                </td>
                                <td class="text-right text-nowrap font-weight-bold">
                                    {{ money(row.total, report.currency_type_id) }}
                                </td>
                            </tr>
                            <tr class="font-weight-bold">
                                <td>Total salidas</td>
                                <td v-for="ps in report.period_summary" :key="'tout-' + ps.key" class="text-right text-nowrap text-danger">
                                    {{ signedMoney(-Math.abs(ps.outflows || 0), report.currency_type_id) }}
                                </td>
                                <td class="text-right text-nowrap text-danger">
                                    {{ signedMoney(-Math.abs(report.totals.outflows || 0), report.currency_type_id) }}
                                </td>
                            </tr>

                            <!-- Internal transfers section (Real consolidated only) -->
                            <template v-if="activeMode === 'real' && report.transfer_rows && report.transfer_rows.length > 0">
                                <tr class="bg-light font-weight-bold">
                                    <td :colspan="report.periods.length + 2">
                                        TRANSFERENCIAS INTERNAS (excluidas de entradas y salidas operativas)
                                    </td>
                                </tr>
                                <tr v-for="row in report.transfer_rows" :key="'tr-' + row.category">
                                    <td>{{ row.category }}</td>
                                    <td v-for="p in report.periods" :key="'tr-' + row.category + '-' + p.key" class="text-right text-nowrap text-muted">
                                        {{ money(row.values[p.key], report.currency_type_id) }}
                                    </td>
                                    <td class="text-right text-nowrap text-muted font-weight-bold">
                                        {{ money(row.total, report.currency_type_id) }}
                                    </td>
                                </tr>
                            </template>

                            <!-- Non-zero internal transfers net (FX spread / cross-currency) -->
                            <tr v-if="hasNonZeroTransfersNet" class="font-weight-bold table-warning">
                                <td>Neto de transferencias (dif. cambio / otra moneda)</td>
                                <td v-for="ps in report.period_summary" :key="'tnet-' + ps.key" class="text-right text-nowrap" :class="amountClass(ps.transfers_net)">
                                    {{ signedMoney(ps.transfers_net || 0, report.currency_type_id) }}
                                </td>
                                <td class="text-right text-nowrap" :class="amountClass(report.totals.transfers_net)">
                                    {{ signedMoney(report.totals.transfers_net || 0, report.currency_type_id) }}
                                </td>
                            </tr>

                            <!-- Period net & closing -->
                            <tr class="font-weight-bold">
                                <td>Flujo neto del período</td>
                                <td v-for="ps in report.period_summary" :key="'net-' + ps.key" class="text-right text-nowrap" :class="amountClass(ps.net)">
                                    {{ signedMoney(ps.net, report.currency_type_id) }}
                                </td>
                                <td class="text-right text-nowrap" :class="amountClass(report.totals.net)">
                                    {{ signedMoney(report.totals.net, report.currency_type_id) }}
                                </td>
                            </tr>
                            <tr class="table-primary font-weight-bold">
                                <td>Saldo final</td>
                                <td
                                    v-for="ps in report.period_summary"
                                    :key="'close-' + ps.key"
                                    class="text-right text-nowrap"
                                    :class="ps.closing_balance < 0 ? 'table-danger text-danger' : amountClass(ps.closing_balance)"
                                >
                                    {{ signedMoney(ps.closing_balance, report.currency_type_id) }}
                                </td>
                                <td
                                    class="text-right text-nowrap"
                                    :class="report.totals.closing_balance < 0 ? 'table-danger text-danger' : amountClass(report.totals.closing_balance)"
                                >
                                    {{ signedMoney(report.totals.closing_balance, report.currency_type_id) }}
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
import { amountClass, money, parseError, signedMoney, today } from '../reconciliation/helpers'

function startOfMonth() {
    const d = new Date()
    const pad = n => (n < 10 ? '0' + n : '' + n)
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-01`
}

function addDays(baseStr, days) {
    const d = new Date(baseStr + 'T00:00:00')
    d.setDate(d.getDate() + days)
    const pad = n => (n < 10 ? '0' + n : '' + n)
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

export default {
    name: 'TreasuryCashFlowIndex',
    data() {
        return {
            activeMode: 'real',
            loading: false,
            errorMessage: null,
            requestSeq: 0,
            tables: {
                establishments: [],
                currencies: [
                    { id: 'ALL', description: 'Consolidado (PEN)' },
                    { id: 'PEN', description: 'Soles (PEN)' },
                    { id: 'USD', description: 'Dólares (USD)' },
                ],
                destination_types: [
                    { value: 'all', label: 'Todas las cuentas (Caja y Bancos)' },
                    { value: 'bank_account', label: 'Solo cuentas bancarias' },
                    { value: 'cash', label: 'Solo caja (Efectivo)' },
                ],
                granularities: [
                    { value: 'week', label: 'Semanal' },
                    { value: 'month', label: 'Mensual' },
                ],
                is_admin: true,
                default_establishment_id: null,
            },
            filters: {
                date_start: startOfMonth(),
                date_end: today(),
                granularity: 'month',
                currency_type_id: 'ALL',
                establishment_id: null,
                destination_type: 'all',
                include_overdue: true,
            },
            report: null,
        }
    },
    computed: {
        hasNonZeroTransfersNet() {
            if (!this.report || this.activeMode !== 'real') return false
            if (Math.abs(parseFloat((this.report.totals && this.report.totals.transfers_net) || 0)) > 0.0001) {
                return true
            }
            return (this.report.period_summary || []).some(ps => Math.abs(parseFloat(ps.transfers_net || 0)) > 0.0001)
        },
    },
    created() {
        this.loadTables()
    },
    methods: {
        money,
        signedMoney,
        amountClass,
        async loadTables() {
            try {
                const { data } = await this.$http.get('/treasury/cash-flow/tables')
                this.tables = Object.assign({}, this.tables, data)
                if (!data.is_admin && data.default_establishment_id) {
                    this.filters.establishment_id = data.default_establishment_id
                }
            } catch (error) {
                this.errorMessage = parseError(error).general
            }
            this.loadReport()
        },
        onModeChange() {
            if (this.activeMode === 'projected') {
                const now = today()
                this.filters.date_start = now
                this.filters.date_end = addDays(now, 60)
                this.filters.granularity = 'week'
            } else {
                this.filters.date_start = startOfMonth()
                this.filters.date_end = today()
                this.filters.granularity = 'month'
            }
            this.loadReport()
        },
        buildQueryParams() {
            const params = {
                mode: this.activeMode,
                granularity: this.filters.granularity,
                currency_type_id: this.filters.currency_type_id,
            }
            if (this.filters.date_start) {
                params.date_start = this.filters.date_start
            }
            if (this.filters.date_end) {
                params.date_end = this.filters.date_end
            }
            if (this.filters.establishment_id) {
                params.establishment_id = this.filters.establishment_id
            }
            if (this.activeMode === 'real') {
                params.destination_type = this.filters.destination_type
            } else {
                params.include_overdue = this.filters.include_overdue ? 1 : 0
            }
            return params
        },
        exportUrl(type) {
            const qs = new URLSearchParams(this.buildQueryParams()).toString()
            return `/treasury/cash-flow/${type}?${qs}`
        },
        async loadReport() {
            const seq = ++this.requestSeq
            this.loading = true
            this.errorMessage = null
            try {
                const { data } = await this.$http.get('/treasury/cash-flow/records', {
                    params: this.buildQueryParams(),
                })
                if (seq !== this.requestSeq) return
                this.report = data.data
            } catch (error) {
                if (seq !== this.requestSeq) return
                this.errorMessage = parseError(error).general
            } finally {
                if (seq === this.requestSeq) {
                    this.loading = false
                }
            }
        },
    },
}
</script>
