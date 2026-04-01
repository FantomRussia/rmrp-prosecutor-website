"""
ЕИАС «Фемида» — Discord Bot
============================
Бот для прокуратуры RMRP: уведомления, команды, авто-обновления.

Зависимости: discord.py[voice]>=2.3, aiohttp>=3.9
Конфиг: bot_config.json (см. bot_config.json в директории проекта)

Безопасность (согласно Регламенту §3.4):
  - Токен бота хранится ИСКЛЮЧИТЕЛЬНО в переменной окружения DISCORD_TOKEN
    или в bot_config.json, который не публикуется в репозиторий.
  - Никаких хардкодных секретов в коде.
"""

from __future__ import annotations

import asyncio
import json
import logging
import os
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Optional

import aiohttp
import discord
from discord import app_commands
from discord.ext import commands, tasks

# ──────────────────────────────────────────────────────────────────────────────
# LOGGING (согласно Регламенту §7.3 — логирование критических действий)
# ──────────────────────────────────────────────────────────────────────────────

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
    handlers=[
        logging.StreamHandler(sys.stdout),
        logging.FileHandler("bot.log", encoding="utf-8"),
    ],
)
log = logging.getLogger("femida-bot")

# ──────────────────────────────────────────────────────────────────────────────
# CONFIG LOADER
# ──────────────────────────────────────────────────────────────────────────────

CONFIG_PATH = Path(__file__).parent / "bot_config.json"


def load_config() -> dict[str, Any]:
    """Загружает конфигурацию из bot_config.json."""
    if not CONFIG_PATH.exists():
        log.critical("Файл bot_config.json не найден рядом с bot.py")
        sys.exit(1)
    with CONFIG_PATH.open(encoding="utf-8") as fh:
        cfg = json.load(fh)
    # Токен можно переопределить переменной окружения (Регламент §3.4)
    env_token = os.getenv("DISCORD_TOKEN")
    if env_token:
        cfg["TOKEN"] = env_token
    if not cfg.get("TOKEN") or cfg["TOKEN"].startswith("YOUR_"):
        log.critical("Токен бота не задан. Укажите DISCORD_TOKEN в окружении или TOKEN в bot_config.json")
        sys.exit(1)
    return cfg


CONFIG: dict[str, Any] = load_config()

API_URL: str = CONFIG["API_URL"]            # напр. https://prosecutors-office-rmrp.ru/api.php
API_COOKIE: str = CONFIG["API_SESSION_COOKIE"]  # значение cookie PHPSESSID=...
GUILD_ID: int = int(CONFIG["GUILD_ID"])

# Каналы Discord (ID из bot_config.json)
CH_COMPLAINTS      = int(CONFIG["channels"]["complaints"])      # жалобы / новые дела
CH_BONUSES         = int(CONFIG["channels"]["bonuses"])         # премии
CH_REGISTRATIONS   = int(CONFIG["channels"]["registrations"])   # заявки на регистрацию
CH_ROSTER          = int(CONFIG["channels"]["roster"])          # состав прокуратуры
CH_STATS           = int(CONFIG["channels"]["stats"])           # статистика
CH_DAILY           = int(CONFIG["channels"]["daily"])           # ежедневная сводка
CH_BOT_COMMANDS    = int(CONFIG["channels"]["bot_commands"])    # канал для команд бота
CH_AUDIT_LOG       = int(CONFIG["channels"]["audit_log"])       # лог-канал (Регламент §7.3)

# Период опроса API (секунды)
POLL_INTERVAL: int = int(CONFIG.get("poll_interval_seconds", 60))
ROSTER_INTERVAL: int = int(CONFIG.get("roster_update_interval_seconds", 300))
DAILY_HOUR_UTC: int = int(CONFIG.get("daily_summary_hour_utc", 6))

# ──────────────────────────────────────────────────────────────────────────────
# INTERNAL STATE  (отслеживание уже отправленных уведомлений)
# ──────────────────────────────────────────────────────────────────────────────

_seen_complaints: set[str] = set()        # id обращений/жалоб
_seen_bonuses: set[str] = set()           # id премий
_seen_registrations: set[str] = set()     # id заявок на регистрацию
_last_roster_hash: str = ""               # хэш состава прокуратуры
_last_daily_date: Optional[str] = None    # дата последней сводки (YYYY-MM-DD)
_roster_message_id: Optional[int] = None  # ID сообщения с составом (для редактирования)

# ──────────────────────────────────────────────────────────────────────────────
# HTTP HELPER
# ──────────────────────────────────────────────────────────────────────────────

