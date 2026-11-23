(function() {
    var root = document.documentElement;
    var activeController = null;
    var activeRequestId = 0;

    var NEW_INVOICE_PATTERN = /^\/(?:documents|quotations|sale-opportunities|order-notes|sale-notes|purchase-quotations|purchase-orders|purchases)\/create(?:\/|$)/;
    var BYPASS_PATH_PATTERN = /^\/(?:pos\/pos_full|pos_full|cuenta\/payment_index|reports\/inventory|ecommerce(?:\/|$)|logout(?:\/|$)|login(?:\/|$)|register(?:\/|$)|password(?:\/|$)|configurations\/change-mode(?:\/|$)|downloads(?:\/|$)|print(?:\/|$)|cash\/report\/|document_payments\/report\/|configurations\/preprinted\/|documents\/payments-complete(?:\/|$))/i;
    var BYPASS_SEGMENT_PATTERN = /\/(?:export|exportation|pdf|excel|download|downloads|print|print-a5|downloadExternal|products-excel|pdf-totals|excel-totals|download-file)(?:\/|$)/i;
    // Core layout styles must never be removed by head synchronization.
    var CORE_STYLE_PATTERN = /(?:^|\/)(?:app|theme|custom|tenant-theme|custom_styles|style-switcher)\.css(?:[?#]|$)|\/skins?\/|\/porto-light\/vendor\//i;
    var SSR_HEAD_ATTR = "data-shell-ssr";

    function currentShellLocation() {
        return (window.location.pathname || "/") + (window.location.search || "");
    }

    var lastShellLocation = currentShellLocation();

    function normalizePath(pathname) {
        var clean = (pathname || "/").replace(/\/+$/, "");
        return clean || "/";
    }

    function isNewInvoicePath(pathname) {
        var clean = normalizePath(pathname);
        if (clean === "/documents/create_tensu") return true;
        return NEW_INVOICE_PATTERN.test(clean);
    }

    window.shouldBypassTenantShell = function shouldBypassTenantShell(urlObj, linkEl) {
        if (linkEl && typeof linkEl.hasAttribute === "function") {
            if (
                linkEl.hasAttribute("data-no-shell") ||
                linkEl.hasAttribute("download") ||
                linkEl.hasAttribute("data-confirm")
            ) {
                return true;
            }
            var targetAttr = (linkEl.getAttribute("target") || "").toLowerCase();
            if (targetAttr === "_blank" || targetAttr === "blank") {
                return true;
            }
            var rawHref = (linkEl.getAttribute("href") || "").trim();
            if (
                !rawHref ||
                rawHref.charAt(0) === "#" ||
                rawHref.indexOf("javascript:") === 0 ||
                rawHref.indexOf("mailto:") === 0 ||
                rawHref.indexOf("tel:") === 0
            ) {
                return true;
            }
        }

        if (!urlObj || urlObj.origin !== window.location.origin) {
            return true;
        }

        var cleanPath = normalizePath(urlObj.pathname);
        if (BYPASS_PATH_PATTERN.test(cleanPath)) {
            return true;
        }
        if (BYPASS_SEGMENT_PATTERN.test(cleanPath)) {
            return true;
        }

        if (urlObj.searchParams) {
            if (
                urlObj.searchParams.has("format") ||
                urlObj.searchParams.has("dl") ||
                urlObj.searchParams.has("export")
            ) {
                return true;
            }
        }

        return false;
    };

    window.bindTenantSidebarAccordion = function bindTenantSidebarAccordion() {
        var navMain = document.querySelector("#sidebar-left ul.nav-main");
        if (!navMain || navMain.dataset.tenantAccordionBound === "1") return;
        navMain.dataset.tenantAccordionBound = "1";

        if (window.jQuery) {
            return;
        }

        navMain.addEventListener("click", function(event) {
            var anchor = event.target && typeof event.target.closest === "function"
                ? event.target.closest("li.nav-parent > a")
                : null;
            if (!anchor || !navMain.contains(anchor)) return;
            var href = anchor.getAttribute("href") || "";
            if (href === "#" || href === "") {
                event.preventDefault();
            }
            var parentLi = anchor.parentElement;
            if (!parentLi) return;
            var siblingLis = parentLi.parentElement ? parentLi.parentElement.children : [];
            var willExpand = !parentLi.classList.contains("nav-expanded");
            Array.prototype.forEach.call(siblingLis, function(sibling) {
                if (sibling !== parentLi && sibling.classList && sibling.classList.contains("nav-expanded")) {
                    sibling.classList.remove("nav-expanded");
                }
            });
            parentLi.classList.toggle("nav-expanded", willExpand);
        });
    };

    var layoutHelpersInitialized = false;

    // Replicates the Porto card behavior from porto-light/js/theme.js for cards
    // that were inserted after an SPA swap (Porto only binds at DOM ready).
    function bindPortoCard($, cardEl) {
        var $card = $(cardEl);
        $card
            .on("card:toggle.tenantShellCard", function(event) {
                if (event.target !== cardEl) return;
                var direction = $card.hasClass("card-collapsed") ? "Down" : "Up";
                $card.find(".card-body, .card-footer")["slide" + direction](200, function() {
                    $card[(direction === "Up" ? "add" : "remove") + "Class"]("card-collapsed");
                });
            })
            .on("card:dismiss.tenantShellCard", function(event) {
                if (event.target !== cardEl) return;
                var parentClass = $card.parent("div").attr("class") || "";
                if (/col-(xs|sm|md|lg)/.test(parentClass) && $card.siblings().length === 0) {
                    var $row = $card.closest(".row");
                    $card.parent("div").remove();
                    if ($row.children().length === 0) {
                        $row.remove();
                    }
                } else {
                    $card.remove();
                }
            })
            .on("click.tenantShellCard", "[data-card-toggle]", function(e) {
                if ($(this).closest(".card")[0] !== cardEl) return;
                e.preventDefault();
                $card.trigger("card:toggle");
            })
            .on("click.tenantShellCard", "[data-card-dismiss]", function(e) {
                if ($(this).closest(".card")[0] !== cardEl) return;
                e.preventDefault();
                $card.trigger("card:dismiss");
            });
    }

    window.initTenantLayoutHelpers = function initTenantLayoutHelpers() {
        var cardClickCount = 0;
        var isFirstInit = !layoutHelpersInitialized;
        layoutHelpersInitialized = true;

        if (window.jQuery) {
            var $ = window.jQuery;
            $(document)
                .off("click.tenantShellCard", "#card-click")
                .on("click.tenantShellCard", "#card-click", function() {
                    cardClickCount += 1;
                    if (cardClickCount === 1) {
                        $("#card-section").removeClass("card-collapsed");
                    }
                });

            $(".tableScrollTop, .tableWide-wrapper")
                .off("scroll.tenantShellScroll")
                .on("scroll.tenantShellScroll", function() {
                    $(".tableWide-wrapper, .tableScrollTop").scrollLeft($(this).scrollLeft());
                });

            // Cards rendered on first load are bound by Porto at DOM ready; only
            // cards inserted by later SPA swaps need the replicated binding.
            $(".card").each(function() {
                if (this.getAttribute("data-card-bound") === "1") return;
                this.setAttribute("data-card-bound", "1");
                if (!isFirstInit) {
                    bindPortoCard($, this);
                }
            });

            // porto-light/js/custom.js binds #styleSwitcherOpen directly at DOM
            // ready; delegate for switchers inserted after SPA swaps.
            if (isFirstInit) {
                $("#styleSwitcherOpen").attr("data-style-switcher-bound", "1");
            }
            $(document)
                .off("click.tenantShellSwitcher", "#styleSwitcherOpen")
                .on("click.tenantShellSwitcher", "#styleSwitcherOpen", function() {
                    if (this.getAttribute("data-style-switcher-bound") === "1") return;
                    var $switcher = $(".style-switcher");
                    if ($switcher.hasClass("active")) {
                        $switcher.animate({ right: "-" + $switcher.width() + "px" }, 300).removeClass("active");
                    } else {
                        $switcher.animate({ right: "0" }, 300).addClass("active");
                    }
                });
        }
        window.bindTenantSidebarAccordion();
    };

    function headNodeKey(node) {
        if (!node || !node.tagName) return null;
        var tag = node.tagName.toUpperCase();
        if (tag === "LINK") {
            var href = (node.getAttribute("href") || "").trim();
            if (!href) return null;
            try {
                return "link:" + new URL(href, window.location.origin).href;
            } catch (e) {
                return "link:" + href;
            }
        }
        if (tag === "STYLE") {
            var css = (node.textContent || "").replace(/\s+/g, " ").trim();
            return css ? "style:" + css : null;
        }
        return null;
    }

    function isCoreHeadNode(node) {
        if (!node || !node.tagName || node.tagName.toUpperCase() !== "LINK") return false;
        return CORE_STYLE_PATTERN.test(node.getAttribute("href") || "");
    }

    function collectHeadStyleNodes(head) {
        var nodes = head.querySelectorAll('link[rel="stylesheet"], style');
        return Array.prototype.filter.call(nodes, function(node) {
            return headNodeKey(node) !== null;
        });
    }

    // Diffs page-level stylesheets and inline <style> blocks (from @stack('styles'))
    // between the current head and the fetched head. Only nodes that were
    // server-rendered (marked with data-shell-ssr by the layout) are eligible for
    // removal, so Vue/Element-injected <style> tags are never touched.
    function syncHeadStylesheets(doc) {
        if (!doc || !doc.head || !document.head) return;

        var incomingNodes = collectHeadStyleNodes(doc.head);
        var incomingKeys = {};
        incomingNodes.forEach(function(node) {
            incomingKeys[headNodeKey(node)] = true;
        });

        var currentNodes = collectHeadStyleNodes(document.head);
        var currentKeys = {};
        currentNodes.forEach(function(node) {
            var key = headNodeKey(node);
            var isServerRendered = node.getAttribute(SSR_HEAD_ATTR) === "1";
            if (isServerRendered && incomingNodes.length > 0 && !incomingKeys[key] && !isCoreHeadNode(node)) {
                if (node.parentNode) node.parentNode.removeChild(node);
                return;
            }
            currentKeys[key] = true;
        });

        incomingNodes.forEach(function(node) {
            var key = headNodeKey(node);
            if (currentKeys[key]) return;
            currentKeys[key] = true;
            var clone;
            if (node.tagName.toUpperCase() === "LINK") {
                clone = document.createElement("link");
                clone.setAttribute("rel", "stylesheet");
                clone.setAttribute("href", (node.getAttribute("href") || "").trim());
            } else {
                clone = document.createElement("style");
                clone.textContent = node.textContent;
            }
            clone.setAttribute(SSR_HEAD_ATTR, "1");
            document.head.appendChild(clone);
        });
    }

    // Keep the tab icon in sync with the fetched page (e.g. after the favicon is changed).
    function syncFavicon(doc) {
        if (!doc || !doc.head || !document.head) return;
        var incoming = doc.head.querySelector('link[rel~="icon"]');
        if (!incoming) return;
        var href = (incoming.getAttribute("href") || "").trim();
        if (!href) return;
        window.setTenantFavicon(href);
    }

    window.setTenantFavicon = function(href) {
        var current = document.head.querySelectorAll('link[rel~="icon"]');
        for (var i = 0; i < current.length; i++) {
            if (current[i].getAttribute("href") === href) return;
        }
        for (var j = 0; j < current.length; j++) {
            if (current[j].parentNode) current[j].parentNode.removeChild(current[j]);
        }
        var link = document.createElement("link");
        link.setAttribute("rel", "icon");
        link.setAttribute("type", "image/png");
        link.setAttribute("href", href);
        link.setAttribute("data-tenant-favicon", "1");
        document.head.appendChild(link);
    };

    function syncHtmlClasses(doc, pathname) {
        var incomingHtml = doc ? doc.documentElement : null;
        var shouldBeNewInvoice = (incomingHtml && incomingHtml.classList.contains("newinvoice")) || isNewInvoicePath(pathname);
        root.classList.toggle("newinvoice", Boolean(shouldBeNewInvoice));

        if (incomingHtml && incomingHtml.className) {
            if (incomingHtml.classList.contains("dark") !== root.classList.contains("dark")) {
                root.classList.toggle("dark", incomingHtml.classList.contains("dark"));
            }
        }
    }

    function syncSidebarState(doc, pathname) {
        var sidebar = document.getElementById("sidebar-left");
        if (!sidebar) return;

        var nanoContent = sidebar.querySelector(".nano-content");
        var savedScrollTop = nanoContent ? nanoContent.scrollTop : 0;

        var currentItems = sidebar.querySelectorAll("ul.nav-main li");
        var incomingItems = doc ? doc.querySelectorAll("#sidebar-left ul.nav-main li") : [];

        if (incomingItems.length > 0 && currentItems.length === incomingItems.length) {
            for (var i = 0; i < currentItems.length; i++) {
                var isActive = incomingItems[i].classList.contains("nav-active");
                var isExpanded = incomingItems[i].classList.contains("nav-expanded");
                currentItems[i].classList.toggle("nav-active", isActive);
                currentItems[i].classList.toggle("nav-expanded", isExpanded);
                var childList = currentItems[i].querySelector("ul.nav-children");
                if (childList && childList.parentElement === currentItems[i]) {
                    childList.style.display = "";
                }
            }
        } else {
            var cleanTarget = normalizePath(pathname);
            if (cleanTarget === "/") cleanTarget = "/dashboard";

            Array.prototype.forEach.call(currentItems, function(li) {
                li.classList.remove("nav-active");
                if (li.classList.contains("nav-parent")) {
                    li.classList.remove("nav-expanded");
                }
            });

            var links = sidebar.querySelectorAll("ul.nav-main a.nav-link[href]");
            Array.prototype.forEach.call(links, function(link) {
                var rawHref = link.getAttribute("href");
                if (!rawHref || rawHref === "#") return;
                try {
                    var parsed = new URL(rawHref, window.location.origin);
                    var linkPath = normalizePath(parsed.pathname);
                    if (linkPath === cleanTarget) {
                        var itemLi = link.closest("li");
                        if (itemLi) itemLi.classList.add("nav-active");
                        var parent = itemLi ? itemLi.parentElement : null;
                        while (parent && sidebar.contains(parent)) {
                            if (parent.tagName === "LI" && parent.classList.contains("nav-parent")) {
                                parent.classList.add("nav-active");
                                parent.classList.add("nav-expanded");
                            }
                            parent = parent.parentElement;
                        }
                    }
                } catch (e) {}
            });
        }

        if (nanoContent) {
            nanoContent.scrollTop = savedScrollTop;
        }
    }

    function syncHeaderNotifications(doc) {
        if (!doc) return;
        var currentGroup = document.querySelector(".header [data-shell-notifications]");
        var incomingGroup = doc.querySelector(".header [data-shell-notifications]");
        if (currentGroup && incomingGroup) {
            // Replacing the whole wrapper handles badges that appear or disappear
            // when counts cross 0 <-> >0 (conditional <ul class="notifications">).
            currentGroup.innerHTML = incomingGroup.innerHTML;
            return;
        }

        var currentHeaderRight = document.querySelector(".header .header-right");
        var incomingHeaderRight = doc.querySelector(".header .header-right");
        if (!currentHeaderRight || !incomingHeaderRight) return;

        var currentLists = currentHeaderRight.querySelectorAll("ul.notifications");
        var incomingLists = incomingHeaderRight.querySelectorAll("ul.notifications");
        if (currentLists.length === incomingLists.length && currentLists.length > 0) {
            for (var i = 0; i < currentLists.length; i++) {
                currentLists[i].innerHTML = incomingLists[i].innerHTML;
            }
        }
    }

    function executeInlineMainScripts(container) {
        if (!container) return;
        var scripts = container.querySelectorAll("script");
        Array.prototype.forEach.call(scripts, function(oldScript) {
            var src = oldScript.getAttribute("src") || "";
            if (src) {
                if (/\/(manifest|vendor|app|theme|custom|jquery)\.js/i.test(src)) {
                    return;
                }
            }
            var code = (oldScript.textContent || "").trim();
            if (!src && !code) return;

            var newScript = document.createElement("script");
            Array.prototype.forEach.call(oldScript.attributes, function(attr) {
                newScript.setAttribute(attr.name, attr.value);
            });
            if (code) {
                newScript.textContent = code;
            }
            if (oldScript.parentNode) {
                oldScript.parentNode.replaceChild(newScript, oldScript);
            }
        });
    }

    function fallbackNavigation(url) {
        root.classList.remove("tenant-nav-loading");
        window.location.href = url;
    }

    window.tenantShellNavigate = function tenantShellNavigate(rawUrl, options) {
        options = options || {};
        var targetUrl;
        try {
            targetUrl = new URL(rawUrl, window.location.origin);
        } catch (e) {
            fallbackNavigation(rawUrl);
            return Promise.resolve(false);
        }

        if (window.shouldBypassTenantShell(targetUrl, null)) {
            fallbackNavigation(targetUrl.href);
            return Promise.resolve(false);
        }

        if (activeController && typeof activeController.abort === "function") {
            activeController.abort();
        }
        activeController = typeof AbortController !== "undefined" ? new AbortController() : null;
        var requestId = ++activeRequestId;

        root.classList.add("tenant-nav-loading");
        root.classList.remove("sidebar-left-opened");

        var fetchOptions = {
            method: "GET",
            credentials: "same-origin",
            headers: {
                "X-Requested-With": "XMLHttpRequest",
                "X-Tenant-Shell": "1",
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
                    if (window.shouldBypassTenantShell(redirectedUrl, null)) {
                        fallbackNavigation(redirectedUrl.href);
                        return null;
                    }
                    targetUrl = redirectedUrl;
                }
                var contentType = response.headers && typeof response.headers.get === "function"
                    ? (response.headers.get("content-type") || "")
                    : "text/html";
                if (contentType.toLowerCase().indexOf("text/html") === -1) {
                    // Files (PDF, XLSX, ZIP...) must be handled natively by the browser.
                    fallbackNavigation(targetUrl.href);
                    return null;
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

                syncHeadStylesheets(doc);
                syncFavicon(doc);

                if (doc.title) {
                    document.title = doc.title;
                }

                if (typeof window.unmountSystemVue === "function") {
                    window.unmountSystemVue();
                }

                var finalRelativeUrl = targetUrl.pathname + targetUrl.search + targetUrl.hash;
                var currentRelativeUrl = window.location.pathname + window.location.search + window.location.hash;
                if (!options.popstate && window.history && typeof window.history.pushState === "function") {
                    if (finalRelativeUrl !== currentRelativeUrl) {
                        window.history.pushState({ tenantShell: true, url: finalRelativeUrl }, "", finalRelativeUrl);
                    } else if (typeof window.history.replaceState === "function") {
                        window.history.replaceState({ tenantShell: true, url: finalRelativeUrl }, "", finalRelativeUrl);
                    }
                }
                lastShellLocation = targetUrl.pathname + targetUrl.search;

                syncHtmlClasses(doc, targetUrl.pathname);
                syncSidebarState(doc, targetUrl.pathname);
                syncHeaderNotifications(doc);

                mainWrapper.innerHTML = incomingMain.innerHTML;
                executeInlineMainScripts(mainWrapper);

                if (typeof window.mountSystemVue === "function") {
                    window.mountSystemVue("#main-wrapper");
                }
                window.initTenantLayoutHelpers();

                if (!options.preserveScroll && typeof window.scrollTo === "function") {
                    window.scrollTo(0, 0);
                }

                root.classList.remove("tenant-nav-loading");
                if (typeof window.CustomEvent === "function") {
                    window.dispatchEvent(new CustomEvent("tenant:navigated", { detail: { url: finalRelativeUrl } }));
                }
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

        var inShellRegion = Boolean(
            link.closest("#sidebar-left") ||
            link.closest(".header") ||
            link.closest("#main-wrapper")
        );
        if (!inShellRegion) return;

        if (link.parentElement && link.parentElement.classList.contains("nav-parent")) {
            var parentHref = (link.getAttribute("href") || "").trim();
            if (parentHref === "#" || parentHref === "") {
                return;
            }
        }

        var rawHref = (link.getAttribute("href") || "").trim();
        if (!rawHref || rawHref.charAt(0) === "#" || rawHref.indexOf("javascript:") === 0) return;

        var urlObj;
        try {
            urlObj = new URL(rawHref, window.location.origin);
        } catch (e) {
            return;
        }

        if (window.shouldBypassTenantShell(urlObj, link)) return;

        if (
            urlObj.pathname === window.location.pathname &&
            urlObj.search === window.location.search &&
            urlObj.hash &&
            urlObj.hash !== window.location.hash
        ) {
            return;
        }

        event.preventDefault();
        window.tenantShellNavigate(urlObj.href);
    });

    window.addEventListener("popstate", function() {
        var currentUrl;
        try {
            currentUrl = new URL(window.location.href);
        } catch (e) {
            return;
        }
        var nextLocation = currentUrl.pathname + currentUrl.search;
        // Hash-only history entries (in-page anchors/tabs) must not refetch the page.
        if (nextLocation === lastShellLocation) return;

        if (window.shouldBypassTenantShell(currentUrl, null)) {
            // The URL bar already points to a non-shell page; load it natively so
            // the DOM does not stay desynchronized from the address bar.
            lastShellLocation = nextLocation;
            root.classList.remove("tenant-nav-loading");
            if (typeof window.location.reload === "function") {
                window.location.reload();
            } else if (typeof window.location.assign === "function") {
                window.location.assign(currentUrl.href);
            } else {
                window.location.href = currentUrl.href;
            }
            return;
        }
        window.tenantShellNavigate(currentUrl.href, { popstate: true });
    });

    window.initTenantLayoutHelpers();
})();
