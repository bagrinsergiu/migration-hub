import axios from 'axios';

const API_BASE_URL = '/api';

const apiClient = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    'Content-Type': 'application/json',
  },
  withCredentials: true, // Всегда отправляем куки с запросами
});

// Функция для получения session_id из куки или localStorage
function getSessionId(): string | null {
  // Сначала пытаемся получить из куки (если доступно)
  if (typeof document !== 'undefined') {
    const cookies = document.cookie.split(';');
    for (let cookie of cookies) {
      const [name, value] = cookie.trim().split('=');
      if (name === 'dashboard_session') {
        // Синхронизируем с localStorage
        if (value) {
          localStorage.setItem('dashboard_session', value);
        }
        return value || null;
      }
    }
  }
  
  // Если не нашли в куки, берем из localStorage
  return localStorage.getItem('dashboard_session');
}

// Interceptor для добавления session_id в заголовки
apiClient.interceptors.request.use(
  (config) => {
    const sessionId = getSessionId();
    if (sessionId) {
      config.headers['X-Dashboard-Session'] = sessionId;
    }
    return config;
  },
  (error) => {
    return Promise.reject(error);
  }
);

// Interceptor для обработки ошибок
apiClient.interceptors.response.use(
  (response) => {
    // Успешный ответ
    return response;
  },
  (error) => {
    // Ошибка - пробрасываем дальше для обработки в методах API
    // Методы сами решат, как обработать ошибку
    return Promise.reject(error);
  }
);

export interface Migration {
  id: number;
  mb_project_uuid: string;
  brz_project_id: number;
  created_at: string;
  updated_at: string;
  status: 'pending' | 'in_progress' | 'success' | 'completed' | 'error';
  changes_json?: any;
  result?: any;
}

export interface MigrationDetails {
  mapping: {
    brz_project_id: number;
    mb_project_uuid: string;
    changes_json?: any;
    created_at: string;
    updated_at: string;
  };
  result?: {
    migration_uuid: string;
    brz_project_id: number;
    brizy_project_domain?: string;
    mb_project_uuid: string;
    result_json?: any;
  };
  result_data?: any;
  status: 'pending' | 'in_progress' | 'success' | 'error' | 'completed';
  brizy_project_domain?: string;
  mb_project_domain?: string;
  progress?: any;
  warnings?: string[];
}

export interface RunMigrationParams {
  mb_project_uuid: string;
  brz_project_id: number;
  mb_site_id?: number;
  mb_secret?: string;
  brz_workspaces_id?: number;
  mb_page_slug?: string;
  mgr_manual?: number;
  quality_analysis?: boolean;
}

export interface ApiResponse<T> {
  success: boolean;
  data?: T;
  error?: string;
  count?: number;
  details?: any;
}

