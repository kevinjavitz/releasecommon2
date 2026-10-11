/**
 * Stores > Configuration, Sales Igniter sections: filter the settings as the admin types.
 *
 * - A field matches when every word of the search is found in its label, comment, tooltip, hint, setting path or
 *   the label of its group (case and accents ignored). Matches are marked; a match found only in the path shows the
 *   path under the label; a match in a tooltip rings its (?) icon.
 * - Groups with a match are opened and every other group and field is hidden (a class, never an inline style, so
 *   the field dependencies' own show/hide is left alone). A match hidden by a dependency shows the field it depends
 *   on, with "Change this to show n more matching settings".
 * - The other Sales Igniter sections are counted from the server-side index (IndexBuilder) and linked with the term
 *   (?config_search=...), which pre-fills the box there.
 * - Esc or the clear button puts the page back as it was, open groups included. Saving while searching saves the
 *   groups' open state as it was before the search (groups the admin opened or closed by hand stay as they are).
 */
define(['jquery'], function ($) {
    'use strict';

    var HIDE = 'si-cfg-search-hide',
        MARK = 'si-cfg-search-mark',
        PATH = 'si-cfg-search-path',
        HINT = 'si-cfg-search-hint',
        TIP = 'si-cfg-search-tip',
        CONTEXT = 'si-cfg-search-context',
        MATCH = 'si-cfg-search-match';

    /** one character in, one character out, so positions in the folded text are positions in the original */
    function foldChar(c) {
        var d = c.normalize ? c.normalize('NFD') : c;

        return (d.charAt(0) || c).toLowerCase().charAt(0) || c;
    }

    function fold(text) {
        var s = String(text || ''), out = '', i;

        for (i = 0; i < s.length; i++) {
            out += foldChar(s.charAt(i));
        }

        return out;
    }

    function squash(text) {
        return String(text || '').replace(/\s+/g, ' ').trim();
    }

    /** the search as distinct folded words, longest first (so a long word's mark wins over a word inside it) */
    function words(query) {
        var seen = {}, list = [];

        fold(squash(query)).split(' ').forEach(function (w) {
            if (w && !seen[w]) {
                seen[w] = true;
                list.push(w);
            }
        });

        return list.sort(function (a, b) {
            return b.length - a.length;
        });
    }

    function hasAll(hay, list) {
        for (var i = 0; i < list.length; i++) {
            if (hay.indexOf(list[i]) === -1) {
                return false;
            }
        }

        return true;
    }

    function format(pattern) {
        var args = Array.prototype.slice.call(arguments, 1);

        return String(pattern).replace(/%(\d+)/g, function (m, n) {
            var v = args[parseInt(n, 10) - 1];

            return v === undefined ? m : String(v);
        });
    }

    /** add a class; an element that had no class attribute gets none back on reset (the page restored exactly) */
    function addCls(el, cls) {
        if (!el.hasAttribute('class')) {
            el.siCfgNoClass = true;
        }
        el.classList.add(cls);
    }

    function dropCls(el, cls) {
        el.classList.remove(cls);
        if (el.siCfgNoClass && !el.getAttribute('class')) {
            el.removeAttribute('class');
            delete el.siCfgNoClass;
        }
    }

    /** Magento's element id for a config path (section/group/field -> section_group_field) */
    function domId(path) {
        return String(path || '').replace(/\//g, '_');
    }

    function parentPath(path) {
        var p = String(path || '');

        return p.substring(0, p.lastIndexOf('/'));
    }

    function textOf(el) {
        return el ? squash(el.textContent) : '';
    }

    function ConfigSearch(config, root) {
        this.cfg = config || {};
        this.text = this.cfg.text || {};
        this.root = root;
        this.form = document.getElementById('config-edit-form');
        this.input = root.querySelector('.si-cfg-search__input');
        this.clearBtn = root.querySelector('.si-cfg-search__clear');
        this.count = root.querySelector('.si-cfg-search__count');
        this.live = root.querySelector('[role="status"]');
        this.results = root.querySelector('.si-cfg-search__results');
        this.alsoLinks = root.querySelector('.si-cfg-search__also-links');
        this.active = false;
        this.query = '';
        this.model = null;
        this.saved = null;
        this.touched = {};
        this.timer = null;
        this.announceTimer = null;

        if (!this.form || !this.input) {
            return;
        }
        this.bind();
        this.start();
    }

    ConfigSearch.prototype = {
        bind: function () {
            var self = this;

            this.input.addEventListener('input', function () {
                self.clearBtn.hidden = self.input.value === '';
                clearTimeout(self.timer);
                self.timer = setTimeout(function () {
                    self.run(self.input.value);
                }, 120);
            });
            this.input.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' || e.key === 'Esc') {
                    if (self.input.value !== '' || self.active) {
                        e.preventDefault();
                        e.stopPropagation();
                        self.clear();
                    }
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    clearTimeout(self.timer);
                    self.run(self.input.value);
                    self.focusFirst();
                }
            });
            this.clearBtn.addEventListener('click', function () {
                self.clear();
                self.input.focus();
            });
            this.clearBtn.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' || e.key === 'Esc') {
                    e.preventDefault();
                    self.clear();
                    self.input.focus();
                }
            });
            // a group opened or closed by hand while searching keeps the admin's choice when the form is saved
            this.form.addEventListener('click', function (e) {
                var head = e.target.closest ? e.target.closest('a[id$="-head"]') : null;

                if (self.active && head) {
                    self.touched[head.id.replace(/-head$/, '')] = true;
                }
            }, true);
            // Save Config while searching: the groups' open state goes to the server as it was before the search
            $(this.form).on('afterValidate', function () {
                self.restoreStateInputs();
            });
            this.form.addEventListener('submit', function () {
                self.restoreStateInputs();
            });
            // a changed value can show or hide dependent fields: count and hint again
            this.form.addEventListener('change', function () {
                if (self.active) {
                    clearTimeout(self.timer);
                    self.timer = setTimeout(function () {
                        self.run(self.input.value, true);
                    }, 60);
                }
            });
        },

        /** a term carried over from another section: filter once the page's own scripts have set it up */
        start: function () {
            var self = this,
                go = function () {
                    if (squash(self.input.value) === '') {
                        return;
                    }
                    self.clearBtn.hidden = false;
                    self.run(self.input.value);
                    self.input.focus();
                    try {
                        self.input.setSelectionRange(self.input.value.length, self.input.value.length);
                    } catch (e) {
                        // not every input type allows a selection
                    }
                    self.dropParam();
                };

            if (squash(this.input.value) === '') {
                return;
            }
            if (document.readyState === 'complete') {
                setTimeout(go, 0);
            } else {
                window.addEventListener('load', function () {
                    setTimeout(go, 0);
                });
            }
        },

        dropParam: function () {
            var url;

            try {
                url = new URL(window.location.href);
                if (url.searchParams.has(this.cfg.param)) {
                    url.searchParams.delete(this.cfg.param);
                    window.history.replaceState(window.history.state, '', url.toString());
                }
            } catch (e) {
                // an old browser keeps the parameter; harmless
            }
        },

        /* ------------------------------------------------------------------------------------------------ model */

        index: function () {
            var el = document.getElementById(this.cfg.indexId || 'si-cfg-search-index'), data;

            try {
                data = JSON.parse(el ? el.textContent : '{}');
            } catch (e) {
                data = {};
            }

            return Array.isArray(data.sections) ? data.sections : [];
        },

        build: function () {
            var self = this,
                sections = this.index(),
                current = null,
                others = [],
                fieldsById = {},
                groups = [],
                groupsById = {},
                rows = [];

            sections.forEach(function (section) {
                if (section.id === self.cfg.section) {
                    current = section;
                } else {
                    others.push(section);
                }
            });
            if (current) {
                (current.fields || []).forEach(function (f) {
                    fieldsById[domId(f.path)] = f;
                });
            }

            // the groups on this page, outermost first
            Array.prototype.forEach.call(this.form.querySelectorAll('fieldset.config[id]'), function (fieldset) {
                var id = fieldset.id,
                    head = document.getElementById(id + '-head'),
                    parentFieldset = fieldset.parentElement ? fieldset.parentElement.closest('fieldset.config[id]') : null,
                    g = {
                        id: id,
                        fieldset: fieldset,
                        head: head,
                        state: document.getElementById(id + '-state'),
                        wrapper: (head && head.closest('.section-config')) || fieldset.closest('.section-config') || fieldset,
                        comment: fieldset.querySelector(':scope > .comment'),
                        parent: parentFieldset ? parentFieldset.id : null,
                        label: textOf(head) || textOf(fieldset.querySelector(':scope > legend')),
                        rows: []
                    };

                groups.push(g);
                groupsById[id] = g;
            });
            groups.forEach(function (g) {
                var chain = [], p = g;

                while (p) {
                    chain.push(p.label);
                    p = p.parent ? groupsById[p.parent] : null;
                }
                g.chain = chain.join(' ');
            });

            // the settings: rows of each group's own table (not rows of a grid inside a setting)
            Array.prototype.forEach.call(this.form.querySelectorAll('tr[id^="row_"]'), function (tr) {
                var table = tr.parentElement ? tr.parentElement.closest('table') : null,
                    fieldset = table ? table.parentElement : null,
                    g = fieldset && fieldset.id ? groupsById[fieldset.id] : null,
                    id = tr.id.substring(4),
                    f = fieldsById[id] || null,
                    label = tr.querySelector('.label label') || tr.querySelector('td.label'),
                    note = tr.querySelector('.note'),
                    tip = tr.querySelector('.tooltip-content'),
                    seen, path, row;

                if (!g || !fieldset.matches('fieldset.config')) {
                    return;
                }
                seen = [
                    textOf(label), textOf(note), textOf(tip), g.chain,
                    f ? [f.label, f.comment, f.tooltip, f.hint].join(' ') : ''
                ].join(' ');
                path = f ? [f.path, f.config_path || ''].join(' ') : id;
                row = {
                    el: tr,
                    id: id,
                    group: g,
                    field: f,
                    label: label,
                    note: note,
                    tipEl: tip,
                    tooltip: tr.querySelector('.tooltip'),
                    labelCell: tr.querySelector('td.label, .label'),
                    valueCell: tr.querySelector('td.value, .value'),
                    seenHay: fold(seen),
                    tipHay: fold(textOf(tip) || (f && f.tooltip) || ''),
                    // the path the setting is saved under (bin/magento config:show takes it)
                    pathText: f ? (f.config_path || f.path) : '',
                    pathHay: fold(path)
                };
                g.rows.push(row);
                rows.push(row);
            });

            // the other sections, from the index
            others.forEach(function (section) {
                var labels = {};

                (section.groups || []).forEach(function (g) {
                    labels[g.id] = g;
                });
                section.hays = (section.fields || []).map(function (f) {
                    var chain = [], g = labels[domId(parentPath(f.path))];

                    while (g) {
                        chain.push(g.label);
                        g = g.parent ? labels[g.parent] : null;
                    }

                    return fold([f.label, f.comment, f.tooltip, f.hint, f.path, f.config_path, chain.join(' ')].join(' '));
                });
            });

            this.model = {current: current, others: others, groups: groups, groupsById: groupsById, rows: rows};
        },

        /* ---------------------------------------------------------------------------------------------- search */

        run: function (raw, again) {
            var query = squash(raw),
                list = words(query),
                m, shown, hiddenMatches, count;

            if (query.length < (this.cfg.minLength || 2) || !list.length) {
                if (this.active) {
                    this.reset();
                    this.restoreGroups();
                    this.active = false;
                    this.root.classList.remove('is-active', 'is-empty');
                }
                this.query = query;
                this.results.hidden = true;
                this.say('');

                return;
            }
            if (!this.model) {
                this.build();
            }
            m = this.model;
            if (!this.active) {
                this.snapshot();
                this.active = true;
                this.root.classList.add('is-active');
            }
            if (query === this.query && !again) {
                return;
            }
            this.query = query;
            this.reset();

            shown = {};
            hiddenMatches = [];
            count = 0;
            m.rows.forEach(function (row) {
                var matched = hasAll(row.seenHay + ' ' + row.pathHay, list);

                row.matched = matched;
                row.pathOnly = matched && !hasAll(row.seenHay, list);
                if (!matched) {
                    return;
                }
                if (window.getComputedStyle(row.el).display === 'none') {
                    hiddenMatches.push(row);
                } else {
                    shown[row.id] = row;
                    count++;
                }
            });

            // a match hidden by a dependency: show what it depends on, and say so there
            var context = {}, self = this;

            hiddenMatches.forEach(function (row) {
                var master = self.visibleMaster(row, 0);

                if (master) {
                    context[master.id] = context[master.id] || {row: master, n: 0};
                    context[master.id].n++;
                    count++;
                }
            });

            m.rows.forEach(function (row) {
                if (shown[row.id]) {
                    addCls(row.el, MATCH);
                    self.decorate(row, list);
                } else if (context[row.id]) {
                    addCls(row.el, CONTEXT);
                } else {
                    addCls(row.el, HIDE);
                }
            });
            Object.keys(context).forEach(function (id) {
                self.hint(context[id].row, context[id].n);
            });

            // groups: open the ones with something to show, hide the rest
            m.groups.forEach(function (g) {
                g.hasRows = g.rows.some(function (row) {
                    return !row.el.classList.contains(HIDE);
                });
            });
            m.groups.forEach(function (g) {
                var p = g.parent ? m.groupsById[g.parent] : null;

                if (g.hasRows) {
                    while (p) {
                        p.hasRows = true;
                        p = p.parent ? m.groupsById[p.parent] : null;
                    }
                }
            });
            m.groups.forEach(function (g) {
                if (g.hasRows) {
                    self.setOpen(g, true);
                    self.mark(g.head, list);
                    self.mark(g.comment, list);
                } else {
                    addCls(g.wrapper, HIDE);
                }
            });

            this.render(query, list, count);
        },

        /** the nearest field this hidden one depends on that is on the page and shown (up to three steps) */
        visibleMaster: function (row, depth) {
            var deps = row.field && row.field.depends ? row.field.depends : [],
                i, el, master, found;

            if (depth > 2) {
                return null;
            }
            for (i = 0; i < deps.length; i++) {
                el = document.getElementById('row_' + deps[i]);
                master = el ? this.rowOf(el) : null;
                if (!master) {
                    continue;
                }
                if (window.getComputedStyle(master.el).display !== 'none') {
                    return master;
                }
                found = this.visibleMaster(master, depth + 1);
                if (found) {
                    return found;
                }
            }

            return null;
        },

        rowOf: function (el) {
            var rows = this.model.rows, i;

            for (i = 0; i < rows.length; i++) {
                if (rows[i].el === el) {
                    return rows[i];
                }
            }

            return null;
        },

        decorate: function (row, list) {
            var code, wrap;

            this.mark(row.label, list);
            this.mark(row.note, list);
            if (row.tooltip && row.tipEl && hasAny(row.tipHay, list)) {
                addCls(row.tooltip, TIP);
                this.mark(row.tipEl, list);
            }
            if (row.pathOnly && row.pathText && row.labelCell) {
                wrap = document.createElement('div');
                wrap.className = PATH;
                code = document.createElement('code');
                code.textContent = row.pathText;
                code.setAttribute('title', this.text.path || '');
                wrap.appendChild(code);
                row.labelCell.appendChild(wrap);
                this.mark(code, list);
            }
        },

        hint: function (row, n) {
            var div;

            if (!row.valueCell) {
                return;
            }
            div = document.createElement('div');
            div.className = HINT;
            div.textContent = n === 1 ? this.text.hintOne : format(this.text.hintMany, n);
            row.valueCell.appendChild(div);
        },

        /** wrap every occurrence of the words in the element's text in <mark> */
        mark: function (el, list) {
            var walker, nodes = [], node;

            if (!el) {
                return;
            }
            walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, {
                acceptNode: function (n) {
                    var p = n.parentNode;

                    if (!n.nodeValue || !n.nodeValue.trim() || !p || p.closest('script,style,select,option,textarea,button,mark')) {
                        return NodeFilter.FILTER_REJECT;
                    }

                    return NodeFilter.FILTER_ACCEPT;
                }
            });
            while ((node = walker.nextNode())) {
                nodes.push(node);
            }
            nodes.forEach(function (n) {
                var text = n.nodeValue,
                    hay = fold(text),
                    ranges = [],
                    merged = [],
                    frag, at;

                list.forEach(function (w) {
                    var i = hay.indexOf(w);

                    while (i !== -1) {
                        ranges.push([i, i + w.length]);
                        i = hay.indexOf(w, i + w.length);
                    }
                });
                if (!ranges.length) {
                    return;
                }
                ranges.sort(function (a, b) {
                    return a[0] - b[0] || b[1] - a[1];
                }).forEach(function (r) {
                    var last = merged[merged.length - 1];

                    if (last && r[0] <= last[1]) {
                        last[1] = Math.max(last[1], r[1]);
                    } else {
                        merged.push([r[0], r[1]]);
                    }
                });
                frag = document.createDocumentFragment();
                at = 0;
                merged.forEach(function (r) {
                    var mk = document.createElement('mark');

                    if (r[0] > at) {
                        frag.appendChild(document.createTextNode(text.substring(at, r[0])));
                    }
                    mk.className = MARK;
                    mk.textContent = text.substring(r[0], r[1]);
                    frag.appendChild(mk);
                    at = r[1];
                });
                if (at < text.length) {
                    frag.appendChild(document.createTextNode(text.substring(at)));
                }
                n.parentNode.replaceChild(frag, n);
            });
        },

        /** take every mark, hint, path, class and hidden group off the page (the open groups stay) */
        reset: function () {
            var form = this.form, parents = [];

            Array.prototype.forEach.call(form.querySelectorAll('mark.' + MARK), function (mk) {
                var p = mk.parentNode;

                p.replaceChild(document.createTextNode(mk.textContent), mk);
                if (parents.indexOf(p) === -1) {
                    parents.push(p);
                }
            });
            parents.forEach(function (p) {
                p.normalize();
            });
            Array.prototype.forEach.call(form.querySelectorAll('.' + PATH + ', .' + HINT), function (el) {
                el.parentNode.removeChild(el);
            });
            [HIDE, CONTEXT, MATCH, TIP].forEach(function (cls) {
                Array.prototype.forEach.call(form.querySelectorAll('.' + cls), function (el) {
                    dropCls(el, cls);
                });
            });
        },

        /* ---------------------------------------------------------------------------------------------- groups */

        isOpen: function (g) {
            if (g.state) {
                return g.state.value === '1';
            }

            return g.fieldset.style.display !== 'none';
        },

        /** open or close a group the way the head link does, without saving the state to the admin's profile */
        setOpen: function (g, open) {
            if (this.isOpen(g) === open && (g.fieldset.style.display === 'none') !== open) {
                return;
            }
            if (g.state) {
                g.state.value = open ? '1' : '0';
            }
            if (g.head) {
                g.head.collapsed = open ? 0 : 1;
            }
            if (window.Fieldset && typeof window.Fieldset.applyCollapse === 'function' && g.head) {
                window.Fieldset.applyCollapse(g.id);
            } else {
                g.fieldset.style.display = open ? '' : 'none';
                if (g.head) {
                    g.head.classList.toggle('open', open);
                }
                g.wrapper.classList.toggle('active', open);
            }
        },

        snapshot: function () {
            var self = this;

            this.touched = {};
            this.saved = this.model.groups.map(function (g) {
                return {g: g, open: self.isOpen(g), state: g.state ? g.state.value : null};
            });
        },

        restoreGroups: function () {
            var self = this;

            (this.saved || []).forEach(function (s) {
                self.setOpen(s.g, s.open);
                if (s.g.state && s.state !== null) {
                    s.g.state.value = s.state;
                }
            });
            this.saved = null;
            this.touched = {};
        },

        restoreStateInputs: function () {
            var self = this;

            if (!this.active) {
                return;
            }
            (this.saved || []).forEach(function (s) {
                if (s.g.state && s.state !== null && !self.touched[s.g.id]) {
                    s.g.state.value = s.state;
                }
            });
        },

        /* --------------------------------------------------------------------------------------------- results */

        render: function (query, list, count) {
            var self = this,
                m = this.model,
                links = [],
                sectionLabel = m.current ? m.current.label : '',
                sentence;

            this.alsoLinks.textContent = '';
            m.others.forEach(function (section) {
                var n = 0, a, url;

                section.hays.forEach(function (hay) {
                    if (hasAll(hay, list)) {
                        n++;
                    }
                });
                if (!n) {
                    return;
                }
                a = document.createElement('a');
                try {
                    url = new URL(section.url, window.location.href);
                    url.searchParams.set(self.cfg.param, query);
                    a.href = url.toString();
                } catch (e) {
                    a.href = section.url + (section.url.indexOf('?') === -1 ? '?' : '&')
                        + encodeURIComponent(self.cfg.param) + '=' + encodeURIComponent(query);
                }
                a.className = 'si-cfg-search__also-link';
                a.setAttribute('data-section', section.id);
                a.textContent = section.label + ' (' + n + ')';
                links.push(a);
            });
            links.forEach(function (a) {
                self.alsoLinks.appendChild(a);
            });
            if (count === 0) {
                sentence = links.length && sectionLabel
                    ? format(this.text.noneHere, query, sectionLabel)
                    : format(this.text.none, query);
            } else {
                sentence = count === 1 ? this.text.countOne : format(this.text.countMany, count);
            }
            this.results.hidden = !links.length;
            this.root.classList.toggle('is-empty', count === 0);
            this.say(sentence, links.length ? sentence + ' ' + textOf(this.results) : sentence);
        },

        /** the count at once; the live region (screen readers) a moment after the typing stops */
        say: function (visible, spoken) {
            var self = this;

            clearTimeout(this.announceTimer);
            this.count.textContent = visible || '';
            if (!visible) {
                this.live.textContent = '';

                return;
            }
            this.announceTimer = setTimeout(function () {
                self.live.textContent = spoken || visible;
            }, 400);
        },

        focusFirst: function () {
            var rows = this.model ? this.model.rows : [], i, target;

            for (i = 0; i < rows.length; i++) {
                if (rows[i].el.classList.contains(MATCH)) {
                    target = rows[i].el.querySelector(
                        'input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled]), a[href]'
                    );
                    if (target) {
                        target.focus();
                        target.scrollIntoView({block: 'center'});

                        return;
                    }
                }
            }
        },

        clear: function () {
            clearTimeout(this.timer);
            this.input.value = '';
            this.clearBtn.hidden = true;
            this.run('');
            this.dropParam();
        }
    };

    function hasAny(hay, list) {
        for (var i = 0; i < list.length; i++) {
            if (hay.indexOf(list[i]) !== -1) {
                return true;
            }
        }

        return false;
    }

    return function (config, element) {
        return new ConfigSearch(config, element);
    };
});
