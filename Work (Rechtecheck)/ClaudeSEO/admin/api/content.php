<?php
ini_set('display_errors', 0);
error_reporting(0);
set_time_limit(0); // Generation via reasoning models can take several minutes
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../lib/Database.php';
require_once __DIR__ . '/../../lib/ContentGenerator.php';
require_once __DIR__ . '/../../lib/AIProvider.php';
require_once __DIR__ . '/../../lib/ProviderFactory.php';
require_once __DIR__ . '/../../lib/Providers/ClaudeProvider.php';
require_once __DIR__ . '/../../lib/Providers/OpenAIProvider.php';

$db = Database::getInstance();

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['type']) || !isset($input['id'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing required fields: type and id']);
        exit;
    }

    $type = $input['type'];
    $id = (int) $input['id'];
    $gen = new ContentGenerator(ProviderFactory::make());

    // Check budget first
    if (!$gen->checkDailyBudget()) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Daily API budget exceeded']);
        exit;
    }

    switch ($type) {
        case 'rechtsgebiet':
            $rechtsgebiet = $db->fetchOne('SELECT * FROM rechtsgebiete WHERE id = ?', [$id]);
            if (!$rechtsgebiet) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Rechtsgebiet not found']);
                exit;
            }

            $rechtsfragen = $db->fetchAll(
                'SELECT * FROM rechtsfragen WHERE rechtsgebiet_id = ?',
                [$id]
            );

            $content = $gen->generateRechtsgebietContent($rechtsgebiet, $rechtsfragen);

            // Check if page already exists
            $existingPage = $db->fetchOne(
                'SELECT id FROM rechtsgebiet_pages WHERE rechtsgebiet_id = ?',
                [$id]
            );

            if ($existingPage) {
                $db->update('rechtsgebiet_pages', [
                    'title'             => $content['title'],
                    'meta_description'  => $content['meta_description'],
                    'meta_keywords'     => $content['meta_keywords'],
                    'html_content'      => $content['html_content'],
                    'og_title'          => $content['og_title'],
                    'og_description'    => $content['og_description'],
                    'generation_status' => 'generated',
                    'generated_by'      => 'admin_api',
                    'updated_at'        => date('Y-m-d H:i:s'),
                ], 'id = ?', [$existingPage['id']]);
            } else {
                $db->insert('rechtsgebiet_pages', [
                    'rechtsgebiet_id'   => $id,
                    'title'             => $content['title'],
                    'meta_description'  => $content['meta_description'],
                    'meta_keywords'     => $content['meta_keywords'],
                    'html_content'      => $content['html_content'],
                    'og_title'          => $content['og_title'],
                    'og_description'    => $content['og_description'],
                    'generation_status' => 'generated',
                    'generated_by'      => 'admin_api',
                    'created_at'        => date('Y-m-d H:i:s'),
                ]);
            }

            echo json_encode(['status' => 'success', 'title' => $content['title']]);
            break;

        case 'rechtsfrage':
            $rechtsfrage = $db->fetchOne('SELECT * FROM rechtsfragen WHERE id = ?', [$id]);
            if (!$rechtsfrage) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Rechtsfrage not found']);
                exit;
            }

            $rechtsgebiet = $db->fetchOne(
                'SELECT * FROM rechtsgebiete WHERE id = ?',
                [$rechtsfrage['rechtsgebiet_id']]
            );
            if (!$rechtsgebiet) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Parent Rechtsgebiet not found']);
                exit;
            }

            // Generate base content (1 API call)
            $content = $gen->generateRechtsfragContent($rechtsfrage, $rechtsgebiet);

            // Upsert base page
            $existingPage = $db->fetchOne(
                'SELECT id FROM rechtsfrage_pages WHERE rechtsfrage_id = ?',
                [$id]
            );

            if ($existingPage) {
                $db->update('rechtsfrage_pages', [
                    'title'             => $content['title'],
                    'meta_description'  => $content['meta_description'],
                    'meta_keywords'     => $content['meta_keywords'],
                    'html_content'      => $content['html_content'],
                    'og_title'          => $content['og_title'],
                    'og_description'    => $content['og_description'],
                    'generation_status' => 'generated',
                    'generated_by'      => 'admin_api',
                    'updated_at'        => date('Y-m-d H:i:s'),
                ], 'id = ?', [$existingPage['id']]);
            } else {
                $db->insert('rechtsfrage_pages', [
                    'rechtsfrage_id'    => $id,
                    'title'             => $content['title'],
                    'meta_description'  => $content['meta_description'],
                    'meta_keywords'     => $content['meta_keywords'],
                    'html_content'      => $content['html_content'],
                    'og_title'          => $content['og_title'],
                    'og_description'    => $content['og_description'],
                    'generation_status' => 'generated',
                    'generated_by'      => 'admin_api',
                    'created_at'        => date('Y-m-d H:i:s'),
                ]);
            }

            echo json_encode(['status' => 'success', 'title' => $content['title']]);
            break;

        case 'variation':
            $variationValueId = (int)($input['variation_value_id'] ?? 0);
            if (!$variationValueId) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'variation_value_id required']);
                exit;
            }

            $rechtsfrage = $db->fetchOne('SELECT * FROM rechtsfragen WHERE id = ?', [$id]);
            if (!$rechtsfrage) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Rechtsfrage not found']); exit; }

            $rechtsgebiet = $db->fetchOne('SELECT * FROM rechtsgebiete WHERE id = ?', [$rechtsfrage['rechtsgebiet_id']]);
            if (!$rechtsgebiet) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Rechtsgebiet not found']); exit; }

            $variationValue = $db->fetchOne(
                'SELECT vv.*, vt.slug as type_slug FROM variation_values vv
                 JOIN variation_types vt ON vt.id = vv.variation_type_id
                 WHERE vv.id = ?',
                [$variationValueId]
            );
            if (!$variationValue) { http_response_code(400); echo json_encode(['status'=>'error','message'=>'Variation value not found']); exit; }

            $content = $gen->generateVariationContent(
                $rechtsfrage,
                $rechtsgebiet,
                $variationValue['type_slug'],
                $variationValue['value']
            );

            $existing = $db->fetchOne(
                'SELECT id FROM variation_pages WHERE rechtsfrage_id = ? AND variation_value_id = ?',
                [$id, $variationValueId]
            );

            if ($existing) {
                $db->update('variation_pages', [
                    'title'             => $content['title'],
                    'meta_description'  => $content['meta_description'],
                    'meta_keywords'     => $content['meta_keywords'],
                    'html_content'      => $content['html_content'],
                    'og_title'          => $content['og_title'],
                    'og_description'    => $content['og_description'],
                    'generation_status' => 'generated',
                    'generated_by'      => 'admin_api',
                    'updated_at'        => date('Y-m-d H:i:s'),
                ], 'id = ?', [$existing['id']]);
            } else {
                $db->insert('variation_pages', [
                    'rechtsfrage_id'     => $id,
                    'variation_value_id' => $variationValueId,
                    'title'              => $content['title'],
                    'meta_description'   => $content['meta_description'],
                    'meta_keywords'      => $content['meta_keywords'],
                    'html_content'       => $content['html_content'],
                    'og_title'           => $content['og_title'],
                    'og_description'     => $content['og_description'],
                    'generation_status'  => 'generated',
                    'generated_by'       => 'admin_api',
                    'created_at'         => date('Y-m-d H:i:s'),
                ]);
            }

            echo json_encode(['status' => 'success', 'title' => $content['title']]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid type. Must be: rechtsgebiet, rechtsfrage, or variation']);
            break;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
