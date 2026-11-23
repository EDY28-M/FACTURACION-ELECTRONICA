<template>
    <div class="card mb-0 pt-2 pt-md-0">
        <div class="card-header bg-info d-flex align-items-center justify-content-between flex-wrap">
            <h3 class="my-0">Tesorería — Libro Caja y Bancos</h3>
            <div class="d-flex align-items-center flex-wrap">
                <el-button size="small" :loading="loading" :disabled="!canLoad" @click="loadReport">
                    <i class="fa fa-refresh"></i> Actualizar
                </el-button>
                <a
                    v-if="canLoad && report"
                    class="btn btn-custom btn-sm ml-2"
                    :href="exportUrl('excel')"
                    target="_blank"
                    rel="noopener"
                >
                    <i class="fa fa-file-excel"></i> Excel
                </a>
                <a
                    v-if="canLoad && report"
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
            <!-- PLE TXT notice -->
            <div class="alert alert-info small mb-3" role="note">
                <strong>Información normativa (SUNAT):</strong>
                Estos reportes incluyen la información mínima oficial de los Formatos 1.1 (Efectivo) y 1.2 (Cuenta corriente) en Excel y PDF.
                La exportación de archivos TXT PLE (códigos 010100 / 010200) permanece pendiente hasta confirmar el layout campo por campo del Anexo 2 de la RS 286-2009.
            </div>

            <el-tabs v-model="activeFormat" @tab-click="onFormatChange">
                <el-tab-pane label="Formato 1.1 — Efectivo (Caja)" name="1.1" />
                <el-tab-pane label="Formato 1.2 — Cuenta corriente (Bancos)" name="1.2" />
            </el-tabs>

            <!-- Filters -->
            <form class="row mb-3" @submit.prevent="loadReport">
                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="cb-date-start" class="control-label">Desde</label>
                    <el-date-picker
                        id="cb-date-start"
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
                    <label for="cb-date-end" class="control-label">Hasta</label>
                    <el-date-picker
                        id="cb-date-end"
                        v-model="filters.date_end"
                        type="date"
                        value-format="yyyy-MM-dd"
                        placeholder="AAAA-MM-DD"
                        :clearable="false"
                        class="w-100"
                        @change="loadReport"
                    />
                </div>

                <template v-if="activeFormat === '1.1'">
                    <div v-if="tables.establishments.length > 0" class="col-md-4 col-sm-6 mb-2">
                        <label for="cb-establishment" class="control-label">Sede</label>
                        <el-select
                            id="cb-establishment"
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
                    <div class="col-md-4 col-sm-6 mb-2">
                        <label for="cb-cash" class="control-label">Caja POS (opcional)</label>
                        <el-select
                            id="cb-cash"
                            v-model="filters.cash_id"
                            clearable
                            filterable
                            placeholder="Todas las cajas"
                            class="w-100"
                            @change="loadReport"
                        >
                            <el-option
                                v-for="c in tables.cashes"
                                :key="c.id"
                                :label="c.description"
                                :value="c.id"
                            />
                        </el-select>
                    </div>
                </template>

                <template v-else>
                    <div class="col-md-8 col-sm-12 mb-2">
                        <label for="cb-bank-account" class="control-label">Cuenta bancaria</label>
                        <el-select
                            id="cb-bank-account"
                            v-model="filters.bank_account_id"
                            filterable
                            placeholder="Seleccione una cuenta bancaria"
                            class="w-100"
                            @change="loadReport"
                        >
                            <el-option
                                v-for="acc in tables.bank_accounts"
                                :key="acc.id"
                                :label="acc.description + (acc.number ? ' (' + acc.number + ')' : '')"
                                :value="acc.id"
                            />
                        </el-select>
                    </div>
                </template>
            </form>

            <!-- Error state -->
            <div v-if="errorMessage" class="alert alert-danger d-flex justify-content-between align-items-center" role="alert">
                <span>{{ errorMessage }}</span>
                <el-button size="mini" type="danger" plain @click="loadReport">Reintentar</el-button>
            </div>

            <!-- Missing account prompt for 1.2 -->
            <div v-else-if="activeFormat === '1.2' && !filters.bank_account_id" class="alert alert-warning mb-0" role="status">
                Seleccione una cuenta bancaria para consultar el Formato 1.2.
            </div>

            <!-- Loading state -->
            <div v-else-if="loading" class="text-center py-5 text-muted" role="status" aria-live="polite">
                <i class="el-icon-loading mr-2"></i> Generando Libro Caja y Bancos...
            </div>

            <!-- Report body -->
            <template v-else-if="report">
                <div class="border rounded p-2 mb-3 bg-light small">
                    <div class="font-weight-bold mb-1">{{ report.meta.title }}</div>
                    <div class="row">
                        <div class="col-md-4"><strong>RUC:</strong> {{ report.meta.company_ruc }}</div>
                        <div class="col-md-4"><strong>Razón social:</strong> {{ report.meta.company_name }}</div>
                        <div class="col-md-4"><strong>Período:</strong> {{ report.meta.period_label }}</div>
                    </div>
                    <div v-if="activeFormat === '1.2'" class="row mt-1">
                        <div class="col-md-4"><strong>Entidad financiera:</strong> {{ report.meta.bank_name }}</div>
                        <div class="col-md-4"><strong>Cuenta corriente:</strong> {{ report.meta.account_number }}</div>
                        <div class="col-md-4"><strong>Moneda:</strong> {{ report.meta.currency_type_id }}</div>
                    </div>
                    <div v-else class="row mt-1">
                        <div class="col-md-4"><strong>Sede:</strong> {{ report.meta.establishment_label }}</div>
                        <div class="col-md-4"><strong>Caja:</strong> {{ report.meta.cash_label }}</div>
                        <div class="col-md-4"><strong>Moneda:</strong> {{ report.meta.currency_type_id }}</div>
                    </div>
                </div>

                <div v-if="activeFormat === '1.2' && report.totals.missing_payment_code_count > 0" class="alert alert-warning small py-2">
                    <strong>{{ report.totals.missing_payment_code_count }}</strong> operación(es) sin código de medio de pago (Tabla 1).
                    Complete la evidencia de pago antes de presentar el libro.
                </div>
                <div v-if="report.totals.rounding_drift && Number(report.totals.rounding_drift) !== 0" class="alert alert-info small py-2">
                    Diferencia por redondeo de conversión de moneda: {{ signedMoney(report.totals.rounding_drift, report.meta.currency_type_id) }}.
                    No se considera diferencia de conciliación.
                </div>

                <!-- Format 1.1 Table -->
                <div v-if="activeFormat === '1.1'" class="table-responsive">
                    <table class="table table-sm table-bordered table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th class="text-center text-nowrap">N.º Correlativo</th>
                                <th class="text-center text-nowrap">Fecha</th>
                                <th>Descripción de la operación</th>
                                <th class="text-center text-nowrap">Cód. PCGE</th>
                                <th>Denominación cuenta contable</th>
                                <th class="text-right text-nowrap">Deudor (Debe)</th>
                                <th class="text-right text-nowrap">Acreedor (Haber)</th>
                                <th class="text-right text-nowrap">Saldo acumulado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="table-secondary font-weight-bold">
                                <td class="text-center">-</td>
                                <td class="text-center">{{ report.meta.date_start }}</td>
                                <td>SALDO INICIAL</td>
                                <td class="text-center">101</td>
                                <td>Caja</td>
                                <td class="text-right">{{ report.opening_balance >= 0 ? money(report.opening_balance, 'PEN') : money(0, 'PEN') }}</td>
                                <td class="text-right">{{ report.opening_balance < 0 ? money(Math.abs(report.opening_balance), 'PEN') : money(0, 'PEN') }}</td>
                                <td class="text-right" :class="amountClass(report.opening_balance)">{{ signedMoney(report.opening_balance, 'PEN') }}</td>
                            </tr>

                            <tr v-if="report.rows.length === 0">
                                <td colspan="8" class="text-center text-muted py-3">
                                    No se registraron movimientos de efectivo en el período seleccionado.
                                </td>
                            </tr>

                            <tr v-for="row in report.rows" :key="row.correlative">
                                <td class="text-center text-nowrap">{{ row.correlative }}</td>
                                <td class="text-center text-nowrap">{{ row.date }}</td>
                                <td>{{ row.description }}</td>
                                <td class="text-center text-nowrap">{{ row.pcge_code }}</td>
                                <td>{{ row.pcge_name }}</td>
                                <td class="text-right text-nowrap">{{ money(row.debit, 'PEN') }}</td>
                                <td class="text-right text-nowrap">{{ money(row.credit, 'PEN') }}</td>
                                <td class="text-right text-nowrap" :class="amountClass(row.running_balance)">
                                    {{ signedMoney(row.running_balance, 'PEN') }}
                                </td>
                            </tr>

                            <tr class="font-weight-bold bg-light">
                                <td colspan="5">TOTALES DEL PERÍODO</td>
                                <td class="text-right text-nowrap">{{ money(report.totals.debit, 'PEN') }}</td>
                                <td class="text-right text-nowrap">{{ money(report.totals.credit, 'PEN') }}</td>
                                <td class="text-right text-nowrap" :class="amountClass(report.totals.closing_balance)">
                                    {{ signedMoney(report.totals.closing_balance, 'PEN') }}
                                </td>
                            </tr>

                            <template v-if="report.credit_note_adjustments && report.credit_note_adjustments.length > 0">
                                <tr class="table-warning">
                                    <td colspan="7">
                                        <strong>Ajustes por notas de crédito del período ({{ report.credit_note_adjustments.length }}):</strong>
                                        <span v-for="(cn, idx) in report.credit_note_adjustments" :key="cn.key">
                                            {{ idx > 0 ? ', ' : ' ' }}{{ cn.number }} (ref. {{ cn.document_number }}: {{ signedMoney(cn.amount, 'PEN') }})
                                        </span>
                                    </td>
                                    <td class="text-right text-nowrap font-weight-bold text-danger">
                                        {{ signedMoney(report.totals.credit_note_adjustments, 'PEN') }}
                                    </td>
                                </tr>
                                <tr class="table-primary font-weight-bold">
                                    <td colspan="7">SALDO FINAL SEGÚN SISTEMA</td>
                                    <td class="text-right text-nowrap" :class="amountClass(report.totals.system_closing_balance)">
                                        {{ signedMoney(report.totals.system_closing_balance, 'PEN') }}
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <!-- Format 1.2 Table -->
                <div v-else class="table-responsive">
                    <table class="table table-sm table-bordered table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th class="text-center text-nowrap">Correlativo</th>
                                <th class="text-center text-nowrap">Fecha</th>
                                <th class="text-nowrap">Medio de pago (T.1)</th>
                                <th>Descripción</th>
                                <th>Girador / Beneficiario</th>
                                <th class="text-nowrap">N.º transacción / doc.</th>
                                <th class="text-center text-nowrap">Cód. PCGE</th>
                                <th>Cuenta contable</th>
                                <th class="text-right text-nowrap">Deudor (Debe)</th>
                                <th class="text-right text-nowrap">Acreedor (Haber)</th>
                                <th class="text-right text-nowrap">Saldo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="table-secondary font-weight-bold">
                                <td class="text-center">-</td>
                                <td class="text-center">{{ report.meta.date_start }}</td>
                                <td class="text-center">-</td>
                                <td>SALDO INICIAL</td>
                                <td>-</td>
                                <td>-</td>
                                <td class="text-center">{{ report.meta.pcge_account }}</td>
                                <td>{{ report.meta.account_description }}</td>
                                <td class="text-right">{{ report.opening_balance >= 0 ? money(report.opening_balance, report.meta.currency_type_id) : money(0, report.meta.currency_type_id) }}</td>
                                <td class="text-right">{{ report.opening_balance < 0 ? money(Math.abs(report.opening_balance), report.meta.currency_type_id) : money(0, report.meta.currency_type_id) }}</td>
                                <td class="text-right" :class="amountClass(report.opening_balance)">{{ signedMoney(report.opening_balance, report.meta.currency_type_id) }}</td>
                            </tr>

                            <tr v-if="report.rows.length === 0">
                                <td colspan="11" class="text-center text-muted py-3">
                                    No se registraron movimientos bancarios en el período seleccionado.
                                </td>
                            </tr>

                            <tr v-for="row in report.rows" :key="row.correlative">
                                <td class="text-center text-nowrap">{{ row.correlative }}</td>
                                <td class="text-center text-nowrap">{{ row.date }}</td>
                                <td class="text-nowrap" :title="row.sunat_payment_label">
                                    <span v-if="row.missing_payment_code" class="badge badge-warning">Sin código</span>
                                    <span v-else class="badge badge-light border">{{ row.sunat_payment_code }}</span>
                                    <span v-if="!row.missing_payment_code" class="small ml-1">{{ row.sunat_payment_label }}</span>
                                </td>
                                <td>{{ row.description }}</td>
                                <td>{{ row.counterparty }}</td>
                                <td class="text-nowrap">{{ row.transaction_number }}</td>
                                <td class="text-center text-nowrap">{{ row.pcge_code }}</td>
                                <td>{{ row.pcge_name }}</td>
                                <td class="text-right text-nowrap">{{ money(row.debit, report.meta.currency_type_id) }}</td>
                                <td class="text-right text-nowrap">{{ money(row.credit, report.meta.currency_type_id) }}</td>
                                <td class="text-right text-nowrap" :class="amountClass(row.running_balance)">
                                    {{ signedMoney(row.running_balance, report.meta.currency_type_id) }}
                                </td>
                            </tr>

                            <tr class="font-weight-bold bg-light">
                                <td colspan="8">TOTALES DEL PERÍODO</td>
                                <td class="text-right text-nowrap">{{ money(report.totals.debit, report.meta.currency_type_id) }}</td>
                                <td class="text-right text-nowrap">{{ money(report.totals.credit, report.meta.currency_type_id) }}</td>
                                <td class="text-right text-nowrap" :class="amountClass(report.totals.closing_balance)">
                                    {{ signedMoney(report.totals.closing_balance, report.meta.currency_type_id) }}
                                </td>
                            </tr>

                            <template v-if="report.credit_note_adjustments && report.credit_note_adjustments.length > 0">
                                <tr class="table-warning">
                                    <td colspan="10">
                                        <strong>Ajustes por notas de crédito del período ({{ report.credit_note_adjustments.length }}):</strong>
                                        <span v-for="(cn, idx) in report.credit_note_adjustments" :key="cn.key">
                                            {{ idx > 0 ? ', ' : ' ' }}{{ cn.number }} (ref. {{ cn.document_number }}: {{ signedMoney(cn.amount, report.meta.currency_type_id) }})
                                        </span>
                                    </td>
                                    <td class="text-right text-nowrap font-weight-bold text-danger">
                                        {{ signedMoney(report.totals.credit_note_adjustments, report.meta.currency_type_id) }}
                                    </td>
                                </tr>
                                <tr class="table-primary font-weight-bold">
                                    <td colspan="10">SALDO FINAL SEGÚN SISTEMA</td>
                                    <td class="text-right text-nowrap" :class="amountClass(report.totals.system_closing_balance)">
                                        {{ signedMoney(report.totals.system_closing_balance, report.meta.currency_type_id) }}
                                    </td>
                                </tr>
                            </template>
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

