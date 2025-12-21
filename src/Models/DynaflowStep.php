<?php

namespace RSE\DynaFlow\Models;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RSE\DynaFlow\Database\Factories\DynaflowStepFactory;
use Spatie\Translatable\HasTranslations;

#[UseFactory(DynaflowStepFactory::class)]
class DynaflowStep extends Model
{
    use HasFactory;
    use HasTranslations;

    protected $fillable = [
        'dynaflow_id',
        'key',
        'name',
        'description',
        'order',
        'is_final',
        'auto_close',
        'workflow_status',
        'metadata',
    ];

    protected $casts = [
        'is_final'   => 'boolean',
        'auto_close' => 'boolean',
        'metadata'   => 'array',
    ];

    public array $translatable = ['name', 'description'];

    public function dynaflow(): BelongsTo
    {
        return $this->belongsTo(Dynaflow::class);
    }

    public function allowedTransitions(): BelongsToMany
    {
        return $this->belongsToMany(
            DynaflowStep::class,
            'dynaflow_step_transitions',
            'from_step_id',
            'to_step_id'
        )->withTimestamps();
    }

    public function assignees(): HasMany
    {
        return $this->hasMany(DynaflowStepAssignee::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(DynaflowStepExecution::class);
    }

    public function canTransitionTo(DynaflowStep $step): bool
    {
        return $this->allowedTransitions()->where('to_step_id', $step->id)->exists();
    }

    /**
     * Get a metadata value by key
     */
    public function getMetadata(string $key, mixed $default = null): mixed
    {
        return data_get($this->metadata, $key, $default);
    }

    /**
     * Check if step should be auto-rejected after given hours
     */
    public function shouldAutoReject(int $hours): bool
    {
        $maxHours = $this->getMetadata('max_duration_to_reject');

        return $maxHours && $hours >= $maxHours;
    }

    /**
     * Check if step should be auto-accepted after given hours
     */
    public function shouldAutoAccept(int $hours): bool
    {
        $maxHours = $this->getMetadata('max_duration_to_accept');

        return $maxHours && $hours >= $maxHours;
    }

    /**
     * Get notification subject with placeholders replaced
     */
    public function getNotificationSubject(array $placeholders = []): string
    {
        $subject = $this->getMetadata('notification_subject', ['en' => '']);
        $translated = is_array($subject) ? ($subject[app()->getLocale()] ?? $subject['en'] ?? '') : $subject;

        return $this->replacePlaceholders($translated, $placeholders);
    }

    /**
     * Get notification message with placeholders replaced
     */
    public function getNotificationMessage(array $placeholders = []): string
    {
        $message = $this->getMetadata('notification_message', ['en' => '']);
        $translated = is_array($message) ? ($message[app()->getLocale()] ?? $message['en'] ?? '') : $message;

        return $this->replacePlaceholders($translated, $placeholders);
    }

    /**
     * Replace placeholders in a string
     */
    protected function replacePlaceholders(string $text, array $placeholders): string
    {
        foreach ($placeholders as $key => $value) {
            $text = str_replace('{' . $key . '}', $value, $text);
        }

        return $text;
    }
}
