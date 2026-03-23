// ═══════════════════════════════════════════════════════════════════
//  UI TOOLKIT — Toast, ConfirmDialog, Skeleton, Theme, Hotkeys, etc.
// ═══════════════════════════════════════════════════════════════════

// ── Theme System ──

const THEMES = {
  dark: {
    bg: "#04122b", bgCard: "#0a1b3d", bgSidebar: "#06152f", bgInput: "#0d285a",
    border: "#174b98", borderHover: "#2d6bc0",
    gold: "#5bc0eb", goldDark: "#012d66", goldLight: "#63a4ff",
    crimson: "#0353a4", crimsonLight: "#4ea8de",
    text: "#f2f7ff", textDim: "#c6d4eb", textMuted: "#8ea5c7",
    accent: "#0077b6", accentLight: "#6bb7ff",
    danger: "#b34739", dangerLight: "#f08b7b",
    success: "#2f9e8f", warning: "#d69a2d", blue: "#1d70d1", blueLight: "#85c5ff",
    overlay: "#010816c6", navHover: "#023e8a1a", rowHover: "#4ea8de12",
    heroGlowPrimary: "#023e8a2b", heroGlowSecondary: "#4ea8de24", activeNav: "#023e8a30",
  },
  light: {
    bg: "#f0f4f8", bgCard: "#ffffff", bgSidebar: "#e8eef6", bgInput: "#f5f7fa",
    border: "#c8d6e5", borderHover: "#8395a7",
    gold: "#0077b6", goldDark: "#01497c", goldLight: "#0353a4",
    crimson: "#0353a4", crimsonLight: "#4ea8de",
    text: "#1a1d21", textDim: "#4a5568", textMuted: "#718096",
    accent: "#0077b6", accentLight: "#0353a4",
    danger: "#c0392b", dangerLight: "#e74c3c",
    success: "#27ae60", warning: "#d69a2d", blue: "#1d70d1", blueLight: "#2980b9",
    overlay: "rgba(0,0,0,0.45)", navHover: "#0077b60a", rowHover: "#0077b608",
    heroGlowPrimary: "#0077b60a", heroGlowSecondary: "#4ea8de08", activeNav: "#0077b615",
  },
};

function getStoredTheme() {
  try { return localStorage.getItem("femida_theme") || "dark"; } catch { return "dark"; }
}

function setStoredTheme(theme) {
  try { localStorage.setItem("femida_theme", theme); } catch {}
}

const ThemeContext = React.createContext({ theme: "dark", toggleTheme: () => {} });

function useTheme() {
  return React.useContext(ThemeContext);
}

function ThemeToggleButton() {
  const { theme, toggleTheme } = useTheme();
  return React.createElement("button", {
    className: "btn-hover",
    onClick: toggleTheme,
    title: theme === "dark" ? "Светлая тема" : "Тёмная тема",
    "aria-label": theme === "dark" ? "Переключить на светлую тему" : "Переключить на тёмную тему",
    style: {
      background: "transparent",
      border: "1px solid " + (theme === "dark" ? "#174b98" : "#c8d6e5"),
      borderRadius: 8,
      padding: "8px 12px",
      cursor: "pointer",
      fontSize: 18,
      lineHeight: 1,
      color: theme === "dark" ? "#f2f7ff" : "#1a1d21",
      minHeight: 40,
      display: "flex",
      alignItems: "center",
      gap: 6,
    },
  }, theme === "dark" ? "\u2600\uFE0F" : "\uD83C\uDF19");
}

// ── Toast System ──

let __toastListeners = [];
let __toastCounter = 0;

function showToast(message, type, duration) {
  if (type === void 0) type = "info";
  if (duration === void 0) duration = 4500;
  const id = ++__toastCounter;
  const toast = { id: id, message: message, type: type, duration: duration, createdAt: Date.now() };
  __toastListeners.forEach(function(fn) { fn(toast); });
  return id;
}

function showSuccess(msg) { return showToast(msg, "success"); }
function showError(msg) { return showToast(msg, "error", 6000); }
function showWarning(msg) { return showToast(msg, "warning", 5000); }
function showInfo(msg) { return showToast(msg, "info"); }

