<?php
header('Content-Type: application/json');

// Configuration
$uploadDir = __DIR__ . '/uploads/';
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$maxFileSize = 5 * 1024 * 1024; // 5MB
$htmlFile = __DIR__ . '/index.html';

// Create uploads directory if it doesn't exist
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Response function
function sendResponse($success, $message, $data = null) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

// Function to create backup
function createBackup($htmlFile) {
    $backupDir = __DIR__ . '/backups/';
    
    // Create backups directory if it doesn't exist
    if (!file_exists($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    
    $filename = basename($htmlFile);
    $backupFile = $backupDir . $filename . '.backup.' . date('Ymd_His');
    
    if (!copy($htmlFile, $backupFile)) {
        return ['success' => false, 'message' => 'Failed to create backup'];
    }
    
    // Clean up old backups (keep only last 10)
    $backups = glob($backupDir . $filename . '.backup.*');
    if (count($backups) > 10) {
        usort($backups, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        foreach (array_slice($backups, 0, -10) as $oldBackup) {
            @unlink($oldBackup);
        }
    }
    
    return ['success' => true, 'message' => 'Backup created'];
}

// Normalize $_FILES array to a flat array of files
function normalizeFilesArray($files) {
    if (!isset($files['name'])) {
        return [];
    }

    // Single file
    if (!is_array($files['name'])) {
        return [$files];
    }

    $normalized = [];
    $fileCount = count($files['name']);
    for ($i = 0; $i < $fileCount; $i++) {
        $normalized[] = [
            'name' => $files['name'][$i],
            'type' => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'error' => $files['error'][$i],
            'size' => $files['size'][$i]
        ];
    }

    return $normalized;
}

// Function to update HTML file with multiple images (first image becomes display image)
function updateHtmlImages($htmlFile, $projectId, $imagePaths) {
    $backupResult = createBackup($htmlFile);
    if (!$backupResult['success']) {
        return $backupResult;
    }
    
    $htmlContent = file_get_contents($htmlFile);
    if ($htmlContent === false) {
        return ['success' => false, 'message' => 'Failed to read HTML file'];
    }
    
    $pattern = '/<div class="project-card card h-100" data-project-id="' . preg_quote($projectId, '/') . '">(.*?)<\/div>\s*<\/div>\s*<\/div>/s';

    if (!preg_match($pattern, $htmlContent, $matches)) {
        return ['success' => false, 'message' => 'Card not found in HTML'];
    }

    if (empty($imagePaths)) {
        return ['success' => false, 'message' => 'No images provided'];
    }

    $cardContent = $matches[1];

    // Determine alt text from existing image if present
    $altText = $projectId;
    if (preg_match('/alt="([^"]*)"/i', $cardContent, $altMatch)) {
        $altText = $altMatch[1];
    }

    $galleryJson = htmlspecialchars(json_encode($imagePaths), ENT_QUOTES);
    $firstImage = $imagePaths[0];
    $totalImages = count($imagePaths);

    $replacement = '<div class="text-center project-gallery" data-images=\'' . $galleryJson . '\'>
            <a href="' . $firstImage . '" target="_blank" class="project-image-link" data-images=\'' . $galleryJson . '\'>
                <img src="' . $firstImage . '" alt="' . $altText . '" class="img-fluid rounded mb-2" style="max-height:150px;" data-images=\'' . $galleryJson . '\'>
            </a>
            <div class="text-center small text-muted mb-2">Image 1 of ' . $totalImages . '</div>
        </div>';

    // Remove ALL existing gallery/image blocks from the card first
    $imageBlockPattern = '/<div class="text-center[^"]*"[^>]*>.*?<\/div>\s*(?=<h[1-6]|<div class="text-center mb-3">|$)/s';
    $cardContent = preg_replace($imageBlockPattern, '', $cardContent);
    
    // Inject the single new gallery block after card-body opening
    $cardBodyPattern = '/(<div class="card-body[^>]*>)/s';
    $cardContent = preg_replace($cardBodyPattern, '$1' . "\n                            " . $replacement, $cardContent, 1, $count);
    
    // Fallback: prepend if injection failed
    if ($count === 0) {
        $cardContent = $replacement . "\n" . $cardContent;
    }

    $updatedHtml = preg_replace($pattern, '<div class="project-card card h-100" data-project-id="' . $projectId . '">' . $cardContent . '</div></div></div>', $htmlContent, 1);
    
    if (file_put_contents($htmlFile, $updatedHtml) === false) {
        return ['success' => false, 'message' => 'Failed to write HTML file'];
    }
    
    return ['success' => true, 'message' => 'HTML file updated successfully'];
}

// Remove a specific image from a project's gallery and update HTML
function removeProjectImage($htmlFile, $projectId, $imagePath) {
    $htmlContent = file_get_contents($htmlFile);
    if ($htmlContent === false) {
        return ['success' => false, 'message' => 'Failed to read HTML file'];
    }

    $pattern = '/<div class="project-card card h-100" data-project-id="' . preg_quote($projectId, '/') . '">(.*?)<\/div>\s*<\/div>\s*<\/div>/s';
    if (!preg_match($pattern, $htmlContent, $matches)) {
        return ['success' => false, 'message' => 'Card not found'];
    }

    $cardHtml = $matches[0];
    $imagesJson = null;
    if (preg_match('/data-images=\'(.*?)\'/s', $cardHtml, $imgMatch)) {
        $imagesJson = html_entity_decode($imgMatch[1], ENT_QUOTES);
    } elseif (preg_match('/data-images="(.*?)"/s', $cardHtml, $imgMatch)) {
        $imagesJson = html_entity_decode($imgMatch[1], ENT_QUOTES);
    }

    if ($imagesJson === null) {
        return ['success' => false, 'message' => 'No gallery data found'];
    }

    $images = json_decode($imagesJson, true);
    if (!is_array($images)) {
        return ['success' => false, 'message' => 'Invalid gallery data'];
    }

    $originalCount = count($images);
    $images = array_values(array_filter($images, function($img) use ($imagePath) {
        return $img !== $imagePath;
    }));

    if (count($images) === $originalCount) {
        return ['success' => false, 'message' => 'Image not found in gallery'];
    }

    if (empty($images)) {
        $images = ['./placeholder.png'];
    }

    $result = updateHtmlImages($htmlFile, $projectId, $images);
    if (!$result['success']) {
        return $result;
    }

    // Delete file from uploads folder if it resides there
    $normalized = str_replace(['../', '..\\'], '', $imagePath);
    $fullPath = realpath(__DIR__ . '/' . ltrim($normalized, './'));
    if ($fullPath && strpos($fullPath, realpath(__DIR__ . '/uploads/')) === 0 && file_exists($fullPath)) {
        @unlink($fullPath);
    }

    return ['success' => true, 'message' => 'Image removed'];
}

// Function to add new project card
function addProjectCard($htmlFile, $cardData) {
    $backupResult = createBackup($htmlFile);
    if (!$backupResult['success']) {
        return $backupResult;
    }
    
    $htmlContent = file_get_contents($htmlFile);
    if ($htmlContent === false) {
        return ['success' => false, 'message' => 'Failed to read HTML file'];
    }
    
    $projectId = isset($cardData['id']) ? $cardData['id'] : 'project-' . uniqid();
    $title = htmlspecialchars($cardData['title'] ?? 'New Project');
    $description = htmlspecialchars($cardData['description'] ?? 'Project description');
    $category = htmlspecialchars($cardData['category'] ?? 'General');
    $imagePath = $cardData['image'] ?? './placeholder.png';
    $galleryJson = htmlspecialchars(json_encode([$imagePath]), ENT_QUOTES);
    $badges = isset($cardData['badges']) ? $cardData['badges'] : [];
    
    $badgesHtml = '';
    foreach ($badges as $badge) {
        $badgeText = htmlspecialchars($badge);
        $badgesHtml .= '<span class="badge bg-primary me-1 mb-1">' . $badgeText . '</span>';
    }
    
    $cardCategory = strtolower($cardData['card_category'] ?? 'flagship-main');
    $columnClass = 'col-lg-6 mb-3';
    $newCard = '
                <div class="' . $columnClass . '">
                    <div class="project-card card h-100" data-project-id="' . $projectId . '">
                        <button class="upload-btn-card" onclick="openUploadModal(\'' . $projectId . '\')" title="Upload Image (Ctrl+Alt+E)">
                            <i class="fas fa-camera"></i>
                        </button>
                        <button class="delete-btn-card" onclick="deleteCard(\'' . $projectId . '\')" title="Delete Card">
                            <i class="fas fa-trash"></i>
                        </button>
                        <div class="card-body p-3">
                            <div class="text-center project-gallery" data-images="' . $galleryJson . '">
                                <a href="' . $imagePath . '" target="_blank" class="project-image-link" data-images="' . $galleryJson . '">
                                    <img src="' . $imagePath . '" alt="' . $title . '" class="img-fluid rounded mb-2" style="max-height:150px;" data-images="' . $galleryJson . '">
                                </a>
                                <div class="text-center small text-muted mb-2">Image 1 of 1</div>
                            </div>
                            <h5 class="card-title fw-bold text-center text-primary">' . $title . '</h5>
                            <h6 class="text-center text-warning mb-3">' . $category . '</h6>
                            <p class="card-text text-center">' . $description . '</p>
                            <div class="text-center">
                                ' . $badgesHtml . '
                            </div>
                        </div>
                    </div>
                </div>';
    
    // All cards go into the single flagship row
    $searchPattern = '/(<div\s+class="row[^>]*"\s+id="flagshipMainRow"[^>]*>)/i';
    $sectionName = 'Flagship Projects';
    
    if (preg_match($searchPattern, $htmlContent)) {
        // For all categories, add the card to the existing row
        $updatedContent = preg_replace($searchPattern, '$1' . $newCard, $htmlContent, 1);
    } else {
        return ['success' => false, 'message' => 'Could not find insertion point for ' . $sectionName];
    }
    
    if (file_put_contents($htmlFile, $updatedContent) === false) {
        return ['success' => false, 'message' => 'Failed to write HTML file'];
    }
    
    return ['success' => true, 'message' => 'New card added to ' . $sectionName . ' successfully', 'project_id' => $projectId];
}

// Function to edit card text
function editCardText($htmlFile, $projectId, $updates) {
    $backupResult = createBackup($htmlFile);
    if (!$backupResult['success']) {
        return $backupResult;
    }
    
    $htmlContent = file_get_contents($htmlFile);
    if ($htmlContent === false) {
        return ['success' => false, 'message' => 'Failed to read HTML file'];
    }
    
    // Find the card with the specific project ID
    $pattern = '/<div class="project-card card h-100" data-project-id="' . preg_quote($projectId, '/') . '">(.*?)<\/div>\s*<\/div>\s*<\/div>/s';
    
    if (!preg_match($pattern, $htmlContent, $matches)) {
        return ['success' => false, 'message' => 'Card not found'];
    }
    
    $cardContent = $matches[1];
    
    // Update title if provided
    if (isset($updates['title'])) {
        $newTitle = htmlspecialchars($updates['title']);
        $cardContent = preg_replace(
            '/<h[56] class="card-title[^"]*">.*?<\/h[56]>/s',
            '<h6 class="card-title fw-bold text-center">' . $newTitle . '</h6>',
            $cardContent
        );
        // Also update alt text
        $cardContent = preg_replace(
            '/alt="[^"]*"/',
            'alt="' . $newTitle . '"',
            $cardContent,
            1
        );
    }
    
    // Update description if provided
    if (isset($updates['description'])) {
        $newDesc = htmlspecialchars($updates['description']);
        $cardContent = preg_replace(
            '/<p class="card-text[^"]*">.*?<\/p>/s',
            '<p class="card-text text-center small">' . $newDesc . '</p>',
            $cardContent
        );
    }
    
    // Update badges if provided
    if (isset($updates['badges']) && is_array($updates['badges'])) {
        $badgesHtml = '';
        foreach ($updates['badges'] as $badge) {
            $badgeText = htmlspecialchars($badge);
            $badgesHtml .= '<span class="badge bg-primary me-1 mb-1">' . $badgeText . '</span>';
        }
        
        $cardContent = preg_replace(
            '/<div class="text-center">\s*<span class="badge.*?<\/div>/s',
            '<div class="text-center">' . $badgesHtml . '</div>',
            $cardContent
        );
    }
    
    // Replace the old card content with updated content
    $updatedHtml = preg_replace($pattern, '<div class="project-card card h-100" data-project-id="' . $projectId . '">' . $cardContent . '</div></div></div>', $htmlContent, 1);
    
    if (file_put_contents($htmlFile, $updatedHtml) === false) {
        return ['success' => false, 'message' => 'Failed to write HTML file'];
    }
    
    return ['success' => true, 'message' => 'Card updated successfully'];
}

// Delete project card
function deleteProjectCard($htmlFile, $projectId) {
    // Create backup first
    createBackup($htmlFile);
    
    // Read HTML content
    $htmlContent = file_get_contents($htmlFile);
    if ($htmlContent === false) {
        return ['success' => false, 'message' => 'Failed to read HTML file'];
    }
    
    // Pattern to match the exact card structure as created by addProjectCard
    // We need to match the complete card including all nested divs
    $escapedId = preg_quote($projectId, '/');
    
    // Match the structure explicitly:
    // 1. Column wrapper div
    // 2. Project card div with buttons
    // 3. Card body with all nested content
    // 4. All three closing divs
    $pattern = '/\s*<div class="col-lg-6 mb-3">\s*<div class="project-card card h-100" data-project-id="' . $escapedId . '">\s*<button.*?<\/button>\s*<button.*?<\/button>\s*<div class="card-body.*?<\/div>\s*<\/div>\s*<\/div>\s*<\/div>/s';
    
    if (!preg_match($pattern, $htmlContent)) {
        return ['success' => false, 'message' => 'Card not found'];
    }
    
    $updatedContent = preg_replace($pattern, "\n", $htmlContent, 1);
    
    if (file_put_contents($htmlFile, $updatedContent) === false) {
        return ['success' => false, 'message' => 'Failed to write HTML file'];
    }
    
    return ['success' => true, 'message' => 'Card deleted successfully'];
}

// Handle different actions
$action = $_POST['action'] ?? 'upload';

switch ($action) {
    case 'upload':
        // Handle image upload (single or multiple)
        $projectId = $_POST['project_id'] ?? null;

        // Prefer multiple files via images[] but keep backward compatibility with image
        $files = [];
        if (isset($_FILES['images'])) {
            $files = normalizeFilesArray($_FILES['images']);
        } elseif (isset($_FILES['image'])) {
            $files = normalizeFilesArray($_FILES['image']);
        }

        if (empty($files)) {
            sendResponse(false, 'No file uploaded');
        }

        $savedPaths = [];
        foreach ($files as $file) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                sendResponse(false, 'Upload error: ' . $file['error']);
            }

            if ($file['size'] > $maxFileSize) {
                sendResponse(false, 'File size exceeds 5MB limit');
            }

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mimeType, $allowedTypes)) {
                sendResponse(false, 'Invalid file type. Only JPEG, PNG, GIF, and WebP are allowed');
            }

            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $newFilename = uniqid('project_', true) . '.' . $extension;
            $targetPath = $uploadDir . $newFilename;

            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                sendResponse(false, 'Failed to save file');
            }

            $savedPaths[] = './uploads/' . $newFilename;
        }

        $htmlUpdateResult = ['success' => true, 'message' => 'No HTML update requested'];
        if ($projectId) {
            $htmlUpdateResult = updateHtmlImages($htmlFile, $projectId, $savedPaths);
        }

        sendResponse(true, 'Files uploaded successfully', [
            'paths' => $savedPaths,
            'first_image' => $savedPaths[0] ?? null,
            'project_id' => $projectId,
            'html_updated' => $htmlUpdateResult['success'],
            'html_message' => $htmlUpdateResult['message']
        ]);
        break;

    case 'delete_image':
        $projectId = $_POST['project_id'] ?? null;
        $imagePath = $_POST['image_path'] ?? null;

        if (!$projectId || !$imagePath) {
            sendResponse(false, 'Project ID and image path are required');
        }

        $backupResult = createBackup($htmlFile);
        if (!$backupResult['success']) {
            sendResponse(false, 'Failed to create backup: ' . $backupResult['message']);
        }

        $result = removeProjectImage($htmlFile, $projectId, $imagePath);
        if ($result['success']) {
            sendResponse(true, 'Image deleted', ['project_id' => $projectId, 'image_path' => $imagePath]);
        } else {
            sendResponse(false, $result['message']);
        }
        break;
        
    case 'add_card':
        // Handle adding new card with image upload
        $title = $_POST['title'] ?? '';
        $category = $_POST['category'] ?? '';
        $description = $_POST['description'] ?? '';
        $badges = json_decode($_POST['badges'] ?? '[]', true);
        $cardCategory = $_POST['card_category'] ?? 'flagship-main';
        
        $imagePath = './placeholder.png'; // Default image
        
        // Handle image upload if provided
        if (isset($_FILES['card_image']) && $_FILES['card_image']['error'] === UPLOAD_ERR_OK) {
            $uploadedFile = $_FILES['card_image'];
            
            // Validate image
            $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
            $fileMimeType = finfo_file($fileInfo, $uploadedFile['tmp_name']);
            finfo_close($fileInfo);
            
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($fileMimeType, $allowedTypes)) {
                sendResponse(false, 'Invalid image type. Only JPEG, PNG, GIF, and WebP allowed');
            }
            
            if ($uploadedFile['size'] > 5 * 1024 * 1024) {
                sendResponse(false, 'Image too large. Maximum 5MB allowed');
            }
            
            // Generate unique filename
            $extension = pathinfo($uploadedFile['name'], PATHINFO_EXTENSION);
            $newFilename = 'card_' . uniqid() . '.' . $extension;
            $uploadPath = $uploadDir . $newFilename;
            
            if (!move_uploaded_file($uploadedFile['tmp_name'], $uploadPath)) {
                sendResponse(false, 'Failed to save uploaded image');
            }
            
            $imagePath = './uploads/' . $newFilename;
        }
        
        $cardData = [
            'title' => $title,
            'category' => $category,
            'description' => $description,
            'badges' => $badges,
            'image' => $imagePath,
            'card_category' => $cardCategory
        ];
        
        $result = addProjectCard($htmlFile, $cardData);
        sendResponse($result['success'], $result['message'], $result);
        break;
        
    case 'edit_card':
        // Handle editing card text
        $projectId = $_POST['project_id'] ?? null;
        $updates = json_decode($_POST['updates'] ?? '{}', true);
        
        if (!$projectId) {
            sendResponse(false, 'Project ID is required');
        }
        
        $result = editCardText($htmlFile, $projectId, $updates);
        sendResponse($result['success'], $result['message']);
        break;
        
    case 'save_all_edits':
        // Handle saving all inline edits
        $edits = json_decode($_POST['edits'] ?? '[]', true);
        
        if (empty($edits)) {
            sendResponse(false, 'No edits to save');
        }
        
        $backupResult = createBackup($htmlFile);
        if (!$backupResult['success']) {
            sendResponse(false, 'Failed to create backup: ' . $backupResult['message']);
        }
        
        $htmlContent = file_get_contents($htmlFile);
        if ($htmlContent === false) {
            sendResponse(false, 'Failed to read HTML file');
        }
        
        $successCount = 0;
        $errorCount = 0;
        
        foreach ($edits as $edit) {
            $selector = $edit['selector'] ?? '';
            $oldText = $edit['oldText'] ?? '';
            $newText = $edit['newText'] ?? '';
            $useHtml = $edit['useHtml'] ?? false;
            
            if (!$selector || !$oldText || $oldText === $newText) {
                continue;
            }
            
            // For About Me sections with IDs, use more specific targeting
            if (in_array($selector, ['personalInfo', 'educationInfo', 'focusAreas'])) {
                // Match the specific div by ID and replace its content
                $pattern = '/(<div[^>]*id="' . preg_quote($selector, '/') . '"[^>]*>)(.*?)(<\/div>)/s';
                $replacement = '$1' . $newText . '$3';
                $newHtmlContent = preg_replace($pattern, $replacement, $htmlContent, 1, $count);
                
                if ($count > 0) {
                    $htmlContent = $newHtmlContent;
                    $successCount++;
                } else {
                    $errorCount++;
                }
            } else {
                // For other elements, use the old text replacement method
                // Escape special characters for regex
                $oldTextEscaped = preg_quote($oldText, '/');
                
                // Try to find and replace the text
                $pattern = '/' . $oldTextEscaped . '/u';
                $newHtmlContent = preg_replace($pattern, $newText, $htmlContent, 1, $count);
                
                if ($count > 0) {
                    $htmlContent = $newHtmlContent;
                    $successCount++;
                } else {
                    $errorCount++;
                }
            }
        }
        
        if ($successCount > 0) {
            if (file_put_contents($htmlFile, $htmlContent) === false) {
                sendResponse(false, 'Failed to write HTML file');
            }
        }
        
        sendResponse(true, "Saved $successCount edits successfully" . ($errorCount > 0 ? " ($errorCount failed)" : ''), [
            'success_count' => $successCount,
            'error_count' => $errorCount
        ]);
        break;
    
    case 'delete_card':
        $projectId = $_POST['project_id'] ?? '';
        
        if (!$projectId) {
            sendResponse(false, 'Project ID required');
        }
        
        $result = deleteProjectCard($htmlFile, $projectId);
        
        if ($result['success']) {
            sendResponse(true, $result['message']);
        } else {
            sendResponse(false, $result['message']);
        }
        break;
    
    case 'update_profile':
        // Handle profile picture upload
        if (!isset($_FILES['image']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
            sendResponse(false, 'No file uploaded');
        }
        
        $file = $_FILES['image'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            sendResponse(false, 'Upload error: ' . $file['error']);
        }
        
        if ($file['size'] > $maxFileSize) {
            sendResponse(false, 'File size exceeds 5MB limit');
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedTypes)) {
            sendResponse(false, 'Invalid file type. Only JPEG, PNG, GIF, and WebP are allowed');
        }
        
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $newFilename = 'RUSSEL.' . $extension;
        $targetPath = __DIR__ . '/' . $newFilename;
        
        // Backup old profile picture if exists
        if (file_exists(__DIR__ . '/RUSSEL.jpg')) {
            $backupName = 'RUSSEL_backup_' . date('Ymd_His') . '.jpg';
            copy(__DIR__ . '/RUSSEL.jpg', __DIR__ . '/' . $backupName);
        }
        
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            sendResponse(false, 'Failed to save file');
        }
        
        // Update HTML to reference new profile picture
        $backupResult = createBackup($htmlFile);
        if (!$backupResult['success']) {
            sendResponse(false, 'Failed to create backup: ' . $backupResult['message']);
        }
        
        $htmlContent = file_get_contents($htmlFile);
        if ($htmlContent === false) {
            sendResponse(false, 'Failed to read HTML file');
        }
        
        // Update profile picture references (both navbar and hero)
        $imagePath = './' . $newFilename;
        
        // Update navbar profile picture
        $htmlContent = preg_replace(
            '/<img\s+id="profilePicture"\s+src="[^"]+"/i',
            '<img id="profilePicture" src="' . $imagePath . '"',
            $htmlContent
        );
        
        // Update hero profile picture
        $htmlContent = preg_replace(
            '/<img\s+id="heroProfilePicture"\s+src="[^"]+"/i',
            '<img id="heroProfilePicture" src="' . $imagePath . '"',
            $htmlContent
        );
        
        if (file_put_contents($htmlFile, $htmlContent) === false) {
            sendResponse(false, 'Failed to update HTML file');
        }
        
        sendResponse(true, 'Profile picture updated successfully', [
            'filename' => $newFilename,
            'path' => $imagePath
        ]);
        break;
        
    default:
        sendResponse(false, 'Invalid action');
}
?>
