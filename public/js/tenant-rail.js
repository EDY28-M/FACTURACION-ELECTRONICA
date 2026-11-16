/*
 * Tenant portal rail: collapse to an icon rail, overlay drawer on narrow screens,
 * fixed tooltips, flyout submenus, sidebar accordion and the top bar dropdowns.
 *
 * Everything is bound once on `document` (delegated), so it survives the
 * #main-wrapper swaps done by tenant-shell.js and is safe to load twice.
 */
(function () {
    "use strict";

    if (window.__tenantRailBound) return;
    window.__tenantRailBound = true;

    var root = document.documentElement;
    var NARROW = 1200;
    var KEY = "tenantRailMini";
    var SCROLL_KEY = "sidebar-left-position";
    var wasNarrow = window.innerWidth < NARROW;

    function narrow() { return window.innerWidth < NARROW; }
    function stored() {
        try { return window.localStorage.getItem(KEY); } catch (e) { return null; }
    }
    function pref() {
        var value = stored();
        if (value === null) return window.TENANT_RAIL_DEFAULT === true;
        return value === "1";
    }
    function setPref(value) {
        try { window.localStorage.setItem(KEY, value ? "1" : "0"); } catch (e) {}
    }
    function mini() { return root.classList.contains("rail-mini") && !root.classList.contains("rail-peek"); }
    function byId(id) { return document.getElementById(id); }

    function closeMenus(except) {
        var buttons = document.querySelectorAll(".tn-top .tn-user-btn, .tn-top .tn-caret");
        Array.prototype.forEach.call(buttons, function (button) {
            if (button !== except) button.setAttribute("aria-expanded", "false");
        });
    }

    function closeFlyouts() {
        var open = document.querySelectorAll("#sidebar-left li.tn-fly");
        Array.prototype.forEach.call(open, function (li) {
            li.classList.remove("tn-fly");
            var list = li.querySelector(":scope > ul.nav-children");
            if (list) list.style.top = "";
            var anchor = li.querySelector(":scope > a.nav-link");
            if (anchor) anchor.setAttribute("aria-expanded", "false");
        });
    }

    function hideTip() {
        var tip = byId("railTip");
        if (tip) tip.classList.remove("show");
    }

    function syncAria() {
        var anchors = document.querySelectorAll("#sidebar-left li.nav-parent > a.nav-link");
        Array.prototype.forEach.call(anchors, function (anchor) {
            var li = anchor.parentElement;
            anchor.setAttribute("aria-expanded", li.classList.contains("nav-expanded") || li.classList.contains("tn-fly") ? "true" : "false");
        });
    }

    function label() {
        var toggle = byId("railToggle");
        if (!toggle) return;
        var peek = root.classList.contains("rail-peek");
        var text = peek ? "Cerrar menú" : (mini() ? "Expandir menú" : "Contraer menú");
        toggle.setAttribute("data-tip", text);
        toggle.setAttribute("aria-label", text);
        var span = toggle.querySelector(".rail-label");
        if (span) span.textContent = peek ? "Cerrar menú" : "Contraer menú";
    }

    function apply() {
        var wantMini = narrow() || pref();
        root.classList.toggle("rail-mini", wantMini);
        if (!narrow()) root.classList.remove("rail-peek");
        if (!mini()) closeFlyouts();
        label();
        syncAria();
    }

    // Charts and tables listen for resize; give the width transition time to finish first.
    function settle() {
        window.setTimeout(function () {
            window.dispatchEvent(new Event("resize"));
            if (typeof window.CustomEvent === "function") {
                window.dispatchEvent(new CustomEvent("tenant:rail-changed"));
            }
        }, 300);
    }

    function toggleRail() {
        closeFlyouts();
        hideTip();
        if (narrow()) {
            root.classList.toggle("rail-peek");
            label();
            return;
        }
        setPref(!mini());
        apply();
        settle();
    }

    function openFlyout(li) {
        var wasOpen = li.classList.contains("tn-fly");
        closeFlyouts();
        hideTip();
        if (wasOpen) return;
        li.classList.add("tn-fly");
        var anchor = li.querySelector(":scope > a.nav-link");
        var list = li.querySelector(":scope > ul.nav-children");
        if (anchor) anchor.setAttribute("aria-expanded", "true");
        if (!anchor || !list) return;
        var rect = anchor.getBoundingClientRect();
        var top = Math.min(rect.top - 8, window.innerHeight - list.offsetHeight - 16);
        list.style.top = Math.max(8, top) + "px";
    }

    function toggleAccordion(li) {
        var willExpand = !li.classList.contains("nav-expanded");
        var siblings = li.parentElement ? li.parentElement.children : [];
        Array.prototype.forEach.call(siblings, function (sibling) {
            if (sibling !== li && sibling.classList.contains("nav-expanded")) {
                sibling.classList.remove("nav-expanded");
                var other = sibling.querySelector(":scope > a.nav-link");
                if (other) other.setAttribute("aria-expanded", "false");
            }
        });
        li.classList.toggle("nav-expanded", willExpand);
        var anchor = li.querySelector(":scope > a.nav-link");
        if (anchor) anchor.setAttribute("aria-expanded", willExpand ? "true" : "false");
    }

    // Capture phase on purpose: Porto binds its own slide accordion directly on these anchors,
    // and the two must not both run on the same click.
    document.addEventListener("click", function (event) {
        var target = event.target;
        if (!target || typeof target.closest !== "function") return;
        var anchor = target.closest("#sidebar-left li.nav-parent > a.nav-link");
        if (!anchor) return;
        var href = (anchor.getAttribute("href") || "").trim();
        if (href !== "" && href !== "#") return;

        event.preventDefault();
        event.stopPropagation();

        var li = anchor.parentElement;
        var topLevel = li.parentElement && li.parentElement.classList.contains("nav-main");
        if (mini() && topLevel) {
            openFlyout(li);
        } else {
            toggleAccordion(li);
        }
    }, true);

    document.addEventListener("click", function (event) {
        var target = event.target;
        if (!target || typeof target.closest !== "function") return;

        if (target.closest("#railToggle")) {
            toggleRail();
            return;
        }
        if (target.closest("#railScrim")) {
            root.classList.remove("rail-peek");
            label();
            return;
        }

        var menuButton = target.closest(".tn-top .tn-user-btn, .tn-top .tn-caret");
        if (menuButton) {
            closeMenus(menuButton);
            menuButton.setAttribute("aria-expanded", menuButton.getAttribute("aria-expanded") === "true" ? "false" : "true");
            return;
        }
        if (!target.closest(".tn-top .tn-user") && !target.closest(".tn-top .tn-new")) {
            closeMenus();
        } else if (target.closest(".tn-top .tn-menu a")) {
            closeMenus();
        }

        if (!target.closest("#sidebar-left li.tn-fly")) closeFlyouts();

        // Following a real link from the overlay drawer closes it.
        var link = target.closest("#sidebar-left a[href]");
        if (link && root.classList.contains("rail-peek") && !link.parentElement.classList.contains("nav-parent")) {
            root.classList.remove("rail-peek");
            label();
        }
        if (link && !link.parentElement.classList.contains("nav-parent")) {
            closeFlyouts();
        }
    });

    document.addEventListener("keydown", function (event) {
        if (event.key !== "Escape") return;
        root.classList.remove("rail-peek");
        closeFlyouts();
        closeMenus();
        hideTip();
        label();
    });

    // Tooltips in icon-rail mode. Fixed positioning so the scrolling nav never clips them.
    document.addEventListener("mouseover", function (event) {
        var target = event.target;
        if (!target || typeof target.closest !== "function") return;
        var holder = target.closest("#sidebar-left [data-tip]");
        var tip = byId("railTip");
        if (!holder || !tip || !mini()) return;
        if (holder.parentElement && holder.parentElement.classList.contains("tn-fly")) return;
        var rect = holder.getBoundingClientRect();
        tip.textContent = holder.getAttribute("data-tip");
        tip.style.left = (rect.right + 12) + "px";
        tip.style.top = (rect.top + rect.height / 2) + "px";
        tip.classList.add("show");
    });
    document.addEventListener("mouseout", function (event) {
        var related = event.relatedTarget;
        if (!related || typeof related.closest !== "function" || !related.closest("#sidebar-left [data-tip]")) hideTip();
    });

    // Remember the sidebar scroll position (same key Porto used) for full page loads.
    var scrollTimer = null;
    document.addEventListener("scroll", function (event) {
        var box = event.target;
        if (!box || !box.classList || !box.classList.contains("nano-content") || !box.closest("#sidebar-left")) return;
        window.clearTimeout(scrollTimer);
        scrollTimer = window.setTimeout(function () {
            try { window.localStorage.setItem(SCROLL_KEY, String(box.scrollTop)); } catch (e) {}
        }, 120);
    }, true);

    window.addEventListener("resize", function () {
        if (narrow() !== wasNarrow) {
            wasNarrow = narrow();
            apply();
            settle();
        }
    });

    window.addEventListener("tenant:navigated", function () {
        root.classList.remove("rail-peek");
        closeFlyouts();
        closeMenus();
        hideTip();
        apply();
    });

    apply();
    window.requestAnimationFrame(function () {
        window.requestAnimationFrame(function () { root.classList.remove("no-trans"); });
    });
})();
