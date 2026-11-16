/**
 * Topbar module search.
 *
 * The index is read from the rendered sidebar (#sidebar-left), so it always reflects what the
 * current user can actually open: permissions, plan, enabled modules and environment are
 * already applied by sidebar.blade.php. Nothing is duplicated and no request is made.
 * Result links reuse the sidebar hrefs, so tenant-shell.js navigates them like any menu entry.
 */
(function () {
    'use strict';
    if (window.__tnModuleSearchInit) return;
    window.__tnModuleSearchInit = true;

    var MAX_RESULTS = 12;
    var SEPARATOR = ' › ';
    var FALLBACK_ICON = 'layout-dashboard';

    function normalize(value) {
        var text = String(value || '').toLowerCase();
        if (text.normalize) text = text.normalize('NFD').replace(/[̀-ͯ]/g, '');
        return text.replace(/\s+/g, ' ').trim();
    }

    function textOf(node) {
        return String(node && node.textContent || '').replace(/\s+/g, ' ').trim();
    }

    function childrenByTag(parent, tag) {
        return Array.prototype.filter.call(parent.children, function (child) {
            return child.tagName === tag;
        });
    }

    function labelOf(anchor) {
        var label = anchor.querySelector('.rail-label');
        return textOf(label || anchor);
    }

    function isNavigable(href) {
        var value = String(href || '').trim();
        return value !== '' && value.charAt(0) !== '#' && value.toLowerCase().indexOf('javascript:') !== 0;
    }

    /** Walks the sidebar tree and returns one entry per navigable link. */
    function collectModules() {
        var nav = document.querySelector('#sidebar-left ul.nav-main');
        var entries = [];
        if (!nav) return entries;

        function walk(list, trail, section, icon) {
            childrenByTag(list, 'LI').forEach(function (item) {
                if (item.classList.contains('nav-description')) {
                    section = textOf(item);
                    return;
                }
                var anchor = childrenByTag(item, 'A')[0];
                if (!anchor) return;

                var label = labelOf(anchor);
                var ownIcon = icon || anchor.querySelector('svg.tn-icon:not(.chev)');
                var children = childrenByTag(item, 'UL')[0];

                if (children) {
                    walk(children, trail.concat(label), section, ownIcon);
                    return;
                }
                if (!label || !isNavigable(anchor.getAttribute('href'))) return;

                var path = trail.join(SEPARATOR);
                entries.push({
                    label: label,
                    path: path,
                    section: section || '',
                    href: anchor.getAttribute('href'),
                    target: anchor.getAttribute('target') || '',
                    icon: ownIcon,
                    haystackLabel: normalize(label),
                    haystackPath: normalize(path + ' ' + (section || ''))
                });
            });
        }

        walk(nav, [], '', null);
        return entries;
    }

    /** Lower is better; null means the entry does not match every term. */
    function score(entry, terms, phrase) {
        var total = 0;
        for (var i = 0; i < terms.length; i++) {
            var term = terms[i];
            var inLabel = entry.haystackLabel.indexOf(term);
            if (inLabel === -1 && entry.haystackPath.indexOf(term) === -1) return null;
            if (inLabel === -1) total += 30;
            else if (inLabel === 0) total += 0;
            else if (entry.haystackLabel.charAt(inLabel - 1) === ' ') total += 5;
            else total += 15;
        }
        if (entry.haystackLabel === phrase) total -= 20;
        return total;
    }

    function search(entries, query) {
        var phrase = normalize(query);
        if (!phrase) return [];
        var terms = phrase.split(' ');
        var ranked = [];
        entries.forEach(function (entry, order) {
            var value = score(entry, terms, phrase);
            if (value !== null) ranked.push({ entry: entry, value: value, order: order });
        });
        ranked.sort(function (a, b) { return a.value - b.value || a.order - b.order; });
        return ranked.slice(0, MAX_RESULTS).map(function (row) { return row.entry; });
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined && text !== null) node.textContent = String(text);
        return node;
    }

    function init() {
        var root = document.querySelector('[data-tn-module-search]');
        if (!root) return;

        var box = root.querySelector('.tn-search');
        var input = root.querySelector('.tn-search-input');
        var panel = root.querySelector('.tn-search-panel');
        var body = root.querySelector('.tn-search-body');
        var status = root.querySelector('.tn-search-status');
        var closeBtn = root.querySelector('.tn-search-close');
        if (!box || !input || !panel || !body) return;

        var anchors = [];
        var activeIndex = -1;

        function setStatus(text) { if (status) status.textContent = text || ''; }

        function setActive(index) {
            if (activeIndex >= 0 && anchors[activeIndex]) {
                anchors[activeIndex].classList.remove('is-active');
                anchors[activeIndex].setAttribute('aria-selected', 'false');
            }
            activeIndex = index;
            if (activeIndex >= 0 && anchors[activeIndex]) {
                anchors[activeIndex].classList.add('is-active');
                anchors[activeIndex].setAttribute('aria-selected', 'true');
                if (anchors[activeIndex].scrollIntoView) anchors[activeIndex].scrollIntoView({ block: 'nearest' });
            }
        }

        function showPanel() {
            panel.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            root.classList.add('is-focused');
        }

        function hidePanel() {
            panel.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            root.classList.remove('is-focused');
            root.classList.remove('is-open');
            setActive(-1);
        }

        function iconFor(entry) {
            var wrapper = el('span', 'tn-search-item-ico');
            if (entry.icon) {
                wrapper.appendChild(entry.icon.cloneNode(true));
            } else {
                wrapper.innerHTML = '<svg class="tn-icon" aria-hidden="true" focusable="false"><use href="/images/system-icons.svg#' + FALLBACK_ICON + '"></use></svg>';
            }
            return wrapper;
        }

        function render(results, term) {
            body.textContent = '';
            anchors = [];
            activeIndex = -1;

            if (!results.length) {
                body.appendChild(el('div', 'tn-search-empty', 'No hay módulos que coincidan con «' + term + '».'));
                setStatus('Sin resultados');
                return;
            }

            var group = el('div', 'tn-search-group');
            results.forEach(function (entry) {
                var link = document.createElement('a');
                link.className = 'tn-search-item';
                link.href = entry.href;
                link.setAttribute('role', 'option');
                link.setAttribute('aria-selected', 'false');
                if (entry.target) {
                    link.target = entry.target;
                    link.rel = 'noopener';
                }

                link.appendChild(iconFor(entry));
                var txt = el('span', 'tn-search-item-txt');
                txt.appendChild(el('span', 'tn-search-item-title', entry.label));
                var subtitle = entry.path || entry.section;
                if (subtitle) txt.appendChild(el('span', 'tn-search-item-sub', subtitle));
                link.appendChild(txt);

                // Keep focus in the input so a click never blurs (and hides) the panel before it lands.
                link.addEventListener('mousedown', function (event) { event.preventDefault(); });
                link.addEventListener('click', function () {
                    input.value = '';
                    input.blur();
                    hidePanel();
                    setStatus('');
                });
                group.appendChild(link);
                anchors.push(link);
            });
            body.appendChild(group);
            setStatus(results.length === 1 ? '1 resultado' : results.length + ' resultados');
            setActive(0);
        }

        function run() {
            var term = input.value.trim();
            if (!term) {
                hidePanel();
                setStatus('');
                return;
            }
            // Rebuilt on every query on purpose: the list is ~100 nodes and the shell may swap the sidebar.
            showPanel();
            render(search(collectModules(), term), term);
        }

        input.addEventListener('input', run);
        input.addEventListener('focus', function () { if (input.value.trim()) run(); });
        input.addEventListener('keydown', function (event) {
            var key = event.key;
            if (key === 'ArrowDown' || key === 'ArrowUp') {
                if (panel.hidden || !anchors.length) return;
                event.preventDefault();
                var next = key === 'ArrowDown' ? activeIndex + 1 : activeIndex - 1;
                if (next < 0) next = anchors.length - 1;
                if (next >= anchors.length) next = 0;
                setActive(next);
            } else if (key === 'Enter') {
                if (panel.hidden || !anchors.length) return;
                event.preventDefault();
                // A real click, so tenant-shell.js (and target=_blank links) behave as in the sidebar.
                anchors[activeIndex >= 0 ? activeIndex : 0].click();
            } else if (key === 'Escape') {
                if (!panel.hidden) { event.preventDefault(); hidePanel(); input.blur(); }
            }
        });

        if (closeBtn) closeBtn.addEventListener('click', function () { hidePanel(); input.focus(); });

        document.addEventListener('mousedown', function (event) {
            if (!panel.hidden && !root.contains(event.target)) hidePanel();
        });

        // "/" focuses the search unless the user is already typing somewhere.
        document.addEventListener('keydown', function (event) {
            if (event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey) return;
            var target = event.target;
            var tag = String(target && target.tagName || '').toLowerCase();
            if (tag === 'input' || tag === 'textarea' || tag === 'select' || (target && target.isContentEditable)) return;
            event.preventDefault();
            input.focus();
        });

        // On narrow screens the box collapses to an icon; tapping it expands the field.
        box.addEventListener('click', function () {
            if (window.innerWidth <= 760) {
                root.classList.add('is-open');
                setTimeout(function () { input.focus(); }, 30);
            }
            input.focus();
        });
        input.addEventListener('blur', function () {
            if (window.innerWidth > 760) return;
            setTimeout(function () {
                if (panel.hidden && document.activeElement !== input) root.classList.remove('is-open');
            }, 160);
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
