<template>
    <div class="card mb-0 pt-2 pt-md-0">
        <div class="card-header bg-info d-flex align-items-center justify-content-between flex-wrap">
            <h3 class="my-0">Tesorería — Cheques y letras</h3>
            <div>
                <button type="button" class="btn btn-custom btn-sm mr-2" :disabled="loading" @click.prevent="loadRecords(true)">
                    <i class="fa fa-refresh"></i> Actualizar
                </button>
                <button type="button" class="btn btn-custom btn-sm" :disabled="!tablesLoaded" @click.prevent="openCreate">
                    <i class="fa fa-plus"></i> {{ newLabel }}
                </button>
            </div>
        </div>

        <div class="card-body">
            <div v-if="tablesError" class="alert alert-danger d-flex justify-content-between align-items-center" role="alert">
                <span>{{ tablesError }}</span>
                <el-button size="mini" type="danger" plain @click="loadTables">Reintentar</el-button>
            </div>

            <el-tabs v-model="activeTab" type="card">
                <el-tab-pane v-for="tab in tabs" :key="tab.type" :name="tab.type" :label="tab.label" />
            </el-tabs>

            <p class="text-muted small mb-3">{{ currentTab.help }}</p>

            <form class="row mb-3" @submit.prevent="loadRecords(1)">
                <div v-if="tables.is_admin && tables.establishments.length > 1" class="col-md-3 col-sm-6 mb-2">
                    <label for="ci-est" class="control-label">Sede</label>
                    <el-select id="ci-est" v-model="filters.establishment_id" clearable placeholder="Todas las sedes" class="w-100" @change="loadRecords(1)">
                        <el-option v-for="item in tables.establishments" :key="item.id" :label="item.description" :value="item.id" />
                    </el-select>
                </div>
                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="ci-status" class="control-label">Estado</label>
                    <el-select id="ci-status" v-model="filters.status" class="w-100" @change="loadRecords(1)">
                        <el-option label="Todos" value="all" />
                        <el-option label="Pendientes" value="open" />
                        <el-option v-for="(label, value) in currentStatusLabels" :key="value" :label="label" :value="value" />
                    </el-select>
                </div>
                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="ci-start" class="control-label">Vence desde</label>
                    <el-date-picker id="ci-start" v-model="filters.date_start" type="date" value-format="yyyy-MM-dd" placeholder="AAAA-MM-DD" class="w-100" @change="loadRecords(1)" />
                </div>
                <div class="col-md-2 col-sm-6 mb-2">
                    <label for="ci-end" class="control-label">Hasta</label>
                    <el-date-picker id="ci-end" v-model="filters.date_end" type="date" value-format="yyyy-MM-dd" placeholder="AAAA-MM-DD" class="w-100" @change="loadRecords(1)" />
                </div>
                <div class="col-md-3 col-sm-12 mb-2">
                    <label for="ci-search" class="control-label">Buscar</label>
                    <el-input
                        id="ci-search"
                        v-model="filters.search"
                        clearable
                        :placeholder="currentTab.isInflow ? 'Número o girador' : 'Número o beneficiario'"
                        @keyup.enter.native="loadRecords(1)"
                        @clear="loadRecords(1)"
                    />
                </div>
            </form>

            <div v-if="errorMessage" class="alert alert-danger d-flex justify-content-between align-items-center" role="alert">
                <span>{{ errorMessage }}</span>
                <el-button size="mini" type="danger" plain @click="loadRecords(1)">Reintentar</el-button>
            </div>

            <div v-if="loading" class="text-center py-5 text-muted" role="status" aria-live="polite">
                <i class="el-icon-loading mr-2"></i> Cargando...
            </div>

            <template v-else-if="!errorMessage">
                <div v-if="totals.length" class="row mb-3">
                    <div v-for="item in totals" :key="item.currency_type_id" class="col-6 col-md-3 mb-2">
                        <div class="border rounded p-2 h-100">
                            <div class="small text-muted">{{ currentTab.openLabel }} ({{ item.currency_type_id }})</div>
                            <div class="h5 mb-0">{{ money(item.amount, item.currency_type_id) }}</div>
                            <div class="small text-muted">{{ item.count }} documento(s)</div>
                        </div>
                    </div>
                </div>

                <div v-if="records.length === 0" class="alert alert-light border text-muted mb-0">
                    No hay documentos para los filtros seleccionados.
                </div>

                <div v-else class="table-responsive">
                    <table class="table table-sm table-hover mb-2">
                        <thead>
                            <tr>
                                <th>Número</th>
                                <th>{{ currentTab.isInflow ? 'Girador / cliente' : 'Beneficiario / proveedor' }}</th>
                                <th>{{ currentTab.isCheque ? 'Emisión / pago' : 'Emisión / vencimiento' }}</th>
                                <th>Plazo legal</th>
                                <th class="text-right">Importe</th>
                                <th class="text-center">Estado</th>
                                <th class="text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in records" :key="row.id">
                                <td>
                                    <div class="font-weight-bold">{{ row.number }}</div>
                                    <div class="small text-muted">{{ row.bank_name || row.bank_account_label || 'Sin banco' }}</div>
                                </td>
                                <td>
                                    <div>{{ row.counterparty_name || row.person_name || '-' }}</div>
                                    <div v-if="row.source_type" class="small text-muted">Vinculado a {{ row.source_type === 'document' ? 'comprobante' : 'compra' }} #{{ row.source_id }}</div>
                                </td>
                                <td>
                                    <div>{{ row.issue_date }}</div>
                                    <div class="small text-muted">
                                        {{ row.due_date }}
                                        <span v-if="row.is_deferred" class="badge badge-secondary ml-1">Pago diferido</span>
                                    </div>
                                </td>
                                <td>
                                    <div v-if="row.presentation_deadline" class="small">
                                        Presentar hasta {{ row.presentation_deadline }}
                                    </div>
                                    <div v-if="row.protest_deadline" class="small">
                                        Protesto hasta {{ row.protest_deadline }}
                                        <span v-if="row.deadline_is_estimate" class="text-muted">(estimado)</span>
                                    </div>
                                    <div v-for="flag in row.flags" :key="flag" class="small" :class="flagClass(flag)">{{ flagLabel(flag) }}</div>
                                </td>
                                <td class="text-right font-weight-bold">{{ money(row.amount, row.currency_type_id) }}</td>
                                <td class="text-center">
                                    <span class="badge" :class="statusClass(row.status)">{{ row.status_label }}</span>
                                </td>
                                <td>
                                    <div class="tn-row-actions">
                                        <el-button v-if="row.is_editable" size="mini" plain @click="openEdit(row)">Editar</el-button>
                                        <el-button
                                            v-for="action in row.actions"
                                            :key="action.to"
                                            size="mini"
                                            :type="action.destructive ? 'danger' : 'primary'"
                                            :plain="action.destructive"
                                            @click="openTransition(row, action)"
                                        >
                                            {{ action.label }}
                                        </el-button>
                                        <el-button size="mini" icon="el-icon-time" title="Historial" aria-label="Historial" @click="openHistory(row)" />
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-if="meta.last_page > 1" class="d-flex justify-content-end mt-2">
                        <el-pagination layout="prev, pager, next" :current-page="meta.current_page" :page-size="meta.per_page" :total="meta.total" @current-change="loadRecords" />
                    </div>
                </div>
            </template>
        </div>

        <!-- Create / edit -->
        <el-dialog :title="formTitle" :visible.sync="formVisible" width="640px" :close-on-click-modal="false" append-to-body>
            <form @submit.prevent="saveForm">
                <div v-if="formError" class="alert alert-danger py-2" role="alert">{{ formError }}</div>
                <div class="row">
                    <div class="col-md-6 mb-2">
                        <label for="cf-number" class="control-label">Número</label>
                        <el-input id="cf-number" v-model="form.number" maxlength="40" />
                        <small v-if="errors.number" class="text-danger">{{ errors.number[0] }}</small>
                    </div>
                    <div class="col-md-3 mb-2">
                        <label for="cf-currency" class="control-label">Moneda</label>
                        <el-select id="cf-currency" v-model="form.currency_type_id" class="w-100" @change="onCurrencyChange">
                            <el-option v-for="c in tables.currency_types" :key="c.id" :label="c.description" :value="c.id" />
                        </el-select>
                        <small v-if="errors.currency_type_id" class="text-danger">{{ errors.currency_type_id[0] }}</small>
                    </div>
                    <div class="col-md-3 mb-2">
                        <label for="cf-amount" class="control-label">Monto</label>
                        <el-input id="cf-amount" v-model="form.amount" type="number" step="0.01" min="0" />
                        <small v-if="errors.amount" class="text-danger">{{ errors.amount[0] }}</small>
                    </div>

                    <div class="col-md-6 mb-2">
                        <label for="cf-issue" class="control-label">Fecha de emisión</label>
                        <el-date-picker id="cf-issue" v-model="form.issue_date" type="date" value-format="yyyy-MM-dd" placeholder="AAAA-MM-DD" class="w-100" />
                        <small v-if="errors.issue_date" class="text-danger">{{ errors.issue_date[0] }}</small>
                    </div>
                    <div v-if="form.type && currentFormIsCheque" class="col-md-6 mb-2 d-flex align-items-end">
                        <el-checkbox v-model="form.is_deferred">Cheque de pago diferido (postfechado)</el-checkbox>
                    </div>
                    <div v-if="showDueDate" class="col-md-6 mb-2">
                        <label for="cf-due" class="control-label">{{ currentFormIsCheque ? 'Fecha de pago' : 'Fecha de vencimiento' }}</label>
                        <el-date-picker id="cf-due" v-model="form.due_date" type="date" value-format="yyyy-MM-dd" placeholder="AAAA-MM-DD" class="w-100" />
                        <small v-if="errors.due_date" class="text-danger">{{ errors.due_date[0] }}</small>
                        <small v-if="currentFormIsCheque" class="text-muted d-block">Máximo {{ tables.rules.cheque_deferred_max_days }} días desde la emisión.</small>
                    </div>

                    <div class="col-md-6 mb-2">
                        <label for="cf-person" class="control-label">{{ formIsInflow ? 'Cliente' : 'Proveedor' }}</label>
                        <el-select
                            id="cf-person"
                            v-model="form.person_id"
                            filterable
                            remote
                            clearable
                            reserve-keyword
                            :remote-method="searchPersons"
                            :loading="personLoading"
                            placeholder="Buscar por nombre o documento"
                            class="w-100"
                            @change="onPersonChange"
                        >
                            <el-option v-for="p in personOptions" :key="p.id" :label="p.name + (p.number ? ' - ' + p.number : '')" :value="p.id" />
                        </el-select>
                        <small v-if="errors.person_id" class="text-danger">{{ errors.person_id[0] }}</small>
                    </div>
                    <div class="col-md-6 mb-2">
                        <label v-if="formIsInflow" for="cf-drawer" class="control-label">Nombre del girador</label>
                        <label v-else for="cf-payee" class="control-label">Nombre del beneficiario</label>
                        <el-input v-if="formIsInflow" id="cf-drawer" v-model="form.drawer_name" maxlength="255" />
                        <el-input v-else id="cf-payee" v-model="form.payee_name" maxlength="255" />
                        <small v-if="errors.drawer_name" class="text-danger">{{ errors.drawer_name[0] }}</small>
                        <small v-if="errors.payee_name" class="text-danger">{{ errors.payee_name[0] }}</small>
                    </div>

                    <div class="col-md-6 mb-2">
                        <label for="cf-bank" class="control-label">{{ currentFormIsCheque ? 'Banco librado' : 'Banco' }}</label>
                        <el-select id="cf-bank" v-model="form.bank_id" filterable clearable placeholder="Seleccione" class="w-100">
                            <el-option v-for="b in tables.banks" :key="b.id" :label="b.description" :value="b.id" />
                        </el-select>
                        <small v-if="errors.bank_id" class="text-danger">{{ errors.bank_id[0] }}</small>
                    </div>
                    <div v-if="form.type === 'cheque_issued'" class="col-md-6 mb-2">
                        <label for="cf-account" class="control-label">Cuenta de giro</label>
                        <el-select id="cf-account" v-model="form.bank_account_id" placeholder="Seleccione" class="w-100">
                            <el-option v-for="a in formAccounts" :key="a.id" :label="a.description" :value="a.id" />
                        </el-select>
                        <small v-if="errors.bank_account_id" class="text-danger">{{ errors.bank_account_id[0] }}</small>
                    </div>
                    <div v-if="tables.is_admin && tables.establishments.length > 1 && !editingId" class="col-md-6 mb-2">
                        <label for="cf-est" class="control-label">Sede</label>
                        <el-select id="cf-est" v-model="form.establishment_id" placeholder="Seleccione" class="w-100">
                            <el-option v-for="item in tables.establishments" :key="item.id" :label="item.description" :value="item.id" />
                        </el-select>
                        <small v-if="errors.establishment_id" class="text-danger">{{ errors.establishment_id[0] }}</small>
                    </div>

                    <div class="col-md-12 mb-2">
                        <label for="cf-source" class="control-label">{{ formIsInflow ? 'Comprobante por cobrar (opcional)' : 'Compra por pagar (opcional)' }}</label>
                        <el-select
                            id="cf-source"
                            v-model="form.source_id"
                            filterable
                            remote
                            clearable
                            reserve-keyword
                            :remote-method="searchSources"
                            :loading="sourceLoading"
                            placeholder="Buscar por serie o número"
                            class="w-100"
                        >
                            <el-option v-for="s in sourceOptions" :key="s.id" :label="s.label + ' - ' + s.currency_type_id + ' ' + s.total" :value="s.id" />
                        </el-select>
                        <small v-if="errors.source_id" class="text-danger">{{ errors.source_id[0] }}</small>
                        <small class="text-muted d-block">
                            Al vincularlo, el flujo de caja proyectado no cuenta dos veces el mismo importe. El saldo del comprobante se sigue cancelando desde Cobranzas y pagos.
                        </small>
                    </div>

                    <div class="col-md-12 mb-2">
                        <label for="cf-notes" class="control-label">Observaciones</label>
                        <el-input id="cf-notes" v-model="form.notes" type="textarea" :rows="2" maxlength="500" />
                        <small v-if="errors.notes" class="text-danger">{{ errors.notes[0] }}</small>
                    </div>
                </div>

                <p class="text-muted small mb-0">
                    <template v-if="currentFormIsCheque">
                        Plazo de presentación: {{ tables.rules.cheque_presentation_days }} días{{ form.is_deferred ? ' desde la fecha de pago' : ' desde la emisión' }}.
                    </template>
                    <template v-else>
                        Plazo de protesto: {{ tables.rules.bill_protest_days }} días {{ tables.rules.protest_day_type === 'business' ? 'hábiles' : 'calendario' }} después del vencimiento.
                    </template>
                    Los plazos se calculan al guardar según la Ley de Títulos Valores.
                </p>
            </form>
            <span slot="footer" class="dialog-footer">
                <el-button @click="formVisible = false">Cancelar</el-button>
                <el-button type="primary" :loading="saving" @click="saveForm">Guardar</el-button>
            </span>
        </el-dialog>

        <!-- Transition -->
        <el-dialog :title="transitionTitle" :visible.sync="transitionVisible" width="480px" :close-on-click-modal="false" append-to-body>
            <form v-if="transitionRow" @submit.prevent="submitTransition">
                <div v-if="transitionError" class="alert alert-danger py-2" role="alert">{{ transitionError }}</div>
                <p class="mb-3">
                    {{ transitionRow.type_label }} <strong>{{ transitionRow.number }}</strong> por
                    <strong>{{ money(transitionRow.amount, transitionRow.currency_type_id) }}</strong>
                </p>
                <div class="mb-2">
                    <label for="tr-date" class="control-label">Fecha de la operación</label>
                    <el-date-picker id="tr-date" v-model="transition.date" type="date" value-format="yyyy-MM-dd" class="w-100" />
                    <small v-if="errors.date" class="text-danger">{{ errors.date[0] }}</small>
                </div>
                <div v-if="transitionAction && transitionAction.needs_account" class="mb-2">
                    <label for="tr-account" class="control-label">Cuenta bancaria</label>
                    <el-select id="tr-account" v-model="transition.bank_account_id" placeholder="Seleccione" class="w-100">
                        <el-option v-for="a in transitionAccounts" :key="a.id" :label="a.description" :value="a.id" />
                    </el-select>
                    <small v-if="errors.bank_account_id" class="text-danger">{{ errors.bank_account_id[0] }}</small>
                    <small v-if="transitionAccounts.length === 0" class="text-danger d-block">No hay cuentas activas en {{ transitionRow.currency_type_id }} disponibles.</small>
                </div>
                <div class="mb-2">
                    <label for="tr-notes" class="control-label">{{ transitionAction && transitionAction.needs_notes ? 'Motivo' : 'Observaciones (opcional)' }}</label>
                    <el-input id="tr-notes" v-model="transition.notes" type="textarea" :rows="2" maxlength="500" />
                    <small v-if="errors.notes" class="text-danger">{{ errors.notes[0] }}</small>
                </div>
                <div v-if="transitionEarlyWarning" class="alert alert-warning small py-2 mb-2" role="alert">{{ transitionEarlyWarning }}</div>
                <p v-if="transitionHint" class="text-muted small mb-0">{{ transitionHint }}</p>
            </form>
            <span slot="footer" class="dialog-footer">
                <el-button @click="transitionVisible = false">Cancelar</el-button>
                <el-button :type="transitionAction && transitionAction.destructive ? 'danger' : 'primary'" :loading="saving" @click="submitTransition">
                    {{ transitionAction ? transitionAction.label : 'Confirmar' }}
                </el-button>
            </span>
        </el-dialog>

        <!-- History -->
        <el-drawer :title="historyTitle" :visible.sync="historyVisible" direction="rtl" size="420px" append-to-body>
            <div class="px-3 pb-3">
                <div v-if="historyLoading" class="text-center py-4 text-muted" role="status" aria-live="polite">
                    <i class="el-icon-loading mr-2"></i> Cargando historial...
                </div>
                <div v-else-if="historyError" class="alert alert-danger" role="alert">{{ historyError }}</div>
                <div v-else-if="history.length === 0" class="text-muted">Sin movimientos registrados.</div>
                <el-timeline v-else>
                    <el-timeline-item v-for="event in history" :key="event.id" :timestamp="event.date + (event.user ? ' · ' + event.user : '')" placement="top">
                        <div class="font-weight-bold">
                            <template v-if="event.action === 'created'">Registrado: {{ event.to_label }}</template>
                            <template v-else-if="event.action === 'updated'">Datos actualizados</template>
                            <template v-else>{{ event.from_label }} → {{ event.to_label }}</template>
                        </div>
                        <div v-if="event.notes" class="small text-muted">{{ event.notes }}</div>
                        <div v-if="event.movement_id" class="small text-muted">Movimiento de tesorería #{{ event.movement_id }}</div>
                    </el-timeline-item>
                </el-timeline>
            </div>
        </el-drawer>
    </div>
