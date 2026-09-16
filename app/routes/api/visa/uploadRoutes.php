<?php
// ============================================================================
// FILE: app/routes/api/visa/uploadRoutes.php
// VISA DOCUMENT UPLOAD API
// ============================================================================
@$SECURE or die('Access Denied!');

$router->post('/api/visas/upload-document', function () use ($db) {

    header('Content-Type: application/json');

    try {

        // Check if file was uploaded
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('No file uploaded or upload error occurred');
        }

        $file = $_FILES['file'];
        $fieldType = $_POST['field_type'] ?? '';
        $travelerIndex = $_POST['traveler_index'] ?? '';

        // Validate field type
        if (!in_array($fieldType, ['passport_copy', 'national_id_front_copy', 'national_id_back_copy'])) {
            throw new Exception('Invalid field type. Must be passport_copy, national_id_front_copy, or national_id_back_copy');
        }

        // SECURITY: validate via the canonical secureUploadCheck() — verifies
        // is_uploaded_file, size, that the real MIME matches the extension, and
        // rejects PHP polyglots. Crucially it does NOT allow SVG: an SVG can carry
        // <script> and, served inline from /uploads, becomes stored XSS in our
        // origin. This is a customer-facing endpoint, so SVG must never be accepted.
        $chk = secureUploadCheck($file, ['pdf', 'png', 'jpg', 'jpeg', 'webp'], 5 * 1024 * 1024);
        if (!$chk['ok']) {
            throw new Exception($chk['error'] ?? 'Invalid file.');
        }
        $extension = $chk['ext'];

        // Create upload directory
        $uploadDir = uploads . 'visa/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Generate unique filename (extension comes from the verified real MIME,
        // never from the client-supplied name).
        $filename = 'visa_' . $fieldType . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $uploadPath = $uploadDir . $filename;

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
            throw new Exception('Failed to save file');
        }
        @chmod($uploadPath, 0644);

        echo json_encode([
            'success' => true,
            'message' => 'File uploaded successfully',
            'file_path' => 'uploads/visa/' . $filename,
            'file_name' => $file['name']
        ]);

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

    exit;
});
