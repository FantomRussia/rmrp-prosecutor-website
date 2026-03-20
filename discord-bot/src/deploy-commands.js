require('dotenv').config();
const { REST, Routes } = require('discord.js');
const fs = require('fs');
const path = require('path');

const commands = [];
const commandsPath = path.join(__dirname, 'commands');
const commandFiles = fs.readdirSync(commandsPath).filter(f => f.endsWith('.js'));

for (const file of commandFiles) {
  const command = require(path.join(commandsPath, file));
  if (command.data) commands.push(command.data.toJSON());
}

const rest = new REST({ version: '10' }).setToken(process.env.DISCORD_TOKEN);

(async () => {
  try {
    console.log(`Регистрирую ${commands.length} команд...`);

    if (process.env.DISCORD_GUILD_ID) {
      // Guild commands (instant, for dev)
      await rest.put(
        Routes.applicationGuildCommands(process.env.DISCORD_CLIENT_ID, process.env.DISCORD_GUILD_ID),
        { body: commands },
      );
      console.log('✅ Guild commands зарегистрированы.');
    } else {
      // Global commands (up to 1h to propagate)
      await rest.put(
        Routes.applicationCommands(process.env.DISCORD_CLIENT_ID),
        { body: commands },
      );
      console.log('✅ Global commands зарегистрированы.');
    }
  } catch (err) {
    console.error('❌ Ошибка регистрации:', err);
  }
})();
