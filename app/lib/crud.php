<?php
/**
 * Ultra-Basic CRUD Library - Minimal Code
 *
 * Usage:
 * $crud->table('users')
 *      ->col('id,first_name,last_name,email')  // Specify which columns to display
 *      ->title('Users')
 *      ->perPage(25)
 *      ->actions(['add' => true, 'view' => false, 'delete' => false])  // Control action buttons
 *      ->render();
 */

class CRUD {
    public $table, $db, $title = 'Records', $perPage = 25, $columns = [];
    public $actions = [
        'add' => true,
        'status' => true,
        'view' => true,
        'edit' => true,
        'delete' => true,
        'search' => true,
        'bulk_delete' => true
    ];
    public $action_urls = [
        'add' => null,
        'view' => null,
        'edit' => null,
        'delete' => null
    ];
    public $id_column = 'id'; // Column to use for URLs (edit, view, delete)
    public $where_conditions = []; // Where conditions for filtering
    public $order_by = []; // Order by conditions
    public $col_widths = []; // Column width settings
    public $col_labels = []; // Custom column labels
    public $row_formats = []; // Custom row data formatting
    public $relations = []; // Relations to other tables
    public $custom_buttons = []; // Custom action buttons
    public $action_icons = []; // Override icons for built-in action buttons
    public $protected_records = []; // IDs of records that cannot be deleted
    public $extra_fetch_cols = []; // Extra columns to fetch for placeholder use (not displayed)
    public $list_url = ''; // List page URL (search form + clear), e.g. /admin/settings#ai

    // ID column mapping for different tables
    private static $id_columns = [
        'users' => 'user_id',
        'bookings' => 'id',
        // Add more tables with custom primary keys here
    ];

