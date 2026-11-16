<template>
    <div class="td-stock">
        <div class="td-skel td-skel-tall" v-if="loader"></div>
        <template v-else>
            <p class="td-empty" v-if="!records.length">Todos tus productos tienen stock suficiente.</p>
            <div class="td-gauges td-num" v-else>
                <div class="td-g" v-for="row in gauges" :key="row.id">
                    <div class="td-g-chart">
                        <apexchart type="radialBar" height="150" :options="row.chart.options" :series="row.chart.series"></apexchart>
                    </div>
                    <b :title="row.product">{{ row.product }}</b>
                    <span :title="row.warehouse">{{ row.stockText }} de 20 · {{ row.warehouse }}</span>
                    <em :class="'is-' + row.tone">{{ row.label }}</em>
                </div>
            </div>
            <el-pagination
                v-if="pagination.total > pagination.per_page"
                class="td-pager"
                small
                layout="prev, pager, next"
                :total="pagination.total"
                :current-page.sync="pagination.current_page"
                :page-size="pagination.per_page"
                @current-change="getRecords"
            ></el-pagination>
        </template>
    </div>
</template>

<script>
    import { C, gauge, formatStock } from '../charts'

    export default {
        props: ['initial'],
        data() {
            const initial = this.initial
            return {
                loader: !initial,
                resource: 'dashboard/stock-by-product',
                records: initial ? initial.records || [] : [],
                pagination: initial && initial.pagination ? initial.pagination : { total: 0, per_page: 5, current_page: 1 },
                establishment_id: initial ? initial.establishment_id : null,
                seq: 0
            }
        },
        computed: {
            gauges() {
                return this.records.map(row => {
                    const state = row.state
                    const tone = state === '01' ? 'bad' : state === '02' ? 'warn' : 'info'
                    const color = state === '01' ? C.red : state === '02' ? C.amber : C.blue
                    const label = state === '01' ? 'Agotado' : state === '02' ? 'Pocas unidades' : 'Stock bajo'
                    return Object.assign({}, row, { tone, label, stockText: formatStock(row.stock), chart: gauge(row.stock, color) })
                })
            }
        },
        created() {
            this.$eventHub.$on('changeStock', this.onChangeStock)
        },
        beforeDestroy() {
            this.$eventHub.$off('changeStock', this.onChangeStock)
        },
        methods: {
            onChangeStock(establishment_id, cached) {
                this.establishment_id = establishment_id
                this.pagination.current_page = 1
                if (cached && cached.establishment_id === establishment_id) {
                    // Stale-while-revalidate: show the cached page now, refresh in the background.
                    this.records = cached.records || []
                    this.pagination = cached.pagination || this.pagination
                    this.loader = false
                }
                this.getRecords()
            },
            getRecords() {
                if (!this.establishment_id) {
                    this.records = []
                    this.loader = false
                    return Promise.resolve()
                }
                const seq = ++this.seq
                const query = `establishment_id=${this.establishment_id}&page=${this.pagination.current_page}`
                return this.$http.get(`/${this.resource}/records?${query}`).then(response => {
                    if (seq !== this.seq) return
                    const body = (response && response.data) || {}
                    this.records = body.data || []
                    const meta = body.meta || {}
                    this.pagination = {
                        total: parseInt(meta.total) || 0,
                        per_page: parseInt(meta.per_page) || 5,
                        current_page: parseInt(meta.current_page) || 1
                    }
                    this.$emit('loaded', this.pagination.total)
                    if (this.pagination.current_page === 1) {
                        this.$emit('fetched', { establishment_id: this.establishment_id, records: this.records, pagination: this.pagination })
                    }
                }).catch(() => {
                    if (seq === this.seq) this.records = []
                }).then(() => {
                    if (seq === this.seq) this.loader = false
                })
            }
        }
    }
</script>
