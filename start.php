<?php

use Zanzara\Zanzara;
use Zanzara\Context;
require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
$dotenv->required('TG_BOT_TOKEN');

$bot = new Zanzara($_ENV['TG_BOT_TOKEN'] ?? "5374769262:AAFD_F0341iMnNf9kPYHMGiEQd6HwLP9-58");

$bot->onCommand('start', function (Context $ctx) {
    $ctx->sendMessage("Bu bot guruhdagi join va remove xabarlarni larni tozalaydi. Guruhga qo'shing va o'sha guruh adminligini botga bering. O'qish va O'chira olish huquqi bo'lishi kerak.");
});

$bot->onCommand('help', function (Context $ctx) {
    $ctx->sendMessage("Bot yaratuvchi: @dasturchi_xizmati ");
});

$bot->onUpdate(function (Context $ctx) {

    $update = $ctx->getUpdate();
    $user = $update->getEffectiveUser();
    $chat = $update->getEffectiveChat();
    $message = $ctx->getMessage();
    $name = $user->getFirstName() . " " . $user->getLastName();
    $sender_id = $user->getId();
    $chat_id = $chat->getId();
    $username = $user->getUsername();
    if ($username != null){
        $name = "@" . $username;
    }
    $entities = $message->getEntities();
    var_dump($entities);
    if (is_array($entities)){
        foreach ($entities as $entity){
            if ($entity->getType() == 'text_link'){
                $ctx->deleteMessage($chat->getId(), $message->getMessageId());

                $ctx->sendMessage("<a href='tg://user?id=$sender_id'>$name</a>, reklama tarqatmang, iltimos!", [
                    'chat_id' => $chat_id,
                    'parse_mode' => 'HTML'
                ]);
                return false;
            }
        }
    }

    if (!$user->isBot()){
        if ($message->getLeftChatMember() != null){
            $ctx->deleteMessage($chat->getId(), $message->getMessageId());
        }
        if ($message->getNewChatMembers() != null){
            $ctx->deleteMessage($chat->getId(), $message->getMessageId());
        }
    }
});

$bot->fallback(function(Context $ctx) {
    $update = $ctx->getUpdate();
    $user = $update->getEffectiveUser();
    $chat = $update->getEffectiveChat();
    if ($chat->getId() == $user->getId()){
        $ctx->sendMessage("Berishingiz mumkin bo'lgan buyruqlar: /start, /help");
    }
});

$bot->run();