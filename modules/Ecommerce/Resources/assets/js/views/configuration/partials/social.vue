<template>
    <form autocomplete="off" novalidate @submit.prevent="submit" aria-labelledby="sc-social-title">
        <h4 id="sc-social-title" class="sc-section-title">Redes sociales</h4>
        <p class="co-help sc-section-help">Los íconos aparecen en el pie de la tienda. Deje vacío el que no use. Solo se aceptan enlaces que empiecen con https://.</p>
        <div class="row">
            <div class="col-md-12" v-for="network in networks" :key="network.field">
                <div class="form-group" :class="{'has-danger': errors[network.field]}">
                    <label class="control-label" :for="'sc-' + network.field">{{ network.label }}</label>
                    <el-input :id="'sc-' + network.field" type="url" v-model="form[network.field]" maxlength="255" :placeholder="network.placeholder" @input="clearError(network.field)"></el-input>
                    <small class="form-control-feedback" role="alert" v-if="errors[network.field]" v-text="errors[network.field][0]"></small>
                </div>
            </div>
        </div>
        <div class="sc-actions">
            <span class="sc-state" :class="{'is-dirty': dirty}" aria-live="polite">{{ dirty ? 'Cambios sin guardar' : 'Sin cambios' }}</span>
            <el-button type="primary" native-type="submit" :loading="saving" :disabled="!dirty">Guardar redes sociales</el-button>
        </div>
    </form>
</template>

<script>
import section from '../section'

export default {
    mixins: [section],
    data() {
        return {
            endpoint: '/ecommerce/configuration_social',
            networks: [
                { field: 'link_facebook', label: 'Facebook', placeholder: 'https://facebook.com/su-pagina' },
                { field: 'link_twitter', label: 'X (Twitter)', placeholder: 'https://x.com/su-cuenta' },
                { field: 'link_youtube', label: 'YouTube', placeholder: 'https://youtube.com/@su-canal' }
            ]
        }
    },
    methods: {
        fill(record) {
            this.form = {
                link_facebook: record.link_facebook || '',
                link_twitter: record.link_twitter || '',
                link_youtube: record.link_youtube || ''
            }
        }
    }
}
</script>
