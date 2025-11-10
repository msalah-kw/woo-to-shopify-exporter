(function ($) {
    'use strict';

    const storageKey = 'wseActiveExportJob';
    const POLL_INTERVAL = 5000;

    const state = {
        stepIndex: 0,
        steps: [],
        job: null,
        pollTimer: null,
        isPolling: false,
        settings: {},
    };

    function init() {
        if (typeof wseAdmin === 'undefined') {
            return;
        }

        const form = $('#wse-export-form');
        if (!form.length) {
            return;
        }

        state.steps = $('.wse-step');
        state.settings = wseAdmin.settings || {};

        restoreJobFromData();
        bindStepNavigation();
        bindScopeToggles();
        bindCustomMappingToggle();
        bindFormSubmit(form);
        bindProgressActions();
        updateStepVisibility();
        updateReviewSummary();

        if (state.job) {
            updateProgressPanel(state.job);
            startPolling();
        }
    }

    function restoreJobFromData() {
        let job = null;

        if (wseAdmin.activeJob && Object.keys(wseAdmin.activeJob).length) {
            job = wseAdmin.activeJob;
        } else {
            try {
                const stored = window.localStorage ? window.localStorage.getItem(storageKey) : null;
                if (stored) {
                    job = JSON.parse(stored);
                }
            } catch (error) {
                // eslint-disable-next-line no-console
                console.warn('Unable to read persisted job state', error);
            }
        }

        if (job && job.id) {
            state.job = job;
        }
    }

    function persistJob(job) {
        state.job = job;

        try {
            if (window.localStorage) {
                if (job) {
                    window.localStorage.setItem(storageKey, JSON.stringify(job));
                } else {
                    window.localStorage.removeItem(storageKey);
                }
            }
        } catch (error) {
            // eslint-disable-next-line no-console
            console.warn('Unable to persist job state', error);
        }
    }

    function bindStepNavigation() {
        $(document).on('click', '.wse-next-step', function (event) {
            event.preventDefault();
            const target = parseInt($(this).attr('data-next'), 10) - 1;
            if (!Number.isNaN(target)) {
                goToStep(target);
            }
        });

        $(document).on('click', '.wse-previous-step', function (event) {
            event.preventDefault();
            const target = parseInt($(this).attr('data-previous'), 10) - 1;
            if (!Number.isNaN(target)) {
                goToStep(target);
            }
        });

        $(document).on('change input', '#wse-export-form :input', function () {
            updateReviewSummary();
        });
    }

    function bindScopeToggles() {
        const scopeInputs = $('input[name="export_scope"]');
        if (!scopeInputs.length) {
            return;
        }

        scopeInputs.on('change', toggleScopeFields);
        toggleScopeFields();
    }

    function toggleScopeFields() {
        const activeScope = $('input[name="export_scope"]:checked').val();
        $('.wse-scope-extended').each(function () {
            const wrapper = $(this);
            const scope = wrapper.data('scope');
            const enabled = scope === activeScope;
            wrapper.toggleClass('is-active', enabled);
            wrapper.find('select, input, textarea').prop('disabled', !enabled);
        });
    }

    function bindCustomMappingToggle() {
        const preset = $('#wse-field-preset');
        if (!preset.length) {
            return;
        }

        const toggle = function () {
            const value = preset.val();
            const customWrapper = $('.wse-custom-mapping');
            const isVisible = customWrapper.attr('data-visible-when') === value;
            customWrapper.toggleClass('is-active', isVisible);
            customWrapper.find('textarea, input').prop('disabled', !isVisible);
        };

        preset.on('change', toggle);
        toggle();
    }

    function bindFormSubmit(form) {
        form.on('submit', function (event) {
            event.preventDefault();
            startExport(form);
        });
    }

    function bindProgressActions() {
        const resumeButton = $('.wse-resume-export');
        const refreshButton = $('.wse-refresh-progress');

        resumeButton.on('click', function (event) {
            event.preventDefault();
            if (!state.job || !state.job.id) {
                return;
            }
            resumeExport();
        });

        refreshButton.on('click', function (event) {
            event.preventDefault();
            pollProgress();
        });
    }

    function startExport(form) {
        if (!wseAdmin.nonces || !wseAdmin.nonces.start) {
            return;
        }

        if (state.job && state.job.id && state.job.status && state.job.status !== 'completed' && state.job.status !== 'failed') {
            if (!window.confirm(wseAdmin.strings.resumeConfirmation)) {
                return;
            }
        }

        const formData = new window.FormData(form[0]);
        formData.append('action', 'wse_start_export');
        formData.append('nonce', wseAdmin.nonces.start);

        setProgressState('starting');

        $.ajax({
            url: wseAdmin.ajaxUrl,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
        })
            .done(function (response) {
                if (!response || !response.success) {
                    handleAjaxError(response && response.data ? response.data.message : null);
                    return;
                }

                const job = response.data.job || null;
                persistJob(job);
                updateProgressPanel(job);
                startPolling();
                maybeShowNotice(response.data.notice);
            })
            .fail(function (jqXHR) {
                const message = jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data ? jqXHR.responseJSON.data.message : null;
                handleAjaxError(message);
            });
    }

    function resumeExport() {
        if (!wseAdmin.nonces || !wseAdmin.nonces.resume || !state.job) {
            return;
        }

        setProgressState('starting');

        $.post(
            wseAdmin.ajaxUrl,
            {
                action: 'wse_resume_export',
                nonce: wseAdmin.nonces.resume,
                job_id: state.job.id,
            }
        )
            .done(function (response) {
                if (!response || !response.success) {
                    handleAjaxError(response && response.data ? response.data.message : null);
                    return;
                }

                const job = response.data.job || null;
                persistJob(job);
                updateProgressPanel(job);
                startPolling();
                maybeShowNotice(response.data.notice);
            })
            .fail(function (jqXHR) {
                const message = jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data ? jqXHR.responseJSON.data.message : null;
                handleAjaxError(message);
            });
    }

    function pollProgress() {
        if (!wseAdmin.nonces || !wseAdmin.nonces.poll) {
            return;
        }

        if (state.isPolling) {
            return;
        }

        state.isPolling = true;

        $.post(
            wseAdmin.ajaxUrl,
            {
                action: 'wse_poll_export',
                nonce: wseAdmin.nonces.poll,
            }
        )
            .done(function (response) {
                if (response && response.success) {
                    const job = response.data.job || null;
                    persistJob(job);
                    updateProgressPanel(job);
                    if (!job || !job.status || job.status === 'completed' || job.status === 'failed') {
                        stopPolling();
                    }
                } else {
                    handleAjaxError(response && response.data ? response.data.message : null);
                }
            })
            .fail(function (jqXHR) {
                const message = jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data ? jqXHR.responseJSON.data.message : null;
                handleAjaxError(message);
            })
            .always(function () {
                state.isPolling = false;
            });
    }

    function startPolling() {
        stopPolling();
        pollProgress();
        state.pollTimer = window.setInterval(pollProgress, POLL_INTERVAL);
    }

    function stopPolling() {
        if (state.pollTimer) {
            window.clearInterval(state.pollTimer);
            state.pollTimer = null;
        }
    }

    function setProgressState(status) {
        const panel = $('#wse-progress-panel');
        panel.attr('data-state', status);
        panel.find('[data-status]').text(resolveStatusLabel(status));
    }

    function updateProgressPanel(job) {
        const panel = $('#wse-progress-panel');
        if (!panel.length) {
            return;
        }

        if (!job || !job.id) {
            panel.find('[data-status]').text(resolveStatusLabel('idle'));
            panel.find('[data-message]').text(wseAdmin.strings.idle);
            panel.find('.wse-progress-fill').css('width', '0%');
            panel.attr('data-state', 'idle');
            panel.find('[data-job-id]').html('&mdash;');
            panel.find('[data-last-updated]').html('&mdash;');
            panel.find('.wse-resume-export').prop('disabled', true);
            panel.find('.wse-download-export').attr('hidden', true);
            persistJob(null);
            return;
        }

        panel.attr('data-state', job.status || 'queued');
        panel.find('[data-status]').text(resolveStatusLabel(job.status));
        panel.find('[data-message]').text(job.message || '');
        panel.find('[data-job-id]').text(job.id);
        panel.find('[data-last-updated]').text(formatTimestamp(job.last_updated));

        const progress = typeof job.progress === 'number' ? Math.max(0, Math.min(100, job.progress)) : 0;
        panel.find('.wse-progress-fill').css('width', progress + '%');
        panel.find('.wse-progress-bar').attr('aria-valuenow', progress);

        const resumeDisabled = job.status && (job.status === 'completed' || job.status === 'failed');
        panel.find('.wse-resume-export').prop('disabled', resumeDisabled);

        if (job.download_url) {
            panel.find('.wse-download-export').attr('href', job.download_url).attr('hidden', false);
        } else {
            panel.find('.wse-download-export').attr('hidden', true);
        }

        persistJob(job);
    }

    function resolveStatusLabel(status) {
        if (!wseAdmin.strings) {
            return status;
        }
        switch (status) {
            case 'starting':
                return wseAdmin.strings.starting;
            case 'queued':
                return wseAdmin.strings.queued;
            case 'running':
                return wseAdmin.strings.running;
            case 'paused':
                return wseAdmin.strings.paused;
            case 'completed':
                return wseAdmin.strings.completed;
            case 'failed':
                return wseAdmin.strings.failed;
            case 'idle':
            default:
                return wseAdmin.strings.idle;
        }
    }

    function handleAjaxError(message) {
        stopPolling();
        const text = message || wseAdmin.strings.ajaxError;
        updateProgressPanel({
            status: 'failed',
            message: text,
            progress: 0,
        });
    }

    function maybeShowNotice(message) {
        if (!message) {
            return;
        }

        const container = $('<div />', {
            class: 'notice notice-success is-dismissible wse-temp-notice',
        });

        container.append($('<p />').text(message));
        const header = $('.wrap.wse-export-page > h1');
        if (header.length) {
            header.after(container);
        } else {
            $('.wrap.wse-export-page').prepend(container);
        }

        setTimeout(function () {
            container.fadeOut(300, function () {
                container.remove();
            });
        }, 5000);
    }

    function goToStep(index) {
        if (index < 0 || index >= state.steps.length) {
            return;
        }

        state.stepIndex = index;
        updateStepVisibility();
        updateReviewSummary();
    }

    function updateStepVisibility() {
        state.steps.removeClass('is-active is-complete');
        state.steps.each(function (i) {
            const step = $(this);
            if (i === state.stepIndex) {
                step.addClass('is-active');
            }
            if (i < state.stepIndex) {
                step.addClass('is-complete');
            }
        });
    }

    function updateReviewSummary() {
        const container = $('#wse-review-summary');
        if (!container.length) {
            return;
        }

        const scope = $('input[name="export_scope"]:checked').val();
        const preset = $('#wse-field-preset').val();
        const fileName = $('#wse-output-filename').val();
        const includeImages = $('input[name="include_images"]').is(':checked');
        const includeInventory = $('input[name="include_inventory"]').is(':checked');
        const includeVariations = $('input[name="include_variations"]').is(':checked');
        const format = $('#wse-output-format').val();
        const delimiter = $('#wse-output-delimiter').val();

        const summary = $('<dl />', { class: 'wse-summary-list' });
        summary.append(renderSummaryRow(wseAdmin.strings.summaryScope, describeScope(scope)));
        summary.append(renderSummaryRow(wseAdmin.strings.summaryPreset, describePreset(preset)));
        summary.append(renderSummaryRow(wseAdmin.strings.summaryOutput, describeOutput(includeImages, includeInventory, includeVariations, format, delimiter)));
        summary.append(renderSummaryRow(wseAdmin.strings.summaryFile, fileName || '—'));

        container.empty().append(summary);
    }

    function renderSummaryRow(label, value) {
        const row = $('<div />', { class: 'wse-summary-row' });
        row.append($('<dt />').text(label));
        row.append($('<dd />').text(value));
        return row;
    }

    function describeScope(scope) {
        switch (scope) {
            case 'category':
                const categories = $('#wse-scope-categories option:selected').map(function () { return $(this).text(); }).get().join(', ');
                return categories ? wseAdmin.strings.scopeCategories + ': ' + categories : wseAdmin.strings.scopeEmpty;
            case 'tag':
                const tags = $('#wse-scope-tags option:selected').map(function () { return $(this).text(); }).get().join(', ');
                return tags ? wseAdmin.strings.scopeTags + ': ' + tags : wseAdmin.strings.scopeEmpty;
            case 'status':
                const statuses = $('#wse-scope-status option:selected').map(function () { return $(this).text(); }).get().join(', ');
                return statuses ? wseAdmin.strings.scopeStatuses + ': ' + statuses : wseAdmin.strings.scopeEmpty;
            case 'all':
            default:
                return wseAdmin.strings.scopeAll;
        }
    }

    function describePreset(preset) {
        switch (preset) {
            case 'minimal':
                return wseAdmin.strings.presetMinimal;
            case 'extended':
                return wseAdmin.strings.presetExtended;
            case 'custom':
                return wseAdmin.strings.presetCustom;
            case 'shopify-default':
            default:
                return wseAdmin.strings.presetDefault;
        }
    }

    function describeOutput(includeImages, includeInventory, includeVariations, format, delimiter) {
        const chunks = [];
        chunks.push(format.toUpperCase());
        chunks.push(wseAdmin.strings.outputDelimiter + ' ' + (delimiter === '\t' ? 'TAB' : delimiter));
        if (includeImages) {
            chunks.push(wseAdmin.strings.outputImages);
        }
        if (includeInventory) {
            chunks.push(wseAdmin.strings.outputInventory);
        }
        if (includeVariations) {
            chunks.push(wseAdmin.strings.outputVariations);
        }
        return chunks.join(' · ');
    }

    function formatTimestamp(timestamp) {
        if (!timestamp) {
            return '—';
        }

        const date = new Date(timestamp * 1000);
        if (!Number.isFinite(date.getTime())) {
            return '—';
        }

        return date.toLocaleString();
    }

    $(document).ready(init);
})(jQuery);
