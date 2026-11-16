<template>
    <div class="system-dashboard">
        <header class="system-page-heading">
            <div>
                <h1>Planes</h1>
                <p>Tarifas y límites que se asignan a cada cliente</p>
            </div>
            <div class="system-page-actions">
                <button type="button" class="system-button system-button-primary" @click="clickCreate(null, $event)">
                    <svg class="sys-icon" aria-hidden="true"><use :href="$icon('plus')" /></svg>Nuevo plan
                </button>
            </div>
        </header>

        <p v-if="loadError" class="system-load-error" role="alert">
            No se pudieron cargar los planes.
            <button type="button" @click="getData()">Reintentar</button>
        </p>

        <div class="system-plans-layout">
        <div class="system-plan-grid">
            <article v-for="row in records" :key="row.id" class="system-receipt">
                <div class="system-receipt-top">
                    <p class="system-receipt-meta"><span>{{ row.name }}</span><b>N° {{ serial(row.id) }}</b></p>
                    <p class="system-receipt-price" :aria-label="'Precio ' + priceLabel(row.pricing)"><span class="cur">S/</span><span class="int">{{ priceParts(row.pricing).int }}</span><span class="dec">.{{ priceParts(row.pricing).dec }}</span></p>
                </div>
                <div class="system-receipt-bottom">
                    <dl class="system-receipt-rows">
                        <div><dt>Usuarios</dt><i class="leader" aria-hidden="true"></i><dd><limit-value :value="row.limit_users" /></dd></div>
                        <div><dt>Comprobantes</dt><i class="leader" aria-hidden="true"></i><dd><limit-value :value="row.limit_documents" /></dd></div>
                    </dl>
                    <div class="system-receipt-stampline">
                        <span v-if="row.locked" class="system-stamp is-red">Sistema</span>
                        <span v-else class="system-stamp">{{ clientsLabel(row.clients_count) }}</span>
                    </div>
                    <div v-if="!row.locked" class="system-plan-actions">
                        <button type="button" class="system-button" @click.prevent="clickCreate(row.id, $event)">
                            <svg class="sys-icon" aria-hidden="true"><use :href="$icon('pencil')" /></svg>Editar plan
                        </button>
                        <button type="button" class="system-button system-icon-only" :aria-label="'Eliminar plan ' + row.name" title="Eliminar plan" @click.prevent="clickDelete(row.id)">
                            <svg class="sys-icon" aria-hidden="true"><use :href="$icon('trash-2')" /></svg>
                        </button>
                    </div>
                </div>
            </article>

            <button type="button" class="system-plan-add" @click="clickCreate(null, $event)">
                <svg class="sys-icon sys-icon-lg" aria-hidden="true"><use :href="$icon('plus')" /></svg>
                <strong>Nuevo plan</strong>
                <small>Define tarifa y límites</small>
            </button>
        </div>
        <aside class="system-panel system-panel-pad system-plans-summary" aria-label="Resumen de planes">
            <h2 class="system-panel-title"><svg class="sys-icon" aria-hidden="true"><use :href="$icon('layers')" /></svg><span>Resumen</span></h2>
            <p class="system-summary-line"><strong>{{ records.length }}</strong> {{ records.length === 1 ? 'plan' : 'planes' }} · <strong>{{ totalClients }}</strong> {{ totalClients === 1 ? 'cliente asignado' : 'clientes asignados' }}</p>
            <ul v-if="records.length" class="system-meter-list">
                <li v-for="row in records" :key="row.id">
                    <div><span>{{ row.name }}</span><strong>{{ row.clients_count || 0 }}</strong></div>
                    <i class="system-meter"><b :style="{ width: ((row.clients_count || 0) / Math.max(totalClients, 1)) * 100 + '%' }"></b></i>
                </li>
            </ul>
            <p v-else class="system-hint">Todavía no hay planes.</p>
        </aside>
        </div>

        <system-plans-form :showDialog.sync="showDialog" :recordId="recordId" @closed="restoreFocus"></system-plans-form>
    </div>
</template>

<script>
    import PlansForm from './form.vue'
    import {deletable} from "../../../mixins/deletable"

    const LimitValue = {
        props: ['value'],
        render(h) {
            if (this.value === null || this.value === undefined || this.value === '') {
                return h('span', {class: 'system-plan-limit is-muted'}, 'Sin definir')
            }
            if (Number(this.value) === 0) {
                return h('span', {class: 'system-plan-limit'}, 'Ilimitados')
            }
            return h('span', {class: 'system-plan-limit'}, 'Hasta ' + Number(this.value).toLocaleString('es-PE'))
        }
    }

    export default {
        mixins: [deletable],
        components: {PlansForm, LimitValue},
        data() {
            return {
                showDialog: false,
                resource: 'plans',
                recordId: null,
                trigger: null,
                records: [],
                loadError: false
            }
        },
        computed: {
            totalClients() {
                return this.records.reduce((total, row) => total + Number(row.clients_count || 0), 0)
            }
        },
        created() {
            this.$eventHub.$on('reloadData', () => {
                this.getData()
            })
            this.getData()
        },
        methods: {
            getData() {
                this.loadError = false
                this.$http.get(`/${this.resource}/records`)
                    .then(response => {
                        this.records = response.data.data
                    })
                    .catch(() => {
                        this.loadError = true
                    })
            },
            restoreFocus() {
                const el = this.trigger
                this.$nextTick(() => { if (el && document.body.contains(el)) el.focus() })
            },
            priceParts(value) {
                const amount = Number(value)
                const [int, dec] = (isFinite(amount) ? amount : 0).toFixed(2).split('.')
                return {int: Number(int).toLocaleString('es-PE'), dec}
            },
            priceLabel(value) {
                const price = this.priceParts(value)
                return `S/ ${price.int}.${price.dec}`
            },
            serial(id) {
                return String(id).padStart(4, '0')
            },
            clientsLabel(count) {
                const total = Number(count || 0)
                return `${total} ${total === 1 ? 'cliente' : 'clientes'}`
            },
            clickCreate(recordId = null, event = null) {
                this.trigger = event && event.currentTarget ? event.currentTarget : null
                this.recordId = recordId
                this.showDialog = true
            },
            clickDelete(id) {
                this.destroy(`/${this.resource}/${id}`).then(() =>
                    this.$eventHub.$emit('reloadData')
                )
            }
        }
    }
</script>