async def api_get(session: aiohttp.ClientSession, action: str, **params) -> Optional[dict]:
    """
    Выполняет GET-запрос к api.php.
    Возвращает распарсенный JSON или None при ошибке.
    """
    try:
        headers = {}
        if API_COOKIE:
            headers["Cookie"] = API_COOKIE
        url = f"{API_URL}?action={action}"
        for k, v in params.items():
            url += f"&{k}={v}"
        async with session.get(url, headers=headers, timeout=aiohttp.ClientTimeout(total=15)) as resp:
            if resp.status != 200:
                log.warning("API [%s] вернул HTTP %d", action, resp.status)
                return None
            data = await resp.json(content_type=None)
            if not data.get("ok"):
                log.warning("API [%s] вернул ok=false: %s", action, data.get("error", "—"))
                return None
            return data
    except asyncio.TimeoutError:
        log.error("API [%s]: таймаут запроса", action)
    except aiohttp.ClientError as exc:
        log.error("API [%s]: сетевая ошибка: %s", action, exc)
    except Exception as exc:
        log.error("API [%s]: неожиданная ошибка: %s", action, exc)
    return None


async def fetch_state(session: aiohttp.ClientSession) -> Optional[dict]:
    """Загружает полный bootstrap-state из api.php."""
    data = await api_get(session, "bootstrap")
    if data and data.get("authenticated"):
        return data.get("state")
    return None

# ──────────────────────────────────────────────────────────────────────────────
# EMBED BUILDERS
# ──────────────────────────────────────────────────────────────────────────────

COLOR_COMPLAINT   = 0xE53935   # красный — жалобы
COLOR_BONUS       = 0xFFC107   # янтарный — премии
COLOR_REGISTRATION = 0x1E88E5  # синий — регистрация
COLOR_ROSTER      = 0x43A047   # зелёный — состав
COLOR_STATS       = 0x8E24AA   # фиолетовый — статистика
COLOR_DAILY       = 0x00ACC1   # голубой — сводка
COLOR_ERROR       = 0x757575   # серый — ошибки


def ts(iso: str) -> str:
    """Форматирует ISO-дату для отображения."""
    try:
        dt = datetime.fromisoformat(iso.replace("Z", "+00:00"))
        return dt.strftime("%d.%m.%Y %H:%M")
    except Exception:
        return iso or "—"


def role_label(role: str) -> str:
    """Возвращает человекочитаемое название роли."""
    labels = {
        "STAFF":        "Сотрудник",
        "SENIOR_STAFF": "Старший помощник прокурора",
        "USP":          "Прокурор УСБ",
        "BOSS":         "Руководитель субъекта",
        "FEDERAL":      "Федеральный сотрудник",
        "ADMIN":        "Администратор",
    }
    return labels.get(role, role)


def build_complaint_embed(case: dict, state: dict) -> discord.Embed:
    """Embed для нового обращения/жалобы."""
    subject = case.get("subject") or "—"
    status  = case.get("status") or "—"
    title   = case.get("title") or case.get("description") or "Без названия"
    case_id = case.get("id", "")[:8]
    created = ts(case.get("createdAt") or "")

    emb = discord.Embed(
        title=f"📋 Новое обращение #{case_id}",
        description=title[:512],
        colour=COLOR_COMPLAINT,
        timestamp=datetime.now(timezone.utc),
    )
    emb.add_field(name="Субъект", value=subject, inline=True)
    emb.add_field(name="Статус",  value=status,  inline=True)
    emb.add_field(name="Создано", value=created, inline=True)
    if case.get("complainantName"):
        emb.add_field(name="Заявитель", value=case["complainantName"], inline=True)
    if case.get("assignedTo"):
        user = next((u for u in state.get("users", []) if u.get("id") == case["assignedTo"]), None)
        if user:
            emb.add_field(
                name="Назначен",
                value=f"{user.get('surname', '')} {user.get('name', '')}".strip(),
                inline=True,
            )
    emb.set_footer(text="ЕИАС «Фемида» • Прокуратура RMRP")
    return emb


def build_bonus_embed(bonus: dict, state: dict) -> discord.Embed:
    """Embed для новой заявки на премию."""
    bonus_id  = bonus.get("id", "")[:8]
    amount    = bonus.get("amount") or bonus.get("baseAmount") or "—"
    subject   = bonus.get("subject") or "—"
    created   = ts(bonus.get("createdAt") or "")
    status    = bonus.get("status") or "pending"
    user_id   = bonus.get("userId") or bonus.get("requesterId") or ""

    user_name = "—"
    if user_id:
        usr = next((u for u in state.get("users", []) if u.get("id") == user_id), None)
        if usr:
            user_name = f"{usr.get('surname', '')} {usr.get('name', '')}".strip()

    status_labels = {
        "pending":  "⏳ На рассмотрении",
        "approved": "✅ Одобрена",
        "rejected": "❌ Отклонена",
        "paid":     "💰 Выплачена",
    }

    emb = discord.Embed(
        title=f"💰 Новая заявка на премию #{bonus_id}",
        colour=COLOR_BONUS,
        timestamp=datetime.now(timezone.utc),
    )
    emb.add_field(name="Сотрудник", value=user_name,                        inline=True)
    emb.add_field(name="Субъект",   value=subject,                          inline=True)
    emb.add_field(name="Сумма",     value=f"{amount} ₽" if amount != "—" else "—", inline=True)
    emb.add_field(name="Статус",    value=status_labels.get(status, status), inline=True)
    emb.add_field(name="Создано",   value=created,                          inline=True)
    emb.set_footer(text="ЕИАС «Фемида» • Прокуратура RMRP")
    return emb


