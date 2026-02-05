import { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { getStatusConfig } from '../utils/status';
import { formatDate, formatUUID } from '../utils/format';
import { useTranslation } from '../hooks/useTranslation';
import LanguageSelector from './LanguageSelector';
import ThemeToggle from './ThemeToggle';
import './ProjectReviewPage.css';
import './QualityAnalysis.css';
import './common.css';

export default function ProjectReviewPage() {
  const { t } = useTranslation();
  const { token, brzProjectId } = useParams<{ token: string; brzProjectId: string }>();
  const navigate = useNavigate();
  const [details, setDetails] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<string>('overview');
  const [logs, setLogs] = useState<string | null>(null);
  const [loadingLogs, setLoadingLogs] = useState(false);
  const [screenshots, setScreenshots] = useState<string[]>([]);
  const [loadingScreenshots, setLoadingScreenshots] = useState(false);
  const [analysisPages, setAnalysisPages] = useState<any[]>([]);
  const [loadingAnalysis, setLoadingAnalysis] = useState(false);
  const [analysisReports, setAnalysisReports] = useState<any[]>([]);
  const [analysisStatistics, setAnalysisStatistics] = useState<any>(null);
  const [selectedAnalysisPage, setSelectedAnalysisPage] = useState<string | null>(null);
  const [severityFilter, setSeverityFilter] = useState<string | null>(null);
  const [fullscreenImage, setFullscreenImage] = useState<string | null>(null);
  const [showReviewModal, setShowReviewModal] = useState(false);
  const [reviewStatus, setReviewStatus] = useState<string>('approved');
  const [reviewComment, setReviewComment] = useState<string>('');
  const [savingReview, setSavingReview] = useState(false);
  const [projectReview, setProjectReview] = useState<any>(null);

  // Используем useRef для отслеживания, был ли уже сделан запрос
  const loadingRef = useRef<string | null>(null);
  const loadedRef = useRef<string | null>(null);
  const abortControllerRef = useRef<AbortController | null>(null);

  useEffect(() => {
    if (!token || !brzProjectId) {
      setError(t('tokenOrUuidNotSpecified'));
      setLoading(false);
      return;
    }

    const requestKey = `${token}-${brzProjectId}`;

    // Если уже загружены данные для этого ключа, не делаем повторный запрос
    if (loadedRef.current === requestKey) {
      console.log('[ProjectReviewPage] Data already loaded for:', requestKey);
      // Убеждаемся, что loading установлен в false
      if (loading) {
        setLoading(false);
      }
      return;
    }

    // Если уже загружаем этот ключ, не делаем повторный запрос
    if (loadingRef.current === requestKey) {
      console.log('[ProjectReviewPage] Already loading:', requestKey);
      return;
    }

    // Отменяем предыдущий запрос, если он был
    if (abortControllerRef.current) {
      console.log('[ProjectReviewPage] Aborting previous request');
      abortControllerRef.current.abort();
    }

    // Создаем новый AbortController для этого запроса
    const abortController = new AbortController();
    abortControllerRef.current = abortController;
    loadingRef.current = requestKey;

    const loadDetails = async () => {
      try {
        setLoading(true);
        setError(null);
        
        console.log('[ProjectReviewPage] Loading details for:', requestKey);
        
        const response = await fetch(`/api/review/wave/${token}/migration/${brzProjectId}`, {
          signal: abortController.signal
        });
        
        if (abortController.signal.aborted) {
          console.log('[ProjectReviewPage] Request aborted');
          return;
        }
        
        if (!response.ok) {
          const errorText = await response.text();
          console.error('[ProjectReviewPage] API Error:', response.status, errorText);
          setError(`Ошибка загрузки: ${response.status} ${response.statusText}`);
          loadingRef.current = null;
          return;
        }
        
        const data = await response.json();
        
        if (abortController.signal.aborted) {
          console.log('[ProjectReviewPage] Request aborted after response');
          return;
        }
        
        console.log('[ProjectReviewPage] API Response received, success:', data.success);
        
        if (data.success && data.data) {
          console.log('[ProjectReviewPage] Setting details');
          setDetails(data.data);
          loadedRef.current = requestKey; // Отмечаем, что данные загружены
          // Активная вкладка будет установлена в useEffect ниже на основе allowed_tabs
          
          // Загружаем существующее ревью после загрузки деталей
          loadProjectReview();
        } else {
          setError(data.error || t('failedToLoadProjectDetails'));
          loadingRef.current = null;
        }
      } catch (err: any) {
        if (abortController.signal.aborted) {
          console.log('[ProjectReviewPage] Request aborted in catch');
          return;
        }
        if (err.name === 'AbortError') {
          console.log('[ProjectReviewPage] Fetch aborted');
          return;
        }
        console.error('[ProjectReviewPage] Fetch error:', err);
        setError(err.message || t('errorLoadingData'));
        loadingRef.current = null;
      } finally {
        if (!abortController.signal.aborted) {
          setLoading(false);
          loadingRef.current = null;
        }
      }
    };

    loadDetails();
    
    // Cleanup function для отмены запроса при размонтировании или изменении зависимостей
    return () => {
      console.log('[ProjectReviewPage] Cleanup: aborting request for:', requestKey);
      if (abortControllerRef.current) {
        abortControllerRef.current.abort();
      }
      loadingRef.current = null;
      abortControllerRef.current = null;
    };
  }, [token, brzProjectId]); // Убрали t из зависимостей, чтобы избежать бесконечного цикла

  // Определяем доступные вкладки из разрешений (мемоизируем, чтобы избежать бесконечных циклов)
  // Если allowed_tabs не определен или пустой, используем все доступные вкладки по умолчанию
  const effectiveAllowedTabs = useMemo(() => {
    if (details?.allowed_tabs && Array.isArray(details.allowed_tabs) && details.allowed_tabs.length > 0) {
      return details.allowed_tabs;
    }
    // По умолчанию разрешаем все доступные вкладки
    return ['overview', 'analysis'];
  }, [details?.allowed_tabs]);

  const availableTabs = useMemo(() => {
    const allTabs = [
      { id: 'overview', label: t('overview'), icon: '📊' },
      { id: 'analysis', label: t('analysis'), icon: '📈' },
    ];
    return allTabs.filter(tab => effectiveAllowedTabs.includes(tab.id));
  }, [effectiveAllowedTabs, t]);

  // Загрузка существующего ревью
  const loadProjectReview = useCallback(async () => {
    if (!token || !brzProjectId) return;
    
    try {
      const response = await fetch(`/api/review/wave/${token}/migration/${brzProjectId}/review`);
      const data = await response.json();
      
      if (data.success && data.data) {
        setProjectReview(data.data);
        setReviewStatus(data.data.review_status || 'approved');
        setReviewComment(data.data.comment || '');
      }
    } catch (err: any) {
      console.error('[ProjectReviewPage] Error loading review:', err);
    }
  }, [token, brzProjectId]);

  // Сохранение ревью
  const handleSaveReview = async () => {
    if (!token || !brzProjectId) return;
    
    try {
      setSavingReview(true);
      
      const response = await fetch(`/api/review/wave/${token}/migration/${brzProjectId}/review`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          review_status: reviewStatus,
          comment: reviewComment || null
        })
      });
      
      const data = await response.json();
      
      if (data.success) {
        // Обновляем локальное состояние
        setProjectReview({
          review_status: reviewStatus,
          comment: reviewComment,
          reviewed_at: new Date().toISOString()
        });
        setShowReviewModal(false);
        alert('Ревью успешно сохранено!');
      } else {
        alert('Ошибка при сохранении ревью: ' + (data.error || 'Неизвестная ошибка'));
      }
    } catch (err: any) {
      console.error('[ProjectReviewPage] Error saving review:', err);
      alert('Ошибка при сохранении ревью: ' + (err.message || 'Неизвестная ошибка'));
    } finally {
      setSavingReview(false);
    }
  };

  // Объявляем функции загрузки данных ДО их использования в useEffect
  const loadLogs = useCallback(async () => {
    if (!token || !brzProjectId) return;
    
    try {
      setLoadingLogs(true);
      const response = await fetch(`/api/review/wave/${token}/migration/${brzProjectId}/logs`);
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
        
        setLogs(logText || t('logsNotFound'));
      } else {
        setLogs(t('logsNotFound'));
      }
    } catch (err: any) {
      setLogs(t('errorLoadingLogs') + ' ' + (err.message || 'Unknown error'));
    } finally {
      setLoadingLogs(false);
    }
  }, [token, brzProjectId]);

  const loadScreenshots = useCallback(async () => {
    if (!details?.result_data) return;
    
    try {
      setLoadingScreenshots(true);
      const screenshotsList: string[] = [];
      
      // Ищем скриншоты в разных местах структуры данных
      const findScreenshots = (obj: any): void => {
        if (!obj || typeof obj !== 'object') return;
        
        if (Array.isArray(obj)) {
          obj.forEach((item) => findScreenshots(item));
        } else {
          for (const [key, value] of Object.entries(obj)) {
            if (key.toLowerCase().includes('screenshot') || key.toLowerCase().includes('image')) {
              if (typeof value === 'string' && (value.endsWith('.png') || value.endsWith('.jpg') || value.endsWith('.jpeg'))) {
                screenshotsList.push(value);
              }
            }
            if (typeof value === 'object' && value !== null) {
              findScreenshots(value);
            }
          }
        }
      };
      
      findScreenshots(details.result_data);
      setScreenshots(screenshotsList);
    } catch (err: any) {
      console.error('Ошибка загрузки скриншотов:', err);
    } finally {
      setLoadingScreenshots(false);
    }
  }, [details]);

  const loadAnalysis = useCallback(async () => {
    if (!token || !brzProjectId) return;
    
    try {
      setLoadingAnalysis(true);
      
      console.log('[ProjectReviewPage] Loading analysis for brzProjectId:', brzProjectId);
      
      // Загружаем статистику и отчеты параллельно
      const [pagesResponse, reportsResponse, statsResponse] = await Promise.allSettled([
        fetch(`/api/review/wave/${token}/migration/${brzProjectId}/analysis`),
        fetch(`/api/review/wave/${token}/migration/${brzProjectId}/analysis/reports`),
        fetch(`/api/review/wave/${token}/migration/${brzProjectId}/analysis/statistics`)
      ]);
      
      // Обработка списка страниц
      if (pagesResponse.status === 'fulfilled') {
        const response = pagesResponse.value;
        if (!response.ok) {
          console.error('[ProjectReviewPage] Pages API error:', response.status, response.statusText);
          const errorText = await response.text();
          console.error('[ProjectReviewPage] Pages API error details:', errorText);
          setAnalysisPages([]);
        } else {
          const pagesData = await response.json();
          console.log('[ProjectReviewPage] Pages response:', pagesData);
          if (pagesData.success && pagesData.data) {
            const pages = Array.isArray(pagesData.data) ? pagesData.data : [];
            console.log('[ProjectReviewPage] Setting analysis pages:', pages.length);
            setAnalysisPages(pages);
          } else {
            console.warn('[ProjectReviewPage] Pages response not successful:', pagesData);
            setAnalysisPages([]);
          }
        }
      } else {
        console.error('[ProjectReviewPage] Pages request failed:', pagesResponse.reason);
        setAnalysisPages([]);
      }
      
      // Обработка отчетов
      if (reportsResponse.status === 'fulfilled') {
        const response = reportsResponse.value;
        if (!response.ok) {
          console.error('[ProjectReviewPage] Reports API error:', response.status, response.statusText);
          const errorText = await response.text();
          console.error('[ProjectReviewPage] Reports API error details:', errorText);
          setAnalysisReports([]);
        } else {
          const reportsData = await response.json();
          console.log('[ProjectReviewPage] Reports response:', reportsData);
          if (reportsData.success && reportsData.data) {
            const reports = Array.isArray(reportsData.data) ? reportsData.data : [];
            console.log('[ProjectReviewPage] Setting analysis reports:', reports.length);
            setAnalysisReports(reports);
          } else {
            console.warn('[ProjectReviewPage] Reports response not successful:', reportsData);
            setAnalysisReports([]);
          }
        }
      } else {
        console.error('[ProjectReviewPage] Reports request failed:', reportsResponse.reason);
        setAnalysisReports([]);
      }
      
      // Обработка статистики
      if (statsResponse.status === 'fulfilled') {
        const response = statsResponse.value;
        if (!response.ok) {
          console.error('[ProjectReviewPage] Statistics API error:', response.status, response.statusText);
          const errorText = await response.text();
          console.error('[ProjectReviewPage] Statistics API error details:', errorText);
        } else {
          const statsData = await response.json();
          console.log('[ProjectReviewPage] Statistics response:', statsData);
          if (statsData.success && statsData.data) {
            setAnalysisStatistics(statsData.data);
          } else {
            // Устанавливаем пустую статистику
            setAnalysisStatistics({
              total_pages: 0,
              avg_quality_score: null,
              by_severity: {
                critical: 0,
                high: 0,
                medium: 0,
                low: 0,
                none: 0
              },
              token_statistics: {
                total_prompt_tokens: 0,
                total_completion_tokens: 0,
                total_tokens: 0,
                avg_tokens_per_page: 0,
                total_cost_usd: 0,
                avg_cost_per_page_usd: 0
              }
            });
          }
        }
      } else {
        console.error('[ProjectReviewPage] Statistics request failed:', statsResponse.reason);
        // Устанавливаем пустую статистику при ошибке
        setAnalysisStatistics({
          total_pages: 0,
          avg_quality_score: null,
          by_severity: {
            critical: 0,
            high: 0,
            medium: 0,
            low: 0,
            none: 0
          },
          token_statistics: {
            total_prompt_tokens: 0,
            total_completion_tokens: 0,
            total_tokens: 0,
            avg_tokens_per_page: 0,
            total_cost_usd: 0,
            avg_cost_per_page_usd: 0
          }
        });
      }
    } catch (err: any) {
      console.error('[ProjectReviewPage] Error loading analysis:', err);
      setAnalysisPages([]);
      setAnalysisReports([]);
      setAnalysisStatistics(null);
    } finally {
      setLoadingAnalysis(false);
    }
  }, [token, brzProjectId]);

  // Если активная вкладка не разрешена, переключаемся на первую доступную
  const availableTabIds = useMemo(() => availableTabs.map(tab => tab.id), [availableTabs]);
  const firstAvailableTabId = useMemo(() => availableTabs.length > 0 ? availableTabs[0].id : null, [availableTabs]);
  
  useEffect(() => {
    // Только переключаем вкладку, если details уже загружены и текущая вкладка не разрешена
    if (details && firstAvailableTabId && !availableTabIds.includes(activeTab)) {
      // Проверяем, что мы действительно меняем вкладку, чтобы избежать бесконечных циклов
      if (activeTab !== firstAvailableTabId) {
        setActiveTab(firstAvailableTabId);
      }
    }
  }, [details, firstAvailableTabId, availableTabIds, activeTab]);

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

  // Загрузка анализа при переключении на вкладку analysis
  useEffect(() => {
    if (activeTab === 'analysis' && analysisPages.length === 0 && analysisReports.length === 0 && !loadingAnalysis && effectiveAllowedTabs.includes('analysis')) {
      console.log('[ProjectReviewPage] Loading analysis on tab switch');
      loadAnalysis();
    }
  }, [activeTab, analysisPages.length, analysisReports.length, loadingAnalysis, effectiveAllowedTabs, loadAnalysis]);

  if (loading) {
    return (
      <div className="project-review-page">
        <div className="loading-container">
          <div className="spinner"></div>
          <p>{t('loading')}</p>
        </div>
      </div>
    );
  }

  if (error || !details) {
    return (
      <div className="project-review-page">
        <div className="error-container">
          <p className="error-message">❌ {error || t('dataNotFound')}</p>
          <button
            className="btn btn-secondary"
            onClick={() => navigate(`/review/${token}`)}
          >
            {t('backToProjects')}
          </button>
        </div>
      </div>
    );
  }

  const statusConfig = getStatusConfig(details.status as any);
  const progress = details.result_data?.progress;
  const projectName = details.brizy_project_domain 
    ? details.brizy_project_domain.replace(/^https?:\/\//, '').replace(/\/$/, '')
    : formatUUID(details.mb_project_uuid);

  return (
    <div className="project-review-page">
      <div className="review-page-header">
        <div style={{ display: 'flex', alignItems: 'center', gap: '1rem' }}>
          <button
            className="btn btn-secondary"
            onClick={() => navigate(`/review/${token}`)}
          >
            {t('backToProjects')}
          </button>
          <LanguageSelector />
          <ThemeToggle />
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: '1rem', flex: 1, justifyContent: 'space-between' }}>
          <h1>{projectName}</h1>
          <button
            className="btn btn-primary"
            onClick={() => setShowReviewModal(true)}
            style={{ marginLeft: 'auto' }}
          >
            {projectReview ? '✏️ ' : '✅ '}{t('completeReview') || 'Завершить ревью'}
          </button>
        </div>
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

      <div className="review-page-content">
        {activeTab === 'overview' && (
          <div className="tab-content">
            <div className="overview-section">
              <h3>{t('basicInfo')}</h3>
              <div className="info-grid">
                <div className="info-item">
                  <span className="info-label">{t('mbUuid')}</span>
                  <span className="info-value uuid-cell">{formatUUID(details.mb_project_uuid)}</span>
                </div>
                {details.brz_project_id && (
                  <div className="info-item">
                    <span className="info-label">{t('brizyProjectId')}</span>
                    <span className="info-value">{details.brz_project_id}</span>
                  </div>
                )}
                {details.brizy_project_domain && (
                  <div className="info-item">
                    <span className="info-label">{t('domain')}</span>
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
                  <span className="info-label">{t('status')}:</span>
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
                  <span className="info-label">{t('created')}:</span>
                  <span className="info-value">{formatDate(details.created_at)}</span>
                </div>
                {details.completed_at && (
                  <div className="info-item">
                    <span className="info-label">{t('completed')}:</span>
                    <span className="info-value">{formatDate(details.completed_at)}</span>
                  </div>
                )}
                {progress && (
                  <div className="info-item">
                    <span className="info-label">{t('progress')}:</span>
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
                <h3>{t('errors')}</h3>
                <div className="error-box">
                  <p className="error-text">{details.error}</p>
                </div>
              </div>
            )}

            {details.result_data?.warnings && details.result_data.warnings.length > 0 && (
              <div className="overview-section warning-section">
                <h3>{t('warnings')} ({details.result_data.warnings.length})</h3>
                <div className="warnings-list">
                  {details.result_data.warnings.slice(0, 10).map((warning: any, idx: number) => (
                    <div key={idx} className="warning-item">
                      {typeof warning === 'string' ? warning : JSON.stringify(warning)}
                    </div>
                  ))}
                  {details.result_data.warnings.length > 10 && (
                    <p className="more-warnings">
                      {t('moreWarnings', { count: details.result_data.warnings.length - 10 })}
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
                    const screenshotFilename = screenshot.split('/').pop() || screenshot;
                    const screenshotUrl = screenshot.startsWith('http') 
                      ? screenshot 
                      : `/api/review/wave/${token}/screenshots/${screenshotFilename}`;
                    
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
                        <div className="screenshot-name">{screenshotFilename}</div>
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

        {activeTab === 'analysis' && (
          <div className="tab-content">
            <div className="analysis-section quality-analysis">
              <div className="analysis-header">
                <h3>{t('pageAnalysis')}</h3>
                <button
                  className="btn-refresh"
                  onClick={loadAnalysis}
                  disabled={loadingAnalysis}
                  title={t('refresh')}
                >
                  {loadingAnalysis ? '⏳' : '↻'}
                </button>
              </div>
              
              {loadingAnalysis ? (
                <div className="loading-container">
                  <div className="spinner"></div>
                  <p>{t('loading')}</p>
                </div>
              ) : (
                <>
                  {/* Плитки статистики */}
                  {analysisStatistics && (
                    <>
                      <div className="quality-statistics">
                        <div className="stat-card">
                          <div className="stat-label">{t('totalPages')}</div>
                          <div className="stat-value">{analysisStatistics.total_pages ?? 0}</div>
                        </div>
                        <div className="stat-card">
                          <div className="stat-label">{t('averageRating')}</div>
                          <div className="stat-value" style={{ 
                            color: analysisStatistics.avg_quality_score !== null 
                              ? (analysisStatistics.avg_quality_score >= 90 ? '#198754' 
                                : analysisStatistics.avg_quality_score >= 70 ? '#ffc107' 
                                : analysisStatistics.avg_quality_score >= 50 ? '#fd7e14' 
                                : '#dc3545')
                              : '#6c757d'
                          }}>
                            {analysisStatistics.avg_quality_score !== null 
                              ? analysisStatistics.avg_quality_score.toFixed(1) 
                              : 'N/A'}
                          </div>
                        </div>
                      </div>
                      
                      <div className="quality-statistics severity-row">
                        <div 
                          className={`stat-card ${severityFilter === 'critical' ? 'active-filter' : ''}`}
                          onClick={() => setSeverityFilter(severityFilter === 'critical' ? null : 'critical')}
                          style={{ cursor: 'pointer', transition: 'all 0.2s ease' }}
                        >
                          <div className="stat-label">{t('critical')}</div>
                          <div className="stat-value" style={{ color: '#dc3545' }}>
                            {analysisStatistics.by_severity?.critical ?? 0}
                          </div>
                        </div>
                        <div 
                          className={`stat-card ${severityFilter === 'high' ? 'active-filter' : ''}`}
                          onClick={() => setSeverityFilter(severityFilter === 'high' ? null : 'high')}
                          style={{ cursor: 'pointer', transition: 'all 0.2s ease' }}
                        >
                          <div className="stat-label">{t('high')}</div>
                          <div className="stat-value" style={{ color: '#fd7e14' }}>
                            {analysisStatistics.by_severity?.high ?? 0}
                          </div>
                        </div>
                        <div 
                          className={`stat-card ${severityFilter === 'medium' ? 'active-filter' : ''}`}
                          onClick={() => setSeverityFilter(severityFilter === 'medium' ? null : 'medium')}
                          style={{ cursor: 'pointer', transition: 'all 0.2s ease' }}
                        >
                          <div className="stat-label">{t('medium')}</div>
                          <div className="stat-value" style={{ color: '#ffc107' }}>
                            {analysisStatistics.by_severity?.medium ?? 0}
                          </div>
                        </div>
                        <div 
                          className={`stat-card ${severityFilter === 'low' ? 'active-filter' : ''}`}
                          onClick={() => setSeverityFilter(severityFilter === 'low' ? null : 'low')}
                          style={{ cursor: 'pointer', transition: 'all 0.2s ease' }}
                        >
                          <div className="stat-label">{t('low')}</div>
                          <div className="stat-value" style={{ color: '#0dcaf0' }}>
                            {analysisStatistics.by_severity?.low ?? 0}
                          </div>
                        </div>
                      </div>
                    </>
                  )}

                  {/* Список страниц с анализом */}
                  {analysisReports.length > 0 ? (
                    <div className="quality-pages-list">
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
                        <h3>{t('pageAnalysis')}</h3>
                        {severityFilter && (
                          <button 
                            onClick={() => setSeverityFilter(null)}
                            className="btn btn-secondary"
                            style={{ fontSize: '0.875rem', padding: '0.25rem 0.75rem' }}
                          >
                            {t('resetFilter', { severity: severityFilter })}
                          </button>
                        )}
                      </div>
                      <div className="pages-grid">
                        {analysisReports
                          .filter((report: any) => !severityFilter || report.severity_level === severityFilter)
                          .map((report: any) => {
                            const qualityScore = report.quality_score ?? null;
                            const getQualityScoreColor = (score: number | null) => {
                              if (!score || score === null) return '#6c757d';
                              if (score >= 90) return '#198754';
                              if (score >= 70) return '#ffc107';
                              if (score >= 50) return '#fd7e14';
                              return '#dc3545';
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
                            
                            return (
                              <div
                                key={report.id || report.page_slug}
                                className={`page-card ${selectedAnalysisPage === report.page_slug ? 'selected' : ''}`}
                                onClick={() => setSelectedAnalysisPage(report.page_slug)}
                              >
                                <div className="page-card-header" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.75rem' }}>
                                  <h4 style={{ margin: 0, flex: 1 }}>{report.page_slug || t('noTitle')}</h4>
                                  <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
                                    {qualityScore !== null && qualityScore !== undefined && (
                                      <span
                                        className="score-value"
                                        style={{ 
                                          color: getQualityScoreColor(typeof qualityScore === 'string' ? parseInt(qualityScore) : qualityScore),
                                          fontWeight: 600,
                                          fontSize: '0.95rem'
                                        }}
                                      >
                                        {t('rating')} {typeof qualityScore === 'string' ? parseInt(qualityScore) : qualityScore}
                                      </span>
                                    )}
                                    <span
                                      className="severity-badge"
                                      style={{
                                        backgroundColor: getSeverityColor(report.severity_level || 'none'),
                                        color: 'white',
                                        padding: '0.25rem 0.5rem',
                                        borderRadius: '4px',
                                        fontSize: '0.875rem'
                                      }}
                                    >
                                      {report.severity_level || 'none'}
                                    </span>
                                  </div>
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
                                        title={t('edit')}
                                      >
                                        {t('edit')}
                                      </a>
                                    </div>
                                  )}
                                  {/* Скриншоты */}
                                  {(report.screenshots_path?.source || report.screenshots_path?.migrated) && (
                                    <div style={{ display: 'flex', gap: '0.5rem', marginBottom: '0.75rem', minHeight: '150px' }}>
                                      {report.screenshots_path?.source && (() => {
                                        const sourceFilename = report.screenshots_path.source.split('/').pop();
                                        return sourceFilename ? (
                                          <div style={{ flex: 1, border: '1px solid #e0e0e0', borderRadius: '4px', overflow: 'hidden', backgroundColor: '#f9fafb', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                            <img 
                                              src={`/api/review/wave/${token}/screenshots/${sourceFilename}`}
                                              alt="Исходная страница"
                                              style={{ width: '100%', height: '100%', objectFit: 'contain', display: 'block', maxHeight: '150px' }}
                                              onError={(e) => {
                                                (e.target as HTMLImageElement).style.display = 'none';
                                              }}
                                            />
                                          </div>
                                        ) : null;
                                      })()}
                                      {report.screenshots_path?.migrated && (() => {
                                        const migratedFilename = report.screenshots_path.migrated.split('/').pop();
                                        return migratedFilename ? (
                                          <div style={{ flex: 1, border: '1px solid #e0e0e0', borderRadius: '4px', overflow: 'hidden', backgroundColor: '#f9fafb', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                            <img 
                                              src={`/api/review/wave/${token}/screenshots/${migratedFilename}`}
                                              alt="Мигрированная страница"
                                              style={{ width: '100%', height: '100%', objectFit: 'contain', display: 'block', maxHeight: '150px' }}
                                              onError={(e) => {
                                                (e.target as HTMLImageElement).style.display = 'none';
                                              }}
                                            />
                                          </div>
                                        ) : null;
                                      })()}
                                    </div>
                                  )}
                                  
                                  {report.token_usage && (
                                    <div className="page-tokens-info" style={{ display: 'flex', gap: '1rem', marginBottom: '0.75rem', flexWrap: 'wrap', fontSize: '0.875rem' }}>
                                      <span className="tokens-value" style={{ color: '#6c757d' }}>
                                        {report.token_usage.total_tokens?.toLocaleString() || '0'}
                                        {report.token_usage.prompt_tokens && report.token_usage.completion_tokens && (
                                          <span className="tokens-detail" style={{ fontSize: '0.8rem', color: '#9ca3af', marginLeft: '0.25rem' }}>
                                            ({report.token_usage.prompt_tokens.toLocaleString()}/{report.token_usage.completion_tokens.toLocaleString()})
                                          </span>
                                        )}
                                      </span>
                                    </div>
                                  )}
                                  <div className="page-meta" style={{ fontSize: '0.75rem', color: '#9ca3af' }}>
                                    <span className="meta-item">
                                      {report.created_at ? formatDate(report.created_at) : '—'}
                                    </span>
                                    {report.analysis_status === 'completed' && (
                                      <span className="meta-item status-completed">✓ {t('completed')}</span>
                                    )}
                                  </div>
                                </div>
                              </div>
                            );
                          })}
                      </div>
                    </div>
                  ) : loadingAnalysis ? (
                    <div className="loading-container">
                      <div className="spinner"></div>
                      <p>{t('loading')}</p>
                    </div>
                  ) : (
                    <div className="quality-analysis-empty" style={{ marginTop: '2rem' }}>
                      <p>{t('noAnalysis') || 'Анализ страниц не найден'}</p>
                      <p className="text-muted">
                        {t('noAnalysisDescription') || 'Для этого проекта еще не был выполнен анализ качества страниц. Анализ выполняется автоматически во время миграции.'}
                      </p>
                      <div style={{ marginTop: '1rem', fontSize: '0.875rem', color: '#718096' }}>
                        <p>Загружено страниц: {analysisPages.length}</p>
                        <p>Загружено отчетов: {analysisReports.length}</p>
                      </div>
                    </div>
                  )}
                </>
              )}
            </div>
          </div>
        )}

        {/* Модальное окно для деталей анализа страницы */}
        {selectedAnalysisPage && (
          <PageAnalysisDetailsModal
            token={token!}
            brzProjectId={brzProjectId!}
            mbUuid={details?.mb_project_uuid || details?.mb_uuid}
            pageSlug={selectedAnalysisPage}
            onClose={() => setSelectedAnalysisPage(null)}
          />
        )}

        {/* Полноэкранный просмотр изображения */}
        {fullscreenImage && (
          <div 
            className="fullscreen-image-overlay"
            onClick={() => setFullscreenImage(null)}
          >
            <button 
              className="fullscreen-close-btn"
              onClick={(e) => {
                e.stopPropagation();
                setFullscreenImage(null);
              }}
            >
              ×
            </button>
            <img 
              src={fullscreenImage}
              alt="Fullscreen screenshot"
              className="fullscreen-image"
              onClick={(e) => e.stopPropagation()}
            />
          </div>
        )}
      </div>

      {/* Модальное окно для завершения ревью */}
      {showReviewModal && (
        <div className="modal-overlay" onClick={() => setShowReviewModal(false)}>
          <div className="modal-content" onClick={(e) => e.stopPropagation()} style={{ maxWidth: '600px', width: '90%' }}>
            <div className="modal-header">
              <h2>{t('completeReview') || 'Завершить ревью'}</h2>
              <button className="btn-close" onClick={() => setShowReviewModal(false)}>×</button>
            </div>
            <div className="modal-body">
              <div className="form-group">
                <label className="form-label">
                  {t('reviewStatus') || 'Статус ревью'} <span className="required">*</span>
                </label>
                <select
                  className="form-select"
                  value={reviewStatus}
                  onChange={(e) => setReviewStatus(e.target.value)}
                  disabled={savingReview}
                >
                  <option value="approved">{t('approved') || 'Одобрено'}</option>
                  <option value="rejected">{t('rejected') || 'Отклонено'}</option>
                  <option value="needs_changes">{t('needsChanges') || 'Требуются изменения'}</option>
                  <option value="pending">{t('pending') || 'Ожидает ревью'}</option>
                </select>
              </div>
              
              <div className="form-group">
                <label className="form-label">
                  {t('comment') || 'Комментарий'} {t('optional') || '(необязательно)'}
                </label>
                <textarea
                  className="form-textarea"
                  rows={6}
                  value={reviewComment}
                  onChange={(e) => setReviewComment(e.target.value)}
                  placeholder={t('reviewCommentPlaceholder') || 'Оставьте комментарий к ревью (если необходимо)...'}
                  disabled={savingReview}
                />
              </div>

              {projectReview && projectReview.reviewed_at && (
                <div className="info-text" style={{ marginBottom: '1rem', fontSize: '0.875rem', color: '#666' }}>
                  {t('lastReviewed') || 'Последнее ревью'}: {formatDate(projectReview.reviewed_at)}
                </div>
              )}
            </div>
            <div className="modal-footer" style={{ display: 'flex', gap: '1rem', justifyContent: 'flex-end' }}>
              <button
                className="btn btn-secondary"
                onClick={() => setShowReviewModal(false)}
                disabled={savingReview}
              >
                {t('cancel') || 'Отмена'}
              </button>
              <button
                className="btn btn-primary"
                onClick={handleSaveReview}
                disabled={savingReview}
              >
                {savingReview ? (t('saving') || 'Сохранение...') : (t('save') || 'Сохранить')}
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

// Компонент модального окна для деталей анализа страницы
function PageAnalysisDetailsModal({ 
  token, 
  brzProjectId,
  mbUuid, 
  pageSlug, 
  onClose 
}: { 
  token: string; 
  brzProjectId: string;
  mbUuid?: string; 
  pageSlug: string; 
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const [report, setReport] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'overview' | 'screenshots' | 'issues' | 'json'>('screenshots');
  const [fullscreenImage, setFullscreenImage] = useState<string | null>(null);
  const [imageScale, setImageScale] = useState(1);
  const [imagePosition, setImagePosition] = useState({ x: 0, y: 0 });
  const [isDragging, setIsDragging] = useState(false);
  const [dragStart, setDragStart] = useState({ x: 0, y: 0 });

  useEffect(() => {
    loadPageAnalysis();
  }, [token, brzProjectId, pageSlug]);

  const loadPageAnalysis = async () => {
    try {
      setLoading(true);
      setError(null);
      const response = await fetch(`/api/review/wave/${token}/migration/${brzProjectId}/analysis/${encodeURIComponent(pageSlug)}`);
      const data = await response.json();
      
      if (data.success && data.data) {
        setReport(data.data);
      } else {
        setError(data.error || t('analysisNotFound'));
      }
    } catch (err: any) {
      setError(err.message || t('errorLoadingAnalysis'));
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

  const getQualityScoreColor = (score?: number | null) => {
    if (!score || score === null) return '#6c757d';
    if (score >= 90) return '#198754';
    if (score >= 70) return '#ffc107';
    if (score >= 50) return '#fd7e14';
    return '#dc3545';
  };

  if (loading) {
    return (
      <div className="page-analysis-modal" onClick={onClose}>
        <div className="modal-content" onClick={(e) => e.stopPropagation()}>
          <div className="loading-container">
            <div className="spinner"></div>
            <p>{t('loading')}</p>
          </div>
        </div>
      </div>
    );
  }

  if (error || !report) {
    return (
      <div className="page-analysis-modal" onClick={onClose}>
        <div className="modal-content" onClick={(e) => e.stopPropagation()}>
          <div className="error-container">
            <p className="error-message">❌ {error || t('analysisNotFound')}</p>
            <button onClick={onClose} className="btn btn-secondary">
              {t('close')}
            </button>
          </div>
        </div>
      </div>
    );
  }

  const sourceScreenshot = report.screenshots_path?.source;
  const migratedScreenshot = report.screenshots_path?.migrated;
  
  // Функция для получения URL скриншота
  const getScreenshotUrl = (path: string | null | undefined): string | null => {
    if (!path) return null;
    
    // Если это уже URL (начинается с /api/), используем его напрямую
    if (path.startsWith('/api/')) {
      return path;
    }
    
    // Если это полный путь к файлу, извлекаем имя файла и используем новый эндпоинт
    const filename = getFilename(path);
    if (filename && mbUuid) {
      // Используем новый эндпоинт для получения скриншота из хранилища дашборда
      return `/api/screenshots/${mbUuid}/${filename}`;
    }
    
    return null;
  };
  
  // Более надежное извлечение имени файла (обрабатывает и / и \)
  const getFilename = (path: string | null | undefined): string | null => {
    if (!path) return null;
    // Если это URL, извлекаем имя файла из URL
    if (path.startsWith('/api/')) {
      const parts = path.split('/');
      return parts[parts.length - 1] || null;
    }
    // Заменяем обратные слеши на прямые для единообразия
    const normalizedPath = path.replace(/\\/g, '/');
    // Извлекаем имя файла
    const filename = normalizedPath.split('/').pop();
    // Убираем возможные пробелы и лишние символы
    return filename ? filename.trim() : null;
  };
  
  const sourceUrl = getScreenshotUrl(sourceScreenshot);
  const migratedUrl = getScreenshotUrl(migratedScreenshot);
  const sourceFilename = getFilename(sourceScreenshot);
  const migratedFilename = getFilename(migratedScreenshot);

  return (
    <div className="page-analysis-modal" onClick={onClose}>
      <div className="modal-content" onClick={(e) => e.stopPropagation()}>
        <div className="modal-header">
          <h2>{t('pageAnalysisTitle')} {report.page_slug}</h2>
          <button onClick={onClose} className="btn-close">×</button>
        </div>

        <div className="modal-tabs">
          <button
            className={activeTab === 'screenshots' ? 'active' : ''}
            onClick={() => setActiveTab('screenshots')}
          >
            {t('screenshots')}
          </button>
          <button
            className={activeTab === 'overview' ? 'active' : ''}
            onClick={() => setActiveTab('overview')}
          >
            {t('overview')}
          </button>
          <button
            className={activeTab === 'issues' ? 'active' : ''}
            onClick={() => setActiveTab('issues')}
          >
            {t('issues')}
          </button>
          <button
            className={activeTab === 'json' ? 'active' : ''}
            onClick={() => setActiveTab('json')}
          >
            {t('json')}
          </button>
        </div>

        <div className="modal-body">
          {activeTab === 'overview' && (
            <div className="overview-tab">
              <div className="info-grid">
                <div className="info-item">
                  <span className="info-label">{t('qualityRating')}</span>
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
                  <span className="info-label">{t('severityLevel')}</span>
                  <span
                    className="info-value"
                    style={{ color: getSeverityColor(report.severity_level) }}
                  >
                    {report.severity_level}
                  </span>
                </div>
                <div className="info-item">
                  <span className="info-label">{t('analysisStatus')}</span>
                  <span className="info-value">{report.analysis_status}</span>
                </div>
                {report.token_usage && (
                  <>
                    <div className="info-item">
                      <span className="info-label">{t('totalTokens')}</span>
                      <span className="info-value">
                        {report.token_usage.total_tokens !== undefined && report.token_usage.total_tokens !== null
                          ? report.token_usage.total_tokens.toLocaleString()
                          : 'N/A'}
                      </span>
                    </div>
                    <div className="info-item">
                      <span className="info-label">{t('promptTokens')}</span>
                      <span className="info-value">
                        {report.token_usage.prompt_tokens !== undefined && report.token_usage.prompt_tokens !== null
                          ? report.token_usage.prompt_tokens.toLocaleString()
                          : 'N/A'}
                      </span>
                    </div>
                    <div className="info-item">
                      <span className="info-label">{t('completionTokens')}</span>
                      <span className="info-value">
                        {report.token_usage.completion_tokens !== undefined && report.token_usage.completion_tokens !== null
                          ? report.token_usage.completion_tokens.toLocaleString()
                          : 'N/A'}
                      </span>
                    </div>
                    {report.token_usage.model && (
                      <div className="info-item">
                        <span className="info-label">{t('aiModel')}</span>
                        <span className="info-value">{report.token_usage.model}</span>
                      </div>
                    )}
                  </>
                )}
                {report.source_url && (
                  <div className="info-item">
                    <span className="info-label">{t('sourcePage')}</span>
                    <span className="info-value">
                      <a href={report.source_url} target="_blank" rel="noopener noreferrer">
                        {report.source_url}
                      </a>
                    </span>
                  </div>
                )}
                {report.migrated_url && (
                  <div className="info-item">
                    <span className="info-label">{t('migratedPage')}</span>
                    <span className="info-value">
                      <a href={report.migrated_url} target="_blank" rel="noopener noreferrer">
                        {report.migrated_url}
                      </a>
                    </span>
                  </div>
                )}
                <div className="info-item">
                  <span className="info-label">{t('analysisDate')}</span>
                  <span className="info-value">
                    {report.created_at ? formatDate(report.created_at) : '—'}
                  </span>
                </div>
              </div>

              {report.issues_summary?.summary && (
                <div className="summary-section">
                  <h3>{t('summary')}</h3>
                  <p>{report.issues_summary.summary}</p>
                </div>
              )}
            </div>
          )}

          {activeTab === 'screenshots' && (
            <div className="screenshots-tab">
              <div className="screenshots-grid">
                {sourceScreenshot && sourceUrl && (
                  <div className="screenshot-item">
                    <h4>{t('sourcePage')}</h4>
                    <img
                      src={sourceUrl}
                      alt="Source screenshot"
                      className="screenshot-image"
                      style={{ cursor: 'pointer' }}
                      onClick={() => setFullscreenImage(sourceUrl)}
                      onError={async (e) => {
                        // Если новый эндпоинт не сработал, пробуем старый способ
                        if (sourceFilename && !sourceUrl.includes('/screenshots/')) {
                          const fallbackUrl = `/api/review/wave/${token}/screenshots/${sourceFilename}`;
                          (e.target as HTMLImageElement).src = fallbackUrl;
                        } else {
                          (e.target as HTMLImageElement).src = `data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="400" height="300"%3E%3Ctext x="50%25" y="50%25" text-anchor="middle" dy=".3em"%3E${encodeURIComponent(t('screenshotNotFound'))}%3C/text%3E%3C/svg%3E`;
                        }
                      }}
                    />
                    <p className="screenshot-path">{sourceScreenshot}</p>
                  </div>
                )}
                {migratedScreenshot && migratedUrl && (
                  <div className="screenshot-item">
                    <h4>{t('migratedPage')}</h4>
                    <img
                      src={migratedUrl}
                      alt="Migrated screenshot"
                      className="screenshot-image"
                      style={{ cursor: 'pointer' }}
                      onClick={() => setFullscreenImage(migratedUrl)}
                      onError={async (e) => {
                        // Если новый эндпоинт не сработал, пробуем старый способ
                        if (migratedFilename && !migratedUrl.includes('/screenshots/')) {
                          const fallbackUrl = `/api/review/wave/${token}/screenshots/${migratedFilename}`;
                          (e.target as HTMLImageElement).src = fallbackUrl;
                        } else {
                          (e.target as HTMLImageElement).src = `data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="400" height="300"%3E%3Ctext x="50%25" y="50%25" text-anchor="middle" dy=".3em"%3E${encodeURIComponent(t('screenshotNotFound'))}%3C/text%3E%3C/svg%3E`;
                        }
                      }}
                    />
                    <p className="screenshot-path">{migratedScreenshot}</p>
                  </div>
                )}
                {!sourceScreenshot && !migratedScreenshot && (
                  <div className="no-screenshots">
                    <p>{t('screenshotsUnavailable')}</p>
                  </div>
                )}
              </div>
            </div>
          )}

          {/* Полноэкранный просмотр изображения */}
          {fullscreenImage && (
            <div 
              className="fullscreen-image-overlay"
              onClick={() => {
                setFullscreenImage(null);
                setImageScale(1);
                setImagePosition({ x: 0, y: 0 });
              }}
            >
              <button 
                className="fullscreen-close-btn"
                onClick={(e) => {
                  e.stopPropagation();
                  setFullscreenImage(null);
                  setImageScale(1);
                  setImagePosition({ x: 0, y: 0 });
                }}
              >
                ×
              </button>
              
              {/* Кнопки управления масштабом */}
              <div className="fullscreen-controls" onClick={(e) => e.stopPropagation()}>
                <button
                  className="zoom-btn"
                  onClick={(e) => {
                    e.stopPropagation();
                    setImageScale(prev => Math.min(prev + 0.25, 5));
                  }}
                  title="Увеличить"
                >
                  +
                </button>
                <button
                  className="zoom-btn"
                  onClick={(e) => {
                    e.stopPropagation();
                    setImageScale(prev => Math.max(prev - 0.25, 0.5));
                  }}
                  title="Уменьшить"
                >
                  −
                </button>
                <button
                  className="zoom-btn"
                  onClick={(e) => {
                    e.stopPropagation();
                    setImageScale(1);
                    setImagePosition({ x: 0, y: 0 });
                  }}
                  title="Сбросить"
                >
                  ↺
                </button>
              </div>

              <div
                className="fullscreen-image-container"
                onWheel={(e) => {
                  e.stopPropagation();
                  const delta = e.deltaY > 0 ? -0.1 : 0.1;
                  setImageScale(prev => Math.max(0.5, Math.min(5, prev + delta)));
                }}
                onMouseDown={(e) => {
                  if (imageScale > 1) {
                    e.stopPropagation();
                    setIsDragging(true);
                    setDragStart({ x: e.clientX - imagePosition.x, y: e.clientY - imagePosition.y });
                  }
                }}
                onMouseMove={(e) => {
                  if (isDragging && imageScale > 1) {
                    e.stopPropagation();
                    setImagePosition({
                      x: e.clientX - dragStart.x,
                      y: e.clientY - dragStart.y
                    });
                  }
                }}
                onMouseUp={() => setIsDragging(false)}
                onMouseLeave={() => setIsDragging(false)}
                style={{ cursor: imageScale > 1 ? (isDragging ? 'grabbing' : 'grab') : 'default' }}
              >
                <img 
                  src={fullscreenImage}
                  alt="Fullscreen screenshot"
                  className="fullscreen-image"
                  style={{
                    transform: `scale(${imageScale}) translate(${imagePosition.x / imageScale}px, ${imagePosition.y / imageScale}px)`,
                    transformOrigin: 'center center',
                    transition: isDragging ? 'none' : 'transform 0.2s ease'
                  }}
                  onClick={(e) => e.stopPropagation()}
                />
              </div>
            </div>
          )}

          {activeTab === 'issues' && (
            <div className="issues-tab">
              {report.detailed_report?.issues && Array.isArray(report.detailed_report.issues) && report.detailed_report.issues.length > 0 && (
                <div className="issues-section">
                  <h3>Проблемы и замечания</h3>
                  <div className="issues-list">
                    {report.detailed_report.issues.map((issue: any, index: number) => (
                      <div key={index} className={`issue-item issue-severity-${issue.severity || 'medium'}`}>
                        <div className="issue-header">
                          <span className="issue-type">{issue.type || 'unknown'}</span>
                          <span className={`issue-severity-badge severity-${issue.severity || 'medium'}`}>
                            {issue.severity || 'medium'}
                          </span>
                        </div>
                        <div className="issue-description">
                          <strong>{issue.description || 'Описание отсутствует'}</strong>
                        </div>
                        {issue.details && (
                          <div className="issue-details">
                            {issue.details}
                          </div>
                        )}
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {(!report.detailed_report?.issues?.length &&
                !report.detailed_report?.missing_elements?.length &&
                !report.issues_summary?.missing_elements?.length &&
                !report.detailed_report?.changed_elements?.length &&
                !report.issues_summary?.changed_elements?.length &&
                !report.detailed_report?.recommendations?.length &&
                !report.issues_summary?.recommendations?.length &&
                !report.detailed_report?.summary &&
                !report.issues_summary?.summary) && (
                <div className="no-issues">
                  <p>{t('noIssues')}</p>
                </div>
              )}
            </div>
          )}

          {activeTab === 'json' && (
            <div className="json-tab">
              <div className="json-viewer">
                <pre>{JSON.stringify(report.detailed_report || report, null, 2)}</pre>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
