# ЕИАС Фемида — Discord Bot

Помощник администратора. Уведомления из системы ЕИАС в Discord, управление через slash-команды.

## Быстрый старт

### 1. Создать бота в Discord Developer Portal

1. https://discord.com/developers/applications → **New Application**
2. Вкладка **Bot** → **Reset Token** → скопировать токен
3. Включить **SERVER MEMBERS INTENT**
4. Вкладка **OAuth2** → скопировать **Client ID**
5. Пригласить бота на сервер:
   ```
   https://discord.com/api/oauth2/authorize?client_id=YOUR_CLIENT_ID&permissions=2147485696&scope=bot%20applications.commands
   ```
   (permissions: Send Messages, Embed Links, Use Slash Commands)

### 2. Настроить .env

```bash
cp .env.example .env
```

Заполнить:
- `DISCORD_TOKEN` — токен бота
- `DISCORD_CLIENT_ID` — Client ID приложения
- `DISCORD_GUILD_ID` — ID сервера (правый клик → Copy Server ID)
- `CHANNEL_NOTIFICATIONS` — ID канала для уведомлений
- `CHANNEL_LOGS` — ID канала для логов (опционально)
- `WEBHOOK_SECRET` — общий секрет для проверки запросов от ЕИАС

### 3. Установить зависимости

```bash
npm install
```

### 4. Зарегистрировать slash-команды

```bash
npm run deploy-commands
```

### 5. Запустить бота

```bash
npm start
```

Для разработки (авто-перезапуск):
```bash
npm run dev
```

## Slash-команды

| Команда | Описание | Доступ |
|---------|----------|--------|
| `/статус` | Статистика системы ЕИАС | Все |
| `/проверки` | Список проверок с фильтром | Все |
| `/уведомление` | Рассылка уведомлений в ЕИАС | Администратор |
| `/помощь` | Справка по командам | Все |

## Webhook API

Бот слушает POST-запросы на `http://localhost:3100/webhook`.

### Формат запроса

```json
{
  "event": "check.approved",
  "data": {
    "checkName": "Проверка №42",
    "status": "approved",
    "userName": "Иванов И.И.",
    "subject": "Субъект-1"
  }
}
```

### Типы событий

- `check.created` — создана проверка
- `check.approved` — проверка утверждена
- `check.status_changed` — смена статуса
- `user.registered` — заявка на регистрацию
- `user.approved` / `user.blocked` — решение по пользователю
- `report.submitted` — подан отчёт
- `broadcast` — произвольная рассылка

### Заголовки

- `X-Signature: HMAC-SHA256(body, WEBHOOK_SECRET)` — подпись тела запроса

## Структура

```
discord-bot/
├── src/
│   ├── index.js              # Точка входа: Discord клиент + Express webhook
│   ├── deploy-commands.js    # Регистрация slash-команд
│   └── commands/
│       ├── status.js         # /статус
│       ├── checks.js         # /проверки
│       ├── notify.js         # /уведомление
│       └── help.js           # /помощь
├── .env.example
├── .gitignore
├── package.json
└── README.md
```