function ToastContainer() {
  const [toasts, setToasts] = React.useState([]);

  React.useEffect(function() {
    var handler = function(toast) {
      setToasts(function(prev) { return prev.concat([toast]); });
      setTimeout(function() {
        setToasts(function(prev) { return prev.filter(function(t) { return t.id !== toast.id; }); });
      }, toast.duration);
    };
    __toastListeners.push(handler);
    return function() {
      __toastListeners = __toastListeners.filter(function(fn) { return fn !== handler; });
    };
  }, []);

  var dismiss = function(id) {
    setToasts(function(prev) { return prev.filter(function(t) { return t.id !== id; }); });
  };

  var typeStyles = {
    success: { bg: "#2f9e8f", border: "#2f9e8f", icon: "\u2713" },
    error: { bg: "#b34739", border: "#b34739", icon: "\u2717" },
    warning: { bg: "#d69a2d", border: "#d69a2d", icon: "\u26A0" },
    info: { bg: "#0077b6", border: "#0077b6", icon: "\u2139" },
  };

  if (toasts.length === 0) return null;

  return React.createElement("div", {
    "aria-live": "polite",
    "aria-atomic": "false",
    style: {
      position: "fixed", top: 16, right: 16, zIndex: 10000,
      display: "flex", flexDirection: "column", gap: 8,
      maxWidth: 420, width: "calc(100% - 32px)",
      pointerEvents: "none",
    },
  },
    toasts.map(function(t) {
      var ts = typeStyles[t.type] || typeStyles.info;
      return React.createElement("div", {
        key: t.id,
        className: "toast-enter",
        style: {
          display: "flex", alignItems: "flex-start", gap: 10,
          padding: "12px 16px", borderRadius: 10,
          background: ts.bg + "18", backdropFilter: "blur(12px)",
          border: "1px solid " + ts.bg + "66",
          color: "#f2f7ff", fontSize: 14, fontWeight: 500,
          boxShadow: "0 8px 32px rgba(0,0,0,0.3)",
          pointerEvents: "auto",
          animation: "toastSlideIn 0.3s ease",
        },
      },
        React.createElement("span", {
          style: {
            width: 24, height: 24, borderRadius: "50%",
            background: ts.bg, color: "#fff",
            display: "flex", alignItems: "center", justifyContent: "center",
            fontSize: 13, fontWeight: 700, flexShrink: 0,
          },
        }, ts.icon),
        React.createElement("span", { style: { flex: 1, lineHeight: 1.45 } }, t.message),
        React.createElement("button", {
          onClick: function() { dismiss(t.id); },
          "aria-label": "\u0417\u0430\u043a\u0440\u044b\u0442\u044c \u0443\u0432\u0435\u0434\u043e\u043c\u043b\u0435\u043d\u0438\u0435",
          style: {
            background: "none", border: "none", color: "#f2f7ff88",
            cursor: "pointer", fontSize: 16, padding: "0 4px", lineHeight: 1,
            flexShrink: 0,
          },
        }, "\u2715")
      );
    })
  );
}

// ── Confirm Dialog ──

let __confirmResolve = null;
let __confirmListeners = [];

function showConfirm(message, options) {
  if (options === void 0) options = {};
  return new Promise(function(resolve) {
    __confirmResolve = resolve;
    var payload = {
      message: message,
      title: options.title || "\u041f\u043e\u0434\u0442\u0432\u0435\u0440\u0436\u0434\u0435\u043d\u0438\u0435",
      confirmLabel: options.confirmLabel || "\u041f\u043e\u0434\u0442\u0432\u0435\u0440\u0434\u0438\u0442\u044c",
      cancelLabel: options.cancelLabel || "\u041e\u0442\u043c\u0435\u043d\u0430",
      danger: options.danger || false,
    };
    __confirmListeners.forEach(function(fn) { fn(payload); });
  });
}

