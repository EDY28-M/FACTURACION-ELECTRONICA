<template>
    <el-dialog
        title="Importar extracto bancario"
        :visible="visible"
        width="880px"
        append-to-body
        :close-on-click-modal="false"
        @update:visible="val => $emit('update:visible', val)"
        @close="reset"
    >
        <form @submit.prevent="submit">
            <div v-if="general" class="alert alert-danger py-2 px-3 small" role="alert">{{ general }}</div>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group" :class="{ 'has-danger': hasError('bank_account_id') }">
                        <label for="rc-imp-account" class="control-label">Cuenta bancaria <span class="text-danger">*</span></label>
                        <el-select id="rc-imp-account" v-model="form.bank_account_id" filterable placeholder="Seleccione una cuenta" class="w-100" @change="onAccountChange">
                            <el-option v-for="acc in tables.accounts" :key="acc.id" :label="acc.description" :value="acc.id" />
                        </el-select>
                        <small v-if="hasError('bank_account_id')" class="form-control-feedback text-danger">{{ firstError('bank_account_id') }}</small>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group" :class="{ 'has-danger': hasError('file') }">
                        <label for="rc-imp-file" class="control-label">Archivo (CSV, XLS o XLSX) <span class="text-danger">*</span></label>
                        <input id="rc-imp-file" ref="file" type="file" class="form-control-file" accept=".csv,.txt,.xls,.xlsx" @change="onFile">
                        <small class="text-muted d-block">Tamaño máximo: {{ Math.round((tables.max_file_kb || 5120) / 1024) }} MB.</small>
                        <small v-for="(msg, i) in fieldErrors('file')" :key="'f' + i" class="form-control-feedback text-danger d-block">{{ msg }}</small>
                    </div>
                </div>
            </div>

            <div class="border rounded p-3 mb-3 bg-light">
                <div class="d-flex justify-content-between align-items-center flex-wrap mb-2">
                    <div class="font-weight-bold">Mapeo de columnas</div>
                    <div class="d-flex align-items-center">
                        <label for="rc-imp-mapping" class="mb-0 mr-2 small">Mapeo guardado</label>
                        <el-select id="rc-imp-mapping" v-model="selectedMappingId" clearable placeholder="Ninguno" size="small" style="width: 220px" @change="applyMapping">
                            <el-option v-for="m in availableMappings" :key="m.id" :label="m.name" :value="m.id" />
                        </el-select>
                    </div>
                </div>
                <p class="small text-muted mb-2">Indique el número de columna (1 = primera) donde está cada dato en el archivo.</p>

                <div class="row">
                    <div class="col-md-3 col-6">
                        <div class="form-group mb-2" :class="{ 'has-danger': hasError('mapping.header_row') }">
                            <label for="rc-map-header" class="control-label small">Fila del encabezado</label>
                            <el-input-number id="rc-map-header" v-model="mapping.header_row" :min="0" :max="500" size="small" controls-position="right" class="w-100" />
                            <small class="text-muted">0 = sin encabezado</small>
                            <small v-if="hasError('mapping.header_row')" class="form-control-feedback text-danger d-block">{{ firstError('mapping.header_row') }}</small>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="form-group mb-2" :class="{ 'has-danger': hasError('mapping.date_column') }">
                            <label for="rc-map-date" class="control-label small">Columna de fecha <span class="text-danger">*</span></label>
                            <el-input-number id="rc-map-date" v-model="mapping.date_column" :min="1" :max="200" size="small" controls-position="right" class="w-100" />
                            <small v-if="hasError('mapping.date_column')" class="form-control-feedback text-danger d-block">{{ firstError('mapping.date_column') }}</small>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="form-group mb-2" :class="{ 'has-danger': hasError('mapping.description_column') }">
                            <label for="rc-map-desc" class="control-label small">Columna de descripción <span class="text-danger">*</span></label>
                            <el-input-number id="rc-map-desc" v-model="mapping.description_column" :min="1" :max="200" size="small" controls-position="right" class="w-100" />
                            <small v-if="hasError('mapping.description_column')" class="form-control-feedback text-danger d-block">{{ firstError('mapping.description_column') }}</small>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="form-group mb-2" :class="{ 'has-danger': hasError('mapping.reference_column') }">
                            <label for="rc-map-ref" class="control-label small">Columna de referencia</label>
                            <el-input-number id="rc-map-ref" v-model="mapping.reference_column" :min="1" :max="200" size="small" controls-position="right" class="w-100" />
                            <small v-if="hasError('mapping.reference_column')" class="form-control-feedback text-danger d-block">{{ firstError('mapping.reference_column') }}</small>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group mb-2">
                            <label for="rc-map-mode" class="control-label small">Importes</label>
                            <el-select id="rc-map-mode" v-model="mapping.amount_mode" size="small" class="w-100">
                                <el-option label="Una columna con signo (+ abono / - cargo)" value="signed" />
                                <el-option label="Columnas separadas de cargo y abono" value="debit_credit" />
                            </el-select>
                        </div>
                    </div>
                    <template v-if="mapping.amount_mode === 'signed'">
                        <div class="col-md-4 col-6">
                            <div class="form-group mb-2" :class="{ 'has-danger': hasError('mapping.amount_column') }">
                                <label for="rc-map-amount" class="control-label small">Columna de importe <span class="text-danger">*</span></label>
                                <el-input-number id="rc-map-amount" v-model="mapping.amount_column" :min="1" :max="200" size="small" controls-position="right" class="w-100" />
                                <small v-if="hasError('mapping.amount_column')" class="form-control-feedback text-danger d-block">{{ firstError('mapping.amount_column') }}</small>
                            </div>
                        </div>
                        <div class="col-md-4 col-6 d-flex align-items-center">
                            <el-checkbox v-model="mapping.invert_sign">Los cargos vienen como positivos (invertir signo)</el-checkbox>
                        </div>
                    </template>
                    <template v-else>
                        <div class="col-md-4 col-6">
                            <div class="form-group mb-2" :class="{ 'has-danger': hasError('mapping.debit_column') }">
                                <label for="rc-map-debit" class="control-label small">Columna de cargo <span class="text-danger">*</span></label>
                                <el-input-number id="rc-map-debit" v-model="mapping.debit_column" :min="1" :max="200" size="small" controls-position="right" class="w-100" />
                                <small v-if="hasError('mapping.debit_column')" class="form-control-feedback text-danger d-block">{{ firstError('mapping.debit_column') }}</small>
                            </div>
                        </div>
                        <div class="col-md-4 col-6">
                            <div class="form-group mb-2" :class="{ 'has-danger': hasError('mapping.credit_column') }">
                                <label for="rc-map-credit" class="control-label small">Columna de abono <span class="text-danger">*</span></label>
                                <el-input-number id="rc-map-credit" v-model="mapping.credit_column" :min="1" :max="200" size="small" controls-position="right" class="w-100" />
                                <small v-if="hasError('mapping.credit_column')" class="form-control-feedback text-danger d-block">{{ firstError('mapping.credit_column') }}</small>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group mb-2" :class="{ 'has-danger': hasError('mapping.date_format') }">
                            <label for="rc-map-dformat" class="control-label small">Formato de fecha</label>
                            <el-select id="rc-map-dformat" v-model="mapping.date_format" size="small" class="w-100">
                                <el-option v-for="f in tables.date_formats" :key="f.value" :label="f.label" :value="f.value" />
                            </el-select>
                            <small v-if="hasError('mapping.date_format')" class="form-control-feedback text-danger d-block">{{ firstError('mapping.date_format') }}</small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group mb-2">
                            <label for="rc-map-decimal" class="control-label small">Separador decimal</label>
                            <el-select id="rc-map-decimal" v-model="mapping.decimal_separator" size="small" class="w-100">
                                <el-option label="Punto (1,250.50)" value="." />
                                <el-option label="Coma (1.250,50)" value="," />
                            </el-select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group mb-2">
                            <label for="rc-map-delim" class="control-label small">Delimitador del CSV</label>
                            <el-select id="rc-map-delim" v-model="mapping.delimiter" clearable placeholder="Automático" size="small" class="w-100">
                                <el-option label="Coma (,)" value="," />
                                <el-option label="Punto y coma (;)" value=";" />
                                <el-option label="Tabulador" value="tab" />
                                <el-option label="Barra vertical (|)" value="|" />
                            </el-select>
                        </div>
                    </div>
                </div>

                <div class="d-flex align-items-end flex-wrap mt-2">
                    <div class="mr-2" :class="{ 'has-danger': hasError('name') }" style="min-width: 240px">
                        <label for="rc-map-name" class="control-label small mb-1">Guardar este mapeo como</label>
                        <el-input id="rc-map-name" v-model="mappingName" size="small" maxlength="100" placeholder="Ej. BCP soles" />
                        <small v-if="hasError('name')" class="form-control-feedback text-danger d-block">{{ firstError('name') }}</small>
                    </div>
                    <el-button size="small" :loading="savingMapping" :disabled="!mappingName" @click.prevent="saveMapping">Guardar mapeo</el-button>
                    <el-button v-if="selectedMappingId" size="small" type="danger" plain :disabled="savingMapping" @click.prevent="deleteMapping">Eliminar mapeo</el-button>
                </div>
            </div>

            <div class="d-flex align-items-center mb-3">
                <el-button type="primary" plain :loading="previewing" :disabled="!file || !form.bank_account_id" @click.prevent="runPreview">Previsualizar</el-button>
                <span class="small text-muted ml-3">Revise cómo se leerá el archivo antes de importarlo.</span>
            </div>

            <div v-if="preview">
                <div v-if="preview.error_count > 0" class="alert alert-danger py-2 px-3 small" role="alert">
                    <strong>{{ preview.error_count }} fila(s) con errores.</strong> Corrija el mapeo o el archivo:
                    <ul class="mb-0 pl-3"><li v-for="(e, i) in preview.errors" :key="i">{{ e }}</li></ul>
                </div>
                <div v-for="(w, i) in preview.warnings" :key="'w' + i" class="alert alert-warning py-2 px-3 small" role="status">{{ w }}</div>

                <div class="small mb-2">
                    <strong>{{ preview.totals.count }}</strong> movimiento(s) ·
                    abonos {{ money(preview.totals.inflow, accountCurrency) }} ·
                    cargos {{ money(preview.totals.outflow, accountCurrency) }} ·
                    neto {{ signedMoney(preview.totals.net, accountCurrency) }} ·
                    <span v-if="preview.totals.date_from">del {{ preview.totals.date_from }} al {{ preview.totals.date_to }}</span>
                </div>

                <div v-if="preview.lines.length" class="table-responsive mb-3" style="max-height: 220px; overflow: auto">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr><th>#</th><th>Fecha</th><th>Descripción</th><th>Referencia</th><th class="text-right">Importe</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="line in preview.lines" :key="line.line_no">
                                <td>{{ line.line_no }}</td>
                                <td>{{ line.date }}</td>
                                <td>{{ line.description }}</td>
                                <td>{{ line.reference || '-' }}</td>
                                <td class="text-right" :class="amountClass(line.amount)">{{ signedMoney(line.amount, accountCurrency) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="preview.totals.count > preview.lines.length" class="small text-muted px-2 py-1">
                        Se muestran las primeras {{ preview.lines.length }} filas.
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-3 col-6">
                        <div class="form-group mb-2" :class="{ 'has-danger': hasError('period_from') }">
                            <label for="rc-imp-from" class="control-label small">Período desde <span class="text-danger">*</span></label>
                            <el-date-picker id="rc-imp-from" v-model="form.period_from" type="date" value-format="yyyy-MM-dd" placeholder="AAAA-MM-DD" size="small" class="w-100" />
                            <small v-if="hasError('period_from')" class="form-control-feedback text-danger d-block">{{ firstError('period_from') }}</small>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="form-group mb-2" :class="{ 'has-danger': hasError('period_to') }">
                            <label for="rc-imp-to" class="control-label small">Período hasta <span class="text-danger">*</span></label>
                            <el-date-picker id="rc-imp-to" v-model="form.period_to" type="date" value-format="yyyy-MM-dd" placeholder="AAAA-MM-DD" size="small" class="w-100" />
                            <small v-if="hasError('period_to')" class="form-control-feedback text-danger d-block">{{ firstError('period_to') }}</small>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="form-group mb-2" :class="{ 'has-danger': hasError('opening_balance') }">
                            <label for="rc-imp-open" class="control-label small">Saldo inicial del banco <span class="text-danger">*</span></label>
                            <el-input id="rc-imp-open" v-model="form.opening_balance" size="small" placeholder="0.00" @input="suggestClosing" />
                            <small v-if="hasError('opening_balance')" class="form-control-feedback text-danger d-block">{{ firstError('opening_balance') }}</small>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="form-group mb-2" :class="{ 'has-danger': hasError('closing_balance') }">
                            <label for="rc-imp-close" class="control-label small">Saldo final del banco <span class="text-danger">*</span></label>
                            <el-input id="rc-imp-close" v-model="form.closing_balance" size="small" placeholder="0.00" />
                            <small v-if="hasError('closing_balance')" class="form-control-feedback text-danger d-block">{{ firstError('closing_balance') }}</small>
                        </div>
                    </div>
                </div>
                <small v-if="hasError('period_from') || hasError('closing_balance')" class="text-muted d-block mb-2">
                    El saldo inicial más los movimientos debe dar el saldo final del extracto.
                </small>
            </div>
        </form>

        <span slot="footer" class="dialog-footer">
            <el-button :disabled="submitting" @click="$emit('update:visible', false)">Cancelar</el-button>
            <el-button type="primary" :loading="submitting" :disabled="!preview || preview.error_count > 0 || preview.totals.count === 0" @click="submit">Importar extracto</el-button>
        </span>
    </el-dialog>
</template>

<script>
import { amountClass, money, parseError, signedMoney } from './helpers'

const defaultMapping = () => ({
    header_row: 1,
    date_column: 1,
    description_column: 2,
    reference_column: null,
    amount_mode: 'signed',
    amount_column: 3,
    debit_column: 3,
    credit_column: 4,
    date_format: 'd/m/Y',
    decimal_separator: '.',
    invert_sign: false,
    delimiter: null,
})

export default {
    name: 'TreasuryReconciliationStatementImportDialog',
    props: {
        visible: { type: Boolean, default: false },
        tables: { type: Object, required: true },
    },
    data() {
        return {
            file: null,
            form: { bank_account_id: null, period_from: null, period_to: null, opening_balance: '', closing_balance: '' },
            mapping: defaultMapping(),
            selectedMappingId: null,
            mappingName: '',
            savingMapping: false,
            previewing: false,
            preview: null,
            submitting: false,
            errors: {},
            general: null,
        }
    },
    computed: {
        availableMappings() {
            return (this.tables.mappings || []).filter(m => !m.bank_account_id || m.bank_account_id === this.form.bank_account_id)
        },
        accountCurrency() {
            const acc = (this.tables.accounts || []).filter(a => a.id === this.form.bank_account_id)[0]
            return acc ? acc.currency_type_id : 'PEN'
        },
    },
    methods: {
        money,
        signedMoney,
        amountClass,
        reset() {
            this.file = null
            this.preview = null
            this.errors = {}
            this.general = null
            this.submitting = false
            this.previewing = false
            this.form = { bank_account_id: null, period_from: null, period_to: null, opening_balance: '', closing_balance: '' }
            if (this.$refs.file) {
                this.$refs.file.value = ''
            }
        },
        hasError(key) {
            return !!(this.errors && this.errors[key])
        },
        firstError(key) {
            return this.errors[key] ? this.errors[key][0] : ''
        },
        fieldErrors(key) {
            return this.errors[key] || []
        },
        onFile(event) {
            this.file = event.target.files && event.target.files[0] ? event.target.files[0] : null
            this.preview = null
            delete this.errors.file
        },
        onAccountChange() {
            this.selectedMappingId = null
            this.preview = null
        },
        applyMapping(id) {
            const found = (this.tables.mappings || []).filter(m => m.id === id)[0]
            if (!found) {
                this.mappingName = ''
                return
            }
            this.mapping = Object.assign(defaultMapping(), found.config, {
                delimiter: found.config.delimiter === '\t' ? 'tab' : (found.config.delimiter || null),
            })
            this.mappingName = found.name
            this.preview = null
        },
        mappingPayload() {
            const config = Object.assign({}, this.mapping)
            Object.keys(config).forEach(key => {
                if (config[key] === null || config[key] === undefined || config[key] === '') {
                    delete config[key]
                }
            })
            return config
        },
        buildForm(extra) {
            const data = new FormData()
            data.append('file', this.file)
            data.append('mapping', JSON.stringify(this.mappingPayload()))
            Object.keys(extra || {}).forEach(key => {
                if (extra[key] !== null && extra[key] !== undefined && extra[key] !== '') {
                    data.append(key, extra[key])
                }
            })
            return data
        },
        async runPreview() {
            if (!this.file || this.previewing) {
                return
            }
            this.previewing = true
            this.errors = {}
            this.general = null
            try {
                const res = await this.$http.post('/treasury/reconciliation/preview', this.buildForm())
                this.preview = res.data.data
                if (this.preview.totals.date_from) {
                    this.form.period_from = this.form.period_from || this.preview.totals.date_from
                    this.form.period_to = this.form.period_to || this.preview.totals.date_to
                }
            } catch (error) {
                this.preview = null
                const parsed = parseError(error)
                this.errors = parsed.fields
                this.general = parsed.general
            } finally {
                this.previewing = false
            }
        },
        suggestClosing() {
            if (!this.preview) {
                return
            }
            const opening = parseFloat(this.form.opening_balance)
            if (!isNaN(opening)) {
                this.form.closing_balance = (Math.round((opening + this.preview.totals.net) * 100) / 100).toFixed(2)
            }
        },
        async submit() {
            if (!this.file || !this.preview || this.submitting) {
                return
            }
            this.submitting = true
            this.errors = {}
            this.general = null
            try {
                const res = await this.$http.post('/treasury/reconciliation/statements', this.buildForm({
                    bank_account_id: this.form.bank_account_id,
                    period_from: this.form.period_from,
                    period_to: this.form.period_to,
                    opening_balance: this.form.opening_balance,
                    closing_balance: this.form.closing_balance,
                }))
                this.$message.success(res.data.message || 'Extracto importado.')
                this.$emit('imported', res.data.data.id)
                this.$emit('update:visible', false)
            } catch (error) {
                const parsed = parseError(error)
                this.errors = parsed.fields
                this.general = parsed.general
            } finally {
                this.submitting = false
            }
        },
        async saveMapping() {
            if (this.savingMapping || !this.mappingName) {
                return
            }
            this.savingMapping = true
            this.errors = {}
            this.general = null
            try {
                const res = await this.$http.post('/treasury/reconciliation/mappings', {
                    id: this.selectedMappingId || null,
                    name: this.mappingName,
                    bank_account_id: this.form.bank_account_id || null,
                    config: this.mappingPayload(),
                })
                this.$message.success(res.data.message || 'Mapeo guardado.')
                this.$emit('mappings-changed')
                this.selectedMappingId = res.data.data.id
            } catch (error) {
                const parsed = parseError(error)
                this.errors = parsed.fields
                this.general = parsed.general
            } finally {
                this.savingMapping = false
            }
        },
        deleteMapping() {
            if (!this.selectedMappingId) {
                return
            }
            this.$confirm('¿Desea eliminar el mapeo guardado? Los extractos ya importados no se afectan.', 'Eliminar mapeo', {
                confirmButtonText: 'Eliminar',
                cancelButtonText: 'Cancelar',
                type: 'warning',
            }).then(async () => {
                try {
                    await this.$http.post(`/treasury/reconciliation/mappings/${this.selectedMappingId}/delete`)
                    this.$message.success('Mapeo eliminado.')
                    this.selectedMappingId = null
                    this.mappingName = ''
                    this.$emit('mappings-changed')
                } catch (error) {
                    this.general = parseError(error).general
                }
            }).catch(() => {})
        },
    },
}
</script>
