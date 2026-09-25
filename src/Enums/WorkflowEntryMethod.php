<?php

namespace LBHurtado\SettlementEnvelope\Enums;

enum WorkflowEntryMethod: string
{
    case PublicEndpoint = 'public_endpoint';
    case PaymentQr = 'payment_qr';
}
