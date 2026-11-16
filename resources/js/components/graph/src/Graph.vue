<template>
    <div class="chart-container">
        <apexchart
            v-if="hasData"
            :key="renderKey"
            :type="apexType"
            height="260"
            :options="options"
            :series="series"
        ></apexchart>
    </div>
</template>

<style>
    .chart-container {
        position: relative;
        margin: auto;
        width: 100%;
        max-width: 320px;
        min-height: 40px;
    }
</style>

<script>
    import { PALETTE, theme, num, baseChart, money, tooltip } from './apex-theme'

    export default {
        props: ['type', 'allData'],
        data() {
            return { renderKey: 0 }
        },
        computed: {
            dataset() {
                const sets = this.allData && this.allData.datasets
                return sets && sets.length ? sets[0] : null
            },
            values() {
                return this.dataset ? (this.dataset.data || []).map(num) : []
            },
            labels() {
                return (this.allData && this.allData.labels) || []
            },
            hasData() {
                return this.values.length > 0
            },
            isRound() {
                return this.type === 'doughnut' || this.type === 'pie' || !this.type
            },
            apexType() {
                return this.isRound ? (this.type === 'pie' ? 'pie' : 'donut') : this.type
            },
            series() {
                return this.isRound ? this.values : [{ name: (this.dataset && this.dataset.label) || '', data: this.values }]
            },
            options() {
                const opts = {
                    chart: baseChart({ type: this.apexType }),
                    labels: this.labels,
                    colors: PALETTE,
                    stroke: { width: 3, colors: [theme().stroke] },
                    dataLabels: { enabled: false },
                    legend: { position: 'bottom', fontSize: '13px', labels: { colors: theme().ink2 }, markers: { width: 10, height: 10, radius: 3 } },
                    tooltip: Object.assign({}, tooltip, { y: { formatter: money } })
                }
                if (this.isRound) {
                    opts.plotOptions = { pie: { donut: { size: '68%', labels: { show: true, name: { show: false },
                        value: { show: true, fontSize: '20px', fontWeight: 600, color: theme().ink, formatter: v => money(v) },
                        total: { show: true, showAlways: true, label: 'Total', color: theme().ink2, formatter: w => money(w.globals.seriesTotals.reduce((a, b) => a + b, 0)) } } } } }
                } else {
                    opts.xaxis = { categories: this.labels }
                    opts.legend = { show: false }
                }
                return opts
            }
        },
        watch: {
            allData() {
                // Force a clean re-render, like the previous destroy/create behaviour.
                this.renderKey++
            }
        }
    }
</script>
