<?php

namespace LBHurtado\SettlementEnvelope\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LBHurtado\SettlementEnvelope\Models\EnvelopeAttachment;

class AttachmentReviewed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public EnvelopeAttachment $attachment,
        public string $decision, // 'accepted' or 'rejected'
        public ?Model $actor = null
    ) {}
}
