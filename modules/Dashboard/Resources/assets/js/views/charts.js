// ApexCharts option builders for the tenant dashboard (v7 visual contract).
// Colours that depend on the surface (text, strokes, tracks) come from theme(), so the charts stay readable in dark mode.
import {
    num, money, compact, baseChart, tooltip, theme, isDark, escapeHtml,
    FONT, DISPLAY
} from '../../../../../../resources/js/components/graph/src/apex-theme'

export const C = {
    blue: '#2F6FDF', teal: '#1FA79A', amber: '#E9A23B', violet: '#7B61E8', red: '#E05D5D', green: '#14804A',
    blueSoft: '#C7D8F7', tealSoft: '#BFE8E2', violetSoft: '#D9D1FA', amberSoft: '#F6DDB4', greenSoft: '#BFE3CD', violetPale: '#C9BEF6'
}
export const SERIES = [C.blue, C.teal, C.violet, C.amber, C.red]

export { num, money, compact, escapeHtml }

// Light tints are too bright on the dark panel: use the series colour with transparency instead.
function soften(color, light) {
    return isDark() ? color + '59' : light
}

// Round a chart ceiling up to a value that splits cleanly into four ticks.
export function niceMax(value) {
    const v = num(value)
    if (v <= 0) return 1
    const mag = Math.pow(10, Math.floor(Math.log10(v)))
    const steps = [1, 1.2, 1.6, 2, 2.4, 3, 4, 5, 6, 8, 10]
    const n = v / mag
    for (let i = 0; i < steps.length; i++) {
        if (n <= steps[i]) return steps[i] * mag
    }
    return 10 * mag
}

// Stock quantities arrive as "4.00": show integers as integers and keep up to two decimals otherwise.
export function formatStock(value) {
    const n = num(value)
    return Number.isInteger(n) ? String(n) : String(Math.round(n * 100) / 100)
}

// Current vs previous period as a small delta line. Returns null when the comparison cannot be computed.
export function compare(current, previous) {
    if (previous === null || previous === undefined) return null
    const cur = num(current)
    const prev = num(previous)
    if (prev === 0) return cur === 0 ? { text: 'sin cambios', tone: 'flat' } : null
    const change = Math.round((cur - prev) / Math.abs(prev) * 100)
    if (change === 0) return { text: 'sin cambios', tone: 'flat' }
    if (Math.abs(change) > 999) return change > 0 ? { text: '>+999%', tone: 'up' } : { text: '<\u2212999%', tone: 'down' }
    return change > 0 ? { text: '+' + change + '%', tone: 'up' } : { text: '\u2212' + Math.abs(change) + '%', tone: 'down' }
}

export function pct(part, whole) {
    const w = num(whole)
    if (w <= 0) return 0
    return Math.max(0, Math.min(100, Math.round(num(part) / w * 100)))
}

export function findSet(graph, label) {
    const sets = (graph && graph.datasets) || []
    return sets.find(set => set.label === label) || null
}

export function setValues(graph, label) {
    const set = findSet(graph, label)
    return set ? (set.data || []).map(num) : []
}

export function miniBars(data, color, soft) {
    const last = data.length - 1
    const tint = soften(color, soft)
    const ghost = theme().track
    const top = Math.max.apply(null, data.map(num).concat([0]))
    // Empty buckets keep a short ghost bar so the row reads as a steady rhythm instead of gaps.
    const floor = top > 0 ? top * 0.08 : 1
    const shown = data.map((v) => Math.max(num(v), floor))
    const empty = data.map((v) => num(v) <= 0)
    return {
        options: {
            chart: baseChart({ type: 'bar', sparkline: { enabled: true } }),
            colors: [function (o) {
                if (empty[o.dataPointIndex]) return ghost
                return o.dataPointIndex === last ? color : tint
            }],
            plotOptions: { bar: { columnWidth: '58%', borderRadius: 3, borderRadiusApplication: 'end' } },
            states: { hover: { filter: { type: 'none' } }, active: { filter: { type: 'none' } } },
            yaxis: { show: false, min: 0, max: top > 0 ? top : 1 },
            tooltip: { enabled: false }
        },
        series: [{ name: 'Total', data: shown }]
    }
}

export function miniRing(value, color, track) {
    const t = theme()
    return {
        series: [Math.max(0, Math.min(100, Math.round(value)))],
        options: {
            chart: baseChart({ type: 'radialBar', sparkline: { enabled: true } }),
            colors: [color],
            plotOptions: { radialBar: { hollow: { size: '52%' }, track: { background: track || t.track, strokeWidth: '100%' },
                dataLabels: { name: { show: false }, value: { offsetY: 5, fontSize: '13px', fontWeight: 600, color: t.ink, fontFamily: FONT, formatter: v => Math.round(v) + '%' } } } },
            stroke: { lineCap: 'round' }
        }
    }
}

