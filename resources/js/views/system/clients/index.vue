<template>
    <div class="system-dashboard">
        <header class="system-page-heading">
            <div>
                <h1>{{ managementMode ? "Clientes" : "Dashboard" }}</h1>
                <p>
                    {{
                        managementMode
                            ? accountsLabel
                            : today
                    }}
                </p>
            </div>
            <div class="system-page-actions">
                <a v-if="!managementMode" href="/clients" class="system-button"
                    >Ver clientes</a
                ><button
                    type="button"
                    class="system-button system-button-primary"
                    @click="clickCreate()"
                >
                    <svg class="sys-icon" aria-hidden="true">
                        <use :href="$icon('plus')" /></svg
                    >Nuevo cliente
                </button>
            </div>
        </header>
        <div v-if="recordsError" class="system-load-error" role="alert">
            No se pudieron cargar los clientes.
            <button type="button" @click="getData()">Reintentar</button>
        </div>
        <template v-if="!managementMode">
            <div class="system-dash-top">
                <section
                    class="system-client-summary"
                    aria-label="Resumen de clientes"
                >
                    <p class="system-hero-meta">Resumen · {{ todayShort }}</p>
                    <div class="system-client-total">
                        <div>
                            <p>Clientes</p>
                            <strong>{{
                                recordsLoading ? "—" : records.length
                            }}</strong>
                        </div>
                        <svg
                            class="system-donut"
                            viewBox="0 0 108 108"
                            role="img"
                            aria-label="Clientes por entorno"
                        >
                            <circle
                                cx="54"
                                cy="54"
                                r="42"
                                fill="none"
                                stroke="rgba(255,255,255,.10)"
                                stroke-width="13"
                            />
                            <circle
                                cx="54"
                                cy="54"
                                r="42"
                                fill="none"
                                stroke="#F2B544"
                                stroke-width="13"
                                :stroke-dasharray="donutDemo"
                                transform="rotate(-90 54 54)"
                            />
                            <circle
                                cx="54"
                                cy="54"
                                r="42"
                                fill="none"
                                stroke="#5ED3A0"
                                stroke-width="13"
                                :stroke-dasharray="donutProduction"
                                :stroke-dashoffset="
                                    (-demoCount / Math.max(records.length, 1)) *
                                        264
                                "
                                transform="rotate(-90 54 54)"
                            />
                        </svg>
                    </div>
                    <div class="system-environment-legend">
                        <span
                            ><i class="system-dot system-dot-demo"></i>Demo
                            <b>{{ demoCount }}</b></span
                        ><span
                            ><i class="system-dot system-dot-production"></i
                            >Producción <b>{{ productionCount }}</b></span
                        ><span v-if="internalCount"
                            ><i class="system-dot system-dot-internal"></i
                            >Interno <b>{{ internalCount }}</b></span
                        >
                    </div>
                    <div class="system-summary-metrics">
                        <div>
                            <p><svg class="sys-icon" aria-hidden="true"><use :href="$icon('receipt-text')" /></svg><span>Comprobantes</span></p>
                            <strong>{{
                                recordsLoading ? "—" : documentsCount
                            }}</strong>
                        </div>
                        <div>
                            <p><svg class="sys-icon" aria-hidden="true"><use :href="$icon('users')" /></svg><span>Usuarios</span></p>
                            <strong>{{
                                recordsLoading ? "—" : usersCount
                            }}</strong>
                        </div>
                    </div>
                    <a href="/clients" class="system-summary-action"
                        >Gestionar clientes<svg class="sys-icon" aria-hidden="true"><use :href="$icon('arrow-right')" /></svg></a>
                </section>
                <section
                    class="system-monthly-chart"
                    aria-label="Comprobantes por mes"
                >
                    <div class="system-chart-heading">
                        <div>
                            <h2>Comprobantes por mes</h2>
                            <p>
                                <strong>{{
                                    loaded ? total_documents : "—"
                                }}</strong
                                ><span>en {{ year }}</span>
                            </p>
                        </div>
                        <div class="system-year-selector" aria-label="Año del gráfico">
                            <button v-for="option in chartYears" :key="option" type="button"
                                :aria-pressed="year === option" :class="{ 'is-active': year === option }"
                                @click="selectYear(option)">{{ option }}</button>
                        </div>
                    </div>
                    <div
                        class="system-bar-chart"
                        :aria-busy="!loaded && !chartError"
                    >
                        <div class="system-chart-ticks">
                            <span v-for="tick in chartTicks" :key="tick">{{
                                tick
                            }}</span>
                        </div>
                        <div class="system-chart-plot">
                            <div class="system-chart-grid">
                                <span
                                    v-for="tick in chartTicks"
                                    :key="tick"
                                ></span>
                            </div>
                            <div class="system-chart-bars">
                                <div
                                    v-for="(month, index) in chartMonths"
                                    :key="month"
                                    class="system-chart-month"
                                    :class="{ 'is-current': year === currentYear && index === currentMonth }"
                                >
                                    <div
                                        class="system-chart-bar"
                                        v-if="chartValue(index)"
                                        :style="{ height: chartHeight(index) }"
                                        tabindex="0"
                                        role="img"
                                        :data-tip="barLabel(month, index)"
                                        :aria-label="barLabel(month, index)"
                                    ></div>
                                    <div v-else-if="loaded && !chartError && !total_documents" class="system-chart-ghost"
                                        :style="{ height: [24, 42, 33, 56, 38, 64, 48, 72, 60, 85, 68, 94][index] + '%' }"
                                        aria-hidden="true"></div>
                                    <span>{{ month }}</span>
                                </div>
                            </div>
                            <div
                                v-if="chartError"
                                class="system-chart-empty"
                                role="alert"
                            >
                                No se pudieron cargar los comprobantes.<button
                                    type="button"
                                    @click="loadChart()"
                                >
                                    Reintentar
                                </button>
                            </div>
                            <div
                                v-else-if="!loaded"
                                class="system-chart-empty"
                                role="status"
                            >
                                Cargando comprobantes…
                            </div>
                            <div
                                v-else-if="!total_documents"
                                class="system-chart-empty"
                            >
                                <svg class="sys-icon" aria-hidden="true">
                                    <use
                                        :href="$icon('chart-column')"
                                    /></svg
                                >No se generaron comprobantes en {{ year }}
                            </div>
                        </div>
                    </div>
                </section>
            <div class="system-resource-grid">
                <section class="system-resource" :class="{ 'is-unavailable': !resourceAvailable(discUsed) }">
                    <p class="system-resource-label"><svg class="sys-icon" aria-hidden="true"><use :href="$icon('hard-drive')" /></svg><span>Disco duro</span></p>
                    <strong>{{ resourceAvailable(discUsed) ? discUsed : "Sin datos" }}</strong>
                    <i v-if="resourceAvailable(discUsed)" class="system-meter" :class="resourceTone(discUsed)"><b :style="{ width: discUsed }"></b></i>
                    <small v-else>No se pudo leer el uso del disco</small>
                    <a class="system-resource-link"
                        href="https://docs.google.com/document/d/1hpEQUs9OFha_35yyLb1cMKeluD-dEku5lQsQ3TJFib8/edit"
                        target="_blank"
                        rel="noopener noreferrer"
                        ><span>Incrementar</span><svg class="sys-icon" aria-hidden="true"><use :href="$icon('arrow-right')" /></svg></a>
                </section>
                <section class="system-resource" :class="{ 'is-unavailable': !resourceAvailable(iUsed) }">
                    <p class="system-resource-label"><svg class="sys-icon" aria-hidden="true"><use :href="$icon('folder-tree')" /></svg><span>Inodes</span></p>
                    <strong>{{ resourceAvailable(iUsed) ? iUsed : "Sin datos" }}</strong>
                    <i v-if="resourceAvailable(iUsed)" class="system-meter" :class="resourceTone(iUsed)"><b :style="{ width: iUsed }"></b></i>
                    <small v-else>No se pudo leer el uso de inodes</small>
                    <a class="system-resource-link"
                        href="https://drive.google.com/open?id=1foPKDI3V3Z9uKTjRc2SPSoztVSOBevPAluT2BqFbfxA"
                        target="_blank"
                        rel="noopener noreferrer"
                        ><span>Limpiar</span><svg class="sys-icon" aria-hidden="true"><use :href="$icon('arrow-right')" /></svg></a>
                </section>
                <section class="system-resource" :class="{ 'is-unavailable': !storageSize }">
                    <p class="system-resource-label"><svg class="sys-icon" aria-hidden="true"><use :href="$icon('files')" /></svg><span>Archivos generados</span></p>
                    <strong>{{ storageSize || "Sin datos" }}</strong>
                    <small>XML, CDR y PDF de todos los clientes</small>
                </section>
                <section class="system-resource" :class="{ 'is-unavailable': !version }">
                    <p class="system-resource-label"><svg class="sys-icon" aria-hidden="true"><use :href="$icon('git-branch')" /></svg><span>Versión</span></p>
                    <strong class="system-version">{{ version || "Sin datos" }}</strong>
                    <small>Versión instalada del sistema</small>
                    <a class="system-resource-link" href="/auto-update"><span>Ver actualizaciones</span><svg class="sys-icon" aria-hidden="true"><use :href="$icon('arrow-right')" /></svg></a>
                </section>
            </div>
            </div>
            <div class="system-dash-bottom">
            <section class="system-recent-clients">
                <div class="system-section-heading">
                    <h2><svg class="sys-icon" aria-hidden="true"><use :href="$icon('users')" /></svg><span>Clientes recientes</span></h2>
                    <a href="/clients"
                        >Ver todos ({{ records.length }})</a>
                </div>
                <div class="table-responsive">
                    <table class="system-client-table">
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>RUC</th>
                                <th>Plan</th>
                                <th>Entorno</th>
                                <th class="text-right">Comprobantes</th>
                                <th>Creado</th>
                                <th>
                                    <span class="system-sr-only">Acciones</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in recentRecords" :key="row.id">
                                <td>
                                    <strong>{{ row.name }}</strong
                                    ><small>{{ row.hostname }}</small>
                                </td>
                                <td class="system-mono">{{ row.number }}</td>
                                <td>{{ row.plan }}</td>
                                <td>
                                    <span
                                        :class="[
                                            'system-badge',
                                            'system-badge-' + row.soap_type
                                        ]"
                                        ><i class="system-dot"></i
                                        >{{
                                            environmentLabel(row.soap_type)
                                        }}</span
                                    >
                                </td>
                                <td class="text-right">{{ row.count_doc }}</td>
                                <td class="system-mono">{{ formatDate(row.created_at) }}</td>
                                <td class="text-right">
                                    <button
                                        type="button"
                                        class="system-button system-button-small"
                                        @click="clickEdit(row.id)"
                                    >
                                        Gestionar
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p
                    v-if="recordsLoading"
                    class="system-table-empty"
                    role="status"
                >
                    Cargando clientes…
                </p>
                <p
                    v-else-if="!records.length && !recordsError"
                    class="system-table-empty"
                >
                    Todavía no hay clientes registrados.
                </p>
            </section>
                <aside class="system-dash-side" aria-label="Resumen de cuentas">
                    <section class="system-panel system-panel-pad">
                        <h2 class="system-panel-title"><svg class="sys-icon" aria-hidden="true"><use :href="$icon('list-checks')" /></svg><span>Estado de cuentas</span></h2>
                        <ul class="system-meter-list">
                            <li><div><span>Activas</span><strong>{{ activeCount }}</strong></div><i class="system-meter"><b :style="{ width: share(activeCount) }"></b></i></li>
                            <li><div><span>Bloqueadas</span><strong>{{ lockedCount }}</strong></div><i class="system-meter is-danger"><b :style="{ width: share(lockedCount) }"></b></i></li>
                            <li><div><span>Demo</span><strong>{{ demoCount }}</strong></div><i class="system-meter is-warn"><b :style="{ width: share(demoCount) }"></b></i></li>
                            <li><div><span>Producción</span><strong>{{ productionCount }}</strong></div><i class="system-meter is-ok"><b :style="{ width: share(productionCount) }"></b></i></li>
                        </ul>
                    </section>
                    <section class="system-panel system-panel-pad">
                        <h2 class="system-panel-title"><svg class="sys-icon" aria-hidden="true"><use :href="$icon('gauge')" /></svg><span>Uso de límites</span></h2>
                        <ul v-if="limitUsage.length" class="system-meter-list">
                            <li v-for="row in limitUsage" :key="row.id">
                                <div><span>{{ row.name }}</span><strong :class="{ 'system-over-limit': row.ratio > 1 }">{{ row.count_doc }} / {{ row.max_documents }}</strong></div>
                                <i class="system-meter" :class="{ 'is-danger': row.ratio > 1 }"><b :style="{ width: Math.min(100, row.ratio * 100) + '%' }"></b></i>
                            </li>
                        </ul>
                        <p v-else class="system-hint">Sin límites definidos</p>
                    </section>
                </aside>
            </div>
        </template>
        <div v-if="managementMode" class="system-kpis">
            <div><svg class="sys-icon" aria-hidden="true"><use :href="$icon('building-2')" /></svg><span>Total</span><strong>{{ records.length }}</strong></div>
            <div><svg class="sys-icon" aria-hidden="true"><use :href="$icon('flask-conical')" /></svg><span>Demo</span><strong>{{ demoCount }}</strong></div>
            <div><svg class="sys-icon" aria-hidden="true"><use :href="$icon('rocket')" /></svg><span>Producción</span><strong>{{ productionCount }}</strong></div>
            <div><svg class="sys-icon" aria-hidden="true"><use :href="$icon('lock')" /></svg><span>Bloqueadas</span><strong>{{ lockedCount }}</strong></div>
            <div><svg class="sys-icon" aria-hidden="true"><use :href="$icon('receipt-text')" /></svg><span>{{ hasMonthDocs ? "Comprobantes del ciclo" : "Comprobantes" }}</span><strong>{{ hasMonthDocs ? monthDocsCount : documentsCount }}</strong></div>
        </div>
        <section v-if="managementMode" class="system-panel" id="client-list">
            <div class="system-toolbar">
                <div class="system-filter system-filter-grow">
                    <label for="client-search">Buscar</label>
                    <div class="system-input-icon">
                        <svg class="sys-icon" aria-hidden="true"><use :href="$icon('search')" /></svg>
                        <input id="client-search" type="search" v-model="search" placeholder="Buscar por nombre, dominio o RUC" />
                    </div>
                </div>
                <div class="system-filter">
                    <label id="client-env-label">Entorno</label>
                    <el-select aria-labelledby="client-env-label" v-model="filterEnv">
                        <el-option value="" label="Entornos"></el-option>
                        <el-option value="01" label="Demo"></el-option>
                        <el-option value="02" label="Producción"></el-option>
                        <el-option value="03" label="Interno"></el-option>
                    </el-select>
                </div>
                <div class="system-filter">
                    <label id="client-plan-label">Plan</label>
                    <el-select aria-labelledby="client-plan-label" v-model="filterPlan">
                        <el-option value="" label="Planes"></el-option>
                        <el-option v-for="plan in planOptions" :key="plan" :value="plan" :label="plan"></el-option>
                    </el-select>
                </div>
                <div class="system-filter">
                    <label id="client-account-label">Cuenta</label>
                    <el-select aria-labelledby="client-account-label" v-model="filterAccount">
                        <el-option value="" label="Cuentas"></el-option>
                        <el-option value="active" label="Activas"></el-option>
                        <el-option value="locked" label="Bloqueadas"></el-option>
                    </el-select>
                </div>
                <p class="system-counter" role="status">{{ counterLabel }}</p>
            </div>
            <div class="table-responsive">
                <table class="system-client-table system-client-manager-table">
                    <thead>
                        <tr>
                            <th>Cliente</th>
                            <th>RUC y correo</th>
                            <th>Plan y entorno</th>
                            <th class="text-right">Comprobantes</th>
                            <th class="text-right">Usuarios</th>
                            <th>Bloqueos<small class="system-th-legend">Cuenta · Emisión · Usuarios</small></th>
                            <th class="text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in filteredRecords" :key="row.id">
                            <td>
                                <strong>{{ row.name }}</strong><small>{{ row.hostname }}</small>
                                <small class="system-cell-date">Creado {{ formatDate(row.created_at) }}</small>
                                <span v-if="row.locked_tenant" class="system-badge system-badge-locked">Bloqueada</span>
                            </td>
                            <td><span class="system-mono">{{ row.number }}</span><small class="system-cell-email" :title="row.email">{{ row.email }}</small></td>
                            <td>
                                <span :class="['system-badge', 'system-badge-' + row.soap_type]"><i class="system-dot"></i>{{ environmentLabel(row.soap_type) }}</span>
                                <small class="system-cell-plan">{{ row.plan }}</small>
                            </td>
                            <td class="text-right system-nowrap">
                                <el-popover v-if="row.max_documents !== 0 && row.count_doc > row.max_documents" placement="top-start" width="220" trigger="hover" :content="text_limit_doc">
                                    <strong slot="reference" class="system-over-limit">{{ row.count_doc }}</strong>
                                </el-popover>
                                <strong v-else>{{ row.count_doc }}</strong>
                                <span class="system-limit"> / <template v-if="row.max_documents == 0"><svg class="sys-icon" aria-label="Ilimitado" role="img"><use :href="$icon('infinity')" /></svg></template><template v-else>{{ row.max_documents }}</template></span>
                            </td>
                            <td class="text-right system-nowrap">
                                <el-popover v-if="row.max_users !== 0 && row.count_user > row.max_users" placement="top-start" width="220" trigger="hover" :content="text_limit_users">
                                    <strong slot="reference" class="system-over-limit">{{ row.count_user }}</strong>
                                </el-popover>
                                <strong v-else>{{ row.count_user }}</strong>
                                <span class="system-limit"> / <template v-if="row.max_users == 0"><svg class="sys-icon" aria-label="Ilimitado" role="img"><use :href="$icon('infinity')" /></svg></template><template v-else>{{ row.max_users }}</template></span>
                            </td>
                            <td>
                                <div class="system-switches">
                                    <el-switch v-if="!row.locked" :width="30" v-model="row.locked_tenant" title="Bloquear cuenta" aria-label="Bloquear cuenta" @change="changeLockedTenant(row)"></el-switch>
                                    <el-switch :width="30" v-model="row.locked_emission" title="Bloquear emisión" aria-label="Bloquear emisión" @change="changeLockedEmission(row)"></el-switch>
                                    <el-switch :width="30" v-model="row.locked_users" title="Bloquear usuarios" aria-label="Bloquear usuarios" @change="changeLockedUser(row)"></el-switch>
                                </div>
                            </td>
                            <td class="text-right">
                                <div class="system-row-actions">
                                    <button type="button" class="system-button system-button-small" @click.prevent="clickEdit(row.id)">Gestionar</button>
                                    <el-dropdown trigger="click" placement="bottom-end" @command="rowCommand($event, row)">
                                        <button type="button" class="system-button system-button-small system-icon-only" :aria-label="'Más acciones de ' + row.name" title="Más acciones">
                                            <svg class="sys-icon" aria-hidden="true"><use :href="$icon('ellipsis')" /></svg>
                                        </button>
                                        <el-dropdown-menu slot="dropdown" class="system-menu">
                                            <el-dropdown-item command="edit"><svg class="sys-icon" aria-hidden="true"><use :href="$icon('pencil')" /></svg>Editar datos</el-dropdown-item>
                                            <el-dropdown-item command="cycle"><svg class="sys-icon" aria-hidden="true"><use :href="$icon('calendar-clock')" /></svg>Ciclo de facturación</el-dropdown-item>
                                            <el-dropdown-item command="payments"><svg class="sys-icon" aria-hidden="true"><use :href="$icon('credit-card')" /></svg>Pagos</el-dropdown-item>
                                            <el-dropdown-item command="account"><svg class="sys-icon" aria-hidden="true"><use :href="$icon('file-text')" /></svg>Estado de cuenta</el-dropdown-item>
                                            <el-dropdown-item v-if="!row.locked" command="password"><svg class="sys-icon" aria-hidden="true"><use :href="$icon('key-round')" /></svg>Restablecer clave</el-dropdown-item>
                                            <el-dropdown-item v-if="!row.locked && deletePermission == true" command="delete" divided class="is-danger"><svg class="sys-icon" aria-hidden="true"><use :href="$icon('trash-2')" /></svg>Eliminar cliente</el-dropdown-item>
                                        </el-dropdown-menu>
                                    </el-dropdown>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-if="recordsLoading" class="system-table-empty" role="status">Cargando clientes…</p>
            <div v-else-if="!filteredRecords.length" class="system-empty-state">
                <h2>{{ records.length ? 'Ningún cliente coincide con la búsqueda' : 'Todavía no hay clientes registrados' }}</h2>
                <p v-if="records.length">Revisa el texto o los filtros aplicados.</p>
                <button v-if="records.length" type="button" class="system-button" @click="clearFilters()">Limpiar filtros</button>
            </div>
        </section>

        <el-dialog title="Ciclo de facturación" :visible.sync="showCycle" width="420px" append-to-body>
            <template v-if="cycleRow">
                <p v-if="cycleRow.start_billing_cycle" class="system-hint">Inicio del ciclo: <strong>{{ cycleRow.start_billing_cycle }}</strong><br />{{ cycleRow.count_doc_month ? cycleRow.count_doc_month : 0 }} / {{ cycleRow.max_documents == 0 ? "ilimitados" : cycleRow.max_documents }} comprobantes en el ciclo.</p>
                <div v-else class="system-field">
                    <label for="cycle-start">Inicio del ciclo</label>
                    <el-date-picker id="cycle-start" v-model="cycleRow.select_date_billing" value-format="yyyy-MM-dd" type="date" placeholder="Selecciona una fecha" @change="saveCycle($event, cycleRow.id)"></el-date-picker>
                </div>
            </template>
        </el-dialog>

        <system-clients-form
            :showDialog.sync="showDialog"
            :recordId="recordId"
        ></system-clients-form>

        <!--<system-clients-form-edit :showDialog.sync="showDialogEdit"
    :recordId="recordId"></system-clients-form-edit>-->

        <client-payments
            :showDialog.sync="showDialogPayments"
            :clientId="recordId"
        ></client-payments>

        <account-status
            :showDialog.sync="showDialogAccountStatus"
            :clientId="recordId"
        ></account-status>
    </div>
