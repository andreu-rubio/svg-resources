<?php
require_once (INCLUDE_DIR . 'class.signal.php');
require_once (INCLUDE_DIR . 'class.plugin.php');
require_once ('config.php');

include ('lib/Html2Text.php');

class DiscordPlugin extends Plugin
{
    var $config_class = 'DiscordPluginConfig';

    function bootstrap()
    {
        Signal::connect('ticket.created', array(
            $this,
            'onTicketCreated'
        ));
        Signal::connect('threadentry.created', array(
            $this,
            'onNewMessage'
        ));
    }

    function onTicketCreated(Ticket $ticket)
    {
        global $cfg;

        $author['name'] = sprintf('%s (%s)', $ticket->getEmail()
            ->getName() , $ticket->getEmail());

        $embeds[0]['author'] = $author;
        $embeds[0]['type'] = 'rich';
        $embeds[0]['color'] = "14177041";
        $embeds[0]['title'] = "[" . $ticket->getId() . "] Aberto - " . $ticket->getSubject();
        $embeds[0]['url'] = $cfg->getUrl() . 'scp/tickets.php?id=' . $ticket->getId();
        $embeds[0]['description'] = strip_tags($ticket->getLastMessage()
            ->getBody()
            ->getClean());
        $payload['embeds'] = $embeds;

        $this->discordMessage($payload);

        return;
    }

    function onNewMessage(ThreadEntry $entry)
    {
        global $cfg;

        if ($entry instanceof MessageThreadEntry)
        {
            $ticketId = $entry->getThreadId();

            $ticket = Ticket::lookup($ticketId);

            if ($ticket)
            {
                $author['name'] = $entry->getPoster();

                $embeds[0]['author'] = $author;
                $embeds[0]['type'] = 'rich';
                $embeds[0]['color'] = 1127128;

                $embeds[0]['title'] = "[" . $ticketId . "] Resposta - " . $ticket->getSubject();

                $embeds[0]['url'] = $cfg->getUrl() . 'scp/tickets.php?id=' . $ticketId . "#reply";
                $embeds[0]['description'] = $this->escapeText($entry->getBody()
                    ->body);

                $payload['embeds'] = $embeds;

                $this->discordMessage($payload);
            }

        }

        return;
    }

    function discordMessage($payload)
    {

        $data_string = utf8_encode(json_encode($payload));

        $url = $this->getConfig()
            ->get('discord-webhook-url');

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            sprintf('Content-Length: %s', strlen($data_string)) ,
        ));

        $response = curl_exec($ch);

        curl_close($ch);

    }

    function escapeText($text)
    {
        $text = Html2Text\Html2Text::convert($text);

        $text = preg_replace("/[\r\n]+/", "\n", $text);
        $text = preg_replace("/[\n\n]+/", "\n", $text);

        if (strlen($text) >= $this->getConfig()
            ->get('discord-text-length'))
        {
            $text = substr($text, 0, $this->getConfig()
                ->get('discord-text-length')) . '...';
        }
        return $text;
    }

}