</template>

<script>
import { money, parseError, today } from '../reconciliation/helpers'

const TAB_DEFS = [
    {
        type: 'cheque_received',
        label: 'Cheques recibidos',
        help: 'Cheques de clientes en su poder. Al depositarlos y cobrarlos se registra el ingreso en la cuenta bancaria; si el banco los rechaza, el cobro se revierte.',
        isInflow: true,
        isCheque: true,
        openLabel: 'Por cobrar',
    },
    {
        type: 'cheque_issued',
        label: 'Cheques emitidos',
        help: 'Cheques girados a proveedores. Cuando el beneficiario los cobra se registra el egreso de la cuenta de giro.',
        isInflow: false,
        isCheque: true,
        openLabel: 'Por pagar',
    },
    {
        type: 'bill_receivable',
        label: 'Letras por cobrar',
        help: 'Letras de cambio giradas a clientes. Registre la aceptación, el envío al banco, el cobro o el protesto.',
        isInflow: true,
        isCheque: false,
        openLabel: 'Por cobrar',
    },
    {
        type: 'bill_payable',
        label: 'Letras por pagar',
        help: 'Letras de cambio aceptadas a favor de proveedores. El pago registra el egreso de la cuenta bancaria.',
        isInflow: false,
        isCheque: false,
        openLabel: 'Por pagar',
    },
]

