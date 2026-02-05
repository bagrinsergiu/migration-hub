import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { api, CreateTestMigrationParams } from '../api/client';
import './common.css';
import './RunMigration.css';

export default function TestRunMigration() {
  const navigate = useNavigate();
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);
  const [formData, setFormData] = useState<CreateTestMigrationParams>({
    mb_project_uuid: '',
    brz_project_id: 0,
    mb_site_id: undefined,
    mb_secret: '',
    brz_workspaces_id: undefined,
    mb_page_slug: '',
    mb_element_name: '',
    skip_media_upload: false,
    skip_cache: false,
    mgr_manual: 0,
    quality_analysis: false,
  });
  const [defaultSettings, setDefaultSettings] = useState<{ mb_site_id?: number; mb_secret?: string }>({});

  useEffect(() => {
    // Загружаем настройки по умолчанию
    api.getSettings().then((response) => {
      if (response.success && response.data) {
        setDefaultSettings({
          mb_site_id: response.data.mb_site_id || undefined,
          mb_secret: response.data.mb_secret || undefined,
        });
        // Устанавливаем значения по умолчанию в форму, если они не заданы
        if (response.data.mb_site_id && !formData.mb_site_id) {
          setFormData(prev => ({ ...prev, mb_site_id: response.data.mb_site_id }));
        }
        if (response.data.mb_secret && !formData.mb_secret) {
          setFormData(prev => ({ ...prev, mb_secret: response.data.mb_secret }));
        }
      }
    }).catch((err) => {
      console.error('Ошибка загрузки настроек:', err);
    });
  }, []);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError(null);
    setSuccess(false);

    try {
      const params: CreateTestMigrationParams = {
        mb_project_uuid: formData.mb_project_uuid,
        brz_project_id: formData.brz_project_id,
        mb_site_id: formData.mb_site_id || defaultSettings.mb_site_id || undefined,
        mb_secret: formData.mb_secret || defaultSettings.mb_secret || undefined,
      };

      if (formData.brz_workspaces_id) {
        params.brz_workspaces_id = formData.brz_workspaces_id;
      }
      if (formData.mb_page_slug) {
        params.mb_page_slug = formData.mb_page_slug;
      }
      if (formData.mb_element_name) {
        params.mb_element_name = formData.mb_element_name;
      }
      if (formData.skip_media_upload !== undefined) {
        params.skip_media_upload = formData.skip_media_upload;
      }
      if (formData.skip_cache !== undefined) {
        params.skip_cache = formData.skip_cache;
      }
      if (formData.mgr_manual !== undefined) {
        params.mgr_manual = formData.mgr_manual;
      }
      if (formData.quality_analysis !== undefined) {
        params.quality_analysis = formData.quality_analysis;
      }

      const response = await api.createTestMigration(params);
      
      if (response.success && response.data && response.data.id) {
        setSuccess(true);
        // Переходим сразу на детали созданной миграции
        const migrationId = response.data.id;
        setTimeout(() => {
          navigate(`/test/${migrationId}`);
        }, 1500);
      } else {
        const errorMessage = response.error || 'Ошибка создания тестовой миграции';
        setError(errorMessage);
      }
    } catch (err: any) {
      let errorMessage = 'Ошибка создания тестовой миграции';
      if (err.response?.data?.error) {
        errorMessage = err.response.data.error;
      } else if (err.message) {
        errorMessage = err.message;
      }
      setError(errorMessage);
    } finally {
      setLoading(false);
    }
  };

  const handleChange = (field: keyof CreateTestMigrationParams, value: string | number | boolean | undefined) => {
    setFormData(prev => ({ ...prev, [field]: value }));
  };

  return (
    <div className="run-migration">
      <div className="page-header">
        <h2>Добавить тестовую миграцию</h2>
      </div>

      {error && (
        <div className="alert alert-error">
          ❌ {error}
        </div>
      )}

      {success && (
        <div className="alert alert-success">
          ✅ Тестовая миграция успешно создана! Перенаправление...
        </div>
      )}

      <div className="card">
        <form onSubmit={handleSubmit} className="migration-form">
          <div className="form-group">
            <label className="form-label">
              MB Project UUID <span className="required">*</span>
            </label>
            <input
              type="text"
              className="form-input"
              value={formData.mb_project_uuid}
              onChange={(e) => handleChange('mb_project_uuid', e.target.value)}
              placeholder="3c56530e-ca31-4a7c-964f-e69be01f382a"
              required
            />
            <div className="form-help">UUID проекта в Ministry Brands</div>
          </div>

          <div className="form-group">
            <label className="form-label">
              Brizy Project ID <span className="required">*</span>
            </label>
            <input
              type="number"
              className="form-input"
              value={formData.brz_project_id || ''}
              onChange={(e) => handleChange('brz_project_id', parseInt(e.target.value) || 0)}
              placeholder="23131991"
              required
            />
            <div className="form-help">ID проекта в Brizy</div>
          </div>

          <div className="form-group">
            <label className="form-label">
              MB Site ID
              {defaultSettings.mb_site_id && (
                <span className="form-default-badge">(из настроек: {defaultSettings.mb_site_id})</span>
              )}
            </label>
            <input
              type="number"
              className="form-input"
              value={formData.mb_site_id || ''}
              onChange={(e) => handleChange('mb_site_id', e.target.value ? parseInt(e.target.value) : undefined)}
              placeholder={defaultSettings.mb_site_id ? String(defaultSettings.mb_site_id) : "31383"}
            />
            <div className="form-help">
              ID сайта в Ministry Brands
              {!defaultSettings.mb_site_id && (
                <span className="form-help-hint"> (можно задать в <a href="/settings">настройках</a>)</span>
              )}
            </div>
          </div>

          <div className="form-group">
            <label className="form-label">
              MB Secret
              {defaultSettings.mb_secret && (
                <span className="form-default-badge">(из настроек: ••••••••)</span>
              )}
            </label>
            <input
              type="password"
              className="form-input"
              value={formData.mb_secret}
              onChange={(e) => handleChange('mb_secret', e.target.value)}
              placeholder={defaultSettings.mb_secret ? "••••••••" : "b0kcNmG1cvoMl471cFK2NiOvCIwtPB5Q"}
            />
            <div className="form-help">
              Секретный ключ для доступа к MB API
              {!defaultSettings.mb_secret && (
                <span className="form-help-hint"> (можно задать в <a href="/settings">настройках</a>)</span>
              )}
            </div>
          </div>

          <div className="form-group">
            <label className="form-label">Brizy Workspaces ID</label>
            <input
              type="number"
              className="form-input"
              value={formData.brz_workspaces_id || ''}
              onChange={(e) => handleChange('brz_workspaces_id', e.target.value ? parseInt(e.target.value) : undefined)}
              placeholder="22925473"
            />
            <div className="form-help">ID рабочего пространства в Brizy (опционально)</div>
          </div>

          <div className="form-group">
            <label className="form-label">MB Page Slug</label>
            <input
              type="text"
              className="form-input"
              value={formData.mb_page_slug}
              onChange={(e) => handleChange('mb_page_slug', e.target.value)}
              placeholder="home"
            />
            <div className="form-help">Slug страницы для миграции одной страницы (опционально, для тестирования)</div>
          </div>

          <div className="form-group">
            <label className="form-label">MB Element Name</label>
            <input
              type="text"
              className="form-input"
              value={formData.mb_element_name}
              onChange={(e) => handleChange('mb_element_name', e.target.value)}
              placeholder="gallery-layout"
            />
            <div className="form-help">
              Имя элемента для миграции только одного элемента на странице (опционально, для тестирования).
              Примеры: "gallery-layout", "text-layout", "event-calendar-layout"
            </div>
          </div>

          <div className="form-group">
            <label className="form-label">Manual</label>
            <select
              className="form-input"
              value={formData.mgr_manual}
              onChange={(e) => handleChange('mgr_manual', parseInt(e.target.value))}
            >
              <option value="0">Автоматически</option>
              <option value="1">Вручную</option>
            </select>
            <div className="form-help">Режим миграции</div>
          </div>

          <div className="form-group">
            <label className="form-label checkbox-label">
              <input
                type="checkbox"
                checked={formData.skip_media_upload || false}
                onChange={(e) => handleChange('skip_media_upload', e.target.checked)}
                className="form-checkbox"
              />
              <span>Пропустить загрузку медиа-файлов</span>
            </label>
            <div className="form-help">
              Ускоряет тестирование, но медиа не будет доступно в мигрированном проекте
            </div>
          </div>

          <div className="form-group">
            <label className="form-label checkbox-label">
              <input
                type="checkbox"
                checked={formData.skip_cache || false}
                onChange={(e) => handleChange('skip_cache', e.target.checked)}
                className="form-checkbox"
              />
              <span>Пропустить использование кэша</span>
            </label>
            <div className="form-help">
              Полезно для тестирования с "чистого листа" или при изменении логики миграции
            </div>
          </div>

          <div className="form-group">
            <label className="form-label checkbox-label">
              <input
                type="checkbox"
                checked={formData.quality_analysis || false}
                onChange={(e) => handleChange('quality_analysis', e.target.checked)}
                className="form-checkbox"
              />
              <span>Включить анализ качества миграции</span>
            </label>
            <div className="form-help">
              При включении будет выполнен AI-анализ качества миграции каждой страницы с сравнением скриншотов и выявлением проблем
            </div>
          </div>

          <div className="form-actions">
            <button
              type="submit"
              className="btn btn-primary"
              disabled={loading || success}
            >
              {loading ? 'Создание...' : 'Создать тестовую миграцию'}
            </button>
            <button
              type="button"
              onClick={() => navigate('/test')}
              className="btn btn-secondary"
              disabled={loading}
            >
              Отмена
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