export function donut(values, labels, colors) {
    const t = theme()
    const total = values.reduce((a, b) => a + b, 0)
    return {
        series: values,
        options: {
            chart: baseChart({ type: 'donut' }),
            labels: labels,
            colors: colors,
            dataLabels: { enabled: false },
            legend: { show: false },
            stroke: { width: 4, colors: [t.stroke] },
            plotOptions: { pie: { expandOnClick: false, donut: { size: '72%', labels: { show: true,
                name: { fontSize: '13px', color: t.ink3, offsetY: 20 },
                value: { fontSize: '22px', fontWeight: 600, color: t.ink, fontFamily: DISPLAY, offsetY: -12, formatter: v => 'S/ ' + compact(v) },
                total: { show: true, showAlways: true, label: 'Total', fontSize: '13px', color: t.ink3, formatter: () => 'S/ ' + compact(total) } } } } },
            tooltip: Object.assign({}, tooltip, { y: { formatter: money } })
        }
    }
}

// Current period against the previous one: solid blue area plus a dashed amber line.
export function compareArea(cfg) {
    const t = theme()
    const current = cfg.current
    const previous = cfg.previous
    const labels = cfg.labels
    const full = cfg.full
    const best = current.reduce((b, v, i, arr) => (v > arr[b] ? i : b), 0)
    const peak = Math.max.apply(null, current.concat((previous || []).map(num)).concat([0]))
    const series = [{ name: cfg.names[0], data: current }]
    if (previous) series.push({ name: cfg.names[1], data: previous })
    const dense = labels.length > 31
    // Phones: keep only the first word of each label ("Jue 5" -> "Jue") and fewer ticks so they never collide.
    const narrow = typeof window !== 'undefined' && window.innerWidth < 640
    return {
        series: series,
        options: {
            chart: baseChart({ type: 'area' }),
            colors: [C.blue, C.amber],
            stroke: { curve: 'monotoneCubic', width: [3.5, 2.5], dashArray: [0, 7], lineCap: 'round' },
            fill: { type: ['gradient', 'solid'], opacity: [1, 0], gradient: { shadeIntensity: 0, opacityFrom: 0.32, opacityTo: 0.02, stops: [0, 92] } },
            dataLabels: { enabled: false },
            markers: { size: dense ? [0, 0] : (narrow ? [4, 0] : [5, 0]), colors: [t.pointFill], strokeColors: [C.blue], strokeWidth: 3, hover: { size: 7 } },
            grid: { borderColor: t.grid, strokeDashArray: 5, xaxis: { lines: { show: false } }, padding: { left: 6, right: 10, top: 8 } },
            xaxis: { categories: labels, axisBorder: { show: false }, axisTicks: { show: false }, tickAmount: Math.min(labels.length, narrow ? 5 : 10), labels: { rotate: 0, hideOverlappingLabels: true, style: { colors: t.ink3, fontSize: narrow ? '11px' : '13px' },
                formatter: narrow ? function (v) { return String(v == null ? '' : v).split(' ')[0] } : undefined },
                crosshairs: { stroke: { color: t.crosshair, dashArray: 4 } } },
            yaxis: { min: 0, max: niceMax(peak), tickAmount: 4, labels: { style: { colors: t.ink3, fontSize: '12.5px' }, formatter: compact } },
            annotations: current[best] > 0 && labels.length > 1 ? { points: [{ x: labels[best], y: current[best], marker: { size: 6, fillColor: C.blue, strokeColor: t.stroke, strokeWidth: 2 },
                label: { text: cfg.bestText + ' \u00b7 S/ ' + compact(current[best]), offsetY: -12, offsetX: best >= labels.length - 2 ? -64 : (best <= 1 ? 64 : 0), borderWidth: 0, borderRadius: 9,
                    style: { background: t.labelBg, color: '#fff', fontSize: '12px', fontWeight: 600, fontFamily: FONT, padding: { left: 9, right: 9, top: 5, bottom: 5 } } } }] } : {},
            legend: { show: false },
            tooltip: { custom: function (o) {
                const i = o.dataPointIndex
                const cur = current[i]
                if (cur === undefined || cur === null) return ''
                let html = '<div class="td-tt"><span>' + escapeHtml(full[i]) + '</span><b>' + money(cur) + '</b>'
                const prev = previous ? previous[i] : null
                if (prev !== null && prev !== undefined) {
                    const diff = cur - prev
                    html += '<em>' + escapeHtml(cfg.names[1]) + ' ' + money(prev) + '</em>'
                    html += '<em style="color:' + (diff >= 0 ? '#7EE2A8' : '#FFB4A8') + '">' + (diff >= 0 ? '+' : '\u2212') + money(Math.abs(diff)) + '</em>'
                }
                return html + '</div>'
            } },
            responsive: [{ breakpoint: 640, options: { chart: { height: 270 } } }]
        },
        best: best
    }
}

