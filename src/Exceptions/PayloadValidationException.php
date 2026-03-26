<?php

namespace LBHurtado\SettlementEnvelope\Exceptions;

class PayloadValidationException extends SettlementEnvelopeException
{
    public function __construct(
        string $message,
        public readonly array $errors = []
    ) {
        parent::__construct($message);
    }
}
