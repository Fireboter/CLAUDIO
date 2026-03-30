<?php
ini_set('display_errors', 0);
error_reporting(0);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../lib/Database.php';

function toSlug(string $text, array &$used = []): string {
    $text = mb_strtolower($text, 'UTF-8');
    $text = str_replace(['ä','ö','ü','ß'], ['ae','oe','ue','ss'], $text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $base = trim($text, '-');
    if (empty($base)) $base = 'item';

    $slug = $base;
    $i = 2;
    while (isset($used[$slug])) {
        $slug = $base . '-' . $i++;
    }
    $used[$slug] = true;
    return $slug;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $rgId  = (int)($input['rechtsgebiet_id'] ?? 0);
    $sets  = $input['sets'] ?? [];

    if (!$rgId || !is_array($sets) || empty($sets)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'rechtsgebiet_id and sets required']);
        exit;
    }

    $db  = Database::getInstance();
    $pdo = $db->getPdo();

    $pdo->beginTransaction();
    try {
        // 1. Delete all values for this RG's types
        $db->query(
            'DELETE vv FROM variation_values vv
             INNER JOIN variation_types vt ON vv.variation_type_id = vt.id
             WHERE vt.rechtsgebiet_id = ?',
            [$rgId]
        );
        // 2. Delete all types for this RG
        $db->query('DELETE FROM variation_types WHERE rechtsgebiet_id = ?', [$rgId]);

        $saved = 0;
        $usedTypeSlugs = [];
        foreach ($sets as $s) {
            $typeName = trim($s['type'] ?? '');
            $values   = $s['values'] ?? [];
            if (!$typeName || !is_array($values)) continue;

            // 3. Insert type
            $typeId = $db->insert('variation_types', [
                'rechtsgebiet_id' => $rgId,
                'name'            => $typeName,
                'slug'            => toSlug($typeName, $usedTypeSlugs),
            ]);

            // 4. Insert values
            $usedValueSlugs = [];
            foreach ($values as $val) {
                $val = trim($val);
                if (!$val) continue;
                $db->insert('variation_values', [
                    'variation_type_id' => $typeId,
                    'value'             => $val,
                    'slug'              => toSlug($val, $usedValueSlugs),
                    'tier'              => 1,
                ]);
                $saved++;
            }
        }

        $pdo->commit();
        echo json_encode(['status' => 'ok', 'saved' => $saved]);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log('variation_finalize.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Ein Fehler ist aufgetreten. Bitte versuche es erneut.']);
}
