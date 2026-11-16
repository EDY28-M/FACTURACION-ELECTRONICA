const assert = require("assert");
const fs = require("fs");
const vm = require("vm");
const compiler = require("vue-template-compiler");
const component = compiler.parseComponent(
    fs.readFileSync("resources/js/views/system/clients/index.vue", "utf8")
);
assert.deepStrictEqual(
    compiler.compile(component.template.content).errors,
    [],
    "Dashboard template must compile"
);
const script = component.script.content
    .replace(/^import .*;\n/gm, "")
    .replace("export default", "module.exports =");
const sandbox = {
    module: { exports: {} },
    deletable: {},
    changeable: {},
    CompaniesForm: {},
    ClientPayments: {},
    AccountStatus: {}
};
vm.runInNewContext(script, sandbox);
const options = sandbox.module.exports;
const context = {
    records: [
        {
            id: 1,
            name: "Alpha",
            number: "20111111111",
            hostname: "alpha.localhost",
            soap_type: "01",
            count_doc: "12",
            count_user: "3"
        },
        {
            id: 3,
            name: "Beta",
            number: "20222222222",
            hostname: "beta.localhost",
            soap_type: "02",
            count_doc: 8,
            count_user: 2
        },
        {
            id: 2,
            name: "Internal",
            number: "20333333333",
            hostname: "internal.localhost",
            soap_type: "03",
            count_doc: 0,
            count_user: 1
        }
    ],
    search: "",
    chartMonths: ["Jan", "Feb"],
    dataChartLine: { datasets: [{ data: [0, 11] }] }
};
Object.keys(options.methods).forEach(name => {
    context[name] = options.methods[name].bind(context);
});
Object.keys(options.computed).forEach(name =>
    Object.defineProperty(context, name, {
        get: options.computed[name].bind(context)
    })
);
assert.strictEqual(
    context.documentsCount,
    20,
    "Counts must add numeric API values, not concatenate"
);
assert.strictEqual(context.usersCount, 6);
assert.strictEqual(context.demoCount, 1);
assert.strictEqual(context.productionCount, 1);
assert.strictEqual(context.internalCount, 1);
assert.deepStrictEqual(Array.from(context.recentRecords, row => row.id), [
    3,
    2,
    1
]);
assert.deepStrictEqual(
    context.records.map(row => row.id),
    [1, 3, 2],
    "Recent sorting must preserve source data"
);
context.search = " ALPHA.LOCALHOST ";
assert.strictEqual(context.filteredRecords.length, 1);
context.search = "20222222222";
assert.strictEqual(context.filteredRecords[0].name, "Beta");
context.search = "missing";
assert.strictEqual(context.filteredRecords.length, 0);
assert.strictEqual(context.resourceRing("180%"), "157 157");
assert.strictEqual(context.resourceRing("-1%"), "0 157");
assert.strictEqual(context.resourceRing("unavailable"), "0 157");
assert.strictEqual(context.chartValue(20), 0);
assert.strictEqual(context.chartHeight(1), (11 / 12) * 100 + "%");
context.records = [];
assert.strictEqual(context.donutDemo, "0 264");
assert.strictEqual(context.donutProduction, "0 264");
assert.strictEqual(context.documentsCount, 0);
assert.strictEqual(context.environmentLabel("03"), "Interno");
console.log(
    "System dashboard: template and data/empty-state assertions passed."
);

async function testChartRequests() {
    const pending = [];
    const chart = {
        resource: "clients", year: 2025, chartRequest: 0,
        dataChartLine: { datasets: [{ data: [] }] },
        $http: { get: (url, config) => new Promise((resolve, reject) => pending.push({ resolve, reject, config })) }
    };
    const first = options.methods.loadChart.call(chart);
    chart.year = 2026;
    const second = options.methods.loadChart.call(chart);
    pending[1].resolve({ data: { line: { labels: ["Ene"], data: [26] }, total_documents: 26 } });
    await second;
    pending[0].resolve({ data: { line: { labels: ["Ene"], data: [25] }, total_documents: 25 } });
    await first;
    assert.strictEqual(pending[0].config.params.year, 2025);
    assert.strictEqual(chart.total_documents, 26, "Stale year response must not replace current totals");
    const third = options.methods.loadChart.call(chart);
    const fourth = options.methods.loadChart.call(chart);
    pending[3].resolve({ data: { line: { labels: ["Ene"], data: [27] }, total_documents: 27 } });
    await fourth;
    pending[2].reject(new Error("Stale request"));
    await third;
    assert.strictEqual(chart.chartError, false, "Stale error must not replace a successful current chart");
    assert.strictEqual(chart.total_documents, 27);
    assert.strictEqual(chart.loaded, true);
    assert.strictEqual(context.resourceAvailable("0"), false);
    assert.strictEqual(context.resourceAvailable("0%"), true);
    assert(component.template.content.includes('loaded && !chartError && !total_documents'), "Ghost bars must only appear for a wholly empty year");
    console.log("Chart request race, ghost-bar and resource availability assertions passed.");
}

