<template>
    <el-dialog :title="titleDialog" :visible="showDialog" @close="close" @open="create">
        <form autocomplete="off" @submit.prevent="submit">
            <div class="form-body" v-loading="loading_record">
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group" :class="{'has-danger': errors.bank_id}">
                            <label for="bank-account-bank" class="control-label">Banco</label>
                            <el-select id="bank-account-bank" v-model="form.bank_id">
                                <el-option v-for="option in banks" :key="option.id" :value="option.id" :label="option.description"></el-option>
                            </el-select>
                            <small class="form-control-feedback" v-if="errors.bank_id" v-text="errors.bank_id[0]"></small>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="form-group" :class="{'has-danger': errors.description}">
                            <label for="bank-account-description" class="control-label">Descripción</label>
                            <el-input id="bank-account-description" v-model="form.description"></el-input>
                            <small class="form-control-feedback" v-if="errors.description" v-text="errors.description[0]"></small>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-8">
                        <div class="form-group" :class="{'has-danger': errors.number}">
                            <label for="bank-account-number" class="control-label">Número</label>
                            <el-input id="bank-account-number" v-model="form.number"></el-input>
                            <small class="form-control-feedback" v-if="errors.number" v-text="errors.number[0]"></small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group" :class="{'has-danger': errors.currency_type_id}">
                            <label for="bank-account-currency" class="control-label">Moneda</label>
                            <el-select id="bank-account-currency" v-model="form.currency_type_id">
                                <el-option v-for="option in currency_types" :key="option.id" :value="option.id" :label="option.description"></el-option>
                            </el-select>
                            <small class="form-control-feedback" v-if="errors.currency_type_id" v-text="errors.currency_type_id[0]"></small>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="form-group" :class="{'has-danger': errors.cci}">
                            <label for="bank-account-cci" class="control-label">CCI</label>
                            <el-input id="bank-account-cci" v-model="form.cci"></el-input>
                            <small class="form-control-feedback" v-if="errors.cci" v-text="errors.cci[0]"></small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group" :class="{'has-danger': errors.initial_balance}">
                            <label for="bank-account-initial-balance" class="control-label">Saldo inicial</label>
                            <el-input id="bank-account-initial-balance" v-model="form.initial_balance"></el-input>
                            <small class="form-control-feedback" v-if="errors.initial_balance" v-text="errors.initial_balance[0]"></small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group" :class="{'has-danger': errors.account_type}">
                            <label for="bank-account-type" class="control-label">Tipo de cuenta</label>
                            <el-select id="bank-account-type" v-model="form.account_type" @change="onAccountTypeChange">
                                <el-option v-for="option in account_types" :key="option.id" :value="option.id" :label="option.description"></el-option>
                            </el-select>
                            <small class="form-control-feedback" v-if="errors.account_type" v-text="errors.account_type[0]"></small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group" :class="{'has-danger': errors.pcge_account}">
                            <label for="bank-account-pcge" class="control-label">Cuenta contable (PCGE)</label>
                            <el-input id="bank-account-pcge" v-model="form.pcge_account" placeholder="Ej. 1041"></el-input>
                            <small class="form-control-feedback" v-if="errors.pcge_account" v-text="errors.pcge_account[0]"></small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group" :class="{'has-danger': errors.establishment_id}">
                            <label for="bank-account-establishment" class="control-label">Sede</label>
                            <el-select id="bank-account-establishment" v-model="form.establishment_id" clearable placeholder="Todas las sedes">
                                <el-option v-for="option in establishments" :key="option.id" :value="option.id" :label="option.description"></el-option>
                            </el-select>
                            <small class="form-control-feedback" v-if="errors.establishment_id" v-text="errors.establishment_id[0]"></small>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group" :class="{'has-danger': errors.sunat_financial_entity_code}">
                            <label for="bank-account-entity-code" class="control-label">Código de entidad financiera (PLE, Tabla 3)</label>
                            <el-select id="bank-account-entity-code" v-model="form.sunat_financial_entity_code" clearable filterable allow-create default-first-option placeholder="Ej. 02">
                                <el-option v-for="option in financial_entity_codes" :key="option.id" :value="option.id" :label="option.description"></el-option>
                            </el-select>
                            <small class="form-text text-muted">Referencial: valide el código en el validador oficial del PLE. Se exige para exportar el Libro Caja y Bancos (1.2).</small>
                            <small class="form-control-feedback" v-if="errors.sunat_financial_entity_code" v-text="errors.sunat_financial_entity_code[0]"></small>
                        </div>
                    </div>
                    <div class="col-md-6 form-group mt-3">
                        <el-switch id="bank-account-show-docs" v-model="form.show_in_documents"></el-switch>
                        <label for="bank-account-show-docs">Mostrar cuenta en los reportes de comprobantes</label>
                    </div>
                    <div class="col-md-6 form-group mt-3">
                        <el-switch id="bank-account-itf-exempt" v-model="form.itf_exempt"></el-switch>
                        <label for="bank-account-itf-exempt">Exenta de ITF (ej. detracciones BN)</label>
                    </div>
                </div>
            </div>
            <div class="form-actions text-right mt-4">
                <el-button @click.prevent="close()">Cancelar</el-button>
                <el-button type="primary" native-type="submit" :loading="loading_submit" :disabled="loading_record">Guardar</el-button>
            </div>
        </form>
    </el-dialog>

