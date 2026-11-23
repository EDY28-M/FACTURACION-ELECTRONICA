<template>
    <div class="card mb-0 pt-2 pt-md-0">
        <div class="card-header bg-info d-flex align-items-center justify-content-between flex-wrap">
            <h3 class="my-0">Tesorería — Cuentas bancarias y cajas</h3>
            <div>
                <a class="btn btn-custom btn-sm mr-2" href="/treasury">
                    Ver resumen
                </a>
                <button type="button" class="btn btn-custom btn-sm" @click.prevent="clickCreate()">
                    <i class="fa fa-plus-circle"></i> Nueva cuenta
                </button>
            </div>
        </div>

        <div class="card-body">
            <!-- Filters -->
            <form class="row mb-3" @submit.prevent="getData">
                <div class="col-md-3 col-sm-6 mb-2">
                    <label for="treasury-accounts-establishment" class="control-label">Sede</label>
                    <el-select
                        id="treasury-accounts-establishment"
                        v-model="filters.establishment_id"
                        clearable
                        placeholder="Todas las sedes"
                        class="w-100"
                        @change="getData"
                    >
                        <el-option
                            v-for="item in establishments"
                            :key="item.id"
                            :label="item.description"
                            :value="item.id"
                        />
                    </el-select>
                </div>

                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="treasury-accounts-currency" class="control-label">Moneda</label>
                    <el-select
                        id="treasury-accounts-currency"
                        v-model="filters.currency_type_id"
                        clearable
                        placeholder="Todas"
                        class="w-100"
                        @change="getData"
                    >
                        <el-option
                            v-for="item in currency_types"
                            :key="item.id"
                            :label="item.description"
                            :value="item.id"
                        />
                    </el-select>
                </div>
            </form>

            <div v-if="errorMessage" class="alert alert-danger d-flex justify-content-between align-items-center" role="alert">
                <span>{{ errorMessage }}</span>
                <el-button size="mini" type="danger" plain @click="getData">Reintentar</el-button>
            </div>

            <div v-if="loading" class="text-center py-5 text-muted" role="status" aria-live="polite">
                <i class="el-icon-loading mr-2"></i> Cargando cuentas...
            </div>

            <template v-else-if="!errorMessage">
                <h5 class="mb-2">Cuentas bancarias</h5>
                <div v-if="bankAccounts.length === 0" class="alert alert-light border text-muted mb-4">
                    Aún no hay cuentas bancarias registradas para los filtros seleccionados.
                </div>
                <div v-else class="table-responsive mb-4">
                    <table class="table table-sm table-hover">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Banco / Descripción</th>
                                <th>Número / CCI</th>
                                <th>Tipo</th>
                                <th>PCGE</th>
                                <th>Sede</th>
                                <th class="text-center">ITF</th>
                                <th class="text-right">Saldo inicial</th>
                                <th class="text-right">Saldo actual</th>
                                <th class="text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, index) in bankAccounts" :key="row.id">
                                <td>{{ index + 1 }}</td>
                                <td>
                                    <strong>{{ row.bank_description }}</strong> — {{ row.description }}
                                    <span class="badge badge-light ml-1">{{ row.currency_type_id }}</span>
                                </td>
                                <td>
                                    <div>{{ row.number }}</div>
                                    <div v-if="row.cci" class="small text-muted">CCI: {{ row.cci }}</div>
                                </td>
                                <td>{{ row.account_type_label }}</td>
                                <td>{{ row.pcge_account || '—' }}</td>
                                <td>{{ row.establishment_description }}</td>
                                <td class="text-center">
                                    <span v-if="row.itf_exempt" class="badge badge-secondary">Exenta</span>
                                    <span v-else class="text-muted small">Afecta</span>
                                </td>
                                <td class="text-right">{{ formatSignedCurrency(row.initial_balance, row.currency_type_id) }}</td>
                                <td class="text-right" :class="parseFloat(row.balance_currency) < 0 ? 'text-danger' : ''">
                                    <strong>{{ formatSignedCurrency(row.balance_currency, row.currency_type_id) }}</strong>
                                    <span v-if="row.is_negative" class="badge badge-danger ml-1">Negativo</span>
                                </td>
                                <td class="text-right">
                                    <button
                                        type="button"
                                        class="btn waves-effect waves-light btn-xs btn-info"
                                        @click.prevent="clickCreate(row.id)"
                                    >
                                        Editar
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <h5 class="mb-2">Cajas abiertas</h5>
                <div v-if="openCashes.length === 0" class="alert alert-light border text-muted mb-0">
                    No hay cajas abiertas actualmente.
                </div>
                <div v-else class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Caja</th>
                                <th>Usuario</th>
                                <th>Apertura</th>
                                <th class="text-right">Saldo inicial</th>
                                <th class="text-right">Saldo actual</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="cash in openCashes" :key="cash.id">
                                <td>{{ cash.description }}</td>
                                <td>{{ cash.user_name }}</td>
                                <td>{{ cash.date_opening }} {{ cash.time_opening }}</td>
                                <td class="text-right">{{ formatSignedCurrency(cash.beginning_balance, 'PEN') }}</td>
                                <td class="text-right" :class="cash.is_negative ? 'text-danger' : ''">
                                    <strong>{{ formatSignedCurrency(cash.balance, 'PEN') }}</strong>
                                    <span v-if="cash.is_negative" class="badge badge-danger ml-1">Negativo</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>

        <bank-accounts-form
            :showDialog.sync="showDialog"
            :recordId="recordId"
            resource="treasury/accounts"
        />
    </div>
</template>

<script>
import BankAccountsForm from '@views/bank_accounts/form.vue'

export default {
    components: { BankAccountsForm },
    data() {
        return {
            loading: false,
            errorMessage: null,
            showDialog: false,
            recordId: null,
            establishments: [],
            currency_types: [],
            filters: {
                establishment_id: null,
                currency_type_id: null,
            },
            bankAccounts: [],
            openCashes: [],
        }
    },
    created() {
        this.onReloadData = () => {
            this.getData()
        }
        this.$eventHub.$on('reloadData', this.onReloadData)
        this.loadTables()
        this.getData()
    },
    beforeDestroy() {
        this.$eventHub.$off('reloadData', this.onReloadData)
    },
    methods: {
        loadTables() {
            this.$http.get('/treasury/accounts/tables')
                .then(response => {
                    this.establishments = response.data.establishments || []
                    this.currency_types = response.data.currency_types || []
                })
                .catch(() => {})
        },
        getData() {
            this.loading = true
            this.errorMessage = null

            this.$http.get('/treasury/accounts/records', { params: this.filters })
                .then(response => {
                    const data = response.data.data || {}
                    this.bankAccounts = data.bank_accounts || []
                    this.openCashes = data.open_cashes || []
                })
                .catch(() => {
                    this.errorMessage = 'No se pudieron cargar las cuentas de tesorería. Intente nuevamente.'
                })
                .then(() => {
                    this.loading = false
                })
        },
        clickCreate(recordId = null) {
            this.recordId = recordId
            this.showDialog = true
        },
        formatSignedCurrency(rawAmount, currencyTypeId) {
            const value = parseFloat(rawAmount || 0)
            let symbol = currencyTypeId || 'PEN'
            if (currencyTypeId === 'PEN') {
                symbol = 'S/'
            } else if (currencyTypeId === 'USD') {
                symbol = 'US$'
            }
            const sign = value < 0 ? '- ' : ''
            const formatted = Math.abs(value).toLocaleString('es-PE', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            })

            return `${sign}${symbol} ${formatted}`
        },
    },
}
</script>
