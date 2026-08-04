<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/helpers/csrf_helper.php';
require_once __DIR__ . '/../app/controllers/PublicReviewController.php';

header('Content-Type: application/json');

$csrfToken = $_POST['csrf_token'] ?? '';
if (!csrfValidateToken($csrfToken)) {
    echo json_encode(['ok' => false, 'message' => 'CSRF validation failed.']);
    exit;
}

try {
    $controller = new PublicReviewController();
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'rate':
            $controller->handleRatingAjax();
            break;
        case 'submit_feedback':
            $controller->submitFeedbackAjax();
            break;
        case 'get_positive_review':
            $controller->getPositiveReviewAjax();
            break;
        case 'mark_review_used':
            $controller->markReviewUsedAjax();
            break;
        default:
            echo json_encode(['ok' => false, 'message' => 'Invalid action']);
            break;
    }
} catch (Throwable) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Server error while preparing review response.']);
}
