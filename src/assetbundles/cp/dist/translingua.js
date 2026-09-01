/**
 * Translingua — control panel field translation.
 *
 * Works entirely against the DOM rather than against Craft's field classes.
 * That's deliberate: it's why the buttons show up inside Formie, SEOmatic and
 * anything else that renders a normal input, without the plugin needing to know
 * those plugins exist. Nothing is saved here — translations are written into the
 * inputs and the editor still presses Save.
 */
(function () {
    'use strict';

    var SOURCE_KEY = 'translingua.sourceLanguage';
    var MARK = 'translinguaAttached';

    // Matched on the exact input name, not as a substring, so a custom field
    // handled `notesField` is unaffected.
    var CP_METADATA_INPUTS = ['notes'];

    var Translingua = {
        settings: null,
        booted: false,
        pageMenu: null,
        pageCaret: null,
        floating: null,
        floatingTarget: null,

        init: function (settings) {
            if (this.booted) {
                return;
            }

            this.booted = true;
            this.settings = settings;

            var self = this;

            var start = function () {
                self.scan(document);
                self.addPageButton();
                self.observe();
                self.bindFloating();
            };

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', start);
            } else {
                start();
            }
        },

        // Discovery
        // ---------------------------------------------------------------

        /**
         * Returns a read/write adapter for a node, or null when it isn't a
         * translatable input.
         */
        adapterFor: function (el) {
            if (!el || el[MARK] === 'skip') {
                return null;
            }

            if (el.classList && el.classList.contains('ck-editor__editable')) {
                return {
                    el: el,
                    format: 'html',
                    get: function () {
                        return el.ckeditorInstance ? el.ckeditorInstance.getData() : el.innerHTML;
                    },
                    set: function (value) {
                        if (el.ckeditorInstance) {
                            el.ckeditorInstance.setData(value);
                        } else {
                            el.innerHTML = value;
                        }
                    }
                };
            }

            var tag = el.tagName;

            if (tag === 'TEXTAREA' || tag === 'INPUT') {
                return {
                    el: el,
                    format: 'text',
                    get: function () {
                        return el.value;
                    },
                    set: function (value) {
                        el.value = value;
                        el.dispatchEvent(new Event('input', {bubbles: true}));
                        el.dispatchEvent(new Event('change', {bubbles: true}));
                    }
                };
            }

            if (el.isContentEditable) {
                return {
                    el: el,
                    format: 'html',
                    get: function () {
                        return el.innerHTML;
                    },
                    set: function (value) {
                        el.innerHTML = value;
                        el.dispatchEvent(new Event('input', {bubbles: true}));
                    }
                };
            }

            return null;
        },

        /**
         * Whether a node holds editable copy worth translating.
         */
        isTranslatable: function (el) {
            if (!el || !el.isConnected) {
                return false;
            }

            if (el.disabled || el.readOnly || el.getAttribute('aria-readonly') === 'true') {
                return false;
            }

            if (el.closest('[data-translingua-skip], .tp-no-translate, #global-sidebar, #global-header, .modal-shade')) {
                return false;
            }

            // CKEditor keeps a hidden source textarea in sync; translating it
            // would be overwritten by the editor on save.
            if (el.tagName === 'TEXTAREA' && el.classList.contains('hidden')) {
                return false;
            }

            if (el.classList.contains('code') || el.classList.contains('ck-hidden')) {
                return false;
            }

            if (el.tagName === 'INPUT') {
                var type = (el.getAttribute('type') || 'text').toLowerCase();

                if (type !== 'text' && type !== 'search') {
                    return false;
                }

                // Search boxes are UI, not content.
                if (type === 'search' || el.classList.contains('filter-search')) {
                    return false;
                }

                if (el.closest('.texticon.search, #search-container, .elementselect, .autosuggest-container, .selectize, .datewrapper, .timewrapper')) {
                    return false;
                }
            }

            var identity = ((el.getAttribute('name') || '') + ' ' + (el.getAttribute('id') || '')).toLowerCase();
            var patterns = (this.settings && this.settings.excludePatterns) || [];

            for (var i = 0; i < patterns.length; i++) {
                if (patterns[i] && identity.indexOf(patterns[i]) !== -1) {
                    return false;
                }
            }

            // Inside Formie the set of translatable settings is fixed, so an
            // allowlist replaces the usual "anything you can type" rule.
            var allowed = (this.settings && this.settings.attributeAllowlist) || [];

            if (allowed.length && allowed.indexOf(this.attributeOf(el)) === -1) {
                return false;
            }

            // Set by markAddressLinks(): an email/phone/SMS link's label is
            // the address itself far more often than not, and translating it
            // breaks a working link.
            if (el.closest('[data-tp-address-link]')) {
                return false;
            }

            // The value side of a Link field is a URL, address or element
            // reference — never copy. Only its Label is.
            if (el.closest('[data-link-type]')) {
                return false;
            }

            // Craft's own element-editor metadata, not content: the revision
            // notes box in the sidebar describes the change, it isn't part of it.
            if (CP_METADATA_INPUTS.indexOf(el.getAttribute('name')) !== -1) {
                return false;
            }

            return true;
        },

        /**
         * Flags Link fields that point at an email address, phone number or SMS
         * number, so nothing inside them gets a translate button.
         *
         * Done as a marking pass rather than by walking up from each input:
         * Craft renders the Label field as a *sibling* of `.link-input`, inside
         * a shared wrapper, so there's nothing to find by climbing from the
         * label itself.
         */
        markAddressLinks: function (root) {
            var scope = root.querySelectorAll ? root : document;

            scope.querySelectorAll('[data-link-field], .link-input').forEach(function (linkInput) {
                // The wrapper holding both the link input and its Label field.
                var wrapper = linkInput.parentElement;

                if (!wrapper) {
                    return;
                }

                // A <select> when several link types are allowed, a hidden
                // input when the field is locked to one.
                var typeInput = linkInput.querySelector(
                    'select[name$="[type]"], input[type="hidden"][name$="[type]"]'
                );

                var type = typeInput ? typeInput.value : null;

                if (!type) {
                    var shown = linkInput.querySelector('[data-link-type]:not(.hidden)');
                    type = shown ? shown.getAttribute('data-link-type') : null;
                }

                if (['email', 'tel', 'sms'].indexOf(type) !== -1) {
                    wrapper.setAttribute('data-tp-address-link', '');
                } else {
                    wrapper.removeAttribute('data-tp-address-link');
                }
            });
        },

        /**
         * Drops any buttons already inside a Link field that has just been
         * switched to an email or phone link.
         */
        pruneAddressLinks: function () {
            document.querySelectorAll('[data-tp-address-link] .tp-btn').forEach(function (button) {
                button.remove();
            });

            // Let a field switched *back* to a URL pick up a button again.
            document.querySelectorAll(
                '[data-link-field], .link-input'
            ).forEach(function (linkInput) {
                var wrapper = linkInput.parentElement;

                if (wrapper && !wrapper.hasAttribute('data-tp-address-link')) {
                    wrapper.querySelectorAll('textarea, input').forEach(function (el) {
                        el[MARK] = false;
                    });
                }
            });
        },

        /**
         * The setting an input edits.
         *
         * Craft stamps `data-attribute` on the field wrapper, which is the most
         * reliable answer and the only one that works for rich-text editors with
         * no input of their own. Otherwise the last `[...]` segment of the name
         * (`fields[2][label]` -> `label`), or the name/id as-is.
         */
        attributeOf: function (el) {
            var wrapper = el.closest('[data-attribute]');

            if (wrapper) {
                return wrapper.getAttribute('data-attribute');
            }

            var name = el.getAttribute('name') || '';
            var brackets = name.match(/\[([^\[\]]+)\]\s*$/);

            if (brackets) {
                return brackets[1];
            }

            return name || el.getAttribute('id') || '';
        },

        /**
         * All translatable adapters under a root.
         *
         * @param includeHidden include inputs that aren't currently on screen —
         *        used by the page button so collapsed sections aren't silently missed.
         */
        collect: function (root, includeHidden) {
            var nodes = root.querySelectorAll(
                'textarea, input[type="text"], input:not([type]), .ck-editor__editable[contenteditable="true"]'
            );

            var adapters = [];
            var self = this;

            Array.prototype.forEach.call(nodes, function (el) {
                if (!self.isTranslatable(el)) {
                    return;
                }

                if (!includeHidden && !self.isVisible(el)) {
                    return;
                }

                var adapter = self.adapterFor(el);

                if (adapter) {
                    adapters.push(adapter);
                }
            });

            return adapters;
        },

        /**
         * Values that are plainly not copy: URLs, paths, emails, phone numbers,
         * bare numbers and tokens. Field names alone don't catch these — Formie's
         * "Redirect URL" is called `submitActionUrl` — so the value is checked too.
         */
        isNotProse: function (value) {
            var v = value.trim();

            // Phone numbers carry spaces, so they're checked before the
            // "has a space, must be a sentence" shortcut below.
            if (/^[+(]?[\d][\d\s().\/-]{5,}$/.test(v)) {
                return true;
            }

            if (/\s/.test(v)) {
                // Anything else with a space is prose, not an identifier.
                return false;
            }

            return /^(https?:\/\/|\/\/|\/|\.{1,2}\/|mailto:|tel:|#|\{)/i.test(v)
                || /^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i.test(v)
                || /^-?\d+([.,]\d+)?$/.test(v);
        },

        isVisible: function (el) {
            return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
        },

        // Per-field buttons
        // ---------------------------------------------------------------

        scan: function (root) {
            if (!this.settings || !this.settings.enableFieldButtons) {
                return;
            }

            var self = this;

            this.markAddressLinks(root);

            // Hidden fields are included on purpose: tabbed panels (Formie's
            // field settings, SEOmatic, Craft's own tabs) are in the DOM but
            // off-screen when they're inserted, and would otherwise never get
            // a button. The button is hidden along with its field anyway.
            this.collect(root, true).forEach(function (adapter) {
                self.attachButton(adapter);
            });
        },

        /**
         * Adds a button to the field's label row when there is one. Fields
         * rendered without Craft's `.field` wrapper fall back to the floating
         * button, so nothing has to be injected into unfamiliar markup.
         */
        attachButton: function (adapter) {
            var el = adapter.el;

            if (el[MARK]) {
                return;
            }

            el[MARK] = true;

            var field = el.closest('.field');
            var heading = field ? field.querySelector(':scope > .heading') : null;

            if (!heading) {
                return;
            }

            if (heading.querySelector('.tp-btn')) {
                // One button per label, even when a field holds several inputs.
                return;
            }

            var button = this.buildButton();
            var self = this;

            button.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                self.translateFields([adapter], button);
            });

            // Straight after the label: `.heading` is a block, so anything
            // placed after its `.flex-grow` spacer wraps onto its own line.
            var label = heading.querySelector(':scope > label, :scope > legend');

            if (label) {
                label.insertAdjacentElement('afterend', button);
            } else {
                heading.appendChild(button);
            }
        },

        buildButton: function () {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'tp-btn';
            button.innerHTML = this.icon() + '<span class="tp-btn-lang"></span>';

            this.labelButton(button);

            return button;
        },

        /**
         * The short `NL → EN` form shown on the page button.
         */
        directionChip: function () {
            var source = this.sourceLanguage();
            var target = (this.targetLanguage() || '').toUpperCase();
            var from = source ? source.toUpperCase() : this.settings.strings.autoShort;

            return from + ' → ' + target;
        },

        /**
         * Spells out both directions in words, for tooltips.
         */
        directionLabel: function () {
            var source = this.sourceLanguage();
            var target = this.targetLanguage();
            var targetName = this.languageName(target) + ' (' + target + ')';

            if (!source) {
                return Craft.t('translingua', 'Translate into {target}, detecting the source language', {
                    target: targetName,
                });
            }

            return Craft.t('translingua', 'Translate from {source} into {target}', {
                source: this.languageName(source) + ' (' + source + ')',
                target: targetName,
            });
        },

        /**
         * Labels a field button with the full direction, e.g. `AUTO → EN`.
         *
         * This is where it matters: you're about to rewrite one value, and the
         * direction decides what you get. The page button stays with the target
         * alone, since its own text already says what it does.
         */
        labelButton: function (button) {
            var chip = button.querySelector('.tp-btn-lang');
            var label = this.directionLabel();

            if (chip) {
                chip.textContent = this.directionChip();
            }

            button.title = label;
            button.setAttribute('aria-label', label);
        },

        /**
         * Re-labels every button after the source language is changed.
         */
        refreshLabels: function () {
            var self = this;

            document.querySelectorAll('.tp-btn').forEach(function (button) {
                self.labelButton(button);
            });

            var pageChip = document.querySelector('.tp-page-btn .tp-page-lang');

            if (pageChip) {
                pageChip.textContent = (self.targetLanguage() || '').toUpperCase();
            }

            var pageBtn = document.querySelector('.tp-page-btn');

            if (pageBtn) {
                pageBtn.title = self.directionLabel();
            }

            var target = document.querySelector('.tp-menu-target');

            if (target) {
                target.textContent = self.menuTargetText();
            }
        },

        icon: function () {
            return '<svg viewBox="0 0 589 589" width="13" height="13" fill="none" aria-hidden="true" focusable="false">' +
                '<path fill="currentColor" d="M341.07 266.481H372.34L307.394 476.955H273.117L341.07 266.481ZM385.87 266.481L455.327 476.955H421.049L354.299 266.481H385.87ZM305.89 399.38H419.245V428.245H305.89V399.38Z"/>' +
                '<path fill="currentColor" d="M249.22 108.698V147.577H344.588V169.272H308.585C299.144 195.507 287.205 218.352 272.746 237.78C267.162 245.358 261.156 252.493 254.73 259.188C260.648 264.042 266.892 268.597 273.464 272.85C293.928 286.091 317.822 295.977 345.182 302.477L349.855 303.586L346.265 306.777C345.025 307.88 343.604 309.364 342 311.255C340.507 313.344 339.015 315.433 337.523 317.521C336.175 319.619 335.059 321.407 334.169 322.89L333.208 324.493L331.398 324.023C302.953 316.643 278.057 305.71 256.748 291.194L256.739 291.188L256.731 291.183C249.79 286.37 243.191 281.212 236.932 275.716C230.959 280.656 224.694 285.29 218.136 289.614L218.135 289.613C197.031 303.63 172.504 315.157 144.576 324.211L142.351 324.932L141.483 322.761C140.967 321.471 140.086 319.887 138.776 317.995L138.738 317.94L138.702 317.883C137.515 315.955 136.176 313.944 134.68 311.851L134.645 311.801L134.612 311.749C133.296 309.702 132.109 308.183 131.059 307.134L128.075 304.149L132.125 302.966C159.08 295.091 182.568 284.811 202.618 272.148C208.803 268.195 214.7 263.961 220.309 259.448C214.06 252.633 208.231 245.398 202.826 237.74L202.82 237.732V237.731C188.684 217.558 176.962 194.733 167.638 169.272H132.842V147.577H226.61V108.698H249.22ZM191.746 169.272C199.569 189.404 209.491 207.74 221.513 224.29C226.535 231.075 231.959 237.489 237.779 243.536C243.436 237.706 248.724 231.517 253.639 224.964C265.522 208.99 275.421 190.433 283.322 169.272H191.746Z"/>' +
                '<path fill-rule="evenodd" clip-rule="evenodd" fill="currentColor" d="M588.154 294.077C588.154 456.702 456.702 588.154 294.077 588.154C131.452 588.154 0 456.702 0 294.077C0 131.452 131.452 0 294.077 0C456.702 0 588.154 131.452 588.154 294.077ZM559.154 294.077C559.154 441.354 441.354 559.154 294.077 559.154C146.8 559.154 29 441.354 29 294.077C29 146.8 146.8 29 294.077 29C441.354 29 559.154 146.8 559.154 294.077Z"/>' +
                '</svg>';
        },

        // Floating button for inputs without a Craft field wrapper
        // ---------------------------------------------------------------

        bindFloating: function () {
            if (!this.settings.enableFieldButtons) {
                return;
            }

            var self = this;

            var show = function (e) {
                var el = e.target;

                if (!el || !el.closest) {
                    return;
                }

                var candidate = el.closest('textarea, input, .ck-editor__editable');

                if (!candidate || !self.isTranslatable(candidate)) {
                    return self.hideFloating();
                }

                // Fields that already have an inline button don't need this one.
                var field = candidate.closest('.field');

                if (field && field.querySelector(':scope > .heading .tp-btn')) {
                    return self.hideFloating();
                }

                var adapter = self.adapterFor(candidate);

                if (adapter) {
                    self.showFloating(adapter);
                }
            };

            document.addEventListener('focusin', show, true);
            document.addEventListener('mouseover', show, true);

            // Switching a Link field between URL and email/phone changes whether
            // it may be translated, and no nodes are added, so the mutation
            // observer wouldn't notice.
            document.addEventListener('change', function (e) {
                if (e.target && e.target.matches && e.target.matches('select[name$="[type]"]')) {
                    self.markAddressLinks(document);
                    self.pruneAddressLinks();
                    self.scan(document);
                }
            }, true);

            window.addEventListener('scroll', function () {
                self.positionFloating();
            }, true);

            window.addEventListener('resize', function () {
                self.positionFloating();
            });
        },

        showFloating: function (adapter) {
            if (!this.floating) {
                this.floating = this.buildButton();
                this.floating.classList.add('tp-btn--floating');
                document.body.appendChild(this.floating);

                var self = this;

                this.floating.addEventListener('click', function (e) {
                    e.preventDefault();

                    if (self.floatingTarget) {
                        self.translateFields([self.floatingTarget], self.floating);
                    }
                });
            }

            this.floatingTarget = adapter;
            this.floating.hidden = false;
            this.positionFloating();
        },

        hideFloating: function () {
            if (this.floating && !this.floating.matches(':hover')) {
                this.floating.hidden = true;
                this.floatingTarget = null;
            }
        },

        positionFloating: function () {
            if (!this.floating || this.floating.hidden || !this.floatingTarget) {
                return;
            }

            var rect = this.floatingTarget.el.getBoundingClientRect();

            if (!rect.width && !rect.height) {
                this.floating.hidden = true;
                return;
            }

            this.floating.style.top = (window.scrollY + rect.top + 3) + 'px';
            this.floating.style.left = (window.scrollX + rect.right - 27) + 'px';
        },

        // Page button
        // ---------------------------------------------------------------

        addPageButton: function () {
            if (!this.settings.enablePageButton) {
                return;
            }

            var container = this.actionBar();

            if (!container || container.querySelector('.tp-page-btn')) {
                return;
            }

            // Nothing to translate means no reason to add the button.
            if (this.collect(document, true).length === 0) {
                return;
            }

            var wrapper = document.createElement('div');
            wrapper.className = 'tp-page';

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn tp-page-btn';
            button.innerHTML = this.icon()
                + '<span>' + this.escape(this.settings.strings.translatePage) + '</span>'
                + '<span class="tp-page-lang">' + this.escape((this.targetLanguage() || '').toUpperCase()) + '</span>';

            button.title = this.directionLabel();

            var caret = document.createElement('button');
            caret.type = 'button';
            caret.className = 'btn menubtn tp-page-caret';
            caret.setAttribute('aria-label', this.settings.strings.translateFrom);

            var menu = this.buildLanguageMenu();

            wrapper.appendChild(button);
            wrapper.appendChild(caret);

            // The menu lives on <body>, not inside the wrapper. The control
            // panel header is its own stacking context, so a menu nested in it
            // renders *behind* the page content no matter how high its z-index
            // goes. Positioned against the caret on open instead.
            if (this.pageMenu && this.pageMenu.parentElement) {
                // The toolbar was rebuilt; drop the previous menu rather than
                // stacking another copy onto <body>.
                this.pageMenu.remove();
            }

            document.body.appendChild(menu);

            container.insertBefore(wrapper, container.firstChild);

            var self = this;

            this.pageMenu = menu;
            this.pageCaret = caret;

            button.addEventListener('click', function (e) {
                e.preventDefault();
                self.translatePage(button);
            });

            caret.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

                if (menu.hidden) {
                    self.openMenu();
                } else {
                    self.closeMenu();
                }
            });

            document.addEventListener('click', function (e) {
                if (!wrapper.contains(e.target) && !menu.contains(e.target)) {
                    self.closeMenu();
                }
            });

            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    self.closeMenu();
                }
            });

            // Anchored to a fixed position, so it has to follow the caret.
            window.addEventListener('scroll', function () {
                self.positionMenu();
            }, true);

            window.addEventListener('resize', function () {
                self.positionMenu();
            });
        },

        /**
         * The toolbar to put the page button in.
         *
         * `#action-buttons` covers most screens, but Craft's element editor
         * rebuilds its toolbar as the draft state changes and doesn't always
         * carry that id, so fall back to whatever holds the Save button.
         */
        actionBar: function () {
            var container = document.querySelector('#action-buttons');

            if (container) {
                return container;
            }

            var save = document.querySelector(
                '#header .btn.submit, #header button[type="submit"], #header input[type="submit"]'
            );

            if (!save) {
                return null;
            }

            // Step out of any button group so we sit beside Save, not inside it.
            var group = save.closest('.btngroup, .btn-group');

            return (group || save).parentElement;
        },

        openMenu: function () {
            if (!this.pageMenu) {
                return;
            }

            this.pageMenu.hidden = false;
            this.pageCaret.setAttribute('aria-expanded', 'true');
            this.positionMenu();
        },

        closeMenu: function () {
            if (!this.pageMenu || this.pageMenu.hidden) {
                return;
            }

            this.pageMenu.hidden = true;
            this.pageCaret.setAttribute('aria-expanded', 'false');
        },

        /**
         * Pins the menu under the caret, kept inside the viewport.
         */
        positionMenu: function () {
            if (!this.pageMenu || this.pageMenu.hidden || !this.pageCaret) {
                return;
            }

            var anchor = this.pageCaret.getBoundingClientRect();

            if (!anchor.width && !anchor.height) {
                // The button scrolled out of a sticky header, or was removed.
                this.closeMenu();
                return;
            }

            var menu = this.pageMenu;
            var width = menu.offsetWidth;
            var margin = 8;

            // Right-aligned with the caret, then pulled back inside the viewport.
            var left = Math.min(
                Math.max(margin, anchor.right - width),
                window.innerWidth - width - margin
            );

            menu.style.left = Math.round(left) + 'px';
            menu.style.top = Math.round(anchor.bottom + 7) + 'px';
        },

        /**
         * The button menu.
         *
         * There's no "translate into" picker: the target is always the site
         * you're editing (or, for a Formie form, the language set on its
         * Translingua tab). Picking it by hand only ever produced content in
         * the wrong site. The source is detected by the provider unless you
         * override it.
         */
        buildLanguageMenu: function () {
            // Deliberately not Craft's `.menu` class: its own padding and list
            // styles fought ours and left the panel with a stray scrollbar.
            var menu = document.createElement('div');
            menu.className = 'tp-menu';
            menu.hidden = true;

            var into = document.createElement('div');
            into.className = 'tp-menu-target';
            into.textContent = this.menuTargetText();
            menu.appendChild(into);

            menu.appendChild(this.buildSourceList());

            return menu;
        },

        menuTargetText: function () {
            var target = this.targetLanguage();

            if (!target) {
                return this.settings.strings.noTarget;
            }

            return Craft.t('translingua', 'Translating into {language}', {
                language: this.languageName(target) + ' (' + target + ')',
            });
        },

        /**
         * The optional "translate from" override.
         */
        buildSourceList: function () {
            var section = document.createElement('div');
            section.className = 'tp-menu-section';

            var title = document.createElement('h6');
            title.textContent = this.settings.strings.translateFrom;
            section.appendChild(title);

            var list = document.createElement('ul');
            var self = this;
            var selected = this.stored(SOURCE_KEY);

            var options = [{language: '', name: this.settings.strings.autoDetect}];

            this.languages().forEach(function (lang) {
                if (lang.language !== self.targetLanguage()) {
                    options.push(lang);
                }
            });

            options.forEach(function (lang) {
                var item = document.createElement('li');
                var link = document.createElement('a');
                link.href = '#';
                link.textContent = lang.language
                    ? lang.name + ' (' + lang.language + ')'
                    : lang.name;
                link.title = link.textContent;

                if ((selected || '') === lang.language) {
                    link.classList.add('sel');
                }

                link.addEventListener('click', function (e) {
                    e.preventDefault();

                    try {
                        window.localStorage.setItem(SOURCE_KEY, lang.language);
                    } catch (err) {
                        // Private browsing — the choice just won't be remembered.
                    }

                    list.querySelectorAll('a').forEach(function (a) {
                        a.classList.remove('sel');
                    });

                    link.classList.add('sel');
                    self.refreshLabels();
                });

                item.appendChild(link);
                list.appendChild(item);
            });

            section.appendChild(list);

            return section;
        },

        /**
         * The distinct languages across all sites.
         */
        languages: function () {
            var seen = {};
            var langs = [];

            this.settings.sites.forEach(function (site) {
                if (!seen[site.language]) {
                    seen[site.language] = true;
                    langs.push({
                        language: site.language,
                        name: site.languageName || site.name,
                    });
                }
            });

            return langs;
        },

        languageName: function (language) {
            var match = this.languages().filter(function (l) {
                return l.language === language;
            })[0];

            return match ? match.name : language;
        },

        currentLanguage: function () {
            var current = this.settings.sites.filter(function (site) {
                return site.id === this.settings.currentSiteId;
            }, this)[0];

            return current ? current.language : null;
        },

        stored: function (key) {
            try {
                return window.localStorage.getItem(key);
            } catch (e) {
                return null;
            }
        },

        /**
         * The language being translated *into*: always the site being edited,
         * resolved server-side. Not overridable — translating into anything but
         * the site you're looking at just writes the wrong content.
         */
        targetLanguage: function () {
            return this.settings.targetLanguage || null;
        },

        /**
         * The language being translated *from*, or null to let the provider
         * detect it. Detection is the default: the text is right there in the
         * field, and the editor shouldn't have to tell us what language it's in.
         */
        sourceLanguage: function () {
            var stored = this.stored(SOURCE_KEY);

            if (!stored) {
                return null;
            }

            var known = this.languages().filter(function (l) {
                return l.language === stored;
            })[0];

            return known && known.language !== this.targetLanguage() ? known.language : null;
        },

        // Translating
        // ---------------------------------------------------------------

        translatePage: function (button) {
            var adapters = this.collect(document, true);

            if (!adapters.length) {
                this.notice(this.settings.strings.nothingToTranslate, 'notice');
                return;
            }

            this.translateFields(adapters, button, true);
        },

        translateFields: function (adapters, button, isPage) {
            if (!this.settings.configured) {
                this.notice(this.settings.strings.notConfigured, 'error');
                return;
            }

            var sourceLanguage = this.sourceLanguage();
            var targetLanguage = this.targetLanguage();

            if (!targetLanguage) {
                this.notice(this.settings.strings.noTarget, 'notice');
                return;
            }

            var items = [];
            var byId = {};

            var self = this;

            adapters.forEach(function (adapter, index) {
                var value = (adapter.get() || '').trim();

                if (!value || self.isNotProse(value)) {
                    return;
                }

                var id = 'f' + index;
                items.push({id: id, text: adapter.get(), format: adapter.format});
                byId[id] = adapter;
            });

            if (!items.length) {
                this.notice(this.settings.strings.nothingToTranslate, 'notice');
                return;
            }

            this.setBusy(button, true);

            Craft.sendActionRequest('POST', 'translingua/ai/translate', {
                data: {
                    items: items,
                    // Omitted when null, so the provider auto-detects.
                    sourceLanguage: sourceLanguage || '',
                    targetLanguage: targetLanguage
                }
            }).then(function (response) {
                var translations = (response.data && response.data.translations) || {};
                var count = 0;

                Object.keys(translations).forEach(function (id) {
                    var adapter = byId[id];
                    var value = translations[id];

                    if (!adapter || typeof value !== 'string' || !value.length) {
                        return;
                    }

                    if (value === adapter.get()) {
                        return;
                    }

                    adapter.set(value);
                    self.flash(adapter.el);
                    count++;
                });

                if (count) {
                    self.notice(
                        isPage
                            ? Craft.t('translingua', '{num, plural, =1{1 field translated} other{# fields translated}}', {num: count})
                            : self.settings.strings.translated,
                        'notice'
                    );
                } else {
                    self.notice(self.settings.strings.nothingToTranslate, 'notice');
                }
            }).catch(function (error) {
                var message = (error && error.response && error.response.data && error.response.data.message)
                    || self.settings.strings.failed;

                self.notice(message, 'error');
            }).then(function () {
                self.setBusy(button, false);
            });
        },

        setBusy: function (button, busy) {
            if (!button) {
                return;
            }

            button.classList.toggle('tp-busy', busy);
            button.disabled = busy;
        },

        flash: function (el) {
            var target = el.closest('.field') || el;
            target.classList.add('tp-flash');

            window.setTimeout(function () {
                target.classList.remove('tp-flash');
            }, 900);
        },

        notice: function (message, type) {
            if (window.Craft && Craft.cp && Craft.cp.displayNotice) {
                if (type === 'error') {
                    Craft.cp.displayError(message);
                } else {
                    Craft.cp.displayNotice(message);
                }

                return;
            }

            window.alert(message);
        },

        escape: function (value) {
            var div = document.createElement('div');
            div.textContent = value;

            return div.innerHTML;
        },

        // Craft adds fields constantly — Matrix blocks, slideouts, Formie's
        // builder — so the scan has to keep running.
        observe: function () {
            var self = this;
            var pending = null;

            var observer = new MutationObserver(function (mutations) {
                var relevant = mutations.some(function (mutation) {
                    return mutation.addedNodes && mutation.addedNodes.length;
                });

                if (!relevant) {
                    return;
                }

                window.clearTimeout(pending);

                pending = window.setTimeout(function () {
                    self.scan(document);
                    self.addPageButton();
                }, 200);
            });

            observer.observe(document.body, {childList: true, subtree: true});
        }
    };

    window.Translingua = Translingua;
})();