export function rings(values, labels, colors, dueText) {
    const t = theme()
    return {
        series: values,
        options: {
            chart: baseChart({ type: 'radialBar' }),
            labels: labels,
            colors: colors,
            plotOptions: { radialBar: { hollow: { size: '34%' }, track: { background: t.track, margin: 7 }, startAngle: -135, endAngle: 135,
                dataLabels: { name: { fontSize: '12.5px', color: t.ink3, offsetY: 20 },
                    value: { fontSize: '20px', fontWeight: 600, color: t.ink, fontFamily: DISPLAY, offsetY: -12, formatter: v => Math.round(v) + '%' },
                    total: { show: true, label: 'Por cobrar', fontSize: '12.5px', color: t.ink3, formatter: () => dueText } } } },
            stroke: { lineCap: 'round' }
        }
    }
}

// Which weekdays sell: this period against the previous one.
export function weekdayRadar(categories, current, previous, names) {
    const t = theme()
    const series = [{ name: names[0], data: current.map(v => Math.max(0, num(v))) }]
    if (previous) series.push({ name: names[1], data: previous.map(v => Math.max(0, num(v))) })
    const max = Math.max.apply(null, series.reduce((all, s) => all.concat(s.data), []).concat([1]))
    return {
        series: series,
        options: {
            chart: baseChart({ type: 'radar', dropShadow: { enabled: false } }),
            labels: categories,
            colors: [C.blue, C.amber],
            stroke: { width: 2.5 },
            fill: { opacity: [0.22, 0.12] },
            dataLabels: { enabled: false },
            markers: { size: 4, colors: [t.pointFill], strokeColors: [C.blue, C.amber], strokeWidth: 2.5 },
            plotOptions: { radar: { size: 118, polygons: { strokeColors: t.polygonStroke, connectorColors: t.polygonStroke, fill: { colors: t.polygonFill } } } },
            xaxis: { labels: { style: { colors: categories.map(() => t.ink2), fontSize: '13px', fontWeight: 500 } } },
            yaxis: { show: false, min: 0, max: niceMax(max), tickAmount: 4 },
            legend: { show: false },
            tooltip: Object.assign({}, tooltip, { y: { formatter: money } }),
            responsive: [{ breakpoint: 520, options: { plotOptions: { radar: { size: 86 } }, chart: { height: 270 } } }]
        }
    }
}

export function polar(values) {
    const t = theme()
    return {
        series: values.map(v => Math.max(0, v)),
        options: {
            chart: baseChart({ type: 'polarArea' }),
            labels: ['Ventas', 'Compras', 'Gastos', 'Utilidad'],
            colors: [C.blue, C.amber, C.red, C.teal],
            fill: { opacity: 0.88 },
            stroke: { width: 2, colors: [t.stroke] },
            yaxis: { show: false },
            dataLabels: { enabled: false },
            plotOptions: { polarArea: { rings: { strokeWidth: 1, strokeColor: t.polygonStroke }, spokes: { strokeWidth: 0 } } },
            legend: { show: true, position: 'bottom', fontSize: '13px', fontWeight: 500, labels: { colors: t.ink2 }, markers: { width: 10, height: 10, radius: 3 }, itemMargin: { vertical: 4 },
                formatter: function (name, o) { return escapeHtml(name) + ' <b style="color:' + t.ink + ';margin-left:4px">S/ ' + compact(values[o.seriesIndex]) + '</b>' } },
            tooltip: Object.assign({}, tooltip, { y: { formatter: (v, o) => money(values[o.seriesIndex]) } })
        }
    }
}