</template>

<script>
    export default {
        props: {
            showDialog: {},
            recordId: {},
            resource: {
                type: String,
                default: 'bank_accounts',
            },
        },
        data() {
            return {
                loading_submit: false,
                loading_record: false,
                titleDialog: null,
                errors: {},
                form: {},
                banks: [],
                currency_types: [],
                establishments: [],
                financial_entity_codes: [],
                account_types: [
                    { id: 'checking', description: 'Cuenta corriente' },
                    { id: 'savings', description: 'Cuenta de ahorros' },
                    { id: 'detraction_bn', description: 'Detracciones Banco de la Nación' },
                    { id: 'card', description: 'Tarjeta' },
                    { id: 'other', description: 'Otra' },
                ],
            }
        },
        created() {
            this.initForm()
            this.$http.get(`/${this.resource}/tables`)
                .then(response => {
                    this.banks = response.data.banks || []
                    this.currency_types = response.data.currency_types || []
                    this.establishments = response.data.establishments || []
                    this.financial_entity_codes = response.data.financial_entity_codes || []
                    if (response.data.account_types && response.data.account_types.length > 0) {
                        this.account_types = response.data.account_types
                    }
                })
                .catch(() => {
                    this.$message.error('No se pudieron cargar los catálogos de cuentas bancarias.')
                })
        },
        methods: {
            initForm() {
                this.errors = {}
                this.form = {
                    id: null,
                    bank_id: null,
                    description: null,
                    number: null,
                    currency_type_id: null,
                    cci: null,
                    initial_balance: 0,
                    show_in_documents: false,
                    account_type: 'checking',
                    pcge_account: null,
                    sunat_financial_entity_code: null,
                    itf_exempt: false,
                    establishment_id: null,
                }
            },
            onAccountTypeChange(value) {
                if (value === 'detraction_bn') {
                    this.form.itf_exempt = true
                }
            },
            create() {
                this.titleDialog = (this.recordId) ? 'Editar Cuenta Bancaria' : 'Nueva Cuenta Bancaria'
                if (this.recordId) {
                    this.loading_record = true
                    this.$http.get(`/${this.resource}/record/${this.recordId}`)
                        .then(response => {
                            this.form = Object.assign({}, this.form, response.data.data || {})
                        })
                        .catch(() => {
                            this.$message.error('No se pudo cargar la cuenta bancaria seleccionada.')
                            this.close()
                        })
                        .then(() => {
                            this.loading_record = false
                        })
                }
            },
            submit() {
                this.loading_submit = true
                this.errors = {}
                this.$http.post(`/${this.resource}`, this.form)
                    .then(response => {
                        if (response.data.success) {
                            this.$message.success(response.data.message)
                            this.$eventHub.$emit('reloadData')
                            this.close()
                        } else {
                            this.$message.error(response.data.message || 'No se pudo guardar la cuenta bancaria.')
                        }
                    })
                    .catch(error => {
                        const status = error.response ? error.response.status : null
                        if (status === 422 && error.response.data) {
                            const payload = error.response.data
                            this.errors = payload.errors || (typeof payload.message === 'object' ? payload.message : payload)
                        } else {
                            const message = (error.response && error.response.data && typeof error.response.data.message === 'string')
                                ? error.response.data.message
                                : 'Ocurrió un error al guardar la cuenta bancaria.'
                            this.$message.error(message)
                        }
                    })
                    .then(() => {
                        this.loading_submit = false
                    })
            },
            close() {
                this.$emit('update:showDialog', false)
                this.initForm()
            },
        }
    }
</script>
