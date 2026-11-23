<template>
    <div>
        <div class="page-header pr-0">
            <h2><a href="/ecommerce/configuration"><i class="fas fa-store"></i></a></h2>
            <ol class="breadcrumbs">
                <li><span class="text-muted">Tienda virtual</span></li>
                <li class="active"><span>Configuración</span></li>
            </ol>
        </div>

        <div class="card co-card sc-card">
            <div class="card-header bg-info">
                <h3 class="my-0">Configuración de la tienda virtual</h3>
            </div>

            <div class="card-body" v-loading="loading" element-loading-text="Cargando configuración…" style="min-height: 160px;">
                <div v-if="failed" class="sc-failed" role="alert">
                    <p>No se pudo cargar la configuración de la tienda.</p>
                    <el-button size="small" @click="load">Reintentar</el-button>
                </div>

                <template v-else-if="record">
                    <div class="sc-summary">
                        <dl class="sc-facts">
                            <div>
                                <dt>Productos publicados</dt>
                                <dd>{{ record.published_products }}</dd>
                            </div>
                            <div>
                                <dt>Pagos con tarjeta</dt>
                                <dd>{{ cardStatus }}</dd>
                            </div>
                            <div>
                                <dt>Logo</dt>
                                <dd>{{ record.logo_url ? 'Propio' : (record.company_logo_url ? 'De la empresa' : 'Sin logo') }}</dd>
                            </div>
                        </dl>
                        <div class="sc-links">
                            <a class="el-button el-button--primary el-button--small" :href="record.store_url" target="_blank" rel="noopener noreferrer">Ver tienda</a>
                            <a class="el-button el-button--default el-button--small" href="/items_ecommerce">Productos de la tienda</a>
                        </div>
                    </div>

                    <p v-if="record.published_products === 0" class="sc-note" role="status">
                        Aún no hay productos publicados: la tienda se ve vacía. Publíquelos en Productos de la tienda.
                    </p>
                    <p v-if="record.contact_is_default" class="sc-note is-warn" role="status">
                        Los datos de contacto todavía tienen los valores de ejemplo (Admin, admin@mail.com). Complételos en la pestaña Contacto antes de abrir la tienda al público.
                    </p>

                    <el-tabs v-model="activeTab" class="sc-tabs">
                        <el-tab-pane name="contact">
                            <span slot="label">Contacto<i v-if="dirty.contact" class="sc-dot" title="Cambios sin guardar"></i></span>
                            <contact-section :record="record" @saved="onSaved" @dirty="value => setDirty('contact', value)"></contact-section>
                        </el-tab-pane>
                        <el-tab-pane name="logo">
                            <span slot="label">Logo</span>
                            <logo-section :record="record" @saved="onSaved"></logo-section>
                        </el-tab-pane>
                        <el-tab-pane name="social">
                            <span slot="label">Redes sociales<i v-if="dirty.social" class="sc-dot" title="Cambios sin guardar"></i></span>
                            <social-section :record="record" @saved="onSaved" @dirty="value => setDirty('social', value)"></social-section>
                        </el-tab-pane>
                        <el-tab-pane name="payments">
                            <span slot="label">Pagos con tarjeta<i v-if="dirty.payments" class="sc-dot" title="Cambios sin guardar"></i></span>
                            <payments-section :record="record" @saved="onSaved" @dirty="value => setDirty('payments', value)"></payments-section>
                        </el-tab-pane>
                        <el-tab-pane name="messages">
                            <span slot="label">Mensajes del producto<i v-if="dirty.messages" class="sc-dot" title="Cambios sin guardar"></i></span>
                            <messages-section :record="record" @saved="onSaved" @dirty="value => setDirty('messages', value)"></messages-section>
                        </el-tab-pane>
                    </el-tabs>
                </template>
            </div>
        </div>
    </div>
</template>

<script>
import ContactSection from './partials/contact.vue'
import LogoSection from './partials/logo.vue'
import SocialSection from './partials/social.vue'
import PaymentsSection from './partials/payments.vue'
import MessagesSection from './partials/messages.vue'

