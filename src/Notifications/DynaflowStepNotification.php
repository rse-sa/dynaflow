<?php

namespace RSE\DynaFlow\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use RSE\DynaFlow\Support\DynaflowContext;

class DynaflowStepNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        protected DynaflowContext $context
    ) {}

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $placeholders = $this->getPlaceholders();

        $subject = $this->context->sourceStep->getNotificationSubject($placeholders);
        $message = $this->context->sourceStep->getNotificationMessage($placeholders);

        $mailMessage = (new MailMessage)
            ->subject($subject)
            ->greeting(__('Hello!'))
            ->line($message);

        // Add note if provided
        if ($this->context->notes) {
            $mailMessage->line(__('Note: :note', ['note' => $this->context->notes]));
        }

        // Add action button to view workflow
        if ($this->context->model()) {
            $mailMessage->action(
                __('View Workflow'),
                url('/workflows/' . $this->context->instance->id)
            );
        }

        return $mailMessage;
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        $placeholders = $this->getPlaceholders();

        return [
            'workflow_instance_id' => $this->context->instance->id,
            'step_id' => $this->context->sourceStep->id,
            'step_name' => $this->context->sourceStep->name,
            'execution_id' => $this->context->execution?->id,
            'decision' => $this->context->decision,
            'note' => $this->context->notes,
            'executed_by' => $this->context->user?->name ?? __('System'),
            'subject' => $this->context->sourceStep->getNotificationSubject($placeholders),
            'message' => $this->context->sourceStep->getNotificationMessage($placeholders),
            'topic' => $this->context->instance->dynaflow->topic,
            'action' => $this->context->instance->dynaflow->action,
        ];
    }

    /**
     * Get placeholders for notification templates.
     */
    protected function getPlaceholders(): array
    {
        $workflow = $this->context->instance->dynaflow;

        return [
            'step_name' => $this->context->sourceStep->name,
            'decision' => __(ucfirst($this->context->decision)),
            'topic' => $workflow->topic,
            'action' => $workflow->action,
            'workflow_name' => $workflow->name,
            'user_name' => $this->context->user?->name ?? __('System'),
            'user_email' => $this->context->user?->email ?? '',
            'note' => $this->context->notes ?? '',
            'duration' => $this->context->duration() ?? 0,
            'duration_hours' => round(($this->context->duration() ?? 0) / 60),
            'executed_at' => $this->context->execution?->executed_at->format('Y-m-d H:i:s') ?? now()->format('Y-m-d H:i:s'),
            'model_type' => $this->context->instance->model_type ?? '',
            'model_id' => $this->context->instance->model_id ?? '',
        ];
    }
}
