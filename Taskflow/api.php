<?php
declare(strict_types=1);

/*
 * Dashboard state API.
 *
 * index.js uses this file to load and save the TaskFlow data:
 * - GET  api.php?action=state  => returns current tasks, notes, habits, etc.
 * - POST api.php?action=state  => saves the latest browser state to MySQL
 *
 * The app stores one JSON state for easy front-end syncing, then db.php mirrors
 * it into normal tables for phpMyAdmin/admin reports.
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
taskflow_no_cache_headers();
taskflow_start_session();

try {
    // Safe to call every request; it creates missing database/tables only.
    taskflow_install_database();
    if (!taskflow_current_user()) {
        /*
         * API calls come from fetch(), so JSON 401 is clearer than redirecting
         * HTML to login.php.
         */
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => 'Login session required.']);
        exit;
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        // Browser is asking for the latest saved dashboard data.
        echo json_encode(['ok' => true, 'state' => taskflow_load_state()]);
        exit;
    }

    if ($method === 'POST') {
        // Browser is saving the complete latest dashboard state.
        $payload = json_decode(file_get_contents('php://input'), true);
        if (!is_array($payload) || !isset($payload['state']) || !is_array($payload['state'])) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'message' => 'Invalid state payload.']);
            exit;
        }
        taskflow_save_state($payload['state']);
        echo json_encode(['ok' => true]);
        exit;
    }

    // Any method except GET/POST is not used by this app.
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
} catch (Throwable $error) {
    // Return the real PHP/MySQL error so debugging in development is easier.
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => $error->getMessage()]);
}
