<template>
    <el-dialog :title="titleDialog" :visible="showDialog" width="880px" custom-class="system-plan-dialog" :close-on-click-modal="false"
               append-to-body @close="close" @open="create" @closed="$emit('closed')">
        <form id="plan-form" class="system-plan-form" autocomplete="off" novalidate @submit.prevent="submit">
            <div class="system-plan-fields">
                <div class="system-field" :class="{'has-danger': errors.name}">
                    <label for="plan-name">Nombre</label>
                    <el-input id="plan-name" v-model="form.name" :maxlength="11" placeholder="Ej. Básico"></el-input>
                    <small class="form-control-feedback" v-if="errors.name" v-text="errors.name[0]"></small>
                </div>

                <div class="system-field" :class="{'has-danger': errors.pricing}">
                    <label for="plan-pricing">Precio</label>
                    <el-input id="plan-pricing" v-model="form.pricing" placeholder="0.00"><template slot="prepend">S/</template></el-input>
                    <small class="form-control-feedback" v-if="errors.pricing" v-text="errors.pricing[0]"></small>
                </div>

                <div class="system-field" :class="{'has-danger': errors.limit_users || errorLUser.limit_users}">
                    <label id="plan-users-label">Usuarios</label>
                    <div class="system-segmented" role="group" aria-labelledby="plan-users-label">
                        <button type="button" :class="{'is-active': users_unlimited}" :aria-pressed="String(!!users_unlimited)" @click="setUsers(true)">Ilimitados</button>
                        <button type="button" :class="{'is-active': !users_unlimited}" :aria-pressed="String(!users_unlimited)" @click="setUsers(false)">Con límite</button>
                    </div>
                    <el-input v-if="!users_unlimited" v-model="limit_users" @input="validateLUsers" placeholder="Máximo de usuarios" aria-label="Máximo de usuarios"></el-input>
                    <small class="form-control-feedback" v-if="errors.limit_users" v-text="errors.limit_users[0]"></small>
                    <small class="form-control-feedback" v-if="errorLUser.limit_users" v-text="errorLUser.limit_users[0]"></small>
                </div>

                <div class="system-field" :class="{'has-danger': errors.limit_documents || errorLDocument.limit_documents}">
                    <label id="plan-docs-label">Comprobantes</label>
                    <div class="system-segmented" role="group" aria-labelledby="plan-docs-label">
                        <button type="button" :class="{'is-active': documents_unlimited}" :aria-pressed="String(!!documents_unlimited)" @click="setDocuments(true)">Ilimitados</button>
                        <button type="button" :class="{'is-active': !documents_unlimited}" :aria-pressed="String(!documents_unlimited)" @click="setDocuments(false)">Con límite</button>
                    </div>
                    <el-input v-if="!documents_unlimited" v-model="limit_documents" @input="validateLDocuments" placeholder="Máximo de comprobantes" aria-label="Máximo de comprobantes"></el-input>
                    <small class="form-control-feedback" v-if="errors.limit_documents" v-text="errors.limit_documents[0]"></small>
                    <small class="form-control-feedback" v-if="errorLDocument.limit_documents" v-text="errorLDocument.limit_documents[0]"></small>
                </div>
            </div>

            <div class="system-plan-preview-col">
                <p class="system-aside-title">Vista previa</p>
                <article class="system-receipt" aria-label="Vista previa">
                    <div class="system-receipt-top">
                        <p class="system-receipt-meta"><span>{{ form.name || 'Nombre del plan' }}</span><b>N° {{ recordId ? String(recordId).padStart(4, '0') : '0000' }}</b></p>
                        <p class="system-receipt-price"><span class="cur">S/</span><span class="int">{{ preview.int }}</span><span class="dec">.{{ preview.dec }}</span></p>
                    </div>
                    <div class="system-receipt-bottom">
                        <dl class="system-receipt-rows">
                            <div><dt>Usuarios</dt><i class="leader" aria-hidden="true"></i><dd><span class="system-plan-limit">{{ limitText(users_unlimited, limit_users) }}</span></dd></div>
                            <div><dt>Comprobantes</dt><i class="leader" aria-hidden="true"></i><dd><span class="system-plan-limit">{{ limitText(documents_unlimited, limit_documents) }}</span></dd></div>
                        </dl>
                    </div>
                </article>
            </div>
        </form>

        <div slot="footer" class="system-dialog-foot">
            <button type="button" class="system-button" @click.prevent="close()">Descartar</button>
            <button type="submit" form="plan-form" class="system-button system-button-primary" :disabled="loading_submit">
                {{ loading_submit ? 'Guardando…' : (recordId ? 'Guardar cambios' : 'Crear plan') }}
            </button>
        </div>
    </el-dialog>
