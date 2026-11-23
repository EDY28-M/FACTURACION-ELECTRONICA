<template>
    <div>
        <div v-if="loadError" class="alert alert-danger d-flex justify-content-between align-items-center" role="alert">
            <span>{{ loadError }}</span>
            <el-button size="mini" type="danger" plain @click="reloadAll">Reintentar</el-button>
        </div>

        <div v-if="loadingStatement && !statement" class="text-center py-5 text-muted" role="status" aria-live="polite">
            <i class="el-icon-loading mr-2"></i> Cargando extracto...
        </div>

        <template v-if="statement">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-start flex-wrap mb-3">
                <div>
                    <el-button size="small" icon="el-icon-back" @click="$emit('back')">Volver al listado</el-button>
                    <h4 class="d-inline-block ml-3 mb-0 align-middle">{{ statement.account_description }}</h4>
                    <span class="badge ml-2 align-middle" :class="isClosed ? 'badge-success' : 'badge-warning'">{{ isClosed ? 'Cerrada' : 'En proceso' }}</span>
                    <span v-if="statement.imported_unbalanced" class="badge badge-danger ml-2 align-middle" title="El saldo inicial más los movimientos no coincide con el saldo final del archivo">
                        Importado con diferencia ({{ signedMoney(statement.unbalanced_gap, currency) }})
                    </span>
                    <span v-if="summary && summary.stale" class="badge badge-danger ml-2 align-middle">Desactualizada</span>
                    <div class="small text-muted mt-1">
                        Período {{ statement.period_from }} al {{ statement.period_to }} · Saldo inicial {{ money(statement.opening_balance, currency) }}
                        · Saldo final {{ money(statement.closing_balance, currency) }}
                        <span v-if="statement.file_name"> · Archivo: {{ statement.file_name }}</span>
                    </div>
                </div>
                <div class="mt-2 mt-md-0">
                    <template v-if="!isClosed">
                        <el-button size="small" type="primary" plain :loading="autoMatching" @click="autoMatch">Emparejar automáticamente</el-button>
                        <el-button size="small" type="success" @click="openClose">Cerrar conciliación</el-button>
                        <el-button size="small" type="danger" plain @click="discard">Descartar extracto</el-button>
                    </template>
                    <el-button v-else-if="tables.is_admin" size="small" type="warning" plain :loading="reopening" @click="reopen">Reabrir conciliación</el-button>
                </div>
            </div>

            <!-- Summary bar -->
            <div class="row mb-3" aria-live="polite">
                <div class="col-6 col-md-2 mb-2">
                    <div class="border rounded p-2 h-100">
                        <div class="small text-muted">Saldo según banco</div>
                        <div class="h6 mb-0">{{ money(statement.closing_balance, currency) }}</div>
                    </div>
                </div>
                <div class="col-6 col-md-2 mb-2">
                    <div class="border rounded p-2 h-100">
                        <div class="small text-muted">Saldo según sistema</div>
                        <div class="h6 mb-0">{{ summary ? money(summary.system_balance, currency) : '...' }}</div>
                        <div v-if="summary" class="small text-muted">al {{ summary.system_balance_as_of }}</div>
                    </div>
                </div>
                <div class="col-6 col-md-2 mb-2">
                    <div class="border rounded p-2 h-100">
                        <div class="small text-muted">Partidas en tránsito</div>
                        <div class="h6 mb-0">{{ summary ? signedMoney(summary.in_transit.net, currency) : '...' }}</div>
                        <div v-if="summary" class="small text-muted">{{ summary.in_transit.count }} movimiento(s) del sistema sin línea</div>
                    </div>
                </div>
                <div class="col-6 col-md-2 mb-2">
                    <div class="border rounded p-2 h-100">
                        <div class="small text-muted">Líneas ignoradas</div>
                        <div class="h6 mb-0">{{ summary ? signedMoney(summary.ignored.net_cumulative, currency) : '...' }}</div>
                        <div v-if="summary" class="small text-muted">{{ summary.ignored.count }} en este extracto</div>
                    </div>
                </div>
                <div class="col-6 col-md-2 mb-2">
                    <div class="border rounded p-2 h-100">
                        <div class="small text-muted">Saldo banco ajustado</div>
                        <div class="h6 mb-0">{{ summary ? money(summary.adjusted_bank_balance, currency) : '...' }}</div>
                    </div>
                </div>
                <div class="col-6 col-md-2 mb-2">
                    <div class="border rounded p-2 h-100" :class="differenceBoxClass">
                        <div class="small">Diferencia</div>
                        <div class="h6 mb-0">{{ summary ? signedMoney(summary.difference, currency) : '...' }}</div>
                        <div v-if="summary" class="small">{{ summary.balanced ? 'Conciliado: diferencia cero' : 'No conciliado' }}</div>
                    </div>
                </div>
            </div>
            <div v-if="summary && summary.stale" class="alert alert-danger py-2 px-3 small" role="alert">
                <strong>La conciliación está desactualizada:</strong> {{ summary.stale_count }} movimiento(s) emparejado(s) fueron eliminados o modificados
                (cobros/pagos editados o borrados) después de emparejarlos.
                <ul class="mb-0 pl-3">
                    <li v-for="m in summary.stale_matches" :key="m.type + m.id">
                        Línea {{ m.line_no }}: {{ staleReasonLabel(m.reason) }} (importe emparejado {{ signedMoney(m.expected_amount, currency) }}<span v-if="m.current_amount !== null">, actual {{ signedMoney(m.current_amount, currency) }}</span>).
                    </li>
                </ul>
                <span v-if="isClosed">Reabra la conciliación (administrador) para corregirla.</span>
            </div>
            <div v-if="statement.imported_unbalanced" class="alert alert-warning py-2 px-3 small" role="alert">
                Este extracto se importó aceptando una diferencia de {{ signedMoney(statement.unbalanced_gap, currency) }} entre el saldo final del archivo y saldo inicial + movimientos.
            </div>
            <div v-if="summary && summary.book_adjustments && summary.book_adjustments.count > 0" class="alert alert-secondary py-2 px-3 small">
                <strong>Ajustes contables (notas de crédito):</strong> {{ signedMoney(summary.book_adjustments.net, currency) }} en {{ summary.book_adjustments.count }} nota(s).
                Reducen el saldo del sistema pero no aparecen en el extracto del banco, por eso no se emparejan.
                <ul class="mb-0 pl-3">
                    <li v-for="a in summary.book_adjustments.items" :key="a.id + '-' + a.document_id">
                        {{ a.date }} · {{ a.description }}: {{ signedMoney(a.amount, currency) }}
                    </li>
                </ul>
                <span v-if="summary.book_adjustments.truncated">(Se muestran solo los primeros ajustes.)</span>
            </div>
            <div v-if="summary && summary.ahead_net != 0" class="alert alert-info py-2 px-3 small">
                Hay movimientos del sistema fechados después del período que el banco ya registró ({{ signedMoney(summary.ahead_net, currency) }}); se tienen en cuenta en el saldo ajustado.
            </div>
            <div v-if="statement.lines.total" class="small text-muted mb-3">
                Líneas: {{ statement.lines.matched }} emparejada(s), {{ statement.lines.ignored }} ignorada(s), {{ statement.lines.unmatched }} pendiente(s) de {{ statement.lines.total }}.
            </div>

            <div class="row">
                <!-- Statement lines pane -->
                <div class="col-lg-6 mb-3">
                    <div class="border rounded h-100">
                        <div class="p-2 border-bottom bg-light d-flex justify-content-between align-items-center flex-wrap">
                            <strong>Líneas del extracto</strong>
                            <el-radio-group v-model="lineFilters.status" size="mini" @change="loadLines(1)">
                                <el-radio-button label="all">Todas</el-radio-button>
                                <el-radio-button label="unmatched">Pendientes</el-radio-button>
                                <el-radio-button label="matched">Emparejadas</el-radio-button>
                                <el-radio-button label="ignored">Ignoradas</el-radio-button>
                            </el-radio-group>
                        </div>
                        <div class="p-2">
                            <el-input v-model="lineFilters.search" size="small" clearable placeholder="Buscar por descripción o referencia" class="mb-2" @keyup.enter.native="loadLines(1)" @clear="loadLines(1)" />

                            <div v-if="linesError" class="alert alert-danger py-2 px-3 small d-flex justify-content-between align-items-center" role="alert">
                                <span>{{ linesError }}</span>
                                <el-button size="mini" type="danger" plain @click="loadLines(linesMeta.current_page)">Reintentar</el-button>
                            </div>
                            <div v-if="loadingLines" class="text-center py-4 text-muted" role="status" aria-live="polite">
                                <i class="el-icon-loading mr-2"></i> Cargando líneas...
                            </div>
                            <div v-else-if="!linesError && lines.length === 0" class="alert alert-light border text-muted mb-0">
                                No hay líneas para los filtros seleccionados.
                            </div>
                            <div v-else class="table-responsive" style="max-height: 520px; overflow: auto">
                                <table class="table table-sm table-hover mb-2">
                                    <thead>
                                        <tr><th>Fecha</th><th>Descripción</th><th class="text-right">Importe</th><th>Estado</th><th class="text-right">Acciones</th></tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="line in lines"
                                            :key="line.id"
                                            :class="{ 'table-active': selectedLine && selectedLine.id === line.id, 'text-muted': line.status === 'ignored' }"
                                            :style="line.status === 'unmatched' && !isClosed ? 'cursor: pointer' : ''"
                                            :tabindex="line.status === 'unmatched' && !isClosed ? 0 : -1"
                                            @click="selectLine(line)"
                                            @keyup.enter="selectLine(line)"
                                        >
                                            <td class="text-nowrap">{{ line.date }}</td>
                                            <td>
                                                <div>{{ line.description }}</div>
                                                <div v-if="line.reference" class="small text-muted">Ref: {{ line.reference }}</div>
                                                <div v-if="line.status === 'ignored'" class="small">Motivo: {{ line.ignore_reason }}</div>
                                                <div v-for="item in line.items" :key="item.type + item.id" class="small text-muted">
                                                    ↳ {{ item.label || 'Movimiento' }}: {{ item.description }} ({{ signedMoney(item.amount, currency) }})
                                                </div>
                                            </td>
                                            <td class="text-right text-nowrap font-weight-bold" :class="amountClass(line.amount)">{{ signedMoney(line.amount, currency) }}</td>
                                            <td><span class="badge" :class="lineBadge(line.status)">{{ lineStatusLabel(line.status) }}</span></td>
                                            <td class="text-right text-nowrap" @click.stop>
                                                <template v-if="!isClosed">
                                                    <el-button v-if="line.status === 'unmatched'" size="mini" @click="openMove(line)">Crear movimiento</el-button>
                                                    <el-button v-if="line.status === 'unmatched'" size="mini" @click="openIgnore(line)">Ignorar</el-button>
                                                    <el-button v-if="line.status === 'matched'" size="mini" type="warning" plain @click="unmatch(line)">Desvincular</el-button>
                                                    <el-button v-if="line.status === 'ignored'" size="mini" @click="unignore(line)">Reactivar</el-button>
                                                </template>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                                <div v-if="linesMeta.last_page > 1" class="d-flex justify-content-end">
                                    <el-pagination layout="prev, pager, next" :current-page="linesMeta.current_page" :page-size="linesMeta.per_page" :total="linesMeta.total" @current-change="loadLines" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- System candidates pane -->
                <div class="col-lg-6 mb-3">
                    <div class="border rounded h-100">
                        <div class="p-2 border-bottom bg-light d-flex justify-content-between align-items-center flex-wrap">
                            <strong>Movimientos del sistema sin emparejar</strong>
                            <span class="small text-muted">Ventana de ±{{ candidatesInfo.window_days }} día(s) alrededor del período</span>
                        </div>
                        <div class="p-2">
                            <div v-if="isClosed" class="alert alert-light border text-muted mb-0">La conciliación está cerrada. Reábrala para modificar los emparejamientos.</div>
                            <template v-else>
                                <div v-if="selectedLine" class="alert alert-info py-2 px-3 small d-flex justify-content-between align-items-center">
                                    <span>Línea seleccionada: {{ selectedLine.date }} · {{ selectedLine.description }} · <strong>{{ signedMoney(selectedLine.amount, currency) }}</strong></span>
                                    <el-button size="mini" type="text" @click="clearSelectedLine">Quitar selección</el-button>
                                </div>
                                <div v-else class="alert alert-light border small text-muted">Seleccione una línea pendiente del extracto para ver sugerencias y emparejar.</div>

                                <el-input v-model="candidateSearch" size="small" clearable placeholder="Buscar movimiento" class="mb-2" @keyup.enter.native="loadCandidates" @clear="loadCandidates" />

                                <div v-if="candidatesError" class="alert alert-danger py-2 px-3 small d-flex justify-content-between align-items-center" role="alert">
                                    <span>{{ candidatesError }}</span>
                                    <el-button size="mini" type="danger" plain @click="loadCandidates">Reintentar</el-button>
                                </div>
                                <div v-if="loadingCandidates" class="text-center py-4 text-muted" role="status" aria-live="polite">
                                    <i class="el-icon-loading mr-2"></i> Cargando movimientos...
                                </div>
                                <div v-else-if="!candidatesError && candidates.length === 0" class="alert alert-light border text-muted mb-0">
                                    No hay movimientos del sistema pendientes de emparejar en este período.
                                </div>
                                <template v-else-if="!candidatesError">
                                    <el-table
                                        ref="candidatesTable"
                                        :data="candidates"
                                        size="mini"
                                        max-height="420"
                                        :row-key="candidateKey"
                                        :row-class-name="candidateRowClass"
                                        @selection-change="onSelectionChange"
                                    >
                                        <el-table-column type="selection" width="40" :selectable="() => !!selectedLine" />
                                        <el-table-column label="Fecha" width="95" prop="date" />
                                        <el-table-column label="Detalle" min-width="160">
                                            <template slot-scope="scope">
                                                <div>
                                                    {{ scope.row.description || scope.row.label }}
                                                    <span v-if="scope.row.suggested" class="badge badge-info ml-1">Sugerido</span>
                                                    <span v-if="scope.row.converted" class="badge badge-warning ml-1" title="Moneda distinta a la cuenta: importe convertido">Convertido</span>
                                                </div>
                                                <div class="small text-muted">{{ scope.row.label }}<span v-if="scope.row.reference"> · Ref: {{ scope.row.reference }}</span></div>
                                            </template>
                                        </el-table-column>
                                        <el-table-column label="Importe" width="120" align="right">
                                            <template slot-scope="scope">
                                                <span class="font-weight-bold" :class="amountClass(scope.row.amount)">{{ signedMoney(scope.row.amount, currency) }}</span>
                                            </template>
                                        </el-table-column>
                                    </el-table>
                                    <div v-if="candidatesTruncated" class="small text-muted mt-1">Se muestran los primeros resultados; use la búsqueda para acotar.</div>
                                </template>

                                <div class="mt-3 d-flex justify-content-between align-items-center flex-wrap">
                                    <div class="small" aria-live="polite">
                                        <template v-if="selectedLine">
                                            Seleccionado: <strong>{{ signedMoney(selectedSum, currency) }}</strong> de {{ signedMoney(selectedLine.amount, currency) }}
                                            <span v-if="selectedItems.length && !sumMatches" class="text-danger">· Faltan {{ signedMoney(selectedLine.amount - selectedSum, currency) }}</span>
                                            <span v-else-if="selectedItems.length && sumMatches" class="text-success">· Importes iguales</span>
                                        </template>
                                    </div>
                                    <el-button type="primary" size="small" :disabled="!canMatch" :loading="matching" @click="matchSelected">Emparejar seleccionados</el-button>
                                </div>
                                <div v-if="matchError" class="alert alert-danger py-2 px-3 small mt-2" role="alert">{{ matchError }}</div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- Ignore dialog -->
        <el-dialog title="Ignorar línea del extracto" :visible.sync="ignoreDialog.visible" width="480px" append-to-body>
            <p v-if="ignoreDialog.line" class="small text-muted mb-2">
                {{ ignoreDialog.line.date }} · {{ ignoreDialog.line.description }} · {{ signedMoney(ignoreDialog.line.amount, currency) }}.
                Una línea ignorada queda explicada con su motivo y se resta en la conciliación.
            </p>
            <div v-if="ignoreDialog.general" class="alert alert-danger py-2 px-3 small" role="alert">{{ ignoreDialog.general }}</div>
            <div class="form-group mb-0" :class="{ 'has-danger': ignoreDialog.errors.reason }">
                <label for="rc-ign-reason" class="control-label">Motivo <span class="text-danger">*</span></label>
                <el-input id="rc-ign-reason" v-model="ignoreDialog.reason" type="textarea" :rows="3" maxlength="255" placeholder="Indique por qué se ignora esta línea" />
                <small v-if="ignoreDialog.errors.reason" class="form-control-feedback text-danger">{{ ignoreDialog.errors.reason[0] }}</small>
            </div>
            <span slot="footer" class="dialog-footer">
                <el-button :disabled="ignoreDialog.busy" @click="ignoreDialog.visible = false">Cancelar</el-button>
                <el-button type="primary" :loading="ignoreDialog.busy" @click="submitIgnore">Ignorar línea</el-button>
            </span>
        </el-dialog>

        <!-- Create movement dialog -->
        <el-dialog title="Crear movimiento desde la línea" :visible.sync="moveDialog.visible" width="520px" append-to-body>
            <template v-if="moveDialog.line">
                <p class="small text-muted mb-3">
                    {{ moveDialog.line.date }} · {{ moveDialog.line.description }} · <strong>{{ signedMoney(moveDialog.line.amount, currency) }}</strong>.
                    Se registra en la cuenta del extracto y se empareja automáticamente.
                </p>
                <div v-if="moveDialog.general" class="alert alert-danger py-2 px-3 small" role="alert">{{ moveDialog.general }}</div>

                <div class="form-group" :class="{ 'has-danger': moveDialog.errors.kind }">
                    <label for="rc-mv-kind" class="control-label">Tipo de movimiento <span class="text-danger">*</span></label>
                    <el-select id="rc-mv-kind" v-model="moveDialog.kind" placeholder="Seleccione" class="w-100" @change="moveDialog.treasury_concept_id = null">
                        <el-option v-for="k in moveKinds" :key="k.value" :label="k.label" :value="k.value" />
                    </el-select>
                    <small v-if="moveDialog.errors.kind" class="form-control-feedback text-danger">{{ moveDialog.errors.kind[0] }}</small>
                </div>

                <div class="form-group" :class="{ 'has-danger': moveDialog.errors.treasury_concept_id }">
                    <label for="rc-mv-concept" class="control-label">
                        Concepto <span v-if="conceptRequired" class="text-danger">*</span>
                        <span v-else class="small text-muted">(opcional: se usa el predeterminado)</span>
                    </label>
                    <el-select id="rc-mv-concept" v-model="moveDialog.treasury_concept_id" :clearable="!conceptRequired" placeholder="Seleccione un concepto" class="w-100">
                        <el-option v-for="c in conceptOptions" :key="c.id" :label="c.description" :value="c.id" />
                    </el-select>
                    <small v-if="moveDialog.errors.treasury_concept_id" class="form-control-feedback text-danger">{{ moveDialog.errors.treasury_concept_id[0] }}</small>
                </div>

                <div class="form-group" :class="{ 'has-danger': moveDialog.errors.description }">
                    <label for="rc-mv-desc" class="control-label">Descripción</label>
                    <el-input id="rc-mv-desc" v-model="moveDialog.description" maxlength="500" placeholder="Por defecto, la descripción de la línea" />
                    <small v-if="moveDialog.errors.description" class="form-control-feedback text-danger">{{ moveDialog.errors.description[0] }}</small>
                </div>

                <div v-if="tables.is_admin && tables.establishments.length > 1" class="form-group mb-0" :class="{ 'has-danger': moveDialog.errors.establishment_id }">
                    <label for="rc-mv-est" class="control-label">Sede <span class="small text-muted">(solo si la cuenta no tiene sede asignada)</span></label>
                    <el-select id="rc-mv-est" v-model="moveDialog.establishment_id" clearable placeholder="Automática" class="w-100">
                        <el-option v-for="e in tables.establishments" :key="e.id" :label="e.description" :value="e.id" />
                    </el-select>
                    <small v-if="moveDialog.errors.establishment_id" class="form-control-feedback text-danger">{{ moveDialog.errors.establishment_id[0] }}</small>
                </div>
            </template>
            <span slot="footer" class="dialog-footer">
                <el-button :disabled="moveDialog.busy" @click="moveDialog.visible = false">Cancelar</el-button>
                <el-button type="primary" :loading="moveDialog.busy" :disabled="!moveDialog.kind" @click="submitMove">Crear y emparejar</el-button>
            </span>
        </el-dialog>

        <!-- Close dialog -->
        <el-dialog title="Cerrar conciliación" :visible.sync="closeDialog.visible" width="520px" append-to-body>
            <div v-if="summary">
                <p class="mb-2">Al cerrar, los movimientos emparejados quedan marcados como conciliados y no podrán anularse sin reabrir la conciliación.</p>
                <table class="table table-sm mb-2">
                    <tbody>
                        <tr><td>Saldo según banco</td><td class="text-right">{{ money(summary.bank_closing_balance, currency) }}</td></tr>
                        <tr><td>Líneas ignoradas (acumulado)</td><td class="text-right">{{ signedMoney(-summary.ignored.net_cumulative, currency) }}</td></tr>
                        <tr><td>Partidas en tránsito</td><td class="text-right">{{ signedMoney(summary.in_transit.net, currency) }}</td></tr>
                        <tr v-if="summary.book_adjustments && summary.book_adjustments.count > 0"><td>Ajustes contables (notas de crédito)</td><td class="text-right">{{ signedMoney(summary.book_adjustments.net, currency) }}</td></tr>
                        <tr><td><strong>Saldo banco ajustado</strong></td><td class="text-right"><strong>{{ money(summary.adjusted_bank_balance, currency) }}</strong></td></tr>
                        <tr><td>Saldo según sistema al {{ summary.system_balance_as_of }}</td><td class="text-right">{{ money(summary.system_balance, currency) }}</td></tr>
                        <tr><td><strong>Diferencia</strong></td><td class="text-right"><strong>{{ signedMoney(summary.difference, currency) }}</strong></td></tr>
                    </tbody>
                </table>
                <div v-if="summary.unmatched.count > 0" class="alert alert-warning py-2 px-3 small">
                    Quedan {{ summary.unmatched.count }} línea(s) sin emparejar ni ignorar.
                </div>
                <div v-else-if="!summary.balanced" class="alert alert-warning py-2 px-3 small">
                    La diferencia debe ser cero para cerrar. Revise el saldo inicial del primer extracto y los movimientos pendientes de registrar.
                </div>
            </div>
            <div v-if="closeDialog.general" class="alert alert-danger py-2 px-3 small" role="alert">{{ closeDialog.general }}</div>
            <span slot="footer" class="dialog-footer">
                <el-button :disabled="closeDialog.busy" @click="closeDialog.visible = false">Cancelar</el-button>
                <el-button type="success" :loading="closeDialog.busy" @click="submitClose">Cerrar conciliación</el-button>
            </span>
        </el-dialog>
    </div>
