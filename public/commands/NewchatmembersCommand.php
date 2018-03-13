<?php declare(strict_types=1);
/**
 * This file is part of the PHP Telegram Support Bot.
 *
 * (c) PHP Telegram Bot Team (https://github.com/php-telegram-bot)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Longman\TelegramBot\Commands\SystemCommands;

use Longman\TelegramBot\Commands\SystemCommand;
use Longman\TelegramBot\DB;
use Longman\TelegramBot\Entities\ChatMember;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Entities\User;
use Longman\TelegramBot\Request;

/**
 * New chat members command
 */
class NewchatmembersCommand extends SystemCommand
{
    /**
     * @var string
     */
    protected $name = 'newchatmembers';

    /**
     * @var string
     */
    protected $description = 'New Chat Members';

    /**
     * @var string
     */
    protected $version = '0.3.0';

    /**
     * @var int
     */
    private $chat_id;

    /**
     * @var int
     */
    private $user_id;

    /**
     * @var string
     */
    private $group_name = 'PHP Telegram Support Bot';

    /**
     * @inheritdoc
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    public function execute(): ServerResponse
    {
        $message       = $this->getMessage();
        $this->chat_id = $message->getChat()->getId();
        $this->user_id = $message->getFrom()->getId();

        $this->group_name = $message->getChat()->getTitle();

        ['users' => $new_users, 'bots' => $new_bots] = $this->getNewUsersAndBots();

        // Kick bots if they weren't added by an admin.
        $this->kickDisallowedBots($new_bots);

        return $this->refreshWelcomeMessage($new_users);
    }

    /**
     * Remove existing and send new welcome message.
     *
     * @param array $new_users
     *
     * @return ServerResponse
     * @throws \Longman\TelegramBot\Exception\TelegramException
     */
    private function refreshWelcomeMessage(array $new_users): ServerResponse
    {
        if (empty($new_users)) {
            return Request::emptyResponse();
        }

        $new_users_text = implode(', ', array_map(function (User $new_user) {
            return '<a href="tg://user?id=' . $new_user->getId() . '">' . filter_var($new_user->getFirstName(), FILTER_SANITIZE_SPECIAL_CHARS) . '</a>';
        }, $new_users));

        $text = "Welcome {$new_users_text} to the <b>{$this->group_name}</b> group\n";
        $text .= 'Please remember that this is <b>NOT</b> the Telegram Support Chat.' . PHP_EOL;
        $text .= 'Read the <a href="https://telegram.me/PHP_Telegram_Support_Bot?start=">Rules</a> that apply here.';

        $welcome_message_sent = $this->replyToChat($text, ['parse_mode' => 'HTML', 'disable_web_page_preview' => true]);
        if (!$welcome_message_sent->isOk()) {
            return Request::emptyResponse();
        }

        $welcome_message = $welcome_message_sent->getResult();

        $new_message_id = $welcome_message->getMessageId();
        $chat_id        = $welcome_message->getChat()->getId();

        if ($new_message_id && $chat_id) {
            if ($message_id = $this->getSimpleOption('welcome_message_id')) {
                Request::deleteMessage(compact('chat_id', 'message_id'));
            }

            $this->setSimpleOption('welcome_message_id', $new_message_id);
        }

        return $welcome_message_sent;
    }

    /**
     * Check if the bot has been added by an admin.
     *
     * @return bool
     */
    private function isUserAllowedToAddBot(): bool
    {
        $chat_member = Request::getChatMember([
            'chat_id' => $this->chat_id,
            'user_id' => $this->user_id,
        ])->getResult();

        if ($chat_member instanceof ChatMember) {
            return \in_array($chat_member->getStatus(), ['creator', 'administrator'], true);
        }

        return false;
    }

    /**
     * Get an array of all newly added users and bots.
     *
     * @return array
     */
    private function getNewUsersAndBots(): array
    {
        $users = [];
        $bots  = [];

        foreach ($this->getMessage()->getNewChatMembers() as $member) {
            if ($member->getIsBot()) {
                $bots[] = $member;
                continue;
            }

            $users[] = $member;
        }

        return compact('users', 'bots');
    }

    /**
     * Kick bots that weren't added by an admin.
     *
     * @todo: Maybe notify the admins / user that tried to add the bot(s)?
     *
     * @param array $bots
     */
    private function kickDisallowedBots(array $bots): void
    {
        if (empty($bots) || $this->isUserAllowedToAddBot()) {
            return;
        }

        foreach ($bots as $bot) {
            Request::kickChatMember([
                'chat_id' => $this->chat_id,
                'user_id' => $bot->getId(),
            ]);
        }
    }

    /**
     * Get a simple option value.
     *
     * @todo: Move into core!
     *
     * @param string $name
     * @param mixed  $default
     *
     * @return mixed
     */
    private function getSimpleOption($name, $default = false)
    {
        return DB::getPdo()->query("
            SELECT `value`
            FROM `simple_options`
            WHERE `name` = '{$name}'
        ")->fetchColumn() ?? $default;
    }

    /**
     * Set a simple option value.
     *
     * @todo: Move into core!
     *
     * @param string $name
     * @param mixed  $value
     *
     * @return bool
     */
    private function setSimpleOption($name, $value): bool
    {
        return DB::getPdo()->prepare("
            INSERT INTO `simple_options`
            (`name`, `value`) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE
            `name` = VALUES(`name`),
            `value` = VALUES(`value`)
        ")->execute([$name, $value]);
    }
}
