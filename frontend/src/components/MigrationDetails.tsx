import { useState, useEffect, useRef, useCallback } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { api, MigrationDetails as MigrationDetailsType, QualityStatistics } from '../api/client';
import { getStatusConfig } from '../utils/status';
import { formatDate, formatUUID } from '../utils/format';
import QualityAnalysis from './QualityAnalysis';
import './MigrationDetails.css';
import './common.css';
import './WaveDetails.css';

export default function MigrationDetails() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [details, setDetails] = useState<MigrationDetailsType | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [restarting, setRestarting] = useState(false);
  const [showRestartForm, setShowRestartForm] = useState(false);
  const [restartParams, setRestartParams] = useState({
    mb_site_id: '',
    mb_secret: '',
    brz_workspaces_id: '',
    mb_page_slug: '',
    mgr_manual: '0',
    quality_analysis: false,
  });
  const [defaultSettings, setDefaultSettings] = useState<{ mb_site_id?: number; mb_secret?: string }>({});
  const [activeTab, setActiveTab] = useState<'management' | 'details' | 'pages' | 'analysis' | 'archive' | 'warnings' | 'statistics'>('management');
  const [pagesList, setPagesList] = useState<any[]>([]);
  const [loadingPages, setLoadingPages] = useState(false);
  const [rebuildingPages, setRebuildingPages] = useState<{ [key: string]: boolean }>({});
  const [pageMigrationStatus, setPageMigrationStatus] = useState<{ [key: string]: 'in_progress' | 'completed' | 'error' | null }>({});
  const [qualityStatistics, setQualityStatistics] = useState<QualityStatistics | null>(null);
  const [processInfo, setProcessInfo] = useState<any | null>(null);
  const [loadingProcessInfo, setLoadingProcessInfo] = useState(false);
  const [refreshingProcessInfo, setRefreshingProcessInfo] = useState(false);
  const [killingProcess, setKillingProcess] = useState(false);
  const [removingLock, setRemovingLock] = useState(false);
  const [removingCache, setRemovingCache] = useState(false);
  const [resettingStatus, setResettingStatus] = useState(false);
  const [hardResetting, setHardResetting] = useState(false);
  const [hasRefreshedAfterCompletion, setHasRefreshedAfterCompletion] = useState(false);
  const [showLogs, setShowLogs] = useState(false);
  const [logs, setLogs] = useState<string | null>(null);
  const [loadingLogs, setLoadingLogs] = useState(false);
  const logsContentRef = useRef<HTMLDivElement>(null);
  const [webhookInfo, setWebhookInfo] = useState<any | null>(null);
  const [loadingWebhookInfo, setLoadingWebhookInfo] = useState(false);

  // Вспомогательная функция для безопасного парсинга changes_json
  const safeParseChangesJson = (changesJsonValue: any): any => {
    if (!changesJsonValue) return null;
    
    try {
      // Если уже объект, возвращаем как есть
      if (typeof changesJsonValue === 'object' && !Array.isArray(changesJsonValue)) {
        return changesJsonValue;
      }
      
      // Если строка, проверяем на обрезанность и парсим
      if (typeof changesJsonValue === 'string') {
        const trimmed = changesJsonValue.trim();
        // Проверяем, не обрезан ли JSON (неполная строка)
        if (trimmed.length > 0 && !trimmed.endsWith('}') && !trimmed.endsWith(']')) {
          // JSON обрезан - не парсим, возвращаем null
          return null;
        }
        // Пытаемся распарсить
        return JSON.parse(trimmed);
      }
      
      return null;
    } catch (e) {
      // Ошибка парсинга - возвращаем null без логирования
      return null;
    }
  };

  useEffect(() => {
    // Загружаем настройки по умолчанию
    api.getSettings().then((response) => {
      if (response.success && response.data) {
        setDefaultSettings({
          mb_site_id: response.data.mb_site_id || undefined,
          mb_secret: response.data.mb_secret || undefined,
        });
      }
    }).catch((err) => {
      console.error('Ошибка загрузки настроек:', err);
    });
  }, []);

  const loadQualityStatistics = async () => {
    if (!id) return;
    try {
      const response = await api.getQualityStatistics(parseInt(id));
      if (response.success && response.data) {
        setQualityStatistics(response.data);
      }
    } catch (err) {
      // Игнорируем ошибки - статистика опциональна
      console.error('Error loading quality statistics:', err);
    }
  };

  const loadPagesList = async () => {
    if (!id) return;
    try {
      setLoadingPages(true);
      const response = await api.getMigrationPages(parseInt(id));
      if (response.success && response.data) {
        setPagesList(response.data);
        // Обновляем статусы миграции на основе processInfo и details
        if (processInfo?.process?.running) {
          // Если есть информация о текущей странице в lock-файле
          const currentChangesJson = safeParseChangesJson(details?.mapping?.changes_json);
          const currentPageSlug = processInfo.process.current_page_slug || 
                                 (details?.result as any)?.mb_page_slug ||
                                 currentChangesJson?.mb_page_slug;
          
          if (currentPageSlug) {
            setPageMigrationStatus(prev => {
              const newStatus = { ...prev };
              // Если текущая страница изменилась, сбрасываем статус для предыдущей
              Object.keys(newStatus).forEach(slug => {
                if (slug !== currentPageSlug && newStatus[slug] === 'in_progress') {
                  newStatus[slug] = 'completed';
                }
              });
              // Устанавливаем статус для текущей страницы
              newStatus[currentPageSlug] = 'in_progress';
              return newStatus;
            });
          }
        } else {
          // Если процесс не запущен, сбрасываем все статусы "in_progress" в "completed"
          setPageMigrationStatus(prev => {
            const newStatus = { ...prev };
            Object.keys(newStatus).forEach(slug => {
              if (newStatus[slug] === 'in_progress') {
                newStatus[slug] = 'completed';
              }
            });
            return newStatus;
          });
        }
      }
    } catch (err) {
      console.error('Error loading pages list:', err);
      setPagesList([]);
    } finally {
      setLoadingPages(false);
    }
  };

  const loadDetails = async () => {
    if (!id) return;
    try {
      setLoading(true);
      setError(null);
      const response = await api.getMigrationDetails(parseInt(id));
      if (response.success && response.data) {
        setDetails(response.data);
        // Сбрасываем флаг обновления после завершения при загрузке новых деталей
        // Это нужно для случая, когда пользователь переходит на другую миграцию
        setHasRefreshedAfterCompletion(false);
      } else {
        setError(response.error || 'Миграция не найдена');
      }
    } catch (err: any) {
      setError(err.message || 'Ошибка загрузки деталей');
    } finally {
      setLoading(false);
    }
  };

  const refreshDetails = async () => {
    // Фоновое обновление без полного спиннера и без показа загрузки
    if (!id || !details) return;
    try {
      // Не показываем индикатор загрузки при автоматическом обновлении
      const response = await api.getMigrationDetails(parseInt(id));
      if (response.success && response.data) {
        // Обновляем данные без показа загрузки
        setDetails(response.data);
      } else {
        // В фоне не ломаем текущий экран, только логируем
        console.error('Error refreshing migration details:', response.error);
      }
    } catch (err: any) {
      console.error('Error refreshing migration details:', err);
    }
    // Не устанавливаем setAutoRefreshing, чтобы не показывать индикатор
  };

  const loadWebhookInfo = async () => {
    if (!id) return;
    try {
      setLoadingWebhookInfo(true);
      const response = await api.getMigrationWebhookInfo(parseInt(id));
      if (response.success && response.data) {
        setWebhookInfo(response.data);
      }
    } catch (err: any) {
      console.error('Error loading webhook info:', err);
    } finally {
      setLoadingWebhookInfo(false);
    }
  };

  useEffect(() => {
    if (id) {
      loadDetails();
      loadQualityStatistics();
      loadProcessInfo(true); // Показываем загрузку только при первой загрузке
      loadWebhookInfo();
    }
  }, [id]);

  const loadProcessInfo = async (showLoading: boolean = false) => {
    if (!id) return;
    try {
      if (showLoading) {
        setLoadingProcessInfo(true);
      } else {
        setRefreshingProcessInfo(true);
      }
      const response = await api.getMigrationProcessInfo(parseInt(id));
      if (response.success && response.data) {
        setProcessInfo(response.data);
        
        // Если статус был автоматически обновлен, перезагружаем детали миграции в фоне
        if (response.data.status_updated) {
          // Небольшая задержка, чтобы БД успела обновиться
          setTimeout(() => {
            refreshDetails(); // Используем refreshDetails вместо loadDetails, чтобы не показывать загрузку
          }, 500);
        }
      }
    } catch (err) {
      console.error('Error loading process info:', err);
    } finally {
      if (showLoading) {
        setLoadingProcessInfo(false);
      } else {
        setRefreshingProcessInfo(false);
      }
    }
  };

  const handleKillProcess = async (force: boolean = false) => {
    if (!id) return;
    if (!confirm(`Вы уверены, что хотите ${force ? 'принудительно ' : ''}завершить процесс миграции?`)) {
      return;
    }
    try {
      setKillingProcess(true);
      const response = await api.killMigrationProcess(parseInt(id), force);
      if (response.success) {
        alert(response.data?.message || 'Процесс завершен');
        await loadProcessInfo();
        await loadDetails();
      } else {
        alert(response.error || 'Ошибка при завершении процесса');
      }
    } catch (err: any) {
      alert(err.message || 'Ошибка при завершении процесса');
    } finally {
      setKillingProcess(false);
    }
  };

  const handleRemoveLock = async () => {
    if (!id) return;
    if (!confirm('Вы уверены, что хотите удалить lock-файл? Это позволит перезапустить миграцию.')) {
      return;
    }
    try {
      setRemovingLock(true);
      const response = await api.removeMigrationLock(parseInt(id));
      if (response.success) {
        alert(response.data?.message || 'Lock-файл удален');
        await loadProcessInfo();
        await loadDetails();
      } else {
        alert(response.error || 'Ошибка при удалении lock-файла');
      }
    } catch (err: any) {
      alert(err.message || 'Ошибка при удалении lock-файла');
    } finally {
      setRemovingLock(false);
    }
  };

  const handleRemoveCache = async () => {
    if (!id) return;
    if (!confirm('Вы уверены, что хотите удалить кэш-файл миграции? Это удалит все промежуточные данные кэша.')) {
      return;
    }
    try {
      setRemovingCache(true);
      const response = await api.removeMigrationCache(parseInt(id));
      if (response.success) {
        alert(response.data?.message || 'Кэш-файл удален');
        await loadDetails();
        await loadProcessInfo(false);
      } else {
        alert(response.error || 'Ошибка при удалении кэш-файла');
      }
    } catch (err: any) {
      alert(err.message || 'Ошибка при удалении кэш-файла');
    } finally {
      setRemovingCache(false);
    }
  };

  const handleResetStatus = async () => {
    if (!id) return;
    if (!confirm('Вы уверены, что хотите сбросить статус миграции? Статус будет установлен на "pending", и миграцию можно будет перезапустить.')) {
      return;
    }
    try {
      setResettingStatus(true);
      const response = await api.resetMigrationStatus(parseInt(id));
      if (response.success) {
        alert(response.data?.message || 'Статус миграции сброшен');
        await loadDetails();
        await loadProcessInfo(false);
      } else {
        alert(response.error || 'Ошибка при сбросе статуса');
      }
    } catch (err: any) {
      alert(err.message || 'Ошибка при сбросе статуса');
    } finally {
      setResettingStatus(false);
    }
  };

  const handleHardReset = async () => {
    if (!id) return;
    if (!confirm('Вы уверены, что хотите выполнить HARD RESET?\n\nЭто действие:\n- Удалит lock-файл\n- Удалит cache-файл\n- Завершит процесс миграции (если запущен)\n- Сбросит статус в БД на "pending"\n\nПосле этого миграцию можно будет перезапустить.')) {
      return;
    }
    try {
      setHardResetting(true);
      const response = await api.hardResetMigration(parseInt(id));
      if (response.success) {
        const results = response.data?.results || {};
        const messages = results.messages || [];
        const summary = [
          'Hard reset выполнен:',
          ...messages
        ].join('\n');
        alert(summary);
        await loadDetails();
        await loadProcessInfo(false);
      } else {
        alert(response.error || 'Ошибка при выполнении hard reset');
      }
    } catch (err: any) {
      alert(err.message || 'Ошибка при выполнении hard reset');
    } finally {
      setHardResetting(false);
    }
  };

  useEffect(() => {
    // Обновляем статус каждые 3 секунды если миграция в процессе
    // Частое обновление для отслеживания текущего этапа миграции
    const hasActiveMigration = details?.status === 'in_progress' || 
                               (processInfo?.lock_file_exists && processInfo?.process?.running) ||
                               Object.values(pageMigrationStatus).some(status => status === 'in_progress');
    
    if (hasActiveMigration) {
      // Сбрасываем флаг при начале новой миграции
      setHasRefreshedAfterCompletion(false);
      const interval = setInterval(() => {
        refreshDetails(); // Обновляет только данные, без показа загрузки
        loadProcessInfo(false); // Обновляем в фоне без показа загрузки
        // Обновляем список страниц, чтобы обновить статусы
        loadPagesList();
      }, 3000); // Обновление каждые 3 секунды
      return () => clearInterval(interval);
    }
    
    // Обновляем данные после завершения миграции (успешной или нет)
    // Обновляем только один раз после завершения
    if ((details?.status === 'success' || details?.status === 'error' || details?.status === 'completed') && !hasRefreshedAfterCompletion) {
      setHasRefreshedAfterCompletion(true);
      // Обновляем данные после завершения миграции
      refreshDetails();
      loadProcessInfo(false);
    }
  }, [details?.status, processInfo?.lock_file_exists, processInfo?.process?.running, hasRefreshedAfterCompletion, pageMigrationStatus]);

  // Обновляем статусы страниц при изменении processInfo
  useEffect(() => {
    if (processInfo?.process?.running) {
      // Безопасно получаем changesJson
      const currentChangesJson = safeParseChangesJson(details?.mapping?.changes_json);
      
      const currentPageSlug = processInfo.process.current_page_slug || 
                             (details?.result as any)?.mb_page_slug ||
                             currentChangesJson?.mb_page_slug;
      
      if (currentPageSlug) {
        setPageMigrationStatus(prev => {
          const newStatus = { ...prev };
          // Если текущая страница изменилась, сбрасываем статус для предыдущей
          Object.keys(newStatus).forEach(slug => {
            if (slug !== currentPageSlug && newStatus[slug] === 'in_progress') {
              newStatus[slug] = 'completed';
            }
          });
          // Устанавливаем статус для текущей страницы
          newStatus[currentPageSlug] = 'in_progress';
          return newStatus;
        });
      }
    } else {
      // Если процесс не запущен, сбрасываем все статусы "in_progress" в "completed"
      setPageMigrationStatus(prev => {
        const newStatus = { ...prev };
        Object.keys(newStatus).forEach(slug => {
          if (newStatus[slug] === 'in_progress') {
            newStatus[slug] = 'completed';
          }
        });
        return newStatus;
      });
    }
  }, [processInfo?.process?.running, processInfo?.process?.current_page_slug, details?.result, details?.mapping?.changes_json]);

  const loadMigrationLogs = useCallback(async () => {
    if (!id) return;
    
    try {
      setLoadingLogs(true);
      const response = await api.getMigrationLogs(parseInt(id));
      
      if (response.success && response.data) {
        let logText = '';
        
        // Обрабатываем разные форматы ответа
        if (Array.isArray(response.data.logs)) {
          logText = response.data.logs
            .filter((line: string) => line && line.trim())
            .join('\n');
        } else if (typeof response.data.logs === 'string') {
          logText = response.data.logs;
        } else if (typeof response.data === 'string') {
          logText = response.data;
        } else {
          logText = JSON.stringify(response.data, null, 2);
        }
        
        // Нормализуем переносы строк
        logText = logText.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
        logText = logText.replace(/\]\[/g, ']\n[');
        
        setLogs(logText);
      } else {
        setLogs('Не удалось загрузить логи: ' + (response.error || 'Неизвестная ошибка'));
      }
    } catch (err: any) {
      setLogs('Ошибка загрузки логов: ' + (err.message || 'Неизвестная ошибка'));
    } finally {
      setLoadingLogs(false);
    }
  }, [id]);

  // Автообновление логов для активных миграций
  useEffect(() => {
    if (!showLogs || !id) return;
    
    if (details?.status === 'in_progress') {
      const interval = setInterval(() => {
        loadMigrationLogs();
      }, 3000);
      
      return () => clearInterval(interval);
    }
  }, [showLogs, details?.status, id, loadMigrationLogs]);

  // Автопрокрутка логов вверх при обновлении
  useEffect(() => {
    if (logsContentRef.current && showLogs && logs) {
      logsContentRef.current.scrollTop = 0;
    }
  }, [logs, showLogs]);

  const handleRestart = async () => {
    if (!id) return;
    try {
      setRestarting(true);
      const params: any = {};
      // Используем значения из формы, если они заданы, иначе из настроек по умолчанию
      if (restartParams.mb_site_id) {
        params.mb_site_id = parseInt(restartParams.mb_site_id);
      } else if (defaultSettings.mb_site_id) {
        params.mb_site_id = defaultSettings.mb_site_id;
      }
      if (restartParams.mb_secret) {
        params.mb_secret = restartParams.mb_secret;
      } else if (defaultSettings.mb_secret) {
        params.mb_secret = defaultSettings.mb_secret;
      }
      if (restartParams.brz_workspaces_id) params.brz_workspaces_id = parseInt(restartParams.brz_workspaces_id);
      if (restartParams.mb_page_slug) params.mb_page_slug = restartParams.mb_page_slug;
      if (restartParams.mgr_manual) params.mgr_manual = parseInt(restartParams.mgr_manual);
      if (restartParams.quality_analysis !== undefined) {
        params.quality_analysis = restartParams.quality_analysis;
      }

      const response = await api.restartMigration(parseInt(id), params);
      if (response.success) {
        setShowRestartForm(false);
        loadDetails();
      } else {
        setError(response.error || 'Ошибка перезапуска');
      }
    } catch (err: any) {
      setError(err.message || 'Ошибка перезапуска');
    } finally {
      setRestarting(false);
    }
  };

  if (loading) {
    return (
      <div className="loading-container">
        <div className="spinner"></div>
        <p>Загрузка деталей миграции...</p>
      </div>
    );
  }

  if (error && !details) {
    return (
      <div className="error-container">
        <p className="error-message">❌ {error}</p>
        <button onClick={() => navigate('/')} className="btn btn-primary">
          Вернуться к списку
        </button>
      </div>
    );
  }

  if (!details) {
    return null;
  }

  const statusConfig = getStatusConfig(details.status);
  
  // Безопасный парсинг result_json
  let resultData = null;
  if (details.result?.result_json) {
    try {
      resultData = typeof details.result.result_json === 'string'
        ? JSON.parse(details.result.result_json)
        : details.result.result_json;
    } catch (e) {
      console.error('Error parsing result_json:', e);
      resultData = null;
    }
  }
  
  // Извлекаем данные из value, если они там находятся, или используем result_data из API
  const migrationValue = (details as any).result_data || resultData?.value || resultData;
  
  // Безопасный парсинг changes_json
  const changesJson = safeParseChangesJson(details.mapping.changes_json);
  
  // Если migrationValue пуст, но есть changes_json с данными, используем их
  if (!migrationValue && changesJson) {
    // Можно использовать данные из changes_json как fallback
  }

  return (
    <div className="migration-details">
      <div className="page-header">
        <button onClick={() => navigate('/')} className="btn btn-secondary">
          ← Назад
        </button>
        <h2>Детали миграции #{details.mapping.brz_project_id}</h2>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
          <span
            className="status-badge"
            style={{
              color: statusConfig.color,
              backgroundColor: statusConfig.bgColor,
            }}
          >
            {statusConfig.label}
          </span>
        </div>
      </div>

      {error && (
        <div className="alert alert-error">
          {error}
        </div>
      )}

      <div className="migration-tabs">
        <button
          className={activeTab === 'management' ? 'active' : ''}
          onClick={() => setActiveTab('management')}
        >
          Управление
        </button>
        <button
          className={activeTab === 'details' ? 'active' : ''}
          onClick={() => setActiveTab('details')}
        >
          Детали
        </button>
        <button
          className={activeTab === 'pages' ? 'active' : ''}
          onClick={() => {
            setActiveTab('pages');
            loadPagesList();
          }}
        >
          Страницы
          {pagesList.length > 0 && (
            <span className="badge-count">{pagesList.length}</span>
          )}
        </button>
        <button
          className={activeTab === 'analysis' ? 'active' : ''}
          onClick={() => setActiveTab('analysis')}
        >
          Анализ
        </button>
        <button
          className={activeTab === 'archive' ? 'active' : ''}
          onClick={() => setActiveTab('archive')}
        >
          Архив
        </button>
        <button
          className={activeTab === 'warnings' ? 'active' : ''}
          onClick={() => setActiveTab('warnings')}
        >
          Предупреждения
          {((migrationValue?.message?.warning && migrationValue.message.warning.length > 0) ||
            (details.warnings && details.warnings.length > 0) ||
            details.status === 'error' ||
            resultData?.error) && (
            <span className="badge-count">
              {[
                migrationValue?.message?.warning?.length || 0,
                details.warnings?.length || 0,
                details.status === 'error' ? 1 : 0,
                resultData?.error ? 1 : 0
              ].reduce((a, b) => a + b, 0)}
            </span>
          )}
        </button>
        <button
          className={activeTab === 'statistics' ? 'active' : ''}
          onClick={() => setActiveTab('statistics')}
        >
          Статистика
          {qualityStatistics && (
            <span className="badge-count">{qualityStatistics.total_pages > 0 ? qualityStatistics.total_pages : ''}</span>
          )}
        </button>
      </div>

      {activeTab === 'management' && (
        <div className="management-tab">
          <div className="card">
            <div className="card-header">
              <h3 className="card-title">Действия</h3>
            </div>
            <div className="actions">
              <button
                onClick={() => setShowRestartForm(true)}
                className="btn btn-primary"
                disabled={details.status === 'in_progress'}
              >
                Перезапустить миграцию
              </button>
              {details.status === 'in_progress' && (
                <button onClick={loadDetails} className="btn btn-secondary">
                  Обновить статус
                </button>
              )}
              <button
                onClick={() => {
                  if (showLogs) {
                    setShowLogs(false);
                    setLogs(null);
                  } else {
                    setShowLogs(true);
                    loadMigrationLogs();
                  }
                }}
                className="btn btn-secondary"
                title="Показать логи миграции"
              >
                📋 Логи
              </button>
            </div>

            {/* Информация о веб-хуке */}
            <div className="webhook-info" style={{ marginTop: '1.5rem', paddingTop: '1.5rem', borderTop: '1px solid #e0e0e0' }}>
              <h4 style={{ marginBottom: '1rem' }}>Информация о веб-хуке</h4>
              {loadingWebhookInfo ? (
                <div style={{ padding: '1rem', textAlign: 'center' }}>
                  <span className="inline-spinner" /> Загрузка информации о веб-хуке...
                </div>
              ) : webhookInfo ? (
                <div style={{ display: 'flex', flexDirection: 'column', gap: '0.75rem' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                    <strong>Статус регистрации:</strong>
                    <span style={{ 
                      padding: '0.25rem 0.5rem', 
                      borderRadius: '4px',
                      backgroundColor: webhookInfo.webhook_registered ? '#d4edda' : '#f8d7da',
                      color: webhookInfo.webhook_registered ? '#155724' : '#721c24',
                      fontSize: '0.875rem'
                    }}>
                      {webhookInfo.webhook_registered ? '✓ Зарегистрирован' : '✗ Не зарегистрирован'}
                    </span>
                  </div>
                  
                  <div>
                    <strong>URL веб-хука:</strong>
                    <div style={{ 
                      marginTop: '0.25rem', 
                      padding: '0.5rem', 
                      backgroundColor: '#f8f9fa', 
                      borderRadius: '4px',
                      fontFamily: 'monospace',
                      fontSize: '0.875rem',
                      wordBreak: 'break-all'
                    }}>
                      {webhookInfo.webhook_url}
                    </div>
                  </div>
                  
                  <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap' }}>
                    <strong>Веб-хук получен:</strong>
                    <span style={{ 
                      padding: '0.25rem 0.5rem', 
                      borderRadius: '4px',
                      backgroundColor: webhookInfo.webhook_received ? '#d4edda' : '#fff3cd',
                      color: webhookInfo.webhook_received ? '#155724' : '#856404',
                      fontSize: '0.875rem'
                    }}>
                      {webhookInfo.webhook_received ? '✓ Да' : '⚠ Нет'}
                    </span>
                    {webhookInfo.webhook_received_at && (
                      <span style={{ fontSize: '0.875rem', color: '#6c757d' }}>
                        ({new Date(webhookInfo.webhook_received_at).toLocaleString('ru-RU')})
                      </span>
                    )}
                  </div>
                  
                  {webhookInfo.last_result && (
                    <div>
                      <strong>Последний результат:</strong>
                      <div style={{ 
                        marginTop: '0.25rem', 
                        padding: '0.5rem', 
                        backgroundColor: '#f8f9fa', 
                        borderRadius: '4px',
                        fontSize: '0.875rem'
                      }}>
                        <div>UUID: {webhookInfo.last_result.migration_uuid || 'N/A'}</div>
                        <div>Статус: {webhookInfo.last_result.status || 'N/A'}</div>
                        {webhookInfo.last_result.created_at && (
                          <div>Получен: {new Date(webhookInfo.last_result.created_at).toLocaleString('ru-RU')}</div>
                        )}
                      </div>
                    </div>
                  )}
                  
                  {webhookInfo.webhook_logs && webhookInfo.webhook_logs.length > 0 && (
                    <div>
                      <strong>Последние записи в логах:</strong>
                      <div style={{ 
                        marginTop: '0.25rem', 
                        padding: '0.5rem', 
                        backgroundColor: '#f8f9fa', 
                        borderRadius: '4px',
                        maxHeight: '150px',
                        overflowY: 'auto',
                        fontSize: '0.75rem',
                        fontFamily: 'monospace'
                      }}>
                        {webhookInfo.webhook_logs.map((log: string, index: number) => (
                          <div key={index} style={{ marginBottom: '0.25rem', color: '#6c757d' }}>
                            {log}
                          </div>
                        ))}
                      </div>
                    </div>
                  )}
                  
                  {webhookInfo.server_status && (
                    <div>
                      <strong>Статус с сервера миграции:</strong>
                      <div style={{ 
                        marginTop: '0.25rem', 
                        padding: '0.5rem', 
                        backgroundColor: '#f8f9fa', 
                        borderRadius: '4px',
                        fontSize: '0.875rem'
                      }}>
                        <div>Статус: {webhookInfo.server_status.status || 'N/A'}</div>
                        {webhookInfo.server_status.progress && (
                          <div>
                            Прогресс: {webhookInfo.server_status.progress.progress_percent || 0}% 
                            ({webhookInfo.server_status.progress.processed_pages || 0} / {webhookInfo.server_status.progress.total_pages || 0})
                          </div>
                        )}
                      </div>
                    </div>
                  )}
                  
                  <div style={{ marginTop: '0.5rem', fontSize: '0.875rem', color: '#6c757d' }}>
                    <p><strong>Как это работает:</strong></p>
                    <ul style={{ marginLeft: '1.5rem', marginTop: '0.25rem' }}>
                      <li>При запуске миграции веб-хук автоматически регистрируется на сервере миграции</li>
                      <li>Сервер миграции вызывает веб-хук по завершении миграции (успешной или с ошибкой)</li>
                      <li>Дашборд также периодически опрашивает статус миграции (каждые 3 секунды)</li>
                      <li>Если веб-хук не получен, статус обновляется через опрос</li>
                    </ul>
                  </div>
                  
                  <button 
                    onClick={loadWebhookInfo} 
                    className="btn btn-secondary"
                    style={{ marginTop: '0.5rem', alignSelf: 'flex-start' }}
                  >
                    🔄 Обновить информацию
                  </button>
                </div>
              ) : (
                <div style={{ padding: '1rem', textAlign: 'center', color: '#6c757d' }}>
                  Информация о веб-хуке недоступна
                </div>
              )}
            </div>

            {/* Управление кэшем и статусом */}
            <div className="cache-management" style={{ marginTop: '1.5rem', paddingTop: '1.5rem', borderTop: '1px solid #e0e0e0' }}>
              <h4 style={{ marginBottom: '1rem' }}>Управление кэшем и статусом</h4>
              <div className="cache-actions" style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
                <button
                  onClick={handleRemoveCache}
                  className="btn btn-warning"
                  disabled={removingCache}
                  title="Удалить кэш-файл миграции (промежуточные данные)"
                >
                  {removingCache ? 'Удаление...' : '🗑️ Удалить кэш'}
                </button>
                <button
                  onClick={handleResetStatus}
                  className="btn btn-info"
                  disabled={resettingStatus || details.status === 'pending'}
                  title="Сбросить статус миграции на 'pending', чтобы можно было перезапустить"
                >
                  {resettingStatus ? 'Сброс...' : '🔄 Сбросить статус'}
                </button>
                <button
                  onClick={handleHardReset}
                  className="btn btn-danger"
                  disabled={hardResetting}
                  title="Hard Reset: удалить lock-файл, cache-файл, завершить процесс и сбросить статус"
                >
                  {hardResetting ? 'Выполнение...' : '💥 Hard Reset'}
                </button>
              </div>
              <div className="form-help" style={{ marginTop: '0.5rem', fontSize: '0.875rem', color: '#6c757d' }}>
                <p>• <strong>Удалить кэш</strong> - удаляет промежуточные данные кэша миграции</p>
                <p>• <strong>Сбросить статус</strong> - устанавливает статус на "pending", позволяя перезапустить миграцию</p>
                <p>• <strong>Hard Reset</strong> - полный сброс: удаляет lock-файл, cache-файл, завершает процесс и сбрасывает статус (одна кнопка для полной очистки)</p>
              </div>
            </div>
          </div>

          {/* Статус процесса миграции - отдельная карточка, всегда видна */}
          <div className="card" style={{ marginTop: '1.5rem' }}>
            <div className="card-header">
              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <h3 className="card-title">Статус процесса миграции</h3>
                {refreshingProcessInfo && (
                  <span className="status-refresh-indicator" title="Обновление информации о процессе...">
                    <span className="inline-spinner" style={{ width: '12px', height: '12px', borderWidth: '2px' }} />
                  </span>
                )}
              </div>
            </div>
            <div className="card-body">
              {/* Блок уведомлений и информации о процессе - сразу под заголовком */}
              {!loadingProcessInfo && processInfo && (
                <>
                  {/* Уведомление о статусе lock-файла - показываем только если нет process.message или оно не содержит эту информацию */}
                  {!processInfo.lock_file_exists && !processInfo.process?.running && 
                   (!processInfo.process?.message || !processInfo.process.message.includes('Lock-файл не найден')) && (
                    <div className="alert alert-info" style={{ marginBottom: '1rem', padding: '0.75rem', fontSize: '0.875rem', borderRadius: '4px', backgroundColor: '#d1ecf1', border: '1px solid #bee5eb', color: '#0c5460' }}>
                      ℹ️ Lock-файл не найден, процесс не запущен
                    </div>
                  )}
                  
                  {/* Информация о том, как был обнаружен процесс - показываем только если нет process.message или оно не содержит эту информацию */}
                  {processInfo.process?.running && processInfo.process?.detected_by && 
                   (!processInfo.process?.message || !processInfo.process.message.includes('найден') && !processInfo.process.message.includes('определен')) && (
                    <div className="alert alert-info" style={{ marginBottom: '1rem', padding: '0.75rem', fontSize: '0.875rem', borderRadius: '4px', backgroundColor: '#d1ecf1', border: '1px solid #bee5eb', color: '#0c5460' }}>
                      ℹ️ {processInfo.process.detected_by === 'lock_file_pid' ? 'Процесс найден по PID из lock-файла' :
                          processInfo.process.detected_by === 'lock_file_timestamp_and_db_status' ? 'Процесс определен по времени файла и статусу БД' :
                          processInfo.process.detected_by === 'db_status' ? 'Процесс определен по статусу БД' :
                          processInfo.process.detected_by === 'lsof' ? 'Процесс найден через lsof' :
                          processInfo.process.detected_by === 'fuser' ? 'Процесс найден через fuser' :
                          processInfo.process.detected_by === 'ps_grep' ? 'Процесс найден через ps' :
                          'Процесс найден'}
                    </div>
                  )}
                  
                  {/* Уведомление о статусе миграции */}
                  {processInfo.status_updated && (
                    <div className="alert alert-info" style={{ marginBottom: '1rem', padding: '0.75rem', fontSize: '0.875rem', borderRadius: '4px', backgroundColor: '#d1ecf1', border: '1px solid #bee5eb', color: '#0c5460' }}>
                      ✅ Статус миграции был автоматически обновлен, так как процесс не найден. Страница будет обновлена...
                    </div>
                  )}
                  
                  {/* Уведомление о lock-файле без процесса */}
                  {processInfo.lock_file_exists && !processInfo.process?.running && !processInfo.status_updated && 
                   (!processInfo.process?.message || !processInfo.process.message.includes('Lock-файл')) && (
                    <div className="alert alert-warning" style={{ marginBottom: '1rem', padding: '0.75rem', fontSize: '0.875rem', borderRadius: '4px', backgroundColor: '#fff3cd', border: '1px solid #ffc107', color: '#856404' }}>
                      ⚠️ Lock-файл существует, но процесс не найден.
                      {processInfo.process?.lock_file_age !== undefined && processInfo.process.lock_file_age > 600 && (
                        <span> Lock-файл не обновлялся более {Math.floor(processInfo.process.lock_file_age / 60)} минут.</span>
                      )}
                      {' '}Возможно, процесс был прерван. Рекомендуется удалить lock-файл, чтобы разрешить повторный запуск миграции.
                    </div>
                  )}
                  
                  {/* Уведомление о процессе без PID */}
                  {processInfo.process?.running && !processInfo.process?.pid && 
                   (!processInfo.process?.message || !processInfo.process.message.includes('PID') && !processInfo.process.message.includes('синхронно')) && (
                    <div className="alert alert-info" style={{ marginBottom: '1rem', padding: '0.75rem', fontSize: '0.875rem', borderRadius: '4px', backgroundColor: '#d1ecf1', border: '1px solid #bee5eb', color: '#0c5460' }}>
                      ℹ️ Процесс миграции активен (определен по статусу в БД и времени модификации lock-файла). PID процесса недоступен, возможно миграция выполняется синхронно через веб-сервер.
                    </div>
                  )}
                  
                  {/* Сообщение от процесса - показываем всегда, если есть */}
                  {processInfo.process?.message && (
                    <div className="alert alert-info" style={{ marginBottom: '1rem', padding: '0.75rem', fontSize: '0.875rem', borderRadius: '4px', backgroundColor: '#d1ecf1', border: '1px solid #bee5eb', color: '#0c5460' }}>
                      ℹ️ {processInfo.process.message}
                    </div>
                  )}
                </>
              )}
              
              {loadingProcessInfo ? (
                  <div style={{ padding: '1rem', textAlign: 'center' }}>
                    <span className="inline-spinner" /> Загрузка информации о процессе...
                  </div>
                ) : processInfo ? (
                  <div className="process-info" style={{ marginBottom: '1rem' }}>
                    <div className="info-grid">
                      <div className="info-item">
                        <span className="info-label">Lock-файл:</span>
                        <span className="info-value">
                          {processInfo.lock_file_exists ? (
                            <span style={{ color: '#dc3545' }}>● Существует</span>
                          ) : (
                            <span style={{ color: '#198754' }}>● Не найден</span>
                          )}
                        </span>
                      </div>
                      {processInfo.process?.running ? (
                        <>
                          <div className="info-item">
                            <span className="info-label">Процесс:</span>
                            <span className="info-value" style={{ color: '#198754' }}>
                              ● Запущен
                              {processInfo.process.pid && ` (PID: ${processInfo.process.pid})`}
                            </span>
                          </div>
                          {processInfo.process.started_at && (
                            <div className="info-item">
                              <span className="info-label">Запущен:</span>
                              <span className="info-value">{processInfo.process.started_at}</span>
                            </div>
                          )}
                          {processInfo.process.current_stage && (
                            <div className="info-item" style={{ gridColumn: '1 / -1', marginTop: '0.5rem', paddingTop: '0.5rem', borderTop: '1px solid #e0e0e0' }}>
                              <span className="info-label">Текущий этап:</span>
                              <span className="info-value" style={{ fontWeight: 600, color: '#2563eb' }}>
                                {processInfo.process.current_stage}
                                {processInfo.process.stage_updated_at && (
                                  <span style={{ fontSize: '0.875rem', color: '#6c757d', marginLeft: '0.5rem', fontWeight: 'normal' }}>
                                    (обновлено {Math.floor((Date.now() / 1000 - processInfo.process.stage_updated_at) / 60)} мин. назад)
                                  </span>
                                )}
                              </span>
                            </div>
                          )}
                          {processInfo.process.progress_percent !== null && processInfo.process.progress_percent !== undefined && (
                            <div className="info-item" style={{ gridColumn: '1 / -1', marginTop: '1rem', paddingTop: '1rem', borderTop: '1px solid #e0e0e0' }}>
                              {/* Заголовок и процент на одной строке */}
                              <div style={{ marginBottom: '0.75rem' }}>
                                <span className="info-label" style={{ fontSize: '0.95rem', fontWeight: 600 }}>Прогресс миграции: </span>
                                <span className="info-value" style={{ fontWeight: 600, color: '#2563eb', fontSize: '0.95rem' }}>
                                  {processInfo.process.progress_percent}%
                                  {processInfo.process.total_pages && processInfo.process.processed_pages !== null && (
                                    <span style={{ fontSize: '0.875rem', color: '#6c757d', marginLeft: '0.5rem', fontWeight: 'normal' }}>
                                      ({processInfo.process.processed_pages} из {processInfo.process.total_pages} страниц)
                                    </span>
                                  )}
                                </span>
                              </div>
                              {/* Прогресс-бар в отдельной строке */}
                              <div style={{ 
                                width: '100%', 
                                height: '28px', 
                                backgroundColor: '#e5e7eb', 
                                borderRadius: '14px', 
                                overflow: 'hidden',
                                position: 'relative',
                                boxShadow: 'inset 0 1px 2px rgba(0, 0, 0, 0.1)',
                                marginBottom: '0.75rem'
                              }}>
                                <div style={{
                                  width: `${Math.min(processInfo.process.progress_percent, 100)}%`,
                                  height: '100%',
                                  backgroundColor: processInfo.process.progress_percent >= 100 ? '#10b981' : '#2563eb',
                                  transition: 'width 0.5s ease, background-color 0.3s ease',
                                  display: 'flex',
                                  alignItems: 'center',
                                  justifyContent: 'center',
                                  color: '#fff',
                                  fontSize: '0.8rem',
                                  fontWeight: 600,
                                  boxShadow: processInfo.process.progress_percent >= 100 ? '0 2px 4px rgba(16, 185, 129, 0.3)' : '0 2px 4px rgba(37, 99, 235, 0.3)'
                                }}>
                                  {processInfo.process.progress_percent >= 8 && `${Math.round(processInfo.process.progress_percent)}%`}
                                </div>
                              </div>
                              {/* Информация об оставшихся страницах */}
                              {processInfo.process.total_pages && processInfo.process.processed_pages !== null && (
                                <div style={{ fontSize: '0.875rem', color: '#6c757d', textAlign: 'center' }}>
                                  Осталось страниц: <strong style={{ color: '#374151' }}>{processInfo.process.total_pages - processInfo.process.processed_pages}</strong>
                                </div>
                              )}
                            </div>
                          )}
                          {processInfo.process.lock_file_age !== undefined && (
                            <div className="info-item">
                              <span className="info-label">Возраст lock-файла:</span>
                              <span className="info-value">
                                {Math.floor(processInfo.process.lock_file_age / 60)} мин. {processInfo.process.lock_file_age % 60} сек.
                              </span>
                            </div>
                          )}
                          {processInfo.process_details && (
                            <>
                              <div className="info-item">
                                <span className="info-label">Пользователь:</span>
                                <span className="info-value">{processInfo.process_details.user}</span>
                              </div>
                              <div className="info-item">
                                <span className="info-label">Время работы:</span>
                                <span className="info-value">{processInfo.process_details.time}</span>
                              </div>
                              <div className="info-item">
                                <span className="info-label">Запущен:</span>
                                <span className="info-value">{processInfo.process_details.start}</span>
                              </div>
                            </>
                          )}
                        </>
                      ) : (
                        <div className="info-item">
                          <span className="info-label">Процесс:</span>
                          <span className="info-value" style={{ color: '#6c757d' }}>
                            ● Не запущен
                          </span>
                        </div>
                      )}
                    </div>
                  </div>
              ) : (
                <div style={{ padding: '1rem', textAlign: 'center', color: '#6c757d' }}>
                  Информация о процессе недоступна. Нажмите "Обновить информацию" для загрузки.
                </div>
              )}

              <div className="process-actions" style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap', marginTop: '1rem' }}>
                  {processInfo?.process?.running && processInfo?.process?.pid && (
                    <>
                      <button
                        onClick={() => handleKillProcess(false)}
                        className="btn btn-warning"
                        disabled={killingProcess}
                        title="Отправить сигнал SIGTERM для корректного завершения процесса"
                      >
                        {killingProcess ? 'Завершение...' : 'Завершить процесс'}
                      </button>
                      <button
                        onClick={() => handleKillProcess(true)}
                        className="btn btn-danger"
                        disabled={killingProcess}
                        title="Принудительно завершить процесс (SIGKILL)"
                      >
                        {killingProcess ? 'Завершение...' : 'Принудительно завершить'}
                      </button>
                    </>
                  )}
                  {processInfo?.process?.running && !processInfo?.process?.pid && (
                    <div className="alert alert-info" style={{ padding: '0.75rem', fontSize: '0.875rem' }}>
                      ⚠️ PID процесса недоступен. Для завершения процесса используйте кнопку "Сбросить статус" или удалите lock-файл.
                    </div>
                  )}
                  {(processInfo?.lock_file_exists || details.status === 'in_progress') && (
                    <button
                      onClick={handleRemoveLock}
                      className="btn btn-secondary"
                      disabled={removingLock}
                      title="Удалить lock-файл, чтобы разрешить повторный запуск миграции"
                    >
                      {removingLock ? 'Удаление...' : 'Удалить lock-файл'}
                    </button>
                  )}
                  <button
                    onClick={() => loadProcessInfo(false)}
                    className="btn btn-secondary"
                    disabled={refreshingProcessInfo || loadingProcessInfo}
                    title="Обновить информацию о процессе"
                  >
                    {refreshingProcessInfo ? (
                      <>
                        <span className="inline-spinner" style={{ width: '12px', height: '12px', borderWidth: '2px' }} />
                        Обновление...
                      </>
                    ) : (
                      'Обновить информацию'
                    )}
                  </button>
                </div>
            </div>

          {/* Модальное окно для формы перезапуска */}
          {showRestartForm && (
            <div className="page-analysis-modal" onClick={() => setShowRestartForm(false)}>
              <div className="modal-content" onClick={(e) => e.stopPropagation()} style={{ maxWidth: '600px' }}>
                <div className="modal-header">
                  <h2>Параметры перезапуска миграции</h2>
                  <button onClick={() => setShowRestartForm(false)} className="btn-close">×</button>
                </div>
                <div className="modal-body">
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
                    value={restartParams.mb_site_id}
                    onChange={(e) => setRestartParams({ ...restartParams, mb_site_id: e.target.value })}
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
                    value={restartParams.mb_secret}
                    onChange={(e) => setRestartParams({ ...restartParams, mb_secret: e.target.value })}
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
                    value={restartParams.brz_workspaces_id}
                    onChange={(e) => setRestartParams({ ...restartParams, brz_workspaces_id: e.target.value })}
                    placeholder="22925473"
                  />
                </div>
                <div className="form-group">
                  <label className="form-label">MB Page Slug</label>
                  <input
                    type="text"
                    className="form-input"
                    value={restartParams.mb_page_slug}
                    onChange={(e) => setRestartParams({ ...restartParams, mb_page_slug: e.target.value })}
                    placeholder="Оставьте пустым для миграции всех страниц"
                  />
                  <div className="form-help">
                    Если указан, будет мигрирована только эта страница
                  </div>
                </div>
                <div className="form-group">
                  <label className="form-label">
                    <input
                      type="checkbox"
                      checked={restartParams.mgr_manual === '1'}
                      onChange={(e) => setRestartParams({ ...restartParams, mgr_manual: e.target.checked ? '1' : '0' })}
                    />
                    <span style={{ marginLeft: '0.5rem' }}>Ручной режим</span>
                  </label>
                  <div className="form-help">
                    В ручном режиме миграция выполняется синхронно через веб-сервер
                  </div>
                </div>
                <div className="form-group">
                  <label className="form-label">
                    <input
                      type="checkbox"
                      checked={restartParams.quality_analysis}
                      onChange={(e) => setRestartParams({ ...restartParams, quality_analysis: e.target.checked })}
                    />
                    <span style={{ marginLeft: '0.5rem' }}>Анализ качества</span>
                  </label>
                  <div className="form-help">
                    Включить анализ качества миграции страниц
                  </div>
                </div>
                  <div className="form-actions" style={{ display: 'flex', gap: '0.5rem', justifyContent: 'flex-end', marginTop: '1.5rem', paddingTop: '1.5rem', borderTop: '1px solid var(--border)' }}>
                    <button
                      onClick={() => setShowRestartForm(false)}
                      className="btn btn-secondary"
                    >
                      Отменить
                    </button>
                    <button
                      onClick={handleRestart}
                      className="btn btn-primary"
                      disabled={restarting || details.status === 'in_progress'}
                    >
                      {restarting ? 'Перезапуск...' : 'Перезапустить'}
                    </button>
                  </div>
                </div>
              </div>
            </div>
          )}
          </div>
        </div>
      )}

      {activeTab === 'pages' && (
        <div className="pages-tab">
          <div style={{ marginBottom: '1rem' }}>
            <button 
              onClick={loadPagesList}
              disabled={loadingPages}
              className="btn btn-secondary"
              style={{ marginRight: '0.5rem' }}
            >
              {loadingPages ? 'Загрузка...' : 'Обновить список'}
            </button>
          </div>
          
          {loadingPages ? (
            <div className="loading">Загрузка списка страниц...</div>
          ) : pagesList.length === 0 ? (
            <div className="no-data">Страницы не найдены</div>
          ) : (
            <div className="pages-table-container">
              <table className="pages-table">
                <thead>
                  <tr>
                    <th>Slug страницы</th>
                    <th>Статус миграции</th>
                    <th>Статус анализа</th>
                    <th>Оценка качества</th>
                    <th>Уровень критичности</th>
                    <th>Дата создания</th>
                    <th>Действия</th>
                  </tr>
                </thead>
                <tbody>
                  {pagesList.map((page) => {
                    // Определяем статус миграции для страницы
                    let migrationStatus = pageMigrationStatus[page.page_slug] || null;
                    
                    // Если статус не установлен локально, проверяем processInfo
                    if (!migrationStatus && processInfo?.process?.running) {
                      // Безопасно получаем changesJson
                      const currentChangesJson = safeParseChangesJson(details?.mapping?.changes_json);
                      
                      const currentPageSlug = processInfo.process.current_page_slug || 
                                             (details?.result as any)?.mb_page_slug ||
                                             currentChangesJson?.mb_page_slug ||
                                             (migrationValue as any)?.mb_page_slug;
                      if (currentPageSlug === page.page_slug) {
                        migrationStatus = 'in_progress';
                      }
                    }
                    
                    return (
                    <tr key={page.page_slug}>
                      <td>
                        <code>{page.page_slug}</code>
                      </td>
                      <td>
                        {migrationStatus === 'in_progress' ? (
                          <span className="status-badge status-in_progress" style={{ backgroundColor: '#2563eb', color: '#fff' }}>
                            Миграция в процессе
                          </span>
                        ) : migrationStatus === 'completed' ? (
                          <span className="status-badge status-completed" style={{ backgroundColor: '#10b981', color: '#fff' }}>
                            Завершено
                          </span>
                        ) : migrationStatus === 'error' ? (
                          <span className="status-badge status-error" style={{ backgroundColor: '#dc3545', color: '#fff' }}>
                            Ошибка
                          </span>
                        ) : (
                          <span className="status-badge" style={{ backgroundColor: '#e5e7eb', color: '#6b7280' }}>
                            —
                          </span>
                        )}
                      </td>
                      <td>
                        <span className={`status-badge status-${page.analysis_status || 'pending'}`}>
                          {page.analysis_status || 'pending'}
                        </span>
                      </td>
                      <td>
                        {page.quality_score !== null ? (
                          <span className="quality-score">{page.quality_score}/100</span>
                        ) : (
                          <span className="no-score">—</span>
                        )}
                      </td>
                      <td>
                        <span className={`severity-badge severity-${page.severity_level || 'none'}`}>
                          {page.severity_level || 'none'}
                        </span>
                      </td>
                      <td>
                        {page.created_at ? formatDate(page.created_at) : '—'}
                      </td>
                      <td>
                        <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
                          {page.collection_items_id && page.brz_project_id && (
                            <a
                              href={`https://admin.brizy.io/projects/${page.brz_project_id}/editor/page/${page.collection_items_id}`}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="btn btn-sm btn-primary"
                              style={{ textDecoration: 'none' }}
                              title="Открыть страницу в редакторе Brizy"
                            >
                              Редактировать
                            </a>
                          )}
                          <button
                            onClick={async () => {
                              if (!confirm(`Пересобрать страницу "${page.page_slug}" без анализа?`)) {
                                return;
                              }
                              try {
                                setRebuildingPages(prev => ({ ...prev, [page.page_slug]: true }));
                                setPageMigrationStatus(prev => ({ ...prev, [page.page_slug]: 'in_progress' }));
                                const response = await api.rebuildPageNoAnalysis(parseInt(id!), page.page_slug);
                                if (response.success) {
                                  // Обновляем информацию о процессе и детали миграции
                                  await refreshDetails();
                                  await loadProcessInfo(false);
                                  // Не показываем alert, так как статус виден в таблице
                                  setTimeout(() => {
                                    loadPagesList();
                                  }, 2000);
                                } else {
                                  setPageMigrationStatus(prev => ({ ...prev, [page.page_slug]: 'error' }));
                                  alert('Ошибка: ' + (response.error || 'Неизвестная ошибка'));
                                }
                              } catch (err: any) {
                                setPageMigrationStatus(prev => ({ ...prev, [page.page_slug]: 'error' }));
                                alert('Ошибка: ' + (err.message || 'Не удалось запустить пересборку'));
                              } finally {
                                setRebuildingPages(prev => ({ ...prev, [page.page_slug]: false }));
                              }
                            }}
                            disabled={rebuildingPages[page.page_slug] || pageMigrationStatus[page.page_slug] === 'in_progress'}
                            className="btn btn-sm btn-secondary"
                            title="Пересобрать без анализа"
                          >
                            {rebuildingPages[page.page_slug] ? '...' : 'Пересобрать'}
                          </button>
                          <button
                            onClick={async () => {
                              if (!confirm(`Пересобрать страницу "${page.page_slug}" с анализом?`)) {
                                return;
                              }
                              try {
                                setRebuildingPages(prev => ({ ...prev, [page.page_slug + '_with_analysis']: true }));
                                setPageMigrationStatus(prev => ({ ...prev, [page.page_slug]: 'in_progress' }));
                                const response = await api.rebuildPage(parseInt(id!), page.page_slug);
                                if (response.success) {
                                  // Обновляем информацию о процессе и детали миграции
                                  await refreshDetails();
                                  await loadProcessInfo(false);
                                  // Не показываем alert, так как статус виден в таблице
                                  setTimeout(() => {
                                    loadPagesList();
                                  }, 2000);
                                } else {
                                  setPageMigrationStatus(prev => ({ ...prev, [page.page_slug]: 'error' }));
                                  alert('Ошибка: ' + (response.error || 'Неизвестная ошибка'));
                                }
                              } catch (err: any) {
                                setPageMigrationStatus(prev => ({ ...prev, [page.page_slug]: 'error' }));
                                alert('Ошибка: ' + (err.message || 'Не удалось запустить пересборку'));
                              } finally {
                                setRebuildingPages(prev => ({ ...prev, [page.page_slug + '_with_analysis']: false }));
                              }
                            }}
                            disabled={rebuildingPages[page.page_slug + '_with_analysis'] || pageMigrationStatus[page.page_slug] === 'in_progress'}
                            className="btn btn-sm btn-primary"
                            title="Пересобрать с анализом"
                          >
                            {rebuildingPages[page.page_slug + '_with_analysis'] ? '...' : 'С анализом'}
                          </button>
                        </div>
                      </td>
                    </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {activeTab === 'details' && (
        <div className="details-grid">
        <div className="card">
          <div className="card-header">
            <h3 className="card-title">Информация о маппинге</h3>
          </div>
          <div className="info-grid">
            <div className="info-item">
              <span className="info-label">Brizy Project ID:</span>
              <span className="info-value">{details.mapping.brz_project_id}</span>
            </div>
            <div className="info-item">
              <span className="info-label">MB Project UUID:</span>
              <span className="info-value uuid">{formatUUID(details.mapping.mb_project_uuid)}</span>
            </div>
            <div className="info-item">
              <span className="info-label">Создано:</span>
              <span className="info-value">{formatDate(details.mapping.created_at)}</span>
            </div>
            <div className="info-item">
              <span className="info-label">Обновлено:</span>
              <span className="info-value">{formatDate(details.mapping.updated_at)}</span>
            </div>
          </div>
          {details.mapping.changes_json && (
            <div className="json-section">
              <h4>Changes JSON:</h4>
              <div className="json-viewer">
                <pre>
                  {typeof changesJson === 'object' && changesJson !== null
                    ? JSON.stringify(changesJson, null, 2)
                    : typeof details.mapping.changes_json === 'string'
                    ? details.mapping.changes_json.substring(0, 500) + (details.mapping.changes_json.length > 500 ? '... (truncated)' : '')
                    : 'Invalid JSON data'}
                </pre>
              </div>
            </div>
          )}
        </div>

        {(details.result || migrationValue || changesJson) && (
          <div className="card">
            <div className="card-header">
              <h3 className="card-title">Результат миграции</h3>
            </div>
            <div className="info-grid">
              {details.result?.migration_uuid && (
                <div className="info-item">
                  <span className="info-label">Migration UUID:</span>
                  <span className="info-value uuid">{formatUUID(details.result.migration_uuid)}</span>
                </div>
              )}
              {(migrationValue?.brizy_project_domain || (details as any).brizy_project_domain || changesJson?.brizy_project_domain) && (
                <div className="info-item">
                  <span className="info-label">Brizy Project Domain:</span>
                  <span className="info-value">
                    <a 
                      href={migrationValue?.brizy_project_domain || (details as any).brizy_project_domain || changesJson?.brizy_project_domain} 
                      target="_blank" 
                      rel="noopener noreferrer"
                    >
                      {migrationValue?.brizy_project_domain || (details as any).brizy_project_domain || changesJson?.brizy_project_domain}
                    </a>
                  </span>
                </div>
              )}
              {(migrationValue?.mb_project_domain || (details as any).mb_project_domain || changesJson?.mb_project_domain) && (
                <div className="info-item">
                  <span className="info-label">MB Project Domain:</span>
                  <span className="info-value">
                    {migrationValue?.mb_project_domain || (details as any).mb_project_domain || changesJson?.mb_project_domain}
                  </span>
                </div>
              )}
              {migrationValue?.migration_id && (
                <div className="info-item">
                  <span className="info-label">Migration ID:</span>
                  <span className="info-value uuid">{migrationValue.migration_id}</span>
                </div>
              )}
              {migrationValue?.date && (
                <div className="info-item">
                  <span className="info-label">Дата миграции:</span>
                  <span className="info-value">{migrationValue.date}</span>
                </div>
              )}
              {migrationValue?.theme && (
                <div className="info-item">
                  <span className="info-label">Тема:</span>
                  <span className="info-value">{migrationValue.theme}</span>
                </div>
              )}
              {migrationValue?.mb_product_name && (
                <div className="info-item">
                  <span className="info-label">MB Product Name:</span>
                  <span className="info-value">{migrationValue.mb_product_name}</span>
                </div>
              )}
              {migrationValue?.mb_site_id && (
                <div className="info-item">
                  <span className="info-label">MB Site ID:</span>
                  <span className="info-value">{migrationValue.mb_site_id}</span>
                </div>
              )}
              {migrationValue?.progress && (
                <div className="info-item">
                  <span className="info-label">Прогресс:</span>
                  <span className="info-value">
                    {migrationValue.progress.Success || 0} / {migrationValue.progress.Total || 0}
                    {migrationValue.progress.processTime && (
                      <span style={{ fontSize: '0.875rem', color: '#666', marginLeft: '0.5rem' }}>
                        ({migrationValue.progress.processTime.toFixed(1)}s)
                      </span>
                    )}
                  </span>
                </div>
              )}
              {migrationValue?.DEV_MODE !== undefined && (
                <div className="info-item">
                  <span className="info-label">DEV Mode:</span>
                  <span className="info-value">{migrationValue.DEV_MODE ? 'Да' : 'Нет'}</span>
                </div>
              )}
              {changesJson?.completed_at && (
                <div className="info-item">
                  <span className="info-label">Завершено:</span>
                  <span className="info-value">{formatDate(changesJson.completed_at)}</span>
                </div>
              )}
            </div>
            {resultData && (
              <div className="json-section">
                <h4>Полный JSON ответа:</h4>
                <div className="json-viewer">
                  <pre>{JSON.stringify(resultData, null, 2)}</pre>
                </div>
              </div>
            )}
          </div>
        )}

        {/* Карточка с ответом миграции при завершении */}
        {(details.result?.result_json || resultData) && (details.result?.result_json?.value || resultData?.value) && (
          <div className="card" style={{ marginTop: '1.5rem' }}>
            <div className="card-header">
              <h3 className="card-title">Ответ миграции при завершении</h3>
            </div>
            <div className="card-body">
              <div className="json-section">
                <div className="json-viewer" style={{ 
                  backgroundColor: '#f8f9fa', 
                  border: '1px solid #dee2e6', 
                  borderRadius: '4px', 
                  padding: '1rem',
                  maxHeight: '600px',
                  overflow: 'auto'
                }}>
                  <pre style={{ 
                    margin: 0, 
                    whiteSpace: 'pre-wrap', 
                    wordBreak: 'break-word',
                    fontFamily: 'Monaco, Menlo, "Ubuntu Mono", Consolas, "source-code-pro", monospace',
                    fontSize: '0.875rem',
                    lineHeight: '1.5'
                  }}>
                    {JSON.stringify(details.result?.result_json || resultData, null, 2)}
                  </pre>
                </div>
              </div>
              {((details.result?.result_json?.value?.status === 'success') || (resultData?.value?.status === 'success')) && (
                <div className="alert alert-success" style={{ 
                  marginTop: '1rem', 
                  padding: '0.75rem', 
                  borderRadius: '4px', 
                  backgroundColor: '#d4edda', 
                  border: '1px solid #c3e6cb', 
                  color: '#155724' 
                }}>
                  ✅ Миграция завершена успешно (status: success)
                </div>
              )}
            </div>
          </div>
        )}
      </div>
      )}

      {activeTab === 'analysis' && (
        <QualityAnalysis />
      )}

      {activeTab === 'archive' && (
        <QualityAnalysisArchive migrationId={parseInt(id || '0')} />
      )}

      {activeTab === 'warnings' && (
        <div className="warnings-tab">
          <div className="card">
            <div className="card-header">
              <h3 className="card-title">Ошибки и предупреждения</h3>
            </div>
            <div className="card-body">
              {/* Статус ошибки */}
              {details.status === 'error' && (
                <div className="error-section">
                  <h4 className="section-title error-title">
                    <span className="icon">⚠️</span>
                    Статус миграции: Ошибка
                  </h4>
                  <div className="error-item">
                    <p>Миграция завершилась с ошибкой. Проверьте детали ниже.</p>
                  </div>
                </div>
              )}

              {/* Предупреждения из message.warning */}
              {migrationValue?.message?.warning && migrationValue.message.warning.length > 0 && (
                <div className="warnings-section">
                  <h4 className="section-title warning-title">
                    <span className="icon">⚠️</span>
                    Предупреждения ({migrationValue.message.warning.length})
                  </h4>
                  <div className="warnings-list">
                    {migrationValue.message.warning.map((warning: string, index: number) => (
                      <div key={index} className="warning-item">
                        <span className="warning-number">{index + 1}.</span>
                        <span className="warning-text">{warning}</span>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* Предупреждения из details.warnings */}
              {details.warnings && details.warnings.length > 0 && (
                <div className="warnings-section">
                  <h4 className="section-title warning-title">
                    <span className="icon">⚠️</span>
                    Предупреждения из API ({details.warnings.length})
                  </h4>
                  <div className="warnings-list">
                    {details.warnings.map((warning: string, index: number) => (
                      <div key={index} className="warning-item">
                        <span className="warning-number">{index + 1}.</span>
                        <span className="warning-text">{warning}</span>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* Ошибки из result_json или других источников */}
              {resultData?.error && (
                <div className="error-section">
                  <h4 className="section-title error-title">
                    <span className="icon">❌</span>
                    Ошибка выполнения
                  </h4>
                  <div className="error-item">
                    <pre className="error-details">{typeof resultData.error === 'string' ? resultData.error : JSON.stringify(resultData.error, null, 2)}</pre>
                  </div>
                </div>
              )}

              {/* Если нет ошибок и предупреждений */}
              {details.status !== 'error' &&
               (!migrationValue?.message?.warning || migrationValue.message.warning.length === 0) &&
               (!details.warnings || details.warnings.length === 0) &&
               !resultData?.error && (
                <div className="no-warnings">
                  <p className="no-warnings-message">
                    <span className="icon">✅</span>
                    Ошибок и предупреждений не обнаружено
                  </p>
                </div>
              )}
            </div>
          </div>
        </div>
      )}

      {activeTab === 'statistics' && (
        <div className="statistics-tab">
          {qualityStatistics ? (
            <div className="details-grid">
              {/* Общая статистика */}
              <div className="card">
                <div className="card-header">
                  <h3 className="card-title">📊 Общая статистика анализа</h3>
                </div>
                <div className="info-grid">
                  <div className="info-item">
                    <span className="info-label">Всего страниц проанализировано:</span>
                    <span className="info-value" style={{ color: '#2563eb', fontWeight: 'bold', fontSize: '1.2em' }}>
                      {qualityStatistics.total_pages}
                    </span>
                  </div>
                  <div className="info-item">
                    <span className="info-label">Средний рейтинг качества:</span>
                    <span className="info-value" style={{ 
                      color: qualityStatistics.avg_quality_score !== null 
                        ? (qualityStatistics.avg_quality_score >= 90 ? '#198754' 
                          : qualityStatistics.avg_quality_score >= 70 ? '#ffc107' 
                          : qualityStatistics.avg_quality_score >= 50 ? '#fd7e14' 
                          : '#dc3545')
                        : '#6c757d',
                      fontWeight: 'bold',
                      fontSize: '1.2em'
                    }}>
                      {qualityStatistics.avg_quality_score !== null 
                        ? qualityStatistics.avg_quality_score.toFixed(1) 
                        : 'N/A'}
                    </span>
                  </div>
                </div>
              </div>

              {/* Статистика по уровням серьезности */}
              <div className="card">
                <div className="card-header">
                  <h3 className="card-title">⚠️ Распределение по уровням серьезности</h3>
                </div>
                <div className="info-grid">
                  <div className="info-item">
                    <span className="info-label" style={{ color: '#dc3545' }}>Критичные:</span>
                    <span className="info-value" style={{ color: '#dc3545', fontWeight: 'bold' }}>
                      {qualityStatistics.by_severity.critical}
                    </span>
                  </div>
                  <div className="info-item">
                    <span className="info-label" style={{ color: '#fd7e14' }}>Высокие:</span>
                    <span className="info-value" style={{ color: '#fd7e14', fontWeight: 'bold' }}>
                      {qualityStatistics.by_severity.high}
                    </span>
                  </div>
                  <div className="info-item">
                    <span className="info-label" style={{ color: '#ffc107' }}>Средние:</span>
                    <span className="info-value" style={{ color: '#ffc107', fontWeight: 'bold' }}>
                      {qualityStatistics.by_severity.medium}
                    </span>
                  </div>
                  <div className="info-item">
                    <span className="info-label" style={{ color: '#0dcaf0' }}>Низкие:</span>
                    <span className="info-value" style={{ color: '#0dcaf0', fontWeight: 'bold' }}>
                      {qualityStatistics.by_severity.low}
                    </span>
                  </div>
                  <div className="info-item">
                    <span className="info-label" style={{ color: '#198754' }}>Без проблем:</span>
                    <span className="info-value" style={{ color: '#198754', fontWeight: 'bold' }}>
                      {qualityStatistics.by_severity.none}
                    </span>
                  </div>
                </div>
              </div>

              {/* Статистика по токенам и стоимости */}
              {qualityStatistics.token_statistics && (
                <div className="card highlight-card">
                  <div className="card-header">
                    <h3 className="card-title">💰 Статистика использования токенов</h3>
                  </div>
                  <div className="info-grid">
                    <div className="info-item">
                      <span className="info-label">Общая стоимость анализа:</span>
                      <span className="info-value" style={{ color: '#198754', fontWeight: 'bold', fontSize: '1.2em' }}>
                        ${qualityStatistics.token_statistics.total_cost_usd.toFixed(6)}
                      </span>
                    </div>
                    <div className="info-item">
                      <span className="info-label">Средняя стоимость на страницу:</span>
                      <span className="info-value">
                        ${qualityStatistics.token_statistics.avg_cost_per_page_usd.toFixed(6)}
                      </span>
                    </div>
                    <div className="info-item">
                      <span className="info-label">Всего токенов использовано:</span>
                      <span className="info-value" style={{ color: '#2563eb', fontWeight: 'bold' }}>
                        {qualityStatistics.token_statistics.total_tokens.toLocaleString()}
                      </span>
                    </div>
                    <div className="info-item">
                      <span className="info-label">Входные токены (prompt):</span>
                      <span className="info-value">
                        {qualityStatistics.token_statistics.total_prompt_tokens.toLocaleString()}
                      </span>
                    </div>
                    <div className="info-item">
                      <span className="info-label">Выходные токены (completion):</span>
                      <span className="info-value">
                        {qualityStatistics.token_statistics.total_completion_tokens.toLocaleString()}
                      </span>
                    </div>
                    <div className="info-item">
                      <span className="info-label">Среднее токенов на страницу:</span>
                      <span className="info-value">
                        {qualityStatistics.token_statistics.avg_tokens_per_page.toLocaleString()}
                      </span>
                    </div>
                  </div>
                </div>
              )}
            </div>
          ) : (
            <div className="card">
              <div className="card-body">
                <div className="no-statistics">
                  <p className="no-statistics-message">
                    <span className="icon">ℹ️</span>
                    Статистика анализа качества недоступна
                  </p>
                  <p className="no-statistics-hint">
                    Для получения статистики необходимо запустить миграцию с параметром <code>quality_analysis=true</code>
                  </p>
                </div>
              </div>
            </div>
          )}
        </div>
      )}

      {/* Модальное окно для логов миграции */}
      {showLogs && (
        <div className="page-analysis-modal" onClick={() => {
          setShowLogs(false);
          setLogs(null);
        }}>
          <div className="modal-content" onClick={(e) => e.stopPropagation()} style={{ maxWidth: '90vw', maxHeight: '90vh' }}>
            <div className="modal-header">
              <h2>
                Логи миграции #{details.mapping.brz_project_id}
                {details.status === 'in_progress' && (
                  <span className="auto-refresh-badge" style={{ marginLeft: '1rem', fontSize: '0.875rem', fontWeight: 'normal' }}>🔄 Автообновление</span>
                )}
              </h2>
              <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
                <button
                  onClick={() => loadMigrationLogs()}
                  className="btn btn-sm btn-secondary"
                  title="Обновить логи"
                  disabled={loadingLogs}
                >
                  {loadingLogs ? '...' : '↻'}
                </button>
                <button
                  onClick={() => {
                    setShowLogs(false);
                    setLogs(null);
                  }}
                  className="btn-close"
                  title="Закрыть"
                >
                  ×
                </button>
              </div>
            </div>
            <div className="modal-body" style={{ padding: 0 }}>
              {loadingLogs && !logs ? (
                <div className="loading-container" style={{ padding: '3rem' }}>
                  <div className="spinner"></div>
                  <p>Загрузка логов...</p>
                </div>
              ) : (
                <div 
                  ref={logsContentRef}
                  className="logs-content" 
                  style={{ padding: '1.5rem', maxHeight: 'calc(90vh - 100px)', overflowY: 'auto' }}
                >
                  {logs ? (
                    <div className="logs-text">
                      {logs
                        .split('\n')
                        .filter((line: string) => line && line.trim())
                        .reverse()
                        .map((line: string, index: number) => {
                          let lineClass = 'log-line';
                          const trimmedLine = line.trim();
                          const lowerLine = trimmedLine.toLowerCase();
                          
                          if (/\.[CRITICAL|ERROR|FATAL]:/i.test(trimmedLine) ||
                              lowerLine.includes('.critical:') ||
                              lowerLine.includes('.error:') ||
                              lowerLine.includes('.fatal:')) {
                            lineClass += ' log-error';
                          } else if (/\.[WARNING|WARN]:/i.test(trimmedLine) ||
                                     lowerLine.includes('.warning:') ||
                                     lowerLine.includes('.warn:')) {
                            lineClass += ' log-warning';
                          } else if (/\.[INFO|SUCCESS]:/i.test(trimmedLine) ||
                                     lowerLine.includes('.info:') ||
                                     lowerLine.includes('.success:') ||
                                     lowerLine.includes('completed') ||
                                     lowerLine.includes('done')) {
                            lineClass += ' log-info';
                          } else if (/\.[DEBUG|TRACE]:/i.test(trimmedLine) ||
                                     lowerLine.includes('.debug:') ||
                                     lowerLine.includes('.trace:')) {
                            lineClass += ' log-debug';
                          } else if (lowerLine.includes('error') || 
                                     lowerLine.includes('exception') || 
                                     lowerLine.includes('failed') ||
                                     lowerLine.includes('critical')) {
                            lineClass += ' log-error';
                          } else if (lowerLine.includes('warning') || 
                                     lowerLine.includes('warn') ||
                                     lowerLine.includes('deprecated')) {
                            lineClass += ' log-warning';
                          }
                          
                          return (
                            <div key={`log-${index}`} className={lineClass}>
                              <span className="log-line-content">{line || '\u00A0'}</span>
                            </div>
                          );
                        })}
                    </div>
                  ) : (
                    <div className="logs-empty">
                      <p>Логи не найдены</p>
                    </div>
                  )}
                </div>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

// Компонент для отображения архивных результатов анализа
function QualityAnalysisArchive({ migrationId }: { migrationId: number }) {
  const [reports, setReports] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [selectedPage, setSelectedPage] = useState<string | null>(null);

  useEffect(() => {
    loadArchivedReports();
  }, [migrationId]);

  const loadArchivedReports = async () => {
    try {
      setLoading(true);
      setError(null);
      const response = await api.getArchivedQualityAnalysis(migrationId);
      if (response.success && response.data && Array.isArray(response.data)) {
        setReports(response.data);
      } else {
        setReports([]);
        if (response.error && !response.error.includes('не найден')) {
          setError(response.error);
        }
      }
    } catch (err: any) {
      console.error('Error loading archived reports:', err);
      setError(err.message || 'Ошибка загрузки архивных отчетов');
      setReports([]);
    } finally {
      setLoading(false);
    }
  };

  const getQualityScoreColor = (score?: number) => {
    if (!score) return '#6c757d';
    if (score >= 90) return '#198754';
    if (score >= 70) return '#ffc107';
    if (score >= 50) return '#fd7e14';
    return '#dc3545';
  };

  const formatCost = (cost?: number) => {
    if (cost === undefined || cost === null) return 'N/A';
    return `$${cost.toFixed(6)}`;
  };

  const formatTokens = (tokens?: number) => {
    if (tokens === undefined || tokens === null) return 'N/A';
    return tokens.toLocaleString();
  };

  if (loading) {
    return (
      <div className="loading-container">
        <div className="spinner"></div>
        <p>Загрузка архивных результатов...</p>
      </div>
    );
  }

  if (error && reports.length === 0) {
    return (
      <div className="error-container">
        <p className="error-message">❌ {error}</p>
        <button onClick={loadArchivedReports} className="btn btn-primary">
          Попробовать снова
        </button>
      </div>
    );
  }

  if (reports.length === 0) {
    return (
      <div className="quality-analysis-empty">
        <p>Архивных результатов анализа нет.</p>
        <p className="text-muted">Архивные результаты появляются после перезапуска миграции с анализом качества.</p>
      </div>
    );
  }

  return (
    <div className="quality-analysis">
      <div className="archive-header">
        <h3>📦 Архивные результаты анализа</h3>
        <p className="text-muted">Эти результаты были помечены как устаревшие после перезапуска миграции с анализом качества.</p>
      </div>

      <div className="quality-pages-list">
        <div className="pages-grid">
          {reports.map((report) => (
            <div
              key={report.id}
              className={`page-card archived-page ${selectedPage === report.page_slug ? 'selected' : ''}`}
              onClick={() => setSelectedPage(report.page_slug)}
            >
              <div className="page-card-header">
                <h4>{report.page_slug || 'Без названия'}</h4>
                <span className="archived-badge">Архив</span>
              </div>
              <div className="page-card-body">
                {report.collection_items_id && report.brz_project_id && (
                  <div style={{ marginBottom: '0.75rem' }}>
                    <a
                      href={`https://admin.brizy.io/projects/${report.brz_project_id}/editor/page/${report.collection_items_id}`}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="btn btn-sm btn-primary"
                      style={{ textDecoration: 'none', display: 'inline-block' }}
                      onClick={(e) => e.stopPropagation()}
                      title="Открыть страницу в редакторе Brizy"
                    >
                      Редактировать
                    </a>
                  </div>
                )}
                {report.quality_score !== null && report.quality_score !== undefined && (
                  <div className="quality-score">
                    <span className="score-label">Рейтинг:</span>
                    <span
                      className="score-value"
                      style={{ color: getQualityScoreColor(typeof report.quality_score === 'string' ? parseInt(report.quality_score) : report.quality_score) }}
                    >
                      {typeof report.quality_score === 'string' ? parseInt(report.quality_score) : report.quality_score}
                    </span>
                  </div>
                )}
                {report.token_usage && (
                  <div className="page-tokens-info">
                    <div className="tokens-row">
                      <span className="tokens-label">Токены:</span>
                      <span className="tokens-value">
                        {formatTokens(report.token_usage.total_tokens)}
                      </span>
                    </div>
                    {report.token_usage.cost_estimate_usd !== undefined && report.token_usage.cost_estimate_usd !== null && (
                      <div className="tokens-row">
                        <span className="tokens-label">Стоимость:</span>
                        <span className="tokens-value cost-value" style={{ color: '#198754', fontWeight: 'bold' }}>
                          {formatCost(report.token_usage.cost_estimate_usd)}
                        </span>
                      </div>
                    )}
                  </div>
                )}
                <div className="page-meta">
                  <span className="meta-item">
                    {new Date(report.created_at).toLocaleDateString()}
                  </span>
                  <span className="meta-item archived-status">📦 Архивирован</span>
                </div>
              </div>
            </div>
          ))}
        </div>
      </div>

      {selectedPage && (
        <ArchivedPageAnalysisDetails
          migrationId={migrationId}
          pageSlug={selectedPage}
          onClose={() => setSelectedPage(null)}
        />
      )}
    </div>
  );
}

// Компонент для отображения деталей архивной страницы
function ArchivedPageAnalysisDetails({ migrationId, pageSlug, onClose }: { migrationId: number; pageSlug: string; onClose: () => void }) {
  const [report, setReport] = useState<any | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'overview' | 'screenshots' | 'issues'>('overview');

  useEffect(() => {
    loadPageAnalysis();
  }, [migrationId, pageSlug]);

  const loadPageAnalysis = async () => {
    try {
      setLoading(true);
      setError(null);
      const response = await api.getPageQualityAnalysis(migrationId, pageSlug, true); // includeArchived = true
      if (response.success && response.data) {
        setReport(response.data);
      } else {
        setError(response.error || 'Не удалось загрузить детали анализа');
      }
    } catch (err: any) {
      setError(err.message || 'Ошибка загрузки деталей');
    } finally {
      setLoading(false);
    }
  };

  const getSeverityColor = (severity: string) => {
    switch (severity) {
      case 'critical': return '#dc3545';
      case 'high': return '#fd7e14';
      case 'medium': return '#ffc107';
      case 'low': return '#0dcaf0';
      case 'none': return '#198754';
      default: return '#6c757d';
    }
  };

  const getQualityScoreColor = (score?: number) => {
    if (!score) return '#6c757d';
    if (score >= 90) return '#198754';
    if (score >= 70) return '#ffc107';
    if (score >= 50) return '#fd7e14';
    return '#dc3545';
  };

  if (loading) {
    return (
      <div className="page-analysis-modal">
        <div className="modal-content">
          <div className="loading-container">
            <div className="spinner"></div>
            <p>Загрузка деталей анализа...</p>
          </div>
        </div>
      </div>
    );
  }

  if (error || !report) {
    return (
      <div className="page-analysis-modal">
        <div className="modal-content">
          <div className="error-container">
            <p className="error-message">❌ {error || 'Анализ не найден'}</p>
            <button onClick={onClose} className="btn btn-secondary">
              Закрыть
            </button>
          </div>
        </div>
      </div>
    );
  }

  const sourceScreenshot = report.screenshots_path?.source;
  const migratedScreenshot = report.screenshots_path?.migrated;
  const sourceFilename = sourceScreenshot ? sourceScreenshot.split('/').pop() : null;
  const migratedFilename = migratedScreenshot ? migratedScreenshot.split('/').pop() : null;

  return (
    <div className="page-analysis-modal" onClick={onClose}>
      <div className="modal-content" onClick={(e) => e.stopPropagation()}>
        <div className="modal-header">
          <h2>📦 Архив: Анализ страницы: {report.page_slug}</h2>
          <button onClick={onClose} className="btn-close">×</button>
        </div>

        <div className="modal-tabs">
          <button
            className={activeTab === 'overview' ? 'active' : ''}
            onClick={() => setActiveTab('overview')}
          >
            Обзор
          </button>
          <button
            className={activeTab === 'screenshots' ? 'active' : ''}
            onClick={() => setActiveTab('screenshots')}
          >
            Скриншоты
          </button>
          <button
            className={activeTab === 'issues' ? 'active' : ''}
            onClick={() => setActiveTab('issues')}
          >
            Проблемы
          </button>
        </div>

        <div className="modal-body">
          {activeTab === 'overview' && (
            <div className="overview-tab">
              <div className="info-grid">
                <div className="info-item highlight-item">
                  <span className="info-label">📦 Статус:</span>
                  <span className="info-value" style={{ color: '#6c757d' }}>Архивирован</span>
                </div>
                <div className="info-item">
                  <span className="info-label">Рейтинг качества:</span>
                  <span
                    className="info-value"
                    style={{ color: getQualityScoreColor(typeof report.quality_score === 'string' ? parseInt(report.quality_score) : report.quality_score) }}
                  >
                    {report.quality_score !== null && report.quality_score !== undefined 
                      ? (typeof report.quality_score === 'string' ? parseInt(report.quality_score) : report.quality_score)
                      : 'N/A'}
                  </span>
                </div>
                <div className="info-item">
                  <span className="info-label">Уровень критичности:</span>
                  <span
                    className="info-value"
                    style={{ color: getSeverityColor(report.severity_level) }}
                  >
                    {report.severity_level}
                  </span>
                </div>
                {report.token_usage && (
                  <>
                    <div className="info-item highlight-item">
                      <span className="info-label">💰 Стоимость анализа:</span>
                      <span className="info-value" style={{ color: '#198754', fontWeight: 'bold', fontSize: '1.2em' }}>
                        ${report.token_usage.cost_estimate_usd !== undefined && report.token_usage.cost_estimate_usd !== null
                          ? report.token_usage.cost_estimate_usd.toFixed(6)
                          : 'N/A'}
                      </span>
                    </div>
                    <div className="info-item">
                      <span className="info-label">Всего токенов:</span>
                      <span className="info-value">
                        {report.token_usage.total_tokens !== undefined && report.token_usage.total_tokens !== null
                          ? report.token_usage.total_tokens.toLocaleString()
                          : 'N/A'}
                      </span>
                    </div>
                    <div className="info-item">
                      <span className="info-label">Входные токены (prompt):</span>
                      <span className="info-value">
                        {report.token_usage.prompt_tokens !== undefined && report.token_usage.prompt_tokens !== null
                          ? report.token_usage.prompt_tokens.toLocaleString()
                          : 'N/A'}
                      </span>
                    </div>
                    <div className="info-item">
                      <span className="info-label">Выходные токены (completion):</span>
                      <span className="info-value">
                        {report.token_usage.completion_tokens !== undefined && report.token_usage.completion_tokens !== null
                          ? report.token_usage.completion_tokens.toLocaleString()
                          : 'N/A'}
                      </span>
                    </div>
                    {report.token_usage.model && (
                      <div className="info-item">
                        <span className="info-label">Модель AI:</span>
                        <span className="info-value">{report.token_usage.model}</span>
                      </div>
                    )}
                  </>
                )}
                {report.source_url && (
                  <div className="info-item">
                    <span className="info-label">Исходная страница:</span>
                    <span className="info-value">
                      <a href={report.source_url} target="_blank" rel="noopener noreferrer">
                        {report.source_url}
                      </a>
                    </span>
                  </div>
                )}
                {report.migrated_url && (
                  <div className="info-item">
                    <span className="info-label">Мигрированная страница:</span>
                    <span className="info-value">
                      <a href={report.migrated_url} target="_blank" rel="noopener noreferrer">
                        {report.migrated_url}
                      </a>
                    </span>
                  </div>
                )}
                <div className="info-item">
                  <span className="info-label">Дата анализа:</span>
                  <span className="info-value">
                    {new Date(report.created_at).toLocaleString()}
                  </span>
                </div>
              </div>

              {report.issues_summary?.summary && (
                <div className="summary-section">
                  <h3>Краткое описание</h3>
                  <p>{report.issues_summary.summary}</p>
                </div>
              )}
            </div>
          )}

          {activeTab === 'screenshots' && (
            <div className="screenshots-tab">
              <div className="screenshots-grid">
                {sourceScreenshot && sourceFilename && (
                  <div className="screenshot-item">
                    <h4>Исходная страница</h4>
                    <img
                      src={api.getScreenshotUrl(sourceFilename)}
                      alt="Source screenshot"
                      className="screenshot-image"
                      onError={(e) => {
                        (e.target as HTMLImageElement).src = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="400" height="300"%3E%3Ctext x="50%25" y="50%25" text-anchor="middle" dy=".3em"%3EСкриншот не найден%3C/text%3E%3C/svg%3E';
                      }}
                    />
                    <p className="screenshot-path">{sourceScreenshot}</p>
                  </div>
                )}
                {migratedScreenshot && migratedFilename && (
                  <div className="screenshot-item">
                    <h4>Мигрированная страница</h4>
                    <img
                      src={api.getScreenshotUrl(migratedFilename)}
                      alt="Migrated screenshot"
                      className="screenshot-image"
                      onError={(e) => {
                        (e.target as HTMLImageElement).src = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="400" height="300"%3E%3Ctext x="50%25" y="50%25" text-anchor="middle" dy=".3em"%3EСкриншот не найден%3C/text%3E%3C/svg%3E';
                      }}
                    />
                    <p className="screenshot-path">{migratedScreenshot}</p>
                  </div>
                )}
                {!sourceScreenshot && !migratedScreenshot && (
                  <div className="no-screenshots">
                    <p>Скриншоты недоступны</p>
                  </div>
                )}
              </div>
            </div>
          )}

          {activeTab === 'issues' && (
            <div className="issues-tab">
              {report.issues_summary?.missing_elements && report.issues_summary.missing_elements.length > 0 && (
                <div className="issues-section">
                  <h3>Отсутствующие элементы</h3>
                  <ul>
                    {report.issues_summary.missing_elements.map((item: string, index: number) => (
                      <li key={index}>{item}</li>
                    ))}
                  </ul>
                </div>
              )}

              {report.issues_summary?.changed_elements && report.issues_summary.changed_elements.length > 0 && (
                <div className="issues-section">
                  <h3>Измененные элементы</h3>
                  <ul>
                    {report.issues_summary.changed_elements.map((item: string, index: number) => (
                      <li key={index}>{item}</li>
                    ))}
                  </ul>
                </div>
              )}

              {report.issues_summary?.recommendations && report.issues_summary.recommendations.length > 0 && (
                <div className="issues-section">
                  <h3>Рекомендации</h3>
                  <ul>
                    {report.issues_summary.recommendations.map((item: string, index: number) => (
                      <li key={index}>{item}</li>
                    ))}
                  </ul>
                </div>
              )}

              {(!report.issues_summary?.missing_elements?.length &&
                !report.issues_summary?.changed_elements?.length &&
                !report.issues_summary?.recommendations?.length) && (
                <div className="no-issues">
                  <p>Проблем не обнаружено</p>
                </div>
              )}

              {report.detailed_report && (
                <div className="issues-section">
                  <h3>Детальный отчет</h3>
                  <div className="json-viewer">
                    <pre>{JSON.stringify(report.detailed_report, null, 2)}</pre>
                  </div>
                </div>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