export const api = {
  // Health check
  async health() {
    const response = await apiClient.get('/health');
    return response.data;
  },

  // Migration server health check
  async checkMigrationServerHealth(): Promise<ApiResponse<{
    available: boolean;
    message: string;
    http_code: number | null;
    data?: any;
    error?: string;
    timestamp: string;
  }>> {
    const response = await apiClient.get('/migration-server/health');
    return response.data;
  },

  // Взаимное рукопожатие: дашборд опрашивает сервер миграции, сервер опрашивает дашборд
  async getMigrationServerHandshake(): Promise<ApiResponse<{
    success: boolean;
    message?: string;
    migration_server?: { service: string; server_id: string; client_ip?: string; timestamp?: string };
    handshake_with_dashboard: 'ok' | 'fail';
    handshake_error?: string;
    http_code?: number;
  }>> {
    const response = await apiClient.get('/migration-server/handshake');
    return response.data;
  },

  // Auth
  async login(username: string, password: string): Promise<ApiResponse<{ session_id: string; user: any }>> {
    try {
      const response = await apiClient.post('/auth/login', { username, password });
      return response.data;
    } catch (error: any) {
      console.error('Login API error:', error);
      // Если есть ответ от сервера, возвращаем его данные
      if (error.response && error.response.data) {
        return error.response.data;
      }
      // Если нет ответа, возвращаем ошибку подключения
      return {
        success: false,
        error: error.message || 'Не удалось подключиться к серверу. Убедитесь, что сервер запущен на порту 8000.'
      };
    }
  },

  async logout(): Promise<ApiResponse<any>> {
    const response = await apiClient.post('/auth/logout');
    localStorage.removeItem('dashboard_session');
    localStorage.removeItem('dashboard_user');
    // Удаляем куки
    if (typeof document !== 'undefined') {
      document.cookie = 'dashboard_session=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    }
    return response.data;
  },

  async checkAuth(): Promise<ApiResponse<{ authenticated: boolean; user?: any }>> {
    try {
      const response = await apiClient.get('/auth/check');
      return response.data;
    } catch (error: any) {
      // Если есть ответ от сервера, возвращаем его данные
      if (error.response && error.response.data) {
        return error.response.data;
      }
      // Если нет ответа, возвращаем ошибку подключения
      return {
        success: false,
        data: { authenticated: false },
        error: error.message || 'Не удалось подключиться к серверу'
      };
    }
  },

  // Migrations
  async getMigrations(filters?: {
    status?: string;
    mb_project_uuid?: string;
    brz_project_id?: number;
  }): Promise<ApiResponse<Migration[]>> {
    const response = await apiClient.get('/migrations', { params: filters });
    return response.data;
  },

  async getMigrationDetails(id: number): Promise<ApiResponse<MigrationDetails>> {
    const response = await apiClient.get(`/migrations/${id}`);
    return response.data;
  },

  async getMigrationLogs(id: number): Promise<ApiResponse<any>> {
    const response = await apiClient.get(`/migrations/${id}/logs`);
    return response.data;
  },

  async runMigration(params: RunMigrationParams): Promise<ApiResponse<any>> {
    try {
      const response = await apiClient.post('/migrations/run', params);
      return response.data;
    } catch (error: any) {
      // Обработка ошибок axios
      if (error.response) {
        // Сервер вернул ошибку - возвращаем данные из ответа
        const errorData = error.response.data;
        return {
          success: false,
          error: errorData?.error || errorData?.message || error.response.statusText || 'Ошибка сервера',
          data: errorData?.data
        };
      } else if (error.request) {
        // Запрос был отправлен, но ответа не получено
        return {
          success: false,
          error: 'Не удалось подключиться к серверу. Проверьте подключение к интернету.'
        };
      } else {
        // Ошибка при настройке запроса
        return {
          success: false,
          error: error.message || 'Ошибка при отправке запроса'
        };
      }
    }
  },

  async restartMigration(id: number, params: Partial<RunMigrationParams>): Promise<ApiResponse<any>> {
    const response = await apiClient.post(`/migrations/${id}/restart`, params);
    return response.data;
  },

  async removeMigrationLock(id: number): Promise<ApiResponse<any>> {
    const response = await apiClient.delete(`/migrations/${id}/lock`);
    return response.data;
  },

  async killMigrationProcess(id: number, force: boolean = false): Promise<ApiResponse<any>> {
    const response = await apiClient.post(`/migrations/${id}/kill`, { force });
    return response.data;
  },

  async getMigrationProcessInfo(id: number): Promise<ApiResponse<any>> {
    const response = await apiClient.get(`/migrations/${id}/process`);
    return response.data;
  },

  async getMigrationWebhookInfo(id: number): Promise<ApiResponse<{
    webhook_url: string;
    webhook_registered: boolean;
    webhook_received: boolean;
    webhook_received_at: string | null;
    webhook_params: {
      mb_project_uuid: string;
      brz_project_id: number;
      webhook_url: string;
    };
    webhook_logs: string[];
    server_status: any;
    migration_status: string;
    last_result: {
      migration_uuid: string | null;
      created_at: string | null;
      status: string | null;
    } | null;
  }>> {
    const response = await apiClient.get(`/migrations/${id}/webhook-info`);
    return response.data;
  },

  async removeMigrationCache(id: number): Promise<ApiResponse<any>> {
    const response = await apiClient.delete(`/migrations/${id}/cache`);
    return response.data;
  },

  async resetMigrationStatus(id: number): Promise<ApiResponse<any>> {
    const response = await apiClient.post(`/migrations/${id}/reset-status`);
    return response.data;
  },

  async setMigrationCompleted(id: number, brizyProjectDomain?: string): Promise<ApiResponse<any>> {
    const response = await apiClient.post(`/migrations/${id}/set-completed`, {
      brizy_project_domain: brizyProjectDomain ?? undefined,
    });
    return response.data;
  },

  async hardResetMigration(id: number): Promise<ApiResponse<any>> {
    const response = await apiClient.post(`/migrations/${id}/hard-reset`);
    return response.data;
  },

  // Logs
  async getLogs(brzProjectId: number): Promise<ApiResponse<any>> {
    const response = await apiClient.get(`/logs/${brzProjectId}`);
    return response.data;
  },

  async getRecentLogs(limit: number = 10): Promise<ApiResponse<any[]>> {
    const response = await apiClient.get('/logs/recent', { params: { limit } });
    return response.data;
  },

  // Settings
  async getSettings(): Promise<ApiResponse<any>> {
    const response = await apiClient.get('/settings');
    return response.data;
  },

      async saveSettings(settings: { mb_site_id?: number | null; mb_secret?: string | null }): Promise<ApiResponse<any>> {
        const response = await apiClient.post('/settings', settings);
        return response.data;
      },

      // Waves
      async getWaves(filters?: { status?: string }): Promise<ApiResponse<Wave[]>> {
        const response = await apiClient.get('/waves', { params: filters });
        return response.data;
      },

      async getWaveDetails(id: string): Promise<ApiResponse<WaveDetails>> {
        const response = await apiClient.get(`/waves/${id}`);
        return response.data;
      },

      async getWaveStatus(id: string): Promise<ApiResponse<WaveStatus>> {
        const response = await apiClient.get(`/waves/${id}/status`);
        return response.data;
      },

      async resetWaveStatus(waveId: string): Promise<ApiResponse<{ message?: string; wave_status?: string }>> {
        const response = await apiClient.post(`/waves/${waveId}/reset-status`);
        return response.data;
      },

      async getWaveMapping(id: string): Promise<ApiResponse<WaveMapping[]>> {
        const response = await apiClient.get(`/waves/${id}/mapping`);
        return response.data;
      },

      async createWave(params: CreateWaveParams): Promise<ApiResponse<CreateWaveResponse>> {
        const response = await apiClient.post('/waves', params);
        return response.data;
      },

      async restartWaveMigration(waveId: string, mbUuid: string, params?: { mb_site_id?: number; mb_secret?: string; quality_analysis?: boolean }): Promise<ApiResponse<any>> {
        const response = await apiClient.post(`/waves/${waveId}/migrations/${mbUuid}/restart`, params || {});
        return response.data;
      },

      async restartAllWaveMigrations(waveId: string, mbUuids?: string[], params?: { quality_analysis?: boolean }): Promise<ApiResponse<any>> {
        const response = await apiClient.post(`/waves/${waveId}/restart-all`, {
          mb_uuids: mbUuids || [],
          quality_analysis: params?.quality_analysis ?? false
        });
        return response.data;
      },

      async getWaveLogs(waveId: string): Promise<ApiResponse<any>> {
        const response = await apiClient.get(`/waves/${waveId}/logs`);
        return response.data;
      },

      async getWaveMigrationLogs(waveId: string, mbUuid: string): Promise<ApiResponse<any>> {
        const response = await apiClient.get(`/waves/${waveId}/migrations/${mbUuid}/logs`);
        return response.data;
      },

      async removeWaveMigrationLock(waveId: string, mbUuid: string): Promise<ApiResponse<any>> {
        const response = await apiClient.delete(`/waves/${waveId}/migrations/${mbUuid}/lock`);
        return response.data;
      },

      async toggleCloning(waveId: string, brzProjectId: number, cloningEnabled: boolean): Promise<ApiResponse<any>> {
        const response = await apiClient.put(`/waves/${waveId}/mapping/${brzProjectId}/cloning`, {
          cloning_enabled: cloningEnabled
        });
        return response.data;
      },

      async createReviewToken(
        waveId: string, 
        data: {
          expires_in_days?: number;
          name?: string;
          description?: string;
          settings?: any;
          project_settings?: Record<string, { allowed_tabs: string[]; is_active: boolean }>;
        }
      ): Promise<ApiResponse<{ id: number; token: string; review_url: string }>> {
        const response = await apiClient.post(`/waves/${waveId}/review-token`, data, { timeout: 120000 });
        return response.data;
      },

      async getReviewTokens(waveId: string): Promise<ApiResponse<any[]>> {
        const response = await apiClient.get(`/waves/${waveId}/review-tokens`);
        return response.data;
      },

      async updateReviewToken(waveId: string, tokenId: number, data: any): Promise<ApiResponse<any>> {
        const response = await apiClient.put(`/waves/${waveId}/review-tokens/${tokenId}`, data);
        return response.data;
      },

      async deleteReviewToken(waveId: string, tokenId: number): Promise<ApiResponse<any>> {
        const response = await apiClient.delete(`/waves/${waveId}/review-tokens/${tokenId}`);
        return response.data;
      },

      async updateProjectAccess(
        waveId: string, 
        tokenId: number, 
        mbUuid: string, 
        config: { allowed_tabs: string[]; is_active: boolean }
      ): Promise<ApiResponse<any>> {
        const response = await apiClient.put(`/waves/${waveId}/review-tokens/${tokenId}/projects/${mbUuid}`, config);
        return response.data;
      },

      // Users Management
      async getUsers(): Promise<ApiResponse<any[]>> {
        const response = await apiClient.get('/users');
        return response.data;
      },

      async getUser(id: number): Promise<ApiResponse<any>> {
        const response = await apiClient.get(`/users/${id}`);
        return response.data;
      },

      async createUser(data: any): Promise<ApiResponse<any>> {
        const response = await apiClient.post('/users', data);
        return response.data;
      },

      async updateUser(id: number, data: any): Promise<ApiResponse<any>> {
        const response = await apiClient.put(`/users/${id}`, data);
        return response.data;
      },

      async deleteUser(id: number): Promise<ApiResponse<any>> {
        const response = await apiClient.delete(`/users/${id}`);
        return response.data;
      },

      async getRoles(): Promise<ApiResponse<any[]>> {
        const response = await apiClient.get('/users/roles');
        return response.data;
      },

      async getPermissions(): Promise<ApiResponse<any[]>> {
        const response = await apiClient.get('/users/permissions');
        return response.data;
      },

      async getUserPermissions(userId: number): Promise<ApiResponse<any[]>> {
        const response = await apiClient.get(`/users/${userId}/permissions`);
        return response.data;
      },

      // Quality Analysis
      async getQualityAnalysis(migrationId: number): Promise<ApiResponse<QualityAnalysisReport[]>> {
        const response = await apiClient.get(`/migrations/${migrationId}/quality-analysis`);
        return response.data;
      },

      async getArchivedQualityAnalysis(migrationId: number): Promise<ApiResponse<QualityAnalysisReport[]>> {
        const response = await apiClient.get(`/migrations/${migrationId}/quality-analysis/archived`);
        return response.data;
      },

      async getQualityStatistics(migrationId: number): Promise<ApiResponse<QualityStatistics>> {
        const response = await apiClient.get(`/migrations/${migrationId}/quality-analysis/statistics`);
        return response.data;
      },

      async getMigrationPages(migrationId: number): Promise<ApiResponse<any[]>> {
        const response = await apiClient.get(`/migrations/${migrationId}/pages`);
        return response.data;
      },

      async getPageQualityAnalysis(migrationId: number, pageSlug: string, includeArchived: boolean = false): Promise<ApiResponse<QualityAnalysisReport>> {
        const params = includeArchived ? { include_archived: 'true' } : {};
        const response = await apiClient.get(`/migrations/${migrationId}/quality-analysis/${encodeURIComponent(pageSlug)}`, { params });
        return response.data;
      },

      getScreenshotUrl(filename: string): string {
        return `${API_BASE_URL}/screenshots/${filename}`;
      },

      /**
       * Returns img src for a screenshot. If path is already an API URL (/api/screenshots/...), use as-is.
       * Otherwise treat as filename or file path and return /api/screenshots/{filename}.
       */
      getScreenshotSrc(path: string | null | undefined): string {
        if (!path) return '';
        if (path.startsWith('/api/screenshots/')) return path;
        const filename = path.replace(/\\/g, '/').split('/').pop() || path;
        return `${API_BASE_URL}/screenshots/${filename}`;
      },

      async rebuildPage(migrationId: number, pageSlug: string): Promise<ApiResponse<any>> {
        const response = await apiClient.post(`/migrations/${migrationId}/rebuild-page`, {
          page_slug: pageSlug
        });
        return response.data;
      },

      async rebuildPageNoAnalysis(migrationId: number, pageSlug: string): Promise<ApiResponse<any>> {
        const response = await apiClient.post(`/migrations/${migrationId}/rebuild-page-no-analysis`, {
          page_slug: pageSlug
        });
        return response.data;
      },

      async reanalyzePage(migrationId: number, pageSlug: string): Promise<ApiResponse<any>> {
        try {
          const response = await apiClient.post(`/migrations/${migrationId}/quality-analysis/${encodeURIComponent(pageSlug)}/reanalyze`);
          return response.data;
        } catch (error: any) {
          // Если сервер вернул ошибку с данными, возвращаем их
          if (error.response && error.response.data) {
            return error.response.data;
          }
          // Иначе пробрасываем ошибку дальше
          throw error;
        }
      },

      // Test Migrations
      async getTestMigrations(filters?: {
        status?: string;
        mb_project_uuid?: string;
        brz_project_id?: number;
      }): Promise<ApiResponse<TestMigration[]>> {
        const response = await apiClient.get('/test-migrations', { params: filters });
        return response.data;
      },

      async getTestMigrationDetails(id: number): Promise<ApiResponse<TestMigration>> {
        const response = await apiClient.get(`/test-migrations/${id}`);
        return response.data;
      },

      async createTestMigration(params: CreateTestMigrationParams): Promise<ApiResponse<TestMigration>> {
        const response = await apiClient.post('/test-migrations', params);
        return response.data;
      },

      async updateTestMigration(id: number, params: Partial<CreateTestMigrationParams>): Promise<ApiResponse<TestMigration>> {
        const response = await apiClient.put(`/test-migrations/${id}`, params);
        return response.data;
      },

      async deleteTestMigration(id: number): Promise<ApiResponse<any>> {
        const response = await apiClient.delete(`/test-migrations/${id}`);
        return response.data;
      },

      async runTestMigration(id: number): Promise<ApiResponse<any>> {
        const response = await apiClient.post(`/test-migrations/${id}/run`);
        return response.data;
      },

      async resetTestMigrationStatus(id: number): Promise<ApiResponse<any>> {
        const response = await apiClient.post(`/test-migrations/${id}/reset-status`);
        return response.data;
      },

      // Google Sheets
      async connectGoogleSheet(spreadsheetId: string, spreadsheetName?: string): Promise<ApiResponse<GoogleSheet>> {
        const response = await apiClient.post('/google-sheets/connect', {
          spreadsheet_id: spreadsheetId,
          spreadsheet_name: spreadsheetName
        });
        return response.data;
      },

      async getGoogleSheetsList(): Promise<ApiResponse<GoogleSheet[]>> {
        const response = await apiClient.get('/google-sheets/list');
        return response.data;
      },

      async getGoogleSheet(id: number): Promise<ApiResponse<GoogleSheet>> {
        const response = await apiClient.get(`/google-sheets/${id}`);
        return response.data;
      },

      async syncGoogleSheet(id: number, sheetName?: string): Promise<ApiResponse<SyncResult>> {
        const response = await apiClient.post(`/google-sheets/sync/${id}`, {
          sheet_name: sheetName
        });
        return response.data;
      },

      async linkSheetToWave(spreadsheetId: string, sheetName: string, waveId: string): Promise<ApiResponse<any>> {
        const response = await apiClient.post('/google-sheets/link-wave', {
          spreadsheet_id: spreadsheetId,
          sheet_name: sheetName,
          wave_id: waveId
        });
        return response.data;
      },

      async getGoogleSheetsListForSpreadsheet(spreadsheetId: string): Promise<ApiResponse<SheetInfo[]>> {
        const response = await apiClient.get(`/google-sheets/sheets/${spreadsheetId}`);
        return response.data;
      },

      async getOAuthAuthorizeUrl(): Promise<ApiResponse<{ url: string }>> {
        const response = await apiClient.get('/google-sheets/oauth/authorize');
        return response.data;
      },

      async deleteGoogleSheet(id: number): Promise<ApiResponse<any>> {
        const response = await apiClient.delete(`/google-sheets/${id}`);
        return response.data;
      },
    };

    export interface Wave {
      id: string;
      name: string;
      workspace_id?: number;
      workspace_name: string;
      status: 'pending' | 'in_progress' | 'completed' | 'error';
      created_at: string;
      updated_at: string;
      completed_at?: string;
      progress: {
        total: number;
        completed: number;
        failed: number;
      };
    }

    export interface WaveDetails {
      wave: Wave;
      migrations: WaveMigration[];
    }

    export interface WaveStatus {
      status: string;
      progress: {
        total: number;
        completed: number;
        failed: number;
      };
    }

    export interface WaveMigration {
      cloning_enabled?: boolean;
      mb_project_uuid: string;
      brz_project_id?: number;
      status: 'pending' | 'in_progress' | 'completed' | 'error';
      brizy_project_domain?: string;
      error?: string;
      completed_at?: string;
      migration_uuid?: string;
      migration_id?: string | number;
      reviewer?: {
        person_brizy?: string | null;
        uuid?: string | null;
      } | null;
      result_data?: {
        migration_id?: string | number;
        date?: string;
        theme?: string;
        mb_product_name?: string;
        mb_site_id?: number;
        brizy_project_id?: number;
        progress?: {
          Total?: number;
          Success?: number;
          processTime?: number;
        };
        DEV_MODE?: boolean;
        mb_project_domain?: string;
        warnings?: string[];
      };
    }

    export interface WaveMapping {
      id?: number | null;
      brz_project_id: number;
      mb_project_uuid: string;
      brizy_project_domain?: string | null;
      changes_json?: any;
      cloning_enabled?: boolean;
      reviewer?: {
        person_brizy?: string | null;
        uuid?: string | null;
      } | null;
      created_at: string;
      updated_at: string;
    }

    export interface CreateWaveParams {
      name: string;
      project_uuids: string[];
      batch_size?: number;
      mgr_manual?: boolean;
    }

    export interface CreateWaveResponse {
      wave_id: string;
      workspace_id: number;
      workspace_name: string;
      status: string;
    }

    export interface QualityAnalysisReport {
      id: number;
      migration_id: number;
      mb_project_uuid: string;
      page_slug: string;
      source_url?: string;
      migrated_url?: string;
      analysis_status: 'pending' | 'analyzing' | 'completed' | 'error';
      quality_score?: number | string; // Может быть строкой из API
      severity_level: 'critical' | 'high' | 'medium' | 'low' | 'none';
      token_usage?: {
        prompt_tokens?: number;
        completion_tokens?: number;
        total_tokens?: number;
        estimated_prompt_tokens?: number;
        estimation_accuracy_percent?: number;
        cost_estimate_usd?: number;
        model?: string;
      };
      issues_summary?: {
        summary?: string;
        missing_elements?: string[];
        changed_elements?: string[];
        recommendations?: string[];
      };
      detailed_report?: any;
      screenshots_path?: {
        source?: string;
        migrated?: string;
      };
      created_at: string;
      updated_at: string;
    }

    export interface QualityStatistics {
      total_pages: number;
      avg_quality_score: number | null;
      by_severity: {
        critical: number;
        high: number;
        medium: number;
        low: number;
        none: number;
      };
      token_statistics?: {
        total_prompt_tokens: number;
        total_completion_tokens: number;
        total_tokens: number;
        avg_tokens_per_page: number;
        total_cost_usd: number;
        avg_cost_per_page_usd: number;
      };
    }

    export interface TestMigration {
      id: number;
      mb_project_uuid: string;
      brz_project_id: number;
      mb_site_id?: number;
      mb_secret?: string;
      brz_workspaces_id?: number;
      mb_page_slug?: string;
      mb_element_name?: string;
      skip_media_upload: boolean;
      skip_cache: boolean;
      mgr_manual: number;
      quality_analysis: boolean;
      status: 'pending' | 'in_progress' | 'success' | 'completed' | 'error';
      changes_json?: any;
      section_json?: string | null;
      element_result_json?: string | null;
      created_at: string;
      updated_at: string;
      result?: any;
      migration_uuid?: string;
      brizy_project_domain?: string;
      mb_project_domain?: string;
    }

    export interface CreateTestMigrationParams {
      mb_project_uuid: string;
      brz_project_id: number;
      mb_site_id?: number;
      mb_secret?: string;
      brz_workspaces_id?: number;
      mb_page_slug?: string;
      mb_element_name?: string;
      skip_media_upload?: boolean;
      skip_cache?: boolean;
      mgr_manual?: number;
      quality_analysis?: boolean;
    }

    export interface GoogleSheet {
      id: number;
      spreadsheet_id: string;
      spreadsheet_name?: string;
      sheet_id?: string;
      sheet_name?: string;
      wave_id?: string;
      wave_name?: string;
      wave_status?: string;
      workspace_name?: string;
      last_synced_at?: string;
      created_at: string;
      updated_at: string;
    }

    export interface SheetInfo {
      id: string;
      name: string;
    }

    export interface SyncResult {
      success: boolean;
      synced_rows: number;
      updated_migrations: number;
      errors?: string[];
    }

    export default api;
