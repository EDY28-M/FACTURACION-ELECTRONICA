// Shared ApexCharts palette and helpers for the tenant portal.
export const PALETTE = ['#2F6FDF', '#1FA79A', '#E9A23B', '#7B61E8', '#E05D5D', '#14804A']
export const INK = '#0E1B2E'
export const INK2 = '#3A4A60'
export const INK3 = '#5F6E83'
export const GRID = '#EEF2F8'
export const FONT = 'Hanken Grotesk, ui-sans-serif, system-ui, sans-serif'
export const DISPLAY = 'Bricolage Grotesque, ui-sans-serif, system-ui, sans-serif'

// Theme-aware chart colours. Read when the options are built (the mode switch reloads the page).
const LIGHT = {
    ink: INK, ink2: INK2, ink3: INK3, grid: GRID,
    stroke: '#FFFFFF', track: '#F0F3F8', trackSoft: '#EEF2F8',
    polygonStroke: '#E4EAF3', polygonFill: ['#FAFBFE', '#FFFFFF'],
    labelBg: INK, amberText: '#B9771A', pointFill: '#FFFFFF', crosshair: '#C9D7EE'
}
const DARK = {
    ink: '#E6ECF5', ink2: '#AEBBD0', ink3: '#93A3BA', grid: '#22324D',
    stroke: '#16233A', track: '#22324D', trackSoft: '#22324D',
    polygonStroke: '#2B3F5F', polygonFill: ['#1A2A44', '#16233A'],
    labelBg: '#2B3F5F', amberText: '#F2C177', pointFill: '#16233A', crosshair: '#2B3F5F'
}

export function isDark() {
    return typeof document !== 'undefined' && !!document.documentElement && document.documentElement.classList.contains('dark')
}

export function theme() {
    return isDark() ? DARK : LIGHT
}

// Escape text before it is placed in ApexCharts tooltip HTML (names come from user data).
export function escapeHtml(text) {
    return String(text === null || text === undefined ? '' : text).replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]))
}

// Parse money-like values ("1,234.50", null, undefined) into numbers.
export function num(value) {
    if (typeof value === 'number') return isFinite(value) ? value : 0
    const parsed = parseFloat(String(value === null || value === undefined ? '' : value).replace(/,/g, ''))
    return isFinite(parsed) ? parsed : 0
}

export function prefersReducedMotion() {
    return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)
}

export function money(value) {
    return 'S/ ' + num(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

export function compact(value) {
    const n = num(value)
    const abs = Math.abs(n)
    if (abs >= 1000000) return (n / 1000000).toFixed(1).replace(/\.0$/, '') + 'M'
    if (abs >= 1000) return (n / 1000).toFixed(1).replace(/\.0$/, '') + 'k'
    return String(Math.round(n * 100) / 100)
}

export function baseChart(extra) {
    return Object.assign({
        fontFamily: FONT,
        toolbar: { show: false },
        zoom: { enabled: false },
        // First paint: short and simultaneous. Data swaps (stale -> fresh) use a brief dynamic animation.
        animations: {
            enabled: !prefersReducedMotion(),
            easing: 'easeout',
            speed: 450,
            animateGradually: { enabled: false },
            dynamicAnimation: { enabled: true, speed: 200 }
        }
    }, extra || {})
}

// Rebuild the ApexCharts locale-free tooltip theme.
export const tooltip = { theme: 'dark' }
