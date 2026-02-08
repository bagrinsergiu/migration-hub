<?php

namespace Dashboard\Services;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use Psr\Log\LoggerInterface;

/**
 * BrizyApiService
 * 
 * Современный сервис для работы с Brizy API
 * Заменяет старый MBMigration\Layer\Brizy\BrizyAPI
 */
class BrizyApiService
{
    private Client $httpClient;
    private string $apiToken;
    private string $baseUrl;
    private ?LoggerInterface $logger;
    private array $endpoints;

    public function __construct(
        string $apiToken,
        string $baseUrl = 'https://admin.brizy.io',
        ?LoggerInterface $logger = null
    ) {
        if (empty($apiToken)) {
            throw new Exception('Brizy API token is required');
        }

        $this->apiToken = $apiToken;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->logger = $logger;
        $this->httpClient = new Client([
            'timeout' => 60,
            'connect_timeout' => 50,
            'verify' => true, // Проверка SSL сертификата
            'allow_redirects' => true,
        ]);

        $this->endpoints = [
            'workspaces' => '/api/2.0/workspaces',
            'projects' => '/api/2.0/projects',
            'users' => '/api/2.0/users',
            'pages' => '/api/pages',
            'media' => '/api/media',
            'fonts' => '/api/fonts',
        ];
    }

    /**
     * Получить список workspace или найти по имени
     * 
     * @param string|null $name Имя workspace для поиска
     * @return array|int|false Массив всех workspace или ID найденного, или false
     * @throws Exception
     */
    public function getWorkspaces(?string $name = null)
    {
        $url = $this->endpoints['workspaces'];
        $response = $this->request('GET', $url, [
            'page' => 1,
            'count' => 100,
        ]);

        if (!isset($name)) {
            return $response;
        }

        if (!is_array($response)) {
            return false;
        }

        foreach ($response as $workspace) {
            if (isset($workspace['name']) && $workspace['name'] === $name) {
                return $workspace['id'] ?? false;
            }
        }

        return false;
    }

    /**
     * Создать новый workspace
     * 
     * @param string $name Имя workspace
     * @return array Ответ от API
     * @throws Exception
     */
    public function createWorkspace(string $name): array
    {
        $url = $this->endpoints['workspaces'];
        return $this->request('POST', $url, ['name' => $name]);
    }

    /**
     * Создать новый workspace (алиас для совместимости)
     * 
     * @param string $name Имя workspace
     * @return array Ответ от API
     * @throws Exception
     */
    public function createdWorkspaces(string $name): array
    {
        return $this->createWorkspace($name);
    }

    /**
     * Получить проекты из workspace
     * 
     * @param int $workspaceId ID workspace
     * @param string|null $filterName Имя проекта для фильтрации
     * @return array|int|false Массив проектов или ID найденного проекта
     * @throws Exception
     */
    public function getProjects(int $workspaceId, ?string $filterName = null)
    {
        $url = $this->endpoints['projects'];
        $response = $this->request('GET', $url, [
            'page' => 1,
            'count' => 100,
            'workspace' => $workspaceId,
        ]);

        if (!isset($filterName)) {
            return $response;
        }

        if (!is_array($response)) {
            return false;
        }

        foreach ($response as $project) {
            if (isset($project['name']) && $project['name'] === $filterName) {
                return $project['id'] ?? false;
            }
        }

        return false;
    }

    /**
     * Получить проект из workspace (алиас для совместимости)
     * 
     * @param int $workspaceId ID workspace
     * @param string|null $filterName Имя проекта для фильтрации
     * @return array|int|false Массив проектов или ID найденного проекта
     * @throws Exception
     */
    public function getProject(int $workspaceId, ?string $filterName = null)
    {
        return $this->getProjects($workspaceId, $filterName);
    }

    /**
     * Создать новый проект в workspace
     * 
     * @param string $projectName Имя проекта
     * @param int $workspaceId ID workspace
     * @param string|null $returnField Поле для возврата (например, 'id')
     * @return array|mixed Ответ от API или значение поля
     * @throws Exception
     */
    public function createProject(string $projectName, int $workspaceId, ?string $returnField = null)
    {
        $url = $this->endpoints['projects'];
        $response = $this->request('POST', $url, [
            'name' => $projectName,
            'workspace' => $workspaceId,
        ]);

        if ($returnField && isset($response[$returnField])) {
            return $response[$returnField];
        }

        return $response;
    }