function ConfirmDialog() {
  const [dialog, setDialog] = React.useState(null);
  const confirmBtnRef = React.useRef(null);

  React.useEffect(function() {
    var handler = function(payload) { setDialog(payload); };
    __confirmListeners.push(handler);
    return function() {
      __confirmListeners = __confirmListeners.filter(function(fn) { return fn !== handler; });
    };
  }, []);

  React.useEffect(function() {
    if (dialog && confirmBtnRef.current) confirmBtnRef.current.focus();
  }, [dialog]);

  React.useEffect(function() {
    if (!dialog) return;
    var onKey = function(e) {
      if (e.key === "Escape") { respond(false); }
    };
    window.addEventListener("keydown", onKey);
    return function() { window.removeEventListener("keydown", onKey); };
  }, [dialog]);

  var respond = function(result) {
    setDialog(null);
    if (__confirmResolve) { __confirmResolve(result); __confirmResolve = null; }
  };

  if (!dialog) return null;

  return React.createElement("div", {
    role: "dialog",
    "aria-modal": "true",
    "aria-label": dialog.title,
    style: {
      position: "fixed", inset: 0, background: "rgba(1,8,22,0.78)",
      display: "flex", alignItems: "center", justifyContent: "center",
      zIndex: 10001,
    },
    onClick: function() { respond(false); },
  },
    React.createElement("div", {
      className: "fade-in",
      style: {
        background: "#0a1b3d", border: "1px solid #174b98",
        borderRadius: 12, padding: 28, width: "92%", maxWidth: 420,
        boxShadow: "0 24px 60px rgba(1,8,22,0.9)",
      },
      onClick: function(e) { e.stopPropagation(); },
    },
      React.createElement("h3", {
        style: { fontSize: 18, fontWeight: 700, color: "#f2f7ff", marginBottom: 12 },
      }, dialog.title),
      React.createElement("p", {
        style: { fontSize: 15, color: "#c6d4eb", marginBottom: 24, lineHeight: 1.5 },
      }, dialog.message),
      React.createElement("div", { style: { display: "flex", gap: 10, justifyContent: "flex-end" } },
        React.createElement("button", {
          className: "btn-hover",
          onClick: function() { respond(false); },
          style: {
            padding: "10px 20px", background: "transparent",
            border: "1px solid #174b98", borderRadius: 8,
            color: "#c6d4eb", cursor: "pointer", fontSize: 15, fontWeight: 600,
            minHeight: 44,
          },
        }, dialog.cancelLabel),
        React.createElement("button", {
          ref: confirmBtnRef,
          className: "btn-hover",
          onClick: function() { respond(true); },
          style: {
            padding: "10px 20px",
            background: dialog.danger ? "#b34739" : "#0077b6",
            border: "none", borderRadius: 8,
            color: "#fff", cursor: "pointer", fontSize: 15, fontWeight: 700,
            minHeight: 44,
          },
        }, dialog.confirmLabel)
      )
    )
  );
}

// ── Loading Skeleton ──

function Skeleton(props) {
  var w = props.width || "100%";
  var h = props.height || 16;
  var r = props.radius || 6;
  return React.createElement("div", {
    "aria-hidden": "true",
    className: "skeleton-shimmer",
    style: {
      width: w, height: h, borderRadius: r,
      background: "linear-gradient(90deg, #0d285a 25%, #174b9840 50%, #0d285a 75%)",
      backgroundSize: "400% 100%",
      animation: "shimmer 1.5s ease infinite",
    },
  });
}

function SkeletonCard() {
  return React.createElement("div", {
    style: {
      background: "#0a1b3d", border: "1px solid #174b98",
      borderRadius: 10, padding: 24, marginBottom: 12,
    },
  },
    React.createElement(Skeleton, { width: "40%", height: 18, radius: 8 }),
    React.createElement("div", { style: { height: 10 } }),
    React.createElement(Skeleton, { width: "80%", height: 14 }),
    React.createElement("div", { style: { height: 8 } }),
    React.createElement(Skeleton, { width: "60%", height: 14 }),
  );
}