// Purchases of the calendar year: violet columns (current month darker) and perceptions on a right axis.
export function purchases(labels, buys, perceptions, last, months) {
    const t = theme()
    const maxBuy = Math.max.apply(null, buys.map(num).concat([0]))
    const maxPer = Math.max.apply(null, perceptions.map(num).concat([0]))
    const pale = soften(C.violet, C.violetPale)
    return {
        series: [{ name: 'Compras', type: 'column', data: buys }, { name: 'Percepciones', type: 'line', data: perceptions }],
        options: {
            chart: baseChart({ type: 'line' }),
            colors: [function (o) { return o.seriesIndex === 0 ? (o.dataPointIndex === last ? C.violet : pale) : C.amber }, C.amber],
            stroke: { width: [0, 2.5], curve: 'monotoneCubic' },
            plotOptions: { bar: { borderRadius: 6, borderRadiusApplication: 'end', columnWidth: '58%' } },
            dataLabels: { enabled: false },
            markers: { size: 0, hover: { size: 5 } },
            grid: { borderColor: t.grid, strokeDashArray: 5, xaxis: { lines: { show: false } }, padding: { left: 2, right: 2, top: -6 } },
            xaxis: { categories: labels, axisBorder: { show: false }, axisTicks: { show: false }, labels: { rotate: 0, hideOverlappingLabels: true, style: { colors: t.ink3, fontSize: '13px' } } },
            yaxis: [{ min: 0, max: niceMax(maxBuy), tickAmount: 4, labels: { style: { colors: t.ink3, fontSize: '12.5px' }, formatter: compact } },
                { opposite: true, min: 0, max: maxPer > 0 ? niceMax(maxPer) : 100, tickAmount: 4, labels: { style: { colors: t.amberText, fontSize: '12.5px' }, formatter: compact } }],
            legend: { show: true, position: 'top', horizontalAlign: 'left', fontSize: '13px', labels: { colors: t.ink2 }, markers: { width: 10, height: 10, radius: 3 } },
            tooltip: Object.assign({}, tooltip, { shared: true, intersect: false,
                x: { formatter: (v, o) => escapeHtml((months[o.dataPointIndex] || '') + (o.dataPointIndex === last ? ' (en curso)' : '')) },
                y: { formatter: v => (v === null || v === undefined ? '\u2014' : money(v)) } })
        }
    }
}

export function funnel(rows, move) {
    const t = theme()
    const values = rows.map(r => r.value)
    const max = Math.max.apply(null, values.concat([1]))
    return {
        series: [{ name: move ? 'Movimientos' : 'Ventas', data: values }],
        options: {
            chart: baseChart({ type: 'bar' }),
            colors: rows.map((r, i) => SERIES[i % SERIES.length]),
            plotOptions: { bar: { horizontal: true, isFunnel: true, distributed: true, barHeight: '86%', borderRadius: 10 } },
            dataLabels: { enabled: true, dropShadow: { enabled: false }, style: { fontSize: '13.5px', fontWeight: 600, colors: ['#fff'] },
                formatter: v => (move ? v + ' mov.' : 'S/ ' + compact(v)) },
            grid: { show: false, padding: { left: 0, right: 0, top: -10, bottom: -6 } },
            legend: { show: false },
            xaxis: { categories: rows.map(r => r.short), labels: { show: false }, axisBorder: { show: false }, axisTicks: { show: false } },
            yaxis: { labels: { show: false }, min: 0, max: max * 1.0 },
            states: { hover: { filter: { type: 'darken', value: 0.9 } } },
            // Product names are user data and ApexCharts writes tooltip text as HTML.
            tooltip: Object.assign({}, tooltip, { x: { formatter: (v, o) => escapeHtml(rows[o.dataPointIndex] ? rows[o.dataPointIndex].name : '') },
                y: { formatter: v => (move ? v + ' movimientos' : money(v)), title: { formatter: () => '' } } })
        }
    }
}

export function bubbles(rows) {
    const t = theme()
    const maxQty = Math.max.apply(null, rows.map(r => r.qty).concat([1]))
    const maxTotal = Math.max.apply(null, rows.map(r => r.total).concat([1]))
    return {
        series: rows.map(r => ({ name: r.name, data: [[r.qty, r.total, Math.round(r.total / Math.max(r.qty, 1))]] })),
        options: {
            chart: baseChart({ type: 'bubble' }),
            colors: rows.map((r, i) => SERIES[i % SERIES.length]),
            fill: { opacity: 0.85 },
            stroke: { width: 2, colors: [t.stroke] },
            dataLabels: { enabled: false },
            legend: { show: false },
            plotOptions: { bubble: { zScaling: true, minBubbleRadius: 14, maxBubbleRadius: 42 } },
            grid: { borderColor: t.grid, strokeDashArray: 5, padding: { left: 4, right: 14, top: -6 } },
            xaxis: { type: 'numeric', min: 0, max: Math.ceil(maxQty * 1.25), tickAmount: 4, title: { text: 'Transacciones', style: { color: t.ink3, fontSize: '12px', fontWeight: 500 } },
                axisBorder: { show: false }, axisTicks: { show: false }, labels: { style: { colors: t.ink3, fontSize: '12px' }, formatter: v => Math.round(v) } },
            yaxis: { min: 0, max: maxTotal * 1.25, tickAmount: 4, labels: { style: { colors: t.ink3, fontSize: '12px' }, formatter: compact } },
            tooltip: { custom: function (o) {
                const c = rows[o.seriesIndex]
                if (!c) return ''
                return '<div class="td-tt"><span>' + escapeHtml(c.name) + '</span><b>' + money(c.total) + '</b><em>' + c.qty + ' transacciones · ticket S/ ' + compact(c.total / Math.max(c.qty, 1)) + '</em></div>'
            } }
        }
    }
}

