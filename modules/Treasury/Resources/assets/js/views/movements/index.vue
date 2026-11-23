<template>
    <div class="card mb-0 pt-2 pt-md-0">
        <div class="card-header bg-info d-flex align-items-center justify-content-between flex-wrap">
            <h3 class="my-0">Tesorería — Movimientos</h3>
            <div>
                <a class="btn btn-custom btn-sm mr-2" href="/treasury/transfers">
                    Transferencias
                </a>
                <button type="button" class="btn btn-custom btn-sm" @click.prevent="showCreateDialog = true">
                    <i class="fa fa-plus-circle"></i> Nuevo movimiento
                </button>
            </div>
        </div>

        <div class="card-body">
            <!-- Filters -->
            <form class="row mb-3" @submit.prevent="getRecords(1)">
                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="mov-filter-est" class="control-label">Sede</label>
                    <el-select
                        id="mov-filter-est"
                        v-model="filters.establishment_id"
                        clearable
                        placeholder="Todas"
                        class="w-100"
                        @change="getRecords(1)"
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
                    <label for="mov-filter-kind" class="control-label">Tipo</label>
                    <el-select
                        id="mov-filter-kind"
                        v-model="filters.kind"
                        clearable
                        placeholder="Todos"
                        class="w-100"
                        @change="getRecords(1)"
                    >
                        <el-option
                            v-for="item in tables.all_kinds"
                            :key="item.value"
                            :label="item.label"
                            :value="item.value"
                        />
                    </el-select>
                </div>

                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="mov-filter-state" class="control-label">Estado</label>
                    <el-select
                        id="mov-filter-state"
                        v-model="filters.state"
                        clearable
                        placeholder="Todos"
                        class="w-100"
                        @change="getRecords(1)"
                    >
                        <el-option label="Activos" value="active" />
                        <el-option label="Anulados" value="voided" />
                    </el-select>
                </div>

                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="mov-filter-start" class="control-label">Desde</label>
                    <el-date-picker
                        id="mov-filter-start"
                        v-model="filters.date_start"
                        type="date"
                        value-format="yyyy-MM-dd"
                        placeholder="YYYY-MM-DD"
                        class="w-100"
                        @change="getRecords(1)"
                    />
                </div>

                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="mov-filter-end" class="control-label">Hasta</label>
                    <el-date-picker
                        id="mov-filter-end"
                        v-model="filters.date_end"
                        type="date"
                        value-format="yyyy-MM-dd"
                        placeholder="YYYY-MM-DD"
                        class="w-100"
                        @change="getRecords(1)"
                    />
                </div>

                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="mov-filter-search" class="control-label">Buscar</label>
                    <el-input
                        id="mov-filter-search"
                        v-model="filters.search"
                        clearable
                        placeholder="Descripción / op."
                        @keyup.enter.native="getRecords(1)"
                        @clear="getRecords(1)"
                    />
                </div>
            </form>

            <div v-if="errorMessage" class="alert alert-danger d-flex justify-content-between align-items-center" role="alert">
                <span>{{ errorMessage }}</span>
                <el-button size="mini" type="danger" plain @click="getRecords(meta.current_page)">Reintentar</el-button>
            </div>

            <div v-if="loading" class="text-center py-5 text-muted" role="status" aria-live="polite">
                <i class="el-icon-loading mr-2"></i> Cargando movimientos de tesorería...
            </div>

            <template v-else-if="!errorMessage">
                <div v-if="records.length === 0" class="alert alert-light border text-muted mb-0">
                    No se encontraron movimientos para los filtros seleccionados.
                </div>

                <div v-else class="table-responsive">
                    <table class="table table-sm table-hover mb-2">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Tipo</th>
                                <th>Cuenta / Caja</th>
                                <th>Concepto / Descripción</th>
                                <th>N° Operación</th>
                                <th class="text-right">Monto</th>
                                <th class="text-center">Estado</th>
                                <th class="text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in records" :key="row.id" :class="row.state === 'voided' ? 'text-muted' : ''">
                                <td>
                                    <div>{{ row.date }}</div>
                                    <div class="small text-muted">{{ row.establishment_description }}</div>
                                </td>
                                <td>
                                    <div>{{ row.kind_label }}</div>
                                    <span
                                        class="badge"
                                        :class="row.direction > 0 ? 'badge-success' : 'badge-secondary'"
                                    >
                                        {{ row.direction > 0 ? 'Entrada (+)' : 'Salida (-)' }}
                                    </span>
                                </td>
                                <td>
                                    <div>{{ row.destination_label }}</div>
                                    <div v-if="row.counterparty_name" class="small text-muted">
                                        {{ row.counterparty_document ? `${row.counterparty_document} — ` : '' }}{{ row.counterparty_name }}
                                    </div>
                                </td>
                                <td>
                                    <div class="font-weight-bold">{{ row.concept_description }}</div>
                                    <div class="small">{{ row.description }}</div>
                                    <div v-if="row.state === 'voided' && row.void_reason" class="small text-danger">
                                        Anulado: {{ row.void_reason }}
                                    </div>
                                </td>
                                <td>
                                    <div>{{ row.operation_number || '—' }}</div>
                                    <div v-if="row.sunat_payment_code" class="small text-muted">SUNAT {{ row.sunat_payment_code }}</div>
                                </td>
                                <td class="text-right" :class="row.direction > 0 ? 'text-success' : 'text-danger'">
                                    <strong>{{ formatSignedAmount(row.signed_amount, row.currency_type_id) }}</strong>
                                    <div v-if="row.currency_type_id !== 'PEN'" class="small text-muted">
                                        S/ {{ row.amount_pen }} (TC {{ row.exchange_rate_used }})
                                    </div>
                                </td>
                                <td class="text-center">
                                    <span v-if="row.state === 'active'" class="badge badge-info">Activo</span>
                                    <span v-else class="badge badge-danger">Anulado</span>
                                </td>
                                <td class="text-right text-nowrap">
                                    <div class="tn-row-actions">
                                        <button
                                            v-if="row.state === 'active'"
                                            type="button"
                                            class="btn btn-xs btn-danger"
                                            @click.prevent="openVoidDialog(row)"
                                        >
                                            Anular
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-xs"
                                            :class="row.has_evidence ? 'btn-success' : 'btn-outline-secondary'"
                                            @click.prevent="openEvidence(row.id)"
                                        >
                                            {{ row.has_evidence ? 'Evidencia ✓' : 'Evidencia' }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-if="meta.last_page > 1" class="d-flex justify-content-end mt-2">
                        <el-pagination
                            layout="prev, pager, next"
                            :current-page="meta.current_page"
                            :page-size="meta.per_page"
                            :total="meta.total"
                            @current-change="getRecords"
                        />
                    </div>
                </div>
            </template>
        </div>

        <movement-form
            :show-dialog.sync="showCreateDialog"
            :tables="tables"
            @saved="getRecords(1)"
        />

        <payment-evidence-dialog
            :show-dialog.sync="showEvidenceDialog"
            payable-type="treasury_movement"
            :payable-id="selectedEvidenceId"
            @saved="getRecords(meta.current_page)"
        />

        <el-dialog
            title="Anular movimiento de tesorería"
            :visible.sync="showVoidDialog"
            width="480px"
        >
            <div v-if="voidTarget" class="mb-3">
                <p class="mb-2">
                    Está por anular el movimiento <strong>#{{ voidTarget.id }}</strong> ({{ voidTarget.kind_label }})
                    por <strong>{{ formatSignedAmount(voidTarget.signed_amount, voidTarget.currency_type_id) }}</strong>.
                </p>
                <div v-if="voidTarget.transfer_group" class="alert alert-warning py-2 px-3 small" role="alert">
                    Este movimiento pertenece a una transferencia. Al anularlo se anularán ambas patas y sus cargos asociados (ITF/comisiones).
                </div>
                <div v-if="voidGeneralError" class="alert alert-danger py-2 px-3 small" role="alert">
                    {{ voidGeneralError }}
                </div>
                <div class="form-group mb-0" :class="{ 'has-danger': voidErrors.void_reason }">
                    <label for="mov-void-reason" class="control-label">
                        Motivo de anulación <span class="text-danger">*</span>
                    </label>
                    <el-input
                        id="mov-void-reason"
                        v-model="voidReason"
                        type="textarea"
                        :rows="3"
                        maxlength="500"
                        placeholder="Indique el motivo de la anulación"
                    />
                    <small v-if="voidErrors.void_reason" class="form-control-feedback text-danger">
                        {{ voidErrors.void_reason[0] }}
                    </small>
                </div>
            </div>
            <span slot="footer" class="dialog-footer">
                <el-button :disabled="voiding" @click="showVoidDialog = false">Cancelar</el-button>
                <el-button type="danger" :loading="voiding" @click="confirmVoid">Confirmar anulación</el-button>
            </span>
        </el-dialog>
    </div>
</template>

<script>
import MovementForm from './form.vue'
import PaymentEvidenceDialog from '../partials/PaymentEvidenceDialog.vue'

export default {
    name: 'TreasuryMovementsIndex',
    components: {
        MovementForm,
        PaymentEvidenceDialog,
    },
    data() {
        return {
            loading: false,
            errorMessage: null,
            showCreateDialog: false,
            showEvidenceDialog: false,
            selectedEvidenceId: null,
            showVoidDialog: false,
            voidTarget: null,
            voidReason: '',
            voidErrors: {},
            voidGeneralError: null,
            voiding: false,
            tables: {
                establishments: [],
                bank_accounts: [],
                open_cashes: [],
                concepts: [],
                payment_method_types: [],
                currency_types: [],
                manual_kinds: [],
                all_kinds: [],
                sunat_payment_codes: [],
                bancarization_exceptions: [],
                default_establishment_id: null,
                allow_future_dates: false,
            },
            filters: {
                establishment_id: null,
                kind: null,
                state: null,
                date_start: null,
                date_end: null,
                search: '',
            },
            records: [],
            meta: {
                current_page: 1,
                last_page: 1,
                per_page: 20,
                total: 0,
            },
        }
    },
    created() {
        this.loadTables()
        this.getRecords(1)
    },
    methods: {
        async loadTables() {
            try {
                const res = await this.$http.get('/treasury/movements/tables')
                this.tables = Object.assign({}, this.tables, res.data || {})
            } catch (e) {
                // Handled on main records load.
            }
        },
        async getRecords(page = 1) {
            this.loading = true
            this.errorMessage = null

            try {
                const params = Object.assign({}, this.filters, { page })
                const res = await this.$http.get('/treasury/movements/records', { params })
                this.records = (res.data && res.data.data) ? res.data.data : []
                this.meta = (res.data && res.data.meta) ? res.data.meta : this.meta
            } catch (e) {
                this.errorMessage = 'No se pudieron cargar los movimientos de tesorería. Intente nuevamente.'
            } finally {
                this.loading = false
            }
        },
        openEvidence(movementId) {
            this.selectedEvidenceId = movementId
            this.showEvidenceDialog = true
        },
        openVoidDialog(row) {
            this.voidTarget = row
            this.voidReason = ''
            this.voidErrors = {}
            this.voidGeneralError = null
            this.showVoidDialog = true
        },
        async confirmVoid() {
            if (!this.voidTarget || this.voiding) {
                return
            }
            this.voiding = true
            this.voidErrors = {}
            this.voidGeneralError = null

            try {
                const res = await this.$http.post(`/treasury/movements/${this.voidTarget.id}/void`, {
                    void_reason: this.voidReason,
                })
                this.$message.success((res.data && res.data.message) || 'Movimiento anulado correctamente.')
                this.showVoidDialog = false
                this.getRecords(this.meta.current_page)
            } catch (error) {
                if (error.response && error.response.status === 422) {
                    const errs = error.response.data.errors || error.response.data || {}
                    this.voidErrors = errs
                    if (errs.movement && errs.movement[0]) {
                        this.voidGeneralError = errs.movement[0]
                    } else if (!errs.void_reason) {
                        const firstKey = Object.keys(errs)[0]
                        const firstMsg = firstKey && Array.isArray(errs[firstKey]) ? errs[firstKey][0] : error.response.data.message
                        this.voidGeneralError = firstMsg || 'No se pudo anular el movimiento.'
                        this.$message.error(this.voidGeneralError)
                    }
                } else if (error.response && error.response.data && error.response.data.message) {
                    this.voidGeneralError = error.response.data.message
                    this.$message.error(error.response.data.message)
                } else {
                    this.voidGeneralError = 'No se pudo anular el movimiento.'
                    this.$message.error(this.voidGeneralError)
                }
            } finally {
                this.voiding = false
            }
        },
        formatSignedAmount(rawAmount, currencyTypeId) {
            const value = parseFloat(rawAmount || 0)
            const symbol = currencyTypeId === 'USD' ? 'US$' : (currencyTypeId === 'PEN' ? 'S/' : (currencyTypeId || 'PEN'))
            const sign = value < 0 ? '- ' : '+ '
            const formatted = Math.abs(value).toLocaleString('es-PE', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            })
            return `${sign}${symbol} ${formatted}`
        },
    },
}
</script>
