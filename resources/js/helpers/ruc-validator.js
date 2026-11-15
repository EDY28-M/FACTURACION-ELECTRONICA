/**
 * Single source of truth (JS side) for the structural validation of a Peruvian RUC.
 * Keep in sync with app/CoreFacturalo/Helpers/Sunat/RucValidator.php
 *
 * 11 digits, prefix 10/15/16/17/20 and Modulo 11 check digit. It does not prove the RUC exists.
 */
const WEIGHTS = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2]
const PREFIXES = ['10', '15', '16', '17', '20']

export function isValidRuc(ruc) {
    const value = String(ruc === null || ruc === undefined ? '' : ruc).trim()
    if (!/^\d{11}$/.test(value) || PREFIXES.indexOf(value.substring(0, 2)) === -1) return false

    let sum = 0
    for (let i = 0; i < 10; i++) sum += parseInt(value[i], 10) * WEIGHTS[i]

    return (11 - (sum % 11)) % 10 === parseInt(value[10], 10)
}

export function rucErrorMessage(ruc) {
    return 'El RUC ' + String(ruc === null || ruc === undefined ? '' : ruc).trim() + ' no es válido (dígito verificador incorrecto).'
}
