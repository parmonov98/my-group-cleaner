module.exports = {
    apps: [
        {
            name: "my-group-cleaner-bot", // Name of your bot application
            script: "php",        // Command to run PHP
            args: "bot.php",      // Script to execute
            interpreter: "none",  // Do not use an additional interpreter, use system's PHP
            instances: 1,         // Number of instances (1 means single process)
            autorestart: true,    // Restart if the bot crashes
            watch: false,         // Set to true to watch for file changes and auto-restart
            max_memory_restart: "300M", // Restart if memory exceeds 300MB
            env: {
                // Environment variables (optional)
                TG_BOT_TOKEN: process.env.TG_BOT_TOKEN, // Ensure your token is loaded here
                TG_BOT_USERNAME: process.env.TG_BOT_USERNAME,
            }
        }
    ]
};