export default {
    name: 'TreasuryCashBookIndex',
    data() {
        return {
            activeFormat: '1.1',
            loading: false,
            errorMessage: null,
            requestSeq: 0,
            tables: {
                establishments: [],
                bank_accounts: [],
                cashes: [],
                is_admin: true,
                default_establishment_id: null,
            },
            filters: {
                date_start: startOfMonth(),
                date_end: today(),
                establishment_id: null,
                cash_id: null,
                bank_account_id: null,
            },
            report: null,
        }
    },
    computed: {
        canLoad() {
            if (this.activeFormat === '1.2') {
                return Boolean(this.filters.bank_account_id)
            }
            return true
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
                const { data } = await this.$http.get('/treasury/cash-book/tables')
                this.tables = Object.assign({}, this.tables, data)
                if (!data.is_admin && data.default_establishment_id) {
                    this.filters.establishment_id = data.default_establishment_id
                }
                if (data.bank_accounts && data.bank_accounts.length > 0 && !this.filters.bank_account_id) {
                    this.filters.bank_account_id = data.bank_accounts[0].id
                }
            } catch (error) {
                this.errorMessage = parseError(error).general
            }
            this.loadReport()
        },
        onFormatChange() {
            this.requestSeq++
            this.loading = false
            this.report = null
            this.errorMessage = null
            if (this.canLoad) {
                this.loadReport()
            }
        },
        buildQueryParams() {
            const params = {
                format: this.activeFormat,
            }
            if (this.filters.date_start) {
                params.date_start = this.filters.date_start
            }
            if (this.filters.date_end) {
                params.date_end = this.filters.date_end
            }
            if (this.activeFormat === '1.1') {
                if (this.filters.establishment_id) {
                    params.establishment_id = this.filters.establishment_id
                }
                if (this.filters.cash_id) {
                    params.cash_id = this.filters.cash_id
                }
            } else if (this.filters.bank_account_id) {
                params.bank_account_id = this.filters.bank_account_id
            }
            return params
        },
        exportUrl(type) {
            const qs = new URLSearchParams(this.buildQueryParams()).toString()
            return `/treasury/cash-book/${type}?${qs}`
        },
        async loadReport() {
            if (!this.canLoad) return
            const seq = ++this.requestSeq
            this.loading = true
            this.errorMessage = null
            try {
                const { data } = await this.$http.get('/treasury/cash-book/records', {
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
