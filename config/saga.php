<?php

return [
    // Prazos para o cliente agir; vencidos, a saga compensa (CancelQuote) e
    // a OS é cancelada. Valores baixos ajudam a demonstrar o timeout no vídeo.
    'approval_timeout_minutes' => (int) env('SAGA_APPROVAL_TIMEOUT_MINUTES', 2880),
    'payment_timeout_minutes' => (int) env('SAGA_PAYMENT_TIMEOUT_MINUTES', 1440),
];
