<template>
    <div class="card mb-0 pt-2 pt-md-0">
        <div class="card-header bg-info d-flex align-items-center justify-content-between flex-wrap">
            <h3 class="my-0">Tesorería — Conciliación bancaria</h3>
            <div v-if="!selectedId">
                <a class="btn btn-custom btn-sm mr-2" href="/treasury/movements">Movimientos</a>
                <button type="button" class="btn btn-custom btn-sm" :disabled="tablesLoading || !tables.accounts.length" @click.prevent="showImport = true">
                    <i class="fa fa-upload"></i> Importar extracto
                </button>
            </div>
        </div>

        <div class="card-body">
            <statement-detail
                v-if="selectedId"
                :statement-id="selectedId"
                :tables="tables"
                @back="closeDetail"
                @changed="loadRecords"
            />

            <template v-else>
                <p class="text-muted small mb-3">
                    Importe el extracto del banco (CSV o Excel), empareje cada línea con los movimientos del sistema y cierre la conciliación
                    cuando la diferencia sea cero. Los movimientos conciliados no pueden anularse sin reabrir la conciliación.
                </p>

                <div v-if="tablesError" class="alert alert-danger d-flex justify-content-between align-items-center" role="alert">
                    <span>{{ tablesError }}</span>
                    <el-button size="mini" type="danger" plain @click="loadTables">Reintentar</el-button>
                </div>
                <div v-else-if="!tablesLoading && tables.accounts.length === 0" class="alert alert-warning small" role="alert">
                    No tiene cuentas bancarias activas disponibles para conciliar.
                </div>

                <form class="row mb-3" @submit.prevent="loadRecords(1)">
                    <div class="col-md-4 col-sm-6 mb-2">
                        <label for="rc-f-account" class="control-label">Cuenta bancaria</label>
                        <el-select id="rc-f-account" v-model="filters.bank_account_id" clearable filterable placeholder="Todas las cuentas" class="w-100" @change="loadRecords(1)">
                            <el-option v-for="acc in tables.accounts" :key="acc.id" :label="acc.description" :value="acc.id" />
                        </el-select>
                    </div>
                    <div class="col-md-2 col-sm-6 mb-2">
                        <label for="rc-f-status" class="control-label">Estado</label>
                        <el-select id="rc-f-status" v-model="filters.status" clearable placeholder="Todos" class="w-100" @change="loadRecords(1)">
                            <el-option label="En proceso" value="draft" />
                            <el-option label="Cerradas" value="closed" />
                        </el-select>
                    </div>
                    <div class="col-md-3 col-sm-6 mb-2">
                        <label for="rc-f-start" class="control-label">Período desde</label>
                        <el-date-picker id="rc-f-start" v-model="filters.date_start" type="date" value-format="yyyy-MM-dd" placeholder="AAAA-MM-DD" class="w-100" @change="loadRecords(1)" />
                    </div>
                    <div class="col-md-3 col-sm-6 mb-2">
                        <label for="rc-f-end" class="control-label">Hasta</label>
                        <el-date-picker id="rc-f-end" v-model="filters.date_end" type="date" value-format="yyyy-MM-dd" placeholder="AAAA-MM-DD" class="w-100" @change="loadRecords(1)" />
                    </div>
                </form>

                <div v-if="errorMessage" class="alert alert-danger d-flex justify-content-between align-items-center" role="alert">
                    <span>{{ errorMessage }}</span>
                    <el-button size="mini" type="danger" plain @click="loadRecords(meta.current_page)">Reintentar</el-button>
                </div>

                <div v-if="loading" class="text-center py-5 text-muted" role="status" aria-live="polite">
                    <i class="el-icon-loading mr-2"></i> Cargando extractos...
                </div>

                <template v-else-if="!errorMessage">
                    <div v-if="records.length === 0" class="alert alert-light border text-muted mb-0">
                        No hay extractos importados para los filtros seleccionados.
                    </div>

                    <div v-else class="table-responsive">
                        <table class="table table-sm table-hover mb-2">
                            <thead>
                                <tr>
                                    <th>Cuenta</th>
                                    <th>Período</th>
                                    <th class="text-right">Saldo inicial</th>
                                    <th class="text-right">Saldo final</th>
                                    <th>Líneas</th>
                                    <th class="text-center">Estado</th>
                                    <th class="text-right">Diferencia</th>
                                    <th class="text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in records" :key="row.id">
                                    <td>
                                        <div>{{ row.account_description }}</div>
                                        <div v-if="row.file_name" class="small text-muted">{{ row.file_name }}</div>
                                    </td>
                                    <td class="text-nowrap">{{ row.period_from }} al {{ row.period_to }}</td>
                                    <td class="text-right">{{ money(row.opening_balance, currencyOf(row)) }}</td>
                                    <td class="text-right">{{ money(row.closing_balance, currencyOf(row)) }}</td>
                                    <td>
                                        <div>{{ row.lines.matched + row.lines.ignored }} / {{ row.lines.total }} resueltas</div>
                                        <div v-if="row.lines.unmatched > 0" class="small text-muted">{{ row.lines.unmatched }} pendiente(s)</div>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge" :class="row.status === 'closed' ? 'badge-success' : 'badge-warning'">{{ row.status === 'closed' ? 'Cerrada' : 'En proceso' }}</span>
                                        <span v-if="row.stale" class="badge badge-danger ml-1" title="Hay cobros o pagos emparejados que fueron eliminados o modificados después de cerrar">Desactualizada</span>
                                        <span v-if="row.imported_unbalanced" class="badge badge-danger ml-1" title="Importado aceptando una diferencia con el saldo final del archivo">Con diferencia</span>
                                    </td>
                                    <td class="text-right">
                                        <span v-if="row.difference !== null">{{ signedMoney(row.difference, currencyOf(row)) }}</span>
                                        <span v-else class="text-muted">-</span>
                                    </td>
                                    <td class="text-right text-nowrap">
                                        <el-button size="mini" type="primary" @click="openDetail(row)">{{ row.status === 'closed' ? 'Ver' : 'Conciliar' }}</el-button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <div v-if="meta.last_page > 1" class="d-flex justify-content-end mt-2">
                            <el-pagination layout="prev, pager, next" :current-page="meta.current_page" :page-size="meta.per_page" :total="meta.total" @current-change="loadRecords" />
                        </div>
                    </div>
                </template>
            </template>
        </div>

        <statement-import-dialog
            :visible.sync="showImport"
            :tables="tables"
            @imported="onImported"
            @mappings-changed="loadTables"
        />
    </div>
