<?php

function workoutx_api_key()
{
    return defined('WORKOUTX_API_KEY') ? trim((string) WORKOUTX_API_KEY) : '';
}

function workoutx_available()
{
    return workoutx_api_key() !== '';
}

function workoutx_normalize_text($value)
{
    $value = strtolower(trim((string) $value));
    $value = str_replace(['_', '-', '/'], ' ', $value);
    $value = preg_replace('/[^a-z0-9 ]+/i', '', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return trim($value);
}

function workoutx_request($endpoint, array $params = [])
{
    $api_key = workoutx_api_key();
    if ($api_key === '') {
        return ['success' => false, 'error' => 'WorkoutX no esta configurado.'];
    }

    $endpoint = '/' . ltrim($endpoint, '/');
    $url = 'https://api.workoutxapp.com/v1' . $endpoint;
    if ($params) {
        $url .= '?' . http_build_query($params);
    }

    $headers = [];
    $body = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'X-WorkoutX-Key: ' . $api_key
            ],
            CURLOPT_HEADERFUNCTION => static function ($curl, $header) use (&$headers) {
                $length = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $length;
            }
        ]);
        $body = curl_exec($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        if ($body === false || $curl_error !== '') {
            return ['success' => false, 'error' => 'No se pudo conectar con WorkoutX.'];
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 12,
                'header' => "Accept: application/json\r\nX-WorkoutX-Key: {$api_key}\r\n"
            ]
        ]);
        $body = @file_get_contents($url, false, $context);
        $http_code = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                    $http_code = (int) $m[1];
                } elseif (strpos($line, ':') !== false) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
            }
        }
        if ($body === false) {
            return ['success' => false, 'error' => 'No se pudo conectar con WorkoutX.'];
        }
    }

    $data = json_decode((string) $body, true);
    if ($http_code < 200 || $http_code >= 300) {
        $message = is_array($data) ? ($data['message'] ?? $data['error'] ?? '') : '';
        return [
            'success' => false,
            'error' => $message !== '' ? $message : 'WorkoutX devolvio un error.',
            'status' => $http_code,
            'headers' => workoutx_usage_headers($headers)
        ];
    }

    return [
        'success' => true,
        'data' => $data,
        'status' => $http_code,
        'headers' => workoutx_usage_headers($headers)
    ];
}

function workoutx_fetch_gif($external_id)
{
    $api_key = workoutx_api_key();
    $external_id = trim((string) $external_id);
    if ($api_key === '' || $external_id === '') {
        return ['success' => false, 'error' => 'WorkoutX no esta configurado.'];
    }
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $external_id)) {
        return ['success' => false, 'error' => 'Identificador de WorkoutX no valido.'];
    }

    $url = 'https://api.workoutxapp.com/v1/gifs/' . rawurlencode($external_id) . '.gif';
    $body = '';
    $content_type = 'image/gif';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => [
                'Accept: image/gif,image/*,*/*',
                'X-WorkoutX-Key: ' . $api_key
            ],
            CURLOPT_HEADERFUNCTION => static function ($curl, $header) use (&$content_type) {
                $length = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2 && strtolower(trim($parts[0])) === 'content-type') {
                    $content_type = trim($parts[1]);
                }
                return $length;
            }
        ]);
        $body = curl_exec($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        if ($body === false || $curl_error !== '') {
            return ['success' => false, 'error' => 'No se pudo conectar con WorkoutX.'];
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 20,
                'header' => "Accept: image/gif,image/*,*/*\r\nX-WorkoutX-Key: {$api_key}\r\n",
                'ignore_errors' => true
            ]
        ]);
        $body = @file_get_contents($url, false, $context);
        $http_code = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                    $http_code = (int) $m[1];
                } elseif (stripos($line, 'Content-Type:') === 0) {
                    $content_type = trim(substr($line, 13));
                }
            }
        }
        if ($body === false) {
            return ['success' => false, 'error' => 'No se pudo conectar con WorkoutX.'];
        }
    }

    if ($http_code < 200 || $http_code >= 300) {
        return ['success' => false, 'error' => 'WorkoutX no devolvio el GIF.', 'status' => $http_code];
    }

    return [
        'success' => true,
        'body' => $body,
        'content_type' => $content_type !== '' ? $content_type : 'image/gif'
    ];
}

