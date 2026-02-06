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
  const [restarting, setRestarting] = useState<string | null>(null);
  const [showLogs, setShowLogs] = useState<string | null>(null);
  const [logs, setLogs] = useState<string | null>(null);
  const [loadingLogs, setLoadingLogs] = useState(false);
  const logsContentRef = useRef<HTMLDivElement>(null);
  const [showWaveLogs, setShowWaveLogs] = useState(false);
  const [waveLogs, setWaveLogs] = useState<string | null>(null);
  const [loadingWaveLogs, setLoadingWaveLogs] = useState(false);
  const waveLogsContentRef = useRef<HTMLDivElement>(null);
  const [removingLock, setRemovingLock] = useState<string | null>(null);
  const [restartingAll, setRestartingAll] = useState(false);
  const [selectedMigrations, setSelectedMigrations] = useState<Set<string>>(new Set());
  const [togglingCloning, setTogglingCloning] = useState<number | null>(null);

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
      setError(err.message || 'Ошибка загрузки деталей');
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
      const response = await api.getWaveDetails(id);
      if (response.success && response.data) {
        setDetails(response.data);
      } else if (response.error) {
        console.error('Error refreshing wave details:', response.error);
      }
    } catch (err: any) {
      console.error('Error refreshing wave details:', err);
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
  }, [details, id]);

  const handleRestartMigration = async (mbUuid: string) => {
    if (!id) return;
    try {
      setRestarting(mbUuid);
      setError(null);
      
      const response = await api.restartWaveMigration(id, mbUuid);
      
      if (response.success) {
        // Перезагружаем детали
        await loadDetails();
      } else {
        setError(response.error || 'Ошибка перезапуска миграции');
      }
    } catch (err: any) {
      setError(err.message || 'Ошибка перезапуска миграции');
    } finally {
      setRestarting(null);
    }
  };

  const handleRemoveLock = async (mbUuid: string) => {
    if (!id) return;
    
    if (!confirm('Вы уверены, что хотите удалить lock-файл? Это разблокирует миграцию для повторного запуска.')) {
      return;
    }
    
    try {
      setRemovingLock(mbUuid);
      setError(null);
      
      const response = await api.removeWaveMigrationLock(id, mbUuid);
      
      if (response.success) {
        const message = (response.data as any)?.message || 'Lock-файл успешно удален';
        alert(message);
        // Перезагружаем детали
        await loadDetails();
      } else {
        setError(response.error || 'Ошибка удаления lock-файла');
      }
    } catch (err: any) {
      setError(err.message || 'Ошибка удаления lock-файла');
    } finally {
      setRemovingLock(null);
    }
  };

  const handleToggleCloning = async (brzProjectId: number, currentValue: boolean) => {
    if (!id) return;
    
    setTogglingCloning(brzProjectId);
    try {
      const newValue = !currentValue;
      const response = await api.toggleCloning(id, brzProjectId, newValue);
      
      if (response.success) {
        // Обновляем локальное состояние
        if (details) {
          setDetails({
            ...details,
            migrations: details.migrations.map(m => 
              m.brz_project_id === brzProjectId 
                ? { ...m, cloning_enabled: newValue }
                : m
            )
          });
        }
      } else {
        setError(response.error || 'Ошибка обновления параметра клонирования');
      }
    } catch (err: any) {
      setError(err.message || 'Ошибка обновления параметра клонирования');
    } finally {
      setTogglingCloning(null);
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

  const handleShowLogs = async (mbUuid: string) => {
    if (!id) return;
    
    if (showLogs === mbUuid) {
      setShowLogs(null);
      setLogs(null);
      return;
    }

    setShowLogs(mbUuid);
    await loadLogs(mbUuid);
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
      <div className="loading-container">
        <div className="spinner"></div>
        <p>Загрузка деталей волны...</p>
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
              <span className="info-value" style={{ display: 'flex', flexDirection: 'row', gap: '0.5rem', flexWrap: 'wrap' }}>
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
                    if (!confirm('Вы уверены, что хотите перезапустить ВСЕ миграции в этой волне? Это очистит кэш и БД записи и запустит миграции заново.')) {
                      return;
                    }
                    try {
                      setRestartingAll(true);
                      setError(null);
                      const response = await api.restartAllWaveMigrations(id!);
                      if (response.success) {
                        const message = (response.data as any)?.message || 'Массовый перезапуск запущен';
                        alert(message);
                        await loadDetails();
                      } else {
                        setError(response.error || 'Ошибка массового перезапуска');
                      }
                    } catch (err: any) {
                      setError(err.message || 'Ошибка массового перезапуска');
                    } finally {
                      setRestartingAll(false);
                    }
                  }}
                  className="btn btn-warning"
                  disabled={restartingAll || details.wave.status === 'in_progress'}
                  title="Перезапустить все миграции в волне (очистит кэш и БД записи)"
                >
                  {restartingAll ? 'Перезапуск...' : '🔄 Перезапустить все миграции'}
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
                        const response = await api.restartAllWaveMigrations(id!, Array.from(selectedMigrations));
                        if (response.success) {
                          const message = (response.data as any)?.message || 'Массовый перезапуск запущен';
                          alert(message);
                          setSelectedMigrations(new Set());
                          await loadDetails();
                        } else {
                          setError(response.error || 'Ошибка массового перезапуска');
                        }
                      } catch (err: any) {
                        setError(err.message || 'Ошибка массового перезапуска');
                      } finally {
                        setRestartingAll(false);
                      }
                    }}
                    className="btn btn-info"
                    disabled={restartingAll || details.wave.status === 'in_progress'}
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
          {details.migrations.length === 0 ? (
            <p className="empty-message">Миграции еще не начаты</p>
          ) : (
            <div className="migrations-table-container">
              <table className="migrations-table">
                <thead>
                  <tr>
                    <th>
                      <input
                        type="checkbox"
                        checked={selectedMigrations.size === details.migrations.length && details.migrations.length > 0}
                        onChange={(e) => {
                          if (e.target.checked) {
                            setSelectedMigrations(new Set(details.migrations.map(m => m.mb_project_uuid)));
                          } else {
                            setSelectedMigrations(new Set());
                          }
                        }}
                        title="Выбрать все"
                      />
                    </th>
                    <th>MB Project UUID</th>
                    <th>Brizy Project ID</th>
                    <th>Статус</th>
                    <th>Domain</th>
                    <th>Клонирование</th>
                    <th>Прогресс</th>
                    <th>Дата</th>
                    <th>Действия</th>
                  </tr>
                </thead>
                <tbody>
                  {details.migrations.map((migration, index) => {
                    const migrationStatusConfig = getStatusConfig(migration.status as any);
                    const progress = migration.result_data?.progress;
                    const isSelected = selectedMigrations.has(migration.mb_project_uuid);
                    return (
                      <tr key={migration.mb_project_uuid || index} style={isSelected ? { backgroundColor: '#e3f2fd' } : {}}>
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
                          {migration.result_data?.warnings && migration.result_data.warnings.length > 0 && (
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
                          {migration.brz_project_id ? (
                            <label className="toggle-switch">
                              <input
                                type="checkbox"
                                checked={migration.cloning_enabled ?? false}
                                onChange={() => handleToggleCloning(
                                  migration.brz_project_id!,
                                  migration.cloning_enabled ?? false
                                )}
                                disabled={togglingCloning === migration.brz_project_id}
                              />
                              <span className="toggle-slider"></span>
                              <span className="toggle-label">
                                {migration.cloning_enabled ? 'Вкл' : 'Выкл'}
                              </span>
                            </label>
                          ) : (
                            '-'
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
                              <>
                                <button
                                  onClick={() => handleRestartMigration(migration.mb_project_uuid)}
                                  className="btn btn-sm btn-primary"
                                  disabled={restarting === migration.mb_project_uuid}
                                  title="Перезапустить миграцию"
                                >
                                  {restarting === migration.mb_project_uuid ? '...' : '↻'}
                                </button>
                                <button
                                  onClick={() => handleRemoveLock(migration.mb_project_uuid)}
                                  className="btn btn-sm"
                                  disabled={removingLock === migration.mb_project_uuid}
                                  title="Удалить lock-файл (разблокировать миграцию)"
                                  style={{ 
                                    backgroundColor: '#f59e0b', 
                                    color: '#fff', 
                                    borderColor: '#f59e0b',
                                    marginLeft: '0.25rem'
                                  }}
                                >
                                  {removingLock === migration.mb_project_uuid ? '...' : '🔓'}
                                </button>
                                <button
                                  onClick={() => handleShowLogs(migration.mb_project_uuid)}
                                  className="btn btn-sm btn-secondary"
                                  title="Показать логи"
                                >
                                  📋
                                </button>
                                <Link
                                  to={`/migrations/${migration.brz_project_id}`}
                                  className="btn btn-sm btn-link"
                                  title="Детали миграции"
                                >
                                  👁
                                </Link>
                              </>
                            )}
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}

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
        </div>
      </div>

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
    </div>
  );
}
