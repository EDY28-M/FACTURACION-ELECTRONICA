<template>
    <div class="card mb-0 pt-2 pt-md-0">
        <div class="card-header bg-info d-flex align-items-center justify-content-between flex-wrap">
            <h3 class="my-0">Tesorería — Transferencias</h3>
            <div>
                <a class="btn btn-custom btn-sm mr-2" href="/treasury/movements">
                    Ver movimientos
                </a>
                <button type="button" class="btn btn-custom btn-sm" @click.prevent="showCreateDialog = true">
                    <i class="fa fa-exchange"></i> Nueva transferencia
                </button>
            </div>
        </div>

        <div class="card-body">
            <!-- Filters -->
            <form class="row mb-3" @submit.prevent="getRecords(1)">
                <div class="col-md-3 col-sm-6 mb-2">
                    <label for="trf-filter-est" class="control-label">Sede</label>
                    <el-select
                        id="trf-filter-est"
                        v-model="filters.establishment_id"
                        clearable
                        placeholder="Todas las sedes"
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
                    <label for="trf-filter-state" class="control-label">Estado</label>
                    <el-select
                        id="trf-filter-state"
                        v-model="filters.state"
                        clearable
                        placeholder="Todos"
                        class="w-100"
                        @change="getRecords(1)"
                    >
                        <el-option label="Activas" value="active" />
                        <el-option label="Anuladas" value="voided" />
                    </el-select>
                </div>

                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="trf-filter-start" class="control-label">Desde</label>
                    <el-date-picker
                        id="trf-filter-start"
                        v-model="filters.date_start"
                        type="date"
                        value-format="yyyy-MM-dd"
                        placeholder="YYYY-MM-DD"
                        class="w-100"
                        @change="getRecords(1)"
                    />
                </div>

                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="trf-filter-end" class="control-label">Hasta</label>
                    <el-date-picker
                        id="trf-filter-end"
                        v-model="filters.date_end"
                        type="date"
                        value-format="yyyy-MM-dd"
                        placeholder="YYYY-MM-DD"
                        class="w-100"
                        @change="getRecords(1)"
                    />
                </div>

                <div class="col-md-3 col-sm-6 mb-2">
                    <label for="trf-filter-search" class="control-label">Buscar</label>
                    <el-input
                        id="trf-filter-search"
                        v-model="filters.search"
                        clearable
                        placeholder="Descripción o N° operación"
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
                <i class="el-icon-loading mr-2"></i> Cargando transferencias...
            </div>

            <template v-else-if="!errorMessage">
                <div v-if="records.length === 0" class="alert alert-light border text-muted mb-0">
                    Aún no hay transferencias registradas para los filtros seleccionados.
                </div>

                <div v-else class="table-responsive">
                    <table class="table table-sm table-hover mb-2">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Origen (Salida)</th>
                                <th>Destino (Entrada)</th>
                                <th>Descripción / N° Op.</th>
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
                                    <div>{{ row.origin_label }}</div>
                                    <div class="small text-danger">- {{ formatCurrency(row.origin_amount, row.origin_currency) }}</div>
                                </td>
                                <td>
                                    <div>{{ row.target_label }}</div>
                                    <div class="small text-success">+ {{ formatCurrency(row.target_amount, row.target_currency) }}</div>
                                </td>
                                <td>
                                    <div>{{ row.description }}</div>
                                    <div class="small text-muted">
                                        <span v-if="row.operation_number">Op: {{ row.operation_number }}</span>
                                        <span v-if="row.is_cross_currency" class="ml-2">TC: {{ row.exchange_rate_used }}</span>
                                        <span v-if="row.itf_amount" class="ml-2">ITF: {{ formatCurrency(row.itf_amount, row.itf_currency || row.origin_currency) }}</span>
                                        <span v-if="row.bank_fee_amount" class="ml-2">Comisión: {{ formatCurrency(row.bank_fee_amount, row.bank_fee_currency || row.origin_currency) }}</span>
                                    </div>
                                    <div v-if="row.state === 'voided' && row.void_reason" class="small text-danger">
                                        Anulada: {{ row.void_reason }}
                                    </div>
                                </td>
                                <td class="text-right">
                                    <strong>{{ formatCurrency(row.origin_amount, row.origin_currency) }}</strong>
                                    <div v-if="row.is_cross_currency" class="small text-muted">
                                        → {{ formatCurrency(row.target_amount, row.target_currency) }}
                                    </div>
                                </td>
                                <td class="text-center">
                                    <span v-if="row.state === 'active'" class="badge badge-info">Activa</span>
                                    <span v-else class="badge badge-danger">Anulada</span>
                                </td>
                                <td class="text-right text-nowrap">
                                    <button
                                        type="button"
                                        class="btn btn-xs mr-1"
                                        :class="row.has_evidence ? 'btn-success' : 'btn-outline-secondary'"
                                        @click.prevent="openEvidence(row.id)"
                                    >
                                        {{ row.has_evidence ? 'Evidencia ✓' : 'Evidencia' }}
                                    </button>
                                    <button
                                        v-if="row.state === 'active'"
                                        type="button"
                                        class="btn btn-xs btn-danger"
                                        @click.prevent="openVoidDialog(row)"
                                    >
                                        Anular
                                    </button>
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

        <transfer-form
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
            title="Anular transferencia completa"
            :visible.sync="showVoidDialog"
            width="480px"
        >
            <div v-if="voidTarget" class="mb-3">
                <p class="mb-2">
                    Se anularán ambas patas de la transferencia (<strong>{{ voidTarget.origin_label }}</strong> →
                    <strong>{{ voidTarget.target_label }}</strong>) y los cargos asociados (ITF y comisiones).
                </p>
                <div v-if="voidGeneralError" class="alert alert-danger py-2 px-3 small" role="alert">
                    {{ voidGeneralError }}
                </div>
                <div class="form-group mb-0" :class="{ 'has-danger': voidErrors.void_reason }">
                    <label for="trf-void-reason" class="control-label">
                        Motivo de anulación <span class="text-danger">*</span>
                    </label>
                    <el-input
                        id="trf-void-reason"
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
import TransferForm from './form.vue'
import PaymentEvidenceDialog from '../partials/PaymentEvidenceDialog.vue'

