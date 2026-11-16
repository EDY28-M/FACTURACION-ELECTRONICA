<template>
    <div class="chart-line-container">
        <apexchart
            v-if="hasData"
            :key="renderKey"
            type="area"
            height="280"
            :options="options"
            :series="series"
        ></apexchart>
    </div>
</template>

<style>
    .chart-line-container {
        position: relative;
        width: 100%;
        min-height: 40px;
    }
</style>

<script>
    import { PALETTE, theme, num, baseChart, compact, money, tooltip } from './apex-theme'

    export default {
        props: ['allData'],
        data() {
            return { renderKey: 0 }
        },
        computed: {
            datasets() {
                return (this.allData && this.allData.datasets) || []
            },
            labels() {
                return (this.allData && this.allData.labels) || []
            },
            series() {
                return this.datasets.map(set => ({ name: set.label || '', data: (set.data || []).map(num) }))
            },
            hasData() {
                return this.series.some(set => set.data.length > 0)
            },
            options() {
                return {
                    chart: baseChart({ type: 'area' }),
                    colors: PALETTE,
                    stroke: { curve: 'smooth', width: 3 },
                    fill: { type: 'gradient', gradient: { shadeIntensity: 0, opacityFrom: 0.22, opacityTo: 0.02, stops: [0, 92] } },
                    dataLabels: { enabled: false },
                    markers: { size: 0, hover: { size: 5 } },
                    grid: { borderColor: theme().grid, strokeDashArray: 5, xaxis: { lines: { show: false } } },
                    xaxis: { categories: this.labels, axisBorder: { show: false }, axisTicks: { show: false }, labels: { style: { colors: theme().ink3, fontSize: '12.5px' } } },
                    yaxis: { min: 0, labels: { style: { colors: theme().ink3, fontSize: '12.5px' }, formatter: compact } },
                    legend: { position: 'top', horizontalAlign: 'left', fontSize: '13px', labels: { colors: theme().ink2 }, markers: { width: 10, height: 10, radius: 3 } },
                    tooltip: Object.assign({}, tooltip, { shared: true, intersect: false, y: { formatter: money } })
                }
            }
        },
        watch: {
            allData() {
                this.renderKey++
            }
        }
    }
</script>
