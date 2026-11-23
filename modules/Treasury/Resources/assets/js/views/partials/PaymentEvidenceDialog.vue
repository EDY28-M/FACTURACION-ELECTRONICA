<template>
    <el-dialog
        title="Evidencia de bancarización (Ley 28194)"
        :visible="showDialog"
        width="620px"
        append-to-body
        @open="onOpen"
        @close="close"
    >
        <div v-if="loading" class="text-center py-4 text-muted" role="status" aria-live="polite">
            <i class="el-icon-loading mr-2"></i> Cargando datos de evidencia...
        </div>

        <div v-else-if="loadError" class="alert alert-danger d-flex justify-content-between align-items-center" role="alert">
            <span>{{ loadError }}</span>
            <el-button size="mini" type="danger" plain @click="onOpen">Reintentar</el-button>
        </div>

        <form v-else autocomplete="off" @submit.prevent="submit">
            <div v-if="context.amount !== null" class="alert alert-light border py-2 px-3 mb-3">
                <div class="d-flex justify-content-between align-items-center flex-wrap">
                    <span>
                        <strong>Importe del pago:</strong>
                        {{ formatCurrency(context.amount, context.currency_type_id) }}
                    </span>
                    <span v-if="context.bancarization && context.bancarization.threshold !== null" class="small text-muted">
                        Umbral vigente: {{ formatCurrency(context.bancarization.threshold, context.currency_type_id) }}
                    </span>
                </div>
            </div>

            <div
                v-if="showBancarizationWarning"
                class="alert alert-warning py-2 px-3 mb-3"
                role="alert"
            >
                <strong>Atención (bancarización):</strong>
                {{ context.bancarization && context.bancarization.warning ? context.bancarization.warning : 'El importe alcanza o supera el umbral vigente y el medio seleccionado es efectivo. Indique una excepción del art. 6 o cambie el medio de pago.' }}
            </div>

            <div class="row">
                <div class="col-md-7 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.sunat_payment_code }">
                        <label for="evidence-sunat-code" class="control-label">
                            Medio de pago SUNAT (Tabla 1) <span class="text-danger">*</span>
                        </label>
                        <el-select
                            id="evidence-sunat-code"
                            v-model="form.sunat_payment_code"
                            filterable
                            placeholder="Seleccione medio de pago"
                            class="w-100"
                        >
                            <el-option
                                v-for="item in sunatPaymentCodes"
                                :key="item.code"
                                :label="item.label"
                                :value="item.code"
                            />
                        </el-select>
                        <small v-if="errors.sunat_payment_code" class="form-control-feedback text-danger">
                            {{ errors.sunat_payment_code[0] }}
                        </small>
                    </div>
                </div>

                <div class="col-md-5 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.operation_date }">
                        <label for="evidence-operation-date" class="control-label">
                            Fecha de operación <span class="text-danger">*</span>
                        </label>
                        <el-date-picker
                            id="evidence-operation-date"
                            v-model="form.operation_date"
                            type="date"
                            value-format="yyyy-MM-dd"
                            placeholder="YYYY-MM-DD"
                            :clearable="false"
                            class="w-100"
                        />
                        <small v-if="errors.operation_date" class="form-control-feedback text-danger">
                            {{ errors.operation_date[0] }}
                        </small>
                    </div>
                </div>

                <div class="col-md-6 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.operation_number }">
                        <label for="evidence-operation-number" class="control-label">
                            N° de operación / cheque / voucher
                        </label>
                        <el-input
                            id="evidence-operation-number"
                            v-model="form.operation_number"
                            maxlength="60"
                            placeholder="Ej. 00458921"
                        />
                        <small v-if="errors.operation_number" class="form-control-feedback text-danger">
                            {{ errors.operation_number[0] }}
                        </small>
                    </div>
                </div>

                <div class="col-md-6 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.bank_account_id }">
                        <label for="evidence-bank-account" class="control-label">Cuenta bancaria propia</label>
                        <el-select
                            id="evidence-bank-account"
                            v-model="form.bank_account_id"
                            clearable
                            filterable
                            placeholder="Seleccione cuenta"
                            class="w-100"
                            @change="onBankAccountChange"
                        >
                            <el-option
                                v-for="acc in bankAccounts"
                                :key="acc.id"
                                :label="acc.description"
                                :value="acc.id"
                            />
                        </el-select>
                        <small v-if="errors.bank_account_id" class="form-control-feedback text-danger">
                            {{ errors.bank_account_id[0] }}
                        </small>
                    </div>
                </div>

                <div class="col-md-6 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.bank_id }">
                        <label for="evidence-bank" class="control-label">Entidad financiera (banco)</label>
                        <el-select
                            id="evidence-bank"
                            v-model="form.bank_id"
                            clearable
                            filterable
                            placeholder="Seleccione entidad"
                            class="w-100"
                        >
                            <el-option
                                v-for="bank in banks"
                                :key="bank.id"
                                :label="bank.description"
                                :value="bank.id"
                            />
                        </el-select>
                        <small v-if="errors.bank_id" class="form-control-feedback text-danger">
                            {{ errors.bank_id[0] }}
                        </small>
                    </div>
                </div>

                <div class="col-md-6 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.payer_or_beneficiary_document }">
                        <label for="evidence-doc-number" class="control-label">RUC / DNI girador o beneficiario</label>
                        <el-input
                            id="evidence-doc-number"
                            v-model="form.payer_or_beneficiary_document"
                            maxlength="20"
                            placeholder="Ej. 20100070970"
                        />
                        <small v-if="errors.payer_or_beneficiary_document" class="form-control-feedback text-danger">
                            {{ errors.payer_or_beneficiary_document[0] }}
                        </small>
                    </div>
                </div>

                <div class="col-md-12 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.payer_or_beneficiary_name }">
                        <label for="evidence-doc-name" class="control-label">Nombre o razón social</label>
                        <el-input
                            id="evidence-doc-name"
                            v-model="form.payer_or_beneficiary_name"
                            maxlength="255"
                            placeholder="Nombre completo o razón social"
                        />
                        <small v-if="errors.payer_or_beneficiary_name" class="form-control-feedback text-danger">
                            {{ errors.payer_or_beneficiary_name[0] }}
                        </small>
                    </div>
                </div>

                <div v-if="isCashCode || form.bancarization_exception" class="col-md-12 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.bancarization_exception }">
                        <label for="evidence-exception" class="control-label">
                            Excepción de bancarización (Art. 6 Ley 28194)
                            <span v-if="showBancarizationWarning" class="text-danger">*</span>
                        </label>
                        <el-select
                            id="evidence-exception"
                            v-model="form.bancarization_exception"
                            clearable
                            placeholder="Seleccione excepción aplicable"
                            class="w-100"
                        >
                            <el-option
                                v-for="item in bancarizationExceptions"
                                :key="item.value"
                                :label="item.label"
                                :value="item.value"
                            />
                        </el-select>
                        <small v-if="errors.bancarization_exception" class="form-control-feedback text-danger">
                            {{ errors.bancarization_exception[0] }}
                        </small>
                    </div>
                </div>

                <div class="col-md-12 mb-2">
                    <el-checkbox v-model="form.third_party_payment">
                        Pago realizado a o por un tercero (Art. 5-A Ley 28194)
                    </el-checkbox>
                </div>

                <div v-if="form.third_party_payment" class="col-md-12 mb-3 pl-4">
                    <el-checkbox v-model="form.third_party_reported_to_sunat">
                        Designación comunicada a SUNAT antes del pago
                    </el-checkbox>
                </div>
            </div>

            <div class="form-actions text-right pt-2">
                <el-button type="default" :disabled="saving" @click="close">Cancelar</el-button>
                <el-button type="primary" native-type="submit" :loading="saving">Guardar evidencia</el-button>
            </div>
        </form>
    </el-dialog>