def build_registration_embed(reg: dict, state: dict) -> discord.Embed:
    """Embed для новой заявки на регистрацию."""
    reg_id  = reg.get("id", "")[:8]
    name    = f"{reg.get('surname', '')} {reg.get('name', '')}".strip() or reg.get("login", "—")
    subject = reg.get("requestedSubject") or "—"
    role    = role_label(reg.get("requestedRole") or "")
    created = ts(reg.get("createdAt") or "")
    comment = reg.get("comment") or "—"

    # Найти должность
    pos_id  = reg.get("requestedPositionId") or ""
    pos_title = "—"
    for role_key, positions in (state.get("positions") or {}).items():
        for pos in positions:
            if pos.get("id") == pos_id:
                pos_title = pos.get("title", "—")
                break

    emb = discord.Embed(
        title=f"📝 Заявка на регистрацию #{reg_id}",
        colour=COLOR_REGISTRATION,
        timestamp=datetime.now(timezone.utc),
    )
    emb.add_field(name="ФИО",       value=name,      inline=True)
    emb.add_field(name="Субъект",   value=subject,   inline=True)
    emb.add_field(name="Роль",      value=role,      inline=True)
    emb.add_field(name="Должность", value=pos_title, inline=True)
    emb.add_field(name="Создано",   value=created,   inline=True)
    if comment and comment != "—":
        emb.add_field(name="Комментарий", value=comment[:512], inline=False)
    emb.set_footer(text="ЕИАС «Фемида» • Прокуратура RMRP")
    return emb


def build_roster_embed(state: dict) -> discord.Embed:
    """Embed с составом прокуратуры, сгруппированным по субъектам."""
    users: list[dict] = state.get("users") or []
    active = [u for u in users if not u.get("blocked")]

    # Группировка по субъекту
    subjects: dict[str, list[dict]] = {}
    for user in active:
        subj = user.get("subject") or "Неизвестный субъект"
        subjects.setdefault(subj, []).append(user)

    emb = discord.Embed(
        title="🏛️ Состав прокуратуры RMRP",
        description=f"Активных сотрудников: **{len(active)}**",
        colour=COLOR_ROSTER,
        timestamp=datetime.now(timezone.utc),
    )

    for subj, members in sorted(subjects.items()):
        lines = []
        for u in sorted(members, key=lambda x: x.get("surname") or ""):
            fio   = f"{u.get('surname', '')} {u.get('name', '')}".strip()
            rlbl  = role_label(u.get("role") or "")
            lines.append(f"• **{fio}** — {rlbl}")
        field_value = "\n".join(lines) or "—"
        # Discord ограничивает поле 1024 символами
        if len(field_value) > 1020:
            field_value = field_value[:1020] + "…"
        emb.add_field(name=subj, value=field_value, inline=False)

    emb.set_footer(text="ЕИАС «Фемида» • обновлено")
    return emb


def build_stats_embed(state: dict, subject: Optional[str] = None) -> discord.Embed:
    """Embed со статистикой. Если subject задан — только по нему."""
    users    = state.get("users") or []
    reports  = state.get("reports") or []
    bonuses  = state.get("bonuses") or []
    reg_reqs = state.get("registrationRequests") or []
    events   = state.get("activityEvents") or []

    if subject:
        users    = [u for u in users    if u.get("subject") == subject]
        reports  = [r for r in reports  if r.get("subject") == subject]
        bonuses  = [b for b in bonuses  if b.get("subject") == subject]
        reg_reqs = [r for r in reg_reqs if r.get("requestedSubject") == subject]
        events   = [e for e in events   if e.get("subject") == subject]

    active_users = [u for u in users if not u.get("blocked")]
    pending_regs = [r for r in reg_reqs if r.get("status") == "pending"]
    pending_bonuses = [b for b in bonuses if b.get("status") == "pending"]
    approved_bonuses = [b for b in bonuses if b.get("status") == "approved"]

    title = f"📊 Статистика: {subject}" if subject else "📊 Общая статистика ЕИАС «Фемида»"

    emb = discord.Embed(
        title=title,
        colour=COLOR_STATS,
        timestamp=datetime.now(timezone.utc),
    )
    emb.add_field(name="👤 Сотрудников",           value=str(len(active_users)),     inline=True)
    emb.add_field(name="📋 Отчётов",               value=str(len(reports)),          inline=True)
    emb.add_field(name="⚡ Событий активности",    value=str(len(events)),           inline=True)
    emb.add_field(name="📝 Заявок на регистрацию", value=str(len(pending_regs)),     inline=True)
    emb.add_field(name="💰 Премий на рассмотрении",value=str(len(pending_bonuses)),  inline=True)
    emb.add_field(name="✅ Одобренных премий",      value=str(len(approved_bonuses)), inline=True)
    emb.set_footer(text="ЕИАС «Фемида» • Прокуратура RMRP")
    return emb


