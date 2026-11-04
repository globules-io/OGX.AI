<?php

/**
 * OGX.AI MCP endpoint (Streamable HTTP, JSON-RPC 2.0).
 * Copyright (C) 2026 globules.io
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Clients POST to /mcp. This server does not open an SSE stream and does not issue a session id.
 * Requires PHP 8.0+.
 */

declare(strict_types=1);

const SERVER_NAME = 'ogx-oml';
const SERVER_VERSION = '1.0.0';
const LATEST_PROTOCOL_VERSION = '2025-11-25';
const SUPPORTED_PROTOCOL_VERSIONS = [
    '2025-03-26',
    '2025-06-18',
    '2025-11-25',
];
const SERVER_INSTRUCTIONS = 'You generate OML for OGX.JS. Before writing any OML, call read_oml for overview, then specification, then components, then examples. Use only constructs those documents define. OML is the declarative node. Methods and runtime fields are not OML keys. When you need a method, call read_runtime with the class name. inherited defaults to true and includes documented base classes. If catalog_status says own methods are not listed yet, do not invent members. Helpers Color, Data, Math, and Form are static JavaScript, not OML nodes. Call read_oml with name validator-rules for the checks generated OML must satisfy. This server does not generate OML.';

ini_set('display_errors', '0');

set_exception_handler(static function (Throwable $e): void {
    error_log('ogx-oml mcp: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Internal error"}}';
    exit;
});

send_cors_headers();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    header('Allow: POST, OPTIONS');
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || $raw === '') {
    emit(rpc_error(null, -32700, 'Parse error'), 400);
    exit;
}

$decoded = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    emit(rpc_error(null, -32700, 'Parse error'), 400);
    exit;
}

if (!is_array($decoded) || $decoded === []) {
    emit(rpc_error(null, -32600, 'Invalid Request'), 400);
    exit;
}

$batch = is_batch($decoded);
$messages = $batch ? $decoded : [$decoded];

if ($batch) {
    foreach ($messages as $message) {
        if (is_array($message) && (($message['method'] ?? null) === 'initialize')) {
            emit(rpc_error(null, -32600, 'Invalid Request'), 400);
            exit;
        }
    }
}

$responses = [];
foreach ($messages as $message) {
    $response = dispatch_message($message);
    if ($response !== null) {
        $responses[] = $response;
    }
}

if ($responses === []) {
    header('Cache-Control: no-store');
    http_response_code(202);
    exit;
}

emit($batch ? $responses : $responses[0], 200);
exit;

function resource_catalog(): array
{
    $dir = __DIR__ . DIRECTORY_SEPARATOR . 'resources';

    return [
        'oml://overview' => [
            'name' => 'overview',
            'description' => 'General information about OML and OGX.JS.',
            'mimeType' => 'application/json',
            'file' => $dir . DIRECTORY_SEPARATOR . 'overview.json',
        ],
        'oml://specification' => [
            'name' => 'specification',
            'description' => 'OML syntax, rules, and document structure.',
            'mimeType' => 'application/json',
            'file' => $dir . DIRECTORY_SEPARATOR . 'specs.json',
        ],
        'oml://components' => [
            'name' => 'components',
            'description' => 'OML components, properties, datatypes, and constraints.',
            'mimeType' => 'application/json',
            'file' => $dir . DIRECTORY_SEPARATOR . 'components.json',
        ],
        'oml://helpers' => [
            'name' => 'helpers',
            'description' => 'OGX.JS Javascript Helpers',
            'mimeType' => 'application/json',
            'file' => $dir . DIRECTORY_SEPARATOR . 'helpers.json',
        ],
        'oml://examples' => [
            'name' => 'examples',
            'description' => 'Reference OML examples.',
            'mimeType' => 'application/json',
            'file' => $dir . DIRECTORY_SEPARATOR . 'examples.json',
        ],
        'oml://runtime' => [
            'name' => 'runtime',
            'description' => 'Index of OGX.JS runtime objects. Methods and properties here are not OML keys.',
            'mimeType' => 'application/json',
            'file' => $dir . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'index.json',
        ],
        'oml://validator-rules' => [
            'name' => 'validator-rules',
            'description' => 'Validation rules for generated OML.',
            'mimeType' => 'application/json',
            'file' => $dir . DIRECTORY_SEPARATOR . 'validator-rules.json',
        ],
    ];
}

