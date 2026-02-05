import { useState, useEffect, useCallback, useRef } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { api, TestMigration } from '../api/client';
import { getStatusConfig } from '../utils/status';
import { formatDate, formatUUID } from '../utils/format';
import './MigrationDetails.css';
import './common.css';

export default function TestMigrationDetails() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const [details, setDetails] = useState<TestMigration | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [restarting, setRestarting] = useState(false);
  const [resettingStatus, setResettingStatus] = useState(false);
  const [activeTab, setActiveTab] = useState<'details' | 'element_result' | 'management'>('details');
  const [elementResult, setElementResult] = useState<any>(null);
  const [isRefreshing, setIsRefreshing] = useState(false);
  const intervalRef = useRef<NodeJS.Timeout | null>(null);

  const loadDetails = useCallback(async (showLoading: boolean = false) => {
    if (!id) return;
    try {
      if (showLoading) {
        setLoading(true);
      } else {
        setIsRefreshing(true);
      }
      setError(null);
      const response = await api.getTestMigrationDetails(parseInt(id));
      if (response.success && response.data) {
        // Используем функциональное обновление для отслеживания изменений статуса
        setDetails(prevDetails => {
          const newDetails = response.data;
          if (!newDetails) {
            return prevDetails;
          }
          // Логируем изменение статуса для отладки
          if (prevDetails && prevDetails.status !== newDetails.status) {
            console.log('[TestMigrationDetails] Status changed:', prevDetails.status, '->', newDetails.status);
          }
          return newDetails;
        });
        
        // Парсим JSON результат секции, если есть (приоритет section_json, затем element_result_json для обратной совместимости)
        const sectionJson = response.data.section_json || response.data.element_result_json;
        console.log('[TestMigrationDetails] Section JSON check:', {
          hasSectionJson: !!response.data.section_json,
          hasElementResultJson: !!response.data.element_result_json,
          sectionJsonType: typeof sectionJson,
          sectionJsonLength: sectionJson ? (typeof sectionJson === 'string' ? sectionJson.length : JSON.stringify(sectionJson).length) : 0,
          sectionJsonPreview: sectionJson ? (typeof sectionJson === 'string' ? sectionJson.substring(0, 200) + '...' : 'not string') : 'null'
        });
        
        if (sectionJson) {
          try {
            let parsed: any;
            if (typeof sectionJson === 'string') {
              // Проверяем, не обрезан ли JSON (65535 - это максимальная длина TEXT в MySQL)
              if (sectionJson.length >= 65535) {
                console.warn('[TestMigrationDetails] section_json is very long (' + sectionJson.length + ' chars), might be truncated');
              }
              
              // Пытаемся распарсить JSON строку
              parsed = JSON.parse(sectionJson);
              let parsedKeys: string | string[];
              if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                parsedKeys = Object.keys(parsed);
              } else if (Array.isArray(parsed)) {
                parsedKeys = `Array[${parsed.length}]`;
              } else {
                parsedKeys = 'N/A';
              }
              console.log('[TestMigrationDetails] Successfully parsed section_json from string, type:', typeof parsed, 'keys:', parsedKeys);
            } else {
              // Уже объект
              parsed = sectionJson;
              let parsedKeys: string | string[];
              if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                parsedKeys = Object.keys(parsed);
              } else if (Array.isArray(parsed)) {
                parsedKeys = `Array[${(parsed as any[]).length}]`;
              } else {
                parsedKeys = 'N/A';
              }
              console.log('[TestMigrationDetails] section_json is already an object, keys:', parsedKeys);
            }
            
            // Всегда обновляем elementResult, если данные есть
            setElementResult(parsed);
            console.log('[TestMigrationDetails] Element result set successfully, type:', typeof parsed);
          } catch (e: any) {
            console.error('[TestMigrationDetails] Error parsing section_json:', e);
            const errorDetails: Record<string, any> = {
              message: e instanceof Error ? e.message : String(e),
              name: e instanceof Error ? e.name : 'Unknown',
            };
            if (typeof sectionJson === 'string') {
              errorDetails.sectionJsonLength = sectionJson.length;
              errorDetails.sectionJsonStart = sectionJson.substring(0, 200);
              errorDetails.sectionJsonEnd = sectionJson.length > 200 ? '...' + sectionJson.substring(sectionJson.length - 200) : 'N/A';
            } else {
              errorDetails.sectionJsonLength = 'N/A';
              errorDetails.sectionJsonStart = 'not string';
              errorDetails.sectionJsonEnd = 'N/A';
            }
            console.error('[TestMigrationDetails] Error details:', errorDetails);
            // Не устанавливаем null, чтобы можно было отобразить как строку
            // setElementResult(null);
          }
        } else {
          console.log('[TestMigrationDetails] No section_json found in response');
          setElementResult(null);
        }
      } else {
        // Показываем ошибку только при первой загрузке
        if (showLoading) {
          setError(response.error || 'Ошибка загрузки деталей');
        }
      }
    } catch (err: any) {
      // Показываем ошибку только при первой загрузке
      if (showLoading) {
        setError(err.message || 'Ошибка загрузки деталей');
      }
    } finally {
      if (showLoading) {
        setLoading(false);
      } else {
        // Небольшая задержка для плавности
        setTimeout(() => {
          setIsRefreshing(false);
        }, 100);
      }
    }
  }, [id]); // Зависимость только от id, чтобы избежать циклических обновлений

  useEffect(() => {
    if (id) {
      loadDetails(true);
    }
  }, [id]);

  // Автоматическое обновление статуса каждые 2 секунды
  useEffect(() => {
    // Очищаем предыдущий интервал, если он был
    if (intervalRef.current) {
      clearInterval(intervalRef.current);
      intervalRef.current = null;
    }

    if (!id) {
      console.log('[TestMigrationDetails] No id, skipping auto-refresh');
      return;
    }

    if (!details) {
      console.log('[TestMigrationDetails] No details yet, skipping auto-refresh');
      return;
    }

    // Обновляем только если миграция в процессе или ожидает запуска
    const shouldAutoRefresh = details.status === 'in_progress' || details.status === 'pending';
    
    console.log('[TestMigrationDetails] Auto-refresh check:', {
      status: details.status,
      shouldAutoRefresh,
      id
    });
    
    if (!shouldAutoRefresh) {
      console.log('[TestMigrationDetails] Status is final, not starting auto-refresh');
      return; // Не запускаем интервал, если статус финальный
    }

    console.log('[TestMigrationDetails] Starting auto-refresh interval');
    // Создаем интервал для автоматического обновления
    intervalRef.current = setInterval(() => {
      console.log('[TestMigrationDetails] Auto-refreshing status...', details.status);
      loadDetails(false);
    }, 2000);

    // Очищаем интервал при размонтировании или изменении зависимостей
    return () => {
      console.log('[TestMigrationDetails] Cleaning up auto-refresh interval');
      if (intervalRef.current) {
        clearInterval(intervalRef.current);
        intervalRef.current = null;
      }
    };
  }, [id, details?.status, loadDetails]); // Зависимости: id, статус и функция loadDetails
  
  // Дополнительный эффект для очистки интервала при размонтировании компонента
  useEffect(() => {
    return () => {
      if (intervalRef.current) {
        clearInterval(intervalRef.current);
        intervalRef.current = null;
      }
    };
  }, []);

  const handleRunMigration = async () => {
    if (!id) return;
    if (!confirm('Запустить тестовую миграцию?')) {
      return;
    }
    
    try {
      setRestarting(true);
      const response = await api.runTestMigration(parseInt(id));
      if (response.success) {
        // Сразу обновляем данные, чтобы увидеть изменение статуса на 'in_progress'
        await loadDetails(false);
        // Затем обновляем еще раз через небольшую задержку для надежности
        setTimeout(() => {
          loadDetails(false);
        }, 1000);
      } else {
        alert('Ошибка запуска: ' + (response.error || 'Неизвестная ошибка'));
      }
    } catch (err: any) {
      alert('Ошибка запуска: ' + err.message);
    } finally {
      setRestarting(false);
    }
  };

  const handleResetStatus = async () => {
    if (!id) return;
    if (!confirm('Вы уверены, что хотите сбросить статус тестовой миграции? Статус будет установлен на "pending", и миграцию можно будет перезапустить.')) {
      return;
    }
    
    try {
      setResettingStatus(true);
      const response = await api.resetTestMigrationStatus(parseInt(id));
      if (response.success) {
        alert(response.data?.message || 'Статус тестовой миграции сброшен');
        // Плавное обновление после сброса статуса
        setTimeout(() => {
          loadDetails(false);
        }, 300);
      } else {
        alert('Ошибка сброса статуса: ' + (response.error || 'Неизвестная ошибка'));
      }
    } catch (err: any) {
      alert('Ошибка сброса статуса: ' + err.message);
    } finally {
      setResettingStatus(false);
    }
  };

  if (loading && !details) {
    return (
      <div className="loading-container">
        <div className="spinner"></div>
        <p>Загрузка деталей тестовой миграции...</p>
      </div>
    );
  }

  if (error && !details) {
    return (
      <div className="error-container">
        <p className="error-message">❌ {error}</p>
        <button onClick={() => navigate('/test')} className="btn btn-primary">
          Вернуться к списку
        </button>
      </div>
    );
  }

  if (!details) {
    return null;
  }

  const statusConfig = getStatusConfig(details.status);

  return (
    <div className="migration-details">
      <div className="page-header">
        <button onClick={() => navigate('/test')} className="btn btn-secondary">
          ← Назад
        </button>
        <h2>Детали тестовой миграции #{details.id}</h2>
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
          className={activeTab === 'details' ? 'active' : ''}
          onClick={() => setActiveTab('details')}
        >
          Детали
        </button>
        {details.mb_element_name && (
          <button
            className={activeTab === 'element_result' ? 'active' : ''}
            onClick={() => setActiveTab('element_result')}
          >
            Результат секции
            {elementResult && <span className="badge-count">✓</span>}
          </button>
        )}
        <button
          className={activeTab === 'management' ? 'active' : ''}
          onClick={() => setActiveTab('management')}
        >
          Управление
        </button>
      </div>

      {activeTab === 'details' && (
        <div className="details-tab">
          <div className="card">
            <div className="card-header">
              <h3 className="card-title">Информация о миграции</h3>
            </div>
            <div className="card-body">
              <table className="info-table">
                <tbody>
                  <tr>
                    <td><strong>ID:</strong></td>
                    <td>{details.id}</td>
                  </tr>
                  <tr>
                    <td><strong>MB Project UUID:</strong></td>
                    <td className="uuid-cell">{formatUUID(details.mb_project_uuid)}</td>
                  </tr>
                  <tr>
                    <td><strong>Brizy Project ID:</strong></td>
                    <td>{details.brz_project_id}</td>
                  </tr>
                  <tr>
                    <td><strong>MB Site ID:</strong></td>
                    <td>{details.mb_site_id || '-'}</td>
                  </tr>
                  <tr>
                    <td><strong>Brizy Workspace ID:</strong></td>
                    <td>{details.brz_workspaces_id || '-'}</td>
                  </tr>
                  <tr>
                    <td><strong>Страница (slug):</strong></td>
                    <td>{details.mb_page_slug || '-'}</td>
                  </tr>
                  <tr>
                    <td><strong>Элемент:</strong></td>
                    <td>{details.mb_element_name || '-'}</td>
                  </tr>
                  <tr>
                    <td><strong>Пропустить загрузку медиа:</strong></td>
                    <td>{details.skip_media_upload ? 'Да' : 'Нет'}</td>
                  </tr>
                  <tr>
                    <td><strong>Пропустить кэш:</strong></td>
                    <td>{details.skip_cache ? 'Да' : 'Нет'}</td>
                  </tr>
                  <tr>
                    <td><strong>Статус:</strong></td>
                    <td>
                      <span
                        className="status-badge"
                        style={{
                          color: statusConfig.color,
                          backgroundColor: statusConfig.bgColor,
                        }}
                      >
                        {statusConfig.label}
                      </span>
                    </td>
                  </tr>
                  <tr>
                    <td><strong>Создано:</strong></td>
                    <td>{formatDate(details.created_at)}</td>
                  </tr>
                  <tr>
                    <td><strong>Обновлено:</strong></td>
                    <td>{formatDate(details.updated_at)}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          {details.changes_json && (
            <div className="card" style={{ marginTop: '1rem' }}>
              <div className="card-header">
                <h3 className="card-title">Результат миграции</h3>
              </div>
              <div className="card-body">
                <pre className="json-code-block">
                  {JSON.stringify(details.changes_json, null, 2)}
                </pre>
              </div>
            </div>
          )}
        </div>
      )}

      {activeTab === 'element_result' && (
        <div className="element-result-tab">
          {/* Отладочная информация */}
          {process.env.NODE_ENV === 'development' && (
            <div className="debug-info">
              <strong>Debug:</strong> section_json exists: {details.section_json ? 'YES' : 'NO'}, 
              element_result exists: {elementResult ? 'YES' : 'NO'},
              section_json type: {typeof details.section_json},
              section_json length: {details.section_json ? (typeof details.section_json === 'string' ? details.section_json.length : 'not string') : 'N/A'}
            </div>
          )}
          {(elementResult || details.section_json) ? (
            <div className="card">
              <div className="card-header">
                <h3 className="card-title">JSON результат секции: {details.mb_element_name}</h3>
                <div className="text-muted" style={{ fontSize: '0.9rem', marginTop: '0.5rem' }}>
                  Элемент: <strong>{details.mb_page_slug}</strong> → <strong>{details.mb_element_name}</strong>
                </div>
              </div>
              <div className="card-body">
                <div style={{ marginBottom: '1rem', display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
                  <button
                    onClick={() => {
                      const jsonString = elementResult
                        ? JSON.stringify(elementResult, null, 2)
                        : (typeof details.section_json === 'string' 
                            ? details.section_json 
                            : JSON.stringify(details.section_json, null, 2));
                      navigator.clipboard.writeText(jsonString);
                      alert('JSON скопирован в буфер обмена');
                    }}
                    className="btn btn-secondary"
                  >
                    📋 Копировать JSON
                  </button>
                  <button
                    onClick={() => {
                      const jsonString = elementResult
                        ? JSON.stringify(elementResult, null, 2)
                        : (typeof details.section_json === 'string' 
                            ? details.section_json 
                            : JSON.stringify(details.section_json, null, 2));
                      const blob = new Blob([jsonString], { type: 'application/json' });
                      const url = URL.createObjectURL(blob);
                      const a = document.createElement('a');
                      a.href = url;
                      a.download = `section_${details.mb_element_name}_${details.id}_${Date.now()}.json`;
                      a.click();
                      URL.revokeObjectURL(url);
                    }}
                    className="btn btn-secondary"
                  >
                    💾 Скачать JSON
                  </button>
                  <button
                    onClick={() => {
                      const jsonString = elementResult
                        ? JSON.stringify(elementResult, null, 2)
                        : (typeof details.section_json === 'string' 
                            ? details.section_json 
                            : JSON.stringify(details.section_json, null, 2));
                      const newWindow = window.open();
                      if (newWindow) {
                        newWindow.document.write(`<pre style="padding: 20px; font-family: monospace; white-space: pre-wrap;">${jsonString.replace(/</g, '&lt;').replace(/>/g, '&gt;')}</pre>`);
                        newWindow.document.title = `Section JSON - ${details.mb_element_name}`;
                      }
                    }}
                    className="btn btn-secondary"
                  >
                    🔍 Открыть в новом окне
                  </button>
                  {!elementResult && details.section_json && (
                    <button
                      onClick={() => {
                        try {
                          const parsed = typeof details.section_json === 'string'
                            ? JSON.parse(details.section_json)
                            : details.section_json;
                          setElementResult(parsed);
                          console.log('[TestMigrationDetails] Manually parsed section_json');
                        } catch (e) {
                          console.error('[TestMigrationDetails] Error manually parsing:', e);
                          const errorMessage = e instanceof Error ? e.message : String(e);
                          alert('Ошибка парсинга JSON: ' + errorMessage);
                        }
                      }}
                      className="btn btn-primary"
                    >
                      🔄 Попробовать распарсить JSON
                    </button>
                  )}
                </div>
                <div className="text-muted" style={{ marginBottom: '0.5rem', fontSize: '0.9rem' }}>
                  Размер JSON: {typeof details.section_json === 'string' ? details.section_json.length : (elementResult ? JSON.stringify(elementResult).length : 0)} символов
                  {!elementResult && details.section_json && (
                    <span style={{ color: '#ff9800', marginLeft: '1rem' }}>
                      ⚠️ Отображается как строка (парсинг не выполнен)
                    </span>
                  )}
                </div>
                <pre className="json-code-block json-code-block-large">
                  {elementResult 
                    ? JSON.stringify(elementResult, null, 2)
                    : (typeof details.section_json === 'string' 
                        ? details.section_json 
                        : JSON.stringify(details.section_json, null, 2))}
                </pre>
              </div>
            </div>
          ) : (
            <div className="card">
              <div className="card-body">
                <p className="text-muted">
                  Результат секции еще не сохранен. Запустите миграцию для получения результата.
                </p>
                {details.mb_element_name && (
                  <p style={{ marginTop: '0.5rem', fontSize: '0.9rem', color: '#999' }}>
                    При тестировании элемента <strong>{details.mb_element_name}</strong> JSON секции будет автоматически сохранен после завершения миграции.
                  </p>
                )}
              </div>
            </div>
          )}
        </div>
      )}

      {activeTab === 'management' && (
        <div className="management-tab">
          <div className="card">
            <div className="card-header">
              <h3 className="card-title">Действия</h3>
            </div>
            <div className="card-body">
              <div className="actions">
                <button
                  onClick={handleRunMigration}
                  className="btn btn-primary"
                  disabled={details.status === 'in_progress' || restarting}
                >
                  {restarting ? 'Запуск...' : 'Запустить миграцию'}
                </button>
                <button
                  onClick={handleResetStatus}
                  className="btn btn-warning"
                  disabled={details.status === 'pending' || resettingStatus}
                  title="Сбросить статус на 'pending' для перезапуска миграции"
                >
                  {resettingStatus ? 'Сброс...' : '🔄 Сбросить статус'}
                </button>
                <button
                  onClick={() => loadDetails(false)}
                  className="btn btn-secondary"
                  disabled={isRefreshing}
                  title="Обновить данные"
                >
                  {isRefreshing ? '⏳' : '🔄 Обновить'}
                </button>
              </div>
              {details.status === 'in_progress' && (
                <div className="alert-warning-message">
                  ⚠️ Миграция выполняется. Дождитесь завершения или сбросьте статус для перезапуска.
                </div>
              )}
              {(details.status === 'error' || details.status === 'completed') && (
                <div className="alert-info-message">
                  💡 Используйте кнопку "Сбросить статус" для перезапуска миграции.
                </div>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