def build_daily_summary_embed(state: dict) -> discord.Embed:
    """Embed с ежедневной сводкой."""
    today = datetime.now(timezone.utc).strftime("%d.%m.%Y")
    users    = state.get("users") or []
    reports  = state.get("reports") or []
    bonuses  = state.get("bonuses") or []
    reg_reqs = state.get("registrationRequests") or []

    active_users     = len([u for u in users if not u.get("blocked")])
    pending_regs     = len([r for r in reg_reqs if r.get("status") == "pending"])
    pending_bonuses  = len([b for b in bonuses if b.get("status") == "pending"])
    approved_bonuses = len([b for b in bonuses if b.get("status") == "approved"])
    total_reports    = len(reports)

    # Субъекты с наибольшим числом сотрудников
    subjects: dict[str, int] = {}
    for u in users:
        if not u.get("blocked"):
            subj = u.get("subject") or "—"
            subjects[subj] = subjects.get(subj, 0) + 1
    top_subjects = sorted(subjects.items(), key=lambda x: x[1], reverse=True)[:5]
    top_lines = "\n".join(f"• {s}: **{c}** чел." for s, c in top_subjects) or "—"

    emb = discord.Embed(
        title=f"🌅 Ежедневная сводка — {today}",
        description="Состояние системы ЕИАС «Фемида» на начало дня.",
        colour=COLOR_DAILY,
        timestamp=datetime.now(timezone.utc),
    )
    emb.add_field(name="👤 Активных сотрудников",    value=str(active_users),     inline=True)
    emb.add_field(name="📋 Всего отчётов",            value=str(total_reports),    inline=True)
    emb.add_field(name="📝 Ожидают регистрации",      value=str(pending_regs),     inline=True)
    emb.add_field(name="💰 Премий на рассмотрении",   value=str(pending_bonuses),  inline=True)
    emb.add_field(name="✅ Одобренных премий",         value=str(approved_bonuses), inline=True)
    emb.add_field(name="🏛️ Топ субъектов", value=top_lines, inline=False)
    emb.set_footer(text="ЕИАС «Фемида» • Прокуратура RMRP")
    return emb

# ──────────────────────────────────────────────────────────────────────────────
# BOT CLASS
# ──────────────────────────────────────────────────────────────────────────────

intents = discord.Intents.default()
intents.message_content = False  # не нужен для slash-команд (минимальные права)

bot = commands.Bot(command_prefix="!", intents=intents)
tree = bot.tree


async def get_channel(channel_id: int) -> Optional[discord.TextChannel]:
    """Возвращает канал по ID или None."""
    try:
        ch = bot.get_channel(channel_id) or await bot.fetch_channel(channel_id)
        return ch  # type: ignore
    except Exception as exc:
        log.warning("Не удалось получить канал %d: %s", channel_id, exc)
        return None


async def bot_log(message: str) -> None:
    """Отправляет системное сообщение в лог-канал (Регламент §7.3)."""
    log.info("[BOT_LOG] %s", message)
    ch = await get_channel(CH_AUDIT_LOG)
    if ch:
        try:
            emb = discord.Embed(
                description=f"🔧 {message}",
                colour=0x607D8B,
                timestamp=datetime.now(timezone.utc),
            )
            await ch.send(embed=emb)
        except Exception as exc:
            log.warning("Не удалось записать в лог-канал: %s", exc)

# ──────────────────────────────────────────────────────────────────────────────
# POLLING TASKS
# ──────────────────────────────────────────────────────────────────────────────

