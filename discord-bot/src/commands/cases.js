const { SlashCommandBuilder, EmbedBuilder } = require('discord.js');

const STATUS_LABELS = {
  registered: '🔵 Зарегистрировано',
  assigned_staff: '🔵 Назначен исполнитель',
  assigned_supervisor: '🔵 Назначен надзирающий',
  preliminary_check: '🟡 На предварительной проверке',
  check_terminated: '🔴 Проверка прекращена',
  transferred_investigation: '🟠 Передано в следствие',
  investigation_check: '🟡 Проверка следствием',
  criminal_case_opened: '🟢 ВУД',
  criminal_case_refused: '🔴 Отказ в ВУД',
  under_investigation: '🟡 На следствии',
  indictment_drafted: '🟠 Обвинительное заключение',
  prosecution_review: '🟡 На утверждении',
  prosecution_approved: '🟢 Утверждено',
  prosecution_refused: '🔴 В утверждении отказано',
  sent_to_court: '🔵 Передано в суд',
  verdict_issued: '🟢 Приговор вынесен',
  completed: '✅ Завершено',
  archive: '⚫ Архив',
};

module.exports = {
  data: new SlashCommandBuilder()
    .setName('обращения')
    .setDescription('Список последних обращений и жалоб')
    .addStringOption(opt =>
      opt.setName('статус')
        .setDescription('Фильтр по статусу')
        .addChoices(
          { name: 'В работе', value: 'active' },
          { name: 'Просроченные', value: 'overdue' },
          { name: 'Завершённые', value: 'completed' },
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
          action: 'cases.discord.list',
          filter: status === 'all' ? undefined : status,
          limit: 10,
        }),
      });
      const json = await res.json();

      if (!json.success) throw new Error(json.error || 'API error');

      const cases = json.data?.cases || [];
      if (cases.length === 0) {
        return interaction.editReply('📨 Нет обращений по заданному фильтру.');
      }

      const embed = new EmbedBuilder()
        .setColor(0x0077b6)
        .setTitle(`📨 Обращения${status !== 'all' ? ` — ${status === 'active' ? 'В работе' : status === 'overdue' ? 'Просроченные' : 'Завершённые'}` : ''}`)
        .setFooter({ text: `Показано: ${cases.length}` })
        .setTimestamp();

      for (const c of cases.slice(0, 10)) {
        const statusLabel = STATUS_LABELS[c.status] || c.status;
        const deadline = c.deadline ? ` | Срок: ${c.deadline}` : '';
        embed.addFields({
          name: `${c.regNumber || 'Без номера'} — ${c.applicantName || 'Без заявителя'}`,
          value: `${statusLabel} | ${c.subject || '—'}${deadline}`,
        });
      }

      await interaction.editReply({ embeds: [embed] });
    } catch (err) {
      console.error('[CMD /обращения]', err);
      await interaction.editReply('❌ Не удалось получить обращения.');
    }
  },
};
