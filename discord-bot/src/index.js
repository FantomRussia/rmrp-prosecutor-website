require('dotenv').config();
const { Client, GatewayIntentBits, Collection, EmbedBuilder } = require('discord.js');
const express = require('express');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

// ── Discord Client ──
const client = new Client({
  intents: [
    GatewayIntentBits.Guilds,
    GatewayIntentBits.GuildMessages,
  ],
});

client.commands = new Collection();

// Load commands
const commandsPath = path.join(__dirname, 'commands');
const commandFiles = fs.readdirSync(commandsPath).filter(f => f.endsWith('.js'));
for (const file of commandFiles) {
  const command = require(path.join(commandsPath, file));
  if (command.data && command.execute) {
    client.commands.set(command.data.name, command);
  }
}

// ── Discord Events ──
client.once('ready', () => {
  console.log(`[BOT] Онлайн: ${client.user.tag}`);
  client.user.setActivity('ЕИАС Фемида', { type: 3 }); // Watching
});

client.on('interactionCreate', async (interaction) => {
  if (!interaction.isChatInputCommand()) return;
  const command = client.commands.get(interaction.commandName);
  if (!command) return;
  try {
    await command.execute(interaction, client);
  } catch (err) {
    console.error(`[CMD ERROR] ${interaction.commandName}:`, err);
    const reply = { content: '❌ Ошибка выполнения команды.', ephemeral: true };
    if (interaction.replied || interaction.deferred) {
      await interaction.followUp(reply);
    } else {
      await interaction.reply(reply);
    }
  }
});

// ── Webhook Server (receives events from ЕИАС) ──
const app = express();
app.use(express.json());

function verifySignature(req) {
  const secret = process.env.WEBHOOK_SECRET;
  if (!secret) return true; // no secret = skip check (dev mode)
  const sig = req.headers['x-signature'] || '';
  const expected = crypto.createHmac('sha256', secret).update(JSON.stringify(req.body)).digest('hex');
  return crypto.timingSafeEqual(Buffer.from(sig), Buffer.from(expected));
}

// Event type → embed config
const EVENT_CONFIG = {
  'check.created':          { color: 0x1d70d1, title: '📋 Новая проверка',           field: 'checkName' },
  'check.approved':         { color: 0x2f9e8f, title: '✅ Проверка утверждена',       field: 'checkName' },
  'check.status_changed':   { color: 0xd69a2d, title: '🔄 Смена статуса проверки',   field: 'checkName' },
  'user.registered':        { color: 0x6bb7ff, title: '👤 Новая заявка на регистрацию', field: 'userName' },
  'user.approved':          { color: 0x2f9e8f, title: '✅ Пользователь одобрен',      field: 'userName' },
  'user.blocked':           { color: 0xb34739, title: '🔒 Пользователь заблокирован', field: 'userName' },
  'report.submitted':       { color: 0x023e8a, title: '📄 Отчёт подан',              field: 'reportTitle' },
  'bonus.decision':         { color: 0xd69a2d, title: '💰 Решение по премии',        field: 'userName' },
  'maintenance.scheduled':  { color: 0xb34739, title: '⚠️ Техработы запланированы',   field: 'description' },
  'broadcast':              { color: 0x0077b6, title: '📢 Рассылка',                 field: 'title' },
  // ── Cases (Обращения) ──
  'case.created':              { color: 0x0077b6, title: '📨 Новое обращение',         field: 'description' },
  'case.assigned':             { color: 0x1d70d1, title: '👤 Назначен исполнитель',     field: 'assignedName' },
  'case.supervisor_assigned':  { color: 0x0353a4, title: '👁 Назначен надзирающий',     field: 'supervisorName' },
  'case.status_changed':       { color: 0xd69a2d, title: '🔄 Статус обращения',        field: 'statusLabel' },
  'case.deadline_approaching': { color: 0xd69a2d, title: '⏰ Приближается срок',       field: 'description' },
  'case.overdue':              { color: 0xb34739, title: '🔴 Просрочено',              field: 'description' },
  'case.completed':            { color: 0x2f9e8f, title: '✅ Обращение завершено',      field: 'description' },
};