@tasks.loop(seconds=POLL_INTERVAL)
async def poll_notifications():
    """
    Опрашивает API каждые POLL_INTERVAL секунд.
    Отправляет уведомления о:
      - новых жалобах/делах (CH_COMPLAINTS)
      - новых заявках на премию (CH_BONUSES)
      - новых заявках на регистрацию (CH_REGISTRATIONS)
    """
    global _seen_complaints, _seen_bonuses, _seen_registrations

    async with aiohttp.ClientSession() as session:
        state = await fetch_state(session)
        if not state:
            return

        # ── Жалобы / дела ─────────────────────────────────────────────────
        cases_raw: list[dict] = []
        # Ищем жалобы в activityEvents (kind=person/visit) или в отдельном ключе
        activity_events: list[dict] = state.get("activityEvents") or []
        for event in activity_events:
            eid = event.get("id") or ""
            if eid and eid not in _seen_complaints:
                _seen_complaints.add(eid)
                if _seen_complaints and len(_seen_complaints) > 1:
                    # не спамим при первом запуске — только реальные новые
                    ch = await get_channel(CH_COMPLAINTS)
                    if ch:
                        try:
                            emb = _build_event_embed(event, state)
                            await ch.send(embed=emb)
                        except Exception as exc:
                            log.error("Ошибка отправки уведомления о событии: %s", exc)
        # Инициализируем при первом запуске без уведомлений
        if not _seen_complaints:
            _seen_complaints = {e.get("id") for e in activity_events if e.get("id")}

        # ── Премии ────────────────────────────────────────────────────────
        bonuses: list[dict] = state.get("bonuses") or []
        new_bonuses = [b for b in bonuses if b.get("id") and b.get("id") not in _seen_bonuses]
        if not _seen_bonuses:
            # Первый запуск — запоминаем без уведомлений
            _seen_bonuses = {b.get("id") for b in bonuses if b.get("id")}
        else:
            for bonus in new_bonuses:
                _seen_bonuses.add(bonus["id"])
                ch = await get_channel(CH_BONUSES)
                if ch:
                    try:
                        await ch.send(embed=build_bonus_embed(bonus, state))
                    except Exception as exc:
                        log.error("Ошибка отправки уведомления о премии: %s", exc)

        # ── Заявки на регистрацию ─────────────────────────────────────────
        reg_reqs: list[dict] = state.get("registrationRequests") or []
        pending_regs = [r for r in reg_reqs if r.get("status") == "pending"]
        new_regs = [r for r in pending_regs if r.get("id") and r.get("id") not in _seen_registrations]
        if not _seen_registrations:
            _seen_registrations = {r.get("id") for r in reg_reqs if r.get("id")}
        else:
            for reg in new_regs:
                _seen_registrations.add(reg["id"])
                ch = await get_channel(CH_REGISTRATIONS)
                if ch:
                    try:
                        await ch.send(embed=build_registration_embed(reg, state))
                    except Exception as exc:
                        log.error("Ошибка отправки уведомления о регистрации: %s", exc)


def _build_event_embed(event: dict, state: dict) -> discord.Embed:
    """Embed для нового события активности."""
    event_id  = (event.get("id") or "")[:8]
    etype     = event.get("type") or event.get("eventType") or "—"
    subject   = event.get("subject") or "—"
    created   = ts(event.get("createdAt") or event.get("date") or "")
    descr     = event.get("description") or event.get("comment") or "—"
    user_id   = event.get("userId") or event.get("authorId") or ""

    # Метки типов событий
    kind_labels = {
        "detention":    "Задержание госслужащего",
        "decision":     "Вынесение решения",
        "fine":         "Назначение штрафа",
        "warning":      "Предупреждение",
        "disciplinary": "Дисциплинарное взыскание",
        "official_visit": "Официальный визит / мероприятие",
        "news":         "Новость (пресс-служба)",
        "duty":         "Дежурство",
    }

    # Найти автора
    user_name = "—"
    if user_id:
        usr = next((u for u in state.get("users", []) if u.get("id") == user_id), None)
        if usr:
            user_name = f"{usr.get('surname', '')} {usr.get('name', '')}".strip()

    emb = discord.Embed(
        title=f"⚡ Новое событие #{event_id}",
        description=descr[:512],
        colour=COLOR_COMPLAINT,
        timestamp=datetime.now(timezone.utc),
    )
    emb.add_field(name="Тип",      value=kind_labels.get(etype, etype), inline=True)
    emb.add_field(name="Субъект",  value=subject,                       inline=True)
    emb.add_field(name="Автор",    value=user_name,                     inline=True)
    emb.add_field(name="Создано",  value=created,                       inline=True)
    emb.set_footer(text="ЕИАС «Фемида» • Прокуратура RMRP")
    return emb


@tasks.loop(seconds=ROSTER_INTERVAL)
async def update_roster():
    """
    Обновляет сообщение с составом прокуратуры каждые ROSTER_INTERVAL секунд.
    Если сообщение уже есть — редактирует его. Иначе создаёт новое.
    """
    global _last_roster_hash, _roster_message_id

    async with aiohttp.ClientSession() as session:
        state = await fetch_state(session)
        if not state:
            return

    roster_emb = build_roster_embed(state)
    # Хэш для избежания лишних правок
    new_hash = str(hash(str(state.get("users"))))
    if new_hash == _last_roster_hash:
        return
    _last_roster_hash = new_hash

    ch = await get_channel(CH_ROSTER)
    if not ch:
        return

    try:
        if _roster_message_id:
            try:
                msg = await ch.fetch_message(_roster_message_id)
                await msg.edit(embed=roster_emb)
                log.info("Состав прокуратуры обновлён (edit msg %d)", _roster_message_id)
                return
            except discord.NotFound:
                _roster_message_id = None

        # Создаём новое закреплённое сообщение
        msg = await ch.send(embed=roster_emb)
        _roster_message_id = msg.id
        try:
            await msg.pin()
        except discord.Forbidden:
            log.warning("Нет прав для закрепления сообщения в канале %d", CH_ROSTER)
        log.info("Состав прокуратуры опубликован (msg %d)", msg.id)
    except Exception as exc:
        log.error("Ошибка обновления состава: %s", exc)


