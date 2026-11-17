/**
 * Famly Enquiry Form: AJAX submit, validation, GTM events, UTM passthrough.
 * Link mode: tracks clicks on the "Book your tour" button.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var cfg = window.famlyForm || {};

        // ─── Link mode ──────────────────────────────────────────────
        var link = document.getElementById('famly-link-button');
        if (link) {
            link.addEventListener('click', function () {
                var nursery = link.getAttribute('data-nursery') || '';
                pushDataLayer('tour_booking_link_clicked', { nursery: nursery, link_url: link.href });
                dispatch('famly:tour-link-clicked', { nursery: nursery });
            });
        }

        // ─── Form mode ──────────────────────────────────────────────
        var btn       = document.getElementById('famly-submit-btn');
        var formWrap  = document.getElementById('famly-form-container');
        var successEl = document.getElementById('famly-success');
        var errorEl   = document.getElementById('famly-error');
        if (!btn || !formWrap) return;

        var utmParams = getUtmParams();
        var nursery   = val('fe-site-label');

        btn.addEventListener('click', function () {
            errorEl.style.display = 'none';
            formWrap.querySelectorAll('.famly-invalid').forEach(function (el) { el.classList.remove('famly-invalid'); });

            var sourceSelect = document.getElementById('fe-source');
            var sourceText   = sourceSelect && sourceSelect.selectedIndex > 0 ? sourceSelect.options[sourceSelect.selectedIndex].text : '';

            var fields = {
                site:              val('fe-site'),
                first_name:        val('fe-child-first-name'),
                last_name:         val('fe-child-last-name'),
                dob:               val('fe-child-dob'),
                gender:            val('fe-child-gender'),
                parent_first_name: val('fe-parent-first-name'),
                parent_last_name:  val('fe-parent-last-name'),
                email:             val('fe-parent-email'),
                phone:             val('fe-phone'),
                postcode:          val('fe-postcode'),
                start_date:        val('fe-start-date'),
                source:            val('fe-source'),
                agree_terms:       document.getElementById('fe-agree-terms').checked ? '1' : ''
            };

            // Required fields (Settings → Required fields, plus Famly's own)
            var requiredMap = cfg.requiredMap || {};
            var valid = true;
            Object.keys(requiredMap).forEach(function (key) {
                if (!fields[key]) {
                    var el = document.getElementById(requiredMap[key]);
                    if (el) el.classList.add('famly-invalid');
                    valid = false;
                }
            });

            // Extra questions
            var extra = [];
            formWrap.querySelectorAll('[data-key][data-type]').forEach(function (el) {
                var key = el.getAttribute('data-key');
                var values = [];
                if (el.getAttribute('data-type') === 'checkbox') {
                    el.querySelectorAll('input[type="checkbox"]:checked').forEach(function (cb) { values.push(cb.value); });
                } else if (el.value.trim() !== '') {
                    values.push(el.value.trim());
                }
                if (el.getAttribute('data-required') === '1' && !values.length) {
                    el.classList.add('famly-invalid');
                    valid = false;
                }
                extra.push({ key: key, type: el.getAttribute('data-type'), values: values });
            });

            var email = fields.email;
            if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                document.getElementById('fe-parent-email').classList.add('famly-invalid');
                valid = false;
            }

            if (!valid) {
                var firstInvalid = formWrap.querySelector('.famly-invalid');
                if (firstInvalid) firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            var turnstileToken = '';
            if (cfg.turnstile) {
                var t = document.querySelector('#famly-form-wrapper [name="cf-turnstile-response"]');
                turnstileToken = t ? t.value : '';
                if (!turnstileToken) {
                    showError('Please wait a moment while we check you\'re not a robot, then press Submit again.');
                    return;
                }
            }

            pushDataLayer('tour_booking_attempted', { source: sourceText, nursery: nursery });

            btn.disabled = true;
            btn.querySelector('.btn-text').style.display = 'none';
            btn.querySelector('.btn-loading').style.display = 'inline';

            var formData = new FormData();
            formData.append('action', 'famly_submit_enquiry');
            formData.append('nonce', cfg.nonce || '');
            Object.keys(fields).forEach(function (key) { formData.append(key, fields[key]); });
            extra.forEach(function (q) {
                if (q.type === 'checkbox') q.values.forEach(function (v) { formData.append('extra[' + q.key + '][]', v); });
                else formData.append('extra[' + q.key + ']', q.values[0] || '');
            });
            ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'].forEach(function (k) {
                formData.append(k, utmParams[k] || '');
            });
            formData.append('page_url', window.location.href);
            formData.append('fe_extra', val('fe-extra'));
            formData.append('turnstile_token', turnstileToken);

            fetch(cfg.ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' })
                .then(function (res) { return res.json(); })
                .then(function (json) {
                    if (json.success) {
                        formWrap.style.display = 'none';
                        successEl.style.display = 'block';
                        pushDataLayer('tour_booking_submitted', {
                            source:       sourceText,
                            postcode:     fields.postcode,
                            nursery:      nursery,
                            utm_source:   utmParams.utm_source || '',
                            utm_medium:   utmParams.utm_medium || '',
                            utm_campaign: utmParams.utm_campaign || ''
                        });
                        dispatch('famly:enquiry-submitted', { nursery: nursery });
                        successEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    } else {
                        var msg = (json.data && json.data.message) ? json.data.message : 'Something went wrong.';
                        showError(msg);
                        resetTurnstile();
                        pushDataLayer('tour_booking_error', { error_message: msg, nursery: nursery });
                    }
                })
                .catch(function (err) {
                    showError('Network error. Please check your connection and try again.');
                    resetTurnstile();
                    pushDataLayer('tour_booking_error', { error_message: 'Network: ' + err.message, nursery: nursery });
                })
                .finally(function () {
                    btn.disabled = false;
                    btn.querySelector('.btn-text').style.display = 'inline';
                    btn.querySelector('.btn-loading').style.display = 'none';
                });
        });

        function showError(message) {
            errorEl.innerHTML = '';
            var p1 = document.createElement('p');
            p1.textContent = message;
            errorEl.appendChild(p1);
            if (cfg.contactEmail) {
                var p2 = document.createElement('p');
                p2.appendChild(document.createTextNode('Please try again or contact us at '));
                var a = document.createElement('a');
                a.href = 'mailto:' + cfg.contactEmail;
                a.textContent = cfg.contactEmail;
                p2.appendChild(a);
                p2.appendChild(document.createTextNode('.'));
                errorEl.appendChild(p2);
            }
            errorEl.style.display = 'block';
            errorEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });

    function resetTurnstile() {
        if (window.turnstile && document.getElementById('fe-turnstile')) {
            try { window.turnstile.reset('#fe-turnstile'); } catch (e) {}
        }
    }

    function val(id) {
        var el = document.getElementById(id);
        return el ? el.value.trim() : '';
    }

    function getUtmParams() {
        var params = {};
        try {
            var sp = new URLSearchParams(window.location.search);
            ['utm_medium', 'utm_source', 'utm_campaign', 'utm_content', 'utm_term'].forEach(function (k) {
                if (sp.get(k)) params[k] = sp.get(k);
            });
        } catch (e) {}
        return params;
    }

    function pushDataLayer(event, params) {
        window.dataLayer = window.dataLayer || [];
        var obj = { event: event };
        if (params) Object.keys(params).forEach(function (k) { obj[k] = params[k]; });
        window.dataLayer.push(obj);
    }

    function dispatch(name, detail) {
        try { document.dispatchEvent(new CustomEvent(name, { detail: detail })); } catch (e) {}
    }
})();
