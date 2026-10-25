<?php
// CLI fixture only. The product has no HTTP-accessible mocks or test overrides.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$config = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
foreach (['HTTPSMS_API_KEY','HTTPSMS_FROM_NUMBER','GEMINI_API_KEY'] as $key) {
    putenv($key . '='); $_ENV[$key] = ''; $_SERVER[$key] = '';
}
require __DIR__ . '/../../db.php';
if (!preg_match('/^capstone_requests_[0-9]+$/', $config['db'])) throw new RuntimeException('Unsafe fixture schema');
$DB_NAME = $config['db'];
session_save_path($config['sessions']);
session_id($config['session']); session_start();
$_SESSION = $config['actor'] ? ['official_id' => $config['actor']] : [];
session_write_close();
$_SERVER['REQUEST_METHOD'] = 'POST';
register_shutdown_function(function () use ($config) { file_put_contents($config['result'] . '.status', (string)(http_response_code() ?: 200)); });
final class RequestFixtureInput {
    public $context;
    private int $position = 0;
    public function stream_open($path, $mode, $options, &$opened): bool {
        global $config;
        if ($path !== 'php://input') return false;
        if (!empty($config['barrier'])) {
            file_put_contents($config['barrier'] . '.ready', 'ready');
            $deadline = microtime(true) + 12;
            while (!is_file($config['barrier'] . '.go')) {
                if (microtime(true) > $deadline) throw new RuntimeException('Fixture barrier timed out');
                usleep(1000);
            }
        }
        return true;
    }
    public function stream_read($count): string { global $config; $data = substr(json_encode($config['payload']), $this->position, $count); $this->position += strlen($data); return $data; }
    public function stream_eof(): bool { global $config; return $this->position >= strlen(json_encode($config['payload'])); }
    public function stream_stat(): array { return []; }
}
if (($config['endpoint'] ?? '') === 'dispatch') {
    require __DIR__ . '/../../api/requests/_dispatch.php';
    $result = request_dispatch(get_db(), $config['event'], function ($event) use ($config) {
        file_put_contents($config['calls'], 'call' . "\n", FILE_APPEND | LOCK_EX);
        file_put_contents($config['barrier'] . '.ready', 'ready');
        $deadline = microtime(true) + 12;
        while (!is_file($config['barrier'] . '.go')) {
            if (microtime(true) > $deadline) throw new RuntimeException('Dispatch fixture timeout');
            usleep(1000);
        }
        return ['status' => 'sent', 'reason' => 'mock'];
    });
    echo json_encode($result); exit;
}
stream_wrapper_unregister('php'); stream_wrapper_register('php', RequestFixtureInput::class);
if ($config['endpoint'] === 'generation_begin') {
    require __DIR__ . '/../../api/requests/_generation.php';
    $actor = request_actor(); $payload = read_json_input();
    request_exec(get_db(), 'SET SESSION innodb_lock_wait_timeout=3');
    $begun = request_generation_begin(get_db(), $actor, $payload);
    request_reply($begun['result'] ?? request_success($begun['request'], false, ['leased'=>true]));
}
require __DIR__ . '/../../api/requests/' . $config['endpoint'] . '.php';
