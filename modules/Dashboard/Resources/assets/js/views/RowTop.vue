<template>
  <div>
    <div v-if="company && company.certificate_due" class="td-cert" :class="{ 'is-due-warning': isDueWarning }" role="status">
      <strong>Certificado digital</strong>
      <span>Vence el {{ company.certificate_due }}</span>
    </div>

    <section class="td-kpis td-num" :style="{ '--n': kpis.length }" aria-label="Indicadores generales">
      <div v-for="kpi in kpis" :key="kpi.key" class="td-panel td-kpi" :style="{ '--k': kpi.color }">
        <div class="td-kt">
          <span>{{ kpi.title }}</span>
          <strong :title="kpi.value"><span class="td-full">{{ kpi.value }}</span><span class="td-short" aria-hidden="true">{{ kpi.short }}</span></strong>
          <small v-if="kpi.delta" :class="'is-' + kpi.delta.tone" :title="kpi.delta.hint">{{ kpi.delta.text }}</small>
          <small v-else-if="kpi.note" class="is-flat">{{ kpi.note }}</small>
        </div>
        <div class="td-mc">
          <apexchart v-if="kpi.ring" type="radialBar" width="72" height="72" :options="kpi.ring.options" :series="kpi.ring.series"></apexchart>
          <apexchart v-else-if="kpi.chart" type="bar" width="100%" height="60" :options="kpi.chart.options" :series="kpi.chart.series"></apexchart>
          <i v-else class="td-mc-empty" aria-hidden="true"></i>
        </div>
        <p class="td-cap">{{ kpi.caption }}</p>
      </div>
    </section>
  </div>
</template>

<script>
import moment from "moment";
import { C, num, money, compact, pct, compare, miniBars, miniRing } from "./charts";

const KPI_BARS = 12;
const UNIT_TEXT = { hour: "Por hora", day: "Por día", week: "Por semana", month: "Por mes" };

export default {
  props: ["company", "utilities", "general", "document", "sale_note", "soapCompany", "insights", "initialGlobal", "skipGlobalFetch"],
  data() {
    const initial = this.initialGlobal || {};
    return {
      document_total_global: initial.document_total_global || 0,
      total_cpe: initial.total_cpe || 0,
      sale_note_total_global: initial.sale_note_total_global || 0,
    };
  },
  mounted() {
    if (!this.skipGlobalFetch) this.onFetchData();
  },
  computed: {
    total() {
      return num(this.document_total_global) + num(this.sale_note_total_global);
    },
    isDueWarning() {
      if (this.company && this.company.certificate_due) {
        const dueDate = moment(this.company.certificate_due);
        const diffInDays = dueDate.diff(moment(), "days");
        return diffInDays <= 15;
      }
      return false;
    },
    // Bucket unit for the mini charts: as fine as the period allows while keeping a dozen readable bars.
    kpiUnit() {
      const meta = (this.insights && this.insights.meta) || {};
      const series = (this.insights && this.insights.series) || {};
      if (series.hour) return "hour";
      const days = num(meta.days);
      const preferred = days <= 45 ? "day" : days <= 182 ? "week" : "month";
      return [preferred, "day", "week", "month"].find((unit) => series[unit]) || null;
    },
    kpis() {
      const ins = this.insights || {};
      const unit = this.kpiUnit;
      const set = unit && ins.series ? ins.series[unit] : null;
      const unitText = unit ? UNIT_TEXT[unit] : "Por periodo";
      const totals = ins.totals || {};
      const cur = totals.current || null;
      const prev = totals.previous || null;
      const hint = ins.meta && ins.meta.labels ? "Periodo seleccionado " + ins.meta.labels.compare : "";
      const delta = (key) => {
        const d = cur && prev ? compare(cur[key], prev[key]) : null;
        return d ? Object.assign({ hint }, d) : null;
      };
      const values = (key) => (set ? (set.current[key] || []).slice(-KPI_BARS).map((v) => Math.max(0, num(v))) : []);
      const bars = (key, color, soft) => {
        const data = values(key);
        return data.some((v) => v > 0) ? miniBars(data, color, soft) : null;
      };

      const docTotals = (this.document && this.document.totals) || {};
      const noteTotals = (this.sale_note && this.sale_note.totals) || {};
      const utility = (this.utilities && this.utilities.totals) || {};

      let bestText = "";
      if (set) {
        const all = (set.current.total || []).map(num);
        const peak = all.length ? Math.max.apply(null, all) : 0;
        if (peak > 0) bestText = "mejor: " + set.labels[all.indexOf(peak)];
      }
      const income = num(utility.total_income);
      const margin = income > 0 ? Math.round((num(utility.utility) / income) * 100) : null;
      // Compact form of a money figure, shown instead of the full one when the card is too narrow for it.
      const short = (value) => "S/ " + compact(value);
      const caption = (extra) => (extra ? unitText + " · " + extra : unitText);

      const list = [];
      if (this.soapCompany != "03") {
        list.push({
          key: "cpe", title: "CPE emitidos", color: C.blue, value: String(num(this.total_cpe)), short: String(num(this.total_cpe)),
          delta: delta("count"), chart: bars("count", C.blue, C.blueSoft),
          caption: caption(cur ? cur.count + " en el periodo" : ""),
        });
        list.push({
          key: "documents", title: "Monto total comprobantes", color: C.teal, value: money(this.document_total_global), short: short(this.document_total_global),
          delta: delta("docs"), chart: bars("docs", C.teal, C.tealSoft),
          caption: caption(num(docTotals.total) > 0 ? pct(docTotals.total_payment, docTotals.total) + "% ya cobrado" : ""),
        });
      }
      list.push({
        key: "notes", title: "Monto total notas de venta", color: C.violet, value: money(this.sale_note_total_global), short: short(this.sale_note_total_global),
        delta: delta("notes"), chart: bars("notes", C.violet, C.violetSoft),
        caption: caption(num(noteTotals.total) > 0 ? pct(noteTotals.total_payment, noteTotals.total) + "% pagado" : ""),
      });
      list.push({
        key: "total", title: "Monto total general", color: C.amber, value: money(this.total), short: short(this.total),
        delta: delta("total"), chart: bars("total", C.amber, C.amberSoft), caption: caption(bestText),
      });
      list.push({
        key: "utility", title: "Utilidad neta", color: C.green, value: money(utility.utility), short: short(utility.utility),
        delta: null, note: margin === null ? "" : margin + "% de margen",
        // Daily profit is not available (cost is resolved per item), so the ring shows the margin over sales.
        ring: margin !== null && margin > 0 ? miniRing(margin, C.green) : null,
        caption: margin === null ? "Ingreso, egreso y utilidad" : "Utilidad sobre las ventas del periodo",
      });
      return list;
    },
  },
  methods: {
    applyGlobal(data) {
      if (!data) return;
      this.document_total_global = data.document_total_global || 0;
      this.total_cpe = data.total_cpe || 0;
      this.sale_note_total_global = data.sale_note_total_global || 0;
    },
    onFetchData() {
      this.$http
        .get("/dashboard/global-data")
        .then((response) => {
          const data = (response && response.data) || {};
          this.applyGlobal(data);
          this.$emit("global-loaded", data);
        })
        .catch(() => {
          this.document_total_global = 0;
          this.total_cpe = 0;
          this.sale_note_total_global = 0;
        });
    },
  },
};
</script>
