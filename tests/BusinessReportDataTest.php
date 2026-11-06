<?php
define('PREVENT_DIRECT_ACCESS', true);

class Model
{
    public $db;
}

class ReportTestStatement
{
    public function fetch()
    {
        return [];
    }

    public function fetchAll()
    {
        return [];
    }
}

class ReportTestDatabase
{
    public $queries = [];

    public function raw($sql, $bindings = [])
    {
        $this->queries[] = $sql;
        return new ReportTestStatement();
    }
}

require_once __DIR__ . '/../app/models/BusinessModel.php';

$database = new ReportTestDatabase();
$model = new BusinessModel();
$model->db = $database;
$model->reportData('2026-11-01', '2026-11-30');

$reportQueries = array_slice($database->queries, 0, 7);
$completionDateQueries = array_filter($reportQueries, function ($query) {
    return strpos($query, 'completed_at') !== false;
});
if (count($completionDateQueries) < 5) {
    fwrite(STDERR, "FAIL: completed online orders use their completion date in all sales reports\n");
    exit(1);
}
echo "PASS: completed online orders use their completion date in all sales reports\n";

$model->orderNotificationInfo(42);
$notificationQuery = end($database->queries);
if (strpos($notificationQuery, "COALESCE(NULLIF(TRIM(c.email), ''), u.email)") === false
    || strpos($notificationQuery, 'LEFT JOIN users u ON u.id = c.user_id') === false) {
    fwrite(STDERR, "FAIL: order notifications fall back to the customer's account email\n");
    exit(1);
}
echo "PASS: order notifications fall back to the customer's account email\n";
