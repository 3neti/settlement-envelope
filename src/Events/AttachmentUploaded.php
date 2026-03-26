<?php

namespace LBHurtado\SettlementEnvelope\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LBHurtado\SettlementEnvelope\Models\Envelope;
use LBHurtado\SettlementEnvelope\Models\EnvelopeAttachment;

class AttachmentUploaded
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Envelope $envelope,
        public EnvelopeAttachment $attachment,
        public ?Model $actor = null
    ) {}
}
