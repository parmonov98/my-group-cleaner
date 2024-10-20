<?php

use Zanzara\Zanzara;
use Zanzara\Context;

require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$botToken = $_ENV['TG_BOT_TOKEN'];
$botUsername = $_ENV['TG_BOT_USERNAME'];

// Create SQLite DB connection
$db = new PDO('sqlite:' . __DIR__ . '/bot_data.db');

// Create tables if they do not exist
$db->exec("CREATE TABLE IF NOT EXISTS votes (
    message_id INTEGER,
    chat_id INTEGER,
    thumbs_up INTEGER DEFAULT 0,
    thumbs_down INTEGER DEFAULT 0,
    is_voting BOOLEAN DEFAULT true,
    UNIQUE(message_id, chat_id)
)");

$db->exec("CREATE TABLE IF NOT EXISTS user_points (
    user_id INTEGER PRIMARY KEY,
    points INTEGER DEFAULT 0,
    badge_level TEXT
)");

$db->exec("CREATE TABLE IF NOT EXISTS ai_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    message_id INTEGER,
    chat_id INTEGER,
    user_id INTEGER,
    message_text TEXT,
    is_processed INTEGER DEFAULT 0
)");

$db->exec("CREATE TABLE IF NOT EXISTS group_settings (
    chat_id INTEGER PRIMARY KEY,
    spam_threshold INTEGER DEFAULT 5,
    cleanup_enabled INTEGER DEFAULT 1
)");

$db->exec("CREATE TABLE IF NOT EXISTS voters (
    message_id INTEGER,
    chat_id INTEGER,
    tg_id INTEGER,
    user_mention TEXT,
    UNIQUE(message_id, chat_id, tg_id)
)");

$bot = new Zanzara($botToken);

$bot->onCommand('start@' . $botUsername, function (Context $ctx) use ($db) {
    // Get the effective chat information
    $chat = $ctx->getEffectiveChat();
    $chatType = $chat->getType();

    // Only handle the command in group or supergroup
    if ($chatType === 'supergroup' || $chatType === 'group') {
        $chatId = $chat->getId();
        $userId = $ctx->getEffectiveUser()->getId();

        // Fetch the bot's information (including bot ID)
        $ctx->getMe()->then(function ($botInfo) use ($ctx, $db, $chatId, $userId) {
            $botId = $botInfo->getId(); // Get the bot's ID from getMe()

            // Debug: show that the command was received and we're fetching admins
            $ctx->sendMessage("Received /start@{$botInfo->getUsername()} in a group. Fetching admins...");

            // Fetch chat administrators to verify if the user and the bot are admins
            $ctx->getChatAdministrators($chatId)->then(function ($admins) use ($ctx, $db, $chatId, $userId, $botId) {
                $isAdmin = false;
                $isBotAdmin = false;

                // Now check if the user issuing the command is an admin and if the bot is an admin
                foreach ($admins as $admin) {
                    $adminUser = $admin->getUser(); // Get the User object

                    if ($adminUser->getId() === $userId) {
                        $isAdmin = true; // Check if the user issuing the command is an admin
                        $ctx->sendMessage("User is an admin.");
                    }
                    if ($adminUser->isBot() && $adminUser->getId() == $botId) {
                        $isBotAdmin = true; // Check if the bot itself is an admin
                        $ctx->sendMessage("Bot is an admin.");
                    }
                }

                // Handle the rest of the logic...
                if ($isBotAdmin) {
                    if ($isAdmin) {
                        // Check if there are settings in the database for this group
                        $stmt = $db->prepare("SELECT * FROM group_settings WHERE chat_id = ?");
                        $stmt->execute([$chatId]);
                        $groupSettings = $stmt->fetch(PDO::FETCH_ASSOC);

                        if ($groupSettings) {
                            $ctx->sendMessage("You're all set! ✅");
                        } else {
                            // Insert the default settings and notify the user
                            $stmt = $db->prepare("INSERT INTO group_settings (chat_id, spam_threshold, cleanup_enabled) VALUES (?, ?, ?)");
                            $stmt->execute([$chatId, 5, 1]); // Default spam_threshold = 5, cleanup_enabled = 1
                            $ctx->sendMessage("Settings initialized for the group. You're all set! ✅");
                        }
                    } else {
                        $ctx->sendMessage("Only group admins can configure the bot.");
                    }
                } else {
                    $ctx->sendMessage("Please make me an admin in the group, I can help you control the group.");
                }
            })->otherwise(function ($error) use ($ctx) {
                // Handle any errors with fetching administrators
                $ctx->sendMessage("Error fetching chat administrators: " . $error->getMessage());
            });
        });
    } else {
        $ctx->sendMessage("This command is only available in group chats.");
    }
});



