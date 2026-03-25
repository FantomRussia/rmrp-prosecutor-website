// ═══════════════════════════════════════════════════════════════════
//  МОДУЛЬ «ОБРАЩЕНИЯ И ЖАЛОБЫ» — Frontend
// ═══════════════════════════════════════════════════════════════════

const { useState, useEffect, useMemo, useCallback } = React;

// ── Helpers ──

function renderTextWithLinks(text) {
  if (!text) return text;
  const parts = text.split(/(https?:\/\/[^\s]+)/g);
  if (parts.length === 1) return text;
  return React.createElement(React.Fragment, null,
    ...parts.map((part, i) =>
      part.match(/^https?:\/\//)
        ? React.createElement("a", { key: i, href: part, target: "_blank", rel: "noopener noreferrer", style: { color: "#5bc0eb", textDecoration: "underline", wordBreak: "break-all" } }, part)
        : part
    )
  );
}

// ── Constants ──

const CASE_STATUSES = {
  registered:                { label: "Зарегистрировано",                 color: "#1d70d1", icon: "📋" },
  assigned_staff:            { label: "Назначен исполнитель",             color: "#0077b6", icon: "👤" },
  assigned_supervisor:       { label: "Назначен прокурор",                color: "#0353a4", icon: "👁" },
  preliminary_check:         { label: "На предварительной проверке",      color: "#d69a2d", icon: "🔍" },
  check_terminated:          { label: "Проверка прекращена",              color: "#b34739", icon: "⛔" },
  transferred_investigation: { label: "Передано в следствие",             color: "#c77c28", icon: "📤" },
  criminal_case_opened:      { label: "ВУД",                             color: "#2f9e8f", icon: "⚖" },
  criminal_case_refused:     { label: "Отказ в ВУД",                     color: "#b34739", icon: "❌" },
  prosecution_review:        { label: "На утверждении в прокуратуре",    color: "#d69a2d", icon: "⏳" },
  prosecution_approved:      { label: "Утверждено",                      color: "#2f9e8f", icon: "✅" },
  prosecution_refused:       { label: "В утверждении отказано",           color: "#b34739", icon: "🚫" },
  sent_to_court:             { label: "Передано в суд",                  color: "#023e8a", icon: "🏛" },
  verdict_issued:            { label: "Приговор вынесен",                color: "#2f9e8f", icon: "⚖" },
  verdict_guilty:            { label: "Приговор в пользу обвинения",     color: "#2f9e8f", icon: "✅" },
  verdict_partial:           { label: "Приговор частично в пользу обвинения", color: "#d69a2d", icon: "⚖" },
  verdict_acquitted:         { label: "Приговор в пользу подсудимого",   color: "#b34739", icon: "❌" },
  completed:                 { label: "Завершено",                       color: "#2f9e8f", icon: "✔" },
  archive:                   { label: "Архив",                           color: "#8ea5c7", icon: "📦" },
};

const CASE_STATUS_TRANSITIONS = {
  registered:                ["preliminary_check"],
  assigned_staff:            ["assigned_supervisor", "preliminary_check"],
  assigned_supervisor:       ["preliminary_check"],
  preliminary_check:         ["check_terminated", "transferred_investigation"],
  check_terminated:          ["archive"],
  transferred_investigation: ["criminal_case_opened", "criminal_case_refused"],
  criminal_case_opened:      ["prosecution_review"],
  criminal_case_refused:     ["archive"],
  prosecution_review:        ["prosecution_approved", "prosecution_refused"],
  prosecution_approved:      ["sent_to_court"],
  prosecution_refused:       ["prosecution_review", "archive"],
  sent_to_court:             ["verdict_guilty", "verdict_partial", "verdict_acquitted"],
  verdict_guilty:            ["completed"],
  verdict_partial:           ["completed"],
  verdict_acquitted:         ["completed"],
  completed:                 ["archive"],
  archive:                   [],
};

const CASE_TYPES = { appeal: "Жалоба" };
const CASE_SOURCES = { forum: "Форум", oral: "Устное обращение", sk_transfer: "Передано из СК", fsb_transfer: "Передано из ФСБ", other: "Иной источник" };
const CASE_LINK_TYPES = { material: "Материалы", lawsuit: "Иск", procedural: "Процессуальные", other: "Прочее" };
const CASE_SEVERITY = {
  minor:             { label: "Небольшой тяжести", days: 6 },
  medium:            { label: "Средней тяжести",   days: 12 },
  serious:           { label: "Тяжкая",            days: 18 },
  especially_serious:{ label: "Особо тяжкая",      days: 24 },
};
const CASES_EXTENSION_DAYS = 7;

const CASES_TERMINAL_STATUSES_SET = new Set(["completed", "archive", "check_terminated", "criminal_case_refused", "prosecution_refused"]);
const CASE_TRANSITION_REQUIRES_STAGE_RESULT = ["check_terminated", "criminal_case_opened", "criminal_case_refused", "prosecution_approved", "prosecution_refused"];
const CASE_TRANSITION_REQUIRES_FINAL_RESULT = ["completed"];

const DEFAULT_CASES_META = {
  counters: { total: 0, assigned: 0, supervised: 0, overdue: 0, active: 0 },
  permissions: { canCreate: false, canAccessModule: false },
};

function normalizeCasesMeta(meta) {
  const src = meta && typeof meta === "object" ? meta : {};
  return {
    counters: {
      total: Number(src.counters?.total || 0),
      assigned: Number(src.counters?.assigned || 0),
      supervised: Number(src.counters?.supervised || 0),
      overdue: Number(src.counters?.overdue || 0),
      active: Number(src.counters?.active || 0),
      approaching: Number(src.counters?.approaching || 0),
    },
    approachingCaseIds: Array.isArray(src.approachingCaseIds) ? src.approachingCaseIds : [],
    permissions: {
      canCreate: Boolean(src.permissions?.canCreate),
      canAccessModule: Boolean(src.permissions?.canAccessModule),
    },
  };
}

async function uploadCaseFile(file) {
  const fd = new FormData();
  fd.append("file", file);
  const headers = {};
  if (typeof __csrfToken === "string" && __csrfToken) {
    headers["X-CSRF-Token"] = __csrfToken;
  }
  const resp = await fetch("api.php?action=upload-case-file", { method: "POST", body: fd, credentials: "include", headers });
  const data = await resp.json();
  if (!data.ok) throw new Error(data.error || "Ошибка загрузки");
  return data;
}

function ImageUploadButton({ onUploaded, label, style }) {
  const [uploading, setUploading] = React.useState(false);
  const inputRef = React.useRef(null);
  const handleFile = async (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    setUploading(true);
    try {
      const result = await uploadCaseFile(file);
      onUploaded(result.url, result.name || file.name);
    } catch (err) {
      showError(err.message || "Ошибка загрузки");
    } finally {
      setUploading(false);
      if (inputRef.current) inputRef.current.value = "";
    }
  };
  return React.createElement("span", { style: { display: "inline-flex", alignItems: "center" } },
    React.createElement("input", { ref: inputRef, type: "file", accept: "image/png,image/jpeg,image/webp", style: { display: "none" }, onChange: handleFile }),
    React.createElement("button", {
      className: "btn-hover",
      style: style || { ...btn("subtle"), fontSize: 12 },
      onClick: () => inputRef.current?.click(),
      disabled: uploading,
    }, uploading ? "Загрузка..." : (label || "📎 Изображение")),
  );
}

function getCaseStatusBadge(status) {
  const meta = CASE_STATUSES[status] || { label: status, color: "#8ea5c7" };
  return React.createElement("span", {
    style: {
      display: "inline-block",
      padding: "2px 10px",
      borderRadius: 12,
      fontSize: 12,
      fontWeight: 700,
      background: meta.color + "22",
      color: meta.color,
      border: "1px solid " + meta.color + "44",
      whiteSpace: "nowrap",
    },
  }, (meta.icon || "") + " " + meta.label);
}

function getCaseTypeBadge(caseType) {
  const isComplaint = caseType === "complaint";
  return React.createElement("span", {
    style: {
      display: "inline-block",
      padding: "2px 8px",
      borderRadius: 10,
      fontSize: 11,
      fontWeight: 700,
      background: isComplaint ? "#b3473922" : "#0077b622",
      color: isComplaint ? "#b34739" : "#0077b6",
      border: "1px solid " + (isComplaint ? "#b3473944" : "#0077b644"),
    },
  }, "Жалоба");
}

function isOverdue(item) {
  if (!item.deadline) return false;
  const noDeadline = ["sent_to_court", "verdict_issued", "verdict_guilty", "verdict_partial", "verdict_acquitted", "completed", "archive", "check_terminated", "criminal_case_refused", "prosecution_refused"];
  if (noDeadline.includes(item.status)) return false;
  return new Date(item.deadline) < new Date();
}

function daysUntilDeadline(item) {
  if (!item.deadline) return null;
  const diff = Math.ceil((new Date(item.deadline) - new Date()) / 86400000);
  return diff;
}

// ── PageCases ──

function PageCases({ user, users, factions, casesMeta, checksMeta, onRefresh, onNavigate }) {
  const [cases, setCases] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [tab, setTab] = useState("all");
  const [statusFilter, setStatusFilter] = useState("");
  const [typeFilter, setTypeFilter] = useState("");
  const [sourceFilter, setSourceFilter] = useState("");
  const [searchText, setSearchText] = useState("");
  const [subjectFilter, setSubjectFilter] = useState("");
  const [selectedCaseId, setSelectedCaseId] = useState(null);
  const [showCreateModal, setShowCreateModal] = useState(false);

  const isFederalOrAdmin = user?.role === "FEDERAL" || hasSystemAdminAccess(user);
  const isBoss = user?.role === "BOSS" || user?.role === "SENIOR_STAFF" || user?.role === "USP";
  const canCreate = true; // Все сотрудники могут регистрировать обращения
  const enabledSubjects = checksMeta?.settings?.enabledSubjects || ["Рублёвка", "Арбат", "Патрики", "Тверской", "Кутузовский"];

  const loadCases = useCallback(async (currentTab) => {
    setLoading(true);
    setError("");
    try {
      const payload = { tab: currentTab || tab };
      if (statusFilter) payload.status = statusFilter;
      if (subjectFilter && isFederalOrAdmin) payload.subject = subjectFilter;
      const res = await apiRequest("cases.list", payload);
      setCases(res.items || []);
    } catch (e) {
      setError(e.message || "Ошибка загрузки");
    } finally {
      setLoading(false);
    }
  }, [tab, statusFilter, subjectFilter, isFederalOrAdmin]);

  useEffect(() => { loadCases(); }, [tab, statusFilter, subjectFilter]);

  const debouncedSearch = typeof useDebouncedValue === "function" ? useDebouncedValue(searchText, 300) : searchText;

  const filtered = useMemo(() => {
    let list = cases;
    if (typeFilter) list = list.filter(c => c.caseType === typeFilter);
    if (sourceFilter) list = list.filter(c => c.source === sourceFilter);
    if (debouncedSearch.trim()) {
      const q = debouncedSearch.trim().toLowerCase();
      list = list.filter(c =>
        (c.regNumber || "").toLowerCase().includes(q) ||
        (c.applicantName || "").toLowerCase().includes(q) ||
        (c.description || "").toLowerCase().includes(q) ||
        (c.assignedStaffName || "").toLowerCase().includes(q)
      );
    }
    return list;
  }, [cases, typeFilter, sourceFilter, debouncedSearch]);

  const { page, totalPages, paged, setPage, total } = usePagination(filtered, 20);

  const handleCreated = (newCase) => {
    setShowCreateModal(false);
    loadCases();
    if (onRefresh) onRefresh();
  };

  if (selectedCaseId) {
    return React.createElement(CaseDetailView, {
      caseId: selectedCaseId,
      user,
      users,
      factions,
      checksMeta,
      onBack: () => { setSelectedCaseId(null); loadCases(); },
      onRefresh,
    });
  }

  const tabs = [
    { id: "all", label: "Все", count: null },
    { id: "my", label: "Мои дела", count: casesMeta?.counters?.assigned || 0 },
    { id: "supervised", label: "На контроле", count: casesMeta?.counters?.supervised || 0 },
    { id: "overdue", label: "Просроченные", count: casesMeta?.counters?.overdue || 0 },
    { id: "archive", label: "Архив", count: null },
    { id: "analytics", label: "Аналитика", count: null },
  ];

  if (tab === "analytics") {
    return React.createElement("div", { className: "fade-in" },
      React.createElement("div", { style: { display: "flex", alignItems: "center", gap: 16, marginBottom: 20, flexWrap: "wrap" } },
        React.createElement("h1", { className: "resp-page-title", style: { ...S.pageTitle, margin: 0 } }, "Жалобы"),
        React.createElement("button", {
          className: "btn-hover",
          style: btn("ghost"),
          onClick: () => setTab("all"),
        }, "← К списку"),
      ),
      React.createElement("div", { className: "checks-tab-bar", style: { display: "flex", gap: 4, marginBottom: 20, flexWrap: "wrap" } },
        tabs.map(t => React.createElement("button", {
          key: t.id,
          className: "btn-hover",
          style: {
            padding: "8px 16px",
            borderRadius: 8,
            border: "none",
            cursor: "pointer",
            fontSize: 14,
            fontWeight: tab === t.id ? 700 : 400,
            background: tab === t.id ? C.accent : "transparent",
            color: tab === t.id ? "#fff" : C.textDim,
          },
          onClick: () => setTab(t.id),
        }, t.label + (t.count != null ? ` (${t.count})` : ""))),
      ),
      React.createElement(CaseAnalyticsDashboard, { user, users, factions, checksMeta, enabledSubjects }),
    );
  }

  return React.createElement("div", { className: "fade-in" },
    // Header
    React.createElement("div", { style: { display: "flex", alignItems: "center", justifyContent: "space-between", gap: 16, marginBottom: 20, flexWrap: "wrap" } },
      React.createElement("h1", { className: "resp-page-title", style: { ...S.pageTitle, margin: 0 } }, "Жалобы"),
      canCreate && React.createElement("button", {
        className: "btn-hover",
        style: btn("gold"),
        onClick: () => setShowCreateModal(true),
      }, "+ Зарегистрировать"),
    ),

    // Tabs
    React.createElement("div", { className: "checks-tab-bar", style: { display: "flex", gap: 4, marginBottom: 16, flexWrap: "wrap" } },
      tabs.map(t => React.createElement("button", {
        key: t.id,
        className: "btn-hover",
        style: {
          padding: "8px 16px",
          borderRadius: 8,
          border: "none",
          cursor: "pointer",
          fontSize: 14,
          fontWeight: tab === t.id ? 700 : 400,
          background: tab === t.id ? C.accent : "transparent",
          color: tab === t.id ? "#fff" : C.textDim,
        },
        onClick: () => setTab(t.id),
      }, t.label + (t.count != null ? ` (${t.count})` : ""))),
    ),

    // Filters
    React.createElement("div", { style: { display: "flex", gap: 8, marginBottom: 16, flexWrap: "wrap", alignItems: "center" } },
      React.createElement("input", {
        type: "text",
        placeholder: "Поиск по номеру, заявителю, описанию...",
        value: searchText,
        onChange: e => setSearchText(e.target.value),
        style: { ...S.input, flex: "1 1 200px", minWidth: 200 },
      }),
      React.createElement("select", {
        value: statusFilter,
        onChange: e => setStatusFilter(e.target.value),
        style: { ...S.select, minWidth: 140 },
      },
        React.createElement("option", { value: "" }, "Все статусы"),
        ...Object.entries(CASE_STATUSES).map(([code, meta]) =>
          React.createElement("option", { key: code, value: code }, meta.label)
        ),
      ),
      React.createElement("select", {
        value: typeFilter,
        onChange: e => setTypeFilter(e.target.value),
        style: { ...S.select, minWidth: 120 },
      },
        React.createElement("option", { value: "" }, "Все типы"),
        ...Object.entries(CASE_TYPES).map(([code, label]) =>
          React.createElement("option", { key: code, value: code }, label)
        ),
      ),
      React.createElement("select", {
        value: sourceFilter,
        onChange: e => setSourceFilter(e.target.value),
        style: { ...S.select, minWidth: 120 },
      },
        React.createElement("option", { value: "" }, "Все источники"),
        ...Object.entries(CASE_SOURCES).map(([code, label]) =>
          React.createElement("option", { key: code, value: code }, label)
        ),
      ),
      isFederalOrAdmin && React.createElement("select", {
        value: subjectFilter,
        onChange: e => setSubjectFilter(e.target.value),
        style: { ...S.select, minWidth: 120 },
      },
        React.createElement("option", { value: "" }, "Все субъекты"),
        ...enabledSubjects.map(s => React.createElement("option", { key: s, value: s }, s)),
      ),
    ),

    // Deadline warnings
    (casesMeta?.counters?.overdue > 0 || casesMeta?.counters?.approaching > 0) && React.createElement("div", { style: { display: "flex", gap: 8, marginBottom: 12, flexWrap: "wrap" } },
      casesMeta.counters.overdue > 0 && React.createElement("div", {
        style: { padding: "8px 16px", borderRadius: 8, background: C.danger + "22", border: "1px solid " + C.danger + "44", color: C.danger, fontSize: 14, fontWeight: 700 },
      }, "Просроченных дел: " + casesMeta.counters.overdue),
      casesMeta.counters.approaching > 0 && React.createElement("div", {
        style: { padding: "8px 16px", borderRadius: 8, background: "#e67e2222", border: "1px solid #e67e2244", color: "#e67e22", fontSize: 14, fontWeight: 600 },
      }, "Срок истекает в ближайшие 3 дня: " + casesMeta.counters.approaching),
    ),

    // Error
    error && React.createElement("div", { style: { color: C.danger, marginBottom: 12 } }, error),

    // Loading
    loading && React.createElement("div", { style: { display: "flex", flexDirection: "column", gap: 8 } },
      React.createElement(SkeletonCard, null),
      React.createElement(SkeletonCard, null),
      React.createElement(SkeletonCard, null),
    ),

    // List
    !loading && paged.length === 0 && React.createElement(EmptyState, {
      icon: tab === "overdue" ? "\u2705" : "\uD83D\uDCC2",
      title: tab === "overdue" ? "\u041F\u0440\u043E\u0441\u0440\u043E\u0447\u0435\u043D\u043D\u044B\u0445 \u0434\u0435\u043B \u043D\u0435\u0442" : "\u041E\u0431\u0440\u0430\u0449\u0435\u043D\u0438\u0439 \u043D\u0435 \u043D\u0430\u0439\u0434\u0435\u043D\u043E",
      description: tab === "overdue" ? "\u0412\u0441\u0435 \u0434\u0435\u043B\u0430 \u0432 \u0441\u0440\u043E\u043A\u0435" : "\u0417\u0430\u0440\u0435\u0433\u0438\u0441\u0442\u0440\u0438\u0440\u0443\u0439\u0442\u0435 \u043F\u0435\u0440\u0432\u043E\u0435 \u043E\u0431\u0440\u0430\u0449\u0435\u043D\u0438\u0435 \u0438\u043B\u0438 \u0436\u0430\u043B\u043E\u0431\u0443",
      actionLabel: canCreate && tab !== "overdue" ? "+ \u0417\u0430\u0440\u0435\u0433\u0438\u0441\u0442\u0440\u0438\u0440\u043E\u0432\u0430\u0442\u044C" : undefined,
      onAction: canCreate && tab !== "overdue" ? function() { setShowCreateModal(true); } : undefined,
    }),

    !loading && paged.length > 0 && React.createElement("div", { style: { display: "flex", flexDirection: "column", gap: 8 } },
      paged.map(c => React.createElement("div", {
        key: c.id,
        className: "check-card resp-card",
        style: {
          ...S.card,
          cursor: "pointer",
          borderLeft: `4px solid ${(CASE_STATUSES[c.status] || {}).color || C.accent}`,
          position: "relative",
        },
        onClick: () => setSelectedCaseId(c.id),
      },
        React.createElement("div", { style: { display: "flex", justifyContent: "space-between", alignItems: "flex-start", gap: 12, flexWrap: "wrap" } },
          React.createElement("div", { style: { flex: 1, minWidth: 200 } },
            React.createElement("div", { style: { display: "flex", gap: 8, alignItems: "center", marginBottom: 6, flexWrap: "wrap" } },
              React.createElement("span", { style: { fontWeight: 700, color: C.text, fontSize: 15 } }, c.regNumber),
              getCaseTypeBadge(c.caseType),
              getCaseStatusBadge(c.status),
              isOverdue(c) && React.createElement("span", {
                style: { fontSize: 11, color: C.danger, fontWeight: 700, background: C.danger + "22", padding: "2px 8px", borderRadius: 10 },
              }, "ПРОСРОЧЕНО"),
            ),
            React.createElement("div", { style: { fontSize: 14, color: C.textDim, marginBottom: 4 } },
              c.applicantName && React.createElement("span", null, "Заявитель: ", React.createElement("b", null, c.applicantName), " · "),
              c.description && React.createElement("span", null, c.description.length > 100 ? c.description.slice(0, 100) + "..." : c.description),
            ),
            React.createElement("div", { style: { fontSize: 12, color: C.textMuted, display: "flex", gap: 12, flexWrap: "wrap" } },
              c.assignedStaffName && React.createElement("span", null, "Следователь: ", c.assignedStaffName),
              c.supervisorName && React.createElement("span", null, "Прокурор: ", c.supervisorName),
              c.subject && React.createElement("span", null, "Субъект: ", c.subject),
              c.deadline && (() => {
                const days = daysUntilDeadline(c);
                const over = isOverdue(c);
                const deadlineColor = over ? C.danger : days !== null && days <= 3 ? "#e67e22" : days !== null && days <= 5 ? "#d69a2d" : C.textMuted;
                const deadlineWeight = over || (days !== null && days <= 3) ? 700 : 400;
                return React.createElement("span", { style: { color: deadlineColor, fontWeight: deadlineWeight } },
                  "Срок: ", c.deadline,
                  over ? " (ПРОСРОЧЕНО)" : days !== null && days <= 3 ? ` (${days} дн.!)` : days !== null && days <= 5 ? ` (${days} дн.)` : "",
                );
              })(),
            ),
          ),
          React.createElement("div", { style: { fontSize: 12, color: C.textMuted, whiteSpace: "nowrap" } },
            formatDateTime(c.createdAt),
          ),
        ),
      )),
    ),

    // Pagination
    total > 20 && React.createElement(Pagination, { page, totalPages, onChange: setPage, total }),

    // Create modal
    showCreateModal && React.createElement(CaseCreateModal, {
      user,
      users,
      factions,
      enabledSubjects,
      onClose: () => setShowCreateModal(false),
      onCreated: handleCreated,
    }),
  );
}

// ── CaseCreateModal ──

function CaseCreateModal({ user, users, factions, enabledSubjects, onClose, onCreated }) {
  const [form, setForm] = useState({
    caseType: "appeal",
    source: "forum",
    applicantName: "",
    applicantContact: "",
    description: "",
    factionId: "",
    forumLink: "",
    videoLink: "",
    incidentDate: "",
    severity: "",
    deadline: "",
    comments: "",
    skExecutorName: "",
    customRegNumber: "",
    subject: (user?.subject === GENERAL_SUBJECT || user?.role === "FEDERAL") ? (enabledSubjects[0] || "") : (user?.subject || enabledSubjects[0] || ""),
    assignedStaffId: "",
    supervisorId: "",
  });
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");

  const isFederalOrAdmin = user?.role === "FEDERAL" || user?.subject === GENERAL_SUBJECT || hasSystemAdminAccess(user);
  const effectiveSubject = isFederalOrAdmin ? form.subject : (user?.subject || "");

  const candidateUsers = useMemo(() => {
    return (users || []).filter(u => u && !u.blocked && (u.subject === effectiveSubject || u.subject === GENERAL_SUBJECT || u.role === "FEDERAL"));
  }, [users, effectiveSubject]);

  // STAFF не может назначить FEDERAL/BOSS исполнителем
  const staffAssignCandidates = useMemo(() => {
    if (user && user.role === "STAFF") {
      return candidateUsers.filter(u => u.role !== "FEDERAL" && u.role !== "BOSS");
    }
    return candidateUsers;
  }, [candidateUsers, user]);

  const factionList = useMemo(() => {
    return (factions || []).filter(f => f && f.subject === effectiveSubject);
  }, [factions, effectiveSubject]);

  const update = (key, val) => {
    setForm(prev => {
      const next = { ...prev, [key]: val };
      // Auto-calculate deadline when severity or incidentDate changes
      const sev = key === "severity" ? val : next.severity;
      const incDate = key === "incidentDate" ? val : next.incidentDate;
      if ((key === "severity" || key === "incidentDate") && sev && CASE_SEVERITY[sev]) {
        const from = incDate ? new Date(incDate + "T00:00:00") : new Date();
        from.setDate(from.getDate() + CASE_SEVERITY[sev].days);
        next.deadline = from.toISOString().slice(0, 10);
      }
      return next;
    });
  };

  const validate = () => {
    if (!form.caseType) return "Выберите тип";
    if (!form.source) return "Выберите источник";
    if (!form.description.trim()) return "Введите описание";
    if (form.source === "forum" && !form.forumLink.trim()) return "Укажите ссылку на форум";
    if (form.source === "oral" && !form.videoLink.trim()) return "Укажите ссылку на видео регистрации жалобы";
    return "";
  };

  const handleSubmit = async () => {
    const err = validate();
    if (err) { setError(err); return; }
    setSaving(true);
    setError("");
    try {
      const payload = { ...form, subject: effectiveSubject };
      const res = await apiRequest("cases.create", payload);
      onCreated(res.detail);
    } catch (e) {
      setError(e.message || "Ошибка создания");
    } finally {
      setSaving(false);
    }
  };

  const fieldStyle = { marginBottom: 14 };
  const labelSt = { ...S.label, marginBottom: 4 };

  return React.createElement(Modal, { onClose, maxWidth: 640, persistent: true },
    React.createElement("h2", { style: { ...S.cardTitle, marginBottom: 20 } }, "Регистрация жалобы"),

    // Subject (for FEDERAL/ADMIN/GP)
    isFederalOrAdmin && React.createElement("div", { style: fieldStyle },
      React.createElement("label", { style: labelSt }, "Субъект"),
      React.createElement("select", { value: form.subject, onChange: e => update("subject", e.target.value), style: S.select },
        ...enabledSubjects.map(s => React.createElement("option", { key: s, value: s }, s)),
        React.createElement("option", { key: GENERAL_SUBJECT, value: GENERAL_SUBJECT }, GENERAL_SUBJECT),
      ),
    ),

    // Type (hidden — only appeal now)


    // Source
    React.createElement("div", { style: fieldStyle },
      React.createElement("label", { style: labelSt }, "Источник *"),
      React.createElement("select", { value: form.source, onChange: e => update("source", e.target.value), style: S.select },
        ...Object.entries(CASE_SOURCES).map(([code, label]) =>
          React.createElement("option", { key: code, value: code }, label)
        ),
      ),
    ),

    // Custom reg number
    React.createElement("div", { style: fieldStyle },
      React.createElement("label", { style: labelSt }, "Регистрационный номер"),
      React.createElement("input", { type: "text", value: form.customRegNumber, onChange: e => update("customRegNumber", e.target.value), style: S.input, placeholder: "Оставьте пустым для автоматической генерации" }),
      React.createElement("div", { style: { fontSize: 12, color: C.textMuted, marginTop: 4 } }, "Если не указан — номер будет сгенерирован автоматически."),
    ),

    // Forum link
    form.source === "forum" && React.createElement("div", { style: fieldStyle },
      React.createElement("label", { style: labelSt }, "Ссылка на форум *"),
      React.createElement("input", { type: "text", value: form.forumLink, onChange: e => update("forumLink", e.target.value), style: S.input, placeholder: "https://..." }),
    ),

    // Video link for oral
    form.source === "oral" && React.createElement("div", { style: fieldStyle },
      React.createElement("label", { style: labelSt }, "Ссылка на видео регистрации жалобы *"),
      React.createElement("input", { type: "text", value: form.videoLink, onChange: e => update("videoLink", e.target.value), style: S.input, placeholder: "https://youtube.com/... или прямая ссылка на видео" }),
    ),

    // Applicant (hidden for SK/FSB transfers)
    form.source !== "sk_transfer" && form.source !== "fsb_transfer" && React.createElement("div", { style: fieldStyle },
      React.createElement("label", { style: labelSt }, "Заявитель"),
      React.createElement("input", { type: "text", value: form.applicantName, onChange: e => update("applicantName", e.target.value), style: S.input, placeholder: "ФИО или никнейм" }),
    ),

    // Description
    React.createElement("div", { style: fieldStyle },
      React.createElement("label", { style: labelSt }, "Описание *"),
      React.createElement("textarea", { value: form.description, onChange: e => update("description", e.target.value), style: { ...S.textarea, minHeight: 80 }, placeholder: "Суть жалобы" }),
    ),

    // Incident date
    React.createElement("div", { style: fieldStyle },
      React.createElement("label", { style: labelSt }, "Дата произошедшего"),
      React.createElement("input", { type: "date", value: form.incidentDate, onChange: e => update("incidentDate", e.target.value), style: S.input }),
      React.createElement("div", { style: { fontSize: 12, color: C.textMuted, marginTop: 4 } }, "Срок по тяжести считается от этой даты. Если не указана — от даты регистрации."),
    ),

    // Faction
    React.createElement("div", { style: fieldStyle },
      React.createElement("label", { style: labelSt }, "Фракция"),
      React.createElement("select", { value: form.factionId, onChange: e => update("factionId", e.target.value), style: S.select },
        React.createElement("option", { value: "" }, "— указать позже —"),
        ...(factionList.length > 0
          ? factionList.map(f => React.createElement("option", { key: f.id, value: f.id }, f.name || f.id))
          : (factions || []).map(f => React.createElement("option", { key: f.id, value: f.id }, f.name || f.id))
        ),
      ),
    ),

    // Supervisor (prosecutor)
    React.createElement("div", { style: fieldStyle },
      React.createElement("label", { style: labelSt }, "Прокурор"),
      React.createElement("select", { value: form.supervisorId, onChange: e => update("supervisorId", e.target.value), style: S.select },
        React.createElement("option", { value: "" }, "— назначить позже —"),
        ...candidateUsers.map(u =>
          React.createElement("option", { key: u.id, value: u.id },
            (u.surname || "") + " " + (u.name || "") + " (" + (u.role || "") + ")"
          )
        ),
      ),
    ),

    // SK/FSB executor name
    (form.source === "sk_transfer" || form.source === "fsb_transfer") && React.createElement("div", { style: fieldStyle },
      React.createElement("label", { style: labelSt }, form.source === "fsb_transfer" ? "Следователь (ФИО сотрудника ФСБ)" : "Следователь (ФИО сотрудника СК)"),
      React.createElement("input", { type: "text", value: form.skExecutorName, onChange: e => update("skExecutorName", e.target.value), style: S.input, placeholder: "Фамилия Имя Отчество" }),
    ),

    // Severity
    React.createElement("div", { style: fieldStyle },
      React.createElement("label", { style: labelSt }, "Тяжесть статьи"),
      React.createElement("select", { value: form.severity, onChange: e => update("severity", e.target.value), style: S.select },
        React.createElement("option", { value: "" }, "— не указана —"),
        ...Object.entries(CASE_SEVERITY).map(([code, meta]) =>
          React.createElement("option", { key: code, value: code }, meta.label + " (" + meta.days + " дн.)")
        ),
      ),
      form.severity && CASE_SEVERITY[form.severity] && React.createElement("div", { style: { fontSize: 12, color: C.textMuted, marginTop: 4 } },
        "Срок автоматически: " + CASE_SEVERITY[form.severity].days + " дней от " + (form.incidentDate || "даты регистрации"),
      ),
    ),

    // Deadline
    React.createElement("div", { style: fieldStyle },
      React.createElement("label", { style: labelSt }, "Крайний срок", form.severity ? " (авто)" : ""),
      React.createElement("input", { type: "date", value: form.deadline, onChange: e => update("deadline", e.target.value), style: S.input }),
    ),

    // Comments
    React.createElement("div", { style: fieldStyle },
      React.createElement("label", { style: labelSt }, "Комментарий / служебная пометка"),
      React.createElement("textarea", { value: form.comments, onChange: e => update("comments", e.target.value), style: { ...S.textarea, minHeight: 50 }, placeholder: "Необязательно" }),
    ),

    error && React.createElement("div", { style: { color: C.danger, marginBottom: 12, fontSize: 14 } }, error),

    React.createElement("div", { style: { display: "flex", gap: 10, justifyContent: "flex-end" } },
      React.createElement("button", { className: "btn-hover", style: btn("gold"), onClick: handleSubmit, disabled: saving },
        saving ? "Создание..." : "Зарегистрировать",
      ),
    ),
  );
}

// ── CaseDetailView ──

function CaseDetailView({ caseId, user, users, factions, checksMeta, onBack, onRefresh }) {
  const [caseData, setCaseData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [statusComment, setStatusComment] = useState("");
  const [stageResult, setStageResult] = useState("");
  const [finalResult, setFinalResult] = useState("");
  const [newComment, setNewComment] = useState("");
  const [newCommentImage, setNewCommentImage] = useState("");
  const [newLinkUrl, setNewLinkUrl] = useState("");
  const [newLinkType, setNewLinkType] = useState("other");
  const [newLinkLabel, setNewLinkLabel] = useState("");
  const [savingStatus, setSavingStatus] = useState(false);
  const [savingComment, setSavingComment] = useState(false);
  const [savingLink, setSavingLink] = useState(false);
  const [assigningStaff, setAssigningStaff] = useState(false);
  const [assigningSupervisor, setAssigningSupervisor] = useState(false);
  const [selectedStaffId, setSelectedStaffId] = useState("");
  const [selectedSupervisorId, setSelectedSupervisorId] = useState("");
  const [showAssignStaff, setShowAssignStaff] = useState(false);
  const [showAssignSupervisor, setShowAssignSupervisor] = useState(false);
  const [editingFields, setEditingFields] = useState(null);
  const [savingEdit, setSavingEdit] = useState(false);
  const [skExecutorName, setSkExecutorName] = useState("");
  const [showExtend, setShowExtend] = useState(false);
  const [extensionReason, setExtensionReason] = useState("");
  const [extensionImage, setExtensionImage] = useState("");
  const [savingExtension, setSavingExtension] = useState(false);
  const [showSetDeadline, setShowSetDeadline] = useState(false);
  const [newDeadlineValue, setNewDeadlineValue] = useState("");
  const [savingDeadline, setSavingDeadline] = useState(false);

  const isAdmin = user && hasSystemAdminAccess(user);
  const isManager = user && caseData && (
    isAdmin ||
    user.role === "FEDERAL" ||
    ((user.role === "BOSS" || user.role === "SENIOR_STAFF" || user.role === "USP") && user.subject === caseData.subject)
  );
  const isAssigned = user && caseData && caseData.assignedStaffId === user.id;
  const isSupervisor = user && caseData && caseData.supervisorId === user.id;
  const isCreator = user && caseData && caseData.createdBy === user.id;
  const enabledSubjects = checksMeta?.settings?.enabledSubjects || ["Рублёвка", "Арбат", "Патрики", "Тверской", "Кутузовский"];

  const loadCase = useCallback(async () => {
    setLoading(true);
    try {
      const res = await apiRequest("cases.get", { caseId });
      setCaseData(res.detail);
    } catch (e) {
      setError(e.message || "Ошибка загрузки");
    } finally {
      setLoading(false);
    }
  }, [caseId]);

  useEffect(() => { loadCase(); }, [caseId]);

  const candidateUsers = useMemo(() => {
    if (!caseData) return [];
    return (users || []).filter(u => u && !u.blocked && (u.subject === caseData.subject || u.subject === GENERAL_SUBJECT || u.role === "FEDERAL"));
  }, [users, caseData?.subject]);

  const handleChangeStatus = async (newStatus) => {
    const requiresStage = CASE_TRANSITION_REQUIRES_STAGE_RESULT.includes(newStatus);
    const requiresFinal = CASE_TRANSITION_REQUIRES_FINAL_RESULT.includes(newStatus);

    if (requiresStage && !stageResult.trim()) {
      showWarning("Укажите результат этапа");
      return;
    }
    if (requiresFinal && !finalResult.trim()) {
      showWarning("Укажите итоговый результат");
      return;
    }

    setSavingStatus(true);
    try {
      const payload = {
        caseId,
        newStatus,
        comment: statusComment,
        stageResult: stageResult,
        finalResult: finalResult,
      };
      if (newStatus === "criminal_case_opened" && skExecutorName.trim()) {
        payload.skExecutorName = skExecutorName.trim();
      }
      const res = await apiRequest("cases.change-status", payload);
      setCaseData(null);
      await loadCase();
      setStatusComment("");
      setStageResult("");
      setFinalResult("");
      setSkExecutorName("");
    } catch (e) {
      showError(e.message || "Ошибка смены статуса");
    } finally {
      setSavingStatus(false);
    }
  };

  const handleAssignStaff = async () => {
    if (!selectedStaffId.trim()) return;
    setAssigningStaff(true);
    try {
      // Try to find a matching system user
      const matchedUser = (users || []).find(u => {
        const fullName = ((u.surname || "") + " " + (u.name || "")).trim().toLowerCase();
        return fullName === selectedStaffId.trim().toLowerCase();
      });
      const payload = { caseId };
      if (matchedUser) {
        payload.staffId = matchedUser.id;
      } else {
        payload.staffName = selectedStaffId.trim();
      }
      await apiRequest("cases.assign-staff", payload);
      await loadCase();
      setShowAssignStaff(false);
      setSelectedStaffId("");
    } catch (e) {
      showError(e.message || "Ошибка назначения");
    } finally {
      setAssigningStaff(false);
    }
  };

  const handleAssignSupervisor = async () => {
    if (!selectedSupervisorId) return;
    setAssigningSupervisor(true);
    try {
      await apiRequest("cases.assign-supervisor", { caseId, supervisorId: selectedSupervisorId });
      await loadCase();
      setShowAssignSupervisor(false);
      setSelectedSupervisorId("");
    } catch (e) {
      showError(e.message || "Ошибка назначения");
    } finally {
      setAssigningSupervisor(false);
    }
  };

  const handleAddComment = async () => {
    if (!newComment.trim() && !newCommentImage) return;
    setSavingComment(true);
    try {
      await apiRequest("cases.add-comment", { caseId, body: newComment, imageUrl: newCommentImage || undefined });
      setNewComment("");
      setNewCommentImage("");
      await loadCase();
    } catch (e) {
      showError(e.message || "Ошибка");
    } finally {
      setSavingComment(false);
    }
  };

  const handleAddLink = async () => {
    if (!newLinkUrl.trim()) return;
    setSavingLink(true);
    try {
      await apiRequest("cases.add-link", { caseId, url: newLinkUrl, linkType: newLinkType, label: newLinkLabel });
      setNewLinkUrl("");
      setNewLinkLabel("");
      await loadCase();
    } catch (e) {
      showError(e.message || "Ошибка");
    } finally {
      setSavingLink(false);
    }
  };

  const handleDeleteLink = async (linkId) => {
    const confirmed = await showConfirm("Удалить эту ссылку?", { danger: true, confirmLabel: "Удалить" });
    if (!confirmed) return;
    try {
      await apiRequest("cases.delete-link", { linkId });
      await loadCase();
    } catch (e) {
      showError(e.message || "Ошибка удаления");
    }
  };

  const handleSaveEdit = async () => {
    if (!editingFields) return;
    setSavingEdit(true);
    try {
      await apiRequest("cases.update", { caseId, ...editingFields });
      setEditingFields(null);
      await loadCase();
    } catch (e) {
      showError(e.message || "Ошибка");
    } finally {
      setSavingEdit(false);
    }
  };

  const handleExtendDeadline = async () => {
    if (!extensionReason.trim()) { showWarning("Укажите основание продления (официальная бумага)"); return; }
    setSavingExtension(true);
    try {
      await apiRequest("cases.extend-deadline", { caseId, reason: extensionReason, imageUrl: extensionImage || undefined });
      setShowExtend(false);
      setExtensionReason("");
      setExtensionImage("");
      await loadCase();
    } catch (e) {
      showError(e.message || "Ошибка продления");
    } finally {
      setSavingExtension(false);
    }
  };

  const handleSetDeadline = async () => {
    if (!newDeadlineValue) { showWarning("Выберите дату крайнего срока"); return; }
    setSavingDeadline(true);
    try {
      await apiRequest("cases.set-deadline", { caseId, deadline: newDeadlineValue });
      setShowSetDeadline(false);
      setNewDeadlineValue("");
      await loadCase();
    } catch (e) {
      showError(e.message || "Ошибка установки срока");
    } finally {
      setSavingDeadline(false);
    }
  };

  const handleDelete = async () => {
    const confirmed = await showConfirm("Удалить эту жалобу?", { danger: true, confirmLabel: "Удалить" });
    if (!confirmed) return;
    try {
      await apiRequest("cases.delete", { caseId });
      onBack();
    } catch (e) {
      showError(e.message || "Ошибка удаления");
    }
  };

  if (loading) return React.createElement("div", { style: { padding: 20 } },
    React.createElement(SkeletonCard, null),
    React.createElement("div", { style: { height: 12 } }),
    React.createElement(SkeletonCard, null),
  );
  if (error) return React.createElement("div", { style: { color: C.danger, padding: 40 } }, error,
    React.createElement("button", { className: "btn-hover", style: { ...btn("ghost"), marginLeft: 12 }, onClick: onBack }, "Назад"),
  );
  if (!caseData) return null;

  const d = caseData;
  const isArchived = d.status === "archive";
  const allowedTransitions = d.allowedTransitions || [];
  const sectionStyle = { ...S.card, marginBottom: 16 };
  const sectionTitle = (text) => React.createElement("h3", { style: { fontSize: 16, fontWeight: 700, color: C.text, marginBottom: 12 } }, text);

  return React.createElement("div", { className: "fade-in" },
    // Breadcrumbs
    typeof Breadcrumbs === "function" && React.createElement(Breadcrumbs, {
      items: [
        { label: "\u041E\u0431\u0440\u0430\u0449\u0435\u043D\u0438\u044F", onClick: onBack },
        { label: d.regNumber },
      ],
    }),
    // Header
    React.createElement("div", { style: { display: "flex", alignItems: "center", gap: 12, marginBottom: 20, flexWrap: "wrap" } },
      React.createElement("button", { className: "btn-hover", style: btn("ghost"), onClick: onBack }, "\u2190 \u041D\u0430\u0437\u0430\u0434"),
      React.createElement("h1", { className: "resp-page-title", style: { ...S.pageTitle, margin: 0, fontSize: T.heading } }, d.regNumber),
      getCaseTypeBadge(d.caseType),
      getCaseStatusBadge(d.status),
      isOverdue(d) && React.createElement("span", {
        style: { fontSize: 13, color: C.danger, fontWeight: 700, background: C.danger + "22", padding: "3px 10px", borderRadius: 10 },
      }, "ПРОСРОЧЕНО"),
      (isManager || isAssigned || isSupervisor || isCreator) && !isArchived && React.createElement("button", {
        className: "btn-hover",
        style: { ...btn("subtle"), fontSize: 13 },
        onClick: () => setEditingFields({
          description: d.description || "",
          applicantName: d.applicantName || "",
          applicantContact: d.applicantContact || "",
          factionId: d.factionId || "",
          forumLink: d.forumLink || "",
          severity: d.severity || "",
          deadline: d.deadline || "",
          incidentDate: d.incidentDate || "",
          comments: d.serviceNote || "",
          skExecutorName: d.skExecutorName || "",
        }),
      }, "Редактировать"),
    ),

    // Edit modal
    editingFields && React.createElement(Modal, { onClose: () => setEditingFields(null), maxWidth: 640, persistent: true },
      React.createElement("h2", { style: { ...S.cardTitle, marginBottom: 20 } }, "Редактирование жалобы"),
      React.createElement("div", { style: { marginBottom: 14 } },
        React.createElement("label", { style: { ...S.label, marginBottom: 4 } }, "Описание"),
        React.createElement("textarea", { value: editingFields.description, onChange: e => setEditingFields(prev => ({ ...prev, description: e.target.value })), style: { ...S.textarea, minHeight: 80 } }),
      ),
      React.createElement("div", { style: { marginBottom: 14 } },
        React.createElement("label", { style: { ...S.label, marginBottom: 4 } }, "Заявитель"),
        React.createElement("input", { type: "text", value: editingFields.applicantName, onChange: e => setEditingFields(prev => ({ ...prev, applicantName: e.target.value })), style: S.input }),
      ),
      React.createElement("div", { style: { marginBottom: 14 } },
        React.createElement("label", { style: { ...S.label, marginBottom: 4 } }, "Контакт заявителя"),
        React.createElement("input", { type: "text", value: editingFields.applicantContact, onChange: e => setEditingFields(prev => ({ ...prev, applicantContact: e.target.value })), style: S.input }),
      ),
      React.createElement("div", { style: { marginBottom: 14 } },
        React.createElement("label", { style: { ...S.label, marginBottom: 4 } }, "Фракция"),
        React.createElement("select", { value: editingFields.factionId, onChange: e => setEditingFields(prev => ({ ...prev, factionId: e.target.value })), style: S.select },
          React.createElement("option", { value: "" }, "— указать позже —"),
          ...(factions || []).map(f => React.createElement("option", { key: f.id, value: f.id }, f.name || f.id)),
        ),
      ),
      React.createElement("div", { style: { marginBottom: 14 } },
        React.createElement("label", { style: { ...S.label, marginBottom: 4 } }, "Ссылка на форум"),
        React.createElement("input", { type: "text", value: editingFields.forumLink, onChange: e => setEditingFields(prev => ({ ...prev, forumLink: e.target.value })), style: S.input }),
      ),
      React.createElement("div", { style: { marginBottom: 14 } },
        React.createElement("label", { style: { ...S.label, marginBottom: 4 } }, "Дата произошедшего"),
        React.createElement("input", { type: "date", value: editingFields.incidentDate, onChange: e => {
          setEditingFields(prev => {
            const next = { ...prev, incidentDate: e.target.value };
            if (next.severity && CASE_SEVERITY[next.severity]) {
              const from = next.incidentDate ? new Date(next.incidentDate + "T00:00:00") : new Date();
              from.setDate(from.getDate() + CASE_SEVERITY[next.severity].days);
              next.deadline = from.toISOString().slice(0, 10);
            }
            return next;
          });
        }, style: S.input }),
      ),
      React.createElement("div", { style: { marginBottom: 14 } },
        React.createElement("label", { style: { ...S.label, marginBottom: 4 } }, "Тяжесть статьи"),
        React.createElement("select", { value: editingFields.severity, onChange: e => {
          setEditingFields(prev => {
            const next = { ...prev, severity: e.target.value };
            if (next.severity && CASE_SEVERITY[next.severity]) {
              const from = next.incidentDate ? new Date(next.incidentDate + "T00:00:00") : new Date();
              from.setDate(from.getDate() + CASE_SEVERITY[next.severity].days);
              next.deadline = from.toISOString().slice(0, 10);
            }
            return next;
          });
        }, style: S.select },
          React.createElement("option", { value: "" }, "— не указана —"),
          ...Object.entries(CASE_SEVERITY).map(([code, meta]) =>
            React.createElement("option", { key: code, value: code }, meta.label + " (" + meta.days + " дн.)")
          ),
        ),
      ),
      React.createElement("div", { style: { marginBottom: 14 } },
        React.createElement("label", { style: { ...S.label, marginBottom: 4 } }, "Крайний срок"),
        React.createElement("input", { type: "date", value: editingFields.deadline, onChange: e => setEditingFields(prev => ({ ...prev, deadline: e.target.value })), style: S.input }),
      ),
      React.createElement("div", { style: { marginBottom: 14 } },
        React.createElement("label", { style: { ...S.label, marginBottom: 4 } }, d.source === "fsb_transfer" ? "Сотрудник ФСБ (исполнитель)" : "Сотрудник СК (исполнитель)"),
        React.createElement("input", { type: "text", value: editingFields.skExecutorName, onChange: e => setEditingFields(prev => ({ ...prev, skExecutorName: e.target.value })), style: S.input, placeholder: d.source === "fsb_transfer" ? "ФИО сотрудника ФСБ" : "ФИО сотрудника СК" }),
      ),
      React.createElement("div", { style: { marginBottom: 14 } },
        React.createElement("label", { style: { ...S.label, marginBottom: 4 } }, "Комментарий / служебная пометка"),
        React.createElement("textarea", { value: editingFields.comments, onChange: e => setEditingFields(prev => ({ ...prev, comments: e.target.value })), style: { ...S.textarea, minHeight: 50 } }),
      ),
      React.createElement("div", { style: { display: "flex", gap: 10, justifyContent: "flex-end" } },
        React.createElement("button", { className: "btn-hover", style: btn("gold"), onClick: handleSaveEdit, disabled: savingEdit },
          savingEdit ? "Сохранение..." : "Сохранить",
        ),
      ),
    ),

    // General info
    React.createElement("div", { style: sectionStyle },
      sectionTitle("Общая информация"),
      React.createElement("div", { style: { display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(200px, 1fr))", gap: 12 } },
        infoField("Тип", CASE_TYPES[d.caseType] || d.caseType),
        infoField("Источник", CASE_SOURCES[d.source] || d.source),
        infoField("Тяжесть статьи", d.severityLabel || "Не указана"),
        infoField("Заявитель", d.applicantName || "—"),
        infoField("Субъект", d.subject),
        infoField("Фракция", d.factionName || d.factionId || "—"),
        infoField("Дата регистрации", formatDateTime(d.createdAt)),
        infoField("Зарегистрировал", d.createdByName),
      ),
      d.description && React.createElement("div", { style: { marginTop: 12 } },
        React.createElement("div", { style: { fontSize: 12, color: C.textMuted, marginBottom: 4 } }, "Описание"),
        React.createElement("div", { style: { fontSize: 14, color: C.text, whiteSpace: "pre-wrap" } }, d.description),
      ),
      d.forumLink && React.createElement("div", { style: { marginTop: 8 } },
        React.createElement("div", { style: { fontSize: 12, color: C.textMuted, marginBottom: 4 } }, "Ссылка на форум"),
        React.createElement("a", { href: d.forumLink, target: "_blank", rel: "noopener", style: { color: C.accent, fontSize: 14 } }, d.forumLink),
      ),
      d.serviceNote && React.createElement("div", { style: { marginTop: 8 } },
        React.createElement("div", { style: { fontSize: 12, color: C.textMuted, marginBottom: 4 } }, "Служебная пометка"),
        React.createElement("div", { style: { fontSize: 14, color: C.textDim, fontStyle: "italic" } }, d.serviceNote),
      ),
    ),

    // Assignment
    React.createElement("div", { style: sectionStyle },
      sectionTitle("Назначение"),
      React.createElement("div", { className: "case-info-row", style: { display: "flex", gap: 20, flexWrap: "wrap" } },
        // Staff (free text ФИО)
        React.createElement("div", { style: { flex: 1, minWidth: 200 } },
          React.createElement("div", { style: { fontSize: 12, color: C.textMuted, marginBottom: 4 } }, "Следователь"),
          d.assignedStaffName
            ? React.createElement("div", { style: { fontSize: 15, fontWeight: 600, color: C.text } }, d.assignedStaffName)
            : React.createElement("div", { style: { fontSize: 14, color: C.textMuted } }, "Не назначен"),
          !isArchived && React.createElement("div", { style: { marginTop: 6 } },
            !showAssignStaff
              ? React.createElement("button", { className: "btn-hover", style: { ...btn("subtle"), fontSize: 12 }, onClick: () => { setShowAssignStaff(true); setSelectedStaffId(d.assignedStaffName || ""); } },
                  d.assignedStaffId || d.assignedStaffName ? "Сменить" : "Назначить")
              : React.createElement("div", { style: { display: "flex", gap: 6, alignItems: "center", flexWrap: "wrap" } },
                  React.createElement("input", { type: "text", value: selectedStaffId, onChange: e => setSelectedStaffId(e.target.value), style: { ...S.input, fontSize: 13, minWidth: 200 }, placeholder: "ФИО следователя" }),
                  React.createElement("button", { className: "btn-hover", style: { ...btn("gold"), fontSize: 12 }, onClick: handleAssignStaff, disabled: assigningStaff }, "OK"),
                  React.createElement("button", { className: "btn-hover", style: { ...btn("ghost"), fontSize: 12 }, onClick: () => setShowAssignStaff(false) }, "✕"),
                ),
          ),
        ),
        // Supervisor
        React.createElement("div", { style: { flex: 1, minWidth: 200 } },
          React.createElement("div", { style: { fontSize: 12, color: C.textMuted, marginBottom: 4 } }, "Прокурор"),
          d.supervisorName
            ? React.createElement("div", { style: { fontSize: 15, fontWeight: 600, color: C.text } }, d.supervisorName)
            : React.createElement("div", { style: { fontSize: 14, color: C.textMuted } }, "Не назначен"),
          !isArchived && React.createElement("div", { style: { marginTop: 6 } },
            !showAssignSupervisor
              ? React.createElement("button", { className: "btn-hover", style: { ...btn("subtle"), fontSize: 12 }, onClick: () => setShowAssignSupervisor(true) },
                  d.supervisorId ? "Сменить" : "Назначить")
              : React.createElement("div", { style: { display: "flex", gap: 6, alignItems: "center", flexWrap: "wrap" } },
                  React.createElement("select", { value: selectedSupervisorId, onChange: e => setSelectedSupervisorId(e.target.value), style: { ...S.select, fontSize: 13 } },
                    React.createElement("option", { value: "" }, "Выберите сотрудника"),
                    ...candidateUsers.map(u => React.createElement("option", { key: u.id, value: u.id }, (u.surname || "") + " " + (u.name || ""))),
                  ),
                  React.createElement("button", { className: "btn-hover", style: { ...btn("gold"), fontSize: 12 }, onClick: handleAssignSupervisor, disabled: assigningSupervisor }, "OK"),
                  React.createElement("button", { className: "btn-hover", style: { ...btn("ghost"), fontSize: 12 }, onClick: () => setShowAssignSupervisor(false) }, "✕"),
                ),
          ),
        ),
        // SK executor name
        d.skExecutorName && React.createElement("div", { style: { flex: 1, minWidth: 200 } },
          React.createElement("div", { style: { fontSize: 12, color: C.textMuted, marginBottom: 4 } }, d.source === "fsb_transfer" ? "Сотрудник ФСБ (исполнитель)" : "Сотрудник СК (исполнитель)"),
          React.createElement("div", { style: { fontSize: 15, fontWeight: 600, color: C.text } }, d.skExecutorName),
        ),
      ),
    ),

    // Deadline
    React.createElement("div", { style: sectionStyle },
      sectionTitle("Контроль сроков"),
      React.createElement("div", { style: { display: "flex", gap: 20, flexWrap: "wrap", marginBottom: 10 } },
        (() => {
          const isTerminalCase = CASES_TERMINAL_STATUSES_SET.has(d.status);
          const days = daysUntilDeadline(d);
          const over = isOverdue(d);
          const deadlineColor = !d.deadline ? C.textMuted : isTerminalCase ? C.textMuted : over ? C.danger : days !== null && days <= 3 ? "#e67e22" : days !== null && days <= 5 ? "#d69a2d" : C.success;
          const deadlineText = !d.deadline ? "Не установлен" : isTerminalCase ? d.deadline : d.deadline + (over ? " (ПРОСРОЧЕНО)" : days !== null && days <= 3 ? ` (осталось ${days} дн.!)` : days !== null && days <= 5 ? ` (осталось ${days} дн.)` : "");
          return React.createElement("div", null,
            React.createElement("div", { style: { fontSize: 12, color: C.textMuted, marginBottom: 2 } }, "Крайний срок"),
            React.createElement("div", { style: { fontSize: 14, fontWeight: 700, color: deadlineColor, padding: "2px 8px", borderRadius: 6, background: deadlineColor + "18", display: "inline-block" } }, deadlineText),
          );
        })(),
        infoField("Следующий контрольный срок", d.nextControlDate || "—"),
        d.incidentDate && infoField("Дата произошедшего", new Date(d.incidentDate + "T00:00:00").toLocaleDateString("ru-RU")),
        d.decisionDeadline && infoField("Срок принятия решения (48ч)", (() => {
          const dd = new Date(d.decisionDeadline);
          const now = new Date();
          const isTerminal = CASES_TERMINAL_STATUSES_SET.has(d.status);
          const pastInvestigation = ["criminal_case_opened", "criminal_case_refused", "transferred_investigation", "prosecution_review", "prosecution_approved", "prosecution_refused", "sent_to_court", "verdict_issued", "verdict_guilty", "verdict_partial", "verdict_acquitted", "completed", "archive"].includes(d.status);
          const overDecision = !isTerminal && !pastInvestigation && dd < now;
          const ddText = dd.toLocaleString("ru-RU", { day: "2-digit", month: "2-digit", year: "numeric", hour: "2-digit", minute: "2-digit" });
          return overDecision ? ddText + " (ПРОСРОЧЕНО)" : ddText;
        })()),
        d.deadlineExtended && infoField("Продлено", "Да (с " + (d.deadlineOriginal || "—") + ")"),
        d.deadlineExtensionReason && infoField("Основание продления", d.deadlineExtensionReason),
      ),
      // Set deadline button (when no deadline set)
      isManager && !d.deadline && !CASES_TERMINAL_STATUSES_SET.has(d.status) && React.createElement("div", { style: { marginTop: 6 } },
        !showSetDeadline
          ? React.createElement("button", { className: "btn-hover", style: { ...btn("gold"), fontSize: 13 }, onClick: () => setShowSetDeadline(true) }, "Назначить крайний срок")
          : React.createElement("div", { style: { display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" } },
              React.createElement("input", { type: "date", value: newDeadlineValue, onChange: e => setNewDeadlineValue(e.target.value), style: { ...S.input, fontSize: 13, width: 180 } }),
              React.createElement("button", { className: "btn-hover", style: { ...btn("gold"), fontSize: 13 }, onClick: handleSetDeadline, disabled: savingDeadline }, savingDeadline ? "..." : "Установить"),
              React.createElement("button", { className: "btn-hover", style: { ...btn("ghost"), fontSize: 13 }, onClick: () => { setShowSetDeadline(false); setNewDeadlineValue(""); } }, "Отмена"),
            ),
      ),
      // Extend deadline button
      isManager && d.deadline && !d.deadlineExtended && !CASES_TERMINAL_STATUSES_SET.has(d.status) && React.createElement("div", { style: { marginTop: 6 } },
        !showExtend
          ? React.createElement("button", { className: "btn-hover", style: { ...btn("subtle"), fontSize: 13 }, onClick: () => setShowExtend(true) }, "Продлить срок на " + CASES_EXTENSION_DAYS + " дней")
          : React.createElement("div", null,
              extensionImage && React.createElement("div", { style: { marginBottom: 6, position: "relative", display: "inline-block" } },
                React.createElement("img", { src: extensionImage, alt: "Документ", style: { maxWidth: 200, maxHeight: 120, borderRadius: 8, border: "1px solid " + C.border } }),
                React.createElement("button", {
                  style: { position: "absolute", top: 2, right: 2, background: C.danger, color: "#fff", border: "none", borderRadius: "50%", width: 20, height: 20, fontSize: 12, cursor: "pointer", lineHeight: "20px", textAlign: "center", padding: 0 },
                  onClick: () => setExtensionImage(""),
                }, "✕"),
              ),
              React.createElement("div", { style: { display: "flex", gap: 8, alignItems: "flex-start", flexWrap: "wrap" } },
                React.createElement("input", {
                  type: "text", value: extensionReason, onChange: e => setExtensionReason(e.target.value),
                  style: { ...S.input, flex: 1, minWidth: 200, fontSize: 13 },
                  placeholder: "Основание: номер и дата официальной бумаги",
                }),
                React.createElement(ImageUploadButton, {
                  label: "📎",
                  style: { ...btn("subtle"), fontSize: 14, padding: "6px 10px" },
                  onUploaded: (url) => setExtensionImage(url),
                }),
                React.createElement("button", { className: "btn-hover", style: { ...btn("gold"), fontSize: 13 }, onClick: handleExtendDeadline, disabled: savingExtension },
                  savingExtension ? "..." : "Продлить"),
                React.createElement("button", { className: "btn-hover", style: { ...btn("ghost"), fontSize: 13 }, onClick: () => { setShowExtend(false); setExtensionReason(""); setExtensionImage(""); } }, "Отмена"),
              ),
            ),
      ),
    ),

    // Status transition
    allowedTransitions.length > 0 && (isManager || isAssigned || isSupervisor || isCreator) && React.createElement("div", { style: { ...sectionStyle, borderLeft: "4px solid " + C.warning } },
      sectionTitle("Изменение статуса"),
      React.createElement("div", { style: { display: "flex", gap: 8, flexWrap: "wrap", marginBottom: 10 } },
        allowedTransitions.map(st =>
          React.createElement("button", {
            key: st,
            className: "btn-hover",
            style: { ...btn("subtle"), fontSize: 13, borderColor: (CASE_STATUSES[st] || {}).color || C.accent },
            onClick: () => handleChangeStatus(st),
            disabled: savingStatus,
          }, (CASE_STATUSES[st] || {}).icon + " " + (CASE_STATUSES[st] || {}).label),
        ),
      ),
      // Stage result field (if needed for any allowed transition)
      allowedTransitions.some(s => CASE_TRANSITION_REQUIRES_STAGE_RESULT.includes(s)) && React.createElement("div", { style: { marginBottom: 8 } },
        React.createElement("label", { style: { ...S.label, fontSize: 12 } }, "Результат этапа (для перехода)"),
        React.createElement("textarea", { value: stageResult, onChange: e => setStageResult(e.target.value), style: { ...S.textarea, minHeight: 50 }, placeholder: "Обязательно при прекращении, ВУД, утверждении..." }),
      ),
      allowedTransitions.some(s => CASE_TRANSITION_REQUIRES_FINAL_RESULT.includes(s)) && React.createElement("div", { style: { marginBottom: 8 } },
        React.createElement("label", { style: { ...S.label, fontSize: 12 } }, "Итоговый результат"),
        React.createElement("textarea", { value: finalResult, onChange: e => setFinalResult(e.target.value), style: { ...S.textarea, minHeight: 50 }, placeholder: "Обязательно для завершения дела" }),
      ),
      // SK executor name (for criminal_case_opened transition)
      allowedTransitions.includes("criminal_case_opened") && React.createElement("div", { style: { marginBottom: 8 } },
        React.createElement("label", { style: { ...S.label, fontSize: 12 } }, d.source === "fsb_transfer" ? "ФИО сотрудника ФСБ (исполнитель)" : "ФИО сотрудника СК (исполнитель)"),
        React.createElement("input", { type: "text", value: skExecutorName, onChange: e => setSkExecutorName(e.target.value), style: S.input, placeholder: d.source === "fsb_transfer" ? "Фамилия Имя Отчество сотрудника ФСБ" : "Фамилия Имя Отчество сотрудника СК" }),
      ),
      React.createElement("div", { style: { marginBottom: 8 } },
        React.createElement("label", { style: { ...S.label, fontSize: 12 } }, "Комментарий к переходу"),
        React.createElement("input", { type: "text", value: statusComment, onChange: e => setStatusComment(e.target.value), style: S.input, placeholder: "Необязательно" }),
      ),
    ),

    // Timeline
    d.timeline && d.timeline.length > 0 && React.createElement("div", { style: sectionStyle },
      sectionTitle("Таймлайн"),
      React.createElement("div", { style: { position: "relative", paddingLeft: 20 } },
        d.timeline.map((t, i) => React.createElement("div", {
          key: t.id,
          style: { position: "relative", paddingBottom: 16, paddingLeft: 16, borderLeft: i < d.timeline.length - 1 ? "2px solid " + C.textMuted + "33" : "2px solid transparent" },
        },
          React.createElement("div", {
            style: { position: "absolute", left: -7, top: 2, width: 12, height: 12, borderRadius: "50%", background: (CASE_STATUSES[t.toStatus] || {}).color || C.accent },
          }),
          React.createElement("div", { style: { fontSize: 13, fontWeight: 600, color: (CASE_STATUSES[t.toStatus] || {}).color || C.text } }, t.toStatusLabel),
          React.createElement("div", { style: { fontSize: 12, color: C.textMuted } },
            formatDateTime(t.createdAt) + (t.changedByName ? " · " + t.changedByName : ""),
          ),
          t.comment && React.createElement("div", { style: { fontSize: 13, color: C.textDim, marginTop: 2 } }, renderTextWithLinks(t.comment)),
          t.stageResult && React.createElement("div", { style: { fontSize: 13, color: C.warning, marginTop: 2 } }, "Результат: ", renderTextWithLinks(t.stageResult)),
        )),
      ),
    ),

    // Stage/Final result display
    d.stageResult && React.createElement("div", { style: sectionStyle },
      sectionTitle("Результат текущего этапа"),
      React.createElement("div", { style: { fontSize: 14, color: C.text, whiteSpace: "pre-wrap" } }, renderTextWithLinks(d.stageResult)),
    ),
    d.finalResult && React.createElement("div", { style: { ...sectionStyle, borderLeft: "4px solid " + C.success } },
      sectionTitle("Итоговый результат"),
      React.createElement("div", { style: { fontSize: 14, color: C.text, whiteSpace: "pre-wrap" } }, renderTextWithLinks(d.finalResult)),
    ),

    // Links
    React.createElement("div", { style: sectionStyle },
      sectionTitle("Материалы и ссылки"),
      d.links && d.links.length > 0
        ? d.links.map(l => {
            const isImage = /\.(png|jpe?g|webp|gif)$/i.test(l.url);
            return React.createElement("div", { key: l.id, style: { marginBottom: 8 } },
              React.createElement("div", { style: { display: "flex", gap: 8, alignItems: "center" } },
                React.createElement("span", { style: { ...S.tag, fontSize: 11 } }, CASE_LINK_TYPES[l.linkType] || l.linkType),
                React.createElement("a", { href: l.url, target: "_blank", rel: "noopener", style: { color: C.accent, fontSize: 14 } }, l.label || l.url),
                React.createElement("span", { style: { fontSize: 11, color: C.textMuted } }, l.addedByName + " · " + formatDateTime(l.createdAt)),
                (isManager || (l.addedBy === (user && user.id))) && React.createElement("button", {
                  className: "btn-hover",
                  style: { background: "none", border: "none", color: C.danger, cursor: "pointer", fontSize: 13, padding: "2px 6px", opacity: 0.7 },
                  onClick: () => handleDeleteLink(l.id),
                  title: "Удалить ссылку",
                }, "✕"),
              ),
              isImage && React.createElement("a", { href: l.url, target: "_blank", rel: "noopener" },
                React.createElement("img", { src: l.url, alt: l.label || "Изображение", style: { maxWidth: 320, maxHeight: 200, borderRadius: 8, marginTop: 6, border: "1px solid " + C.border, cursor: "pointer" } }),
              ),
            );
          })
        : React.createElement("div", { style: { color: C.textMuted, fontSize: 14 } }, "Ссылки не добавлены"),
      (isManager || isAssigned || isSupervisor || isCreator) && !isArchived && React.createElement("div", { style: { marginTop: 10, display: "flex", gap: 6, flexWrap: "wrap", alignItems: "center" } },
        React.createElement("select", { value: newLinkType, onChange: e => setNewLinkType(e.target.value), style: { ...S.select, fontSize: 12, minWidth: 100 } },
          ...Object.entries(CASE_LINK_TYPES).map(([code, label]) => React.createElement("option", { key: code, value: code }, label)),
        ),
        React.createElement("input", { type: "text", value: newLinkUrl, onChange: e => setNewLinkUrl(e.target.value), style: { ...S.input, fontSize: 13, flex: "1 1 200px" }, placeholder: "URL" }),
        React.createElement("input", { type: "text", value: newLinkLabel, onChange: e => setNewLinkLabel(e.target.value), style: { ...S.input, fontSize: 13, flex: "0 1 150px" }, placeholder: "Название" }),
        React.createElement("button", { className: "btn-hover", style: { ...btn("subtle"), fontSize: 12 }, onClick: handleAddLink, disabled: savingLink }, "+"),
        React.createElement(ImageUploadButton, {
          label: "📎 Загрузить изображение",
          style: { ...btn("subtle"), fontSize: 12 },
          onUploaded: async (url, name) => {
            try {
              await apiRequest("cases.add-link", { caseId, url, linkType: newLinkType || "material", label: name || "Изображение" });
              await loadCase();
            } catch (e) { showError(e.message || "Ошибка"); }
          },
        }),
      ),
    ),

    // Comments
    React.createElement("div", { style: sectionStyle },
      sectionTitle("Комментарии"),
      d.commentsThread && d.commentsThread.length > 0
        ? d.commentsThread.map(c => React.createElement("div", { key: c.id, style: { marginBottom: 10, padding: "8px 12px", background: c.isServiceNote ? "#d69a2d11" : "#0077b611", borderRadius: 8, borderLeft: "3px solid " + (c.isServiceNote ? C.warning : C.accent) } },
            React.createElement("div", { style: { fontSize: 12, color: C.textMuted, marginBottom: 4 } },
              React.createElement("b", null, c.authorName), " · ", formatDateTime(c.createdAt),
              c.isServiceNote && React.createElement("span", { style: { marginLeft: 8, color: C.warning, fontSize: 11 } }, "Служебная пометка"),
            ),
            c.body && React.createElement("div", { style: { fontSize: 14, color: C.text, whiteSpace: "pre-wrap" } }, renderTextWithLinks(c.body)),
            c.imageUrl && React.createElement("a", { href: c.imageUrl, target: "_blank", rel: "noopener" },
              React.createElement("img", { src: c.imageUrl, alt: "Вложение", style: { maxWidth: 300, maxHeight: 180, borderRadius: 8, marginTop: 6, border: "1px solid " + C.border, cursor: "pointer" } }),
            ),
          ))
        : React.createElement("div", { style: { color: C.textMuted, fontSize: 14, marginBottom: 10 } }, "Комментариев пока нет"),
      (isManager || isAssigned || isSupervisor || isCreator) && !isArchived && React.createElement("div", null,
        newCommentImage && React.createElement("div", { style: { marginBottom: 6, position: "relative", display: "inline-block" } },
          React.createElement("img", { src: newCommentImage, alt: "Вложение", style: { maxWidth: 200, maxHeight: 120, borderRadius: 8, border: "1px solid " + C.border } }),
          React.createElement("button", {
            style: { position: "absolute", top: 2, right: 2, background: C.danger, color: "#fff", border: "none", borderRadius: "50%", width: 20, height: 20, fontSize: 12, cursor: "pointer", lineHeight: "20px", textAlign: "center", padding: 0 },
            onClick: () => setNewCommentImage(""),
          }, "✕"),
        ),
        React.createElement("div", { style: { display: "flex", gap: 6, alignItems: "flex-end" } },
          React.createElement("textarea", {
            value: newComment,
            onChange: e => setNewComment(e.target.value),
            style: { ...S.textarea, flex: 1, minHeight: 40, fontSize: 13 },
            placeholder: "Написать комментарий...",
          }),
          React.createElement(ImageUploadButton, {
            label: "📎",
            style: { ...btn("subtle"), fontSize: 14, padding: "6px 10px" },
            onUploaded: (url) => setNewCommentImage(url),
          }),
          React.createElement("button", { className: "btn-hover", style: { ...btn("gold"), fontSize: 13 }, onClick: handleAddComment, disabled: savingComment }, "Отправить"),
        ),
      ),
    ),

    // Discord
    (d.discordThreadId || d.discordMessageId) && React.createElement("div", { style: sectionStyle },
      sectionTitle("Discord"),
      d.discordThreadId && React.createElement("div", { style: { fontSize: 14, color: C.textDim } }, "Thread ID: ", d.discordThreadId),
      d.discordMessageId && React.createElement("div", { style: { fontSize: 14, color: C.textDim } }, "Message ID: ", d.discordMessageId),
      React.createElement("div", { style: { fontSize: 12, color: C.success, marginTop: 4 } }, "✓ Синхронизировано с Discord"),
    ),

    // Actions — admin can delete even archived cases
    (isManager && !isArchived || isAdmin) && React.createElement("div", { style: { marginTop: 20, display: "flex", gap: 10, justifyContent: "flex-end" } },
      React.createElement("button", { className: "btn-hover", style: btn("danger"), onClick: handleDelete }, "Удалить дело"),
    ),
  );
}

// ── Helper: infoField ──

function infoField(label, value) {
  return React.createElement("div", null,
    React.createElement("div", { style: { fontSize: 12, color: C.textMuted, marginBottom: 2 } }, label),
    React.createElement("div", { style: { fontSize: 14, fontWeight: 500, color: C.text } }, value || "—"),
  );
}

// ── CaseAnalyticsDashboard ──

function CaseAnalyticsDashboard({ user, users, factions, checksMeta, enabledSubjects }) {
  const [analytics, setAnalytics] = useState(null);
  const [loading, setLoading] = useState(true);
  const [subjectFilter, setSubjectFilter] = useState("");
  const isFederalOrAdmin = user?.role === "FEDERAL" || hasSystemAdminAccess(user);
  const isSubjectScoped = user?.role === "BOSS" || user?.role === "SENIOR_STAFF" || user?.role === "USP";

  useEffect(() => {
    (async () => {
      setLoading(true);
      try {
        const res = await apiRequest("cases.analytics", { subject: isSubjectScoped ? "" : subjectFilter });
        setAnalytics(res.data);
      } catch (e) {
        setAnalytics(null);
      } finally {
        setLoading(false);
      }
    })();
  }, [subjectFilter]);

  if (loading) return React.createElement("div", { style: { padding: 20 } },
    React.createElement(SkeletonCard, null),
    React.createElement("div", { style: { height: 8 } }),
    React.createElement(SkeletonTable, { rows: 4, cols: 3 }),
  );
  if (!analytics) return React.createElement(EmptyState, {
    icon: "\uD83D\uDCCA",
    title: "\u041D\u0435\u0442 \u0434\u0430\u043D\u043D\u044B\u0445 \u0434\u043B\u044F \u0430\u043D\u0430\u043B\u0438\u0442\u0438\u043A\u0438",
    description: "\u0414\u0430\u043D\u043D\u044B\u0435 \u043F\u043E\u044F\u0432\u044F\u0442\u0441\u044F \u043F\u043E\u0441\u043B\u0435 \u0440\u0435\u0433\u0438\u0441\u0442\u0440\u0430\u0446\u0438\u0438 \u043F\u0435\u0440\u0432\u044B\u0445 \u043E\u0431\u0440\u0430\u0449\u0435\u043D\u0438\u0439",
  });

  const a = analytics;

  const statBoxStyle = (color) => ({
    ...S.statBox,
    borderTop: `3px solid ${color}`,
    textAlign: "center",
    minWidth: 120,
  });

  const handleExportCsv = function() {
    if (!a) return;
    var headers = ["\u0421\u0442\u0430\u0442\u0443\u0441", "\u041A\u043E\u043B\u0438\u0447\u0435\u0441\u0442\u0432\u043E"];
    var rows = Object.entries(a.byStatus || {}).filter(function(e) { return e[1] > 0; }).map(function(e) {
      return [(CASE_STATUSES[e[0]] || {}).label || e[0], e[1]];
    });
    if (typeof exportToCsv === "function") exportToCsv("cases_analytics.csv", headers, rows);
  };

  return React.createElement("div", null,
    // Subject filter + export
    React.createElement("div", { style: { display: "flex", gap: 12, marginBottom: 16, alignItems: "center", flexWrap: "wrap" } },
      isFederalOrAdmin && !isSubjectScoped && React.createElement("select", { value: subjectFilter, onChange: e => setSubjectFilter(e.target.value), style: { ...S.select, flex: "0 0 auto", width: "auto", minWidth: 180 } },
        React.createElement("option", { value: "" }, "\u0412\u0441\u0435 \u0441\u0443\u0431\u044A\u0435\u043A\u0442\u044B"),
        ...enabledSubjects.map(s => React.createElement("option", { key: s, value: s }, s)),
      ),
      React.createElement("button", {
        className: "btn-hover",
        style: { ...btn("subtle"), fontSize: 13 },
        onClick: handleExportCsv,
      }, "\u0421\u043A\u0430\u0447\u0430\u0442\u044C CSV"),
    ),

    // Stat boxes
    React.createElement("div", { className: "stat-row", style: { display: "flex", gap: 10, marginBottom: 20, flexWrap: "wrap" } },
      statBox("Всего дел", a.total, C.accent),
      statBox("В работе", a.totalActive, C.warning),
      statBox("Просрочено", a.overdue, C.danger),
      statBox("Жалоб", a.byType?.appeal || 0, "#0077b6"),
    ),

    // By status
    React.createElement("div", { style: S.card, marginBottom: 16 },
      React.createElement("h3", { style: { fontSize: 16, fontWeight: 700, color: C.text, marginBottom: 12 } }, "По статусам"),
      React.createElement("div", { style: { display: "flex", flexWrap: "wrap", gap: 8 } },
        ...Object.entries(a.byStatus || {}).filter(([_, v]) => v > 0).map(([status, count]) =>
          React.createElement("div", { key: status, style: { display: "flex", alignItems: "center", gap: 6, padding: "4px 10px", background: C.bgCard, borderRadius: 8, border: "1px solid " + ((CASE_STATUSES[status] || {}).color || C.textMuted) + "44" } },
            getCaseStatusBadge(status),
            React.createElement("span", { style: { fontSize: 14, fontWeight: 700, color: C.text } }, count),
          )
        ),
      ),
    ),

    // By source
    React.createElement("div", { style: { ...S.card, marginBottom: 16 } },
      React.createElement("h3", { style: { fontSize: 16, fontWeight: 700, color: C.text, marginBottom: 12 } }, "По источникам"),
      React.createElement("div", { className: "responsive-table-wrap" },
        React.createElement("table", { style: S.table },
          React.createElement("thead", null,
            React.createElement("tr", null,
              React.createElement("th", { style: S.th }, "Источник"),
              React.createElement("th", { style: S.th }, "Количество"),
            ),
          ),
          React.createElement("tbody", null,
            ...Object.entries(a.bySource || {}).filter(([_, v]) => v > 0).map(([src, count]) =>
              React.createElement("tr", { key: src, className: "row-hover" },
                React.createElement("td", { style: S.td, "data-label": "Источник" }, CASE_SOURCES[src] || src),
                React.createElement("td", { style: S.td, "data-label": "Количество" }, count),
              ),
            ),
          ),
        ),
      ),
    ),

    // By staff
    a.byStaff && a.byStaff.length > 0 && React.createElement("div", { style: { ...S.card, marginBottom: 16 } },
      React.createElement("h3", { style: { fontSize: 16, fontWeight: 700, color: C.text, marginBottom: 12 } }, "По сотрудникам"),
      React.createElement("div", { className: "responsive-table-wrap" },
        React.createElement("table", { style: S.table },
          React.createElement("thead", null,
            React.createElement("tr", null,
              React.createElement("th", { style: S.th }, "Сотрудник"),
              React.createElement("th", { style: S.th }, "Назначено"),
              React.createElement("th", { style: S.th }, "Завершено"),
              React.createElement("th", { style: S.th }, "Просрочено"),
            ),
          ),
          React.createElement("tbody", null,
            ...a.byStaff.map(s =>
              React.createElement("tr", { key: s.userId, className: "row-hover" },
                React.createElement("td", { style: S.td, "data-label": "Сотрудник" }, s.name),
                React.createElement("td", { style: S.td, "data-label": "Назначено" }, s.assigned),
                React.createElement("td", { style: S.td, "data-label": "Завершено" }, s.completed),
                React.createElement("td", { style: { ...S.td, color: s.overdue > 0 ? C.danger : C.text, fontWeight: s.overdue > 0 ? 700 : 400 }, "data-label": "Просрочено" }, s.overdue),
              ),
            ),
          ),
        ),
      ),
    ),

    // By faction
    Object.keys(a.byFaction || {}).length > 0 && React.createElement("div", { style: { ...S.card, marginBottom: 16 } },
      React.createElement("h3", { style: { fontSize: 16, fontWeight: 700, color: C.text, marginBottom: 12 } }, "По фракциям"),
      React.createElement("div", { className: "responsive-table-wrap" },
        React.createElement("table", { style: S.table },
          React.createElement("thead", null,
            React.createElement("tr", null,
              React.createElement("th", { style: S.th }, "Фракция"),
              React.createElement("th", { style: S.th }, "Всего"),
              React.createElement("th", { style: S.th }, "В следствии"),
              React.createElement("th", { style: S.th }, "В суде"),
              React.createElement("th", { style: S.th }, "Приговоры"),
              React.createElement("th", { style: S.th }, "Прекращено"),
              React.createElement("th", { style: S.th }, "Архив"),
            ),
          ),
          React.createElement("tbody", null,
            ...Object.entries(a.byFaction).map(([fid, stats]) => {
              const faction = (factions || []).find(f => f.id === fid);
              return React.createElement("tr", { key: fid, className: "row-hover" },
                React.createElement("td", { style: S.td, "data-label": "Фракция" }, faction?.name || fid),
                React.createElement("td", { style: S.td, "data-label": "Всего" }, stats.total),
                React.createElement("td", { style: S.td, "data-label": "В следствии" }, stats.toInvestigation),
                React.createElement("td", { style: S.td, "data-label": "В суде" }, stats.toCourt),
                React.createElement("td", { style: S.td, "data-label": "Приговоры" }, stats.verdicts),
                React.createElement("td", { style: S.td, "data-label": "Прекращено" }, stats.terminated),
                React.createElement("td", { style: S.td, "data-label": "Архив" }, stats.archived || 0),
              );
            }),
          ),
        ),
      ),
    ),
  );
}

function statBox(label, value, color) {
  return React.createElement("div", {
    style: {
      ...S.statBox,
      borderTop: "3px solid " + color,
      textAlign: "center",
      minWidth: 110,
      flex: "1 1 110px",
    },
  },
    React.createElement("div", { style: { fontSize: T.stat, fontWeight: 700, color } },
      typeof CountUp === "function" && typeof value === "number"
        ? React.createElement(CountUp, { value: value })
        : value
    ),
    React.createElement("div", { style: { fontSize: 12, color: C.textMuted, marginTop: 4 } }, label),
  );
}
