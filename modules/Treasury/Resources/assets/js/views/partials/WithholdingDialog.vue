<template>
    <el-dialog
        :title="dialogTitle"
        :visible.sync="visible"
        :width="mode === 'received_retention' ? '480px' : '860px'"
        append-to-body
        @open="onOpen"
        @closed="onClosed"
    >
        <!-- Register a CRE received from a customer (retention agent) -->
        <form v-if="mode === 'received_retention'" @submit.prevent="submitReceived">
            <p class="text-muted small mb-3">
                Registre el comprobante de retención (CRE) que le entregó su cliente. El monto retenido reduce el saldo
                neto por cobrar de la factura.
            </p>
            <div v-if="generalError" class="alert alert-danger py-2 px-3 small" role="alert">{{ generalError }}</div>

            <div class="row">
                <div class="col-4">
                    <div class="form-group" :class="{ 'has-danger': errors.series }">
                        <label for="wd-series" class="control-label">Serie <span class="text-danger">*</span></label>
                        <el-input id="wd-series" v-model="form.series" maxlength="10" placeholder="R001" />
                        <small v-if="errors.series" class="form-control-feedback text-danger">{{ errors.series[0] }}</small>
                    </div>
                </div>
                <div class="col-8">
                    <div class="form-group" :class="{ 'has-danger': errors.number }">
                        <label for="wd-number" class="control-label">Número <span class="text-danger">*</span></label>
                        <el-input id="wd-number" v-model="form.number" maxlength="20" />
                        <small v-if="errors.number" class="form-control-feedback text-danger">{{ errors.number[0] }}</small>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-6">
                    <div class="form-group" :class="{ 'has-danger': errors.date }">
                        <label for="wd-date" class="control-label">Fecha <span class="text-danger">*</span></label>
                        <el-date-picker
                            id="wd-date"
                            v-model="form.date"
                            type="date"
                            value-format="yyyy-MM-dd"
                            class="w-100"
                            placeholder="AAAA-MM-DD"
                        />
                        <small v-if="errors.date" class="form-control-feedback text-danger">{{ errors.date[0] }}</small>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-group" :class="{ 'has-danger': errors.amount }">
                        <label for="wd-amount" class="control-label">Monto retenido <span class="text-danger">*</span></label>
                        <el-input id="wd-amount" v-model="form.amount" placeholder="0.00" />
                        <small v-if="errors.amount" class="form-control-feedback text-danger">{{ errors.amount[0] }}</small>
                    </div>
                </div>
            </div>
            <div class="form-group mb-0" :class="{ 'has-danger': errors.notes }">
                <label for="wd-notes" class="control-label">Observaciones</label>
                <el-input id="wd-notes" v-model="form.notes" type="textarea" :rows="2" maxlength="500" />
            </div>
        </form>

        <!-- Offers (retention for a purchase / perception for a sale) -->
        <div v-else>
            <div v-if="loading" class="text-center py-4 text-muted" role="status" aria-live="polite">
                <i class="el-icon-loading mr-2"></i> Calculando...
            </div>

            <div v-else-if="errorMessage" class="alert alert-danger d-flex justify-content-between align-items-center" role="alert">
                <span>{{ errorMessage }}</span>
                <el-button size="mini" type="danger" plain @click="loadOffers">Reintentar</el-button>
            </div>

            <template v-else-if="offers">
                <div v-if="!offers.enabled" class="alert alert-light border text-muted mb-0">
                    {{ offers.reason }}
                </div>

                <template v-else>
                    <div v-if="mode === 'perception_offers'" class="form-inline mb-3">
                        <label for="wd-rate-code" class="mr-2">Clase de tasa</label>
                        <el-select id="wd-rate-code" v-model="rateCode" size="small" @change="loadOffers">
                            <el-option
                                v-for="(rate, code) in perceptionRates"
                                :key="code"
                                :label="rateLabel(code, rate)"
                                :value="code"
                            />
                        </el-select>
                    </div>

                    <div v-if="offers.reason" class="alert alert-warning py-2 px-3 small">{{ offers.reason }}</div>
                    <ul v-if="offers.warnings && offers.warnings.length" class="small text-muted pl-3">
                        <li v-for="(w, i) in offers.warnings" :key="i">{{ w }}</li>
                    </ul>
                    <div v-if="generalError" class="alert alert-danger py-2 px-3 small" role="alert">{{ generalError }}</div>

                    <div v-if="offers.lines.length === 0 && !offers.reason" class="alert alert-light border text-muted mb-0">
                        Aún no hay {{ mode === 'retention_offers' ? 'pagos' : 'cobros' }} registrados en este comprobante.
                    </div>

                    <div v-else-if="offers.lines.length" class="table-responsive">
                        <table class="table table-sm table-bordered mb-2">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th class="text-right">{{ mode === 'retention_offers' ? 'Pago' : 'Cobro' }}</th>
                                    <th class="text-right">{{ mode === 'retention_offers' ? 'Retención' : 'Percepción' }}</th>
                                    <th class="text-right">{{ mode === 'retention_offers' ? 'Pago neto al proveedor' : 'Total a cobrar' }}</th>
                                    <th>Comprobante</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="line in offers.lines" :key="line.payment_id">
                                    <td>{{ line.date }}</td>
                                    <td class="text-right">{{ money(line.payment, line.currency_type_id) }}</td>
                                    <td class="text-right">
                                        <template v-if="line.applicable">
                                            {{ money(mode === 'retention_offers' ? line.retention_amount : line.perception_amount, line.currency_type_id) }}
                                            <div class="small text-muted">{{ line.rate_percent }}%</div>
                                        </template>
                                        <span v-else class="small text-muted">No aplica</span>
                                    </td>
                                    <td class="text-right">
                                        <template v-if="line.applicable">
                                            {{ money(mode === 'retention_offers' ? line.net_payment : line.total_to_collect, line.currency_type_id) }}
                                        </template>
                                        <span v-else class="small text-muted" :title="line.reason">{{ line.reason }}</span>
                                    </td>
                                    <td style="min-width: 260px;">
                                        <template v-if="line.linked">
                                            <span class="badge badge-success">{{ line.certificate }}</span>
                                        </template>
                                        <template v-else-if="line.applicable">
                                            <div class="d-flex align-items-start">
                                                <el-input
                                                    v-model="linkForms[line.payment_id].series"
                                                    size="mini"
                                                    placeholder="Serie"
                                                    maxlength="10"
                                                    style="width: 80px;"
                                                    class="mr-1"
                                                    :aria-label="'Serie del comprobante del pago ' + line.payment_id"
                                                />
                                                <el-input
                                                    v-model="linkForms[line.payment_id].number"
                                                    size="mini"
                                                    placeholder="Número"
                                                    maxlength="20"
                                                    style="width: 100px;"
                                                    class="mr-1"
                                                    :aria-label="'Número del comprobante del pago ' + line.payment_id"
                                                />
                                                <el-button
                                                    size="mini"
                                                    type="primary"
                                                    :loading="linking === line.payment_id"
                                                    @click="link(line)"
                                                >
                                                    Enlazar
                                                </el-button>
                                            </div>
                                            <small v-if="linkErrors[line.payment_id]" class="text-danger d-block">
                                                {{ linkErrors[line.payment_id] }}
                                            </small>
                                        </template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="offers.emit_url" class="mt-2">
                        <a :href="offers.emit_url" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">
                            {{ mode === 'retention_offers' ? 'Emitir comprobante de retención' : 'Emitir comprobante de percepción' }}
                            <i class="fa fa-external-link ml-1"></i>
                        </a>
                        <span class="small text-muted ml-2">
                            Se abre el flujo de emisión existente. Luego vuelva aquí y enlace la serie y el número emitidos.
                        </span>
                    </div>
                </template>
            </template>
        </div>

        <span slot="footer" class="dialog-footer">
            <el-button :disabled="saving" @click="visible = false">Cerrar</el-button>
            <el-button v-if="mode === 'received_retention'" type="primary" :loading="saving" @click="submitReceived">
                Registrar retención
            </el-button>
        </span>
    </el-dialog>
