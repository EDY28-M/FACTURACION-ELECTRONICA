<template>
    <div>
        <div class="page-header pr-0">
            <h2><a href="/dashboard"><i class="fas fa-tachometer-alt"></i></a></h2>
            <ol class="breadcrumbs">
                <li class="active"><span>Contratos</span></li>
                <li><span class="text-muted">Facturación recurrente</span></li>
            </ol>
            <div class="right-wrapper pull-right">
                <a href="/contracts" class="btn btn-custom btn-sm mt-2 mr-2"><i class="fa fa-list"></i> Contratos</a>
            </div>
        </div>

        <div class="card mb-0">
            <div class="card-body">
                <el-tabs v-model="activeTab" @tab-click="onTabClick">
                    <el-tab-pane label="Pendientes" name="pending">
                        <div class="d-flex flex-wrap align-items-center mb-3">
                            <span class="mr-2">Pendientes al</span>
                            <el-date-picker v-model="date" type="date" value-format="yyyy-MM-dd" :clearable="false" size="small" style="width:150px" @change="loadPending"></el-date-picker>
                            <el-button class="ml-auto" type="primary" size="small" :disabled="selection.length === 0 || issuing"
                                       :loading="issuing" @click="issueSelected">
                                Emitir seleccionados ({{ selection.length }})
                            </el-button>
                        </div>

                        <el-progress v-if="progress" class="mb-3" :percentage="Math.round(progress.done / progress.total * 100)"
                                     :format="() => `${progress.done} de ${progress.total}`"></el-progress>
                        <el-alert v-for="(message, index) in failures" :key="index" class="mb-2" type="error" :closable="false" show-icon :title="message"></el-alert>

                        <el-table :data="pending" v-loading="loadingPending" @selection-change="selection = $event" empty-text="No hay contratos pendientes de facturar">
                            <el-table-column type="selection" width="45"></el-table-column>
                            <el-table-column label="Contrato" min-width="110">
                                <template slot-scope="{ row }">
                                    <a :href="`/contracts/create/${row.contract_id}`">{{ row.number_full }}</a>
                                </template>
                            </el-table-column>
                            <el-table-column label="Cliente" min-width="200">
                                <template slot-scope="{ row }">
                                    {{ row.customer_name }}<br><small class="text-muted">{{ row.customer_number }}</small>
                                </template>
                            </el-table-column>
                            <el-table-column label="Periodo" width="100">
                                <template slot-scope="{ row }">{{ row.period_label }}</template>
                            </el-table-column>
                            <el-table-column label="Fecha" width="110" prop="period_date"></el-table-column>
                            <el-table-column label="Comprobante" width="110">
                                <template slot-scope="{ row }">{{ row.document_type_id === '03' ? 'Boleta' : 'Factura' }}</template>
                            </el-table-column>
                            <el-table-column label="Modo" width="110">
                                <template slot-scope="{ row }">
                                    <el-tag size="mini" :type="row.mode === 'automatic' ? 'success' : 'info'">{{ row.mode === 'automatic' ? 'Automática' : 'Manual' }}</el-tag>
                                </template>
                            </el-table-column>
                            <el-table-column label="Monto" width="120" align="right">
                                <template slot-scope="{ row }">{{ row.currency_type_id }} {{ row.total }}</template>
                            </el-table-column>
                            <el-table-column label="Último error" min-width="220">
                                <template slot-scope="{ row }"><span class="text-danger" v-if="row.last_error">{{ row.last_error }}</span>
                                    <small class="d-block text-muted" v-if="row.auto_paused">Reintentos automáticos detenidos tras {{ row.attempts }} intentos. Emita manualmente.</small></template>
                            </el-table-column>
                            <el-table-column label="" width="100" align="right">
                                <template slot-scope="{ row }">
                                    <el-button size="mini" type="primary" :disabled="issuing" @click="issueRows([row])">Emitir</el-button>
                                </template>
                            </el-table-column>
                        </el-table>
                    </el-tab-pane>

                    <el-tab-pane label="Historial" name="history">
                        <div class="d-flex flex-wrap align-items-center mb-3">
                            <el-select v-model="statusFilter" clearable placeholder="Todos los estados" size="small" style="width:180px" @change="onFilterChange">
                                <el-option value="issued" label="Emitidos"></el-option>
                                <el-option value="failed" label="Con error"></el-option>
                            </el-select>
                        </div>

                        <el-table :data="history" v-loading="loadingHistory" empty-text="Aún no hay facturaciones registradas">
                            <el-table-column label="Periodo" width="110" prop="period_date"></el-table-column>
                            <el-table-column label="Contrato" min-width="110">
                                <template slot-scope="{ row }">{{ row.contract_number }}</template>
                            </el-table-column>
                            <el-table-column label="Cliente" min-width="200" prop="customer_name"></el-table-column>
                            <el-table-column label="Estado" width="110">
                                <template slot-scope="{ row }">
                                    <el-tag size="mini" :type="row.status === 'issued' ? 'success' : 'danger'">{{ row.status === 'issued' ? 'Emitido' : 'Con error' }}</el-tag>
                                </template>
                            </el-table-column>
                            <el-table-column label="Modo" width="110">
                                <template slot-scope="{ row }">{{ row.mode === 'automatic' ? 'Automática' : 'Manual' }}</template>
                            </el-table-column>
                            <el-table-column label="Comprobante" min-width="150">
                                <template slot-scope="{ row }">
                                    <template v-if="row.document_number">
                                        <a v-if="row.document_pdf" :href="row.document_pdf" target="_blank">{{ row.document_number }}</a>
                                        <span v-else>{{ row.document_number }}</span>
                                    </template>
                                </template>
                            </el-table-column>
                            <el-table-column label="Monto" width="120" align="right">
                                <template slot-scope="{ row }"><span v-if="row.total">{{ row.currency_type_id }} {{ row.total }}</span></template>
                            </el-table-column>
                            <el-table-column label="Error" min-width="220">
                                <template slot-scope="{ row }"><span class="text-danger" v-if="row.error">{{ row.error }}</span></template>
                            </el-table-column>
                            <el-table-column label="" width="110" align="right">
                                <template slot-scope="{ row }">
                                    <el-button v-if="row.status === 'failed'" size="mini" :disabled="issuing" @click="issueRows([row])">Reintentar</el-button>
                                </template>
                            </el-table-column>
                        </el-table>

                        <el-pagination class="mt-3" layout="prev, pager, next" :page-size="pagination.per_page" :total="pagination.total"
                                       :current-page.sync="pagination.current_page" @current-change="loadHistory"></el-pagination>
                    </el-tab-pane>
                </el-tabs>
            </div>
        </div>
    </div>
