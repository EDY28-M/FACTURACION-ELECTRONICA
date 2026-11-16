<template>
    <div class="system-dashboard">
        <header class="system-page-heading">
            <div>
                <h1>Perfil</h1>
                <p>Datos del administrador y acceso al sistema</p>
            </div>
        </header>
        <div class="system-profile-layout">
        <section class="system-panel system-panel-pad">
            <form autocomplete="off" @submit.prevent="submit">
                <div class="system-form-grid">
                    <div class="system-field" :class="{'has-danger': errors.name}">
                        <label for="user-name">Nombre</label>
                        <el-input id="user-name" v-model="form.name"></el-input>
                        <small class="form-control-feedback" v-if="errors.name" v-text="errors.name[0]"></small>
                    </div>
                    <div class="system-field" :class="{'has-danger': errors.email}">
                        <label for="user-email">Correo electrónico</label>
                        <el-input id="user-email" v-model="form.email" :disabled="true"></el-input>
                        <small class="form-control-feedback" v-if="errors.email" v-text="errors.email[0]"></small>
                    </div>
                    <div class="system-field" :class="{'has-danger': errors.password}">
                        <label for="user-pass">Contraseña</label>
                        <el-input id="user-pass" type="password" v-model="form.password" autocomplete="new-password"></el-input>
                        <small class="form-control-feedback" v-if="errors.password" v-text="errors.password[0]"></small>
                    </div>
                    <div class="system-field" :class="{'has-danger': errors.password_confirmation}">
                        <label for="user-pass2">Confirmar contraseña</label>
                        <el-input id="user-pass2" type="password" v-model="form.password_confirmation" autocomplete="new-password"></el-input>
                        <small class="form-control-feedback" v-if="errors.password_confirmation" v-text="errors.password_confirmation[0]"></small>
                    </div>
                    <div class="system-field" :class="{'has-danger': errors.phone}">
                        <label for="user-phone">Teléfono</label>
                        <el-input id="user-phone" v-model="form.phone"></el-input>
                        <small class="system-field-hint">Se mostrará un icono de WhatsApp en cada cliente. Incluye el código de país, por ejemplo 51955955955.</small>
                        <small class="form-control-feedback" v-if="errors.phone" v-text="errors.phone[0]"></small>
                    </div>
                </div>
                <div class="system-form-actions">
                    <button type="submit" class="system-button system-button-primary" :disabled="loading_submit">{{ loading_submit ? 'Guardando…' : 'Guardar' }}</button>
                </div>
            </form>
        </section>
        <aside class="system-panel system-panel-pad" aria-label="Información de la cuenta">
            <h2 class="system-panel-title">Cuenta</h2>
            <dl class="system-facts">
                <div><dt>Nombre</dt><dd>{{ saved.name || 'Sin datos' }}</dd></div>
                <div><dt>Correo</dt><dd>{{ saved.email || 'Sin datos' }}</dd></div>
                <div><dt>Teléfono</dt><dd>{{ saved.phone || 'Sin datos' }}</dd></div>
            </dl>
            <p class="system-hint">La contraseña solo se cambia si completas ambos campos.</p>
        </aside>
        </div>
    </div>
</template>

<script>

    export default {
        data() {
            return {
                loading_submit: false,
                headers: null,
                resource: 'users',
                errors: {},
                form: {},
                saved: {},
            }
        },
        created() {
            this.initForm()
            this.$http.get(`/${this.resource}/record`)
                .then(response => {
                    if (response.data !== '') {
                        this.form = response.data.data
                        this.saved = Object.assign({}, response.data.data)
                    }
                })
        },
        methods: {
            initForm() {
                this.errors = {}
                this.form = {
                    id: null,
                    name: null,
                    email: null,
                    api_token: null,
                    password: null,
                    password_confirmation: null,
                    phone: null,
                }
            },
            submit() {
                this.loading_submit = true
                this.$http.post(`/${this.resource}`, this.form)
                    .then(response => {
                        if (response.data.success) {
                            this.saved = Object.assign({}, this.form)
                            this.form.password = null
                            this.form.password_confirmation = null
                            this.$message.success(response.data.message)
                        } else {
                            this.$message.error(response.data.message)
                        }
                    })
                    .catch(error => {
                        if (error.response.status === 422) {
                            this.errors = error.response.data
                        } else {
                            console.log(error)
                        }
                    })
                    .then(() => {
                        this.loading_submit = false
                    })
            },
        }
    }
</script>
