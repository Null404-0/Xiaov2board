<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>专属节点管理 - {{$title}}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
            background: #f5f6f8;
            color: #2c3e50;
            font-size: 14px;
        }
        header {
            background: #001529;
            color: #fff;
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        header h1 { margin: 0; font-size: 18px; font-weight: 500; }
        header a { color: #69b1ff; text-decoration: none; font-size: 13px; }
        header a:hover { text-decoration: underline; }
        .auth-warning {
            background: #fff7e6;
            border: 1px solid #ffd591;
            color: #ad6800;
            padding: 12px 20px;
            margin: 16px;
            border-radius: 4px;
        }
        .layout {
            display: grid;
            grid-template-columns: 380px 1fr;
            gap: 16px;
            padding: 16px;
            height: calc(100vh - 56px);
        }
        .panel {
            background: #fff;
            border-radius: 6px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .panel-header {
            padding: 12px 16px;
            border-bottom: 1px solid #f0f0f0;
            font-weight: 500;
        }
        .panel-body { flex: 1; overflow: auto; padding: 12px 16px; }
        .toolbar {
            padding: 12px 16px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            gap: 8px;
        }
        input[type="text"], select {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid #d9d9d9;
            border-radius: 4px;
            font-size: 13px;
            outline: none;
        }
        input[type="text"]:focus, select:focus { border-color: #1677ff; }
        select { cursor: pointer; max-width: 140px; }
        button {
            padding: 6px 14px;
            border: 1px solid #d9d9d9;
            background: #fff;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
        }
        button:hover { border-color: #1677ff; color: #1677ff; }
        button.primary {
            background: #1677ff;
            color: #fff;
            border-color: #1677ff;
        }
        button.primary:hover { background: #4096ff; color: #fff; }
        button.danger {
            color: #ff4d4f;
            border-color: #ffa39e;
        }
        button.danger:hover { background: #fff1f0; color: #ff4d4f; }
        button:disabled { opacity: 0.5; cursor: not-allowed; }
        .node-item {
            padding: 10px 12px;
            border-radius: 4px;
            cursor: pointer;
            margin-bottom: 4px;
            border: 1px solid transparent;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .node-item:hover { background: #f5f5f5; }
        .node-item.active {
            background: #e6f4ff;
            border-color: #91caff;
        }
        .node-item .name { font-weight: 500; }
        .node-item .meta { font-size: 12px; color: #888; margin-top: 2px; }
        .badge {
            display: inline-block;
            padding: 1px 8px;
            border-radius: 10px;
            font-size: 11px;
            background: #f0f0f0;
            color: #555;
            margin-left: 6px;
        }
        .badge.assigned { background: #e6f4ff; color: #1677ff; }
        .badge.type-vless { background: #f9f0ff; color: #722ed1; }
        .badge.type-vmess { background: #e6fffb; color: #08979c; }
        .badge.type-trojan { background: #fff7e6; color: #d48806; }
        .badge.type-shadowsocks { background: #f6ffed; color: #389e0d; }
        .badge.type-tuic { background: #fff0f6; color: #c41d7f; }
        .badge.type-hysteria { background: #e6f7ff; color: #0958d9; }
        .badge.type-anytls { background: #fff2e8; color: #d4380d; }
        .badge.type-v2node { background: #f0f5ff; color: #2f54eb; }
        .empty {
            text-align: center;
            padding: 40px 20px;
            color: #999;
        }
        .right-section { margin-bottom: 24px; }
        .right-section h3 {
            margin: 0 0 8px;
            font-size: 14px;
            color: #555;
            font-weight: 500;
        }
        .user-chip {
            display: inline-flex;
            align-items: center;
            background: #e6f4ff;
            color: #1677ff;
            padding: 4px 4px 4px 10px;
            border-radius: 14px;
            margin: 0 6px 6px 0;
            font-size: 12px;
        }
        .user-chip button {
            border: none;
            background: transparent;
            color: #1677ff;
            cursor: pointer;
            padding: 0 6px;
            font-size: 14px;
            line-height: 1;
        }
        .user-chip button:hover { color: #ff4d4f; }
        .search-result {
            border: 1px solid #f0f0f0;
            border-radius: 4px;
            max-height: 220px;
            overflow: auto;
            margin-top: 8px;
        }
        .search-result .row {
            padding: 8px 12px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .search-result .row:last-child { border-bottom: none; }
        .search-result .row.assigned { background: #fafafa; color: #999; }
        .actions { display: flex; gap: 8px; margin-top: 16px; }
        .actions .hint { color: #999; font-size: 12px; flex: 1; align-self: center; }
        #toast {
            position: fixed;
            top: 80px;
            left: 50%;
            transform: translateX(-50%);
            background: #fff;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            padding: 10px 20px;
            border-radius: 4px;
            display: none;
            z-index: 999;
            min-width: 200px;
            text-align: center;
        }
        #toast.success { border-left: 3px solid #52c41a; }
        #toast.error { border-left: 3px solid #ff4d4f; color: #ff4d4f; }
    </style>
</head>
<body>
<header>
    <h1>专属节点管理</h1>
    <div>
        <a href="/{{$secure_path}}" target="_blank">返回管理后台</a>
    </div>
</header>

<div id="auth-warning" class="auth-warning" style="display:none;">
    检测不到登录凭证。请先在
    <a href="/{{$secure_path}}" target="_blank">管理后台</a>
    登录，然后刷新本页面。
</div>

<div id="main" class="layout" style="display:none;">
    <div class="panel">
        <div class="panel-header">节点列表</div>
        <div class="toolbar">
            <input type="text" id="node-search" placeholder="搜索节点名称...">
            <select id="type-filter">
                <option value="">全部类型</option>
                <option value="vless">VLESS</option>
                <option value="vmess">VMess</option>
                <option value="trojan">Trojan</option>
                <option value="shadowsocks">SS</option>
                <option value="tuic">TUIC</option>
                <option value="hysteria">Hysteria</option>
                <option value="anytls">AnyTLS</option>
                <option value="v2node">v2node</option>
            </select>
        </div>
        <div class="panel-body" id="node-list">
            <div class="empty">加载中...</div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-header" id="right-title">请从左侧选择一个节点</div>
        <div class="panel-body" id="right-body">
            <div class="empty">选择左侧节点后，可在此处分配用户。<br>分配后该节点仅对被分配的用户可见，同套餐其他用户看不到。</div>
        </div>
    </div>
</div>

<div id="toast"></div>

<script>
(function() {
    const SECURE_PATH = @json($secure_path);
    const API_BASE = '/api/v1/' + SECURE_PATH;

    const token = localStorage.getItem('authorization');
    if (!token) {
        document.getElementById('auth-warning').style.display = 'block';
        return;
    }
    document.getElementById('main').style.display = 'grid';

    const state = {
        allNodes: [],
        selected: null,
        // Local working copy of assigned users for selected node: [{id, email}]
        assignedUsers: [],
        // Last user search results: [{id, email}]
        searchResults: [],
    };

    function api(path, options = {}) {
        return fetch(API_BASE + path, {
            ...options,
            headers: {
                'Authorization': token,
                'Accept': 'application/json',
                ...(options.headers || {}),
            },
        }).then(async (r) => {
            const data = await r.json().catch(() => ({}));
            if (!r.ok) {
                throw new Error(data.message || ('HTTP ' + r.status));
            }
            return data;
        });
    }

    function toast(msg, type) {
        const el = document.getElementById('toast');
        el.textContent = msg;
        el.className = type || '';
        el.style.display = 'block';
        clearTimeout(el._t);
        el._t = setTimeout(() => { el.style.display = 'none'; }, 2400);
    }

    function escape(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[c]);
    }

    function renderNodeList() {
        const search = document.getElementById('node-search').value.toLowerCase().trim();
        const typeFilter = document.getElementById('type-filter').value;
        const list = document.getElementById('node-list');

        const filtered = state.allNodes.filter((n) => {
            if (typeFilter && n.type !== typeFilter) return false;
            if (search && !(n.name || '').toLowerCase().includes(search)) return false;
            return true;
        });

        if (filtered.length === 0) {
            list.innerHTML = '<div class="empty">无匹配节点</div>';
            return;
        }

        list.innerHTML = filtered.map((n) => {
            const count = (n.assigned_user_ids || []).length;
            const active = state.selected && state.selected.id === n.id && state.selected.type === n.type;
            return `
                <div class="node-item ${active ? 'active' : ''}" data-id="${n.id}" data-type="${escape(n.type)}">
                    <div>
                        <div class="name">${escape(n.name)}</div>
                        <div class="meta">
                            <span class="badge type-${escape(n.type)}">${escape(n.type)}</span>
                            ${count > 0 ? `<span class="badge assigned">已分配 ${count} 人</span>` : ''}
                        </div>
                    </div>
                </div>
            `;
        }).join('');

        list.querySelectorAll('.node-item').forEach((el) => {
            el.addEventListener('click', () => {
                const id = parseInt(el.dataset.id, 10);
                const type = el.dataset.type;
                selectNode(id, type);
            });
        });
    }

    function selectNode(id, type) {
        const node = state.allNodes.find((n) => n.id === id && n.type === type);
        if (!node) return;
        state.selected = node;
        state.searchResults = [];
        document.getElementById('node-search-users') && (document.getElementById('node-search-users').value = '');
        renderNodeList();
        renderRightPanel();

        api(`/server/userAssign/fetch?server_type=${encodeURIComponent(type)}&server_id=${id}`)
            .then((res) => {
                state.assignedUsers = (res.data || []).map((u) => ({ id: u.id, email: u.email }));
                renderRightPanel();
            })
            .catch((e) => toast('加载已分配用户失败: ' + e.message, 'error'));
    }

    function renderRightPanel() {
        const node = state.selected;
        if (!node) return;

        document.getElementById('right-title').innerHTML = `
            <span>${escape(node.name)}</span>
            <span class="badge type-${escape(node.type)}">${escape(node.type)}</span>
        `;

        const assignedIds = new Set(state.assignedUsers.map((u) => u.id));

        document.getElementById('right-body').innerHTML = `
            <div class="right-section">
                <h3>当前已分配用户 (${state.assignedUsers.length})</h3>
                <div id="chips">
                    ${state.assignedUsers.length === 0
                        ? '<div style="color:#999;font-size:12px;">暂未分配。注：未分配任何用户时，节点对所有同 group 用户可见（原有行为）。</div>'
                        : state.assignedUsers.map((u) => `
                            <span class="user-chip">
                                ${escape(u.email)}
                                <button data-uid="${u.id}" title="移除">×</button>
                            </span>
                        `).join('')}
                </div>
            </div>

            <div class="right-section">
                <h3>搜索并添加用户</h3>
                <input type="text" id="node-search-users" placeholder="输入邮箱关键词搜索...">
                <div id="search-result"></div>
            </div>

            <div class="actions">
                <span class="hint">改动后请点保存，否则不会生效。</span>
                <button id="btn-clear">全部清空</button>
                <button id="btn-save" class="primary">保存</button>
            </div>
        `;

        document.querySelectorAll('#chips .user-chip button').forEach((b) => {
            b.addEventListener('click', () => {
                const uid = parseInt(b.dataset.uid, 10);
                state.assignedUsers = state.assignedUsers.filter((u) => u.id !== uid);
                renderRightPanel();
            });
        });

        const searchInput = document.getElementById('node-search-users');
        let searchTimer = null;
        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimer);
            const kw = searchInput.value.trim();
            searchTimer = setTimeout(() => searchUsers(kw), 250);
        });

        document.getElementById('btn-clear').addEventListener('click', () => {
            if (state.assignedUsers.length === 0) return;
            if (!confirm('确定要移除该节点的所有专属分配吗？保存后该节点将恢复对所有同 group 用户可见。')) return;
            state.assignedUsers = [];
            renderRightPanel();
        });

        document.getElementById('btn-save').addEventListener('click', save);

        renderSearchResult();
    }

    function searchUsers(keyword) {
        if (!keyword) {
            state.searchResults = [];
            renderSearchResult();
            return;
        }
        api('/server/userAssign/searchUsers?keyword=' + encodeURIComponent(keyword))
            .then((res) => {
                state.searchResults = res.data || [];
                renderSearchResult();
            })
            .catch((e) => toast('搜索失败: ' + e.message, 'error'));
    }

    function renderSearchResult() {
        const wrap = document.getElementById('search-result');
        if (!wrap) return;
        if (state.searchResults.length === 0) {
            wrap.innerHTML = '';
            wrap.className = '';
            return;
        }
        wrap.className = 'search-result';
        const assignedIds = new Set(state.assignedUsers.map((u) => u.id));
        wrap.innerHTML = state.searchResults.map((u) => {
            const isAssigned = assignedIds.has(u.id);
            return `
                <div class="row ${isAssigned ? 'assigned' : ''}">
                    <span>${escape(u.email)}</span>
                    ${isAssigned
                        ? '<span style="font-size:12px;">已添加</span>'
                        : `<button data-uid="${u.id}" data-email="${escape(u.email)}">+ 添加</button>`}
                </div>
            `;
        }).join('');

        wrap.querySelectorAll('button[data-uid]').forEach((b) => {
            b.addEventListener('click', () => {
                const uid = parseInt(b.dataset.uid, 10);
                if (state.assignedUsers.some((u) => u.id === uid)) return;
                state.assignedUsers.push({ id: uid, email: b.dataset.email });
                renderRightPanel();
            });
        });
    }

    function save() {
        if (!state.selected) return;
        const btn = document.getElementById('btn-save');
        btn.disabled = true;
        btn.textContent = '保存中...';

        const formData = new FormData();
        formData.append('server_type', state.selected.type);
        formData.append('server_id', state.selected.id);
        state.assignedUsers.forEach((u) => formData.append('user_ids[]', u.id));

        api('/server/userAssign/save', { method: 'POST', body: formData })
            .then(() => {
                toast('保存成功', 'success');
                // Update the cached node assignment count
                state.selected.assigned_user_ids = state.assignedUsers.map((u) => u.id);
                const idx = state.allNodes.findIndex((n) => n.id === state.selected.id && n.type === state.selected.type);
                if (idx >= 0) state.allNodes[idx].assigned_user_ids = state.selected.assigned_user_ids;
                renderNodeList();
            })
            .catch((e) => toast('保存失败: ' + e.message, 'error'))
            .finally(() => {
                btn.disabled = false;
                btn.textContent = '保存';
            });
    }

    function loadNodes() {
        return api('/server/manage/getNodes').then((res) => {
            state.allNodes = res.data || [];
            renderNodeList();
        }).catch((e) => {
            document.getElementById('node-list').innerHTML =
                '<div class="empty" style="color:#ff4d4f;">加载失败: ' + escape(e.message) + '</div>';
        });
    }

    document.getElementById('node-search').addEventListener('input', renderNodeList);
    document.getElementById('type-filter').addEventListener('change', renderNodeList);

    loadNodes();
})();
</script>
</body>
</html>
