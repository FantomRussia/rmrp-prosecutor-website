const { SlashCommandBuilder, EmbedBuilder } = require('discord.js');

module.exports = {
  data: new SlashCommandBuilder()
    .setName('помощь')
    .setDescription('Список команд бота'),

  async execute(interaction) {
    const embed = new EmbedBuilder()
      .setColor(0x0077b6)
      .setTitle('🤖 ЕИАС Фемида — Помощник администратора')
      .setDescription('Доступные команды:')
      .addFields(
        { name: '/статус', value: 'Статус системы: пользователи, проверки, онлайн' },
        { name: '/проверки', value: 'Список последних проверок с фильтром по статусу' },
        { name: '/уведомление', value: 'Отправить уведомление всем пользователям ЕИАС (только админ)' },
        { name: '/помощь', value: 'Эта справка' },
      )
      .setFooter({ text: 'prosecutors-office-rmrp.ru' })
      .setTimestamp();

    await interaction.reply({ embeds: [embed], ephemeral: true });
  },
};