export default {
    components: { ContactSection, LogoSection, SocialSection, PaymentsSection, MessagesSection },
    data() {
        return {
            loading: true,
            failed: false,
            record: null,
            activeTab: 'contact',
            dirty: { contact: false, social: false, payments: false, messages: false }
        }
    },
    computed: {
        cardStatus() {
            const record = this.record || {}
            if (record.card_enabled) return record.culqi_mode === 'live' ? 'Activos (producción)' : 'Activos (modo pruebas)'
            if (record.token_public_culqui || record.has_token_private_culqui) return 'Incompletos'
            return 'Sin configurar'
        },
        hasUnsaved() {
            return Object.keys(this.dirty).some(key => this.dirty[key])
        }
    },
    watch: {
        hasUnsaved(value) {
            window.onbeforeunload = value ? () => 'Hay cambios sin guardar.' : null
        }
    },
    created() {
        this.load()
    },
    beforeDestroy() {
        window.onbeforeunload = null
    },
    methods: {
        async load() {
            this.loading = true
            this.failed = false
            try {
                const response = await this.$http.get('/ecommerce/record')
                this.record = response.data.data
            } catch (error) {
                this.failed = true
            } finally {
                this.loading = false
            }
        },
        // Sections answer with the stored record: keep the summary fields they do not return.
        onSaved(saved) {
            if (saved) {
                this.record = Object.assign({}, this.record, saved)
            }
        },
        setDirty(section, value) {
            this.dirty = Object.assign({}, this.dirty, { [section]: value })
        }
    }
}
</script>

<style>
.sc-card .card-body { padding-bottom: 20px; }
.sc-summary { display: flex; flex-wrap: wrap; gap: 16px 32px; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.sc-facts { display: flex; flex-wrap: wrap; gap: 12px 40px; margin: 0; }
.sc-facts dt { font-size: 12px; font-weight: 500; color: var(--tenant-muted, #5f6e83); margin: 0 0 2px; }
.sc-facts dd { margin: 0; font-size: 18px; font-weight: 600; color: var(--tenant-ink, #0e1b2e); }
.sc-links { display: flex; flex-wrap: wrap; gap: 8px; }
.sc-links a.el-button { text-decoration: none; }
.sc-note { margin: 8px 0; padding: 10px 14px; border-radius: 10px; background: var(--tenant-surface-subtle, #f3f7fe); color: var(--tenant-ink-secondary, #3a4a60); }
.sc-note.is-warn { background: #fff7e6; color: #7a4b00; }
.sc-failed { text-align: center; padding: 24px 0; color: var(--tenant-ink-secondary, #3a4a60); }
.sc-tabs { margin-top: 12px; }
.sc-dot { display: inline-block; width: 7px; height: 7px; margin-left: 6px; border-radius: 50%; background: var(--tenant-blue, #2f6fdf); vertical-align: middle; }
.sc-section-title { margin: 8px 0 4px; font-size: 16px; font-weight: 600; color: var(--tenant-ink, #0e1b2e); }
.sc-section-help { margin: 0 0 16px; max-width: 70ch; }
.sc-actions { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: 12px; padding-top: 14px; margin-top: 8px; border-top: 1px solid var(--tenant-hairline, #eef2f8); }
.sc-spacer { flex: 1 1 auto; }
.sc-state { font-size: 13px; color: var(--tenant-muted, #5f6e83); }
.sc-state.is-dirty { color: var(--tenant-blue-hover, #2559bd); font-weight: 500; }
.sc-mono input { font-family: var(--tenant-font-mono, ui-monospace, monospace); }
.sc-tag { display: inline-block; margin-left: 8px; padding: 1px 8px; border-radius: 999px; font-size: 12px; font-weight: 500; background: rgba(34, 139, 87, 0.12); color: #1b6b43; }
.sc-tag.is-muted { background: var(--tenant-surface-subtle, #f3f7fe); color: var(--tenant-muted, #5f6e83); }
.sc-status { display: flex; flex-direction: column; gap: 2px; margin: 0 0 16px; padding: 10px 14px; border-radius: 10px; background: var(--tenant-surface-subtle, #f3f7fe); color: var(--tenant-ink-secondary, #3a4a60); }
.sc-status.is-ok { background: rgba(34, 139, 87, 0.1); color: #1b6b43; }
.sc-status.is-warn { background: #fff7e6; color: #7a4b00; }
.sc-danger.el-button--text { color: #b42318; }
.sc-logo { max-width: 460px; }
.sc-logo .co-image-preview { height: 160px; }
.sc-logo-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
.sc-card :focus-visible { outline: 2px solid var(--tenant-blue, #2f6fdf); outline-offset: 2px; }
@media (max-width: 575px) {
    .sc-actions { justify-content: stretch; }
    .sc-actions .el-button--primary { width: 100%; }
}
</style>
