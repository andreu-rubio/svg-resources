<?php

require_once INCLUDE_DIR . 'class.plugin.php';
include_once(INCLUDE_DIR . 'class.dept.php');
include_once(INCLUDE_DIR . 'class.list.php');


class DiscordPluginConfig extends PluginConfig {
    function getOptions() {
        return array(
            'discord' => new SectionBreakField(array(
                'label' => 'Discord',
            )),
            'discord-webhook-url' => new TextboxField(array(
                'label' => 'Webhook URL',
                'configuration' => array(
                    'size' => 100,
                    'length' => 200,
                ),
            )),
            'discord-text-length' => new TextboxField(array(
                'label' => 'Tamanho do texto na notificação',
                'configuration' => array(
                    'size' => 10,
                    'length' => 10,
                ),
            )),
        );
    }
}