    /**
     * Получить метаданные проекта
     * 
     * @param int $projectId ID проекта
     * @return array|null Метаданные или null
     * @throws Exception
     */
    public function getProjectMetadata(int $projectId): ?array
    {
        $url = $this->endpoints['projects'] . '/' . $projectId;
        $response = $this->request('GET', $url);

        if (!is_array($response) || !isset($response['metadata'])) {
            return null;
        }

        $metadata = json_decode($response['metadata'], true);
        return is_array($metadata) ? $metadata : null;
    }

    /**
     * Удалить проект
     * 
     * @param int $projectId ID проекта
     * @return bool Успешно ли удален
     * @throws Exception
     */
    public function deleteProject(int $projectId): bool
    {
        $url = $this->endpoints['projects'] . '/' . $projectId;
        $response = $this->request('DELETE', $url);
        return isset($response['status']) && $response['status'] === 200;
    }

    /**
     * Включить/выключить cloning link для проекта
     * 
     * @param int $projectId ID проекта
     * @param bool $enabled Включен ли cloning
     * @return bool Успешно ли обновлено
     * @throws Exception
     */
    public function setCloningLink(int $projectId, bool $enabled): bool
    {
        $url = $this->baseUrl . '/api/projects/' . $projectId . '/cloning_link';
        $response = $this->request('PUT', $url, [
            'enabled' => $enabled ? 1 : 0,
            'regenerate' => 0,
        ]);

        return isset($response['status']) && $response['status'] === 200;
    }

    /**
     * Выполнить HTTP запрос
     * 
     * @param string $method HTTP метод
     * @param string $url URL
     * @param array|null $data Данные для отправки
     * @return array Ответ от API
     * @throws Exception
     */
    private function request(string $method, string $url, ?array $data = null): array
    {
        $fullUrl = strpos($url, 'http') === 0 ? $url : $this->baseUrl . $url;

        $options = [
            'headers' => [
                'x-auth-user-token' => $this->apiToken,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ];

        if ($method === 'GET' && !empty($data)) {
            $fullUrl .= '?' . http_build_query($data);
        } elseif (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $options['form_params'] = $data ?? [];
        }

        $maxRetries = 3;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = $this->httpClient->request($method, $fullUrl, $options);
                $body = $response->getBody()->getContents();
                $statusCode = $response->getStatusCode();

                $decoded = json_decode($body, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }

                return [
                    'status' => $statusCode,
                    'body' => $body,
                ];
            } catch (ConnectException $e) {
                $lastException = $e;
                $errorMessage = $e->getMessage();
                
                // Проверяем, является ли это ошибкой DNS
                if (strpos($errorMessage, 'Could not resolve host') !== false || 
                    strpos($errorMessage, 'cURL error 6') !== false) {
                    $this->log('error', "DNS resolution failed (attempt {$attempt}/{$maxRetries}): {$errorMessage}");
                    throw new Exception(
                        "Не удалось разрешить DNS для {$this->baseUrl}. " .
                        "Проверьте интернет-соединение и настройки DNS в Docker. " .
                        "Ошибка: {$errorMessage}"
                    );
                }
                
                $this->log('warning', "Connection error (attempt {$attempt}/{$maxRetries}): {$errorMessage}");
            } catch (RequestException $e) {
                $response = $e->getResponse();
                $statusCode = $response ? $response->getStatusCode() : 0;
                $body = $response ? $response->getBody()->getContents() : '';

                if ($statusCode >= 400 && $statusCode < 500) {
                    throw new Exception("API request failed: HTTP {$statusCode} - {$body}");
                }

                $lastException = $e;
                $this->log('warning', "Request error (attempt {$attempt}/{$maxRetries}): HTTP {$statusCode}");
            } catch (GuzzleException $e) {
                $lastException = $e;
                $this->log('error', "Guzzle error (attempt {$attempt}/{$maxRetries}): " . $e->getMessage());
            }

            if ($attempt < $maxRetries) {
                sleep(2);
            }
        }

        $errorMsg = "Request failed after {$maxRetries} attempts";
        if ($lastException) {
            $errorMsg .= ": " . $lastException->getMessage();
            // Если это ошибка DNS, добавляем более понятное сообщение
            if (strpos($lastException->getMessage(), 'Could not resolve host') !== false) {
                $errorMsg .= ". Проверьте интернет-соединение и настройки DNS в Docker контейнере.";
            }
        }
        throw new Exception($errorMsg);
    }

    /**
     * Логирование
     */
    private function log(string $level, string $message): void
    {
        if ($this->logger) {
            $this->logger->{$level}($message);
        }
    }
}
