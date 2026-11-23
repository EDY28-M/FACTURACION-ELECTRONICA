<template>
    <el-dialog
        :title="dialogTitle"
        :visible="showDialog"
        width="640px"
        append-to-body
        @open="onOpen"
        @close="close"
    >
        <div v-if="purchase" class="alert alert-light border py-2 px-3 mb-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap small">
                <div>
                    <strong>Proveedor:</strong> {{ purchase.supplier_name }}
                </div>
                <div>
                    <strong>Total compra:</strong>
                    {{ formatCurrency(purchase.total, purchase.currency_type_id) }}
                </div>
            </div>
        </div>

        <div v-if="errors.fees" class="alert alert-danger py-2 px-3 mb-3" role="alert">
            {{ errors.fees[0] }}
        </div>
        <div v-if="errors.purchase_id" class="alert alert-danger py-2 px-3 mb-3" role="alert">
            {{ errors.purchase_id[0] }}
        </div>

        <form autocomplete="off" @submit.prevent="submit">
            <div class="table-responsive mb-2">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th>Fecha de vencimiento</th>
                            <th style="width: 190px;">Importe ({{ currencyCode }})</th>
                            <th style="width: 60px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(fee, idx) in fees" :key="idx">
                            <td class="align-middle">{{ idx + 1 }}</td>
                            <td>
                                <el-date-picker
                                    v-model="fee.date"
                                    type="date"
                                    value-format="yyyy-MM-dd"
                                    placeholder="YYYY-MM-DD"
                                    :aria-label="`Fecha de vencimiento cuota ${idx + 1}`"
                                    :clearable="false"
                                    class="w-100"
                                    size="small"
                                />
                                <small
                                    v-if="fieldError(`fees.${idx}.date`)"
                                    class="text-danger d-block"
                                >
                                    {{ fieldError(`fees.${idx}.date`) }}
                                </small>
                            </td>
                            <td>
                                <el-input
                                    v-model="fee.amount"
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    placeholder="0.00"
                                    :aria-label="`Importe cuota ${idx + 1}`"
                                    size="small"
                                />
                                <small
                                    v-if="fieldError(`fees.${idx}.amount`)"
                                    class="text-danger d-block"
                                >
                                    {{ fieldError(`fees.${idx}.amount`) }}
                                </small>
                            </td>
                            <td class="text-right align-middle">
                                <el-button
                                    type="danger"
                                    icon="el-icon-delete"
                                    size="mini"
                                    plain
                                    :disabled="fees.length <= 1 || saving || deleting"
                                    :aria-label="`Quitar cuota ${idx + 1}`"
                                    title="Quitar cuota"
                                    @click="removeRow(idx)"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-between align-items-center flex-wrap mb-3 pt-2 border-top">
                <el-button size="mini" type="primary" plain icon="el-icon-plus" @click="addRow">
                    Agregar cuota
                </el-button>
                <div class="small text-right">
                    <div>
                        <strong>Suma de cuotas:</strong>
                        <span :class="exceedsTotal ? 'text-danger font-weight-bold' : ''">
                            {{ formatCurrency(feesSum, currencyCode) }}
                        </span>
                    </div>
                    <div class="text-muted">
                        Diferencia respecto al total:
                        {{ formatCurrency(remainingDiff, currencyCode) }}
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                <div>
                    <el-button
                        v-if="purchase && purchase.has_installments"
                        type="danger"
                        plain
                        size="small"
                        :loading="deleting"
                        :disabled="saving || deleting"
                        @click="confirmDeleteSchedule"
                    >
                        Quitar cronograma
                    </el-button>
                </div>
                <div>
                    <el-button size="small" :disabled="saving || deleting" @click="close">Cancelar</el-button>
                    <el-button
                        type="primary"
                        size="small"
                        native-type="submit"
                        :loading="saving"
                        :disabled="saving || deleting || exceedsTotal || fees.length === 0"
                    >
                        Guardar cuotas
                    </el-button>
                </div>
            </div>
        </form>
    </el-dialog>
</template>