</template>

<script>
export default {
    name: 'TreasuryWithholdingDialog',
    props: {
        showDialog: { type: Boolean, default: false },
        // received_retention | retention_offers | perception_offers
        mode: { type: String, default: 'received_retention' },
        recordId: { type: Number, default: null },
        perceptionRates: { type: Object, default: () => ({}) },
    },
    data() {
        return {
            visible: false,
            loading: false,
            saving: false,
            linking: null,
            errorMessage: null,
            generalError: null,
            errors: {},
            offers: null,
            rateCode: 'general',
            linkForms: {},
            linkErrors: {},
            form: { series: '', number: '', date: null, amount: '', notes: '' },
        }
    },
    computed: {
        dialogTitle() {
            if (this.mode === 'retention_offers') return 'Retención del IGV (CRE)'
            if (this.mode === 'perception_offers') return 'Percepción del IGV (CPE)'
            return 'Registrar retención recibida (CRE)'
        },
    },
    watch: {
        showDialog(value) {
            this.visible = value
        },
        visible(value) {
            this.$emit('update:showDialog', value)
        },
    },
    methods: {
        onOpen() {
            this.errors = {}
            this.generalError = null
            this.errorMessage = null
            this.linkErrors = {}
            if (this.mode === 'received_retention') {
                this.form = { series: '', number: '', date: null, amount: '', notes: '' }
            } else {
                const codes = Object.keys(this.perceptionRates || {})
                this.rateCode = codes.indexOf('general') !== -1 ? 'general' : (codes[0] || 'general')
                this.loadOffers()
            }
        },
        onClosed() {
            this.offers = null
        },
        async loadOffers() {
            if (!this.recordId) {
                return
            }
            this.loading = true
            this.errorMessage = null
            try {
                const url = this.mode === 'retention_offers'
                    ? `/treasury/withholdings/retention-offers/${this.recordId}`
                    : `/treasury/withholdings/perception-offers/${this.recordId}`
                const params = this.mode === 'perception_offers' ? { rate_code: this.rateCode } : {}
                const res = await this.$http.get(url, { params })
                const data = (res.data && res.data.data) || null
                const forms = {}
                if (data && data.lines) {
                    data.lines.forEach(line => {
                        forms[line.payment_id] = { series: '', number: '' }
                    })
                }
                this.linkForms = forms
                this.offers = data
            } catch (e) {
                this.errorMessage = (e.response && e.response.status === 403)
                    ? 'No tiene permiso para ver este comprobante.'
                    : 'No se pudieron calcular las opciones. Intente nuevamente.'
            } finally {
                this.loading = false
            }
        },
        async submitReceived() {
            if (this.saving) {
                return
            }
            this.saving = true
            this.errors = {}
            this.generalError = null
            try {
                const res = await this.$http.post(`/treasury/withholdings/received-retentions/${this.recordId}`, this.form)
                this.$message.success((res.data && res.data.message) || 'Retención registrada correctamente.')
                this.visible = false
                this.$emit('saved')
            } catch (error) {
                this.handleError(error, errs => { this.errors = errs })
            } finally {
                this.saving = false
            }
        },
        async link(line) {
            if (this.linking !== null) {
                return
            }
            this.linking = line.payment_id
            this.$set(this.linkErrors, line.payment_id, null)
            this.generalError = null
            try {
                const base = this.mode === 'retention_offers' ? 'issued-retentions' : 'issued-perceptions'
                const payload = Object.assign({}, this.linkForms[line.payment_id])
                if (this.mode === 'perception_offers') {
                    payload.rate_code = this.rateCode
                }
                const res = await this.$http.post(`/treasury/withholdings/${base}/${line.payment_id}`, payload)
                this.$message.success((res.data && res.data.message) || 'Comprobante enlazado correctamente.')
                this.$emit('saved')
                await this.loadOffers()
            } catch (error) {
                this.handleError(error, errs => {
                    const first = Object.keys(errs).map(k => errs[k][0])[0]
                    this.$set(this.linkErrors, line.payment_id, first || 'No se pudo enlazar el comprobante.')
                })
            } finally {
                this.linking = null
            }
        },
        handleError(error, onFieldErrors) {
            if (error.response && error.response.status === 422) {
                const errs = error.response.data.errors || {}
                onFieldErrors(errs)
                if (!Object.keys(errs).length) {
                    this.generalError = error.response.data.message || 'Los datos ingresados no son válidos.'
                }
            } else if (error.response && error.response.data && error.response.data.message) {
                this.generalError = error.response.data.message
            } else {
                this.generalError = 'No se pudo completar la operación. Intente nuevamente.'
            }
        },
        rateLabel(code, rate) {
            const labels = { general: 'General', fuel: 'Combustibles', agent: 'Cliente agente de percepción' }
            return `${labels[code] || code} (${(rate * 100).toFixed(2)}%)`
        },
        money(raw, currencyTypeId) {
            const value = parseFloat(raw || 0)
            const symbol = currencyTypeId === 'USD' ? 'US$' : 'S/'
            return `${symbol} ${value.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
        },
    },
}
</script>
