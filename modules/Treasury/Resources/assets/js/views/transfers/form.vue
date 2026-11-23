<template>
    <el-dialog
        title="Nueva transferencia entre cuentas y cajas"
        :visible="showDialog"
        width="760px"
        @open="onOpen"
        @close="close"
    >
        <form autocomplete="off" @submit.prevent="submit">
            <div class="row">
                <div class="col-md-4 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.date }">
                        <label for="trf-date" class="control-label">
                            Fecha <span class="text-danger">*</span>
                        </label>
                        <el-date-picker
                            id="trf-date"
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
                        <label for="trf-establishment" class="control-label">
                            Sede <span class="text-danger">*</span>
                        </label>
                        <el-select id="trf-establishment" v-model="form.establishment_id" class="w-100">
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
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.operation_number }">
                        <label for="trf-op-number" class="control-label">N° de operación</label>
                        <el-input id="trf-op-number" v-model="form.operation_number" maxlength="60" placeholder="Opcional" />
                        <small v-if="errors.operation_number" class="form-control-feedback text-danger">{{ errors.operation_number[0] }}</small>
                    </div>
                </div>

                <!-- Origin -->
                <div class="col-md-4 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.origin_type }">
                        <label for="trf-origin-type" class="control-label">
                            Tipo origen <span class="text-danger">*</span>
                        </label>
                        <el-select id="trf-origin-type" v-model="form.origin_type" class="w-100" @change="onOriginTypeChange">
                            <el-option label="Cuenta bancaria" value="bank_account" />
                            <el-option label="Caja POS abierta" value="cash" />
                        </el-select>
                        <small v-if="errors.origin_type" class="form-control-feedback text-danger">{{ errors.origin_type[0] }}</small>
                    </div>
                </div>

                <div class="col-md-8 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.origin_id }">
                        <label for="trf-origin-id" class="control-label">
                            Cuenta / caja de origen <span class="text-danger">*</span>
                        </label>
                        <el-select
                            id="trf-origin-id"
                            v-model="form.origin_id"
                            filterable
                            placeholder="Seleccione origen"
                            class="w-100"
                            @change="onDateOrCurrencyChange"
                        >
                            <el-option
                                v-for="item in originOptions"
                                :key="item.id"
                                :label="item.description"
                                :value="item.id"
                            />
                        </el-select>
                        <small v-if="errors.origin_id" class="form-control-feedback text-danger">{{ errors.origin_id[0] }}</small>
                    </div>
                </div>

                <!-- Target -->
                <div class="col-md-4 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.target_type }">
                        <label for="trf-target-type" class="control-label">
                            Tipo destino <span class="text-danger">*</span>
                        </label>
                        <el-select id="trf-target-type" v-model="form.target_type" class="w-100" @change="onTargetTypeChange">
                            <el-option label="Cuenta bancaria" value="bank_account" />
                            <el-option label="Caja POS abierta" value="cash" />
                        </el-select>
                        <small v-if="errors.target_type" class="form-control-feedback text-danger">{{ errors.target_type[0] }}</small>
                    </div>
                </div>

                <div class="col-md-8 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.target_id }">
                        <label for="trf-target-id" class="control-label">
                            Cuenta / caja de destino <span class="text-danger">*</span>
                        </label>
                        <el-select
                            id="trf-target-id"
                            v-model="form.target_id"
                            filterable
                            placeholder="Seleccione destino"
                            class="w-100"
                            @change="onDateOrCurrencyChange"
                        >
                            <el-option
                                v-for="item in targetOptions"
                                :key="item.id"
                                :label="item.description"
                                :value="item.id"
                            />
                        </el-select>
                        <small v-if="errors.target_id" class="form-control-feedback text-danger">{{ errors.target_id[0] }}</small>
                    </div>
                </div>

                <!-- Amounts & FX -->
                <div class="col-md-4 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.amount }">
                        <label for="trf-amount" class="control-label">
                            Monto origen ({{ originCurrency }}) <span class="text-danger">*</span>
                        </label>
                        <el-input
                            id="trf-amount"
                            v-model="form.amount"
                            type="number"
                            step="0.01"
                            min="0.01"
                            placeholder="0.00"
                            @input="onAmountOrRateChange"
                        />
                        <small v-if="errors.amount" class="form-control-feedback text-danger">{{ errors.amount[0] }}</small>
                    </div>
                </div>

                <div v-if="needsExchangeRate" class="col-md-4 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.exchange_rate_used }">
                        <label for="trf-fx" class="control-label">
                            Tipo de cambio <span class="text-danger">*</span>
                        </label>
                        <el-input
                            id="trf-fx"
                            v-model="form.exchange_rate_used"
                            type="number"
                            step="0.0001"
                            min="0.0001"
                            placeholder="3.7500"
                            @input="onAmountOrRateChange"
                        />
                        <small v-if="fxNote" class="text-muted d-block">{{ fxNote }}</small>
                        <small v-if="errors.exchange_rate_used" class="form-control-feedback text-danger">{{ errors.exchange_rate_used[0] }}</small>
                    </div>
                </div>

                <div v-if="isCrossCurrency" class="col-md-4 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.target_amount }">
                        <label for="trf-target-amount" class="control-label">
                            Monto destino ({{ targetCurrency }})
                        </label>
                        <el-input
                            id="trf-target-amount"
                            v-model="form.target_amount"
                            type="number"
                            step="0.01"
                            min="0.01"
                            placeholder="Calculado automáticamente"
                        />
                        <small v-if="errors.target_amount" class="form-control-feedback text-danger">{{ errors.target_amount[0] }}</small>
                    </div>
                </div>

                <div class="col-md-12 mb-3">
                    <div class="form-group mb-0" :class="{ 'has-danger': errors.description }">
                        <label for="trf-description" class="control-label">
                            Descripción <span class="text-danger">*</span>
                        </label>
                        <el-input
                            id="trf-description"
                            v-model="form.description"
                            maxlength="500"
                            placeholder="Ej. Depósito de ventas del día / Traslado de fondos entre cuentas"
                        />
                        <small v-if="errors.description" class="form-control-feedback text-danger">{{ errors.description[0] }}</small>
                    </div>
                </div>

                <!-- ITF & Bank Fee -->
                <div v-if="hasBankLeg" class="col-md-12 mb-3">
                    <div class="border rounded p-3 bg-light">
                        <div class="mb-2">
                            <el-checkbox v-model="form.same_holder" @change="schedulePreview">
                                Transferencia entre cuentas del mismo titular (exonerada de ITF)
                            </el-checkbox>
                        </div>
                        <div class="row align-items-end">
                            <div class="col-md-6 mb-2">
                                <el-checkbox
                                    v-model="form.include_itf"
                                    :disabled="form.same_holder || bankLegIsItfExempt"
                                    @change="itfManuallyEdited = true"
                                >
                                    Registrar ITF asociado
                                    <span v-if="form.same_holder || bankLegIsItfExempt" class="badge badge-secondary ml-1">Exonerado</span>
                                </el-checkbox>
                                <div v-if="form.include_itf && !form.same_holder && !bankLegIsItfExempt" class="mt-2">
                                    <label for="trf-itf-amount" class="control-label small mb-1">
                                        Monto ITF editable ({{ bankLegCurrency }})
                                    </label>
                                    <el-input
                                        id="trf-itf-amount"
                                        v-model="form.itf_amount"
                                        size="small"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        @input="itfManuallyEdited = true"
                                    />
                                    <small v-if="errors.itf_amount" class="form-control-feedback text-danger">{{ errors.itf_amount[0] }}</small>
                                </div>
                            </div>

                            <div class="col-md-6 mb-2">
                                <label for="trf-bank-fee" class="control-label small mb-1">
                                    Comisión bancaria ({{ bankLegCurrency }}, opcional)
                                </label>
                                <el-input
                                    id="trf-bank-fee"
                                    v-model="form.bank_fee_amount"
                                    size="small"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    placeholder="0.00"
                                />
                                <small v-if="errors.bank_fee_amount" class="form-control-feedback text-danger">{{ errors.bank_fee_amount[0] }}</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-actions text-right pt-2">
                <el-button type="default" :disabled="saving" @click="close">Cancelar</el-button>
                <el-button type="primary" native-type="submit" :loading="saving">Registrar transferencia</el-button>
            </div>
        </form>
    </el-dialog>
