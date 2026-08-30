<?php
// FILE: app/routes/api/tours/detail.php

@$SECURE or die('Access Denied!');

$router->get('/api/tours/([0-9]+)', function ($params) use ($SECURE, $db) {

    header('Content-Type: application/json');

    $tour_id = trim($params);

    if (empty($tour_id) || !is_numeric($tour_id)) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid or missing tour id'
        ]);
        return;
    }

    // ================= BASE URL =================
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    $baseUrl = $scheme . '://' . $host . $base;

    // ================= FETCH TOUR =================
    $tour = $db->get('tours', '*', ['id' => $tour_id]);

    if (!$tour) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Tour not found'
        ]);
        return;
    }

    // ================= INCLUSIONS =================
    $inclusions = [];
    $inclusionIds = json_decode($tour['inclusions'], true);

    if (is_array($inclusionIds)) {
        $rows = $db->select('tours_settings', [
            'id',
            'setting_label',
            'icon'
        ], [
            'id' => $inclusionIds,
            'setting_type' => 'inclusion',
            'status' => 1
        ]);

        foreach ($rows as $row) {
            $inclusions[] = [
                'id' => $row['id'],
                'name' => $row['setting_label'],
                'icon_class' => $row['icon']
            ];
        }
    }

    // ================= EXCLUSIONS =================
    $exclusions = [];
    $exclusionIds = json_decode($tour['exclusions'], true);

    if (is_array($exclusionIds)) {
        $rows = $db->select('tours_settings', [
            'id',
            'setting_label',
            'icon'
        ], [
            'id' => $exclusionIds,
            'setting_type' => 'exclusion',
            'status' => 1
        ]);

        foreach ($rows as $row) {
            $exclusions[] = [
                'id' => $row['id'],
                'name' => $row['setting_label'],
                'icon_class' => $row['icon']
            ];
        }
    }

    // ================= ITINERARY =================
    $itinerary = json_decode($tour['itinerary'], true);

    if (is_array($itinerary)) {
        foreach ($itinerary as &$day) {
            if (!empty($day['activities'])) {
                foreach ($day['activities'] as &$activity) {

                    // main image
                    if (!empty($activity['image'])) {
                        $activity['image'] = $baseUrl . $activity['image'];
                    }

                    // multiple images
                    if (!empty($activity['images']) && is_array($activity['images'])) {
                        foreach ($activity['images'] as &$img) {
                            $img = $baseUrl . $img;
                        }
                    }
                }
            }
        }
    }

    // ================= GALLERY IMAGE =================
    $gallery = json_decode($tour['img'], true);
    if (is_array($gallery)) {
        foreach ($gallery as &$img) {
            if (!empty($img['url'])) {
                $img['url'] = $baseUrl . $img['url'];
            }
        }
    }

    // ================= FINAL RESPONSE =================
    $tour['img'] = $gallery;
    $tour['inclusions'] = $inclusions;
    $tour['exclusions'] = $exclusions;
    $tour['itinerary'] = $itinerary;

    echo json_encode([
        'status' => 'success',
        'message' => 'Tour details retrieved',
        'data' => [
            'tour' => $tour
        ]
    ]);
});