@tasks.loop(seconds=60)
async def daily_summary():
    """
    Отправляет ежедневную сводку в CH_DAILY в заданный час UTC.
    """
    global _last_daily_date

    now = datetime.now(timezone.utc)
    if now.hour != DAILY_HOUR_UTC:
        return
    today_str = now.strftime("%Y-%m-%d")
    if _last_daily_date == today_str:
        return  # уже отправляли сегодня

    async with aiohttp.ClientSession() as session:
        state = await fetch_state(session)
        if not state:
            return

    ch = await get_channel(CH_DAILY)
    if not ch:
        return

    try:
        await ch.send(embed=build_daily_summary_embed(state))
        _last_daily_date = today_str
        log.info("Ежедневная сводка отправлена (%s)", today_str)
        await bot_log(f"Ежедневная сводка отправлена ({today_str})")
    except Exception as exc:
        log.error("Ошибка отправки ежедневной сводки: %s", exc)

# ──────────────────────────────────────────────────────────────────────────────
# SLASH COMMANDS
# ──────────────────────────────────────────────────────────────────────────────

@tree.command(
    name="статистика",
    description="Показать статистику ЕИАС «Фемида» (опционально — по субъекту)",
    guild=discord.Object(id=GUILD_ID),
)
@app_commands.describe(субъект="Название субъекта (оставьте пустым для общей статистики)")
async def cmd_stats(interaction: discord.Interaction, субъект: Optional[str] = None):
    """Слэш-команда /статистика [субъект]"""
    await interaction.response.defer(ephemeral=False)
    try:
        async with aiohttp.ClientSession() as session:
            state = await fetch_state(session)
        if not state:
            await interaction.followup.send("❌ Не удалось получить данные из ЕИАС «Фемида».")
            return

        # Проверка существования субъекта
        if субъект:
            all_subjects = {u.get("subject") for u in state.get("users") or [] if u.get("subject")}
            if субъект not in all_subjects:
                subject_list = "\n".join(f"• {s}" for s in sorted(all_subjects)) or "—"
                await interaction.followup.send(
                    f"❓ Субъект **{субъект}** не найден.\n\nДоступные субъекты:\n{subject_list}"
                )
                return

        emb = build_stats_embed(state, субъект)
        await interaction.followup.send(embed=emb)
        await bot_log(f"/статистика запрошена пользователем {interaction.user} (субъект: {субъект or 'все'})")
    except Exception as exc:
        log.error("/статистика: %s", exc)
        await interaction.followup.send("❌ Внутренняя ошибка бота.")


@tree.command(
    name="состав",
    description="Показать актуальный состав прокуратуры RMRP",
    guild=discord.Object(id=GUILD_ID),
)
async def cmd_roster(interaction: discord.Interaction):
    """Слэш-команда /состав"""
    await interaction.response.defer(ephemeral=False)
    try:
        async with aiohttp.ClientSession() as session:
            state = await fetch_state(session)
        if not state:
            await interaction.followup.send("❌ Не удалось получить данные из ЕИАС «Фемида».")
            return
        await interaction.followup.send(embed=build_roster_embed(state))
        await bot_log(f"/состав запрошен пользователем {interaction.user}")
    except Exception as exc:
        log.error("/состав: %s", exc)
        await interaction.followup.send("❌ Внутренняя ошибка бота.")


@tree.command(
    name="субъекты",
    description="Показать список субъектов прокуратуры и количество сотрудников",
    guild=discord.Object(id=GUILD_ID),
)
async def cmd_subjects(interaction: discord.Interaction):
    """Слэш-команда /субъекты"""
    await interaction.response.defer(ephemeral=False)
    try:
        async with aiohttp.ClientSession() as session:
            state = await fetch_state(session)
        if not state:
            await interaction.followup.send("❌ Не удалось получить данные.")
            return

        users = [u for u in (state.get("users") or []) if not u.get("blocked")]
        subjects: dict[str, int] = {}
        for u in users:
            s = u.get("subject") or "—"
            subjects[s] = subjects.get(s, 0) + 1

        lines = [
            f"• **{s}**: {c} чел."
            for s, c in sorted(subjects.items(), key=lambda x: x[1], reverse=True)
        ]
        emb = discord.Embed(
            title="🏛️ Субъекты прокуратуры RMRP",
            description="\n".join(lines) or "Нет данных",
            colour=COLOR_ROSTER,
            timestamp=datetime.now(timezone.utc),
        )
        emb.set_footer(text=f"Всего сотрудников: {len(users)}")
        await interaction.followup.send(embed=emb)
    except Exception as exc:
        log.error("/субъекты: %s", exc)
        await interaction.followup.send("❌ Внутренняя ошибка бота.")


