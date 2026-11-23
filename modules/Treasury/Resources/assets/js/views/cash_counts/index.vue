<template>
    <div class="card mb-0 pt-2 pt-md-0">
        <div class="card-header bg-info d-flex align-items-center justify-content-between flex-wrap">
            <h3 class="my-0">Tesorería — Arqueo de caja</h3>
            <div>
                <el-button size="small" :loading="loading" @click="loadRecords(meta.current_page)">
                    <i class="fa fa-refresh"></i> Actualizar
                </el-button>
                <button
                    type="button"
                    class="btn btn-custom btn-sm ml-2"
                    :disabled="tables.cashes.length === 0"
                    @click.prevent="showForm = true"
                >
                    <i class="fa fa-plus"></i> Nuevo arqueo
                </button>
            </div>
        </div>

        <div class="card-body">
            <p class="text-muted small mb-3">
                Registre el conteo físico de billetes y monedas por denominación, compárelo contra el saldo del sistema y descargue el acta en PDF.
            </p>

            <div v-if="tablesError" class="alert alert-danger d-flex justify-content-between align-items-center" role="alert">
                <span>{{ tablesError }}</span>
                <el-button size="mini" type="danger" plain @click="loadTables">Reintentar</el-button>
            </div>
            <div v-else-if="!tablesLoading && tables.cashes.length === 0" class="alert alert-warning small" role="alert">
                No tiene cajas POS disponibles para registrar arqueos.
            </div>

            <!-- Filters -->
            <form class="row mb-3" @submit.prevent="loadRecords(1)">
                <div class="col-md-3 col-sm-6 mb-2">
                    <label for="cc-f-cash" class="control-label">Caja</label>
                    <el-select
                        id="cc-f-cash"
                        v-model="filters.cash_id"
                        clearable
                        filterable
                        placeholder="Todas las cajas"
                        class="w-100"
                        @change="loadRecords(1)"
                    >
                        <el-option
                            v-for="c in tables.cashes"
                            :key="c.id"
                            :label="c.description"
                            :value="c.id"
                        />
                    </el-select>
                </div>

                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="cc-f-status" class="control-label">Resultado</label>
                    <el-select
                        id="cc-f-status"
                        v-model="filters.status"
                        clearable
                        placeholder="Todos"
                        class="w-100"
                        @change="loadRecords(1)"
                    >
                        <el-option label="Cuadrado" value="balanced" />
                        <el-option label="Sobrante" value="surplus" />
                        <el-option label="Faltante" value="shortage" />
                    </el-select>
                </div>

                <div v-if="tables.is_admin && tables.establishments.length > 0" class="col-md-3 col-sm-6 mb-2">
                    <label for="cc-f-est" class="control-label">Sede</label>
                    <el-select
                        id="cc-f-est"
                        v-model="filters.establishment_id"
                        clearable
                        placeholder="Todas las sedes"
                        class="w-100"
                        @change="loadRecords(1)"
                    >
                        <el-option
                            v-for="est in tables.establishments"
                            :key="est.id"
                            :label="est.description"
                            :value="est.id"
                        />
                    </el-select>
                </div>

                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="cc-f-start" class="control-label">Desde</label>
                    <el-date-picker
                        id="cc-f-start"
                        v-model="filters.date_start"
                        type="date"
                        value-format="yyyy-MM-dd"
                        placeholder="AAAA-MM-DD"
                        class="w-100"
                        @change="loadRecords(1)"
                    />
                </div>

                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="cc-f-end" class="control-label">Hasta</label>
                    <el-date-picker
                        id="cc-f-end"
                        v-model="filters.date_end"
                        type="date"
                        value-format="yyyy-MM-dd"
                        placeholder="AAAA-MM-DD"
                        class="w-100"
                        @change="loadRecords(1)"
                    />
                </div>
            </form>

            <!-- Error state -->
            <div v-if="errorMessage" class="alert alert-danger d-flex justify-content-between align-items-center" role="alert">
                <span>{{ errorMessage }}</span>
                <el-button size="mini" type="danger" plain @click="loadRecords(meta.current_page)">Reintentar</el-button>
            </div>

            <!-- Loading state -->
            <div v-if="loading" class="text-center py-5 text-muted" role="status" aria-live="polite">
                <i class="el-icon-loading mr-2"></i> Cargando arqueos de caja...
            </div>

            <template v-else-if="!errorMessage">
                <div v-if="records.length === 0" class="alert alert-light border text-muted mb-0">
                    No se encontraron arqueos de caja para los filtros seleccionados.
                </div>

                <div v-else class="table-responsive">
                    <table class="table table-sm table-hover mb-2">
                        <thead>
                            <tr>
                                <th>Fecha y hora</th>
                                <th>Caja</th>
                                <th>Sede</th>
                                <th>Responsable</th>
                                <th class="text-right">Saldo sistema</th>
                                <th class="text-right">Saldo contado</th>
                                <th class="text-right">Diferencia</th>
                                <th class="text-center">Estado</th>
                                <th class="text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in records" :key="row.id">
                                <td class="text-nowrap">{{ row.counted_at }}</td>
                                <td>
                                    <div>{{ row.cash_description }}</div>
                                    <div v-if="row.notes" class="small text-muted">{{ row.notes }}</div>
                                </td>
                                <td>{{ row.establishment_description }}</td>
                                <td>{{ row.user_name }}</td>
                                <td class="text-right text-nowrap">{{ money(row.system_balance, 'PEN') }}</td>
                                <td class="text-right text-nowrap">{{ money(row.counted_balance, 'PEN') }}</td>
                                <td class="text-right text-nowrap" :class="amountClass(row.difference)">
                                    {{ signedMoney(row.difference, 'PEN') }}
                                </td>
                                <td class="text-center">
                                    <span class="badge" :class="statusBadgeClass(row.status)">
                                        {{ row.status_label }}
                                    </span>
                                </td>
                                <td class="text-right text-nowrap">
                                    <a
                                        class="btn btn-xs btn-outline-primary"
                                        :href="'/treasury/cash-counts/' + row.id + '/pdf'"
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        <i class="fa fa-file-pdf"></i> PDF
                                    </a>
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
                            @current-change="loadRecords"
                        />
                    </div>
                </div>
            </template>
        </div>

        <cash-count-form
            :visible.sync="showForm"
            :cashes="tables.cashes"
            :default-denominations="tables.default_denominations"
            @saved="onSaved"
        />
    </div>