$bot->onCommand('help', function (Context $ctx) {
    $helpMessage = "
Here are the available commands for this bot:
/start - Start interacting with the bot
/help - Display this help message
/set_threshold [number] - Set the spam vote threshold (admin only)
/toggle_cleanup - Toggle join/leave message cleanup in the group (admin only)
    
Features:
- This bot helps clean up the group by automatically deleting join/leave messages when enabled.
- It initiates voting for messages with links to decide if they should be marked as spam and deleted.
- Group members can vote using 👍 or 👎 buttons on such messages.
- If the spam vote threshold is reached, the message will be deleted automatically.
- Each group member can vote only once per message, and points will be awarded for participation.
";

    // Send the help message to the user
    $ctx->sendMessage($helpMessage);
});


// Handle both `/set_threshold` and `/set_threshold@botUsername` commands
$bot->onCommand('set_threshold', function (Context $ctx) use ($db) {
    handleSetThreshold($ctx, $db);
});

$bot->onCommand('set_threshold@' . $botUsername, function (Context $ctx) use ($db) {
    handleSetThreshold($ctx, $db);
});
// Add a command to toggle join/leave cleanup (admin only)
$bot->onCommand('toggle_cleanup', function (Context $ctx) use ($db) {
    $chatId = $ctx->getEffectiveChat()->getId();  // Use getEffectiveChat() here
    $userId = $ctx->getEffectiveUser()->getId();

    // Check if the user is an admin
    $ctx->getChatAdministrators($chatId)->then(function ($admins) use ($ctx, $db, $chatId, $userId) {
        $isAdmin = false;
        foreach ($admins as $admin) {
            if ($admin->getUser()->getId() === $userId) {
                $isAdmin = true;
                break;
            }
        }

        if ($isAdmin) {
            // Check the current status in group_settings for join/leave cleanup
            $stmt = $db->prepare("SELECT cleanup_enabled FROM group_settings WHERE chat_id = ?");
            $stmt->execute([$chatId]);
            $groupSettings = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$groupSettings) {
                // If no settings exist, insert the default setting (cleanup enabled)
                $stmt = $db->prepare("INSERT INTO group_settings (chat_id, cleanup_enabled) VALUES (?, ?)");
                $stmt->execute([$chatId, 1]);
                $ctx->sendMessage("Join/leave message cleanup has been enabled.");
            } else {
                // Toggle the cleanup status
                $newStatus = $groupSettings['cleanup_enabled'] ? 0 : 1;
                $stmt = $db->prepare("UPDATE group_settings SET cleanup_enabled = ? WHERE chat_id = ?");
                $stmt->execute([$newStatus, $chatId]);

                $statusMessage = $newStatus ? "enabled" : "disabled";
                $ctx->sendMessage("Join/leave message cleanup has been $statusMessage.");
            }
        } else {
            $ctx->sendMessage("You must be an admin to toggle this setting.");
        }
    });
});