</template>

<script>
export default {
    name: 'TreasuryTransferForm',
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
            form: this.initialForm(),
        }
    },
    computed: {
        originOptions() {
            return this.form.origin_type === 'bank_account'
                ? (this.tables.bank_accounts || [])
                : (this.tables.open_cashes || [])
        },
        targetOptions() {
            return this.form.target_type === 'bank_account'
                ? (this.tables.bank_accounts || [])
                : (this.tables.open_cashes || [])
        },
        selectedOrigin() {
            return this.originOptions.find(item => item.id === this.form.origin_id) || null
        },
        selectedTarget() {
            return this.targetOptions.find(item => item.id === this.form.target_id) || null
        },
        originCurrency() {
            return this.selectedOrigin ? (this.selectedOrigin.currency_type_id || 'PEN') : 'PEN'
        },
        targetCurrency() {
            return this.selectedTarget ? (this.selectedTarget.currency_type_id || 'PEN') : 'PEN'
        },
        isCrossCurrency() {
            return this.originCurrency !== this.targetCurrency
        },
        needsExchangeRate() {
            return this.isCrossCurrency || this.originCurrency !== 'PEN' || this.targetCurrency !== 'PEN'
        },
        hasBankLeg() {
            return this.form.origin_type === 'bank_account' || this.form.target_type === 'bank_account'
        },
        bankLeg() {
            if (this.form.origin_type === 'bank_account') {
                return this.selectedOrigin
            }
            if (this.form.target_type === 'bank_account') {
                return this.selectedTarget
            }
            return null
        },
        bankLegCurrency() {
            return this.bankLeg ? (this.bankLeg.currency_type_id || 'PEN') : 'PEN'
        },
        bankLegIsItfExempt() {
            return Boolean(this.bankLeg && this.bankLeg.itf_exempt)
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
                origin_type: 'bank_account',
                origin_id: null,
                target_type: 'bank_account',
                target_id: null,
                amount: '',
                target_amount: '',
                exchange_rate_purchase: null,
                exchange_rate_sale: null,
                exchange_rate_used: null,
                operation_number: null,
                description: '',
                same_holder: true,
                include_itf: false,
                itf_amount: '',
                bank_fee_amount: '',
            }
        },
        onOpen() {
            this.errors = {}
            this.fxNote = null
            this.itfManuallyEdited = false
            this.form = this.initialForm()

            const banks = this.tables.bank_accounts || []
            if (banks.length >= 2) {
                this.form.origin_id = banks[0].id
                this.form.target_id = banks[1].id
            } else if (banks.length === 1 && (this.tables.open_cashes || []).length > 0) {
                this.form.origin_type = 'cash'
                this.form.origin_id = this.tables.open_cashes[0].id
                this.form.target_type = 'bank_account'
                this.form.target_id = banks[0].id
            }

            this.onDateOrCurrencyChange()
        },
        onOriginTypeChange() {
            this.form.origin_id = this.originOptions.length > 0 ? this.originOptions[0].id : null
            this.onDateOrCurrencyChange()
        },
        onTargetTypeChange() {
            this.form.target_id = this.targetOptions.length > 0 ? this.targetOptions[0].id : null
            this.onDateOrCurrencyChange()
        },
        async onDateOrCurrencyChange() {
            this.fxNote = null
            const seq = ++this.fxSeq
            if (this.needsExchangeRate && this.form.date) {
                try {
                    const res = await this.$http.get(`/treasury/exchange-rate/${this.form.date}`)
                    if (seq !== this.fxSeq) {
                        return
                    }
                    const fx = res.data && res.data.data ? res.data.data : null
                    if (fx && (fx.purchase || fx.sale)) {
                        this.form.exchange_rate_purchase = fx.purchase
                        this.form.exchange_rate_sale = fx.sale
                        this.form.exchange_rate_used = this.originCurrency === 'PEN'
                            ? (fx.sale || fx.purchase)
                            : (fx.purchase || fx.sale)
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
                this.form.target_amount = ''
            }
            this.onAmountOrRateChange()
        },
        onAmountOrRateChange() {
            if (this.isCrossCurrency) {
                const amount = parseFloat(this.form.amount || 0)
                const rate = parseFloat(this.form.exchange_rate_used || 0)
                if (amount > 0 && rate > 0) {
                    const converted = this.originCurrency === 'PEN' ? (amount / rate) : (amount * rate)
                    this.form.target_amount = converted.toFixed(2)
                }
            }
            this.schedulePreview()
        },
        schedulePreview() {
            if (this.previewTimer) {
                clearTimeout(this.previewTimer)
            }
            this.previewTimer = setTimeout(() => {
                this.fetchItfPreview()
            }, 180)
        },
        async fetchItfPreview() {
            if (!this.bankLeg || this.form.same_holder || this.bankLegIsItfExempt) {
                this.form.include_itf = false
                this.form.itf_amount = ''
                return
            }

            const seq = ++this.previewSeq
            const previewAmount = this.form.origin_type === 'bank_account'
                ? (this.form.amount || 0)
                : (this.isCrossCurrency ? (this.form.target_amount || 0) : (this.form.amount || 0))

            try {
                const res = await this.$http.post('/treasury/movements/preview', {
                    date: this.form.date,
                    amount: previewAmount,
                    destination_type: 'bank_account',
                    destination_id: this.bankLeg.id,
                    same_holder: this.form.same_holder,
                })
                if (seq !== this.previewSeq) {
                    return
                }
                const itf = res.data && res.data.data ? res.data.data.itf : null
                if (itf && itf.applies && !this.itfManuallyEdited) {
                    this.form.include_itf = itf.amount > 0
                    this.form.itf_amount = itf.amount > 0 ? itf.amount.toFixed(2) : ''
                }
            } catch (e) {
                // Keep UI responsive.
            }
        },
        async submit() {
            if (this.saving) {
                return
            }
            this.saving = true
            this.errors = {}

            const payload = Object.assign({}, this.form)
            if (!this.isCrossCurrency || payload.target_amount === '') {
                payload.target_amount = null
            }
            if (!payload.include_itf) {
                payload.itf_amount = null
            }
            if (payload.bank_fee_amount === '') {
                payload.bank_fee_amount = null
            }

            try {
                const res = await this.$http.post('/treasury/transfers', payload)
                this.$message.success((res.data && res.data.message) || 'Transferencia registrada correctamente.')
                this.$emit('saved')
                this.close()
            } catch (error) {
                if (error.response && error.response.status === 422) {
                    this.errors = error.response.data.errors || error.response.data || {}
                } else if (error.response && error.response.data && error.response.data.message) {
                    this.$message.error(error.response.data.message)
                } else {
                    this.$message.error('No se pudo registrar la transferencia.')
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
