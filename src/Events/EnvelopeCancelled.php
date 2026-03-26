<?php

namespace LBHurtado\SettlementEnvelope\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LBHurtado\SettlementEnvelope\Models\Envelope;

class EnvelopeCancelled
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Envelope $envelope,
        public ?string $reason = null,
        public ?Model $actor = null
    ) {}
}
