<?php

namespace RSE\DynaFlow\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use RSE\DynaFlow\Support\DynaflowContext;

/**
 * Generic workflow email mailable.
 *
 * Used by EmailActionHandler when no custom template is specified.
 * Supports HTML body with optional plain text fallback.
 */
class WorkflowEmail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        protected string $emailSubject,
        protected string $emailBody,
        protected DynaflowContext $ctx,
        protected array $config = []
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->emailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'dynaflow::emails.workflow-notification',
            with: [
                'body'     => $this->emailBody,
                'context'  => $this->ctx,
                'config'   => $this->config,
                'instance' => $this->ctx->instance,
                'model'    => $this->ctx->model(),
                'step'     => $this->ctx->targetStep,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        $attachments = [];

        foreach ($this->config['attachments'] ?? [] as $attachment) {
            if (is_string($attachment)) {
                $attachments[] = Attachment::fromPath($attachment);
            } elseif (is_array($attachment) && isset($attachment['path'])) {
                $att = Attachment::fromPath($attachment['path']);
                if (isset($attachment['name'])) {
                    $att->as($attachment['name']);
                }
                if (isset($attachment['mime'])) {
                    $att->withMime($attachment['mime']);
                }
                $attachments[] = $att;
            }
        }

        return $attachments;
    }
}