</template>

<script>
import { amountClass, cents, money, parseError, signedMoney } from './helpers'

const emptyPage = () => ({ current_page: 1, last_page: 1, per_page: 100, total: 0 })

export default {
    name: 'TreasuryReconciliationStatementDetail',
    props: {
        statementId: { type: Number, required: true },
        tables: { type: Object, required: true },
    },
    data() {
        return {
            statement: null,
            summary: null,
            loadError: null,
            loadingStatement: false,
            lines: [],
            linesMeta: emptyPage(),
            linesError: null,
            loadingLines: false,
            lineFilters: { status: 'all', search: '' },
            candidates: [],
            candidatesInfo: { window_days: 3 },
            candidatesTruncated: false,
            candidatesError: null,
            candidateSearch: '',
            loadingCandidates: false,
            selectedLine: null,
            selectedItems: [],
            matching: false,
            matchError: null,
            autoMatching: false,
            reopening: false,
            seq: { statement: 0, lines: 0, candidates: 0, summary: 0 },
            ignoreDialog: { visible: false, busy: false, line: null, reason: '', errors: {}, general: null },
            moveDialog: { visible: false, busy: false, line: null, kind: null, treasury_concept_id: null, description: '', establishment_id: null, errors: {}, general: null },
            closeDialog: { visible: false, busy: false, general: null },
        }
    },
    computed: {
        isClosed() {
            return !!this.statement && this.statement.status === 'closed'
        },
        currency() {
            const acc = (this.tables.accounts || []).filter(a => this.statement && a.id === this.statement.bank_account_id)[0]
            return acc ? acc.currency_type_id : 'PEN'
        },
        selectedSum() {
            return this.selectedItems.reduce((acc, item) => acc + parseFloat(item.amount || 0), 0)
        },
        sumMatches() {
            return !!this.selectedLine && cents(this.selectedSum) === cents(this.selectedLine.amount)
        },
        canMatch() {
            return !this.isClosed && !!this.selectedLine && this.selectedItems.length > 0 && this.sumMatches
        },
        differenceBoxClass() {
            if (!this.summary) {
                return ''
            }
            return this.summary.balanced ? 'border-success' : 'border-danger'
        },
        moveKinds() {
            if (!this.moveDialog.line) {
                return []
            }
            const sign = this.moveDialog.line.amount > 0 ? 1 : -1
            return (this.tables.movement_kinds || []).filter(k => k.sign === 0 || k.sign === sign)
        },
        conceptRequired() {
            return this.moveDialog.kind === 'income' || this.moveDialog.kind === 'expense'
        },
        conceptOptions() {
            const kind = this.moveDialog.kind
            const line = this.moveDialog.line
            let type = null
            if (kind === 'income' || kind === 'interest') {
                type = 'income'
            } else if (kind === 'expense' || kind === 'bank_fee' || kind === 'itf') {
                type = 'expense'
            } else if (kind === 'adjustment' && line) {
                type = line.amount > 0 ? 'income' : 'expense'
            }
            return (this.tables.concepts || []).filter(c => !type || c.type === type)
        },
    },
    watch: {
        statementId() {
            this.resetState()
            this.reloadAll()
        },
    },
    created() {
        this.reloadAll()
    },
    methods: {
        money,
        signedMoney,
        amountClass,
        staleReasonLabel(reason) {
            const labels = {
                missing: 'el cobro o pago emparejado ya no existe o fue anulado',
                amount_changed: 'el importe del movimiento emparejado cambió',
                date_changed: 'la fecha del movimiento emparejado cambió',
            }

            return labels[reason] || 'el movimiento emparejado cambió'
        },
        resetState() {
            this.statement = null
            this.summary = null
            this.lines = []
            this.candidates = []
            this.selectedLine = null
            this.selectedItems = []
            this.lineFilters = { status: 'all', search: '' }
        },
        // `silent === true` on purpose: a click handler would pass a MouseEvent as the first argument.
        async reloadAll(silent = false) {
            await this.loadStatement(silent)
            this.loadSummary()
            this.loadLines(this.linesMeta.current_page, silent)
            this.loadCandidates(silent)
        },
        async loadStatement(silent = false) {
            const seq = ++this.seq.statement
            if (silent !== true) {
                this.loadingStatement = true
            }
            this.loadError = null
            try {
                const res = await this.$http.get(`/treasury/reconciliation/statements/${this.statementId}`)
                if (seq === this.seq.statement) {
                    this.statement = res.data.data
                }
            } catch (error) {
                if (seq === this.seq.statement) {
                    this.loadError = parseError(error).general || 'No se pudo cargar el extracto.'
                }
            } finally {
                if (seq === this.seq.statement) {
                    this.loadingStatement = false
                }
            }
        },
        async loadSummary() {
            const seq = ++this.seq.summary
            try {
                const res = await this.$http.get(`/treasury/reconciliation/statements/${this.statementId}/summary`)
                if (seq === this.seq.summary) {
                    this.summary = res.data.data
                }
            } catch (error) {
                if (seq === this.seq.summary) {
                    this.summary = null
                    this.loadError = this.loadError || parseError(error).general
                }
            }
        },
        async loadLines(page = 1, silent = false) {
            const seq = ++this.seq.lines
            if (silent !== true) {
                this.loadingLines = true
            }
            this.linesError = null
            try {
                const params = { page: typeof page === 'number' ? page : 1 }
                if (this.lineFilters.status && this.lineFilters.status !== 'all') {
                    params.status = this.lineFilters.status
                }
                if (this.lineFilters.search) {
                    params.search = this.lineFilters.search
                }
                const res = await this.$http.get(`/treasury/reconciliation/statements/${this.statementId}/lines`, { params })
                if (seq === this.seq.lines) {
                    this.lines = res.data.data.rows
                    this.linesMeta = res.data.data.meta
                    // Keep the selected line in sync (it may have been matched or ignored).
                    if (this.selectedLine) {
                        const fresh = this.lines.filter(l => l.id === this.selectedLine.id)[0]
                        if (fresh && fresh.status !== 'unmatched') {
                            this.clearSelectedLine()
                        }
                    }
                }
            } catch (error) {
                if (seq === this.seq.lines) {
                    this.linesError = parseError(error).general || 'No se pudieron cargar las líneas.'
                }
            } finally {
                if (seq === this.seq.lines) {
                    this.loadingLines = false
                }
            }
        },
        async loadCandidates(silent = false) {
            if (this.isClosed) {
                this.candidates = []
                return
            }
            const seq = ++this.seq.candidates
            if (silent !== true) {
                this.loadingCandidates = true
            }
            this.candidatesError = null
            try {
                const params = {}
                if (this.selectedLine) {
                    params.line_id = this.selectedLine.id
                }
                if (this.candidateSearch) {
                    params.search = this.candidateSearch
                }
                const res = await this.$http.get(`/treasury/reconciliation/statements/${this.statementId}/candidates`, { params })
                if (seq === this.seq.candidates) {
                    this.candidates = res.data.data.rows
                    this.candidatesTruncated = !!res.data.data.truncated
                    this.candidatesInfo = { window_days: res.data.data.window_days }
                    this.selectedItems = []
                }
            } catch (error) {
                if (seq === this.seq.candidates) {
                    this.candidatesError = parseError(error).general || 'No se pudieron cargar los movimientos del sistema.'
                }
            } finally {
                if (seq === this.seq.candidates) {
                    this.loadingCandidates = false
                }
            }
        },
        selectLine(line) {
            if (this.isClosed || line.status !== 'unmatched') {
                return
            }
            this.selectedLine = line
            this.matchError = null
            this.loadCandidates()
        },
        clearSelectedLine() {
            this.selectedLine = null
            this.selectedItems = []
            this.matchError = null
            if (this.$refs.candidatesTable) {
                this.$refs.candidatesTable.clearSelection()
            }
            this.loadCandidates(true)
        },
        candidateKey(row) {
            return row.type + ':' + row.id
        },
        candidateRowClass({ row }) {
            return row.suggested ? 'rc-suggested-row' : ''
        },
        onSelectionChange(rows) {
            this.selectedItems = rows
        },
        lineBadge(status) {
            return status === 'matched' ? 'badge-success' : (status === 'ignored' ? 'badge-secondary' : 'badge-warning')
        },
        lineStatusLabel(status) {
            return status === 'matched' ? 'Emparejada' : (status === 'ignored' ? 'Ignorada' : 'Pendiente')
        },
        afterChange() {
            this.loadStatement(true)
            this.loadSummary()
            this.loadLines(this.linesMeta.current_page, true)
            this.loadCandidates(true)
        },
        async matchSelected() {
            if (!this.canMatch || this.matching) {
                return
            }
            this.matching = true
            this.matchError = null
            try {
                const res = await this.$http.post(`/treasury/reconciliation/lines/${this.selectedLine.id}/match`, {
                    items: this.selectedItems.map(i => ({ type: i.type, id: i.id })),
                })
                this.$message.success(res.data.message || 'Línea emparejada.')
                this.selectedLine = null
                this.selectedItems = []
                this.afterChange()
            } catch (error) {
                const parsed = parseError(error)
                this.matchError = parsed.general
            } finally {
                this.matching = false
            }
        },
        async autoMatch() {
            if (this.autoMatching) {
                return
            }
            this.autoMatching = true
            try {
                const res = await this.$http.post(`/treasury/reconciliation/statements/${this.statementId}/auto-match`)
                this.$message.success(res.data.message || 'Emparejamiento automático completado.')
                this.selectedLine = null
                this.afterChange()
            } catch (error) {
                this.$message.error(parseError(error).general)
            } finally {
                this.autoMatching = false
            }
        },
        unmatch(line) {
            this.$confirm('La línea volverá a quedar pendiente y los movimientos asociados podrán emparejarse de nuevo.', 'Desvincular línea', {
                confirmButtonText: 'Desvincular',
                cancelButtonText: 'Cancelar',
                type: 'warning',
            }).then(async () => {
                try {
                    await this.$http.post(`/treasury/reconciliation/lines/${line.id}/unmatch`)
                    this.$message.success('Línea desvinculada.')
                    this.afterChange()
                } catch (error) {
                    this.$message.error(parseError(error).general)
                }
            }).catch(() => {})
        },
        async unignore(line) {
            try {
                await this.$http.post(`/treasury/reconciliation/lines/${line.id}/unignore`)
                this.$message.success('La línea vuelve a estar pendiente.')
                this.afterChange()
            } catch (error) {
                this.$message.error(parseError(error).general)
            }
        },
        openIgnore(line) {
            this.ignoreDialog = { visible: true, busy: false, line, reason: '', errors: {}, general: null }
        },
        async submitIgnore() {
            const dialog = this.ignoreDialog
            if (dialog.busy || !dialog.line) {
                return
            }
            dialog.busy = true
            dialog.errors = {}
            dialog.general = null
            try {
                await this.$http.post(`/treasury/reconciliation/lines/${dialog.line.id}/ignore`, { reason: dialog.reason })
                this.$message.success('Línea ignorada.')
                dialog.visible = false
                this.afterChange()
            } catch (error) {
                const parsed = parseError(error)
                dialog.errors = parsed.fields
                dialog.general = parsed.fields.reason ? null : parsed.general
            } finally {
                dialog.busy = false
            }
        },
        openMove(line) {
            const sign = line.amount > 0 ? 1 : -1
            const first = (this.tables.movement_kinds || []).filter(k => k.sign === sign)[0]
            this.moveDialog = {
                visible: true,
                busy: false,
                line,
                kind: first ? first.value : null,
                treasury_concept_id: null,
                description: '',
                establishment_id: null,
                errors: {},
                general: null,
            }
        },
        async submitMove() {
            const dialog = this.moveDialog
            if (dialog.busy || !dialog.line || !dialog.kind) {
                return
            }
            dialog.busy = true
            dialog.errors = {}
            dialog.general = null
            try {
                const payload = { kind: dialog.kind }
                if (dialog.treasury_concept_id) {
                    payload.treasury_concept_id = dialog.treasury_concept_id
                }
                if (dialog.description) {
                    payload.description = dialog.description
                }
                if (dialog.establishment_id) {
                    payload.establishment_id = dialog.establishment_id
                }
                const res = await this.$http.post(`/treasury/reconciliation/lines/${dialog.line.id}/create-movement`, payload)
                this.$message.success(res.data.message || 'Movimiento registrado.')
                dialog.visible = false
                this.selectedLine = null
                this.afterChange()
            } catch (error) {
                const parsed = parseError(error)
                dialog.errors = parsed.fields
                const known = ['kind', 'treasury_concept_id', 'description', 'establishment_id']
                const otherKey = Object.keys(parsed.fields).filter(k => known.indexOf(k) === -1)[0]
                dialog.general = otherKey ? parsed.fields[otherKey][0] : (Object.keys(parsed.fields).length ? null : parsed.general)
            } finally {
                dialog.busy = false
            }
        },
        async openClose() {
            this.closeDialog = { visible: true, busy: false, general: null }
            await this.loadSummary()
        },
        async submitClose() {
            const dialog = this.closeDialog
            if (dialog.busy) {
                return
            }
            dialog.busy = true
            dialog.general = null
            try {
                const res = await this.$http.post(`/treasury/reconciliation/statements/${this.statementId}/close`)
                this.$message.success(res.data.message || 'Conciliación cerrada.')
                dialog.visible = false
                this.selectedLine = null
                this.reloadAll(true)
                this.$emit('changed')
            } catch (error) {
                dialog.general = parseError(error).general
            } finally {
                dialog.busy = false
            }
        },
        reopen() {
            this.$confirm('Los movimientos emparejados dejarán de estar conciliados y podrán modificarse. ¿Desea reabrir la conciliación?', 'Reabrir conciliación', {
                confirmButtonText: 'Reabrir',
                cancelButtonText: 'Cancelar',
                type: 'warning',
            }).then(async () => {
                this.reopening = true
                try {
                    const res = await this.$http.post(`/treasury/reconciliation/statements/${this.statementId}/reopen`)
                    this.$message.success(res.data.message || 'Conciliación reabierta.')
                    this.reloadAll(true)
                    this.$emit('changed')
                } catch (error) {
                    this.$message.error(parseError(error).general)
                } finally {
                    this.reopening = false
                }
            }).catch(() => {})
        },
        discard() {
            this.$confirm('Se eliminará el extracto importado y sus emparejamientos. Los movimientos del sistema no se modifican. ¿Desea descartarlo?', 'Descartar extracto', {
                confirmButtonText: 'Descartar',
                cancelButtonText: 'Cancelar',
                type: 'warning',
            }).then(async () => {
                try {
                    await this.$http.post(`/treasury/reconciliation/statements/${this.statementId}/discard`)
                    this.$message.success('Extracto descartado.')
                    this.$emit('changed')
                    this.$emit('back')
                } catch (error) {
                    this.$message.error(parseError(error).general)
                }
            }).catch(() => {})
        },
    },
}
</script>

<style>
.rc-suggested-row td {
    background-color: #f0f7ff !important;
}
</style>