async function testSystemShellNavigation() {
    const shellCode = fs.readFileSync("public/js/system-shell.js", "utf8");
    const appCode = fs.readFileSync("resources/js/app.js", "utf8");
    assert(appCode.includes("window.unmountSystemVue = function unmountSystemVue()"), "app.js must expose window.unmountSystemVue");
    assert(appCode.includes("Vue.prototype.$eventHub.$off()"), "unmountSystemVue must clear global $eventHub listeners");
    assert(appCode.includes("ElementUI.MessageBox.close()"), "unmountSystemVue must close any open ElementUI MessageBox");
    assert(appCode.includes("window.mountSystemVue = function mountSystemVue("), "app.js must expose window.mountSystemVue");

    function makeClassList(initial = []) {
        const set = new Set(initial);
        return {
            add: c => set.add(c),
            remove: c => set.delete(c),
            contains: c => set.has(c),
            toggle: (c, force) => {
                const next = typeof force === "boolean" ? force : !set.has(c);
                if (next) set.add(c);
                else set.delete(c);
                return next;
            }
        };
    }

    function makeLink(href, segment, extraAttrs = {}) {
        const attrs = Object.assign({ href, "data-nav-segment": segment }, extraAttrs);
        return {
            classList: makeClassList(segment === "dashboard" ? ["is-active"] : []),
            getAttribute: k => (k in attrs ? attrs[k] : null),
            setAttribute: (k, v) => { attrs[k] = String(v); },
            removeAttribute: k => { delete attrs[k]; },
            hasAttribute: k => k in attrs,
            closest: sel => (sel === "a[href]" ? this : null)
        };
    }

    const navLinks = [
        makeLink("http://localhost:8080/dashboard", "dashboard", { "aria-current": "page" }),
        makeLink("http://localhost:8080/clients", "clients"),
        makeLink("http://localhost:8080/plans", "plans"),
        makeLink("http://localhost:8080/logs", "logs"),
        makeLink("http://localhost:8080/users/create", "users")
    ];
    navLinks.forEach(l => { l.closest = sel => (sel === "a[href]" ? l : null); });

    const mainWrapper = { id: "main-wrapper", innerHTML: "<system-clients-index></system-clients-index>" };
    const navCount = { textContent: "3" };
    const certNode = { innerHTML: "old", attrs: {}, getAttribute: function(k) { return this.attrs[k]; }, setAttribute: function(k, v) { this.attrs[k] = v; } };
    const searchInput = { value: "" };
    const docListeners = {};
    const winListeners = {};
    const lifecycleOrder = [];
    const pushedStates = [];

    let logsRoot = null;
    const fetchQueue = [];

    const documentMock = {
        title: "Facturación Electrónica",
        documentElement: { classList: makeClassList(["dashboard-system"]) },
        body: { style: {} },
        querySelector: sel => {
            if (sel === ".system-nav-count") return navCount;
            if (sel === ".system-certificate") return certNode;
            if (sel === '.system-search input[name="search"]') return searchInput;
            return null;
        },
        querySelectorAll: sel => {
            if (sel === ".system-sidebar .system-nav-link, .system-sidebar .system-user-link") return navLinks;
            return [];
        },
        getElementById: id => {
            if (id === "main-wrapper") return mainWrapper;
            if (id === "system-logs") return logsRoot;
            return null;
        },
        addEventListener: (type, fn) => {
            docListeners[type] = docListeners[type] || [];
            docListeners[type].push(fn);
        }
    };

    const windowMock = {
        location: {
            origin: "http://localhost:8080",
            href: "http://localhost:8080/dashboard",
            pathname: "/dashboard",
            search: "",
            hash: ""
        },
        innerWidth: 1280,
        localStorage: {
            store: {},
            getItem(k) { return this.store[k] || null; },
            setItem(k, v) { this.store[k] = String(v); }
        },
        history: {
            pushState(state, title, url) {
                pushedStates.push({ type: "push", url });
                const parsed = new URL(url, "http://localhost:8080");
                windowMock.location.pathname = parsed.pathname;
                windowMock.location.search = parsed.search;
                windowMock.location.hash = parsed.hash;
                windowMock.location.href = parsed.href;
                lifecycleOrder.push("pushState:" + url);
            },
            replaceState(state, title, url) {
                pushedStates.push({ type: "replace", url });
            }
        },
        scrollTo: () => lifecycleOrder.push("scrollTo"),
        addEventListener: (type, fn) => {
            winListeners[type] = winListeners[type] || [];
            winListeners[type].push(fn);
        },
        unmountSystemVue: () => lifecycleOrder.push("unmountVue"),
        mountSystemVue: () => lifecycleOrder.push("mountVue:" + windowMock.location.pathname),
        fetch: (url, opts) => new Promise((resolve, reject) => {
            fetchQueue.push({ url, opts, resolve, reject });
        })
    };

    class MockAbortController {
        constructor() {
            this.aborted = false;
            this.signal = { aborted: false };
        }
        abort() {
            this.aborted = true;
            this.signal.aborted = true;
        }
    }

    class MockDOMParser {
        parseFromString(html) {
            return {
                title: "Facturación Electrónica - Planes",
                getElementById: id => (id === "main-wrapper" ? { innerHTML: html } : null),
                querySelector: sel => {
                    if (sel === ".system-nav-count") return { textContent: "5" };
                    if (sel === ".system-certificate") return { innerHTML: "new-cert", getAttribute: () => "Certificado PSE: configurado" };
                    return null;
                }
            };
        }
    }

    const shellSandbox = {
        window: windowMock,
        document: documentMock,
        URL,
        Intl,
        Date,
        AbortController: MockAbortController,
        DOMParser: MockDOMParser
    };
    vm.runInNewContext(shellCode, shellSandbox);

    assert.strictEqual(typeof windowMock.systemShellNavigate, "function", "system-shell.js must expose systemShellNavigate");
    assert.strictEqual(typeof windowMock.initSystemLogs, "function", "system-shell.js must expose initSystemLogs");

    // 1. Intercept sidebar click and verify abort on rapid navigation + lifecycle order
    let prevented = false;
    docListeners.click[0]({
        defaultPrevented: false,
        button: 0,
        target: navLinks[1],
        preventDefault: () => { prevented = true; }
    });
    assert.strictEqual(prevented, true, "Click on /clients sidebar link must be intercepted");
    assert.strictEqual(fetchQueue.length, 1);

    // Rapid second click to /plans must abort the in-flight /clients request
    const secondNav = windowMock.systemShellNavigate("http://localhost:8080/plans");
    assert.strictEqual(fetchQueue.length, 2);
    assert.strictEqual(fetchQueue[0].opts.signal.aborted, true, "Previous in-flight navigation must be aborted");

    fetchQueue[1].resolve({
        ok: true,
        redirected: false,
        headers: { get: name => (name.toLowerCase() === "content-type" ? "text/html; charset=UTF-8" : null) },
        text: () => Promise.resolve("<system-plans-index></system-plans-index>")
    });
    await secondNav;

    assert.strictEqual(mainWrapper.innerHTML, "<system-plans-index></system-plans-index>");
    assert.strictEqual(navCount.textContent, "5", "Sidebar client count badge must sync from response");
    assert.strictEqual(navLinks[2].classList.contains("is-active"), true, "/plans link must be marked active");
    assert.strictEqual(navLinks[2].getAttribute("aria-current"), "page");
    assert.strictEqual(navLinks[0].classList.contains("is-active"), false, "/dashboard link must no longer be active");
    assert.strictEqual(navLinks[0].getAttribute("aria-current"), null);
    assert.deepStrictEqual(
        lifecycleOrder,
        ["unmountVue", "pushState:/plans", "mountVue:/plans", "scrollTo"],
        "History pushState must happen after unmountVue and BEFORE mountVue so component data() reads updated window.location"
    );

    // 2. Non-shell or [data-confirm] / download / /logs?dl= links must NOT be intercepted
    let confirmPrevented = false;
    const confirmLink = makeLink("http://localhost:8080/logs?del=abc", "logs", { "data-confirm": "Delete?" });
    confirmLink.closest = () => confirmLink;
    docListeners.click[0]({
        defaultPrevented: false,
        button: 0,
        target: confirmLink,
        preventDefault: () => { confirmPrevented = true; }
    });
    assert.strictEqual(confirmPrevented, false, "Links with [data-confirm] must not be intercepted by shell router");

    let dlPrevented = false;
    const dlLink = makeLink("http://localhost:8080/logs?dl=encryptedfile", "logs");
    dlLink.closest = () => dlLink;
    docListeners.click[0]({
        defaultPrevented: false,
        button: 0,
        target: dlLink,
        preventDefault: () => { dlPrevented = true; }
    });
    assert.strictEqual(dlPrevented, false, "Log download links (?dl=) must not be intercepted by shell router");

    // 3. popstate must navigate without pushing a duplicate history entry
    const pushedCountBeforePop = pushedStates.length;
    windowMock.location.href = "http://localhost:8080/dashboard";
    windowMock.location.pathname = "/dashboard";
    winListeners.popstate[0]();
    fetchQueue[fetchQueue.length - 1].resolve({
        ok: true,
        redirected: false,
        text: () => Promise.resolve("<system-clients-index></system-clients-index>")
    });
    await new Promise(r => setTimeout(r, 10));
    assert.strictEqual(pushedStates.length, pushedCountBeforePop, "popstate navigation must not call pushState");
    assert.strictEqual(navLinks[0].classList.contains("is-active"), true, "popstate must restore active nav item");

    // 4. initSystemLogs idempotency guard
    let inputListenersCount = 0;
    const mockInput = { value: "", addEventListener: () => { inputListenersCount++; } };
    logsRoot = {
        dataset: {},
        querySelectorAll: () => [],
        querySelector: () => null
    };
    const origGetById = documentMock.getElementById;
    documentMock.getElementById = id => {
        if (id === "system-logs") return logsRoot;
        if (["log-search", "log-type", "log-from", "log-to"].includes(id)) return mockInput;
        return origGetById(id);
    };
    windowMock.initSystemLogs();
    windowMock.initSystemLogs();
    assert.strictEqual(inputListenersCount, 4, "initSystemLogs must be idempotent and attach listeners only once per #system-logs element");

    console.log("System shell navigation, lifecycle order, abort, popstate, and log initializer assertions passed.");
}

