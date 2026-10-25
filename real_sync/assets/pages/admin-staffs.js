// ---- 原第 2 个内联 script 块 ----

      const roleLabels = { sales: '销售', coach: '教练', manager: '店长', ops: '运营', operation: '运营', finance: '财务', admin: '管理员', ceo: '负责人' };
      const lifecycleLabels = { active: '在职', inactive: '停用', offboarded: '离职' };
      let currentUser = null;
      let currentStaffId = 0;
      let currentPage = 1;
      let currentPageCount = 0;
      let currentTotalPages = 0;
      let storeOptions = [];
      let positionOptions = [];
      let createStep = 1;
      let createSubmitting = false;
      let createDrawerReturnFocus = null;
      let currentDetailData = null;
      let detailActionSubmitting = false;
      let purgeCheckData = null;
      let detailDrawerReturnFocus = null;
      let activeWorkspace = 'staff';
      let organizationMode = 'tree';
      let organizationData = null;
      let organizationLoading = false;
      let settingType = '';
      let settingSubmitting = false;
      let settingDrawerReturnFocus = null;
      let managerOptions = [];
      let importHeaders = [];
      let importSourceRecords = [];
      let importMapping = {};
      let importResult = null;
      let importSubmitting = false;
      let healthData = null;
      let healthLoading = false;
      const importFields = [
        { key: 'employee_no', label: '工号', aliases: ['employee_no', '工号'] },
        { key: 'name', label: '姓名', required: true, aliases: ['name', '姓名'] },
        { key: 'phone', label: '手机号', required: true, aliases: ['phone', '手机号'] },
        { key: 'store_id', label: '门店 ID', required: true, aliases: ['store_id', '门店ID'] },
        { key: 'position_id', label: '岗位 ID', required: true, aliases: ['position_id', '岗位ID'] },
        { key: 'role', label: '角色', required: true, aliases: ['role', '角色'] },
        { key: 'initial_password', label: '初始密码', required: true, aliases: ['initial_password', 'password', '初始密码'] },
        { key: 'username', label: '账号', aliases: ['username', '账号'] },
        { key: 'email', label: '邮箱', aliases: ['email', '邮箱'] },
        { key: 'entry_date', label: '入职日期', aliases: ['entry_date', '入职日期'] },
        { key: 'stage', label: '阶段', aliases: ['stage', '阶段'] }
      ];
      const healthCategories = {
        duplicate_employee_numbers: ['重复工号', '相同工号对应多个员工档案'],
        duplicate_phones: ['重复手机号', '相同手机号对应多个员工档案'],
        duplicate_accounts: ['重复账号', '同一登录账号关联多个员工档案'],
        invalid_stores: ['无效门店', '在职员工引用缺失或停用门店'],
        invalid_positions: ['无效岗位', '在职员工引用缺失或停用岗位'],
        role_mismatches: ['角色不一致', '员工角色与 WordPress 能力不一致'],
        orphan_identities: ['孤立身份', '员工缺少账号或内部账号缺少员工档案']
      };

      function escapeHtml(value) {
        return String(value == null ? '' : value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
      }

      function authFetch(url, options = {}) {
        const headers = Object.assign({}, window.authHeaders ? window.authHeaders() : {}, options.headers || {});
        return fetch(url, Object.assign({}, options, { headers, cache: 'no-store' }));
      }

      async function readJson(response, fallbackMessage) {
        const result = await response.json();
        if (!response.ok || result.code !== 0) {
          const error = new Error(result.message || fallbackMessage);
          error.status = response.status;
          error.data = result.data || {};
          throw error;
        }
        return result.data || {};
      }

      function lifecyclePill(item) {
        const lifecycle = String(item.lifecycle_status || 'inactive');
        const tone = lifecycle === 'active' ? 'ok' : lifecycle === 'offboarded' ? 'danger' : 'warn';
        return `<span class="status-pill ${tone}">${escapeHtml(lifecycleLabels[lifecycle] || lifecycle)}</span>`;
      }

      function accountPill(item) {
        if (!item.account_linked) return '<span class="status-pill">未关联</span>';
        if (item.account_enabled) return '<span class="status-pill ok">账号正常</span>';
        return '<span class="status-pill warn">账号停用</span>';
      }

      function storeSelectOptions(selectedId) {
        const fallback = selectedId && !storeOptions.some(item => String(item.id) === String(selectedId)) ? [{ id: selectedId, name: `门店 ${selectedId}` }] : [];
        return [...storeOptions, ...fallback].map(item => `<option value="${escapeHtml(item.id)}" ${String(item.id) === String(selectedId) ? 'selected' : ''}>${escapeHtml(item.name || `门店 ${item.id}`)}</option>`).join('');
      }

      function positionSelectOptions(selectedId) {
        const fallback = selectedId && !positionOptions.some(item => String(item.id) === String(selectedId)) ? [{ id: selectedId, position_name: `岗位 ${selectedId}` }] : [];
        return [...positionOptions, ...fallback].map(item => `<option value="${escapeHtml(item.id)}" ${String(item.id) === String(selectedId) ? 'selected' : ''}>${escapeHtml(item.position_name || `岗位 ${item.id}`)}</option>`).join('');
      }

      function referenceCounts(item) {
        const summary = item?.reference_summary || {};
        return {
          staff: Number(summary.current_staff_count || 0),
          current: Number(summary.current_assignment_count || 0),
          history: Number(summary.historical_assignment_count || 0)
        };
      }

      function referenceChips(item) {
        const counts = referenceCounts(item);
        return `<div class="reference-summary"><span class="reference-chip">当前员工 ${counts.staff}</span><span class="reference-chip">有效任职 ${counts.current}</span><span class="reference-chip">历史任职 ${counts.history}</span></div>`;
      }

      function statusPill(enabled) {
        return Number(enabled) === 1 ? '<span class="status-pill ok">启用</span>' : '<span class="status-pill warn">停用</span>';
      }

      function renderStoreSettings() {
        const body = document.getElementById('storeSettingsBody');
        const cards = document.getElementById('storeSettingsCards');
        if (!body || !cards) return;
        document.getElementById('storeSettingsStatus').textContent = `共 ${storeOptions.length} 个门店，按排序值升序展示`;
        if (!storeOptions.length) {
          body.innerHTML = '<tr><td colspan="7"><div class="empty" data-testid="store-settings-empty">暂无门店，请先新增门店。</div></td></tr>';
          cards.innerHTML = '<div class="empty">暂无门店，请先新增门店。</div>';
          return;
        }
        body.innerHTML = storeOptions.map(item => { const counts = referenceCounts(item); return `<tr><td><div class="staff-name">${escapeHtml(item.name || '-')}</div><div class="staff-number">${escapeHtml(item.store_code || '-')}</div></td><td>${escapeHtml(item.manager_name || '未设置')}</td><td>${counts.staff}</td><td>有效 ${counts.current} / 历史 ${counts.history}</td><td>${Number(item.sort_order || 0)}</td><td>${statusPill(item.status)}</td><td><button class="btn secondary compact" type="button" data-setting-edit="store" data-setting-id="${escapeHtml(item.id)}">编辑</button> <button class="btn secondary compact" type="button" data-setting-toggle="store" data-setting-id="${escapeHtml(item.id)}">${Number(item.status) === 1 ? '停用' : '启用'}</button></td></tr>`; }).join('');
        cards.innerHTML = storeOptions.map(item => `<article class="settings-card" data-testid="store-settings-card"><div class="settings-card-head"><div><div class="staff-name">${escapeHtml(item.name || '-')}</div><div class="staff-number">${escapeHtml(item.store_code || '-')} · 排序 ${Number(item.sort_order || 0)}</div></div>${statusPill(item.status)}</div><div class="list-item-sub">负责人：${escapeHtml(item.manager_name || '未设置')}</div>${referenceChips(item)}<div class="settings-actions"><button class="btn secondary compact" type="button" data-setting-edit="store" data-setting-id="${escapeHtml(item.id)}">编辑</button><button class="btn secondary compact" type="button" data-setting-toggle="store" data-setting-id="${escapeHtml(item.id)}">${Number(item.status) === 1 ? '停用' : '启用'}</button></div></article>`).join('');
        bindSettingRows();
      }

      function renderPositionSettings() {
        const body = document.getElementById('positionSettingsBody');
        const cards = document.getElementById('positionSettingsCards');
        if (!body || !cards) return;
        document.getElementById('positionSettingsStatus').textContent = `共 ${positionOptions.length} 个岗位，按排序值升序展示`;
        if (!positionOptions.length) {
          body.innerHTML = '<tr><td colspan="7"><div class="empty" data-testid="position-settings-empty">暂无岗位，请先新增岗位。</div></td></tr>';
          cards.innerHTML = '<div class="empty">暂无岗位，请先新增岗位。</div>';
          return;
        }
        body.innerHTML = positionOptions.map(item => { const counts = referenceCounts(item); const roles = (item.applicable_roles || []).map(role => roleLabels[role] || role).join('、'); return `<tr><td><div class="staff-name">${escapeHtml(item.position_name || '-')}</div><div class="staff-number">${escapeHtml(item.position_code || '-')}</div></td><td>${escapeHtml(roles || '-')}</td><td>${counts.staff}</td><td>有效 ${counts.current} / 历史 ${counts.history}</td><td>${Number(item.sort_order || 0)}</td><td>${statusPill(item.status)}</td><td><button class="btn secondary compact" type="button" data-setting-edit="position" data-setting-id="${escapeHtml(item.id)}">编辑</button> <button class="btn secondary compact" type="button" data-setting-toggle="position" data-setting-id="${escapeHtml(item.id)}">${Number(item.status) === 1 ? '停用' : '启用'}</button></td></tr>`; }).join('');
        cards.innerHTML = positionOptions.map(item => `<article class="settings-card" data-testid="position-settings-card"><div class="settings-card-head"><div><div class="staff-name">${escapeHtml(item.position_name || '-')}</div><div class="staff-number">${escapeHtml(item.position_code || '-')} · 排序 ${Number(item.sort_order || 0)}</div></div>${statusPill(item.status)}</div><div class="list-item-sub">适用角色：${escapeHtml((item.applicable_roles || []).map(role => roleLabels[role] || role).join('、') || '-')}</div>${referenceChips(item)}<div class="settings-actions"><button class="btn secondary compact" type="button" data-setting-edit="position" data-setting-id="${escapeHtml(item.id)}">编辑</button><button class="btn secondary compact" type="button" data-setting-toggle="position" data-setting-id="${escapeHtml(item.id)}">${Number(item.status) === 1 ? '停用' : '启用'}</button></div></article>`).join('');
        bindSettingRows();
      }

      function renderOrganizationSummary(summary = {}) {
        const values = [
          ['门店', summary.store_count],
          ['岗位', summary.position_count],
          ['当前员工', summary.staff_count],
          ['任职关系', summary.assignment_count],
          ['主任职', summary.primary_assignment_count],
          ['兼岗', summary.secondary_assignment_count]
        ];
        document.getElementById('organizationSummary').innerHTML = values.map(([label, value]) => `<div class="organization-stat"><strong>${Number(value || 0)}</strong><span>${escapeHtml(label)}</span></div>`).join('');
      }

      function renderOrganizationTree(tree) {
        const stores = Array.isArray(tree?.children) ? tree.children : [];
        if (!stores.length) return '<div class="empty" data-testid="organization-empty">当前没有启用门店。</div>';
        return `<div class="organization-tree" data-testid="organization-tree">${stores.map(store => `<details class="tree-store" open><summary><span class="tree-store-name">${escapeHtml(store.name || '未命名门店')} <span class="muted">${escapeHtml(store.code || '')}</span></span><span>${Number(store.staff_count || 0)} 人</span></summary>${(store.children || []).length ? (store.children || []).map(position => `<details class="tree-position" open><summary><span>${escapeHtml(position.name || '未命名岗位')}</span><span class="muted">${Number(position.staff_count || 0)} 人</span></summary>${(position.children || []).map(staff => `<div class="tree-staff"><span><strong>${escapeHtml(staff.name || '-')}</strong><br><span class="muted">${escapeHtml(staff.employee_no || '-')}</span></span><span>${staff.assignment_type === 'primary' ? '主岗' : '兼岗'}</span><span>${escapeHtml(roleLabels[staff.system_role] || staff.system_role || '-')} · ${escapeHtml(staff.start_date || '-')}</span></div>`).join('')}</details>`).join('') : '<div class="empty" style="margin:12px">当前没有有效任职</div>'}</details>`).join('')}</div>`;
      }

      function renderOrganizationList(list = {}) {
        const rows = Array.isArray(list.staff) ? list.staff : [];
        if (!rows.length) return '<div class="empty" data-testid="organization-list-empty">当前没有有效任职记录。</div>';
        const table = `<div class="table-wrap"><table class="table"><thead><tr><th>员工与工号</th><th>门店</th><th>岗位</th><th>任职类型</th><th>系统角色</th><th>生效区间</th></tr></thead><tbody>${rows.map(row => `<tr><td><div class="staff-name">${escapeHtml(row.name || '-')}</div><div class="staff-number">${escapeHtml(row.employee_no || '-')}</div></td><td>${escapeHtml(row.store_name || '-')}</td><td>${escapeHtml(row.position_name || '-')}</td><td>${row.assignment_type === 'primary' ? '主岗' : '兼岗'}</td><td>${escapeHtml(roleLabels[row.system_role] || row.system_role || '-')}</td><td>${escapeHtml(row.start_date || '-')} 至 ${escapeHtml(row.end_date || '至今')}</td></tr>`).join('')}</tbody></table></div>`;
        const cards = `<div class="employee-cards">${rows.map(row => `<article class="employee-card" data-testid="organization-list-card"><div class="employee-card-head"><div><div class="staff-name">${escapeHtml(row.name || '-')}</div><div class="staff-number">${escapeHtml(row.employee_no || '-')}</div></div><span class="role-pill">${row.assignment_type === 'primary' ? '主岗' : '兼岗'}</span></div><div class="employee-card-grid"><div><strong>门店</strong>${escapeHtml(row.store_name || '-')}</div><div><strong>岗位</strong>${escapeHtml(row.position_name || '-')}</div><div><strong>系统角色</strong>${escapeHtml(roleLabels[row.system_role] || row.system_role || '-')}</div><div><strong>生效日期</strong>${escapeHtml(row.start_date || '-')}</div></div></article>`).join('')}</div>`;
        return `<div data-testid="organization-list">${table}${cards}</div>`;
      }

      function renderOrganization() {
        if (!organizationData) return;
        document.getElementById('organizationBusinessDate').textContent = `业务日期 ${organizationData.business_date || '-'}，仅展示当前有效组织关系`;
        renderOrganizationSummary(organizationData.summary || {});
        const root = document.getElementById('organizationState');
        root.dataset.state = 'ready';
        root.innerHTML = organizationMode === 'tree' ? renderOrganizationTree(organizationData.tree) : renderOrganizationList(organizationData.list);
      }

      async function loadOrganizationTree(force = false) {
        if (organizationLoading || (organizationData && !force)) { renderOrganization(); return; }
        organizationLoading = true;
        const root = document.getElementById('organizationState');
        root.dataset.state = 'loading';
        root.innerHTML = '<div class="loading-state" data-testid="organization-loading">正在读取当前组织架构...</div>';
        try {
          const response = await authFetch('/api/admin/organization/tree.php');
          organizationData = await readJson(response, '组织架构加载失败');
          renderOrganization();
        } catch (error) {
          root.dataset.state = 'error';
          root.innerHTML = `<div class="error" data-testid="organization-error">${escapeHtml(error.message || '组织架构加载失败')}</div>`;
          throw error;
        } finally {
          organizationLoading = false;
        }
      }

      function parseCsv(text) {
        const rows = [];
        let row = [];
        let value = '';
        let quoted = false;
        for (let index = 0; index < text.length; index += 1) {
          const character = text[index];
          if (quoted) {
            if (character === '"' && text[index + 1] === '"') { value += '"'; index += 1; }
            else if (character === '"') quoted = false;
            else value += character;
          } else if (character === '"') quoted = true;
          else if (character === ',') { row.push(value); value = ''; }
          else if (character === '\n') { row.push(value); rows.push(row); row = []; value = ''; }
          else if (character !== '\r') value += character;
        }
        if (value !== '' || row.length) { row.push(value); rows.push(row); }
        const headers = (rows.shift() || []).map((header, index) => String(header).replace(/^\uFEFF/, '').trim() || `column_${index + 1}`);
        const records = rows.filter(values => values.some(item => String(item).trim() !== '')).map(values => Object.fromEntries(headers.map((header, index) => [header, String(values[index] ?? '').trim()])));
        return { headers, records };
      }

      function autoImportMapping(headers) {
        return Object.fromEntries(importFields.map(field => {
          const source = headers.find(header => field.aliases.some(alias => String(alias).toLowerCase() === String(header).toLowerCase()));
          return [field.key, source || ''];
        }));
      }

      function mappedImportRecords() {
        return importSourceRecords.map(source => {
          const record = {};
          for (const field of importFields) {
            const sourceKey = importMapping[field.key];
            if (!sourceKey) continue;
            const value = source[sourceKey];
            record[field.key] = typeof value === 'string' ? value.trim() : value;
          }
          return record;
        });
      }

      function precheckImportRecord(record) {
        const errors = [];
        for (const field of importFields.filter(item => item.required)) {
          if (record[field.key] == null || String(record[field.key]).trim() === '') errors.push(`${field.label}为空`);
        }
        if (record.phone && !/^1[3-9]\d{9}$/.test(String(record.phone))) errors.push('手机号格式无效');
        if (record.store_id && !/^\d+$/.test(String(record.store_id))) errors.push('门店 ID 需为整数');
        if (record.position_id && !/^\d+$/.test(String(record.position_id))) errors.push('岗位 ID 需为整数');
        if (record.role && !Object.prototype.hasOwnProperty.call(roleLabels, String(record.role).toLowerCase())) errors.push('角色编码无效');
        const password = String(record.initial_password || '');
              if (password && (password.length < 10 || !/[A-Z]/.test(password) || !/[a-z]/.test(password) || !/\d/.test(password))) errors.push('初始密码强度不足');
        return errors;
      }

      function renderImportMapping() {
        const root = document.getElementById('importMapping');
        if (!importHeaders.length) {
          root.innerHTML = '<div class="empty" data-testid="import-empty">选择文件后显示字段映射和逐行预检查。</div>';
          return;
        }
        const records = mappedImportRecords();
        const checks = records.map(precheckImportRecord);
        const valid = checks.filter(errors => errors.length === 0).length;
        const options = field => '<option value="">不导入</option>' + importHeaders.map(header => `<option value="${escapeHtml(header)}" ${importMapping[field.key] === header ? 'selected' : ''}>${escapeHtml(header)}</option>`).join('');
        const mapping = `<div class="mapping-grid" data-testid="import-field-mapping">${importFields.map(field => `<div class="field"><label for="importMap_${field.key}">${escapeHtml(field.label)}${field.required ? ' *' : ''}</label><select id="importMap_${field.key}" data-import-map="${field.key}">${options(field)}</select></div>`).join('')}</div>`;
        const summary = `<div class="precheck-summary" data-testid="import-precheck"><div class="precheck-stat"><strong>${records.length}</strong><span>文件总行数</span></div><div class="precheck-stat"><strong>${valid}</strong><span>预检查通过</span></div><div class="precheck-stat"><strong>${records.length - valid}</strong><span>需要修正</span></div></div>`;
        const table = `<div class="table-wrap"><table class="table"><thead><tr><th>行号</th><th>员工</th><th>预检查</th><th>问题</th></tr></thead><tbody>${records.map((record, index) => `<tr><td>${index + 1}</td><td>${escapeHtml(record.name || '-')}<div class="staff-number">${escapeHtml(record.employee_no || '工号自动生成')}</div></td><td>${checks[index].length ? '<span class="status-pill danger">需修正</span>' : '<span class="status-pill ok">通过</span>'}</td><td>${escapeHtml(checks[index].join('；') || '字段格式通过，等待服务端校验')}</td></tr>`).join('')}</tbody></table></div>`;
        root.innerHTML = mapping + summary + table;
        root.querySelectorAll('[data-import-map]').forEach(select => { select.onchange = () => { importMapping[select.dataset.importMap] = select.value; renderImportMapping(); }; });
        const ready = records.length > 0 && records.length <= 1000 && checks.every(errors => errors.length === 0) && !importSubmitting;
        document.getElementById('submitImportBtn').disabled = !ready;
        document.getElementById('retryImportBtn').disabled = !ready;
      }

      async function selectImportFile(file) {
        if (!file) return;
        const extension = String(file.name || '').split('.').pop().toLowerCase();
        if (!['csv', 'json'].includes(extension)) throw new Error('请选择 CSV 或 JSON 文件');
        const text = await file.text();
        let parsed;
        if (extension === 'json') {
          const value = JSON.parse(text);
          const records = Array.isArray(value) ? value : value?.records;
          if (!Array.isArray(records)) throw new Error('JSON 文件需为记录数组或包含 records 数组');
          const headers = [...new Set(records.flatMap(record => record && typeof record === 'object' && !Array.isArray(record) ? Object.keys(record) : []))];
          parsed = { headers, records: records.filter(record => record && typeof record === 'object' && !Array.isArray(record)) };
        } else parsed = parseCsv(text);
        importHeaders = parsed.headers;
        importSourceRecords = parsed.records;
        importMapping = autoImportMapping(importHeaders);
        importResult = null;
        document.getElementById('importResults').innerHTML = '';
        document.getElementById('importResults').dataset.state = 'idle';
        document.getElementById('retryImportBtn').hidden = true;
        document.getElementById('importFileMeta').textContent = `${file.name} · ${importSourceRecords.length} 行 · ${importHeaders.length} 个字段`;
        document.getElementById('importStatus').textContent = importSourceRecords.length > 1000 ? '单批最多 1000 行，请拆分文件后重试。' : '';
        renderImportMapping();
      }

      function clearImport() {
        importHeaders = [];
        importSourceRecords = [];
        importMapping = {};
        importResult = null;
        document.getElementById('importFile').value = '';
        document.getElementById('importFileMeta').textContent = '尚未选择文件';
        document.getElementById('importStatus').textContent = '';
        document.getElementById('importResults').innerHTML = '';
        document.getElementById('importResults').dataset.state = 'idle';
        document.getElementById('submitImportBtn').disabled = true;
        document.getElementById('retryImportBtn').hidden = true;
        renderImportMapping();
      }

      function setImportSubmitting(submitting) {
        importSubmitting = submitting;
        for (const id of ['importFile', 'clearImportBtn', 'submitImportBtn', 'retryImportBtn']) document.getElementById(id).disabled = submitting;
        document.getElementById('submitImportBtn').textContent = submitting ? '正在导入...' : '提交导入';
        if (!submitting) renderImportMapping();
      }

      function renderImportResult(data) {
        const root = document.getElementById('importResults');
        const rows = Array.isArray(data.rows) ? data.rows : [];
        const tone = data.status === 'completed' ? 'ok' : data.status === 'partial_failed' ? 'warn' : 'danger';
        root.dataset.state = data.status || 'ready';
        const summary = `<div class="precheck-summary" data-testid="import-result-summary"><div class="precheck-stat"><strong>${Number(data.total || 0)}</strong><span>总行数</span></div><div class="precheck-stat"><strong>${Number(data.succeeded || 0)}</strong><span>成功</span></div><div class="precheck-stat"><strong>${Number(data.failed || 0)}</strong><span>失败</span></div></div><div class="list-status"><span class="status-pill ${tone}">${escapeHtml(data.status || 'unknown')}</span> 批次 ${escapeHtml(data.batch_key || '-')}</div>`;
        const rowMarkup = row => { const validation = row.validation || {}; const summaryData = row.summary || {}; return `<tr><td>${Number(row.line || 0)}</td><td>${escapeHtml(summaryData.name || '-')}<div class="staff-number">${escapeHtml(summaryData.employee_no || validation.employee_no || '工号自动生成')}</div></td><td>${row.status === 'succeeded' ? '<span class="status-pill ok">成功</span>' : '<span class="status-pill danger">失败</span>'}</td><td>${Number(row.retry_count || 0)}</td><td>${escapeHtml(validation.message || (row.status === 'succeeded' ? '员工已创建' : '员工创建失败'))}</td></tr>`; };
        const table = `<div class="table-wrap"><table class="table"><thead><tr><th>行号</th><th>员工</th><th>结果</th><th>重试次数</th><th>说明</th></tr></thead><tbody>${rows.map(rowMarkup).join('')}</tbody></table></div>`;
        const cards = `<div class="import-result-cards">${rows.map(row => { const validation = row.validation || {}; const summaryData = row.summary || {}; return `<article class="import-result-card" data-testid="import-result-row"><div class="employee-card-head"><div><div class="staff-name">第 ${Number(row.line || 0)} 行 · ${escapeHtml(summaryData.name || '-')}</div><div class="staff-number">${escapeHtml(summaryData.employee_no || validation.employee_no || '工号自动生成')} · 重试 ${Number(row.retry_count || 0)} 次</div></div>${row.status === 'succeeded' ? '<span class="status-pill ok">成功</span>' : '<span class="status-pill danger">失败</span>'}</div><div class="list-item-sub">${escapeHtml(validation.message || (row.status === 'succeeded' ? '员工已创建' : '员工创建失败'))}</div></article>`; }).join('')}</div>`;
        root.innerHTML = summary + table + cards;
        document.getElementById('retryImportBtn').hidden = !data.retryable_batch_key;
      }

      async function submitImport(retry = false) {
        if (importSubmitting) return;
        const records = mappedImportRecords();
        const checks = records.map(precheckImportRecord);
        if (!records.length || records.length > 1000 || checks.some(errors => errors.length)) throw new Error('请先完成文件预检查并修正全部格式问题');
        const batchKey = retry ? importResult?.retryable_batch_key : '';
        if (retry && !batchKey) throw new Error('当前结果没有可重试批次');
        setImportSubmitting(true);
        document.getElementById('importStatus').textContent = retry ? '正在按原批次重试失败行...' : '正在逐行创建员工档案、账号和主任职...';
        try {
          const response = await authFetch('/api/admin/staff-import.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ records, ...(batchKey ? { batch_key: batchKey } : {}) }) });
          importResult = await readJson(response, '员工批量导入失败');
          renderImportResult(importResult);
          document.getElementById('importStatus').textContent = `批次处理完成：成功 ${Number(importResult.succeeded || 0)} 行，失败 ${Number(importResult.failed || 0)} 行。`;
          await Promise.allSettled([loadList({ resetPage: true }), loadSummary(), loadDataHealth(true)]);
        } catch (error) {
          document.getElementById('importStatus').textContent = error.message || '员工批量导入失败';
          throw error;
        } finally {
          setImportSubmitting(false);
        }
      }

      function downloadImportTemplate() {
        const content = '\uFEFF工号,姓名,手机号,门店ID,岗位ID,角色,初始密码,账号,邮箱,入职日期,阶段\n';
        const url = URL.createObjectURL(new Blob([content], { type: 'text/csv;charset=utf-8' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = 'staff-import-template.csv';
        link.click();
        URL.revokeObjectURL(url);
      }

      function healthIssueDescription(issue) {
        const identity = issue.employee_no || issue.username || issue.identifier || `员工 ${issue.staff_id || (issue.staff_ids || []).join('、') || '-'}`;
        const parts = [identity];
        if (issue.name) parts.push(issue.name);
        if (issue.reference_id) parts.push(`引用 ${issue.reference_id}`);
        if (issue.reason) parts.push(issue.reason === 'inactive' ? '引用已停用' : '引用缺失');
        if (issue.count) parts.push(`影响 ${issue.count} 条`);
        if (issue.staff_role) parts.push(`员工角色 ${issue.staff_role}`);
        return parts.filter(Boolean).join(' · ');
      }

      function healthIssueStaffId(issue) {
        return Number(issue.staff_id || (Array.isArray(issue.staff_ids) ? issue.staff_ids[0] : 0) || 0);
      }

      function bindHealthActions() {
        document.querySelectorAll('[data-health-staff]').forEach(button => { button.onclick = () => { setWorkspace('staff'); loadDetail(button.dataset.healthStaff).catch(showActionError); }; });
        document.querySelectorAll('[data-health-context]').forEach(button => { button.onclick = () => { const keyword = button.dataset.healthContext || ''; setWorkspace('staff'); document.getElementById('filterKeyword').value = keyword; loadList({ resetPage: true }).catch(() => {}); }; });
      }

      function renderDataHealth(data) {
        const root = document.getElementById('healthState');
        const counts = data.counts || {};
        const issues = data.issues || {};
        const total = Number(data.total_issues || 0);
        root.dataset.state = total === 0 ? 'healthy' : 'issues';
        const overview = `<div class="health-summary" data-testid="health-summary"><div class="health-overview ${total ? 'has-issues' : ''}"><strong>${total}</strong><span>${total ? '项问题对象等待处理' : '当前检查范围健康'}<br>检查时间 ${escapeHtml(formatDateTime(data.checked_at))}</span></div><div class="health-counts">${Object.entries(healthCategories).map(([key, meta]) => `<div class="health-count"><strong>${Number(counts[key] || 0)}</strong><span>${escapeHtml(meta[0])}</span></div>`).join('')}</div></div>`;
        const categories = `<div class="health-categories">${Object.entries(healthCategories).map(([key, meta]) => { const list = Array.isArray(issues[key]) ? issues[key] : []; return `<section class="health-category" data-health-category="${key}"><div class="health-category-head"><div><h4>${escapeHtml(meta[0])}</h4><p>${escapeHtml(meta[1])}</p></div><span class="status-pill ${list.length ? 'warn' : 'ok'}">${list.length}</span></div><div class="health-issues">${list.length ? list.map(issue => { const staffId = healthIssueStaffId(issue); const context = issue.identifier || issue.username || ''; const action = staffId ? `<button class="btn secondary compact" type="button" data-health-staff="${staffId}">查看影响员工</button>` : `<button class="btn secondary compact" type="button" data-health-context="${escapeHtml(context)}">进入修复上下文</button>`; return `<article class="health-issue" data-testid="health-issue"><div class="health-issue-head"><div><strong>${escapeHtml(issue.type || meta[0])}</strong><div class="list-item-sub">${escapeHtml(healthIssueDescription(issue))}</div></div>${action}</div><div class="health-audit">审计结果：等待对应管理流程完成修复；完成后点击重新检查确认关闭。</div></article>`; }).join('') : '<div class="health-audit">复检通过，当前分类没有待办。</div>'}</div></section>`; }).join('')}</div>`;
        root.innerHTML = overview + categories;
        bindHealthActions();
      }

      async function loadDataHealth(force = false) {
        if (healthLoading || (healthData && !force)) { if (healthData) renderDataHealth(healthData); return; }
        healthLoading = true;
        const root = document.getElementById('healthState');
        root.dataset.state = 'loading';
        root.innerHTML = '<div class="loading-state" data-testid="health-loading">正在执行员工数据健康检查...</div>';
        try {
          const response = await authFetch('/api/admin/staff/data-health.php');
          healthData = await readJson(response, '员工数据健康检查失败');
          renderDataHealth(healthData);
        } catch (error) {
          root.dataset.state = 'error';
          root.innerHTML = `<div class="error" data-testid="health-error">${escapeHtml(error.message || '员工数据健康检查失败')}，请重新检查。</div>`;
          throw error;
        } finally {
          healthLoading = false;
        }
      }

      function setWorkspace(name) {
        activeWorkspace = name;
        document.querySelectorAll('[data-workspace-panel]').forEach(panel => { panel.hidden = panel.dataset.workspacePanel !== name; });
        document.querySelectorAll('[data-workspace-tab]').forEach(tab => {
          const current = tab.dataset.workspaceTab === name;
          tab.classList.toggle('current', current);
          if (current) tab.setAttribute('aria-current', 'page'); else tab.removeAttribute('aria-current');
        });
        const titles = { staff: '员工目录与组织状态一屏掌握', organization: '总部到员工的当前组织关系', stores: '门店字典与负责人维护', positions: '业务岗位与适用角色维护', import: '批量入职的预检查与逐行结果', health: '员工身份与组织数据实时体检' };
        document.getElementById('heroTitle').textContent = titles[name] || titles.staff;
        if (name === 'organization') loadOrganizationTree().catch(() => {});
        if (name === 'stores') renderStoreSettings();
        if (name === 'positions') renderPositionSettings();
        if (name === 'health') loadDataHealth().catch(() => {});
      }

      function renderRows(list) {
        const tableBody = document.getElementById('tableBody');
        const cards = document.getElementById('employeeCards');
        if (!Array.isArray(list) || list.length === 0) {
          tableBody.innerHTML = '<tr><td colspan="7"><div class="empty" data-testid="staff-empty">当前筛选下没有员工档案，请调整条件后重试。</div></td></tr>';
          cards.innerHTML = '<div class="empty" data-testid="staff-card-empty">当前筛选下没有员工档案，请调整条件后重试。</div>';
          return;
        }
        tableBody.innerHTML = list.map(item => `<tr><td><div class="staff-name">${escapeHtml(item.name || '-')}</div><div class="staff-number">${escapeHtml(item.employee_no || '未分配工号')}</div></td><td>${escapeHtml(item.store_name || '未分配')}</td><td>${escapeHtml(item.primary_position_name || item.job_title || '未分配')}</td><td><span class="role-pill">${escapeHtml(item.role_name || roleLabels[item.role] || item.role || '-')}</span></td><td>${escapeHtml(item.phone || '-')}</td><td>${accountPill(item)}<div style="margin-top:6px">${lifecyclePill(item)}</div></td><td><button class="btn secondary compact" type="button" data-staff="${escapeHtml(item.id)}">查看详情</button></td></tr>`).join('');
        cards.innerHTML = list.map(item => `<article class="employee-card" data-testid="staff-card"><div class="employee-card-head"><div><div class="staff-name">${escapeHtml(item.name || '-')}</div><div class="staff-number">${escapeHtml(item.employee_no || '未分配工号')}</div></div>${lifecyclePill(item)}</div><div class="employee-card-grid"><div><strong>门店</strong>${escapeHtml(item.store_name || '未分配')}</div><div><strong>主岗位</strong>${escapeHtml(item.primary_position_name || item.job_title || '未分配')}</div><div><strong>系统角色</strong><span class="role-pill">${escapeHtml(item.role_name || roleLabels[item.role] || item.role || '-')}</span></div><div><strong>手机号</strong>${escapeHtml(item.phone || '-')}</div><div><strong>账号状态</strong>${accountPill(item)}</div></div><button class="btn secondary employee-card-action" type="button" data-staff="${escapeHtml(item.id)}">查看详情</button></article>`).join('');
        document.querySelectorAll('[data-staff]').forEach(button => { button.onclick = () => loadDetail(button.dataset.staff); });
      }

      function renderDetail(data) {
        const item = data.item || {};
        const devices = Array.isArray(data.devices) ? data.devices : [];
        const audits = Array.isArray(data.recent_login_audits) ? data.recent_login_audits : [];
        const assignments = Array.isArray(data.current_assignments) ? data.current_assignments : [];
        document.getElementById('detailRoot').innerHTML = `<div class="detail-title"><div><h3>${escapeHtml(item.name || '员工详情')}</h3><div class="staff-number">${escapeHtml(item.employee_no || '-')} · ${escapeHtml(item.store_name || '未分配门店')}</div></div>${lifecyclePill(item)}</div><div class="info"><strong>员工资料编辑</strong><div class="edit-form"><div class="field"><label for="editName">姓名</label><input id="editName" value="${escapeHtml(item.name || '')}" /></div><div class="field"><label for="editPhone">手机号</label><input id="editPhone" value="${escapeHtml(item.phone || '')}" /></div><div class="field"><label for="editRole">系统角色</label><select id="editRole">${Object.entries(roleLabels).filter(([code]) => code !== 'ops').map(([code, label]) => `<option value="${code}" ${item.role === code ? 'selected' : ''}>${label}</option>`).join('')}</select></div><div class="field"><label for="editStatus">账号状态</label><select id="editStatus"><option value="1" ${String(item.status) === '1' ? 'selected' : ''}>启用</option><option value="0" ${String(item.status) !== '1' ? 'selected' : ''}>停用</option></select></div><div class="field full"><label for="editStoreId">门店</label><select id="editStoreId"><option value="0">未分配</option>${storeSelectOptions(item.store_id)}</select></div><div class="field full"><label for="editPositionId">主岗位</label><select id="editPositionId"><option value="0">未分配</option>${positionSelectOptions(item.primary_position_id)}</select></div></div></div><div class="detail-actions"><button id="saveProfileBtn" type="button">保存资料</button><button id="refreshDetailBtn" type="button">刷新详情</button><button id="resetPwdBtn" type="button">重置密码</button><button id="unbindBtn" type="button">解绑微信</button><button id="unlockBtn" type="button">解锁账号</button></div><div class="info"><strong>账号与组织</strong><div>${accountPill(item)} · ${escapeHtml(item.role_name || roleLabels[item.role] || item.role || '-')}</div><div class="list-item-sub">主岗位：${escapeHtml(item.primary_position_name || item.job_title || '未分配')}，当前任职 ${assignments.length} 条</div></div><div class="info"><strong>设备概况</strong><div>设备 ${escapeHtml(data.device_stats?.total_devices || 0)} 台，信任 ${escapeHtml(data.device_stats?.trusted_devices || 0)} 台</div><div class="list-item-sub">最近登录：${escapeHtml(data.device_stats?.recent_login || '未记录')}</div></div><div><strong>设备列表</strong><div class="list">${devices.length ? devices.map(device => `<div class="list-item"><div class="list-item-title">${escapeHtml(device.device_name || device.device_model || '未知设备')}</div><div class="list-item-sub">${escapeHtml(device.os_version || '-')} · 最近 ${escapeHtml(device.last_login || '-')}</div></div>`).join('') : '<div class="empty">暂无设备记录</div>'}</div></div><div><strong>最近登录审计</strong><div class="list">${audits.length ? audits.map(audit => `<div class="list-item"><div class="list-item-title">${escapeHtml(audit.login_status || '-')} · ${escapeHtml(audit.login_type || '-')}</div><div class="list-item-sub">${escapeHtml(audit.created_at || '-')} · ${escapeHtml(audit.ip_address || '-')}</div></div>`).join('') : '<div class="empty">暂无登录审计</div>'}</div></div>`;
        enhanceDetail(data);
        document.getElementById('saveProfileBtn').onclick = () => saveProfile().catch(showActionError);
        document.getElementById('refreshDetailBtn').onclick = () => loadDetail(currentStaffId).catch(showActionError);
        document.getElementById('resetPwdBtn').onclick = () => resetPassword().catch(showActionError);
        document.getElementById('unbindBtn').onclick = () => unbindWechat().catch(showActionError);
        document.getElementById('unlockBtn').onclick = () => unlockAccount().catch(showActionError);
      }

      function formatDateTime(value) {
        return value ? String(value).replace('T', ' ').slice(0, 19) : '-';
      }

      function assignmentTimeline(history) {
        if (!history.length) return '<div class="empty">暂无历史任职记录</div>';
        return `<div class="timeline">${history.map(row => `<div class="timeline-item"><div class="timeline-title">${escapeHtml(row.store_name || '未分配门店')} · ${escapeHtml(row.position_name || '未分配岗位')}</div><div class="timeline-meta">${row.assignment_type === 'primary' ? '主岗' : '兼岗'} · ${escapeHtml(row.start_date || '-')} 至 ${escapeHtml(row.end_date || '至今')} · ${escapeHtml(roleLabels[row.system_role] || row.system_role || '-')}</div>${row.change_reason ? `<div class="timeline-meta">原因：${escapeHtml(row.change_reason)}</div>` : ''}</div>`).join('')}</div>`;
      }

      function auditTimeline(data) {
        const operationLabels = { create: '创建员工', update: '更新员工资料', offboard: '离职归档', restore: '恢复员工', reset_password: '重置密码', unbind_wechat: '解绑微信', unlock_account: '解锁账号', purge_miscreated: '受控清理' };
        const operations = (Array.isArray(data.operation_audits) ? data.operation_audits : []).map(row => ({ title: operationLabels[row.action] || row.action || '员工操作', meta: `操作人：${row.operator_name || `员工 ${row.operator_staff_id || '-'}`}`, created_at: row.created_at, tone: 'operation' }));
        const logins = (Array.isArray(data.recent_login_audits) ? data.recent_login_audits : []).map(row => ({ title: `${row.login_type || '登录'} · ${row.login_status || '-'}`, meta: `${row.source || '未知来源'}${row.risk_level ? ` · 风险 ${row.risk_level}` : ''}`, created_at: row.created_at, tone: 'login' }));
        const events = [...operations, ...logins].sort((a, b) => String(b.created_at || '').localeCompare(String(a.created_at || ''))).slice(0, 20);
        if (!events.length) return '<div class="empty">暂无审计记录</div>';
        return `<div class="timeline">${events.map(event => `<div class="timeline-item" data-audit-type="${event.tone}"><div class="timeline-title">${escapeHtml(event.title)}</div><div class="timeline-meta">${escapeHtml(event.meta)} · ${escapeHtml(formatDateTime(event.created_at))}</div></div>`).join('')}</div>`;
      }

      function enhanceDetail(data) {
        currentDetailData = data;
        purgeCheckData = null;
        const item = data.item || {};
        const account = data.account_status || {};
        const history = Array.isArray(data.assignment_history) ? data.assignment_history : [];
        const business = data.business_summary || {};
        const actions = Array.isArray(data.available_actions) ? data.available_actions : [];
        const readonly = item.lifecycle_status === 'offboarded';
        const editForm = document.querySelector('#detailRoot .edit-form');
        editForm?.insertAdjacentHTML('beforeend', `<div class="field"><label for="editEffectiveDate">生效日期</label><input id="editEffectiveDate" type="date" value="${localDateValue()}" /></div><div class="field"><label for="editReason">变更原因</label><input id="editReason" maxlength="500" placeholder="说明本次修改原因" /></div>`);
        if (readonly) {
          document.querySelectorAll('#detailRoot .edit-form input, #detailRoot .edit-form select').forEach(field => { field.disabled = true; });
          document.getElementById('saveProfileBtn').disabled = true;
          document.getElementById('saveProfileBtn').textContent = '离职档案只读';
        }
        document.getElementById('resetPwdBtn').hidden = true;
        document.getElementById('detailRoot').insertAdjacentHTML('beforeend', `
          <section class="info" data-testid="account-binding-summary"><div class="detail-section-title"><strong>账号与绑定状态</strong>${account.enabled ? '<span class="status-pill ok">可登录</span>' : '<span class="status-pill warn">已停用</span>'}</div><div class="list-item-sub">登录账号：${escapeHtml(account.username || item.username || '-')}</div><div class="list-item-sub">邮箱：${escapeHtml(account.email || item.email || '-')}</div><div class="list-item-sub">微信：${account.wechat_bound ? '已绑定' : '未绑定'} · 企业微信：能力已停用</div></section>
          <section class="info" data-testid="assignment-history"><div class="detail-section-title"><strong>任职历史</strong><span class="muted">${history.length} 条</span></div>${assignmentTimeline(history)}</section>
          <section class="info" data-testid="business-summary"><div class="detail-section-title"><strong>业务摘要</strong><span class="muted">历史关联只读</span></div><div class="summary-mini-grid"><div class="summary-mini"><strong>${Number(business.workload_reports || 0)}</strong><span>工作量日报</span></div><div class="summary-mini"><strong>${Number(business.completed_exams || 0)}</strong><span>完成考试</span></div><div class="summary-mini"><strong>${Number(business.completed_passes || 0)}</strong><span>完成通关</span></div><div class="summary-mini"><strong>${Number(business.courses || 0) + Number(business.drills || 0)}</strong><span>课程与演练</span></div></div></section>
          <section class="info" data-testid="audit-timeline"><div class="detail-section-title"><strong>操作与登录审计</strong><span class="muted">最近 20 条</span></div>${auditTimeline(data)}</section>
          ${renderRiskZone(item, actions)}
        `);
        bindRiskActions();
      }

      function renderRiskZone(item, actions) {
        const canReset = actions.includes('reset_password');
        const canOffboard = actions.includes('offboard');
        const canRestore = actions.includes('restore');
        const canPurgeCheck = item.lifecycle_status !== 'offboarded';
        return `<section class="risk-zone" data-testid="staff-risk-zone"><h4>高风险操作</h4><p>以下操作会改变账号、任职或历史状态。每项操作独立确认并写入审计记录。</p><div class="action-status" id="detailActionStatus" role="status" aria-live="polite"></div>
          ${canReset ? `<details class="risk-action"><summary>重置登录密码</summary><form class="risk-form" id="resetPasswordForm"><div class="field"><label for="riskNewPassword">新密码</label><input id="riskNewPassword" type="password" autocomplete="new-password" /></div><div class="field"><label for="riskConfirmPassword">确认新密码</label><input id="riskConfirmPassword" type="password" autocomplete="new-password" /></div><button class="risk-button secondary-risk" type="submit">重置密码并撤销旧会话</button></form></details>` : ''}
          ${canOffboard ? `<details class="risk-action"><summary>离职归档</summary><form class="risk-form" id="offboardForm"><div class="field"><label for="offboardDate">离职日期</label><input id="offboardDate" type="date" max="${localDateValue()}" value="${localDateValue()}" /></div><div class="field"><label for="offboardReason">离职原因</label><textarea id="offboardReason" maxlength="500" rows="3"></textarea></div><label class="risk-confirm"><input id="offboardConfirmed" type="checkbox" />我确认停用账号、关闭当前任职并撤销现有会话</label><button class="risk-button" type="submit">确认离职归档</button></form></details>` : ''}
          ${canRestore ? `<details class="risk-action" open><summary>恢复员工</summary><form class="risk-form" id="restoreForm"><div class="risk-form-row"><div class="field"><label for="restoreDate">恢复日期</label><input id="restoreDate" type="date" max="${localDateValue()}" value="${localDateValue()}" /></div><div class="field"><label for="restoreRole">系统角色</label><select id="restoreRole">${Object.entries(roleLabels).filter(([code]) => code !== 'ops').map(([code, label]) => `<option value="${code}" ${item.role === code ? 'selected' : ''}>${label}</option>`).join('')}</select></div></div><div class="risk-form-row"><div class="field"><label for="restoreStoreId">有效门店</label><select id="restoreStoreId"><option value="">请选择</option>${storeOptions.filter(row => Number(row.status) !== 0).map(row => `<option value="${escapeHtml(row.id)}">${escapeHtml(row.name || `门店 ${row.id}`)}</option>`).join('')}</select></div><div class="field"><label for="restorePositionId">有效主岗位</label><select id="restorePositionId"><option value="">请选择</option>${positionOptions.filter(row => Number(row.status) !== 0).map(row => `<option value="${escapeHtml(row.id)}">${escapeHtml(row.position_name || `岗位 ${row.id}`)}</option>`).join('')}</select></div></div><div class="field"><label for="restoreReason">恢复原因</label><textarea id="restoreReason" maxlength="500" rows="3"></textarea></div><label class="risk-confirm"><input id="restoreAccountConfirmed" type="checkbox" />我已确认账号恢复为启用状态，兼岗清单确认为空</label><button class="risk-button" type="submit">确认恢复员工</button></form></details>` : ''}
          ${canPurgeCheck ? `<details class="risk-action"><summary>误建清理资格检查</summary><div class="risk-form"><p class="field-note">系统会检查登录、设备、工作量、学习、审核、通知和消息等业务关联。</p><button class="risk-button secondary-risk" id="purgeCheckBtn" type="button">检查业务关联</button><div id="purgeCheckResult" class="purge-results" data-testid="purge-check-result"></div><form class="risk-form" id="purgeForm" hidden><div class="field"><label for="purgeReason">清理原因</label><textarea id="purgeReason" maxlength="500" rows="3"></textarea></div><label class="risk-confirm"><input id="purgeConfirmed" type="checkbox" />我确认使用短时令牌清理无业务关联的误建身份链</label><button class="risk-button" type="submit">执行受控清理</button></form></div></details>` : ''}
        </section>`;
      }

      function bindRiskActions() {
        document.getElementById('resetPasswordForm')?.addEventListener('submit', event => resetPassword(event).catch(showDetailActionError));
        document.getElementById('offboardForm')?.addEventListener('submit', event => offboardStaff(event).catch(showDetailActionError));
        document.getElementById('restoreForm')?.addEventListener('submit', event => restoreStaff(event).catch(showDetailActionError));
        document.getElementById('purgeCheckBtn')?.addEventListener('click', () => inspectPurge().catch(showDetailActionError));
        document.getElementById('purgeForm')?.addEventListener('submit', event => purgeStaff(event).catch(showDetailActionError));
      }

      function collectFilters() {
        return {
          keyword: document.getElementById('filterKeyword').value.trim(),
          store_id: document.getElementById('filterStore').value,
          position_id: document.getElementById('filterPosition').value,
          role: document.getElementById('filterRole').value,
          lifecycle_status: document.getElementById('filterLifecycle').value,
          page_size: document.getElementById('filterSize').value
        };
      }

      function buildListQuery(page = currentPage) {
        const filters = collectFilters();
        const query = new URLSearchParams({ page: String(page), page_size: filters.page_size });
        for (const [key, value] of Object.entries(filters)) {
          if (key !== 'page_size' && value) query.set(key, value);
        }
        if (!filters.lifecycle_status) query.set('include_offboarded', '1');
        return query;
      }

      function renderAppliedFilters() {
        const filters = collectFilters();
        const labels = [];
        if (filters.keyword) labels.push(`关键词：${filters.keyword}`);
        if (filters.store_id) labels.push(`门店：${document.getElementById('filterStore').selectedOptions[0]?.textContent || filters.store_id}`);
        if (filters.position_id) labels.push(`岗位：${document.getElementById('filterPosition').selectedOptions[0]?.textContent || filters.position_id}`);
        if (filters.role) labels.push(`角色：${roleLabels[filters.role] || filters.role}`);
        if (filters.lifecycle_status) labels.push(`状态：${lifecycleLabels[filters.lifecycle_status] || filters.lifecycle_status}`);
        document.getElementById('appliedFilters').innerHTML = `<span class="applied-label">已生效条件</span>${(labels.length ? labels : ['全部员工']).map(label => `<span class="filter-chip">${escapeHtml(label)}</span>`).join('')}`;
      }

      function setListLoading() {
        document.getElementById('listState').dataset.state = 'loading';
        document.getElementById('listStatus').textContent = '正在加载员工数据';
        document.getElementById('tableBody').innerHTML = '<tr><td colspan="7"><div class="loading-state" data-testid="staff-loading">正在读取员工目录...</div></td></tr>';
        document.getElementById('employeeCards').innerHTML = '<div class="loading-state" data-testid="staff-card-loading">正在读取员工目录...</div>';
      }

      function setListError(error) {
        const message = error?.message || '员工列表加载失败';
        document.getElementById('listState').dataset.state = 'error';
        document.getElementById('listStatus').textContent = message;
        document.getElementById('tableBody').innerHTML = `<tr><td colspan="7"><div class="error" data-testid="staff-error">${escapeHtml(message)}，请点击刷新重试。</div></td></tr>`;
        document.getElementById('employeeCards').innerHTML = `<div class="error" data-testid="staff-card-error">${escapeHtml(message)}，请点击刷新重试。</div>`;
      }

      async function loadDictionaries() {
        const [storesResponse, positionsResponse] = await Promise.all([authFetch('/api/admin/organization/stores.php?status=all'), authFetch('/api/admin/organization/positions.php?status=all')]);
        const [storesData, positionsData] = await Promise.all([readJson(storesResponse, '门店字典加载失败'), readJson(positionsResponse, '岗位字典加载失败')]);
        storeOptions = Array.isArray(storesData.list) ? storesData.list : [];
        positionOptions = Array.isArray(positionsData.list) ? positionsData.list : [];
        document.getElementById('filterStore').innerHTML = '<option value="">全部门店</option>' + storeOptions.map(item => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.name || `门店 ${item.id}`)}${Number(item.status) === 0 ? '（已停用）' : ''}</option>`).join('');
        document.getElementById('filterPosition').innerHTML = '<option value="">全部岗位</option>' + positionOptions.map(item => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.position_name || `岗位 ${item.id}`)}${Number(item.status) === 0 ? '（已停用）' : ''}</option>`).join('');
        document.getElementById('createStoreId').innerHTML = '<option value="">请选择启用门店</option>' + storeOptions.filter(item => Number(item.status) !== 0).map(item => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.name || `门店 ${item.id}`)}</option>`).join('');
        document.getElementById('createPositionId').innerHTML = '<option value="">请选择启用岗位</option>' + positionOptions.filter(item => Number(item.status) !== 0).map(item => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.position_name || `岗位 ${item.id}`)}</option>`).join('');
        renderStoreSettings();
        renderPositionSettings();
      }

      async function loadList(options = {}) {
        if (options.resetPage) currentPage = 1;
        setListLoading();
        renderAppliedFilters();
        try {
          const response = await authFetch('/api/admin/staff/list.php?' + buildListQuery().toString());
          const data = await readJson(response, '员工列表加载失败');
          const list = Array.isArray(data.list) ? data.list : [];
          currentPage = Number(data.page || currentPage || 1);
          currentPageCount = list.length;
          currentTotalPages = Number(data.total_pages || 0);
          const total = Number(data.total || 0);
          document.getElementById('summaryResult').textContent = String(total);
          document.getElementById('listState').dataset.state = list.length ? 'ready' : 'empty';
          document.getElementById('listStatus').textContent = `共 ${total} 位员工，当前显示 ${list.length} 位`;
          document.getElementById('paginationInfo').textContent = total === 0 ? '暂无数据' : `第 ${currentPage} / ${Math.max(1, currentTotalPages)} 页，共 ${total} 条`;
          document.getElementById('prevPageBtn').disabled = currentPage <= 1;
          document.getElementById('nextPageBtn').disabled = currentTotalPages === 0 || currentPage >= currentTotalPages;
          renderRows(list);
        } catch (error) {
          currentPageCount = 0;
          currentTotalPages = 0;
          document.getElementById('summaryResult').textContent = '-';
          document.getElementById('paginationInfo').textContent = '加载失败';
          document.getElementById('prevPageBtn').disabled = true;
          document.getElementById('nextPageBtn').disabled = true;
          setListError(error);
          throw error;
        }
      }

      async function loadSummary() {
        const statuses = ['active', 'inactive', 'offboarded'];
        const requests = statuses.map(status => authFetch(`/api/admin/staff/list.php?lifecycle_status=${status}&page=1&page_size=1`).then(response => readJson(response, '状态摘要加载失败')));
        const healthRequest = authFetch('/api/admin/staff/data-health.php').then(response => readJson(response, '数据健康摘要加载失败'));
        const results = await Promise.allSettled([...requests, healthRequest]);
        statuses.forEach((status, index) => {
          const id = status === 'active' ? 'summaryActive' : status === 'inactive' ? 'summaryInactive' : 'summaryOffboarded';
          document.getElementById(id).textContent = results[index].status === 'fulfilled' ? String(Number(results[index].value.total || 0)) : '-';
        });
        const health = results[3];
        if (health.status === 'fulfilled') healthData = health.value;
        document.getElementById('summaryIssues').textContent = health.status === 'fulfilled' ? String(Number(health.value.total_issues || 0)) : '-';
      }

      function openDetailDrawer() {
        const drawer = document.getElementById('detailDrawer');
        if (drawer.hidden) detailDrawerReturnFocus = document.activeElement;
        drawer.hidden = false;
        document.body.classList.add('drawer-open');
        focusDrawer(drawer, '#closeDetailDrawerBtn');
      }

      function closeDetailDrawer() {
        if (detailActionSubmitting) return;
        document.getElementById('detailDrawer').hidden = true;
        if (document.getElementById('createDrawer').hidden) document.body.classList.remove('drawer-open');
        if (detailDrawerReturnFocus?.focus) detailDrawerReturnFocus.focus();
      }

      async function loadDetail(staffId) {
        currentStaffId = Number(staffId || 0);
        openDetailDrawer();
        const root = document.getElementById('detailRoot');
        root.innerHTML = '<div class="loading-state" data-testid="detail-loading">正在加载员工详情...</div>';
        try {
          const response = await authFetch('/api/admin/staff/detail.php?staff_id=' + encodeURIComponent(currentStaffId));
          renderDetail(await readJson(response, '员工详情加载失败'));
        } catch (error) {
          root.innerHTML = `<div class="error" data-testid="detail-error">${escapeHtml(error.message || '员工详情加载失败')}</div>`;
          throw error;
        }
      }

      async function postAction(url, body) {
        const response = await authFetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        return readJson(response, '操作失败');
      }

      async function loadManagerOptions() {
        if (managerOptions.length) return;
        const response = await authFetch('/api/admin/staff/list.php?lifecycle_status=active&page=1&page_size=100');
        const data = await readJson(response, '负责人候选加载失败');
        managerOptions = Array.isArray(data.list) ? data.list : [];
      }

      function bindSettingRows() {
        document.querySelectorAll('[data-setting-edit]').forEach(button => {
          button.onclick = () => openSettingDrawer(button.dataset.settingEdit, Number(button.dataset.settingId)).catch(showActionError);
        });
        document.querySelectorAll('[data-setting-toggle]').forEach(button => {
          button.onclick = () => toggleSetting(button.dataset.settingToggle, Number(button.dataset.settingId)).catch(showActionError);
        });
      }

      function settingCollection(type) {
        return type === 'store' ? storeOptions : positionOptions;
      }

      function settingEndpoint(type) {
        return type === 'store' ? '/api/admin/organization/stores.php' : '/api/admin/organization/positions.php';
      }

      function settingLabel(type) {
        return type === 'store' ? '门店' : '岗位';
      }

      function renderManagerOptions(selectedId) {
        document.getElementById('settingManager').innerHTML = '<option value="">暂不设置</option>' + managerOptions.map(item => `<option value="${escapeHtml(item.id)}" ${String(item.id) === String(selectedId) ? 'selected' : ''}>${escapeHtml(item.name || `员工 ${item.id}`)} · ${escapeHtml(item.employee_no || '无工号')}</option>`).join('');
      }

      function renderRoleCheckboxes(selectedRoles = []) {
        const roles = ['sales', 'coach', 'manager', 'operation', 'finance', 'admin', 'ceo'];
        document.getElementById('settingRoles').innerHTML = roles.map(role => `<label class="role-checkbox"><input type="checkbox" name="settingRole" value="${role}" ${selectedRoles.includes(role) ? 'checked' : ''} />${escapeHtml(roleLabels[role])}</label>`).join('');
      }

      function renderSettingReferences(item) {
        const target = document.getElementById('settingReferenceSummary');
        if (!item) {
          target.innerHTML = '<strong>当前引用</strong><div class="field-note">新建项目暂无引用。</div>';
          return;
        }
        const counts = referenceCounts(item);
        target.innerHTML = `<strong>当前引用</strong>${referenceChips(item)}<div class="field-note" style="margin-top:8px">停用前需先处理 ${counts.staff} 位当前员工和 ${counts.current} 条有效任职，历史任职将持续保留。</div>`;
      }

      async function openSettingDrawer(type, id = 0) {
        const item = settingCollection(type).find(row => Number(row.id) === Number(id)) || null;
        settingType = type;
        settingDrawerReturnFocus = document.activeElement;
        settingSubmitting = false;
        document.getElementById('settingForm').reset();
        document.getElementById('settingId').value = item?.id || '';
        document.getElementById('settingCode').value = type === 'store' ? item?.store_code || '' : item?.position_code || '';
        document.getElementById('settingName').value = type === 'store' ? item?.name || '' : item?.position_name || '';
        document.getElementById('settingSortOrder').value = Number(item?.sort_order || 0);
        document.getElementById('settingEnabled').value = String(Number(item?.status ?? 1));
        document.getElementById('settingManagerField').hidden = type !== 'store';
        document.getElementById('settingRolesField').hidden = type !== 'position';
        if (type === 'store') {
          await loadManagerOptions();
          renderManagerOptions(item?.manager_staff_id || '');
        } else {
          renderRoleCheckboxes(Array.isArray(item?.applicable_roles) ? item.applicable_roles : []);
        }
        renderSettingReferences(item);
        const action = item ? '编辑' : '新增';
        document.getElementById('settingDrawerTitle').textContent = `${action}${settingLabel(type)}`;
        document.getElementById('settingDrawerDesc').textContent = type === 'store' ? '维护门店编码、名称、负责人、排序和启停状态。' : '维护岗位编码、名称、适用角色、排序和启停状态。';
        document.getElementById('settingStatus').textContent = '';
        document.getElementById('settingDrawer').hidden = false;
        document.body.classList.add('drawer-open');
        focusDrawer(document.getElementById('settingDrawer'), '#settingCode');
      }

      function closeSettingDrawer() {
        if (settingSubmitting) return;
        document.getElementById('settingDrawer').hidden = true;
        if (document.getElementById('createDrawer').hidden && document.getElementById('detailDrawer').hidden) document.body.classList.remove('drawer-open');
        if (settingDrawerReturnFocus?.focus) settingDrawerReturnFocus.focus();
      }

      function setSettingSubmitting(submitting) {
        settingSubmitting = submitting;
        for (const id of ['cancelSettingBtn', 'closeSettingDrawerBtn', 'submitSettingBtn']) document.getElementById(id).disabled = submitting;
        document.getElementById('submitSettingBtn').textContent = submitting ? '正在保存...' : '保存设置';
      }

      async function saveSetting(event) {
        event.preventDefault();
        if (settingSubmitting || !settingType) return;
        const id = Number(document.getElementById('settingId').value || 0);
        const rawCode = document.getElementById('settingCode').value.trim();
        const code = settingType === 'store' ? rawCode.toUpperCase() : rawCode.toLowerCase();
        const name = document.getElementById('settingName').value.trim();
        const sortOrder = Number(document.getElementById('settingSortOrder').value || 0);
        const status = Number(document.getElementById('settingEnabled').value);
        const codeValid = settingType === 'store' ? /^[A-Z0-9_-]{2,64}$/.test(code) : /^[a-z0-9_-]{2,64}$/.test(code);
        const selectedRoles = [...document.querySelectorAll('input[name="settingRole"]:checked')].map(input => input.value);
        if (!codeValid || !name || !Number.isInteger(sortOrder) || Math.abs(sortOrder) > 1000000) throw new Error(`请完整填写有效的${settingLabel(settingType)}编码、名称和排序`);
        if (settingType === 'position' && !selectedRoles.length) throw new Error('岗位至少选择一个适用系统角色');
        const payload = { action: id ? 'update' : 'create', sort_order: sortOrder, status };
        if (id) payload.id = id;
        if (settingType === 'store') Object.assign(payload, { store_code: code, name, manager_staff_id: Number(document.getElementById('settingManager').value || 0) || null });
        else Object.assign(payload, { position_code: code, position_name: name, applicable_roles: selectedRoles });
        setSettingSubmitting(true);
        document.getElementById('settingStatus').textContent = `正在保存${settingLabel(settingType)}设置...`;
        try {
          await postAction(settingEndpoint(settingType), payload);
        } catch (error) {
          const refs = error?.data?.reference_summary;
          const message = refs ? `${error.message}。当前员工 ${Number(refs.current_staff_count || 0)}，有效任职 ${Number(refs.current_assignment_count || 0)}` : error.message;
          document.getElementById('settingStatus').textContent = message || '保存失败';
          return;
        } finally {
          setSettingSubmitting(false);
        }
        closeSettingDrawer();
        organizationData = null;
        await loadDictionaries();
        if (activeWorkspace === 'organization') await loadOrganizationTree(true);
      }

      async function toggleSetting(type, id) {
        if (settingSubmitting) return;
        const item = settingCollection(type).find(row => Number(row.id) === Number(id));
        if (!item) throw new Error(`${settingLabel(type)}不存在，请刷新后重试`);
        const nextStatus = Number(item.status) === 1 ? 0 : 1;
        const counts = referenceCounts(item);
        if (nextStatus === 0 && (counts.staff > 0 || counts.current > 0)) {
          window.AdminCommon.adminToast(`当前有 ${counts.staff} 位员工和 ${counts.current} 条有效任职引用，请先完成员工归属调整。历史任职 ${counts.history} 条将持续保留。`);
          return;
        }
        const actionText = nextStatus === 1 ? '启用' : '停用';
        if (!window.confirm(`确认${actionText}${settingLabel(type)}“${type === 'store' ? item.name : item.position_name}”吗？当前员工 ${counts.staff} 位，有效任职 ${counts.current} 条，历史任职 ${counts.history} 条。`)) return;
        settingSubmitting = true;
        try {
          await postAction(settingEndpoint(type), { action: 'set_status', id, status: nextStatus });
        } catch (error) {
          const refs = error?.data?.reference_summary;
          if (refs) window.AdminCommon.adminToast(`${error.message}。请先处理 ${Number(refs.current_staff_count || 0)} 位当前员工和 ${Number(refs.current_assignment_count || 0)} 条有效任职。`);
          else throw error;
          return;
        } finally {
          settingSubmitting = false;
        }
        organizationData = null;
        await loadDictionaries();
      }

      async function saveProfile() {
        if (!currentStaffId) return;
        const name = document.getElementById('editName').value.trim();
        const phone = document.getElementById('editPhone').value.replace(/\s+/g, '');
        const effectiveDate = document.getElementById('editEffectiveDate').value;
        const reason = document.getElementById('editReason').value.trim();
        if (!name || !/^1[3-9]\d{9}$/.test(phone) || !/^\d{4}-\d{2}-\d{2}$/.test(effectiveDate) || !reason) {
          throw new Error('请完整填写姓名、11 位手机号、生效日期和变更原因');
        }
        const payload = { staff_id: currentStaffId, name, phone, role: document.getElementById('editRole').value, status: Number(document.getElementById('editStatus').value), store_id: Number(document.getElementById('editStoreId').value || 0), position_id: Number(document.getElementById('editPositionId').value || 0), effective_date: effectiveDate, change_reason: reason };
        await postAction('/api/admin/staff/update.php', payload);
        window.AdminCommon.adminToast('员工资料已保存');
        await Promise.allSettled([loadList(), loadDetail(currentStaffId), loadSummary()]);
      }

      function setDetailActionSubmitting(submitting) {
        detailActionSubmitting = submitting;
        document.querySelectorAll('#detailRoot .risk-button').forEach(button => { button.disabled = submitting; });
      }

      function setDetailActionStatus(message, success = false) {
        const status = document.getElementById('detailActionStatus');
        if (!status) return;
        status.textContent = message;
        status.style.color = success ? 'var(--ok)' : 'var(--danger)';
      }

      function showDetailActionError(error) {
        setDetailActionSubmitting(false);
        setDetailActionStatus(error?.message || '操作失败，请稍后重试');
      }

      async function refreshAfterDetailAction(message) {
        const results = await Promise.allSettled([loadList(), loadSummary(), loadDetail(currentStaffId)]);
        if (results[2].status === 'rejected') {
          window.AdminCommon.adminToast(`${message}，详情刷新失败，请点击员工列表重新打开`);
          return;
        }
        setDetailActionStatus(message, true);
      }

      async function resetPassword(event) {
        event?.preventDefault();
        if (!currentStaffId || detailActionSubmitting) return;
        const newPassword = document.getElementById('riskNewPassword')?.value || '';
        const confirmation = document.getElementById('riskConfirmPassword')?.value || '';
        if (newPassword.length < 10 || !/[A-Z]/.test(newPassword) || !/[a-z]/.test(newPassword) || !/\d/.test(newPassword)) throw new Error('密码需至少 10 位并包含大小写字母和数字');
        if (newPassword !== confirmation) throw new Error('两次输入的密码不一致');
        setDetailActionSubmitting(true);
        try {
          await postAction('/api/admin/staff/reset-password.php', { staff_id: currentStaffId, new_password: newPassword });
          await refreshAfterDetailAction('密码已重置，员工现有会话已失效');
        } finally {
          setDetailActionSubmitting(false);
        }
      }

      async function offboardStaff(event) {
        event.preventDefault();
        if (!currentStaffId || detailActionSubmitting) return;
        const offboardDate = document.getElementById('offboardDate').value;
        const reason = document.getElementById('offboardReason').value.trim();
        const confirmed = document.getElementById('offboardConfirmed').checked;
        if (!/^\d{4}-\d{2}-\d{2}$/.test(offboardDate) || !reason || !confirmed) throw new Error('请填写离职日期和原因，并勾选操作确认');
        setDetailActionSubmitting(true);
        try {
          await postAction('/api/admin/staff/offboard.php', { staff_id: currentStaffId, offboard_date: offboardDate, offboard_reason: reason, confirmed });
          await refreshAfterDetailAction('员工已离职归档，账号与现有会话已停用');
        } finally {
          setDetailActionSubmitting(false);
        }
      }

      async function restoreStaff(event) {
        event.preventDefault();
        if (!currentStaffId || detailActionSubmitting) return;
        const restoreDate = document.getElementById('restoreDate').value;
        const storeId = Number(document.getElementById('restoreStoreId').value || 0);
        const positionId = Number(document.getElementById('restorePositionId').value || 0);
        const role = document.getElementById('restoreRole').value;
        const reason = document.getElementById('restoreReason').value.trim();
        const accountConfirmed = document.getElementById('restoreAccountConfirmed').checked;
        if (!/^\d{4}-\d{2}-\d{2}$/.test(restoreDate) || !storeId || !positionId || !role || !reason || !accountConfirmed) throw new Error('请重新确认恢复日期、有效门店、主岗位、角色、原因和账号状态');
        setDetailActionSubmitting(true);
        try {
          await postAction('/api/admin/staff/restore.php', { staff_id: currentStaffId, restore_date: restoreDate, store_id: storeId, position_id: positionId, role, restore_reason: reason, account_status: 'active', secondary_assignments: [] });
          await refreshAfterDetailAction('员工已恢复，并创建新的当前主任职');
        } finally {
          setDetailActionSubmitting(false);
        }
      }

      function renderPurgeInspection(data) {
        const labels = { identity_baseline: '身份基线', login_devices: '登录与设备', workload: '工作量', learning: '学习', review: '审核', notifications: '通知与消息', audit_actor_history: '审计操作历史', organization_references: '组织引用' };
        const categories = data.categories && typeof data.categories === 'object' ? Object.entries(data.categories) : [];
        const result = document.getElementById('purgeCheckResult');
        result.innerHTML = `${categories.map(([key, value]) => `<div class="purge-result"><span>${escapeHtml(labels[key] || key)}</span><strong>${Number(value.blocking_count || 0)} 项阻断</strong></div>`).join('')}<div class="purge-result"><span>检查结论</span><strong>${data.eligible_for_purge ? '可受控清理' : '建议离职归档'}</strong></div>`;
        document.getElementById('purgeForm').hidden = !data.eligible_for_purge;
      }

      async function inspectPurge() {
        if (!currentStaffId || detailActionSubmitting) return;
        setDetailActionSubmitting(true);
        try {
          purgeCheckData = await postAction('/api/admin/staff/purge-check.php', { staff_id: currentStaffId });
          renderPurgeInspection(purgeCheckData);
          setDetailActionStatus(purgeCheckData.eligible_for_purge ? '关联检查完成，短时确认令牌已签发' : '员工存在业务关联，请使用离职归档保留历史', purgeCheckData.eligible_for_purge);
        } finally {
          setDetailActionSubmitting(false);
        }
      }

      async function purgeStaff(event) {
        event.preventDefault();
        if (!currentStaffId || detailActionSubmitting) return;
        const reason = document.getElementById('purgeReason').value.trim();
        const confirmed = document.getElementById('purgeConfirmed').checked;
        const token = purgeCheckData?.confirmation_token || '';
        if (!purgeCheckData?.eligible_for_purge || !token) throw new Error('请先完成清理资格检查');
        if (!reason || !confirmed) throw new Error('请填写清理原因并勾选操作确认');
        setDetailActionSubmitting(true);
        try {
          await postAction('/api/admin/staff/purge.php', { staff_id: currentStaffId, purge_reason: reason, confirmed, confirmation_token: token });
          currentStaffId = 0;
          currentDetailData = null;
          purgeCheckData = null;
          document.getElementById('detailRoot').innerHTML = '<div class="empty">误建员工已完成受控清理，请从员工一览选择其他档案。</div>';
          await Promise.allSettled([loadList(), loadSummary()]);
        } finally {
          setDetailActionSubmitting(false);
        }
        closeDetailDrawer();
      }

      async function unbindWechat() {
        if (!currentStaffId || !window.confirm('确认解绑该员工的微信绑定吗？')) return;
        await postAction('/api/admin/staff/unbind-wechat.php', { staff_id: currentStaffId });
        window.AdminCommon.adminToast('微信绑定已解除');
        await loadDetail(currentStaffId);
      }

      async function unlockAccount() {
        if (!currentStaffId) return;
        await postAction('/api/admin/staff/unlock-account.php', { staff_id: currentStaffId });
        window.AdminCommon.adminToast('账号已解锁');
        await loadDetail(currentStaffId);
      }

      function showActionError(error) { window.AdminCommon.adminToast(error?.message || '操作失败，请稍后重试'); }

      function localDateValue(date = new Date()) {
        const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
        return local.toISOString().slice(0, 10);
      }

      function clearCreateErrors() {
        document.querySelectorAll('[data-error-for]').forEach(node => { node.textContent = ''; });
        document.querySelectorAll('#createStaffForm [aria-invalid="true"]').forEach(node => node.removeAttribute('aria-invalid'));
        document.getElementById('createStatus').textContent = '';
      }

      function setCreateFieldError(fieldId, message) {
        const field = document.getElementById(fieldId);
        const error = document.querySelector(`[data-error-for="${fieldId}"]`);
        if (field) field.setAttribute('aria-invalid', 'true');
        if (error) error.textContent = message;
      }

      function createFormValues() {
        return {
          name: document.getElementById('createName').value.trim(),
          phone: document.getElementById('createPhone').value.replace(/\s+/g, ''),
          employee_no: document.getElementById('createEmployeeNo').value.trim(),
          entry_date: document.getElementById('createEntryDate').value,
          store_id: document.getElementById('createStoreId').value,
          position_id: document.getElementById('createPositionId').value,
          role: document.getElementById('createRole').value,
          username: document.getElementById('createUsername').value.trim(),
          initial_password: document.getElementById('createInitialPassword').value
        };
      }

      function validateCreateStep(step) {
        clearCreateErrors();
        const values = createFormValues();
        let firstInvalid = '';
        const reject = (fieldId, message) => { setCreateFieldError(fieldId, message); if (!firstInvalid) firstInvalid = fieldId; };
        if (step === 1) {
          if (!values.name) reject('createName', '请输入员工姓名');
          if (!/^1[3-9]\d{9}$/.test(values.phone)) reject('createPhone', '请输入有效的 11 位手机号');
          if (values.employee_no && !/^[A-Za-z0-9_-]{2,64}$/.test(values.employee_no)) reject('createEmployeeNo', '工号仅支持 2 至 64 位字母、数字、下划线和短横线');
          if (!/^\d{4}-\d{2}-\d{2}$/.test(values.entry_date)) reject('createEntryDate', '请选择入职日期');
        }
        if (step === 2) {
          if (!values.store_id) reject('createStoreId', '请选择启用门店');
          if (!values.position_id) reject('createPositionId', '请选择启用岗位');
          if (!values.role) reject('createRole', '请选择系统角色');
        }
        if (step === 3) {
          if (values.username && !/^[A-Za-z0-9_.@-]{2,60}$/.test(values.username)) reject('createUsername', '登录账号格式无效');
           if (values.initial_password.length < 10 || !/[A-Z]/.test(values.initial_password) || !/[a-z]/.test(values.initial_password) || !/\d/.test(values.initial_password)) reject('createInitialPassword', '密码需至少 10 位并包含大小写字母和数字');
        }
        if (firstInvalid) document.getElementById(firstInvalid).focus();
        return !firstInvalid;
      }

      function maskPhone(phone) {
        return /^\d{11}$/.test(phone) ? `${phone.slice(0, 3)}****${phone.slice(-4)}` : phone;
      }

      function renderCreateSummary() {
        const values = createFormValues();
        const store = storeOptions.find(item => String(item.id) === String(values.store_id));
        const position = positionOptions.find(item => String(item.id) === String(values.position_id));
        const summary = [
          ['员工', `${values.name} · ${values.employee_no || '工号自动生成'}`],
          ['手机号', maskPhone(values.phone)],
          ['入职日期', values.entry_date],
          ['组织', `${store?.name || '未选择门店'} · ${position?.position_name || '未选择岗位'}`],
          ['系统角色', roleLabels[values.role] || values.role || '-'],
          ['登录账号', values.username || '使用最终工号'],
          ['初始密码', '已按安全规则设置']
        ];
        document.getElementById('createConfirmSummary').innerHTML = summary.map(([label, value]) => `<div class="confirm-row"><span>${escapeHtml(label)}</span><strong>${escapeHtml(value)}</strong></div>`).join('');
      }

      function renderCreateStep() {
        document.querySelectorAll('[data-create-step]').forEach(panel => { panel.hidden = Number(panel.dataset.createStep) !== createStep; });
        document.querySelectorAll('[data-step-indicator]').forEach(indicator => {
          const step = Number(indicator.dataset.stepIndicator);
          indicator.classList.toggle('current', step === createStep);
          indicator.classList.toggle('completed', step < createStep);
        });
        document.getElementById('prevCreateStepBtn').hidden = createStep === 1;
        document.getElementById('nextCreateStepBtn').hidden = createStep === 3;
        document.getElementById('submitCreateStaffBtn').hidden = createStep !== 3;
        if (createStep === 3) renderCreateSummary();
      }

      function openCreateDrawer() {
        createDrawerReturnFocus = document.activeElement;
        document.getElementById('createStaffForm').reset();
        document.getElementById('createEntryDate').value = localDateValue();
        createStep = 1;
        createSubmitting = false;
        clearCreateErrors();
        renderCreateStep();
        document.getElementById('createDrawer').hidden = false;
        document.body.classList.add('drawer-open');
        focusDrawer(document.getElementById('createDrawer'), '#createName');
      }

      function closeCreateDrawer() {
        if (createSubmitting) return;
        document.getElementById('createDrawer').hidden = true;
        document.body.classList.remove('drawer-open');
        if (createDrawerReturnFocus?.focus) createDrawerReturnFocus.focus();
      }

      function moveCreateStep(direction) {
        if (direction > 0 && !validateCreateStep(createStep)) return;
        createStep = Math.min(3, Math.max(1, createStep + direction));
        clearCreateErrors();
        renderCreateStep();
        const panel = document.querySelector(`[data-create-step="${createStep}"]`);
        panel?.querySelector('input, select')?.focus();
      }

      function createConflictMessage(error) {
        const fields = Array.isArray(error?.data?.conflict_fields) ? error.data.conflict_fields : [];
        const profiles = Array.isArray(error?.data?.existing_profiles) ? error.data.existing_profiles : [];
        const fieldText = fields.length ? `冲突字段：${fields.join('、')}` : '';
        const profileText = profiles.slice(0, 3).map(item => [item.name, item.employee_no, item.phone].filter(Boolean).join(' / ')).filter(Boolean).join('；');
        return [error?.message || '员工创建失败', fieldText, profileText ? `现有档案：${profileText}` : ''].filter(Boolean).join('。');
      }

      function setCreateSubmitting(submitting) {
        createSubmitting = submitting;
        for (const id of ['cancelCreateBtn', 'prevCreateStepBtn', 'nextCreateStepBtn', 'submitCreateStaffBtn', 'closeCreateDrawerBtn']) {
          document.getElementById(id).disabled = submitting;
        }
        document.getElementById('submitCreateStaffBtn').textContent = submitting ? '正在创建...' : '确认创建';
      }

      async function submitCreateStaff(event) {
        event.preventDefault();
        if (createSubmitting) return;
        for (const step of [1, 2, 3]) {
          if (!validateCreateStep(step)) { createStep = step; renderCreateStep(); return; }
        }
        const values = createFormValues();
        const payload = { ...values, store_id: Number(values.store_id), position_id: Number(values.position_id) };
        if (!payload.employee_no) delete payload.employee_no;
        if (!payload.username) delete payload.username;
        setCreateSubmitting(true);
        document.getElementById('createStatus').textContent = '正在创建员工档案、登录账号和主任职...';
        let created;
        try {
          const data = await postAction('/api/admin/staff/create.php', payload);
          created = data.item || {};
        } catch (error) {
          setCreateSubmitting(false);
          document.getElementById('createStatus').textContent = createConflictMessage(error);
          return;
        }
        setCreateSubmitting(false);
        closeCreateDrawer();
        window.AdminCommon.adminToast(`员工 ${created.name || values.name} 创建成功，工号：${created.employee_no || values.employee_no || '已自动生成'}`);
        await Promise.allSettled([loadList({ resetPage: true }), loadSummary()]);
        if (created.id) await loadDetail(created.id).catch(showActionError);
      }

      function clearFilters() {
        document.getElementById('filterForm').reset();
        document.getElementById('filterSize').value = '20';
        loadList({ resetPage: true }).catch(() => {});
      }

      function focusDrawer(layer, preferredSelector) {
        requestAnimationFrame(() => {
          const preferred = preferredSelector ? layer.querySelector(preferredSelector) : null;
          (preferred || drawerFocusableElements(layer)[0] || layer.querySelector('[role="dialog"]'))?.focus();
        });
      }

      function drawerFocusableElements(layer) {
        const selector = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
        return [...layer.querySelectorAll(selector)].filter(element => !element.closest('[hidden]'));
      }

      function activeDrawerLayer() {
        return ['settingDrawer', 'createDrawer', 'detailDrawer']
          .map(id => document.getElementById(id))
          .find(layer => !layer.hidden) || null;
      }

      function trapDrawerFocus(event) {
        if (event.key !== 'Tab') return;
        const layer = activeDrawerLayer();
        if (!layer) return;
        const focusable = drawerFocusableElements(layer);
        if (!focusable.length) {
          event.preventDefault();
          layer.querySelector('[role="dialog"]')?.focus();
          return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && (document.activeElement === first || !layer.contains(document.activeElement))) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      }

      function bindEvents() {
        document.querySelectorAll('[data-workspace-tab]').forEach(tab => { tab.onclick = () => setWorkspace(tab.dataset.workspaceTab); });
        document.getElementById('downloadImportTemplateBtn').onclick = downloadImportTemplate;
        document.getElementById('importFile').onchange = event => selectImportFile(event.target.files?.[0]).catch(showActionError);
        const importDropZone = document.getElementById('importDropZone');
        for (const eventName of ['dragenter', 'dragover']) importDropZone.addEventListener(eventName, event => { event.preventDefault(); importDropZone.classList.add('dragging'); });
        for (const eventName of ['dragleave', 'drop']) importDropZone.addEventListener(eventName, event => { event.preventDefault(); importDropZone.classList.remove('dragging'); });
        importDropZone.addEventListener('drop', event => selectImportFile(event.dataTransfer?.files?.[0]).catch(showActionError));
        document.getElementById('clearImportBtn').onclick = clearImport;
        document.getElementById('submitImportBtn').onclick = () => submitImport(false).catch(showActionError);
        document.getElementById('retryImportBtn').onclick = () => submitImport(true).catch(showActionError);
        document.getElementById('reloadHealthBtn').onclick = () => loadDataHealth(true).catch(() => {});
        document.querySelectorAll('[data-organization-mode]').forEach(button => {
          button.onclick = () => {
            organizationMode = button.dataset.organizationMode;
            document.querySelectorAll('[data-organization-mode]').forEach(item => {
              const current = item.dataset.organizationMode === organizationMode;
              item.classList.toggle('current', current);
              item.setAttribute('aria-pressed', String(current));
            });
            renderOrganization();
          };
        });
        document.getElementById('filterForm').addEventListener('submit', event => { event.preventDefault(); loadList({ resetPage: true }).catch(() => {}); });
        document.getElementById('clearFiltersBtn').onclick = clearFilters;
        document.getElementById('reloadBtn').onclick = () => Promise.all([loadList(), loadSummary()]).catch(() => {});
        document.getElementById('prevPageBtn').onclick = () => { if (currentPage > 1) { currentPage -= 1; loadList().catch(() => {}); } };
        document.getElementById('nextPageBtn').onclick = () => { if (currentPage < currentTotalPages) { currentPage += 1; loadList().catch(() => {}); } };
        document.getElementById('openCreateDrawerBtn').onclick = openCreateDrawer;
        document.getElementById('closeDetailDrawerBtn').onclick = closeDetailDrawer;
        document.getElementById('detailDrawerBackdrop').onclick = closeDetailDrawer;
        document.getElementById('closeCreateDrawerBtn').onclick = closeCreateDrawer;
        document.getElementById('createDrawerBackdrop').onclick = closeCreateDrawer;
        document.getElementById('cancelCreateBtn').onclick = closeCreateDrawer;
        document.getElementById('prevCreateStepBtn').onclick = () => moveCreateStep(-1);
        document.getElementById('nextCreateStepBtn').onclick = () => moveCreateStep(1);
        document.getElementById('createStaffForm').addEventListener('submit', submitCreateStaff);
        document.getElementById('createStoreBtn').onclick = () => openSettingDrawer('store').catch(showActionError);
        document.getElementById('createPositionBtn').onclick = () => openSettingDrawer('position').catch(showActionError);
        document.getElementById('closeSettingDrawerBtn').onclick = closeSettingDrawer;
        document.getElementById('settingDrawerBackdrop').onclick = closeSettingDrawer;
        document.getElementById('cancelSettingBtn').onclick = closeSettingDrawer;
        document.getElementById('settingForm').addEventListener('submit', event => saveSetting(event).catch(error => { setSettingSubmitting(false); document.getElementById('settingStatus').textContent = error?.message || '保存失败'; }));
        document.addEventListener('keydown', event => {
          trapDrawerFocus(event);
          if (event.key !== 'Escape') return;
          event.preventDefault();
          if (!document.getElementById('settingDrawer').hidden) closeSettingDrawer();
          else if (!document.getElementById('createDrawer').hidden) closeCreateDrawer();
          else if (!document.getElementById('detailDrawer').hidden) closeDetailDrawer();
        });
      }

      async function boot(user) {
        currentUser = user || null;
        document.getElementById('currentUser').textContent = [user?.staff_name, user?.display_name, user?.username].find(Boolean) || '已登录';
        bindEvents();
        try {
          await loadDictionaries();
          await Promise.all([loadList({ resetPage: true }), loadSummary()]);
          document.getElementById('heroTitle').textContent = '员工目录与组织状态一屏掌握';
          document.getElementById('heroDesc').textContent = '员工一览已接入统一目录服务，支持关键词、门店、主岗位、系统角色、人员状态组合筛选以及完整分页。';
        } catch (error) {
          document.getElementById('heroTitle').textContent = '员工工作区加载失败';
          document.getElementById('heroDesc').textContent = error.message || '请稍后重试';
        }
      }

      window.requirePageAuth({ onAuthed: async user => {
    if (user && window.InternalAuth && !window.InternalAuth.adminPageAllowed('headquarter', user)) { window.location.replace('/internal.html'); return; }
        const role = String(user?.role || '').toLowerCase();
        const canManageStaff = Boolean(user?.is_hq || user?.is_admin || ['admin', 'ceo', 'operation', 'ops'].includes(role));
        if (!canManageStaff) { window.location.replace('/internal.html'); return; }
        await boot(user);
      }});
    