</template>

<script>
    export default {
        props: ['showDialog', 'recordId'],
        data() {
            return {
                loading_submit: false,
                titleDialog: 'Nuevo plan',
                resource: 'plans',
                documents_unlimited: false,
                users_unlimited: false,
                limit_users: null,
                limit_documents: null,
                errors: {},
                errorLDocument: {},
                errorLUser: {},
                form: {},
            }
        },
        computed: {
            preview() {
                const amount = Number(this.form.pricing)
                const [int, dec] = (isFinite(amount) ? amount : 0).toFixed(2).split('.')
                return {int: Number(int).toLocaleString('es-PE'), dec}
            }
        },
        created() {
            this.initForm()
        },
        methods: {
            initForm() {
                this.limit_users = null
                this.limit_documents = null
                this.documents_unlimited = false
                this.users_unlimited = false
                this.errors = {}
                this.errorLDocument = {}
                this.errorLUser = {}
                this.form = {
                    id: null,
                    name: null,
                    pricing: null,
                    limit_users: null,
                    limit_documents: null,
                    plan_documents: []
                }
            },
            limitText(unlimited, value) {
                if (unlimited) return 'Ilimitados'
                return value ? 'Hasta ' + Number(value).toLocaleString('es-PE') : 'Sin definir'
            },
            create() {
                this.titleDialog = this.recordId ? 'Editar plan' : 'Nuevo plan'
                if (this.recordId) {
                    this.$http.get(`/${this.resource}/record/${this.recordId}`).then(response => {
                        this.setData(response.data.data)
                    })
                }
            },
            submit() {
                if (this.validateLUsers().limit_users || this.validateLDocuments().limit_documents)
                    return

                this.transform()

                this.loading_submit = true
                this.$http.post(`${this.resource}`, this.form)
                    .then(response => {
                        if (response.data.success) {
                            this.$message.success(response.data.message)
                            this.$eventHub.$emit('reloadData')
                            this.close()
                        } else {
                            this.$message.error(response.data.message)
                        }
                    })
                    .catch(error => {
                        if (error.response.status === 422) {
                            this.errors = error.response.data
                        } else {
                            console.log(error.response)
                        }
                    })
                    .then(() => {
                        this.loading_submit = false
                    })
            },
            setData(data) {
                this.form = data
                this.form.plan_documents = Object.values(data.plan_documents)
                this.users_unlimited = data.limit_users == 0
                this.documents_unlimited = data.limit_documents == 0
                this.limit_users = this.users_unlimited ? null : data.limit_users
                this.limit_documents = this.documents_unlimited ? null : data.limit_documents
            },
            transform() {
                this.form.limit_users = this.users_unlimited ? 0 : this.limit_users
                this.form.limit_documents = this.documents_unlimited ? 0 : this.limit_documents
            },
            validateLDocuments() {
                this.errorLDocument = {}
                if (!this.documents_unlimited && !(Number(this.limit_documents) >= 1)) {
                    this.$set(this.errorLDocument, 'limit_documents', ['El límite de comprobantes debe ser mayor a cero'])
                }
                return this.errorLDocument
            },
            validateLUsers() {
                this.errorLUser = {}
                if (!this.users_unlimited && !(Number(this.limit_users) >= 1)) {
                    this.$set(this.errorLUser, 'limit_users', ['El límite de usuarios debe ser mayor a cero'])
                }
                return this.errorLUser
            },
            setUsers(unlimited) {
                this.users_unlimited = unlimited
                this.errorLUser = {}
            },
            setDocuments(unlimited) {
                this.documents_unlimited = unlimited
                this.errorLDocument = {}
            },
            close() {
                this.$emit('update:showDialog', false)
                this.initForm()
            }
        }
    }
</script>
