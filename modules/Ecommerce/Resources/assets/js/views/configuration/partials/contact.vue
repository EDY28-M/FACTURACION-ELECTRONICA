<template>
    <form autocomplete="off" novalidate @submit.prevent="submit" aria-labelledby="sc-contact-title">
        <h4 id="sc-contact-title" class="sc-section-title">Datos de contacto</h4>
        <p class="co-help sc-section-help">Se muestran en la cabecera, el pie y los correos de la tienda. El nombre de la tienda es el nombre comercial de la empresa.</p>
        <div class="row">
            <div class="col-md-6">
                <div class="form-group" :class="{'has-danger': errors.information_contact_name}">
                    <label class="control-label" for="sc-contact-name">Nombre de contacto <span class="text-danger" aria-hidden="true">*</span></label>
                    <el-input id="sc-contact-name" v-model="form.information_contact_name" maxlength="255" @input="clearError('information_contact_name')"></el-input>
                    <small class="co-help">Persona o área que atiende los pedidos.</small>
                    <small class="form-control-feedback" role="alert" v-if="errors.information_contact_name" v-text="errors.information_contact_name[0]"></small>
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group" :class="{'has-danger': errors.information_contact_email}">
                    <label class="control-label" for="sc-contact-email">Correo electrónico <span class="text-danger" aria-hidden="true">*</span></label>
                    <el-input id="sc-contact-email" type="email" v-model="form.information_contact_email" maxlength="255" @input="clearError('information_contact_email')"></el-input>
                    <small class="co-help">Recibe el aviso de cada pedido nuevo y se muestra a los compradores.</small>
                    <small class="form-control-feedback" role="alert" v-if="errors.information_contact_email" v-text="errors.information_contact_email[0]"></small>
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group" :class="{'has-danger': errors.information_contact_phone}">
                    <label class="control-label" for="sc-contact-phone">Teléfono o WhatsApp <span class="text-danger" aria-hidden="true">*</span></label>
                    <el-input id="sc-contact-phone" v-model="form.information_contact_phone" maxlength="50" @input="clearError('information_contact_phone')"></el-input>
                    <small class="co-help">Solo números, espacios y los signos + - ( ). Ejemplo: +51 987 654 321.</small>
                    <small class="form-control-feedback" role="alert" v-if="errors.information_contact_phone" v-text="errors.information_contact_phone[0]"></small>
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group" :class="{'has-danger': errors.information_contact_address}">
                    <label class="control-label" for="sc-contact-address">Dirección <span class="text-danger" aria-hidden="true">*</span></label>
                    <el-input id="sc-contact-address" v-model="form.information_contact_address" maxlength="255" @input="clearError('information_contact_address')"></el-input>
                    <small class="co-help">Dirección de atención o recojo.</small>
                    <small class="form-control-feedback" role="alert" v-if="errors.information_contact_address" v-text="errors.information_contact_address[0]"></small>
                </div>
            </div>
        </div>
        <div class="sc-actions">
            <span class="sc-state" :class="{'is-dirty': dirty}" aria-live="polite">{{ dirty ? 'Cambios sin guardar' : 'Sin cambios' }}</span>
            <el-button type="primary" native-type="submit" :loading="saving" :disabled="!dirty">Guardar datos de contacto</el-button>
        </div>
    </form>
</template>

<script>
import section from '../section'

export default {
    mixins: [section],
    data() {
        return { endpoint: '/ecommerce/configuration' }
    },
    methods: {
        fill(record) {
            this.form = {
                id: record.id,
                information_contact_name: record.information_contact_name || '',
                information_contact_email: record.information_contact_email || '',
                information_contact_phone: record.information_contact_phone || '',
                information_contact_address: record.information_contact_address || ''
            }
        }
    }
}
</script>
