import { useState, useEffect, useCallback } from 'react';
import { getStatusConfig } from '../utils/status';
import { formatDate, formatUUID } from '../utils/format';
import './ProjectReviewDetails.css';

interface ProjectReviewDetailsProps {
  token: string;
  mbUuid: string;
  projectName: string;
  allowedTabs: string[];
  onClose: () => void;
}

interface MigrationDetails {
  id: number;
  mb_project_uuid: string;
  brz_project_id?: number;
  brizy_project_domain?: string;
  status: string;
  created_at: string;
  updated_at?: string;
  completed_at?: string;
  error?: string;
  result_data?: any;
  allowed_tabs?: string[];
  migration_uuid?: string;
}

export default function ProjectReviewDetails({
  token,
  mbUuid,
  projectName,
  allowedTabs,
  onClose
}: ProjectReviewDetailsProps) {
  const [details, setDetails] = useState<MigrationDetails | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<string>('overview');
  const [logs, setLogs] = useState<string | null>(null);
  const [loadingLogs, setLoadingLogs] = useState(false);
  const [screenshots, setScreenshots] = useState<string[]>([]);
  const [loadingScreenshots, setLoadingScreenshots] = useState(false);

  useEffect(() => {
    const loadDetails = async () => {
      try {
        setLoading(true);
        setError(null);
        
        const response = await fetch(`/api/review/wave/${token}/migration/${mbUuid}`);
        const data = await response.json();
        
        if (data.success && data.data) {
          setDetails(data.data);
          // Устанавливаем первую доступную вкладку
          if (data.data.allowed_tabs && data.data.allowed_tabs.length > 0) {
            setActiveTab(data.data.allowed_tabs[0]);
          }
        } else {
          setError(data.error || 'Не удалось загрузить детали проекта');
        }
      } catch (err: any) {
        setError(err.message || 'Ошибка загрузки данных');
      } finally {
        setLoading(false);
      }
    };

    loadDetails();
  }, [token, mbUuid]);

  if (loading) {
    return (
      <div className="modal-overlay" onClick={onClose}>
        <div className="modal-content project-review-modal" onClick={(e) => e.stopPropagation()}>
          <div className="modal-header">
            <h2>Загрузка деталей проекта...</h2>
            <button className="btn-close" onClick={onClose}>×</button>
          </div>
          <div className="modal-body">
            <div className="loading-container">
              <div className="spinner"></div>
            </div>
          </div>
        </div>
      </div>
    );
  }

  if (error || !details) {
    return (
      <div className="modal-overlay" onClick={onClose}>
        <div className="modal-content project-review-modal" onClick={(e) => e.stopPropagation()}>
          <div className="modal-header">
            <h2>Ошибка</h2>
            <button className="btn-close" onClick={onClose}>×</button>
          </div>
          <div className="modal-body">
            <div className="error-container">
              <p className="error-message">❌ {error || 'Данные не найдены'}</p>
            </div>
          </div>
        </div>
      </div>
    );
  }

  const statusConfig = getStatusConfig(details.status as any);
  const progress = details.result_data?.progress;

  // Определяем доступные вкладки из разрешений или используем переданные allowedTabs
  const effectiveAllowedTabs = details.allowed_tabs && details.allowed_tabs.length > 0 
    ? details.allowed_tabs 
    : allowedTabs;

  const availableTabs = [
    { id: 'overview', label: 'Обзор', icon: '📊' },
    { id: 'details', label: 'Детали', icon: '📋' },
    { id: 'logs', label: 'Логи', icon: '📄' },
    { id: 'screenshots', label: 'Скриншоты', icon: '🖼️' },
    { id: 'quality', label: 'Качество', icon: '⭐' },
  ].filter(tab => effectiveAllowedTabs.includes(tab.id));

  const loadLogs = useCallback(async () => {
    try {
      setLoadingLogs(true);
      const response = await fetch(`/api/review/wave/${token}/migration/${mbUuid}/logs`);
      const data = await response.json();
      
      if (data.success && data.data) {
        let logText = '';
        if (typeof data.data === 'string') {
          logText = data.data;
        } else if (data.data.logs) {
          if (Array.isArray(data.data.logs)) {
            logText = data.data.logs.join('\n');
          } else {
            logText = data.data.logs;
          }
        } else {
          logText = JSON.stringify(data.data, null, 2);
        }
        
        // Нормализуем переносы строк
        logText = logText.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
        logText = logText.replace(/\]\[/g, ']\n[');
        logText = logText.replace(/(\])(\[202)/g, '$1\n$2');
        
        setLogs(logText || 'Логи не найдены');
      } else {
        setLogs('Логи не найдены');
      }
    } catch (err: any) {
      setLogs('Ошибка загрузки логов: ' + (err.message || 'Неизвестная ошибка'));
    } finally {
      setLoadingLogs(false);
    }
  }, [token, mbUuid]);

  const loadScreenshots = useCallback(async () => {
    try {
      setLoadingScreenshots(true);
      // Извлекаем скриншоты из result_data, если они там есть
      if (details?.result_data) {
        const screenshotsList: string[] = [];
        
        // Ищем скриншоты в разных местах структуры данных
        const findScreenshots = (obj: any, path: string = ''): void => {
          if (!obj || typeof obj !== 'object') return;
          
          if (Array.isArray(obj)) {
            obj.forEach((item, idx) => findScreenshots(item, `${path}[${idx}]`));
          } else {
            for (const [key, value] of Object.entries(obj)) {
              if (key.toLowerCase().includes('screenshot') || key.toLowerCase().includes('image')) {
                if (typeof value === 'string' && (value.endsWith('.png') || value.endsWith('.jpg') || value.endsWith('.jpeg'))) {
                  screenshotsList.push(value);
                }
              }
              if (typeof value === 'object' && value !== null) {
                findScreenshots(value, path ? `${path}.${key}` : key);
              }
            }
          }
        };
        
        findScreenshots(details.result_data);
        setScreenshots(screenshotsList);
      }
    } catch (err: any) {
      console.error('Ошибка загрузки скриншотов:', err);
    } finally {
      setLoadingScreenshots(false);
    }
  }, [details]);

  // Если активная вкладка не разрешена, переключаемся на первую доступную
  useEffect(() => {
    if (availableTabs.length > 0 && !availableTabs.find(tab => tab.id === activeTab)) {
      setActiveTab(availableTabs[0].id);
    }
  }, [availableTabs, activeTab]);

  // Загрузка логов при переключении на вкладку logs
  useEffect(() => {
    if (activeTab === 'logs' && !logs && !loadingLogs && effectiveAllowedTabs.includes('logs')) {
      loadLogs();
    }
  }, [activeTab, logs, loadingLogs, effectiveAllowedTabs, loadLogs]);

  // Загрузка скриншотов при переключении на вкладку screenshots
  useEffect(() => {
    if (activeTab === 'screenshots' && screenshots.length === 0 && !loadingScreenshots && effectiveAllowedTabs.includes('screenshots')) {
      loadScreenshots();
    }
  }, [activeTab, screenshots.length, loadingScreenshots, effectiveAllowedTabs, loadScreenshots]);

  return (
    <div className="modal-overlay" onClick={onClose}>
      <div className="modal-content project-review-modal" onClick={(e) => e.stopPropagation()}>
        <div className="modal-header">
          <h2>{projectName || formatUUID(mbUuid)}</h2>
          <button className="btn-close" onClick={onClose}>×</button>
        </div>

        {availableTabs.length > 0 && (
          <div className="tabs-nav">
            {availableTabs.map(tab => (
              <button
                key={tab.id}
                className={`tab-button ${activeTab === tab.id ? 'active' : ''}`}
                onClick={() => setActiveTab(tab.id)}
              >
                <span className="tab-icon">{tab.icon}</span>
                {tab.label}
              </button>
            ))}
          </div>
        )}

        <div className="modal-body">
          {activeTab === 'overview' && (
            <div className="tab-content">
              <div className="overview-section">
                <h3>Основная информация</h3>
                <div className="info-grid">
                  <div className="info-item">
                    <span className="info-label">MB UUID:</span>
                    <span className="info-value uuid-cell">{formatUUID(details.mb_project_uuid)}</span>
                  </div>
                  {details.brz_project_id && (
                    <div className="info-item">
                      <span className="info-label">Brizy Project ID:</span>
                      <span className="info-value">{details.brz_project_id}</span>
                    </div>
                  )}
                  {details.brizy_project_domain && (
                    <div className="info-item">
                      <span className="info-label">Домен:</span>
                      <span className="info-value">
                        <a
                          href={details.brizy_project_domain}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="link"
                        >
                          {details.brizy_project_domain}
                        </a>
                      </span>
                    </div>
                  )}
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
                    <span className="info-label">Создано:</span>
                    <span className="info-value">{formatDate(details.created_at)}</span>
                  </div>
                  {details.completed_at && (
                    <div className="info-item">
                      <span className="info-label">Завершено:</span>
                      <span className="info-value">{formatDate(details.completed_at)}</span>
                    </div>
                  )}
                  {progress && (
                    <div className="info-item">
                      <span className="info-label">Прогресс:</span>
                      <span className="info-value">
                        {progress.Success || 0} / {progress.Total || 0}
                        {progress.processTime && ` (${progress.processTime.toFixed(1)}s)`}
                      </span>
                    </div>
                  )}
                </div>
              </div>

              {details.error && (
                <div className="overview-section error-section">
                  <h3>Ошибки</h3>
                  <div className="error-box">
                    <p className="error-text">{details.error}</p>
                  </div>
                </div>
              )}

              {details.result_data?.warnings && details.result_data.warnings.length > 0 && (
                <div className="overview-section warning-section">
                  <h3>Предупреждения ({details.result_data.warnings.length})</h3>
                  <div className="warnings-list">
                    {details.result_data.warnings.slice(0, 10).map((warning: any, idx: number) => (
                      <div key={idx} className="warning-item">
                        {typeof warning === 'string' ? warning : JSON.stringify(warning)}
                      </div>
                    ))}
                    {details.result_data.warnings.length > 10 && (
                      <p className="more-warnings">
                        ... и еще {details.result_data.warnings.length - 10} предупреждений
                      </p>
                    )}
                  </div>
                </div>
              )}
            </div>
          )}

          {activeTab === 'details' && (
            <div className="tab-content">
              <div className="details-section">
                <h3>Детальная информация</h3>
                <pre className="details-json">
                  {JSON.stringify(details.result_data || {}, null, 2)}
                </pre>
              </div>
            </div>
          )}

          {activeTab === 'logs' && (
            <div className="tab-content">
              <div className="logs-section">
                <div className="logs-header">
                  <h3>Логи миграции</h3>
                  <button
                    className="btn-refresh"
                    onClick={loadLogs}
                    disabled={loadingLogs}
                    title="Обновить логи"
                  >
                    {loadingLogs ? '⏳' : '↻'}
                  </button>
                </div>
                {loadingLogs ? (
                  <div className="loading-container">
                    <div className="spinner"></div>
                    <p>Загрузка логов...</p>
                  </div>
                ) : logs ? (
                  <div className="logs-content">
                    {logs.split('\n').map((line, index) => {
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
                                 lowerLine.includes('.success:')) {
                        lineClass += ' log-info';
                      } else if (lowerLine.includes('error') || 
                                 lowerLine.includes('exception') || 
                                 lowerLine.includes('failed')) {
                        lineClass += ' log-error';
                      } else if (lowerLine.includes('warning') || 
                                 lowerLine.includes('warn')) {
                        lineClass += ' log-warning';
                      }
                      
                      return (
                        <div key={index} className={lineClass}>
                          <span className="log-line-content">{line || '\u00A0'}</span>
                        </div>
                      );
                    })}
                  </div>
                ) : (
                  <p className="info-text">Логи не найдены</p>
                )}
              </div>
            </div>
          )}

          {activeTab === 'screenshots' && (
            <div className="tab-content">
              <div className="screenshots-section">
                <div className="screenshots-header">
                  <h3>Скриншоты</h3>
                  <button
                    className="btn-refresh"
                    onClick={loadScreenshots}
                    disabled={loadingScreenshots}
                    title="Обновить скриншоты"
                  >
                    {loadingScreenshots ? '⏳' : '↻'}
                  </button>
                </div>
                {loadingScreenshots ? (
                  <div className="loading-container">
                    <div className="spinner"></div>
                    <p>Загрузка скриншотов...</p>
                  </div>
                ) : screenshots.length > 0 ? (
                  <div className="screenshots-grid">
                    {screenshots.map((screenshot, index) => {
                      // Формируем URL для скриншота
                      const screenshotUrl = screenshot.startsWith('http') 
                        ? screenshot 
                        : `/dashboard/api/screenshots/${screenshot}`;
                      
                      return (
                        <div key={index} className="screenshot-item">
                          <img
                            src={screenshotUrl}
                            alt={`Скриншот ${index + 1}`}
                            className="screenshot-image"
                            onError={(e) => {
                              (e.target as HTMLImageElement).style.display = 'none';
                              const parent = (e.target as HTMLImageElement).parentElement;
                              if (parent) {
                                parent.innerHTML = `<div class="screenshot-error">Не удалось загрузить изображение</div>`;
                              }
                            }}
                          />
                          <div className="screenshot-name">{screenshot.split('/').pop() || `Скриншот ${index + 1}`}</div>
                        </div>
                      );
                    })}
                  </div>
                ) : (
                  <p className="info-text">Скриншоты не найдены</p>
                )}
              </div>
            </div>
          )}

          {activeTab === 'quality' && (
            <div className="tab-content">
              <div className="quality-section">
                <h3>Анализ качества</h3>
                {details.result_data?.quality || details.result_data?.quality_analysis ? (
                  <div className="quality-content">
                    <pre className="quality-json">
                      {JSON.stringify(
                        details.result_data.quality || details.result_data.quality_analysis,
                        null,
                        2
                      )}
                    </pre>
                  </div>
                ) : details.result_data?.warnings ? (
                  <div className="quality-warnings">
                    <h4>Предупреждения качества ({details.result_data.warnings.length})</h4>
                    <div className="warnings-list">
                      {details.result_data.warnings.map((warning: any, idx: number) => (
                        <div key={idx} className="warning-item">
                          {typeof warning === 'string' ? warning : JSON.stringify(warning, null, 2)}
                        </div>
                      ))}
                    </div>
                  </div>
                ) : (
                  <p className="info-text">Данные анализа качества не найдены</p>
                )}
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
