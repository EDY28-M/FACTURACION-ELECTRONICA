<template>
    <el-dialog
        title="Registrar movimiento manual de tesorería"
        :visible="showDialog"
        width="760px"
        @open="onOpen"
        @close="close"
    >
        <form autocomplete="off" @submit.prevent="submit">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.kind }">
                        <label for="mov-kind" class="control-label">
                            Tipo de movimiento <span class="text-danger">*</span>
                        </label>
                        <el-select
                            id="mov-kind"
                            v-model="form.kind"
                            class="w-100"
                            @change="onKindChange"
                        >
                            <el-option
                                v-for="item in tables.manual_kinds"
                                :key="item.value"
                                :label="item.label"
                                :value="item.value"
                            />
                        </el-select>
                        <small v-if="errors.kind" class="form-control-feedback text-danger">{{ errors.kind[0] }}</small>
                    </div>
                </div>

                <div v-if="form.kind === 'adjustment'" class="col-md-4 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.direction }">
                        <label for="mov-direction" class="control-label">
                            Sentido del ajuste <span class="text-danger">*</span>
                        </label>
                        <el-select id="mov-direction" v-model="form.direction" class="w-100" @change="applyDirectionalExchangeRate">
                            <el-option label="Entrada (+)" :value="1" />
                            <el-option label="Salida (-)" :value="-1" />
                        </el-select>
                        <small v-if="errors.direction" class="form-control-feedback text-danger">{{ errors.direction[0] }}</small>
                    </div>
                </div>

                <div class="col-md-4 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.date }">
                        <label for="mov-date" class="control-label">
                            Fecha <span class="text-danger">*</span>
                        </label>
                        <el-date-picker
                            id="mov-date"
                            v-model="form.date"
                            type="date"
                            value-format="yyyy-MM-dd"
                            :clearable="false"
                            :picker-options="datePickerOptions"
                            class="w-100"
                            @change="onDateOrCurrencyChange"
                        />
                        <small v-if="errors.date" class="form-control-feedback text-danger">{{ errors.date[0] }}</small>
                    </div>
                </div>

                <div class="col-md-4 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.establishment_id }">
                        <label for="mov-establishment" class="control-label">
                            Sede <span class="text-danger">*</span>
                        </label>
                        <el-select
                            id="mov-establishment"
                            v-model="form.establishment_id"
                            placeholder="Seleccione sede"
                            class="w-100"
                        >
                            <el-option
                                v-for="est in tables.establishments"
                                :key="est.id"
                                :label="est.description"
                                :value="est.id"
                            />
                        </el-select>
                        <small v-if="errors.establishment_id" class="form-control-feedback text-danger">{{ errors.establishment_id[0] }}</small>
                    </div>
                </div>

                <div class="col-md-4 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.destination_type }">
                        <label for="mov-dest-type" class="control-label">
                            Tipo de destino <span class="text-danger">*</span>
                        </label>
                        <el-select
                            id="mov-dest-type"
                            v-model="form.destination_type"
                            class="w-100"
                            @change="onDestinationTypeChange"
                        >
                            <el-option label="Cuenta bancaria" value="bank_account" />
                            <el-option label="Caja POS abierta" value="cash" />
                        </el-select>
                        <small v-if="errors.destination_type" class="form-control-feedback text-danger">{{ errors.destination_type[0] }}</small>
                    </div>
                </div>

                <div class="col-md-8 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.destination_id }">
                        <label for="mov-dest-id" class="control-label">
                            {{ form.destination_type === 'bank_account' ? 'Cuenta bancaria' : 'Caja POS abierta' }}
                            <span class="text-danger">*</span>
                        </label>
                        <el-select
                            id="mov-dest-id"
                            v-model="form.destination_id"
                            filterable
                            placeholder="Seleccione destino"
                            class="w-100"
                            @change="onDestinationChange"
                        >
                            <el-option
                                v-for="dest in destinationOptions"
                                :key="dest.id"
                                :label="dest.description"
                                :value="dest.id"
                            />
                        </el-select>
                        <small v-if="errors.destination_id" class="form-control-feedback text-danger">{{ errors.destination_id[0] }}</small>
                    </div>
                </div>

                <div class="col-md-6 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.treasury_concept_id }">
                        <label for="mov-concept" class="control-label">
                            Concepto <span class="text-danger">*</span>
                        </label>
                        <el-select
                            id="mov-concept"
                            v-model="form.treasury_concept_id"
                            filterable
                            placeholder="Seleccione concepto"
                            class="w-100"
                        >
                            <el-option
                                v-for="concept in filteredConcepts"
                                :key="concept.id"
                                :label="concept.description"
                                :value="concept.id"
                            />
                        </el-select>
                        <small v-if="errors.treasury_concept_id" class="form-control-feedback text-danger">{{ errors.treasury_concept_id[0] }}</small>
                    </div>
                </div>

                <div class="col-md-3 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.amount }">
                        <label for="mov-amount" class="control-label">
                            Monto ({{ form.currency_type_id }}) <span class="text-danger">*</span>
                        </label>
                        <el-input
                            id="mov-amount"
                            v-model="form.amount"
                            type="number"
                            step="0.01"
                            min="0.01"
                            placeholder="0.00"
                            @input="schedulePreview"
                        />
                        <small v-if="errors.amount" class="form-control-feedback text-danger">{{ errors.amount[0] }}</small>
                    </div>
                </div>

                <div v-if="form.currency_type_id !== 'PEN'" class="col-md-3 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.exchange_rate_used }">
                        <label for="mov-fx" class="control-label">
                            Tipo de cambio <span class="text-danger">*</span>
                        </label>
                        <el-input
                            id="mov-fx"
                            v-model="form.exchange_rate_used"
                            type="number"
                            step="0.0001"
                            min="0.0001"
                            placeholder="3.7500"
                            @input="schedulePreview"
                        />
                        <small v-if="fxNote" class="text-muted d-block">{{ fxNote }}</small>
                        <small v-if="errors.exchange_rate_used" class="form-control-feedback text-danger">{{ errors.exchange_rate_used[0] }}</small>
                    </div>
                </div>

                <div class="col-md-5 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.payment_method_type_id }">
                        <label for="mov-method" class="control-label">Método de pago</label>
                        <el-select
                            id="mov-method"
                            v-model="form.payment_method_type_id"
                            clearable
                            filterable
                            placeholder="Seleccione método"
                            class="w-100"
                            @change="onMethodChange"
                        >
                            <el-option
                                v-for="m in tables.payment_method_types"
                                :key="m.id"
                                :label="m.description"
                                :value="m.id"
                            />
                        </el-select>
                        <small v-if="errors.payment_method_type_id" class="form-control-feedback text-danger">{{ errors.payment_method_type_id[0] }}</small>
                    </div>
                </div>

                <div class="col-md-7 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.sunat_payment_code }">
                        <label for="mov-sunat-code" class="control-label">Código SUNAT (Tabla 1)</label>
                        <el-select
                            id="mov-sunat-code"
                            v-model="form.sunat_payment_code"
                            clearable
                            filterable
                            placeholder="Código medio de pago"
                            class="w-100"
                            @change="schedulePreview"
                        >
                            <el-option
                                v-for="item in tables.sunat_payment_codes"
                                :key="item.code"
                                :label="item.label"
                                :value="item.code"
                            />
                        </el-select>
                        <small v-if="errors.sunat_payment_code" class="form-control-feedback text-danger">{{ errors.sunat_payment_code[0] }}</small>
                    </div>
                </div>

                <!-- Bancarization warning & required exception -->
                <div
                    v-if="(preview.bancarization && preview.bancarization.requires_exception_or_bank_method) || errors.bancarization_exception"
                    class="col-md-12 mb-3"
                >
                    <div class="alert alert-warning mb-2" role="alert">
                        <strong>Bancarización (Ley 28194):</strong>
                        {{ (preview.bancarization && preview.bancarization.warning) || 'El monto alcanza o supera el umbral de bancarización vigente. Indique una excepción del art. 6 o cambie el medio de pago.' }}
                    </div>
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.bancarization_exception }">
                        <label for="mov-bancarization-exception" class="control-label">
                            Excepción legal aplicable (Art. 6) <span class="text-danger">*</span>
                        </label>
                        <el-select
                            id="mov-bancarization-exception"
                            v-model="form.bancarization_exception"
                            clearable
                            placeholder="Seleccione motivo de excepción"
                            class="w-100"
                        >
                            <el-option
                                v-for="ex in tables.bancarization_exceptions"
                                :key="ex.value"
                                :label="ex.label"
                                :value="ex.value"
                            />
                        </el-select>
                        <small v-if="errors.bancarization_exception" class="form-control-feedback text-danger">
                            {{ errors.bancarization_exception[0] }}
                        </small>
                    </div>
                </div>

                <div class="col-md-4 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.operation_number }">
                        <label for="mov-operation-number" class="control-label">N° de operación / voucher</label>
                        <el-input
                            id="mov-operation-number"
                            v-model="form.operation_number"
                            maxlength="60"
                            placeholder="Opcional"
                        />
                        <small v-if="errors.operation_number" class="form-control-feedback text-danger">{{ errors.operation_number[0] }}</small>
                    </div>
                </div>

                <div class="col-md-3 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.counterparty_document }">
                        <label for="mov-cp-doc" class="control-label">RUC / DNI contraparte</label>
                        <el-input
                            id="mov-cp-doc"
                            v-model="form.counterparty_document"
                            maxlength="20"
                            placeholder="Opcional"
                        />
                        <small v-if="errors.counterparty_document" class="form-control-feedback text-danger">{{ errors.counterparty_document[0] }}</small>
                    </div>
                </div>

                <div class="col-md-5 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.counterparty_name }">
                        <label for="mov-cp-name" class="control-label">Nombre / razón social contraparte</label>
                        <el-input
                            id="mov-cp-name"
                            v-model="form.counterparty_name"
                            maxlength="255"
                            placeholder="Opcional"
                        />
                        <small v-if="errors.counterparty_name" class="form-control-feedback text-danger">{{ errors.counterparty_name[0] }}</small>
                    </div>
                </div>

                <div class="col-md-12 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.description }">
                        <label for="mov-description" class="control-label">
                            Descripción <span class="text-danger">*</span>
                        </label>
                        <el-input
                            id="mov-description"
                            v-model="form.description"
                            type="textarea"
                            :rows="2"
                            maxlength="500"
                            placeholder="Detalle del movimiento"
                        />
                        <small v-if="errors.description" class="form-control-feedback text-danger">{{ errors.description[0] }}</small>
                    </div>
                </div>

                <!-- Bank-only ITF & Bank Fee section -->
                <div v-if="form.destination_type === 'bank_account'" class="col-md-12 mb-3">
                    <div class="border rounded p-3 bg-light">
                        <div class="row align-items-end">
                            <div class="col-md-6 mb-2">
                                <el-checkbox
                                    v-model="form.include_itf"
                                    :disabled="preview.is_itf_exempt"
                                    @change="itfManuallyEdited = true"
                                >
                                    Registrar ITF asociado
                                    <span v-if="preview.is_itf_exempt" class="badge badge-secondary ml-1">Cuenta exenta</span>
                                    <span v-else-if="preview.itf && preview.itf.applies" class="small text-muted">
                                        ({{ (preview.itf.rate * 100).toFixed(3) }}%)
                                    </span>
                                </el-checkbox>
                                <div v-if="form.include_itf && !preview.is_itf_exempt" class="mt-2">
                                    <label for="mov-itf-amount" class="control-label small mb-1">
                                        Monto ITF editable ({{ form.currency_type_id }})
                                    </label>
                                    <el-input
                                        id="mov-itf-amount"
                                        v-model="form.itf_amount"
                                        size="small"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        @input="itfManuallyEdited = true"
                                    />
                                    <small v-if="errors.itf_amount" class="form-control-feedback text-danger">
                                        {{ errors.itf_amount[0] }}
                                    </small>
                                </div>
                            </div>

                            <div class="col-md-6 mb-2">
                                <label for="mov-bank-fee" class="control-label small mb-1">
                                    Comisión bancaria adicional ({{ form.currency_type_id }}, opcional)
                                </label>
                                <el-input
                                    id="mov-bank-fee"
                                    v-model="form.bank_fee_amount"
                                    size="small"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    placeholder="0.00"
                                />
                                <small v-if="errors.bank_fee_amount" class="form-control-feedback text-danger">
                                    {{ errors.bank_fee_amount[0] }}
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-actions text-right pt-2">
                <el-button type="default" :disabled="saving" @click="close">Cancelar</el-button>
                <el-button type="primary" native-type="submit" :loading="saving">Guardar movimiento</el-button>
            </div>
        </form>
    </el-dialog>
