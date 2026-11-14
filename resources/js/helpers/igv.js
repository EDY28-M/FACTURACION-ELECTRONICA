/**
 * Single source of truth (JS side) for the IGV percentage.
 * Keep in sync with app/CoreFacturalo/Helpers/Igv/IgvRate.php
 *
 * Reduced regime (restaurants / hotels, IGV + IPM reported as one percentage):
 *   2022-09-01 .. 2025-12-31 => 10
 *   2026-01-01 .. 2026-12-31 => 10.5
 *   2027-01-01 .. 2027-12-31 => 15
 *   2028-01-01 onwards       => 18
 */
const GENERAL_IGV = 18

const REDUCED_SCHEDULE = [
    ['2022-09-01', '2025-12-31', 10],
    ['2026-01-01', '2026-12-31', 10.5],
    ['2027-01-01', '2027-12-31', 15],
]

function todayString() {
    const d = new Date()
    const pad = n => (n < 10 ? '0' + n : '' + n)
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
}

// Tenant flag exposed once by the layout: window.TENANT_IGV = {reduced_regime: bool}
function tenantReducedRegime() {
    if (typeof window !== 'undefined' && window.TENANT_IGV) {
        return !!window.TENANT_IGV.reduced_regime
    }
    return false
}

function getIgvPercentage({reducedRegime, date, itemGeneralRate} = {}) {
    if (reducedRegime === undefined || reducedRegime === null) {
        reducedRegime = tenantReducedRegime()
    }
    if (!reducedRegime || itemGeneralRate) {
        return GENERAL_IGV
    }
    const day = date ? String(date).substring(0, 10) : todayString()
    for (const [from, to, rate] of REDUCED_SCHEDULE) {
        if (day >= from && day <= to) {
            return rate
        }
    }
    return GENERAL_IGV
}

// 1 + rate/100 (e.g. 1.18). Used to turn a price with IGV into a value without IGV.
function getIgvFactor(options) {
    return 1 + getIgvPercentage(options) / 100
}

// Rate for a document row.
// - Non-gravado rows (affectation other than 10) keep the original 18 value (never the reduced rate).
// - Rows flagged `igv_rate_locked` (credit/debit notes, existing saved documents being edited)
//   keep their stored percentage_igv.
// - Any other row is rated by the issue date of the document, honouring the item flag
//   `igv_general_rate` (item outside the restaurant/hotel activity).
function rowIgvPercentage(row, date, reducedRegime) {
    if (row && row.affectation_igv_type_id !== undefined && row.affectation_igv_type_id !== null
        && String(row.affectation_igv_type_id) !== '10') {
        return GENERAL_IGV
    }
    if (row && row.igv_rate_locked) {
        const own = parseFloat(row.percentage_igv)
        if (isFinite(own) && own > 0) {
            return own
        }
    }
    const item = (row && row.item) || {}
    return getIgvPercentage({
        reducedRegime: reducedRegime,
        date: date,
        itemGeneralRate: !!(item.igv_general_rate || (row && row.igv_general_rate)),
    })
}

function rowIgvFactor(row, date, reducedRegime) {
    return 1 + rowIgvPercentage(row, date, reducedRegime) / 100
}

function taxedRows(items) {
    return (items || []).filter(r => String(r.affectation_igv_type_id) === '10')
}

function storedRate(row) {
    const rate = parseFloat(row.percentage_igv)
    return isFinite(rate) && rate > 0 ? rate : GENERAL_IGV
}

// IGV on a taxable base (net of IGV) that is allocated across the taxed rows proportionally to
// their total_value, each share taxed at its own row rate.
function igvFromBase(items, base, date, reducedRegime) {
    const taxed = taxedRows(items)
    const sum = taxed.reduce((a, r) => a + (parseFloat(r.total_value) || 0), 0)
    if (!taxed.length || sum <= 0) {
        return base * getIgvPercentage({reducedRegime, date}) / 100
    }
    return taxed.reduce((acc, r) => {
        const share = base * ((parseFloat(r.total_value) || 0) / sum)
        return acc + share * storedRate(r) / 100
    }, 0)
}

// Splits a gross amount (IGV included) into {value, igv}, allocating it across the taxed rows
// proportionally to their total and using each row's own rate.
function splitGross(items, gross, date, reducedRegime) {
    const taxed = taxedRows(items)
    const sum = taxed.reduce((a, r) => a + (parseFloat(r.total) || 0), 0)
    if (!taxed.length || sum <= 0) {
        const rate = getIgvPercentage({reducedRegime, date})
        const value = gross / (1 + rate / 100)
        return {value: value, igv: value * rate / 100}
    }
    let value = 0
    let igv = 0
    taxed.forEach(r => {
        const rate = storedRate(r)
        const part = gross * ((parseFloat(r.total) || 0) / sum) / (1 + rate / 100)
        value += part
        igv += part * rate / 100
    })
    return {value: value, igv: igv}
}

// Rate to use for document-level math (global discount, prepayments): the highest rate among
// the taxed (affectation 10) rows, falling back to the current rate when there are none.
function documentIgvPercentage(items, date, reducedRegime) {
    let max = 0
    ;(items || []).forEach(row => {
        if (String(row.affectation_igv_type_id) === '10') {
            const rate = parseFloat(row.percentage_igv)
            if (isFinite(rate) && rate > max) max = rate
        }
    })
    return max > 0 ? max : getIgvPercentage({reducedRegime, date})
}

// True when at least one re-ratable (not locked) taxed row would get a different rate for `date`.
function needsRerate(items, date, reducedRegime) {
    return (items || []).some(r => !r.igv_rate_locked
        && String(r.affectation_igv_type_id) === '10'
        && rowIgvPercentage(r, date, reducedRegime) !== parseFloat(r.percentage_igv))
}

export {needsRerate, igvFromBase, splitGross, documentIgvPercentage, GENERAL_IGV, getIgvPercentage, getIgvFactor, rowIgvPercentage, rowIgvFactor, tenantReducedRegime}
