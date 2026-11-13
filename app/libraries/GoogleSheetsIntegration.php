<?php

class GoogleSheetsIntegration
{
    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';
    private const API_ROOT = 'https://sheets.googleapis.com/v4/spreadsheets/';
    private const MAX_ROWS_PER_TAB = 5000;

    public function sync(array $tabs)
    {
        $spreadsheetId = trim((string) getenv('GOOGLE_SHEETS_SPREADSHEET_ID'));
        $serviceAccountJson = (string) getenv('GOOGLE_SHEETS_SERVICE_ACCOUNT_JSON');
        if ($spreadsheetId === '' || $serviceAccountJson === '') {
            throw new RuntimeException('Google Sheets is not configured. Add the spreadsheet ID and service account JSON in the backend environment.');
        }
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $spreadsheetId)) {
            throw new RuntimeException('The configured Google Sheets spreadsheet ID is invalid.');
        }

        $account = json_decode($serviceAccountJson, true);
        if (!is_array($account)
            || !filter_var($account['client_email'] ?? '', FILTER_VALIDATE_EMAIL)
            || !is_string($account['private_key'] ?? null)
            || ($account['token_uri'] ?? self::TOKEN_URI) !== self::TOKEN_URI) {
            throw new RuntimeException('The Google Sheets service account configuration is invalid.');
        }

        foreach ($tabs as $tab) {
            if (count($tab['rows'] ?? []) > self::MAX_ROWS_PER_TAB) {
                throw new RuntimeException('A sync tab contains more than 5,000 rows. Choose a smaller date range and try again.');
            }
        }

        $accessToken = $this->accessToken($account);
        $spreadsheetUrl = self::API_ROOT . rawurlencode($spreadsheetId);
        $spreadsheet = $this->apiRequest(
            'GET',
            $spreadsheetUrl . '?fields=' . rawurlencode('sheets(properties(sheetId,title,gridProperties(rowCount,columnCount)))'),
            $accessToken
        );
        $sheetProperties = [];
        foreach ($spreadsheet['sheets'] ?? [] as $sheet) {
            $properties = $sheet['properties'] ?? [];
            if (isset($properties['title'], $properties['sheetId'])) {
                $sheetProperties[$properties['title']] = $properties;
            }
        }

        $addSheetRequests = [];
        foreach ($tabs as $title => $tab) {
            if (!isset($sheetProperties[$title])) {
                $addSheetRequests[] = [
                    'addSheet' => [
                        'properties' => [
                            'title' => $title,
                            'gridProperties' => [
                                'rowCount' => max(1000, count($tab['rows']) + 1),
                                'columnCount' => max(20, count($tab['headers']))
                            ]
                        ]
                    ]
                ];
            }
        }
        if ($addSheetRequests) {
            $this->apiRequest('POST', $spreadsheetUrl . ':batchUpdate', $accessToken, [
                'requests' => $addSheetRequests
            ]);
            $spreadsheet = $this->apiRequest(
                'GET',
                $spreadsheetUrl . '?fields=' . rawurlencode('sheets(properties(sheetId,title,gridProperties(rowCount,columnCount)))'),
                $accessToken
            );
            foreach ($spreadsheet['sheets'] ?? [] as $sheet) {
                $properties = $sheet['properties'] ?? [];
                if (isset($properties['title'], $properties['sheetId'])) {
                    $sheetProperties[$properties['title']] = $properties;
                }
            }
        }

        $requests = [];
        foreach ($tabs as $title => $tab) {
            if (!isset($sheetProperties[$title])) {
                throw new RuntimeException('Google Sheets did not create the required sync tabs.');
            }
            $properties = $sheetProperties[$title];
            $sheetId = (int) $properties['sheetId'];
            $grid = $properties['gridProperties'] ?? [];
            $rowCount = max((int) ($grid['rowCount'] ?? 1000), count($tab['rows']) + 1);
            $columnCount = max((int) ($grid['columnCount'] ?? 20), count($tab['headers']));

            $requests[] = [
                'updateSheetProperties' => [
                    'properties' => [
                        'sheetId' => $sheetId,
                        'gridProperties' => [
                            'rowCount' => $rowCount,
                            'columnCount' => $columnCount
                        ]
                    ],
                    'fields' => 'gridProperties.rowCount,gridProperties.columnCount'
                ]
            ];
            $requests[] = [
                'repeatCell' => [
                    'range' => [
                        'sheetId' => $sheetId,
                        'startRowIndex' => 0,
                        'endRowIndex' => $rowCount,
                        'startColumnIndex' => 0,
                        'endColumnIndex' => $columnCount
                    ],
                    'cell' => (object) [],
                    'fields' => 'userEnteredValue'
                ]
            ];
            $rows = array_merge([$tab['headers']], $tab['rows']);
            $requests[] = [
                'updateCells' => [
                    'start' => ['sheetId' => $sheetId, 'rowIndex' => 0, 'columnIndex' => 0],
                    'rows' => array_map(function ($row) {
                        return [
                            'values' => array_map(function ($value) {
                                if (is_int($value) || is_float($value)) {
                                    return ['userEnteredValue' => ['numberValue' => $value]];
                                }
                                if ($value === null) return ['userEnteredValue' => ['stringValue' => '']];
                                return ['userEnteredValue' => ['stringValue' => (string) $value]];
                            }, $row)
                        ];
                    }, $rows),
                    'fields' => 'userEnteredValue'
                ]
            ];
        }

        $this->apiRequest('POST', $spreadsheetUrl . ':batchUpdate', $accessToken, [
            'requests' => $requests
        ]);

        $counts = [];
        foreach ($tabs as $title => $tab) {
            $counts[$title] = count($tab['rows']);
        }
        return $counts;
    }

    private function accessToken(array $account)
    {
        $now = time();
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $this->base64UrlEncode(json_encode([
            'iss' => $account['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets',
            'aud' => self::TOKEN_URI,
            'iat' => $now,
            'exp' => $now + 3600
        ], JSON_THROW_ON_ERROR));
        $unsignedToken = $header . '.' . $claims;
        $privateKey = @openssl_pkey_get_private($account['private_key']);
        if ($privateKey === false) {
            throw new RuntimeException('The Google Sheets service account private key is invalid.');
        }
        $signature = '';
        $signed = @openssl_sign($unsignedToken, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (is_resource($privateKey)) openssl_free_key($privateKey);
        if (!$signed) throw new RuntimeException('Could not sign the Google Sheets service account request.');

        $response = $this->httpRequest(self::TOKEN_URI, [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
            'content' => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $unsignedToken . '.' . $this->base64UrlEncode($signature)
            ]),
            'timeout' => 30,
            'ignore_errors' => true
        ]);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException('Google could not authorize the Sheets service account. Check its credentials and API access.');
        }
        $token = json_decode($response['body'], true);
        if (!is_array($token) || !is_string($token['access_token'] ?? null) || $token['access_token'] === '') {
            throw new RuntimeException('Google returned an invalid Sheets access token response.');
        }
        return $token['access_token'];
    }

    private function apiRequest($method, $url, $accessToken, ?array $payload = null)
    {
        $options = [
            'method' => $method,
            'header' => 'Authorization: ' . 'Bearer ' . $accessToken . "\r\nAccept: application/json\r\n",
            'timeout' => 30,
            'ignore_errors' => true
        ];
        if ($payload !== null) {
            $options['header'] .= "Content-Type: application/json\r\n";
            $options['content'] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        $response = $this->httpRequest($url, $options);
        $decoded = json_decode($response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? '') : '';
            if (!is_string($message) || $message === '') {
                $message = 'Google Sheets API request failed with HTTP ' . $response['status'] . '.';
            }
            throw new RuntimeException($message);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Google Sheets returned an invalid response.');
        }
        return $decoded;
    }

    private function httpRequest($url, array $options)
    {
        $context = stream_context_create(['http' => $options]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches)) {
                $status = (int) $matches[1];
            }
        }
        if ($body === false) {
            throw new RuntimeException('Could not connect to Google Sheets. Check the backend outbound network and try again.');
        }
        return ['status' => $status, 'body' => $body];
    }

    private function base64UrlEncode($value)
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
