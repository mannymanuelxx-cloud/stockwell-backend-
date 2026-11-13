<?php
define('PREVENT_DIRECT_ACCESS', true);
require_once __DIR__ . '/../app/libraries/GoogleSheetsIntegration.php';

$originalSpreadsheetId = getenv('GOOGLE_SHEETS_SPREADSHEET_ID');
$originalServiceAccount = getenv('GOOGLE_SHEETS_SERVICE_ACCOUNT_JSON');
try {
    putenv('GOOGLE_SHEETS_SPREADSHEET_ID=invalid/id');
    putenv('GOOGLE_SHEETS_SERVICE_ACCOUNT_JSON={"client_email":"sheets@example.com","private_key":"not-a-key","token_uri":"https://oauth2.googleapis.com/token"}');
    assertSyncRejected('The configured Google Sheets spreadsheet ID is invalid.');

    putenv('GOOGLE_SHEETS_SPREADSHEET_ID=valid_sheet_id');
    putenv('GOOGLE_SHEETS_SERVICE_ACCOUNT_JSON={"client_email":"sheets@example.com"}');
    assertSyncRejected('The Google Sheets service account configuration is invalid.');
} finally {
    putenv($originalSpreadsheetId === false ? 'GOOGLE_SHEETS_SPREADSHEET_ID' : 'GOOGLE_SHEETS_SPREADSHEET_ID=' . $originalSpreadsheetId);
    putenv($originalServiceAccount === false ? 'GOOGLE_SHEETS_SERVICE_ACCOUNT_JSON' : 'GOOGLE_SHEETS_SERVICE_ACCOUNT_JSON=' . $originalServiceAccount);
}

function assertSyncRejected($expectedMessage)
{
    try {
        (new GoogleSheetsIntegration())->sync([]);
    } catch (RuntimeException $error) {
        if ($error->getMessage() === $expectedMessage) return;
        throw $error;
    }
    throw new RuntimeException('Expected sync to fail with: ' . $expectedMessage);
}

echo "Google Sheets integration configuration tests passed.\n";