function SkeletonTable(props) {
  var rows = props.rows || 5;
  var cols = props.cols || 4;
  return React.createElement("div", { style: { display: "flex", flexDirection: "column", gap: 8 } },
    Array.from({ length: rows }).map(function(_, i) {
      return React.createElement("div", { key: i, style: { display: "flex", gap: 12 } },
        Array.from({ length: cols }).map(function(_, j) {
          return React.createElement(Skeleton, {
            key: j,
            width: j === 0 ? "30%" : "20%",
            height: 14,
          });
        })
      );
    })
  );
}

// ── Empty State ──

function EmptyState(props) {
  var icon = props.icon || "\uD83D\uDCC2";
  var title = props.title || "\u041D\u0435\u0442 \u0434\u0430\u043D\u043D\u044B\u0445";
  var description = props.description || "";
  var actionLabel = props.actionLabel;
  var onAction = props.onAction;

  return React.createElement("div", {
    style: {
      display: "flex", flexDirection: "column", alignItems: "center",
      justifyContent: "center", padding: "48px 24px", textAlign: "center",
    },
  },
    React.createElement("div", {
      style: { fontSize: 56, marginBottom: 16, opacity: 0.6, filter: "grayscale(0.3)" },
    }, icon),
    React.createElement("div", {
      style: { fontSize: 18, fontWeight: 700, color: "#f2f7ff", marginBottom: 8 },
    }, title),
    description && React.createElement("div", {
      style: { fontSize: 14, color: "#8ea5c7", marginBottom: 20, maxWidth: 360, lineHeight: 1.5 },
    }, description),
    actionLabel && onAction && React.createElement("button", {
      className: "btn-hover",
      onClick: onAction,
      style: {
        padding: "10px 22px", background: "#0077b6", color: "#fff",
        border: "none", borderRadius: 8, cursor: "pointer",
        fontSize: 15, fontWeight: 700, minHeight: 44,
      },
    }, actionLabel)
  );
}

// ── CountUp Animation ──

function CountUp(props) {
  var end = props.value || 0;
  var duration = props.duration || 800;
  var ref = React.useRef(null);
  var [display, setDisplay] = React.useState(0);

  React.useEffect(function() {
    var start = 0;
    var startTime = null;
    function step(ts) {
      if (!startTime) startTime = ts;
      var progress = Math.min((ts - startTime) / duration, 1);
      var eased = 1 - Math.pow(1 - progress, 3);
      setDisplay(Math.round(eased * end));
      if (progress < 1) ref.current = requestAnimationFrame(step);
    }
    ref.current = requestAnimationFrame(step);
    return function() { if (ref.current) cancelAnimationFrame(ref.current); };
  }, [end, duration]);

  return React.createElement("span", null, display);
}

// ── Upload Progress ──

function UploadProgressBar(props) {
  var progress = props.progress || 0;
  return React.createElement("div", {
    role: "progressbar",
    "aria-valuenow": progress,
    "aria-valuemin": 0,
    "aria-valuemax": 100,
    "aria-label": "\u0417\u0430\u0433\u0440\u0443\u0437\u043a\u0430: " + progress + "%",
    style: {
      width: "100%", height: 6, background: "#174b9840",
      borderRadius: 3, overflow: "hidden",
    },
  },
    React.createElement("div", {
      style: {
        width: progress + "%", height: "100%",
        background: "linear-gradient(90deg, #0077b6, #5bc0eb)",
        borderRadius: 3,
        transition: "width 0.3s ease",
      },
    })
  );
}

// ── Debounced Input ──

function useDebouncedValue(value, delay) {
  if (delay === void 0) delay = 300;
  var [debouncedValue, setDebouncedValue] = React.useState(value);
  React.useEffect(function() {
    var timer = setTimeout(function() { setDebouncedValue(value); }, delay);
    return function() { clearTimeout(timer); };
  }, [value, delay]);
  return debouncedValue;
}

// ── Hotkeys ──

var __hotkeyRegistry = {};

