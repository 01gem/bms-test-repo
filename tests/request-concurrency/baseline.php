<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Deterministically reproduce the old read-then-write SQL, with separate connections.
// Run before replacing the old endpoints. No application tables are modified.
require __DIR__ . '/../../db.php';
$name = 'capstone_requests_baseline_' . getmypid();
$admin = new mysqli($DB_HOST, $DB_USER, $DB_PASS);
$admin->query("CREATE DATABASE `$name`");
try {
    $a = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $name);
    $b = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $name);
    $a->query('CREATE TABLE document_requests (id INT PRIMARY KEY, status VARCHAR(30), payment_status VARCHAR(30), date_completed DATETIME NULL) ENGINE=InnoDB');
    $a->query("INSERT INTO document_requests VALUES (1,'processing','unpaid',NULL)");
    // Both original callers read the same state before either writes (explicit barrier).
    $ra = $a->query('SELECT * FROM document_requests WHERE id=1')->fetch_assoc();
    $rb = $b->query('SELECT * FROM document_requests WHERE id=1')->fetch_assoc();
    $a->begin_transaction();
    $a->query("UPDATE document_requests SET payment_status='paid' WHERE id=1");
    $b->query("UPDATE document_requests SET payment_status='waived' WHERE id=1", MYSQLI_ASYNC);
    $a->commit();
    $b->reap_async_query();
    if ($ra !== $rb || $a->query('SELECT payment_status FROM document_requests')->fetch_row()[0] !== 'waived') throw new RuntimeException('Unexpected baseline');
    echo "REPRODUCED original SQL: overlapping Paid/Waived overwrites the first decision.\n";
    $a->query("UPDATE document_requests SET status='completed',date_completed=NOW() WHERE id=1");
    // Original notify_ready unconditionally executes this after its earlier lookup.
    $b->query("UPDATE document_requests SET status='ready' WHERE id=1");
    if ($a->query('SELECT status FROM document_requests')->fetch_row()[0] !== 'ready') throw new RuntimeException('Unexpected baseline');
    echo "REPRODUCED original SQL: pickup notification moves Completed back to Ready.\n";
    echo "These are SQL-level reproductions, not authenticated browser reproductions.\n";
    $a->close(); $b->close();
} finally {
    $admin->query("DROP DATABASE `$name`");
}
