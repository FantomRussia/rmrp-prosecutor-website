const { SlashCommandBuilder, EmbedBuilder } = require('discord.js');

const STATUS_LABELS = {
  planned: '📝 Запланирована',
  active: '▶️ Активна',
  completed: '📊 Сбор завершён',
  pending_approval: '⏳ На утверждении',
  approved: '✅ Утверждена',
  cancelled: '❌ Отменена',
};

module.exports = {
  data: new SlashCommandBuilder()
    .setName('проверки')
    .setDescription('Список последних проверок')
    .addStringOption(opt =>
      opt.setName('статус')
        .setDescription('Фильтр по статусу')
        .addChoices(
          { name: 'Активные', value: 'active' },
          { name: 'На утверждении', value: 'pending_approval' },
          { name: 'Утверждённые', value: 'approved' },
          { name: 'Все', value: 'all' },
        )),

  async execute(interaction) {
    await interaction.deferReply();

    const status = interaction.options.getString('статус') || 'all';
    const apiUrl = process.env.EIAS_API_URL;

    if (!apiUrl) {
      return interaction.editReply('❌ EIAS_API_URL не настроен.');
    }

    try {
      const res = await fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'checks.discord.list',
          status: status === 'all' ? undefined : status,
          limit: 10,
        }),
      });
      const json = await res.json();

      if (!json.success) throw new Error(json.error || 'API error');

      const checks = json.data?.checks || [];
      if (checks.length === 0) {
        return interaction.editReply('📋 Нет проверок по заданному фильтру.');
      }

      const embed = new EmbedBuilder()
        .setColor(0x0077b6)
        .setTitle(`📋 Проверки${status !== 'all' ? ` — ${STATUS_LABELS[status] || status}` : ''}`)
        .setFooter({ text: `Показано: ${checks.length}` })
        .setTimestamp();

      for (const c of checks.slice(0, 10)) {
        const statusLabel = STATUS_LABELS[c.status] || c.status;
        embed.addFields({
          name: `${c.name || 'Без названия'}`,
          value: `${statusLabel} | ${c.subject || '—'} | ${c.date_start || '—'}`,
        });
      }

      await interaction.editReply({ embeds: [embed] });
    } catch (err) {
      console.error('[CMD /проверки]', err);
      await interaction.editReply('❌ Не удалось получить проверки.');
    }
  },
};
