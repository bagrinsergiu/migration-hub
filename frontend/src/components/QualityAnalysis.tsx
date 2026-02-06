import { useState, useEffect } from 'react';
import { useParams } from 'react-router-dom';
import { api, QualityAnalysisReport, QualityStatistics } from '../api/client';
import './QualityAnalysis.css';
import './common.css';

export default function QualityAnalysis() {
  const { id } = useParams<{ id: string }>();
  const [reports, setReports] = useState<QualityAnalysisReport[]>([]);
  const [statistics, setStatistics] = useState<QualityStatistics | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [selectedPage, setSelectedPage] = useState<string | null>(null);
  const [severityFilter, setSeverityFilter] = useState<string | null>(null);

  useEffect(() => {
    if (id) {
      loadAnalysis();
    }
  }, [id]);

  const loadAnalysis = async () => {
    if (!id) return;
    try {
      setLoading(true);
      setError(null);
      
      const [reportsResponse, statsResponse] = await Promise.allSettled([
        api.getQualityAnalysis(parseInt(id)),
        api.getQualityStatistics(parseInt(id))
      ]);

      // Обработка отчетов
      if (reportsResponse.status === 'fulfilled') {
        const response = reportsResponse.value;
        if (response.success && response.data && Array.isArray(response.data)) {
          setReports(response.data);
        } else {
          // Если нет данных анализа - это не ошибка, просто пустой список
          if (response.error && !response.error.includes('не найден') && !response.error.includes('Request failed')) {
            setError(response.error);
          } else {
            setReports([]);
          }
        }
      } else {
        // Ошибка при запросе отчетов
        console.error('Error loading reports:', reportsResponse.reason);
        setReports([]);
        // Не показываем ошибку если это просто отсутствие данных
        if (reportsResponse.reason?.response?.status !== 404) {
          setError('Ошибка загрузки отчетов анализа');
        }
      }

      // Обработка статистики
      if (statsResponse.status === 'fulfilled') {
        const response = statsResponse.value;
        console.log('Statistics response:', response);
        if (response.success && response.data) {
          console.log('Setting statistics:', response.data);
          setStatistics(response.data);
        } else {
          // Статистика опциональна, не показываем ошибку если её нет
          console.warn('Statistics response missing data:', response);
          // Устанавливаем пустую статистику вместо null, чтобы плитки отображались
          setStatistics({
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
      } else {
        // Ошибка при запросе статистики - устанавливаем пустую статистику
        console.error('Error loading statistics:', statsResponse.reason);
        setStatistics({
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
      console.error('Error loading quality analysis:', err);
      setError(err.message || 'Ошибка загрузки анализа');
      setReports([]);
      setStatistics(null);
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

  const formatTokens = (tokens?: number) => {
    if (tokens === undefined || tokens === null) return 'N/A';
    return tokens.toLocaleString();
  };

  const formatCost = (cost?: number) => {
    if (cost === undefined || cost === null) return 'N/A';
    return `$${cost.toFixed(6)}`;
  };

  if (loading) {
    return (
      <div className="loading-container">
        <div className="spinner"></div>
        <p>Загрузка анализа качества...</p>
      </div>
    );
  }

  if (error && reports.length === 0 && !statistics) {
    return (
      <div className="error-container">
        <p className="error-message">❌ {error}</p>
        <button onClick={loadAnalysis} className="btn btn-primary">
          Попробовать снова
        </button>
      </div>
    );
  }

  return (
    <div className="quality-analysis">
      {/* Плитки статистики - показываем всегда */}
      <div className="quality-statistics">
        {/* Первая строка: Всего страниц, Средний рейтинг, Токены/Стоимость */}
        <div className="stat-card">
          <div className="stat-label">Всего страниц</div>
          <div className="stat-value">{statistics?.total_pages ?? 0}</div>
        </div>
        <div className="stat-card">
          <div className="stat-label">Средний рейтинг</div>
          <div className="stat-value" style={{ color: getQualityScoreColor(statistics?.avg_quality_score) }}>
            {statistics && typeof statistics.avg_quality_score === 'number' ? statistics.avg_quality_score.toFixed(1) : 'N/A'}
          </div>
        </div>
        <div className="stat-card" style={{ backgroundColor: '#f8f9fa', border: '2px solid #e0e0e0', display: 'flex', flexDirection: 'column', justifyContent: 'center' }}>
          <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', marginBottom: '0.75rem' }}>
            <div className="stat-label" style={{ fontSize: '0.875rem', marginBottom: '0.25rem', color: '#6c757d' }}>Токены</div>
            <div className="stat-value" style={{ color: '#2563eb', fontSize: '1.5rem', fontWeight: 700, margin: 0 }}>
              {statistics?.token_statistics?.total_tokens ? formatTokens(statistics.token_statistics.total_tokens) : '0'}
            </div>
          </div>
          <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
            <div className="stat-label" style={{ fontSize: '0.875rem', marginBottom: '0.25rem', color: '#6c757d' }}>Стоимость</div>
            <div className="stat-value" style={{ color: '#198754', fontSize: '1.5rem', fontWeight: 700, margin: 0 }}>
              {statistics?.token_statistics?.total_cost_usd ? formatCost(statistics.token_statistics.total_cost_usd) : '$0.000000'}
            </div>
          </div>
        </div>
      </div>
      
      {/* Вторая строка: Критичные, Высокие, Средние, Низкие */}
      <div className="quality-statistics severity-row">
        <div 
          className={`stat-card ${severityFilter === 'critical' ? 'active-filter' : ''}`}
          onClick={() => setSeverityFilter(severityFilter === 'critical' ? null : 'critical')}
          style={{ cursor: 'pointer', transition: 'all 0.2s ease' }}
        >
          <div className="stat-label">Критичные</div>
          <div className="stat-value" style={{ color: getSeverityColor('critical') }}>
            {statistics?.by_severity?.critical ?? 0}
          </div>
        </div>
        <div 
          className={`stat-card ${severityFilter === 'high' ? 'active-filter' : ''}`}
          onClick={() => setSeverityFilter(severityFilter === 'high' ? null : 'high')}
          style={{ cursor: 'pointer', transition: 'all 0.2s ease' }}
        >
          <div className="stat-label">Высокие</div>
          <div className="stat-value" style={{ color: getSeverityColor('high') }}>
            {statistics?.by_severity?.high ?? 0}
          </div>
        </div>
        <div 
          className={`stat-card ${severityFilter === 'medium' ? 'active-filter' : ''}`}
          onClick={() => setSeverityFilter(severityFilter === 'medium' ? null : 'medium')}
          style={{ cursor: 'pointer', transition: 'all 0.2s ease' }}
        >
          <div className="stat-label">Средние</div>
          <div className="stat-value" style={{ color: getSeverityColor('medium') }}>
            {statistics?.by_severity?.medium ?? 0}
          </div>
        </div>
        <div 
          className={`stat-card ${severityFilter === 'low' ? 'active-filter' : ''}`}
          onClick={() => setSeverityFilter(severityFilter === 'low' ? null : 'low')}
          style={{ cursor: 'pointer', transition: 'all 0.2s ease' }}
        >
          <div className="stat-label">Низкие</div>
          <div className="stat-value" style={{ color: getSeverityColor('low') }}>
            {statistics?.by_severity?.low ?? 0}
          </div>
        </div>
      </div>

      {/* Список страниц - показываем только если есть отчеты */}
      {reports.length > 0 ? (
        <div className="quality-pages-list">
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '1rem' }}>
            <h3>Анализ страниц</h3>
            {severityFilter && (
              <button 
                onClick={() => setSeverityFilter(null)}
                className="btn btn-secondary"
                style={{ fontSize: '0.875rem', padding: '0.25rem 0.75rem' }}
              >
                Сбросить фильтр ({severityFilter})
              </button>
            )}
          </div>
          <div className="pages-grid">
            {reports
              .filter(report => !severityFilter || report.severity_level === severityFilter)
              .map((report) => (
              <div
                key={report.id}
                className={`page-card ${selectedPage === report.page_slug ? 'selected' : ''}`}
                onClick={() => setSelectedPage(report.page_slug)}
              >
              <div className="page-card-header" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '0.75rem' }}>
                <h4 style={{ margin: 0, flex: 1 }}>{report.page_slug || 'Без названия'}</h4>
                <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
                  {report.quality_score !== null && report.quality_score !== undefined && (
                    <span
                      className="score-value"
                      style={{ 
                        color: getQualityScoreColor(typeof report.quality_score === 'string' ? parseInt(report.quality_score) : report.quality_score),
                        fontWeight: 600,
                        fontSize: '0.95rem'
                      }}
                    >
                      Рейтинг: {typeof report.quality_score === 'string' ? parseInt(report.quality_score) : report.quality_score}
                    </span>
                  )}
                  <span
                    className="severity-badge"
                    style={{
                      backgroundColor: getSeverityColor(report.severity_level),
                      color: 'white',
                      padding: '0.25rem 0.5rem',
                      borderRadius: '4px',
                      fontSize: '0.875rem'
                    }}
                  >
                    {report.severity_level}
                  </span>
                </div>
              </div>
              <div className="page-card-body">
                {(report as any).collection_items_id && (report as any).brz_project_id && (
                  <div style={{ marginBottom: '0.75rem' }}>
                    <a
                      href={`https://admin.brizy.io/projects/${(report as any).brz_project_id}/editor/page/${(report as any).collection_items_id}`}
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
                {report.token_usage && (
                  <div className="page-tokens-info" style={{ display: 'flex', gap: '1rem', marginBottom: '0.75rem', flexWrap: 'wrap', fontSize: '0.875rem' }}>
                    <span className="tokens-value" style={{ color: '#6c757d' }}>
                      {formatTokens(report.token_usage.total_tokens)}
                      {report.token_usage.prompt_tokens && report.token_usage.completion_tokens && (
                        <span className="tokens-detail" style={{ fontSize: '0.8rem', color: '#9ca3af', marginLeft: '0.25rem' }}>
                          ({formatTokens(report.token_usage.prompt_tokens)}/{formatTokens(report.token_usage.completion_tokens)})
                        </span>
                      )}
                    </span>
                    {report.token_usage.cost_estimate_usd !== undefined && report.token_usage.cost_estimate_usd !== null && (
                      <span className="tokens-value cost-value" style={{ color: '#198754', fontWeight: 'bold' }}>
                        {formatCost(report.token_usage.cost_estimate_usd)}
                      </span>
                    )}
                  </div>
                )}
                {(report.screenshots_path?.source || report.screenshots_path?.migrated) && (
                  <div style={{ display: 'flex', gap: '0.5rem', marginBottom: '0.5rem', minHeight: '150px' }}>
                    {report.screenshots_path?.source && (() => {
                      const sourceFilename = report.screenshots_path.source.split('/').pop();
                      return sourceFilename ? (
                        <div style={{ flex: 1, border: '1px solid #e0e0e0', borderRadius: '4px', overflow: 'hidden', backgroundColor: '#f9fafb', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                          <img 
                            src={api.getScreenshotUrl(sourceFilename)}
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
                            src={api.getScreenshotUrl(migratedFilename)}
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
                <div className="page-meta" style={{ fontSize: '0.75rem', color: '#9ca3af' }}>
                  <span className="meta-item">
                    {new Date(report.created_at).toLocaleDateString()}
                  </span>
                  {report.analysis_status === 'completed' && (
                    <span className="meta-item status-completed">✓ Завершен</span>
                  )}
                </div>
              </div>
            </div>
          ))}
          </div>
        </div>
      ) : (
        <div className="quality-analysis-empty" style={{ marginTop: '2rem' }}>
          <p>Анализ качества для этой миграции еще не выполнен.</p>
          <p className="text-muted">Запустите миграцию с параметром <code>quality_analysis=true</code> для выполнения анализа.</p>
        </div>
      )}

      {selectedPage && (
        <PageAnalysisDetails
          migrationId={parseInt(id || '0')}
          pageSlug={selectedPage}
          onClose={() => setSelectedPage(null)}
        />
      )}
    </div>
  );
}

export interface PageAnalysisDetailsProps {
  migrationId: number;
  pageSlug: string;
  onClose: () => void;
}

export function PageAnalysisDetails({ migrationId, pageSlug, onClose }: PageAnalysisDetailsProps) {
  const [report, setReport] = useState<QualityAnalysisReport | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<'overview' | 'screenshots' | 'issues' | 'json' | 'management'>('screenshots');
  const [rebuilding, setRebuilding] = useState(false);
  const [rebuildingNoAnalysis, setRebuildingNoAnalysis] = useState(false);
  const [reanalyzing, setReanalyzing] = useState(false);

  useEffect(() => {
    loadPageAnalysis();
  }, [migrationId, pageSlug]);

  const loadPageAnalysis = async () => {
    try {
      setLoading(true);
      setError(null);
      const response = await api.getPageQualityAnalysis(migrationId, pageSlug);
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

  const getQualityScoreColor = (score?: number | null) => {
    if (!score || score === null) return '#6c757d';
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
          <h2>Анализ страницы: {report.page_slug}</h2>
          <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center' }}>
            {(report as any).collection_items_id && (report as any).brz_project_id && (
              <a
                href={`https://admin.brizy.io/projects/${(report as any).brz_project_id}/editor/page/${(report as any).collection_items_id}`}
                target="_blank"
                rel="noopener noreferrer"
                className="btn btn-sm btn-primary"
                style={{ textDecoration: 'none' }}
                onClick={(e) => e.stopPropagation()}
                title="Открыть страницу в редакторе Brizy"
              >
                Редактировать
              </a>
            )}
            <button onClick={onClose} className="btn-close">×</button>
          </div>
        </div>

        <div className="modal-tabs">
          <button
            className={activeTab === 'screenshots' ? 'active' : ''}
            onClick={() => setActiveTab('screenshots')}
          >
            Скриншоты
          </button>
          <button
            className={activeTab === 'overview' ? 'active' : ''}
            onClick={() => setActiveTab('overview')}
          >
            Обзор
          </button>
          <button
            className={activeTab === 'issues' ? 'active' : ''}
            onClick={() => setActiveTab('issues')}
          >
            Проблемы
          </button>
          <button
            className={activeTab === 'json' ? 'active' : ''}
            onClick={() => setActiveTab('json')}
          >
            JSON
          </button>
          <button
            className={activeTab === 'management' ? 'active' : ''}
            onClick={() => setActiveTab('management')}
          >
            Управление
          </button>
        </div>

        <div className="modal-body">
          {activeTab === 'overview' && (
            <div className="overview-tab">
              <div className="info-grid">
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
                <div className="info-item">
                  <span className="info-label">Статус анализа:</span>
                  <span className="info-value">{report.analysis_status}</span>
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
              {/* Отображение issues из detailed_report */}
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

              {/* Отсутствующие элементы из detailed_report или issues_summary */}
              {(report.detailed_report?.missing_elements || report.issues_summary?.missing_elements) && 
               ((Array.isArray(report.detailed_report?.missing_elements) && report.detailed_report.missing_elements.length > 0) ||
                (Array.isArray(report.issues_summary?.missing_elements) && report.issues_summary.missing_elements.length > 0)) && (
                <div className="issues-section">
                  <h3>Отсутствующие элементы</h3>
                  <div className="elements-list">
                    {(report.detailed_report?.missing_elements || report.issues_summary?.missing_elements || []).map((item: string, index: number) => (
                      <div key={index} className="element-item element-missing">
                        <span className="element-icon">⚠️</span>
                        <span className="element-text">{item}</span>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* Измененные элементы из detailed_report или issues_summary */}
              {(report.detailed_report?.changed_elements || report.issues_summary?.changed_elements) && 
               ((Array.isArray(report.detailed_report?.changed_elements) && report.detailed_report.changed_elements.length > 0) ||
                (Array.isArray(report.issues_summary?.changed_elements) && report.issues_summary.changed_elements.length > 0)) && (
                <div className="issues-section">
                  <h3>Измененные элементы</h3>
                  <div className="elements-list">
                    {(report.detailed_report?.changed_elements || report.issues_summary?.changed_elements || []).map((item: string, index: number) => (
                      <div key={index} className="element-item element-changed">
                        <span className="element-icon">🔄</span>
                        <span className="element-text">{item}</span>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* Рекомендации из detailed_report или issues_summary */}
              {(report.detailed_report?.recommendations || report.issues_summary?.recommendations) && 
               ((Array.isArray(report.detailed_report?.recommendations) && report.detailed_report.recommendations.length > 0) ||
                (Array.isArray(report.issues_summary?.recommendations) && report.issues_summary.recommendations.length > 0)) && (
                <div className="issues-section">
                  <h3>Рекомендации</h3>
                  <div className="recommendations-list">
                    {(report.detailed_report?.recommendations || report.issues_summary?.recommendations || []).map((item: string, index: number) => (
                      <div key={index} className="recommendation-item">
                        <span className="recommendation-icon">💡</span>
                        <span className="recommendation-text">{item}</span>
                      </div>
                    ))}
                  </div>
                </div>
              )}

              {/* Summary из detailed_report или issues_summary */}
              {(report.detailed_report?.summary || report.issues_summary?.summary) && (
                <div className="issues-section summary-section">
                  <h3>Краткое описание</h3>
                  <div className="summary-text">
                    {report.detailed_report?.summary || report.issues_summary?.summary}
                  </div>
                </div>
              )}

              {/* Если нет данных */}
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
                  <p>Проблем не обнаружено</p>
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

          {activeTab === 'management' && (
            <div className="management-tab">
              <h3>Управление страницей</h3>
              <p style={{ marginBottom: '1.5rem', color: '#666' }}>
                Здесь вы можете пересобрать страницу (с анализом или без) или перезапустить только анализ качества.
                Существующая статистика анализа не будет удалена.
              </p>
              
              <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
                {(report as any).collection_items_id && (report as any).brz_project_id && (
                  <div className="action-card">
                    <h4>Редактирование страницы</h4>
                    <p style={{ fontSize: '0.875rem', color: '#666', marginBottom: '1rem' }}>
                      Открыть страницу в редакторе Brizy для ручного редактирования.
                    </p>
                    <a
                      href={`https://admin.brizy.io/projects/${(report as any).brz_project_id}/editor/page/${(report as any).collection_items_id}`}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="btn btn-primary"
                      style={{ width: '100%', textDecoration: 'none', display: 'inline-block', textAlign: 'center' }}
                    >
                      Редактировать в Brizy
                    </a>
                  </div>
                )}
                
                <div className="action-card">
                  <h4>Пересборка страницы</h4>
                  <p style={{ fontSize: '0.875rem', color: '#666', marginBottom: '1rem' }}>
                    Пересоберет страницу в Brizy и автоматически запустит анализ качества.
                  </p>
                  <button
                    onClick={async () => {
                      if (!confirm('Вы уверены, что хотите пересобрать страницу? Это запустит процесс миграции для этой страницы.')) {
                        return;
                      }
                      try {
                        setRebuilding(true);
                        const response = await api.rebuildPage(migrationId, pageSlug);
                        if (response.success) {
                          alert('Пересборка страницы запущена. Процесс выполняется в фоне.');
                          // Обновляем данные через несколько секунд
                          setTimeout(() => {
                            loadPageAnalysis();
                          }, 3000);
                        } else {
                          alert('Ошибка: ' + (response.error || 'Неизвестная ошибка'));
                        }
                      } catch (err: any) {
                        alert('Ошибка: ' + (err.message || 'Не удалось запустить пересборку'));
                      } finally {
                        setRebuilding(false);
                      }
                    }}
                    disabled={rebuilding}
                    className="btn btn-primary"
                    style={{ width: '100%' }}
                  >
                    {rebuilding ? 'Запуск пересборки...' : 'Пересобрать страницу'}
                  </button>
                </div>

                <div className="action-card">
                  <h4>Пересборка без анализа</h4>
                  <p style={{ fontSize: '0.875rem', color: '#666', marginBottom: '1rem' }}>
                    Пересоберет страницу в Brizy без запуска анализа качества. Полезно для быстрой пересборки.
                  </p>
                  <button
                    onClick={async () => {
                      if (!confirm('Вы уверены, что хотите пересобрать страницу без анализа? Это запустит процесс миграции для этой страницы без анализа качества.')) {
                        return;
                      }
                      try {
                        setRebuildingNoAnalysis(true);
                        const response = await api.rebuildPageNoAnalysis(migrationId, pageSlug);
                        if (response.success) {
                          alert('Пересборка страницы запущена (без анализа). Процесс выполняется в фоне.');
                          // Обновляем данные через несколько секунд
                          setTimeout(() => {
                            loadPageAnalysis();
                          }, 3000);
                        } else {
                          const errorMsg = response.error || 'Неизвестная ошибка';
                          const details = response.details ? `\n\nДетали:\n${JSON.stringify(response.details, null, 2)}` : '';
                          alert('Ошибка: ' + errorMsg + details);
                          console.error('Rebuild no analysis error:', response);
                        }
                      } catch (err: any) {
                        const errorMsg = err.response?.data?.error || err.message || 'Не удалось запустить пересборку';
                        const details = err.response?.data?.details ? `\n\nДетали:\n${JSON.stringify(err.response.data.details, null, 2)}` : '';
                        alert('Ошибка: ' + errorMsg + details);
                        console.error('Rebuild no analysis exception:', err);
                      } finally {
                        setRebuildingNoAnalysis(false);
                      }
                    }}
                    disabled={rebuildingNoAnalysis}
                    className="btn btn-secondary"
                    style={{ width: '100%' }}
                  >
                    {rebuildingNoAnalysis ? 'Запуск пересборки...' : 'Пересобрать без анализа'}
                  </button>
                </div>

                <div className="action-card">
                  <h4>Перезапуск анализа</h4>
                  <p style={{ fontSize: '0.875rem', color: '#666', marginBottom: '1rem' }}>
                    Перезапустит анализ качества для этой страницы без пересборки.
                  </p>
                  <button
                    onClick={async () => {
                      if (!confirm('Вы уверены, что хотите перезапустить анализ? Это создаст новый отчет анализа.')) {
                        return;
                      }
                      try {
                        setReanalyzing(true);
                        const response = await api.reanalyzePage(migrationId, pageSlug);
                        if (response.success) {
                          alert('Анализ перезапущен. Обновление данных...');
                          // Обновляем данные через несколько секунд
                          setTimeout(() => {
                            loadPageAnalysis();
                          }, 3000);
                        } else {
                          const errorMsg = response.error || 'Неизвестная ошибка';
                          const details = response.details ? `\n\nДетали:\n${JSON.stringify(response.details, null, 2)}` : '';
                          alert('Ошибка: ' + errorMsg + details);
                          console.error('Reanalyze error:', response);
                        }
                      } catch (err: any) {
                        const errorMsg = err.response?.data?.error || err.message || 'Не удалось запустить анализ';
                        const details = err.response?.data?.details ? `\n\nДетали:\n${JSON.stringify(err.response.data.details, null, 2)}` : '';
                        alert('Ошибка: ' + errorMsg + details);
                        console.error('Reanalyze exception:', err);
                      } finally {
                        setReanalyzing(false);
                      }
                    }}
                    disabled={reanalyzing}
                    className="btn btn-secondary"
                    style={{ width: '100%' }}
                  >
                    {reanalyzing ? 'Запуск анализа...' : 'Перезапустить анализ'}
                  </button>
                </div>
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
