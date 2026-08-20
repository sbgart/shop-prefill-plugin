/**
 * Appark Contacts Dialog — standalone module, no jQuery (except $.waDialog).
 *
 * Drop-in for any Webasyst plugin:
 *   1. Include appark.contacts.css + appark.contacts.js
 *   2. Add a trigger element with class "js-appark-contacts" and data-* config
 *
 * data-* config on the trigger element:
 *   data-dialog-title      — modal header text
 *   data-support-email     — support email address
 *   data-support-title     — support card heading
 *   data-support-hint      — support card description
 *   data-dev-email         — developer email address
 *   data-dev-title         — developer card heading
 *   data-dev-hint          — developer card description
 *   data-close-label       — close button label
 */
(function () {
    'use strict';

    // Open dialog
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-appark-contacts');
        if (!btn) return;

        var d = btn.dataset;
        var cfg = {
            dialogTitle:  d.dialogTitle  || 'Contact us',
            supportEmail: d.supportEmail || '',
            supportTitle: d.supportTitle || 'Support',
            supportHint:  d.supportHint  || '',
            devEmail:     d.devEmail     || '',
            devTitle:     d.devTitle     || 'Developer',
            devHint:      d.devHint      || '',
            closeLabel:   d.closeLabel   || 'Close',
            copiedLabel:  d.copiedLabel  || 'Copied'
        };

        var header = el('div', 'appark-contacts-dialog__header');
        header.appendChild(el('span', 'appark-contacts-dialog__title', cfg.dialogTitle));
        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'appark-contacts-dialog__close js-close-dialog';
        closeBtn.appendChild(el('i', 'fas fa-times'));
        header.appendChild(closeBtn);

        var content = el('div', 'appark-contacts-dialog');
        content.appendChild(buildCard('fas fa-headset',   cfg.supportEmail, cfg.supportTitle, cfg.supportHint, cfg.copiedLabel));
        content.appendChild(buildCard('fas fa-lightbulb', cfg.devEmail,     cfg.devTitle,     cfg.devHint,     cfg.copiedLabel));

        $.waDialog({
            header:  header,
            content: content,
            onOpen: function ($wrapper) {
                $wrapper[0].classList.add('appark-contacts-dialog-wrapper');
            }
        });
    });

    // Copy email to clipboard
    document.addEventListener('click', function (e) {
        var copyBtn = e.target.closest('.js-appark-copy');
        if (!copyBtn) return;

        e.preventDefault();
        e.stopPropagation();

        var email = copyBtn.dataset.email;
        var label = copyBtn.dataset.copiedLabel || 'Copied';

        navigator.clipboard.writeText(email).then(function () {
            copyBtn.classList.add('appark-contacts-card__copy--done');
            showCopyTip(copyBtn, label);
            setTimeout(function () {
                copyBtn.classList.remove('appark-contacts-card__copy--done');
            }, 1500);
        });
    });

    function showCopyTip(anchor, label) {
        var tip = el('span', 'appark-contacts-copy-tip', label);
        anchor.appendChild(tip);
        // trigger reflow so the animation starts from the initial state
        tip.getBoundingClientRect();
        tip.classList.add('appark-contacts-copy-tip--visible');
        setTimeout(function () {
            tip.classList.add('appark-contacts-copy-tip--out');
            tip.addEventListener('transitionend', function () {
                tip.parentNode && tip.parentNode.removeChild(tip);
            }, { once: true });
        }, 900);
    }

    function buildCard(icon, email, title, hint, copiedLabel) {
        var card = document.createElement('a');
        card.className = 'appark-contacts-card';
        card.href = 'mailto:' + email;

        var iconWrap = el('div', 'appark-contacts-card__icon');
        var i = document.createElement('i');
        i.className = icon;
        iconWrap.appendChild(i);

        var body = el('div', 'appark-contacts-card__body');
        body.appendChild(el('div', 'appark-contacts-card__title', title));
        body.appendChild(buildEmailRow(email, copiedLabel));
        body.appendChild(el('div', 'appark-contacts-card__hint', hint));

        card.appendChild(iconWrap);
        card.appendChild(body);
        return card;
    }

    function buildEmailRow(email, copiedLabel) {
        var row = el('div', 'appark-contacts-card__email-row');

        var emailEl = el('span', 'appark-contacts-card__email', email);

        var copyBtn = document.createElement('button');
        copyBtn.type = 'button';
        copyBtn.className = 'appark-contacts-card__copy js-appark-copy';
        copyBtn.dataset.email = email;
        copyBtn.dataset.copiedLabel = copiedLabel || 'Copied';
        var iconCopy  = document.createElement('i');
        iconCopy.className  = 'fas fa-copy appark-icon-copy';
        var iconCheck = document.createElement('i');
        iconCheck.className = 'fas fa-check appark-icon-check';
        copyBtn.appendChild(iconCopy);
        copyBtn.appendChild(iconCheck);

        row.appendChild(emailEl);
        row.appendChild(copyBtn);
        return row;
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    // Announcement bubble — dismissed for 24 h, then reappears automatically.
    // Change V1_BUBBLE_KEY when the announcement text changes so it shows again immediately.
    var V1_BUBBLE_KEY = 'appark_prefill_bubble_v1';
    var BUBBLE_TTL    = 24 * 60 * 60 * 1000; // 24 hours in ms

    // Hide on load if dismissed recently (runs as soon as DOM is available)
    function initBubble() {
        var bubble = document.querySelector('.js-appark-bubble');
        if (!bubble) return;
        try {
            var raw = localStorage.getItem(V1_BUBBLE_KEY);
            if (raw) {
                var saved = JSON.parse(raw);
                if (Date.now() - saved.t < BUBBLE_TTL) {
                    bubble.style.display = 'none';
                }
            }
        } catch (e) {}
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBubble);
    } else {
        initBubble();
    }

    // Delegated click — works regardless of script load timing
    document.addEventListener('click', function (e) {
        var closeBtn = e.target.closest('.js-appark-bubble-close');
        if (!closeBtn) return;
        var bubble = closeBtn.closest('.js-appark-bubble');
        if (!bubble) return;
        bubble.style.transition = 'opacity 0.2s ease';
        bubble.style.opacity = '0';
        setTimeout(function () { bubble.style.display = 'none'; }, 220);
        try { localStorage.setItem(V1_BUBBLE_KEY, JSON.stringify({ t: Date.now() })); } catch (e) {}
    });
}());
