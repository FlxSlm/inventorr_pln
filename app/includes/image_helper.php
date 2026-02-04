<?php
// app/includes/image_helper.php
// Helper functions for image compression and processing

/**
 * Compress and save an uploaded image
 * @param string $sourcePath Temporary file path
 * @param string $destPath Destination file path  
 * @param int $maxWidth Maximum width (default 1200px)
 * @param int $quality JPEG quality (default 80)
 * @return bool Success status
 */
function compressImage($sourcePath, $destPath, $maxWidth = 1200, $quality = 80) {
    // Get image info
    $imageInfo = getimagesize($sourcePath);
    if (!$imageInfo) {
        return false;
    }
    
    $mimeType = $imageInfo['mime'];
    $origWidth = $imageInfo[0];
    $origHeight = $imageInfo[1];
    
    // Create image resource based on type
    switch ($mimeType) {
        case 'image/jpeg':
            $sourceImage = imagecreatefromjpeg($sourcePath);
            break;
        case 'image/png':
            $sourceImage = imagecreatefrompng($sourcePath);
            break;
        case 'image/gif':
            $sourceImage = imagecreatefromgif($sourcePath);
            break;
        case 'image/webp':
            $sourceImage = imagecreatefromwebp($sourcePath);
            break;
        default:
            return false;
    }
    
    if (!$sourceImage) {
        return false;
    }
    
    // Calculate new dimensions if image is larger than max width
    if ($origWidth > $maxWidth) {
        $newWidth = $maxWidth;
        $newHeight = (int)(($origHeight / $origWidth) * $newWidth);
    } else {
        $newWidth = $origWidth;
        $newHeight = $origHeight;
    }
    
    // Create new image with new dimensions
    $newImage = imagecreatetruecolor($newWidth, $newHeight);
    
    // Preserve transparency for PNG and GIF
    if ($mimeType === 'image/png' || $mimeType === 'image/gif') {
        imagealphablending($newImage, false);
        imagesavealpha($newImage, true);
        $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
        imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
    }
    
    // Resample image
    imagecopyresampled(
        $newImage, $sourceImage,
        0, 0, 0, 0,
        $newWidth, $newHeight,
        $origWidth, $origHeight
    );
    
    // Get file extension from destination path
    $ext = strtolower(pathinfo($destPath, PATHINFO_EXTENSION));
    
    // Save compressed image
    $result = false;
    switch ($ext) {
        case 'jpg':
        case 'jpeg':
            $result = imagejpeg($newImage, $destPath, $quality);
            break;
        case 'png':
            // PNG quality is 0-9, convert from percentage
            $pngQuality = (int)((100 - $quality) / 10);
            if ($pngQuality < 0) $pngQuality = 0;
            if ($pngQuality > 9) $pngQuality = 9;
            $result = imagepng($newImage, $destPath, $pngQuality);
            break;
        case 'gif':
            $result = imagegif($newImage, $destPath);
            break;
        case 'webp':
            $result = imagewebp($newImage, $destPath, $quality);
            break;
        default:
            // Default to JPEG
            $result = imagejpeg($newImage, $destPath, $quality);
    }
    
    // Clean up
    imagedestroy($sourceImage);
    imagedestroy($newImage);
    
    return $result;
}

/**
 * Process uploaded image with compression
 * @param array $file $_FILES array element
 * @param string $uploadDir Upload directory path
 * @param string $prefix File name prefix
 * @return array ['success' => bool, 'filename' => string, 'error' => string]
 */
function processUploadedImage($file, $uploadDir, $prefix = 'img') {
    $result = ['success' => false, 'filename' => '', 'error' => ''];
    
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $result['error'] = 'Gagal mengupload file.';
        return $result;
    }
    
    // Validate file type
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $fileType = mime_content_type($file['tmp_name']);
    
    if (!in_array($fileType, $allowedTypes)) {
        $result['error'] = 'Format gambar tidak valid. Gunakan JPG, PNG, GIF, atau WEBP.';
        return $result;
    }
    
    // Check file size (max 10MB before compression)
    if ($file['size'] > 10 * 1024 * 1024) {
        $result['error'] = 'Ukuran file terlalu besar. Maksimal 10MB.';
        return $result;
    }
    
    // Create upload directory if not exists
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0777, true)) {
            $result['error'] = 'Gagal membuat folder upload.';
            return $result;
        }
    }
    
    // Generate unique filename
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    // Convert to JPEG for better compression if PNG or others
    if ($ext !== 'jpg' && $ext !== 'jpeg' && $ext !== 'webp') {
        $ext = 'jpg';
    }
    $filename = $prefix . '_' . uniqid() . '_' . time() . '.' . $ext;
    $destPath = $uploadDir . $filename;
    
    // Compress and save
    if (compressImage($file['tmp_name'], $destPath, 1200, 80)) {
        $result['success'] = true;
        $result['filename'] = $filename;
    } else {
        // Fallback to simple move if compression fails
        if (move_uploaded_file($file['tmp_name'], $destPath)) {
            $result['success'] = true;
            $result['filename'] = $filename;
        } else {
            $result['error'] = 'Gagal menyimpan gambar.';
        }
    }
    
    return $result;
}

/**
 * Delete an image file
 * @param string $uploadDir Upload directory path
 * @param string $filename Filename to delete
 * @return bool Success status
 */
function deleteImageFile($uploadDir, $filename) {
    if (empty($filename)) return true;
    
    $filepath = $uploadDir . $filename;
    if (file_exists($filepath)) {
        return unlink($filepath);
    }
    return true;
}