function workoutx_usage_headers(array $headers)
{
    return [
        'plan' => $headers['x-workoutx-plan'] ?? '',
        'rate_limit' => $headers['x-ratelimit-limit'] ?? '',
        'rate_remaining' => $headers['x-ratelimit-remaining'] ?? '',
        'quota_limit' => $headers['x-quota-limit'] ?? '',
        'quota_remaining' => $headers['x-quota-remaining'] ?? '',
        'quota_reset' => $headers['x-quota-reset'] ?? ''
    ];
}

function workoutx_exercise_by_id($external_id)
{
    $external_id = trim((string) $external_id);
    if ($external_id === '') {
        return ['success' => false, 'error' => 'Falta el identificador de WorkoutX.'];
    }
    return workoutx_request('/exercises/exercise/' . rawurlencode($external_id));
}

function workoutx_search_exercises_by_name($name, $limit = 10)
{
    $name = trim((string) $name);
    if ($name === '') {
        return ['success' => false, 'error' => 'Falta el nombre del ejercicio.'];
    }
    return workoutx_request('/exercises', [
        'name' => $name,
        'limit' => max(1, min(500, (int) $limit))
    ]);
}

function workoutx_list_exercises($limit = 10, $offset = 0, array $filters = [])
{
    $params = array_merge($filters, [
        'limit' => max(1, min(500, (int) $limit)),
        'offset' => max(0, (int) $offset)
    ]);
    return workoutx_request('/exercises', $params);
}

function workoutx_generate_workout(array $params = [])
{
    $allowed = ['goal', 'duration', 'level', 'split', 'equipment', 'bodyFocus', 'exclude', 'seed'];
    $query = [];
    foreach ($allowed as $key) {
        if (isset($params[$key]) && trim((string) $params[$key]) !== '') {
            $query[$key] = trim((string) $params[$key]);
        }
    }
    return workoutx_request('/workout/generate', $query);
}

function workoutx_extract_exercises($data)
{
    if (!is_array($data)) {
        return [];
    }

    $candidate_keys = ['exercises', 'results', 'items', 'data'];
    foreach ($candidate_keys as $key) {
        if (isset($data[$key]) && is_array($data[$key])) {
            return workoutx_extract_exercises($data[$key]);
        }
    }

    $list = [];
    foreach ($data as $item) {
        if (is_array($item)) {
            $list[] = $item;
        }
    }
    return $list;
}

function workoutx_pick_best_exercise_match(array $candidates, array $names)
{
    $candidates = workoutx_extract_exercises($candidates);
    $normalized_names = array_values(array_unique(array_filter(array_map('workoutx_normalize_text', $names))));
    if (!$normalized_names || !$candidates) {
        return null;
    }

    $best = null;
    $best_score = -1;
    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        $candidate_name = workoutx_normalize_text($candidate['name'] ?? '');
        if ($candidate_name === '') {
            continue;
        }
        $score = 0;
        foreach ($normalized_names as $name) {
            if ($candidate_name === $name) {
                $score = max($score, 100);
            } elseif (strpos($candidate_name, $name) !== false || strpos($name, $candidate_name) !== false) {
                $score = max($score, 75);
            } else {
                similar_text($candidate_name, $name, $percent);
                $score = max($score, (int) round($percent));
            }
        }
        if ($score > $best_score) {
            $best = $candidate;
            $best_score = $score;
        }
    }

    return $best_score >= 55 ? $best : null;
}

function workoutx_match_exercise(array $local_exercise)
{
    if (!empty($local_exercise['external_id']) && ($local_exercise['external_source'] ?? '') === 'workoutx') {
        $by_id = workoutx_exercise_by_id($local_exercise['external_id']);
        if (!empty($by_id['success'])) {
            return $by_id;
        }
    }

    $names = [];
    foreach (['name_en', 'name_es'] as $field) {
        if (!empty($local_exercise[$field])) {
            $names[] = $local_exercise[$field];
        }
    }
    if (!empty($local_exercise['aliases'])) {
        foreach (preg_split('/[,;|]+/', (string) $local_exercise['aliases']) as $alias) {
            if (trim($alias) !== '') {
                $names[] = trim($alias);
            }
        }
    }

    foreach ($names as $name) {
        $search = workoutx_search_exercises_by_name($name, 10);
        if (empty($search['success']) || !is_array($search['data'] ?? null)) {
            continue;
        }
        $best = workoutx_pick_best_exercise_match($search['data'], $names);
        if ($best) {
            return [
                'success' => true,
                'data' => $best,
                'headers' => $search['headers'] ?? [],
                'matched_by' => $name
            ];
        }
    }

    return ['success' => false, 'error' => 'No se encontro un GIF asociado en WorkoutX.'];
}
