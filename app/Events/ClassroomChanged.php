<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class ClassroomChanged implements ShouldBroadcastNow
{
    use \Illuminate\Broadcasting\InteractsWithSockets;

    public function __construct(public int $campusId, public int $courseId, public ?int $quizId, public string $kind) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel(app()->environment().'.campus.'.$this->campusId)];
    }

    public function broadcastAs(): string
    {
        return 'classroom.changed';
    }

    public function broadcastWith(): array
    {
        // Invalidation only: grades, answers and personal data stay behind page authorization.
        return ['courseId' => $this->courseId, 'quizId' => $this->quizId, 'kind' => $this->kind];
    }
}
