const { SlashCommandBuilder, EmbedBuilder, PermissionFlagsBits } = require('discord.js');

module.exports = {
  data: new SlashCommandBuilder()
    .setName('уведомление')
    .setDescription('Отправить уведомление пользователям ЕИАС')
    .addStringOption(opt =>
      opt.setName('заголовок').setDescription('Заголовок уведомления').setRequired(true))
    .addStringOption(opt =>
      opt.setName('текст').setDescription('Текст уведомления').setRequired(true))
    .addStringOption(opt =>
      opt.setName('приоритет')
        .setDescription('Приоритет')
        .addChoices(
          { name: '🟢 Обычный', value: 'normal' },
          { name: '🟡 Важный', value: 'warning' },
          { name: '🔴 Критический', value: 'critical' },
        ))
    .setDefaultMemberPermissions(PermissionFlagsBits.Administrator),

  async execute(interaction) {
    await interaction.deferReply();

    const title = interaction.options.getString('заголовок');
    const body = interaction.options.getString('текст');
    const priority = interaction.options.getString('приоритет') || 'normal';

    const apiUrl = process.env.EIAS_API_URL;
    if (!apiUrl) {
      return interaction.editReply('❌ EIAS_API_URL не настроен.');
    }

    try {
      const res = await fetch(apiUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'checks.notifications.broadcast',
          title,
          body,
          priority,
          source: 'discord',
          sender: interaction.user.tag,
        }),
      });
      const json = await res.json();

      if (!json.success) throw new Error(json.error || 'API error');

      const priorityLabels = { normal: '🟢 Обычный', warning: '🟡 Важный', critical: '🔴 Критический' };

      const embed = new EmbedBuilder()
        .setColor(priority === 'critical' ? 0xb34739 : priority === 'warning' ? 0xd69a2d : 0x2f9e8f)
        .setTitle('📢 Уведомление отправлено')
        .addFields(
          { name: 'Заголовок', value: title },
          { name: 'Текст', value: body },
          { name: 'Приоритет', value: priorityLabels[priority] || priority, inline: true },
          { name: 'Получатели', value: String(json.data?.count || 'все'), inline: true },
        )
        .setFooter({ text: `Отправил: ${interaction.user.tag}` })
        .setTimestamp();

      await interaction.editReply({ embeds: [embed] });
    } catch (err) {
      console.error('[CMD /уведомление]', err);
      await interaction.editReply('❌ Не удалось отправить уведомление.');
    }
  },
};
