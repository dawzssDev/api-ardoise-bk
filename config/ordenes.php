<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ventana de órdenes "lista" en el tablero de cocina (KDS)
    |--------------------------------------------------------------------------
    |
    | Las órdenes en status pendiente / pagada / en cocina se muestran siempre.
    | Las STATUS_LISTA (5) solo aparecen si listo_at está dentro de estas horas.
    |
    */

    'kitchen_listas_hours' => (int) env('KITCHEN_LISTAS_HOURS', 8),

];
