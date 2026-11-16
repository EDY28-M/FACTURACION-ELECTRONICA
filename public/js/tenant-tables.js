/*
 * Tenant portal tables: each header cell takes the text alignment of the body
 * cells below it, so titles sit over their column whatever the data type
 * (text left, badges and buttons centred, amounts right).
 *
 * Runs after every DOM render (Vue updates, column toggles, dialogs, shell
 * navigation). Writes inline styles on <th> only and observes childList
 * changes, so it cannot trigger itself.
 */
(function () {
    "use strict";

    if (window.__tenantTablesBound) return;
    window.__tenantTablesBound = true;

    var observed = null;
    var frame = null;

    function sampleRow(body, columns) {
        if (!body) return null;
        for (var r = 0; r < body.rows.length; r++) {
            var row = body.rows[r];
            if (row.cells.length !== columns) continue;
            var spans = row.querySelector("[colspan]");
            if (!spans) return row;
        }
        return null;
    }

    // Plural only: "Acción" is also used as a data column title (Documentary > Actions).
    var ACTIONS_TITLE = /^\s*(acciones|opciones)\s*$/i;

    function markActionColumn(body, th, index, columns) {
        if (!th.hasAttribute("data-tn-actions")) th.setAttribute("data-tn-actions", "");
        if (!body) return;
        for (var r = 0; r < body.rows.length; r++) {
            var cells = body.rows[r].cells;
            if (cells.length !== columns) continue;
            if (!cells[index].hasAttribute("data-tn-actions")) {
                cells[index].setAttribute("data-tn-actions", "");
            }
        }
    }

    function alignTable(table) {
        var head = table.tHead;
        var headRow = head && head.rows[0];
        if (!headRow) return;

        var columns = headRow.cells.length;
        var row = sampleRow(table.tBodies[0], columns);
        if (!row) return;

        for (var i = 0; i < columns; i++) {
            var th = headRow.cells[i];
            if (th.hasAttribute("colspan")) continue;
            // Action columns hug their buttons (see tenant-theme.css) instead of
            // stretching to the table edge. Attributes, not classes: Vue owns those.
            if (ACTIONS_TITLE.test(th.textContent) && i > 0) {
                markActionColumn(table.tBodies[0], th, i, columns);
            }
            var align = window.getComputedStyle(row.cells[i]).textAlign;
            // Bootstrap's .text-center/.text-right on <th> are !important, so an
            // inline value only wins when it is !important too.
            if (
                th.style.getPropertyValue("text-align") !== align ||
                th.style.getPropertyPriority("text-align") !== "important"
            ) {
                th.style.setProperty("text-align", align, "important");
            }
        }
    }

    function alignAll() {
        frame = null;
        var tables = document.body.querySelectorAll("table");
        for (var t = 0; t < tables.length; t++) alignTable(tables[t]);
    }

    function schedule() {
        if (frame !== null) return;
        frame = window.requestAnimationFrame(alignAll);
    }

    function attach() {
        // Observe the whole body: dialogs mounted outside #main-wrapper
        // (append-to-body) also contain tables.
        if (observed !== document.body) {
            observer.disconnect();
            observer.observe(document.body, { childList: true, subtree: true });
            observed = document.body;
        }
        schedule();
    }

    var observer = new MutationObserver(schedule);

    window.addEventListener("tenant:navigated", attach);
    window.addEventListener("resize", schedule);

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", attach);
    } else {
        attach();
    }
})();
