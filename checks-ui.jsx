const { useState, useEffect, useMemo, useCallback } = React;

function getCheckDisplayUserName(userLike) {
  if (!userLike) return "—";
  if (userLike.fullName) return userLike.fullName;
  const surname = String(userLike.surname || "").trim();
  const name = String(userLike.name || "").trim();
  const combined = [surname, name].filter(Boolean).join(" ").trim();
  return combined || userLike.login || userLike.id || "—";
}

function getChecksEnabledSubjects(checksMeta = DEFAULT_CHECKS_META) {
  const subjects = Array.isArray(checksMeta?.settings?.enabledSubjects)
    ? checksMeta.settings.enabledSubjects.filter(Boolean)
    : [];
  return subjects.length ? subjects : (DEFAULT_CHECKS_META.settings.enabledSubjects || []);
}

function createChecksCreateForm(user, factions, checksMeta = DEFAULT_CHECKS_META, sessionActor = null) {
  const base = createCheckFormState(user, factions);
  const enabledSubjects = getChecksEnabledSubjects(checksMeta);
  const canChooseSubject = hasSystemAdminAccess(sessionActor) || sessionActor?.role === "FEDERAL";
  const subject = canChooseSubject
    ? (enabledSubjects.includes(user?.subject) ? user.subject : (enabledSubjects[0] || user?.subject || ""))
    : (user?.subject || enabledSubjects[0] || "");
  return {
    ...base,
    subject,
  };
}

function createChecksInterviewEditorState(interview = null) {
  if (!interview) {
    return createInterviewFormState();
  }
  const employee = interview.employee || interview.factionPerson || {};
  return {
    interviewId: interview.id || "",
    factionPerson: {
      externalEmployeeId: employee.externalEmployeeId || "",
      fullName: employee.fullName || "",
      positionTitle: employee.positionTitle || "",
    },
    reviewerComment: interview.reviewerComment || "",
    overrideConsequence: interview.overrideConsequence || "",
    answers: Array.isArray(interview.answers) && interview.answers.length
      ? interview.answers.map(answer => ({
          topicCode: answer.topicCode || "",
          topicLabel: answer.topicLabel || "",
          questionText: answer.questionText || "",
          answerText: answer.answerText || "",
          scoreChoice: answer.scoreChoice || "incorrect",
          reviewerComment: answer.reviewerComment || "",
        }))
      : [{ topicCode: "", topicLabel: "", questionText: "", answerText: "", scoreChoice: "incorrect", reviewerComment: "" }],
  };
}

function getCheckParticipantCandidates(users, subject) {
  const safeSubject = String(subject || "");
  return (users || [])
    .filter(item => item && !item.blocked)
    .filter(item => item.subject === safeSubject || item.subject === GENERAL_SUBJECT || item.role === "FEDERAL" || hasSystemAdminAccess(item))
    .sort((left, right) => {
      const leftPriority = left.subject === safeSubject ? 0 : 1;
      const rightPriority = right.subject === safeSubject ? 0 : 1;
      if (leftPriority !== rightPriority) return leftPriority - rightPriority;
      return getCheckDisplayUserName(left).localeCompare(getCheckDisplayUserName(right), "ru");
    });
}

function normalizeMetricsItems(items) {
  if (Array.isArray(items) && items.length) {
    return items.map(item => ({
      label: String(item?.label || ""),
      value: String(item?.value || ""),
    }));
  }
  return [{ label: "", value: "" }];
}

function buildCheckFormPayloadWithSubject(form) {
  return {
    subject: String(form.subject || "").trim(),
    factionId: String(form.factionId || "").trim(),
    basisText: String(form.basisText || "").trim(),
    startsAt: form.startsAt ? new Date(form.startsAt).toISOString() : "",
    endsAt: form.endsAt ? new Date(form.endsAt).toISOString() : "",
    description: String(form.description || "").trim(),
    typeLabel: String(form.typeLabel || "").trim(),
    notes: String(form.notes || "").trim(),
    participantUserIds: Array.from(new Set((form.participantUserIds || []).filter(Boolean))),
  };
}

function isChecksStaffView(userLike) {
  return (userLike?.role || "") === "STAFF";
}

function isChecksBossView(userLike) {
  return (userLike?.role || "") === "BOSS" && !hasSystemAdminAccess(userLike);
}

function getPrecheckMetricLabel(metricKey) {
  const labels = {
    eventsTotal: "Всего событий",
    detentions: "Задержания",
    fines: "Штрафы",
    decisions: "Решения",
    warnings: "Предупреждения",
    disciplinary: "Дисциплинарные взыскания",
    officialVisits: "Официальные визиты",
    uniquePersons: "Уникальные сотрудники",
  };
  return labels[metricKey] || metricKey;
}

function getReportSectionLabel(sectionKey) {
  const normalized = String(sectionKey || "").trim();
  const labels = {
    general: "Общий комментарий",
    employee: "Комментарий по сотруднику",
    comment: "Комментарий участника",
  };
  return labels[normalized] || normalized || "Материалы";
}

function getCurrentCheckForStaff(items) {
  const safeItems = Array.isArray(items) ? items.filter(Boolean) : [];
  if (!safeItems.length) return null;
  const statusPriority = {
    active: 0,
    planned: 1,
    completed: 2,
    pending_approval: 3,
    approved: 4,
    cancelled: 5,
  };
  return [...safeItems].sort((left, right) => {
    const leftPriority = statusPriority[left?.status] ?? 99;
    const rightPriority = statusPriority[right?.status] ?? 99;
    if (leftPriority !== rightPriority) return leftPriority - rightPriority;
    const leftDate = new Date(left?.startsAt || 0).getTime();
    const rightDate = new Date(right?.startsAt || 0).getTime();
    if (leftPriority <= 1) return leftDate - rightDate;
    return rightDate - leftDate;
  })[0];
}

function getChecksVisibleTabs(userLike) {
  if (isChecksStaffView(userLike)) {
    return [
      ["general", "Общая информация"],
      ["participants", "Участники"],
      ["reports", "Отчёты"],
      ["interviews", "Опросы"],
    ];
  }
  return [
    ["general", "Общая информация"],
    ["participants", "Участники"],
    ["reports", "Отчёты"],
    ["interviews", "Опросы"],
    ["summary", "Сводка"],
    ["final", "Итоговый отчёт"],
    ["gp-notes", "Пометки ГП"],
  ];
}

function getChecksResolvedTabs(userLike) {
  return getChecksVisibleTabs(userLike);
}

function renderMetricValue(value) {
  if (value === null || value === undefined || value === "") return "—";
  if (Array.isArray(value)) return value.length;
  if (typeof value === "object") return JSON.stringify(value);
  return String(value);
}

function CheckStatusBadge({ status }) {
  const meta = getCheckStatusMeta(status);
  return (
    <span
      style={{
        ...badge(meta.color),
        display: "inline-flex",
        alignItems: "center",
        justifyContent: "center",
        alignSelf: "flex-start",
        whiteSpace: "nowrap",
        lineHeight: 1,
      }}
    >
      {meta.label}
    </span>
  );
}

function ChecksAwareCalendar({ events = [] }) {
  const fallbackEvents = useMemo(() => ([
    { id: "fallback_1", title: "Совещание прокуроров субъектов", description: "Общий координационный сбор", startsAt: "2026-03-15T10:00:00+03:00", statusLabel: "Совещание" },
    { id: "fallback_2", title: "Сдача квартальных материалов", description: "Контрольный срок подготовки материалов", startsAt: "2026-03-18T18:00:00+03:00", statusLabel: "Дедлайн" },
  ]), []);

  const items = useMemo(() => {
    const source = Array.isArray(events) && events.length ? events : fallbackEvents;
    return [...source].sort((left, right) => new Date(left.startsAt || 0) - new Date(right.startsAt || 0));
  }, [events, fallbackEvents]);

  return (
    <div className="fade-in">
      <h1 style={S.pageTitle}>Календарь</h1>
      <p style={S.pageSubtitle}>Ключевые даты, события проверок и служебные отметки.</p>
      <div style={S.card}>
        {items.length === 0 ? (
          <div style={{ color: C.textMuted }}>Пока нет календарных событий.</div>
        ) : (
          items.map(event => (
            <div key={event.id} style={{ padding: "16px 18px", borderLeft: `3px solid ${C.gold}`, marginBottom: 12, background: C.bgInput, borderRadius: "0 10px 10px 0" }}>
              <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 12, flexWrap: "wrap" }}>
                <span style={{ color: C.text, fontSize: T.bodyStrong, fontWeight: 700 }}>{event.title || "Событие"}</span>
                <span style={badge(C.blue)}>{event.statusLabel || "Событие"}</span>
              </div>
              {event.description ? <div style={{ fontSize: T.body, color: C.textDim, marginTop: 8, lineHeight: 1.55 }}>{event.description}</div> : null}
              <div style={{ fontSize: T.meta, color: C.textMuted, marginTop: 8 }}>{formatDateTime(event.startsAt)}</div>
            </div>
          ))
        )}
      </div>
    </div>
  );
}