@tree.command(
    name="премии",
    description="Показать сводку по заявкам на премии",
    guild=discord.Object(id=GUILD_ID),
)
@app_commands.describe(субъект="Фильтр по субъекту (необязательно)")
async def cmd_bonuses(interaction: discord.Interaction, субъект: Optional[str] = None):
    """Слэш-команда /премии [субъект]"""
    await interaction.response.defer(ephemeral=True)
    try:
        async with aiohttp.ClientSession() as session:
            state = await fetch_state(session)
        if not state:
            await interaction.followup.send("❌ Не удалось получить данные.", ephemeral=True)
            return

        bonuses: list[dict] = state.get("bonuses") or []
        if субъект:
            bonuses = [b for b in bonuses if b.get("subject") == субъект]

        pending  = [b for b in bonuses if b.get("status") == "pending"]
        approved = [b for b in bonuses if b.get("status") == "approved"]
        rejected = [b for b in bonuses if b.get("status") == "rejected"]
        paid     = [b for b in bonuses if b.get("status") == "paid"]

        title = f"💰 Премии: {субъект}" if субъект else "💰 Сводка по премиям"
        emb = discord.Embed(title=title, colour=COLOR_BONUS, timestamp=datetime.now(timezone.utc))
        emb.add_field(name="⏳ На рассмотрении", value=str(len(pending)),  inline=True)
        emb.add_field(name="✅ Одобрено",         value=str(len(approved)), inline=True)
        emb.add_field(name="❌ Отклонено",         value=str(len(rejected)), inline=True)
        emb.add_field(name="💵 Выплачено",         value=str(len(paid)),    inline=True)
        emb.add_field(name="📊 Всего",             value=str(len(bonuses)), inline=True)
        emb.set_footer(text="ЕИАС «Фемида» • Прокуратура RMRP")
        await interaction.followup.send(embed=emb, ephemeral=True)
    except Exception as exc:
        log.error("/премии: %s", exc)
        await interaction.followup.send("❌ Внутренняя ошибка бота.", ephemeral=True)


@tree.command(
    name="регистрации",
    description="Показать список заявок на регистрацию (только для уполномоченных)",
    guild=discord.Object(id=GUILD_ID),
)
async def cmd_registrations(interaction: discord.Interaction):
    """Слэш-команда /регистрации"""
    await interaction.response.defer(ephemeral=True)
    try:
        async with aiohttp.ClientSession() as session:
            state = await fetch_state(session)
        if not state:
            await interaction.followup.send("❌ Нет доступа к данным.", ephemeral=True)
            return

        reg_reqs: list[dict] = state.get("registrationRequests") or []
        pending = [r for r in reg_reqs if r.get("status") == "pending"]

        if not pending:
            await interaction.followup.send("✅ Нет ожидающих заявок на регистрацию.", ephemeral=True)
            return

        lines = []
        for r in pending[:20]:  # показываем не более 20
            name    = f"{r.get('surname', '')} {r.get('name', '')}".strip() or r.get("login", "—")
            subject = r.get("requestedSubject") or "—"
            role    = role_label(r.get("requestedRole") or "")
            created = ts(r.get("createdAt") or "")
            lines.append(f"• **{name}** | {subject} | {role} | {created}")

        emb = discord.Embed(
            title=f"📝 Ожидающие заявки на регистрацию ({len(pending)})",
            description="\n".join(lines),
            colour=COLOR_REGISTRATION,
            timestamp=datetime.now(timezone.utc),
        )
        if len(pending) > 20:
            emb.set_footer(text=f"Показано 20 из {len(pending)}")
        await interaction.followup.send(embed=emb, ephemeral=True)
        await bot_log(f"/регистрации запрошены пользователем {interaction.user}")
    except Exception as exc:
        log.error("/регистрации: %s", exc)
        await interaction.followup.send("❌ Внутренняя ошибка бота.", ephemeral=True)


@tree.command(
    name="сводка",
    description="Немедленно показать ежедневную сводку",
    guild=discord.Object(id=GUILD_ID),
)
async def cmd_daily(interaction: discord.Interaction):
    """Слэш-команда /сводка"""
    await interaction.response.defer(ephemeral=False)
    try:
        async with aiohttp.ClientSession() as session:
            state = await fetch_state(session)
        if not state:
            await interaction.followup.send("❌ Не удалось получить данные.")
            return
        await interaction.followup.send(embed=build_daily_summary_embed(state))
        await bot_log(f"/сводка запрошена пользователем {interaction.user}")
    except Exception as exc:
        log.error("/сводка: %s", exc)
        await interaction.followup.send("❌ Внутренняя ошибка бота.")


