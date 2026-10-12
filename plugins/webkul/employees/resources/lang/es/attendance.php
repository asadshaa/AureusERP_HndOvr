<?php

return [
    'results' => [
        'verified'               => 'Ubicación verificada.',
        'needs_review'           => 'Registrado, pero su ubicación no se pudo confirmar con precisión, por lo que RR. HH. puede revisarla.',
        'outside_geofence'       => 'Parece que está fuera de su lugar de trabajo autorizado. Acérquese e intente nuevamente, o comuníquese con su supervisor.',
        'low_accuracy'           => 'La precisión de la ubicación es demasiado baja. Active la ubicación precisa, acérquese a una ventana o al exterior e intente nuevamente.',
        'invalid_coordinates'    => 'No se pudo leer una ubicación válida. Por favor, inténtelo de nuevo.',
        'stale_location'         => 'La lectura de su ubicación estaba desactualizada. Por favor, inténtelo de nuevo.',
        'permission_denied'      => 'Se requiere permiso de ubicación para registrar la asistencia. Permita la ubicación para este sitio en la configuración de su navegador y vuelva a intentarlo.',
        'location_unavailable'   => 'Su teléfono no pudo determinar su ubicación. Verifique que los servicios de ubicación estén activados e inténtelo de nuevo.',
        'location_timeout'       => 'Determinar su ubicación tomó demasiado tiempo. Verifique que los servicios de ubicación estén activados e inténtelo de nuevo.',
        'insecure_context'       => 'La ubicación solo se puede usar a través de una conexión segura (https). Utilice la dirección oficial de Aureus.',
        'no_location_configured' => 'No hay ningún lugar de trabajo configurado para el registro móvil. Por favor, comuníquese con RR. HH.',
        'employee_not_eligible'  => 'Su perfil de empleado no está configurado para el registro móvil. Por favor, comuníquese con RR. HH.',
        'already_checked_in'     => 'Ya registró su entrada hoy.',
        'not_checked_in'         => 'No ha registrado su entrada.',
        'already_checked_out'    => 'Ya registró su salida hoy.',
        'open_shift_exists'      => 'Todavía tiene una entrada activa. Por favor, registre la salida primero.',
        'on_leave_or_holiday'    => 'Hoy está registrado como permiso o día festivo. Comuníquese con RR. HH. si esto es incorrecto.',
        'remote_exempt'          => 'Registrado (remoto).',
        'review_approved'        => 'Revisión aprobada.',
        'review_rejected'        => 'Revisión rechazada.',
        'too_early_for_checkout' => 'La duración mínima de la jornada es de 1 hora. No puede registrar la salida todavía.',
    ],

    'with_time' => [
        'already_checked_in'     => 'Ya registró su entrada hoy a las :time.',
        'already_checked_out'    => 'Ya registró su salida hoy a las :time.',
        'open_shift_exists'      => 'Todavía tiene una entrada activa desde las :time. Por favor, registre la salida primero.',
        'too_early_for_checkout' => 'La duración mínima de la jornada es de 1 hora. La salida estará disponible a las :time.',
    ],

    'done' => [
        'check_in' => [
            'verified'      => 'Ubicación verificada. Entrada registrada a las :time.',
            'needs_review'  => 'Entrada registrada a las :time. Su ubicación no se pudo confirmar con precisión, por lo que RR. HH. puede revisarla.',
            'remote_exempt' => 'Entrada registrada (remoto) a las :time.',
        ],
        'check_out' => [
            'verified'      => 'Ubicación verificada. Salida registrada a las :time.',
            'needs_review'  => 'Salida registrada a las :time. Su ubicación no se pudo confirmar con precisión, por lo que RR. HH. puede revisarla.',
            'remote_exempt' => 'Salida registrada (remoto) a las :time.',
        ],
    ],

    'rate_limited'     => 'Demasiados intentos. Por favor, espere un minuto e inténtelo de nuevo.',
    'unexpected_error' => 'Algo salió mal. Por favor, inténtelo de nuevo o comuníquese con RR. HH.',
    'not_enabled'      => 'El registro móvil no está habilitado.',
    'privacy_note'     => 'Su ubicación solo se verifica cuando toca Registrar entrada o Registrar salida. No se rastrea en ningún otro momento.',
];