export default {
    name: 'TreasuryInstrumentsIndex',
    data() {
        return {
            tabs: TAB_DEFS,
            activeTab: 'cheque_received',
            tables: {
                establishments: [],
                banks: [],
                accounts: [],
                currency_types: [],
                types: [],
                rules: { cheque_presentation_days: 30, cheque_deferred_max_days: 30, bill_protest_days: 15, protest_day_type: 'business' },
                is_admin: false,
                default_establishment_id: null,
            },
            tablesLoaded: false,
            tablesError: null,
            filters: { establishment_id: null, status: 'all', date_start: null, date_end: null, search: '' },
            records: [],
            totals: [],
            meta: { total: 0, per_page: 20, current_page: 1, last_page: 1 },
            loading: false,
            errorMessage: null,
            requestToken: 0,
            // Create / edit
            formVisible: false,
            editingId: null,
            form: this.emptyForm('cheque_received'),
            formError: null,
            errors: {},
            saving: false,
            personOptions: [],
            personLoading: false,
            sourceOptions: [],
            sourceLoading: false,
            // Transition
            transitionVisible: false,
            transitionRow: null,
            transitionAction: null,
            transition: { date: today(), bank_account_id: null, notes: '' },
            transitionError: null,
            // History
            historyVisible: false,
            historyLoading: false,
            historyError: null,
            historyRow: null,
            history: [],
        }
    },
    computed: {
        currentTab() {
            return this.tabs.filter(t => t.type === this.activeTab)[0]
        },
        newLabel() {
            const labels = {
                cheque_received: 'Nuevo cheque recibido',
                cheque_issued: 'Nuevo cheque emitido',
                bill_receivable: 'Nueva letra por cobrar',
                bill_payable: 'Nueva letra por pagar',
            }
            return labels[this.activeTab]
        },
        currentStatusLabels() {
            const type = this.tables.types.filter(t => t.value === this.activeTab)[0]
            return type ? type.status_labels : {}
        },
        formTitle() {
            const tab = this.tabs.filter(t => t.type === this.form.type)[0]
            return (this.editingId ? 'Editar ' : 'Registrar ') + (tab ? tab.label.toLowerCase() : '')
        },
        formTab() {
            return this.tabs.filter(t => t.type === this.form.type)[0] || this.currentTab
        },
        formIsInflow() {
            return this.formTab.isInflow
        },
        currentFormIsCheque() {
            return this.formTab.isCheque
        },
        showDueDate() {
            return this.currentFormIsCheque ? Boolean(this.form.is_deferred) : true
        },
        formAccounts() {
            return this.tables.accounts.filter(a => a.currency_type_id === this.form.currency_type_id)
        },
        transitionAccounts() {
            if (!this.transitionRow) {
                return []
            }
            return this.tables.accounts.filter(a => a.currency_type_id === this.transitionRow.currency_type_id)
        },
        transitionTitle() {
            return this.transitionAction ? this.transitionAction.label : ''
        },
        transitionHint() {
            if (!this.transitionAction || !this.transitionRow) {
                return ''
            }
            const to = this.transitionAction.to
            const linked = Boolean(this.transitionRow.source_id)
            const isInflow = this.transitionRow.type.indexOf('receiv') !== -1 || this.transitionRow.type === 'cheque_received'
            if (to === 'collected') {
                if (linked) {
                    return isInflow
                        ? 'Se registrará el cobro en el comprobante vinculado (igual que desde Cobranzas) y el saldo de la cuenta bancaria seleccionada aumentará.'
                        : 'Se registrará el pago en la compra vinculada (igual que desde Pagos) y el saldo de la cuenta bancaria seleccionada disminuirá.'
                }
                return isInflow
                    ? 'Se registrará un ingreso en la cuenta bancaria seleccionada.'
                    : 'Se registrará un egreso de la cuenta bancaria seleccionada.'
            }
            if (this.transitionRow.status === 'collected' && (to === 'bounced' || to === 'delivered')) {
                return linked
                    ? 'Se eliminará el pago registrado en el documento vinculado y el saldo del documento volverá a quedar pendiente.'
                    : 'Se anulará el movimiento de tesorería del cobro o pago y el saldo de la cuenta volverá a su valor anterior.'
            }
            if (to === 'protested') {
                return 'El protesto no mueve dinero: solo deja constancia y controla el plazo legal.'
            }
            return ''
        },
        // Deferred cheque collected before its payment date: allowed, but the bank may refuse it (warning only).
        transitionEarlyWarning() {
            const action = this.transitionAction
            const row = this.transitionRow
            if (!action || !row || !action.warn_if_early || !row.due_date || !this.transition.date) {
                return ''
            }
            return this.transition.date < row.due_date
                ? 'Es un cheque de pago diferido y su fecha de pago es el ' + row.due_date + '. Verifique que el banco ya pueda hacerlo efectivo.'
                : ''
        },
        historyTitle() {
            return this.historyRow ? this.historyRow.type_label + ' ' + this.historyRow.number : 'Historial'
        },
    },
    watch: {
        activeTab() {
            this.filters = { establishment_id: null, status: 'all', date_start: null, date_end: null, search: '' }
            this.records = []
            this.totals = []
            this.loadRecords(1)
        },
    },
    created() {
        this.loadTables()
        this.loadRecords(1)
    },
    methods: {
        money(value, currency) {
            return money(value, currency || 'PEN')
        },
        emptyForm(type) {
            return {
                type: type,
                number: '',
                currency_type_id: 'PEN',
                amount: null,
                issue_date: today(),
                due_date: null,
                is_deferred: false,
                bank_id: null,
                bank_account_id: null,
                person_id: null,
                drawer_name: '',
                payee_name: '',
                establishment_id: null,
                source_id: null,
                notes: '',
            }
        },
        async loadTables() {
            this.tablesError = null
            try {
                const { data } = await this.$http.get('/treasury/instruments/tables')
                this.tables = data
                this.tablesLoaded = true
            } catch (error) {
                this.tablesError = parseError(error).general
            }
        },
        // `arg` is a page number (pagination / filters), `true` for a silent refresh. A bare `@click="loadRecords"`
        // would pass the MouseEvent, so every caller passes an explicit value.
        async loadRecords(arg) {
            const silent = arg === true
            const page = typeof arg === 'number' ? arg : (silent ? this.meta.current_page : 1)
            const token = ++this.requestToken
            if (!silent) {
                this.loading = true
            }
            this.errorMessage = null
            try {
                const params = {
                    type: this.activeTab,
                    status: this.filters.status,
                    page: page,
                    per_page: 20,
                }
                if (this.filters.establishment_id) {
                    params.establishment_id = this.filters.establishment_id
                }
                if (this.filters.date_start) {
                    params.date_start = this.filters.date_start
                }
                if (this.filters.date_end) {
                    params.date_end = this.filters.date_end
                }
                if (this.filters.search) {
                    params.search = this.filters.search
                }
                const { data } = await this.$http.get('/treasury/instruments/records', { params })
                if (token !== this.requestToken) {
                    return
                }
                this.records = data.data.rows
                this.totals = data.data.totals
                this.meta = data.data.meta
            } catch (error) {
                if (token === this.requestToken) {
                    this.errorMessage = parseError(error).general
                }
            } finally {
                if (token === this.requestToken) {
                    this.loading = false
                }
            }
        },
        statusClass(status) {
            const map = {
                issued: 'badge-secondary',
                delivered: 'badge-info',
                deposited: 'badge-primary',
                collected: 'badge-success',
                bounced: 'badge-danger',
                protested: 'badge-danger',
                voided: 'badge-light',
            }
            return map[status] || 'badge-secondary'
        },
        flagLabel(flag) {
            const map = {
                presentation_expired: 'Plazo de presentación vencido',
                presentation_soon: 'Presentación próxima a vencer',
                overdue: 'Vencida',
                due_soon: 'Vence pronto',
                protest_open: 'Protesto aún posible',
                protest_soon: 'Plazo de protesto por vencer',
                protest_expired: 'Plazo de protesto vencido',
            }
            return map[flag] || flag
        },
        flagClass(flag) {
            return ['presentation_expired', 'overdue', 'protest_expired'].indexOf(flag) !== -1 ? 'text-danger' : (flag === 'protest_open' ? 'text-muted' : 'text-warning')
        },
        // Message to show at the top of a dialog: the first error whose key has no input of its own
        // (e.g. `status`, `movement`), or the general message when the failure was not a 422.
        unplacedError(parsed, placedKeys) {
            const keys = Object.keys(parsed.fields)
            if (!keys.length) {
                return parsed.general
            }
            const loose = keys.filter(k => placedKeys.indexOf(k) === -1)
            return loose.length ? parsed.fields[loose[0]][0] : null
        },
        // ---- create / edit ----
        openCreate() {
            this.editingId = null
            this.form = this.emptyForm(this.activeTab)
            this.form.establishment_id = this.tables.default_establishment_id
            this.errors = {}
            this.formError = null
            this.personOptions = []
            this.sourceOptions = []
            this.formVisible = true
            this.searchPersons('')
        },
        openEdit(row) {
            this.editingId = row.id
            this.form = {
                type: row.type,
                number: row.number,
                currency_type_id: row.currency_type_id,
                amount: row.amount,
                issue_date: row.issue_date,
                due_date: row.due_date,
                is_deferred: row.is_deferred,
                bank_id: row.bank_id,
                bank_account_id: row.bank_account_id,
                person_id: row.person_id,
                drawer_name: row.drawer_name || '',
                payee_name: row.payee_name || '',
                establishment_id: row.establishment_id,
                source_id: row.source_id,
                notes: row.notes || '',
            }
            this.errors = {}
            this.formError = null
            this.personOptions = row.person_id ? [{ id: row.person_id, name: row.person_name || '', number: '' }] : []
            this.sourceOptions = row.source_id ? [{ id: row.source_id, label: '#' + row.source_id, currency_type_id: row.currency_type_id, total: '' }] : []
            this.formVisible = true
        },
        onCurrencyChange() {
            this.form.bank_account_id = null
        },
        onPersonChange(value) {
            const person = this.personOptions.filter(p => p.id === value)[0]
            if (!person) {
                return
            }
            if (this.formIsInflow && !this.form.drawer_name) {
                this.form.drawer_name = person.name
            }
            if (!this.formIsInflow && !this.form.payee_name) {
                this.form.payee_name = person.name
            }
        },
        async searchPersons(query) {
            this.personLoading = true
            try {
                const { data } = await this.$http.get('/treasury/instruments/persons', { params: { type: this.form.type, search: query || '' } })
                this.personOptions = data.data
            } catch (error) {
                this.personOptions = []
            } finally {
                this.personLoading = false
            }
        },
        async searchSources(query) {
            this.sourceLoading = true
            try {
                const { data } = await this.$http.get('/treasury/instruments/sources', { params: { type: this.form.type, search: query || '' } })
                this.sourceOptions = data.data
            } catch (error) {
                this.sourceOptions = []
            } finally {
                this.sourceLoading = false
            }
        },
        async saveForm() {
            this.saving = true
            this.errors = {}
            this.formError = null
            try {
                const payload = Object.assign({}, this.form)
                if (payload.is_deferred !== true) {
                    payload.due_date = this.currentFormIsCheque ? null : payload.due_date
                }
                if (this.editingId) {
                    await this.$http.put('/treasury/instruments/' + this.editingId, payload)
                    this.$message.success('Datos actualizados.')
                } else {
                    await this.$http.post('/treasury/instruments', payload)
                    this.$message.success('Documento registrado.')
                }
                this.formVisible = false
                await this.loadRecords(true)
            } catch (error) {
                const parsed = parseError(error)
                this.errors = parsed.fields
                // 422 messages sit next to their fields; anything else (state errors, 403, 500) goes to the top.
                this.formError = this.unplacedError(parsed, Object.keys(this.form))
            } finally {
                this.saving = false
            }
        },
        // ---- transitions ----
        openTransition(row, action) {
            this.transitionRow = row
            this.transitionAction = action
            this.transition = {
                date: today(),
                bank_account_id: row.bank_account_id,
                notes: '',
            }
            this.errors = {}
            this.transitionError = null
            this.transitionVisible = true
        },
        async submitTransition() {
            const action = this.transitionAction
            const row = this.transitionRow
            if (!action || !row) {
                return
            }
            if (action.destructive) {
                try {
                    await this.$confirm(
                        action.to === 'voided'
                            ? '¿Anular ' + row.type_label.toLowerCase() + ' ' + row.number + '? Esta acción no se puede deshacer.'
                            : row.status === 'collected' && action.to === 'delivered'
                                ? '¿Revertir el cobro o pago de ' + row.number + '? Se deshará el efecto en el saldo de la cuenta' + (row.source_id ? ' y en el documento vinculado.' : '.')
                                : '¿Registrar el rechazo de ' + row.number + '?' + (row.status === 'collected' ? ' Se deshará el cobro o pago registrado.' : ''),
                        action.label,
                        { confirmButtonText: 'Confirmar', cancelButtonText: 'Cancelar', type: 'warning' }
                    )
                } catch (cancelled) {
                    return
                }
            }

            this.saving = true
            this.errors = {}
            this.transitionError = null
            try {
                const payload = { to: action.to, date: this.transition.date, notes: this.transition.notes || null }
                if (action.needs_account) {
                    payload.bank_account_id = this.transition.bank_account_id
                }
                const { data } = await this.$http.post('/treasury/instruments/' + row.id + '/transition', payload)
                this.$message.success(data.message)
                ;(data.warnings || []).forEach(text => this.$message({ type: 'warning', message: text, duration: 8000 }))
                this.transitionVisible = false
                await this.loadRecords(true)
            } catch (error) {
                const parsed = parseError(error)
                this.errors = parsed.fields
                // Errors without a field of their own (state machine, closed reconciliation, 403) go to the top.
                this.transitionError = this.unplacedError(parsed, ['date', 'bank_account_id', 'notes'])
            } finally {
                this.saving = false
            }
        },
        // ---- history ----
        async openHistory(row) {
            this.historyRow = row
            this.history = []
            this.historyError = null
            this.historyVisible = true
            this.historyLoading = true
            try {
                const { data } = await this.$http.get('/treasury/instruments/' + row.id + '/history')
                this.history = data.data.events
            } catch (error) {
                this.historyError = parseError(error).general
            } finally {
                this.historyLoading = false
            }
        },
    },
}
</script>
