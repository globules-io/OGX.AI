<?php

/**
 * Stateless MCP endpoint (Streamable HTTP, JSON-RPC 2.0).
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
const SERVER_INSTRUCTIONS = 'You generate OML for OGX.JS. Before writing any OML, call read_oml with name set to overview, then specification, then components, then examples. Use only constructs those documents define. Call read_oml with name validator-rules for the checks generated OML must satisfy. This server does not generate OML.';

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
        'oml://examples' => [
            'name' => 'examples',
            'description' => 'Reference OML examples.',
            'mimeType' => 'application/json',
            'file' => $dir . DIRECTORY_SEPARATOR . 'examples.json',
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
            return rpc_result($id, ['resourceTemplates' => []]);
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
        'tools' => [read_oml_tool()],
    ]);
}

function handle_tools_call($id, array $params): array
{
    $name = $params['name'] ?? null;
    if (!is_string($name) || $name === '') {
        return rpc_error($id, -32602, 'Invalid params', 'Tool name is required');
    }

    if ($name !== 'read_oml') {
        return rpc_error($id, -32602, 'Unknown tool: ' . $name);
    }

    $arguments = $params['arguments'] ?? [];
    if (!is_array($arguments) || is_json_list($arguments)) {
        return rpc_error($id, -32602, 'Invalid params', 'Tool arguments must be an object');
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
        'description' => 'Read one OML reference document before generating OML. Call it for overview, specification, components, and examples.',
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