</template>

<script>
import CashCountForm from './form.vue'
import { amountClass, money, parseError, signedMoney } from '../reconciliation/helpers'

export default {
    name: 'TreasuryCashCountsIndex',
    components: { CashCountForm },
    data() {
        return {
            tablesLoading: false,
            tablesError: null,
            tables: {
                establishments: [],
                cashes: [],
                default_denominations: [],
                is_admin: true,
                default_establishment_id: null,
            },
            filters: {
                cash_id: null,
                establishment_id: null,
                status: null,
                date_start: null,
                date_end: null,
            },
            loading: false,
            errorMessage: null,
            records: [],
            meta: {
                current_page: 1,
                per_page: 20,
                total: 0,
                last_page: 1,
            },
            requestSeq: 0,
            showForm: false,
        }
    },
    created() {
        this.loadTables()
    },
    methods: {
        money,
        signedMoney,
        amountClass,
        statusBadgeClass(status) {
            if (status === 'balanced') return 'badge-success'
            if (status === 'surplus') return 'badge-info'
            return 'badge-danger'
        },
        async loadTables() {
            this.tablesLoading = true
            this.tablesError = null
            try {
                const { data } = await this.$http.get('/treasury/cash-counts/tables')
                this.tables = Object.assign({}, this.tables, data)
            } catch (error) {
                this.tablesError = parseError(error).general
            } finally {
                this.tablesLoading = false
            }
            this.loadRecords(1)
        },
        async loadRecords(page = 1) {
            const seq = ++this.requestSeq
            this.loading = true
            this.errorMessage = null
            try {
                const params = { page, per_page: this.meta.per_page }
                if (this.filters.cash_id) params.cash_id = this.filters.cash_id
                if (this.filters.establishment_id) params.establishment_id = this.filters.establishment_id
                if (this.filters.status) params.status = this.filters.status
                if (this.filters.date_start) params.date_start = this.filters.date_start
                if (this.filters.date_end) params.date_end = this.filters.date_end

                const { data } = await this.$http.get('/treasury/cash-counts/records', { params })
                if (seq !== this.requestSeq) return
                this.records = (data.data && data.data.rows) || []
                this.meta = (data.data && data.data.meta) || this.meta
            } catch (error) {
                if (seq !== this.requestSeq) return
                this.errorMessage = parseError(error).general
            } finally {
                if (seq === this.requestSeq) {
                    this.loading = false
                }
            }
        },
        onSaved() {
            this.loadRecords(1)
        },
    },
}
</script>