</template>

<script>
import CompaniesForm from "./form.vue";
//   import CompaniesFormEdit from './form_edit.vue'
import { deletable } from "../../../mixins/deletable";
import { changeable } from "../../../mixins/changeable";
import ClientPayments from "./partials/payments.vue";
import AccountStatus from "./partials/account_status.vue";

export default {
    mixins: [deletable, changeable],
    props: ["deletePermission", "discUsed", "iUsed", "storageSize", "version"],
    components: { CompaniesForm, ClientPayments, AccountStatus },
    data() {
        return {
            managementMode: window.location.pathname !== "/dashboard",
            search:
                new URLSearchParams(window.location.search).get("search") || "",
            filterEnv: "",
            filterPlan: "",
            filterAccount: "",
            recordsLoading: true,
            recordsError: false,
            chartError: false,
            chartRequest: 0,
            chartMonths: [
                "Ene",
                "Feb",
                "Mar",
                "Abr",
                "May",
                "Jun",
                "Jul",
                "Ago",
                "Set",
                "Oct",
                "Nov",
                "Dic"
            ],
            selectBillingDate: "",
            showDialogEdit: false,
            showCycle: false,
            cycleRow: null,
            showDialog: false,
            showDialogPayments: false,
            showDialogAccountStatus: false,
            resource: "clients",
            recordId: null,
            records: [],
            text_limit_doc: null,
            text_limit_users: null,
            loaded: false,
            year: Number(moment().format("YYYY")),
            currentYear: Number(moment().format("YYYY")),
            currentMonth: Number(moment().format("M")) - 1,
            chartYears: [Number(moment().format("YYYY")), Number(moment().format("YYYY")) - 1],
            total_documents: 0,
            dataChartLine: {
                labels: null,
                datasets: [
                    {
                        // label: 'Data One',
                        // backgroundColor: '#f87979',
                        data: null
                    }
                ]
            }
        };
    },
    computed: {
        today() {
            return new Intl.DateTimeFormat("es-PE", {
                weekday: "long",
                day: "numeric",
                month: "long",
                year: "numeric",
                timeZone: "America/Lima"
            }).format(new Date());
        },
        todayShort() {
            return new Intl.DateTimeFormat("es-PE", {
                day: "2-digit",
                month: "2-digit",
                year: "numeric",
                timeZone: "America/Lima"
            }).format(new Date());
        },
        demoCount() {
            return this.records.filter(row => row.soap_type === "01").length;
        },
        productionCount() {
            return this.records.filter(row => row.soap_type === "02").length;
        },
        internalCount() {
            return this.records.filter(row => row.soap_type === "03").length;
        },
        documentsCount() {
            return this.records.reduce(
                (total, row) => total + Number(row.count_doc || 0),
                0
            );
        },
        usersCount() {
            return this.records.reduce(
                (total, row) => total + Number(row.count_user || 0),
                0
            );
        },
        lockedCount() {
            return this.records.filter(row => row.locked_tenant).length;
        },
        activeCount() {
            return this.records.length - this.lockedCount;
        },
        hasMonthDocs() {
            return this.records.some(
                row => row.count_doc_month !== undefined && row.count_doc_month !== null
            );
        },
        monthDocsCount() {
            return this.records.reduce(
                (total, row) => total + Number(row.count_doc_month || 0),
                0
            );
        },
        limitUsage() {
            return this.records
                .filter(row => Number(row.max_documents) > 0)
                .map(row => ({
                    id: row.id,
                    name: row.name,
                    count_doc: Number(row.count_doc || 0),
                    max_documents: Number(row.max_documents),
                    ratio: Number(row.count_doc || 0) / Number(row.max_documents)
                }))
                .sort((a, b) => b.ratio - a.ratio)
                .slice(0, 5);
        },
        donutDemo() {
            return (
                (this.demoCount / Math.max(this.records.length, 1)) * 264 +
                " 264"
            );
        },
        donutProduction() {
            return (
                (this.productionCount / Math.max(this.records.length, 1)) *
                    264 +
                " 264"
            );
        },
        recentRecords() {
            return this.records
                .slice()
                .sort((a, b) => b.id - a.id)
                .slice(0, 5);
        },
        filteredRecords() {
            const query = this.search.trim().toLocaleLowerCase();
            return this.records.filter(
                row =>
                    [row.name, row.number, row.hostname].some(value =>
                        String(value || "")
                            .toLocaleLowerCase()
                            .includes(query)
                    ) &&
                    (!this.filterEnv || row.soap_type === this.filterEnv) &&
                    (!this.filterPlan || row.plan === this.filterPlan) &&
                    (!this.filterAccount ||
                        (this.filterAccount === "locked") === !!row.locked_tenant)
            );
        },
        planOptions() {
            return Array.from(
                new Set(this.records.map(row => row.plan).filter(Boolean))
            ).sort();
        },
        accountsLabel() {
            const total = this.records.length;
            return `${total} ${total === 1 ? "cuenta registrada" : "cuentas registradas"}`;
        },
        counterLabel() {
            const total = this.records.length;
            const shown = this.filteredRecords.length;
            if (shown !== total) return `${shown} de ${total}`;
            return `${total} ${total === 1 ? "cliente" : "clientes"}`;
        },
        chartMaximum() {
            return Math.max(
                4,
                ...this.chartMonths.map((month, index) =>
                    this.chartValue(index)
                )
            );
        },
        chartTicks() {
            const step = Math.ceil(this.chartMaximum / 4);
            return [step * 4, step * 3, step * 2, step, 0];
        }
    },
    mounted() {
        if (!this.managementMode) this.loadChart();
    },
    created() {
        this.$eventHub.$on("reloadData", () => {
            this.getData();
        });
        this.getData();

        this.text_limit_doc = "El límite de comprobantes fue superado";
        this.text_limit_users = "El límite de usuarios fue superado";
    },
    methods: {
        share(count) {
            return (count / Math.max(this.records.length, 1)) * 100 + "%";
        },
        formatDate(value) {
            const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value || "");
            if (!match) return value || "";
            return new Intl.DateTimeFormat("es-PE", {
                day: "numeric",
                month: "short",
                year: "numeric"
            })
                .format(new Date(+match[1], +match[2] - 1, +match[3]))
                .replace(/\./g, "");
        },
        saveCycle(value, id) {
            this.setStartBillingCycle(value, id);
            this.showCycle = false;
        },
        clearFilters() {
            this.search = "";
            this.filterEnv = "";
            this.filterPlan = "";
            this.filterAccount = "";
        },
        rowCommand(command, row) {
            if (command === "edit") this.clickEdit(row.id);
            else if (command === "cycle") {
                this.cycleRow = row;
                this.showCycle = true;
            } else if (command === "payments") this.clickPayments(row.id);
            else if (command === "account") this.clickAccountStatus(row.id);
            else if (command === "password") this.clickPassword(row.id);
            else if (command === "delete") this.clickDelete(row.id);
        },
        environmentLabel(type) {
            return (
                { "01": "Demo", "02": "Producción", "03": "Interno" }[type] ||
                "Sin información"
            );
        },
        resourceAvailable(value) {
            return typeof value === "string" && /^\d+(?:\.\d+)?%$/.test(value.trim());
        },
        resourceTone(value) {
            const pct = parseFloat(value) || 0;
            return pct >= 90 ? "is-danger" : pct >= 75 ? "is-warn" : "";
        },
        resourceRing(value) {
            return (
                (Math.min(100, Math.max(0, parseFloat(value) || 0)) / 100) *
                    157 +
                " 157"
            );
        },
        barLabel(month, index) {
            return `${month} ${this.year}: ${this.chartValue(index).toLocaleString("es-PE")} comprobantes`;
        },
        chartValue(index) {
            return Number(
                (this.dataChartLine.datasets[0].data || [])[index] || 0
            );
        },
        chartHeight(index) {
            return (this.chartValue(index) / this.chartTicks[0]) * 100 + "%";
        },
        selectYear(year) {
            if (year === this.year) return;
            this.year = year;
            this.loadChart();
        },
        loadChart() {
            const request = ++this.chartRequest;
            this.loaded = false;
            this.chartError = false;
            return this.$http
                .get(`/${this.resource}/charts`, { params: { year: this.year } })
                .then(response => {
                    if (request !== this.chartRequest) return;
                    this.dataChartLine.labels = response.data.line.labels;
                    this.dataChartLine.datasets[0].data =
                        response.data.line.data;
                    this.total_documents = response.data.total_documents;
                    this.loaded = true;
                })
                .catch(() => {
                    if (request !== this.chartRequest) return;
                    this.chartError = true;
                });
        },
        changeLockedTenant(row) {
            this.$http
                .post(`${this.resource}/locked_tenant`, row)
                .then(response => {
                    if (response.data.success) {
                        this.$message.success(response.data.message);
                        this.$eventHub.$emit("reloadData");
                    } else {
                        this.$message.error(response.data.message);
                    }
                })
                .catch(error => {
                    if (error.response.status === 500) {
                        this.$message.error(error.response.data.message);
                    } else {
                        console.log(error.response);
                    }
                })
                .then(() => {});
        },

        changeLockedUser(row) {
            this.$http
                .post(`${this.resource}/locked_user`, row)
                .then(response => {
                    if (response.data.success) {
                        this.$message.success(response.data.message);
                        this.$eventHub.$emit("reloadData");
                    } else {
                        this.$message.error(response.data.message);
                    }
                })
                .catch(error => {
                    if (error.response.status === 500) {
                        this.$message.error(error.response.data.message);
                    } else {
                        console.log(error.response);
                    }
                })
                .then(() => {});
        },

        setStartBillingCycle(event, id) {
            this.$http
                .post(`${this.resource}/set_billing_cycle`, {
                    id: id,
                    start_billing_cycle: event
                })
                .then(response => {
                    if (response.data.success) {
                        this.$message.success(response.data.message);
                    } else {
                        this.$message.error(response.data.message);
                    }
                })
                .catch(error => {
                    if (error.response.status === 500) {
                        this.$message.error(error.response.data.message);
                    } else {
                        console.log(error.response);
                    }
                })
                .then(() => {
                    this.$eventHub.$emit("reloadData");
                });
        },
        changeLockedEmission(row) {
            this.$http
                .post(`${this.resource}/locked_emission`, row)
                .then(response => {
                    if (response.data.success) {
                        this.$message.success(response.data.message);
                        this.$eventHub.$emit("reloadData");
                    } else {
                        this.$message.error(response.data.message);
                    }
                })
                .catch(error => {
                    if (error.response.status === 500) {
                        this.$message.error(error.response.data.message);
                    } else {
                        console.log(error.response);
                    }
                })
                .then(() => {});
        },
        getData() {
            this.recordsLoading = true;
            this.recordsError = false;
            return this.$http
                .get(`/${this.resource}/records`)
                .then(response => {
                    this.records = response.data.data;
                })
                .catch(() => {
                    this.recordsError = true;
                })
                .then(() => {
                    this.recordsLoading = false;
                });
        },
        clickCreate(recordId = null) {
            this.recordId = recordId;
            this.showDialog = true;
        },
        clickPayments(recordId = null) {
            this.recordId = recordId;
            this.showDialogPayments = true;
        },
        clickAccountStatus(recordId = null) {
            this.recordId = recordId;
            this.showDialogAccountStatus = true;
        },
        clickPassword(id) {
            this.change(`/${this.resource}/password/${id}`);
        },
        clickDelete(id) {
            this.destroy(`/${this.resource}/${id}`).then(() =>
                this.$eventHub.$emit("reloadData")
            );
        },
        clickEdit(recordId) {
            this.recordId = recordId;
            this.showDialog = true;
        }
    }
};
</script>
