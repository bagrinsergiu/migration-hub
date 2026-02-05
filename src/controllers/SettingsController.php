<?php

namespace Dashboard\Controllers;

use Dashboard\Services\DatabaseService;
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class SettingsController
{
    /** @var DatabaseService */
    private $dbService;

    public function __construct()
    {
        $this->dbService = new DatabaseService();
    }

    /**
     * GET /api/settings
     * Получить настройки
     */
    public function get(Request $request): JsonResponse
    {
        try {
            $settings = $this->dbService->getSettings();
            return new JsonResponse([
                'success' => true,
                'data' => $settings
            ], 200);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /api/settings
     * Сохранить настройки
     */
    public function save(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            
            if (!$data) {
                $data = $request->request->all();
            }

            // Валидация
            $settings = [];
            if (isset($data['mb_site_id'])) {
                $settings['mb_site_id'] = !empty($data['mb_site_id']) ? (int)$data['mb_site_id'] : null;
            }
            if (isset($data['mb_secret'])) {
                $settings['mb_secret'] = !empty($data['mb_secret']) ? (string)$data['mb_secret'] : null;
            }

            $this->dbService->saveSettings($settings);
            
            return new JsonResponse([
                'success' => true,
                'message' => 'Настройки сохранены',
                'data' => $this->dbService->getSettings()
            ], 200);
        } catch (Exception $e) {
            return new JsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
