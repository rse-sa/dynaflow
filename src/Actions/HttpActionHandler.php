<?php

namespace RSE\DynaFlow\Actions;

use Illuminate\Support\Facades\Http;
use RSE\DynaFlow\Contracts\ActionHandler;
use RSE\DynaFlow\Contracts\ActionResult;
use RSE\DynaFlow\Models\DynaflowStep;
use RSE\DynaFlow\Services\PlaceholderResolver;
use RSE\DynaFlow\Support\DynaflowContext;

/**
 * Action handler for making HTTP requests.
 *
 * Supports:
 * - GET, POST, PUT, PATCH, DELETE methods
 * - Custom headers with placeholders
 * - JSON and form data bodies
 * - Timeout and retry configuration
 * - Response handling and routing based on status
 *
 * Configuration example:
 * ```php
 * [
 *     'url' => 'https://api.example.com/orders/{{model.id}}',
 *     'method' => 'POST',
 *     'headers' => [
 *         'Authorization' => 'Bearer {{config:services.api.token}}',
 *     ],
 *     'body' => [
 *         'order_id' => '{{model.id}}',
 *         'status' => 'approved',
 *     ],
 *     'timeout' => 30,
 *     'retries' => 3,
 * ]
 * ```
 */
class HttpActionHandler implements ActionHandler
{
    public function __construct(
        protected PlaceholderResolver $resolver
    ) {}

