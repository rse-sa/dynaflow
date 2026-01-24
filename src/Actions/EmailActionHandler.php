<?php

namespace RSE\DynaFlow\Actions;

use Illuminate\Support\Facades\Mail;
use RSE\DynaFlow\Contracts\ActionHandler;
use RSE\DynaFlow\Contracts\ActionResult;
use RSE\DynaFlow\Mail\WorkflowEmail;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Services\PlaceholderResolver;
use RSE\DynaFlow\Support\DynaflowContext;

/**
 * Action handler for sending emails.
 *
 * Supports:
 * - Custom subject and body with placeholders
 * - CC and BCC recipients
 * - Laravel Mailable templates
 * - Multiple recipients
 * - Attachments (from paths)
 *
 * Configuration example:
 * ```php
 * [
 *     'to' => '{{model.owner.email}}',
 *     'cc' => ['manager@example.com'],
 *     'subject' => 'Order #{{model.id}} requires approval',
 *     'body' => 'Please review the order submitted by {{user.name}}.',
 *     'template' => null, // Optional: Mailable class name
 * ]
 * ```
 */
class EmailActionHandler implements ActionHandler
{
    public function __construct(
        protected PlaceholderResolver $resolver
    ) {}

    public function execute(DynaflowStep $step, DynaflowContext $ctx): ActionResult
    {
        $config = $step->action_config ?? [];

        try {
            // Resolve recipients
            $to = $this->resolveRecipients($config['to'] ?? null, $ctx);

            if (empty($to)) {
                return ActionResult::failed('No email recipient specified');
            }

            // Check if using custom Mailable template
            if (! empty($config['template'])) {
                return $this->sendWithTemplate($config['template'], $to, $config, $ctx);
            }

            // Resolve subject and body
            $subject = $this->resolver->resolve($config['subject'] ?? 'Workflow Notification', $ctx);
            $body    = $this->resolver->resolve($config['body'] ?? '', $ctx);

            // Resolve CC and BCC
            $cc  = $this->resolveRecipients($config['cc'] ?? [], $ctx);
            $bcc = $this->resolveRecipients($config['bcc'] ?? [], $ctx);

            // Build and send email
            $mail = Mail::to($to);

            if (! empty($cc)) {
                $mail->cc($cc);
            }

            if (! empty($bcc)) {
                $mail->bcc($bcc);
            }

            // Send using generic WorkflowEmail mailable
            $mail->send(new WorkflowEmail($subject, $body, $ctx, $config));

            return ActionResult::success([
                'sent_to' => $to,
                'subject' => $subject,
                'cc'      => $cc,
                'bcc'     => $bcc,
            ]);
        } catch (\Throwable $e) {
            return ActionResult::failed('Failed to send email: ' . $e->getMessage(), [
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * Send email using a custom Mailable template.
     */
    protected function sendWithTemplate(string $template, array $to, array $config, DynaflowContext $ctx): ActionResult
    {
        if (! class_exists($template)) {
            return ActionResult::failed("Mailable class '{$template}' not found");
        }

        $mailable = new $template($ctx, $config);

        $mail = Mail::to($to);

        $cc  = $this->resolveRecipients($config['cc'] ?? [], $ctx);
        $bcc = $this->resolveRecipients($config['bcc'] ?? [], $ctx);

        if (! empty($cc)) {
            $mail->cc($cc);
        }

        if (! empty($bcc)) {
            $mail->bcc($bcc);
        }

        $mail->send($mailable);

        return ActionResult::success([
            'sent_to'  => $to,
            'template' => $template,
            'cc'       => $cc,
            'bcc'      => $bcc,
        ]);
    }

    /**
     * Resolve recipient(s) from config value.
     */
    protected function resolveRecipients(mixed $recipients, DynaflowContext $ctx): array
    {
        if (empty($recipients)) {
            return [];
        }

        if (is_string($recipients)) {
            $resolved = $this->resolver->resolve($recipients, $ctx);

            // Handle comma-separated list
            if (str_contains($resolved, ',')) {
                return array_map('trim', explode(',', $resolved));
            }

            return [$resolved];
        }

        if (is_array($recipients)) {
            $resolved = [];
            foreach ($recipients as $recipient) {
                $email = $this->resolver->resolve($recipient, $ctx);
                if (! empty($email)) {
                    $resolved[] = $email;
                }
            }

            return $resolved;
        }

        return [];
    }

    public function getConfigSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'to' => [
                    'type'        => 'string',
                    'title'       => 'Recipient',
                    'description' => 'Email address or placeholder (e.g., {{model.owner.email}})',
                ],
                'cc' => [
                    'type'  => 'array',
                    'items' => ['type' => 'string'],
                    'title' => 'CC Recipients',
                ],
                'bcc' => [
                    'type'  => 'array',
                    'items' => ['type' => 'string'],
                    'title' => 'BCC Recipients',
                ],
                'subject' => [
                    'type'        => 'string',
                    'title'       => 'Subject',
                    'description' => 'Email subject with placeholders',
                ],
                'body' => [
                    'type'        => 'string',
                    'title'       => 'Message Body',
                    'description' => 'Email content with placeholders',
                    'format'      => 'html',
                ],
                'template' => [
                    'type'        => 'string',
                    'title'       => 'Mail Template',
                    'description' => 'Optional: Laravel Mailable class name',
                ],
            ],
            'required' => ['to', 'subject'],
        ];
    }

    public function getLabel(): string
    {
        return 'Send Email';
    }

    public function getDescription(): string
    {
        return 'Send an email notification with customizable subject and body using placeholders.';
    }

    public function getCategory(): string
    {
        return 'notification';
    }

    public function getIcon(): string
    {
        return 'mail';
    }

    public function getOutputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'sent_to' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'string'],
                    'description' => 'List of recipient email addresses',
                ],
                'subject' => [
                    'type'        => 'string',
                    'description' => 'The resolved email subject',
                ],
            ],
        ];
    }
}
