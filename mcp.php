<?php

header('Content-Type: application/json');

$resources = [
     'oml://overview'   => __DIR__ . '/resources/overview.json',
     'oml://specs'      => __DIR__ . '/resources/specs.json',
     'oml://components' => __DIR__ . '/resources/components.json',
     'oml://examples'   => __DIR__ . '/resources/examples.json',
];

$request = json_decode(file_get_contents('php://input'), true);

if (!is_array($request)) {
     http_response_code(400);
     echo json_encode([
          'error' => 'Invalid JSON request'
     ]);
     exit;
}

$method = $request['method'] ?? '';

switch ($method) {

     case 'resources/list':

     $list = [];

     foreach ($resources as $uri => $file) {
          $list[] = [
               'uri'  => $uri,
               'name' => basename($uri)
          ];
     }

     echo json_encode([
          'resources' => $list
     ], JSON_PRETTY_PRINT);

     break;

     case 'resources/read':

     $uri = $request['params']['uri'] ?? '';

     if (!isset($resources[$uri])) {
          http_response_code(404);
          echo json_encode([
               'error' => 'Resource not found'
          ]);
          exit;
     }

     $file = $resources[$uri];

     if (!file_exists($file)) {
          http_response_code(404);
          echo json_encode([
               'error' => 'Backing file not found'
          ]);
          exit;
     }

     echo json_encode([
          'uri'     => $uri,
          'content' => json_decode(file_get_contents($file), true)
     ], JSON_PRETTY_PRINT);

     break;

     case 'tools/list':

     echo json_encode([
          'tools' => [
               [
               'name' => 'validate_oml',
               'description' => 'Validate OML content'
               ]
          ]
     ], JSON_PRETTY_PRINT);

     break;

     case 'tools/call':

     $tool = $request['params']['name'] ?? '';

     if ($tool !== 'validate_oml') {
          http_response_code(404);
          echo json_encode([
               'error' => 'Tool not found'
          ]);
          exit;
     }

     // Placeholder
     echo json_encode([
          'valid' => false,
          'errors' => [
               'validate_oml() not implemented yet'
          ]
     ], JSON_PRETTY_PRINT);

     break;

     default:

     http_response_code(400);

     echo json_encode([
          'error' => 'Unknown method',
          'supported_methods' => [
               'resources/list',
               'resources/read',
               'tools/list',
               'tools/call'
          ]
     ], JSON_PRETTY_PRINT);
}