</template>

<script>
import StatementDetail from './StatementDetail.vue'
import StatementImportDialog from './StatementImportDialog.vue'
import { money, parseError, signedMoney } from './helpers'

export default {
    name: 'TreasuryReconciliationIndex',
    components: { StatementDetail, StatementImportDialog },
    data() {
        return {
            tablesLoading: false,
            tablesError: null,
            tables: {
                accounts: [],
                establishments: [],
                concepts: [],
                movement_kinds: [],
                date_formats: [],
                mappings: [],
                is_admin: false,
                default_establishment_id: null,
                window_days: 3,
                max_file_kb: 5120,
            },
            filters: { bank_account_id: null, status: null, date_start: null, date_end: null },
            records: [],
            meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
            loading: false,
            errorMessage: null,
            requestSeq: 0,
            showImport: false,
            selectedId: null,
        }
    },
    created() {
        this.loadTables()
        this.loadRecords(1)
    },
    methods: {
        money,
        signedMoney,
        currencyOf(row) {
            const acc = this.tables.accounts.filter(a => a.id === row.bank_account_id)[0]
            return acc ? acc.currency_type_id : 'PEN'
        },
        async loadTables() {
            this.tablesLoading = true
            this.tablesError = null
            try {
                const res = await this.$http.get('/treasury/reconciliation/tables')
                this.tables = Object.assign({}, this.tables, res.data || {})
            } catch (error) {
                this.tablesError = parseError(error).general || 'No se pudieron cargar las cuentas.'
            } finally {
                this.tablesLoading = false
            }
        },
        // `silent === true` on purpose: a click handler would pass a MouseEvent as the first argument.
        async loadRecords(page = 1, silent = false) {
            const seq = ++this.requestSeq
            if (silent !== true) {
                this.loading = true
            }
            this.errorMessage = null
            try {
                const params = Object.assign({}, this.filters, { page: typeof page === 'number' ? page : 1 })
                Object.keys(params).forEach(key => {
                    if (params[key] === null || params[key] === '') {
                        delete params[key]
                    }
                })
                const res = await this.$http.get('/treasury/reconciliation/statements', { params })
                if (seq !== this.requestSeq) {
                    return
                }
                this.records = res.data.data.rows
                this.meta = res.data.data.meta
            } catch (error) {
                if (seq === this.requestSeq) {
                    this.errorMessage = parseError(error).general || 'No se pudieron cargar los extractos. Intente nuevamente.'
                }
            } finally {
                if (seq === this.requestSeq) {
                    this.loading = false
                }
            }
        },
        openDetail(row) {
            this.selectedId = row.id
        },
        closeDetail() {
            this.selectedId = null
            this.loadRecords(this.meta.current_page)
        },
        onImported(id) {
            this.loadRecords(1)
            this.selectedId = id
        },
    },
}
</script>
