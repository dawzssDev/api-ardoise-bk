<?php

$viewerIds = array_values(array_unique(array_filter(
    array_map(
        static fn (string $id): int => (int) trim($id),
        explode(',', (string) env('SUBSCRIPTION_VIEWER_USER_IDS', '17,24')),
    ),
    static fn (int $id): bool => $id > 0,
)));

return [

    /*
    |--------------------------------------------------------------------------
    | Usuarios que pueden listar todos los suscriptores
    |--------------------------------------------------------------------------
    |
    | IDs de la tabla users (separados por coma). Solo estos titulares
    | pueden consultar GET /api/subscriptions/subscribers.
    |
    */

    'viewer_user_ids' => $viewerIds,

];