// Handle the /spam command
$bot->onCommand('spam', function (Context $ctx) use ($db) {
    $message = $ctx->getMessage();
    $replyToMessage = $message->getReplyToMessage();

    // Check if the command is a reply to another message
    if ($replyToMessage) {
        $messageId = $replyToMessage->getMessageId();
        $chatId = $message->getChat()->getId();

        // Store the message for voting purposes in the votes table and set is_voting to true
        $stmt = $db->prepare("INSERT INTO votes (message_id, chat_id, is_voting) VALUES (?, ?, 1)
                              ON CONFLICT (message_id, chat_id) DO NOTHING");
        $stmt->execute([$messageId, $chatId]);

        // Send a message with voting buttons (👍 and 👎) as a reply to the original message
        $ctx->sendMessage("Do you think this message is spam?", [
            'reply_to_message_id' => $messageId,
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        ['text' => '👍', 'callback_data' => 'vote_like_' . $messageId],
                        ['text' => '👎', 'callback_data' => 'vote_dislike_' . $messageId]
                    ]
                ]
            ]
        ]);
    } else {
        $ctx->sendMessage("Please reply to the message you want to mark as spam using /spam.");
    }
});
// Handle link detection and voting initiation
$bot->onUpdate(function (Context $ctx) use ($db, $botUsername) {
    $message = $ctx->getMessage();
    $user = $ctx->getEffectiveUser();
    $chat = $ctx->getEffectiveChat();

    // Check if the message contains links
    if ($message) {

        $chatId = $ctx->getEffectiveChat()->getId();

        // Check if the message contains new chat members (user joined) or a left chat member (user left)
        if ($message->getNewChatMembers() || $message->getLeftChatMember()) {

            // Fetch group settings to check if cleanup is enabled
            $stmt = $db->prepare("SELECT cleanup_enabled FROM group_settings WHERE chat_id = ?");
            $stmt->execute([$chatId]);
            $groupSettings = $stmt->fetch(PDO::FETCH_ASSOC);

            // If cleanup is enabled, handle join/leave messages
            if ($groupSettings && $groupSettings['cleanup_enabled']) {
                handleJoinLeaveMessages($ctx, $db);
            }
        }

    }

    if ($message && $message->getText()) {

        $text = $message->getText();
        // Match both '/set_threshold' and '/set_threshold@<bot_username>'
        if (preg_match("/^\/set_threshold(@$botUsername)? (\d+)/", $text, $matches)) {
            handleSetThreshold($ctx, $db, $matches[2]);  // Pass the threshold value from the command
        }

        $entities = $message->getEntities();
        if ($entities) {
            foreach ($entities as $entity) {
                $stmt = $db->prepare("INSERT INTO ai_messages (message_id, chat_id, user_id, message_text) VALUES (?, ?, ?, ?)");
                $stmt->execute([$message->getMessageId(), $chat->getId(), $user->getId(), $message->getText()]);
                if ($entity->getType() === 'url' || $entity->getType() === 'text_link') {
                    handleSpamVoteRequest($ctx, $db);  // Trigger the voting process
                    break;
                }
            }
        }
    }

    // Handle callback queries (votes)
    $callbackQuery = $ctx->getCallbackQuery();
    if ($callbackQuery) {
        $callbackData = $callbackQuery->getData();
        $user = $callbackQuery->getFrom();
        $messageId = $callbackQuery->getMessage()->getMessageId();
        $chatId = $callbackQuery->getMessage()->getChat()->getId();

        if (strpos($callbackData, 'vote_like_') !== false) {
            $msgId = str_replace('vote_like_', '', $callbackData);
            incrementVote($db, $msgId, $chatId, 'thumbs_up', $ctx, $messageId, $chatId, $user);
        } elseif (strpos($callbackData, 'vote_dislike_') !== false) {
            $msgId = str_replace('vote_dislike_', '', $callbackData);
            incrementVote($db, $msgId, $chatId, 'thumbs_down', $ctx, $messageId, $chatId, $user);
        }
    }
});


function handleJoinLeaveMessages(Context $ctx, $db)
{
    $message = $ctx->getMessage();
    $chatId = $message->getChat()->getId();
    $messageId = $message->getMessageId();

    // Check if the message contains new chat members (user joined)
    if ($message->getNewChatMembers()) {
        // Delete the join message
        $ctx->deleteMessage($chatId, $messageId);
    }

    // Check if the message contains a left chat member (user left)
    if ($message->getLeftChatMember()) {
        // Delete the leave message
        $ctx->deleteMessage($chatId, $messageId);
    }
}

/**
 * Handles initiating the voting process for a message that may contain spam.
 * The bot will reply directly to the user's message.
 */
