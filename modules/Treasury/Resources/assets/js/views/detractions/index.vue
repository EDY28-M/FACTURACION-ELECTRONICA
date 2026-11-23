<template>
    <div class="card mb-0 pt-2 pt-md-0">
        <div class="card-header bg-info d-flex align-items-center justify-content-between flex-wrap">
            <h3 class="my-0">Tesorería — Detracciones</h3>
            <div>
                <a class="btn btn-custom btn-sm mr-2" href="/treasury/receivables-payables">Cobranzas y pagos</a>
                <button type="button" class="btn btn-custom btn-sm mr-2" :disabled="syncing" @click.prevent="syncNow">
                    <i class="fa fa-refresh"></i> {{ syncing ? 'Sincronizando...' : 'Sincronizar' }}
                </button>
                <button
                    v-if="tables.is_admin"
                    type="button"
                    class="btn btn-custom btn-sm"
                    @click.prevent="openRelease"
                >
                    <i class="fa fa-unlock"></i> Liberación de fondos
                </button>
            </div>
        </div>

        <div class="card-body">
            <el-tabs v-model="activeTab" type="card" @tab-click="onTabClick">
                <!-- Receivable / Payable share the same layout -->
                <el-tab-pane
                    v-for="tab in sideTabs"
                    :key="tab.side"
                    :name="tab.side"
                    :label="tab.label"
                >
                    <template v-if="activeTab === tab.side">
                    <p class="text-muted small mb-3">{{ tab.help }}</p>

                    <form class="row mb-3" @submit.prevent="loadRecords(1)">
                        <div class="col-md-3 col-sm-6 mb-2">
                            <label :for="'dt-est-' + tab.side" class="control-label">Sede</label>
                            <el-select
                                :id="'dt-est-' + tab.side"
                                v-model="filters.establishment_id"
                                clearable
                                placeholder="Todas las sedes"
                                class="w-100"
                                @change="loadRecords(1)"
                            >
                                <el-option
                                    v-for="item in tables.establishments"
                                    :key="item.id"
                                    :label="item.description"
                                    :value="item.id"
                                />
                            </el-select>
                        </div>
                        <div class="col-md-2 col-sm-6 mb-2">
                            <label :for="'dt-status-' + tab.side" class="control-label">Estado</label>
                            <el-select
                                :id="'dt-status-' + tab.side"
                                v-model="filters.status"
                                class="w-100"
                                @change="loadRecords(1)"
                            >
                                <el-option label="Todos" value="all" />
                                <el-option label="Pendientes" value="pending" />
                                <el-option label="Vencidas" value="overdue" />
                                <el-option :label="tab.side === 'receivable' ? 'Recibidas' : 'Depositadas'" :value="tab.side === 'receivable' ? 'received' : 'deposited'" />
                            </el-select>
                        </div>
                        <div class="col-md-2 col-sm-6 mb-2">
                            <label :for="'dt-start-' + tab.side" class="control-label">Emisión desde</label>
                            <el-date-picker
                                :id="'dt-start-' + tab.side"
                                v-model="filters.date_start"
                                type="date"
                                value-format="yyyy-MM-dd"
                                placeholder="AAAA-MM-DD"
                                class="w-100"
                                @change="loadRecords(1)"
                            />
                        </div>
                        <div class="col-md-2 col-sm-6 mb-2">
                            <label :for="'dt-end-' + tab.side" class="control-label">Hasta</label>
                            <el-date-picker
                                :id="'dt-end-' + tab.side"
                                v-model="filters.date_end"
                                type="date"
                                value-format="yyyy-MM-dd"
                                placeholder="AAAA-MM-DD"
                                class="w-100"
                                @change="loadRecords(1)"
                            />
                        </div>
                        <div class="col-md-3 col-sm-12 mb-2">
                            <label :for="'dt-search-' + tab.side" class="control-label">Buscar</label>
                            <el-input
                                :id="'dt-search-' + tab.side"
                                v-model="filters.search"
                                clearable
                                :placeholder="tab.side === 'receivable' ? 'Comprobante, cliente o constancia' : 'Comprobante, proveedor o constancia'"
                                @keyup.enter.native="loadRecords(1)"
                                @clear="loadRecords(1)"
                            />
                        </div>
                    </form>

                    <div v-if="errorMessage" class="alert alert-danger d-flex justify-content-between align-items-center" role="alert">
                        <span>{{ errorMessage }}</span>
                        <el-button size="mini" type="danger" plain @click="loadRecords(meta.current_page)">Reintentar</el-button>
                    </div>

                    <div v-if="loading" class="text-center py-5 text-muted" role="status" aria-live="polite">
                        <i class="el-icon-loading mr-2"></i> Cargando detracciones...
                    </div>

                    <template v-else-if="!errorMessage">
                        <div class="row mb-3">
                            <div class="col-6 col-md-3 mb-2" v-for="card in summaryCards" :key="card.key">
                                <div class="border rounded p-2 h-100">
                                    <div class="small text-muted">{{ card.label }}</div>
                                    <div class="h5 mb-0">{{ money(card.amount) }}</div>
                                    <div class="small text-muted">{{ card.count }} registro(s)</div>
                                </div>
                            </div>
                        </div>

                        <div v-if="records.length === 0" class="alert alert-light border text-muted mb-0">
                            No hay detracciones para los filtros seleccionados.
                        </div>

                        <div v-else class="table-responsive">
                            <table class="table table-sm table-hover mb-2">
                                <thead>
                                    <tr>
                                        <th>Comprobante</th>
                                        <th>{{ tab.side === 'receivable' ? 'Cliente' : 'Proveedor' }}</th>
                                        <th>Código / Tasa</th>
                                        <th class="text-right">Detracción (S/)</th>
                                        <th>{{ tab.side === 'receivable' ? 'Fecha estimada' : 'Fecha límite' }}</th>
                                        <th class="text-center">Estado</th>
                                        <th>Constancia / Depósito</th>
                                        <th class="text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in records" :key="row.id">
                                        <td>
                                            <div class="font-weight-bold">{{ row.number_full }}</div>
                                            <div class="small text-muted">{{ row.date_of_issue }} · {{ money(row.base_amount, row.currency_type_id) }}</div>
                                        </td>
                                        <td>
                                            <div>{{ row.party_name }}</div>
                                            <div v-if="row.party_number" class="small text-muted">{{ row.party_number }}</div>
                                        </td>
                                        <td>
                                            <div>{{ row.code || '-' }}</div>
                                            <div class="small text-muted">
                                                {{ row.code_description }}
                                                <span v-if="row.rate_percent !== null"> · {{ row.rate_percent }}%</span>
                                            </div>
                                            <div v-if="row.rate_differs" class="small text-warning">
                                                Tasa vigente en la fecha de emisión: {{ row.expected_rate_percent }}%
                                            </div>
                                        </td>
                                        <td class="text-right font-weight-bold">
                                            {{ money(row.amount_pen) }}
                                            <div v-if="tab.side === 'receivable' && row.net_amount !== null" class="small text-muted font-weight-normal">
                                                Neto cliente: {{ money(row.net_amount) }}
                                            </div>
                                        </td>
                                        <td>
                                            <div>
                                                {{ row.due_date || '-' }}
                                                <span v-if="row.due_date && row.due_date_is_estimate" class="small text-muted">(estimado)</span>
                                            </div>
                                            <div v-if="row.status === 'overdue'" class="small text-danger">Vencida hace {{ row.days_overdue }} d</div>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge" :class="statusClass(row.status)">{{ statusLabel(row.status, tab.side) }}</span>
                                        </td>
                                        <td>
                                            <template v-if="row.deposited_at">
                                                <div>{{ row.deposited_at }}</div>
                                                <div v-if="row.constancia_number" class="small text-muted">Constancia: {{ row.constancia_number }}</div>
                                                <div v-if="row.operation_number" class="small text-muted">Op: {{ row.operation_number }}</div>
                                            </template>
                                            <span v-else class="text-muted small">Sin depósito</span>
                                        </td>
                                        <td class="text-right text-nowrap">
                                            <el-button
                                                v-if="row.stored_status === 'pending'"
                                                size="mini"
                                                type="primary"
                                                @click="openDeposit(row)"
                                            >
                                                {{ tab.side === 'receivable' ? 'Marcar recibida' : 'Registrar depósito' }}
                                            </el-button>
                                            <el-button
                                                v-else
                                                size="mini"
                                                type="danger"
                                                plain
                                                @click="openRevert(row)"
                                            >
                                                Revertir
                                            </el-button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>

                            <div v-if="meta.last_page > 1" class="d-flex justify-content-end mt-2">
                                <el-pagination
                                    layout="prev, pager, next"
                                    :current-page="meta.current_page"
                                    :page-size="meta.per_page"
                                    :total="meta.total"
                                    @current-change="loadRecords"
                                />
                            </div>
                        </div>
                    </template>
                    </template>
                </el-tab-pane>

                <!-- Retentions and perceptions linked in Treasury -->
                <el-tab-pane name="withholdings" label="Retenciones y percepciones">
                    <p class="text-muted small mb-3">
                        Comprobantes de retención (CRE) y percepción (CPE) vinculados a pagos y cobros. Los montos
                        retenidos o percibidos como agente quedan como deuda con SUNAT hasta que registre el pago de tributos.
                    </p>

                    <div v-if="wh.error" class="alert alert-danger d-flex justify-content-between align-items-center" role="alert">
                        <span>{{ wh.error }}</span>
                        <el-button size="mini" type="danger" plain @click="loadWithholdings(1)">Reintentar</el-button>
                    </div>

                    <div v-if="wh.loading" class="text-center py-5 text-muted" role="status" aria-live="polite">
                        <i class="el-icon-loading mr-2"></i> Cargando comprobantes...
                    </div>

                    <template v-else-if="!wh.error">
                        <div class="row mb-3">
                            <div class="col-md-4 mb-2">
                                <label for="wh-kind" class="control-label">Tipo</label>
                                <el-select id="wh-kind" v-model="wh.filters.kind" clearable placeholder="Todos" class="w-100" @change="loadWithholdings(1)">
                                    <el-option v-for="k in wh.kinds" :key="k.value" :label="k.label" :value="k.value" />
                                </el-select>
                            </div>
                            <div class="col-md-4 mb-2">
                                <label for="wh-state" class="control-label">Estado</label>
                                <el-select id="wh-state" v-model="wh.filters.state" class="w-100" @change="loadWithholdings(1)">
                                    <el-option label="Activos" value="active" />
                                    <el-option label="Anulados" value="voided" />
                                </el-select>
                            </div>
                        </div>

                        <div v-if="wh.sunat.total_pen > 0" class="alert alert-warning py-2 px-3 small">
                            <strong>Pendiente de pago a SUNAT: {{ money(wh.sunat.total_pen) }}</strong>
                            <span v-for="(amount, period) in wh.sunat.by_period" :key="period" class="ml-3">
                                {{ period }}: {{ money(amount) }}
                            </span>
                            <div class="text-muted">
                                Retenciones: Formulario 626 (PDT Agentes de Retención), mensual.
                            </div>
                        </div>

                        <div v-if="wh.records.length === 0" class="alert alert-light border text-muted mb-0">
                            No hay comprobantes de retención o percepción enlazados.
                            <span v-if="!wh.flags.is_retention_agent && !wh.flags.is_perception_agent">
                                La empresa no está registrada como agente de retención ni de percepción; solo puede registrar retenciones recibidas desde Cobranzas y pagos.
                            </span>
                        </div>

                        <div v-else class="table-responsive">
                            <table class="table table-sm table-hover mb-2">
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Tipo</th>
                                        <th>Comprobante</th>
                                        <th>Contraparte</th>
                                        <th class="text-right">Base</th>
                                        <th class="text-right">Monto</th>
                                        <th class="text-center">SUNAT</th>
                                        <th class="text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in wh.records" :key="row.id" :class="row.state === 'voided' ? 'text-muted' : ''">
                                        <td>{{ row.date }}</td>
                                        <td>{{ kindLabel(row.kind) }}</td>
                                        <td>{{ row.certificate }}</td>
                                        <td>
                                            <div>{{ row.counterparty_name }}</div>
                                            <div class="small text-muted">{{ row.counterparty_document }}</div>
                                        </td>
                                        <td class="text-right">{{ money(row.base_amount, row.currency_type_id) }}</td>
                                        <td class="text-right font-weight-bold">
                                            {{ money(row.amount, row.currency_type_id) }}
                                            <div v-if="row.rate_percent !== null" class="small text-muted font-weight-normal">{{ row.rate_percent }}%</div>
                                        </td>
                                        <td class="text-center">
                                            <span v-if="row.state === 'voided'" class="badge badge-danger">Anulado</span>
                                            <span v-else-if="!row.payable_to_sunat" class="badge badge-light border">No aplica</span>
                                            <span v-else-if="row.settled" class="badge badge-success">Pagado</span>
                                            <span v-else class="badge badge-warning">Por pagar</span>
                                        </td>
                                        <td class="text-right text-nowrap">
                                            <el-button
                                                v-if="row.state === 'active' && !row.settled"
                                                size="mini"
                                                type="danger"
                                                plain
                                                @click="openWithholdingVoid(row)"
                                            >
                                                Anular
                                            </el-button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <div v-if="wh.meta.last_page > 1" class="d-flex justify-content-end mt-2">
                                <el-pagination
                                    layout="prev, pager, next"
                                    :current-page="wh.meta.current_page"
                                    :page-size="wh.meta.per_page"
                                    :total="wh.meta.total"
                                    @current-change="loadWithholdings"
                                />
                            </div>
                        </div>
                    </template>
                </el-tab-pane>
            </el-tabs>

            <div v-if="tables.release_info && tables.release_info.length" class="border rounded p-3 mt-3 bg-light">
                <div class="font-weight-bold small mb-1">Liberación de fondos de la cuenta de detracciones (informativo)</div>
                <ul class="small text-muted pl-3 mb-0">
                    <li v-for="(line, i) in tables.release_info" :key="i">{{ line }}</li>
                </ul>
            </div>
        </div>

        <!-- Deposit dialog -->
        <el-dialog
            :title="depositTarget && depositTarget.side === 'receivable' ? 'Marcar detracción como recibida' : 'Registrar depósito de detracción'"
            :visible.sync="showDeposit"
            width="520px"
            append-to-body
        >
            <form v-if="depositTarget" @submit.prevent="submitDeposit">
                <p class="small text-muted mb-3">
                    {{ depositTarget.number_full }} · Detracción {{ money(depositTarget.amount_pen) }}
                    <span v-if="depositTarget.side === 'receivable'">
                        — se registrará un ingreso en su cuenta de detracciones del Banco de la Nación.
                    </span>
                    <span v-else>
                        — se registrará una salida de la cuenta propia desde la que depositó.
                    </span>
                </p>
                <div v-if="depositGeneralError" class="alert alert-danger py-2 px-3 small" role="alert">{{ depositGeneralError }}</div>

                <div class="form-group" :class="{ 'has-danger': depositErrors.date }">
                    <label for="dt-dep-date" class="control-label">Fecha del depósito <span class="text-danger">*</span></label>
                    <el-date-picker
                        id="dt-dep-date"
                        v-model="depositForm.date"
                        type="date"
                        value-format="yyyy-MM-dd"
                        placeholder="AAAA-MM-DD"
                        class="w-100"
                    />
                    <small v-if="depositErrors.date" class="form-control-feedback text-danger">{{ depositErrors.date[0] }}</small>
                </div>

                <div class="form-group" :class="{ 'has-danger': depositErrors.bank_account_id }">
                    <label for="dt-dep-account" class="control-label">
                        {{ depositTarget.side === 'receivable' ? 'Cuenta de detracciones (BN)' : 'Cuenta desde la que depositó' }}
                        <span v-if="depositTarget.side === 'payable'" class="text-danger">*</span>
                    </label>
                    <el-select
                        id="dt-dep-account"
                        v-model="depositForm.bank_account_id"
                        :clearable="depositTarget.side === 'receivable'"
                        :placeholder="depositTarget.side === 'receivable' ? 'Automática según el número de cuenta' : 'Seleccione una cuenta'"
                        class="w-100"
                    >
                        <el-option
                            v-for="acc in depositAccounts"
                            :key="acc.id"
                            :label="acc.description"
                            :value="acc.id"
                        />
                    </el-select>
                    <small v-if="depositErrors.bank_account_id" class="form-control-feedback text-danger">{{ depositErrors.bank_account_id[0] }}</small>
                </div>

                <div class="row">
                    <div class="col-6">
                        <div class="form-group" :class="{ 'has-danger': depositErrors.constancia_number }">
                            <label for="dt-dep-constancia" class="control-label">
                                N° de constancia
                                <span v-if="depositTarget.side === 'payable'" class="text-danger">*</span>
                            </label>
                            <el-input id="dt-dep-constancia" v-model="depositForm.constancia_number" maxlength="30" />
                            <small v-if="depositErrors.constancia_number" class="form-control-feedback text-danger">{{ depositErrors.constancia_number[0] }}</small>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-group" :class="{ 'has-danger': depositErrors.operation_number }">
                            <label for="dt-dep-operation" class="control-label">N° de operación</label>
                            <el-input id="dt-dep-operation" v-model="depositForm.operation_number" maxlength="60" />
                            <small v-if="depositErrors.operation_number" class="form-control-feedback text-danger">{{ depositErrors.operation_number[0] }}</small>
                        </div>
                    </div>
                </div>
            </form>
            <span slot="footer" class="dialog-footer">
                <el-button :disabled="depositing" @click="showDeposit = false">Cancelar</el-button>
                <el-button type="primary" :loading="depositing" @click="submitDeposit">Guardar</el-button>
            </span>
        </el-dialog>

        <!-- Revert / void confirmation (destructive) -->
        <el-dialog :title="reasonDialog.title" :visible.sync="reasonDialog.visible" width="480px" append-to-body>
            <p class="mb-2">{{ reasonDialog.message }}</p>
            <div v-if="reasonDialog.generalError" class="alert alert-danger py-2 px-3 small" role="alert">{{ reasonDialog.generalError }}</div>
            <div class="form-group mb-0" :class="{ 'has-danger': reasonDialog.errors.void_reason }">
                <label for="dt-reason" class="control-label">Motivo <span class="text-danger">*</span></label>
                <el-input id="dt-reason" v-model="reasonDialog.reason" type="textarea" :rows="3" maxlength="500" placeholder="Indique el motivo" />
                <small v-if="reasonDialog.errors.void_reason" class="form-control-feedback text-danger">{{ reasonDialog.errors.void_reason[0] }}</small>
            </div>
            <span slot="footer" class="dialog-footer">
                <el-button :disabled="reasonDialog.busy" @click="reasonDialog.visible = false">Cancelar</el-button>
                <el-button type="danger" :loading="reasonDialog.busy" @click="confirmReason">Confirmar</el-button>
            </span>
        </el-dialog>

        <!-- Release of funds (Formulario 1697) -->
        <el-dialog title="Liberación de fondos de detracciones" :visible.sync="showRelease" width="520px" append-to-body>
            <form @submit.prevent="submitRelease">
                <p class="small text-muted mb-3">
                    Registre aquí la liberación aprobada por SUNAT (Formulario 1697). Los fondos pasan de la cuenta de
                    detracciones a una de sus cuentas.
                </p>
                <div v-if="releaseGeneralError" class="alert alert-danger py-2 px-3 small" role="alert">{{ releaseGeneralError }}</div>

                <div class="form-group" :class="{ 'has-danger': releaseErrors.origin_id }">
                    <label for="dt-rel-origin" class="control-label">Cuenta de detracciones <span class="text-danger">*</span></label>
                    <el-select id="dt-rel-origin" v-model="releaseForm.origin_id" placeholder="Seleccione" class="w-100">
                        <el-option v-for="acc in tables.bn_accounts" :key="acc.id" :label="acc.description" :value="acc.id" />
                    </el-select>
                    <small v-if="releaseErrors.origin_id" class="form-control-feedback text-danger">{{ releaseErrors.origin_id[0] }}</small>
                </div>
                <div class="form-group" :class="{ 'has-danger': releaseErrors.target_id }">
                    <label for="dt-rel-target" class="control-label">Cuenta de destino <span class="text-danger">*</span></label>
                    <el-select id="dt-rel-target" v-model="releaseForm.target_id" placeholder="Seleccione" class="w-100">
                        <el-option v-for="acc in tables.own_accounts" :key="acc.id" :label="acc.description" :value="acc.id" />
                    </el-select>
                    <small v-if="releaseErrors.target_id" class="form-control-feedback text-danger">{{ releaseErrors.target_id[0] }}</small>
                </div>
                <div class="row">
                    <div class="col-6">
                        <div class="form-group" :class="{ 'has-danger': releaseErrors.date }">
                            <label for="dt-rel-date" class="control-label">Fecha <span class="text-danger">*</span></label>
                            <el-date-picker id="dt-rel-date" v-model="releaseForm.date" type="date" value-format="yyyy-MM-dd" class="w-100" placeholder="AAAA-MM-DD" />
                            <small v-if="releaseErrors.date" class="form-control-feedback text-danger">{{ releaseErrors.date[0] }}</small>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-group" :class="{ 'has-danger': releaseErrors.amount }">
                            <label for="dt-rel-amount" class="control-label">Monto (S/) <span class="text-danger">*</span></label>
                            <el-input id="dt-rel-amount" v-model="releaseForm.amount" placeholder="0.00" />
                            <small v-if="releaseErrors.amount" class="form-control-feedback text-danger">{{ releaseErrors.amount[0] }}</small>
                        </div>
                    </div>
                </div>
                <div class="form-group mb-0" :class="{ 'has-danger': releaseErrors.operation_number }">
                    <label for="dt-rel-op" class="control-label">N° de operación</label>
                    <el-input id="dt-rel-op" v-model="releaseForm.operation_number" maxlength="60" />
                    <small v-if="releaseErrors.operation_number" class="form-control-feedback text-danger">{{ releaseErrors.operation_number[0] }}</small>
                </div>
            </form>
            <span slot="footer" class="dialog-footer">
                <el-button :disabled="releasing" @click="showRelease = false">Cancelar</el-button>
                <el-button type="primary" :loading="releasing" @click="submitRelease">Registrar liberación</el-button>
            </span>
        </el-dialog>
    </div>