function useHotkey(key, callback, deps) {
  React.useEffect(function() {
    var handler = function(e) {
      var combo = "";
      if (e.ctrlKey || e.metaKey) combo += "ctrl+";
      if (e.shiftKey) combo += "shift+";
      if (e.altKey) combo += "alt+";
      combo += e.key.toLowerCase();

      if (combo === key.toLowerCase()) {
        var active = document.activeElement;
        var isInput = active && (active.tagName === "INPUT" || active.tagName === "TEXTAREA" || active.tagName === "SELECT" || active.contentEditable === "true");
        if (isInput && key.toLowerCase() !== "escape") return;
        e.preventDefault();
        callback();
      }
    };
    window.addEventListener("keydown", handler);
    return function() { window.removeEventListener("keydown", handler); };
  }, deps || [callback]);
}

// ── Breadcrumbs ──

function Breadcrumbs(props) {
  var items = props.items || [];
  if (items.length <= 1) return null;

  return React.createElement("nav", {
    "aria-label": "\u041D\u0430\u0432\u0438\u0433\u0430\u0446\u0438\u044F",
    style: { display: "flex", alignItems: "center", gap: 6, marginBottom: 16, fontSize: 13, flexWrap: "wrap" },
  },
    items.map(function(item, i) {
      var isLast = i === items.length - 1;
      return React.createElement(React.Fragment, { key: i },
        isLast
          ? React.createElement("span", { style: { color: "#f2f7ff", fontWeight: 600 }, "aria-current": "page" }, item.label)
          : React.createElement("button", {
              onClick: item.onClick,
              style: {
                background: "none", border: "none", color: "#8ea5c7",
                cursor: "pointer", fontSize: 13, padding: 0,
                textDecoration: "underline", textUnderlineOffset: 3,
              },
            }, item.label),
        !isLast && React.createElement("span", { style: { color: "#8ea5c744" }, "aria-hidden": "true" }, " / ")
      );
    })
  );
}

// ── Scroll Position Memory ──

var __scrollPositions = {};

function saveScrollPosition(pageId) {
  __scrollPositions[pageId] = window.scrollY || document.documentElement.scrollTop || 0;
}

function restoreScrollPosition(pageId) {
  var pos = __scrollPositions[pageId];
  if (pos !== undefined) {
    requestAnimationFrame(function() { window.scrollTo(0, pos); });
  } else {
    window.scrollTo(0, 0);
  }
}

// ── Sortable Table Header ──

function SortableHeader(props) {
  var label = props.label;
  var field = props.field;
  var currentSort = props.sortField;
  var currentDir = props.sortDir;
  var onSort = props.onSort;
  var isActive = currentSort === field;
  var arrow = isActive ? (currentDir === "asc" ? " \u2191" : " \u2193") : "";

  return React.createElement("th", {
    style: {
      padding: "12px 14px", textAlign: "left",
      borderBottom: "1px solid #174b98",
      color: isActive ? "#5bc0eb" : "#5bc0eb",
      fontSize: 13, letterSpacing: 1, textTransform: "uppercase",
      fontWeight: 700, cursor: "pointer", userSelect: "none",
      whiteSpace: "nowrap",
    },
    onClick: function() { onSort(field); },
    "aria-sort": isActive ? (currentDir === "asc" ? "ascending" : "descending") : "none",
  }, label + arrow);
}

function useSortableData(items, defaultField, defaultDir) {
  var [sortField, setSortField] = React.useState(defaultField || "");
  var [sortDir, setSortDir] = React.useState(defaultDir || "asc");

  var handleSort = React.useCallback(function(field) {
    if (field === sortField) {
      setSortDir(function(d) { return d === "asc" ? "desc" : "asc"; });
    } else {
      setSortField(field);
      setSortDir("asc");
    }
  }, [sortField]);

  var sorted = React.useMemo(function() {
    if (!sortField || !Array.isArray(items)) return items || [];
    return items.slice().sort(function(a, b) {
      var av = a[sortField], bv = b[sortField];
      if (av == null) av = "";
      if (bv == null) bv = "";
      var cmp = typeof av === "number" ? av - bv : String(av).localeCompare(String(bv), "ru");
      return sortDir === "desc" ? -cmp : cmp;
    });
  }, [items, sortField, sortDir]);

  return { sorted: sorted, sortField: sortField, sortDir: sortDir, onSort: handleSort };
}

