<?php

namespace LBHurtado\SettlementEnvelope\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LBHurtado\SettlementEnvelope\Models\Envelope;

class SignalChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Envelope $envelope,
        public string $key,
        public mixed $oldValue,
        public mixed $newValue,
        public ?Model $actor = null
    ) {}
}
