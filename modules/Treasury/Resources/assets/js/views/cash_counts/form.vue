<template>
    <el-dialog
        title="Registrar arqueo de caja"
        :visible="visible"
        width="760px"
        append-to-body
        :close-on-click-modal="false"
        @open="onOpen"
        @close="close"
    >
        <div v-if="errorGeneral" class="alert alert-danger small" role="alert">
            {{ errorGeneral }}
        </div>

        <form @submit.prevent="submit">
            <div class="row mb-3">
                <div class="col-md-8 mb-2">
                    <label for="cc-form-cash" class="control-label">Caja POS</label>
                    <el-select
                        id="cc-form-cash"
                        v-model="form.cash_id"
                        filterable
                        placeholder="Seleccione una caja"
                        class="w-100"
                        @change="loadSystemBalance"
                    >
                        <el-option
                            v-for="c in cashes"
                            :key="c.id"
                            :label="c.description"
                            :value="c.id"
                        />
                    </el-select>
                    <small v-if="fieldError('cash_id')" class="text-danger">{{ fieldError('cash_id') }}</small>
                </div>

                <div class="col-md-4 mb-2">
                    <label class="control-label">Saldo según sistema (PEN)</label>
                    <div class="form-control bg-light text-right font-weight-bold">
                        <span v-if="loadingBalance"><i class="el-icon-loading"></i> Consultando...</span>
                        <span v-else-if="systemBalance !== null">{{ money(systemBalance, 'PEN') }}</span>
                        <span v-else class="text-muted">-</span>
                    </div>
                </div>
            </div>

            <!-- Peru PEN denomination grid -->
            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>Denominación</th>
                            <th class="text-center">Tipo</th>
                            <th class="text-right">Valor (S/)</th>
                            <th class="text-center" style="width: 140px;">Cantidad</th>
                            <th class="text-right">Subtotal (S/)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(item, idx) in form.denominations" :key="item.value">
                            <td>
                                <label :for="'cc-denom-' + idx" class="mb-0">{{ item.label }}</label>
                            </td>
                            <td class="text-center small text-muted">{{ item.type === 'bill' ? 'Billete' : 'Moneda' }}</td>
                            <td class="text-right text-nowrap">{{ money(item.value, 'PEN') }}</td>
                            <td class="text-center">
                                <input
                                    :id="'cc-denom-' + idx"
                                    v-model.number="item.qty"
                                    type="number"
                                    min="0"
                                    step="1"
                                    class="form-control form-control-sm text-right"
                                >
                                <small v-if="fieldError('denominations.' + idx + '.qty')" class="text-danger d-block">
                                    {{ fieldError('denominations.' + idx + '.qty') }}
                                </small>
                            </td>
                            <td class="text-right text-nowrap font-weight-bold">
                                {{ money(lineSubtotal(item), 'PEN') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
                <small v-if="fieldError('denominations')" class="text-danger">{{ fieldError('denominations') }}</small>
            </div>

            <!-- Live comparison summary -->
            <div class="row mb-3">
                <div class="col-md-4 mb-2">
                    <div class="border rounded p-2">
                        <div class="small text-muted">Saldo contado</div>
                        <div class="h5 mb-0">{{ money(countedBalance, 'PEN') }}</div>
                    </div>
                </div>
                <div class="col-md-4 mb-2">
                    <div class="border rounded p-2">
                        <div class="small text-muted">Saldo sistema</div>
                        <div class="h5 mb-0">{{ systemBalance !== null ? money(systemBalance, 'PEN') : '-' }}</div>
                    </div>
                </div>
                <div class="col-md-4 mb-2">
                    <div class="border rounded p-2">
                        <div class="small text-muted">Diferencia</div>
                        <div class="h5 mb-0" :class="amountClass(difference)">
                            <span v-if="systemBalance !== null">
                                {{ signedMoney(difference, 'PEN') }}
                                <span class="badge ml-1" :class="differenceBadgeClass">{{ differenceLabel }}</span>
                            </span>
                            <span v-else class="text-muted">-</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-group mb-0">
                <label for="cc-form-notes" class="control-label">Observaciones (opcional)</label>
                <textarea
                    id="cc-form-notes"
                    v-model="form.notes"
                    rows="2"
                    maxlength="1000"
                    class="form-control"
                    placeholder="Detalle del conteo, justificación de faltante o sobrante, etc."
                ></textarea>
                <small v-if="fieldError('notes')" class="text-danger">{{ fieldError('notes') }}</small>
            </div>
        </form>

        <span slot="footer" class="dialog-footer">
            <el-button @click="close">Cancelar</el-button>
            <el-button type="primary" :loading="saving" :disabled="!form.cash_id || loadingBalance" @click="submit">
                Guardar arqueo
            </el-button>
        </span>
    </el-dialog>
</template>

<script>
import { amountClass, money, parseError, signedMoney } from '../reconciliation/helpers'

const FALLBACK_DENOMINATIONS = [
    200, 100, 50, 20, 10, 5, 2, 1, 0.5, 0.2, 0.1,
].map(value => ({
    value,
    type: value >= 10 ? 'bill' : 'coin',
    label: `${value >= 10 ? 'Billete' : 'Moneda'} S/ ${value.toFixed(2)}`,
    qty: 0,
}))

export default {
    name: 'TreasuryCashCountForm',
    props: {
        visible: { type: Boolean, default: false },
        cashes: { type: Array, default: () => [] },
        defaultDenominations: { type: Array, default: () => [] },
    },
    data() {
        return {
            form: {
                cash_id: null,
                denominations: [],
                notes: '',
            },
            systemBalance: null,
            loadingBalance: false,
            balanceRequestSeq: 0,
            saving: false,
            errorGeneral: null,
            fieldErrors: {},
        }
    },
    computed: {
        countedBalance() {
            const sum = this.form.denominations.reduce((acc, item) => acc + this.lineSubtotal(item), 0)
            return Math.round(sum * 100) / 100
        },
        difference() {
            if (this.systemBalance === null) return 0
            return Math.round((this.countedBalance - this.systemBalance) * 100) / 100
        },
        differenceLabel() {
            if (this.difference === 0) return 'Cuadrado'
            return this.difference > 0 ? 'Sobrante' : 'Faltante'
        },
        differenceBadgeClass() {
            if (this.difference === 0) return 'badge-success'
            return this.difference > 0 ? 'badge-info' : 'badge-danger'
        },
    },
    methods: {
        money,
        signedMoney,
        amountClass,
        lineSubtotal(item) {
            const qty = Math.max(0, parseInt(item.qty || 0, 10) || 0)
            return Math.round(parseFloat(item.value || 0) * qty * 100) / 100
        },
        fieldError(key) {
            const errs = this.fieldErrors[key]
            return Array.isArray(errs) && errs.length ? errs[0] : null
        },
        onOpen() {
            this.errorGeneral = null
            this.fieldErrors = {}
            this.systemBalance = null
            const base = (this.defaultDenominations && this.defaultDenominations.length)
                ? this.defaultDenominations
                : FALLBACK_DENOMINATIONS
            this.form.denominations = base.map(item => ({
                value: parseFloat(item.value),
                type: item.type || (parseFloat(item.value) >= 10 ? 'bill' : 'coin'),
                label: item.label || `S/ ${parseFloat(item.value).toFixed(2)}`,
                qty: 0,
            }))
            this.form.notes = ''

            const openCash = this.cashes.find(c => c.state) || this.cashes[0]
            this.form.cash_id = openCash ? openCash.id : null
            if (this.form.cash_id) {
                this.loadSystemBalance()
            }
        },
        async loadSystemBalance() {
            if (!this.form.cash_id) {
                this.systemBalance = null
                return
            }
            const seq = ++this.balanceRequestSeq
            this.loadingBalance = true
            this.errorGeneral = null
            try {
                const { data } = await this.$http.get(`/treasury/cash-counts/system-balance/${this.form.cash_id}`)
                if (seq !== this.balanceRequestSeq) return
                this.systemBalance = parseFloat(data.data.system_balance || 0)
            } catch (error) {
                if (seq !== this.balanceRequestSeq) return
                this.systemBalance = null
                this.errorGeneral = parseError(error).general
            } finally {
                if (seq === this.balanceRequestSeq) {
                    this.loadingBalance = false
                }
            }
        },
        async submit() {
            if (this.saving || this.loadingBalance || !this.form.cash_id) return
            this.saving = true
            this.errorGeneral = null
            this.fieldErrors = {}
            try {
                const payload = {
                    cash_id: this.form.cash_id,
                    denominations: this.form.denominations.map(item => ({
                        value: item.value,
                        qty: parseInt(item.qty || 0, 10) || 0,
                    })),
                    notes: this.form.notes || null,
                }
                const { data } = await this.$http.post('/treasury/cash-counts', payload)
                this.$message.success(data.message || 'Arqueo de caja registrado.')
                this.$emit('saved', data.data)
                this.close()
            } catch (error) {
                const parsed = parseError(error)
                this.fieldErrors = parsed.fields
                this.errorGeneral = parsed.general
            } finally {
                this.saving = false
            }
        },
        close() {
            this.$emit('update:visible', false)
        },
    },
}
</script>