function dispatch_message($message): ?array
{
    if (!is_array($message)) {
        return rpc_error(null, -32600, 'Invalid Request');
    }

    $hasId = array_key_exists('id', $message) && $message['id'] !== null;
    $id = $hasId ? $message['id'] : null;

    if (!isset($message['method'])) {
        if (isset($message['result']) || isset($message['error'])) {
            return null;
        }
        if (!$hasId) {
            return null;
        }
        return rpc_error($id, -32600, 'Invalid Request');
    }

    if (!isset($message['jsonrpc']) || $message['jsonrpc'] !== '2.0' || !is_string($message['method'])) {
        if (!$hasId) {
            return null;
        }
        return rpc_error($id, -32600, 'Invalid Request');
    }

    // A notification has no id. The server does not reply.
    if (!$hasId) {
        return null;
    }

    $params = normalize_params($message['params'] ?? []);
    if ($params === null) {
        return rpc_error($id, -32602, 'Invalid params');
    }

    switch ($message['method']) {
        case 'initialize':
            return handle_initialize($id, $params);
        case 'ping':
            return rpc_result($id, new stdClass());
        case 'resources/list':
            return handle_resources_list($id);
        case 'resources/read':
            return handle_resources_read($id, $params);
        case 'resources/templates/list':
            return rpc_result($id, [
                'resourceTemplates' => [runtime_template()],
            ]);
        case 'tools/list':
            return handle_tools_list($id);
        case 'tools/call':
            return handle_tools_call($id, $params);
        default:
            return rpc_error($id, -32601, 'Method not found');
    }
}

function handle_initialize($id, array $params): array
{
    $requested = $params['protocolVersion'] ?? null;
    $version = is_string($requested) && in_array($requested, SUPPORTED_PROTOCOL_VERSIONS, true)
        ? $requested
        : LATEST_PROTOCOL_VERSION;

    return rpc_result($id, [
        'protocolVersion' => $version,
        'capabilities' => [
            'resources' => new stdClass(),
            'tools' => new stdClass(),
        ],
        'serverInfo' => [
            'name' => SERVER_NAME,
            'version' => SERVER_VERSION,
        ],
        'instructions' => SERVER_INSTRUCTIONS,
    ]);
}

function handle_resources_list($id): array
{
    $resources = [];
    foreach (resource_catalog() as $uri => $meta) {
        $resources[] = [
            'uri' => $uri,
            'name' => $meta['name'],
            'description' => $meta['description'],
            'mimeType' => $meta['mimeType'],
        ];
    }

    return rpc_result($id, ['resources' => $resources]);
}