app.post('/webhook', (req, res) => {
  if (!verifySignature(req)) {
    return res.status(403).json({ error: 'Invalid signature' });
  }

  const { event, data } = req.body;
  if (!event || !data) {
    return res.status(400).json({ error: 'Missing event or data' });
  }

  const config = EVENT_CONFIG[event];
  if (!config) {
    console.log(`[WEBHOOK] Unknown event: ${event}`);
    return res.status(200).json({ ok: true, skipped: true });
  }

  const channelId = process.env.CHANNEL_NOTIFICATIONS;
  if (!channelId) {
    console.error('[WEBHOOK] CHANNEL_NOTIFICATIONS not set');
    return res.status(500).json({ error: 'Channel not configured' });
  }

  const embed = new EmbedBuilder()
    .setColor(config.color)
    .setTitle(config.title)
    .setTimestamp();

  // Main field
  if (data[config.field]) {
    embed.setDescription(String(data[config.field]));
  }

  // Extra fields from data
  if (data.subject) embed.addFields({ name: 'Субъект', value: data.subject, inline: true });
  if (data.status) embed.addFields({ name: 'Статус', value: data.status, inline: true });
  if (data.userName) embed.addFields({ name: 'Пользователь', value: data.userName, inline: true });
  if (data.regNumber) embed.addFields({ name: 'Рег. №', value: data.regNumber, inline: true });
  if (data.applicantName) embed.addFields({ name: 'Заявитель', value: data.applicantName, inline: true });
  if (data.deadline) embed.addFields({ name: 'Срок', value: data.deadline, inline: true });
  if (data.details) embed.addFields({ name: 'Детали', value: String(data.details).slice(0, 1024) });

  // Mentions for pinged users
  const mentions = (data.discordUserIds || []).map(id => `<@${id}>`).join(' ');

  const channel = client.channels.cache.get(channelId);
  if (!channel) {
    console.error(`[WEBHOOK] Channel ${channelId} not found`);
    return res.json({ ok: true });
  }

  // Case events: thread management
  if (event === 'case.created' && data.caseId) {
    channel.send({ content: mentions || undefined, embeds: [embed] })
      .then(async (msg) => {
        console.log(`[WEBHOOK] Sent: ${event}`);
        try {
          const thread = await msg.startThread({
            name: `📨 ${data.regNumber || 'Обращение'} — ${(data.applicantName || 'Без заявителя').slice(0, 80)}`,
            autoArchiveDuration: 10080,
          });
          console.log(`[WEBHOOK] Thread created: ${thread.id}`);
          // Sync thread info back to EIAS
          const syncUrl = process.env.EIAS_API_URL || 'https://prosecutors-office-rmrp.ru/api.php';
          fetch(`${syncUrl}?action=cases.discord.sync`, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-Discord-Secret': process.env.WEBHOOK_SECRET || '',
            },
            body: JSON.stringify({
              caseId: data.caseId,
              discordThreadId: thread.id,
              discordMessageId: msg.id,
              discordChannelId: channel.id,
            }),
          }).catch(err => console.error('[SYNC]', err));
        } catch (err) {
          console.error('[WEBHOOK] Thread creation error:', err);
        }
      })
      .catch(err => console.error(`[WEBHOOK] Send error:`, err));
  } else if (event.startsWith('case.') && data.discordThreadId) {
    // Write to existing thread
    channel.threads.fetch(data.discordThreadId)
      .then(thread => {
        if (thread) {
          return thread.send({ content: mentions || undefined, embeds: [embed] });
        }
        return channel.send({ content: mentions || undefined, embeds: [embed] });
      })
      .then(() => console.log(`[WEBHOOK] Sent to thread: ${event}`))
      .catch(() => {
        // Fallback: send to channel if thread not found
        channel.send({ content: mentions || undefined, embeds: [embed] })
          .then(() => console.log(`[WEBHOOK] Sent (fallback): ${event}`))
          .catch(err => console.error(`[WEBHOOK] Send error:`, err));
      });
  } else {
    channel.send({ content: mentions || undefined, embeds: [embed] })
      .then(() => console.log(`[WEBHOOK] Sent: ${event}`))
      .catch(err => console.error(`[WEBHOOK] Send error:`, err));
  }

  res.json({ ok: true });
});

// Health check
app.get('/health', (req, res) => {
  res.json({
    ok: true,
    bot: client.isReady() ? 'online' : 'offline',
    uptime: process.uptime(),
  });
});

// ── Start ──
const PORT = process.env.WEBHOOK_PORT || 3100;

client.login(process.env.DISCORD_TOKEN).then(() => {
  app.listen(PORT, () => {
    console.log(`[WEBHOOK] Слушаю порт ${PORT}`);
  });
});
