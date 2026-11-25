/**
 * Keeps the SUNAT environment pill of the header (Demo / Producción / Interno) in sync with the
 * company record, which both the system admin panel and the company settings write to.
 */
(function () {
    "use strict";
    var script = document.currentScript;
    var url = script && script.getAttribute("data-url");
    if (!url) return;

    var ENVS = {
        "01": { key: "demo", label: "SUNAT · Demo", title: "SUNAT: entorno de demostración" },
        "02": { key: "prod", label: "SUNAT · Producción", title: "SUNAT: entorno de producción" },
        "03": { key: "int", label: "SUNAT · Interno", title: "Entorno interno" }
    };
    var busy = false;

    function apply(type, missing) {
        var env = ENVS[type] || ENVS["03"];
        if (type === "02" && missing && missing.length) {
            env = { key: "warn", label: "SUNAT · Producción incompleta", title: "Para emitir en producción falta: " + missing.join(", ") };
        }
        var pill = document.querySelector(".header .tn-env");
        var stamp = env.key + "|" + env.title;
        if (!pill || pill.getAttribute("data-env") === stamp) return;
        var clickable = pill.tagName === "A";
        pill.className = "tn-env tn-env--" + env.key;
        pill.setAttribute("data-env", stamp);
        pill.textContent = env.label;
        pill.title = env.title + (clickable ? ", pulse para ir a configuración" : "");
    }

    function refresh() {
        if (busy || document.hidden) return;
        busy = true;
        fetch(url, { credentials: "same-origin", headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) { if (d && d.soap_type_id) apply(d.soap_type_id, d.missing); })
            .catch(function () {})
            .then(function () { busy = false; });
    }

    // Tag the server-rendered pill so a later refresh can tell whether anything changed.
    var first = document.querySelector(".header .tn-env");
    if (first) {
        var m = /tn-env--(\w+)/.exec(first.className);
        if (m) first.setAttribute("data-env", m[1] + "|" + first.getAttribute("title").replace(/, pulse para ir a configuración$/, ""));
    }

    setInterval(refresh, 30000);
    document.addEventListener("visibilitychange", refresh);
    window.addEventListener("focus", refresh);

    // The company saves its own settings through POST /companies: refresh right after.
    var origOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (method, reqUrl) {
        if (String(method).toUpperCase() === "POST" && /\/companies(\?|$)/.test(String(reqUrl))) {
            this.addEventListener("loadend", refresh);
        }
        return origOpen.apply(this, arguments);
    };
})();
