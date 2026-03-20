const { SlashCommandBuilder, EmbedBuilder } = require('discord.js');

module.exports = {
  data: new SlashCommandBuilder()
    .setName('статус')
    .setDescription('Показать статус системы ЕИАС Фемида'),

  async execute(interaction) {
    await interaction.deferReply();

    const apiUrl = process.env.EIAS_API_URL;
    if (!apiUrl) {
      return interaction.editReply('❌ EIAS_API_URL не настроен.');
    }

    try {
      const res = await fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'checks.discord.status' }),
      });
      const json = await res.json();

      if (!json.success) throw new Error(json.error || 'API error');

      const d = json.data;
      const embed = new EmbedBuilder()
        .setColor(0x0077b6)
        .setTitle('📊 Статус ЕИАС Фемида')
        .addFields(
          { name: '👥 Пользователей', value: String(d.usersTotal || 0), inline: true },
          { name: '🟢 Онлайн', value: String(d.usersOnline || 0), inline: true },
          { name: '📋 Проверок', value: String(d.checksTotal || 0), inline: true },
          { name: '▶️ Активных', value: String(d.checksActive || 0), inline: true },
          { name: '⏳ На утверждении', value: String(d.checksPending || 0), inline: true },
          { name: '✅ Утверждено', value: String(d.checksApproved || 0), inline: true },
        )
        .setFooter({ text: 'prosecutors-office-rmrp.ru' })
        .setTimestamp();

      await interaction.editReply({ embeds: [embed] });
    } catch (err) {
      console.error('[CMD /статус]', err);
      await interaction.editReply('❌ Не удалось получить данные. Сервер недоступен.');
    }
  },
};
