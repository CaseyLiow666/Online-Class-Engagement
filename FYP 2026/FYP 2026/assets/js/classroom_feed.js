(function () {
    'use strict';

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function bodyToHtml(body) {
        return escapeHtml(body).replace(/\n/g, '<br>');
    }

    function canManageMessage(mode, m, viewerId, viewerIsTeacher) {
        if (mode === 'chat') {
            return m.message_type === 'text' && Number(m.user_id) === Number(viewerId);
        }
        if (mode === 'announcements') {
            return viewerIsTeacher && m.message_type === 'announcement';
        }
        return false;
    }

    function bubbleClassName(m, mode) {
        if (mode === 'announcements') {
            return 'chat-bubble ' + (m.message_type === 'notification'
                ? 'chat-bubble--notification'
                : 'chat-bubble--announcement');
        }
        return 'chat-bubble' + (m.is_teacher ? ' chat-bubble--teacher' : '');
    }

    function appendMetaContent(metaEl, m, mode) {
        let html = '<strong>' + escapeHtml(m.display) + '</strong>';
        if (mode === 'chat') {
            if (m.is_teacher) {
                html += ' <span class="badge badge-teacher">Teacher</span>';
            }
        } else {
            html += m.message_type === 'notification'
                ? ' <span class="badge badge-admin">System</span>'
                : ' <span class="badge badge-teacher">Announcement</span>';
        }
        html += ' · <span class="muted">' + escapeHtml(m.created_at) + '</span>';
        metaEl.innerHTML = html;
    }

    function createActionsEl() {
        const actions = document.createElement('div');
        actions.className = 'chat-bubble__actions btn-group btn-group--compact';
        actions.setAttribute('role', 'group');
        actions.setAttribute('aria-label', 'Message actions');
        actions.innerHTML =
            '<button type="button" class="btn btn-ghost btn-xs chat-msg-edit" title="Edit">Edit</button>' +
            '<button type="button" class="btn btn-danger btn-xs chat-msg-delete" title="Delete">Delete</button>';
        return actions;
    }

    function createMessageElement(m, mode, viewerId, viewerIsTeacher) {
        const wrap = document.createElement('div');
        wrap.className = bubbleClassName(m, mode);
        wrap.dataset.messageId = String(m.id);
        wrap.dataset.rawBody = m.body;

        const metaRow = document.createElement('div');
        metaRow.className = 'chat-bubble__meta-row';

        const meta = document.createElement('div');
        meta.className = 'chat-bubble__meta';
        appendMetaContent(meta, m, mode);
        metaRow.appendChild(meta);

        if (canManageMessage(mode, m, viewerId, viewerIsTeacher)) {
            metaRow.appendChild(createActionsEl());
        }

        wrap.appendChild(metaRow);

        const body = document.createElement('div');
        body.className = 'chat-bubble__body';
        body.innerHTML = bodyToHtml(m.body);
        wrap.appendChild(body);

        return wrap;
    }

    function initFeed(feedEl) {
        const mode = feedEl.dataset.feedMode || 'chat';
        const classroomId = Number(feedEl.dataset.classroomId || 0);
        const pollUrl = feedEl.dataset.pollUrl || '';
        const actionUrl = feedEl.dataset.actionUrl || '';
        const viewerId = Number(feedEl.dataset.viewerId || 0);
        const viewerIsTeacher = feedEl.dataset.viewerIsTeacher === '1';

        let lastId = 0;
        let bootstrapped = false;

        function appendMessages(list) {
            list.forEach(function (m) {
                feedEl.appendChild(createMessageElement(m, mode, viewerId, viewerIsTeacher));
                lastId = Math.max(lastId, m.id);
            });
            feedEl.scrollTop = feedEl.scrollHeight;
        }

        function postAction(action, messageId, body) {
            const fd = new FormData();
            fd.append('action', action);
            fd.append('message_id', String(messageId));
            fd.append('classroom_id', String(classroomId));
            if (body !== undefined) {
                fd.append('body', body);
            }
            return fetch(actionUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd,
            }).then(function (r) {
                return r.json().then(function (data) {
                    if (!r.ok || !data.ok) {
                        throw new Error((data && data.error) || 'Request failed');
                    }
                    return data;
                });
            });
        }

        function startEdit(bubble) {
            if (bubble.classList.contains('is-editing')) {
                return;
            }
            const bodyEl = bubble.querySelector('.chat-bubble__body');
            const actionsEl = bubble.querySelector('.chat-bubble__actions');
            if (!bodyEl) {
                return;
            }
            const original = bubble.dataset.rawBody || bodyEl.textContent || '';
            bubble.classList.add('is-editing');
            if (actionsEl) {
                actionsEl.style.display = 'none';
            }

            const editLabel = mode === 'announcements' ? 'Edit announcement' : 'Edit message';
            const form = document.createElement('form');
            form.className = 'chat-bubble__edit-form stack';
            form.innerHTML =
                '<label class="field"><span>' + editLabel + '</span>' +
                '<textarea rows="3" maxlength="4000" required></textarea></label>' +
                '<div class="btn-group btn-group--compact">' +
                '<button type="submit" class="btn btn-primary btn-xs">Save</button>' +
                '<button type="button" class="btn btn-ghost btn-xs chat-msg-cancel">Cancel</button>' +
                '</div>';
            const textarea = form.querySelector('textarea');
            textarea.value = original;
            bodyEl.replaceWith(form);
            textarea.focus();

            form.querySelector('.chat-msg-cancel').addEventListener('click', function () {
                bubble.classList.remove('is-editing');
                const newBody = document.createElement('div');
                newBody.className = 'chat-bubble__body';
                newBody.innerHTML = bodyToHtml(original);
                form.replaceWith(newBody);
                if (actionsEl) {
                    actionsEl.style.display = '';
                }
            });

            form.addEventListener('submit', function (ev) {
                ev.preventDefault();
                const nextBody = textarea.value.trim();
                if (!nextBody) {
                    return;
                }
                const messageId = Number(bubble.dataset.messageId);
                postAction('edit', messageId, nextBody)
                    .then(function (data) {
                        bubble.classList.remove('is-editing');
                        const saved = data.body || nextBody;
                        bubble.dataset.rawBody = saved;
                        const newBody = document.createElement('div');
                        newBody.className = 'chat-bubble__body';
                        newBody.innerHTML = bodyToHtml(saved);
                        form.replaceWith(newBody);
                        if (actionsEl) {
                            actionsEl.style.display = '';
                        }
                    })
                    .catch(function (err) {
                        alert(err.message || 'Could not save message.');
                    });
            });
        }

        feedEl.addEventListener('click', function (ev) {
            const editBtn = ev.target.closest('.chat-msg-edit');
            const deleteBtn = ev.target.closest('.chat-msg-delete');
            const bubble = ev.target.closest('.chat-bubble');
            if (!bubble) {
                return;
            }

            if (editBtn) {
                ev.preventDefault();
                startEdit(bubble);
                return;
            }

            if (deleteBtn) {
                ev.preventDefault();
                const messageId = Number(bubble.dataset.messageId);
                const confirmText = mode === 'announcements'
                    ? 'Are you sure you want to delete this announcement?'
                    : 'Are you sure you want to delete this message?';
                if (!window.confirm(confirmText)) {
                    return;
                }
                postAction('delete', messageId)
                    .then(function () {
                        bubble.remove();
                    })
                    .catch(function (err) {
                        alert(err.message || 'Could not delete message.');
                    });
            }
        });

        function tick() {
            const url = bootstrapped ? (pollUrl + '&since=' + lastId) : pollUrl;
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.ok) {
                        return;
                    }
                    if (data.messages && data.messages.length) {
                        appendMessages(data.messages);
                    }
                    bootstrapped = true;
                })
                .catch(function () {});
        }

        tick();
        setInterval(tick, 3000);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.chat-feed[data-feed-mode]').forEach(initFeed);
    });
})();
