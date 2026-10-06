<?php

return [
    'settings' => [
        'section' => 'Gerenderte Logs',
        'description' => 'Logs bleiben auf diesem Panel. Empfänger erhalten einen zufälligen Einmal-Link, der unmittelbar einen HTML-Download startet.',
        'link_ttl_minutes' => 'Link-Ablaufzeit in Minuten',
        'link_ttl_minutes_help' => 'Unbenutzte Links werden nach diesem Zeitraum ungültig. Benutzte Links sofort.',
        'max_log_bytes' => 'Maximale Log-Größe in Bytes',
        'max_log_bytes_help' => 'Ist ein Log größer, werden nur die letzten Bytes gerendert.',
        'saved_title' => 'Einstellungen für gerenderte Logs gespeichert',
        'saved_body' => 'Neue Links verwenden die gespeicherte Ablaufzeit und Größenbegrenzung.',
    ],
    'action' => [
        'label' => 'Download Logs',
        'tooltip' => 'Aktuelle Terminal-Logs intern rendern und einen Einmal-Download-Link erstellen.',
    ],
    'create' => [
        'no_logs_title' => 'Keine Logs verfügbar',
        'success_title' => 'Einmal-Download-Link erstellt',
        'failed_title' => 'Logs konnten nicht gerendert werden',
        'document_title' => ':server Terminal-Log :timestamp',
        'truncated' => '[Log auf die letzten :limit Bytes gekürzt]',
    ],
    'command' => [
        'description' => 'Abgelaufene oder verwaiste gerenderte Log-Downloads entfernen.',
        'purged' => ':count abgelaufene Rendered-Log-Downloads entfernt.',
    ],
];