<script>
export default {
    props: {
        showDialog: {
            type: Boolean,
            default: false,
        },
        purchase: {
            type: Object,
            default: null,
        },
    },
    data() {
        return {
            fees: [],
            errors: {},
            saving: false,
            deleting: false,
        }
    },
    computed: {
        currencyCode() {
            return (this.purchase && this.purchase.currency_type_id) || 'PEN'
        },
        dialogTitle() {
            if (!this.purchase) {
                return 'Cronograma de cuotas de compra'
            }
            return `Cuotas de compra: ${this.purchase.number_full}`
        },
        feesSum() {
            const total = this.fees.reduce((acc, row) => {
                const value = parseFloat(row.amount)
                return acc + (Number.isFinite(value) && value > 0 ? value : 0)
            }, 0)
            return Math.round(total * 100) / 100
        },
        remainingDiff() {
            const purchaseTotal = this.purchase ? parseFloat(this.purchase.total || 0) : 0
            return Math.round((purchaseTotal - this.feesSum) * 100) / 100
        },
        exceedsTotal() {
            const purchaseTotal = this.purchase ? parseFloat(this.purchase.total || 0) : 0
            return this.feesSum > purchaseTotal + 0.0001
        },
    },
    methods: {
        onOpen() {
            this.errors = {}
            if (!this.purchase) {
                this.fees = []
                return
            }

            if (this.purchase.has_installments && Array.isArray(this.purchase.installments) && this.purchase.installments.length > 0) {
                const manualFees = this.purchase.installments.filter(item => item.source === 'purchase_fee')
                const sourceList = manualFees.length > 0 ? manualFees : this.purchase.installments
                this.fees = sourceList.map(item => ({
                    date: item.date,
                    amount: Number(item.amount).toFixed(2),
                }))
            } else {
                const defaultDate = this.purchase.date_of_due || this.purchase.date_of_issue || moment().format('YYYY-MM-DD')
                this.fees = [
                    {
                        date: defaultDate,
                        amount: Number(this.purchase.total || 0).toFixed(2),
                    },
                ]
            }
        },
        addRow() {
            const lastDate = this.fees.length > 0 && this.fees[this.fees.length - 1].date
                ? moment(this.fees[this.fees.length - 1].date, 'YYYY-MM-DD').add(30, 'days').format('YYYY-MM-DD')
                : moment().format('YYYY-MM-DD')
            const suggested = this.remainingDiff > 0 ? this.remainingDiff.toFixed(2) : ''
            this.fees.push({
                date: lastDate,
                amount: suggested,
            })
        },
        removeRow(index) {
            if (this.fees.length > 1) {
                this.fees.splice(index, 1)
            }
        },
        fieldError(key) {
            return this.errors && this.errors[key] ? this.errors[key][0] : null
        },
        submit() {
            if (!this.purchase || this.saving) {
                return
            }
            this.saving = true
            this.errors = {}

            const payload = {
                fees: this.fees.map(f => ({
                    date: f.date,
                    amount: parseFloat(f.amount),
                })),
            }

            this.$http
                .post(`/treasury/receivables-payables/purchase-fees/${this.purchase.id}`, payload)
                .then(response => {
                    this.$message.success(response.data.message || 'Cronograma guardado con éxito.')
                    this.$emit('saved')
                    this.close()
                })
                .catch(error => {
                    if (error.response && error.response.status === 422) {
                        this.errors = error.response.data.errors || {}
                    } else if (error.response && error.response.data && error.response.data.message) {
                        this.$message.error(error.response.data.message)
                    } else {
                        this.$message.error('No se pudo guardar el cronograma de cuotas.')
                    }
                })
                .then(() => {
                    this.saving = false
                })
        },
        confirmDeleteSchedule() {
            if (!this.purchase || this.deleting) {
                return
            }
            this.$confirm(
                '¿Desea eliminar el cronograma manual de cuotas de esta compra? Volverá a evaluarse por fecha de vencimiento.',
                'Confirmar eliminación',
                {
                    confirmButtonText: 'Eliminar',
                    cancelButtonText: 'Cancelar',
                    type: 'warning',
                }
            )
                .then(() => {
                    this.deleting = true
                    return this.$http.delete(`/treasury/receivables-payables/purchase-fees/${this.purchase.id}`)
                })
                .then(response => {
                    if (response) {
                        this.$message.success(response.data.message || 'Cronograma eliminado con éxito.')
                        this.$emit('saved')
                        this.close()
                    }
                })
                .catch(error => {
                    if (error && error !== 'cancel') {
                        const msg = error.response && error.response.data && error.response.data.message
                            ? error.response.data.message
                            : 'No se pudo eliminar el cronograma de cuotas.'
                        this.$message.error(msg)
                    }
                })
                .then(() => {
                    this.deleting = false
                })
        },
        close() {
            this.$emit('update:showDialog', false)
        },
        formatCurrency(raw, currencyTypeId) {
            const value = parseFloat(raw || 0)
            const symbol = currencyTypeId === 'USD' ? 'US$' : (currencyTypeId === 'PEN' ? 'S/' : (currencyTypeId || 'PEN'))
            const sign = value < 0 ? '- ' : ''
            return `${sign}${symbol} ${Math.abs(value).toLocaleString('es-PE', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            })}`
        },
    },
}
</script>
