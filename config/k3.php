<?php

return [
    // Keep off until staging identities, recipient mapping and mobile navigation pass.
    // This controls only the new secured chat push endpoints, not appointments.
    'chat_push_enabled' => (bool) env('K3_CHAT_PUSH_ENABLED', false),
];
