<?php
declare(strict_types=1);

header('Content-Type: application/json');
http_response_code(404);
echo json_encode(['error' => 'api_router_not_implemented']);