</template>

<script>
export default {
    name: 'TreasuryMovementForm',
    props: {
        showDialog: {
            type: Boolean,
            default: false,
        },
        tables: {
            type: Object,
            required: true,
        },
    },
    data() {
        return {
            saving: false,
            errors: {},
            fxNote: null,
            itfManuallyEdited: false,
            previewTimer: null,
            fxSeq: 0,
            previewSeq: 0,
            preview: {
                itf: null,
                bancarization: null,
                is_itf_exempt: false,
            },
            form: this.initialForm(),
        }
    },
    computed: {
        destinationOptions() {
            return this.form.destination_type === 'bank_account'
                ? (this.tables.bank_accounts || [])
                : (this.tables.open_cashes || [])
        },
        filteredConcepts() {
            const concepts = this.tables.concepts || []
            if (this.form.kind === 'income' || this.form.kind === 'interest') {
                return concepts.filter(c => c.type === 'income')
            }
            if (['expense', 'bank_fee', 'tax_payment'].includes(this.form.kind)) {
                return concepts.filter(c => c.type === 'expense')
            }
            return concepts
        },
        datePickerOptions() {
            if (this.tables.allow_future_dates) {
                return {}
            }
            return {
                disabledDate(time) {
                    return time.getTime() > Date.now()
                },
            }
        },
    },
    beforeDestroy() {
        if (this.previewTimer) {
            clearTimeout(this.previewTimer)
        }
    },
    methods: {
        initialForm() {
            return {
                establishment_id: this.tables.default_establishment_id || null,
                date: moment().format('YYYY-MM-DD'),
                kind: 'expense',
                direction: null,
                destination_type: 'bank_account',
                destination_id: null,
                currency_type_id: 'PEN',
                amount: '',
                exchange_rate_purchase: null,
                exchange_rate_sale: null,
                exchange_rate_used: null,
                payment_method_type_id: '04',
                sunat_payment_code: '003',
                operation_number: null,
                counterparty_document: null,
                counterparty_name: null,
                treasury_concept_id: null,
                description: '',
                bancarization_exception: null,
                include_itf: false,
                itf_amount: '',
                bank_fee_amount: '',
            }
        },
        onOpen() {
            this.errors = {}
            this.fxNote = null
            this.itfManuallyEdited = false
            this.preview = { itf: null, bancarization: null, is_itf_exempt: false }
            this.form = this.initialForm()

            const accounts = this.tables.bank_accounts || []
            if (accounts.length > 0) {
                this.form.destination_id = accounts[0].id
                this.onDestinationChange(accounts[0].id)
            } else if ((this.tables.open_cashes || []).length > 0) {
                this.form.destination_type = 'cash'
                this.form.destination_id = this.tables.open_cashes[0].id
                this.onDestinationChange(this.tables.open_cashes[0].id)
            }
        },
        onKindChange() {
            if (this.form.kind === 'adjustment') {
                this.form.direction = 1
            } else {
                this.form.direction = null
            }
            const validConcept = this.filteredConcepts.some(c => c.id === this.form.treasury_concept_id)
            if (!validConcept) {
                this.form.treasury_concept_id = null
            }
            this.applyDirectionalExchangeRate()
        },
        applyDirectionalExchangeRate() {
            if (this.form.currency_type_id === 'PEN') {
                return
            }
            const purchase = this.form.exchange_rate_purchase
            const sale = this.form.exchange_rate_sale
            if (!purchase && !sale) {
                return
            }
            const isInflow = this.form.kind === 'income' || this.form.kind === 'interest' || this.form.direction === 1
            this.form.exchange_rate_used = isInflow ? (purchase || sale) : (sale || purchase)
        },
        onDestinationTypeChange() {
            this.form.destination_id = null
            this.form.currency_type_id = 'PEN'
            this.form.include_itf = false
            this.form.itf_amount = ''
            this.form.bank_fee_amount = ''
            this.itfManuallyEdited = false

            if (this.form.destination_type === 'cash') {
                this.form.payment_method_type_id = '01'
                this.form.sunat_payment_code = '008'
            } else {
                this.form.payment_method_type_id = '04'
                this.form.sunat_payment_code = '003'
            }

            if (this.destinationOptions.length > 0) {
                this.form.destination_id = this.destinationOptions[0].id
                this.onDestinationChange(this.destinationOptions[0].id)
            } else {
                this.schedulePreview()
            }
        },
        onDestinationChange(id) {
            const selected = this.destinationOptions.find(item => item.id === id)
            if (selected) {
                this.form.currency_type_id = selected.currency_type_id || 'PEN'
                if (selected.establishment_id) {
                    this.form.establishment_id = selected.establishment_id
                }
            }
            this.itfManuallyEdited = false
            this.onDateOrCurrencyChange()
        },
        onMethodChange(methodId) {
            const method = (this.tables.payment_method_types || []).find(item => item.id === methodId)
            if (method && method.sunat_code) {
                this.form.sunat_payment_code = method.sunat_code
            }
            this.schedulePreview()
        },
        async onDateOrCurrencyChange() {
            this.fxNote = null
            const seq = ++this.fxSeq
            if (this.form.currency_type_id !== 'PEN' && this.form.date) {
                try {
                    const res = await this.$http.get(`/treasury/exchange-rate/${this.form.date}`)
                    if (seq !== this.fxSeq) {
                        return
                    }
                    const fx = res.data && res.data.data ? res.data.data : null
                    if (fx && (fx.sale || fx.purchase)) {
                        this.form.exchange_rate_purchase = fx.purchase
                        this.form.exchange_rate_sale = fx.sale
                        this.applyDirectionalExchangeRate()
                        this.fxNote = fx.source === 'exact'
                            ? `SBS ${fx.date}`
                            : `Último SBS (${fx.date})`
                    } else {
                        this.fxNote = 'Sin TC SBS registrado'
                    }
                } catch (e) {
                    if (seq === this.fxSeq) {
                        this.fxNote = 'No se pudo consultar TC'
                    }
                }
            } else {
                this.form.exchange_rate_purchase = null
                this.form.exchange_rate_sale = null
                this.form.exchange_rate_used = null
            }

            this.schedulePreview()
        },
        schedulePreview() {
            if (this.previewTimer) {
                clearTimeout(this.previewTimer)
            }
            this.previewTimer = setTimeout(() => {
                this.fetchPreview()
            }, 180)
        },
        async fetchPreview() {
            if (!this.form.destination_id) {
                return
            }
            const seq = ++this.previewSeq
            try {
                const res = await this.$http.post('/treasury/movements/preview', {
                    date: this.form.date,
                    amount: this.form.amount || 0,
                    destination_type: this.form.destination_type,
                    destination_id: this.form.destination_id,
                    currency_type_id: this.form.currency_type_id,
                    payment_method_type_id: this.form.payment_method_type_id,
                    sunat_payment_code: this.form.sunat_payment_code,
                    exchange_rate_sale: this.form.exchange_rate_sale,
                })
                if (seq !== this.previewSeq) {
                    return
                }
                const data = res.data && res.data.data ? res.data.data : {}
                this.preview = {
                    itf: data.itf || null,
                    bancarization: data.bancarization || null,
                    is_itf_exempt: Boolean(data.is_itf_exempt),
                }

                if (data.bancarization && data.bancarization.is_cash && data.bancarization.suggested_sunat_code) {
                    this.form.sunat_payment_code = data.bancarization.suggested_sunat_code
                }

                if (this.form.destination_type === 'bank_account' && data.itf && data.itf.applies) {
                    if (!this.itfManuallyEdited) {
                        this.form.include_itf = data.itf.amount > 0
                        this.form.itf_amount = data.itf.amount > 0 ? data.itf.amount.toFixed(2) : ''
                    }
                } else if (this.preview.is_itf_exempt) {
                    this.form.include_itf = false
                    this.form.itf_amount = ''
                }
            } catch (e) {
                // Keep UI responsive even if preview fails.
            }
        },
        async submit() {
            if (this.saving) {
                return
            }
            this.saving = true
            this.errors = {}

            const payload = Object.assign({}, this.form)
            if (!payload.include_itf) {
                payload.itf_amount = null
            }
            if (payload.bank_fee_amount === '') {
                payload.bank_fee_amount = null
            }

            try {
                const res = await this.$http.post('/treasury/movements', payload)
                this.$message.success((res.data && res.data.message) || 'Movimiento registrado correctamente.')
                this.$emit('saved')
                this.close()
            } catch (error) {
                if (error.response && error.response.status === 422) {
                    this.errors = error.response.data.errors || error.response.data || {}
                } else if (error.response && error.response.data && error.response.data.message) {
                    this.$message.error(error.response.data.message)
                } else {
                    this.$message.error('No se pudo registrar el movimiento.')
                }
            } finally {
                this.saving = false
            }
        },
        close() {
            this.$emit('update:showDialog', false)
        },
    },
}
</script>
