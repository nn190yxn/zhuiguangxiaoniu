// ---- 原第 1 个内联 script 块 ----

      var context = null,
        currentWorkspace = "tracking",
        currentView = "dashboard",
        currentPayload = null,
        currentTrendRows = [],
        lastTrigger = null,
        drawerReturnFocus = null,
        previewReturnFocus = null,
        requestVersion = 0,
        storeOptions = [];
      var viewLabels = {
        dashboard: "驾驶舱",
        store: "门店周期完成",
        metric: "项目选取度",
        staff: "个人全数据",
        matrix: "项目全维度",
      };
      var workspaceLabels = {
        tracking: "每日追踪",
        penalties: "处罚处理",
        dashboard: "数据驾驶舱",
        audit: "审核队列",
        funnel: "经营漏斗",
        alerts: "预警建议",
        standards: "岗位标准",
        imports: "导入记录",
      };
      function byId(id) {
        return document.getElementById(id);
      }
      function esc(value) {
        return String(value == null ? "" : value).replace(
          /[&<>"']/g,
          function (char) {
            return {
              "&": "&amp;",
              "<": "&lt;",
              ">": "&gt;",
              '"': "&quot;",
              "'": "&#39;",
            }[char];
          },
        );
      }
      function today() {
        var date = new Date();
        return (
          date.getFullYear() +
          "-" +
          String(date.getMonth() + 1).padStart(2, "0") +
          "-" +
          String(date.getDate()).padStart(2, "0")
        );
      }
      function daysAgo(count) {
        var date = new Date();
        date.setDate(date.getDate() - count);
        return (
          date.getFullYear() +
          "-" +
          String(date.getMonth() + 1).padStart(2, "0") +
          "-" +
          String(date.getDate()).padStart(2, "0")
        );
      }
      function percent(ratio) {
        var value = ratio && typeof ratio === "object" ? ratio.value : ratio;
        return Math.round(Number(value || 0) * 100) + "%";
      }
      function valueOf(value) {
        return value && typeof value === "object"
          ? Number(value.value || 0)
          : Number(value || 0);
      }
      function formatNumber(value) {
        return Number(value || 0).toLocaleString("zh-CN", {
          maximumFractionDigits: 2,
        });
      }
      function roleLabel(value) {
        return (
          {
            sales: "销售",
            coach: "教练",
            manager: "店长",
            operation: "运营",
            finance: "财务",
          }[value] ||
          value ||
          "-"
        );
      }
      function sourceLabel(value) {
        return (
          {
            h5: "手机填报页",
            mini_program: "微信小程序",
            manual_correction: "管理更正",
            synthetic: "系统生成",
            import: "批量导入",
          }[value] ||
          value ||
          "-"
        );
      }
      function displayName(primary, fallback) {
        return primary || fallback || "未命名";
      }
      function metricName(row) {
        return displayName(row && row.metric_name, "未命名项目");
      }
      function relationName(row) {
        return displayName(row && row.relation_name, "未命名关系");
      }
      function versionName(value) {
        if (!value) return "当前版本";
        return "版本 " + String(value).replace(/^v/i, "").replace(/[-_]/g, " ");
      }
      function auditModeLabel(value) {
        return (
          { none: "无需审核", manual: "人工审核", evidence: "凭证审核", full: "完整审核" }[
            value
          ] || "按规则审核"
        );
      }
      function valueTypeLabel(value) {
        return (
          { number: "数值", integer: "整数", decimal: "小数", percentage: "百分比", currency: "金额" }[
            value
          ] || "数值"
        );
      }
      function directionLabel(value) {
        return ({ higher: "越高越好", lower: "越低越好", neutral: "中性" }[value] || "中性");
      }
      function relationStateLabel(value) {
        return ({ comparable: "可对比", empty: "暂无样本", unavailable: "暂无口径" }[value] || "暂无数据");
      }
      function importStatusLabel(value) {
        return (
          {
            uploaded: "已上传",
            preflight_ready: "预检通过",
            preflight_has_errors: "预检有问题",
            confirmed: "已生成草稿",
            failed: "处理失败",
          }[value] || "处理中"
        );
      }
      function differenceSummary(diff) {
        diff = diff || {};
        var added = (diff.added || []).length,
          modified = (diff.modified || []).length,
          removed = (diff.removed || []).length;
        return "新增 " + added + " 项，调整 " + modified + " 项，停用 " + removed + " 项。";
      }
      function statusLabel(value) {
        return (
          {
            missing: "缺交",
            draft: "草稿",
            submitted: "已提交",
            locked_missing: "锁定缺交",
            corrected: "管理更正",
            pending: "待审核",
            approved: "已通过",
            rejected: "已驳回",
            needs_resubmit: "待补凭证",
            not_required: "无需审核",
            sample_low: "样本不足",
            open: "待处理",
            resolved: "已处理",
            closed: "已关闭",
            published: "已发布",
            retired: "已停用",
            today_open: "今日待完成",
            makeup_open: "昨日待补",
            completed: "已达标",
            overdue: "已逾期",
            pending_confirmation: "待确认处罚",
            confirmed: "已确认处罚",
            cancelled: "已撤销处罚",
            payroll_handed_off: "已交薪资",
          }[value] ||
          value ||
          "-"
        );
      }
      function auditStatusLabel(value) {
        return (
          {
            missing: "缺少审核任务",
            pending: "待审核",
            approved: "已通过",
            rejected: "已驳回",
            needs_resubmit: "待补凭证",
            not_required: "无需审核",
          }[value] ||
          value ||
          "-"
        );
      }
      function pill(value) {
        var kind = [
          "submitted",
          "corrected",
          "approved",
          "not_required",
        ].includes(value)
          ? "ok"
          : ["draft", "pending", "needs_resubmit"].includes(value)
            ? "warn"
            : ["missing", "locked_missing", "rejected"].includes(value)
              ? "danger"
              : "";
        return (
          '<span class="pill ' +
          kind +
          '">' +
          esc(statusLabel(value)) +
          "</span>"
        );
      }
      function filterScopeText() {
        var f = filters(), parts = [];
        parts.push((f.date_from || '-') + ' 至 ' + (f.date_to || '-'));
        if (f.store_id) {
          var store = storeOptions.find(function (item) {
            return String(item.id) === String(f.store_id);
          });
          parts.push(store ? store.name : '门店 ' + f.store_id);
        } else {
          parts.push('全部可见门店');
        }
        parts.push(f.role_code ? roleLabel(f.role_code) : '全部岗位');
        return parts.join(' · ');
      }
      function setRange(type, trigger) {
        byId("dateTo").value = type === "yesterday" ? daysAgo(1) : today();
        byId("dateFrom").value =
          type === "yesterday"
            ? daysAgo(1)
            : type === "30d"
              ? daysAgo(29)
              : daysAgo(6);
        document.querySelectorAll("[data-range]").forEach(function (button) {
          button.setAttribute(
            "aria-pressed",
            String(button.dataset.range === type),
          );
        });
        lastTrigger = trigger || null;
        loadData();
      }
      function setView(view, trigger) {
        currentView = view;
        lastTrigger = trigger || document.activeElement;
        document.querySelectorAll("[data-view]").forEach(function (tab) {
          var selected = tab.dataset.view === view;
          tab.setAttribute("aria-selected", String(selected));
          tab.tabIndex = selected ? 0 : -1;
        });
        byId("analyticsContent").setAttribute("aria-labelledby", "tab-" + view);
        byId("primaryDimensionField").hidden = view !== "matrix";
        byId("secondaryDimensionField").hidden = view !== "matrix";
        byId("granularityField").hidden = !["staff", "matrix"].includes(view);
        var role = String(
          (context && context.role) || (context && context.role_code) || "",
        ).toLowerCase();
        if (
          view === "staff" &&
          context &&
          context.staff_id &&
          !context.is_hq &&
          !context.is_admin &&
          role !== "manager"
        ) {
          byId("staffId").value = context.staff_id;
          byId("staffName").value = context.name || context.staff_name || "本人";
          byId("staffName").readOnly = true;
        } else if (role === "manager") {
          byId("staffName").readOnly = false;
        }
        loadData();
      }
      function setWorkspace(workspace, trigger) {
        currentWorkspace = workspace;
        lastTrigger = trigger || document.activeElement;
        byId("pageTitle").textContent =
          workspace === "tracking" ? "工作量每日追踪" : workspaceLabels[workspace];
        document.querySelectorAll("[data-workspace]").forEach(function (tab) {
          var selected = tab.dataset.workspace === workspace;
          tab.setAttribute("aria-selected", String(selected));
          tab.tabIndex = selected ? 0 : -1;
        });
        byId("analyticsTabs").hidden = workspace !== "dashboard";
        byId("exportBtn").hidden = workspace !== "dashboard";
        byId("analyticsContent").setAttribute(
          "aria-labelledby",
          workspace === "dashboard"
            ? "tab-" + currentView
            : "tab-workspace-" + workspace,
        );
        loadData();
      }
      function validateFilters() {
        byId("dateError").textContent = "";
        var from = byId("dateFrom").value,
          to = byId("dateTo").value;
        if (!from || !to) {
          byId("dateError").textContent = "请选择完整日期范围";
          byId(!from ? "dateFrom" : "dateTo").focus();
          return false;
        }
        if (from > to) {
          byId("dateError").textContent = "开始日期需早于或等于结束日期";
          byId("dateFrom").focus();
          return false;
        }
        var days = (new Date(to) - new Date(from)) / 86400000;
        if (days > 365) {
          byId("dateError").textContent = "日期范围最多 366 天";
          byId("dateFrom").focus();
          return false;
        }
        if (
          currentView === "staff" &&
          !/^\d+$/.test(byId("staffId").value.trim())
        ) {
          byId("dateError").textContent = "个人视图请按姓名选择员工";
          byId("staffName").focus();
          return false;
        }
        if (
          currentView === "matrix" &&
          byId("primaryDimension").value === byId("secondaryDimension").value
        ) {
          byId("dateError").textContent = "主维度和次维度需要不同";
          byId("secondaryDimension").focus();
          return false;
        }
        return true;
      }
      function filters() {
        var result = {
          date_from: byId("dateFrom").value,
          date_to: byId("dateTo").value,
        };
        [
          ["storeId", "store_id"],
          ["roleCode", "role_code"],
          ["staffId", "staff_id"],
          ["metricCode", "metric_code"],
          ["reportStatus", "report_status"],
          ["auditStatus", "audit_status"],
          ["source", "source"],
        ].forEach(function (pair) {
          var value = byId(pair[0]).value.trim();
          if (value) result[pair[1]] = value;
        });
        return result;
      }
      function queryString(input) {
        var params = new URLSearchParams();
        Object.keys(input).forEach(function (key) {
          if (input[key] !== "" && input[key] != null)
            params.set(key, input[key]);
        });
        return params.toString();
      }
      function updateApplied() {
        var f = filters(),
          labels = {
            date_from: "开始",
            date_to: "结束",
            store_id: "门店",
            role_code: "岗位",
            staff_id: "员工",
            metric_code: "项目",
            report_status: "日报",
            audit_status: "审核",
            source: "来源",
          };
        var chips = Object.keys(f).map(function (key) {
          var value = f[key];
          if (key === "store_id") {
            var store = storeOptions.find(function (item) {
              return String(item.id) === String(value);
            });
            value = store ? store.name : value;
          }
          if (key === "role_code") value = roleLabel(value);
          if (key === "report_status") value = statusLabel(value);
          if (key === "audit_status") value = auditStatusLabel(value);
          if (key === "source") value = sourceLabel(value);
          return (
            '<span class="chip">' +
            esc(labels[key]) +
            ": " +
            esc(value) +
            "</span>"
          );
        });
        byId("appliedFilters").innerHTML =
          chips.join("") || '<span class="chip">默认权限范围</span>';
        byId("rangeStamp").textContent =
          f.date_from +
          " 至 " +
          f.date_to +
          " · " +
          (currentWorkspace === "dashboard"
            ? viewLabels[currentView]
            : workspaceLabels[currentWorkspace]);
      }
      function setAsyncState(state, error) {
        var workspace = document.querySelector(".workspace"),
          content = byId("analyticsContent");
        workspace.setAttribute("aria-busy", String(state === "loading"));
        content.className = "content" + (state === "ready" ? " ready" : "");
        content.dataset.state = state;
        byId("loadingState").classList.toggle("show", state === "loading");
        byId("emptyState").classList.toggle("show", state === "empty");
        byId("errorBanner").classList.toggle(
          "show",
          state === "error" || state === "timeout",
        );
        byId("refreshBtn").disabled = state === "loading";
        byId("exportBtn").disabled = state === "loading";
        if (error) {
          var trace =
            error.data &&
            error.data.data &&
            (error.data.data.trace_id || error.data.data.request_id);
          byId("errorTitle").textContent =
            state === "timeout" ? "查询超时" : "加载失败";
          byId("errorMessage").textContent = error.message || "统计查询失败";
          byId("traceId").textContent = trace ? "问题编号：" + trace : "";
        }
        byId("liveStatus").textContent =
          {
            loading: "正在加载",
            ready: "统计已更新",
            empty: "当前筛选无数据",
            error: "加载失败",
            timeout: "查询超时",
          }[state] || "";
      }
      function endpointForView(view, f) {
        var query = queryString(f);
        if (view === "store")
          return ["/api/workload/analytics/store-completion.php?" + query];
        if (view === "metric")
          return ["/api/workload/analytics/metric-selection.php?" + query];
        if (view === "staff")
          return [
            "/api/workload/analytics/staff-profile.php?" +
              query +
              "&granularity=" +
              encodeURIComponent(byId("granularity").value),
          ];
        if (view === "matrix")
          return [
            "/api/workload/analytics/cross-analysis.php?" +
              query +
              "&primary_dimension=" +
              byId("primaryDimension").value +
              "&secondary_dimension=" +
              byId("secondaryDimension").value +
              "&time_granularity=" +
              (byId("granularity").value === "week"
                ? "business_week"
                : byId("granularity").value),
          ];
        return [
          "/api/workload/analytics/store-completion.php?" + query,
          "/api/workload/analytics/metric-selection.php?" + query,
        ];
      }
      function endpointForWorkspace(workspace, f) {
        var query = queryString(f);
        if (workspace === "tracking") {
          return ["/api/admin/workload/daily-tracking.php?" + query];
        }
        if (workspace === "penalties") {
          return ["/api/admin/workload/penalty-list.php?" + query];
        }
        if (workspace === "audit") {
          return [
            "/api/workload/audit-list.php?include_history=1&page_size=50&" +
              query,
          ];
        }
        if (workspace === "funnel") {
          return ["/api/workload/analytics/operating-funnel.php?" + query];
        }
        if (workspace === "alerts") {
          return ["/api/workload/alerts.php?page_size=50&" + query];
        }
        if (workspace === "standards") {
          return [
            "/api/admin/workload/standards.php?status=all&role_code=" +
              encodeURIComponent(f.role_code || ""),
          ];
        }
        if (workspace === "imports") {
          return [
            "/api/admin/workload/standard-import-batches.php?page_size=50",
          ];
        }
        return endpointForView(currentView, f);
      }
      async function loadData() {
        if (!validateFilters()) return;
        var version = ++requestVersion;
        updateApplied();
        currentPayload = null;
        byId("analyticsContent").innerHTML = "";
        setAsyncState("loading");
        try {
          var endpoints = endpointForWorkspace(currentWorkspace, filters());
          var responses = await Promise.all(
            endpoints.map(function (url) {
              return ApiClient.get(url, { timeout: 15000 });
            }),
          );
          if (version !== requestVersion) return;
          currentPayload = responses.map(function (response) {
            return response.data;
          });
          renderCurrent(currentPayload);
          setAsyncState(hasCurrentRows(currentPayload) ? "ready" : "empty");
        } catch (error) {
          if (version !== requestVersion) return;
          setAsyncState(
            Number(error.code) === 408 || /超时/.test(error.message || "")
              ? "timeout"
              : "error",
            error,
          );
        }
      }
      function hasCurrentRows(payload) {
        if (
          currentWorkspace === "tracking" ||
          currentWorkspace === "penalties" ||
          currentWorkspace === "audit" ||
          currentWorkspace === "alerts" ||
          currentWorkspace === "standards" ||
          currentWorkspace === "imports"
        ) {
          return (payload[0].list || []).length > 0;
        }
        if (currentWorkspace === "funnel") return true;
        if (currentView === "dashboard")
          return (
            (payload[0].store_summaries || []).length > 0 ||
            (payload[1].project_summaries || []).length > 0
          );
        if (currentView === "store")
          return (payload[0].store_summaries || []).length > 0;
        if (currentView === "metric")
          return (payload[0].project_summaries || []).length > 0;
        if (currentView === "staff")
          return (payload[0].daily_records || []).length > 0;
        if (currentView === "matrix")
          return (payload[0].matrix || []).length > 0;
        return false;
      }
      function metadata(data) {
        var scope = data.permission_scope || {};
        var source = (data.source_scope || []).map(sourceLabel).join("、") || "默认经营来源";
        byId("permissionStamp").textContent =
          "数据范围: " +
          ({ all: "全部门店", stores: "授权门店", staff: "员工本人" }[
            scope.scope_type
          ] || "已授权范围");
        return (
           '<div class="meta-strip"><div class="meta-item">数据截至<strong>' +
          esc(data.data_cutoff_at || data.generated_at || "-") +
           '</strong></div><div class="meta-item">统计范围<strong>' +
          esc(versionName(data.metric_version)) +
          '</strong></div><div class="meta-item">生产来源<strong>' +
          esc(source) +
          '</strong></div><div class="meta-item">权限范围<strong>' +
          esc(
            {
              all: "全部门店",
              stores: "授权门店",
              staff: "员工本人",
            }[scope.ranking_scope || scope.scope_type] || "已授权范围",
          ) +
          "</strong></div></div>"
        );
      }
      function renderCurrent(payload) {
        if (currentWorkspace === "tracking") renderDailyTracking(payload[0]);
        else if (currentWorkspace === "penalties") renderPenaltyList(payload[0]);
        else if (currentWorkspace === "audit") renderAuditQueue(payload[0]);
        else if (currentWorkspace === "funnel")
          renderOperatingFunnel(payload[0]);
        else if (currentWorkspace === "alerts") renderAlerts(payload[0]);
        else if (currentWorkspace === "standards") renderStandards(payload[0]);
        else if (currentWorkspace === "imports") renderImports(payload[0]);
        else if (currentView === "dashboard")
          renderDashboard(payload[0], payload[1]);
        else if (currentView === "store") renderStore(payload[0]);
        else if (currentView === "metric") renderMetric(payload[0]);
        else if (currentView === "staff") renderStaff(payload[0]);
        else renderMatrix(payload[0]);
        bindRowActions();
        bindBusinessActions();
        requestAnimationFrame(drawVisibleTrend);
      }
      function kpiCard(value, label, note, primary) {
        return (
          '<div class="kpi ' +
          (primary ? "primary" : "") +
          '"><div class="kpi-value">' +
          esc(value) +
          '</div><div class="kpi-label">' +
          esc(label) +
          '</div><div class="kpi-note">' +
          esc(note || "") +
          "</div></div>"
        );
      }
      function conversionKpis(summary) {
        if (!summary) return "";
        return (
           kpiCard(formatNumber(summary.effective_points), "已确认工作量", "待确认 " + formatNumber(summary.pending_points) + " 点", true) +
           kpiCard(formatNumber(summary.gap_points), "日报达标差额", "已达标日报 " + Number(summary.completed_report_count || 0) + " 份")
        );
      }
      function completionTotals(rows) {
        return rows.reduce(
          function (total, row) {
            total.required += Number(row.required_count || 0);
            total.completed += Number(row.completed_count || 0);
            total.missing +=
              Number((row.status_counts || {}).missing || 0) +
              Number((row.status_counts || {}).locked_missing || 0);
            if (valueOf(row.completion_rate) < 0.8) total.weak += 1;
            return total;
          },
          { required: 0, completed: 0, missing: 0, weak: 0 },
        );
      }
      function aggregateDailyCompletion(rows) {
        var days = {};
        rows.forEach(function (row) {
          var date = row.business_date || row.date;
          if (!date) return;
          days[date] ??= {
            business_date: date,
            required_count: 0,
            completed_count: 0,
          };
          days[date].required_count += Number(row.required_count || 0);
          days[date].completed_count += Number(row.completed_count || 0);
        });
        return Object.keys(days)
          .sort()
          .map(function (date) {
            var row = days[date];
            row.completion_rate = {
              numerator: row.completed_count,
              denominator: row.required_count,
              value: row.required_count
                ? row.completed_count / row.required_count
                : 0,
            };
            return row;
          });
      }
      function renderDashboard(storeData, metricData) {
        var rows = storeData.store_summaries || [],
          totals = completionTotals(rows),
          rate = totals.required ? totals.completed / totals.required : 0;
        byId("analyticsContent").innerHTML =
          '<div class="kpis">' +
          kpiCard(
            percent(rate),
            "总体完成率",
            "已完成 " + totals.completed + " / 应交 " + totals.required,
            true,
          ) +
          kpiCard(totals.missing, "缺交与锁定缺交", "需要优先跟进") +
           kpiCard(totals.weak, "需要跟进门店", "日报完成率低于 80%") +
          conversionKpis(metricData.conversion_summary) +
          "</div>" +
          metadata(storeData) +
          '<div class="grid"><section class="card"><div class="card-head"><div class="card-title">门店完成排行<span class="card-subtitle">点击查看明细</span></div></div>' +
          storeTable(rows) +
          '</section><section class="card"><div class="card-head"><div class="card-title">项目有效表现</div></div>' +
          metricTable((metricData.project_summaries || []).slice(0, 8)) +
          "</section></div>" +
          trendSection(aggregateDailyCompletion(storeData.daily_trend || []));
      }
      function trackingKpis(summary) {
        summary = summary || {};
        return (
          '<div class="kpis">' +
          kpiCard(summary.today_open_count || 0, "今日待完成", "优先提醒员工完成日报", true) +
          kpiCard(summary.makeup_open_count || 0, "昨日待补", "请在补齐截止前跟进") +
          kpiCard(summary.overdue_count || 0, "已逾期", "需要跟进处罚处理") +
          kpiCard(summary.pending_review_count || 0, "待审核", "待审核点数尚未计入有效点数") +
          kpiCard(summary.pending_penalty_count || 0, "待确认处罚", "进入处罚处理完成确认") +
          "</div>"
        );
      }
      function renderDailyTracking(data) {
        var rows = data.items || [];
        var scope = data.scope || {};
        byId("permissionStamp").textContent =
          "数据范围: " +
          ({ all: "全部门店", stores: "授权门店", staff: "员工本人" }[
            scope.scope_type
          ] || "已授权范围");
        byId("analyticsContent").innerHTML =
          trackingKpis(data.summary) +
          '<section class="card"><div class="card-head"><div class="card-title">员工每日跟踪<span class="card-subtitle">按当前权限与筛选范围展示</span></div></div><div class="table-wrap"><table class="table"><thead><tr><th>员工</th><th>门店 / 岗位</th><th>状态</th><th>目标</th><th>已报</th><th>待核</th><th>有效</th><th>差额</th><th>补齐截止</th><th>处罚金额</th><th>下一步</th></tr></thead><tbody>' +
          rows
            .map(function (row) {
              return (
                "<tr><td>" +
                esc(row.staff_name || row.staff_id) +
                "</td><td>" +
                esc(row.store_name || row.store_id) +
                " · " +
                esc(roleLabel(row.role_code)) +
                "</td><td>" +
                pill(row.settlement_status) +
                "</td><td>" +
                esc(formatNumber(row.target_points)) +
                "</td><td>" +
                esc(formatNumber(row.reported_points)) +
                "</td><td>" +
                esc(formatNumber(row.pending_points)) +
                "</td><td>" +
                esc(formatNumber(row.effective_points)) +
                "</td><td>" +
                esc(formatNumber(row.gap_points)) +
                "</td><td>" +
                esc(row.makeup_deadline_at || "-") +
                "</td><td>" +
                (row.penalty_id
                  ? "¥" + esc(formatNumber(row.penalty_amount)) + " · " + esc(row.penalty_status_label)
                  : "-") +
                "</td><td>" +
                esc(row.next_action || "等待处理") +
                "</td></tr>"
              );
            })
            .join("") +
          '</tbody></table></div><div class="mobile-cards">' +
          rows
            .map(function (row) {
              return (
                '<article class="mobile-card"><strong>' +
                esc(row.staff_name || row.staff_id) +
                " · " +
                esc(row.store_name || row.store_id) +
                '</strong><p>' +
                esc(row.settlement_status_label) +
                " · 有效 " +
                esc(formatNumber(row.effective_points)) +
                " 点 · 差额 " +
                esc(formatNumber(row.gap_points)) +
                " 点</p><p>" +
                esc(row.next_action || "等待处理") +
                "</p></article>"
              );
            })
            .join("") +
          "</div></section>";
      }
      function penaltyActionLabel(action) {
        return { confirm: "确认处罚", cancel: "撤销处罚", payroll_handoff: "标记已交薪资" }[action] || "处理处罚";
      }
      function renderPenaltyList(data) {
        var rows = data.items || [];
        var summary = data.summary || {};
        byId("analyticsContent").innerHTML =
          '<div class="kpis">' +
          kpiCard(summary.record_count || 0, "处罚记录", "当前筛选范围内的全部记录", true) +
          kpiCard("¥" + formatNumber(summary.pending_amount), "待确认金额", "需要运营或管理员确认") +
          kpiCard("¥" + formatNumber(summary.amount), "处罚总金额", "包含当前筛选范围内全部记录") +
          "</div>" +
          '<section class="card"><div class="card-head"><div class="card-title">处罚处理清单<span class="card-subtitle">所有操作必须填写处理原因</span></div></div><div class="table-wrap"><table class="table"><thead><tr><th>员工</th><th>营业日</th><th>门店 / 岗位</th><th>差额</th><th>金额</th><th>状态</th><th>处理说明</th><th>下一步</th><th>操作</th></tr></thead><tbody>' +
          rows
            .map(function (row, index) {
              return (
                "<tr><td>" +
                esc(row.staff_name || row.staff_id) +
                "</td><td>" +
                esc(row.business_date) +
                "</td><td>" +
                esc(row.store_name || row.store_id) +
                " · " +
                esc(roleLabel(row.role_code)) +
                "</td><td>" +
                esc(formatNumber(row.gap_points)) +
                " 点</td><td>¥" +
                esc(formatNumber(row.penalty_amount)) +
                "</td><td>" +
                pill(row.penalty_status) +
                "</td><td>" +
                esc(row.confirmation_comment || row.cancellation_reason || row.payroll_handoff_note || row.adjustment_reason || "-") +
                "</td><td>" +
                esc(row.next_action || "等待处理") +
                "</td><td>" +
                penaltyActionButtons(row, index) +
                "</td></tr>"
              );
            })
            .join("") +
          '</tbody></table></div><div class="mobile-cards">' +
          rows
            .map(function (row, index) {
              return (
                '<article class="mobile-card"><strong>' +
                esc(row.staff_name || row.staff_id) +
                " · ¥" +
                esc(formatNumber(row.penalty_amount)) +
                "</strong><p>" +
                esc(row.business_date) +
                " · " +
                esc(row.penalty_status_label) +
                "</p><p>" +
                esc(row.next_action || "等待处理") +
                '</p><div class="actions">' +
                penaltyActionButtons(row, index) +
                "</div></article>"
              );
            })
            .join("") +
          "</div></section>";
      }
      function penaltyActionButtons(row, index) {
        var role = String(
          (context && context.role) || (context && context.role_code) || "",
        ).toLowerCase();
        if (!((context && (context.is_hq || context.is_admin)) || ["operation", "admin", "ceo"].includes(role))) {
          return "";
        }
        var actions = [];
        if (row.penalty_status === "pending_confirmation") {
          actions.push("confirm", "cancel");
        } else if (row.penalty_status === "confirmed") {
          actions.push("payroll_handoff", "cancel");
        }
        return actions
          .map(function (action) {
            return '<button class="btn" data-business="penalty-action" data-index="' + index + '" data-penalty-action="' + action + '">' + penaltyActionLabel(action) + "</button>";
          })
          .join("");
      }
      function storeTable(rows) {
        var body = rows
          .map(function (row, index) {
            var rate = percent(row.completion_rate);
            return (
              '<tr tabindex="0" data-action="store" data-index="' +
              index +
              '"><td>' +
              esc(row.store_name || row.store_id) +
              '</td><td><div class="progress"><span style="width:' +
              Math.min(100, valueOf(row.completion_rate) * 100) +
              '%"></span></div></td><td class="number">' +
              esc(row.required_count) +
              '</td><td class="number">' +
              esc(row.completed_count) +
              '</td><td class="number">' +
              esc((row.status_counts || {}).locked_missing || 0) +
              '</td><td class="number">' +
              rate +
              "</td></tr>"
            );
          })
          .join("");
        var cards = rows
          .map(function (row, index) {
            return (
              '<article class="mobile-card"><button data-action="store" data-index="' +
              index +
              '"><strong>' +
              esc(row.store_name || row.store_id) +
              "</strong><p>完成 " +
              esc(row.completed_count) +
              " / " +
              esc(row.required_count) +
              " · " +
              percent(row.completion_rate) +
              "</p></button></article>"
            );
          })
          .join("");
        return (
          '<div class="table-wrap"><table class="table"><thead><tr><th>门店</th><th>进度</th><th class="number">应交</th><th class="number">完成</th><th class="number">锁定缺交</th><th class="number">完成率</th></tr></thead><tbody>' +
          body +
          '</tbody></table></div><div class="mobile-cards">' +
          cards +
          "</div>"
        );
      }
      function metricTable(rows) {
        var body = rows
          .map(function (row, index) {
            return (
              '<tr tabindex="0" data-action="metric" data-index="' +
              index +
              '"><td>' +
              esc(metricName(row)) +
              "</td><td>" +
              esc(roleLabel(row.role_code)) +
              '</td><td class="number">' +
              esc(row.sample_size || 0) +
              (row.low_sample ? " " + pill("sample_low") : "") +
              '</td><td class="number">' +
              percent(row.selection_rate) +
              '</td><td class="number">' +
              (row.target_status === "unset" ? "未设置" : formatNumber(row.period_target)) +
              '</td><td class="number">' +
              formatNumber(row.effective_value) +
              '</td><td class="number">' +
              (row.target_status === "unset" ? "未设置" : formatNumber(row.target_gap)) +
              "</td></tr>"
            );
          })
          .join("");
        var cards = rows
          .map(function (row, index) {
            return (
              '<article class="mobile-card"><button data-action="metric" data-index="' +
              index +
              '"><strong>' +
              esc(metricName(row)) +
              "</strong><p>选取率 " +
              percent(row.selection_rate) +
              " · 实际 " +
              formatNumber(row.effective_value) +
              " · 差额 " +
              (row.target_status === "unset" ? "未设置" : formatNumber(row.target_gap)) +
              "</p></button></article>"
            );
          })
          .join("");
        return (
          '<div class="table-wrap"><table class="table"><thead><tr><th>工作项目</th><th>岗位</th><th class="number">已提交日报数</th><th class="number">选取率</th><th class="number">目标</th><th class="number">实际</th><th class="number">差额</th></tr></thead><tbody>' +
          body +
          '</tbody></table></div><div class="mobile-cards">' +
          cards +
          "</div>"
        );
      }
      function trendSection(rows) {
        currentTrendRows = rows;
        return (
          '<section class="card"><div class="card-title">完成趋势<span class="card-subtitle">图表与文字表使用同一数据</span></div><p class="trend-summary" id="trendText">' +
          trendText(rows) +
          '</p><div class="trend-layout"><canvas class="trend-canvas" id="trendChart" height="220" role="img" aria-label="工作量完成率趋势图"></canvas><div>' +
          trendTable(rows) +
          "</div></div></section>"
        );
      }
      function trendText(rows) {
        if (!rows.length) return "当前周期暂无趋势数据。";
        var first = valueOf(rows[0].completion_rate),
          last = valueOf(rows[rows.length - 1].completion_rate),
          delta = Math.round((last - first) * 100);
        return (
          "周期从 " +
          percent(first) +
          " 变化到 " +
          percent(last) +
          "，" +
          (delta > 0 ? "上升 " : delta < 0 ? "下降 " : "保持 ") +
          Math.abs(delta) +
          " 个百分点；共 " +
          rows.length +
          " 个统计日。"
        );
      }
      function trendTable(rows) {
        return (
          '<div class="table-wrap"><table class="table" style="min-width:260px"><thead><tr><th>日期</th><th class="number">完成率</th></tr></thead><tbody>' +
          rows
            .map(function (row) {
              return (
                "<tr><td>" +
                esc(row.business_date || row.date) +
                '</td><td class="number">' +
                percent(row.completion_rate) +
                "</td></tr>"
              );
            })
            .join("") +
          "</tbody></table></div>"
        );
      }
      function drawVisibleTrend() {
        var canvas = byId("trendChart");
        if (!canvas || !currentPayload) return;
        var rows = currentTrendRows;
        var rect = canvas.getBoundingClientRect(),
          ratio = window.devicePixelRatio || 1;
        canvas.width = Math.max(300, rect.width) * ratio;
        canvas.height = 220 * ratio;
        var ctx = canvas.getContext("2d");
        ctx.scale(ratio, ratio);
        var width = canvas.width / ratio,
          height = 220,
          pad = 28;
        ctx.clearRect(0, 0, width, height);
        ctx.strokeStyle = "#e3ddd8";
        for (var grid = 0; grid <= 4; grid++) {
          var y = pad + ((height - pad * 2) * grid) / 4;
          ctx.beginPath();
          ctx.moveTo(pad, y);
          ctx.lineTo(width - pad, y);
          ctx.stroke();
        }
        if (!rows.length) return;
        ctx.strokeStyle = "#ff6b35";
        ctx.lineWidth = 3;
        ctx.beginPath();
        rows.forEach(function (row, index) {
          var x =
            rows.length === 1
              ? width / 2
              : pad + ((width - pad * 2) * index) / (rows.length - 1);
          var y =
            height - pad - valueOf(row.completion_rate) * (height - pad * 2);
          if (index === 0) ctx.moveTo(x, y);
          else ctx.lineTo(x, y);
        });
        ctx.stroke();
      }
      function renderStore(data) {
        var totals = completionTotals(data.store_summaries || []);
        byId("analyticsContent").innerHTML =
          '<div class="kpis">' +
          kpiCard(
            totals.required
              ? percent(totals.completed / totals.required)
              : "0%",
            "门店完成率",
            "统一义务分母",
            true,
          ) +
          kpiCard(totals.missing, "缺交总数", "含锁定缺交") +
           kpiCard(totals.weak, "需要跟进门店", "日报完成率低于 80%") +
          "</div>" +
          metadata(data) +
          '<section class="card"><div class="card-title">门店周期完成</div>' +
          storeTable(data.store_summaries || []) +
          "</section>" +
          trendSection(aggregateDailyCompletion(data.daily_trend || []));
      }
      function renderMetric(data) {
        var rows = data.project_summaries || [],
          pending = rows.reduce(function (sum, row) {
            return sum + Number(row.pending_value || 0);
          }, 0),
          low = rows.filter(function (row) {
            return row.low_sample;
          }).length;
        byId("analyticsContent").innerHTML =
          '<div class="kpis">' +
          kpiCard(rows.length, "项目数量", "当前筛选范围", true) +
          kpiCard(formatNumber(pending), "待审核值", "与有效值分开展示") +
          kpiCard(low, "样本不足项目", "谨慎解读建议") +
          conversionKpis(data.conversion_summary) +
          "</div>" +
          metadata(data) +
          '<section class="card"><div class="card-title">项目选取度与有效值</div>' +
          metricTable(rows) +
          "</section>";
      }
      function renderStaff(data) {
        var staff = data.staff || {},
          records = data.daily_records || [],
          submitted = records.filter(function (row) {
            return ["submitted", "corrected"].includes(row.completion_status);
          }).length,
          drafts = records.filter(function (row) {
            return row.completion_status === "draft";
          }).length;
        byId("analyticsContent").innerHTML =
          '<div class="kpis">' +
          kpiCard(
            staff.staff_name || staff.staff_id,
            "当前员工",
            staff.current_store_name || "",
            true,
          ) +
          kpiCard(records.length, "周期记录", "完成 " + submitted + " · 草稿 " + drafts) +
          kpiCard((data.summary || []).length, "适用项目", "含零值项目") +
          "</div>" +
          metadata(data) +
          '<section class="card"><div class="card-title">个人日报轨迹<span class="card-subtitle">草稿可查看，未计入排行</span></div>' +
          staffRecordsTable(records) +
          "</section>" +
          staffTrendSection(data.trend || []);
      }
      function staffRecordsTable(rows) {
        var body = rows
          .map(function (row, index) {
            return (
              '<tr tabindex="0" data-action="staff-record" data-index="' +
              index +
              '"><td>' +
              esc(row.business_date) +
              "</td><td>" +
              esc(row.store_name || row.store_id) +
              "</td><td>" +
              esc(roleLabel(row.role_code)) +
              "</td><td>" +
              pill(row.completion_status) +
              '</td><td class="number">' +
              esc((row.metrics || []).length) +
              "</td><td>" +
              esc((row.report || {}).submitted_at || "-") +
              "</td></tr>"
            );
          })
          .join("");
        var cards = rows
          .map(function (row, index) {
            return (
              '<article class="mobile-card"><button data-action="staff-record" data-index="' +
              index +
              '"><strong>' +
              esc(row.business_date) +
              " · " +
              esc(row.store_name || row.store_id) +
              "</strong><p>" +
              statusLabel(row.completion_status) +
              " · " +
              (row.metrics || []).length +
              " 个项目</p></button></article>"
            );
          })
          .join("");
        return (
          '<div class="table-wrap"><table class="table"><thead><tr><th>日期</th><th>门店</th><th>岗位</th><th>状态</th><th class="number">项目数</th><th>提交时间</th></tr></thead><tbody>' +
          body +
          '</tbody></table></div><div class="mobile-cards">' +
          cards +
          "</div>"
        );
      }
      function staffTrendSection(rows) {
        var flattened = rows.map(function (period) {
          var metric = (period.metrics || [])[0] || {},
            required = Number(metric.required_obligation_days || 0),
            completed = Number(metric.submitted_report_count || 0);
          return {
            business_date: period.period_key || period.date_from,
            completion_rate: {
              numerator: completed,
              denominator: required,
              value: required ? completed / required : 0,
            },
          };
        });
        return trendSection(flattened);
      }
      function renderMatrix(data) {
        var rows = data.matrix || [],
          summary = data.summary || {};
        byId("analyticsContent").innerHTML =
          '<div class="kpis">' +
          kpiCard(rows.length, "交叉单元", "主次维度组合", true) +
          kpiCard(
            formatNumber(summary.effective_value),
            "有效总值",
            "原始值 " + formatNumber(summary.raw_value),
          ) +
          kpiCard(
            percent(summary.completion_rate),
            "完成率",
            "覆盖率 " + percent(summary.coverage_rate),
          ) +
          "</div>" +
          metadata(data) +
          '<section class="card"><div class="card-title">全维度交叉矩阵<span class="card-subtitle">点击单元查看最细事实</span></div>' +
          matrixTable(rows) +
          "</section>";
      }
      function matrixTable(rows) {
        var body = rows
          .map(function (row, index) {
            return (
              '<tr tabindex="0" data-action="matrix" data-index="' +
              index +
              '"><td>' +
              esc((row.primary || {}).label || (row.primary || {}).value) +
              "</td><td>" +
              esc((row.secondary || {}).label || (row.secondary || {}).value) +
              '</td><td class="number">' +
              formatNumber(row.raw_value) +
              '</td><td class="number">' +
              formatNumber(row.pending_value) +
              '</td><td class="number">' +
              formatNumber(row.effective_value) +
              '</td><td class="number">' +
              percent(row.completion_rate) +
              '</td><td class="number">' +
              percent(row.coverage_rate) +
              "</td></tr>"
            );
          })
          .join("");
        var cards = rows
          .map(function (row, index) {
            return (
              '<article class="mobile-card"><button data-action="matrix" data-index="' +
              index +
              '"><strong>' +
              esc((row.primary || {}).label) +
              " × " +
              esc((row.secondary || {}).label) +
              "</strong><p>有效值 " +
              formatNumber(row.effective_value) +
              " · 完成率 " +
              percent(row.completion_rate) +
              "</p></button></article>"
            );
          })
          .join("");
        return (
          '<div class="table-wrap"><table class="table"><thead><tr><th>主维度</th><th>次维度</th><th class="number">原始值</th><th class="number">待审核</th><th class="number">有效值</th><th class="number">完成率</th><th class="number">覆盖率</th></tr></thead><tbody>' +
          body +
          '</tbody></table></div><div class="mobile-cards">' +
          cards +
          "</div>"
        );
      }
      function renderAuditQueue(data) {
        var rows = data.list || [];
        var pending = rows.filter(function (row) {
          return row.audit_status === "pending";
        }).length;
        byId("analyticsContent").innerHTML =
          '<div class="action-bar"><p>审核任务按版本串联，凭证、意见与每次状态变化完整留痕。</p><span>' +
          esc((data.pagination || {}).total || rows.length) +
          " 条任务</span></div>" +
          '<div class="kpis">' +
          kpiCard(pending, "待审核", "需要处理", true) +
          kpiCard(
            rows.filter(function (row) {
              return row.audit_status === "needs_resubmit";
            }).length,
            "待补凭证",
            "等待员工补充",
          ) +
          kpiCard(
            rows.filter(function (row) {
              return row.audit_status === "rejected";
            }).length,
            "已驳回",
            "保留历史版本",
          ) +
          "</div>" +
          '<section class="card"><div class="card-title">审核任务与版本轨迹</div><div class="table-wrap"><table class="table"><thead><tr><th>员工 / 门店</th><th>项目</th><th>版本</th><th>提交值</th><th>凭证</th><th>状态</th><th>操作</th></tr></thead><tbody>' +
          rows
            .map(function (row, index) {
              var evidenceCount = (row.evidence_urls || []).length;
              return (
                "<tr><td>" +
                esc(row.staff_name || row.staff_id) +
                "<br><small>" +
                esc(row.store_name || row.store_id) +
                "</small></td><td>" +
                esc(metricName(row)) +
                "</td><td>第 " +
                esc(row.task_version || 1) +
                " 版" +
                "</td><td>" +
                formatNumber(row.submitted_value) +
                "</td><td>" +
                evidenceCount +
                " 张</td><td>" +
                pill(row.audit_status) +
                '</td><td><button class="btn" data-business="audit-open" data-index="' +
                index +
                '">查看处理</button></td></tr>'
              );
            })
            .join("") +
          '</tbody></table></div><div class="mobile-cards">' +
          rows
            .map(function (row, index) {
              return (
                '<article class="mobile-card"><button data-business="audit-open" data-index="' +
                index +
                '"><strong>' +
                esc(row.staff_name || row.staff_id) +
                " · " +
                esc(metricName(row)) +
                "</strong><p>" +
                auditStatusLabel(row.audit_status) +
                " · 第 " +
                esc(row.task_version || 1) +
                " 版" +
                "</p></button></article>"
              );
            })
            .join("") +
          "</div></section>";
      }
      function renderOperatingFunnel(data) {
        var funnel = data.sales_funnel || {};
        var stages = funnel.stages || [];
        var relations = [].concat(
          funnel.conversion_rates || [],
          (data.coach_plan_completion || {}).rates || [],
          data.configured_conversions || [],
        );
        var version = data.relation_version || {};
        byId("analyticsContent").innerHTML =
          '<div class="action-bar"><p>经营关系 <strong>' +
          esc(versionName(version.version_code)) +
          "</strong> · 生效 " +
          esc(version.effective_from || "-") +
          " 至 " +
          esc(version.effective_to || "长期") +
          "</p><span>" +
          esc(version.description || "版本化经营关系") +
          "</span></div>" +
          '<section class="card"><div class="card-title">销售五阶段漏斗</div><div class="funnel-track">' +
          stages
            .map(function (stage, index) {
              return (
                '<button class="funnel-stage" data-business="funnel-drill" data-kind="stage" data-index="' +
                index +
                '"><span>' +
                esc(metricName(stage)) +
                "</span><strong>" +
                formatNumber(stage.effective_value) +
                "</strong><small>样本 " +
                esc(stage.sample_size || 0) +
                (stage.low_sample ? " · 样本不足" : "") +
                "</small></button>"
              );
            })
            .join("") +
          '</div></section><section class="card"><div class="card-title">转化与计划完成关系</div><div class="table-wrap"><table class="table"><thead><tr><th>关系</th><th>岗位</th><th>分子 / 分母</th><th>有效转化率</th><th>样本</th><th>状态</th></tr></thead><tbody>' +
          relations
            .map(function (relation, index) {
              var ratio = relation.effective_rate || {};
              return (
                '<tr tabindex="0" data-business="funnel-drill" data-kind="relation" data-index="' +
                index +
                '"><td>' +
                esc(relationName(relation)) +
                "</td><td>" +
                esc(roleLabel(relation.role_code)) +
                "</td><td>" +
                esc(
                    metricName(relation.numerator_metric || {}),
                ) +
                " / " +
                esc(
                    metricName(relation.denominator_metric || {}),
                ) +
                "</td><td>" +
                (ratio.state === "comparable"
                  ? percent(ratio)
                  : esc(relationStateLabel(ratio.state))) +
                "</td><td>" +
                esc(relation.sample_size || 0) +
                "</td><td>" +
                (relation.has_pending_review ? "含待审核值" : "口径稳定") +
                "</td></tr>"
              );
            })
            .join("") +
          "</tbody></table></div></section>";
        currentPayload[0]._all_relations = relations;
      }
      function renderAlerts(data) {
        var rows = data.list || [];
        byId("analyticsContent").innerHTML =
          '<div class="action-bar"><p>预警展示触发规则、事实证据和影响范围，处理意见最多 500 字。</p><span>' +
          esc((data.pagination || {}).total || rows.length) +
          ' 条预警</span></div><div class="timeline">' +
          rows
            .map(function (row, index) {
              var factEvidence = Object.keys(row.evidence || {})
                .map(function (key) {
                  var value = row.evidence[key];
                  if (Array.isArray(value)) value = value.join("、");
                  else if (value && typeof value === "object")
                    value = JSON.stringify(value);
                  return key + "：" + String(value == null ? "" : value);
                })
                .join("；");
              return (
                '<article class="card timeline-item"><div class="action-bar"><div><div class="card-title">' +
                esc(row.alert_title || "工作量预警") +
                "</div><p>" +
                esc(row.business_date) +
                " · " +
                esc(row.store_name || row.store_id) +
                " · " +
                esc(row.staff_name || row.staff_id) +
                "</p></div>" +
                pill(row.status) +
                "</div><p><strong>规则依据：</strong>" +
                esc(row.rule_basis || "按系统预警规则触发") +
                "</p><p><strong>影响范围：</strong>" +
                esc(row.impact_scope || roleLabel(row.role_code) || "已授权范围") +
                "</p><p><strong>事实证据：</strong>" +
                esc(factEvidence || "详见明细数据") +
                '</p><div class="actions"><button class="btn" data-business="alert-open" data-index="' +
                index +
                '">' +
                (row.status === "open" ? "处理预警" : "查看处理") +
                "</button></div></article>"
              );
            })
            .join("") +
          "</div>";
      }
      function renderStandards(data) {
        var rows = data.list || [];
        byId("analyticsContent").innerHTML =
          '<div class="action-bar"><p>每个岗位按生效时间形成独立版本，历史日报持续绑定原版本。</p><button class="btn primary" data-business="standard-create">新建草稿</button></div><div class="timeline">' +
          rows
            .map(function (row, index) {
              return (
                '<article class="card timeline-item"><div class="action-bar"><div><div class="card-title">' +
                esc(row.position_name || roleLabel(row.role_code)) +
                " · " +
                esc(versionName(row.version_code)) +
                "</div><p>" +
                esc(row.effective_from) +
                " 至 " +
                esc(row.effective_to || "长期") +
                " · " +
                esc(row.description || "") +
                "</p></div>" +
                pill(row.status) +
                '</div><div class="actions"><button class="btn" data-business="standard-detail" data-index="' +
                index +
                '">详情与项目</button><button class="btn" data-business="standard-copy" data-index="' +
                index +
                '">复制草稿</button>' +
                (row.status === "draft"
                  ? '<button class="btn primary" data-business="standard-publish" data-index="' +
                    index +
                    '">差异预览并发布</button><button class="btn" data-business="standard-delete" data-index="' +
                    index +
                    '">删除草稿</button>'
                  : '<button class="btn" data-business="standard-disable" data-index="' +
                    index +
                    '">设置截止日期</button>') +
                "</div></article>"
              );
            })
            .join("") +
          "</div>";
      }
      function renderImports(data) {
        var rows = data.list || [];
        byId("analyticsContent").innerHTML =
          '<section class="card"><div class="action-bar"><div><div class="card-title">批量导入岗位标准</div><p>支持表格文件，上传后先预检差异，再确认生成草稿或发布。</p></div><form id="standardImportForm"><input id="standardImportFile" name="file" type="file" accept=".csv,.xlsx" required><button class="btn primary" type="submit">上传并预检</button></form></div></section>' +
          '<section class="card"><div class="card-title">导入批次</div><div class="table-wrap"><table class="table"><thead><tr><th>文件</th><th>状态</th><th>岗位</th><th>有效 / 错误</th><th>创建时间</th><th>操作</th></tr></thead><tbody>' +
          rows
            .map(function (row, index) {
              return (
                "<tr><td>" +
                esc(row.file_name) +
                "</td><td>" +
                 esc(importStatusLabel(row.status)) +
                "</td><td>" +
                esc(row.role_count) +
                "</td><td>" +
                esc(row.valid_rows) +
                " / " +
                esc(row.error_rows) +
                "</td><td>" +
                esc(row.created_at) +
                '</td><td><button class="btn" data-business="import-detail" data-index="' +
                index +
                '">差异详情</button>' +
                (["preflight_ready", "preflight_has_errors"].includes(
                  row.status,
                )
                  ? '<button class="btn primary" data-business="import-confirm" data-index="' +
                    index +
                    '">确认草稿</button>'
                  : "") +
                "</td></tr>"
              );
            })
            .join("") +
          "</tbody></table></div></section>";
      }
      function idempotencyKey(prefix) {
        return (
          prefix + "-" + Date.now() + "-" + Math.random().toString(36).slice(2)
        );
      }
      async function apiMutation(url, body, key) {
        var headers = {
          "Content-Type": "application/json",
          "Idempotency-Key": key || idempotencyKey("workload"),
        };
        if (window.AppAuth && AppAuth.authHeaders)
          headers = AppAuth.authHeaders(headers);
        var response = await fetch(url, {
          method: "POST",
          headers: headers,
          body: JSON.stringify(body || {}),
        });
        var json = await response.json();
        if (!response.ok || Number(json.code) !== 0)
          throw new Error(json.message || "操作失败");
        return json.data;
      }
      function actionForm(title, description, actions, initialComment) {
        openDrawer(
          title,
          '<form class="inline-form" id="businessActionForm"><div>' +
            description +
            '</div><label for="actionComment">处理意见</label><textarea id="actionComment" maxlength="500" placeholder="填写处理依据或后续安排">' +
            esc(initialComment || "") +
            '</textarea><div class="field-error" id="actionError"></div><div class="actions">' +
            actions +
            "</div></form>",
          document.activeElement,
        );
      }
      function bindBusinessActions() {
        byId("analyticsContent")
          .querySelectorAll("[data-business]")
          .forEach(function (button) {
            button.addEventListener("click", handleBusinessAction);
            button.addEventListener("keydown", function (event) {
              if (event.key === "Enter" || event.key === " ") {
                event.preventDefault();
                handleBusinessAction.call(button);
              }
            });
          });
        var importForm = byId("standardImportForm");
        if (importForm)
          importForm.addEventListener("submit", uploadStandardFile);
      }
      async function handleBusinessAction() {
        var action = this.dataset.business;
        var index = Number(this.dataset.index || 0);
        var row = (currentPayload[0].list || [])[index];
        try {
          if (action === "audit-open") return openAuditAction(row);
          if (action === "alert-open") return openAlertAction(row);
          if (action === "penalty-action")
            return openPenaltyAction(row, this.dataset.penaltyAction);
          if (action === "funnel-drill")
            return openFunnelDrill(this.dataset.kind, index);
          if (action === "standard-detail") return openStandardDetail(row);
          if (action === "standard-create") return openStandardEditor();
          if (action === "standard-copy") {
            await apiMutation(
              "/api/admin/workload/standard-copy.php",
              { source_version_id: row.id, effective_from: today() },
              idempotencyKey("standard-copy"),
            );
          } else if (action === "standard-publish") {
            return openStandardPublish(row);
          } else if (action === "standard-disable") {
            await apiMutation(
              "/api/admin/workload/standard-disable.php",
              { version_id: row.id, effective_to: today() },
              idempotencyKey("standard-disable"),
            );
          } else if (action === "standard-delete") {
            if (!window.confirm("确认删除这个未引用草稿？")) return;
            await apiMutation(
              "/api/admin/workload/standard-delete.php",
              { version_id: row.id },
              idempotencyKey("standard-delete"),
            );
          } else if (action === "import-detail") return openImportDetail(row);
          else if (action === "import-confirm") {
            await apiMutation(
              "/api/admin/workload/standard-import-batches.php",
              {
                batch_id: row.id,
                action: "confirm",
                publish: false,
                effective_from: today(),
              },
              idempotencyKey("standard-import-confirm"),
            );
          }
          byId("liveStatus").textContent = "操作成功";
          loadData();
        } catch (error) {
          setAsyncState("error", error);
        }
      }
      function openPenaltyAction(row, action) {
        var label = penaltyActionLabel(action);
        actionForm(
          label,
          "员工：<strong>" +
            esc(row.staff_name || row.staff_id) +
            "</strong><br>营业日：" +
            esc(row.business_date) +
            "<br>处罚金额：¥" +
            esc(formatNumber(row.penalty_amount)) +
            "<br>" +
            esc(row.next_action || "请填写处理原因"),
          '<button class="btn primary" type="submit">' + esc(label) + "</button>",
          "",
        );
        byId("businessActionForm").addEventListener("submit", async function (event) {
          event.preventDefault();
          var reason = byId("actionComment").value.trim();
          if (!reason) {
            byId("actionError").textContent = "请填写处理原因";
            byId("actionComment").focus();
            return;
          }
          try {
            await apiMutation(
              "/api/admin/workload/penalty-action.php",
              { penalty_id: row.penalty_id, action: action, reason: reason },
              idempotencyKey("penalty-" + action),
            );
            closeDrawer();
            byId("liveStatus").textContent = label + "成功";
            loadData();
          } catch (error) {
            byId("actionError").textContent = error.message || "处罚处理失败";
          }
        });
      }
      function openAuditAction(row) {
        var evidence =
          (row.evidence_urls || [])
            .map(function (url, index) {
              return (
                '<button type="button" class="evidence-button" data-evidence-url="' +
                esc(url) +
                '" aria-label="预览第 ' +
                (index + 1) +
                ' 张审核凭证"><img src="' +
                esc(url) +
                '" alt="第 ' +
                (index + 1) +
                ' 张审核凭证缩略图"></button>'
              );
            })
            .join("") || "<p>无凭证</p>";
        var logs =
          (row.audit_logs || [])
            .map(function (log) {
              return (
                '<div class="timeline-item"><strong>' +
                esc(auditStatusLabel(log.before_status)) +
                " → " +
                esc(auditStatusLabel(log.after_status)) +
                "</strong><p>" +
                esc(log.operator_name || log.operator_staff_id) +
                " · " +
                esc(log.created_at) +
                " · " +
                esc(log.comment || "无意见") +
                "</p></div>"
              );
            })
            .join("") || "<p>暂无历史动作</p>";
        actionForm(
          metricName(row) + " · 审核处理",
          "<strong>提交值：</strong>" +
            formatNumber(row.submitted_value) +
            '<p><strong>换算：</strong>有效 ' +
            formatNumber((row.conversion_summary || {}).effective_points) +
            ' 点，待审核 ' +
            formatNumber((row.conversion_summary || {}).pending_points) +
            ' 点，驳回 ' +
            formatNumber((row.conversion_summary || {}).rejected_points) +
            ' 点</p><div class="timeline">' +
            (row.conversion_results || []).map(function (result) {
              return '<div class="timeline-item"><strong>' +
                esc(result.rule_code || '工作量规则') +
                '</strong><p>' + esc(result.rule_version_code || '历史规则快照') +
                ' · ' + esc(result.explanation || '') + '</p></div>';
            }).join('') +
            '</div>' +
            '<div class="evidence-grid">' +
            evidence +
            '</div><div class="timeline">' +
            logs +
            "</div>",
          '<button class="btn primary" type="button" data-submit-audit="approved">通过</button><button class="btn" type="button" data-submit-audit="rejected">驳回</button><button class="btn" type="button" data-submit-audit="needs_resubmit">要求补凭证</button>',
          row.audit_comment,
        );
        bindEvidenceButtons();
        byId("businessActionForm")
          .querySelectorAll("[data-submit-audit]")
          .forEach(function (button) {
            button.addEventListener("click", async function () {
              var status = button.dataset.submitAudit,
                comment = byId("actionComment").value.trim();
              if (["rejected", "needs_resubmit"].includes(status) && !comment) {
                byId("actionError").textContent =
                  "驳回或要求补凭证时必须填写处理意见";
                byId("actionComment").focus();
                return;
              }
              await apiMutation(
                "/api/workload/audit-action.php",
                { task_id: row.id, action: status, comment: comment },
                idempotencyKey("audit"),
              );
              closeDrawer();
              loadData();
            });
          });
      }
      function openAlertAction(row) {
        actionForm(
          row.alert_title || "预警处理",
          "规则依据：" +
            esc(row.rule_basis || "按系统预警规则触发") +
            "；影响范围：" +
            esc(row.impact_scope || roleLabel(row.role_code) || "已授权范围"),
          row.status === "open"
            ? '<button class="btn primary" type="button" id="resolveAlertBtn">标记已处理</button>'
            : "",
          row.handler_comment,
        );
        var button = byId("resolveAlertBtn");
        if (button)
          button.addEventListener("click", async function () {
            var comment = byId("actionComment").value.trim();
            if (!comment) {
              byId("actionError").textContent = "请填写处理意见";
              byId("actionComment").focus();
              return;
            }
            await apiMutation(
              "/api/workload/alerts.php",
              {
                action: "resolve",
                event_id: row.id,
                comment: comment,
              },
              idempotencyKey("alert-resolve"),
            );
            closeDrawer();
            loadData();
          });
      }
      function openFunnelDrill(kind, index) {
        var source =
          kind === "stage"
            ? (currentPayload[0].sales_funnel || {}).stages[index]
            : currentPayload[0]._all_relations[index];
        var drills =
          kind === "stage"
            ? [source.drilldown]
            : [
                source.numerator_metric.drilldown,
                source.denominator_metric.drilldown,
              ];
        openDrawer(
              metricName(source) || relationName(source) || "漏斗明细",
          '<div class="actions">' +
            drills
              .map(function (drill, drillIndex) {
                return (
                  '<button class="btn" data-funnel-detail="' +
                  drillIndex +
                  '">查看' +
                  (drillIndex ? "对比项" : "主项目") +
                  "事实</button>"
                );
              })
              .join("") +
            '</div><div id="funnelDetail"><p>选择一侧查看最细事实。</p></div>',
          document.activeElement,
        );
        byId("drawerContent")
          .querySelectorAll("[data-funnel-detail]")
          .forEach(function (button) {
            button.addEventListener("click", async function () {
              var drill = drills[Number(button.dataset.funnelDetail)],
                params = Object.assign({}, filters(), drill.params || {});
              var response = await ApiClient.get(
                (drill.endpoint ||
                  "/api/workload/analytics/metric-detail.php") +
                  "?" +
                  queryString(params),
                { timeout: 15000 },
              );
              byId("funnelDetail").innerHTML =
                (response.data.rows || [])
                  .map(function (item) {
                    return (
                      '<div class="timeline-item"><strong>' +
                      esc(item.business_date) +
                      " · " +
                      esc(item.store_name || item.store_id) +
                      "</strong><p>" +
                      esc(item.staff_name || item.staff_id) +
                      " · 有效值 " +
                      formatNumber(item.effective_value) +
                      "</p></div>"
                    );
                  })
                  .join("") || "<p>暂无事实明细</p>";
            });
          });
      }
      async function openStandardDetail(row) {
        var response = await ApiClient.get(
          "/api/admin/workload/standards.php?id=" + encodeURIComponent(row.id),
        );
        var standard = response.data;
        var editable = standard.status === "draft";
        var itemRows = (standard.items || [])
          .map(function (item, index) {
            return (
              '<div class="timeline-item"><strong>' +
              esc(
                item.metric_name_snapshot ||
                  item.metric_name ||
                  "",
              ) +
              "</strong><p>" +
               esc(item.unit_snapshot || item.unit || "") +
               " · " +
               esc(auditModeLabel(item.audit_mode)) +
              (item.is_required ? " · 必填" : " · 选填") +
              " · 排序 " +
              esc(item.sort_order) +
              "</p>" +
              (editable
                ? '<div class="actions"><button class="btn" type="button" data-standard-item-edit="' +
                  index +
                  '">编辑</button><button class="btn" type="button" data-standard-item-remove="' +
                  index +
                  '">移除</button></div>'
                : "") +
              "</div>"
            );
          })
          .join("");
        var editor = editable
          ? '<div class="drawer-section"><div class="drawer-section-title">手工项目编辑</div><form class="inline-form" id="standardItemForm"><input type="hidden" id="standardItemId"><div class="filter-grid"><label>项目简称<input id="standardMetricCode" required pattern="[a-z0-9][a-z0-9_-]{0,63}"></label><label>项目名称<input id="standardMetricName" required maxlength="100"></label><label>单位<input id="standardMetricUnit" maxlength="32"></label><label>数值类型<select id="standardValueType"><option value="number">数值</option><option value="integer">整数</option><option value="decimal">小数</option><option value="percentage">百分比</option><option value="currency">金额</option></select></label><label>审核方式<select id="standardAuditMode"><option value="none">无需审核</option><option value="manual">人工审核</option><option value="evidence">凭证审核</option></select></label><label>统计方向<select id="standardDirection"><option value="higher">越高越好</option><option value="lower">越低越好</option><option value="neutral">中性</option></select></label><label>展示顺序<input type="number" min="0" id="standardSortOrder" value="0"></label><label><input type="checkbox" id="standardRequired"> 必填</label><label><input type="checkbox" id="standardAllowZero" checked> 允许零值</label><label><input type="checkbox" id="standardNeedEvidence"> 需要凭证</label></div><div class="actions"><button class="btn primary" type="submit">保存项目</button><button class="btn" type="button" id="resetStandardItemBtn">清空表单</button></div></form></div>'
          : "";
        openDrawer(
          versionName(standard.version_code) + " · 项目详情",
          '<div class="meta-strip"><div class="meta-item">状态<strong>' +
            esc(statusLabel(standard.status)) +
            '</strong></div><div class="meta-item">最低正值项目<strong>' +
            esc(standard.minimum_positive_metrics) +
            '</strong></div><div class="meta-item">生效日期<strong>' +
            esc(standard.effective_from) +
            '</strong></div></div><div class="timeline">' +
            itemRows +
            "</div>" +
            editor +
            '<div class="drawer-section"><div class="drawer-section-title">版本差异</div><p>' +
            esc(differenceSummary(standard.difference)) +
            "</p></div>",
          document.activeElement,
        );
        if (editable) bindStandardItemEditor(standard);
      }
      function standardItemPayload(versionId) {
        return {
          version_id: versionId,
          action: "upsert",
          item_id: Number(byId("standardItemId").value || 0),
          metric_code: byId("standardMetricCode").value.trim(),
          metric_name: byId("standardMetricName").value.trim(),
          unit: byId("standardMetricUnit").value.trim(),
          value_type: byId("standardValueType").value,
          audit_mode: byId("standardAuditMode").value,
          statistic_direction: byId("standardDirection").value,
          sort_order: Number(byId("standardSortOrder").value || 0),
          is_required: byId("standardRequired").checked,
          allow_zero: byId("standardAllowZero").checked,
          need_evidence: byId("standardNeedEvidence").checked,
          min_evidence_count: 0,
          max_evidence_count: 10,
        };
      }
      function bindStandardItemEditor(standard) {
        var form = byId("standardItemForm");
        function reset() {
          form.reset();
          byId("standardItemId").value = "";
          byId("standardAllowZero").checked = true;
          byId("standardSortOrder").value = "0";
        }
        form.addEventListener("submit", async function (event) {
          event.preventDefault();
          await apiMutation(
            "/api/admin/workload/standard-items.php",
            standardItemPayload(standard.id),
            idempotencyKey("standard-item-upsert"),
          );
          closeDrawer();
          loadData();
        });
        byId("resetStandardItemBtn").addEventListener("click", reset);
        byId("drawerContent")
          .querySelectorAll("[data-standard-item-edit]")
          .forEach(function (button) {
            button.addEventListener("click", function () {
              var item =
                standard.items[Number(button.dataset.standardItemEdit)];
              byId("standardItemId").value = item.id;
              byId("standardMetricCode").value = item.metric_code || "";
              byId("standardMetricName").value =
                item.metric_name_snapshot || item.metric_name || "";
              byId("standardMetricUnit").value =
                item.unit_snapshot || item.unit || "";
              byId("standardValueType").value =
                item.value_type_snapshot || item.value_type || "number";
              byId("standardAuditMode").value = item.audit_mode || "none";
              byId("standardDirection").value =
                item.statistic_direction || "higher";
              byId("standardSortOrder").value = item.sort_order || 0;
              byId("standardRequired").checked = Boolean(item.is_required);
              byId("standardAllowZero").checked = Boolean(item.allow_zero);
              byId("standardNeedEvidence").checked = Boolean(
                item.need_evidence,
              );
              byId("standardMetricCode").focus();
            });
          });
        byId("drawerContent")
          .querySelectorAll("[data-standard-item-remove]")
          .forEach(function (button) {
            button.addEventListener("click", async function () {
              var item =
                standard.items[Number(button.dataset.standardItemRemove)];
              if (
                !window.confirm(
                  "确认从草稿移除项目“" +
                    metricName(item) +
                    "”？",
                )
              )
                return;
              await apiMutation(
                "/api/admin/workload/standard-items.php",
                { version_id: standard.id, action: "remove", item_id: item.id },
                idempotencyKey("standard-item-remove"),
              );
              closeDrawer();
              loadData();
            });
          });
      }
      function openStandardEditor() {
        openDrawer(
          "新建岗位标准草稿",
          '<form class="inline-form" id="standardEditor"><label>岗位<select id="standardRole"><option value="sales">销售</option><option value="coach">教练</option><option value="manager">店长</option><option value="operation">运营</option></select></label><label>生效日期<input type="date" id="standardEffectiveFrom" value="' +
            today() +
            '"></label><label>最低正值项目数<input type="number" min="0" id="standardMinimum" value="0"></label><label>说明<textarea id="standardDescription" maxlength="500"></textarea></label><button class="btn primary" type="submit">创建草稿</button></form>',
          document.activeElement,
        );
        byId("standardEditor").addEventListener(
          "submit",
          async function (event) {
            event.preventDefault();
            await apiMutation(
              "/api/admin/workload/standards.php",
              {
                role_code: byId("standardRole").value,
                effective_from: byId("standardEffectiveFrom").value,
                minimum_positive_metrics: Number(byId("standardMinimum").value),
                requires_daily_report: true,
                description: byId("standardDescription").value,
              },
              idempotencyKey("standard-create"),
            );
            closeDrawer();
            loadData();
          },
        );
      }
      async function openStandardPublish(row) {
        var response = await ApiClient.get(
          "/api/admin/workload/standards.php?id=" + encodeURIComponent(row.id),
        );
        var standard = response.data;
        actionForm(
          "发布 " + versionName(standard.version_code),
          "差异预览：" + esc(differenceSummary(standard.difference)),
          '<button class="btn primary" type="button" id="confirmPublishBtn">确认发布</button>',
          "",
        );
        byId("confirmPublishBtn").addEventListener("click", async function () {
          await apiMutation(
            "/api/admin/workload/standard-publish.php",
            { version_id: row.id },
            idempotencyKey("standard-publish"),
          );
          closeDrawer();
          loadData();
        });
      }
      async function openImportDetail(row) {
        var response = await ApiClient.get(
          "/api/admin/workload/standard-import-batches.php?id=" +
            encodeURIComponent(row.id),
        );
        var batch = response.data;
        openDrawer(
          batch.file_name + " · 导入差异",
          '<div class="meta-strip"><div class="meta-item">有效行<strong>' +
            esc(batch.valid_rows) +
            '</strong></div><div class="meta-item">错误行<strong>' +
            esc(batch.error_rows) +
            '</strong></div><div class="meta-item">状态<strong>' +
            esc(batch.status) +
            '</strong></div></div><div class="timeline">' +
            ((batch.summary || {}).roles || [])
              .map(function (role) {
                return (
                  '<div class="timeline-item"><strong>' +
                  esc(role.position_name || roleLabel(role.role_code)) +
                  "</strong><p>新增 " +
                  esc((role.counts || {}).added || 0) +
                  " · 修改 " +
                  esc((role.counts || {}).modified || 0) +
                  " · 停用 " +
                  esc((role.counts || {}).disabled || 0) +
                  " · 错误 " +
                  esc(role.error_rows || 0) +
                  "</p></div>"
                );
              })
              .join("") +
            "</div>",
          document.activeElement,
        );
      }
      async function uploadStandardFile(event) {
        event.preventDefault();
        var input = byId("standardImportFile");
        if (!input.files.length) {
          input.focus();
          return;
        }
        var headers = { "Idempotency-Key": idempotencyKey("standard-import") };
        if (window.AppAuth && AppAuth.authHeaders)
          headers = AppAuth.authHeaders(headers);
        var form = new FormData();
        form.append("file", input.files[0]);
        try {
          var response = await fetch(
            "/api/admin/workload/standard-import.php",
            { method: "POST", headers: headers, body: form },
          );
          var json = await response.json();
          if (!response.ok || Number(json.code) !== 0)
            throw new Error(json.message || "导入预检失败");
          loadData();
        } catch (error) {
          setAsyncState("error", error);
        }
      }
      function bindRowActions() {
        byId("analyticsContent")
          .querySelectorAll("[data-action]")
          .forEach(function (element) {
            element.addEventListener("click", activateRow);
            element.addEventListener("keydown", function (event) {
              if (event.key === "Enter" || event.key === " ") {
                event.preventDefault();
                activateRow.call(element, event);
              }
            });
          });
      }
      function activateRow() {
        var action = this.dataset.action,
          index = Number(this.dataset.index),
          data = currentPayload[0];
        if (action === "store")
          openStoreDetail((data.store_summaries || [])[index]);
        if (action === "metric")
          openMetricDetail(
            ((currentView === "dashboard" ? currentPayload[1] : data)
              .project_summaries || [])[index],
          );
        if (action === "staff-record")
          openStaffRecord((data.daily_records || [])[index]);
        if (action === "matrix") openMatrixDetail((data.matrix || [])[index]);
      }
      function openDrawer(title, html, trigger) {
        drawerReturnFocus = trigger || document.activeElement;
        byId("drawerTitle").textContent = title;
        byId("drawerContent").innerHTML = html;
        byId("drawerOverlay").classList.add("show");
        byId("detailDrawer").classList.add("show");
        document.body.style.overflow = "hidden";
        requestAnimationFrame(function () {
          byId("closeDrawerBtn").focus();
        });
        bindEvidenceButtons();
        bindStaffProfileButtons();
      }
      function closeDrawer() {
        byId("drawerOverlay").classList.remove("show");
        byId("detailDrawer").classList.remove("show");
        document.body.style.overflow = "";
        if (drawerReturnFocus && drawerReturnFocus.focus)
          drawerReturnFocus.focus();
        drawerReturnFocus = null;
      }
      function openStoreDetail(row) {
        var details =
          (currentView === "dashboard" ? currentPayload[0] : currentPayload[0])
            .status_details || [];
        details = details.filter(function (item) {
          return String(item.store_id) === String(row.store_id);
        });
        openDrawer(
          (row.store_name || "门店") + " · 完成明细",
             '<div class="drawer-section"><div class="drawer-section-title">数据怎么算</div><p>' +
            esc(filterScopeText()) +
            '</p><p>按应交日报义务统计，每名员工每天每个岗位计 1 条；草稿可查看，未计入完成率和排行。</p></div>' +
            '<div class="drawer-section"><div class="drawer-section-title">状态摘要</div>' +
            Object.keys(row.status_counts || {})
              .map(function (key) {
                return pill(key) + " " + esc(row.status_counts[key]) + " ";
              })
              .join("") +
            "</div>" +
            details
              .map(function (item) {
                return (
                  '<div class="drawer-section"><strong>' +
                  esc(item.business_date) +
                  " · " +
                  '<button type="button" class="staff-link" data-staff-id="' +
                  esc(item.staff_id) +
                  '" data-staff-name="' +
                  esc(item.staff_name || item.staff_id) +
                  '">' +
                  esc(item.staff_name || item.staff_id) +
                  '</button>' +
                  "</strong><p>" +
                  esc(roleLabel(item.role_code)) +
                  " " +
                  pill(item.completion_status) +
                  "</p></div>"
                );
              })
              .join(""),
          document.activeElement,
        );
      }
      function openMetricDetail(row) {
        openDrawer(
          metricName(row) + " · 项目明细",
          '<div class="drawer-section"><div class="drawer-section-title">四值</div><p>原始 ' +
            formatNumber(row.raw_value) +
            " · 待审核 " +
            formatNumber(row.pending_value) +
            " · 有效 " +
            formatNumber(row.effective_value) +
            " · 驳回 " +
            formatNumber(row.rejected_value) +
            '</p></div><div class="drawer-section"><div class="drawer-section-title">比例口径</div><p>选取率 ' +
            percent(row.selection_rate) +
            "，有效选取率 " +
            percent(row.effective_selection_rate) +
            "，员工覆盖率 " +
            percent(row.staff_coverage) +
            "，门店覆盖率 " +
            percent(row.store_coverage) +
            '</p></div><div class="drawer-section"><div class="drawer-section-title">统计样本</div><p>' +
            esc(row.sample_size || 0) +
            " 份已提交日报" +
            (row.low_sample ? "，样本不足，仅供参考" : "") +
            "</p></div>",
          document.activeElement,
        );
      }
      function evidenceHtml(metrics) {
        var evidences = [];
        (metrics || []).forEach(function (metric) {
          (metric.evidences || []).forEach(function (item) {
            evidences.push(item);
          });
        });
        if (!evidences.length) return "<p>无凭证</p>";
        return (
          '<div class="evidence-grid">' +
          evidences
            .map(function (item, index) {
              var url = item.file_url || item.url || "";
              return (
                '<button class="evidence-button" data-evidence-url="' +
                esc(url) +
                '" aria-label="预览第 ' +
                (index + 1) +
                ' 张工作量凭证"><img src="' +
                esc(url) +
                '" alt="第 ' +
                (index + 1) +
                ' 张工作量凭证缩略图"></button>'
              );
            })
            .join("") +
          "</div>"
        );
      }
      function openStaffRecord(row) {
        openDrawer(
          row.business_date + " · " + (row.store_name || "日报"),
          '<div class="drawer-section">' +
            pill(row.completion_status) +
            " <span>" +
            esc(roleLabel(row.role_code)) +
            '</span></div><div class="drawer-section"><div class="drawer-section-title">项目值</div>' +
            (row.metrics || [])
              .map(function (metric) {
                return (
                  "<p><strong>" +
                  esc(metricName(metric)) +
                  "</strong> 原始 " +
                  formatNumber(metric.raw_value) +
                  " · 有效 " +
                  formatNumber(metric.effective_value) +
                  " · " +
                  auditStatusLabel(metric.audit_status) +
                  "</p>"
                );
              })
              .join("") +
            '</div><div class="drawer-section"><div class="drawer-section-title">凭证图片</div>' +
            evidenceHtml(row.metrics) +
            "</div>",
          document.activeElement,
        );
      }
      function openStaffProfile(staffId, staffName, trigger) {
        if (!/^\d+$/.test(String(staffId || ""))) return;
        byId("staffId").value = String(staffId);
        byId("staffName").value = staffName || "";
        byId("staffMatchList").innerHTML = "";
        byId("staffMatchHint").textContent = "";
        closeDrawer();
        setView("staff", trigger);
      }
      function bindStaffProfileButtons() {
        byId("drawerContent")
          .querySelectorAll("[data-staff-id]")
          .forEach(function (button) {
            button.addEventListener("click", function () {
              openStaffProfile(button.dataset.staffId, button.dataset.staffName, button);
            });
          });
      }
      async function openMatrixDetail(row) {
        openDrawer(
          "最细事实记录",
          '<div class="state-copy">正在加载下钻明细...</div>',
          document.activeElement,
        );
        try {
          var drill = row.drilldown || {},
            response = await ApiClient.get(
              (drill.endpoint || "/api/workload/analytics/metric-detail.php") +
                "?" +
                queryString(drill.params || {}),
              { timeout: 15000 },
            );
          var rows = response.data.rows || [];
          byId("drawerContent").innerHTML = rows.length
            ? rows
                .map(function (item) {
                  return (
                    '<div class="drawer-section"><strong>' +
                    esc(item.business_date) +
                    " · " +
                    esc(item.store_name || item.store_id) +
                    " · " +
                    esc(item.staff_name || item.staff_id) +
                    "</strong><p>" +
                    esc(metricName(item)) +
                    "：原始 " +
                    formatNumber(item.raw_value) +
                    "，有效 " +
                    formatNumber(item.effective_value) +
                    "，" +
                    auditStatusLabel(item.audit_status) +
                    "</p></div>"
                  );
                })
                .join("")
            : '<div class="state-copy">暂无最细事实记录</div>';
        } catch (error) {
          byId("drawerContent").innerHTML =
            '<div class="state-copy">' +
            esc(error.message || "下钻加载失败") +
            "</div>";
        }
      }
      function bindEvidenceButtons() {
        byId("drawerContent")
          .querySelectorAll("[data-evidence-url]")
          .forEach(function (button) {
            button.addEventListener("click", function () {
              openPreview(button.dataset.evidenceUrl, button);
            });
          });
      }
      function openPreview(url, trigger) {
        previewReturnFocus = trigger || document.activeElement;
        byId("previewImg").src = url;
        byId("previewModal").classList.add("show");
        requestAnimationFrame(function () {
          byId("closePreviewBtn").focus();
        });
      }
      function closePreview() {
        byId("previewModal").classList.remove("show");
        byId("previewImg").src = "";
        if (previewReturnFocus && previewReturnFocus.focus)
          previewReturnFocus.focus();
        previewReturnFocus = null;
      }
      function focusables(layer) {
        return Array.from(
          layer.querySelectorAll(
            'button:not([disabled]),a[href],input:not([disabled]),select:not([disabled]),[tabindex]:not([tabindex="-1"])',
          ),
        ).filter(function (element) {
          return !element.closest("[hidden]");
        });
      }
      function trapFocus(event, layer) {
        if (event.key !== "Tab") return;
        var items = focusables(layer),
          first = items[0],
          last = items[items.length - 1];
        if (!first) return;
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      }
      function waitForExport(delay) {
        return new Promise(function (resolve) {
          setTimeout(resolve, delay);
        });
      }
      async function downloadExportJob(jobId, headers) {
        for (var attempt = 0; attempt < 120; attempt++) {
          var statusResponse = await fetch(
            "/api/workload/exports.php?id=" + encodeURIComponent(jobId),
            { headers: headers },
          );
          var statusJson = await statusResponse.json();
          if (!statusResponse.ok || Number(statusJson.code) !== 0)
            throw new Error(statusJson.message || "查询导出任务失败");
          var job = statusJson.data || {};
          if (job.status === "failed")
            throw new Error(job.error_message || "异步导出失败");
          if (job.download_ready) {
            var downloadResponse = await fetch(
              "/api/workload/exports.php?id=" +
                encodeURIComponent(jobId) +
                "&download=1",
              { headers: headers },
            );
            if (!downloadResponse.ok) throw new Error("异步导出下载失败");
            var blob = await downloadResponse.blob(),
              url = URL.createObjectURL(blob),
              link = document.createElement("a");
            link.href = url;
            link.download = "workload-export-" + jobId + ".csv";
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
            byId("liveStatus").textContent = "异步导出已完成，下载已开始";
            return;
          }
          byId("liveStatus").textContent = "异步导出处理中，任务编号 " + jobId;
          await waitForExport(2000);
        }
        throw new Error("异步导出等待超时，请稍后重试");
      }
      async function exportCurrent() {
        if (!validateFilters()) return;
        var exportType = {
          dashboard: "store_completion",
          store: "store_completion",
          metric: "metric_selection",
          staff: "staff_full_data",
          matrix: "metric_full_dimension",
        }[currentView];
        var button = byId("exportBtn");
        button.disabled = true;
        button.textContent = "正在导出...";
        try {
          var headers = { "Content-Type": "application/json" };
          if (window.AppAuth && AppAuth.authHeaders)
            headers = AppAuth.authHeaders(headers);
          var controller = new AbortController(),
            timer = setTimeout(function () {
              controller.abort();
            }, 15000);
          var response = await fetch("/api/workload/exports.php", {
            method: "POST",
            headers: headers,
            body: JSON.stringify(
              Object.assign({ export_type: exportType }, filters()),
            ),
            signal: controller.signal,
          });
          clearTimeout(timer);
          var contentType = response.headers.get("content-type") || "";
          if (contentType.includes("application/json")) {
            var json = await response.json();
            if (!response.ok || Number(json.code) !== 0)
              throw new Error(json.message || "导出失败");
            var jobId = json.data && json.data.job_id;
            if (!jobId) throw new Error("导出任务编号缺失");
            button.textContent = "后台生成中...";
            await downloadExportJob(jobId, headers);
            setAsyncState("ready");
            return;
          }
          if (!response.ok) throw new Error("导出失败");
          var blob = await response.blob(),
            url = URL.createObjectURL(blob),
            link = document.createElement("a");
          link.href = url;
          link.download =
            "workload-" +
            currentView +
            "-" +
            byId("dateFrom").value +
            "-" +
            byId("dateTo").value +
            ".csv";
          document.body.appendChild(link);
          link.click();
          link.remove();
          URL.revokeObjectURL(url);
          byId("liveStatus").textContent = "表格导出已开始";
        } catch (error) {
          setAsyncState(
            error.name === "AbortError" ? "timeout" : "error",
            new Error(
              error.name === "AbortError" ? "导出请求超时" : error.message,
            ),
          );
        } finally {
          button.disabled = false;
          button.textContent = "导出当前视图";
        }
      }
      async function loadStores() {
        try {
          var response = await ApiClient.get(
            "/api/statistics/store.php?page_size=100",
          );
          storeOptions =
            response.data && Array.isArray(response.data.list)
              ? response.data.list
              : [];
          byId("storeId").innerHTML =
            '<option value="">全部可见门店</option>' +
            storeOptions
              .map(function (store) {
                return (
                  '<option value="' +
                  esc(store.id) +
                  '">' +
                  esc(store.name || "门店 " + store.id) +
                  "</option>"
                );
              })
              .join("");
        } catch (error) {
          storeOptions = [];
        }
      }
      function clearFilters() {
        byId("dateFrom").value = currentWorkspace === "tracking" ? daysAgo(1) : daysAgo(6);
        byId("dateTo").value = today();
        [
          "storeId",
          "roleCode",
          "staffName",
          "metricCode",
          "reportStatus",
          "auditStatus",
          "source",
        ].forEach(function (id) {
          if (!byId(id).disabled && !byId(id).readOnly) byId(id).value = "";
        });
        if (!byId("staffName").readOnly) byId("staffId").value = "";
        byId("staffMatchList").innerHTML = "";
        byId("staffMatchHint").textContent = "";
        byId("granularity").value = "day";
        byId("primaryDimension").value = "store";
        byId("secondaryDimension").value = "metric";
        loadData();
      }
      async function init() {
        byId("dateFrom").value = daysAgo(1);
        byId("dateTo").value = today();
        byId("dateFrom").max = today();
        byId("dateTo").max = today();
        try {
          var response = await ApiClient.get("/api/common/context-info.php");
          context = response.data.context || {};
          await loadStores();
          var role = String(
            context.role || context.role_code || "",
          ).toLowerCase();
          if (context.store_id && !context.is_hq && !context.is_admin) {
            byId("storeId").value = String(context.store_id);
            byId("storeId").disabled = true;
          }
          if (!context.is_hq && !context.is_admin && role === "manager") currentView = "store";
          if (!context.is_hq && !context.is_admin && role !== "manager") {
            currentView = "staff";
            byId("staffId").value = String(context.staff_id || "");
            byId("staffName").value = context.name || context.staff_name || "本人";
            byId("staffName").readOnly = true;
          }
          document
            .querySelectorAll(
              '[data-workspace="standards"],[data-workspace="imports"]',
            )
            .forEach(function (tab) {
              tab.hidden =
                !context.is_hq &&
                !context.is_admin &&
                !["operation", "admin", "ceo"].includes(role);
            });
          setWorkspace(currentWorkspace);
        } catch (error) {
          setAsyncState(
            Number(error.code) === 408 ? "timeout" : "error",
            error,
          );
        }
      }
      document.querySelectorAll("[data-range]").forEach(function (button) {
        button.addEventListener("click", function () {
          setRange(button.dataset.range, button);
        });
      });
      function bindTabs(selector, activate) {
        document.querySelectorAll(selector).forEach(function (tab) {
          tab.addEventListener("click", function () {
            activate(tab, tab);
          });
          tab.addEventListener("keydown", function (event) {
            if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key))
              return;
            event.preventDefault();
            var tabs = Array.from(document.querySelectorAll(selector)).filter(
                function (item) {
                  return !item.hidden;
                },
              ),
              index = tabs.indexOf(tab);
            if (event.key === "Home") index = 0;
            else if (event.key === "End") index = tabs.length - 1;
            else
              index =
                (index + (event.key === "ArrowRight" ? 1 : -1) + tabs.length) %
                tabs.length;
            tabs[index].focus();
            activate(tabs[index], tabs[index]);
          });
        });
      }
      bindTabs("[data-workspace]", function (tab, trigger) {
        setWorkspace(tab.dataset.workspace, trigger);
      });
      bindTabs("[data-view]", function (tab, trigger) {
        setView(tab.dataset.view, trigger);
      });
      byId("refreshBtn").addEventListener("click", loadData);
      byId("retryBtn").addEventListener("click", loadData);
      byId("clearFiltersBtn").addEventListener("click", clearFilters);
       byId("exportBtn").addEventListener("click", exportCurrent);
       var staffSearchTimer = null;
       function clearStaffSelection() {
         byId("staffId").value = "";
         byId("staffMatchList").innerHTML = "";
         byId("staffMatchHint").textContent = "";
       }
       function renderStaffMatches(rows) {
         byId("staffMatchList").innerHTML = rows
           .map(function (staff) {
             return '<button type="button" class="staff-match" data-staff-id="' + esc(staff.staff_id) + '" data-staff-name="' + esc(staff.staff_name) + '">' + esc(staff.staff_name) + ' · ' + esc(staff.store_name || "未分配门店") + ' · ' + esc(roleLabel(staff.role_code)) + '</button>';
           })
           .join("");
         byId("staffMatchList").querySelectorAll("[data-staff-id]").forEach(function (button) {
           button.addEventListener("click", function () {
             byId("staffId").value = button.dataset.staffId;
             byId("staffName").value = button.dataset.staffName;
             byId("staffMatchList").innerHTML = "";
             byId("staffMatchHint").textContent = "已选择员工";
           });
         });
       }
       async function searchStaffByName() {
         var name = byId("staffName").value.trim();
         if (!name || byId("staffName").readOnly) return;
         try {
           var response = await ApiClient.get("/api/workload/staff-search.php?name=" + encodeURIComponent(name), { timeout: 10000 });
           var rows = response.data.list || [];
           renderStaffMatches(rows);
           byId("staffMatchHint").textContent = rows.length ? "请选择员工" : "当前权限范围内未找到员工";
         } catch (error) {
           byId("staffMatchList").innerHTML = "";
           byId("staffMatchHint").textContent = error.message || "员工查询失败";
         }
       }
       byId("staffName").addEventListener("input", function () {
         clearStaffSelection();
         clearTimeout(staffSearchTimer);
         if (byId("staffName").value.trim()) staffSearchTimer = setTimeout(searchStaffByName, 250);
       });
      byId("closeDrawerBtn").addEventListener("click", closeDrawer);
      byId("drawerOverlay").addEventListener("click", closeDrawer);
      byId("closePreviewBtn").addEventListener("click", closePreview);
      byId("previewModal").addEventListener("click", function (event) {
        if (event.target === byId("previewModal")) closePreview();
      });
      document.addEventListener("keydown", function (event) {
        if (byId("previewModal").classList.contains("show")) {
          if (event.key === "Escape") {
            event.preventDefault();
            closePreview();
          } else trapFocus(event, byId("previewModal"));
          return;
        }
        if (byId("detailDrawer").classList.contains("show")) {
          if (event.key === "Escape") {
            event.preventDefault();
            closeDrawer();
          } else trapFocus(event, byId("detailDrawer"));
        }
      });
      window.addEventListener("resize", function () {
        if (byId("trendChart")) drawVisibleTrend();
      });
      window.requirePageAuth({
        onAuthed: async function (user) {
          var role = String((user && user.role) || "").toLowerCase(),
            allowed =
              !!(user && user.is_hq) ||
              ["admin", "ceo", "operation", "finance", "manager"].includes(
                role,
              );
          if (!allowed) {
            window.location.replace("/internal.html");
            return;
          }
          init();
        },
      });
    