    /**
     * Handle AJAX requests for CRUD operations
     * Call this method in your AJAX route handler
     */
    public static function handleAjax($db) {
        try {
            // Clean any output buffer and start fresh
            while (ob_get_level()) {
                ob_end_clean();
            }
            ob_start();

            // Set JSON header first
            header('Content-Type: application/json');

            // Get JSON input
            $rawInput = file_get_contents('php://input');
            $input = json_decode($rawInput, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON input: ' . json_last_error_msg());
            }

            $action = $input['action'] ?? '';
            $table = $input['table'] ?? '';
            $id = $input['id'] ?? 0;

            if (empty($action)) {
                throw new Exception('Action parameter is required');
            }

            if (empty($table)) {
                throw new Exception('Table parameter is required');
            }

            // Get the ID column for the table
            $id_column = self::$id_columns[$table] ?? 'id';

            // Validate table name
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                throw new Exception('Invalid table name format: ' . $table);
            }
        } catch (Exception $e) {
            ob_end_clean();
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]);
            exit();
        }

        // Handle toggle status action
        if ($action === 'toggle_status') {
            try {
                $column = $input['column'] ?? 'status';
                $status = $input['status'] ?? 0;

                if (empty($id)) {
                    throw new Exception('ID parameter is required for toggle_status action');
                }

                // SECURITY: Check if trying to disable a default gateway
                if ($status == 0) {  // Only check if disabling (status = 0)
                    $record = $db->get($table, '*', [$id_column => $id]);
                    if ($record && isset($record['default']) && $record['default'] == 1) {
                        ob_end_clean();
                        echo json_encode([
                            'status' => 'error',
                            'message' => 'Cannot disable a payment gateway that is set as default. Please set another gateway as default first.'
                        ]);
                        exit();
                    }
                }

                $valueToSet = $status;

                $current = $db->get($table, [$column], [$id_column => $id]);
                $currentValue = $current[$column] ?? null;

                if ($currentValue !== null) {
                    if (is_string($currentValue)) {
                        if ($currentValue === 'active' || $currentValue === 'inactive') {
                            $valueToSet = $status == 1 ? 'active' : 'inactive';
                        } elseif (in_array(strtolower($currentValue), ['yes', 'no', 'true', 'false'])) {
                            $valueToSet = $status == 1 ? 'yes' : 'no';
                        } elseif ($currentValue === '1' || $currentValue === '0') {
                            $valueToSet = (string)$status;
                        }
                    }
                }

                $result = $db->update($table, [$column => $valueToSet], [$id_column => $id]);

                if ($result === false) {
                    throw new Exception('Database update failed for table: ' . $table . ', column: ' . $column . ', id: ' . $id);
                }

                ob_end_clean();
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Status updated successfully',
                    'data' => ['id' => $id, 'status' => $valueToSet, 'column' => $column]
                ]);
            } catch (Exception $e) {
                ob_end_clean();
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Toggle status error: ' . $e->getMessage(),
                    'context' => ['table' => $table, 'id' => $id, 'column' => $column]
                ]);
            }
            exit();
        }

        if ($action === 'set_default') {
            try {
                $id = (int)$id;

                if ($id <= 0) {
                    throw new Exception('Invalid ID for set_default action: ' . $id);
                }

                // Get the record with status/active field
                $record = $db->get($table, '*', [$id_column => $id]);
                if (!$record) {
                    throw new Exception('Record not found in table: ' . $table . ' with ID: ' . $id);
                }

                // SECURITY: Check if gateway is disabled - prevent setting disabled gateway as default
                $statusField = in_array('status', array_keys($record)) ? 'status' : (in_array('active', array_keys($record)) ? 'active' : null);
                if ($statusField && isset($record[$statusField]) && $record[$statusField] != 1) {
                    ob_end_clean();
                    echo json_encode(['status' => 'error', 'message' => 'Cannot set a disabled payment gateway as default']);
                    exit();
                }

                if (isset($record['default']) && $record['default'] == 1) {
                    ob_end_clean();
                    echo json_encode(['status' => 'success', 'message' => 'Already set as default']);
                    exit();
                }

                // Reset all records to default = 0 using raw SQL
                try {
                    $db->exec("UPDATE " . $table . " SET `default` = 0");
                } catch (Exception $ex) {
                    // Fallback for different quote styles
                    $db->exec("UPDATE " . $table . " SET default = 0");
                }

                if ($table === 'currencies') {
                    $result = $db->update($table, ['default' => 1, 'rate' => 1.00], [$id_column => $id]);
                } else {
                    $result = $db->update($table, ['default' => 1], [$id_column => $id]);
                }

                if ($result === false) {
                    throw new Exception('Failed to set default for table: ' . $table . ', id: ' . $id);
                }

                ob_end_clean();
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Default updated successfully'
                ]);
            } catch (Exception $e) {
                ob_end_clean();
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Set default error: ' . $e->getMessage(),
                    'context' => ['table' => $table, 'id' => $id]
                ]);
            }
            exit();
        }

        // Handle check default action (before delete)
        if ($action === 'check_default') {
            try {
                if (empty($id)) {
                    throw new Exception('ID parameter is required for check_default action');
                }

                $record = $db->get($table, ['default'], [$id_column => $id]);

                if ($record && isset($record['default']) && ($record['default'] == '1' || $record['default'] == 1)) {
                    ob_end_clean();
                    echo json_encode([
                        'status' => 'warning',
                        'is_default' => true,
                        'message' => 'Cannot delete the default record. Please set another record as default first.'
                    ]);
                    exit();
                }
            } catch (Exception $e) {
                // Table doesn't have 'default' column - this is OK
                ob_end_clean();
                echo json_encode([
                    'status' => 'success',
                    'is_default' => false,
                    'note' => 'No default column in table: ' . $table
                ]);
                exit();
            }

            ob_end_clean();
            echo json_encode(['status' => 'success', 'is_default' => false]);
            exit();
        }

        // Handle delete action
        if ($action === 'delete_record') {
            try {
                if (empty($id)) {
                    throw new Exception('ID parameter is required for delete_record action');
                }

                // Prevent deleting admin user
                if ($table === 'users' && $id == 1) {
                    throw new Exception('Cannot delete the admin user (ID: 1)');
                }

                // Prevent deleting system roles
                if ($table === 'users_roles' && in_array($id, [1, 2, 3, 4, 5])) {
                    throw new Exception('Cannot delete system role (ID: ' . $id . '). This role is required for the system.');
                }
                // Check if default record
                try {
                    $record = $db->get($table, ['default'], [$id_column => $id]);
                    if ($record && isset($record['default']) && ($record['default'] == '1' || $record['default'] == 1)) {
                        ob_end_clean();
                        echo json_encode([
                            'status' => 'warning',
                            'message' => 'Cannot delete the default record. Please set another record as default first.'
                        ]);
                        exit();
                    }
                } catch (Exception $e) {
                    // No default column
                }

                $result = $db->delete($table, [$id_column => $id]);

                if (!$result) {
                    throw new Exception('Failed to delete record from table: ' . $table . ', id: ' . $id);
                }

                ob_end_clean();
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Record deleted successfully',
                    'data' => ['id' => $id, 'table' => $table]
                ]);
            } catch (Exception $e) {
                ob_end_clean();
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Delete error: ' . $e->getMessage(),
                    'context' => ['table' => $table, 'id' => $id, 'id_column' => $id_column]
                ]);
            }
            exit();
        }

        // Default response
        ob_end_clean();
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid or unsupported action: ' . $action,
            'received_action' => $action
        ]);
        exit();
    }

    public function __construct() {
        // Database will be set when first accessed
        $this->db = null;
    }

    private function getDB() {
        if ($this->db === null) {
            global $db;
            $this->db = $db;
        }
        return $this->db;
    }

    /**
     * Replace placeholders in URL with actual values
     * Supports {column_name} placeholders from row data
     * Also supports $_GET and $_SESSION variables
     */
    private function replacePlaceholders($url, $row = []) {
        return preg_replace_callback('/\{(\w+)\}/', function($matches) use ($row) {
            $key = $matches[1];
            // Try row data first
            if (isset($row[$key])) {
                return $row[$key];
            }
            // Try GET parameters
            if (isset($_GET[$key])) {
                return $_GET[$key];
            }
            // Try SESSION
            if (isset($_SESSION[$key])) {
                return $_SESSION[$key];
            }
            // Return original placeholder if not found
            return $matches[0];
        }, $url);
    }

    /**
     * Format row data using custom template
     * Supports {{column_name}} placeholders and PHP functions like {{number_format(balance, 2)}}
     */
    private function formatRowData($template, $row) {
        return preg_replace_callback('/\{\{([^}]+)\}\}/', function($matches) use ($row) {
            $expression = trim($matches[1]);

            // Check if it's a simple column reference (just a column name, no functions/operators)
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $expression)) {
                // It's a simple column name
                return isset($row[$expression]) ? $row[$expression] : '';
            }

            // It's an expression with function calls or operations
            // Replace column names with actual values in the expression
            // Sort by length descending to replace longer names first (avoid partial replacements)
            $columns = array_keys($row);
            usort($columns, function($a, $b) {
                return strlen($b) - strlen($a);
            });

            foreach ($columns as $key) {
                $val = $row[$key];
                // Replace column name with quoted value for strings, or raw value for numbers
                if ($val === null) {
                    $quotedVal = 'null';
                } elseif (is_numeric($val)) {
                    $quotedVal = $val;
                } else {
                    $quotedVal = "'" . addslashes($val) . "'";
                }
                $expression = preg_replace('/\b' . preg_quote($key, '/') . '\b/', $quotedVal, $expression);
            }

            // Evaluate the expression safely
            try {
                $result = @eval("return $expression;");
                return $result !== false && $result !== null ? $result : '';
            } catch (Throwable $e) {
                return ''; // Return empty if evaluation fails
            }
        }, $template);
    }

    public function table($name) {
        $this->table = $name;
        return $this;
    }

    public function title($title) {
        $this->title = $title;
        return $this;
    }

    public function list_url($url) {
        $this->list_url = (string) $url;
        return $this;
    }

    public function perPage($count) {
        $this->perPage = $count;
        return $this;
    }

    public function col($columns) {
        // Accept comma-separated string or array
        if (is_string($columns)) {
            $this->columns = array_map('trim', explode(',', $columns));
        } else {
            $this->columns = $columns;
        }
        return $this;
    }

    public function actions($actions) {
        // Accept array of action permissions
        // Example: ['status' => false, 'view' => true, 'edit' => true, 'delete' => false]
        if (is_array($actions)) {
            $this->actions = array_merge($this->actions, $actions);
        }
        return $this;
    }

    public function action_urls($urls) {
        // Accept array of custom action URLs
        // Example: ['add' => 'users/add', 'edit' => 'users/edit']
        if (is_array($urls)) {
            $this->action_urls = array_merge($this->action_urls, $urls);
        }
        return $this;
    }

    public function action_icons($icons) {
        // Override icons for built-in action buttons
        // Example: ['edit' => 'edit_note', 'delete' => 'delete_forever', 'view' => 'open_in_new']
        if (is_array($icons)) {
            $this->action_icons = array_merge($this->action_icons, $icons);
        }
        return $this;
    }

    public function id_column($column) {
        // Set which column to use for URLs (edit, view, delete)
        // Example: ->id_column('user_id')
        $this->id_column = $column;
        return $this;
    }

    public function where($conditions) {
        // Set where conditions for filtering
        // Example: ->where(['user_id' => '123'])
        // Example: ->where(['status' => 'active', 'type' => 'admin'])
        if (is_array($conditions)) {
            $this->where_conditions = array_merge($this->where_conditions, $conditions);
        }
        return $this;
    }

    public function order($column, $direction = 'ASC') {
        // Set order by conditions
        // Example: ->order('id', 'ASC')
        // Example: ->order('created_at', 'DESC')
        // Example: ->order(['id' => 'ASC', 'created_at' => 'DESC']) - for multiple columns
        if (is_array($column)) {
            $this->order_by = $column;
        } else {
            $this->order_by = [$column => strtoupper($direction)];
        }
        return $this;
    }

    public function col_width($column, $width) {
        // Set custom width for a specific column
        // Example: ->col_width('first_name', '150px')
        // Example: ->col_width('email', '200px')
        $this->col_widths[$column] = $width;
        return $this;
    }

    public function label($labels) {
        // Set custom labels for columns
        // Example: ->label(['first_name' => 'First Name', 'email' => 'Email Address'])
        if (is_array($labels)) {
            $this->col_labels = array_merge($this->col_labels, $labels);
        }
        return $this;
    }

    public function row($formats) {
        // Set custom formatting for row data
        // Example: ->row(['balance' => '{{currency}} {{number_format(balance, 2)}}'])
        // Supports {{column_name}} placeholders and PHP functions
        if (is_array($formats)) {
            $this->row_formats = array_merge($this->row_formats, $formats);
        }
        return $this;
    }

    public function relation($column, $relatedTable, $displayColumn, $foreignKey) {
        // Set relation for a column to fetch data from another table
        // Example: ->relation('country', 'countries', 'nicename', 'iso')
        // This means: The 'country' column in current table contains values from 'countries.iso',
        // and we want to display 'countries.nicename' instead
        $this->relations[$column] = [
            'table' => $relatedTable,
            'display' => $displayColumn,
            'foreign_key' => $foreignKey
        ];
        return $this;
    }

    public function custom_button($config) {
        // Add a custom button to the actions column
        // Example: ->custom_button([
        //     'label' => 'Approve',
        //     'icon' => 'check_circle',
        //     'url' => 'users/approve/{user_id}',
        //     'class' => 'text-green-600 hover:bg-green-100',
        //     'title' => 'Approve User',
        //     'confirm' => 'Are you sure you want to approve this user?', // Optional
        //     'onclick' => 'handleApprove(this)', // Optional custom JS function
        // ])
        if (is_array($config)) {
            $this->custom_buttons[] = $config;
        }
        return $this;
    }

    public function extra_fetch($columns) {
        // Fetch additional columns for placeholder use (e.g. in custom_button URLs)
        // without displaying them as table columns.
        // Example: ->extra_fetch('slug,owner_id')
        if (is_string($columns)) {
            $this->extra_fetch_cols = array_merge($this->extra_fetch_cols, array_map('trim', explode(',', $columns)));
        } elseif (is_array($columns)) {
            $this->extra_fetch_cols = array_merge($this->extra_fetch_cols, $columns);
        }
        return $this;
    }

    public function protect($ids) {
        // Set IDs that cannot be deleted (e.g., system roles, default records)
        // Example: ->protect([1, 2, 3, 4, 5])
        if (is_array($ids)) {
            $this->protected_records = $ids;
        } else {
            $this->protected_records = [$ids];
        }
        return $this;
    }

    public function render() {
        // Validate that table is set
        if (empty($this->table)) {
            throw new Exception('CRUD Error: Table name is not set. Use ->table("table_name") before ->render()');
        }

        // Check if table exists
        $db = $this->getDB();
            $tableCheck = $db->query("SHOW TABLES LIKE '{$this->table}'")->fetchAll(\PDO::FETCH_ASSOC);
        if (empty($tableCheck)) {
            throw new Exception("CRUD Error: Table '{$this->table}' does not exist in the database. Please check your table name or create the table first.");
        }

        // Check if all CRUD actions are disabled (read-only list mode)
        $allActionsDisabled = !$this->actions['add'] && !$this->actions['edit'] &&
                              !$this->actions['view'] && !$this->actions['delete'];

        // Parse clean URLs
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $path = parse_url($requestUri, PHP_URL_PATH);
        $pathSegments = array_filter(explode('/', $path));

        // Get the last few segments for our CRUD operations
        $segments = array_slice($pathSegments, -3); // Get last 3 segments

        $action = '';
        $id = '';
        $page = max(1, intval($_GET['page'] ?? 1));
        $search = '';

        // Only parse URL patterns if actions are enabled
        if (!$allActionsDisabled) {
            // Parse URL patterns
            if (count($segments) >= 2) {
                if ($segments[count($segments)-2] === 'edit' && is_numeric($segments[count($segments)-1])) {
                    $action = 'edit';
                    $id = $segments[count($segments)-1];
                } elseif ($segments[count($segments)-2] === 'view' && is_numeric($segments[count($segments)-1])) {
                    $action = 'view';
                    $id = $segments[count($segments)-1];
                } elseif ($segments[count($segments)-2] === 'search') {
                    $search = urldecode($segments[count($segments)-1]);
                }
            }

            if (count($segments) >= 1) {
                if ($segments[count($segments)-1] === 'create' || $segments[count($segments)-1] === 'add') {
                    $action = 'create';
                }
            }

            // Fallback to GET parameters if clean URL parsing fails
            if (!$action && !$search) {
                $action = $_GET['action'] ?? '';
                $id = $_GET['id'] ?? '';
                $search = $_GET['search'] ?? '';
            }
        }

        // Get search from query parameters (no redirect needed)
        if (isset($_GET['q']) && $_GET['q']) {
            $search = $_GET['q'];
        }

        // Normalize search input: trim whitespace and replace repeated whitespace with a single space
        if ($search !== '') {
            $search = trim(preg_replace('/\s+/', ' ', $search));
        }

        // Handle POST actions
        if ($_POST && isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'create':
                    unset($_POST['action']);
                    $this->getDB()->insert($this->table, $_POST);
                    header("Location: ./");
                    exit;
                case 'update':
                    $id = $_POST['id'];
                    unset($_POST['action'], $_POST['id']);
                    $this->getDB()->update($this->table, $_POST, ['id' => $id]);
                    header("Location: ./");
                    exit;
                case 'toggle_status':
                    $id = $_POST['id'];
                    $current = $this->getDB()->get($this->table, 'status', [$this->id_column => $id]);
                    $currentStatus = $current['status'] ?? 0;
                    $newStatus = ($currentStatus == 1 || strtolower($currentStatus) == 'active') ? 0 : 1;
                    $this->getDB()->update($this->table, ['status' => $newStatus], [$this->id_column => $id]);
                    // Clean all output buffers before redirect
                    while (ob_get_level()) {
                        ob_end_clean();
                    }
                    header("Location: " . $_SERVER['REQUEST_URI']);
                    exit;
                case 'delete':
                    $deleteId = $_POST['id'] ?? null;
                    if ($deleteId) {
                        $this->getDB()->delete($this->table, [$this->id_column => $deleteId]);
                    }
                    // Clean all output buffers before redirect
                    while (ob_get_level()) {
                        ob_end_clean();
                    }
                    header("Location: " . $_SERVER['REQUEST_URI']);
                    exit;
                case 'bulk_delete':
                    $ids = explode(',', $_POST['ids'] ?? '');
                    $ids = array_filter($ids); // Remove empty values
                    if (!empty($ids)) {
                        $this->getDB()->delete($this->table, [$this->id_column => $ids]);
                    }
                    // Clean all output buffers before redirect
                    while (ob_get_level()) {
                        ob_end_clean();
                    }
                    header("Location: " . $_SERVER['REQUEST_URI']);
                    exit;
            }
        }

        // Get table structure with proper error handling
        try {
            $tableData = $this->getDB()->get($this->table, '*');
            if (!$tableData) {
                // If no data exists, get column names from table structure
                $tableStructure = $this->getDB()->query("DESCRIBE {$this->table}")->fetchAll(\PDO::FETCH_ASSOC);
                $allCols = array_column($tableStructure, 'Field');
            } else {
                $allCols = array_keys($tableData);
            }
        } catch (\PDOException $e) {
            // Check if it's a column error
            if (strpos($e->getMessage(), 'Unknown column') !== false) {
                preg_match("/Unknown column '([^']+)'/", $e->getMessage(), $matches);
                $invalidCol = $matches[1] ?? 'unknown';
                throw new Exception("CRUD Error: Column '{$invalidCol}' does not exist in table '{$this->table}'. Please check your ->col() configuration.");
            }
            throw new Exception("CRUD Error: Failed to fetch table structure for '{$this->table}'. " . $e->getMessage());
        }

        // Use specified columns or all columns
        $cols = !empty($this->columns) ? $this->columns : $allCols;

        // Validate that all specified columns actually exist in the table
        if (!empty($this->columns)) {
            $invalidCols = array_diff($this->columns, $allCols);
            if (!empty($invalidCols)) {
                throw new Exception("CRUD Error: Invalid column(s) in table '{$this->table}': " . implode(', ', $invalidCols) . ". Available columns: " . implode(', ', $allCols));
            }
        }

        // Ensure 'id' is always included for operations (but might not be displayed)
        if (!in_array('id', $cols) && in_array('id', $allCols)) {
            $displayCols = $cols;
            $cols = array_merge(['id'], $cols);
        } else {
            $displayCols = $cols;
        }

        // Also ensure id_column is included if it's different from 'id'
        if ($this->id_column !== 'id' && !in_array($this->id_column, $cols) && in_array($this->id_column, $allCols)) {
            $cols = array_merge($cols, [$this->id_column]);
        }

        // Also ensure 'status' column is fetched if it exists in the table (for toggle button)
        // but don't add it to displayCols unless explicitly requested
        if (!in_array('status', $cols) && in_array('status', $allCols)) {
            $cols = array_merge($cols, ['status']);
        }

        // Same for 'active' column
        if (!in_array('active', $cols) && in_array('active', $allCols)) {
            $cols = array_merge($cols, ['active']);
        }

        // Always fetch banned column if it exists in the table (for toggle button)
        if (!in_array('banned', $cols) && in_array('banned', $allCols)) {
            $cols = array_merge($cols, ['banned']);
        }

        // Always fetch default column if it exists in the table (for toggle button)
        if (!in_array('default', $cols) && in_array('default', $allCols)) {
            $cols = array_merge($cols, ['default']);
        }

        // Fetch extra columns requested via ->extra_fetch() (available for placeholders, not displayed)
        foreach ($this->extra_fetch_cols as $extraCol) {
            if (!in_array($extraCol, $cols) && in_array($extraCol, $allCols)) {
                $cols = array_merge($cols, [$extraCol]);
            }
        }

        // If all actions are disabled, skip form logic and always show list
        if ($allActionsDisabled) {
            return $this->list($cols, $displayCols, $page, $search);
        }

        if ($action == 'create' || $action == 'edit' || $action == 'view') {
            $data = ($action == 'edit' || $action == 'view') ? $this->getDB()->get($this->table, '*', ['id' => $id]) : [];
            return $this->form($allCols, $data, $action);
        }

        return $this->list($cols, $displayCols, $page, $search);
    }

    private function list($cols, $displayCols, $page, $search) {
        $offset = ($page - 1) * $this->perPage;

        // Check if we have any actions enabled
        $hasAnyAction = $this->actions['status'] || $this->actions['view'] ||
                        $this->actions['edit'] || $this->actions['delete'];

        $bulkDeleteEnabled = !empty($this->actions['bulk_delete']);

        // Get search column from query parameter
        $searchColumn = $_GET['search_col'] ?? 'all';

        // Build where clause for search
        $where = [];

        // Add pre-defined where conditions first
        if (!empty($this->where_conditions)) {
            $where = array_merge($where, $this->where_conditions);
        }

        if ($search) {
            // Split search term into words
            $searchWords = array_filter(explode(' ', $search));
            $searchCols = [];

            if ($searchColumn === 'all') {
                // Search in all columns - match ANY word
                $exactMatchColumns = ['payment_status', 'booking_status', 'status', 'module_type', 'module'];
                $knownStatusValues = ['paid', 'unpaid', 'confirmed', 'pending', 'cancelled', 'failed', 'stays', 'flights', 'tours', 'hotels'];
                
                    $allCurrencies = [];
                
                    if (isset($_SESSION['app_currency'])) {
                        try {
                            $allCurrencies = $this->db->select('currencies', ['name', 'rate', 'default'], ['status' => '1']);
                            foreach($allCurrencies as $cur) {
                                if (($cur['default'] ?? 0) == 1) {
                                    $defaultRate = (float)($cur['rate'] ?? 1.0);
                                    break;
                                }
                            }
                        } catch (Exception $e) {}
                    }

                    foreach ($searchWords as $word) {
                        $isKnownStatus = in_array(strtolower($word), $knownStatusValues, true);
                        $cleanedWord = str_replace(',', '', $word);
                        $isNumeric = is_numeric($cleanedWord);
                        
                        foreach ($cols as $col) {
                            if ($col !== 'id') {
                                if ($isKnownStatus && in_array($col, $exactMatchColumns, true)) {
                                    if (!isset($searchCols[$col])) { $searchCols[$col] = []; }
                                    if (is_array($searchCols[$col])) { $searchCols[$col][] = $word; }
                                } elseif ($col === 'first_name') {
                                    // For user search, check first_name, last_name, and email
                                    $searchCols['first_name[~]'][] = $word;
                                    $searchCols['last_name[~]'][] = $word;
                                    $searchCols['email[~]'][] = $word;
                                } elseif ($isNumeric && (strpos(strtolower($col), 'price') !== false || strpos(strtolower($col), 'markup') !== false || strpos(strtolower($col), 'commission') !== false || strpos(strtolower($col), 'amount') !== false)) {
                                    // For price/numeric columns, handle comma-formatted numbers and currency conversion
                                    $searchCols[$col . '[~]'][] = $cleanedWord;
                                    $val = floatval($cleanedWord);
                                    
                                    if (!isset($searchCols[$col])) { $searchCols[$col] = []; }
                                    if (is_array($searchCols[$col])) { $searchCols[$col][] = $val; }

                                    // Currency Support
                                    if (!empty($allCurrencies)) {
                                        $sessionCurrency = $_SESSION['app_currency'] ?? 'USD';
                                
                                        foreach($allCurrencies as $cur) {
                                            if ($cur['name'] === $sessionCurrency) { $sessionRate = $cur['rate']; break; }
                                        }
                                        
                                        if ($sessionRate > 0) {
                                            foreach($allCurrencies as $cur) {
                                                // Calculate what the base price would be if this was the base currency
                                                // Using default currency as the pivot
                                                $possibleBasePrice = round(($val / $sessionRate) * $cur['rate'], 2);
                                                if (is_array($searchCols[$col])) { $searchCols[$col][] = $possibleBasePrice; }
                                            }
                                        }
                                    }
                                } else {
                                    $searchCols[$col . '[~]'][] = $word;
                                }
                            }
                        }
                    }
                    
                    // Sanitize searchCols - remove duplicates and flatten single items
                    foreach ($searchCols as $k => $v) {
                        if (is_array($v)) {
                            $v = array_unique($v);
                            $searchCols[$k] = (count($v) === 1) ? reset($v) : array_values($v);
                        }
                    }
            } else {
                // Search in specific column only - match ANY word
                if (in_array($searchColumn, $cols)) {
                    $exactMatchColumns = ['payment_status', 'booking_status', 'status', 'module_type', 'module', 'price_markup', 'price', 'commission', 'amount', 'total_price'];
                    $allCurrencies = [];
                    
                    if (isset($_SESSION['app_currency'])) {
                        try {
                            $allCurrencies = $this->db->select('currencies', ['name', 'rate', 'default'], ['status' => '1']);
                            foreach($allCurrencies as $cur) {
                                if (($cur['default'] ?? 0) == 1) {
                                    $defaultRate = (float)($cur['rate'] ?? 1.0);
                                    break;
                                }
                            }
                        } catch (Exception $e) {}
                    }

                    foreach ($searchWords as $word) {
                        if ($searchColumn === 'first_name') {
                            $searchCols['first_name[~]'][] = $word;
                            $searchCols['last_name[~]'][] = $word;
                            $searchCols['email[~]'][] = $word;
                            continue;
                        }
                        
                        $isNumericCol = (strpos(strtolower($searchColumn), 'price') !== false || 
                                        strpos(strtolower($searchColumn), 'markup') !== false || 
                                        strpos(strtolower($searchColumn), 'commission') !== false || 
                                        strpos(strtolower($searchColumn), 'amount') !== false);
                        
                        $useExactMatch = in_array($searchColumn, $exactMatchColumns, true) && count($searchWords) === 1;
                        
                        if ($isNumericCol) {
                            // Check for formatted price (with commas, decimals, or both)
                            $cleanedWord = str_replace(',', '', $word);
                            if (is_numeric($cleanedWord)) {
                                $val = floatval($cleanedWord);
                                if (!isset($searchCols[$searchColumn])) { $searchCols[$searchColumn] = []; }
                                $searchCols[$searchColumn][] = $val;
                                // Also allow partial match for numeric columns when searched specifically
                                $searchCols[$searchColumn . '[~]'][] = $cleanedWord;

                                // Currency Support
                                if (!empty($allCurrencies)) {
                                    $sessionCurrency = $_SESSION['app_currency'] ?? 'USD';
                                
                                    foreach($allCurrencies as $cur) {
                                        if ($cur['name'] === $sessionCurrency) { $sessionRate = $cur['rate']; break; }
                                    }
                                    
                                    if ($sessionRate > 0) {
                                        foreach($allCurrencies as $cur) {
                                            $possibleBasePrice = round(($val / $sessionRate) * $cur['rate'], 2);
                                            $searchCols[$searchColumn][] = $possibleBasePrice;
                                        }
                                    }
                                }
                                continue;
                            }
                        }
                        
                        if ($useExactMatch) {
                            $searchCols[$searchColumn][] = $word;
                        } else {
                            $searchCols[$searchColumn . '[~]'][] = $word;
                        }
                    }
                    
                    // Sanitize searchCols - remove duplicates and flatten single items
                    foreach ($searchCols as $k => $v) {
                        if (is_array($v)) {
                            $v = array_unique($v);
                            $searchCols[$k] = (count($v) === 1) ? reset($v) : array_values($v);
                        }
                    }
                }
            }
            if ($searchCols) {
                $where['OR'] = $searchCols;
            }
        }

        // Get total count
        $total = $this->getDB()->count($this->table, $where);
        $totalPages = ceil($total / $this->perPage);

        // Get paginated data - select the columns we need
        // Use custom order if provided, otherwise default to id DESC
        $where['ORDER'] = !empty($this->order_by) ? $this->order_by : ['id' => 'DESC'];
        $where['LIMIT'] = [$offset, $this->perPage];

        // Select columns. render() already prepends 'id' to $cols whenever the table
        // actually has an 'id' column, so don't force a non-existent 'id' here (that
        // breaks tables whose primary key is not named 'id', e.g. generic DB browsing).
        $selectCols = $cols;

        // Extract columns used in row formats and add them to selectCols if not already present
        foreach ($this->row_formats as $colName => $template) {
            if (!is_string($template)) {
                continue;
            }
            // Find all {{column_name}} patterns in the template
            preg_match_all('/\{\{([^}]+)\}\}/', $template, $matches);
            foreach ($matches[1] as $expression) {
                // Remove quoted strings to avoid matching classes like 'bg-red-500' as columns
                $cleanExpression = preg_replace('/(\'|")(?:\1|\\.|[^\1])*?\1/', '', $expression);
                
                // Extract simple column names from CLEANED expression
                preg_match_all('/\b([a-zA-Z_][a-zA-Z0-9_]*)\b/', $cleanExpression, $colMatches);
                foreach ($colMatches[1] as $col) {
                    // Skip PHP function names, add only if it looks like a column and not already in selectCols
                    if (!in_array($col, $selectCols) && !in_array($col, ['number_format', 'date', 'strtotime', 'ucfirst', 'strtolower', 'strtoupper', 'trim', 'substr', 'strlen']) && !function_exists($col)) {
                        $selectCols[] = $col;
                    }
                }
            }
        }

        // Fetch data with proper error handling
        try {
            // Debug logging
            if (in_array($_SERVER['HTTP_HOST'], ['localhost', '127.0.0.1', '::1'])) {
            }
            
            $data = $this->getDB()->select($this->table, $selectCols, $where);
        } catch (\PDOException $e) {
            // Check if it's a column error
            if (strpos($e->getMessage(), 'Unknown column') !== false) {
                preg_match("/Unknown column '([^']+)'/", $e->getMessage(), $matches);
                $invalidCol = $matches[1] ?? 'unknown';

                // Get available columns for helpful error message
                $availableCols = [];
                try {
                    $tableStructure = $this->getDB()->query("DESCRIBE {$this->table}")->fetchAll(\PDO::FETCH_ASSOC);
                    $availableCols = array_column($tableStructure, 'Field');
                } catch (\Exception $ex) {
                    // Ignore if we can't get column list
                }

                $errorMsg = "CRUD Error: Column '{$invalidCol}' does not exist in table '{$this->table}'. ";
                $errorMsg .= "Check your ->col() configuration. ";
                if (!empty($availableCols)) {
                    $errorMsg .= "Available columns: " . implode(', ', $availableCols);
                }
                throw new Exception($errorMsg);
            }
            throw new Exception("CRUD Error: Failed to fetch data from table '{$this->table}'. " . $e->getMessage());
        }

        $html = '
        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
            <!-- Header with title and actions -->
            <div class="px-5 py-4 border-b border-slate-200 bg-slate-50 rounded-t-lg">
                <div class="flex flex-col xl:flex-row xl:items-center xl:justify-between gap-4">';

        if (trim((string) $this->title) !== '') {
            $html .= '
                    <div>
                        <h2 class="text-xl font-semibold text-gray-900">' . htmlspecialchars($this->title) . '</h2>
                        <p class="text-sm text-gray-600 mt-1">Total: ' . number_format($total) . ' records</p>
                    </div>';
        }

        $html .= '
                    <div class="flex flex-wrap items-center gap-2 w-full xl:w-auto">
                        <!-- Column Selection Dropdown -->
                        <div class="relative w-full sm:w-auto">
                            <button type="button" id="columnToggleBtn"
                                    class="select input flex items-center justify-between sm:justify-start gap-2 cursor-pointer w-full sm:w-[170px]">
                                <span class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-lg">view_column</span>
                                    View Columns
                                </span>
                             </button>
                            <div id="columnDropdown" class="hidden absolute right-0 mt-2 w-56 bg-white border border-gray-200 rounded-lg shadow-lg z-50">
                                <div class="p-3">
                                    <div class="flex items-center justify-between mb-3">
                                        <h3 class="text-sm font-medium text-gray-900">Show Columns</h3>
                                        <button type="button" onclick="selectAllColumns()" class="text-xs text-indigo-600 hover:text-indigo-700">Select All</button>
                                    </div>
                                    <div class="space-y-2" id="columnCheckboxes">';

        // Add checkboxes for each column in displayCols
        foreach ($displayCols as $col) {
            if ($col === 'id') continue; // Skip ID column as it will be replaced with serial
            $label = isset($this->col_labels[$col]) ? $this->col_labels[$col] : ucfirst(str_replace('_', ' ', $col));
            $html .= '
                                        <label class="flex items-center gap-2 text-sm">
                                            <input type="checkbox" checked class="column-toggle rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                   data-column="' . $col . '">
                                            <span class="text-gray-700">' . htmlspecialchars($label) . '</span>
                                        </label>';
        }

        $html .= '
                                    </div>
                                </div>
                            </div>
                        </div>';

        // Search (only if enabled)
        if (isset($this->actions['search']) && $this->actions['search']) {
            $searchAction = $this->list_url !== '' ? htmlspecialchars($this->list_url, ENT_QUOTES, 'UTF-8') : '';
            $html .= '
                        <form method="GET" action="' . $searchAction . '" class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
                            <select name="search_col" class="select input w-full sm:w-[150px]" style="min-width: 110px;">
                                <option value="all"' . ($searchColumn === 'all' ? ' selected' : '') . '>All Columns</option>';

            // Add option for each column
            foreach ($cols as $col) {
                if ($col !== 'id') {
                    $label = isset($this->col_labels[$col]) ? $this->col_labels[$col] : ucfirst(str_replace('_', ' ', $col));
                    $selected = ($searchColumn === $col) ? ' selected' : '';
                    $html .= '<option value="' . htmlspecialchars($col) . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
                }
            }

            $html .= '
                            </select>
                            <input type="text" name="q" value="' . htmlspecialchars($search) . '"
                                   placeholder="Search records..."
                                   class="input w-full sm:w-[200px]" style="min-width: 130px;">
                            <button type="submit" class="btn w-full sm:w-auto">
                                <span class="material-symbols-outlined text-lg">search</span>
                            </button>';

            if ($search) {
                $currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
                $clearHref = $this->list_url !== '' ? $this->list_url : $currentPath;
                $html .= '<a href="' . htmlspecialchars($clearHref, ENT_QUOTES, 'UTF-8') . '" class="w-full sm:w-auto px-3 py-2 text-sm bg-gray-100 text-gray-600 rounded-lg hover:bg-gray-200 flex items-center justify-center gap-1 border">
                            <span class="material-symbols-outlined text-lg">close</span>

                          </a>';
            }

            $html .= '
                        </form>';
        }

        $html .= '';

        // Add button (if enabled)
        if (isset($this->actions['add']) && $this->actions['add']) {
            $addUrl = $this->action_urls['add'] ?? strtolower($this->table) . '/add';
            // Replace placeholders like {user_id} with values from GET/SESSION
            $addUrl = $this->replacePlaceholders($addUrl);
            $html .= '
                        <a href="' . root . $addUrl . '" class="btn w-full sm:w-auto">
                            <span class="material-symbols-outlined text-lg">add</span>
                            ' . T::create_new . '
                        </a>';
        }

        if ($bulkDeleteEnabled) {
            $html .= '
                        <div id="bulkActions" class="hidden w-full sm:w-auto">
                            <button type="button" onclick="bulkDelete()" class="btn rose w-full sm:w-auto">
                                <span class="material-symbols-outlined text-lg mr-2">delete</span>
                                Delete Selected
                            </button>
                        </div>';
        }

        $html .= '
                    </div>
                </div>
            </div>

            <!-- Table -->
            <div class="overflow-x-auto rounded-b-lg" id="tableContainer">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>';

        if ($bulkDeleteEnabled) {
            $html .= '
                            <th id="selectAllHeader" class="w-[40px] text-center px-4 py-1 text-left sticky left-0 bg-gray-50 z-25 border-r border-gray-200">
                                <input type="checkbox" id="selectAll" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                       onchange="toggleAllRows(this)">
                            </th>';
        }

        $html .= '
                            <th class="w-[40px] px-2 py-1 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">#</th>';

        // Add Status column header after serial number if status action is enabled
        if (isset($this->actions['status']) && $this->actions['status']) {
            $html .= '
                            <th class="w-[60px] px-2 py-1 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>';
        }

        // Add Banned column header after status if banned action is enabled
        // Always show Banned column header if action enabled and banned column exists in table
        $allCols = array_keys($this->getDB()->get($this->table, '*') ?: []);
        if (isset($this->actions['banned']) && $this->actions['banned'] && in_array('banned', $allCols)) {
            $html .= '
                            <th class="w-[60px] px-2 py-1 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Banned</th>';
        }

        // Add Default column header if default action is enabled and default column exists in table
        if (isset($this->actions['default']) && $this->actions['default'] && in_array('default', $allCols)) {
            $html .= '
                            <th class="w-[60px] px-2 py-1 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Default</th>';
        }

        $html .= '';

        foreach ($displayCols as $col) {
            if ($col === 'id') continue; // Skip ID column
            $label = isset($this->col_labels[$col]) ? $this->col_labels[$col] : ucfirst(str_replace('_', ' ', $col));
            $fixedClass = ($col === 'status' || $col === 'active') ? 'sticky right-16 bg-gray-50 z-20' : '';

            // Apply custom width if set
            $widthStyle = isset($this->col_widths[$col]) ? 'style="width: ' . $this->col_widths[$col] . '; min-width: ' . $this->col_widths[$col] . ';"' : '';

            $html .= '<th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider ' . $fixedClass . ' column-header" data-column="' . $col . '" ' . $widthStyle . '>' . htmlspecialchars($label) . '</th>';
        }

        // Only show Actions header if any action is enabled
        $actionColW = 160; // default fallback
        if ($hasAnyAction) {
            // Compute action column width dynamically based on number of buttons
            $actionBtnCount  = count($this->custom_buttons);
            if (!empty($this->actions['view']))   $actionBtnCount++;
            if (!empty($this->actions['edit']))   $actionBtnCount++;
            if (!empty($this->actions['delete'])) $actionBtnCount++;
            $actionColW = max(120, $actionBtnCount * 38 + max(0, $actionBtnCount - 1) * 4 + 32);

            $html .= '
                            <th id="actionsHeader" class="px-4 py-1 text-center text-xs font-medium text-gray-500 uppercase tracking-wider sticky right-0 bg-gray-50 z-30 border-l border-gray-200" style="width:' . $actionColW . 'px;min-width:' . $actionColW . 'px;">Actions</th>'; 
        }

        $html .= '
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200" id="tableBody">';

        $serialNumber = ($page - 1) * $this->perPage + 1; // Calculate starting serial number
        foreach ($data as $row) {
            $html .= '<tr class="hover:bg-gray-50 transition-colors row-item" data-id="' . $row[$this->id_column] . '">';

                 // Checkbox column (fixed on left side)
                 if ($bulkDeleteEnabled) {
                  $html .= '<td class="px-4 py-1 whitespace-nowrap text-center sticky left-0 bg-white z-25 border-gray-200 checkbox-cell">
                        <input type="checkbox" class="row-checkbox rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                            value="' . $row[$this->id_column] . '" onchange="toggleRowSelection(this)">
                         </td>';
                 }

            // Serial Number
            $html .= '<td class="px-4 py-1 whitespace-nowrap text-sm text-gray-900">' . $serialNumber . '</td>';

            // Status Toggle Switch (if enabled and column exists) - Smaller version
            if (isset($this->actions['status']) && $this->actions['status'] && (isset($row['status']) || isset($row['active']))) {
                $statusCol = isset($row['status']) ? 'status' : 'active';
                $isActive = ($row[$statusCol] == 1 || strtolower($row[$statusCol]) == 'active');
                $html .= '<td class="px-2 py-1 whitespace-nowrap text-center status-cell">
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" class="sr-only peer toggle-status-switch"
                                       data-id="' . $row[$this->id_column] . '"
                                       data-table="' . $this->table . '"
                                       data-column="' . $statusCol . '"
                                       ' . ($isActive ? 'checked' : '') . '>
                                <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[\'\'] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-blue-600"></div>
                            </label>
                          </td>';
            }

            // Banned Toggle Switch (if enabled and column exists) - Green color
            // Always show banned switch if action enabled and banned column exists in table
            if (isset($this->actions['banned']) && $this->actions['banned'] && array_key_exists('banned', $row)) {
                $isBanned = ($row['banned'] == 1);
                $html .= '<td class="px-2 py-1 whitespace-nowrap text-center banned-cell">
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" class="sr-only peer toggle-status-switch"
                                       data-id="' . $row[$this->id_column] . '"
                                       data-table="' . $this->table . '"
                                       data-column="banned"
                                       ' . ($isBanned ? 'checked' : '') . '>
                                <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[\'\'] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-green-600"></div>
                            </label>
                          </td>';
            }

            // Default Toggle Switch (if enabled and column exists) - Orange color
            if (isset($this->actions['default']) && $this->actions['default'] && array_key_exists('default', $row)) {
                $isDefault = ($row['default'] == 1);
                $html .= '<td class="px-2 py-1 whitespace-nowrap text-center default-cell">
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" class="sr-only peer toggle-default-switch"
                                       data-id="' . $row[$this->id_column] . '"
                                       data-table="' . $this->table . '"
                                       ' . ($isDefault ? 'checked' : '') . '>
                                <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-orange-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[\'\'] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-orange-500"></div>
                            </label>
                          </td>';
            }

            foreach ($displayCols as $col) {
                if ($col === 'id') continue; // Skip ID column
                $value = $row[$col] ?? '';

                // Check if this column has a custom row format
                if (isset($this->row_formats[$col])) {
                    $rowFormat = $this->row_formats[$col];
                    // Special handling for image columns (img, room_images, hotel_images, etc.) - extract URL from JSON
                    if (($col === 'img' || strpos($col, '_images') !== false || strpos($col, '_image') !== false) && !empty($value) && is_string($value)) {
                        $images = json_decode($value, true);
                        if (is_array($images) && !empty($images)) {
                            // Find default image or use first image
                            $imageUrl = '';
                            foreach ($images as $img) {
                                if (isset($img['default']) && $img['default']) {
                                    $imageUrl = $img['url'];
                                    break;
                                }
                            }
                            // If no default found, use first image
                            if (empty($imageUrl) && isset($images[0]['url'])) {
                                $imageUrl = $images[0]['url'];
                            }
                            // Replace the column value with the extracted URL
                            $row[$col] = $imageUrl;
                        }
                    }

                    // Special handling for room_options - extract minimum price from JSON
                    if ($col === 'room_options' && !empty($value) && is_string($value)) {
                        $options = json_decode($value, true);
                        if (is_array($options) && !empty($options)) {
                            $minPrice = null;
                            foreach ($options as $option) {
                                if (isset($option['price'])) {
                                    $price = floatval($option['price']);
                                    if ($minPrice === null || $price < $minPrice) {
                                        $minPrice = $price;
                                    }
                                }
                            }

                            $countOptions = count($options); // get number of options

                            // Append count to the min price
                            $row[$col] = ($minPrice !== null ? number_format($minPrice, 2) : '0.00') . ",  Total Options ({$countOptions})";
                            // Example output: "45.00 (3)" -> min price 45, 3 options
                        } else {
                            $row[$col] = '0.00 (0)';
                        }
                    }

                    if (is_callable($rowFormat)) {
                        try {
                            $value = call_user_func($rowFormat, $row);
                        } catch (Throwable $e) {
                            $value = '';
                            error_log('CRUD Row Format Error for column ' . $col . ': ' . $e->getMessage());
                        }
                    } elseif (is_string($rowFormat)) {
                        $value = $this->formatRowData($rowFormat, $row);
                    }
                }
                // Check if this column has a relation defined
                elseif (isset($this->relations[$col])) {
                    $relation = $this->relations[$col];
                    $relatedTable = $relation['table'];
                    $displayColumn = $relation['display'];
                    $foreignKey = $relation['foreign_key'];

                    // Check if value is null or empty before attempting relation lookup
                    if (empty($value) || $value === '0' || $value === 0) {
                        $value = '<span class="text-gray-400 text-xs">—</span>';
                    } else {
                        // Fetch related data with error handling
                        try {
                            $relatedData = $this->getDB()->get($relatedTable, $displayColumn, [$foreignKey => $value]);

                            if ($relatedData) {
                                // When selecting a single column, Medoo returns the value directly (not an array)
                                $value = $relatedData;
                            } else {
                                // Show a simple placeholder instead of error
                                $value = '<span class="text-gray-400 text-xs">—</span>';
                            }
                        } catch (Exception $e) {
                            // Show a simple placeholder instead of error
                            $value = '<span class="text-gray-400 text-xs">—</span>';
                            error_log('Relation Error: ' . $e->getMessage() . ' | Table: ' . $relatedTable . ' | Display: ' . $displayColumn . ' | Foreign Key: ' . $foreignKey . ' | Value: ' . $row[$col]);
                        }
                    }
                }

                // Don't truncate if this column has a custom row format (it might contain HTML)
                if (!isset($this->row_formats[$col]) && strlen($value) > 50) {
                    $value = substr($value, 0, 50) . '...';
                }

                $fixedClass = ($col === 'status' || $col === 'active') ? 'sticky right-16 bg-white z-20' : '';

                // Apply custom width if set
                $widthStyle = isset($this->col_widths[$col]) ? 'style="width: ' . $this->col_widths[$col] . '; min-width: ' . $this->col_widths[$col] . ';"' : '';

                // Special handling for status/active columns
                if (($col === 'status' || $col === 'active') && !isset($this->row_formats[$col])) {
                    $statusClass = ($value == 1 || strtolower($value) == 'active') ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800';
                    $statusText = ($value == 1 || strtolower($value) == 'active') ? 'Active' : 'Inactive';
                    $html .= '<td class="px-4 py-1 whitespace-nowrap text-sm ' . $fixedClass . ' column-cell" data-column="' . $col . '" ' . $widthStyle . '>
                                <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full ' . $statusClass . '">' . $statusText . '</span>
                              </td>';
                } else {
                    $html .= '<td class="px-2 py-1 whitespace-nowrap text-sm text-gray-900 ' . $fixedClass . ' column-cell" data-column="' . $col . '" ' . $widthStyle . '>' . $value . '</td>';
                }
            }

            // Only show actions cell if any action is enabled
            if ($hasAnyAction) {
                $html .= '
                        <td class="px-4 py-1 whitespace-nowrap text-center text-sm font-medium sticky right-0 bg-white z-30 border-l border-gray-200 actions-cell overflow-hidden" style="width:' . $actionColW . 'px;min-width:' . $actionColW . 'px;">
                            <div class="flex items-center justify-center gap-1 w-full overflow-hidden">';

                // View Button
                if (isset($this->actions['view']) && $this->actions['view']) {
                    $viewUrl = $this->action_urls['view'] ?? 'view';
                    // Replace placeholders like {user_id} with actual values
                    $viewUrl = $this->replacePlaceholders($viewUrl, $row);
                    $html .= '
                                <a href="' . $viewUrl . '/"
                                   class="inline-flex items-center btn light px-[10px] py-1 h-[32px] rounded-xl hover:bg-blue-100 transition-colors"
                                   title="View">
                                    <span class="material-symbols-outlined text-base">' . ($this->action_icons['view'] ?? 'visibility') . '</span>
                                </a>';
                }

                // Custom Buttons (position: before_edit)
                foreach ($this->custom_buttons as $button) {
                    if (($button['position'] ?? '') !== 'before_edit') continue;
                    $btnUrl = isset($button['url']) ? $this->replacePlaceholders($button['url'], $row) : '#';
                    $btnLabel = $button['label'] ?? '';
                    $btnIcon = trim((string) ($button['icon'] ?? ''));
                    $btnClass = $button['class'] ?? 'text-blue-600 hover:bg-blue-100';
                    $btnTitle = $button['title'] ?? $btnLabel;
                    $btn_target = $button['target'] ?? '_self';
                    $btnOnclick = '';
                    if (isset($button['onclick'])) {
                        $btnOnclick = 'onclick="' . htmlspecialchars($this->replacePlaceholders($button['onclick'], $row)) . '"';
                    } elseif (isset($button['confirm'])) {
                        $btnOnclick = 'onclick="return confirm(\'' . addslashes($this->replacePlaceholders($button['confirm'], $row)) . '\')"';
                    }
                    $html .= '
                        <a href="' . $btnUrl . '"
                        class="inline-flex items-center btn light px-[10px] py-1 h-[32px] rounded-xl transition-colors ' . $btnClass . '"
                        title="' . htmlspecialchars($btnTitle) . '" target="' . $btn_target . '"
                        ' . $btnOnclick . '>
                            ' . (!empty($btnIcon) ? '<span class="material-symbols-outlined text-base' . (strlen($btnLabel) > 0 ? ' mr-1' : '') . '">' . htmlspecialchars($btnIcon) . '</span>' : '') . '
                            ' . (strlen($btnLabel) > 0 ? '<span class="text-sm font-medium">' . htmlspecialchars($btnLabel) . '</span>' : '') . '
                        </a>';
                }

                // Edit Button
                if (isset($this->actions['edit']) && $this->actions['edit']) {
                    $editUrl = $this->action_urls['edit'] ?? 'edit';
                    // Replace placeholders like {user_id} with actual values
                    $editUrl = $this->replacePlaceholders($editUrl, $row);
                    $html .= '
                                <a href="' . $editUrl . '"
                                   class="inline-flex items-center btn light px-[10px] py-1 h-[32px] rounded-xl hover:bg-blue-100 transition-colors"
                                   title="Edit">
                                    <span class="material-symbols-outlined text-base">' . ($this->action_icons['edit'] ?? 'edit') . '</span>
                                </a>';
                }

                // Delete Button (AJAX)
                if (isset($this->actions['delete']) && $this->actions['delete']) {
                    $idValue = $row[$this->id_column];
                    $isProtected = in_array($idValue, $this->protected_records);

                    if ($isProtected) {
                        // Protected record - show disabled button
                        $html .= '
                                <button type="button"
                                        class="inline-flex items-center btn light px-[10px] py-1 h-[32px] rounded-xl hover:bg-blue-100 transition-colors"
                                        title="Cannot delete system record"
                                        disabled>
                                    <span class="material-symbols-outlined text-base">' . ($this->action_icons['delete'] ?? 'delete') . '</span>
                                </button>';
                    } else {
                        // Normal deletable record
                        $html .= '
                                <button type="button"
                                        class="delete-btn inline-flex items-center btn light text-red-600 px-[10px] py-1 h-[32px] rounded-xl hover:bg-red-100 transition-colors"
                                        data-table="' . $this->table . '"
                                        data-id="' . $idValue . '"
                                        title="Delete">
                                    <span class="material-symbols-outlined text-base">' . ($this->action_icons['delete'] ?? 'delete') . '</span>
                                </button>';
                    }
                }

                // Custom Buttons (default: after delete)
                foreach ($this->custom_buttons as $button) {
                    if (($button['position'] ?? '') === 'before_edit') continue;
                    $btnUrl = isset($button['url']) ? $this->replacePlaceholders($button['url'], $row) : '#';
                    $btnLabel = $button['label'] ?? '';
                    $btnIcon = trim((string) ($button['icon'] ?? ''));
                    $btnClass = $button['class'] ?? 'text-blue-600 hover:bg-blue-100';
                    $btnTitle = $button['title'] ?? $btnLabel;
                    $btn_target = $button['target'] ?? '_self';

                    // Replace placeholders in onclick attribute if it exists
                    $btnOnclick = '';
                    if (isset($button['onclick'])) {
                        $onclickWithPlaceholders = $this->replacePlaceholders($button['onclick'], $row);
                        $btnOnclick = 'onclick="' . htmlspecialchars($onclickWithPlaceholders) . '"';
                    } elseif (isset($button['confirm'])) {
                        $confirmMessage = $this->replacePlaceholders($button['confirm'], $row);
                        $btnOnclick = 'onclick="return confirm(\'' . addslashes($confirmMessage) . '\')"';
                    }

                    $html .= '
                        <a href="' . $btnUrl . '"
                        class="inline-flex items-center btn light px-[10px] py-1 h-[32px] rounded-xl transition-colors ' . $btnClass . '"
                        title="' . htmlspecialchars($btnTitle) . '" target="'.$btn_target.'"
                        ' . $btnOnclick . '>
                            ' . (!empty($btnIcon) ? '<span class="material-symbols-outlined text-base' . (strlen($btnLabel) > 0 ? ' mr-1' : '') . '">' . htmlspecialchars($btnIcon) . '</span>' : '') . '
                            ' . (strlen($btnLabel) > 0 ? '<span class="text-sm font-medium">' . htmlspecialchars($btnLabel) . '</span>' : '') . '
                        </a>';
                }

                $html .= '
                            </div>
                        </td>';
            }

            $html .= '
                    </tr>';
            $serialNumber++; // Increment serial number
        }

        $html .= '
                    </tbody>
                </table>
            </div>';

        // No content message
        if (empty($data)) {
            $html .= '
            <div class="px-6 py-12 text-center">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-gray-100 rounded-full mb-4">
                    <span class="material-symbols-outlined text-4xl text-gray-400">inbox</span>
                </div>
                <h3 class="text-lg font-medium text-gray-900 mb-2">' . T::no_content_available . '</h3>
                <p class="text-sm text-gray-500 mb-6">' . T::no_records_found_in_this_table . '</p>';

            // Show add button if enabled
            if (isset($this->actions['add']) && $this->actions['add']) {
                $addUrl = $this->action_urls['add'] ?? strtolower($this->table) . '/add';
                $addUrl = $this->replacePlaceholders($addUrl);
                $html .= '
                <a href="' . root . $addUrl . '" class="btn inline-flex">
                    <span class="material-symbols-outlined text-lg">add</span>
                    ' . T::create_new . '
                </a>';
            }

            $html .= '
            </div>';
        }

        // Pagination
        if ($totalPages > 1) {
            // Get current path without query parameters for pagination
            $currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

            $html .= '
            <div class="px-6 py-4 border-t border-gray-200 bg-gray-50 rounded-b-lg">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div class="text-sm text-gray-700">
                        Showing ' . (($page - 1) * $this->perPage + 1) . ' to ' . min($page * $this->perPage, $total) . ' of ' . number_format($total) . ' results
                    </div>
                    <div class="flex items-center gap-2">
                        <nav class="flex items-center gap-1 pagination-nav">';

            // Previous button
            if ($page > 1) {
                $prevPage = $page - 1;
                $params = ['page' => $prevPage];
                if ($search) $params['q'] = $search;
                if ($searchColumn !== 'all') $params['search_col'] = $searchColumn;
                $url = $currentPath . '?' . http_build_query($params);
                $html .= '<a href="' . $url . '" class="pagination-link px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg hover:bg-gray-50 text-gray-700">Previous</a>';
            }

            // Page numbers
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);

            if ($start > 1) {
                $params = ['page' => 1];
                if ($search) $params['q'] = $search;
                if ($searchColumn !== 'all') $params['search_col'] = $searchColumn;
                $url = $currentPath . '?' . http_build_query($params);
                $html .= '<a href="' . $url . '" class="pagination-link px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg hover:bg-gray-50">1</a>';
                if ($start > 2) $html .= '<span class="px-2 text-gray-500">...</span>';
            }

            for ($i = $start; $i <= $end; $i++) {
                $params = ['page' => $i];
                if ($search) $params['q'] = $search;
                if ($searchColumn !== 'all') $params['search_col'] = $searchColumn;
                $url = $currentPath . '?' . http_build_query($params);
                $active = $i == $page ? 'bg-primary text-primary-foreground' : 'bg-white text-gray-700 hover:bg-gray-50';
                $html .= '<a href="' . $url . '" class="pagination-link px-3 py-2 text-sm border border-gray-300 rounded-lg ' . $active . '">' . $i . '</a>';
            }

            if ($end < $totalPages) {
                if ($end < $totalPages - 1) $html .= '<span class="px-2 text-gray-500">...</span>';
                $params = ['page' => $totalPages];
                if ($search) $params['q'] = $search;
                if ($searchColumn !== 'all') $params['search_col'] = $searchColumn;
                $url = $currentPath . '?' . http_build_query($params);
                $html .= '<a href="' . $url . '" class="pagination-link px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg hover:bg-gray-50">' . $totalPages . '</a>';
            }

            // Next button
            if ($page < $totalPages) {
                $nextPage = $page + 1;
                $params = ['page' => $nextPage];
                if ($search) $params['q'] = $search;
                if ($searchColumn !== 'all') $params['search_col'] = $searchColumn;
                $url = $currentPath . '?' . http_build_query($params);
                $html .= '<a href="' . $url . '" class="pagination-link px-3 py-2 text-sm bg-white border border-gray-300 rounded-lg hover:bg-gray-50 text-gray-700">Next</a>';
            }

            $html .= '
                        </nav>
                    </div>
                </div>
            </div>';
        }

        $html .= '</div>';

        // Add JavaScript for hash preservation in pagination
        if ($totalPages > 1) {
            $html .= '
        <script>
        // Function to update pagination links with current hash
        function updatePaginationHash() {
            const hash = window.location.hash;
            document.querySelectorAll(".pagination-link").forEach(function(link) {
                // Remove any existing hash from the link
                const urlWithoutHash = link.href.split("#")[0];
                // Add current hash if it exists
                link.href = hash ? urlWithoutHash + hash : urlWithoutHash;
            });
        }

        // Update on page load
        document.addEventListener("DOMContentLoaded", updatePaginationHash);

        // Update whenever hash changes (when clicking tabs)
        window.addEventListener("hashchange", updatePaginationHash);

        // Also update immediately if DOM is already loaded
        if (document.readyState === "complete" || document.readyState === "interactive") {
            updatePaginationHash();
        }
        </script>';
        }

        // Add JavaScript for multi-select functionality
        $html .= '
        <script>
        let selectedRows = new Set();

        function toggleAllRows(checkbox) {
            const checkboxes = document.querySelectorAll(".row-checkbox");
            const bulkActions = document.getElementById("bulkActions");

            checkboxes.forEach(cb => {
                cb.checked = checkbox.checked;
                if (checkbox.checked) {
                    selectedRows.add(cb.value);
                    cb.closest("tr").classList.add("bg-gray-100");
                } else {
                    selectedRows.delete(cb.value);
                    cb.closest("tr").classList.remove("bg-gray-100");
                }
            });

            if (bulkActions) {
                bulkActions.classList.toggle("hidden", selectedRows.size === 0);
            }
        }

        function toggleRowSelection(checkbox) {
            const bulkActions = document.getElementById("bulkActions");
            const selectAll = document.getElementById("selectAll");

            if (checkbox.checked) {
                selectedRows.add(checkbox.value);
                checkbox.closest("tr").classList.add("bg-gray-100");
            } else {
                selectedRows.delete(checkbox.value);
                checkbox.closest("tr").classList.remove("bg-gray-100");
                if (selectAll) {
                    selectAll.checked = false;
                }
            }

            if (bulkActions) {
                bulkActions.classList.toggle("hidden", selectedRows.size === 0);
            }

            // Update "select all" checkbox state
            const totalCheckboxes = document.querySelectorAll(".row-checkbox").length;
            if (selectAll) {
                selectAll.checked = selectedRows.size === totalCheckboxes;
            }
        }

        function bulkDelete() {
            if (selectedRows.size === 0) return;

            if (confirm(`Are you sure you want to delete ${selectedRows.size} selected records?`)) {
                const table = "' . $this->table . '";
                const ids = Array.from(selectedRows);
                let successCount = 0;
                let failCount = 0;

                // Delete each record via AJAX
                const deletePromises = ids.map(id => {
                    return fetch("' . root . 'ajax", {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/json",
                        },
                        body: JSON.stringify({
                            action: "delete_record",
                            table: table,
                            id: id
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === "success") {
                            successCount++;
                            // Remove the row
                            const row = document.querySelector(`tr[data-id="${id}"]`);
                            if (row) {
                                row.style.transition = "all 0.3s ease-out";
                                row.style.opacity = "0";
                                setTimeout(() => row.remove(), 300);
                            }
                        } else {
                            failCount++;
                        }
                        return data;
                    })
                    .catch(error => {
                        console.error("Error deleting ID:", id, error);
                        failCount++;
                    });
                });

                // Wait for all deletions to complete
                Promise.all(deletePromises).then(() => {
                    selectedRows.clear();
                    const selectAllCheckbox = document.querySelector("thead input[type=\'checkbox\']");
                    if (selectAllCheckbox) selectAllCheckbox.checked = false;

                    if (successCount > 0) {
                        vt.success(`Successfully deleted ${successCount} record(s)`);
                    }
                    if (failCount > 0) {
                        vt.error(`Failed to delete ${failCount} record(s)`);
                    }

                    // Check if table is empty
                    const tbody = document.querySelector("tbody");
                    const remainingRows = tbody.querySelectorAll("tr");
                    if (remainingRows.length === 0) {
                        tbody.innerHTML = `
                            <tr>
                                <td colspan="100" class="text-center py-8 text-gray-500">
                                    <span class="material-symbols-outlined text-4xl mb-2 block">inbox</span>
                                    No records found
                                </td>
                            </tr>
                        `;
                    }
                });
            }
        }

        function selectAllColumns() {
            const checkboxes = document.querySelectorAll(".column-toggle");
            checkboxes.forEach(cb => {
                cb.checked = true;
                toggleColumn(cb.dataset.column, true);
            });
        }

        function toggleColumn(columnName, show) {
            // Target only table headers and cells, not the checkboxes
            const headers = document.querySelectorAll(`.column-header[data-column="${columnName}"]`);
            const cells = document.querySelectorAll(`.column-cell[data-column="${columnName}"]`);

            headers.forEach(element => {
                element.style.display = show ? "" : "none";
            });

            cells.forEach(element => {
                element.style.display = show ? "" : "none";
            });
        }

        // Handle shadow effect on scroll
        document.addEventListener("DOMContentLoaded", function() {
            const tableContainer = document.getElementById("tableContainer");
            const actionsHeader = document.getElementById("actionsHeader");
            const selectAllHeader = document.getElementById("selectAllHeader");
            const actionsCells = document.querySelectorAll(".actions-cell");
            const checkboxCells = document.querySelectorAll(".checkbox-cell");
            const columnToggleBtn = document.getElementById("columnToggleBtn");
            const columnDropdown = document.getElementById("columnDropdown");

            function updateShadowEffects() {
                const isScrolled = tableContainer.scrollLeft > 0;
                const isScrolledRight = tableContainer.scrollLeft < (tableContainer.scrollWidth - tableContainer.clientWidth);

                // Right side shadows for actions (when scrolled left)
                if (actionsHeader && actionsCells.length > 0) {
                    if (isScrolled) {
                        actionsHeader.classList.add("shadow-left");
                        actionsCells.forEach(cell => cell.classList.add("shadow-left"));
                    } else {
                        actionsHeader.classList.remove("shadow-left");
                        actionsCells.forEach(cell => cell.classList.remove("shadow-left"));
                    }
                }

                // Left side shadows for checkboxes (when scrolled right)
                if (selectAllHeader && checkboxCells.length > 0) {
                    if (isScrolledRight && isScrolled) {
                        selectAllHeader.classList.add("shadow-right");
                        checkboxCells.forEach(cell => cell.classList.add("shadow-right"));
                    } else {
                        selectAllHeader.classList.remove("shadow-right");
                        checkboxCells.forEach(cell => cell.classList.remove("shadow-right"));
                    }
                }
            }

            tableContainer.addEventListener("scroll", updateShadowEffects);
            updateShadowEffects(); // Check initial state

            // Column dropdown toggle
            if (columnToggleBtn && columnDropdown) {
                columnToggleBtn.addEventListener("click", function(e) {
                    e.stopPropagation();
                    columnDropdown.classList.toggle("hidden");
                });

                // Close dropdown when clicking outside
                document.addEventListener("click", function(e) {
                    if (!columnDropdown.contains(e.target) && !columnToggleBtn.contains(e.target)) {
                        columnDropdown.classList.add("hidden");
                    }
                });
            }

            // Handle column toggle checkboxes
            document.querySelectorAll(".column-toggle").forEach(checkbox => {
                checkbox.addEventListener("change", function() {
                    toggleColumn(this.dataset.column, this.checked);
                });
            });
        });

        // Handle status toggle switches with AJAX
        document.addEventListener("DOMContentLoaded", function() {
            const statusSwitches = document.querySelectorAll(".toggle-status-switch");

            statusSwitches.forEach(toggle => {
                toggle.addEventListener("change", function() {
                    const id = this.dataset.id;
                    const table = this.dataset.table;
                    const column = this.dataset.column;
                    const newStatus = this.checked ? 1 : 0;
                    const switchElement = this;

                    // Disable the switch during request
                    switchElement.disabled = true;

                    // Send AJAX request
                    fetch("' . root . 'ajax", {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/json",
                        },
                        body: JSON.stringify({
                            action: "toggle_status",
                            table: table,
                            column: column,
                            id: id,
                            status: newStatus
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === "success") {
                            if (column === "banned") {
                                vt.success("Banned updated successfully!");
                            } else {
                                vt.success("Status updated successfully!");
                            }
                            switchElement.disabled = false;
                        } else {
                            vt.error(data.message || (column === "banned" ? "Failed to update banned" : "Failed to update status"));
                            // Revert the switch on error
                            switchElement.checked = !switchElement.checked;
                            switchElement.disabled = false;
                        }
                    })
                    .catch(error => {
                        console.error("Error:", error);
                        vt.error("Error updating status");
                        // Revert the switch on error
                        switchElement.checked = !switchElement.checked;
                        switchElement.disabled = false;
                    });
                });
            });
        });

        // Handle default toggle switches with AJAX
        document.addEventListener("DOMContentLoaded", function() {
            const defaultSwitches = document.querySelectorAll(".toggle-default-switch");

            defaultSwitches.forEach(toggle => {
                toggle.addEventListener("change", function() {
                    const id = this.dataset.id;
                    const table = this.dataset.table;
                    const isChecked = this.checked;
                    const switchElement = this;

                    // Disable all switches during request
                    defaultSwitches.forEach(s => s.disabled = true);

                    // Send AJAX request
                    fetch("' . root . 'ajax", {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/json",
                        },
                        body: JSON.stringify({
                            action: "set_default",
                            table: table,
                            id: id
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === "success") {
                            vt.success("Default updated successfully!");

                            // Uncheck all other switches
                            defaultSwitches.forEach(s => {
                                if (s !== switchElement) {
                                    s.checked = false;
                                }
                            });

                            // Make sure current switch is checked
                            switchElement.checked = true;

                            // Re-enable all switches
                            defaultSwitches.forEach(s => s.disabled = false);
                        } else {
                            vt.error(data.message || "Failed to update default");

                            // Find which switch should remain checked (the current default)
                            // Keep all switches in their original state
                            defaultSwitches.forEach(s => {
                                // If this was the switch that was just clicked
                                if (s === switchElement) {
                                    // If it was trying to enable (turn on), revert it back to off
                                    if (isChecked) {
                                        s.checked = false;
                                    } else {
                                        // If it was trying to disable (turn off), keep it on
                                        s.checked = true;
                                    }
                                }
                            });

                            // Re-enable all switches
                            defaultSwitches.forEach(s => s.disabled = false);
                        }
                    })
                    .catch(error => {
                        console.error("Error:", error);
                        vt.error("Error updating default");

                        // Revert the switch state
                        switchElement.checked = !isChecked;

                        // Re-enable all switches
                        defaultSwitches.forEach(s => s.disabled = false);
                    });
                });
            });
        });

        // Handle delete button with AJAX
        document.addEventListener("DOMContentLoaded", function() {
            const deleteButtons = document.querySelectorAll(".delete-btn");

            deleteButtons.forEach(btn => {
                btn.addEventListener("click", function() {
                    const id = this.dataset.id;
                    const table = this.dataset.table;
                    const buttonElement = this;
                    const row = buttonElement.closest("tr");

                    // Disable the button during check
                    buttonElement.disabled = true;
                    buttonElement.style.opacity = "0.5";

                    // First check if record is default before showing confirmation
                    fetch("' . root . 'ajax", {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/json",
                        },
                        body: JSON.stringify({
                            action: "check_default",
                            table: table,
                            id: id
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        // Re-enable button
                        buttonElement.disabled = false;
                        buttonElement.style.opacity = "1";

                        if (data.is_default) {
                            // Show warning immediately if it\'s a default record
                            vt.warn(data.message || "Cannot delete the default record. Please set another record as default first.");
                            return;
                        }

                        // Not a default record, proceed with confirmation dialog
                        if (!confirm("Are you sure you want to delete this record? This action cannot be undone.")) {
                            return;
                        }

                        // Disable the button during deletion
                        buttonElement.disabled = true;
                        buttonElement.style.opacity = "0.5";

                        // Send delete request
                        fetch("' . root . 'ajax", {
                            method: "POST",
                            headers: {
                                "Content-Type": "application/json",
                            },
                            body: JSON.stringify({
                                action: "delete_record",
                                table: table,
                                id: id
                            })
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.status === "success") {
                                vt.success(data.message || "Record deleted successfully!");

                                // Remove the row from the table with fade out effect
                                row.style.transition = "all 0.4s ease-out";
                                row.style.opacity = "0";
                                row.style.transform = "translateX(-20px)";

                                setTimeout(() => {
                                    row.remove();

                                    // Check if table is empty
                                    const tbody = document.querySelector("tbody");
                                    const remainingRows = tbody.querySelectorAll("tr");
                                    if (remainingRows.length === 0) {
                                        // Show empty state message
                                        tbody.innerHTML = `
                                            <tr>
                                                <td colspan="100" class="text-center py-8 text-gray-500">
                                                    <span class="material-symbols-outlined text-4xl mb-2 block">inbox</span>
                                                    No records found
                                                </td>
                                            </tr>
                                        `;
                                    }
                                }, 400);
                            } else if (data.status === "warning") {
                                vt.warn(data.message || "Cannot delete this record");
                                buttonElement.disabled = false;
                                buttonElement.style.opacity = "1";
                            } else {
                                vt.error(data.message || "Failed to delete record");
                                buttonElement.disabled = false;
                                buttonElement.style.opacity = "1";
                            }
                        })
                        .catch(error => {
                            console.error("Error:", error);
                            vt.error("Error deleting record");
                            buttonElement.disabled = false;
                            buttonElement.style.opacity = "1";
                        });
                    })
                    .catch(error => {
                        console.error("Error:", error);
                        vt.error("Error checking record");
                        buttonElement.disabled = false;
                        buttonElement.style.opacity = "1";
                    });
                });
            });
        });
        </script>

        <style>
        .shadow-left {
            box-shadow: -8px 0 15px -3px rgba(0, 0, 0, 0.15), -4px 0 6px -2px rgba(0, 0, 0, 0.1) !important;
        }

        .shadow-right {
            box-shadow: 8px 0 15px -3px rgba(0, 0, 0, 0.15), 4px 0 6px -2px rgba(0, 0, 0, 0.1) !important;
        }

        .actions-cell {
            position: sticky !important;
            right: 0 !important;
            z-index: 30 !important;
            background-color: white !important;
        }

        .checkbox-cell {
            position: sticky !important;
            left: 0 !important;
            z-index: 25 !important;
            background-color: white !important;
        }

        thead .actions-cell,
        #actionsHeader {
            background-color: rgb(249, 250, 251) !important;
        }

        thead .checkbox-cell,
        #selectAllHeader {
            background-color: rgb(249, 250, 251) !important;
        }
        </style>';        return $html;
    }

    private function form($cols, $data, $action) {
        $html = '
        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
            <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 rounded-t-lg">
                <h2 class="text-xl font-semibold text-gray-900">' . ucfirst($action) . ' ' . rtrim($this->title, 's') . '</h2>
            </div>
            <div class="p-6">
                <form method="post" class="space-y-6">
                    <input type="hidden" name="action" value="' . ($action == 'edit' ? 'update' : 'create') . '">';

        if ($action == 'edit') {
            $html .= '<input type="hidden" name="id" value="' . htmlspecialchars($data['id'] ?? '') . '">';
        }

        foreach ($cols as $col) {
            if ($col == 'id' && $action == 'create') continue;

            $label = isset($this->col_labels[$col]) ? $this->col_labels[$col] : ucfirst(str_replace('_', ' ', $col));
            $value = htmlspecialchars(($data[$col] ?? '') ?: '');
            $readonly = ($col == 'id' || $action == 'view') ? 'readonly' : '';
            $required = ($col !== 'id' && $action !== 'view') ? 'required' : '';

            $html .= '
                    <div>
                        <label for="' . $col . '" class="block text-sm font-medium text-gray-700 mb-2">' . $label . '</label>';

            if (strpos($col, 'description') !== false || strpos($col, 'content') !== false || strpos($col, 'text') !== false) {
                $html .= '<textarea name="' . $col . '" id="' . $col . '" rows="4" ' . $readonly . ' ' . $required . '
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 disabled:bg-gray-50">' . $value . '</textarea>';
            } else {
                $type = 'text';
                if (strpos($col, 'email') !== false) $type = 'email';
                elseif (strpos($col, 'password') !== false) $type = 'password';
                elseif (strpos($col, 'date') !== false) $type = 'date';
                elseif (strpos($col, 'time') !== false) $type = 'time';
                elseif (strpos($col, 'url') !== false) $type = 'url';

                $html .= '<input type="' . $type . '" name="' . $col . '" id="' . $col . '" value="' . $value . '" ' . $readonly . ' ' . $required . '
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 disabled:bg-gray-50">';
            }

            $html .= '</div>';
        }

        $html .= '
                    <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-200">
                        <a href="../" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                            ' . ($action == 'view' ? 'Back' : 'Cancel') . '
                        </a>';

        if ($action !== 'view') {
            $html .= '
                        <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition-colors">
                            ' . ucfirst($action) . ' Record
                        </button>';
        }

        $html .= '
                    </div>
                </form>
            </div>
        </div>';

        return $html;
    }
}

// Global instance
$GLOBALS['crud'] = new CRUD();

// Helper function to get CRUD instance without using global keyword
function crud() {
    return $GLOBALS['crud'];
}

return $GLOBALS['crud'];