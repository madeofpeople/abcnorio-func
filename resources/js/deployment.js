/* global abcnorioDeployment */
(function () {
    const { ajaxUrl, triggerNonce, pollNonce, pushToStagingNonce, pollPushNonce, copyMediaNonce, pollCopyMediaNonce, pullFromStagingNonce, pollPullFromStagingNonce, copyMediaToStagingNonce, pollCopyMediaToStagingNonce, backupMediaDevNonce, pollBackupMediaDevNonce, backupMediaStagingNonce, pollBackupMediaStagingNonce, backupDatabaseStagingNonce, pollBackupDatabaseStagingNonce, listMediaBackupsNonce, deleteMediaBackupNonce, listDatabaseBackupsNonce, downloadDatabaseBackupNonce, deleteDatabaseBackupNonce, pullFromDevNonce, pollPullFromDevNonce, targets = {} } = abcnorioDeployment;

    let pollTimer = null;
    let buildStartTime = null;
    let timerInterval = null;

    const NOTICE_TIMEOUT_MS = 4000;

    // --- Tab switching ---
    document.querySelectorAll('#deployment-tabs .nav-tab').forEach((tab) => {
        tab.addEventListener('click', (e) => {
            e.preventDefault();
            const target = tab.dataset.tab;

            document.querySelectorAll('#deployment-tabs .nav-tab').forEach((t) => {
                t.classList.toggle('nav-tab-active', t.dataset.tab === target);
            });

            document.querySelectorAll('.deployment-tab').forEach((panel) => {
                panel.classList.toggle('hidden', panel.id !== 'tab-' + target);
            });
        });
    });

    // --- Spinner ---
    const SPINNER_SVG = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;animation:abcnorio-spin 1s linear infinite" aria-hidden="true"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>';

    const styleEl = document.createElement('style');
    styleEl.textContent = '@keyframes abcnorio-spin { to { transform: rotate(360deg); } }';
    document.head.appendChild(styleEl);

    // --- Helpers ---
    function setButtonRunning(btn) {
        btn.disabled = true;
        btn.innerHTML = SPINNER_SVG + ' ' + btn.dataset.label + '\u2026';
    }

    function setButtonIdle(btn) {
        btn.disabled = false;
        btn.textContent = btn.dataset.label;
    }

    function setStatus(panel, msg) {
        const el = panel.querySelector('.js-build-status');
        if (el) el.textContent = msg;
    }

    function formatElapsed(seconds) {
        const mins = Math.floor(seconds / 60);
        const secs = seconds % 60;
        if (mins > 0) {
            return mins + 'm ' + secs + 's';
        }
        return secs + 's';
    }

    function startTimer(panel, startMs) {
        buildStartTime = startMs || Date.now();
        clearInterval(timerInterval);
        timerInterval = setInterval(() => {
            const elapsed = Math.floor((Date.now() - buildStartTime) / 1000);
            setStatus(panel, 'Build running (' + formatElapsed(elapsed) + ')\u2026');
        }, 500);
    }

    function stopTimer() {
        if (timerInterval) {
            clearInterval(timerInterval);
            timerInterval = null;
        }
        buildStartTime = null;
    }

    function dismissNotice(notice) {
        notice.style.transition = 'opacity 0.3s';
        notice.style.opacity = '0';
        setTimeout(() => notice.remove(), 500);
    }

    function showAdminNotice(message, type = 'success') {
        const wrap = document.querySelector('.wrap');
        if (!wrap) return;

        const notice = document.createElement('div');
        notice.className = 'notice notice-' + type + ' is-dismissible';
        notice.innerHTML = '<p>' + message + '</p>';

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'notice-dismiss';
        btn.innerHTML = '<span class="screen-reader-text">Dismiss this notice.</span>';
        btn.addEventListener('click', () => dismissNotice(notice));
        notice.appendChild(btn);

        wrap.insertBefore(notice, wrap.firstChild);
        setTimeout(() => dismissNotice(notice), NOTICE_TIMEOUT_MS);
    }

    function escapeHtml(s) {
        return String(s)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#39;');
    }

    function mediaDownloadUrl(env, file) {
        return '/app/backups/media/' + encodeURIComponent(file);
    }

    function renderMediaBackupList(env, backups) {
        const list = document.querySelector('.js-media-backup-list[data-env="' + env + '"]');
        const empty = document.querySelector('.js-media-backup-empty[data-env="' + env + '"]');
        if (!list || !empty) return;

        if (!Array.isArray(backups) || backups.length === 0) {
            list.innerHTML = '';
            empty.style.display = '';
            return;
        }

        empty.style.display = 'none';
        list.innerHTML = backups.map((b) => {
            const name = escapeHtml(b.name || '');
            const href = mediaDownloadUrl(env, b.name || '');
            return '<li class="backup-item">'
                + '<span class="backup-item-name">' + name + '</span>'
                + '<span style="margin-left: 0.5rem; display: inline-flex; gap: 0.5rem;">'
                + '<a href="' + href + '" class="button button-secondary button-small">Download</a>'
                + '<button class="button button-small js-delete-media-backup" data-env="' + escapeHtml(env) + '" data-file="' + name + '">Delete</button>'
                + '</span>'
                + '</li>';
        }).join('');
    }

    function renderDatabaseBackupList(backups) {
        const list = document.querySelector('.js-database-backup-list');
        const empty = document.querySelector('.js-database-backup-empty');
        if (!list || !empty) return;

        if (!Array.isArray(backups) || backups.length === 0) {
            list.innerHTML = '';
            empty.style.display = '';
            return;
        }

        empty.style.display = 'none';
        list.innerHTML = backups.map((backup) => {
            const name = escapeHtml(backup.name || '');
            const href = ajaxUrl + '?action=abcnorio_download_database_backup&nonce='
                + encodeURIComponent(downloadDatabaseBackupNonce) + '&file=' + encodeURIComponent(backup.name || '');
            return '<li class="backup-item">'
                + '<span class="backup-item-name">' + name + '</span>'
                + '<span style="margin-left: 0.5rem; display: inline-flex; gap: 0.5rem;">'
                + '<a href="' + href + '" class="button button-secondary button-small">Download</a>'
                + '<button class="button button-small js-delete-database-backup" data-file="' + name + '">Delete</button>'
                + '</span></li>';
        }).join('');
    }

    function refreshDatabaseBackupList() {
        return fetch(ajaxUrl, {
            method: 'POST',
            body: new URLSearchParams({
                action: 'abcnorio_list_database_backups',
                nonce: listDatabaseBackupsNonce,
            }),
        })
            .then((r) => r.json())
            .then((data) => {
                if (!data.success) {
                    throw new Error(data.data && data.data.message ? data.data.message : 'Could not load database backups.');
                }
                renderDatabaseBackupList((data.data && data.data.backups) || []);
            });
    }

    function refreshMediaBackupList(env) {
        return fetch(ajaxUrl, {
            method: 'POST',
            body: new URLSearchParams({
                action: 'abcnorio_list_media_backups',
                nonce: listMediaBackupsNonce,
                env,
            }),
        })
            .then((r) => r.json())
            .then((data) => {
                if (!data.success) {
                    throw new Error(data.data && data.data.message ? data.data.message : 'Could not load media backups.');
                }
                renderMediaBackupList(env, (data.data && data.data.backups) || []);
            });
    }

    function redirectToDeploymentNotice(envKey, type = 'success', message = '') {
        const url = new URL(window.location.href);
        url.searchParams.set('page', 'abcnorio-deployment');
        url.searchParams.set('tab', envKey);
        url.searchParams.set('deployment_notice', type);
        url.searchParams.set('deployment_env', envKey);
        if (message) {
            url.searchParams.set('deployment_message', message);
        } else {
            url.searchParams.delete('deployment_message');
        }

        if (type === 'success') {
            url.searchParams.set('deployed', envKey);
        } else {
            url.searchParams.delete('deployed');
        }

        url.searchParams.delete('restored');
        window.location.assign(url.toString());
    }

    function getProductionFirstBackupName() {
        const list = document.querySelector('#tab-production .js-backup-list');
        if (!list) return '';
        const first = list.querySelector('li .backup-item-name');
        if (!first) return '';
        return (first.textContent || '').trim();
    }

    function markPendingBackupHighlight() {
        try {
            const previousFirst = getProductionFirstBackupName();
            sessionStorage.setItem('abcnorio.pendingBackupHighlight', '1');
            sessionStorage.setItem('abcnorio.previousProductionFirstBackup', previousFirst);
        } catch {
            // ignore storage issues
        }
    }

    function maybeHighlightNewBackup() {
        const params = new URLSearchParams(window.location.search);
        if (params.get('deployed') !== 'production') return;

        let pending = '';
        let previousFirst = '';
        try {
            pending = sessionStorage.getItem('abcnorio.pendingBackupHighlight') || '';
            previousFirst = sessionStorage.getItem('abcnorio.previousProductionFirstBackup') || '';
        } catch {
            return;
        }

        if (pending !== '1') {
            return;
        }

        const list = document.querySelector('#tab-production .js-backup-list');
        if (!list) return;

        const firstItem = list.querySelector('li');
        const firstNameEl = list.querySelector('li .backup-item-name');
        const currentFirst = firstNameEl ? (firstNameEl.textContent || '').trim() : '';
        if (firstItem && currentFirst && currentFirst !== previousFirst) {
            firstItem.classList.add('backup-item-new');
        }

        try {
            sessionStorage.removeItem('abcnorio.pendingBackupHighlight');
            sessionStorage.removeItem('abcnorio.previousProductionFirstBackup');
        } catch {
            // ignore storage issues
        }
    }

    function showPreview(envKey, panel) {
        const link = panel.querySelector('.js-preview-link[data-env="' + envKey + '"]');
        const url  = targets[envKey] && targets[envKey].previewUrl;
        if (link && url) {
            if (targets[envKey]) {
                targets[envKey].hasBuild = true;
            }
            link.href = url;
            link.classList.remove('hidden');
        }
    }

    function initializePreviewLinks() {
        document.querySelectorAll('.js-preview-link').forEach((link) => {
            const env = link.dataset.env;
            const target = targets[env] || {};
            if (target.previewUrl && target.hasBuild) {
                link.href = target.previewUrl;
                link.classList.remove('hidden');
            }
        });
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function startPolling(btn, panel, envKey) {
        stopPolling();

        pollTimer = setInterval(() => {
            const body = new URLSearchParams({
                action: 'abcnorio_poll_build_status',
                nonce: pollNonce,
            });

            fetch(ajaxUrl, { method: 'POST', body })
                .then((r) => r.json())
                .then((data) => {
                    if (!data.success) {
                        stopPolling();
                        stopTimer();
                        setButtonIdle(btn);
                        const errorMessage = data.data && data.data.message ? data.data.message : 'Status check failed.';
                        setStatus(panel, 'Error: ' + errorMessage);
                        redirectToDeploymentNotice(envKey, 'error', 'Build status check failed: ' + errorMessage);
                        return;
                    }

                    const st = data.data;

                    if (st.status === 'done') {
                        if (st.target && st.target !== envKey) {
                            return;
                        }
                        stopPolling();
                        stopTimer();
                        setButtonIdle(btn);
                        let elapsedStr = '';
                        if (st.started && st.finished && st.finished > st.started) {
                            const elapsed = Math.round((st.finished - st.started) / 1000);
                            elapsedStr = ' (' + formatElapsed(elapsed) + ')';
                        }
                        const doneMessage = envKey === 'production'
                            ? 'Backup and deployment complete' + elapsedStr + '.'
                            : envKey === 'preview'
                                ? 'Production preview build complete' + elapsedStr + '.'
                                : 'Build complete' + elapsedStr + '.';
                        setStatus(panel, doneMessage);
                        setTimeout(() => setStatus(panel, ''), 5000);
                        if (envKey === 'production') {
                            redirectToDeploymentNotice(envKey, 'success', doneMessage);
                            return;
                        }
                        if (envKey === 'preview') {
                            redirectToDeploymentNotice('production', 'success', doneMessage);
                            return;
                        }
                        showAdminNotice(doneMessage, 'success');
                        showPreview(envKey, panel);
                    } else if (st.status === 'failed') {
                        if (st.target && st.target !== envKey) {
                            return;
                        }
                        stopPolling();
                        stopTimer();
                        setButtonIdle(btn);
                        const baseMessage = envKey === 'production'
                            ? 'Backup and deployment failed.'
                            : envKey === 'preview'
                                ? 'Production preview build failed.'
                            : 'Build failed.';
                        const detail = st.message ? ' ' + st.message : ' Check server logs.';
                        const failedMessage = baseMessage + detail;
                        setStatus(panel, failedMessage);
                        redirectToDeploymentNotice(envKey, 'error', failedMessage);
                    }
                })
                .catch(() => {
                    stopPolling();
                    stopTimer();
                    setButtonIdle(btn);
                    setStatus(panel, 'Status check failed.');
                    redirectToDeploymentNotice(envKey, 'error', 'Status check failed while polling build.');
                });
        }, 2500);
    }

    function initFromOrchestratorStatus() {
        const body = new URLSearchParams({
            action: 'abcnorio_poll_build_status',
            nonce: pollNonce,
        });
        fetch(ajaxUrl, { method: 'POST', body })
            .then((r) => r.json())
            .then((data) => {
                if (!data.success) return;
                const st = data.data;
                if (st.status !== 'running' && st.status !== 'requested') return;
                const target = st.target;
                if (!target) return;
                const btn = document.querySelector('.js-trigger-build[data-target="' + target + '"]');
                const panel = btn && btn.closest('.deployment-tab');
                if (!btn || !panel) return;
                setButtonRunning(btn);
                startTimer(panel, st.started || null);
                startPolling(btn, panel, target);
            })
            .catch(() => {});
    }

    initializePreviewLinks();

    // Highlight only after build-complete redirect when backup list actually changed.
    maybeHighlightNewBackup();

    // Resume spinner + polling if a build is already running when the page loads.
    initFromOrchestratorStatus();

    // Auto-dismiss PHP-rendered notices (from redirect query params).
    document.querySelectorAll('#wpbody-content .notice.is-dismissible').forEach((notice) => {
        setTimeout(() => dismissNotice(notice), NOTICE_TIMEOUT_MS);
    });

    // Strip transient notice params from the URL so a page reload doesn't re-show them.
    (function stripNoticeParams() {
        const url = new URL(window.location.href);
        const noticeParams = ['deployment_notice', 'deployment_message', 'deployed', 'restored'];
        let changed = false;
        noticeParams.forEach((p) => {
            if (url.searchParams.has(p)) {
                url.searchParams.delete(p);
                changed = true;
            }
        });
        if (changed) {
            history.replaceState(null, '', url.toString());
        }
    }());

    // --- Build trigger ---
    document.querySelectorAll('.js-trigger-build').forEach((btn) => {
        btn.addEventListener('click', () => {
            const confirmMsg = btn.dataset.confirm;
            if (confirmMsg && !window.confirm(confirmMsg)) return;

            const target = btn.dataset.target;
            const panel  = btn.closest('.deployment-tab');

            if (target === 'production' || target === 'preview') {
                markPendingBackupHighlight();
            }

            setButtonRunning(btn);
            setStatus(panel, 'Starting build\u2026');

            const body = new URLSearchParams({
                action: 'abcnorio_trigger_build',
                nonce: triggerNonce,
                target,
            });

            fetch(ajaxUrl, { method: 'POST', body })
                .then((r) => r.json())
                .then((data) => {
                    if (!data.success) {
                        const errorMessage = data.data && data.data.message ? data.data.message : 'unknown error';
                        setButtonIdle(btn);
                        setStatus(panel, 'Error: ' + errorMessage);
                        redirectToDeploymentNotice(target, 'error', 'Could not start ' + (target === 'production' ? 'deployment' : 'build') + ': ' + errorMessage);
                        return;
                    }

                    startTimer(panel);
                    startPolling(btn, panel, target);
                })
                .catch(() => {
                    stopTimer();
                    setButtonIdle(btn);
                    setStatus(panel, 'Request failed.');
                    redirectToDeploymentNotice(target, 'error', 'Request failed while starting ' + (target === 'production' ? 'deployment' : 'build') + '.');
                });
        });
    });    // --- Dev tools ---
    function wireDevToolButton(selector, { statusClass, startingText, startAction, startNonce, pollAction, pollNonce: pollNonceVal, startErrPrefix, failPrefix, onDone }) {
        const btn = document.querySelector(selector);
        if (!btn) return;
        let devPollTimer = null;

        const stopDevPolling = () => {
            if (devPollTimer) {
                clearInterval(devPollTimer);
                devPollTimer = null;
            }
        };

        btn.addEventListener('click', () => {
            const panel = btn.closest('.deployment-tab');
            const statusEl = panel && panel.querySelector(statusClass);

            setButtonRunning(btn);
            if (statusEl) statusEl.textContent = startingText;

            fetch(ajaxUrl, { method: 'POST', body: new URLSearchParams({ action: startAction, nonce: startNonce }) })
                .then((r) => r.json())
                .then((data) => {
                    if (!data.success) {
                        setButtonIdle(btn);
                        if (statusEl) statusEl.textContent = '';
                        const msg = data.data && data.data.message ? data.data.message : 'Unknown error';
                        showAdminNotice(startErrPrefix + ': ' + msg, 'error');
                        return;
                    }

                    stopDevPolling();
                    devPollTimer = setInterval(() => {
                        fetch(ajaxUrl, { method: 'POST', body: new URLSearchParams({ action: pollAction, nonce: pollNonceVal }) })
                            .then((r) => r.json())
                            .then((pollData) => {
                                if (!pollData || !pollData.success) {
                                    stopDevPolling();
                                    setButtonIdle(btn);
                                    if (statusEl) statusEl.textContent = '';
                                    const msg = pollData && pollData.data && pollData.data.message ? pollData.data.message : 'Status check failed.';
                                    showAdminNotice(failPrefix + ': ' + msg, 'error');
                                    return;
                                }

                                const st = pollData.data;
                                if (!st || typeof st !== 'object' || Array.isArray(st) || !('status' in st)) {
                                    stopDevPolling();
                                    setButtonIdle(btn);
                                    if (statusEl) statusEl.textContent = 'Status check failed.';
                                    showAdminNotice(failPrefix + ': Unable to verify status.', 'error');
                                    return;
                                }

                                const currentStatus = String(st.status || '').toLowerCase();
                                const activeStatuses = ['running', 'requested', 'in_progress', 'pending', 'processing'];

                                if (currentStatus === 'done') {
                                    stopDevPolling();
                                    setButtonIdle(btn);
                                    if (statusEl) statusEl.textContent = '';
                                    showAdminNotice(st.message || 'Done.', 'success');
                                    if (typeof onDone === 'function') {
                                        onDone(btn, st);
                                    }
                                    return;
                                }

                                if (currentStatus === 'failed') {
                                    stopDevPolling();
                                    setButtonIdle(btn);
                                    if (statusEl) statusEl.textContent = st.message || 'Check server logs.';
                                    showAdminNotice(failPrefix + ': ' + (st.message || 'Check server logs.'), 'error');
                                    return;
                                }

                                if (activeStatuses.includes(currentStatus)) {
                                    if (statusEl) {
                                        statusEl.textContent = st.message || startingText;
                                    }
                                    return;
                                }

                                stopDevPolling();
                                setButtonIdle(btn);
                                if (statusEl) statusEl.textContent = 'Status check failed.';
                                showAdminNotice(failPrefix + ': Unable to verify status.', 'error');
                            })
                            .catch((error) => {
                                stopDevPolling();
                                setButtonIdle(btn);
                                if (statusEl) statusEl.textContent = 'Status check failed.';
                                showAdminNotice(failPrefix + ': Unable to check status.' + (error && error.message ? ' ' + error.message : ''), 'error');
                            });
                    }, 2000);
                })
                .catch(() => {
                    setButtonIdle(btn);
                    if (statusEl) statusEl.textContent = '';
                    showAdminNotice('Request failed.', 'error');
                });
        });
    }

    wireDevToolButton('.js-push-to-staging', {
        statusClass: '.js-push-status',
        startingText: 'Pushing\u2026',
        startAction: 'abcnorio_push_to_staging',
        startNonce: pushToStagingNonce,
        pollAction: 'abcnorio_poll_push_status',
        pollNonce: pollPushNonce,
        startErrPrefix: 'Could not start push',
        failPrefix: 'Push failed',
    });

    wireDevToolButton('.js-copy-media-to-dev', {
        statusClass: '.js-copy-media-status',
        startingText: 'Pushing\u2026',
        startAction: 'abcnorio_copy_media_to_dev',
        startNonce: copyMediaNonce,
        pollAction: 'abcnorio_poll_copy_media_status',
        pollNonce: pollCopyMediaNonce,
        startErrPrefix: 'Could not start push',
        failPrefix: 'Push failed',
    });

    wireDevToolButton('.js-pull-from-staging', {
        statusClass: '.js-pull-from-staging-status',
        startingText: 'Pushing\u2026',
        startAction: 'abcnorio_pull_from_staging',
        startNonce: pullFromStagingNonce,
        pollAction: 'abcnorio_poll_pull_from_staging_status',
        pollNonce: pollPullFromStagingNonce,
        startErrPrefix: 'Could not start push',
        failPrefix: 'Push failed',
    });

    wireDevToolButton('.js-copy-media-to-staging', {
        statusClass: '.js-copy-media-to-staging-status',
        startingText: 'Pushing\u2026',
        startAction: 'abcnorio_copy_media_to_staging',
        startNonce: copyMediaToStagingNonce,
        pollAction: 'abcnorio_poll_copy_media_to_staging_status',
        pollNonce: pollCopyMediaToStagingNonce,
        startErrPrefix: 'Could not start push',
        failPrefix: 'Push failed',
    });

    wireDevToolButton('.js-pull-from-dev', {
        statusClass: '.js-pull-from-dev-status',
        startingText: 'Pushing\u2026',
        startAction: 'abcnorio_pull_from_dev',
        startNonce: pullFromDevNonce,
        pollAction: 'abcnorio_poll_pull_from_dev_status',
        pollNonce: pollPullFromDevNonce,
        startErrPrefix: 'Could not start push',
        failPrefix: 'Push failed',
    });

    wireDevToolButton('.js-backup-media-dev', {
        statusClass: '.js-backup-media-dev-status',
        startingText: 'Backing up\u2026',
        startAction: 'abcnorio_backup_media_dev',
        startNonce: backupMediaDevNonce,
        pollAction: 'abcnorio_poll_backup_media_dev_status',
        pollNonce: pollBackupMediaDevNonce,
        startErrPrefix: 'Could not start backup',
        failPrefix: 'Backup failed',
        onDone: () => {
            refreshMediaBackupList('dev').catch((error) => {
                showAdminNotice(error.message || 'Could not refresh media backups.', 'error');
            });
        },
    });

    wireDevToolButton('.js-backup-media-staging', {
        statusClass: '.js-backup-media-staging-status',
        startingText: 'Backing up\u2026',
        startAction: 'abcnorio_backup_media_staging',
        startNonce: backupMediaStagingNonce,
        pollAction: 'abcnorio_poll_backup_media_staging_status',
        pollNonce: pollBackupMediaStagingNonce,
        startErrPrefix: 'Could not start backup',
        failPrefix: 'Backup failed',
        onDone: () => {
            refreshMediaBackupList('staging').catch((error) => {
                showAdminNotice(error.message || 'Could not refresh media backups.', 'error');
            });
        },
    });

    wireDevToolButton('.js-backup-database-staging', {
        statusClass: '.js-backup-database-staging-status',
        startingText: 'Backing up\u2026',
        startAction: 'abcnorio_backup_database_staging',
        startNonce: backupDatabaseStagingNonce,
        pollAction: 'abcnorio_poll_backup_database_staging_status',
        pollNonce: pollBackupDatabaseStagingNonce,
        startErrPrefix: 'Could not start backup',
        failPrefix: 'Backup failed',
        onDone: () => {
            refreshDatabaseBackupList().catch((error) => {
                showAdminNotice(error.message || 'Could not refresh database backups.', 'error');
            });
        },
    });

    document.addEventListener('click', (event) => {
        const btn = event.target.closest('.js-delete-media-backup');
        if (!btn) return;

        event.preventDefault();
        const env = btn.dataset.env || '';
        const file = btn.dataset.file || '';
        if (!env || !file) return;
        if (!window.confirm('Delete this media backup archive?')) return;

        btn.disabled = true;
        fetch(ajaxUrl, {
            method: 'POST',
            body: new URLSearchParams({
                action: 'abcnorio_delete_media_backup',
                nonce: deleteMediaBackupNonce,
                env,
                file,
            }),
        })
            .then((r) => r.json())
            .then((data) => {
                if (!data.success) {
                    const msg = data.data && data.data.message ? data.data.message : 'Delete failed.';
                    throw new Error(msg);
                }
                showAdminNotice('Media backup deleted.', 'success');
                return refreshMediaBackupList(env);
            })
            .catch((error) => {
                showAdminNotice(error.message || 'Delete failed.', 'error');
            })
            .finally(() => {
                btn.disabled = false;
            });
    });

    document.addEventListener('click', (event) => {
        const btn = event.target.closest('.js-delete-database-backup');
        if (!btn) return;

        event.preventDefault();
        const file = btn.dataset.file || '';
        if (!file || !window.confirm('Delete this database backup archive?')) return;

        btn.disabled = true;
        fetch(ajaxUrl, {
            method: 'POST',
            body: new URLSearchParams({
                action: 'abcnorio_delete_database_backup',
                nonce: deleteDatabaseBackupNonce,
                file,
            }),
        })
            .then((r) => r.json())
            .then((data) => {
                if (!data.success) {
                    throw new Error(data.data && data.data.message ? data.data.message : 'Delete failed.');
                }
                showAdminNotice('Database backup deleted.', 'success');
                return refreshDatabaseBackupList();
            })
            .catch((error) => {
                showAdminNotice(error.message || 'Delete failed.', 'error');
            })
            .finally(() => {
                btn.disabled = false;
            });
    });

    refreshMediaBackupList('dev').catch(() => {});
    refreshMediaBackupList('staging').catch(() => {});
    refreshDatabaseBackupList().catch(() => {});

}());
