<template>
    <div class="tenant-dash">
        <section class="td-hello">
            <div>
                <h1>{{ greeting }}</h1>
                <p class="td-today">
                    <template v-if="todayLine.has">
                        Hoy emitiste <b class="td-num">{{ todayLine.count }}</b> por <b class="td-num">{{ todayLine.amount }}</b>.
                        <template v-if="todayLine.accepted">
                            SUNAT aceptó {{ todayLine.accepted }}
                            <span class="td-seal is-ok" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"></path></svg></span>
                        </template>
                        <template v-if="todayLine.accepted && todayLine.pending"> y </template>
                        <template v-if="todayLine.pending">
                            {{ todayLine.pending }}
                            <span class="td-seal is-wait" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M12 6v6h4"></path></svg></span>
                        </template>
                    </template>
                    <template v-else-if="insights">Aún no emites comprobantes hoy.</template>
                    <template v-else-if="errors.insights">Resumen de ventas, cobranza, compras e inventario del periodo seleccionado.</template>
                </p>
            </div>
            <div class="td-filters">
                <div class="td-field">
                    <label class="td-label">Establecimiento</label>
                    <el-select v-model="form.establishment_id" placeholder="Establecimiento" @change="loadAll">
                        <el-option v-for="option in establishments" :key="option.id" :value="option.id" :label="option.name"></el-option>
                    </el-select>
                </div>
                <div class="td-field">
                    <label class="td-label">Periodo</label>
                    <el-select v-model="form.period" @change="changePeriod">
                        <el-option key="all" value="all" label="Todos"></el-option>
                        <el-option key="last_week" value="last_week" label="Última semana"></el-option>
                        <el-option key="month" value="month" label="Por mes"></el-option>
                        <el-option key="between_months" value="between_months" label="Entre meses"></el-option>
                        <el-option key="date" value="date" label="Por fecha"></el-option>
                        <el-option key="between_dates" value="between_dates" label="Entre fechas"></el-option>
                    </el-select>
                </div>
                <div class="td-field" v-if="form.period === 'month' || form.period === 'between_months'">
                    <label class="td-label">Mes de</label>
                    <el-date-picker v-model="form.month_start" type="month" @change="changeDisabledMonths" value-format="yyyy-MM" format="MM/yyyy" :clearable="false"></el-date-picker>
                </div>
                <div class="td-field" v-if="form.period === 'between_months'">
                    <label class="td-label">Mes al</label>
                    <el-date-picker v-model="form.month_end" type="month" :picker-options="pickerOptionsMonths" @change="loadAll" value-format="yyyy-MM" format="MM/yyyy" :clearable="false"></el-date-picker>
                </div>
                <div class="td-field" v-if="form.period === 'date' || form.period === 'between_dates'">
                    <label class="td-label">Fecha del</label>
                    <el-date-picker v-model="form.date_start" type="date" @change="changeDisabledDates" value-format="yyyy-MM-dd" format="dd/MM/yyyy" :clearable="false"></el-date-picker>
                </div>
                <div class="td-field" v-if="form.period === 'between_dates'">
                    <label class="td-label">Fecha al</label>
                    <el-date-picker v-model="form.date_end" type="date" :picker-options="pickerOptionsDates" @change="loadAll" value-format="yyyy-MM-dd" format="dd/MM/yyyy" :clearable="false"></el-date-picker>
                </div>
            </div>
        </section>

        <RowTop :company="company" :utilities="utilities" :general="general" :document="document" :sale_note="sale_note" :soap-company="soapCompany" :insights="insights" :initial-global="initialGlobal" :skip-global-fetch="skipGlobalFetch" @global-loaded="onGlobalLoaded"></RowTop>

        <!-- Hero: type split | sales by period | collection -->
        <section class="td-hero">
            <div class="td-panel">
                <div class="td-ph"><div><h2>Ventas por tipo</h2><p>{{ isSaleNoteOnly ? "Notas de venta del periodo" : "Comprobantes y notas del periodo" }}</p></div></div>
                <div class="td-skel td-skel-tall" v-if="loaders.insights"></div>
                <template v-else>
                    <p class="td-empty" v-if="!typeSplit.has">{{ emptyText('insights', 'Sin ventas en el periodo seleccionado.') }}</p>
                    <template v-else>
                        <apexchart type="donut" height="290" :options="typeSplit.chart.options" :series="typeSplit.chart.series"></apexchart>
                        <ul class="td-legend td-num">
                            <li v-for="row in typeSplit.rows" :key="row.label">
                                <i :style="{ background: row.color }"></i><span>{{ row.label }}</span>
                                <b>{{ row.money }}<em>{{ row.pct }}%</em></b>
                            </li>
                        </ul>
                    </template>
                </template>
            </div>

            <div class="td-panel td-center">
                <div class="td-ph"><h2>{{ hero.title }}</h2>
                    <div class="td-seg" role="group" aria-label="Agrupar por" v-if="hero.units.length > 1">
                        <button v-for="unit in hero.units" :key="unit.key" type="button" :aria-pressed="String(unit.key === hero.unit)" @click="setHeroUnit(unit.key)">{{ unit.label }}</button>
                    </div>
                </div>
                <div class="td-skel td-skel-tall" v-if="loaders.insights"></div>
                <template v-else>
                    <p class="td-empty" v-if="!hero.has">{{ emptyText('insights', 'Sin ventas en el periodo seleccionado.') }}</p>
                    <template v-else>
                        <div class="td-big td-num"><strong>{{ hero.total }}</strong>
                            <span v-if="hero.delta"><span :class="'td-delta is-' + hero.delta.tone">{{ hero.delta.text }}</span> {{ hero.compareText }}</span>
                        </div>
                        <div class="td-keys">
                            <span v-for="key in hero.keys" :key="key.label"><i :class="{ dash: key.dash }" :style="key.dash ? null : { background: key.color }"></i>{{ key.label }}</span>
                        </div>
                        <apexchart :key="'hero-' + hero.unit" type="area" height="330" :options="hero.chart.options" :series="hero.chart.series"></apexchart>
                        <div class="td-mini td-num" :style="{ '--m': hero.tiles.length }">
                            <div v-for="tile in hero.tiles" :key="tile.label"><i :style="{ background: tile.color }"></i>{{ tile.label }}<b>{{ tile.value }}</b></div>
                        </div>
                    </template>
                </template>
            </div>

            <div class="td-panel">
                <div class="td-ph"><div><h2>Cobranza</h2><p>Cuánto ya cobraste</p></div>
                    <div class="td-ph-links">
                        <el-popover placement="bottom" width="280" trigger="hover" v-if="balance && balance.totals && balance.totals.all_totals !== undefined">
                            <p><span class="td-pop">T. Ventas - T. Compras/Gastos</span></p>
                            <p>Total comprobantes:<span class="td-pop right">S/ {{ balance.totals.total_document }}</span></p>
                            <p>Total notas de venta:<span class="td-pop right">S/ {{ balance.totals.total_sale_note }}</span></p>
                            <p>Total compras:<span class="td-pop right">- S/ {{ balance.totals.total_purchase }}</span></p>
                            <p>Total gastos:<span class="td-pop right">- S/ {{ balance.totals.total_expense }}</span></p>
                            <p>Total pagos comprobantes:<span class="td-pop right">S/ {{ balance.totals.total_payment_document }}</span></p>
                            <p>Total pagos notas de venta:<span class="td-pop right">S/ {{ balance.totals.total_payment_sale_note }}</span></p>
                            <p>Total pagos compras:<span class="td-pop right">- S/ {{ balance.totals.total_payment_purchase }}</span></p>
                            <p>Total pagos gastos:<span class="td-pop right">- S/ {{ balance.totals.total_payment_expense }}</span></p>
                            <p>Balance total:<span class="td-pop right">S/ {{ balance.totals.all_totals }}</span></p>
                            <p>Balance pagado:<span class="td-pop right">S/ {{ balance.totals.all_totals_payment }}</span></p>
                            <button type="button" class="td-link" slot="reference">Detalle</button>
                        </el-popover>
                        <a href="/finances/unpaid">Ver</a>
                    </div>
                </div>
                <div class="td-skel td-skel-tall" v-if="loaders.balance || loaders.document"></div>
                <template v-else>
                    <p class="td-empty" v-if="!collection.has">{{ emptyText('general', 'Aún no hay montos por cobrar en el periodo.') }}</p>
                    <template v-else>
                        <apexchart type="radialBar" height="310" :options="collection.chart.options" :series="collection.chart.series"></apexchart>
                        <ul class="td-legend td-num">
                            <li v-for="row in collection.rows" :key="row.label">
                                <i :style="{ background: row.color }"></i><span>{{ row.label }}</span>
                                <b>{{ row.pct }}%<em>{{ row.money }}</em></b>
                            </li>
                        </ul>
                    </template>
                </template>
            </div>
        </section>

        <!-- Balance | Utilities | Purchases -->
        <section class="td-trio">
            <div class="td-panel">
                <div class="td-ph"><div><h2>Ventas por día de la semana</h2><p>Qué días vende más tu negocio</p></div></div>
                <div class="td-skel td-skel-tall" v-if="loaders.insights"></div>
                <template v-else>
                    <p class="td-empty" v-if="!weekdayView.has">{{ emptyText('insights', 'Sin ventas en el periodo seleccionado.') }}</p>
                    <template v-else>
                        <div class="td-keys">
                            <span v-for="key in weekdayView.keys" :key="key.label"><i :style="{ background: key.color }"></i>{{ key.label }}</span>
                        </div>
                        <apexchart type="radar" height="300" :options="weekdayView.chart.options" :series="weekdayView.chart.series"></apexchart>
                    </template>
                </template>
            </div>

            <div class="td-panel">
                <div class="td-ph"><div><h2>Utilidades y ganancias</h2><p>Balance de ventas, compras y gastos</p></div></div>
                <div class="td-checks">
                    <el-checkbox v-model="form.enabled_expense" @change="loadDataUtilities">Considerar gastos</el-checkbox>
                    <el-checkbox v-model="filter_item" @change="changeFilterItem">Filtrar por producto</el-checkbox>
                </div>
                <div class="td-form-row" v-if="filter_item">
                    <el-select v-model="form.item_id" filterable remote popper-class="el-select-customers" clearable
                        placeholder="Buscar producto"
                        :remote-method="searchRemoteItems"
                        :loading="loading_search"
                        @change="loadDataUtilities">
                        <el-option v-for="option in items" :key="option.id" :value="option.id" :label="option.description"></el-option>
                    </el-select>
                </div>
                <div class="td-skel td-skel-tall" v-if="loaders.utility"></div>
                <template v-else>
                    <p class="td-empty" v-if="!polarView.has">{{ emptyText('utility', 'Sin ingresos ni egresos en el periodo.') }}</p>
                    <template v-else>
                        <apexchart type="polarArea" height="340" :options="polarView.chart.options" :series="polarView.chart.series"></apexchart>
                        <div class="td-sum td-num"><span>Te quedan</span><b :class="{ ok: polarView.utility >= 0 }">{{ polarView.text }}</b></div>
                    </template>
                </template>
            </div>

            <div class="td-panel">
                <div class="td-ph"><div><h2>Compras del año</h2>
                    <p>{{ purchaseView.note }}</p></div><a href="/purchases">Ver compras</a></div>
                <div class="td-skel td-skel-tall" v-if="loaders.insights"></div>
                <template v-else>
                    <p class="td-empty" v-if="!purchaseView.has">{{ emptyText('insights', 'Sin compras en el año.') }}</p>
                    <template v-else>
                        <apexchart type="line" height="270" :options="purchaseView.chart.options" :series="purchaseView.chart.series"></apexchart>
                        <div class="td-sum td-num"><span>Total del año</span><b>{{ purchaseView.total }}</b></div>
                    </template>
                </template>
            </div>
        </section>

        <!-- Collection schedule -->
        <section class="td-panel td-timeline">
            <div class="td-ph"><div><h2>Cronograma de cobros</h2><p>Cada factura por cobrar, desde su emisión hasta su vencimiento</p></div>
                <div class="td-status" v-if="scheduleView.has">
                    <span><i style="background:#E05D5D"></i>Vencida</span><span><i style="background:#E9A23B"></i>Vence pronto</span><span><i style="background:#2F6FDF"></i>Al día</span>
                </div>
            </div>
            <div class="td-skel td-skel-tall" v-if="loaders.insights"></div>
            <template v-else>
                <p class="td-empty" v-if="!scheduleView.has">{{ emptyText('insights', 'No tienes facturas a crédito pendientes de cobro.') }}</p>
                <template v-else>
                    <apexchart class="td-sched-chart" type="rangeBar" :height="scheduleView.chart.height" :options="scheduleView.chart.options" :series="scheduleView.chart.series"></apexchart>
                    <!-- Phones: the same receivables as a list, a timeline has no room for names at that width -->
                    <ul class="td-sched-list">
                        <li v-for="(row, i) in scheduleView.rows" :key="i" :class="'is-' + row.status">
                            <div><b>{{ row.customer }}</b><small>{{ row.document }} · {{ row.statusText }}</small></div>
                            <strong>{{ row.amountText }}</strong>
                        </li>
                    </ul>
                </template>
            </template>
        </section>

        <!-- Top products | Top customers | Low stock -->
        <section class="td-bottom">
            <div class="td-panel">
                <div class="td-ph"><div><h2>Lo más vendido</h2><p>Top 5 del periodo</p></div>
                    <div class="td-seg" role="group" aria-label="Medir por">
                        <button type="button" :aria-pressed="String(!form.enabled_move_item)" @click="setMoveItem(false)">Monto</button>
                        <button type="button" :aria-pressed="String(form.enabled_move_item)" @click="setMoveItem(true)">Mov.</button>
                    </div>
                </div>
                <div class="td-skel td-skel-tall" v-if="loaders.items_by_sales"></div>
                <template v-else>
                    <p class="td-empty" v-if="!funnelView.has">{{ emptyText('additional', 'Sin ventas de productos en el periodo.') }}</p>
                    <template v-else>
                        <apexchart type="bar" height="330" :options="funnelView.chart.options" :series="funnelView.chart.series"></apexchart>
                        <ul class="td-who td-num">
                            <li v-for="row in funnelView.rows" :key="row.name"><i :style="{ background: row.color }"></i>{{ row.name }}</li>
                        </ul>
                    </template>
                </template>
            </div>

            <div class="td-panel">
                <div class="td-ph"><div><h2>Top clientes</h2><p>Compras, monto y ticket promedio</p></div>
                    <div class="td-seg" role="group" aria-label="Ordenar por">
                        <button type="button" :aria-pressed="String(!form.enabled_transaction_customer)" @click="setTransactionCustomer(false)">Monto</button>
                        <button type="button" :aria-pressed="String(form.enabled_transaction_customer)" @click="setTransactionCustomer(true)">Trans.</button>
                    </div>
                </div>
                <div class="td-skel td-skel-tall" v-if="loaders.top_customers"></div>
                <template v-else>
                    <p class="td-empty" v-if="!bubbleView.has">{{ emptyText('additional', 'Sin clientes con ventas en el periodo.') }}</p>
                    <template v-else>
                        <apexchart type="bubble" height="290" :options="bubbleView.chart.options" :series="bubbleView.chart.series"></apexchart>
                        <ul class="td-who td-num">
                            <li v-for="row in bubbleView.rows" :key="row.name"><i :style="{ background: row.color }"></i>{{ row.name }}</li>
                        </ul>
                    </template>
                </template>
            </div>

            <div class="td-panel">
                <div class="td-ph"><div><h2>Por reponer</h2><p>Stock frente al mínimo</p></div>
                    <a href="/inventory">Inventario</a></div>
                <dashboard-stock :initial="initialStock" @fetched="onStockFetched"></dashboard-stock>
            </div>
        </section>
    </div>
