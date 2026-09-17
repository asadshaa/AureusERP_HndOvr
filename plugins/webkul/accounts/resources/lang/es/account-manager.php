<?php

return [
    'post-action-validate' => [
        'customer-required'         => 'Proporcione un cliente válido para continuar con la validación de la factura de cliente.',
        'vendor-required'           => 'Proporcione un proveedor válido para continuar con la validación de la factura de proveedor.',
        'bank-archived'             => 'La cuenta bancaria del contacto asociada a esta factura está archivada.',
        'negative-amount'           => 'La factura no se puede confirmar con un importe total negativo.',
        'date-required'             => 'Proporcione una fecha de factura/reembolso válida para continuar con la validación de la factura/reembolso.',
        'currency-archived'         => 'No se puede confirmar una factura con una moneda archivada.',
        'account-deprecated'        => 'Una o más líneas de esta factura utilizan cuentas obsoletas.',
        'lines-required'            => 'Agregue al menos una línea a la factura.',
        'draft-state-required'      => 'Solo se pueden confirmar las facturas en estado borrador.',
        'journal-archived'          => 'No se puede confirmar una factura con un diario archivado.',
        'unbalanced-entry'          => 'Este asiento no está balanceado: el débito total debe ser igual al crédito total antes de poder registrarlo.',
        'sales-tax-not-registered'  => ':company no está registrada para el impuesto sobre las ventas, por lo que esta factura no puede incluir una línea de impuesto. Elimine el impuesto de todas las líneas, o marque a :company como "Registrada para impuesto sobre las ventas" en la Configuración de la empresa si esto es un error.',
        'strn-required'             => ':company está registrada para el impuesto sobre las ventas pero no tiene un STRN registrado. Agregue un STRN en la Configuración de la empresa antes de registrar una factura con impuestos para :company.',
    ],

    'documents' => [
        'titles' => [
            'invoice'     => 'ID de factura n.º :name',
            'bill'        => 'ID de factura de proveedor n.º :name',
            'refund'      => 'ID de reembolso n.º :name',
            'credit-note' => 'ID de nota de crédito n.º :name',
        ],

        'labels' => [
            'invoice-date'          => 'Fecha de factura',
            'bill-date'             => 'Fecha',
            'refund-date'           => 'Fecha de reembolso',
            'credit-note-date'      => 'Fecha de nota de crédito',
            'source'                => 'Origen',
            'due-date'              => 'Fecha de vencimiento',
            'product'               => 'Producto',
            'quantity'              => 'Cantidad',
            'unit'                  => 'Unidad',
            'unit-price'            => 'Precio unitario',
            'subtotal'              => 'Subtotal',
            'tax'                   => 'Impuesto',
            'discount'              => 'Descuento',
            'grand-total'           => 'Total general',
            'payment-information'   => 'Información de pago',
            'payment-communication' => 'Comunicación de pago',
            'account-details'       => 'en estos detalles de la cuenta:',
        ],
    ],
];