export function gauge(stock, color) {
    const t = theme()
    const pctValue = Math.max(1, Math.min(100, Math.round(num(stock) / 20 * 100)))
    return {
        series: [pctValue],
        options: {
            chart: baseChart({ type: 'radialBar', sparkline: { enabled: true } }),
            colors: [color],
            plotOptions: { radialBar: { startAngle: -100, endAngle: 100, hollow: { size: '58%' }, track: { background: t.trackSoft, strokeWidth: '100%' },
                dataLabels: { name: { show: false }, value: { offsetY: -6, fontSize: '20px', fontWeight: 600, color: t.ink, fontFamily: DISPLAY, formatter: () => formatStock(stock) } } } },
            stroke: { lineCap: 'round' }
        }
    }
}

// Receivables timeline: one bar per pending invoice, from issue date to due date.
export function schedule(rows, todayMs) {
    const t = theme()
    const DAY = 86400000
    const colors = { overdue: C.red, soon: C.amber, ok: C.blue }
    const names = {}
    rows.forEach(r => { names[r.customer] = (names[r.customer] || 0) + 1 })
    const data = rows.map(r => {
        const issue = r.issueMs
        const due = Math.max(r.dueMs, issue + DAY)
        return { x: names[r.customer] > 1 ? r.customer + ' \u00b7 ' + r.document : r.customer, y: [issue, due], fillColor: colors[r.status] || C.blue }
    })
    const min = Math.min.apply(null, rows.map(r => r.issueMs).concat([todayMs])) - 2 * DAY
    const max = Math.max.apply(null, rows.map(r => Math.max(r.dueMs, r.issueMs + DAY)).concat([todayMs])) + 4 * DAY
    return {
        height: Math.max(190, rows.length * 52 + 70),
        series: [{ name: 'Por cobrar', data: data }],
        options: {
            chart: baseChart({ type: 'rangeBar' }),
            plotOptions: { bar: { horizontal: true, barHeight: '54%', borderRadius: 9, borderRadiusApplication: 'around', rangeBarGroupRows: false } },
            dataLabels: { enabled: true, formatter: (v, o) => 'S/ ' + compact(rows[o.dataPointIndex] ? rows[o.dataPointIndex].amount : 0), style: { fontSize: '12.5px', fontWeight: 600, colors: ['#fff'] } },
            grid: { borderColor: t.grid, strokeDashArray: 5, xaxis: { lines: { show: true } }, yaxis: { lines: { show: false } }, padding: { left: 4, right: 12, top: -6 } },
            xaxis: { type: 'datetime', min: min, max: max, axisBorder: { show: false }, axisTicks: { show: false }, labels: { datetimeUTC: true, format: 'dd MMM', style: { colors: t.ink3, fontSize: '12.5px' } } },
            yaxis: { labels: { style: { colors: t.ink, fontSize: '13.5px', fontWeight: 500 }, maxWidth: 190 } },
            annotations: { xaxis: [{ x: todayMs, strokeDashArray: 0, borderColor: t.ink, borderWidth: 2, label: { text: 'Hoy', orientation: 'horizontal', offsetY: -8, borderWidth: 0, borderRadius: 8,
                style: { background: t.labelBg, color: '#fff', fontSize: '12px', fontWeight: 600, fontFamily: FONT, padding: { left: 8, right: 8, top: 3, bottom: 3 } } } }] },
            legend: { show: false },
            tooltip: { custom: function (o) {
                const r = rows[o.dataPointIndex]
                if (!r) return ''
                return '<div class="td-tt"><span>' + escapeHtml(r.customer) + ' \u00b7 ' + escapeHtml(r.document) + '</span><b>' + money(r.amount) + '</b><em>' + escapeHtml(r.statusText) + '</em></div>'
            } },
            responsive: [{ breakpoint: 640, options: { yaxis: { labels: { maxWidth: 96, style: { fontSize: '12px' } } }, xaxis: { labels: { format: 'dd/MM' } } } }]
        }
    }
}