function PageChecks({ user, currentUser, sessionUser, users, factions, positions, checksMeta, onRefreshChecksMeta }) {
  const sessionActor = currentUser || sessionUser || user;
  const enabledSubjects = useMemo(() => getChecksEnabledSubjects(checksMeta), [checksMeta]);
  const isPreviewMode = Boolean(user?.isPreviewUser);
  const canMutate = !isPreviewMode;
  const isStaffView = isChecksStaffView(user);
  const isBossView = isChecksBossView(user);

  const [items, setItems] = useState([]);
  const [selectedId, setSelectedId] = useState("");
  const [detail, setDetail] = useState(null);
  const [listBusy, setListBusy] = useState(false);
  const [detailBusy, setDetailBusy] = useState(false);
  const [actionBusy, setActionBusy] = useState("");
  const [listError, setListError] = useState("");
  const [detailError, setDetailError] = useState("");
  const [activeTab, setActiveTab] = useState("general");
  const [scope, setScope] = useState(
    user?.role === "FEDERAL" || hasSystemAdminAccess(user)
      ? ""
      : (checksMeta?.permissions?.canCreate ? "owned" : "assigned")
  );
  const [statusFilter, setStatusFilter] = useState("");
  const [monthFilter, setMonthFilter] = useState(formatMonthInputValue(getReportPeriodKey(new Date())));
  const [subjectFilter, setSubjectFilter] = useState(
    user?.role === "FEDERAL" || hasSystemAdminAccess(user) ? "" : (enabledSubjects.includes(user?.subject) ? user.subject : "")
  );
  const [factionFilter, setFactionFilter] = useState("");
  const [searchText, setSearchText] = useState("");
  const [viewMode, setViewMode] = useState("list");
  const [createOpen, setCreateOpen] = useState(false);
  const [createForm, setCreateForm] = useState(() => createChecksCreateForm(user, factions, checksMeta, sessionActor));
  const [metadataForm, setMetadataForm] = useState(() => createChecksCreateForm(user, factions, checksMeta, sessionActor));
  const [participantsSelection, setParticipantsSelection] = useState([]);
  const [reportEditorOpen, setReportEditorOpen] = useState(false);
  const [editingReportId, setEditingReportId] = useState("");
  const [reportForm, setReportForm] = useState(() => ({ ...createCheckReportFormState(), metricsItems: normalizeMetricsItems(createCheckReportFormState().metricsItems) }));
  const [interviewEditorOpen, setInterviewEditorOpen] = useState(false);
  const [interviewForm, setInterviewForm] = useState(() => createChecksInterviewEditorState());
  const [approvalForm, setApprovalForm] = useState({ finalRating: "good", finalConclusion: "", resolutionText: "" });
  const [gpNoteText, setGpNoteText] = useState("");
  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false);
  const [deletePassword, setDeletePassword] = useState("");
  const [deletePasswordError, setDeletePasswordError] = useState("");
  const [deleteBusy, setDeleteBusy] = useState(false);

  const createParticipantCandidates = useMemo(
    () => getCheckParticipantCandidates(users, createForm.subject || user?.subject),
    [users, createForm.subject, user]
  );

  const detailParticipantCandidates = useMemo(
    () => getCheckParticipantCandidates(users, detail?.check?.subject || user?.subject),
    [users, detail, user]
  );

  const visibleTabs = useMemo(() => getChecksResolvedTabs(user), [user]);
  const effectiveScope = isBossView ? "" : scope;
  const effectiveStatusFilter = isBossView ? "" : statusFilter;
  const effectiveSubjectFilter = isBossView ? (user?.subject || "") : subjectFilter;
  const effectiveFactionFilter = isBossView ? "" : factionFilter;

  const filteredItems = useMemo(() => {
    if (!searchText.trim()) return items;
    const q = searchText.trim().toLowerCase();
    return items.filter(item =>
      (item.factionName || "").toLowerCase().includes(q) ||
      (item.basisText || "").toLowerCase().includes(q) ||
      (item.subject || "").toLowerCase().includes(q)
    );
  }, [items, searchText]);

  const loadList = useCallback(async (preferredId = null) => {
    setListBusy(true);
    setListError("");
    try {
      const response = await apiRequestWithParams("checks.list", {
        scope: effectiveScope,
        status: effectiveStatusFilter,
        month: monthFilter,
        subject: effectiveSubjectFilter,
        factionId: effectiveFactionFilter,
      });
      let nextItems = Array.isArray(response.items) ? response.items : [];
      if (isPreviewMode && user?.role === "BOSS" && isSubjectProsecutorPositionId(user?.positionId, positions) && user?.subject) {
        nextItems = nextItems.filter(item => item.subject === user.subject);
      }
      if (isStaffView) {
        const currentStaffCheck = getCurrentCheckForStaff(nextItems);
        nextItems = currentStaffCheck ? [currentStaffCheck] : [];
      }
      setItems(nextItems);
      const nextSelectedId =
        (preferredId && nextItems.some(item => item.id === preferredId) && preferredId)
        || (selectedId && nextItems.some(item => item.id === selectedId) && selectedId)
        || nextItems[0]?.id
        || "";
      setSelectedId(nextSelectedId);
      if (!nextSelectedId) {
        setDetail(null);
      }
    } catch (error) {
      setListError(error.message || "Не удалось загрузить список проверок");
    } finally {
      setListBusy(false);
    }
  }, [effectiveScope, effectiveStatusFilter, monthFilter, effectiveSubjectFilter, effectiveFactionFilter, selectedId, isPreviewMode, user, positions, isStaffView]);

  const loadDetail = useCallback(async (checkId) => {
    if (!checkId) {
      setDetail(null);
      return;
    }
    setDetailBusy(true);
    setDetailError("");
    try {
      const response = await apiRequestWithParams("checks.get", { id: checkId });
      setDetail(response.detail || null);
    } catch (error) {
      setDetailError(error.message || "Не удалось загрузить карточку проверки");
    } finally {
      setDetailBusy(false);
    }
  }, []);

  useEffect(() => {
    void loadList();
  }, [loadList]);

  useEffect(() => {
    if (selectedId) {
      void loadDetail(selectedId);
    }
  }, [selectedId, loadDetail]);

  useEffect(() => {
    setCreateForm(prev => {
      const next = prev?.subject ? prev : createChecksCreateForm(user, factions, checksMeta, sessionActor);
      if (!(hasSystemAdminAccess(sessionActor) || sessionActor?.role === "FEDERAL")) {
        return { ...next, subject: user?.subject || next.subject };
      }
      return next;
    });
  }, [user, factions, checksMeta, sessionActor]);

  useEffect(() => {
    if (!detail?.check) return;
    setMetadataForm({
      subject: detail.check.subject || "",
      factionId: detail.check.factionId || "",
      basisText: detail.check.basisText || "",
      startsAt: toDateTimeLocalValue(detail.check.startsAt),
      endsAt: toDateTimeLocalValue(detail.check.endsAt),
      description: detail.check.description || "",
      typeCode: detail.check.typeCode || "",
      typeLabel: detail.check.typeLabel || "",
      notes: detail.check.notesText || "",
      participantUserIds: (detail.participants || []).map(item => item.userId),
    });
    setParticipantsSelection((detail.participants || []).map(item => item.userId));
    setApprovalForm({
      finalRating: detail.check.finalRating || "good",
      finalConclusion: detail.check.finalConclusion || "",
      resolutionText: detail.check.resolutionText || "",
    });
  }, [detail]);

  useEffect(() => {
    if (!visibleTabs.some(([id]) => id === activeTab)) {
      setActiveTab(visibleTabs[0]?.[0] || "general");
    }
  }, [activeTab, visibleTabs]);

  const refreshAfterMutation = useCallback(async (preferredId = selectedId) => {
    await loadList(preferredId);
    if (preferredId) {
      await loadDetail(preferredId);
    }
    if (typeof onRefreshChecksMeta === "function") {
      onRefreshChecksMeta();
    }
  }, [selectedId, loadList, loadDetail, onRefreshChecksMeta]);

  const runAction = useCallback(async (key, task) => {
    if (!canMutate) return;
    setActionBusy(key);
    setDetailError("");
    try {
      await task();
    } catch (error) {
      setDetailError(error.message || "Не удалось выполнить действие");
    } finally {
      setActionBusy("");
    }
  }, [canMutate]);

  const openCreate = () => {
    setCreateForm(createChecksCreateForm(user, factions, checksMeta, sessionActor));
    setCreateOpen(true);
  };

  const openReportEditor = (report = null) => {
    const nextState = createCheckReportFormState(report);
    setEditingReportId(report?.id || "");
    setReportForm({
      ...nextState,
      metricsItems: normalizeMetricsItems(nextState.metricsItems),
    });
    setReportEditorOpen(true);
    setActiveTab("reports");
  };

  const openInterviewEditor = (interview = null) => {
    setInterviewForm(createChecksInterviewEditorState(interview));
    setInterviewEditorOpen(true);
    setActiveTab("interviews");
  };

  const handleCreate = () => runAction("create", async () => {
    const response = await apiRequestWithParams("checks.create", {}, buildCheckFormPayloadWithSubject(createForm));
    setCreateOpen(false);
    setCreateForm(createChecksCreateForm(user, factions, checksMeta, sessionActor));
    const nextId = response.detail?.check?.id || "";
    await refreshAfterMutation(nextId);
  });

  const handleSaveMetadata = () => runAction("metadata", async () => {
    if (!detail?.check?.id) return;
    await apiRequestWithParams("checks.update", { id: detail.check.id }, buildCheckFormPayloadWithSubject(metadataForm));
    await refreshAfterMutation(detail.check.id);
  });

  const handleSaveParticipants = () => runAction("participants", async () => {
    if (!detail?.check?.id) return;
    await apiRequestWithParams("checks.participants.sync", { id: detail.check.id }, {
      participantUserIds: Array.from(new Set(participantsSelection.filter(Boolean))),
    });
    await refreshAfterMutation(detail.check.id);
  });

  const handleTransition = (action, key = action) => runAction(key, async () => {
    if (!detail?.check?.id) return;
    await apiRequestWithParams(action, { id: detail.check.id }, {});
    await refreshAfterMutation(detail.check.id);
  });

  const handleSaveReport = () => runAction("report", async () => {
    if (!detail?.check?.id) return;
    const payload = buildCheckReportPayload({
      ...reportForm,
      metricsItems: normalizeMetricsItems(reportForm.metricsItems).filter(item => item.label || item.value),
    });
    if (editingReportId) {
      await apiRequestWithParams("checks.reports.update", { id: detail.check.id, reportId: editingReportId }, payload);
    } else {
      await apiRequestWithParams("checks.reports.create", { id: detail.check.id }, payload);
    }
    setReportEditorOpen(false);
    setEditingReportId("");
    setReportForm({ ...createCheckReportFormState(), metricsItems: normalizeMetricsItems([]) });
    await refreshAfterMutation(detail.check.id);
  });

  const handleUploadReportFile = (reportId, file) => runAction(`file_${reportId}`, async () => {
    if (!detail?.check?.id || !file) return;
    await uploadCheckReportFile({ checkId: detail.check.id, reportId, file });
    await refreshAfterMutation(detail.check.id);
  });

  const handleSaveInterview = () => runAction("interview", async () => {
    if (!detail?.check?.id) return;
    await apiRequestWithParams("checks.interviews.upsert", { id: detail.check.id }, buildCheckInterviewPayload(interviewForm));
    setInterviewEditorOpen(false);
    setInterviewForm(createChecksInterviewEditorState());
    await refreshAfterMutation(detail.check.id);
  });

  const handleApprove = () => runAction("approve", async () => {
    if (!detail?.check?.id) return;
    await apiRequestWithParams("checks.approve", { id: detail.check.id }, {
      finalRating: approvalForm.finalRating,
      finalConclusion: approvalForm.finalConclusion,
      resolutionText: approvalForm.resolutionText,
    });
    await refreshAfterMutation(detail.check.id);
  });

  const handleAddGpNote = () => runAction("gp_note", async () => {
    if (!detail?.check?.id || !gpNoteText.trim()) return;
    await apiRequestWithParams("checks.gp-notes.create", { id: detail.check.id }, { noteText: gpNoteText.trim() });
    setGpNoteText("");
    await refreshAfterMutation(detail.check.id);
  });

  const openDeleteConfirm = () => {
    if (!canMutate || !detail?.check?.id || !detail?.permissions?.canDelete) return;
    setDeletePassword("");
    setDeletePasswordError("");
    setDeleteConfirmOpen(true);
  };

  const closeDeleteConfirm = () => {
    if (deleteBusy) return;
    setDeleteConfirmOpen(false);
    setDeletePassword("");
    setDeletePasswordError("");
  };

  const handleDeleteCheck = async () => {
    if (!detail?.check?.id || deleteBusy) return;
    setDeleteBusy(true);
    setDeletePasswordError("");
    setDetailError("");
    try {
      await apiRequestWithParams("checks.delete", { id: detail.check.id }, { password: deletePassword.trim() });
      setDeleteConfirmOpen(false);
      setDeletePassword("");
      setDeletePasswordError("");
      setDetail(null);
      setSelectedId("");
      setActiveTab("general");
      await refreshAfterMutation("");
    } catch (error) {
      setDeletePasswordError(error.message || "Не удалось удалить проверку");
    } finally {
      setDeleteBusy(false);
    }
  };

  const currentCheck = detail?.check || null;
  const permissions = detail?.permissions || {};
  const summary = detail?.approvedSummary?.summary || detail?.draftSummary?.summary || null;
  const counters = checksMeta?.counters || DEFAULT_CHECKS_META.counters;

  const renderMetricEditor = (items, onChange) => (
    <div style={{ display: "grid", gap: 10 }}>
      {(items || []).map((item, index) => (
        <div key={`metric_${index}`} style={{ display: "grid", gridTemplateColumns: "1.4fr 0.8fr auto", gap: 10, alignItems: "center" }}>
          <input
            style={S.input}
            value={item.label}
            placeholder="Показатель"
            onChange={e => onChange((items || []).map((entry, entryIndex) => entryIndex === index ? { ...entry, label: e.target.value } : entry))}
          />
          <input
            style={S.input}
            value={item.value}
            placeholder="Значение"
            onChange={e => onChange((items || []).map((entry, entryIndex) => entryIndex === index ? { ...entry, value: e.target.value } : entry))}
          />
          <button
            className="btn-hover"
            type="button"
            style={btn("subtle")}
            onClick={() => onChange((items || []).length > 1 ? (items || []).filter((_, entryIndex) => entryIndex !== index) : [{ label: "", value: "" }])}
          >
            Убрать
          </button>
        </div>
      ))}
      <button className="btn-hover" type="button" style={btn("ghost")} onClick={() => onChange([...(items || []), { label: "", value: "" }])}>
        Добавить показатель
      </button>
    </div>
  );

  const renderInterviewAnswersEditor = () => (
    <div style={{ display: "grid", gap: 12 }}>
      {(interviewForm.answers || []).map((answer, index) => (
        <div key={`answer_${index}`} style={{ background: C.bgInput, border: `1px solid ${C.border}`, borderRadius: 12, padding: 14 }}>
          <div style={{ display: "flex", justifyContent: "space-between", gap: 12, alignItems: "center", marginBottom: 12 }}>
            <div style={{ fontWeight: 700, color: C.text }}>Вопрос {index + 1}</div>
            <button
              className="btn-hover"
              type="button"
              style={btn("subtle")}
              onClick={() => setInterviewForm(prev => ({
                ...prev,
                answers: prev.answers.length > 1 ? prev.answers.filter((_, entryIndex) => entryIndex !== index) : [{ topicCode: "", topicLabel: "", questionText: "", answerText: "", scoreChoice: "incorrect", reviewerComment: "" }],
              }))}
            >
              Убрать
            </button>
          </div>
          <div style={{ display: "grid", gap: 10 }}>
            <input
              style={S.input}
              value={answer.topicLabel || ""}
              placeholder="Тема / блок вопросов"
              onChange={e => setInterviewForm(prev => ({
                ...prev,
                answers: prev.answers.map((entry, entryIndex) => entryIndex === index ? { ...entry, topicLabel: e.target.value } : entry),
              }))}
            />
            <textarea
              style={S.textarea}
              value={answer.questionText || ""}
              placeholder="Текст вопроса"
              onChange={e => setInterviewForm(prev => ({
                ...prev,
                answers: prev.answers.map((entry, entryIndex) => entryIndex === index ? { ...entry, questionText: e.target.value } : entry),
              }))}
            />
            <textarea
              style={S.textarea}
              value={answer.answerText || ""}
              placeholder="Краткое содержание ответа"
              onChange={e => setInterviewForm(prev => ({
                ...prev,
                answers: prev.answers.map((entry, entryIndex) => entryIndex === index ? { ...entry, answerText: e.target.value } : entry),
              }))}
            />
            <select
              style={S.select}
              value={answer.scoreChoice || "incorrect"}
              onChange={e => setInterviewForm(prev => ({
                ...prev,
                answers: prev.answers.map((entry, entryIndex) => entryIndex === index ? { ...entry, scoreChoice: e.target.value } : entry),
              }))}
            >
              {INTERVIEW_SCORE_OPTIONS.map(option => (
                <option key={option.id} value={option.id}>
                  {option.label} ({option.value})
                </option>
              ))}
            </select>
            <textarea
              style={S.textarea}
              value={answer.reviewerComment || ""}
              placeholder="Комментарий проверяющего по ответу"
              onChange={e => setInterviewForm(prev => ({
                ...prev,
                answers: prev.answers.map((entry, entryIndex) => entryIndex === index ? { ...entry, reviewerComment: e.target.value } : entry),
              }))}
            />
          </div>
        </div>
      ))}
      <button
        className="btn-hover"
        type="button"
        style={btn("ghost")}
        onClick={() => setInterviewForm(prev => ({
          ...prev,
          answers: [...(prev.answers || []), { topicCode: "", topicLabel: "", questionText: "", answerText: "", scoreChoice: "incorrect", reviewerComment: "" }],
        }))}
      >
        Добавить вопрос
      </button>
    </div>
  );

  const renderSummaryBlock = () => {
    if (!summary) {
      return <div style={{ color: C.textMuted }}>Сводка появится после сохранения материалов проверки.</div>;
    }

    const preMetrics = summary.preCheckSummary?.metrics || {};
    const currentSummary = summary.currentCheckSummary || {};
    const reportsSummary = currentSummary.reportsSummary || {};
    const interviewsSummary = currentSummary.interviewsSummary || {};

    return (
      <div style={{ display: "grid", gap: 18 }}>
        <div style={{ ...S.row, marginBottom: 0 }}>
          <StatBox value={preMetrics.eventsTotal || 0} label="Событий до проверки" color={C.blue} />
          <StatBox value={reportsSummary.reportsCount || 0} label="Отчётов участников" color={C.warning} />
          <StatBox value={interviewsSummary.employeesCount || 0} label="Опрошено сотрудников" color={C.gold} />
          <StatBox value={interviewsSummary.averageTotalScore || 0} label="Средний балл" color={C.success} />
        </div>

        <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(220px, 1fr))", gap: 12 }}>
          {Object.entries(preMetrics)
            .filter(([key]) => key !== "topReasons")
            .map(([key, value]) => (
              <div key={key} style={{ background: C.bgInput, border: `1px solid ${C.border}`, borderRadius: 12, padding: 14 }}>
                <div style={{ fontSize: T.meta, color: C.textMuted }}>{getPrecheckMetricLabel(key)}</div>
                <div style={{ fontSize: T.title, color: C.text, marginTop: 6 }}>{renderMetricValue(value)}</div>
              </div>
            ))}
        </div>

        {(preMetrics.topReasons || []).length ? (
          <div style={{ background: C.bgInput, border: `1px solid ${C.border}`, borderRadius: 12, padding: 16 }}>
            <div style={{ ...S.cardTitle, marginBottom: 12 }}>Основные причины за месяц до проверки</div>
            <div style={{ display: "grid", gap: 8 }}>
              {(preMetrics.topReasons || []).map((item, index) => (
                <div key={`${item.reason}_${index}`} style={{ display: "flex", justifyContent: "space-between", gap: 12 }}>
                  <span style={{ color: C.text }}>{item.reason}</span>
                  <span style={{ color: C.gold }}>{item.count}</span>
                </div>
              ))}
            </div>
          </div>
        ) : null}

        <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(280px, 1fr))", gap: 16 }}>
          <div style={{ background: C.bgInput, border: `1px solid ${C.border}`, borderRadius: 12, padding: 16 }}>
            <div style={{ ...S.cardTitle, marginBottom: 12 }}>Сводка по отчётам</div>
            <div style={{ display: "grid", gap: 8 }}>
              <div style={{ color: C.textDim }}>Нарушений: <span style={{ color: C.text }}>{reportsSummary.violationsCount || 0}</span></div>
              <div style={{ color: C.textDim }}>Вложений: <span style={{ color: C.text }}>{reportsSummary.attachmentsCount || 0}</span></div>
              <div style={{ color: C.textDim }}>Разделы:</div>
              <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                {Object.entries(reportsSummary.reportsBySection || {}).map(([section, count]) => (
                  <span key={section} style={badge(C.blue)}>{getReportSectionLabel(section)}: {count}</span>
                ))}
              </div>
            </div>
          </div>
          <div style={{ background: C.bgInput, border: `1px solid ${C.border}`, borderRadius: 12, padding: 16 }}>
            <div style={{ ...S.cardTitle, marginBottom: 12 }}>Сводка по опросам</div>
            <div style={{ display: "grid", gap: 8 }}>
              <div style={{ color: C.textDim }}>Ответов всего: <span style={{ color: C.text }}>{interviewsSummary.answersCount || 0}</span></div>
              <div style={{ color: C.textDim }}>Распределение оценок:</div>
              <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                {Object.entries(interviewsSummary.gradeDistribution || {}).map(([grade, count]) => (
                  <span key={grade} style={badge(C.success)}>{grade}: {count}</span>
                ))}
              </div>
            </div>
          </div>
        </div>

        {(currentSummary.participantSummary || []).length ? (
          <div style={{ background: C.bgInput, border: `1px solid ${C.border}`, borderRadius: 12, padding: 16 }}>
            <div style={{ ...S.cardTitle, marginBottom: 12 }}>Участники проверки</div>
            <table style={S.table}>
              <thead>
                <tr>
                  <th style={S.th}>Участник</th>
                  <th style={S.th}>Отчётов</th>
                  <th style={S.th}>Опросов</th>
                </tr>
              </thead>
              <tbody>
                {currentSummary.participantSummary.map((item, index) => (
                  <tr key={`${item.user?.id || index}`}>
                    <td style={S.td}>{getCheckDisplayUserName(item.user)}</td>
                    <td style={S.td}>{item.reportCount || 0}</td>
                    <td style={S.td}>{item.interviewCount || 0}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}

        {(summary.interviewRoster || []).length ? (
          <div style={{ background: C.bgInput, border: `1px solid ${C.border}`, borderRadius: 12, padding: 16 }}>
            <div style={{ ...S.cardTitle, marginBottom: 12 }}>Пофамильная сводка по опросам</div>
            <div style={{ display: "grid", gap: 14 }}>
              {summary.interviewRoster.map((item, index) => (
                <div key={`${item.employee?.profileId || item.employee?.externalEmployeeId || index}`} style={{ border: `1px solid ${C.border}`, borderRadius: 12, padding: 14 }}>
                  <div style={{ display: "flex", justifyContent: "space-between", gap: 12, flexWrap: "wrap" }}>
                    <div>
                      <div style={{ fontWeight: 700, color: C.text }}>{item.employee?.fullName || "Без ФИО"}</div>
                      <div style={{ fontSize: T.meta, color: C.textMuted, marginTop: 4 }}>
                        Звание: {item.employee?.positionTitle || "—"} • ID {item.employee?.externalEmployeeId || "—"}
                      </div>
                    </div>
                    <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                      <span style={badge(C.gold)}>Итог: {item.finalGradeLabel || "—"}</span>
                      <span style={badge(C.blue)}>Баллы: {item.totalScore ?? 0}</span>
                    </div>
                  </div>
                  <div style={{ display: "grid", gap: 10, marginTop: 12 }}>
                    {(item.answers || []).map((answer, answerIndex) => (
                      <div key={`${item.employee?.externalEmployeeId || index}_${answerIndex}`} style={{ background: C.bg, borderRadius: 10, padding: 12, border: `1px solid ${C.border}` }}>
                        <div style={{ color: C.text, fontWeight: 700 }}>{answer.questionText || "Вопрос без текста"}</div>
                        <div style={{ color: C.textDim, marginTop: 6, lineHeight: 1.6 }}>{answer.answerText || "Ответ не указан"}</div>
                        <div style={{ display: "flex", gap: 8, flexWrap: "wrap", marginTop: 10 }}>
                          <span style={badge(C.warning)}>{answer.scoreChoice || "—"}</span>
                          <span style={badge(C.success)}>{answer.scoreValue ?? 0} балл.</span>
                        </div>
                      </div>
                    ))}
                  </div>
                  <div style={{ marginTop: 12, color: C.textDim, lineHeight: 1.6 }}>
                    <div>Рекомендованное последствие: <span style={{ color: C.text }}>{item.effectiveConsequence || item.recommendedConsequence || "—"}</span></div>
                    {item.reviewerComment ? <div style={{ marginTop: 6 }}>Комментарий проверяющего: <span style={{ color: C.text }}>{item.reviewerComment}</span></div> : null}
                  </div>
                </div>
              ))}
            </div>
          </div>
        ) : null}
      </div>
    );
  };

  return (
    <div className="fade-in">
      <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start", gap: 16, flexWrap: "wrap" }}>
        <div>
          <h1 style={S.pageTitle}>Проверки</h1>
          <p style={S.pageSubtitle}>Проверки фракций для тестовых субъектов Арбат и Патрики: карточка, участники, отчёты, опросы, сводка, утверждение и передача в Генеральную прокуратуру.</p>
        </div>
        <div style={{ display: "flex", gap: 10, flexWrap: "wrap" }}>
          <button className="btn-hover" style={btn("subtle")} onClick={() => void refreshAfterMutation(selectedId)} disabled={Boolean(actionBusy) || listBusy || detailBusy}>
            Обновить
          </button>
          {checksMeta?.permissions?.canCreate && canMutate ? (
            <button className="btn-hover" style={btn("gold")} onClick={openCreate}>Создать проверку</button>
          ) : null}
        </div>
      </div>

      {isPreviewMode ? (
        <div style={{ ...S.card, marginBottom: 18, padding: 18 }}>
          <div style={{ fontSize: T.body, color: C.warning }}>Режим просмотра. Действия по проверкам отключены.</div>
        </div>
      ) : null}

      <div style={{ ...S.row, marginBottom: 18 }}>
        <StatBox value={counters.owned} label="Мои проверки" color={C.blue} />
        <StatBox value={counters.assigned} label="Назначен участником" color={C.warning} />
        <StatBox value={counters.pendingApproval} label="На утверждении" color={C.gold} />
        <StatBox value={counters.approved} label="Утверждённых" color={C.success} />
      </div>

      {!isStaffView ? (
        <div style={{ display: "flex", gap: 8, marginBottom: 14 }}>
          <button className="btn-hover" style={{ ...btn(viewMode === "list" ? "ghost" : "subtle"), padding: "8px 16px" }} onClick={() => setViewMode("list")}>Список</button>
          <button className="btn-hover" style={{ ...btn(viewMode === "calendar" ? "ghost" : "subtle"), padding: "8px 16px" }} onClick={() => setViewMode("calendar")}>Календарь</button>
        </div>
      ) : null}

      <div className="checks-grid" style={{ display: "grid", gridTemplateColumns: "340px minmax(0, 1fr)", gap: 20, alignItems: "start" }}>
        <div className="checks-sidebar" style={{ display: "flex", flexDirection: "column", gap: 16 }}>
          {isBossView ? (
            <div style={S.card}>
              <div style={S.cardTitle}>Фильтр</div>
              <div style={{ display: "grid", gap: 12 }}>
                <input style={S.input} placeholder="Поиск по названию, основанию..." value={searchText} onChange={e => setSearchText(e.target.value)} />
                <input type="month" style={S.input} value={monthFilter} onChange={e => setMonthFilter(e.target.value)} />
              </div>
            </div>
          ) : null}
          {!isStaffView && !isBossView ? (
            <div style={S.card}>
              <div style={S.cardTitle}>Фильтры</div>
              <div style={{ display: "grid", gap: 12 }}>
                <input style={S.input} placeholder="Поиск по названию, основанию..." value={searchText} onChange={e => setSearchText(e.target.value)} />
                <select style={S.select} value={scope} onChange={e => setScope(e.target.value)}>
                  <option value="">Все доступные</option>
                  <option value="owned">Мои проверки</option>
                  <option value="assigned">Я участник</option>
                  <option value="approved">Утверждённые</option>
                </select>
                <select style={S.select} value={statusFilter} onChange={e => setStatusFilter(e.target.value)}>
                  <option value="">Все статусы</option>
                  {Object.entries(CHECK_STATUS_META).map(([id, meta]) => <option key={id} value={id}>{meta.label}</option>)}
                </select>
                {(user?.role === "FEDERAL" || hasSystemAdminAccess(user)) ? (
                  <select style={S.select} value={subjectFilter} onChange={e => setSubjectFilter(e.target.value)}>
                    <option value="">Все тестовые субъекты</option>
                    {enabledSubjects.map(subject => <option key={subject} value={subject}>{subject}</option>)}
                  </select>
                ) : null}
                <select style={S.select} value={factionFilter} onChange={e => setFactionFilter(e.target.value)}>
                  <option value="">Все фракции</option>
                  {(factions || []).filter(item => item.active !== false).map(item => <option key={item.id} value={item.id}>{item.name}</option>)}
                </select>
                <input type="month" style={S.input} value={monthFilter} onChange={e => setMonthFilter(e.target.value)} />
              </div>
            </div>
          ) : null}

          {viewMode === "list" ? (
            <div style={S.card}>
              <div style={{ ...S.cardTitle, marginBottom: 12 }}>{isStaffView ? "Текущая проверка" : `Список проверок${filteredItems.length !== items.length ? ` (${filteredItems.length})` : ""}`}</div>
              {listError ? <div style={{ color: C.danger, marginBottom: 10 }}>{listError}</div> : null}
              {listBusy ? (
                <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
                  {[1,2,3].map(i => <div key={i} className="pulse" style={{ height: 72, background: C.bgInput, borderRadius: 14 }} />)}
                </div>
              ) : (
                <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
                  {filteredItems.length === 0 ? (
                    <div style={{ color: C.textMuted }}>{isStaffView ? "Сейчас для вас нет назначенной текущей проверки." : "Проверки по выбранным фильтрам не найдены."}</div>
                  ) : filteredItems.map(item => {
                    const statusColor = (CHECK_STATUS_META[item.status] || {}).color || C.border;
                    return (
                      <button
                        key={item.id}
                        className="btn-hover check-card"
                        onClick={() => setSelectedId(item.id)}
                        style={{
                          textAlign: "left",
                          width: "100%",
                          background: item.id === selectedId ? C.bgInput : "transparent",
                          border: `1px solid ${item.id === selectedId ? C.blue : C.border}`,
                          borderLeft: `4px solid ${statusColor}`,
                          color: C.text,
                          borderRadius: 14,
                          padding: 14,
                        }}
                      >
                        <div style={{ display: "flex", justifyContent: "space-between", gap: 10, alignItems: "center" }}>
                          <div style={{ fontWeight: 700 }}>{item.factionName || "Фракция"}</div>
                          <CheckStatusBadge status={item.status} />
                        </div>
                        <div style={{ fontSize: T.meta, color: C.textMuted, marginTop: 6 }}>{item.subject || "—"} • {formatDateTime(item.startsAt)} • {item.participantCount || 0} участн.</div>
                        <div style={{ fontSize: T.meta, color: C.textDim, marginTop: 8, lineHeight: 1.5, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{item.basisText || "Основание не указано"}</div>
                      </button>
                    );
                  })}
                </div>
              )}
            </div>
          ) : (
            <ChecksCalendarView items={filteredItems} selectedId={selectedId} onSelect={setSelectedId} monthFilter={monthFilter} />
          )}
        </div>

        <div style={S.card}>
          {detailBusy ? (
            <div style={{ display: "flex", flexDirection: "column", gap: 16, padding: 4 }}>
              <div className="pulse" style={{ height: 24, width: "60%", background: C.bgInput, borderRadius: 8 }} />
              <div className="pulse" style={{ height: 16, width: "80%", background: C.bgInput, borderRadius: 8 }} />
              <div className="pulse" style={{ height: 16, width: "40%", background: C.bgInput, borderRadius: 8 }} />
              <div style={{ display: "flex", gap: 8, marginTop: 8 }}>
                {[1,2,3,4].map(i => <div key={i} className="pulse" style={{ height: 36, width: 80, background: C.bgInput, borderRadius: 8 }} />)}
              </div>
              <div className="pulse" style={{ height: 180, background: C.bgInput, borderRadius: 12 }} />
            </div>
          ) : !currentCheck ? (
            <div style={{ color: C.textMuted }}>Выберите проверку слева.</div>
          ) : (
            <div style={{ display: "flex", flexDirection: "column", gap: 16 }}>
              <div style={{ display: "flex", justifyContent: "space-between", gap: 16, flexWrap: "wrap", alignItems: "flex-start" }}>
                <div>
                  <div style={S.cardTitle}>{currentCheck.factionName || "Проверка"}</div>
                  <div style={{ fontSize: T.body, color: C.textDim, marginTop: 6 }}>{currentCheck.subject} • {currentCheck.basisText || "Основание не указано"}</div>
                  <div style={{ display: "flex", gap: 8, flexWrap: "wrap", marginTop: 10 }}>
                    <span style={badge(C.blue)}>Начало: {formatDateTime(currentCheck.startsAt)}</span>
                    <span style={badge(C.blue)}>Окончание: {formatDateTime(currentCheck.endsAt)}</span>
                  </div>
                </div>
                <div style={{ display: "flex", gap: 8, flexWrap: "wrap", justifyContent: "flex-end", alignItems: "flex-start", alignSelf: "flex-start" }}>
                  <CheckStatusBadge status={currentCheck.status} />
                  {currentCheck.status !== "approved" && permissions.canActivate && canMutate ? <button className="btn-hover" style={btn("ghost")} onClick={() => handleTransition("checks.activate", "activate")} disabled={Boolean(actionBusy)}>Запустить</button> : null}
                  {currentCheck.status !== "approved" && permissions.canComplete && canMutate ? <button className="btn-hover" style={btn("ghost")} onClick={() => handleTransition("checks.complete", "complete")} disabled={Boolean(actionBusy)}>Завершить сбор</button> : null}
                  {currentCheck.status !== "approved" && ["completed", "pending_approval"].includes(currentCheck.status) && (permissions.canEditMetadata || permissions.canApprove) && canMutate ? <button className="btn-hover" style={btn("subtle")} onClick={() => handleTransition("checks.reopen", "reopen")} disabled={Boolean(actionBusy)}>Вернуть в работу</button> : null}
                  {currentCheck.status !== "approved" && permissions.canDelete && canMutate ? <button className="btn-hover" style={btn("danger")} onClick={openDeleteConfirm} disabled={Boolean(actionBusy) || deleteBusy}>Удалить проверку</button> : null}
                </div>
              </div>

              {detailError ? (
                <div style={{ background: C.danger + "15", border: `1px solid ${C.danger}44`, borderRadius: 10, padding: 14, display: "flex", alignItems: "center", gap: 12, flexWrap: "wrap" }}>
                  <span style={{ color: C.dangerLight, flex: 1 }}>{detailError}</span>
                  <button className="btn-hover" style={btn("subtle")} onClick={() => loadDetail(selectedId)}>Повторить</button>
                </div>
              ) : null}

              <div className="checks-tab-bar" style={{ display: "flex", gap: 4, flexWrap: "wrap", borderBottom: `2px solid ${C.border}`, paddingBottom: 0 }}>
                {visibleTabs.map(([id, label]) => {
                  const countMap = { reports: detail?.reports?.length, interviews: detail?.interviews?.length, participants: detail?.participants?.length, "gp-notes": detail?.gpNotes?.length };
                  const count = countMap[id];
                  const isActive = activeTab === id;
                  return (
                    <button key={id} className="btn-hover" style={{ ...btn(isActive ? "ghost" : "subtle"), padding: "10px 14px", borderRadius: "8px 8px 0 0", borderBottom: isActive ? `2px solid ${C.gold}` : "2px solid transparent", marginBottom: -2 }} onClick={() => setActiveTab(id)}>
                      {label}{count != null ? <span style={{ opacity: 0.5, marginLeft: 4, fontSize: T.meta }}>({count})</span> : null}
                    </button>
                  );
                })}
              </div>

              <div key={activeTab} className="fade-in">
              {activeTab === "general" ? <CheckGeneralTab permissions={permissions} metadataForm={metadataForm} setMetadataForm={setMetadataForm} factions={factions} enabledSubjects={enabledSubjects} sessionActor={sessionActor} canMutate={canMutate} handleSaveMetadata={handleSaveMetadata} actionBusy={actionBusy} currentCheck={currentCheck} /> : null}
              {activeTab === "participants" ? <CheckParticipantsTab detail={detail} detailParticipantCandidates={detailParticipantCandidates} participantsSelection={participantsSelection} setParticipantsSelection={setParticipantsSelection} permissions={permissions} canMutate={canMutate} handleSaveParticipants={handleSaveParticipants} actionBusy={actionBusy} /> : null}
              {activeTab === "reports" ? <CheckReportsTab detail={detail} permissions={permissions} currentCheck={currentCheck} user={user} canMutate={canMutate} openReportEditor={openReportEditor} handleUploadReportFile={handleUploadReportFile} reportEditorOpen={reportEditorOpen} reportForm={reportForm} setReportForm={setReportForm} handleSaveReport={handleSaveReport} setReportEditorOpen={setReportEditorOpen} setEditingReportId={setEditingReportId} setReportFormToDefault={() => setReportForm({ ...createCheckReportFormState(), metricsItems: normalizeMetricsItems([]) })} renderMetricEditor={renderMetricEditor} actionBusy={actionBusy} isStaffView={isStaffView} /> : null}
              {activeTab === "interviews" ? <CheckInterviewsTab detail={detail} permissions={permissions} currentCheck={currentCheck} user={user} canMutate={canMutate} openInterviewEditor={openInterviewEditor} interviewEditorOpen={interviewEditorOpen} interviewForm={interviewForm} setInterviewForm={setInterviewForm} handleSaveInterview={handleSaveInterview} setInterviewEditorOpen={setInterviewEditorOpen} setInterviewFormToDefault={() => setInterviewForm(createChecksInterviewEditorState())} renderInterviewAnswersEditor={renderInterviewAnswersEditor} actionBusy={actionBusy} /> : null}
              {!isStaffView && activeTab === "summary" ? renderSummaryBlock() : null}
              {!isStaffView && activeTab === "final" ? <CheckFinalTab detail={detail} permissions={permissions} approvalForm={approvalForm} setApprovalForm={setApprovalForm} handleApprove={handleApprove} actionBusy={actionBusy} canMutate={canMutate} summaryRenderer={renderSummaryBlock} summary={summary} /> : null}
              {!isStaffView && activeTab === "gp-notes" ? <CheckGpNotesTab detail={detail} permissions={permissions} gpNoteText={gpNoteText} setGpNoteText={setGpNoteText} handleAddGpNote={handleAddGpNote} actionBusy={actionBusy} canMutate={canMutate} /> : null}
              </div>
            </div>
          )}
        </div>
      </div>

      {deleteConfirmOpen && currentCheck ? (
        <Modal onClose={closeDeleteConfirm} maxWidth={560}>
          <div style={S.cardTitle}>Служебное подтверждение удаления</div>
          <div style={{ display: "flex", flexDirection: "column", gap: 14, marginTop: 14 }}>
            <div style={{ color: C.text, fontSize: T.bodyStrong }}>
              Проверка фракции: {currentCheck.factionName || "Без названия"}
            </div>
            <div style={{ color: C.textDim, fontSize: T.body, lineHeight: 1.6 }}>
              Для удаления назначенной или проведённой проверки введите служебный пароль. Проверка будет скрыта из рабочих списков, а доступ к её карточке прекратится.
            </div>
            <input
              type="password"
              style={S.input}
              value={deletePassword}
              onChange={e => {
                setDeletePassword(e.target.value);
                if (deletePasswordError) setDeletePasswordError("");
              }}
              placeholder="Служебный пароль"
              autoFocus
            />
            {deletePasswordError ? <div style={{ color: C.dangerLight, fontSize: T.meta }}>{deletePasswordError}</div> : null}
            <div style={S.row}>
              <button className="btn-hover" style={btn("danger")} onClick={handleDeleteCheck} disabled={deleteBusy}>
                {deleteBusy ? "Удаление..." : "Удалить проверку"}
              </button>
              <button className="btn-hover" style={btn("ghost")} onClick={closeDeleteConfirm} disabled={deleteBusy}>
                Отмена
              </button>
            </div>
          </div>
        </Modal>
      ) : null}

      {createOpen ? <CheckCreateModal createForm={createForm} setCreateForm={setCreateForm} factions={factions} enabledSubjects={enabledSubjects} sessionActor={sessionActor} createParticipantCandidates={createParticipantCandidates} handleCreate={handleCreate} actionBusy={actionBusy} onClose={() => setCreateOpen(false)} users={users} /> : null}

      <datalist id="check-report-sections">
        {CHECK_REPORT_SECTIONS.map(section => <option key={section} value={section} />)}
      </datalist>
    </div>
  );
}

function CheckGeneralTab({ permissions, metadataForm, setMetadataForm, factions, enabledSubjects, sessionActor, canMutate, handleSaveMetadata, actionBusy, currentCheck }) {
  const isApproved = currentCheck?.status === "approved";
  return (
    <div style={{ display: "grid", gap: 14 }}>
      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(220px, 1fr))", gap: 14 }}>
        {(hasSystemAdminAccess(sessionActor) || sessionActor?.role === "FEDERAL") ? (
          <div>
            <label style={S.label}>Субъект</label>
            <select style={S.select} value={metadataForm.subject} onChange={e => setMetadataForm(prev => ({ ...prev, subject: e.target.value }))} disabled={isApproved || !permissions.canEditMetadata || !canMutate}>
              {enabledSubjects.map(subject => <option key={subject} value={subject}>{subject}</option>)}
            </select>
          </div>
        ) : null}
        <div>
          <label style={S.label}>Фракция</label>
          <select style={S.select} value={metadataForm.factionId} onChange={e => setMetadataForm(prev => ({ ...prev, factionId: e.target.value }))} disabled={isApproved || !permissions.canEditMetadata || !canMutate}>
            {(factions || []).filter(item => item.active !== false).map(item => <option key={item.id} value={item.id}>{item.name}</option>)}
          </select>
        </div>
        <div>
          <label style={S.label}>Тип проверки</label>
          <input style={S.input} value={metadataForm.typeLabel} onChange={e => setMetadataForm(prev => ({ ...prev, typeLabel: e.target.value }))} disabled={isApproved || !permissions.canEditMetadata || !canMutate} />
        </div>

        <div>
          <label style={S.label}>Дата начала</label>
          <input type="datetime-local" style={S.input} value={metadataForm.startsAt} onChange={e => setMetadataForm(prev => ({ ...prev, startsAt: e.target.value }))} disabled={isApproved || !permissions.canEditMetadata || !canMutate} />
        </div>
        <div>
          <label style={S.label}>Дата окончания</label>
          <input type="datetime-local" style={S.input} value={metadataForm.endsAt} onChange={e => setMetadataForm(prev => ({ ...prev, endsAt: e.target.value }))} disabled={isApproved || !permissions.canEditMetadata || !canMutate} />
        </div>
      </div>

      <div>
        <label style={S.label}>Основание проверки</label>
        <textarea style={S.textarea} value={metadataForm.basisText} onChange={e => setMetadataForm(prev => ({ ...prev, basisText: e.target.value }))} disabled={isApproved || !permissions.canEditMetadata || !canMutate} />
      </div>
      <div>
        <label style={S.label}>Описание</label>
        <textarea style={S.textarea} value={metadataForm.description} onChange={e => setMetadataForm(prev => ({ ...prev, description: e.target.value }))} disabled={isApproved || !permissions.canEditMetadata || !canMutate} />
      </div>
      {permissions.canEditMetadata && canMutate && !isApproved ? (
        <button className="btn-hover" style={{ ...btn("gold"), width: "fit-content" }} onClick={handleSaveMetadata} disabled={Boolean(actionBusy)}>
          Сохранить карточку
        </button>
      ) : null}
    </div>
  );
}

function CheckParticipantsTab({ detail, detailParticipantCandidates, participantsSelection, setParticipantsSelection, permissions, canMutate, handleSaveParticipants, actionBusy }) {
  return (
    <div style={{ display: "grid", gap: 16 }}>
      <div style={{ display: "grid", gap: 10 }}>
        {(detail.participants || []).map(participant => (
          <div key={participant.id} style={{ display: "flex", justifyContent: "space-between", gap: 12, alignItems: "center", background: C.bgInput, border: `1px solid ${C.border}`, borderRadius: 12, padding: 12 }}>
            <div>
              <div style={{ color: C.text, fontWeight: 700 }}>{getCheckDisplayUserName(participant.user)}</div>
              <div style={{ color: C.textMuted, fontSize: T.meta, marginTop: 4 }}>{participant.user?.subject || "—"} • {participant.participantRole === "lead" ? "Руководитель проверки" : "Участник"}</div>
            </div>
            <span style={badge(participant.source === "federal" ? C.gold : C.blue)}>{participant.source === "federal" ? "ГП" : "Субъект"}</span>
          </div>
        ))}
      </div>
      {permissions.canEditMetadata && canMutate ? (
        <div style={{ background: C.bgInput, border: `1px solid ${C.border}`, borderRadius: 12, padding: 16 }}>
          <div style={{ ...S.cardTitle, marginBottom: 12 }}>Добавить или изменить участников</div>
          <div style={{ display: "grid", gap: 10 }}>
            {detailParticipantCandidates.map(candidate => (
              <label key={candidate.id} style={{ display: "flex", gap: 10, alignItems: "center", border: `1px solid ${C.border}`, borderRadius: 12, padding: 12 }}>
                <input type="checkbox" checked={participantsSelection.includes(candidate.id)} onChange={e => setParticipantsSelection(prev => e.target.checked ? Array.from(new Set([...prev, candidate.id])) : prev.filter(id => id !== candidate.id))} />
                  <span>{getCheckDisplayUserName(candidate)} • {candidate.subject}</span>
              </label>
            ))}
          </div>
          <button className="btn-hover" style={{ ...btn("gold"), marginTop: 16 }} onClick={handleSaveParticipants} disabled={Boolean(actionBusy)}>
            Сохранить участников
          </button>
        </div>
      ) : null}
    </div>
  );
}

function CheckReportsTab({ detail, permissions, currentCheck, user, canMutate, openReportEditor, handleUploadReportFile, reportEditorOpen, reportForm, setReportForm, handleSaveReport, setReportEditorOpen, setEditingReportId, setReportFormToDefault, renderMetricEditor, actionBusy, isStaffView = false }) {
  const reportsPg = usePagination(detail.reports || [], 10);
  return (
    <div>
      <div style={{ display: "flex", justifyContent: "space-between", gap: 12, alignItems: "center", marginBottom: 16 }}>
        <div style={S.cardTitle}>Материалы проверки</div>
        {permissions.canManageMaterials && currentCheck.status === "active" && canMutate ? <button className="btn-hover" style={btn("ghost")} onClick={() => openReportEditor()}>Добавить комментарий</button> : null}
      </div>
      {(detail.reports || []).length === 0 ? <div style={{ color: C.textMuted, marginBottom: 16 }}>Отчётов пока нет.</div> : null}
      {reportsPg.paged.map(report => (
        <div key={report.id} style={{ background: C.bgInput, border: `1px solid ${C.border}`, borderRadius: 14, padding: 16, marginBottom: 12 }}>
          <div style={{ display: "flex", justifyContent: "space-between", gap: 12, alignItems: "center", flexWrap: "wrap" }}>
            <div>
              <div style={{ fontWeight: 700 }}>{report.sectionLabel || "Материалы"}</div>
                <div style={{ fontSize: T.meta, color: C.textMuted, marginTop: 6 }}>{getCheckDisplayUserName(report.author)} • {formatDateTime(report.createdAt)}</div>
            </div>
            {((report.authorUserId === user.id) || permissions.canEditMetadata) && currentCheck.status !== "approved" && canMutate ? <button className="btn-hover" style={btn("subtle")} onClick={() => openReportEditor(report)}>Редактировать</button> : null}
          </div>
          <div style={{ display: "grid", gap: 10, marginTop: 12 }}>
            <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
              <span style={badge(report.reportMode === "employee" ? C.gold : C.blue)}>
                {report.reportMode === "employee" ? "Комментарий по сотруднику" : "Общий комментарий"}
              </span>
            </div>
            {report.reportMode === "employee" ? (
              <div style={{ color: C.textDim, lineHeight: 1.6 }}>
                <div style={{ color: C.text, fontWeight: 700 }}>{report.employeeRef?.fullName || "Без ФИО"}</div>
                <div style={{ color: C.textMuted, marginTop: 4 }}>
                  Звание: {report.employeeRef?.rankTitle || "—"} • ID {report.employeeRef?.externalEmployeeId || "—"}
                </div>
              </div>
            ) : null}
            <div style={{ color: C.textMuted, lineHeight: 1.6 }}>Комментарий: {report.commentText || report.circumstancesText || "—"}</div>
            {report.files?.length ? <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>{report.files.map(file => <a key={file.id} href={buildApiActionUrl("checks.files.download", { fileId: file.id })} target="_blank" rel="noreferrer" style={{ ...btn("subtle"), textDecoration: "none" }}>{file.originalName || "Вложение"}</a>)}</div> : null}
            {currentCheck.status !== "approved" && ((report.authorUserId === user.id) || permissions.canEditMetadata) && canMutate ? <div style={{ marginTop: 8 }}><input type="file" onChange={e => { const file = e.target.files?.[0]; if (file) void handleUploadReportFile(report.id, file); e.target.value = ""; }} /></div> : null}
          </div>
        </div>
      ))}
      {reportsPg.totalPages > 1 ? <Pagination page={reportsPg.page} totalPages={reportsPg.totalPages} onChange={reportsPg.setPage} /> : null}
      {reportEditorOpen ? (
        <div style={{ ...S.card, marginTop: 16, marginBottom: 0 }}>
          <div style={S.cardTitle}>Комментарий участника проверки</div>
          <div style={{ display: "flex", gap: 8, flexWrap: "wrap", marginTop: 14 }}>
            <button
              className="btn-hover"
              type="button"
              style={btn(reportForm.reportMode === "general" ? "ghost" : "subtle")}
              onClick={() => setReportForm(prev => ({ ...prev, reportMode: "general", targetFullName: "", targetId: "", targetRank: "" }))}
            >
              Общий комментарий
            </button>
            <button
              className="btn-hover"
              type="button"
              style={btn(reportForm.reportMode === "employee" ? "ghost" : "subtle")}
              onClick={() => setReportForm(prev => ({ ...prev, reportMode: "employee" }))}
            >
              Комментарий по сотруднику
            </button>
          </div>
          {reportForm.reportMode === "employee" ? (
            <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(220px, 1fr))", gap: 14, marginTop: 14 }}>
              <div style={{ gridColumn: "1 / -1" }}>
                <label style={S.label}>ФИО сотрудника фракции</label>
                <input style={S.input} value={reportForm.targetFullName || ""} onChange={e => setReportForm(prev => ({ ...prev, targetFullName: e.target.value }))} />
              </div>
              <div>
                <label style={S.label}>ID сотрудника</label>
                <input style={S.input} value={reportForm.targetId || ""} onChange={e => setReportForm(prev => ({ ...prev, targetId: e.target.value }))} />
              </div>
              <div>
                <label style={S.label}>Звание</label>
                <input style={S.input} value={reportForm.targetRank || ""} onChange={e => setReportForm(prev => ({ ...prev, targetRank: e.target.value }))} />
              </div>
            </div>
          ) : null}
          <label style={{ ...S.label, marginTop: 14 }}>Комментарий</label>
          <textarea style={S.textarea} value={reportForm.commentText} onChange={e => setReportForm(prev => ({ ...prev, commentText: e.target.value }))} />
          <div style={{ display: "flex", gap: 10, marginTop: 16 }}>
            <button className="btn-hover" style={btn("gold")} onClick={handleSaveReport} disabled={Boolean(actionBusy)}>Сохранить отчёт</button>
            <button className="btn-hover" style={btn("subtle")} onClick={() => { setReportEditorOpen(false); setEditingReportId(""); setReportFormToDefault(); }}>Отмена</button>
          </div>
        </div>
      ) : null}
    </div>
  );
}

function CheckInterviewsTab({ detail, permissions, currentCheck, user, canMutate, openInterviewEditor, interviewEditorOpen, interviewForm, setInterviewForm, handleSaveInterview, setInterviewEditorOpen, setInterviewFormToDefault, renderInterviewAnswersEditor, actionBusy }) {
  const interviewsPg = usePagination(detail.interviews || [], 10);
  return (
    <div>
      <div style={{ display: "flex", justifyContent: "space-between", gap: 12, alignItems: "center", marginBottom: 16 }}>
        <div style={S.cardTitle}>Опросы сотрудников фракции</div>
        {permissions.canManageMaterials && currentCheck.status === "active" && canMutate ? <button className="btn-hover" style={btn("ghost")} onClick={() => openInterviewEditor()}>Новый опрос</button> : null}
      </div>
      {(detail.interviews || []).length === 0 ? <div style={{ color: C.textMuted, marginBottom: 16 }}>Опросов пока нет.</div> : null}
      {interviewsPg.paged.map(interview => (
        <div key={interview.id} style={{ background: C.bgInput, border: `1px solid ${C.border}`, borderRadius: 14, padding: 16, marginBottom: 12 }}>
          <div style={{ display: "flex", justifyContent: "space-between", gap: 12, alignItems: "center", flexWrap: "wrap" }}>
            <div>
              <div style={{ fontWeight: 700 }}>{interview.employee?.fullName || "Без ФИО"}</div>
              <div style={{ fontSize: T.meta, color: C.textMuted, marginTop: 6 }}>Звание: {interview.employee?.positionTitle || "—"} • ID {interview.employee?.externalEmployeeId || "—"} • Баллы {interview.totalScore} • Итог {interview.finalGradeLabel || "—"}</div>
            </div>
            {((interview.enteredBy === user.id) || permissions.canEditMetadata) && currentCheck.status !== "approved" && canMutate ? <button className="btn-hover" style={btn("subtle")} onClick={() => openInterviewEditor(interview)}>Редактировать</button> : null}
          </div>
          <div style={{ display: "grid", gap: 10, marginTop: 12 }}>
            {(interview.answers || []).map((answer, index) => (
              <div key={`${interview.id}_${index}`} style={{ background: C.bg, border: `1px solid ${C.border}`, borderRadius: 10, padding: 12 }}>
                <div style={{ fontWeight: 700, color: C.text }}>{answer.questionText || "Вопрос без текста"}</div>
                <div style={{ color: C.textDim, marginTop: 6 }}>{answer.answerText || "Ответ не указан"}</div>
                <div style={{ display: "flex", gap: 8, flexWrap: "wrap", marginTop: 10 }}>
                  {answer.topicLabel ? <span style={badge(C.blue)}>{answer.topicLabel}</span> : null}
                  <span style={badge(C.warning)}>{answer.scoreChoice || "—"}</span>
                  <span style={badge(C.success)}>{answer.scoreValue ?? 0} балл.</span>
                </div>
              </div>
            ))}
            <div style={{ color: C.textDim, lineHeight: 1.6 }}>
              <div>Рекомендованное последствие: <span style={{ color: C.text }}>{interview.effectiveConsequence || interview.recommendedConsequence || "—"}</span></div>
              {interview.reviewerComment ? <div style={{ marginTop: 6 }}>Комментарий проверяющего: <span style={{ color: C.text }}>{interview.reviewerComment}</span></div> : null}
            </div>
          </div>
        </div>
      ))}
      {interviewsPg.totalPages > 1 ? <Pagination page={interviewsPg.page} totalPages={interviewsPg.totalPages} onChange={interviewsPg.setPage} /> : null}

      {interviewEditorOpen ? (
        <div style={{ ...S.card, marginTop: 16, marginBottom: 0 }}>
          <div style={S.cardTitle}>Опрос сотрудника фракции</div>
          <label style={{ ...S.label, marginTop: 14 }}>ФИО сотрудника фракции</label>
          <input style={S.input} value={interviewForm.factionPerson.fullName} onChange={e => setInterviewForm(prev => ({ ...prev, factionPerson: { ...prev.factionPerson, fullName: e.target.value } }))} />
          <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(220px, 1fr))", gap: 14, marginTop: 14 }}>
            <div>
              <label style={S.label}>ID сотрудника</label>
              <input style={S.input} value={interviewForm.factionPerson.externalEmployeeId} onChange={e => setInterviewForm(prev => ({ ...prev, factionPerson: { ...prev.factionPerson, externalEmployeeId: e.target.value } }))} />
            </div>
            <div>
              <label style={S.label}>Звание</label>
              <input style={S.input} value={interviewForm.factionPerson.positionTitle} onChange={e => setInterviewForm(prev => ({ ...prev, factionPerson: { ...prev.factionPerson, positionTitle: e.target.value } }))} />
            </div>
          </div>
          <label style={{ ...S.label, marginTop: 14 }}>Вопросы и ответы</label>
          {renderInterviewAnswersEditor()}
          <label style={{ ...S.label, marginTop: 14 }}>Комментарий проверяющего</label>
          <textarea style={S.textarea} value={interviewForm.reviewerComment} onChange={e => setInterviewForm(prev => ({ ...prev, reviewerComment: e.target.value }))} />
          <label style={{ ...S.label, marginTop: 14 }}>Вывод</label>
          <textarea style={S.textarea} value={interviewForm.overrideConsequence} onChange={e => setInterviewForm(prev => ({ ...prev, overrideConsequence: e.target.value }))} />
          <div style={{ display: "flex", gap: 10, marginTop: 16 }}>
            <button className="btn-hover" style={btn("gold")} onClick={handleSaveInterview} disabled={Boolean(actionBusy)}>Сохранить опрос</button>
            <button className="btn-hover" style={btn("subtle")} onClick={() => { setInterviewEditorOpen(false); setInterviewFormToDefault(); }}>Отмена</button>
          </div>
        </div>
      ) : null}
    </div>
  );
}