async function testTenantShellAndCertificate() {
    const tenantShellCode = fs.readFileSync("public/js/tenant-shell.js", "utf8");
    const tenantThemeCss = fs.readFileSync("public/css/tenant-theme.css", "utf8");
    const tenantLayoutBlade = fs.readFileSync("resources/views/tenant/layouts/app.blade.php", "utf8");
    const tenantHeaderBlade = fs.readFileSync("resources/views/tenant/layouts/partials/header.blade.php", "utf8");

    assert(tenantLayoutBlade.includes("css/tenant-theme.css"), "Tenant layout must load public/css/tenant-theme.css");
    assert(tenantLayoutBlade.includes("js/tenant-shell.js"), "Tenant layout must load public/js/tenant-shell.js");
    const vendorPos = tenantLayoutBlade.indexOf("js/vendor.js");
    const appPos = tenantLayoutBlade.indexOf("mix('js/app.js')");
    const shellPos = tenantLayoutBlade.indexOf("js/tenant-shell.js");
    assert(vendorPos > 0 && appPos > vendorPos && shellPos > appPos, "Tenant layout must load vendor.js -> app.js -> tenant-shell.js in deterministic order");
    assert(tenantHeaderBlade.includes("data-no-shell"), "Tenant header must mark non-shell action links with data-no-shell");

    assert(tenantThemeCss.includes("html.tenant-nav-loading::before"), "tenant-theme.css must define top loading progress indicator");
    assert(tenantThemeCss.includes("font-variant-numeric: tabular-nums"), "tenant-theme.css must enforce tabular-nums");
    assert(tenantThemeCss.includes("html.dark:not(.dashboard-system)"), "tenant-theme.css must support dark mode tokens");
    [
        "html.dark:not(.dashboard-system) .el-table",
        "html.dark:not(.dashboard-system) .el-input__inner",
        "html.dark:not(.dashboard-system) .form-control",
        "html.dark:not(.dashboard-system) .el-dialog__body",
        "html.dark:not(.dashboard-system) .card",
        "html.dark:not(.dashboard-system) .table > thead > tr > th",
        "html.dark:not(.dashboard-system) .table > tbody > tr > td",
        "html #sidebar-left.rail",
        "html.rail-mini:not(.rail-peek) #sidebar-left",
        "html.rail-peek #sidebar-left.rail",
        "html.no-trans #sidebar-left.rail",
        "html .header.tn-top",
        "html.dark .header.tn-top",
        "html .tn-content .page-header"
    ].forEach(selector => {
        assert(tenantThemeCss.includes(selector), `tenant-theme.css must define ${selector}`);
    });
    assert(!/logo-container|topbar-links|sidebar-left-collapsed|data-short/.test(tenantThemeCss), "tenant-theme.css must not carry the old Porto shell restyle");
    const tenantRailCode = fs.readFileSync("public/js/tenant-rail.js", "utf8");
    const tenantSidebarBlade = fs.readFileSync("resources/views/tenant/layouts/partials/sidebar.blade.php", "utf8");
    assert(tenantLayoutBlade.includes("js/tenant-rail.js"), "Tenant layout must load public/js/tenant-rail.js");
    assert(tenantLayoutBlade.indexOf("js/tenant-rail.js") > shellPos, "tenant-rail.js must load after tenant-shell.js");
    assert(tenantLayoutBlade.includes("tenantRailMini"), "Tenant layout must set the initial rail state before first paint");
    assert(!/class="[^"]*\b(fixed|sidebar-left-collapsed|sidebar-light)\b/.test(tenantLayoutBlade.split("<head>")[0]), "Tenant <html> must not carry Porto layout classes");
    assert(tenantRailCode.includes("__tenantRailBound"), "tenant-rail.js must bind once and stay idempotent");
    ["id=\"sidebar-left\"", "class=\"nav-main\"", "nano-content", "nav-children", "nav-parent"].forEach(token => {
        assert(tenantSidebarBlade.includes(token), `Tenant sidebar must keep the markup contract used by tenant-shell.js: ${token}`);
    });
    assert(!/data-short|feather|fa-receipt/.test(tenantSidebarBlade), "Tenant sidebar must use sprite icons, not Porto glyphs");
    assert(!tenantThemeCss.includes("html.dashboard-system .system-dropzone"), "tenant-theme.css must not carry dead System dropzone rules");
    assert(tenantLayoutBlade.includes("data-shell-ssr"), "Tenant layout must mark server-rendered head styles");
    assert(tenantHeaderBlade.includes("data-shell-notifications"), "Tenant header must expose a stable notifications wrapper");
    assert(!tenantHeaderBlade.includes("@else # @endif"), "Tenant header must not render href=\" # \"");
    assert(!tenantShellCode.includes('$("[data-card-toggle]")'), "tenant-shell.js must not bind a duplicate [data-card-toggle] handler");
    assert(tenantShellCode.includes("data-card-bound"), "tenant-shell.js must bind Porto cards idempotently");
    assert(tenantShellCode.includes("click.tenantShellSwitcher"), "tenant-shell.js must delegate #styleSwitcherOpen with a namespaced event");

    // Verify Certificate index.vue and form.vue templates and drag-and-drop logic
    const certIndexSfc = compiler.parseComponent(fs.readFileSync("resources/js/views/system/certificate/index.vue", "utf8"));
    const certFormSfc = compiler.parseComponent(fs.readFileSync("resources/js/views/system/certificate/form.vue", "utf8"));
    assert.deepStrictEqual(compiler.compile(certIndexSfc.template.content).errors, [], "certificate/index.vue template must compile");
    assert.deepStrictEqual(compiler.compile(certFormSfc.template.content).errors, [], "certificate/form.vue template must compile");

    const certIndexScript = certIndexSfc.script.content
        .replace(/^\s*import .*$/gm, "")
        .replace("export default", "module.exports =");
    const certSandbox = { module: { exports: {} }, deletable: {}, CertificatesForm: {} };
    vm.runInNewContext(certIndexScript, certSandbox);
    const certIndexOpts = certSandbox.module.exports;

    let errorToast = null;
    const certVm = Object.assign(certIndexOpts.data(), {
        $message: { error: msg => { errorToast = msg; } }
    });
    certIndexOpts.methods.onDragOver.call(certVm);
    assert.strictEqual(certVm.isDragover, true, "onDragOver must set isDragover = true");
    certIndexOpts.methods.onDropFile.call(certVm, {
        dataTransfer: { files: [{ name: "invalid.txt" }] }
    });
    assert.strictEqual(certVm.isDragover, false);
    assert.strictEqual(certVm.showDialog, false, "Dropping non-.pfx/.p12 file must not open dialog");
    assert(errorToast && errorToast.includes(".pfx"), "Dropping invalid file must trigger error toast");

    const validPfxFile = { name: "sunat-cert.pfx", size: 2048 };
    certIndexOpts.methods.onDropFile.call(certVm, {
        dataTransfer: { files: [validPfxFile] }
    });
    assert.strictEqual(certVm.droppedFile, validPfxFile, "Dropping .pfx file must stage droppedFile");
    assert.strictEqual(certVm.showDialog, true, "Dropping .pfx file must open certificate dialog");
    assert.strictEqual(certIndexOpts.data().record, null, "certificate/index.vue must initialize record = null");

    assert(certFormSfc.template.content.includes('accept=".pfx,.p12"'), "certificate/form.vue el-upload must restrict to .pfx/.p12");
    const certFormScript = certFormSfc.script.content
        .replace(/^\s*import .*$/gm, "")
        .replace("export default", "module.exports =");
    const certFormSandbox = { module: { exports: {} }, headers_token: {} };
    vm.runInNewContext(certFormScript, certFormSandbox);
    const certFormOpts = certFormSandbox.module.exports;
    const formToasts = [];
    let httpPosted = false;
    const certFormVm = Object.assign(certFormOpts.data(), {
        $refs: {},
        $message: { error: msg => formToasts.push(msg), success: () => {} },
        $http: { post: () => { httpPosted = true; return Promise.resolve({ data: {} }); } }
    });
    certFormOpts.methods.handleFileChange.call(certFormVm, { name: "notes.txt", raw: { name: "notes.txt" } });
    assert.strictEqual(certFormVm.selectedFile, null, "handleFileChange must reject non-.pfx/.p12 files");
    assert(formToasts.length === 1, "handleFileChange must toast on invalid extension");
    certFormOpts.methods.handleFileChange.call(certFormVm, { name: "cert.P12", raw: { name: "cert.P12" } });
    assert.deepStrictEqual(certFormVm.selectedFile, { name: "cert.P12" }, "handleFileChange must accept .p12 files");
    certFormVm.selectedFile = null;
    certFormOpts.methods.clickUpload.call(certFormVm);
    assert.strictEqual(httpPosted, false, "Submitting without a file must not post");
    assert(formToasts[1] && formToasts[1].includes(".pfx"), "Submitting without a file must show an error toast");
    certFormOpts.methods.errorUpload.call(certFormVm, { response: { status: 500, data: {} } });
    assert.strictEqual(formToasts[2], "Error al subir el certificado", "Non-422 upload errors must show a generic toast");
    certFormOpts.methods.errorUpload.call(certFormVm, { response: { status: 422, data: { errors: { password: ["x"] } } } });
    assert.deepStrictEqual(certFormVm.errors, { password: ["x"] }, "422 upload errors must populate field errors");
    assert.strictEqual(formToasts.length, 3, "422 upload errors must not show a generic toast");

    // Verify Tenant Shell navigation, html.newinvoice toggling, sidebar state sync, and bypass rules
    function makeClassList(initial = []) {
        const set = new Set(initial);
        return {
            add: c => set.add(c),
            remove: c => set.delete(c),
            contains: c => set.has(c),
            toggle: (c, force) => {
                const next = typeof force === "boolean" ? force : !set.has(c);
                if (next) set.add(c);
                else set.delete(c);
                return next;
            }
        };
    }

    const liDashboard = { classList: makeClassList(["nav-active"]), querySelector: () => null };
    const liVentasParent = { classList: makeClassList(["nav-parent"]), querySelector: () => ({ style: {}, parentElement: null }) };
    const liDocCreate = { classList: makeClassList([]), querySelector: () => null };
    const currentSidebarItems = [liDashboard, liVentasParent, liDocCreate];
    const nanoContent = { scrollTop: 145 };
    const notifList = { innerHTML: "<span>0</span>" };
    const mainWrapper = {
        id: "main-wrapper",
        innerHTML: "<tenant-dashboard-index></tenant-dashboard-index>",
        querySelectorAll: () => []
    };

    const sidebarEl = {
        querySelector: sel => {
            if (sel === ".nano-content") return nanoContent;
            if (sel === "ul.nav-main") return { dataset: {}, addEventListener: () => {} };
            return null;
        },
        querySelectorAll: sel => {
            if (sel === "ul.nav-main li") return currentSidebarItems;
            return [];
        }
    };

    const headerRightEl = {
        querySelectorAll: sel => (sel === "ul.notifications" ? [notifList] : [])
    };

    function makeHeadNode(tagName, attrs = {}, text = "") {
        return {
            tagName,
            attrs: Object.assign({}, attrs),
            textContent: text,
            parentNode: null,
            getAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attrs, name) ? this.attrs[name] : null; },
            setAttribute(name, value) { this.attrs[name] = String(value); }
        };
    }
    function makeHead(nodes) {
        const head = {
            nodes: [],
            querySelectorAll() {
                return head.nodes.filter(n => n.tagName === "STYLE" || (n.tagName === "LINK" && n.attrs.rel === "stylesheet"));
            },
            appendChild(node) { node.parentNode = head; head.nodes.push(node); },
            removeChild(node) { head.nodes = head.nodes.filter(n => n !== node); node.parentNode = null; }
        };
        nodes.forEach(n => head.appendChild(n));
        return head;
    }
    const coreHeadSpecs = () => [
        makeHeadNode("LINK", { rel: "stylesheet", href: "/css/app.css?id=abc" }),
        makeHeadNode("LINK", { rel: "stylesheet", href: "http://net.localhost:8080/css/tenant-theme.css?v=1" }),
        makeHeadNode("STYLE", {}, ".descarga { color:black; }")
    ];
    const INNER_WRAPPER_CSS = ".inner-wrapper { padding-top: 60px !important; }";
    const ssr = node => { node.setAttribute("data-shell-ssr", "1"); return node; };
    const vueInjectedStyle = makeHeadNode("STYLE", {}, ".el-vue-injected { color: red; }");
    const currentHead = makeHead(coreHeadSpecs().map(ssr).concat([vueInjectedStyle]));
    const headHas = predicate => currentHead.nodes.some(predicate);
    const hasInnerWrapperStyle = () => headHas(n => n.tagName === "STYLE" && n.textContent.includes("padding-top: 60px"));
    const hasPosCss = () => headHas(n => n.tagName === "LINK" && (n.getAttribute("href") || "").includes("css/pos.css"));

    const notificationsGroup = { innerHTML: '<ul class="notifications"><span>0</span></ul>' };

    const lifecycle = [];
    const fetchQueue = [];
    const docListeners = {};
    const winListeners = {};

    const documentMock = {
        title: "Facturación Electrónica",
        head: currentHead,
        createElement: tag => makeHeadNode(tag.toUpperCase()),
        documentElement: { classList: makeClassList(["fixed", "sidebar-white", "sidebar-light"]) },
        getElementById: id => {
            if (id === "main-wrapper") return mainWrapper;
            if (id === "sidebar-left") return sidebarEl;
            return null;
        },
        querySelector: sel => {
            if (sel === "#sidebar-left ul.nav-main") return { dataset: {}, addEventListener: () => {} };
            if (sel === ".header .header-right") return headerRightEl;
            if (sel === ".header [data-shell-notifications]") return notificationsGroup;
            return null;
        },
        querySelectorAll: () => [],
        addEventListener: (type, fn) => {
            docListeners[type] = docListeners[type] || [];
            docListeners[type].push(fn);
        }
    };

    const windowMock = {
        location: {
            origin: "http://net.localhost:8080",
            href: "http://net.localhost:8080/dashboard",
            pathname: "/dashboard",
            search: "",
            hash: "",
            reload: () => lifecycle.push("reload:" + windowMock.location.pathname)
        },
        history: {
            pushState(state, title, url) {
                const parsed = new URL(url, "http://net.localhost:8080");
                windowMock.location.pathname = parsed.pathname;
                windowMock.location.search = parsed.search;
                windowMock.location.hash = parsed.hash;
                windowMock.location.href = parsed.href;
                lifecycle.push("pushState:" + url);
            },
            replaceState() {}
        },
        scrollTo: () => lifecycle.push("scrollTo"),
        addEventListener: (type, fn) => {
            winListeners[type] = winListeners[type] || [];
            winListeners[type].push(fn);
        },
        dispatchEvent: () => {},
        unmountSystemVue: () => lifecycle.push("unmountVue"),
        mountSystemVue: () => lifecycle.push("mountVue:" + windowMock.location.pathname),
        fetch: (url, opts) => new Promise((resolve, reject) => {
            fetchQueue.push({ url, opts, resolve, reject });
        })
    };

    class MockAbortController {
        constructor() {
            this.signal = { aborted: false };
        }
        abort() {
            this.signal.aborted = true;
        }
    }

    class MockDOMParser {
        parseFromString(html) {
            const isNewInvoice = html.includes("tenant-documents-invoice");
            const isPos = html.includes("tenant-pos-index");
            const incomingHeadNodes = coreHeadSpecs();
            if (isNewInvoice) incomingHeadNodes.push(makeHeadNode("STYLE", {}, `\n  ${INNER_WRAPPER_CSS}\n`));
            if (isPos) incomingHeadNodes.push(makeHeadNode("LINK", { rel: "stylesheet", href: "http://net.localhost:8080/css/pos.css" }));
            const incItems = [
                { classList: makeClassList(isNewInvoice ? [] : ["nav-active"]) },
                { classList: makeClassList(isNewInvoice ? ["nav-parent", "nav-active", "nav-expanded"] : ["nav-parent"]) },
                { classList: makeClassList(isNewInvoice ? ["nav-active"] : []) }
            ];
            return {
                title: "Facturación Electrónica",
                head: makeHead(incomingHeadNodes),
                documentElement: {
                    className: isNewInvoice ? "fixed newinvoice" : "fixed",
                    classList: makeClassList(isNewInvoice ? ["fixed", "newinvoice"] : ["fixed"])
                },
                getElementById: id => (id === "main-wrapper" ? { innerHTML: html, querySelectorAll: () => [] } : null),
                querySelector: sel => {
                    if (sel === ".header .header-right") {
                        return { querySelectorAll: s => (s === "ul.notifications" ? [{ innerHTML: "<span>4</span>" }] : []) };
                    }
                    if (sel === ".header [data-shell-notifications]") {
                        return { innerHTML: isNewInvoice
                            ? '<ul class="notifications"><span>0</span></ul><ul class="notifications"><span class="badge">4</span></ul>'
                            : '<ul class="notifications"><span>0</span></ul>' };
                    }
                    return null;
                },
                querySelectorAll: sel => {
                    if (sel === "#sidebar-left ul.nav-main li") return incItems;
                    return [];
                }
            };
        }
    }

    vm.runInNewContext(tenantShellCode, {
        window: windowMock,
        document: documentMock,
        URL,
        AbortController: MockAbortController,
        DOMParser: MockDOMParser
    });

    assert.strictEqual(typeof windowMock.tenantShellNavigate, "function", "tenant-shell.js must expose tenantShellNavigate");
    assert.strictEqual(typeof windowMock.shouldBypassTenantShell, "function", "tenant-shell.js must expose shouldBypassTenantShell");

    // Verify explicit bypass rules
    const bypassPaths = [
        "/pos/pos_full",
        "/cuenta/payment_index",
        "/reports/inventory",
        "/ecommerce",
        "/ecommerce/item/1",
        "/logout",
        "/login",
        "/downloads/document/pdf/abc",
        "/print/document/abc/a4",
        "/items/export",
        "/reports/sales/pdf",
        "/reports/sales/excel",
        "/quotations/download/abc?format=pdf",
        "/sale-notes/print-a5/1/a5",
        "/sale-notes/downloadExternal/abc/a4",
        "/cash/report/products-excel/1",
        "/cash/report/1",
        "/sales-consolidated/pdf-totals",
        "/sales-consolidated/excel-totals",
        "/download-file/report.xlsx",
        "/payment-file/download-file/a/b",
        "/document_payments/report/2024-01-01/2024-01-31/excel",
        "/configurations/preprinted/getFormats",
        "/documents/payments-complete"
    ];
    bypassPaths.forEach(p => {
        const u = new URL(p, "http://net.localhost:8080");
        assert.strictEqual(windowMock.shouldBypassTenantShell(u, null), true, `Path ${p} must bypass Tenant Shell`);
    });

    const allowedPaths = [
        "/dashboard",
        "/documents",
        "/documents/create",
        "/quotations",
        "/quotations/create",
        "/sale-notes",
        "/pos",
        "/items",
        "/persons/customers",
        "/purchases",
        "/purchases/create",
        "/inventory",
        "/list-reports",
        "/list-settings",
        "/configurations/pdf_templates",
        "/advanced"
    ];
    allowedPaths.forEach(p => {
        const u = new URL(p, "http://net.localhost:8080");
        assert.strictEqual(windowMock.shouldBypassTenantShell(u, null), false, `ERP route ${p} must be handled by Tenant Shell`);
    });

    // Navigate to /documents/create and verify html.newinvoice + sidebar state + scrollTop preservation
    const navCreate = windowMock.tenantShellNavigate("http://net.localhost:8080/documents/create");
    assert.strictEqual(documentMock.documentElement.classList.contains("tenant-nav-loading"), true);
    fetchQueue[0].resolve({
        ok: true,
        redirected: false,
        headers: { get: name => (name.toLowerCase() === "content-type" ? "text/html; charset=UTF-8" : null) },
        text: () => Promise.resolve("<tenant-documents-invoice></tenant-documents-invoice>")
    });
    await navCreate;

    assert.strictEqual(documentMock.documentElement.classList.contains("tenant-nav-loading"), false);
    assert.strictEqual(documentMock.documentElement.classList.contains("newinvoice"), true, "/documents/create must enable html.newinvoice class");
    assert.strictEqual(liDashboard.classList.contains("nav-active"), false);
    assert.strictEqual(liVentasParent.classList.contains("nav-active"), true);
    assert.strictEqual(liVentasParent.classList.contains("nav-expanded"), true);
    assert.strictEqual(liDocCreate.classList.contains("nav-active"), true);
    assert.strictEqual(nanoContent.scrollTop, 145, "Sidebar .nano-content scrollTop must be preserved across navigation");
    assert(notificationsGroup.innerHTML.includes('class="badge">4'), "Header notifications wrapper must sync when a badge appears (0 -> >0)");
    assert.strictEqual(hasInnerWrapperStyle(), true, "Entering /documents/create must add its page-level inline <style>");
    assert.strictEqual(currentHead.nodes.includes(vueInjectedStyle), true, "Vue/Element-injected styles must never be removed");
    assert.deepStrictEqual(lifecycle, ["unmountVue", "pushState:/documents/create", "mountVue:/documents/create", "scrollTo"]);

    // Navigate back to /dashboard and verify html.newinvoice is removed
    const navDash = windowMock.tenantShellNavigate("http://net.localhost:8080/dashboard");
    fetchQueue[1].resolve({
        ok: true,
        redirected: false,
        text: () => Promise.resolve("<tenant-dashboard-index></tenant-dashboard-index>")
    });
    await navDash;
    assert.strictEqual(documentMock.documentElement.classList.contains("newinvoice"), false, "Leaving /documents/create must remove html.newinvoice");
    assert.strictEqual(liDashboard.classList.contains("nav-active"), true);
    assert.strictEqual(hasInnerWrapperStyle(), false, "Leaving /documents/create must remove its page-level inline <style>");
    assert.strictEqual(notificationsGroup.innerHTML.includes("badge"), false, "Header notifications wrapper must sync when a badge disappears (>0 -> 0)");
    assert.strictEqual(currentHead.nodes.includes(vueInjectedStyle), true, "Vue/Element-injected styles must survive navigation");
    assert(headHas(n => (n.getAttribute("href") || "").includes("tenant-theme.css")), "Core tenant-theme.css must never be removed");
    assert(headHas(n => (n.getAttribute("href") || "").includes("app.css")), "Core app.css must never be removed");

    // /pos page stylesheet must be added on enter and removed on leave
    const navPos = windowMock.tenantShellNavigate("http://net.localhost:8080/pos");
    fetchQueue[2].resolve({ ok: true, redirected: false, headers: { get: name => (name.toLowerCase() === "content-type" ? "text/html; charset=UTF-8" : null) }, text: () => Promise.resolve("<tenant-pos-index></tenant-pos-index>") });
    await navPos;
    assert.strictEqual(hasPosCss(), true, "Entering /pos must add css/pos.css");
    const navDash2 = windowMock.tenantShellNavigate("http://net.localhost:8080/dashboard");
    fetchQueue[3].resolve({ ok: true, redirected: false, headers: { get: name => (name.toLowerCase() === "content-type" ? "text/html; charset=UTF-8" : null) }, text: () => Promise.resolve("<tenant-dashboard-index></tenant-dashboard-index>") });
    await navDash2;
    assert.strictEqual(hasPosCss(), false, "Leaving /pos must remove css/pos.css");

    // Non-HTML responses must full-navigate before reading the body
    let binaryBodyRead = false;
    const navBinary = windowMock.tenantShellNavigate("http://net.localhost:8080/items/report-binary");
    fetchQueue[4].resolve({
        ok: true,
        redirected: false,
        headers: { get: () => "application/pdf" },
        text: () => { binaryBodyRead = true; return Promise.resolve("%PDF"); }
    });
    assert.strictEqual(await navBinary, false);
    assert.strictEqual(binaryBodyRead, false, "Non-HTML responses must not be read via response.text()");
    assert.strictEqual(windowMock.location.href, "http://net.localhost:8080/items/report-binary", "Non-HTML responses must trigger full navigation");
    windowMock.location.href = "http://net.localhost:8080/dashboard";

    // popstate: hash-only change must not refetch
    const popstate = winListeners.popstate[0];
    const fetchCountBeforePop = fetchQueue.length;
    windowMock.location.hash = "#tab-2";
    windowMock.location.href = "http://net.localhost:8080/dashboard#tab-2";
    popstate();
    assert.strictEqual(fetchQueue.length, fetchCountBeforePop, "Hash-only popstate must not refetch the page");

    // popstate into a bypassed URL must full-navigate instead of silently returning
    windowMock.location.pathname = "/reports/inventory";
    windowMock.location.hash = "";
    windowMock.location.href = "http://net.localhost:8080/reports/inventory";
    popstate();
    assert.strictEqual(fetchQueue.length, fetchCountBeforePop, "Bypassed popstate must not fetch via the shell");
    assert.strictEqual(lifecycle[lifecycle.length - 1], "reload:/reports/inventory", "Bypassed popstate must reload the page natively");

    // popstate into a shell route must navigate via the shell
    windowMock.location.pathname = "/items";
    windowMock.location.href = "http://net.localhost:8080/items";
    popstate();
    assert.strictEqual(fetchQueue.length, fetchCountBeforePop + 1, "Shell popstate must fetch the new page");
    fetchQueue[fetchQueue.length - 1].resolve({ ok: true, redirected: false, headers: { get: name => (name.toLowerCase() === "content-type" ? "text/html; charset=UTF-8" : null) }, text: () => Promise.resolve("<tenant-items-index></tenant-items-index>") });
    await Promise.resolve();

    console.log("Tenant shell navigation, html.newinvoice sync, sidebar scroll preservation, bypass rules, and certificate dropzone assertions passed.");
}

testChartRequests()
    .then(() => testSystemShellNavigation())
    .then(() => testTenantShellAndCertificate())
    .then(() => process.exit(0))
    .catch(error => { console.error(error); process.exit(1); });
