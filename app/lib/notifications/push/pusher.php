<?php
$requirements = [
    'name' => 'Pusher',
    'fields' => ['app_id', 'key', 'secret', 'cluster']
];

class PusherProvider {
    private $config;
    private $lastError;

    public function __construct($config) {
        $this->config = $config;
    }

    public function send($device_token, $to_name, $title, $message, $data = null, $badge = null, $sound = 'default') {
        try {
            if (empty($this->config['app_id']) || empty($this->config['key']) || empty($this->config['secret'])) {
                throw new Exception('Pusher configuration incomplete.');
            }

            $autoloadPath = __DIR__ . '/../../../../vendor/autoload.php';
            if (!file_exists($autoloadPath)) {
                throw new Exception('Composer dependencies not installed.');
            }
            require_once $autoloadPath;

            $options = [
                'cluster' => $this->config['cluster'] ?? 'us2',
                'useTLS' => true
            ];

            $pusher = new Pusher\Pusher(
                $this->config['key'],
                $this->config['secret'], 
                $this->config['app_id'],
                $options
            );

            if (strpos($device_token, 'test-') === 0) {
                $channelName = 'test-channel-' . $device_token;
            } else {
                $channelName = 'notify-channel';
            }

            $currentUrl = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            $referrerUrl = $_SERVER['HTTP_REFERER'] ?? $currentUrl;

            $notificationData = [
                'title' => $title,
                'message' => $message,
                'timestamp' => time(),
                'channel' => $channelName,
                'is_test' => (strpos($device_token, 'test-') === 0),
                'icon' => $data['icon'] ?? '/uploads/global/logo.png', 
                'url' => $referrerUrl,
                'badge' => $data['badge'] ?? '/uploads/global/logo.png', 
                'require_interaction' => true 
            ];

            $result = $pusher->trigger($channelName, 'new-notification', $notificationData);

            error_log("Pusher Trigger - Channel: " . $channelName . ", Event: new-notification");

            return true;

        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            error_log("Pusher Send Error: " . $e->getMessage());
            return false;
        }
    }

    public function testConnection() {
        try {
            if (empty($this->config['app_id']) || empty($this->config['key']) || empty($this->config['secret'])) {
                throw new Exception('Pusher configuration incomplete.');
            }

            $autoloadPath = __DIR__ . '/../../../../vendor/autoload.php';
            if (!file_exists($autoloadPath)) {
                throw new Exception('Composer dependencies not installed.');
            }
            require_once $autoloadPath;

            $options = [
                'cluster' => $this->config['cluster'] ?? 'us2',
                'useTLS' => true
            ];

            $pusher = new Pusher\Pusher(
                $this->config['key'],
                $this->config['secret'],
                $this->config['app_id'],
                $options
            );

            $channels = $pusher->get_channels();
            
            return [
                'success' => true,
                'message' => 'Pusher connection successful!'
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Pusher connection failed: ' . $e->getMessage()
            ];
        }
    }

    public function getLastError() {
        return $this->lastError;
    }
}

return 'PusherProvider';
?>