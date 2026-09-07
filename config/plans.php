<?php

/*
|--------------------------------------------------------------------------
| Límites de catálogo por plan (Básico / Plus)
|--------------------------------------------------------------------------
|
| Se copian a `users` al suscribirse. Si user_ardo_vip = 1, no aplican.
| limit_stock_* = máximo de registros de stock POR sucursal.
|
*/

return [

    'tiers' => [

        'basico' => [
            'label' => 'Básico',
            'limit_sucursales' => 1,
            'limit_insumos' => 150,
            'limit_stock_insumos' => 150,
            'limit_proveedores' => 30,
            'limit_productos' => 100,
            'limit_stock_productos' => 100,
            'limit_personal' => 25,
            'limit_cuentas_contables' => 2,
            'limit_roles' => 5,
            'limit_staff' => 8,
        ],

        'plus' => [
            'label' => 'Plus',
            'limit_sucursales' => 3,
            'limit_insumos' => 400,
            'limit_stock_insumos' => 400,
            'limit_proveedores' => 75,
            'limit_productos' => 300,
            'limit_stock_productos' => 300,
            'limit_personal' => 70,
            'limit_cuentas_contables' => 4,
            'limit_roles' => 12,
            'limit_staff' => 20,
        ],

    ],

    /*
    | Mapa plan Stripe → tier de límites.
    | prueba/mensual/anual (legacy) se tratan como Básico.
    */
    'plan_tier' => [
        'prueba' => 'basico',
        'mensual' => 'basico',
        'anual' => 'basico',
        'basico_mensual' => 'basico',
        'basico_anual' => 'basico',
        'standard_mensual' => 'basico',
        'standard_anual' => 'basico',
        'standart_mensual' => 'basico',
        'standart_anual' => 'basico',
        'plus_mensual' => 'plus',
        'plus_anual' => 'plus',
        'plus' => 'plus',
    ],

];