export default {
    name: 'TreasuryTransfersIndex',
    components: {
        TransferForm,
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
                sunat_payment_codes: [],
                default_establishment_id: null,
                allow_future_dates: false,
            },
            filters: {
                establishment_id: null,
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
                const res = await this.$http.get('/treasury/transfers/tables')
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
                const res = await this.$http.get('/treasury/transfers/records', { params })
                this.records = (res.data && res.data.data) ? res.data.data : []
                this.meta = (res.data && res.data.meta) ? res.data.meta : this.meta
            } catch (e) {
                this.errorMessage = 'No se pudieron cargar las transferencias. Intente nuevamente.'
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
                const res = await this.$http.post(`/treasury/transfers/${this.voidTarget.id}/void`, {
                    void_reason: this.voidReason,
                })
                this.$message.success((res.data && res.data.message) || 'Transferencia anulada correctamente.')
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
                        this.voidGeneralError = firstMsg || 'No se pudo anular la transferencia.'
                        this.$message.error(this.voidGeneralError)
                    }
                } else if (error.response && error.response.data && error.response.data.message) {
                    this.voidGeneralError = error.response.data.message
                    this.$message.error(error.response.data.message)
                } else {
                    this.voidGeneralError = 'No se pudo anular la transferencia.'
                    this.$message.error(this.voidGeneralError)
                }
            } finally {
                this.voiding = false
            }
        },
        formatCurrency(rawAmount, currencyTypeId) {
            const value = parseFloat(rawAmount || 0)
            const symbol = currencyTypeId === 'USD' ? 'US$' : (currencyTypeId === 'PEN' ? 'S/' : (currencyTypeId || 'PEN'))
            return `${symbol} ${value.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
        },
    },
}
</script>