</template>

<script>
export default {
    name: 'TreasuryDetractionsIndex',
    data() {
        return {
            activeTab: 'receivable',
            loading: false,
            syncing: false,
            errorMessage: null,
            requestSeq: 0,
            tables: {
                establishments: [],
                bn_accounts: [],
                own_accounts: [],
                is_admin: false,
                release_info: [],
            },
            filters: {
                establishment_id: null,
                status: 'all',
                date_start: null,
                date_end: null,
                search: '',
            },
            records: [],
            totals: { by_status: {} },
            meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
            sideTabs: [
                {
                    side: 'receivable',
                    label: 'Por cobrar',
                    help: 'Ventas con detracción: su cliente debe depositar la detracción en su cuenta del Banco de la Nación. Usted cobra el total menos la detracción en sus cuentas normales. La fecha es una estimación (5.º día hábil del mes siguiente a la emisión).',
                },
                {
                    side: 'payable',
                    label: 'Por pagar',
                    help: 'Compras con detracción: usted debe depositar la detracción en la cuenta del proveedor, a más tardar en la fecha de pago o el 5.º día hábil del mes siguiente a la anotación en el Registro de Compras (lo que ocurra primero). Al proveedor se le paga el total menos la detracción.',
                },
            ],
            showDeposit: false,
            depositing: false,
            depositTarget: null,
            depositForm: { date: null, bank_account_id: null, constancia_number: '', operation_number: '' },
            depositErrors: {},
            depositGeneralError: null,
            reasonDialog: {
                visible: false,
                busy: false,
                kind: null,
                target: null,
                title: '',
                message: '',
                reason: '',
                errors: {},
                generalError: null,
            },
            showRelease: false,
            releasing: false,
            releaseForm: { origin_id: null, target_id: null, date: null, amount: '', operation_number: '' },
            releaseErrors: {},
            releaseGeneralError: null,
            wh: {
                loading: false,
                error: null,
                records: [],
                kinds: [],
                flags: { is_retention_agent: false, is_perception_agent: false },
                sunat: { total_pen: 0, by_kind: {}, by_period: {} },
                filters: { kind: null, state: 'active' },
                meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 },
            },
        }
    },
    computed: {
        summaryCards() {
            const by = (this.totals && this.totals.by_status) || {}
            const side = this.activeTab
            const done = side === 'receivable' ? 'received' : 'deposited'
            const cards = [
                { key: 'pending', label: 'Pendientes' },
                { key: 'overdue', label: 'Vencidas' },
                { key: done, label: side === 'receivable' ? 'Recibidas' : 'Depositadas' },
            ]
            return cards.map(c => ({
                key: c.key,
                label: c.label,
                count: (by[c.key] && by[c.key].count) || 0,
                amount: (by[c.key] && by[c.key].amount_pen) || 0,
            })).concat([{ key: 'all', label: 'Total', count: this.totals.count || 0, amount: this.totals.amount_pen || 0 }])
        },
        depositAccounts() {
            if (!this.depositTarget) {
                return []
            }
            return this.depositTarget.side === 'receivable' ? this.tables.bn_accounts : this.tables.own_accounts
        },
    },
    created() {
        this.loadTables()
        this.loadRecords(1)
    },
    methods: {
        async loadTables() {
            try {
                const res = await this.$http.get('/treasury/detractions/tables')
                this.tables = Object.assign({}, this.tables, res.data || {})
            } catch (e) {
                // The records request reports connectivity problems.
            }
        },
        onTabClick() {
            if (this.activeTab === 'withholdings') {
                this.loadWithholdings(1)
                return
            }
            this.filters.status = 'all'
            this.loadRecords(1)
        },
        // `silent === true` on purpose: a click handler would pass a MouseEvent as the first argument.
        async loadRecords(page = 1, silent = false) {
            if (this.activeTab === 'withholdings') {
                return
            }
            const seq = ++this.requestSeq
            if (silent !== true) {
                this.loading = true
            }
            this.errorMessage = null

            try {
                const params = Object.assign({}, this.filters, {
                    side: this.activeTab,
                    page: typeof page === 'number' ? page : 1,
                })
                Object.keys(params).forEach(key => {
                    if (params[key] === null || params[key] === '') {
                        delete params[key]
                    }
                })
                const res = await this.$http.get('/treasury/detractions/records', { params })
                if (seq !== this.requestSeq) {
                    return
                }
                const data = (res.data && res.data.data) || {}
                this.records = data.rows || []
                this.totals = data.totals || { by_status: {} }
                this.meta = data.meta || this.meta
            } catch (e) {
                if (seq === this.requestSeq) {
                    this.errorMessage = 'No se pudieron cargar las detracciones. Intente nuevamente.'
                }
            } finally {
                if (seq === this.requestSeq) {
                    this.loading = false
                }
            }
        },
        async syncNow() {
            if (this.syncing) {
                return
            }
            this.syncing = true
            try {
                const res = await this.$http.post('/treasury/detractions/sync')
                this.$message.success((res.data && res.data.message) || 'Sincronización completada.')
                this.loadRecords(1)
            } catch (e) {
                this.$message.error('No se pudo sincronizar. Intente nuevamente.')
            } finally {
                this.syncing = false
            }
        },
        openDeposit(row) {
            this.depositTarget = row
            this.depositErrors = {}
            this.depositGeneralError = null
            const today = new Date()
            const pad = n => (n < 10 ? '0' + n : '' + n)
            this.depositForm = {
                date: `${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`,
                bank_account_id: null,
                constancia_number: '',
                operation_number: '',
            }
            this.showDeposit = true
        },
        async submitDeposit() {
            if (!this.depositTarget || this.depositing) {
                return
            }
            this.depositing = true
            this.depositErrors = {}
            this.depositGeneralError = null
            try {
                const payload = Object.assign({}, this.depositForm)
                if (!payload.bank_account_id) {
                    delete payload.bank_account_id
                }
                const res = await this.$http.post(`/treasury/detractions/${this.depositTarget.id}/deposit`, payload)
                this.$message.success((res.data && res.data.message) || 'Depósito registrado.')
                this.showDeposit = false
                this.loadRecords(this.meta.current_page)
            } catch (error) {
                this.applyError(error, errs => { this.depositErrors = errs }, msg => { this.depositGeneralError = msg })
            } finally {
                this.depositing = false
            }
        },
        openRevert(row) {
            this.openReason('revert', row, 'Revertir depósito',
                `Se anulará el movimiento del depósito de ${row.number_full} y la detracción volverá a estar pendiente.`)
        },
        openWithholdingVoid(row) {
            this.openReason('withholding', row, 'Anular comprobante',
                `Se anulará el enlace del comprobante ${row.certificate}. Esta acción no emite ni anula el comprobante en SUNAT.`)
        },
        openReason(kind, target, title, message) {
            this.reasonDialog = { visible: true, busy: false, kind, target, title, message, reason: '', errors: {}, generalError: null }
        },
        async confirmReason() {
            const dialog = this.reasonDialog
            if (!dialog.target || dialog.busy) {
                return
            }
            dialog.busy = true
            dialog.errors = {}
            dialog.generalError = null
            try {
                const url = dialog.kind === 'revert'
                    ? `/treasury/detractions/${dialog.target.id}/revert`
                    : `/treasury/withholdings/${dialog.target.id}/void`
                const res = await this.$http.post(url, { void_reason: dialog.reason })
                this.$message.success((res.data && res.data.message) || 'Operación realizada.')
                dialog.visible = false
                if (dialog.kind === 'revert') {
                    this.loadRecords(this.meta.current_page)
                } else {
                    this.loadWithholdings(this.wh.meta.current_page)
                }
            } catch (error) {
                this.applyError(error, errs => {
                    dialog.errors = errs
                    const other = Object.keys(errs).filter(k => k !== 'void_reason')[0]
                    if (other) {
                        dialog.generalError = errs[other][0]
                    }
                }, msg => { dialog.generalError = msg })
            } finally {
                dialog.busy = false
            }
        },
        openRelease() {
            this.releaseErrors = {}
            this.releaseGeneralError = null
            this.releaseForm = {
                origin_id: this.tables.bn_accounts.length === 1 ? this.tables.bn_accounts[0].id : null,
                target_id: null,
                date: null,
                amount: '',
                operation_number: '',
            }
            this.showRelease = true
        },
        async submitRelease() {
            if (this.releasing) {
                return
            }
            this.releasing = true
            this.releaseErrors = {}
            this.releaseGeneralError = null
            try {
                const res = await this.$http.post('/treasury/detractions/release', this.releaseForm)
                this.$message.success((res.data && res.data.message) || 'Liberación registrada.')
                this.showRelease = false
            } catch (error) {
                this.applyError(error, errs => { this.releaseErrors = errs }, msg => { this.releaseGeneralError = msg })
            } finally {
                this.releasing = false
            }
        },
        async loadWithholdings(page = 1) {
            this.wh.loading = true
            this.wh.error = null
            try {
                const [tablesRes, recordsRes] = await Promise.all([
                    this.$http.get('/treasury/withholdings/tables'),
                    this.$http.get('/treasury/withholdings/records', {
                        params: Object.assign({}, this.cleanFilters(this.wh.filters), { page: typeof page === 'number' ? page : 1 }),
                    }),
                ])
                this.wh.kinds = (tablesRes.data && tablesRes.data.kinds) || []
                this.wh.flags = (tablesRes.data && tablesRes.data.flags) || this.wh.flags
                const data = (recordsRes.data && recordsRes.data.data) || {}
                this.wh.records = data.rows || []
                this.wh.sunat = data.sunat_payable || this.wh.sunat
                this.wh.meta = data.meta || this.wh.meta
            } catch (e) {
                this.wh.error = 'No se pudieron cargar los comprobantes. Intente nuevamente.'
            } finally {
                this.wh.loading = false
            }
        },
        cleanFilters(filters) {
            const out = {}
            Object.keys(filters).forEach(key => {
                if (filters[key] !== null && filters[key] !== '') {
                    out[key] = filters[key]
                }
            })
            return out
        },
        applyError(error, onFieldErrors, onGeneral) {
            if (error.response && error.response.status === 422) {
                const errs = error.response.data.errors || {}
                onFieldErrors(errs)
                const first = Object.keys(errs).map(k => errs[k][0])[0]
                if (first && !Object.keys(errs).some(k => ['date', 'bank_account_id', 'constancia_number', 'operation_number', 'void_reason', 'origin_id', 'target_id', 'amount'].indexOf(k) !== -1)) {
                    onGeneral(first)
                }
            } else if (error.response && error.response.data && error.response.data.message) {
                onGeneral(error.response.data.message)
            } else {
                onGeneral('No se pudo completar la operación. Intente nuevamente.')
            }
        },
        statusClass(status) {
            switch (status) {
                case 'overdue':
                    return 'badge-danger'
                case 'received':
                case 'deposited':
                    return 'badge-success'
                default:
                    return 'badge-warning'
            }
        },
        statusLabel(status, side) {
            switch (status) {
                case 'overdue':
                    return 'Vencida'
                case 'received':
                    return 'Recibida'
                case 'deposited':
                    return 'Depositada'
                default:
                    return side === 'receivable' ? 'Por recibir' : 'Por depositar'
            }
        },
        kindLabel(kind) {
            const found = this.wh.kinds.filter(k => k.value === kind)[0]
            return found ? found.label : kind
        },
        money(raw, currencyTypeId) {
            const value = parseFloat(raw || 0)
            const symbol = currencyTypeId === 'USD' ? 'US$' : 'S/'
            return `${symbol} ${value.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
        },
    },
}
</script>