function handle_resources_read($id, array $params): array
{
    $uri = $params['uri'] ?? null;
    if (!is_string($uri) || $uri === '') {
        return rpc_error($id, -32602, 'Invalid params', 'uri is required');
    }

    $runtimeName = runtime_name_from_uri($uri);
    if ($runtimeName !== null) {
        $resolved = resolve_runtime($runtimeName, true);
        if ($resolved === null) {
            return rpc_error($id, -32002, 'Resource not found', ['uri' => $uri]);
        }
        $text = json_encode($resolved, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($text === false) {
            return rpc_error($id, -32603, 'Internal error');
        }
        return rpc_result($id, [
            'contents' => [
                [
                    'uri' => $uri,
                    'mimeType' => 'application/json',
                    'text' => $text,
                ],
            ],
        ]);
    }

    $catalog = resource_catalog();
    if (!isset($catalog[$uri])) {
        return rpc_error($id, -32002, 'Resource not found', ['uri' => $uri]);
    }

    $loaded = load_resource($catalog[$uri]);
    if ($loaded === null) {
        return rpc_error($id, -32603, 'Internal error');
    }

    return rpc_result($id, [
        'contents' => [
            [
                'uri' => $uri,
                'mimeType' => $loaded['mimeType'],
                'text' => $loaded['text'],
            ],
        ],
    ]);
}

function handle_tools_list($id): array
{
    return rpc_result($id, [
        'tools' => [read_oml_tool(), read_runtime_tool()],
    ]);
}

function handle_tools_call($id, array $params): array
{
    $name = $params['name'] ?? null;
    if (!is_string($name) || $name === '') {
        return rpc_error($id, -32602, 'Invalid params', 'Tool name is required');
    }

    if ($name !== 'read_oml' && $name !== 'read_runtime') {
        return rpc_error($id, -32602, 'Unknown tool: ' . $name);
    }

    $arguments = $params['arguments'] ?? [];
    if (!is_array($arguments) || is_json_list($arguments)) {
        return rpc_error($id, -32602, 'Invalid params', 'Tool arguments must be an object');
    }

    if ($name === 'read_runtime') {
        return call_read_runtime($id, $arguments);
    }

    $document = $arguments['name'] ?? null;
    if (!is_string($document) || $document === '') {
        return rpc_error($id, -32602, 'Invalid params', 'name is required');
    }

    foreach (resource_catalog() as $meta) {
        if ($meta['name'] !== $document) {
            continue;
        }
        $loaded = load_resource($meta);
        if ($loaded === null) {
            return rpc_result($id, [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Backing file not found',
                    ],
                ],
                'isError' => true,
            ]);
        }
        return rpc_result($id, [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $loaded['text'],
                ],
            ],
            'isError' => false,
        ]);
    }

    return rpc_error($id, -32602, 'Invalid params', 'Unknown document name');
}

function read_oml_tool(): array
{
    $names = [];
    foreach (resource_catalog() as $meta) {
        $names[] = $meta['name'];
    }

    return [
        'name' => 'read_oml',
        'description' => 'Read one OML reference document before generating OML. Call it for overview, specification, components, helpers, examples, and runtime.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'enum' => $names,
                    'description' => 'Reference document to read.',
                ],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ],
    ];
}

function read_runtime_tool(): array
{
    return [
        'name' => 'read_runtime',
        'description' => 'Read runtime properties, methods, and events for one OGX.JS class or helper. These names are not OML attributes. inherited defaults to true and includes documented base classes.',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Class or helper name, for example DynamicList, Uxi, or Color.',
                ],
                'inherited' => [
                    'type' => 'boolean',
                    'description' => 'Include properties, methods, and events from extends. Defaults to true.',
                ],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ],
    ];
}

function call_read_runtime($id, array $arguments): array
{
    $name = $arguments['name'] ?? null;
    if (!is_string($name) || $name === '') {
        return rpc_error($id, -32602, 'Invalid params', 'name is required');
    }

    $inherited = true;
    if (array_key_exists('inherited', $arguments)) {
        if (!is_bool($arguments['inherited'])) {
            return rpc_error($id, -32602, 'Invalid params', 'inherited must be a boolean');
        }
        $inherited = $arguments['inherited'];
    }

    $resolved = resolve_runtime($name, $inherited);
    if ($resolved === null) {
        return rpc_error($id, -32602, 'Invalid params', 'Unknown runtime object');
    }

    $text = json_encode($resolved, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($text === false) {
        return rpc_error($id, -32603, 'Internal error');
    }

    return rpc_result($id, [
        'content' => [
            [
                'type' => 'text',
                'text' => $text,
            ],
        ],
        'isError' => false,
    ]);
}

function runtime_template(): array
{
    return [
        'uriTemplate' => 'oml://runtime/{name}',
        'name' => 'runtime',
        'description' => 'Runtime properties, methods, and events for one OGX.JS object. These are not OML attributes.',
        'mimeType' => 'application/json',
    ];
}

function runtime_name_from_uri(string $uri): ?string
{
    if (!preg_match('#^oml://runtime/([A-Za-z][A-Za-z0-9]*)$#', $uri, $matches)) {
        return null;
    }
    return $matches[1];
}

function runtime_index(): array
{
    $file = __DIR__ . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'index.json';
    $decoded = json_decode((string) file_get_contents($file), true);
    if (!is_array($decoded) || !isset($decoded['objects']) || !is_array($decoded['objects'])) {
        return [];
    }
    $names = [];
    foreach ($decoded['objects'] as $object) {
        if (is_array($object) && isset($object['name']) && is_string($object['name'])) {
            $names[$object['name']] = true;
        }
    }
    return $names;
}

function load_runtime_document(string $name): ?array
{
    if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $name)) {
        return null;
    }
    if (!isset(runtime_index()[$name])) {
        return null;
    }
    $file = __DIR__ . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . $name . '.json';
    if (!is_file($file)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents($file), true);
    if (!is_array($decoded)) {
        return null;
    }
    return $decoded;
}

