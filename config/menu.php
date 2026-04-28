<?php

$prefix = 'nawasara-notification';

return [
    [
        'workspace' => 'communication',
        'label' => 'Komunikasi',
        'icon' => 'lucide-megaphone',
        'url' => '',
        'permission' => 'notification.template.view',
        'submenu' => [
            [
                'label' => 'Templates',
                'icon' => 'lucide-file-text',
                'url' => url($prefix.'/templates'),
                'permission' => 'notification.template.view',
                'navigate' => true,
            ],
            [
                'label' => 'Notification Logs',
                'icon' => 'lucide-mail-search',
                'url' => url($prefix.'/logs'),
                'permission' => 'notification.log.view',
                'navigate' => true,
            ],
        ],
    ],
];
