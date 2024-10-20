<?php

use Zanzara\Zanzara;
use Zanzara\Context;
use function React\Async\await;

require __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
$dotenv->required('TG_BOT_TOKEN');

$bot = new Zanzara($_ENV['TG_BOT_TOKEN'] ?? "864286200:AAEFTcN8X5ETAS-BMOMTd9oWch_4r8cF9go");

$bot->onCommand('start', function (Context $ctx) {
    $update = $ctx->getUpdate();
    file_put_contents('updates/starts/'. $update->getUpdateId() . '.json', $update);
    $ctx->sendMessage("Bu bot guruhdagi join va remove, link`li xabarlarni larni tozalaydi. \nYuqoridagi cheklovlar guruh adminlariga ta'sir qilmaydi.\nGuruhga qo'shing va o'sha guruh adminligini botga bering. O'qish va O'chira olish huquqi bo'lishi kerak.");
});

$bot->onCommand('help', function (Context $ctx) {
    $ctx->sendMessage("Bot yaratuvchi: @dasturchi_xizmati ");
});

$bot->onUpdate(function (Context $ctx) {

    $admins = [];
    $update = $ctx->getUpdate();
    var_dump($update->jsonSerialize());
    $user = $update->getEffectiveUser();
    $chat = $update->getEffectiveChat();
    $message = $update->getMessage();
    var_dump($message->getText());
    $name = $user->getFirstName() . " " . $user->getLastName();
    $sender_id = $user->getId();
    $chat_id = $chat->getId();
    $username = $user->getUsername();
    if ($username != null){
        $name = "@" . $username;
    }
    if ($chat->getType() === 'supergroup'){

        if (!$user->isBot()){
            if ($message->getLeftChatMember() != null){
                $ctx->deleteMessage($chat->getId(), $message->getMessageId());
            }
            if ($message->getNewChatMembers() != null){
                $ctx->deleteMessage($chat->getId(), $message->getMessageId());
            }
        }

        if ($sender_id !== 777000){
            $ctx->getChatAdministrators($chat_id)->then(
                function ($admin_members) use(&$admins, $ctx, $update, $message, $user, $chat, $sender_id, $chat_id, $name){
                    var_dump($admins);
                    if (is_array($admin_members)){
                        foreach ($admin_members as $member){
                            if (!$member->getUser()->isBot()){
                                $admins[] = $member->getUser()->getId();
                            }
                        }
                    }

                    if (!in_array($sender_id, $admins)){
                        var_dump('not admin');

                        $entities = $message->getEntities();
                        $caption_entities = $message->getCaptionEntities();

                        if (is_array($entities)){

                            if ($message->getForwardFrom()){
                                $ctx->deleteMessage($chat->getId(), $message->getMessageId());
                            }

                            foreach ($entities as $entity){

                                if ($entity->getType() == 'text_link' || $entity->getType() == 'url' || $entity->getType() == 'mention' ){
                                    $ctx->deleteMessage($chat->getId(), $message->getMessageId());
                                    break;
                                }
                            }
                        }
                        if (is_array($caption_entities)){
                            foreach ($caption_entities as $entity){
                                if ($entity->getType() == 'text_link' || $entity->getType() == 'url' || $entity->getType() == 'mention' ){
                                    $ctx->deleteMessage($chat->getId(), $message->getMessageId());

//                            $ctx->sendMessage("<a href='tg://user?id=$sender_id'>$name</a>, reklama tarqatmang, iltimos!", [
//                                'chat_id' => $chat_id,
//                                'parse_mode' => 'HTML'
//                            ]);
                                    break;
                                }
                            }
                        }
                    }else{
                        echo "admin";
                    }
                });
        }


    }

});

$bot->fallback(function(Context $ctx) {
    $update = $ctx->getUpdate();
    file_put_contents('updates/fallbacks/'. $update->getUpdateId() . '.json', $update);
    $user = $update->getEffectiveUser();
    $chat = $update->getEffectiveChat();
    if ($chat->getId() == $user->getId()){
        $ctx->sendMessage("Berishingiz mumkin bo'lgan buyruqlar: /start, /help");
    }
});

$bot->run();