@tree.command(
    name="обновить-состав",
    description="Принудительно обновить сообщение с составом прокуратуры",
    guild=discord.Object(id=GUILD_ID),
)
async def cmd_refresh_roster(interaction: discord.Interaction):
    """Слэш-команда /обновить-состав"""
    await interaction.response.defer(ephemeral=True)
    global _last_roster_hash
    _last_roster_hash = ""  # сбросить хэш — обновление будет принудительным
    await interaction.followup.send("🔄 Принудительное обновление состава запущено.", ephemeral=True)
    await update_roster()  # запустить немедленно
    await bot_log(f"/обновить-состав вызвано пользователем {interaction.user}")


@tree.command(
    name="здоровье",
    description="Проверить доступность API ЕИАС «Фемида»",
    guild=discord.Object(id=GUILD_ID),
)
async def cmd_health(interaction: discord.Interaction):
    """Слэш-команда /здоровье — проверка API."""
    await interaction.response.defer(ephemeral=True)
    try:
        async with aiohttp.ClientSession() as session:
            data = await api_get(session, "health")
        if data:
            emb = discord.Embed(
                title="✅ API доступен",
                description=f"Хранилище: `{data.get('storage', '—')}`\nБД: `{data.get('database', '—')}`",
                colour=0x43A047,
                timestamp=datetime.now(timezone.utc),
            )
        else:
            emb = discord.Embed(
                title="❌ API недоступен",
                description="Сервер не отвечает или вернул ошибку.",
                colour=COLOR_ERROR,
                timestamp=datetime.now(timezone.utc),
            )
        await interaction.followup.send(embed=emb, ephemeral=True)
    except Exception as exc:
        log.error("/здоровье: %s", exc)
        await interaction.followup.send("❌ Внутренняя ошибка бота.", ephemeral=True)


@tree.command(
    name="помощь",
    description="Показать список команд бота ЕИАС «Фемида»",
    guild=discord.Object(id=GUILD_ID),
)
async def cmd_help(interaction: discord.Interaction):
    """Слэш-команда /помощь"""
    emb = discord.Embed(
        title="📖 Команды бота ЕИАС «Фемида»",
        colour=COLOR_STATS,
        timestamp=datetime.now(timezone.utc),
    )
    commands_desc = [
        ("/статистика [субъект]",   "Общая или субъектовая статистика"),
        ("/состав",                  "Состав прокуратуры по субъектам"),
        ("/субъекты",                "Список субъектов и кол-во сотрудников"),
        ("/премии [субъект]",        "Сводка по заявкам на премии"),
        ("/регистрации",             "Список ожидающих заявок (скрыто)"),
        ("/сводка",                  "Ежедневная сводка по системе"),
        ("/обновить-состав",         "Принудительное обновление канала состава"),
        ("/здоровье",                "Проверка доступности API"),
        ("/помощь",                  "Эта справка"),
    ]
    desc_lines = [f"`{cmd}` — {desc}" for cmd, desc in commands_desc]
    emb.description = "\n".join(desc_lines)
    emb.set_footer(text="ЕИАС «Фемида» • Прокуратура RMRP")
    await interaction.response.send_message(embed=emb, ephemeral=True)

# ──────────────────────────────────────────────────────────────────────────────
# BOT EVENTS
# ──────────────────────────────────────────────────────────────────────────────

@bot.event
async def on_ready():
    """Вызывается при подключении бота к Discord."""
    log.info("Бот запущен как %s (ID: %d)", bot.user, bot.user.id)  # type: ignore

    # Синхронизация slash-команд с сервером
    try:
        guild = discord.Object(id=GUILD_ID)
        synced = await tree.sync(guild=guild)
        log.info("Синхронизировано %d slash-команд с сервером %d", len(synced), GUILD_ID)
    except Exception as exc:
        log.error("Ошибка синхронизации slash-команд: %s", exc)

    # Запуск фоновых задач
    if not poll_notifications.is_running():
        poll_notifications.start()
    if not update_roster.is_running():
        update_roster.start()
    if not daily_summary.is_running():
        daily_summary.start()

    await bot_log(
        f"Бот ЕИАС «Фемида» запущен. Пользователь: {bot.user}. "
        f"Опрос API каждые {POLL_INTERVAL}с. Обновление состава каждые {ROSTER_INTERVAL}с."
    )


@bot.event
async def on_disconnect():
    log.warning("Бот отключён от Discord")


@bot.event
async def on_error(event: str, *args, **kwargs):
    log.exception("Необработанная ошибка в событии %s", event)


# ──────────────────────────────────────────────────────────────────────────────
# ENTRYPOINT
# ──────────────────────────────────────────────────────────────────────────────

def main():
    token = CONFIG.get("TOKEN", "")
    if not token:
        log.critical("TOKEN не задан. Установите переменную окружения DISCORD_TOKEN.")
        sys.exit(1)
    try:
        bot.run(token, log_handler=None)  # логирование уже настроено выше
    except discord.LoginFailure:
        log.critical("Неверный токен Discord. Проверьте конфигурацию.")
        sys.exit(1)
    except KeyboardInterrupt:
        log.info("Бот остановлен вручную.")


if __name__ == "__main__":
    main()
