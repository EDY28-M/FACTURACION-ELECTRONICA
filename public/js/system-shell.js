(function() {
    var root = document.documentElement;
    var collapse = document.querySelector("[data-system-collapse]");
    var menuButtons = document.querySelectorAll("[data-system-menu]");
    var menuToggle = document.querySelector(".system-mobile-menu");
    var menuClose = document.querySelector("[data-system-close]");
    var dateChip = document.querySelector("[data-system-date]");
    var COMPACT_STORAGE_KEY = "facturador_sidebar_compact";
    var SHELL_ROUTE_PATTERN = /^\/(?:$|dashboard$|clients$|plans$|accounting$|backup$|logs$|auto-update$|configurations$|users\/create$)/;

    if (dateChip) {
        var now = new Date();
        var text = new Intl.DateTimeFormat("es-PE", { weekday: "short", day: "2-digit", month: "short", year: "numeric", timeZone: "America/Lima" })
            .format(now).replace(/[.,]/g, "").replace(/\s+de\s+/g, " ").toUpperCase();
        dateChip.textContent = text;
        dateChip.setAttribute("datetime", now.toISOString().slice(0, 10));
    }

    function applyCompactState(compact) {
        root.classList.toggle("system-sidebar-compact", compact);
        if (!collapse) return;
        collapse.setAttribute("aria-expanded", String(!compact));
        collapse.setAttribute(
            "aria-label",
            compact ? "Expandir menú" : "Contraer menú"
        );
        var labelSpan = collapse.querySelector("span");
        if (labelSpan) {
            labelSpan.textContent = compact ? "Expandir menú" : "Contraer menú";
        }
    }

    try {
        if (window.localStorage && window.localStorage.getItem(COMPACT_STORAGE_KEY) === "1") {
            applyCompactState(true);
        }
    } catch (e) {}

    function setMenu(open) {
        root.classList.toggle("system-menu-open", open);
        if (menuToggle) menuToggle.setAttribute("aria-expanded", String(open));
        document.body.style.overflow = open ? "hidden" : "";
        if (open && menuClose) menuClose.focus();
        if (!open && menuToggle && window.innerWidth < 768) menuToggle.focus();
    }

    if (collapse) {
        collapse.addEventListener("click", function() {
            var compact = !root.classList.contains("system-sidebar-compact");
            applyCompactState(compact);
            try {
                if (window.localStorage) {
                    window.localStorage.setItem(COMPACT_STORAGE_KEY, compact ? "1" : "0");
                }
            } catch (e) {}
        });
    }

    Array.prototype.forEach.call(menuButtons, function(button) {
        button.addEventListener("click", function() {
            setMenu(!root.classList.contains("system-menu-open"));
        });
    });
    if (menuClose) menuClose.addEventListener("click", function() { setMenu(false); });
    window.addEventListener("resize", function() {
        if (window.innerWidth >= 768 && root.classList.contains("system-menu-open")) setMenu(false);
    });
    document.addEventListener("keydown", function(event) {
        if (event.key === "Escape" && root.classList.contains("system-menu-open")) setMenu(false);
    });

    window.initSystemLogs = function initSystemLogs() {
        var logsRoot = document.getElementById("system-logs");
        if (!logsRoot || logsRoot.dataset.logsInitialized === "1") return;
        logsRoot.dataset.logsInitialized = "1";

        var rows = Array.prototype.slice.call(logsRoot.querySelectorAll("#log-table tbody tr"));
        var table = document.getElementById("log-table");
        var empty = document.getElementById("log-empty");
        var search = document.getElementById("log-search");
        var type = document.getElementById("log-type");
        var from = document.getElementById("log-from");
        var to = document.getElementById("log-to");
        var clearBtn = document.getElementById("log-clear");

        function apply() {
            if (!search || !type || !from || !to) return;
            var q = (search.value || "").trim().toLowerCase();
            var matched = 0;
            rows.forEach(function(row) {
                var ok = (!q || row.dataset.text.indexOf(q) > -1) &&
                    (!type.value || row.dataset.level === type.value) &&
                    (!from.value || row.dataset.date >= from.value) &&
                    (!to.value || row.dataset.date <= to.value);
                if (ok) matched++;
                row.hidden = !ok;
            });
            if (table) table.hidden = !matched;
            if (empty) {
                empty.hidden = matched > 0;
                var emptyTitle = document.getElementById("log-empty-title");
                var emptyText = document.getElementById("log-empty-text");
                if (emptyTitle) emptyTitle.textContent = "Ningún registro coincide con los filtros";
                if (emptyText) emptyText.textContent = "Revisa el texto o los filtros aplicados.";
            }
        }

        [search, type, from, to].forEach(function(el) {
            if (el) el.addEventListener("input", function() { apply(); });
        });
        if (clearBtn) {
            clearBtn.addEventListener("click", function() {
                if (search) search.value = "";
                if (type) type.value = "";
                if (from) from.value = "";
                if (to) to.value = "";
                apply();
            });
        }
        var fileSelect = logsRoot.querySelector("[data-file-select]");
        if (fileSelect) {
            fileSelect.addEventListener("change", function() {
                if (!fileSelect.value) return;
                if (typeof window.systemShellNavigate === "function") {
                    window.systemShellNavigate(window.location.pathname + fileSelect.value);
                } else {
                    window.location.search = fileSelect.value;
                }
            });
        }
        Array.prototype.forEach.call(logsRoot.querySelectorAll("[data-confirm]"), function(link) {
            link.addEventListener("click", function(event) {
                if (!window.confirm(link.getAttribute("data-confirm"))) event.preventDefault();
            });
        });
        if (rows.length) apply();
    };

    window.initSystemLogs();

    function resolveSegment(pathname) {
        var clean = (pathname || "/").replace(/\/+$/, "");
        if (!clean || clean === "" || clean === "/dashboard") return "dashboard";
        return clean.replace(/^\/+/, "").split("/")[0];
    }

    function isShellUrl(urlObj) {
        if (!urlObj || urlObj.origin !== window.location.origin) return false;
        var cleanPath = (urlObj.pathname || "/").replace(/\/+$/, "") || "/";
        if (!SHELL_ROUTE_PATTERN.test(cleanPath)) return false;
        if (cleanPath === "/logs" && urlObj.searchParams) {
            if (
                urlObj.searchParams.has("dl") ||
                urlObj.searchParams.has("clean") ||
                urlObj.searchParams.has("del") ||
                urlObj.searchParams.has("delall")
            ) {
                return false;
            }
        }
        return true;
    }

    function updateActiveNav(pathname) {
        var segment = resolveSegment(pathname);
        var navLinks = document.querySelectorAll(".system-sidebar .system-nav-link, .system-sidebar .system-user-link");
        Array.prototype.forEach.call(navLinks, function(link) {
            var linkSegment = link.getAttribute("data-nav-segment");
            if (!linkSegment) {
                try {
                    var parsed = new URL(link.getAttribute("href"), window.location.origin);
                    linkSegment = resolveSegment(parsed.pathname);
                } catch (e) {
                    linkSegment = "";
                }
            }
            var active = linkSegment === segment;
            link.classList.toggle("is-active", active);
            if (active) {
                link.setAttribute("aria-current", "page");
            } else {
                link.removeAttribute("aria-current");
            }
        });
    }

    function syncHeaderSearch(urlObj) {
        var searchInput = document.querySelector('.system-search input[name="search"]');
        if (!searchInput || !urlObj) return;
        searchInput.value = urlObj.searchParams.get("search") || "";
    }

    var activeController = null;
    var activeRequestId = 0;

    function fallbackNavigation(url) {
        root.classList.remove("system-nav-loading");
        updateActiveNav(window.location.pathname);
        try {
            syncHeaderSearch(new URL(window.location.href, window.location.origin));
        } catch (e) {}
        window.location.href = url;
    }

    window.systemShellNavigate = function systemShellNavigate(rawUrl, options) {
        options = options || {};
        var targetUrl;
        try {
            targetUrl = new URL(rawUrl, window.location.origin);
        } catch (e) {
            fallbackNavigation(rawUrl);
            return Promise.resolve(false);
        }

        if (!isShellUrl(targetUrl)) {
            fallbackNavigation(targetUrl.href);
            return Promise.resolve(false);
        }

        if (targetUrl.pathname === "/") {
            targetUrl.pathname = "/dashboard";
        }

        if (activeController && typeof activeController.abort === "function") {
            activeController.abort();
        }
        activeController = typeof AbortController !== "undefined" ? new AbortController() : null;
        var requestId = ++activeRequestId;

        root.classList.add("system-nav-loading");
        updateActiveNav(targetUrl.pathname);
        syncHeaderSearch(targetUrl);
        if (root.classList.contains("system-menu-open")) {
            setMenu(false);
        }

        var fetchOptions = {
            method: "GET",
            credentials: "same-origin",
            headers: {
                "X-Requested-With": "XMLHttpRequest",
                "X-System-Shell": "1",
                "Accept": "text/html,application/xhtml+xml"
            }
        };
        if (activeController) {
            fetchOptions.signal = activeController.signal;
        }

        return window.fetch(targetUrl.href, fetchOptions)
            .then(function(response) {
                if (requestId !== activeRequestId) return null;
                if (!response || !response.ok) {
                    fallbackNavigation(targetUrl.href);
                    return null;
                }
                if (response.redirected && response.url) {
                    var redirectedUrl = new URL(response.url, window.location.origin);
                    if (!isShellUrl(redirectedUrl)) {
                        fallbackNavigation(redirectedUrl.href);
                        return null;
                    }
                    targetUrl = redirectedUrl;
                }
                return response.text();
            })
            .then(function(html) {
                if (html === null || requestId !== activeRequestId) return false;
                var parser = new DOMParser();
                var doc = parser.parseFromString(html, "text/html");
                var incomingMain = doc.getElementById("main-wrapper");
                var mainWrapper = document.getElementById("main-wrapper");
                if (!incomingMain || !mainWrapper) {
                    fallbackNavigation(targetUrl.href);
                    return false;
                }

                if (doc.title) {
                    document.title = doc.title;
                }

                var incomingCount = doc.querySelector(".system-nav-count");
                var currentCount = document.querySelector(".system-nav-count");
                if (incomingCount && currentCount) {
                    currentCount.textContent = incomingCount.textContent;
                }

                var incomingCert = doc.querySelector(".system-certificate");
                var currentCert = document.querySelector(".system-certificate");
                if (incomingCert && currentCert) {
                    currentCert.setAttribute("title", incomingCert.getAttribute("title") || "");
                    currentCert.innerHTML = incomingCert.innerHTML;
                }

                if (typeof window.unmountSystemVue === "function") {
                    window.unmountSystemVue();
                }

                var finalRelativeUrl = targetUrl.pathname + targetUrl.search + targetUrl.hash;
                var currentRelativeUrl = window.location.pathname + window.location.search + window.location.hash;
                if (!options.popstate && window.history && typeof window.history.pushState === "function") {
                    if (finalRelativeUrl !== currentRelativeUrl) {
                        window.history.pushState({ systemShell: true, url: finalRelativeUrl }, "", finalRelativeUrl);
                    } else if (typeof window.history.replaceState === "function") {
                        window.history.replaceState({ systemShell: true, url: finalRelativeUrl }, "", finalRelativeUrl);
                    }
                }

                updateActiveNav(targetUrl.pathname);
                syncHeaderSearch(targetUrl);

                mainWrapper.innerHTML = incomingMain.innerHTML;

                if (typeof window.mountSystemVue === "function") {
                    window.mountSystemVue("#main-wrapper");
                }
                window.initSystemLogs();

                if (!options.preserveScroll && typeof window.scrollTo === "function") {
                    window.scrollTo(0, 0);
                }

                root.classList.remove("system-nav-loading");
                return true;
            })
            .catch(function(error) {
                if (error && error.name === "AbortError") {
                    return false;
                }
                if (requestId === activeRequestId) {
                    fallbackNavigation(targetUrl.href);
                }
                return false;
            });
    };

    document.addEventListener("click", function(event) {
        if (event.defaultPrevented || event.button !== 0) return;
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        var target = event.target;
        var link = target && typeof target.closest === "function" ? target.closest("a[href]") : null;
        if (!link) return;

        if (
            link.hasAttribute("download") ||
            link.getAttribute("target") === "_blank" ||
            link.hasAttribute("data-no-shell") ||
            link.hasAttribute("data-confirm")
        ) {
            return;
        }

        var rawHref = link.getAttribute("href");
        if (!rawHref || rawHref.charAt(0) === "#" || rawHref.indexOf("javascript:") === 0) return;

        var urlObj;
        try {
            urlObj = new URL(rawHref, window.location.origin);
        } catch (e) {
            return;
        }

        if (!isShellUrl(urlObj)) return;

        if (
            urlObj.pathname === window.location.pathname &&
            urlObj.search === window.location.search &&
            urlObj.hash &&
            urlObj.hash !== window.location.hash
        ) {
            return;
        }

        event.preventDefault();
        window.systemShellNavigate(urlObj.href);
    });

    var searchForm = document.querySelector("form.system-search");
    if (searchForm) {
        searchForm.addEventListener("submit", function(event) {
            var input = searchForm.querySelector('input[name="search"]');
            var query = input ? (input.value || "").trim() : "";
            var action = searchForm.getAttribute("action") || "/clients";
            var destUrl;
            try {
                destUrl = new URL(action, window.location.origin);
            } catch (e) {
                return;
            }
            if (query) {
                destUrl.searchParams.set("search", query);
            } else {
                destUrl.searchParams.delete("search");
            }
            event.preventDefault();
            window.systemShellNavigate(destUrl.href);
        });
    }

    window.addEventListener("popstate", function() {
        var currentUrl;
        try {
            currentUrl = new URL(window.location.href);
        } catch (e) {
            return;
        }
        if (isShellUrl(currentUrl)) {
            window.systemShellNavigate(currentUrl.href, { popstate: true });
        }
    });
})();
