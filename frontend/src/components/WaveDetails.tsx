import { useState, useEffect, useCallback, useRef } from 'react';
import { useParams, useNavigate, Link } from 'react-router-dom';
import { api, WaveDetails as WaveDetailsType } from '../api/client';
import { getStatusConfig } from '../utils/status';
import { formatDate, formatUUID } from '../utils/format';
import ReviewTokensManager from './ReviewTokensManager';
import './common.css';
import './WaveDetails.css';
import './QualityAnalysis.css';

export default function WaveDetails() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [details, setDetails] = useState<WaveDetailsType | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [autoRefreshing, setAutoRefreshing] = useState(false);
  const [showLogs, setShowLogs] = useState<string | null>(null);
  const [logs, setLogs] = useState<string | null>(null);
  const [loadingLogs, setLoadingLogs] = useState(false);
  const logsContentRef = useRef<HTMLDivElement>(null);
  const [showWaveLogs, setShowWaveLogs] = useState(false);
  const [waveLogs, setWaveLogs] = useState<string | null>(null);
  const [loadingWaveLogs, setLoadingWaveLogs] = useState(false);
  const waveLogsContentRef = useRef<HTMLDivElement>(null);
  const [restartingAll, setRestartingAll] = useState(false);
  const [resettingWave, setResettingWave] = useState(false);
  const [restartWithQualityAnalysis, setRestartWithQualityAnalysis] = useState(false);
  const [selectedMigrations, setSelectedMigrations] = useState<Set<string>>(new Set());
  const [togglingCloningAll, setTogglingCloningAll] = useState<boolean | null>(null);
  const [showCloningProgress, setShowCloningProgress] = useState(false);
  const [cloningProgress, setCloningProgress] = useState<{
    total: number;
    processed: number;
    successful: number;
    failed: number;
    skipped: number;
    logs: Array<{
      brz_project_id: number;
      mb_project_uuid: string;
      status: 'processing' | 'success' | 'error' | 'skipped';
      message?: string;
    }>;
  } | null>(null);

  const loadDetails = async () => {
    if (!id) return;
    try {
      setLoading(true);
      setError(null);
      const response = await api.getWaveDetails(id);
      if (response.success && response.data) {
        setDetails(response.data);
      } else {
        setError(response.error || 'Волна не найдена');
      }
    } catch (err: any) {
      setError(err?.response?.data?.error || err?.message || 'Ошибка загрузки деталей');
      if (err?.response?.status === 404) {
        navigate('/wave', { state: { waveNotFound: id }, replace: true });
      }
    } finally {
      setLoading(false);
    }
  };

  const refreshDetails = async () => {
    // Фоновое обновление деталей волны без полного спиннера
    if (!id || !details) return;
    try {
      const status = details.wave.status;
      const hasActiveMigrations = details.migrations.some(m =>
        m.status === 'in_progress' || m.status === 'pending'
      );

      // Обновляем если волна активна или есть активные миграции
      if (status !== 'in_progress' && status !== 'pending' && !hasActiveMigrations) {
        return;
      }
      setAutoRefreshing(true);
      setError(null);
      const response = await api.getWaveDetails(id);
      if (response.success && response.data) {
        setDetails(response.data);
      } else {
        setError(response.error || 'Ошибка обновления');
      }
    } catch {
      setError('Ошибка обновления. Повторите позже.');
    } finally {
      setAutoRefreshing(false);
    }
  };

  useEffect(() => {
    if (id) {
      loadDetails();
    }
  }, [id]);

  useEffect(() => {
    if (!id || error) return;
    // Обновляем статус каждые 5 секунд, но в фоне
    // Если есть активные миграции (in_progress или pending), обновляем чаще
    const hasActiveMigrations = details?.migrations.some(m =>
      m.status === 'in_progress' || m.status === 'pending'
    ) || false;

    const intervalTime = hasActiveMigrations ? 3000 : 5000; // 3 сек для активных, 5 сек для остальных

    const interval = setInterval(() => {
      refreshDetails();
    }, intervalTime);
    return () => clearInterval(interval);
  }, [details, id, error]);


  const handleToggleCloningForAll = async (cloningEnabled: boolean) => {
    if (!id || !details) return;
    
    const action = cloningEnabled ? 'включить' : 'выключить';
    if (!confirm(`Вы уверены, что хотите ${action} cloning link для ВСЕХ проектов в этой волне?`)) {
      return;
    }

    // Инициализируем прогресс
    const totalProjects = details.migrations.length;
    const initialProgress = {
      total: totalProjects,
      processed: 0,
      successful: 0,
      failed: 0,
      skipped: 0,
      logs: details.migrations.map(m => ({
        brz_project_id: m.brz_project_id || 0,
        mb_project_uuid: m.mb_project_uuid,
        status: 'processing' as const,
        message: 'Ожидание...'
      }))
    };

    setCloningProgress(initialProgress);
    setShowCloningProgress(true);
    setTogglingCloningAll(cloningEnabled);
    setError(null);

    try {
      const response = await api.toggleCloningForAll(id, cloningEnabled);
      
      if (response.success) {
        const data = response.data as any;
        
        // Создаем мапу для быстрого поиска по brz_project_id
        const detailsMap = new Map();
        if (data.details && Array.isArray(data.details)) {
          data.details.forEach((detail: any) => {
            if (detail.brz_project_id) {
              detailsMap.set(detail.brz_project_id, detail);
            }
          });
        }

        // Обновляем прогресс с результатами, сопоставляя по brz_project_id
        const updatedLogs = initialProgress.logs.map((log) => {
          const detail = detailsMap.get(log.brz_project_id);
          if (detail) {
            if (detail.skipped) {
              return {
                ...log,
                status: 'skipped' as const,
                message: detail.error || 'Пропущен (нет brz_project_id)'
              };
            } else if (detail.success) {
              return {
                ...log,
                status: 'success' as const,
                message: 'Успешно обновлен'
              };
            } else {
              return {
                ...log,
                status: 'error' as const,
                message: detail.error || 'Ошибка обновления'
              };
            }
          }
          // Если детали нет, но brz_project_id есть, считаем успешным
          if (log.brz_project_id > 0) {
            return {
              ...log,
              status: 'success' as const,
              message: 'Обработан'
            };
          }
          return {
            ...log,
            status: 'skipped' as const,
            message: 'Пропущен (нет brz_project_id)'
          };
        });

        setCloningProgress({
          total: data.total || totalProjects,
          processed: data.total || totalProjects,
          successful: data.successful || 0,
          failed: data.failed || 0,
          skipped: data.skipped || 0,
          logs: updatedLogs
        });

        // Обновляем детали через небольшую задержку, чтобы пользователь увидел результаты
        setTimeout(async () => {
          await loadDetails();
        }, 1000);
      } else {
        setError(response.error || `Ошибка ${action === 'включить' ? 'включения' : 'выключения'} cloning`);
        setShowCloningProgress(false);
      }
    } catch (err: any) {
      const serverError = err?.response?.data?.error;
      setError(serverError || err?.message || `Ошибка ${action === 'включить' ? 'включения' : 'выключения'} cloning`);
      setShowCloningProgress(false);
    } finally {
      setTogglingCloningAll(null);
    }
  };

  const loadLogs = useCallback(async (mbUuid: string) => {
    if (!id) return;
    
    try {
      setLoadingLogs(true);
      
      const response = await api.getWaveMigrationLogs(id, mbUuid);
      
      if (response.success && response.data) {
        let logText = '';
        
        // Обрабатываем разные форматы ответа
        if (Array.isArray(response.data.logs)) {
          // Если это массив строк, объединяем их
          logText = response.data.logs
            .filter((line: string) => line && line.trim()) // Убираем пустые строки
            .join('\n');
        } else if (typeof response.data.logs === 'string') {
          logText = response.data.logs;
        } else if (typeof response.data === 'string') {
          logText = response.data;
        } else if (response.data.logs && typeof response.data.logs === 'object') {
          // Если logs это объект, преобразуем в строку
          logText = JSON.stringify(response.data.logs, null, 2);
        } else {
          logText = JSON.stringify(response.data, null, 2);
        }
        
        // Нормализуем переносы строк (унифицируем \r\n и \r в \n)
        logText = logText.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
        
        // Разбиваем логи по паттерну начала новой записи
        // Паттерн: ][ или начало с [202 (дата в формате [YYYY-MM-DD)
        // Заменяем ][ на ]\n[ чтобы каждая запись была на новой строке
        logText = logText.replace(/\]\[/g, ']\n[');
        
        // Также разбиваем по паттерну начала новой записи [202
        logText = logText.replace(/(\])(\[202)/g, '$1\n$2');
        
        setLogs(logText || 'Логи не найдены');
        
        // Прокручиваем вверх при загрузке новых логов
        setTimeout(() => {
          if (logsContentRef.current) {
            logsContentRef.current.scrollTop = 0;
          }
        }, 100);
      } else {
        setLogs('Логи не найдены');
      }
    } catch (err: any) {
      setLogs('Ошибка загрузки логов: ' + err.message);
    } finally {
      setLoadingLogs(false);
    }
  }, [id]);

  const loadWaveLogs = useCallback(async () => {
    if (!id) return;
    
    try {
      setLoadingWaveLogs(true);
      const response = await api.getWaveLogs(id);
      
      if (response.success && response.data) {
        let logText = '';
        
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
        
        logText = logText.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
        logText = logText.replace(/\]\[/g, ']\n[');
        logText = logText.replace(/(\])(\[202)/g, '$1\n$2');
        
        setWaveLogs(logText || 'Логи не найдены');
        
        setTimeout(() => {
          if (waveLogsContentRef.current) {
            waveLogsContentRef.current.scrollTop = 0;
          }
        }, 100);
      } else {
        setWaveLogs('Логи не найдены');
      }
    } catch (err: any) {
      setWaveLogs('Ошибка загрузки логов: ' + err.message);
    } finally {
      setLoadingWaveLogs(false);
    }
  }, [id]);

  const handleShowWaveLogs = async () => {
    if (!id) return;
    
    if (showWaveLogs) {
      setShowWaveLogs(false);
      setWaveLogs(null);
      return;
    }

    setShowWaveLogs(true);
    await loadWaveLogs();
  };


  // Автообновление логов для миграций в процессе
  useEffect(() => {
    if (!showLogs || !id) return;
    
    const migration = details?.migrations.find(m => m.mb_project_uuid === showLogs);
    if (migration?.status === 'in_progress') {
      const interval = setInterval(() => {
        loadLogs(showLogs);
      }, 3000); // Обновляем каждые 3 секунды
      
      return () => clearInterval(interval);
    }
  }, [showLogs, details?.migrations, id, loadLogs]);

  // Автообновление логов волны для активных волн
  useEffect(() => {
    if (!showWaveLogs || !id) return;
    
    if (details?.wave.status === 'in_progress' || details?.wave.status === 'pending') {
      const interval = setInterval(() => {
        loadWaveLogs();
      }, 3000); // Обновляем каждые 3 секунды
      
      return () => clearInterval(interval);
    }
  }, [showWaveLogs, details?.wave.status, id, loadWaveLogs]);

  // Автопрокрутка логов волны вверх при обновлении
  useEffect(() => {
    if (waveLogsContentRef.current && showWaveLogs && waveLogs) {
      waveLogsContentRef.current.scrollTop = 0;
    }
  }, [waveLogs, showWaveLogs]);

  if (loading && !details) {
    return (
      <div className="wave-details wave-details-skeleton">
        <div className="page-header">
          <div className="skeleton skeleton-btn" style={{ width: 100 }} />
          <div className="skeleton skeleton-title" style={{ width: 280, height: 28 }} />
          <div className="skeleton skeleton-badge" style={{ width: 100, height: 24 }} />
        </div>
        <div className="details-grid">
          <div className="card">
            <div className="card-header">
              <div className="skeleton" style={{ width: 180, height: 20 }} />
            </div>
            <div className="info-grid">
              {[1, 2, 3, 4, 5].map((i) => (
                <div key={i} className="info-item">
                  <div className="skeleton" style={{ width: 90, height: 16 }} />
                  <div className="skeleton" style={{ width: 140, height: 16 }} />
                </div>
              ))}
            </div>
          </div>
          <div className="card">
            <div className="card-header">
              <div className="skeleton" style={{ width: 220, height: 20 }} />
            </div>
            <div className="skeleton skeleton-table">
              <div className="skeleton-line" />
              <div className="skeleton-line" />
              <div className="skeleton-line" />
              <div className="skeleton-line" />
              <div className="skeleton-line" />
            </div>
          </div>
        </div>
        <p className="skeleton-loading-text">Загрузка деталей волны...</p>
      </div>
    );
  }

  if (error && !details) {
    return (
      <div className="error-container">
        <p className="error-message">❌ {error}</p>
        <button onClick={() => navigate('/wave')} className="btn btn-primary">
          Вернуться к списку
        </button>
      </div>
    );
  }

  if (!details) {
    return null;
  }

  const wave = details.wave;
  const statusConfig = getStatusConfig(wave.status as any);
  const progressPercent = wave.progress.total > 0
    ? Math.round((wave.progress.completed / wave.progress.total) * 100)
    : 0;

  return (
    <div className="wave-details">
      <div className="page-header">
        <button onClick={() => navigate('/wave')} className="btn btn-secondary">
          ← Назад
        </button>
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
          <h2>Волна: {wave.name}</h2>
          {autoRefreshing && (
            <span className="status-refresh-indicator" title="Обновление статуса волны...">
              <span className="inline-spinner" />
            </span>
          )}
        </div>
        <div>
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

      <div className="details-grid">
        <div className="card">
          <div className="card-header">
            <h3 className="card-title">Информация о волне</h3>
          </div>
          <div className="info-grid">
            <div className="info-item">
              <span className="info-label">Название:</span>
              <span className="info-value">{wave.name}</span>
            </div>
            <div className="info-item">
              <span className="info-label">Workspace:</span>
              <span className="info-value">{wave.workspace_name} (ID: {wave.workspace_id})</span>
            </div>
            <div className="info-item">
              <span className="info-label">Статус:</span>
              <span className="info-value">
                <span
                  className="status-badge"
                  style={{
                    color: statusConfig.color,
                    backgroundColor: statusConfig.bgColor,
                  }}
                >
                  {statusConfig.label}
                </span>
              </span>
            </div>
            <div className="info-item">
              <span className="info-label">Прогресс:</span>
              <span className="info-value">
                {wave.progress.completed} / {wave.progress.total}
                {wave.progress.failed > 0 && (
                  <span style={{ color: '#ef4444', marginLeft: '0.5rem' }}>
                    ({wave.progress.failed} ошибок)
                  </span>
                )}
              </span>
            </div>
            <div className="info-item">
              <span className="info-label">Прогресс:</span>
              <span className="info-value">
                <div className="progress-bar" style={{ width: '200px' }}>
                  <div
                    className="progress-fill"
                    style={{
                      width: `${progressPercent}%`,
                      backgroundColor: wave.progress.failed > 0 ? '#ef4444' : '#10b981',
                    }}
                  />
                </div>
                <span style={{ marginLeft: '0.5rem' }}>{progressPercent}%</span>
              </span>
            </div>
            <div className="info-item">
              <span className="info-label">Создано:</span>
              <span className="info-value">{formatDate(wave.created_at)}</span>
            </div>
            {wave.completed_at && (
              <div className="info-item">
                <span className="info-label">Завершено:</span>
                <span className="info-value">{formatDate(wave.completed_at)}</span>
              </div>
            )}
            <div className="info-item">
              <span className="info-label">Действия:</span>
              <span className="info-value" style={{ display: 'flex', flexDirection: 'row', gap: '0.5rem', flexWrap: 'wrap', alignItems: 'center' }}>
                <Link
                  to={`/wave/${id}/mapping`}
                  className="btn btn-primary"
                >
                  📋 Маппинг
                </Link>
                <button
                  onClick={handleShowWaveLogs}
                  className="btn btn-secondary"
                  title="Показать логи волны"
                >
                  📋 Логи волны
                </button>
                <button
                  onClick={async () => {
                    if (!confirm('Сбросить статус волны и всех миграций на «ожидание»? После этого можно перезапустить миграции.')) {
                      return;
                    }
                    try {
                      setResettingWave(true);
                      setError(null);
                      const response = await api.resetWaveStatus(id!);
                      if (response.success) {
                        const message = (response.data as any)?.message || 'Статус волны сброшен';
                        alert(message);
                        await loadDetails();
                      } else {
                        setError(response.error || 'Ошибка сброса статуса');
                      }
                    } catch (err: any) {
                      setError(err.message || 'Ошибка сброса статуса');
                    } finally {
                      setResettingWave(false);
                    }
                  }}
                  className="btn btn-outline-secondary"
                  disabled={resettingWave}
                  title="Сбросить статус волны и миграций на «ожидание» (разблокирует перезапуск)"
                >
                  {resettingWave ? 'Сброс...' : '↺ Сбросить статус волны'}
                </button>
                <label style={{ display: 'inline-flex', alignItems: 'center', gap: '0.35rem', marginLeft: '0.5rem' }}>
                  <input
                    type="checkbox"
                    checked={restartWithQualityAnalysis}
                    onChange={(e) => setRestartWithQualityAnalysis(e.target.checked)}
                    title="Включить анализ AI при массовом перезапуске"
                  />
                  <span>С анализом AI</span>
                </label>
                <button
                  onClick={async () => {
                    if (!confirm('Вы уверены, что хотите перезапустить ВСЕ миграции в этой волне? Это очистит кэш и БД записи и запустит миграции заново.')) {
                      return;
                    }
                    try {
                      setRestartingAll(true);
                      setError(null);
                      const response = await api.restartAllWaveMigrations(id!, undefined, { quality_analysis: restartWithQualityAnalysis });
                      if (response.success) {
                        const n = details.migrations.length;
                        const message = (response.data as any)?.message || (n === 1 ? 'Запущен перезапуск 1 миграции' : `Запущен перезапуск ${n} миграций`);
                        alert(message);
                        await loadDetails();
                      } else {
                        setError(response.error || 'Ошибка перезапуска');
                      }
                    } catch (err: any) {
                      const serverError = err?.response?.data?.error;
                      setError(serverError || err?.message || 'Ошибка перезапуска');
                    } finally {
                      setRestartingAll(false);
                    }
                  }}
                  className="btn btn-warning"
                  disabled={restartingAll}
                  title="Перезапустить все миграции в волне (очистит кэш и БД записи)"
                >
                  {restartingAll ? 'Перезапуск...' : '🔄 Перезапустить все миграции'}
                </button>
                <button
                  onClick={() => handleToggleCloningForAll(true)}
                  className="btn btn-success"
                  disabled={togglingCloningAll !== null}
                  title="Включить cloning link для всех проектов в волне"
                >
                  {togglingCloningAll === true ? 'Включение...' : '✅ Включить cloning для всех'}
                </button>
                <button
                  onClick={() => handleToggleCloningForAll(false)}
                  className="btn btn-outline-danger"
                  disabled={togglingCloningAll !== null}
                  title="Выключить cloning link для всех проектов в волне"
                >
                  {togglingCloningAll === false ? 'Выключение...' : '❌ Выключить cloning для всех'}
                </button>
                {selectedMigrations.size > 0 && (
                  <button
                    onClick={async () => {
                      const count = selectedMigrations.size;
                      if (!confirm(`Вы уверены, что хотите перезапустить ${count} выбранных миграций? Это очистит кэш и БД записи и запустит миграции заново.`)) {
                        return;
                      }
                      try {
                        setRestartingAll(true);
                        setError(null);
                        const response = await api.restartAllWaveMigrations(id!, Array.from(selectedMigrations), { quality_analysis: restartWithQualityAnalysis });
                        if (response.success) {
                          const k = selectedMigrations.size;
                          const message = (response.data as any)?.message || (k === 1 ? 'Запущен перезапуск 1 миграции' : `Запущен перезапуск ${k} миграций`);
                          alert(message);
                          setSelectedMigrations(new Set());
                          await loadDetails();
                        } else {
                          setError(response.error || 'Ошибка перезапуска');
                        }
                      } catch (err: any) {
                        const serverError = err?.response?.data?.error;
                        setError(serverError || err?.message || 'Ошибка перезапуска');
                      } finally {
                        setRestartingAll(false);
                      }
                    }}
                    className="btn btn-info"
                    disabled={restartingAll}
                    title={`Перезапустить ${selectedMigrations.size} выбранных миграций`}
                  >
                    {restartingAll ? 'Перезапуск...' : `🔄 Перезапустить выбранные (${selectedMigrations.size})`}
                  </button>
                )}
              </span>
            </div>
          </div>
        </div>

        <div className="card">
          <div className="card-header">
            <h3 className="card-title">Публичные ссылки для ревью</h3>
          </div>
          <div className="card-body">
            <ReviewTokensManager 
              waveId={id!} 
              projects={details.migrations.map(m => ({ mb_uuid: m.mb_project_uuid, ...m }))}
            />
          </div>
        </div>

        <div className="card">
          <div className="card-header">
            <h3 className="card-title">Миграции в волне</h3>
          </div>
          <div className="migrations-table-container">
            <table className="migrations-table">
              <thead>
                <tr>
                  <th>
                    <input
                      type="checkbox"
                      checked={details.migrations.length > 0 && selectedMigrations.size === details.migrations.length}
                      onChange={(e) => {
                        if (e.target.checked) {
                          setSelectedMigrations(new Set(details.migrations.map(m => m.mb_project_uuid)));
                        } else {
                          setSelectedMigrations(new Set());
                        }
                      }}
                      title="Выбрать все"
                      disabled={details.migrations.length === 0}
                    />
                  </th>
                  <th>MB Project UUID</th>
                  <th>Brizy Project ID</th>
                  <th>Статус</th>
                  <th>Domain</th>
                  <th>Ревьюер</th>
                  <th>Прогресс</th>
                  <th>Дата</th>
                  <th>Действия</th>
                </tr>
              </thead>
              <tbody>
                {details.migrations.length === 0 ? (
                  <tr>
                    <td colSpan={9} className="empty-message" style={{ textAlign: 'center', padding: '1.5rem', color: '#666' }}>
                      Миграции еще не начаты или не загружены
                    </td>
                  </tr>
                ) : (
                  details.migrations.map((migration, index) => {
                    const migrationStatusConfig = getStatusConfig(migration.status as any);
                    const progress = migration.result_data?.progress;
                    const isSelected = selectedMigrations.has(migration.mb_project_uuid);
                    return (
                      <tr key={`${migration.mb_project_uuid}-${index}`} style={isSelected ? { backgroundColor: '#e3f2fd' } : {}}>
                        <td>
                          <input
                            type="checkbox"
                            checked={isSelected}
                            onChange={(e) => {
                              const newSelected = new Set(selectedMigrations);
                              if (e.target.checked) {
                                newSelected.add(migration.mb_project_uuid);
                              } else {
                                newSelected.delete(migration.mb_project_uuid);
                              }
                              setSelectedMigrations(newSelected);
                            }}
                          />
                        </td>
                        <td className="uuid-cell">{formatUUID(migration.mb_project_uuid)}</td>
                        <td>
                          {migration.brz_project_id ? (
                            <Link
                              to={`/migrations/${migration.brz_project_id}`}
                              className="link"
                            >
                              {migration.brz_project_id}
                            </Link>
                          ) : (
                            '-'
                          )}
                        </td>
                        <td>
                          <span
                            className="status-badge"
                            style={{
                              color: migrationStatusConfig.color,
                              backgroundColor: migrationStatusConfig.bgColor,
                            }}
                          >
                            {migrationStatusConfig.label}
                          </span>
                          {migration.error && (
                            <div className="error-text" style={{ fontSize: '0.75rem', marginTop: '0.25rem' }}>
                              {migration.error}
                            </div>
                          )}
                          {migration.status !== 'pending' && migration.status !== 'in_progress' && migration.result_data?.warnings && migration.result_data.warnings.length > 0 && (
                            <div className="warning-text" style={{ fontSize: '0.75rem', marginTop: '0.25rem', color: '#856404' }}>
                              ⚠ {migration.result_data.warnings.length} предупреждений
                            </div>
                          )}
                        </td>
                        <td>
                          {migration.brizy_project_domain ? (
                            <a
                              href={migration.brizy_project_domain}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="link"
                            >
                              {migration.brizy_project_domain}
                            </a>
                          ) : (
                            '-'
                          )}
                        </td>
                        <td>
                          {migration.reviewer?.person_brizy ? (
                            <span className="reviewer-name" title={`UUID: ${migration.reviewer.uuid || migration.mb_project_uuid}`}>
                              {migration.reviewer.person_brizy}
                            </span>
                          ) : (
                            '—'
                          )}
                        </td>
                        <td>
                          {progress ? (
                            <div className="progress-info-small">
                              <span>
                                {progress.Success || 0} / {progress.Total || 0}
                              </span>
                              {progress.processTime && (
                                <span style={{ fontSize: '0.75rem', color: '#666', display: 'block' }}>
                                  {progress.processTime.toFixed(1)}s
                                </span>
                              )}
                            </div>
                          ) : (
                            '-'
                          )}
                        </td>
                        <td>
                          {migration.completed_at ? formatDate(migration.completed_at) : '-'}
                        </td>
                        <td>
                          <div className="action-buttons">
                            {migration.brz_project_id && (
                              <Link
                                to={`/migrations/${migration.brz_project_id}`}
                                className="btn btn-sm btn-link"
                                title="Детали миграции"
                              >
                                👁 Детали миграции
                              </Link>
                            )}
                          </div>
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>
        </div>

          {/* Модальное окно для логов */}
          {showLogs && (
            <div className="page-analysis-modal" onClick={() => {
              setShowLogs(null);
              setLogs(null);
            }}>
              <div className="modal-content" onClick={(e) => e.stopPropagation()} style={{ maxWidth: '90vw', maxHeight: '90vh' }}>
                <div className="modal-header">
                  <h2>
                    Логи миграции: {formatUUID(showLogs)}
                    {details?.migrations.find(m => m.mb_project_uuid === showLogs)?.status === 'in_progress' && (
                      <span className="auto-refresh-badge" style={{ marginLeft: '1rem', fontSize: '0.875rem', fontWeight: 'normal' }}>🔄 Автообновление</span>
                    )}
                  </h2>
                  <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
                    <button
                      onClick={() => loadLogs(showLogs)}
                      className="btn btn-sm btn-secondary"
                      title="Обновить логи"
                      disabled={loadingLogs}
                    >
                      {loadingLogs ? '...' : '↻'}
                    </button>
                    <button
                      onClick={() => {
                        setShowLogs(null);
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
                            .filter(line => line.trim()) // Убираем полностью пустые строки
                            .reverse() // Переворачиваем массив, чтобы новые логи были сверху
                            .map((line, index) => {
                              // Определяем тип строки для стилизации
                              let lineClass = 'log-line';
                              const trimmedLine = line.trim();
                              const lowerLine = trimmedLine.toLowerCase();
                              
                              // Проверяем уровень лога по паттерну Monolog: .INFO:, .ERROR:, .CRITICAL:, .WARNING:, .DEBUG:
                              if (/\.[CRITICAL|ERROR|FATAL]:/i.test(trimmedLine) ||
                                  lowerLine.includes('.critical:') ||
                                  lowerLine.includes('.error:') ||
                                  lowerLine.includes('.fatal:')) {
                                lineClass += ' log-error';
                              } 
                              // Проверяем на предупреждения
                              else if (/\.[WARNING|WARN]:/i.test(trimmedLine) ||
                                       lowerLine.includes('.warning:') ||
                                       lowerLine.includes('.warn:')) {
                                lineClass += ' log-warning';
                              } 
                              // Проверяем на информационные сообщения
                              else if (/\.[INFO|SUCCESS]:/i.test(trimmedLine) ||
                                       lowerLine.includes('.info:') ||
                                       lowerLine.includes('.success:') ||
                                       lowerLine.includes('completed') ||
                                       lowerLine.includes('done')) {
                                lineClass += ' log-info';
                              } 
                              // Проверяем на отладочные сообщения
                              else if (/\.[DEBUG|TRACE]:/i.test(trimmedLine) ||
                                       lowerLine.includes('.debug:') ||
                                       lowerLine.includes('.trace:')) {
                                lineClass += ' log-debug';
                              }
                              // Дополнительные проверки для общих слов
                              else if (lowerLine.includes('error') || 
                                       lowerLine.includes('exception') || 
                                       lowerLine.includes('failed') ||
                                       lowerLine.includes('critical')) {
                                lineClass += ' log-error';
                              } 
                              else if (lowerLine.includes('warning') || 
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
                        <div className="logs-empty">Логи не найдены</div>
                      )}
                    </div>
                  )}
            </div>
          </div>
        </div>
      )}

      {/* Модальное окно для логов волны */}
      {showWaveLogs && (
        <div className="page-analysis-modal" onClick={() => {
          setShowWaveLogs(false);
          setWaveLogs(null);
        }}>
          <div className="modal-content" onClick={(e) => e.stopPropagation()} style={{ maxWidth: '90vw', maxHeight: '90vh' }}>
            <div className="modal-header">
              <h2>
                Логи волны: {wave.name}
                {(wave.status === 'in_progress' || wave.status === 'pending') && (
                  <span className="auto-refresh-badge" style={{ marginLeft: '1rem', fontSize: '0.875rem', fontWeight: 'normal' }}>🔄 Автообновление</span>
                )}
              </h2>
              <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
                <button
                  onClick={() => loadWaveLogs()}
                  className="btn btn-sm btn-secondary"
                  title="Обновить логи"
                  disabled={loadingWaveLogs}
                >
                  {loadingWaveLogs ? '...' : '↻'}
                </button>
                <button
                  onClick={() => {
                    setShowWaveLogs(false);
                    setWaveLogs(null);
                  }}
                  className="btn-close"
                  title="Закрыть"
                >
                  ×
                </button>
              </div>
            </div>
            <div className="modal-body" style={{ padding: 0 }}>
              {loadingWaveLogs && !waveLogs ? (
                <div className="loading-container" style={{ padding: '3rem' }}>
                  <div className="spinner"></div>
                  <p>Загрузка логов...</p>
                </div>
              ) : (
                <div 
                  ref={waveLogsContentRef}
                  className="logs-content" 
                  style={{ padding: '1.5rem', maxHeight: 'calc(90vh - 100px)', overflowY: 'auto' }}
                >
                  {waveLogs ? (
                    <div className="logs-text">
                      {waveLogs
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
                            <div key={`wave-log-${index}`} className={lineClass}>
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

      {/* Модальное окно прогресса массового управления cloning */}
      {showCloningProgress && cloningProgress && (
        <div 
          className="page-analysis-modal" 
          onClick={() => {
            // Закрываем только если процесс завершен
            if (cloningProgress.processed >= cloningProgress.total) {
              setShowCloningProgress(false);
            }
          }}
          style={{ zIndex: 10000 }}
        >
          <div 
            className="modal-content" 
            onClick={(e) => e.stopPropagation()} 
            style={{ maxWidth: '800px', maxHeight: '90vh' }}
          >
            <div className="modal-header">
              <h2>
                {togglingCloningAll === true ? 'Включение cloning link' : 
                 togglingCloningAll === false ? 'Выключение cloning link' : 
                 'Управление cloning link'}
              </h2>
              <button
                className="modal-close"
                onClick={() => {
                  if (cloningProgress.processed >= cloningProgress.total) {
                    setShowCloningProgress(false);
                  }
                }}
                disabled={cloningProgress.processed < cloningProgress.total}
                title={cloningProgress.processed < cloningProgress.total ? 'Дождитесь завершения' : 'Закрыть'}
              >
                ×
              </button>
            </div>
            <div className="modal-body" style={{ padding: '1.5rem' }}>
              {/* Прогресс-бар */}
              <div style={{ marginBottom: '1.5rem' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '0.5rem' }}>
                  <span>
                    Обработано: {cloningProgress.processed} / {cloningProgress.total}
                  </span>
                  <span style={{ color: '#10b981' }}>
                    Успешно: {cloningProgress.successful}
                  </span>
                  {cloningProgress.failed > 0 && (
                    <span style={{ color: '#ef4444' }}>
                      Ошибок: {cloningProgress.failed}
                    </span>
                  )}
                  {cloningProgress.skipped > 0 && (
                    <span style={{ color: '#f59e0b' }}>
                      Пропущено: {cloningProgress.skipped}
                    </span>
                  )}
                </div>
                <div className="progress-bar" style={{ width: '100%', height: '24px' }}>
                  <div
                    className="progress-fill"
                    style={{
                      width: `${cloningProgress.total > 0 ? (cloningProgress.processed / cloningProgress.total) * 100 : 0}%`,
                      backgroundColor: cloningProgress.failed > 0 ? '#ef4444' : '#10b981',
                      height: '100%',
                      transition: 'width 0.3s ease'
                    }}
                  />
                </div>
                <div style={{ marginTop: '0.5rem', fontSize: '0.875rem', color: '#6b7280' }}>
                  {cloningProgress.total > 0 
                    ? `${Math.round((cloningProgress.processed / cloningProgress.total) * 100)}% завершено`
                    : 'Инициализация...'}
                </div>
              </div>

              {/* Логи проектов */}
              <div style={{ 
                maxHeight: '400px', 
                overflowY: 'auto',
                border: '1px solid #e5e7eb',
                borderRadius: '0.5rem',
                padding: '0.75rem'
              }}>
                <div style={{ fontSize: '0.875rem', fontWeight: '600', marginBottom: '0.75rem' }}>
                  Детали обработки:
                </div>
                {cloningProgress.logs.map((log, index) => (
                  <div
                    key={index}
                    style={{
                      padding: '0.5rem',
                      marginBottom: '0.5rem',
                      borderRadius: '0.25rem',
                      backgroundColor: 
                        log.status === 'success' ? '#d1fae5' :
                        log.status === 'error' ? '#fee2e2' :
                        log.status === 'skipped' ? '#fef3c7' :
                        '#f3f4f6',
                      borderLeft: `3px solid ${
                        log.status === 'success' ? '#10b981' :
                        log.status === 'error' ? '#ef4444' :
                        log.status === 'skipped' ? '#f59e0b' :
                        '#9ca3af'
                      }`,
                      display: 'flex',
                      justifyContent: 'space-between',
                      alignItems: 'center'
                    }}
                  >
                    <div style={{ flex: 1 }}>
                      <div style={{ fontWeight: '500', marginBottom: '0.25rem' }}>
                        Проект: {log.brz_project_id > 0 ? log.brz_project_id : 'N/A'} 
                        {log.mb_project_uuid && (
                          <span style={{ color: '#6b7280', fontSize: '0.75rem', marginLeft: '0.5rem' }}>
                            ({formatUUID(log.mb_project_uuid)})
                          </span>
                        )}
                      </div>
                      <div style={{ fontSize: '0.875rem', color: '#6b7280' }}>
                        {log.status === 'processing' && '⏳ Обработка...'}
                        {log.status === 'success' && '✅ ' + (log.message || 'Успешно')}
                        {log.status === 'error' && '❌ ' + (log.message || 'Ошибка')}
                        {log.status === 'skipped' && '⏭️ ' + (log.message || 'Пропущен')}
                      </div>
                    </div>
                    <div style={{ 
                      fontSize: '0.75rem',
                      color: 
                        log.status === 'success' ? '#10b981' :
                        log.status === 'error' ? '#ef4444' :
                        log.status === 'skipped' ? '#f59e0b' :
                        '#6b7280'
                    }}>
                      {log.status === 'processing' && '⏳'}
                      {log.status === 'success' && '✅'}
                      {log.status === 'error' && '❌'}
                      {log.status === 'skipped' && '⏭️'}
                    </div>
                  </div>
                ))}
              </div>

              {/* Кнопка закрытия (только когда завершено) */}
              {cloningProgress.processed >= cloningProgress.total && (
                <div style={{ marginTop: '1.5rem', display: 'flex', justifyContent: 'flex-end' }}>
                  <button
                    className="btn btn-primary"
                    onClick={() => {
                      setShowCloningProgress(false);
                      setCloningProgress(null);
                    }}
                  >
                    Закрыть
                  </button>
                </div>
              )}
            </div>
          </div>
        </div>
      )}
      </div>
    </div>
  );
}