</template>
<style>
.tenant-dash{--td-blue:#2F6FDF;--td-ink:#0E1B2E;--td-ink2:#3A4A60;--td-ink3:#5F6E83;--td-shadow:0 1px 2px rgba(14,27,46,.04),0 10px 28px -18px rgba(14,27,46,.22);--td-display:"Bricolage Grotesque",ui-sans-serif,system-ui,sans-serif;color:var(--td-ink);padding-bottom:48px}
.tenant-dash .td-num{font-variant-numeric:tabular-nums}
.tenant-dash .td-hello{display:flex;align-items:flex-end;justify-content:space-between;gap:20px 24px;flex-wrap:wrap}
.tenant-dash .td-hello h1{font-family:var(--td-display);font-weight:600;font-size:clamp(30px,3vw,42px);line-height:1.05;letter-spacing:-.03em;margin:0 0 8px;color:var(--td-ink)}
.tenant-dash .td-hello p{font-size:clamp(15.5px,1.25vw,17.5px);color:var(--td-ink2);margin:0;max-width:62ch;line-height:1.45;min-height:1.45em}
.tenant-dash .td-hello p b{color:var(--td-ink);font-weight:600}
.tenant-dash .td-seal{display:inline-grid;place-items:center;width:22px;height:22px;border-radius:50%;vertical-align:-4px;margin:0 2px}
.tenant-dash .td-seal.is-ok{background:#E7F5EC;color:#14804A}
.tenant-dash .td-seal.is-wait{background:#FCF1DF;color:#8A5200}
.tenant-dash .td-seal svg{width:13px;height:13px;stroke-width:3}
.tenant-dash .td-filters{display:flex;gap:10px 14px;flex-wrap:wrap}
.tenant-dash .td-field{min-width:180px}
.tenant-dash .td-label{position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0}
.tenant-dash .td-field{position:relative}
.tenant-dash .td-filters .el-input__inner{height:42px;line-height:42px;border:0;border-radius:12px;background:#fff;box-shadow:var(--td-shadow);font-size:15.6px;font-weight:500;color:var(--td-ink)}
.tenant-dash .td-filters .el-input__icon{line-height:42px}
.tenant-dash .td-filters .el-date-editor.el-input,.tenant-dash .td-filters .el-select{width:100%}
.tenant-dash .td-panel{background:#fff;border-radius:22px;box-shadow:var(--td-shadow);padding:20px 22px;min-width:0}
.tenant-dash .td-ph{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;min-height:34px}
.tenant-dash .td-ph h2{font-size:17.1px;font-weight:600;margin:0;color:var(--td-ink)}
.tenant-dash .td-ph p{margin:2px 0 0;font-size:14.6px;color:var(--td-ink3)}
.tenant-dash .td-ph a,.tenant-dash .td-link{font-size:15.1px;font-weight:600;color:#175CC4;background:none;border:0;padding:0;cursor:pointer}
.tenant-dash .td-ph a:hover,.tenant-dash .td-link:hover{text-decoration:underline;text-underline-offset:3px}
.tenant-dash .td-cert{display:flex;gap:8px 14px;flex-wrap:wrap;align-items:center;margin-top:20px;padding:12px 18px;border-radius:16px;background:#E7F5EC;color:#14804A;font-size:15.6px;box-shadow:var(--td-shadow)}
.tenant-dash .td-cert.is-due-warning{background:#FCE9E9;color:#B4362F}
.tenant-dash .td-kpis{display:grid;grid-template-columns:repeat(var(--n,5),minmax(0,1fr));gap:16px;margin-top:28px}
.tenant-dash .td-kpi{position:relative;display:grid;grid-template-columns:minmax(0,1fr) auto;grid-template-rows:1fr auto;align-items:start;column-gap:10px;padding:18px 16px 14px 20px;overflow:hidden}
.tenant-dash .td-kt{min-width:0;align-self:stretch}
.tenant-dash .td-kt > span{display:block;font-size:14.6px;font-weight:500;color:var(--td-ink3);line-height:1.3;min-height:2.6em}
.tenant-dash .td-kt strong span{display:inline;font:inherit;color:var(--td-ink);line-height:1.15}
.tenant-dash .td-kt small{display:block;font-size:14.1px;font-weight:600;color:#14804A}
.tenant-dash .td-kt small::before{content:"";display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--k);margin-right:7px}
.tenant-dash .td-kt small.is-flat{color:var(--td-ink3);font-weight:500}
.tenant-dash .td-kt small.is-down{color:#B4362F}
.tenant-dash .td-kt strong{display:block;font-family:var(--td-display);font-weight:600;font-size:clamp(23px,1.7vw,28px);letter-spacing:-.02em;margin:8px 0 2px;white-space:nowrap}
.tenant-dash .td-mc{width:min(156px,44cqi);height:60px;display:grid;place-items:center;min-width:0;align-self:center}
.tenant-dash .td-kpi{container-type:inline-size}
.tenant-dash .td-kt .td-short{display:none}
/* Container widths exclude the card padding: full figures need ~190px of text width, compact ones ~110px. */
@container (max-width:299px){.tenant-dash .td-kt strong{font-size:23px}.tenant-dash .td-mc{width:min(120px,40cqi)}}
@container (max-width:249px){.tenant-dash .td-kt .td-full{display:none}.tenant-dash .td-kt .td-short{display:inline}.tenant-dash .td-kt strong{font-size:22px}.tenant-dash .td-mc{width:96px}}
@container (max-width:199px){.tenant-dash .td-kt strong{font-size:20.5px}.tenant-dash .td-mc{width:76px}}
.tenant-dash .td-mc-empty{display:block;width:70%;height:2px;background:#EEF2F8;border-radius:2px}
.tenant-dash .td-cap{grid-column:1 / -1;margin:12px 0 0;padding-top:10px;align-self:end;box-shadow:inset 0 1px 0 #EEF2F8;font-size:14.1px;color:var(--td-ink3)}
.tenant-dash .td-hero{display:grid;grid-template-columns:minmax(260px,1fr) minmax(0,2.3fr) minmax(260px,1fr);gap:16px;margin-top:16px}
.tenant-dash .td-big{display:flex;flex-wrap:wrap;align-items:baseline;column-gap:12px;margin-top:6px}
.tenant-dash .td-big strong{font-family:var(--td-display);font-weight:600;font-size:clamp(26px,2.3vw,34px);letter-spacing:-.02em;white-space:nowrap}
.tenant-dash .td-big span{font-size:15.1px;color:var(--td-ink3)}
.tenant-dash .td-keys{display:flex;flex-wrap:wrap;gap:6px 18px;margin:10px 0 0;font-size:14.6px;color:var(--td-ink2)}
.tenant-dash .td-keys i,.tenant-dash .td-who i,.tenant-dash .td-legend i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:7px}
.tenant-dash .td-keys i.dash{height:0;width:14px;border-top:2px dashed #E9A23B;border-radius:0;vertical-align:3px}
.tenant-dash .td-big .td-delta{font-weight:600;color:#14804A}
.tenant-dash .td-big .td-delta.is-down{color:#B4362F}
.tenant-dash .td-big .td-delta.is-flat{color:var(--td-ink3)}
.tenant-dash .td-mini{display:grid;grid-template-columns:repeat(var(--m,4),minmax(0,1fr));gap:10px;margin-top:6px}
.tenant-dash .td-mini div{background:#F6F8FC;border-radius:14px;padding:10px 12px;font-size:14.1px;color:var(--td-ink3)}
.tenant-dash .td-mini b{display:block;font-size:16.6px;font-weight:600;color:var(--td-ink);margin-top:2px;white-space:nowrap}
.tenant-dash .td-mini i{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px}
.tenant-dash .td-ph-links{display:flex;align-items:center;gap:6px 14px}
.tenant-dash .td-timeline{margin-top:16px}
.tenant-dash .td-sched-list{display:none;list-style:none;margin:8px 0 0;padding:0}
.tenant-dash .td-sched-list li{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 14px;margin-top:8px;border-radius:14px;background:#F6F8FC}
.tenant-dash .td-sched-list li div{min-width:0}
.tenant-dash .td-sched-list b{display:block;font-size:15.1px;font-weight:600;color:var(--td-ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tenant-dash .td-sched-list small{display:block;font-size:14.1px;color:var(--td-ink3)}
.tenant-dash .td-sched-list strong{flex:none;font-size:15.6px;font-weight:600;color:var(--td-ink);font-variant-numeric:tabular-nums}
.tenant-dash .td-sched-list li.is-overdue small{color:#B4362F}
.tenant-dash .td-sched-list li.is-soon small{color:#9A6212}
html.dark .tenant-dash .td-sched-list li{background:#1C2D49}
@media (max-width:640px){.tenant-dash .td-sched-chart{display:none}.tenant-dash .td-sched-list{display:block}}
.tenant-dash .td-status{display:flex;flex-wrap:wrap;gap:6px 16px;font-size:14.6px;color:var(--td-ink2)}
.tenant-dash .td-status i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:7px;vertical-align:-1px}
.tenant-dash .td-legend{list-style:none;margin:6px 0 0;padding:0;display:flex;flex-direction:column;gap:2px}
.tenant-dash .td-legend li{display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;gap:8px;padding:8px 10px;margin:0 -10px;border-radius:10px;font-size:15.1px}
.tenant-dash .td-legend li:hover{background:#F6F8FC}
.tenant-dash .td-legend i{margin:0}
.tenant-dash .td-legend span{color:var(--td-ink2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tenant-dash .td-legend b{font-weight:600;white-space:nowrap}
.tenant-dash .td-legend em{font-style:normal;color:var(--td-ink3);font-size:14.1px;margin-left:4px}
.tenant-dash .td-trio,.tenant-dash .td-bottom{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-top:16px}
.tenant-dash .td-checks{display:flex;flex-wrap:wrap;gap:6px 16px;margin-top:6px}
.tenant-dash .td-checks .el-checkbox{margin-right:0}
.tenant-dash .td-form-row{margin-top:8px}
.tenant-dash .td-sum{display:flex;justify-content:space-between;gap:10px;padding-top:12px;margin-top:2px;box-shadow:inset 0 1px 0 #EEF2F8;font-size:15.1px;color:var(--td-ink3)}
.tenant-dash .td-sum b{color:var(--td-ink);font-weight:600;white-space:nowrap}
.tenant-dash .td-sum b.ok{color:#14804A}
.tenant-dash .td-pop{font-weight:600}
.tenant-dash .td-pop.right{float:right}
.tenant-dash .td-seg{display:inline-flex;background:#F1F4F9;border-radius:11px;padding:3px}
.tenant-dash .td-seg button{border:0;background:none;height:30px;padding:0 12px;border-radius:9px;font-size:14.6px;font-weight:600;color:var(--td-ink3);cursor:pointer;white-space:nowrap}
.tenant-dash .td-seg button[aria-pressed="true"]{background:#fff;color:var(--td-ink);box-shadow:0 1px 2px rgba(14,27,46,.1)}
.tenant-dash .td-who{list-style:none;margin:6px 0 0;padding:0;display:flex;flex-wrap:wrap;gap:6px 14px;font-size:14.1px;color:var(--td-ink2)}
.tenant-dash .td-who i{border-radius:50%;width:9px;height:9px}
.tenant-dash .td-empty{margin:18px 0;padding:28px 12px;border-radius:16px;background:#F8FAFD;color:var(--td-ink3);text-align:center;font-size:15.6px}
.tenant-dash .td-skel{border-radius:16px;background:linear-gradient(90deg,#F1F4F9,#F8FAFD,#F1F4F9);background-size:200% 100%;animation:td-shimmer 1.4s linear infinite;margin-top:14px;min-height:120px}
.tenant-dash .td-skel-tall{min-height:260px}
@keyframes td-shimmer{to{background-position:-200% 0}}
.tenant-dash .td-gauges{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 14px;margin-top:10px}
.tenant-dash .td-g{display:flex;flex-direction:column;align-items:center;text-align:center;padding:8px 6px 12px;border-radius:16px;background:#F8FAFD;min-width:0}
.tenant-dash .td-g-chart{width:100%;max-width:170px;height:96px;overflow:hidden}
.tenant-dash .td-g b{font-size:15.1px;font-weight:600;max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}
.tenant-dash .td-g span{font-size:14.1px;color:var(--td-ink3);margin:4px 0 6px;max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.tenant-dash .td-g em{font-style:normal;font-size:14.1px;font-weight:600;padding:2px 10px;border-radius:999px}
.tenant-dash .td-g em.is-bad{background:#FCE9E9;color:#B4362F}
.tenant-dash .td-g em.is-warn{background:#FCF1DF;color:#8A5200}
.tenant-dash .td-g em.is-info{background:#E6EEFC;color:#2458C2}
.tenant-dash .td-pager{margin-top:12px;text-align:center}
.tenant-dash .apexcharts-canvas text{font-family:"Hanken Grotesk",ui-sans-serif,system-ui,sans-serif!important}
.tenant-dash .apexcharts-tooltip.apexcharts-theme-dark{background:#0E1B2E!important;border:0!important;border-radius:12px!important;box-shadow:0 12px 28px -12px rgba(14,27,46,.5)!important}
.td-tt{padding:10px 12px;font-size:14.6px;line-height:1.55;background:#0E1B2E;color:#fff;border-radius:12px}
.td-tt span{display:block;color:#B9C6D9}
.td-tt b{font-size:16.6px;font-variant-numeric:tabular-nums}
.td-tt em{display:block;font-style:normal;color:#D6E0EE}
@media (max-width:1360px){.tenant-dash .td-hero{grid-template-columns:repeat(2,minmax(0,1fr))}.tenant-dash .td-hero .td-center{grid-column:1/-1;order:-1}.tenant-dash .td-trio,.tenant-dash .td-bottom{grid-template-columns:repeat(2,minmax(0,1fr))}.tenant-dash .td-trio>:last-child,.tenant-dash .td-bottom>:last-child{grid-column:1/-1}.tenant-dash .td-kpis{grid-template-columns:repeat(6,minmax(0,1fr))}.tenant-dash .td-kpi{grid-column:span 2}.tenant-dash .td-kpi:nth-child(n+4){grid-column:span 3}}
@media (max-width:760px){.tenant-dash .td-hero,.tenant-dash .td-trio,.tenant-dash .td-bottom{grid-template-columns:1fr}.tenant-dash .td-mini{grid-template-columns:repeat(2,minmax(0,1fr))}.tenant-dash .td-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.tenant-dash .td-kpi,.tenant-dash .td-kpi:nth-child(n+4){grid-column:auto}.tenant-dash .td-kpi:last-child:nth-child(odd){grid-column:1/-1}.tenant-dash .td-filters,.tenant-dash .td-field{width:100%}.tenant-dash .td-panel{padding:18px 16px;border-radius:18px}}
@media (max-width:420px){.tenant-dash .td-kpis{grid-template-columns:1fr}}
@media (prefers-reduced-motion:reduce){.tenant-dash .td-skel{animation:none}}
html.dark .tenant-dash{--td-ink:#E6EDF7;--td-ink2:#B9C6D9;--td-ink3:#93A3BA}
html.dark .tenant-dash .td-panel{background:#16233A;box-shadow:none}
html.dark .tenant-dash .td-filters .el-input__inner{background:#16233A;box-shadow:none}
html.dark .tenant-dash .td-mc-empty{background:#22324D}
html.dark .tenant-dash .td-cert{background:rgba(20,128,74,.22);color:#6FD3A0}
html.dark .tenant-dash .td-cert.is-due-warning{background:rgba(224,93,93,.2);color:#FFB4A8}
html.dark .tenant-dash .td-g em.is-bad{background:rgba(224,93,93,.2);color:#FFB4A8}
html.dark .tenant-dash .td-g em.is-warn{background:rgba(233,162,59,.2);color:#F2C177}
html.dark .tenant-dash .td-g em.is-info{background:rgba(91,141,239,.2);color:#9DBFF4}
html.dark .tenant-dash .td-sum b.ok,html.dark .tenant-dash .td-kt small,html.dark .tenant-dash .td-big .td-delta{color:#6FD3A0}
html.dark .tenant-dash .td-kt small.is-flat,html.dark .tenant-dash .td-big .td-delta.is-flat{color:var(--td-ink3)}
html.dark .tenant-dash .td-kt small.is-down,html.dark .tenant-dash .td-big .td-delta.is-down{color:#FFB4A8}
html.dark .tenant-dash .td-mini div{background:#1C2D49}
html.dark .tenant-dash .td-seal.is-ok{background:rgba(20,128,74,.22);color:#6FD3A0}
html.dark .tenant-dash .td-seal.is-wait{background:rgba(233,162,59,.2);color:#F2C177}
html.dark .tenant-dash .td-legend li:hover,html.dark .tenant-dash .td-g,html.dark .tenant-dash .td-empty{background:#1C2D49}
html.dark .tenant-dash .td-seg{background:#1C2D49}
html.dark .tenant-dash .td-seg button[aria-pressed="true"]{background:#243A5E}
html.dark .tenant-dash .td-skel{background:#1C2D49;animation:none}
html.dark .tenant-dash .td-cap,html.dark .tenant-dash .td-sum{box-shadow:inset 0 1px 0 #243A5E}
html.dark .tenant-dash .td-ph a,html.dark .tenant-dash .td-link{color:#7FAEF5}
</style>
<script>
import DashboardStock from "./partials/dashboard_stock.vue";
import queryString from "query-string";
import RowTop from "./RowTop";
import { C, SERIES, num, money, pct, compare, donut, compareArea, rings, weekdayRadar, polar, purchases, funnel, bubbles, schedule } from "./charts";

const MONTHS_SHORT = ["ene", "feb", "mar", "abr", "may", "jun", "jul", "ago", "set", "oct", "nov", "dic"];
const HERO_UNITS = [
  { key: "day", label: "Días", title: "Ventas por día", best: "Mejor día" },
  { key: "week", label: "Semanas", title: "Ventas por semana", best: "Mejor semana" },
  { key: "month", label: "Meses", title: "Ventas por mes", best: "Mejor mes" },
];
const HOUR_UNIT = { key: "hour", label: "Horas", title: "Ventas por hora", best: "Mejor hora" };

// "YYYY-MM-DD" to a UTC timestamp, the scale the receivables timeline uses.
function utcMs(ymd) {
  const parts = String(ymd || "").split("-").map(Number);
  return Date.UTC(parts[0], (parts[1] || 1) - 1, parts[2] || 1);
}

// Stale-while-revalidate cache for the combined dashboard payload: in memory (survives shell
// navigation) and in sessionStorage (survives a full reload). Keyed by host + user + filter.
const CACHE_PREFIX = "tenant-dash:v1:";
const CACHE_MAX_ENTRIES = 8;
const CACHE_MAX_CHARS = 600000;
const CACHE_MAX_AGE_MS = 6 * 60 * 60 * 1000;
const memCache = {};
const dirtyKeys = {};
let flushTimer = null;
let consumedStamp = null;

function safeStorage() {
  try {
    return window.sessionStorage;
  } catch (e) {
    return null;
  }
}

function readEntry(key) {
  let entry = memCache[key];
  if (!entry) {
    const st = safeStorage();
    if (!st) return null;
    try {
      const raw = st.getItem(CACHE_PREFIX + key);
      entry = raw ? JSON.parse(raw) : null;
    } catch (e) {
      entry = null;
    }
    if (entry) memCache[key] = entry;
  }
  if (!entry || Date.now() - (entry.ts || 0) > CACHE_MAX_AGE_MS) return null;
  return entry;
}

function persistEntry(key) {
  const st = safeStorage();
  const entry = memCache[key];
  if (!st || !entry) return;
  try {
    const raw = JSON.stringify(entry);
    if (raw.length > CACHE_MAX_CHARS) return;
    let index = [];
    try {
      index = JSON.parse(st.getItem(CACHE_PREFIX + "index") || "[]");
    } catch (e) {
      index = [];
    }
    index = index.filter((k) => k !== key);
    index.push(key);
    while (index.length > CACHE_MAX_ENTRIES) st.removeItem(CACHE_PREFIX + index.shift());
    try {
      st.setItem(CACHE_PREFIX + key, raw);
    } catch (e) {
      // Quota exceeded: keep only this entry.
      index.forEach((k) => k !== key && st.removeItem(CACHE_PREFIX + k));
      index = [key];
      st.setItem(CACHE_PREFIX + key, raw);
    }
    st.setItem(CACHE_PREFIX + "index", JSON.stringify(index));
  } catch (e) {
    /* storage unavailable: the in-memory cache still works */
  }
}

function writeEntry(key, parts) {
  memCache[key] = Object.assign({}, memCache[key] || readEntry(key) || {}, parts, { ts: Date.now() });
  dirtyKeys[key] = true;
  clearTimeout(flushTimer);
  flushTimer = setTimeout(() => {
    Object.keys(dirtyKeys).forEach((k) => {
      delete dirtyKeys[k];
      persistEntry(k);
    });
  }, 250);
}

// Server-embedded payload for the default filter. Each payload is consumed once (stamp), so a
// remount of the same DOM never reuses stale data.
function readBootstrap() {
  try {
    const el = document.getElementById("tenant-dash-bootstrap");
    if (!el) return null;
    const boot = JSON.parse(el.textContent);
    if (!boot || !boot.stamp || boot.stamp === consumedStamp) return null;
    consumedStamp = boot.stamp;
    return boot;
  } catch (e) {
    return null;
  }
}

function plural(n, one, many) {
  return n + " " + (n === 1 ? one : many);
}

// API payloads can miss blocks or keys; widgets must fall back to their empty state instead of throwing.
function withDefaults(block) {
  const source = block && typeof block === "object" ? block : {};
  return Object.assign({}, source, {
    totals: Object.assign({}, source.totals),
    graph: source.graph && typeof source.graph === "object" ? source.graph : {},
  });
}

export default {
  props: ["typeUser", "soapCompany", "userName", "userId"],
  components: { DashboardStock, RowTop },
  data() {
    return {
      loading_search: false,
      records_base: [],
      selected_customer: null,
      customers: [],
      resource: "dashboard",
      establishments: [],
      balance: {
        totals: {},
        graph: {},
      },
      document: {
        totals: {},
        graph: {},
      },
      sale_note: {
        totals: {},
        graph: {},
      },
      general: {
        totals: {},
        graph: {},
      },
      purchase: {
        totals: {},
        graph: {},
      },
      utilities: {
        totals: {},
        graph: {},
      },
      form: {},
      pickerOptionsDates: {
        disabledDate: (time) => {
          time = moment(time).format("YYYY-MM-DD");
          return this.form.date_start > time;
        },
      },
      pickerOptionsMonths: {
        disabledDate: (time) => {
          time = moment(time).format("YYYY-MM");
          return this.form.month_start > time;
        },
      },
      records: [],
      items_by_sales: [],
      top_customers: [],
      recordId: null,
      showDialogDocumentPayments: false,
      showDialogSaleNotePayments: false,
      filter_item: false,
      all_items: [],
      items: [],
      company: {},
      loaders: {},
      insights: null,
      heroUnit: null,
      errors: { general: false, additional: false, utility: false, insights: false },
      // Latest request token per endpoint: an older response must never overwrite a newer filter.
      requestSeq: { data: 0, additional: 0, utility: 0, company: 0, insights: 0 },
      sizeTimer: null,
      initialStock: null,
      initialGlobal: null,
      skipGlobalFetch: false,
      companyLoaded: false,
    };
  },
  created() {
    this.initForm();
    this.initLoaders();
    if (this.hydrateFromBootstrap()) return;
    return this.bootFallback();
  },

  computed: {
    firstName() {
      const first = String(this.userName || "").trim().split(/\s+/)[0] || "";
      return first ? first.charAt(0).toUpperCase() + first.slice(1).toLowerCase() : "";
    },
    greeting() {
      const hour = new Date().getHours();
      const salute = hour >= 5 && hour < 12 ? "Buenos días" : hour >= 12 && hour < 19 ? "Buenas tardes" : "Buenas noches";
      return this.firstName ? `${salute}, ${this.firstName}.` : `${salute}.`;
    },
    isSaleNoteOnly() {
      return this.soapCompany == "03";
    },
    generalTotals() {
      return (this.general && this.general.totals) || {};
    },
    insightsMeta() {
      return (this.insights && this.insights.meta) || {};
    },
    todayLine() {
      const today = this.insights && this.insights.today;
      if (!today || !num(today.count)) return { has: false };
      const accepted = num(today.accepted);
      const pending = num(today.pending);
      return {
        has: true,
        count: plural(num(today.count), "comprobante", "comprobantes"),
        amount: money(today.total),
        accepted: accepted,
        pending: pending ? (pending === 1 ? "1 espera envío" : pending + " esperan envío") : "",
      };
    },
    typeSplit() {
      const split = (this.insights && this.insights.split) || {};
      const rows = [];
      if (!this.isSaleNoteOnly) {
        rows.push({ label: "Facturas", value: Math.max(0, num(split.invoices)), color: C.blue });
        rows.push({ label: "Boletas", value: Math.max(0, num(split.receipts)), color: C.teal });
      }
      rows.push({ label: "Notas de venta", value: Math.max(0, num(split.sale_notes)), color: C.violet });
      const total = rows.reduce((sum, r) => sum + r.value, 0);
      return {
        has: total > 0,
        chart: donut(rows.map((r) => r.value), rows.map((r) => r.label), rows.map((r) => r.color)),
        rows: rows.map((r) => Object.assign({}, r, { money: money(r.value), pct: total > 0 ? Math.round((r.value / total) * 100) : 0 })),
      };
    },
    heroUnits() {
      const available = this.insightsMeta.units || [];
      return HERO_UNITS.filter((u) => available.indexOf(u.key) !== -1);
    },
    heroActiveUnit() {
      const available = this.insightsMeta.units || [];
      if (this.heroUnit && available.indexOf(this.heroUnit) !== -1) return this.heroUnit;
      return this.insightsMeta.default_unit || available[0] || null;
    },
    hero() {
      const meta = this.insightsMeta;
      const unitKey = this.heroActiveUnit;
      const series = this.insights && this.insights.series;
      const set = unitKey && series ? series[unitKey] : null;
      const unitInfo = unitKey === "hour" ? HOUR_UNIT : HERO_UNITS.find((u) => u.key === unitKey) || HERO_UNITS[0];
      const empty = { has: false, title: unitInfo.title, units: [], unit: unitKey, keys: [], tiles: [] };
      if (!set) return empty;

      const docsOn = !this.isSaleNoteOnly;
      const merge = (block) =>
        block ? block.notes.map((n, i) => (block.docs[i] === null || n === null ? null : (docsOn ? num(block.docs[i]) : 0) + num(n))) : null;
      const current = merge(set.current).map(num);
      const previous = merge(set.previous);
      const names = [(meta.labels || {}).current || "Periodo actual", (meta.labels || {}).previous || "Periodo anterior"];

      const totals = (this.insights && this.insights.totals) || {};
      const pick = (block) => (block ? (docsOn ? num(block.total) : num(block.notes)) : null);
      const delta = totals.previous ? compare(pick(totals.current), pick(totals.previous)) : null;

      const counts = (this.insights && this.insights.counts) || {};
      const tiles = [];
      if (docsOn) {
        tiles.push({ label: "Facturas", value: num(counts.invoices), color: C.blue });
        tiles.push({ label: "Boletas", value: num(counts.receipts), color: C.teal });
      }
      tiles.push({ label: "Notas de venta", value: num(counts.sale_notes), color: C.violet });
      if (docsOn) tiles.push({ label: "Notas de crédito", value: num(counts.credit_notes), color: C.red });

      const keys = [{ label: names[0], color: C.blue }];
      if (previous) keys.push({ label: names[1], dash: true });
      return {
        has: current.some((v) => v > 0),
        title: unitInfo.title,
        units: unitKey === "hour" ? [] : this.heroUnits,
        unit: unitKey,
        chart: compareArea({ labels: set.labels, full: set.full, current: current, previous: previous, names: names, bestText: unitInfo.best }),
        total: money(pick(totals.current)),
        delta: delta,
        compareText: (meta.labels || {}).compare || "",
        keys: keys,
        tiles: tiles,
      };
    },
    weekdayView() {
      const week = (this.insights && this.insights.weekdays) || null;
      if (!week) return { has: false };
      const names = week.names && week.names.length === 2
        ? week.names
        : [(this.insightsMeta.labels || {}).current || "Periodo actual", (this.insightsMeta.labels || {}).previous || "Periodo anterior"];
      const keys = [{ label: names[0], color: C.blue }];
      if (week.previous) keys.push({ label: names[1], color: C.amber });
      return {
        has: (week.current || []).some((v) => num(v) > 0),
        keys: keys,
        chart: weekdayRadar(week.labels, week.current || [], week.previous, names),
      };
    },
    collection() {
      const doc = (this.document && this.document.totals) || {};
      const note = (this.sale_note && this.sale_note.totals) || {};
      const bal = (this.balance && this.balance.totals) || {};
      const rows = [];
      if (!this.isSaleNoteOnly) rows.push({ label: "Comprobantes", pct: pct(doc.total_payment, doc.total), money: money(doc.total_payment), color: C.blue });
      rows.push({ label: "Notas de venta", pct: pct(note.total_payment, note.total), money: money(note.total_payment), color: C.teal });
      rows.push({ label: "Balance general", pct: num(bal.all_totals) > 0 ? pct(bal.all_totals_payment, bal.all_totals) : 0, money: money(bal.all_totals_payment), color: C.amber });
      const due = (this.isSaleNoteOnly ? 0 : num(doc.total_to_pay)) + num(note.total_to_pay);
      const has = (this.isSaleNoteOnly ? 0 : num(doc.total)) + num(note.total) > 0;
      return {
        has,
        rows,
        chart: rings(rows.map((r) => r.pct), rows.map((r) => r.label), rows.map((r) => r.color), "S/ " + (due >= 1000 ? (due / 1000).toFixed(1) + "k" : due.toFixed(2))),
      };
    },
    polarView() {
      const t = (this.utilities && this.utilities.totals) || {};
      const income = num(t.total_income);
      const values = [income, num(t.total_purchase), num(t.total_expense), num(t.utility)];
      const margin = income > 0 ? Math.round((values[3] / income) * 100) : 0;
      return {
        has: values.some((v) => v !== 0),
        utility: values[3],
        text: money(values[3]) + " · " + margin + "%",
        chart: polar(values),
      };
    },
    purchaseView() {
      const year = this.insights && this.insights.purchases_year;
      if (!year) return { has: false, note: "Con percepciones" };
      const buys = year.buys || [];
      const perceptions = year.perceptions || [];
      const current = year.current_month;
      const lastIndex = current !== null && current !== undefined ? current : buys.reduce((last, v, i) => (v !== null ? i : last), -1);
      return {
        has: buys.concat(perceptions).some((v) => num(v) > 0),
        note: current === null || current === undefined ? "Con percepciones · " + year.year : "Con percepciones",
        total: money(year.total),
        chart: purchases(["E", "F", "M", "A", "M", "J", "J", "A", "S", "O", "N", "D"], buys, perceptions, lastIndex, year.months || []),
      };
    },
    scheduleView() {
      const list = (this.insights && this.insights.receivables) || [];
      if (!list.length) return { has: false };
      const rows = list
        .map((r) => {
          const days = num(r.days_to_due);
          const due = utcMs(r.due);
          let statusText;
          if (days < 0) statusText = "Vencida hace " + plural(-days, "día", "días");
          else if (days === 0) statusText = "Vence hoy";
          else if (days <= 7) statusText = "Vence en " + plural(days, "día", "días");
          else statusText = "Vence el " + new Date(due).getUTCDate() + " " + MONTHS_SHORT[new Date(due).getUTCMonth()];
          return Object.assign({}, r, { issueMs: utcMs(r.issue), dueMs: due, statusText: statusText });
        })
        .sort((a, b) => a.dueMs - b.dueMs);
      rows.forEach((r) => (r.amountText = money(r.amount)));
      return { has: true, rows: rows, chart: schedule(rows, utcMs(this.insights.today && this.insights.today.date ? this.insights.today.date : moment().format("YYYY-MM-DD"))) };
    },
    funnelView() {
      const move = !!this.form.enabled_move_item;
      const rows = (this.items_by_sales || [])
        .map((r) => ({ name: r.description || "", short: String(r.description || "").slice(0, 14), value: move ? num(r.move_quantity) : num(r.total) }))
        .sort((a, b) => b.value - a.value)
        .slice(0, 5);
      rows.forEach((r, i) => (r.color = SERIES[i % SERIES.length]));
      return { has: rows.length > 0 && rows[0].value > 0, rows, chart: funnel(rows, move) };
    },
    bubbleView() {
      const rows = (this.top_customers || [])
        .slice(0, 5)
        .map((r, i) => ({ name: r.name || "", qty: num(r.transaction_quantity), total: num(r.total), color: SERIES[i % SERIES.length] }));
      return { has: rows.length > 0 && rows.some((r) => r.total > 0), rows, chart: bubbles(rows) };
    },
  },
  mounted() {
    this.refreshChartSizes();
  },
  beforeDestroy() {
    clearTimeout(this.sizeTimer);
  },
  methods: {
    cacheKey() {
      return `${window.location.host}|${this.userId}|${JSON.stringify(this.form)}`;
    },
    cachePart(part) {
      const entry = readEntry(this.cacheKey());
      return entry && entry[part] ? entry[part] : null;
    },
    cacheWrite(key, parts) {
      writeEntry(key, parts);
    },
    // Same shapes as the XHR responses; each apply method is shared by bootstrap, cache and XHR.
    applyData(data) {
      data = data || {};
      this.document = withDefaults(data.document);
      this.balance = withDefaults(data.balance);
      this.sale_note = withDefaults(data.sale_note);
      this.general = withDefaults(data.general);
      this.customers = data.customers || [];
      this.items = data.items || [];
      this.errors.general = false;
      this.hideLoadersLoadData();
    },
    applyAdditional(data) {
      data = data || {};
      this.purchase = withDefaults(data.purchase);
      this.items_by_sales = data.items_by_sales || [];
      this.top_customers = data.top_customers || [];
      this.errors.additional = false;
      this.hideLoadersLoadDataAditional();
    },
    applyUtilities(data) {
      this.utilities = withDefaults((data || {}).utilities);
      this.errors.utility = false;
      this.loaders.utility = false;
    },
    applyInsights(data) {
      this.insights = data || null;
      this.errors.insights = !this.insights;
      this.loaders.insights = false;
    },
    hydrateFromBootstrap() {
      const boot = readBootstrap();
      if (!boot || !boot.data || !boot.additional || !boot.utilities || !boot.insights) return false;
      const f = boot.form || {};
      // The server computes the default dates in its own timezone: only trust it when they match this browser's.
      const same = ["period", "date_start", "date_end", "month_start", "month_end"].every((k) => f[k] === this.form[k]);
      if (!same) return false;
      this.establishments = boot.establishments || [];
      this.form.establishment_id = f.establishment_id === undefined ? null : f.establishment_id;
      this.applyData(boot.data);
      this.applyAdditional(boot.additional);
      this.applyUtilities(boot.utilities);
      this.applyInsights(boot.insights);
      this.company = boot.company || {};
      this.companyLoaded = true;
      this.initialGlobal = boot.global || null;
      this.skipGlobalFetch = !!boot.global;
      const stock = boot.stock || {};
      const meta = stock.meta || {};
      this.initialStock = {
        establishment_id: this.form.establishment_id,
        records: stock.data || [],
        pagination: {
          total: parseInt(meta.total) || 0,
          per_page: parseInt(meta.per_page) || 5,
          current_page: parseInt(meta.current_page) || 1,
        },
      };
      this.cacheWrite(this.cacheKey(), {
        data: boot.data,
        additional: boot.additional,
        utilities: boot.utilities,
        insights: boot.insights,
        stock: this.initialStock,
        global: boot.global || null,
        company: this.company,
      });
      return true;
    },
    async bootFallback() {
      const estKey = `est|${window.location.host}|${this.userId}`;
      const cachedEst = readEntry(estKey);
      let started = false;
      if (cachedEst && cachedEst.establishments && cachedEst.establishments.length) {
        this.establishments = cachedEst.establishments;
        this.form.establishment_id = this.establishments[0].id;
        started = true;
        this.loadAll();
      }
      try {
        const response = await this.$http.get(`/${this.resource}/filter`);
        const list = (response.data && response.data.establishments) || [];
        const id = list.length > 0 ? list[0].id : null;
        writeEntry(estKey, { establishments: list });
        this.establishments = list;
        if (!started || id !== this.form.establishment_id) {
          this.form.establishment_id = id;
          await this.loadAll();
        }
      } catch (error) {
        if (!started) {
          this.establishments = [];
          this.form.establishment_id = null;
          await this.loadAll();
        }
      }
      await this.filterItems();
    },
    onStockFetched(stock) {
      this.cacheWrite(this.cacheKey(), { stock });
    },
    onGlobalLoaded(global) {
      this.cacheWrite(this.cacheKey(), { global });
    },
    emptyText(key, fallback) {
      return this.errors[key] ? "No se pudo cargar esta información. Cambia el filtro para intentarlo de nuevo." : fallback;
    },
    // Charts mounted through a shell navigation can measure a zero-width container: re-fit this
    // dashboard's own charts once instead of broadcasting a global resize.
    refreshChartSizes() {
      clearTimeout(this.sizeTimer);
      this.$nextTick(() => {
        this.sizeTimer = setTimeout(() => {
          const walk = (vm) => {
            (vm.$children || []).forEach((child) => {
              const chart = child.chart;
              if (chart && typeof chart.windowResizeHandler === "function") {
                chart.windowResizeHandler();
              }
              walk(child);
            });
          };
          walk(this);
        }, 60);
      });
    },
    setMoveItem(value) {
      if (this.form.enabled_move_item === value) return;
      this.form.enabled_move_item = value;
      this.loadDataAditional();
    },
    setTransactionCustomer(value) {
      if (this.form.enabled_transaction_customer === value) return;
      this.form.enabled_transaction_customer = value;
      this.loadDataAditional();
    },
    changeFilterItem() {
      this.form.item_id = null;
      this.loadDataUtilities();
    },
    searchRemoteItems(input) {
      if (input.length > 1) {
        this.loading_search = true;
        let parameters = `input=${input}`;
        this.$http
          .get(`/reports/data-table/items/?${parameters}`)
          .then((response) => {
            this.items = response.data.items;
            this.loading_search = false;

            if (this.items.length == 0) {
              this.filterItems();
            }
          });
      } else {
        this.filterItems();
      }
    },
    filterItems() {
      this.items = this.all_items;
    },
    calculateTotalCurrency(currency_type_id, exchange_rate_sale, total) {
      if (currency_type_id == "USD") {
        return parseFloat(total) * exchange_rate_sale;
      } else {
        return parseFloat(total);
      }
    },
    clickDownloadDispatch(download) {
      window.open(download, "_blank");
    },
    clickDownload(type) {
      let query = queryString.stringify({
        ...this.form,
      });
      window.open(`/reports/no_paid/${type}/?${query}`, "_blank");
    },
    initForm() {
      this.form = {
        item_id: null,
        establishment_id: null,
        enabled_expense: null,
        enabled_move_item: false,
        enabled_transaction_customer: false,
        period: "last_week",
        date_start: moment().subtract(7, 'days').format("YYYY-MM-DD"),
        date_end: moment().format("YYYY-MM-DD"),
        month_start: moment().format("YYYY-MM"),
        month_end: moment().format("YYYY-MM"),
        customer_id: null,
      };
    },
    changeDisabledDates() {
      if (this.form.date_end < this.form.date_start) {
        this.form.date_end = this.form.date_start;
      }
      this.loadAll();
    },
    changeDisabledMonths() {
      if (this.form.month_end < this.form.month_start) {
        this.form.month_end = this.form.month_start;
      }
      this.loadAll();
    },
    changePeriod() {
      this.heroUnit = null;
      if (this.form.period === "month") {
        this.form.month_start = moment().format("YYYY-MM");
        this.form.month_end = moment().format("YYYY-MM");
      }
      if (this.form.period === "between_months") {
        this.form.month_start = moment().startOf("year").format("YYYY-MM"); //'2019-01';
        this.form.month_end = moment().endOf("year").format("YYYY-MM");
      }
      if (this.form.period === "date") {
        this.form.date_start = moment().format("YYYY-MM-DD");
        this.form.date_end = moment().format("YYYY-MM-DD");
      }
      if (this.form.period === "between_dates") {
        this.form.date_start = moment().startOf("month").format("YYYY-MM-DD");
        this.form.date_end = moment().endOf("month").format("YYYY-MM-DD");
      }
      this.loadAll();
    },
    loadAll() {
      if (!this.companyLoaded) this.loadCompany();
      this.changeStock();
      return Promise.all([this.loadData(), this.loadDataAditional(), this.loadDataUtilities(), this.loadInsights()]);
    },
    changeStock() {
      // Deferred so the stock child (created during the first render) is already listening.
      const cached = this.cachePart("stock");
      this.$nextTick(() => this.$eventHub.$emit("changeStock", this.form.establishment_id, cached));
    },
    loadCompany() {
      const seq = ++this.requestSeq.company;
      return this.$http
        .get(`/companies/record`)
        .then((response) => {
          if (seq !== this.requestSeq.company) return;
          this.company = (response && response.data && response.data.data) || {};
          this.companyLoaded = true;
        })
        .catch(() => {
          if (seq === this.requestSeq.company) this.company = {};
        });
    },
    initLoaders() {
      this.loaders = {
        document: true,
        sale_note: true,
        general: true,
        balance: true,
        utility: true,
        purchase: true,
        items_by_sales: true,
        top_customers: true,
        insights: true,
      };
    },
    showLoadersLoadData() {
      this.loaders.document = true;
      this.loaders.sale_note = true;
      this.loaders.general = true;
      this.loaders.balance = true;
    },
    hideLoadersLoadData() {
      this.loaders.document = false;
      this.loaders.sale_note = false;
      this.loaders.general = false;
      this.loaders.balance = false;
    },
    loadData() {
      const key = this.cacheKey();
      const cached = this.cachePart("data");
      if (cached) this.applyData(cached);
      else this.showLoadersLoadData();
      const seq = ++this.requestSeq.data;

      return this.$http
        .post(`/${this.resource}/data`, this.form)
        .then((response) => {
          if (seq !== this.requestSeq.data) return;
          const data = (response && response.data && response.data.data) || {};
          this.applyData(data);
          this.cacheWrite(key, { data });
          this.refreshChartSizes();
        })
        .catch(() => {
          if (seq !== this.requestSeq.data || cached) return;
          this.document = withDefaults(null);
          this.balance = withDefaults(null);
          this.sale_note = withDefaults(null);
          this.general = withDefaults(null);
          this.errors.general = true;
          this.hideLoadersLoadData();
        });
    },
    loadDataAditional() {
      const key = this.cacheKey();
      const cached = this.cachePart("additional");
      if (cached) this.applyAdditional(cached);
      else this.showLoadersLoadDataAditional();
      const seq = ++this.requestSeq.additional;

      return this.$http
        .post(`/${this.resource}/data_aditional`, this.form)
        .then((response) => {
          if (seq !== this.requestSeq.additional) return;
          const data = (response && response.data && response.data.data) || {};
          this.applyAdditional(data);
          this.cacheWrite(key, { additional: data });
          this.refreshChartSizes();
        })
        .catch(() => {
          if (seq !== this.requestSeq.additional || cached) return;
          this.purchase = withDefaults(null);
          this.items_by_sales = [];
          this.top_customers = [];
          this.errors.additional = true;
          this.hideLoadersLoadDataAditional();
        });
    },
    setHeroUnit(unit) {
      this.heroUnit = unit;
    },
    loadInsights() {
      const key = this.cacheKey();
      const cached = this.cachePart("insights");
      if (cached) this.applyInsights(cached);
      else this.loaders.insights = true;
      const seq = ++this.requestSeq.insights;

      return this.$http
        .post(`/${this.resource}/insights`, this.form)
        .then((response) => {
          if (seq !== this.requestSeq.insights) return;
          const data = (response && response.data && response.data.data) || null;
          this.applyInsights(data);
          if (data) this.cacheWrite(key, { insights: data });
          this.refreshChartSizes();
        })
        .catch(() => {
          if (seq !== this.requestSeq.insights || cached) return;
          this.insights = null;
          this.errors.insights = true;
          this.loaders.insights = false;
        });
    },
    loadDataUtilities() {
      const key = this.cacheKey();
      const cached = this.cachePart("utilities");
      if (cached) this.applyUtilities(cached);
      else this.loaders.utility = true;
      const seq = ++this.requestSeq.utility;

      return this.$http
        .post(`/${this.resource}/utilities`, this.form)
        .then((response) => {
          if (seq !== this.requestSeq.utility) return;
          const data = (response && response.data && response.data.data) || {};
          this.applyUtilities(data);
          this.cacheWrite(key, { utilities: data });
          this.refreshChartSizes();
        })
        .catch(() => {
          if (seq !== this.requestSeq.utility || cached) return;
          this.utilities = withDefaults(null);
          this.errors.utility = true;
          this.loaders.utility = false;
        });
    },
    showLoadersLoadDataAditional() {
      this.loaders.purchase = true;
      this.loaders.items_by_sales = true;
      this.loaders.top_customers = true;
    },
    hideLoadersLoadDataAditional() {
      this.loaders.purchase = false;
      this.loaders.items_by_sales = false;
      this.loaders.top_customers = false;
    },
  },
};
</script>
