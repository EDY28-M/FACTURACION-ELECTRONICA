<template>
    <form autocomplete="off" novalidate @submit.prevent="submit" aria-labelledby="sc-pay-title">
        <h4 id="sc-pay-title" class="sc-section-title">Pagos con tarjeta (Culqi)</h4>
        <p class="co-help sc-section-help">
            Para cobrar con tarjeta necesita ambas claves de su cuenta en
            <a href="https://www.culqi.com" target="_blank" rel="noopener noreferrer">culqi.com</a>
            (panel Culqi, sección Desarrollo, Llaves API). Sin ellas la tienda solo recibe pedidos con pago en efectivo.
        </p>

        <p class="sc-status" :class="'is-' + status.tone" role="status">
            <strong>{{ status.title }}</strong>
            <span>{{ status.detail }}</span>
        </p>

        <div class="row">
            <div class="col-md-12">
                <div class="form-group" :class="{'has-danger': errors.token_public_culqui}">
                    <label class="control-label" for="sc-culqi-public">Clave pública</label>
                    <el-input id="sc-culqi-public" class="sc-mono" v-model="form.token_public_culqui" maxlength="255" placeholder="pk_test_… o pk_live_…" autocomplete="off" aria-describedby="sc-culqi-public-help" @input="clearError('token_public_culqui')"></el-input>
                    <small class="co-help" id="sc-culqi-public-help">Empieza con pk_test_ (modo pruebas, sin cobros reales) o pk_live_ (producción, cobros reales).</small>
                    <small class="form-control-feedback" role="alert" v-if="errors.token_public_culqui" v-text="errors.token_public_culqui[0]"></small>
                </div>
            </div>
            <div class="col-md-12">
                <div class="form-group" :class="{'has-danger': errors.token_private_culqui}">
                    <label class="control-label" for="sc-culqi-private">
                        Clave privada
                        <span class="sc-tag" v-if="hasPrivate">Configurada</span>
                        <span class="sc-tag is-muted" v-else>Sin configurar</span>
                    </label>
                    <el-input id="sc-culqi-private" class="sc-mono" type="password" v-model="form.token_private_culqui" maxlength="255" autocomplete="new-password" :placeholder="hasPrivate ? 'Guardada. Escriba una nueva para reemplazarla' : 'sk_test_… o sk_live_…'" aria-describedby="sc-culqi-private-help" @input="clearError('token_private_culqui')"></el-input>
                    <small class="co-help" id="sc-culqi-private-help">Se guarda cifrada y nunca se vuelve a mostrar. Debe ser del mismo modo que la clave pública (sk_test_ con pk_test_, sk_live_ con pk_live_).</small>
                    <small class="form-control-feedback" role="alert" v-if="errors.token_private_culqui" v-text="errors.token_private_culqui[0]"></small>
                </div>
            </div>
        </div>

        <div class="sc-actions">
            <el-button v-if="hasPrivate" type="text" class="sc-danger" :loading="removing" @click="removePrivate">Quitar clave privada</el-button>
            <span class="sc-spacer"></span>
            <span class="sc-state" :class="{'is-dirty': dirty}" aria-live="polite">{{ dirty ? 'Cambios sin guardar' : 'Sin cambios' }}</span>
            <el-button type="primary" native-type="submit" :loading="saving" :disabled="!dirty">Guardar pagos con tarjeta</el-button>
        </div>
    </form>
</template>

<script>
import section, { requestErrorMessage } from '../section'

export default {
    mixins: [section],
    data() {
        return {
            endpoint: '/ecommerce/configuration_culqui',
            removing: false
        }
    },
    computed: {
        hasPrivate() {
            return !!(this.record && this.record.has_token_private_culqui)
        },
        status() {
            const record = this.record || {}
            const hasPublic = !!record.token_public_culqui
            if (hasPublic && this.hasPrivate) {
                const live = record.culqi_mode === 'live'
                return {
                    tone: live ? 'ok' : 'warn',
                    title: live ? 'Activo en modo producción' : 'Activo en modo pruebas',
                    detail: live ? 'Los cobros con tarjeta son reales.' : 'Los cobros son de prueba: no se cobra dinero real. Cambie a claves pk_live_ / sk_live_ para vender.'
                }
            }
            if (hasPublic || this.hasPrivate) {
                return {
                    tone: 'warn',
                    title: 'Configuración incompleta',
                    detail: hasPublic ? 'Falta la clave privada: el botón de tarjeta no se muestra en la tienda.' : 'Falta la clave pública: el botón de tarjeta no se muestra en la tienda.'
                }
            }
            return { tone: 'off', title: 'Sin configurar', detail: 'La tienda solo acepta pedidos con pago en efectivo.' }
        }
    },
    methods: {
        fill(record) {
            this.form = {
                token_public_culqui: record.token_public_culqui || '',
                token_private_culqui: ''
            }
        },
        async removePrivate() {
            try {
                await this.$confirm('Sin la clave privada la tienda dejará de cobrar con tarjeta. ¿Quitarla?', 'Quitar clave privada', {
                    confirmButtonText: 'Quitar',
                    cancelButtonText: 'Cancelar',
                    type: 'warning'
                })
            } catch (cancelled) {
                return
            }
            this.removing = true
            try {
                const { data } = await this.$http.post(this.endpoint, { clear_token_private_culqui: true })
                this.$message.success('Clave privada eliminada')
                this.$emit('saved', data.record)
            } catch (error) {
                this.$message.error(requestErrorMessage(error, 'No se pudo quitar la clave privada.'))
            } finally {
                this.removing = false
            }
        }
    }
}
</script>
