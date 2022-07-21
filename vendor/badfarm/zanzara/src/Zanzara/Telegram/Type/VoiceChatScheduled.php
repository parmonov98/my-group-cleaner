<?php

declare(strict_types=1);

namespace Zanzara\Telegram\Type;

/**
 * This object represents a service message about a voice chat scheduled in the chat.
 *
 * More on https://core.telegram.org/bots/api#voicechatscheduled
 *
 */
class VoiceChatScheduled
{

    /**
     * Point in time (Unix timestamp) when the voice chat is supposed to be started by a chat administrator
     *
     * @var int
     */
    private $start_date;

    /**
     * @return int
     */
    public function getStartDate(): int
    {
        return $this->start_date;
    }

    /**
     * @param int $start_date
     */
    public function setStartDate(int $start_date): void
    {
        $this->start_date = $start_date;
    }

}