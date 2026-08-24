(function () {
    const basePath = window.ChatNotificationsBase || "../ajax";
    let lastNotificationId = 0;
    let hasLoadedOnce = false;
    let isPolling = false;
    let lastRenderSignature = "";
    let audioContext = null;
    let soundEnabled = true;

    function ensureStyles() {
        if (document.getElementById("notification-module-style")) {
            return;
        }

        const style = document.createElement("style");
        style.id = "notification-module-style";
        style.textContent = `
            .notification-widget{
                position:fixed;
                right:22px;
                top:22px;
                z-index:9999;
                font-family:Arial, Helvetica, sans-serif;
            }
            .notification-widget.inline{
                position:relative;
                right:auto;
                top:auto;
                z-index:20;
            }
            .notification-bell{
                width:52px;
                height:52px;
                border:1px solid rgba(255,255,255,.45);
                border-radius:14px;
                background:linear-gradient(135deg,#2563eb,#1d4ed8);
                color:#ffffff;
                box-shadow:
                    0 16px 32px rgba(37,99,235,.34),
                    inset 0 1px 0 rgba(255,255,255,.35),
                    inset 0 -8px 18px rgba(15,23,42,.16);
                cursor:pointer;
                position:relative;
                font-size:19px;
                transition:transform .18s ease, box-shadow .18s ease;
            }
            .notification-bell:hover{
                transform:translateY(-2px) scale(1.02);
                box-shadow:
                    0 22px 44px rgba(37,99,235,.42),
                    inset 0 1px 0 rgba(255,255,255,.38),
                    inset 0 -8px 18px rgba(15,23,42,.18);
            }
            .notification-bell:active{
                transform:translateY(1px) scale(.98);
            }
            .notification-count{
                position:absolute;
                right:-6px;
                top:-7px;
                min-width:20px;
                height:20px;
                padding:0 5px;
                border-radius:999px;
                background:#dc2626;
                color:#ffffff;
                display:none;
                align-items:center;
                justify-content:center;
                font-size:11px;
                font-weight:800;
            }
            .notification-count.show{
                display:flex;
                animation:notificationPulse .9s ease both;
            }
            .notification-panel{
                position:absolute;
                right:0;
                top:64px;
                width:390px;
                max-height:560px;
                overflow:auto;
                border:1px solid rgba(219,227,238,.9);
                border-radius:18px;
                background:rgba(255,255,255,.92);
                box-shadow:0 26px 70px rgba(15,23,42,.22);
                backdrop-filter:blur(16px);
                display:none;
            }
            .notification-panel.open{
                display:block;
                animation:notificationPanelIn .18s ease-out;
            }
            .notification-head{
                padding:16px;
                border-bottom:1px solid #eef2f7;
                display:flex;
                justify-content:space-between;
                align-items:center;
                font-weight:800;
            }
            .notification-title{
                display:flex;
                align-items:center;
                gap:9px;
            }
            .notification-title i{
                width:34px;
                height:34px;
                border-radius:8px;
                display:flex;
                align-items:center;
                justify-content:center;
                background:#eff6ff;
                color:#2563eb;
            }
            .notification-actions{
                display:flex;
                align-items:center;
                gap:7px;
            }
            .notification-read{
                border:0;
                background:#eff6ff;
                color:#2563eb;
                border-radius:6px;
                padding:7px 9px;
                cursor:pointer;
                font-weight:700;
                font-size:12px;
            }
            .notification-sound{
                width:31px;
                height:31px;
                border:1px solid #dbe3ee;
                border-radius:8px;
                background:#ffffff;
                color:#475569;
                cursor:pointer;
            }
            .notification-sound.active{
                background:#eff6ff;
                color:#2563eb;
                border-color:#bfdbfe;
            }
            .notification-item{
                padding:13px 14px;
                border-bottom:1px solid #f1f5f9;
                cursor:pointer;
                display:grid;
                grid-template-columns:38px 1fr;
                gap:10px;
            }
            .notification-item-main{
                min-width:0;
            }
            .notification-click-hint{
                color:#2563eb;
                font-size:11px;
                font-weight:800;
                margin-top:7px;
            }
            .notification-item:hover{
                background:#f8fafc;
            }
            .notification-item.unread{
                background:#eff6ff;
            }
            .notification-avatar,
            .notification-type-icon{
                width:38px;
                height:38px;
                border-radius:10px;
                display:flex;
                align-items:center;
                justify-content:center;
                background:#2563eb;
                color:#ffffff;
                font-weight:900;
                text-transform:uppercase;
                overflow:hidden;
            }
            .notification-avatar img{
                width:100%;
                height:100%;
                object-fit:cover;
            }
            .notification-type-icon{
                background:#eff6ff;
                color:#2563eb;
            }
            .notification-item strong{
                display:block;
                margin-bottom:4px;
                color:#111827;
                font-size:14px;
            }
            .notification-item span{
                display:block;
                color:#64748b;
                font-size:12px;
                line-height:1.45;
            }
            .notification-section-title{
                padding:12px 16px 8px;
                color:#64748b;
                font-size:11px;
                font-weight:800;
                text-transform:uppercase;
                letter-spacing:.04em;
                background:#f8fafc;
                border-bottom:1px solid #eef2f7;
            }
            .notification-list-body{
                padding:12px;
            }
            .notification-card{
                position:relative;
                margin-bottom:12px;
                padding:14px;
                border:1px solid rgba(219,227,238,.92);
                border-radius:16px;
                background:linear-gradient(180deg,rgba(255,255,255,.96),rgba(248,250,252,.94));
                box-shadow:0 14px 34px rgba(15,23,42,.10);
                cursor:pointer;
                transition:transform .18s ease, box-shadow .18s ease, border-color .18s ease;
                animation:notificationSlideIn .2s ease-out;
            }
            .notification-card:hover{
                transform:translateY(-2px);
                border-color:rgba(37,99,235,.26);
                box-shadow:0 20px 44px rgba(15,23,42,.14);
            }
            .notification-card-top{
                display:grid;
                grid-template-columns:44px 1fr auto;
                gap:11px;
                align-items:start;
            }
            .notification-card-title{
                min-width:0;
            }
            .notification-card-title strong{
                display:block;
                color:#111827;
                font-size:14px;
                line-height:1.2;
                white-space:nowrap;
                overflow:hidden;
                text-overflow:ellipsis;
            }
            .notification-presence{
                display:flex;
                align-items:center;
                gap:6px;
                margin-top:4px;
                color:#64748b;
                font-size:11px;
                font-weight:700;
            }
            .notification-online-dot{
                width:8px;
                height:8px;
                border-radius:50%;
                background:#94a3b8;
            }
            .notification-online-dot.online{
                background:#16a34a;
                box-shadow:0 0 0 4px rgba(22,163,74,.12);
                animation:notificationPulse 1.7s infinite;
            }
            .notification-badge{
                min-width:28px;
                height:24px;
                padding:0 8px;
                border-radius:999px;
                display:flex;
                align-items:center;
                justify-content:center;
                background:#2563eb;
                color:#ffffff;
                font-size:11px;
                font-weight:900;
                box-shadow:0 10px 22px rgba(37,99,235,.24);
                animation:notificationPulse 1.7s infinite;
            }
            .notification-card-preview{
                margin-top:10px;
                padding:10px 11px;
                border-radius:12px;
                background:#eff6ff;
                color:#334155;
                font-size:12px;
                line-height:1.45;
            }
            .notification-card-preview strong{
                color:#1d4ed8;
                font-size:12px;
            }
            .notification-card-meta{
                display:flex;
                align-items:center;
                justify-content:space-between;
                gap:10px;
                margin-top:9px;
                color:#64748b;
                font-size:11px;
                font-weight:800;
            }
            .notification-open-btn{
                border:0;
                border-radius:10px;
                background:#eff6ff;
                color:#2563eb;
                padding:8px 10px;
                cursor:pointer;
                font-size:12px;
                font-weight:900;
            }
            .notification-open-btn:hover{
                background:#dbeafe;
            }
            .notification-recent{
                margin:0 12px 8px;
                padding:10px 12px;
                border:1px solid #eef2f7;
                border-radius:14px;
                background:#ffffff;
                display:grid;
                grid-template-columns:34px 1fr;
                gap:10px;
            }
            .notification-recent strong{
                display:block;
                color:#111827;
                font-size:13px;
            }
            .notification-recent span{
                display:block;
                color:#64748b;
                font-size:12px;
                margin-top:3px;
            }
            .notification-empty{
                margin:12px;
                padding:26px 18px;
                border:1px dashed #dbe3ee;
                border-radius:16px;
                background:linear-gradient(180deg,#ffffff,#f8fafc);
                color:#64748b;
                text-align:center;
                font-size:13px;
            }
            .notification-empty:before{
                content:"";
                width:54px;
                height:54px;
                margin:0 auto 10px;
                border-radius:18px;
                display:block;
                background:
                    radial-gradient(circle at 50% 36%, #2563eb 0 8px, transparent 9px),
                    linear-gradient(135deg,#eff6ff,#dbeafe);
                box-shadow:0 12px 28px rgba(37,99,235,.14);
            }
            .notification-summary{
                padding:13px 14px;
                border-bottom:1px solid #eef2f7;
                background:#eff6ff;
                display:grid;
                grid-template-columns:38px 1fr;
                gap:10px;
            }
            .notification-summary strong{
                display:block;
                margin-bottom:4px;
                color:#111827;
                font-size:14px;
            }
            .notification-summary span{
                display:block;
                color:#475569;
                font-size:12px;
                line-height:1.45;
            }
            .notification-kind{
                display:flex;
                align-items:center;
                gap:5px;
                margin-top:6px;
                padding:4px 7px;
                border-radius:999px;
                background:#ffffff;
                color:#2563eb;
                font-size:11px;
                font-weight:800;
                width:max-content;
                max-width:100%;
                line-height:1;
            }
            .notification-kind i{
                width:14px;
                min-width:14px;
                text-align:center;
            }
            .notification-time{
                color:#94a3b8;
                font-size:11px;
                margin-top:6px;
            }
            .notification-row-actions{
                display:flex;
                gap:7px;
                margin-top:9px;
                flex-wrap:wrap;
            }
            .notification-row-actions button{
                border:0;
                border-radius:6px;
                background:#ffffff;
                color:#2563eb;
                padding:7px 9px;
                cursor:pointer;
                font-size:12px;
                font-weight:800;
            }
            .notification-item .notification-row-actions button{
                background:#eff6ff;
            }
            .notification-reply{
                display:flex;
                gap:7px;
                margin-top:9px;
            }
            .notification-quick-reply{
                margin-top:12px;
                padding:10px;
                border:1px solid #dbeafe;
                border-radius:14px;
                background:#ffffff;
            }
            .notification-quick-reply-label{
                display:flex;
                align-items:center;
                gap:7px;
                margin-bottom:8px;
                color:#475569;
                font-size:12px;
                font-weight:800;
            }
            .notification-quick-reply-label strong{
                color:#111827;
                font-size:12px;
            }
            .notification-quick-reply .notification-reply{
                margin-top:0;
            }
            .notification-reply input{
                min-width:0;
                flex:1;
                height:34px;
                border:1px solid #dbe3ee;
                border-radius:12px;
                padding:0 10px;
                outline:none;
                font-size:13px;
            }
            .notification-reply input:focus{
                border-color:#2563eb;
                box-shadow:0 0 0 3px rgba(37,99,235,.12);
            }
            .notification-reply button{
                width:38px;
                height:36px;
                border:0;
                border-radius:12px;
                background:#2563eb;
                color:#ffffff;
                cursor:pointer;
            }
            .notification-toast{
                position:fixed;
                right:22px;
                bottom:22px;
                width:min(380px, calc(100vw - 44px));
                padding:0;
                border:1px solid #dbe3ee;
                border-radius:8px;
                background:#ffffff;
                box-shadow:0 20px 45px rgba(15,23,42,.18);
                display:none;
                z-index:10000;
                font-family:Arial, Helvetica, sans-serif;
                overflow:hidden;
            }
            .notification-toast.show{
                display:grid;
                grid-template-columns:54px 1fr;
                animation:notificationSlideIn .2s ease-out;
            }
            .notification-toast-icon{
                background:#2563eb;
                color:#ffffff;
                display:flex;
                align-items:center;
                justify-content:center;
                font-size:20px;
            }
            .notification-toast-body{
                padding:14px;
            }
            .notification-toast strong{
                display:block;
                margin-bottom:5px;
            }
            .notification-toast span{
                color:#64748b;
                font-size:13px;
            }
            @keyframes notificationSlideIn{
                from{transform:translateY(12px);opacity:0}
                to{transform:translateY(0);opacity:1}
            }
            @keyframes notificationPanelIn{
                from{transform:translateY(8px) scale(.98);opacity:0}
                to{transform:translateY(0) scale(1);opacity:1}
            }
            @keyframes notificationPulse{
                0%,100%{transform:scale(1);}
                50%{transform:scale(1.08);}
            }
            @media(max-width:640px){
                .notification-widget{
                    right:14px;
                    top:14px;
                }
                .notification-panel{
                    width:calc(100vw - 28px);
                    right:0;
                }
                .notification-toast{
                    right:14px;
                    bottom:14px;
                    width:calc(100vw - 28px);
                }
            }
        `;
        document.head.appendChild(style);
    }

    function ensureMarkup() {
        if (document.getElementById("notification-widget")) {
            return;
        }

        const widget = document.createElement("div");
        widget.className = "notification-widget";
        widget.id = "notification-widget";
        widget.innerHTML = `
            <button class="notification-bell" id="notification-bell" type="button" title="Notifications">
                <i class="fa-solid fa-bell"></i>
                <span class="notification-count" id="notification-count">0</span>
            </button>
            <div class="notification-panel" id="notification-panel">
                <div class="notification-head">
                    <span class="notification-title"><i class="fa-solid fa-bell"></i> Notifications</span>
                    <div class="notification-actions">
                        <button class="notification-sound active" id="notification-sound" type="button" title="Notification sound">
                            <i class="fa-solid fa-volume-high"></i>
                        </button>
                        <button class="notification-read" id="notification-read" type="button">Mark all read</button>
                    </div>
                </div>
                <div id="notification-list"></div>
            </div>
        `;

        const toast = document.createElement("div");
        toast.className = "notification-toast";
        toast.id = "notification-toast";

        const inlineTarget = document.getElementById("notification-inline");

        if (inlineTarget) {
            widget.classList.add("inline");
            inlineTarget.appendChild(widget);
        } else {
            document.body.appendChild(widget);
        }

        document.body.appendChild(toast);
    }

    function messageKind(notification) {
        const attachment = String(notification.attachment || notification.message || "").toLowerCase();

        if (notification.message_type === "image" && attachment.endsWith(".gif")) {
            return "GIF";
        }

        if (notification.message_type === "image") {
            return "image";
        }

        if (notification.message_type === "video") {
            return "video";
        }

        if (notification.message_type === "audio") {
            return "audio";
        }

        if (notification.message_type === "file") {
            return "file";
        }

        return "message";
    }

    function kindIcon(kind) {
        const icons = {
            GIF: "fa-file-image",
            image: "fa-image",
            video: "fa-video",
            audio: "fa-microphone",
            file: "fa-file",
            message: "fa-message"
        };

        return icons[kind] || "fa-message";
    }

    function preview(notification) {
        const kind = messageKind(notification);

        if (notification.message_type === "text") {
            return notification.message || "New message";
        }

        const article = ["image", "audio"].includes(kind) ? "an" : "a";
        return "Sent " + article + " " + kind;
    }

    function groupSummary(group) {
        const count = Number(group.unread_count || 0);
        const messageWord = count === 1 ? "new message" : "new messages";

        return `${count} ${messageWord}`;
    }

    function initials(name) {
        return String(name || "U").trim().slice(0, 1).toUpperCase() || "U";
    }

    function profileImageUrl(image) {
        if (!image) {
            return "";
        }

        if (String(image).startsWith("uploads/")) {
            return "../" + image;
        }

        return "../uploads/" + image;
    }

    function avatarHtml(name, image) {
        const url = profileImageUrl(image);

        if (url) {
            return `<img src="${escapeHtml(url)}" alt="${escapeHtml(name || "User")}">`;
        }

        return escapeHtml(initials(name));
    }

    function formatTime(value) {
        if (!value) {
            return "";
        }

        const date = new Date(String(value).replace(" ", "T"));

        if (Number.isNaN(date.getTime())) {
            return "";
        }

        return date.toLocaleTimeString([], {
            hour: "2-digit",
            minute: "2-digit"
        });
    }

    function relativeTime(value) {
        if (!value) {
            return "";
        }

        const date = new Date(String(value).replace(" ", "T"));

        if (Number.isNaN(date.getTime())) {
            return "";
        }

        const seconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000));

        if (seconds < 10) {
            return "just now";
        }

        if (seconds < 60) {
            return `${seconds} sec ago`;
        }

        const minutes = Math.floor(seconds / 60);

        if (minutes < 60) {
            return `${minutes} min ago`;
        }

        return formatTime(value);
    }

    function latestPreview(item) {
        return `Last: ${preview(item)}`;
    }

    function chatUrl(item) {
        const conversationId = encodeURIComponent(item.conversation_id || "");
        const user = encodeURIComponent(item.sender_name || "Chat");
        return `chat.php?conversation_id=${conversationId}&user=${user}`;
    }

    function openChat(item) {
        if (!item || !item.conversation_id) {
            return;
        }

        if (item.conversation_id) {
            const formData = new FormData();
            formData.append("conversation_id", item.conversation_id);
            fetch(basePath + "/read_notifications.php", {
                method: "POST",
                body: formData
            })
            .catch(function () {})
            .finally(function () {
                window.location.href = chatUrl(item);
            });
            return;
        }

        window.location.href = chatUrl(item);
    }

    function replyHtml(item) {
        return `
            <div class="notification-reply" data-reply-box>
                <input type="text" placeholder="Reply..." data-reply-input data-conversation-id="${escapeHtml(item.conversation_id || "")}">
                <button type="button" data-reply-send data-conversation-id="${escapeHtml(item.conversation_id || "")}" data-notification-id="${escapeHtml(item.id || item.latest_notification_id || "")}" title="Send reply">
                    <i class="fa-solid fa-paper-plane"></i>
                </button>
            </div>
        `;
    }

    function quickReplyHtml(item) {
        if (!item || !item.conversation_id) {
            return "";
        }

        return `
            <div class="notification-quick-reply">
                <div class="notification-quick-reply-label">
                    <i class="fa-solid fa-reply"></i>
                    <span>Quick reply to <strong>${escapeHtml(item.sender_name || "User")}</strong></span>
                </div>
                ${replyHtml(item)}
            </div>
        `;
    }

    function cardHtml(group) {
        const count = Number(group.unread_count || 1);
        const status = String(group.sender_status || "").toLowerCase();
        const isOnline = status === "online";
        const kind = messageKind(group);

        return `
            <div class="notification-card unread" data-notification-id="${escapeHtml(group.latest_notification_id || group.id || "")}" data-notification-open data-conversation-id="${escapeHtml(group.conversation_id || "")}" data-sender-name="${escapeHtml(group.sender_name || "User")}">
                <div class="notification-card-top">
                    <div class="notification-avatar">${avatarHtml(group.sender_name, group.profile_image)}</div>
                    <div class="notification-card-title">
                        <strong>${escapeHtml(group.sender_name || "User")}</strong>
                        <div class="notification-presence">
                            <span class="notification-online-dot ${isOnline ? "online" : ""}"></span>
                            ${escapeHtml(isOnline ? "Online" : "New message")}
                        </div>
                    </div>
                    <div class="notification-badge">${count}</div>
                </div>
                <div class="notification-card-preview">
                    <strong><i class="fa-solid ${kindIcon(kind)}"></i> ${escapeHtml(groupSummary(group))}</strong>
                    <span>${escapeHtml(latestPreview(group))}</span>
                </div>
                <div class="notification-card-meta">
                    <span>${escapeHtml(relativeTime(group.latest_created_at || group.created_at))}</span>
                    <button class="notification-open-btn" type="button" data-open-chat>Open Chat</button>
                </div>
                ${quickReplyHtml(group)}
            </div>
        `;
    }

    function recentHtml(item) {
        return `
            <div class="notification-recent" data-notification-id="${escapeHtml(item.id || "")}" data-notification-open data-conversation-id="${escapeHtml(item.conversation_id || "")}" data-sender-name="${escapeHtml(item.sender_name || "User")}">
                <div class="notification-type-icon"><i class="fa-solid ${kindIcon(messageKind(item))}"></i></div>
                <div>
                    <strong>${escapeHtml(item.sender_name || "User")}</strong>
                    <span>${escapeHtml(preview(item))} · ${escapeHtml(formatTime(item.created_at))}</span>
                </div>
            </div>
        `;
    }

    function sendReply(conversationId, notificationId, input) {
        const message = input.value.trim();

        if (!message || !conversationId) {
            return;
        }

        const formData = new FormData();
        formData.append("conversation_id", conversationId);
        formData.append("message", message);

        fetch(basePath + "/send_message.php", {
            method: "POST",
            body: formData
        })
        .then(function (res) {
            return res.json();
        })
        .then(function (data) {
            if (data.status !== "success") {
                alert(data.message || "Reply could not be sent");
                return;
            }

            input.value = "";

            if (conversationId) {
                const readData = new FormData();
                readData.append("conversation_id", conversationId);
                fetch(basePath + "/read_notifications.php", {
                    method: "POST",
                    body: readData
                }).catch(function () {});
            }

            pollNotifications();
        })
        .catch(function () {
            alert("Server error while sending reply");
        });
    }

    function render(data) {
        const count = document.getElementById("notification-count");
        const list = document.getElementById("notification-list");
        const unread = Number(data.unread || 0);
        const unreadGroups = data.unread_groups || [];
        const fallbackUnread = (data.notifications || []).filter(function (item) {
            return Number(item.is_read) === 0;
        }).map(function (item) {
            return Object.assign({}, item, {
                unread_count: 1,
                latest_notification_id: item.id,
                latest_created_at: item.created_at
            });
        });
        const unreadItems = unreadGroups.length > 0 ? unreadGroups : fallbackUnread;
        const recentItems = (data.recent_notifications || []).slice(0, 10);

        count.textContent = unread > 99 ? "99+" : unread;
        count.classList.toggle("show", unread > 0);

        const renderSignature = JSON.stringify({
            unread: unread,
            unreadItems: unreadItems.map(function (item) {
                return [
                    item.latest_notification_id || item.id,
                    item.conversation_id,
                    item.unread_count,
                    item.message,
                    item.message_type,
                    item.attachment,
                    item.latest_created_at || item.created_at,
                    item.sender_status
                ];
            }),
            recentItems: recentItems.map(function (item) {
                return [
                    item.id,
                    item.conversation_id,
                    item.message,
                    item.message_type,
                    item.attachment,
                    item.created_at
                ];
            })
        });

        if (renderSignature === lastRenderSignature) {
            return;
        }

        const unreadHtml = unreadItems.length > 0
            ? `<div class="notification-section-title">New messages</div><div class="notification-list-body">${unreadItems.map(cardHtml).join("")}</div>`
            : `<div class="notification-empty">No new notifications.</div>`;

        const recentHtmlBlock = recentItems.length > 0
            ? `<div class="notification-section-title">Recent notifications</div>${recentItems.map(recentHtml).join("")}`
            : "";

        list.innerHTML = unreadHtml + recentHtmlBlock;
        lastRenderSignature = renderSignature;
    }

    function escapeHtml(value) {
        return String(value || "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function showToast(notification) {
        const toast = document.getElementById("notification-toast");
        const kind = messageKind(notification);
        const count = Number(notification.unread_count || 1);

        toast.innerHTML = `
            <div class="notification-toast-icon"><i class="fa-solid ${kindIcon(kind)}"></i></div>
            <div class="notification-toast-body">
                <strong>${escapeHtml(notification.sender_name || "New message")}</strong>
                <span>${escapeHtml(count > 1 ? groupSummary(notification) : preview(notification))}</span>
                <div class="notification-click-hint">Click to open chat</div>
            </div>
        `;
        toast.dataset.conversationId = notification.conversation_id || "";
        toast.dataset.senderName = notification.sender_name || "Chat";
        toast.dataset.notificationId = notification.id || notification.latest_notification_id || "";
        toast.classList.add("show");

        setTimeout(function () {
            toast.classList.remove("show");
        }, 4500);
    }

    function unlockAudio() {
        if (audioContext) {
            return;
        }

        const AudioContext = window.AudioContext || window.webkitAudioContext;

        if (!AudioContext) {
            return;
        }

        audioContext = new AudioContext();

        if (audioContext.state === "suspended") {
            audioContext.resume();
        }
    }

    function playNotificationSound() {
        if (!soundEnabled) {
            return;
        }

        unlockAudio();

        if (!audioContext) {
            return;
        }

        const now = audioContext.currentTime;
        const gain = audioContext.createGain();
        gain.gain.setValueAtTime(0.0001, now);
        gain.gain.exponentialRampToValueAtTime(0.18, now + 0.01);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.38);
        gain.connect(audioContext.destination);

        [740, 980].forEach(function (frequency, index) {
            const oscillator = audioContext.createOscillator();
            oscillator.type = "sine";
            oscillator.frequency.setValueAtTime(frequency, now + index * 0.12);
            oscillator.connect(gain);
            oscillator.start(now + index * 0.12);
            oscillator.stop(now + index * 0.12 + 0.18);
        });
    }

    function showBrowserNotification(notification) {
        if (!("Notification" in window)) {
            return;
        }

        if (Notification.permission === "granted") {
            const count = Number(notification.unread_count || 1);
            new Notification(notification.sender_name || "New message", {
                body: count > 1 ? groupSummary(notification) : preview(notification)
            });
            return;
        }

        if (Notification.permission === "default") {
            Notification.requestPermission();
        }
    }

    function pollNotifications() {
        if (isPolling) {
            return;
        }

        isPolling = true;

        fetch(basePath + "/get_notifications.php", {
            cache: "no-store"
        })
        .then(function (res) {
            return res.json();
        })
        .then(function (data) {
            if (data.status !== "success") {
                return;
            }

            render(data);

            const unreadGroups = data.unread_groups || [];
            const fallbackUnread = (data.notifications || []).filter(function (item) {
                return Number(item.is_read) === 0;
            }).map(function (item) {
                return Object.assign({}, item, {
                    unread_count: 1,
                    latest_notification_id: item.id,
                    latest_created_at: item.created_at
                });
            });
            const latest = unreadGroups[0] || fallbackUnread[0] || null;
            const latestId = latest ? Number(latest.latest_notification_id || latest.id || 0) : 0;

            if (latest && hasLoadedOnce && latestId > lastNotificationId) {
                showToast(latest);
                playNotificationSound();
                showBrowserNotification(latest);
            }

            if (latestId > 0) {
                lastNotificationId = Math.max(lastNotificationId, latestId);
            }

            hasLoadedOnce = true;
        })
        .catch(function () {})
        .finally(function () {
            isPolling = false;
        });
    }

    function markAllRead() {
        fetch(basePath + "/read_notifications.php", {
            method: "POST",
            body: new FormData()
        })
        .then(function () {
            pollNotifications();
        })
        .catch(function () {});
    }

    document.addEventListener("DOMContentLoaded", function () {
        ensureStyles();
        ensureMarkup();

        document.getElementById("notification-bell").addEventListener("click", function () {
            document.getElementById("notification-panel").classList.toggle("open");
        });

        document.getElementById("notification-read").addEventListener("click", markAllRead);

        document.getElementById("notification-sound").addEventListener("click", function () {
            soundEnabled = !soundEnabled;
            this.classList.toggle("active", soundEnabled);
            this.innerHTML = soundEnabled
                ? `<i class="fa-solid fa-volume-high"></i>`
                : `<i class="fa-solid fa-volume-xmark"></i>`;

            if (soundEnabled) {
                playNotificationSound();
            }
        });

        document.addEventListener("click", unlockAudio, { once: true });
        document.addEventListener("keydown", unlockAudio, { once: true });

        document.getElementById("notification-list").addEventListener("click", function (event) {
            const replySend = event.target.closest("[data-reply-send]");
            const replyBox = event.target.closest("[data-reply-box]");
            const markRead = event.target.closest("[data-mark-read]");
            const item = event.target.closest("[data-notification-id]");

            if (replySend) {
                event.stopPropagation();
                const box = replySend.closest("[data-reply-box]");
                const input = box.querySelector("[data-reply-input]");
                sendReply(replySend.dataset.conversationId, replySend.dataset.notificationId, input);
                return;
            }

            if (replyBox) {
                event.stopPropagation();
                return;
            }

            if (markRead) {
                event.stopPropagation();
                const formData = new FormData();
                formData.append("notification_id", markRead.dataset.notificationId);

                fetch(basePath + "/read_notifications.php", {
                    method: "POST",
                    body: formData
                })
                .then(function () {
                    pollNotifications();
                })
                .catch(function () {});
                return;
            }

            if (!item) {
                return;
            }

            openChat({
                id: item.dataset.notificationId,
                conversation_id: item.dataset.conversationId,
                sender_name: item.dataset.senderName
            });
        });

        document.getElementById("notification-list").addEventListener("keydown", function (event) {
            if (event.key !== "Enter") {
                return;
            }

            const input = event.target.closest("[data-reply-input]");

            if (!input) {
                return;
            }

            const box = input.closest("[data-reply-box]");
            const button = box.querySelector("[data-reply-send]");
            sendReply(button.dataset.conversationId, button.dataset.notificationId, input);
        });

        document.getElementById("notification-toast").addEventListener("click", function () {
            openChat({
                id: this.dataset.notificationId,
                conversation_id: this.dataset.conversationId,
                sender_name: this.dataset.senderName
            });
        });

        pollNotifications();
        setInterval(pollNotifications, 5000);
    });
})();
