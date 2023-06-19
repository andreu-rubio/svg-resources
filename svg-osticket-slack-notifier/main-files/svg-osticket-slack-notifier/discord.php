<?php

require_once(INCLUDE_DIR . 'class.signal.php');
require_once(INCLUDE_DIR . 'class.plugin.php');
require_once(INCLUDE_DIR . 'class.ticket.php');
require_once(INCLUDE_DIR . 'class.osticket.php');
require_once(INCLUDE_DIR . 'class.config.php');
require_once(INCLUDE_DIR . 'class.format.php');
require_once('config.php');
require_once('discord.class.php');

class DiscordPlugin extends Plugin {

    var $config_class = "DiscordPluginConfig";

    /**
     * The entrypoint of the plugin, keep short, always runs.
     */
    function bootstrap() {
        // Listen for osTicket to tell us it's made a new ticket or updated
        // an existing ticket:
        Signal::connect('ticket.created', array($this, 'onTicketCreated'));
        Signal::connect('threadentry.created', array($this, 'onTicketUpdated'));
        // Tasks? Signal::connect('task.created',array($this,'onTaskCreated'));
    }


    /**
     * @global $cfg
     * @param Ticket $ticket
     * @throws Exception
     */
    function onTicketCreated(Ticket $ticket) {
        global $cfg;
        $type = 'Issue created: ';
        if (!$cfg instanceof OsticketConfig) {
            error_log("Discord plugin called too early.");
            return;
        }

        $this->sendTodiscord($ticket, $type);
    }

    /**
     * What to do with an Updated Ticket?
     *
     * @global OsticketConfig $cfg
     * @param ThreadEntry $entry
     * @return type
     */
    function onTicketUpdated(ThreadEntry $entry) {
        $type = 'Issue Updated: ';
        global $cfg;
        if (!$cfg instanceof OsticketConfig) {
            error_log("Discord plugin called too early.");
            return;
        }
        if (!$entry instanceof MessageThreadEntry) {
            // this was a reply or a system entry.. not a message from a user
            return;
        }

        // Need to fetch the ticket from the ThreadEntry
        $ticket = $this->getTicket($entry);
        if (!$ticket instanceof Ticket) {
            // Admin created ticket's won't work here.
            return;
        }

        // Check to make sure this entry isn't the first (ie: a New ticket)
        $first_entry = $ticket->getMessages()[0];
        if ($entry->getId() == $first_entry->getId()) {
            return;
        }

        $this->sendTodiscord($ticket, $type, 'warning');
    }

    /**
     * A helper function that sends messages to discord endpoints.
     *
     * @global osTicket $ost
     * @global OsticketConfig $cfg
     * @param Ticket $ticket
     * @param string $heading
     * @param string $body
     * @param string $colour
     * @throws \Exception
     */
    function sendTodiscord(Ticket $ticket, $type, $colour = 'good') {
        global $ost, $cfg;
        error_log("Running sendToDiscord");
        if (!$ost instanceof osTicket || !$cfg instanceof OsticketConfig) {
            error_log("Discord plugin called too early.");
            return;
        }
        $url = $this->getConfig()->get('discord-webhook-url');
        if (!$url) {
            $ost->logError('Discord Plugin not configured', 'You need to read the Readme and configure a webhook URL before using this.');
        }

        // Check the subject, see if we want to filter it.
        $regex_subject_ignore = $this->getConfig()->get('discord-regex-subject-ignore');
        // Filter on subject, and validate regex:
        if ($regex_subject_ignore && preg_match("/$regex_subject_ignore/i", $ticket->getSubject())) {
            $ost->logDebug('Ignored Message', 'Discord notification was not sent because the subject (' . $ticket->getSubject() . ') matched regex (' . htmlspecialchars($regex_subject_ignore) . ').');
            return;
        } else {
            error_log("$ticket_subject didn't trigger $regex_subject_ignore");
        }

        // Build the payload with the formatted data:
        $payload = $this->createJsonMessage($ticket, $type);

        try {
            // Setup curl
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Content-Type: application/json',
                    'Content-Length: ' . strlen($payload))
            );