function ChecksCalendarView({ items, selectedId, onSelect, monthFilter }) {
  const now = new Date();
  const [year, month] = (monthFilter || "").split("-").map(Number);
  const calYear = year || now.getFullYear();
  const calMonth = (month || (now.getMonth() + 1)) - 1;

  const firstDay = new Date(calYear, calMonth, 1);
  const lastDay = new Date(calYear, calMonth + 1, 0);
  const startDow = (firstDay.getDay() + 6) % 7;
  const totalDays = lastDay.getDate();

  const WEEKDAYS = ["Пн", "Вт", "Ср", "Чт", "Пт", "Сб", "Вс"];
  const cells = [];
  for (let i = 0; i < startDow; i++) cells.push(null);
  for (let d = 1; d <= totalDays; d++) cells.push(d);
  while (cells.length % 7 !== 0) cells.push(null);

  const getChecksForDay = (day) => {
    if (!day) return [];
    const dayStart = new Date(calYear, calMonth, day);
    const dayEnd = new Date(calYear, calMonth, day, 23, 59, 59);
    return items.filter(item => {
      const start = new Date(item.startsAt);
      const end = item.endsAt ? new Date(item.endsAt) : start;
      return start <= dayEnd && end >= dayStart;
    });
  };

  const MONTH_NAMES = ["Январь", "Февраль", "Март", "Апрель", "Май", "Июнь", "Июль", "Август", "Сентябрь", "Октябрь", "Ноябрь", "Декабрь"];

  return (
    <div style={S.card}>
      <div style={{ ...S.cardTitle, marginBottom: 14 }}>{MONTH_NAMES[calMonth]} {calYear}</div>
      <div style={{ display: "grid", gridTemplateColumns: "repeat(7, 1fr)", gap: 4 }}>
        {WEEKDAYS.map(d => (
          <div key={d} style={{ textAlign: "center", fontSize: T.meta, color: C.textMuted, padding: "6px 0", fontWeight: 700 }}>{d}</div>
        ))}
        {cells.map((day, i) => {
          const dayChecks = getChecksForDay(day);
          return (
            <div key={i} style={{ minHeight: 60, background: day ? C.bgInput : "transparent", border: day ? `1px solid ${C.border}` : "none", borderRadius: 8, padding: 4, position: "relative" }}>
              {day ? <div style={{ fontSize: 11, color: C.textMuted, textAlign: "right", padding: "0 4px" }}>{day}</div> : null}
              <div style={{ display: "flex", flexDirection: "column", gap: 2, marginTop: 2 }}>
                {dayChecks.slice(0, 2).map(item => {
                  const sc = (CHECK_STATUS_META[item.status] || {}).color || C.border;
                  return (
                    <div key={item.id} onClick={() => onSelect(item.id)} style={{ fontSize: 9, padding: "2px 4px", borderRadius: 4, background: sc + "33", color: sc, cursor: "pointer", overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap", border: item.id === selectedId ? `1px solid ${sc}` : "1px solid transparent" }}>
                      {item.factionName || "—"}
                    </div>
                  );
                })}
                {dayChecks.length > 2 ? <div style={{ fontSize: 9, color: C.textMuted, textAlign: "center" }}>+{dayChecks.length - 2}</div> : null}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}

function CheckTimeline({ audit }) {
  const TIMELINE_STEPS = [
    { code: "check_created", label: "Создана" },
    { code: "status_to_active", label: "Активна" },
    { code: "status_to_completed", label: "Завершена" },
    { code: "status_to_pending_approval", label: "На утверждении" },
    { code: "status_to_approved", label: "Утверждена" },
  ];
  const auditList = audit || [];
  const stepDates = TIMELINE_STEPS.map(step => {
    const entry = auditList.find(e => e.actionCode === step.code);
    return { ...step, date: entry ? formatDateTime(entry.createdAt) : null, reached: Boolean(entry) };
  });
  const lastReachedIndex = stepDates.reduce((acc, s, i) => s.reached ? i : acc, -1);
  return (
    <div style={{ display: "flex", alignItems: "flex-start", gap: 0, overflowX: "auto", padding: "8px 0 16px" }}>
      {stepDates.map((step, i) => {
        const isReached = step.reached;
        const isLast = i === lastReachedIndex;
        const dotColor = isReached ? (isLast ? C.success : C.blue) : C.border;
        return (
          <div key={step.code} style={{ display: "flex", alignItems: "flex-start", flex: 1, minWidth: 110 }}>
            <div style={{ display: "flex", flexDirection: "column", alignItems: "center", flex: 1 }}>
              <div style={{ width: 20, height: 20, borderRadius: "50%", background: dotColor, border: `2px solid ${isReached ? dotColor : C.border}`, display: "flex", alignItems: "center", justifyContent: "center", fontSize: 11, color: "#fff", fontWeight: 700 }}>
                {isLast ? "✓" : ""}
              </div>
              <div style={{ fontSize: 12, color: isReached ? C.text : C.textMuted, marginTop: 6, fontWeight: isReached ? 700 : 400, textAlign: "center" }}>{step.label}</div>
              {step.date ? <div style={{ fontSize: 10, color: C.textMuted, marginTop: 2, textAlign: "center" }}>{step.date}</div> : null}
            </div>
            {i < stepDates.length - 1 ? (
              <div style={{ height: 2, flex: 1, background: stepDates[i + 1].reached ? C.blue : C.border, marginTop: 9, minWidth: 20 }} />
            ) : null}
          </div>
        );
      })}
    </div>
  );
}

function ApprovedCheckReport({ detail, summary }) {
  const [expandedEmployees, setExpandedEmployees] = useState({});
  const approval = detail.approvedSummary?.summary?.approval || {};
  const preMetrics = summary?.preCheckSummary?.metrics || {};
  const currentSummary = summary?.currentCheckSummary || {};
  const reportsSummary = currentSummary.reportsSummary || {};
  const interviewsSummary = currentSummary.interviewsSummary || {};
  const participantSummary = currentSummary.participantSummary || [];
  const interviewRoster = summary?.interviewRoster || [];

  const toggleEmployee = (id) => setExpandedEmployees(prev => ({ ...prev, [id]: !prev[id] }));

  return (
    <div id="approved-check-report" style={{ display: "grid", gap: 18 }}>
      {/* Banner */}
      <div style={{ background: `linear-gradient(135deg, ${C.success}22, ${C.success}08)`, border: `2px solid ${C.success}44`, borderRadius: 16, padding: "24px 28px" }}>
        <div style={{ display: "flex", alignItems: "center", gap: 14, flexWrap: "wrap" }}>
          <div style={{ width: 48, height: 48, borderRadius: "50%", background: C.success + "33", display: "flex", alignItems: "center", justifyContent: "center", fontSize: 24 }}>✓</div>
          <div style={{ flex: 1 }}>
            <div style={{ fontSize: T.heading, fontWeight: 700, color: C.text, fontFamily: F.heading, letterSpacing: 0.5 }}>Проверка утверждена</div>
            <div style={{ color: C.textDim, marginTop: 4 }}>Утвердил: {getCheckDisplayUserName(approval.approvedBy)} • {formatDateTime(approval.approvedAt)}</div>
          </div>
          <span style={{ ...badge(C.success), fontSize: 15, padding: "8px 18px" }}>{getCheckFinalRatingLabel(approval.finalRating)}</span>
        </div>
      </div>

      {/* Timeline */}
      <CheckTimeline audit={detail.audit} />

      {/* Conclusion */}
      <div style={{ background: C.bgInput, border: `1px solid ${C.border}`, borderRadius: 14, padding: 20 }}>
        <div style={{ ...S.cardTitle, marginBottom: 12, fontSize: T.bodyStrong }}>Итоговое заключение</div>
        <div style={{ color: C.text, lineHeight: 1.8, fontSize: T.body }}>{approval.finalConclusion || "—"}</div>
        {approval.resolutionText ? (
          <div style={{ marginTop: 16, paddingTop: 16, borderTop: `1px solid ${C.border}` }}>
            <div style={{ fontSize: T.meta, color: C.textMuted, textTransform: "uppercase", letterSpacing: 1, marginBottom: 8 }}>Резолюция</div>
            <div style={{ color: C.textDim, lineHeight: 1.7 }}>{approval.resolutionText}</div>
          </div>
        ) : null}
      </div>

      {/* Key metrics */}
      <div style={{ ...S.row, marginBottom: 0 }}>
        <StatBox value={preMetrics.eventsTotal || 0} label="Событий до проверки" color={C.blue} />
        <StatBox value={reportsSummary.reportsCount || 0} label="Отчётов участников" color={C.warning} />
        <StatBox value={interviewsSummary.employeesCount || 0} label="Опрошено сотрудников" color={C.gold} />
        <StatBox value={interviewsSummary.averageTotalScore || 0} label="Средний балл" color={C.success} />
      </div>

      {/* Reports & Interviews summary side by side */}
      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(280px, 1fr))", gap: 16 }}>
        <div style={{ background: C.bgInput, border: `1px solid ${C.border}`, borderRadius: 12, padding: 16 }}>
          <div style={{ ...S.cardTitle, marginBottom: 12 }}>Сводка по отчётам</div>
          <div style={{ display: "grid", gap: 8 }}>
            <div style={{ color: C.textDim }}>Нарушений: <span style={{ color: C.text }}>{reportsSummary.violationsCount || 0}</span></div>
            <div style={{ color: C.textDim }}>Вложений: <span style={{ color: C.text }}>{reportsSummary.attachmentsCount || 0}</span></div>
            <div style={{ display: "flex", gap: 8, flexWrap: "wrap", marginTop: 4 }}>
              {Object.entries(reportsSummary.reportsBySection || {}).map(([section, count]) => (
                <span key={section} style={badge(C.blue)}>{getReportSectionLabel(section)}: {count}</span>
              ))}
            </div>
          </div>
        </div>
        <div style={{ background: C.bgInput, border: `1px solid ${C.border}`, borderRadius: 12, padding: 16 }}>
          <div style={{ ...S.cardTitle, marginBottom: 12 }}>Сводка по опросам</div>
          <div style={{ display: "grid", gap: 8 }}>
            <div style={{ color: C.textDim }}>Ответов всего: <span style={{ color: C.text }}>{interviewsSummary.answersCount || 0}</span></div>
            <div style={{ color: C.textDim }}>Распределение оценок:</div>
            <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
              {Object.entries(interviewsSummary.gradeDistribution || {}).map(([grade, count]) => (
                <span key={grade} style={badge(C.success)}>{grade}: {count}</span>
              ))}
            </div>
          </div>
        </div>
      </div>

      {/* Participants table */}
      {participantSummary.length ? (
        <div style={{ background: C.bgInput, border: `1px solid ${C.border}`, borderRadius: 12, padding: 16 }}>
          <div style={{ ...S.cardTitle, marginBottom: 12 }}>Участники проверки</div>
          <table style={S.table}>
            <thead>
              <tr>
                <th style={S.th}>Участник</th>
                <th style={S.th}>Отчётов</th>
                <th style={S.th}>Опросов</th>
              </tr>
            </thead>
            <tbody>
              {participantSummary.map((item, index) => (
                <tr key={item.user?.id || index}>
                  <td style={S.td}>{getCheckDisplayUserName(item.user)}</td>
                  <td style={S.td}>{item.reportCount || 0}</td>
                  <td style={S.td}>{item.interviewCount || 0}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}

      {/* Interview roster — expandable */}
      {interviewRoster.length ? (
        <div style={{ background: C.bgInput, border: `1px solid ${C.border}`, borderRadius: 12, padding: 16 }}>
          <div style={{ ...S.cardTitle, marginBottom: 12 }}>Опрошенные сотрудники</div>
          <table style={S.table}>
            <thead>
              <tr>
                <th style={S.th}>ФИО</th>
                <th style={S.th}>Звание</th>
                <th style={S.th}>Оценка</th>
                <th style={S.th}>Баллы</th>
                <th style={S.th}>Рекомендация</th>
                <th style={{ ...S.th, width: 40 }}></th>
              </tr>
            </thead>
            <tbody>
              {interviewRoster.map((item, index) => {
                const empKey = item.employee?.externalEmployeeId || item.employee?.profileId || index;
                const isExpanded = expandedEmployees[empKey];
                return (
                  <React.Fragment key={empKey}>
                    <tr style={{ cursor: "pointer" }} onClick={() => toggleEmployee(empKey)}>
                      <td style={{ ...S.td, fontWeight: 700, color: C.text }}>{item.employee?.fullName || "—"}</td>
                      <td style={S.td}>{item.employee?.positionTitle || "—"}</td>
                      <td style={S.td}><span style={badge(C.gold)}>{item.finalGradeLabel || "—"}</span></td>
                      <td style={S.td}><span style={badge(C.blue)}>{item.totalScore ?? 0}</span></td>
                      <td style={S.td}>{item.effectiveConsequence || item.recommendedConsequence || "—"}</td>
                      <td style={{ ...S.td, textAlign: "center", color: C.textMuted }}>{isExpanded ? "▲" : "▼"}</td>
                    </tr>
                    {isExpanded ? (
                      <tr>
                        <td colSpan={6} style={{ padding: "0 14px 14px", borderBottom: `1px solid ${C.border}` }}>
                          <div style={{ display: "grid", gap: 10, padding: "12px 0" }}>
                            {(item.answers || []).map((answer, ai) => (
                              <div key={ai} style={{ background: C.bg, borderRadius: 10, padding: 12, border: `1px solid ${C.border}` }}>
                                <div style={{ color: C.text, fontWeight: 700 }}>{answer.questionText || "Вопрос без текста"}</div>
                                <div style={{ color: C.textDim, marginTop: 6, lineHeight: 1.6 }}>{answer.answerText || "Ответ не указан"}</div>
                                <div style={{ display: "flex", gap: 8, flexWrap: "wrap", marginTop: 10 }}>
                                  <span style={badge(C.warning)}>{answer.scoreChoice || "—"}</span>
                                  <span style={badge(C.success)}>{answer.scoreValue ?? 0} балл.</span>
                                </div>
                              </div>
                            ))}
                            {item.reviewerComment ? <div style={{ color: C.textDim }}>Комментарий проверяющего: <span style={{ color: C.text }}>{item.reviewerComment}</span></div> : null}
                          </div>
                        </td>
                      </tr>
                    ) : null}
                  </React.Fragment>
                );
              })}
            </tbody>
          </table>
        </div>
      ) : null}

      {/* Print button */}
      <div style={{ display: "flex", justifyContent: "flex-end", gap: 12 }}>
        <button className="btn-hover no-print" style={btn("gold")} onClick={() => window.print()}>Печать итогового отчёта</button>
      </div>
    </div>
  );
}

function CheckFinalTab({ detail, permissions, approvalForm, setApprovalForm, handleApprove, actionBusy, canMutate, summaryRenderer, summary }) {
  return (
    <div style={{ display: "grid", gap: 16 }}>
      {detail.approvedSummary?.summary ? (
        <ApprovedCheckReport detail={detail} summary={summary} />
      ) : permissions.canApprove ? (
        <div>
          <label style={S.label}>Итоговая оценка проверки</label>
          <select style={S.select} value={approvalForm.finalRating} onChange={e => setApprovalForm(prev => ({ ...prev, finalRating: e.target.value }))}>
            {CHECK_FINAL_RATING_OPTIONS.map(option => <option key={option.id} value={option.id}>{option.label}</option>)}
          </select>
          <label style={{ ...S.label, marginTop: 14 }}>Итоговое заключение</label>
          <textarea style={S.textarea} value={approvalForm.finalConclusion} onChange={e => setApprovalForm(prev => ({ ...prev, finalConclusion: e.target.value }))} />
          <label style={{ ...S.label, marginTop: 14 }}>Резолюция / служебный вывод</label>
          <textarea style={S.textarea} value={approvalForm.resolutionText} onChange={e => setApprovalForm(prev => ({ ...prev, resolutionText: e.target.value }))} />
          <button className="btn-hover" style={{ ...btn("gold"), marginTop: 16 }} onClick={handleApprove} disabled={Boolean(actionBusy) || !canMutate}>Утвердить и передать в ГП</button>
        </div>
      ) : (
        <div style={{ color: C.textMuted }}>Итоговый отчёт станет доступен после утверждения проверки прокурором субъекта.</div>
      )}
      {!detail.approvedSummary?.summary ? summaryRenderer() : null}
    </div>
  );
}

function CheckAuditTab({ detail }) {
  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
      {(detail.audit || []).length === 0 ? <div style={{ color: C.textMuted }}>Аудит по проверке пока пуст.</div> : null}
      {(detail.audit || []).map(entry => (
        <div key={entry.id} style={{ background: C.bgInput, border: `1px solid ${C.border}`, borderRadius: 12, padding: 14 }}>
          <div style={{ display: "flex", justifyContent: "space-between", gap: 12, flexWrap: "wrap" }}>
            <div style={{ fontWeight: 700, color: C.text }}>{entry.actionCode}</div>
            <div style={{ color: C.textMuted, fontSize: T.meta }}>{formatDateTime(entry.createdAt)}</div>
          </div>
          <div style={{ color: C.textDim, marginTop: 8 }}>{entry.actorRole || "—"} • {entry.actorSubject || "—"} • {entry.actorUserId || "—"}</div>
          {(entry.meta && Object.keys(entry.meta).length) ? <pre style={{ marginTop: 10, padding: 12, borderRadius: 10, background: C.bg, color: C.textDim, overflowX: "auto", whiteSpace: "pre-wrap", fontFamily: F.mono, fontSize: 12 }}>{JSON.stringify(entry.meta, null, 2)}</pre> : null}
        </div>
      ))}
    </div>
  );
}

function CheckGpNotesTab({ detail, permissions, gpNoteText, setGpNoteText, handleAddGpNote, actionBusy, canMutate }) {
  return (
    <div>
      <div style={{ display: "flex", flexDirection: "column", gap: 12 }}>
        {(detail.gpNotes || []).length === 0 ? <div style={{ color: C.textMuted }}>Служебных пометок пока нет.</div> : null}
        {(detail.gpNotes || []).map(note => (
          <div key={note.id} style={{ background: C.bgInput, border: `1px solid ${C.border}`, borderRadius: 12, padding: 14 }}>
            <div style={{ color: C.text, lineHeight: 1.6 }}>{note.noteText}</div>
          <div style={{ color: C.textMuted, fontSize: T.meta, marginTop: 8 }}>{getCheckDisplayUserName(note.author)} • {formatDateTime(note.createdAt)}</div>
          </div>
        ))}
      </div>
      {permissions.canAddGpNotes && canMutate ? (
        <div style={{ marginTop: 16 }}>
          <label style={S.label}>Служебная пометка ГП</label>
          <textarea style={S.textarea} value={gpNoteText} onChange={e => setGpNoteText(e.target.value)} />
          <button className="btn-hover" style={{ ...btn("gold"), marginTop: 12 }} onClick={handleAddGpNote} disabled={Boolean(actionBusy) || !gpNoteText.trim()}>Добавить пометку</button>
        </div>
      ) : null}
    </div>
  );
}

function CheckCreateModal({ createForm, setCreateForm, factions, enabledSubjects, sessionActor, createParticipantCandidates, handleCreate, actionBusy, onClose, users }) {
  return (
    <Modal onClose={onClose} maxWidth={860}>
      <div style={S.cardTitle}>Новая проверка фракции</div>
      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(220px, 1fr))", gap: 14, marginTop: 18 }}>
        {(hasSystemAdminAccess(sessionActor) || sessionActor?.role === "FEDERAL") ? (
          <div>
            <label style={S.label}>Субъект</label>
            <select style={S.select} value={createForm.subject} onChange={e => setCreateForm(prev => ({ ...prev, subject: e.target.value, participantUserIds: Array.from(new Set(prev.participantUserIds.filter(id => getCheckParticipantCandidates(users, e.target.value).some(candidate => candidate.id === id)))) }))}>
              {enabledSubjects.map(subject => <option key={subject} value={subject}>{subject}</option>)}
            </select>
          </div>
        ) : null}
        <div>
          <label style={S.label}>Фракция</label>
          <select style={S.select} value={createForm.factionId} onChange={e => setCreateForm(prev => ({ ...prev, factionId: e.target.value }))}>
            {(factions || []).filter(item => item.active !== false).map(item => <option key={item.id} value={item.id}>{item.name}</option>)}
          </select>
        </div>
        <div>
          <label style={S.label}>Тип проверки</label>
          <input style={S.input} value={createForm.typeLabel} onChange={e => setCreateForm(prev => ({ ...prev, typeLabel: e.target.value }))} />
        </div>
        <div>
          <label style={S.label}>Дата начала</label>
          <input type="datetime-local" style={S.input} value={createForm.startsAt} onChange={e => setCreateForm(prev => ({ ...prev, startsAt: e.target.value }))} />
        </div>
        <div>
          <label style={S.label}>Дата окончания</label>
          <input type="datetime-local" style={S.input} value={createForm.endsAt} onChange={e => setCreateForm(prev => ({ ...prev, endsAt: e.target.value }))} />
        </div>
      </div>
      <label style={{ ...S.label, marginTop: 16 }}>Основание</label>
      <textarea style={S.textarea} value={createForm.basisText} onChange={e => setCreateForm(prev => ({ ...prev, basisText: e.target.value }))} />
      <label style={{ ...S.label, marginTop: 16 }}>Описание</label>
      <textarea style={S.textarea} value={createForm.description} onChange={e => setCreateForm(prev => ({ ...prev, description: e.target.value }))} />
      <label style={{ ...S.label, marginTop: 16 }}>Примечания</label>
      <textarea style={S.textarea} value={createForm.notes} onChange={e => setCreateForm(prev => ({ ...prev, notes: e.target.value }))} />
      <label style={{ ...S.label, marginTop: 16 }}>Участники проверки</label>
      <div style={{ display: "grid", gap: 10, maxHeight: 280, overflowY: "auto", paddingRight: 4 }}>
        {createParticipantCandidates.map(candidate => (
          <label key={candidate.id} style={{ display: "flex", gap: 10, alignItems: "center", background: C.bgInput, border: `1px solid ${C.border}`, borderRadius: 12, padding: 12 }}>
            <input type="checkbox" checked={createForm.participantUserIds.includes(candidate.id)} onChange={e => setCreateForm(prev => ({ ...prev, participantUserIds: e.target.checked ? Array.from(new Set([...prev.participantUserIds, candidate.id])) : prev.participantUserIds.filter(id => id !== candidate.id) }))} />
                  <span>{getCheckDisplayUserName(candidate)} • {candidate.subject}</span>
          </label>
        ))}
      </div>
      <div style={{ display: "flex", gap: 10, marginTop: 18 }}>
        <button className="btn-hover" style={btn("gold")} onClick={handleCreate} disabled={Boolean(actionBusy)}>Создать проверку</button>
        <button className="btn-hover" style={btn("subtle")} onClick={onClose}>Отмена</button>
      </div>
    </Modal>
  );
}
