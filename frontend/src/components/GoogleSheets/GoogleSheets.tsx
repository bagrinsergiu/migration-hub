import { useState } from 'react';
import GoogleSheetsList from './GoogleSheetsList';
import GoogleSheetsConnect from './GoogleSheetsConnect';
import GoogleSheetsLinkWave from './GoogleSheetsLinkWave';
import GoogleSheetsSyncStatus from './GoogleSheetsSyncStatus';
import '../common.css';
import './GoogleSheets.css';

type TabType = 'list' | 'connect' | 'link' | 'status';

export default function GoogleSheets() {
  const [activeTab, setActiveTab] = useState<TabType>('list');
  const [refreshKey, setRefreshKey] = useState(0);

  const handleRefresh = () => {
    setRefreshKey(prev => prev + 1);
  };

  return (
    <div className="google-sheets-page">
      <div className="page-header">
        <h1>Google Sheets</h1>
        <p className="page-description">
          Управление подключениями к Google таблицам. Подключайте таблицы, синхронизируйте данные
          и привязывайте листы к волнам миграций.
        </p>
      </div>

      <div className="tabs">
        <button
          className={`tab ${activeTab === 'list' ? 'active' : ''}`}
          onClick={() => setActiveTab('list')}
        >
          📋 Список таблиц
        </button>
        <button
          className={`tab ${activeTab === 'connect' ? 'active' : ''}`}
          onClick={() => setActiveTab('connect')}
        >
          ➕ Подключить
        </button>
        <button
          className={`tab ${activeTab === 'link' ? 'active' : ''}`}
          onClick={() => setActiveTab('link')}
        >
          🔗 Привязать к волне
        </button>
        <button
          className={`tab ${activeTab === 'status' ? 'active' : ''}`}
          onClick={() => setActiveTab('status')}
        >
          📊 Статус синхронизации
        </button>
      </div>

      <div className="tab-content">
        {activeTab === 'list' && (
          <GoogleSheetsList
            key={refreshKey}
            onRefresh={handleRefresh}
            onSync={handleRefresh}
            onDelete={handleRefresh}
          />
        )}
        {activeTab === 'connect' && (
          <GoogleSheetsConnect onConnected={handleRefresh} />
        )}
        {activeTab === 'link' && (
          <GoogleSheetsLinkWave onLinked={handleRefresh} />
        )}
        {activeTab === 'status' && (
          <GoogleSheetsSyncStatus onSync={handleRefresh} />
        )}
      </div>
    </div>
  );
}
