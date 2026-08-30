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

        // Validate file size (5MB max)
        $maxSize = 5 * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            throw new Exception('File size should not exceed 5MB');
        }

        // Validate file type
        $allowedMimes = [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/svg+xml',
            'image/webp',
            'application/pdf'
        ];
        $allowedExtensions = ['pdf', 'png', 'jpg', 'jpeg', 'svg', 'webp'];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($mimeType, $allowedMimes) || !in_array($extension, $allowedExtensions)) {
            throw new Exception('Invalid file type. Only PDF, PNG, JPG, JPEG, SVG, and WebP files are allowed');
        }

        // Create upload directory
        $uploadDir = uploads . 'visa/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Generate unique filename
        $filename = uniqid('visa_' . $fieldType . '_') . '.' . $extension;
        $uploadPath = $uploadDir . $filename;

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
            throw new Exception('Failed to save file');
        }

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
