<template>
    <form autocomplete="off" novalidate @submit.prevent="submit" aria-labelledby="sc-messages-title">
        <h4 id="sc-messages-title" class="sc-section-title">Mensajes de la ficha del producto</h4>
        <p class="co-help sc-section-help">Hasta tres frases cortas que se muestran en la página de cada producto, junto a un ícono. Deje vacío el mensaje que no necesite.</p>
        <div class="row">
            <div class="col-md-12" v-for="message in messages" :key="message.field">
                <div class="form-group" :class="{'has-danger': errors[message.field]}">
                    <label class="control-label" :for="'sc-' + message.field">{{ message.label }}</label>
                    <el-input :id="'sc-' + message.field" v-model="form[message.field]" maxlength="255" :placeholder="message.placeholder" @input="clearError(message.field)"></el-input>
                    <small class="form-control-feedback" role="alert" v-if="errors[message.field]" v-text="errors[message.field][0]"></small>
                </div>
            </div>
        </div>
        <div class="sc-actions">
            <span class="sc-state" :class="{'is-dirty': dirty}" aria-live="polite">{{ dirty ? 'Cambios sin guardar' : 'Sin cambios' }}</span>
            <el-button type="primary" native-type="submit" :loading="saving" :disabled="!dirty">Guardar mensajes</el-button>
        </div>
    </form>
</template>

<script>
import section from '../section'

export default {
    mixins: [section],
    data() {
        return {
            endpoint: '/ecommerce/configuration_tags',
            messages: [
                { field: 'tag_shipping', label: 'Mensaje de envío', placeholder: 'Ejemplo: Envíos a todo el país' },
                { field: 'tag_dollar', label: 'Mensaje de precios y pagos', placeholder: 'Ejemplo: Precios en soles, IGV incluido' },
                { field: 'tag_support', label: 'Mensaje de atención', placeholder: 'Ejemplo: Atención de lunes a sábado' }
            ]
        }
    },
    methods: {
        fill(record) {
            this.form = {
                tag_shipping: record.tag_shipping || '',
                tag_dollar: record.tag_dollar || '',
                tag_support: record.tag_support || ''
            }
        }
    }
}
</script>