</template>

<script>
    export default {
        data() {
            return {
                activeTab: 'pending',
                date: moment().format('YYYY-MM-DD'),
                pending: [],
                selection: [],
                loadingPending: false,
                issuing: false,
                progress: null,
                failures: [],
                history: [],
                loadingHistory: false,
                statusFilter: null,
                pagination: {current_page: 1, per_page: 20, total: 0},
            }
        },
        created() {
            this.loadPending()
        },
        methods: {
            onTabClick() {
                if (this.activeTab === 'history') this.loadHistory()
                else this.loadPending()
            },
            async loadPending() {
                this.loadingPending = true
                try {
                    const response = await this.$http.get('/contracts/recurrent/pending', {params: {date: this.date}})
                    this.pending = response.data.data
                } catch (error) {
                    this.$message.error('No se pudo cargar la lista de pendientes')
                } finally {
                    this.loadingPending = false
                }
            },
            onFilterChange() {
                this.pagination.current_page = 1
                this.loadHistory()
            },
            async loadHistory() {
                this.loadingHistory = true
                try {
                    const response = await this.$http.get('/contracts/recurrent/history', {
                        params: {page: this.pagination.current_page, status: this.statusFilter || undefined}
                    })
                    this.history = response.data.data
                    this.pagination = {
                        current_page: response.data.current_page,
                        per_page: response.data.per_page,
                        total: response.data.total,
                    }
                } catch (error) {
                    this.$message.error('No se pudo cargar el historial')
                } finally {
                    this.loadingHistory = false
                }
            },
            issueSelected() {
                this.issueRows(this.selection)
            },
            async issueRows(rows) {
                if (!rows.length) return
                try {
                    await this.$confirm(`Se emitirán ${rows.length} comprobante(s). ¿Desea continuar?`, 'Emitir facturación', {
                        confirmButtonText: 'Emitir', cancelButtonText: 'Cancelar', type: 'warning'
                    })
                } catch (cancel) {
                    return
                }

                // One contract per request, sequentially, so progress is visible and a slow SUNAT call never blocks the rest.
                this.issuing = true
                this.failures = []
                this.progress = {done: 0, total: rows.length}
                const totals = {issued: 0, failed: 0, skipped: 0}

                for (const row of rows) {
                    try {
                        const response = await this.$http.post('/contracts/recurrent/issue', {
                            items: [{contract_id: row.contract_id, period_date: row.period_date}]
                        })
                        const result = response.data.results[0]
                        totals[result.status] += 1
                        if (result.status !== 'issued') {
                            this.failures.push(`${row.number_full || 'Contrato ' + row.contract_id} (${row.period_date}): ${result.message}`)
                        }
                    } catch (error) {
                        const message = error.response && error.response.data && error.response.data.message
                        totals.failed += 1
                        this.failures.push(`${row.number_full || 'Contrato ' + row.contract_id} (${row.period_date}): ${message || 'No se pudo emitir'}`)
                    }
                    this.progress.done += 1
                }

                this.$message({
                    type: totals.failed ? 'warning' : 'success',
                    message: `Emitidos: ${totals.issued}. Con error: ${totals.failed}. Omitidos: ${totals.skipped}.`,
                })
                this.issuing = false
                this.progress = null
                this.selection = []
                this.loadPending()
                if (this.activeTab === 'history') this.loadHistory()
            },
        },
    }
</script>