// ── Swipe handler for mobile sidebar ──

function useSwipe(onSwipeRight, onSwipeLeft) {
  var startX = React.useRef(0);
  var startY = React.useRef(0);

  React.useEffect(function() {
    var onTouchStart = function(e) {
      startX.current = e.touches[0].clientX;
      startY.current = e.touches[0].clientY;
    };
    var onTouchEnd = function(e) {
      var dx = e.changedTouches[0].clientX - startX.current;
      var dy = e.changedTouches[0].clientY - startY.current;
      if (Math.abs(dx) < 50 || Math.abs(dy) > Math.abs(dx)) return;
      if (dx > 0 && onSwipeRight) onSwipeRight();
      if (dx < 0 && onSwipeLeft) onSwipeLeft();
    };
    document.addEventListener("touchstart", onTouchStart, { passive: true });
    document.addEventListener("touchend", onTouchEnd, { passive: true });
    return function() {
      document.removeEventListener("touchstart", onTouchStart);
      document.removeEventListener("touchend", onTouchEnd);
    };
  }, [onSwipeRight, onSwipeLeft]);
}

// ── Onboarding Welcome Banner ──

function OnboardingBanner(props) {
  var user = props.user;
  var onDismiss = props.onDismiss;
  var storageKey = "femida_onboarding_dismissed_" + (user && user.id || "anon");
  var [dismissed, setDismissed] = React.useState(function() {
    try { return localStorage.getItem(storageKey) === "1"; } catch(e) { return false; }
  });

  if (dismissed || !user) return null;

  var handleDismiss = function() {
    setDismissed(true);
    try { localStorage.setItem(storageKey, "1"); } catch(e) {}
    if (onDismiss) onDismiss();
  };

  var name = (user.name || "").split(" ")[0] || user.surname || "";

  return React.createElement("div", {
    className: "fade-in",
    style: {
      background: "linear-gradient(135deg, #023e8a22 0%, #0077b622 100%)",
      border: "1px solid #0077b644",
      borderRadius: 12, padding: "20px 24px", marginBottom: 18,
      display: "flex", alignItems: "flex-start", gap: 16,
    },
  },
    React.createElement("div", { style: { fontSize: 36, flexShrink: 0 } }, "\uD83D\uDC4B"),
    React.createElement("div", { style: { flex: 1 } },
      React.createElement("div", { style: { fontSize: 18, fontWeight: 700, color: "#f2f7ff", marginBottom: 6 } },
        "\u0414\u043E\u0431\u0440\u043E \u043F\u043E\u0436\u0430\u043B\u043E\u0432\u0430\u0442\u044C" + (name ? ", " + name : "") + "!"
      ),
      React.createElement("div", { style: { fontSize: 14, color: "#c6d4eb", lineHeight: 1.5 } },
        "\u042D\u0442\u043E \u0432\u0430\u0448 \u043B\u0438\u0447\u043D\u044B\u0439 \u043A\u0430\u0431\u0438\u043D\u0435\u0442 \u0432 \u0415\u0418\u0410\u0421 \u00AB\u0424\u0435\u043C\u0438\u0434\u0430\u00BB. \u0418\u0441\u043F\u043E\u043B\u044C\u0437\u0443\u0439\u0442\u0435 \u0431\u043E\u043A\u043E\u0432\u043E\u0435 \u043C\u0435\u043D\u044E \u0434\u043B\u044F \u043D\u0430\u0432\u0438\u0433\u0430\u0446\u0438\u0438."
      ),
      React.createElement("button", {
        onClick: handleDismiss,
        style: {
          marginTop: 10, background: "none", border: "1px solid #0077b666",
          borderRadius: 6, padding: "6px 14px", color: "#6bb7ff",
          cursor: "pointer", fontSize: 13,
        },
      }, "\u041F\u043E\u043D\u044F\u0442\u043D\u043E, \u0441\u043F\u0430\u0441\u0438\u0431\u043E")
    )
  );
}