function handleSpamVoteRequest(Context $ctx, $db)
{
    $message = $ctx->getMessage();
    $messageId = $message->getMessageId();
    $chatId = $message->getChat()->getId();

    // Store the message for voting purposes in the votes table and set is_voting to true
    $stmt = $db->prepare("INSERT INTO votes (message_id, chat_id, is_voting) VALUES (?, ?, 1)
                          ON CONFLICT (message_id, chat_id) DO NOTHING");
    $stmt->execute([$messageId, $chatId]);

    // Send a message with voting buttons (👍 and 👎) as a reply to the user's message
    $ctx->sendMessage("Do you think this is spam?", [
        'reply_to_message_id' => $messageId,
        'reply_markup' => [
            'inline_keyboard' => [
                [
                    ['text' => '👍', 'callback_data' => 'vote_like_' . $messageId],
                    ['text' => '👎', 'callback_data' => 'vote_dislike_' . $messageId]
                ]
            ]
        ]
    ]);
}


/**
 * Handle setting the spam threshold command.
 */
/**
 * Handle setting the spam threshold command.
 */
function handleSetThreshold(Context $ctx, $db, $threshold)
{
    // Get the current chat information
    $chat = $ctx->getEffectiveChat();
    $chatId = $chat->getId();  // Use getEffectiveChat() to retrieve the current chat
    $userId = $ctx->getEffectiveUser()->getId();

    // Fetch chat administrators
    $ctx->getChatAdministrators($chatId)->then(function ($admins) use ($ctx, $db, $chatId, $userId, $threshold) {
        $isAdmin = false;

        // Check if the user is an admin
        foreach ($admins as $admin) {
            if ($admin->getUser()->getId() === $userId) {
                $isAdmin = true;
                break;
            }
        }

        if ($isAdmin) {
            // Check if the provided threshold is valid
            if (is_numeric($threshold)) {
                // Insert or update the spam threshold for the group in the group_settings table
                $stmt = $db->prepare("INSERT INTO group_settings (chat_id, spam_threshold) VALUES (?, ?)
                                      ON CONFLICT(chat_id) DO UPDATE SET spam_threshold = ?");
                $stmt->execute([$chatId, $threshold, $threshold]);

                // Respond with confirmation message
                $ctx->sendMessage("Spam threshold has been set to $threshold.");
            } else {
                // If the threshold is not a valid number, show usage instructions
                $ctx->sendMessage("Usage: /set_threshold [number]");
            }
        } else {
            // If the user is not an admin, send a permission error message
            $ctx->sendMessage("You must be an admin to set the spam threshold.");
        }
    });
}
/**
 * Increment vote count and restrict one vote per user per message.
 */
function incrementVote($db, $messageId, $chatId, $column, $ctx, $botMessageId, $botChatId, $user)
{
    $tgId = $user->getId();
    $username = $user->getUsername() ? '@' . $user->getUsername() : $user->getFirstName();

    // Check if the user has already voted for this message
    $stmt = $db->prepare("SELECT COUNT(*) FROM voters WHERE message_id = ? AND chat_id = ? AND tg_id = ?");
    $stmt->execute([$messageId, $chatId, $tgId]);
    $hasVoted = $stmt->fetchColumn();

    if ($hasVoted > 0) {
        // Send the message as a callback query alert
        $ctx->answerCallbackQuery([
            'text' => "You have already voted on this message.",
            'show_alert' => true
        ]);
        return;
    }

    // Increment the vote count
    $stmt = $db->prepare("UPDATE votes SET $column = $column + 1 WHERE message_id = ? AND chat_id = ?");
    $stmt->execute([$messageId, $chatId]);

    // Add the user who voted
    $stmt = $db->prepare("INSERT INTO voters (message_id, chat_id, tg_id, user_mention) VALUES (?, ?, ?, ?)");
    $stmt->execute([$messageId, $chatId, $tgId, $username]);

    // Fetch the current vote counts and voters
    $stmt = $db->prepare("SELECT thumbs_up, thumbs_down FROM votes WHERE message_id = ? AND chat_id = ?");
    $stmt->execute([$messageId, $chatId]);
    $voteData = $stmt->fetch(PDO::FETCH_ASSOC);

    updateUserPoints($db, $tgId, $ctx, $chatId);

    if ($voteData) {
        $thumbsUp = $voteData['thumbs_up'];
        $thumbsDown = $voteData['thumbs_down'];

        // Fetch the spam threshold
        $stmt = $db->prepare("SELECT spam_threshold FROM group_settings WHERE chat_id = ?");
        $stmt->execute([$chatId]);
        $groupSettings = $stmt->fetch(PDO::FETCH_ASSOC);

        // If no group settings exist, insert default settings with a threshold of 5
        if (!$groupSettings) {
            $threshold = 5;  // Default threshold
            $stmt = $db->prepare("INSERT INTO group_settings (chat_id, spam_threshold) VALUES (?, ?)");
            $stmt->execute([$chatId, $threshold]);
        } else {
            $threshold = $groupSettings['spam_threshold'];
        }

        // Check if the voting threshold has been reached
        if ($thumbsUp >= $threshold || $thumbsDown >= $threshold) {
            $ctx->deleteMessage($chatId, $messageId);

            // Fetch voters
            $stmt = $db->prepare("SELECT tg_id, user_mention FROM voters WHERE message_id = ? AND chat_id = ?");
            $stmt->execute([$messageId, $chatId]);
            $voters = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $votersMentions = array_map(function ($voter) {
                return "<a href=\"tg://user?id={$voter['tg_id']}\">{$voter['user_mention']}</a>";
            }, $voters);
            $votersMentionsString = implode(', ', $votersMentions);

            // Update the bot's message with a thank you note to voters and remove the buttons
            $ctx->editMessageText("The message was counted as spam and has been deleted. Thanks to voters: $votersMentionsString.", [
                'chat_id' => $botChatId,
                'message_id' => $botMessageId,
                'parse_mode' => 'HTML'
            ]);

            // Set is_voting to false after voting is completed
            $stmt = $db->prepare("UPDATE votes SET is_voting = 0 WHERE message_id = ? AND chat_id = ?");
            $stmt->execute([$messageId, $chatId]);
        } else {
            // Update the bot's message with the vote count and keep the buttons
            $ctx->editMessageText("Do u think it is spam?\n\n👍 - $thumbsUp votes\n👎 - $thumbsDown votes", [
                'chat_id' => $botChatId,
                'message_id' => $botMessageId,
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [
                            ['text' => '👍', 'callback_data' => 'vote_like_' . $messageId],
                            ['text' => '👎', 'callback_data' => 'vote_dislike_' . $messageId]
                        ]
                    ]
                ])
            ]);
        }
    } else {
        $ctx->sendMessage("Error: Could not fetch vote data.");
    }
}

/**
 * Update user points and assign badges.
 */
function updateUserPoints($db, $userId, $ctx, $chatId)
{
    $stmt = $db->prepare("INSERT INTO user_points (user_id, points) VALUES (?, 1)
                          ON CONFLICT(user_id) DO UPDATE SET points = points + 1");
    $stmt->execute([$userId]);

    $stmt = $db->prepare("SELECT points FROM user_points WHERE user_id = ?");
    $stmt->execute([$userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($userData) {
        $points = $userData['points'];
        $badges = [
            10 => 'Rookie Hunter',
            100 => 'Slayer',
            1000 => 'Massacre Master',
            5000 => 'Bloodthirsty'
        ];

        foreach ($badges as $threshold => $badge) {
            if ($points == $threshold) {
                $ctx->sendMessage("Kudos to <a href=\"tg://user?id=$userId\">this user</a> for reaching the level of $badge!", [
                    'chat_id' => $chatId,
                    'parse_mode' => 'HTML'
                ]);

                $stmt = $db->prepare("UPDATE user_points SET badge_level = ? WHERE user_id = ?");
                $stmt->execute([$badge, $userId]);
            }
        }
    } else {
        $ctx->sendMessage("Error: Could not fetch user points.");
    }
}

$bot->run();