</template>

<script>
export default {
    name: 'PaymentEvidenceDialog',
    props: {
        showDialog: {
            type: Boolean,
            default: false,
        },
        payableType: {
            type: String,
            default: null,
        },
        payableId: {
            type: [Number, String],
            default: null,
        },
    },
    data() {
        return {
            loading: false,
            saving: false,
            loadError: null,
            tablesLoaded: false,
            openSeq: 0,
            banks: [],
            bankAccounts: [],
            sunatPaymentCodes: [],
            bancarizationExceptions: [],
            context: {
                amount: null,
                currency_type_id: 'PEN',
                bancarization: null,
            },
            form: this.emptyForm(),
            errors: {},
        }
    },
    computed: {
        isCashCode() {
            return this.form.sunat_payment_code === '008' || this.form.sunat_payment_code === '009'
        },
        showBancarizationWarning() {
            return Boolean(
                this.context.bancarization &&
                this.context.bancarization.exceeds_threshold &&
                this.isCashCode
            )
        },
    },
    methods: {
        emptyForm() {
            return {
                sunat_payment_code: null,
                operation_number: null,
                bank_id: null,
                bank_account_id: null,
                operation_date: moment().format('YYYY-MM-DD'),
                payer_or_beneficiary_document: null,
                payer_or_beneficiary_name: null,
                third_party_payment: false,
                third_party_reported_to_sunat: false,
                bancarization_exception: null,
            }
        },
        async onOpen() {
            if (!this.payableType || !this.payableId) {
                return
            }

            const seq = ++this.openSeq
            this.loading = true
            this.loadError = null
            this.errors = {}

            try {
                if (!this.tablesLoaded) {
                    const tablesRes = await this.$http.get('/treasury/payment-evidences/tables')
                    if (seq !== this.openSeq) {
                        return
                    }
                    this.banks = tablesRes.data.banks || []
                    this.bankAccounts = tablesRes.data.bank_accounts || []
                    this.sunatPaymentCodes = tablesRes.data.sunat_payment_codes || []
                    this.bancarizationExceptions = tablesRes.data.bancarization_exceptions || []
                    this.tablesLoaded = true
                }

                const res = await this.$http.get(`/treasury/payment-evidences/${this.payableType}/${this.payableId}`)
                if (seq !== this.openSeq) {
                    return
                }
                const payload = (res.data && res.data.data) ? res.data.data : {}
                const source = payload.evidence || payload.defaults || {}

                this.context = payload.context || { amount: null, currency_type_id: 'PEN', bancarization: null }
                this.form = {
                    sunat_payment_code: source.sunat_payment_code || null,
                    operation_number: source.operation_number || null,
                    bank_id: source.bank_id || null,
                    bank_account_id: source.bank_account_id || null,
                    operation_date: source.operation_date || moment().format('YYYY-MM-DD'),
                    payer_or_beneficiary_document: source.payer_or_beneficiary_document || null,
                    payer_or_beneficiary_name: source.payer_or_beneficiary_name || null,
                    third_party_payment: Boolean(source.third_party_payment),
                    third_party_reported_to_sunat: Boolean(source.third_party_reported_to_sunat),
                    bancarization_exception: source.bancarization_exception || null,
                }
            } catch (error) {
                if (seq !== this.openSeq) {
                    return
                }
                if (error.response && error.response.status === 403) {
                    this.loadError = error.response.data.message || 'No tiene permiso para gestionar esta evidencia.'
                } else {
                    this.loadError = 'No se pudo cargar la evidencia de bancarización. Intente nuevamente.'
                }
            } finally {
                if (seq === this.openSeq) {
                    this.loading = false
                }
            }
        },
        onBankAccountChange(accountId) {
            if (!accountId) {
                return
            }
            const account = this.bankAccounts.find(item => item.id === accountId)
            if (account && account.bank_id) {
                this.form.bank_id = account.bank_id
            }
        },
        async submit() {
            if (this.saving) {
                return
            }
            this.saving = true
            this.errors = {}

            try {
                const res = await this.$http.post(
                    `/treasury/payment-evidences/${this.payableType}/${this.payableId}`,
                    this.form
                )
                this.$message.success((res.data && res.data.message) || 'Evidencia guardada correctamente.')
                this.$emit('saved', res.data.data)
                this.close()
            } catch (error) {
                if (error.response && error.response.status === 422) {
                    this.errors = error.response.data.errors || error.response.data || {}
                } else if (error.response && error.response.data && error.response.data.message) {
                    this.$message.error(error.response.data.message)
                } else {
                    this.$message.error('No se pudo guardar la evidencia de bancarización.')
                }
            } finally {
                this.saving = false
            }
        },
        close() {
            this.$emit('update:showDialog', false)
        },
        formatCurrency(amount, currencyTypeId) {
            const value = parseFloat(amount || 0)
            const symbol = currencyTypeId === 'USD' ? 'US$' : (currencyTypeId === 'PEN' ? 'S/' : (currencyTypeId || 'PEN'))
            return `${symbol} ${value.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
        },
    },
}
</script>