function resolve_runtime(string $name, bool $inherited): ?array
{
    $doc = load_runtime_document($name);
    if ($doc === null) {
        return null;
    }
    if (!$inherited) {
        return $doc;
    }

    $order = [];
    $visiting = [];
    $walk = static function (string $current) use (&$walk, &$order, &$visiting): void {
        if (array_key_exists($current, $order) || isset($visiting[$current])) {
            return;
        }
        $visiting[$current] = true;
        $raw = load_runtime_document($current);
        if ($raw !== null) {
            $bases = $raw['extends'] ?? [];
            if (is_array($bases)) {
                foreach ($bases as $base) {
                    if (is_string($base)) {
                        $walk($base);
                    }
                }
            }
        }
        unset($visiting[$current]);
        $order[$current] = $raw;
    };
    $walk($name);

    $merged = [
        'properties' => [],
        'methods' => [],
        'events' => [],
    ];
    $missing = [];
    foreach ($order as $class => $raw) {
        if ($raw === null) {
            $missing[] = $class;
            continue;
        }
        foreach (['properties', 'methods', 'events'] as $key) {
            $items = $raw[$key] ?? [];
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $item) {
                if (!is_array($item) || !isset($item['name']) || !is_string($item['name'])) {
                    continue;
                }
                $item['declared_on'] = $class;
                $merged[$key][$item['name']] = $item;
            }
        }
    }

    $doc['properties'] = array_values($merged['properties']);
    $doc['methods'] = array_values($merged['methods']);
    $doc['events'] = array_values($merged['events']);
    if ($missing !== []) {
        $doc['extends_not_in_catalog'] = array_values(array_unique($missing));
    }
    return $doc;
}

function load_resource(array $meta): ?array
{
    $file = $meta['file'];
    if (!is_file($file)) {
        return null;
    }

    $text = file_get_contents($file);
    if ($text === false) {
        return null;
    }

    if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) {
        $text = substr($text, 3);
    }

    return [
        'mimeType' => $meta['mimeType'],
        'text' => $text,
    ];
}

/**
 * JSON objects become associative arrays. A JSON array is a list.
 * An empty array is an empty object, because json_decode('{}', true) is [].
 */
function normalize_params($params): ?array
{
    if (!is_array($params) || is_json_list($params)) {
        return null;
    }
    return $params;
}

function is_batch(array $decoded): bool
{
    return is_json_list($decoded);
}

function is_json_list(array $value): bool
{
    if ($value === []) {
        return false;
    }
    return array_keys($value) === range(0, count($value) - 1);
}

function rpc_result($id, $result): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => $result,
    ];
}

function rpc_error($id, int $code, string $message, $data = null): array
{
    $error = [
        'code' => $code,
        'message' => $message,
    ];
    if ($data !== null) {
        $error['data'] = $data;
    }
    return [
        'jsonrpc' => '2.0',
        'id' => $id,
        'error' => $error,
    ];
}

function emit($payload, int $status): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        http_response_code(500);
        echo '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Internal error"}}';
        return;
    }
    echo $json;
}

function send_cors_headers(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization, MCP-Protocol-Version, Mcp-Session-Id, Last-Event-ID');
    header('Access-Control-Expose-Headers: MCP-Protocol-Version, Mcp-Session-Id');
    header('Access-Control-Max-Age: 86400');
}