            // Actually send the payload to discord:
            if (curl_exec($ch) === false) {
                throw new \Exception($url . ' - ' . curl_error($ch));
            } else {
                $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($statusCode != '200') {
                    throw new \Exception(
                        'Error sending to: ' . $url
                        . ' Http code: ' . $statusCode
                        . ' curl-error: ' . curl_errno($ch));
                }
            }
        } catch (\Exception $e) {
            error_log('Error posting to Discord. ' . $e->getMessage());
        } finally {
            curl_close($ch);
        }
    }

    /**
     * Fetches a ticket from a ThreadEntry
     *
     * @param ThreadEntry $entry
     * @return Ticket
     */
    function getTicket(ThreadEntry $entry) {
        $ticket_id = Thread::objects()->filter([
            'id' => $entry->getThreadId()
        ])->values_flat('object_id')->first() [0];

        // Force lookup rather than use cached data..
        // This ensures we get the full ticket, with all
        // thread entries etc..
        return Ticket::lookup(array(
            'ticket_id' => $ticket_id
        ));
    }

    /**
     * Formats text according to the
     * formatting rules:https://docs.microsoft.com/en-us/outlook/actionable-messages/adaptive-card
     *
     * @param string $text
     * @return string
     */
    function format_text($text) {
        $formatter      = [
            '<' => '&lt;',
            '>' => '&gt;',
            '&' => '&amp;'
        ];
        $formatted_text = str_replace(array_keys($formatter), array_values($formatter), $text);
        // put the <>'s control characters back in
        $moreformatter  = [
            'CONTROLSTART' => '<',
            'CONTROLEND'   => '>'
        ];
        // Replace the CONTROL characters, and limit text length to 500 characters.
        return substr(str_replace(array_keys($moreformatter), array_values($moreformatter), $formatted_text), 0, 500);
    }

    /**
     * Get either a Gravatar URL or complete image tag for a specified email address.
     *
     * @param string $email The email address
     * @param string $s Size in pixels, defaults to 80px [ 1 - 2048 ]
     * @param string $d Default imageset to use [ 404 | mm | identicon | monsterid | wavatar ]
     * @param string $r Maximum rating (inclusive) [ g | pg | r | x ]
     * @param boole $img True to return a complete IMG tag False for just the URL
     * @param array $atts Optional, additional key/value attributes to include in the IMG tag
     * @return String containing either just a URL or a complete image tag
     * @source https://gravatar.com/site/implement/images/php/
     */
    function get_gravatar($email, $s = 80, $d = 'mm', $r = 'g', $img = false, $atts = array()) {
        $url = 'https://www.gravatar.com/avatar/';
        $url .= md5(strtolower(trim($email)));
        $url .= "?s=$s&d=$d&r=$r";
        if ($img) {
            $url = '<img src="' . $url . '"';
            foreach ($atts as $key => $val)
                $url .= ' ' . $key . '="' . $val . '"';
            $url .= ' />';
        }
        return $url;
    }

    /**
     * @param $ticket
     * @param string $color
     * @param null $type
     * @return false|string
     */
    private function createJsonMessage($ticket, $type = null, $color = 'AFAFAF')
    {
        global $cfg;
        $timestamp = date("c", strtotime("now"));
        if ($ticket->isOverdue()) {
            $color = 'ff00ff';
        }

        $message = [
            // Username
            "username" => "NeosVR OSTicket",

            // Avatar URL.
            // Uncoment to replace image set in webhook
            "avatar_url" => $this->get_gravatar($ticket->getEmail()),

            // Text-to-speech
            "tts" => false,

            // File upload
            // "file" => "",

            // Embeds Array
            "embeds" => [
                [
                    // Embed Title
                    "title" =>  $this->format_text($type . $ticket->getSubject()),

                    // Embed Type
                    "type" => "rich",

                    // URL of title link
                    "url" => $cfg->getUrl() . '/scp/tickets.php?id=' . $ticket->getId(),

                    // Timestamp of embed must be formatted as ISO8601
                    "timestamp" => $timestamp,

                    // Embed left border color in HEX
                    "color" => hexdec( "3366ff" ),

                    // Additional Fields array
                    "fields" => [
                        [
                            "name" => "Ticket Type",
                            "value" => $ticket->getHelpTopic(),
                            "inline" => true
                        ]
                        // Etc..
                    ],

                    // Footer
                    "footer" => [
                        "text" => $cfg->getUrl(),
                        "icon_url" => $this->get_gravatar($ticket->getEmail()),
                    ],


                    // Thumbnail
                    //"thumbnail" => [
                    //    "url" => "https://ru.gravatar.com/userimage/28503754/1168e2bddca84fec2a63addb348c571d.jpg?size=400"
                    //],

                    // Author
                    "author" => [
                        "name" => "OSTicket",
                        "url" => $this->get_gravatar($ticket->getEmail()),
                    ],
                ]
            ]];

        return json_encode($message, JSON_UNESCAPED_SLASHES  | JSON_UNESCAPED_UNICODE );

    }

}