    public function execute(DynaflowStep $step, DynaflowContext $ctx): ActionResult
    {
        $config = $step->action_config ?? [];

        try {
            // Resolve URL
            $url = $this->resolver->resolve($config['url'] ?? '', $ctx);

            if (empty($url)) {
                return ActionResult::failed('No URL specified for HTTP request');
            }

            $method  = strtoupper($config['method'] ?? 'GET');
            $timeout = (int) ($config['timeout'] ?? 30);
            $retries = (int) ($config['retries'] ?? 0);

            // Build HTTP client
            $http = Http::timeout($timeout);

            // Add retry logic
            if ($retries > 0) {
                $http->retry($retries, 100);
            }

            // Resolve and add headers
            $headers = $this->resolveHeaders($config['headers'] ?? [], $ctx);
            if (! empty($headers)) {
                $http->withHeaders($headers);
            }

            // Handle authentication
            if (! empty($config['auth'])) {
                $http = $this->applyAuth($http, $config['auth'], $ctx);
            }

            // Resolve body
            $body = $this->resolveBody($config['body'] ?? null, $ctx);

            // Make the request
            $response = match ($method) {
                'GET'    => $http->get($url, $body),
                'POST'   => $http->post($url, $body),
                'PUT'    => $http->put($url, $body),
                'PATCH'  => $http->patch($url, $body),
                'DELETE' => $http->delete($url, $body),
                default  => $http->get($url, $body),
            };

            // Check for success
            if ($response->successful()) {
                return ActionResult::success([
                    'status'   => $response->status(),
                    'body'     => $response->json() ?? $response->body(),
                    'headers'  => $response->headers(),
                ]);
            }

            // Handle failure based on config
            $failBehavior = $config['on_failure'] ?? 'fail';

            if ($failBehavior === 'continue') {
                return ActionResult::success([
                    'status'  => $response->status(),
                    'body'    => $response->json() ?? $response->body(),
                    'success' => false,
                ]);
            }

            if ($failBehavior === 'route' && ! empty($config['failure_route'])) {
                return ActionResult::routeTo($config['failure_route'], [
                    'status' => $response->status(),
                    'body'   => $response->json() ?? $response->body(),
                ]);
            }

            return ActionResult::failed(
                "HTTP request failed with status {$response->status()}",
                [
                    'status' => $response->status(),
                    'body'   => $response->json() ?? $response->body(),
                ]
            );
        } catch (\Throwable $e) {
            return ActionResult::failed('HTTP request error: ' . $e->getMessage(), [
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * Resolve headers with placeholders.
     */
    protected function resolveHeaders(array $headers, DynaflowContext $ctx): array
    {
        $resolved = [];

        foreach ($headers as $name => $value) {
            $resolved[$name] = $this->resolver->resolve($value, $ctx);
        }

        return $resolved;
    }

    /**
     * Resolve request body with placeholders.
     */
    protected function resolveBody(mixed $body, DynaflowContext $ctx): mixed
    {
        if ($body === null) {
            return [];
        }

        if (is_string($body)) {
            return $this->resolver->resolve($body, $ctx);
        }

        if (is_array($body)) {
            return $this->resolver->resolveArray($body, $ctx);
        }

        return $body;
    }

    /**
     * Apply authentication to HTTP client.
     */
    protected function applyAuth($http, array $auth, DynaflowContext $ctx)
    {
        $type = $auth['type'] ?? 'bearer';

        return match ($type) {
            'bearer' => $http->withToken($this->resolver->resolve($auth['token'] ?? '', $ctx)),
            'basic'  => $http->withBasicAuth(
                $this->resolver->resolve($auth['username'] ?? '', $ctx),
                $this->resolver->resolve($auth['password'] ?? '', $ctx)
            ),
            'digest' => $http->withDigestAuth(
                $this->resolver->resolve($auth['username'] ?? '', $ctx),
                $this->resolver->resolve($auth['password'] ?? '', $ctx)
            ),
            default => $http,
        };
    }

    public function getConfigSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'url' => [
                    'type'        => 'string',
                    'title'       => 'URL',
                    'description' => 'The request URL (supports placeholders)',
                ],
                'method' => [
                    'type'    => 'string',
                    'title'   => 'Method',
                    'enum'    => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
                    'default' => 'GET',
                ],
                'headers' => [
                    'type'                 => 'object',
                    'title'                => 'Headers',
                    'additionalProperties' => ['type' => 'string'],
                ],
                'body' => [
                    'type'        => 'object',
                    'title'       => 'Request Body',
                    'description' => 'JSON body for POST/PUT/PATCH requests',
                ],
                'timeout' => [
                    'type'        => 'integer',
                    'title'       => 'Timeout',
                    'description' => 'Request timeout in seconds',
                    'default'     => 30,
                ],
                'retries' => [
                    'type'        => 'integer',
                    'title'       => 'Retries',
                    'description' => 'Number of retry attempts on failure',
                    'default'     => 0,
                ],
                'auth' => [
                    'type'       => 'object',
                    'title'      => 'Authentication',
                    'properties' => [
                        'type'     => ['type' => 'string', 'enum' => ['bearer', 'basic', 'digest']],
                        'token'    => ['type' => 'string'],
                        'username' => ['type' => 'string'],
                        'password' => ['type' => 'string'],
                    ],
                ],
                'on_failure' => [
                    'type'        => 'string',
                    'title'       => 'On Failure',
                    'enum'        => ['fail', 'continue', 'route'],
                    'default'     => 'fail',
                    'description' => 'Behavior when request fails',
                ],
                'failure_route' => [
                    'type'        => 'string',
                    'title'       => 'Failure Route',
                    'description' => 'Step to route to on failure (when on_failure=route)',
                ],
            ],
            'required' => ['url'],
        ];
    }

    public function getLabel(): string
    {
        return 'HTTP Request';
    }

    public function getDescription(): string
    {
        return 'Make an HTTP request to an external API with support for authentication and retries.';
    }

    public function getCategory(): string
    {
        return 'integration';
    }

    public function getIcon(): string
    {
        return 'globe';
    }

    public function getOutputSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'status' => [
                    'type'        => 'integer',
                    'description' => 'HTTP response status code',
                ],
                'body' => [
                    'type'        => 'object',
                    'description' => 'Response body (JSON parsed if applicable)',
                ],
                'headers' => [
                    'type'        => 'object',
                    'description' => 'Response headers',
                ],
            ],
        ];
    }
}
