<?php

return [
    'formula-cycle'                  => 'Circular formula reference: :chain',
    'missing-account-bindings'       => 'Line ":line" reads the ledger but has no accounts bound to it.',
    'missing-formulas'               => 'Line ":line" is formula-based but has no formula operands.',
    'missing-external-provider'      => 'Line ":line" uses an external provider but no provider key is set.',
    'unregistered-external-provider' => 'Line ":line" references the external provider ":provider", which is not registered.',
    'operand-constant-missing-value' => 'A formula operand on line ":line" is a constant but has no value.',
    'operand-line-missing-id'        => 'A formula operand on line ":line" is a line reference but points at no line.',
    'missing-operand-line'           => 'A formula operand on line ":line" references line :target, which does not exist.',
    'cross-template-operand'         => 'A formula operand on line ":line" references ":target", which belongs to a different report.',
    'operand-not-computable'         => 'A formula operand on line ":line" references ":target", which carries no values (header or spacer).',
    'duplicate-line-sort'            => 'Multiple lines share sort position :sort (:lines); their order is ambiguous.',
    'duplicate-column-sort'          => 'Multiple columns share sort position :sort; their order is ambiguous.',
    'invalid-column-definition'      => 'Column ":column" has an invalid month configuration.',
    'duplicate-global-code'          => 'Another global template already uses code ":code" version :version.',
    'dimension-not-applied'          => 'Line ":line" defines a dimension filter, which the engine does not apply yet; it will be ignored.',
    'check-line-carries-no-values'   => 'Line ":line" is flagged as a check row but carries no values.',
    'check-row-violation'            => 'Check row ":line" is :value in column :column; expected zero.',
    'account-bound-twice'            => 'Account :account is bound to more than one line (:lines); its balance is counted in each of them.',
    'unbound-account-with-balance'   => ':count account(s) with posted balances are bound to no line of this template, so their balances are left out: :accounts.',
];
